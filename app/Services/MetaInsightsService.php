<?php

namespace App\Services;

use App\Models\MetaPost;
use App\Support\FacebookGraph;
use App\Support\FacebookGraph as FG;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MetaInsightsService
{  /** ---- MÉTRICAS ---- */
    private const POST_METRICS = [
        'post_impressions',          // impresiones totales (visualizaciones)
        'post_impressions_unique',   // alcance (usuarios únicos)
        'post_engaged_users',        // personas que interactuaron
    ];

    private const VIDEO_METRICS = [
        'total_video_impressions',   // "alcance" aproximado del video (impresiones)
        'total_video_views',         // visualizaciones del video
    ];

    /** ---- HTTP options ---- */
    private const TIMEOUT = 60;  // seg
    private const RETRIES = 3;
    private const RETRY_MS = 300;

    /**
     * Intenta actualizar alcance / visualizaciones / interacciones del post.
     * - Primero como POST
     * - Si falla por "es video", intenta como VIDEO
     * - Si puede, resuelve el post real asociado al video y completa con métricas de post
     */
    public function updatePostMetrics(MetaPost $post): bool
    {
        // Kill-switch opcional (si lo usas)
        if (file_exists(storage_path('app/disable-metrics'))) {
            Log::warning('[metrics] service.disabled', ['post_id' => $post->id ?? null, 'pid' => getmypid()]);
            return false;
        }

        if (!$post->fb_post_id) {
            Log::warning('[metrics] fb_post_id.missing', ['post_id' => $post->id]);
            return false;
        }

        $pageToken = $this->resolvePageToken($post->meta_page_id, $post->user_id);
        if (!$pageToken) {
            Log::warning('[FB] missing.page_token', [
                'post_id' => $post->id,
                'meta_page_id' => $post->meta_page_id,
            ]);
            return false;
        }

        $original = [
            'alcance' => $post->alcance,
            'visualizaciones' => $post->visualizaciones,
            'interacciones' => $post->interacciones,
        ];

        $alcance = $original['alcance'];
        $visualizaciones = $original['visualizaciones'];
        $interacciones = $original['interacciones'];

        // 1) POST insights
        $postErr = null;
        $postIns = $this->fetchPostInsights($post->fb_post_id, $pageToken, $postErr);
        if ($postIns) {
            $alcance = $postIns['post_impressions_unique'] ?? $alcance;
            $visualizaciones = $postIns['post_impressions'] ?? $visualizaciones;
            $interacciones = $postIns['post_engaged_users'] ?? $interacciones;

            Log::info('[metrics] post_insights.ok', [
                'post_id' => $post->id,
                'alc' => $alcance,
                'vis' => $visualizaciones,
                'int' => $interacciones,
            ]);
        } else {
            // 2) ¿Parece error de "es un video" o métrica inválida?
            if ($this->looksLikeVideoError($postErr)) {
                // 2.a) VIDEO insights
                $vidErr = null;
                $vidIns = $this->fetchVideoInsights($post->fb_post_id, $pageToken, $vidErr);
                if ($vidIns) {
                    // Mapeo para video:
                    // - Visualizaciones → total_video_views
                    // - Alcance (aprox) → total_video_impressions
                    $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
                    $alcance = $vidIns['total_video_impressions'] ?? $alcance;

                    Log::info('[metrics] video_insights.ok', [
                        'post_id' => $post->id,
                        'alc' => $alcance,
                        'vis' => $visualizaciones,
                    ]);
                } else {
                    Log::warning('[metrics] video_insights.fail', [
                        'post_id' => $post->id,
                        'error' => $vidErr,
                    ]);
                }

                // 2.b) Si podemos, resolvemos el post real asociado a ese video
                $resolved = $this->resolvePostIdFromVideo(
                    $post->fb_post_id,                                 // videoId
                    $post->meta_page_id,                               // metaPageId
                    optional($post->published_at)->toIso8601String(),  // fecha aprox
                    $pageToken
                );

                if ($resolved) {
                    $tmpErr = null;
                    $postIns2 = $this->fetchPostInsights($resolved, $pageToken, $tmpErr);
                    if ($postIns2) {
                        $alcance = $postIns2['post_impressions_unique'] ?? $alcance;
                        $interacciones = $postIns2['post_engaged_users'] ?? $interacciones;

                        Log::info('[metrics] post_from_video.ok', [
                            'post_id' => $post->id,
                            'resolved_post_id' => $resolved,
                            'alc' => $alcance,
                            'int' => $interacciones,
                        ]);
                        // Si quieres, podrías actualizar $post->fb_post_id = $resolved; (yo no lo toco por ahora)
                    } else {
                        Log::info('[metrics] post_from_video.fail', [
                            'post_id' => $post->id,
                            'resolved_post_id' => $resolved,
                            'error' => $tmpErr,
                        ]);
                    }
                } else {
                    Log::info('[metrics] post_id.not_found_from_video', [
                        'post_id' => $post->id,
                        'meta_page_id' => $post->meta_page_id,
                        'fb_post_id' => $post->fb_post_id,
                        'published_at' => optional($post->published_at)->toIso8601String(),
                    ]);
                }
            } else {
                Log::warning('[metrics] post_insights.fail', [
                    'post_id' => $post->id,
                    'error' => $postErr,
                ]);
            }
        }

        // 3) Persistir cambios si hay
        $dirty = false;
        if ($post->alcance !== $alcance && !is_null($alcance)) {
            $post->alcance = (int) $alcance;
            $dirty = true;
        }
        if ($post->visualizaciones !== $visualizaciones && !is_null($visualizaciones)) {
            $post->visualizaciones = (int) $visualizaciones;
            $dirty = true;
        }
        if ($post->interacciones !== $interacciones && !is_null($interacciones)) {
            $post->interacciones = (int) $interacciones;
            $dirty = true;
        }

        if ($dirty) {
            if (Schema::hasColumn('meta_posts', 'last_insights_at')) {
                $post->last_insights_at = now();
            }
            $post->saveQuietly();

            Log::info('[metrics] updated', [
                'post_id' => $post->id,
                'before' => $original,
                'after' => [
                    'alcance' => $post->alcance,
                    'visualizaciones' => $post->visualizaciones,
                    'interacciones' => $post->interacciones,
                ],
            ]);
        } else {
            Log::info('[metrics] no-change', [
                'post_id' => $post->id,
                'fb_post_id' => $post->fb_post_id,
                'published_at' => optional($post->published_at)->toIso8601String(),
                'alcance_before' => $original['alcance'],
                'visualizaciones_before' => $original['visualizaciones'],
                'interacciones_before' => $original['interacciones'],
                'alcance_after' => $alcance,
                'visualizaciones_after' => $visualizaciones,
                'interacciones_after' => $interacciones,
                'sources' => [
                    'post_insights' => (bool) $postIns,
                    'video_insights' => (bool) ($vidIns ?? null),
                    'resolved_post_id' => $resolved ?? null,
                ],
            ]);
        }

        return $dirty;
    }

    /** ------------------------ HELPERS ------------------------ */

    /**
     * Resuelve el Page Access Token para la meta_page y user dados.
     * 1) Si meta_pages.page_access_token existe, úsalo.
     * 2) Si no, usa el token del usuario (social_accounts) y recorre /me/accounts para hallar el de la página.
     */
    public function resolvePageToken(int $metaPageId, int $userId): ?string
    {
        // 1) Intentar token de página desde la pivote (tu caso principal)
        $pivot = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first(['page_access_token', 'expires_at', 'social_account_id']);

        if ($pivot && !empty($pivot->page_access_token)) {
            if (empty($pivot->expires_at) || now()->lt($pivot->expires_at)) {
                return $pivot->page_access_token;
            }
            Log::info('[FB] pivot token expirado, se intentará refrescar', [
                'meta_page_id' => $metaPageId,
                'user_id' => $userId,
            ]);
        }

        // 2) Necesitamos el page_id para pedir /me/accounts
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');
        if (!$pageId) {
            Log::warning('[FB] meta_page no encontrada', ['meta_page_id' => $metaPageId]);
            return null;
        }

        // 3) Access token del usuario (solo columna access_token)
        $userToken = $this->getUserAccessToken($userId, $pivot->social_account_id ?? null);
        if (!$userToken) {
            Log::warning('[FB] user token no encontrado para refrescar page token', ['user_id' => $userId]);
            return null;
        }

        // 4) Buscar el token de la página en /me/accounts y guardarlo en la pivote
        $helper = new \App\Support\FacebookGraph();
        $data = $helper->getPageDataFromMeAccounts($userToken, (string) $pageId);
        $pageToken = $data['access_token'] ?? null;

        if ($pageToken) {
            DB::table('meta_page_user')->updateOrInsert(
                ['meta_page_id' => $metaPageId, 'user_id' => $userId],
                [
                    'page_access_token' => $pageToken,
                    'is_active' => 1,
                    'updated_at' => now(),
                ]
            );
            return $pageToken;
        }

        Log::warning('[FB] no se pudo resolver page token', [
            'meta_page_id' => $metaPageId,
            'user_id' => $userId,
        ]);
        return null;
    }

    private function getUserAccessToken(int $userId, ?int $socialAccountId): ?string
    {
        if ($socialAccountId) {
            $token = DB::table('social_accounts')->where('id', $socialAccountId)->value('access_token');
            if ($token)
                return $token;
        }

        // Preferir facebook/meta
        $token = DB::table('social_accounts')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('provider', 'facebook')->orWhere('provider', 'meta'); })
            ->orderByDesc('id')
            ->value('access_token');
        if ($token)
            return $token;

        // Último recurso: cualquier provider más reciente
        return DB::table('social_accounts')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('access_token');
    }
    /**
     * Insights de POST: /{post-id}/insights?metric=...&period=lifetime
     * Retorna array ['post_impressions'=>..,'post_impressions_unique'=>..,'post_engaged_users'=>..] o null.
     */
    public function fetchPostInsights(string $postId, string $pageToken, ?string &$err = null): ?array
    {
        $err = null;
        $metric = implode(',', self::POST_METRICS);
        $params = ['metric' => $metric, 'period' => 'lifetime'];

        $url = FG::url("{$postId}/insights");
        $json = $this->safeGet($url, $params, $pageToken, $err);
        if (!$json)
            return null;

        $out = [];
        foreach (($json['data'] ?? []) as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;
            if ($name !== null)
                $out[$name] = is_numeric($value) ? (int) $value : $value;
        }
        return $out ?: null;
    }

    /**
     * Insights de VIDEO: /{video-id}/video_insights?metric=...
     * Retorna array ['total_video_impressions'=>..,'total_video_views'=>..] o null.
     */
    public function fetchVideoInsights(string $videoId, string $pageToken, ?string &$err = null): ?array
    {
        $err = null;
        $metric = implode(',', self::VIDEO_METRICS);
        $params = ['metric' => $metric];

        $url = FG::url("{$videoId}/video_insights"); // insights de video
        $json = $this->safeGet($url, $params, $pageToken, $err);
        if (!$json)
            return null;

        $out = [];
        foreach (($json['data'] ?? []) as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;
            if ($name !== null)
                $out[$name] = is_numeric($value) ? (int) $value : $value;
        }
        return $out ?: null;
    }

    /**
     * Dado un VIDEO ID, intenta encontrar el POST real en el feed de la página (comparando object_id).
     * Usa una ventana +/- 36h alrededor de published_at si está disponible.
     * Retorna post-id (pageId_postId) o null.
     */
    public function resolvePostIdFromVideo(
        string $videoId,
        int $metaPageId,
        ?string $publishedAtIso,
        string $pageToken
    ): ?string {
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');
        if (!$pageId)
            return null;

        $since = $publishedAtIso ? strtotime($publishedAtIso) - 36 * 3600 : null;
        $until = $publishedAtIso ? strtotime($publishedAtIso) + 36 * 3600 : null;

        $params = [
            'fields' => 'id,object_id,created_time',
            'limit' => 100,
        ];
        if ($since)
            $params['since'] = $since;
        if ($until)
            $params['until'] = $until;

        $url = FG::url("{$pageId}/feed");
        while (true) {
            $err = null;
            $json = $this->safeGet($url, $params, $pageToken, $err);
            if (!$json) {
                Log::warning('[FB][resolve_post_from_video].fail', ['error' => $err]);
                return null;
            }

            foreach (($json['data'] ?? []) as $row) {
                if (!empty($row['object_id']) && (string) $row['object_id'] === (string) $videoId) {
                    return $row['id'] ?? null; // ← ESTE es el post-id real
                }
            }

            $next = data_get($json, 'paging.next');
            if (!$next)
                break;
            $url = $next;   // la siguiente URL ya trae query params
            $params = [];   // reset (para no duplicar)
        }

        return null;
    }

    /**
     * Heurística para decidir si el error de POST sugiere que es un VIDEO.
     */
    private function looksLikeVideoError(?string $err): bool
    {
        if (!$err)
            return false;
        $e = strtolower($err);
        return str_contains($e, 'node type (video)') ||
            str_contains($e, 'video_insights') ||
            str_contains($e, 'invalid insights metric');
    }

    /**
     * GET con retry/timeout que no lanza excepción. Devuelve json array o null y setea $err.
     */
    private function safeGet(string $url, array $params, string $token, ?string &$err = null): ?array
    {
        $err = null;
        try {
            $resp = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRIES, self::RETRY_MS)
                ->get($url, $params);

            if (!$resp->ok()) {
                $err = "HTTP {$resp->status()}: " . $resp->body();
                Log::warning('[FB][GET].fail', ['url' => $url, 'status' => $resp->status(), 'body' => $resp->body()]);
                return null;
            }

            $json = $resp->json();
            if (!is_array($json)) {
                $err = 'Invalid JSON';
                Log::warning('[FB][GET].invalid_json', ['url' => $url]);
                return null;
            }
            return $json;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            Log::warning('[FB][GET].exception', ['url' => $url, 'error' => $err]);
            return null;
        }
    }
}
