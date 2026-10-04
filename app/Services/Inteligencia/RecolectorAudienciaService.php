<?php

namespace App\Services\Inteligencia;

use App\Models\AudienciaDiaria;
use App\Models\MetaPage;
use App\Models\PublicacionRed;
use App\Services\MetaMetricasService;
use App\Services\MetaPageTokenResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recolecta, por página, el histórico diario (alcance, interacciones, seguidores,
 * demografía, horarios) y las publicaciones con sus métricas, de Facebook e Instagram.
 * Todo agregado: nunca se guardan datos de personas.
 */
class RecolectorAudienciaService
{
    public function __construct(private GraphClient $graph, private MetaPageTokenResolver $tokens, private MetaMetricasService $metricas)
    {
    }

    /** Recolecta todo para una página. Devuelve un resumen por paso (errores incluidos, nunca lanza). */
    /** Avisos de la recolección en curso: métricas que Meta rechazó y por qué (no detienen la recolección). */
    private array $avisos = [];

    public function recolectar(MetaPage $page, int $dias = 7): array
    {
        $this->avisos = [];
        $resumen = ['pagina' => $page->name, 'facebook' => null, 'instagram' => null, 'publicaciones' => 0, 'metricas' => 0, 'errores' => [], 'avisos' => []];
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) {
            $resumen['errores'][] = 'Sin token activo';
            return $resumen;
        }
        $desde = Carbon::today()->subDays(max(1, $dias));
        $hasta = Carbon::today();

        try { $resumen['facebook'] = $this->diarioFacebook($page, $token, $desde, $hasta); } catch (\Throwable $e) { $resumen['errores'][] = 'FB diario: ' . $e->getMessage(); }
        if ($page->instagram_business_account_id) {
            try { $resumen['instagram'] = $this->diarioInstagram($page, $token, $desde, $hasta); } catch (\Throwable $e) { $resumen['errores'][] = 'IG diario: ' . $e->getMessage(); }
        }
        try { $resumen['publicaciones'] += $this->publicacionesFacebook($page, $token, $desde); } catch (\Throwable $e) { $resumen['errores'][] = 'FB publicaciones: ' . $e->getMessage(); }
        if ($page->instagram_business_account_id) {
            try { $resumen['publicaciones'] += $this->publicacionesInstagram($page, $token, $desde); } catch (\Throwable $e) { $resumen['errores'][] = 'IG publicaciones: ' . $e->getMessage(); }
        }
        try { $resumen['metricas'] = $this->actualizarMetricas($page); } catch (\Throwable $e) { $resumen['errores'][] = 'Métricas: ' . $e->getMessage(); }

