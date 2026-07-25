<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\MetaPageAudience;
use App\Models\MetaPageDailyMetric;
use App\Models\MetaPost;
use App\Models\MetaPostReaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recolecta estadísticas de páginas de Facebook vía Graph API:
 *  - Métricas diarias (alcance, impresiones, interacciones, video, fans)
 *  - Audiencia por geografía (seguidores por país y ciudad)
 *  - Reacciones por tipo de cada publicación
 *
 * Nota sobre demografía: Meta eliminó de la API los desgloses por
 * sexo/edad de páginas de Facebook (sept. 2023). Solo están disponibles
 * país/ciudad de los seguidores. Sexo/edad sí existe vía Instagram
 * Business (fase futura).
 */
class PageStatsService
{
    protected array $tokenCache = [];

    /** Métricas que la API rechazó como inválidas (se omiten en llamadas siguientes). */
    protected array $invalidMetrics = [];

    /** Último error devuelto por la API (para mostrarlo en consola). */
    public ?string $lastError = null;

    /** Métricas diarias de página soportadas por Graph v23. */
    protected const DAILY_METRICS = [
        'page_impressions' => 'impressions',
        'page_impressions_unique' => 'reach',
        'page_post_engagements' => 'engagements',
        'page_video_views' => 'video_views',
        'page_fans' => 'fans',
    ];

    protected function base(): string
    {
        $v = config('services.facebook.version', 'v23.0');
        return "https://graph.facebook.com/{$v}";
    }

    /**
     * Page access token desde la pivote activa más reciente.
     */
    public function resolvePageToken(int $metaPageId): ?string
    {
        if (array_key_exists($metaPageId, $this->tokenCache)) {
            return $this->tokenCache[$metaPageId];
        }

        $token = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->orderByDesc('updated_at')
            ->value('page_access_token');

        return $this->tokenCache[$metaPageId] = ($token ?: null);
    }

    /**
     * Recolecta métricas diarias de una página para un rango de fechas.
     * Trocea el rango en ventanas de 30 días (límite práctico de la API).
     *
     * @return int cantidad de días guardados/actualizados
     */
    public function collectDaily(MetaPage $page, Carbon $since, Carbon $until): int
    {
        $token = $this->resolvePageToken($page->id);
        if (!$token) {
            Log::warning('[stats] sin token de página', ['meta_page_id' => $page->id]);
            return 0;
        }

        $this->lastError = null;
        $saved = 0;
        $cursor = $since->copy();

        while ($cursor->lt($until)) {
            $windowEnd = $cursor->copy()->addDays(30)->min($until);

            [$byDate, $err] = $this->fetchDailyWindow($page, $cursor, $windowEnd, $token);

            if ($err) {
                $this->lastError = $err;
                Log::warning('[stats] daily.fail', [
                    'meta_page_id' => $page->id,
                    'since' => $cursor->toDateString(),
                    'err' => mb_substr($err, 0, 500),
                ]);
                // si el token está muerto no insistas con más ventanas
                if (str_contains($err, '"code":190') || str_contains($err, 'Error validating access token')) {
                    break;
                }
            }

            foreach ($byDate as $date => $columns) {
                MetaPageDailyMetric::updateOrCreate(
                    ['meta_page_id' => $page->id, 'date' => $date],
                    $columns
                );
                $saved++;
            }

            $cursor = $windowEnd;
        }

        return $saved;
    }

    /**
     * Trae una ventana de métricas diarias. Si la llamada en lote falla por
     * una métrica inválida (Meta la descontinuó), reintenta métrica por
     * métrica y recuerda las inválidas para no volver a pedirlas.
     *
     * @return array{0: array<string, array<string,int>>, 1: ?string} [byDate, error]
     */
    protected function fetchDailyWindow(MetaPage $page, Carbon $since, Carbon $until, string $token): array
    {
        $metrics = array_values(array_diff(array_keys(self::DAILY_METRICS), $this->invalidMetrics));
        if (empty($metrics)) {
            return [[], 'Todas las métricas fueron marcadas inválidas por la API'];
        }

        $params = [
            'period' => 'day',
            'since' => $since->toDateString(),
            'until' => $until->copy()->addDay()->toDateString(),
        ];
        $url = "{$this->base()}/{$page->page_id}/insights";

        // 1) Intento en lote
        [$json, $err] = $this->get($url, $params + ['metric' => implode(',', $metrics)], $token);
        if ($json) {
            return [$this->parseDailyValues($json), null];
        }

        // 2) Si el lote falló por métrica inválida (#100), probar una por una
        $isInvalidMetric = $err && (str_contains($err, 'valid insights metric') || str_contains($err, '"code":100'));
        if (!$isInvalidMetric) {
            return [[], $err];
        }

        $byDate = [];
        foreach ($metrics as $metric) {
            [$j, $e] = $this->get($url, $params + ['metric' => $metric], $token);
            if ($j) {
                foreach ($this->parseDailyValues($j) as $date => $cols) {
                    $byDate[$date] = ($byDate[$date] ?? []) + $cols;
                }
            } elseif ($e && (str_contains($e, 'valid insights metric') || str_contains($e, '"code":100'))) {
                $this->invalidMetrics[] = $metric;
                Log::warning('[stats] métrica descontinuada por Meta, se omite', ['metric' => $metric]);
            }
        }

        return [$byDate, empty($byDate) ? $err : null];
    }