        $resumen['avisos'] = array_slice(array_values(array_unique($this->avisos)), 0, 5);
        if ($resumen['errores'] || $resumen['avisos']) Log::warning('[inteligencia] recolección con errores o avisos', $resumen);
        return $resumen;
    }

    // ------------------------------------------------------------ Facebook

    private function diarioFacebook(MetaPage $page, string $token, Carbon $desde, Carbon $hasta): int
    {
        $rango = ['period' => 'day', 'since' => $desde->timestamp, 'until' => $hasta->copy()->addDay()->timestamp];
        // Métricas diarias: se piden en grupo y, si Meta rechaza alguna, una por una.
        // Meta retiró page_impressions (nov. 2025) y page_impressions_unique (jun. 2026): ahora son
        // page_media_view y page_total_media_view_unique. Si la página aún no las entrega, se prueban las antiguas.
        $diarias = ['page_total_media_view_unique', 'page_media_view', 'page_post_engagements', 'page_follows', 'page_daily_follows_unique', 'page_views_total'];
        $datos = $this->insightsTolerante($page->page_id, $diarias, $rango, $token);
        if (empty($datos['page_total_media_view_unique']) || empty($datos['page_media_view'])) {
            $antiguas = $this->insightsTolerante($page->page_id, array_values(array_filter(['page_impressions_unique', 'page_impressions'], fn($m) => empty($datos[$m === 'page_impressions_unique' ? 'page_total_media_view_unique' : 'page_media_view']))), $rango, $token);
            $datos['page_total_media_view_unique'] = $datos['page_total_media_view_unique'] ?? $antiguas['page_impressions_unique'] ?? [];
            $datos['page_media_view'] = $datos['page_media_view'] ?? $antiguas['page_impressions'] ?? [];
            // Si las nuevas fallaron pero las antiguas respondieron, el aviso de las nuevas sobra
            if (!empty($antiguas['page_impressions_unique'])) unset($this->avisos['page_total_media_view_unique']);
            if (!empty($antiguas['page_impressions'])) unset($this->avisos['page_media_view']);
        }

        // Demografía y horarios (lifetime / último día)
        $demo = $this->insightsTolerante($page->page_id, ['page_fans_gender_age', 'page_fans_city', 'page_fans_country'], ['period' => 'lifetime'], $token);
        $online = $this->insightsTolerante($page->page_id, ['page_fans_online'], ['period' => 'day', 'since' => $hasta->copy()->subDays(7)->timestamp, 'until' => $hasta->copy()->addDay()->timestamp], $token);
        $demografia = [
            'edad_genero' => self::ultimo($demo['page_fans_gender_age'] ?? []),
            'ciudad' => self::ultimo($demo['page_fans_city'] ?? []),
            'pais' => self::ultimo($demo['page_fans_country'] ?? []),
        ];
        $horarios = $this->horariosDesdeOnline($online['page_fans_online'] ?? []);

        $n = 0;
        $fechas = [];
        foreach ($datos as $serie) foreach (array_keys($serie) as $f) if ($f !== 'lifetime' && $f !== 'total') $fechas[$f] = true;
        krsort($fechas);
        $primera = true;
        foreach (array_keys($fechas) as $fecha) {
            $fila = [
                'alcance' => self::entero($datos['page_total_media_view_unique'][$fecha] ?? null),
                'impresiones' => self::entero($datos['page_media_view'][$fecha] ?? null),
                'interacciones' => self::entero($datos['page_post_engagements'][$fecha] ?? null),
                'seguidores' => self::entero($datos['page_follows'][$fecha] ?? null),
                'nuevos_seguidores' => self::entero($datos['page_daily_follows_unique'][$fecha] ?? null),
                'visitas' => self::entero($datos['page_views_total'][$fecha] ?? null),
            ];
            if ($primera) {
                // La demografía es "de hoy": se guarda en el día más reciente
                if (array_filter($demografia)) $fila['demografia'] = $demografia;
                if ($horarios) $fila['horarios'] = $horarios;
                $primera = false;
            }
            AudienciaDiaria::updateOrCreate(['meta_page_id' => $page->id, 'red' => 'facebook', 'fecha' => $fecha], array_filter($fila, fn($v) => $v !== null));
            $n++;
        }
        return $n;
    }

    private function publicacionesFacebook(MetaPage $page, string $token, Carbon $desde): int
    {
        $items = $this->graph->listar("{$page->page_id}/posts", [
            'fields' => 'id,message,created_time,permalink_url,status_type,attachments{media_type,type}',
            'since' => $desde->timestamp,
            'limit' => 100,
        ], $token, 4);
        $n = 0;
        foreach ($items as $p) {
            $tipo = $this->tipoFacebook($p);
            PublicacionRed::updateOrCreate(['red' => 'facebook', 'post_id' => (string) $p['id']], [
                'meta_page_id' => $page->id,
                'tipo' => $tipo,
                'texto' => isset($p['message']) ? Str::limit((string) $p['message'], 4000, '') : null,
                'permalink' => $p['permalink_url'] ?? null,
                'publicado_en' => Carbon::parse($p['created_time'])->setTimezone(config('app.timezone', 'America/Bogota')),
            ]);
            $n++;
        }
        return $n;
    }

    private function tipoFacebook(array $p): string
    {
        $media = strtolower((string) data_get($p, 'attachments.data.0.media_type', ''));
        $tipoAdj = strtolower((string) data_get($p, 'attachments.data.0.type', ''));
        $status = strtolower((string) ($p['status_type'] ?? ''));
        if (str_contains($tipoAdj, 'live') || str_contains($status, 'live')) return 'en_vivo';
        if ($media === 'video' || str_contains($tipoAdj, 'video')) return 'video';
        if (str_contains($tipoAdj, 'album') || str_contains($tipoAdj, 'multi')) return 'carrusel';
        if ($media === 'photo' || str_contains($tipoAdj, 'photo')) return 'foto';
        if ($media === 'link' || str_contains($tipoAdj, 'share') || $status === 'shared_story') return 'enlace';
        return 'texto';
    }

    // ----------------------------------------------------------- Instagram

    private function diarioInstagram(MetaPage $page, string $token, Carbon $desde, Carbon $hasta): int
    {
        $ig = $page->instagram_business_account_id;
        $rango = ['period' => 'day', 'since' => $desde->timestamp, 'until' => $hasta->copy()->addDay()->timestamp];
        $datos = $this->insightsTolerante($ig, ['reach', 'follower_count'], $rango, $token);
        $totales = $this->insightsTolerante($ig, ['views', 'profile_views', 'accounts_engaged', 'total_interactions'], $rango + ['metric_type' => 'total_value'], $token);

        $demografia = [];
        foreach (['age,gender' => 'edad_genero', 'city' => 'ciudad', 'country' => 'pais'] as $bd => $clave) {
            $r = $this->insightsTolerante($ig, ['follower_demographics'], ['period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $bd], $token);
            $demografia[$clave] = $r['follower_demographics']['breakdown'] ?? [];
        }
        $online = $this->insightsTolerante($ig, ['online_followers'], ['period' => 'lifetime'], $token);
        $horarios = $this->horariosDesdeOnline($online['online_followers'] ?? []);

        $n = 0;
        $fechas = [];
        foreach ($datos as $serie) foreach (array_keys($serie) as $f) if ($f !== 'lifetime' && $f !== 'total') $fechas[$f] = true;
        krsort($fechas);
        $primera = true;
        foreach (array_keys($fechas) as $fecha) {
            $fila = [
                'alcance' => self::entero($datos['reach'][$fecha] ?? null),
                'seguidores' => self::entero($datos['follower_count'][$fecha] ?? null),
            ];
            if ($primera) {
                // Las métricas total_value cubren el rango completo: se guardan como extras del último día
                $fila['extras'] = array_filter([
                    'views_periodo' => self::entero($totales['views']['total'] ?? null),
                    'visitas_perfil_periodo' => self::entero($totales['profile_views']['total'] ?? null),
                    'cuentas_con_interaccion_periodo' => self::entero($totales['accounts_engaged']['total'] ?? null),
                    'interacciones_periodo' => self::entero($totales['total_interactions']['total'] ?? null),
                ], fn($v) => $v !== null);
                if (array_filter($demografia)) $fila['demografia'] = $demografia;
                if ($horarios) $fila['horarios'] = $horarios;
                $primera = false;
            }
            AudienciaDiaria::updateOrCreate(['meta_page_id' => $page->id, 'red' => 'instagram', 'fecha' => $fecha], array_filter($fila, fn($v) => $v !== null && $v !== []));
            $n++;
        }
        return $n;
    }

    private function publicacionesInstagram(MetaPage $page, string $token, Carbon $desde): int
    {
        $items = $this->graph->listar("{$page->instagram_business_account_id}/media", [
            'fields' => 'id,caption,media_type,media_product_type,timestamp,permalink',
            'limit' => 50,
        ], $token, 4, fn($m) => isset($m['timestamp']) && Carbon::parse($m['timestamp'])->lt($desde));
        $n = 0;
        foreach ($items as $m) {
            $producto = strtoupper((string) ($m['media_product_type'] ?? ''));
            $tipoMedia = strtoupper((string) ($m['media_type'] ?? ''));
            $tipo = $producto === 'REELS' ? 'reel' : ($tipoMedia === 'VIDEO' ? 'video' : ($tipoMedia === 'CAROUSEL_ALBUM' ? 'carrusel' : 'foto'));
            PublicacionRed::updateOrCreate(['red' => 'instagram', 'post_id' => (string) $m['id']], [
                'meta_page_id' => $page->id,
                'tipo' => $tipo,
                'texto' => isset($m['caption']) ? Str::limit((string) $m['caption'], 4000, '') : null,
                'permalink' => $m['permalink'] ?? null,
                'publicado_en' => Carbon::parse($m['timestamp'])->setTimezone(config('app.timezone', 'America/Bogota')),
            ]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------ Métricas

    /** Refresca métricas de las publicaciones recientes (últimos 30 días) con métricas de hace más de 12 h. */
    public function actualizarMetricas(MetaPage $page, int $limite = 120): int
    {
        $pubs = PublicacionRed::where('meta_page_id', $page->id)
            ->where('publicado_en', '>=', now()->subDays(30))
            // También las que quedaron sin alcance (p. ej. recolectadas antes del cambio de métricas de Meta): se reintentan cada hora
            ->where(fn($q) => $q->whereNull('metricas_en')->orWhere('metricas_en', '<', now()->subHours(12))
                ->orWhere(fn($w) => $w->whereNull('alcance')->where('metricas_en', '<', now()->subHour())))
            ->orderByDesc('publicado_en')->limit($limite)->get();
        if ($pubs->isEmpty()) return 0;

        $items = $pubs->map(fn($p) => ['red' => $p->red, 'post_id' => $p->post_id, 'page_id' => $page->page_id, 'tipo' => in_array($p->tipo, ['video', 'reel', 'en_vivo'], true) ? 'video' : 'foto'])->values()->all();
        $resultados = $this->metricas->metricas($items);
        $porId = [];
        foreach ($resultados as $r) $porId[$r['red'] . ':' . $r['post_id']] = $r;

        $n = 0;
        foreach ($pubs as $p) {
            $r = $porId[$p->red . ':' . $p->post_id] ?? null;
            if ($r && !empty($r['error']) && !isset($this->avisos['pub:' . $p->red])) $this->avisos['pub:' . $p->red] = 'métricas de publicaciones (' . $p->red . '): ' . $r['error'];
            if (!$r || empty($r['ok'])) continue;
            if ($r['alcance'] === null && !isset($this->avisos['alcance:' . $p->red])) $this->avisos['alcance:' . $p->red] = 'Meta no entregó el alcance de las publicaciones de ' . $p->red . ' (revisa el permiso read_insights del token de la página)';
            $interacciones = (int) ($r['reacciones'] ?? 0) + (int) ($r['comentarios'] ?? 0) + (int) ($r['compartidos'] ?? 0) + (int) ($r['guardados'] ?? 0);
            $p->fill([
                'alcance' => $r['alcance'], 'impresiones' => $r['impresiones'], 'interacciones' => $r['interacciones'] ?? $interacciones,
                'reacciones' => $r['reacciones'], 'comentarios' => $r['comentarios'], 'compartidos' => $r['compartidos'],
                'reproducciones' => $r['reproducciones'], 'guardados' => $r['guardados'], 'metricas_en' => now(),
            ])->save();
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------- Ayudas

    /** Pide varias métricas; si Meta rechaza el grupo (alguna ya no existe), las pide una a una. */
    private function insightsTolerante(string $objeto, array $metricas, array $params, string $token): array
    {
        try {
            return GraphClient::insightsPorFecha($this->graph->get("{$objeto}/insights", $params + ['metric' => implode(',', $metricas)], $token));
        } catch (\Throwable) {
            $out = [];
            foreach ($metricas as $m) {
                try {
                    $out += GraphClient::insightsPorFecha($this->graph->get("{$objeto}/insights", $params + ['metric' => $m], $token));
                } catch (\Throwable $e) {
                    // Se guarda el motivo para mostrarlo al administrador (permiso faltante, métrica retirada por Meta…)
                    $this->avisos[$m] = $m . ': ' . preg_replace('/^Meta [^:]+: /', '', $e->getMessage());
                }
            }
            return $out;
        }
    }

    /** page_fans_online / online_followers → [día_semana(0-6) => [hora => n]] promediando los días disponibles. */
    private function horariosDesdeOnline(array $serie): array
    {
        $acum = [];
        $cuenta = [];
        foreach ($serie as $fecha => $valores) {
            if (!is_array($valores)) continue;
            $dia = ($fecha === 'lifetime' || $fecha === 'total') ? null : (int) date('w', strtotime($fecha));
            foreach ($valores as $hora => $n) {
                $h = (int) $hora;
                if ($dia === null) {
                    for ($d = 0; $d < 7; $d++) { $acum[$d][$h] = ($acum[$d][$h] ?? 0) + (int) $n; $cuenta[$d][$h] = ($cuenta[$d][$h] ?? 0) + 1; }
                } else {
                    $acum[$dia][$h] = ($acum[$dia][$h] ?? 0) + (int) $n;
                    $cuenta[$dia][$h] = ($cuenta[$dia][$h] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($acum as $d => $horas) foreach ($horas as $h => $v) $out[$d][$h] = (int) round($v / max(1, $cuenta[$d][$h]));
        return $out;
    }

    private static function ultimo(array $serie): array
    {
        if (!$serie) return [];
        $v = end($serie);
        return is_array($v) ? array_map('intval', $v) : [];
    }

    private static function entero($v): ?int
    {
        if ($v === null || $v === '' || is_array($v)) return null;
        return (int) $v;
    }
}