    /** Convierte la respuesta de /insights en fecha => [columna => valor]. */
    protected function parseDailyValues(array $json): array
    {
        $byDate = [];
        foreach ((array) data_get($json, 'data', []) as $metricRow) {
            $column = self::DAILY_METRICS[$metricRow['name'] ?? ''] ?? null;
            if (!$column) {
                continue;
            }
            foreach ((array) ($metricRow['values'] ?? []) as $v) {
                $date = isset($v['end_time']) ? Carbon::parse($v['end_time'])->subDay()->toDateString() : null;
                if ($date !== null && is_numeric($v['value'] ?? null)) {
                    $byDate[$date][$column] = (int) $v['value'];
                }
            }
        }
        return $byDate;
    }

    /**
     * Snapshot de audiencia (seguidores) por país y ciudad.
     * Meta ya no expone sexo/edad para páginas de Facebook por API.
     *
     * @return int filas guardadas
     */
    public function collectAudience(MetaPage $page): int
    {
        $this->lastError = null;

        $token = $this->resolvePageToken($page->id);
        if (!$token) {
            return 0;
        }

        $map = [
            'page_fans_country' => 'country',
            'page_fans_city' => 'city',
        ];

        [$json, $err] = $this->get("{$this->base()}/{$page->page_id}/insights", [
            'metric' => implode(',', array_keys($map)),
            'period' => 'day',
        ], $token);

        if (!$json) {
            $this->lastError = $err;
            Log::warning('[stats] audience.fail', ['meta_page_id' => $page->id, 'err' => mb_substr((string) $err, 0, 500)]);
            return 0;
        }

        $today = now()->toDateString();
        $saved = 0;

        foreach ((array) data_get($json, 'data', []) as $metricRow) {
            $dimension = $map[$metricRow['name'] ?? ''] ?? null;
            if (!$dimension) {
                continue;
            }

            // El último "values" trae el snapshot más reciente: {clave: total}
            $values = (array) ($metricRow['values'] ?? []);
            $latest = (array) (end($values)['value'] ?? []);

            foreach ($latest as $key => $total) {
                MetaPageAudience::updateOrCreate(
                    [
                        'meta_page_id' => $page->id,
                        'captured_date' => $today,
                        'dimension' => $dimension,
                        'key' => mb_substr((string) $key, 0, 120),
                    ],
                    ['value' => (int) $total]
                );
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Demografía de seguidores vía Instagram Business (sexo, edad, país, ciudad).
     * Requiere que la página tenga instagram_business_account_id, que el token
     * incluya instagram_basic + instagram_manage_insights, y que la cuenta IG
     * tenga al menos 100 seguidores (límite de Meta).
     *
     * @return int filas guardadas
     */
    public function collectInstagramDemographics(MetaPage $page): int
    {
        $this->lastError = null;

        $igId = $page->instagram_business_account_id;
        if (!$igId) {
            return 0;
        }

        $token = $this->resolvePageToken($page->id);
        if (!$token) {
            return 0;
        }

        $breakdowns = [
            'gender' => 'ig_gender',
            'age' => 'ig_age',
            'country' => 'ig_country',
            'city' => 'ig_city',
        ];

        $today = now()->toDateString();
        $saved = 0;

        foreach ($breakdowns as $breakdown => $dimension) {
            [$json, $err] = $this->get("{$this->base()}/{$igId}/insights", [
                'metric' => 'follower_demographics',
                'period' => 'lifetime',
                'metric_type' => 'total_value',
                'breakdown' => $breakdown,
            ], $token);

            if (!$json) {
                $this->lastError = $err;
                Log::debug('[stats] ig.demographics.fail', [
                    'meta_page_id' => $page->id,
                    'breakdown' => $breakdown,
                    'err' => mb_substr((string) $err, 0, 300),
                ]);
                continue;
            }

            $results = (array) data_get($json, 'data.0.total_value.breakdowns.0.results', []);
            foreach ($results as $r) {
                $key = implode(' · ', (array) data_get($r, 'dimension_values', []));
                if ($key === '') {
                    continue;
                }
                MetaPageAudience::updateOrCreate(
                    [
                        'meta_page_id' => $page->id,
                        'captured_date' => $today,
                        'dimension' => $dimension,
                        'key' => mb_substr($key, 0, 120),
                    ],
                    ['value' => (int) data_get($r, 'value', 0)]
                );
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Reacciones por tipo (like, love, wow, haha, sorry, anger) de un post.
     */
    public function collectPostReactions(MetaPost $post): bool
    {
        if (empty($post->fb_post_id) || !str_contains((string) $post->fb_post_id, '_')) {
            return false; // solo posts del feed
        }

        $token = $this->resolvePageToken($post->meta_page_id);
        if (!$token) {
            return false;
        }

        [$json, $err] = $this->get("{$this->base()}/{$post->fb_post_id}/insights", [
            'metric' => 'post_reactions_by_type_total',
            'period' => 'lifetime',
        ], $token);

        if (!$json) {
            Log::debug('[stats] reactions.fail', ['meta_post_id' => $post->id, 'err' => $err]);
            return false;
        }

        $breakdown = (array) data_get($json, 'data.0.values.0.value', []);
        foreach ($breakdown as $type => $total) {
            MetaPostReaction::updateOrCreate(
                ['meta_post_id' => $post->id, 'type' => (string) $type],
                ['total' => (int) $total]
            );
        }

        return !empty($breakdown);
    }

    /**
     * GET con manejo de errores homogéneo.
     *
     * @return array{0: ?array, 1: ?string} [json, error]
     */
    protected function get(string $url, array $params, string $token): array
    {
        try {
            $resp = Http::withToken($token)
                ->acceptJson()
                ->timeout(40)
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->get($url, $params);

            if ($resp->ok()) {
                return [$resp->json(), null];
            }

            return [null, $resp->body()];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }
}
