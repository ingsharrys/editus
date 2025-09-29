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
{   /**
    * Obtiene el page access token para la página del post.
    * 1) Usa meta_page_user.page_access_token (si existe y no ha expirado).
    * 2) Si falta, usa social_accounts.access_token del usuario para llamar /me/accounts y guardar el token de página en la pivote.
    */
    public function resolvePageToken(int $metaPageId, int $userId): ?string
    {
        // 1) Pivot user<->meta_page
        $pivot = DB::table('meta_page_user')
            ->where('user_id', $userId)
            ->where('meta_page_id', $metaPageId)
            ->orderByDesc('id')
            ->first();

        if ($pivot && !empty($pivot->page_access_token)) {
            return $pivot->page_access_token; // <- Page Access Token (¡este!)
        }

        // 2) Fallback: busca cualquiera activo para esa página
        $pvt = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();

        if ($pvt && !empty($pvt->page_access_token)) {
            return $pvt->page_access_token;
        }

        // 3) Último recurso: user token (no recomendado para fields)
        $social = \App\Models\SocialAccount::where('user_id', $userId)
            ->where('provider', 'facebook')
            ->latest('id')
            ->first();

        return $social?->access_token ?? null;
    }


    /**
     * Devuelve social_accounts.access_token.
     * Prioriza el social_account_id de la pivote; si no, el más reciente de provider facebook/meta; si no, cualquiera.
     */
    private function getUserAccessToken(int $userId, ?int $socialAccountId): ?string
    {
        if ($socialAccountId) {
            $tok = DB::table('social_accounts')
                ->where('id', $socialAccountId)
                ->value('access_token');
            if ($tok)
                return $tok;
        }

        $tok = DB::table('social_accounts')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('provider', 'facebook')->orWhere('provider', 'meta');
            })
            ->orderByDesc('id')
            ->value('access_token');
        if ($tok)
            return $tok;

        return DB::table('social_accounts')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('access_token');
    }

    /**
     * Actualiza alcance / visualizaciones / interacciones para un post.
     * Aplica estrategia: post_insights → video_insights (mismo id) → resolve post desde video → object_id (video_id) desde post.
     */

    private function fetchEngagementCounts(string $postId, string $pageToken): ?int
    {
        try {
            // 1er intento: summary(true)
            $r = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/v23.0/{$postId}", [
                    'fields' => 'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares',
                    'access_token' => $pageToken,
                ]);

            $j = $r->json();
            if ($r->ok() && is_array($j) && empty($j['error'])) {
                $reac = (int) (\Illuminate\Support\Arr::get($j, 'reactions.summary.total_count') ?? 0);
                $comms = (int) (\Illuminate\Support\Arr::get($j, 'comments.summary.total_count') ?? 0);
                $shares = (int) (\Illuminate\Support\Arr::get($j, 'shares.count') ?? 0);
                return $reac + $comms + $shares;
            }

            // 2do intento (algunas apps usan este formato): summary(total_count)
            $r2 = Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/v23.0/{$postId}", [
                    'fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares',
                    'access_token' => $pageToken,
                ]);

            $j2 = $r2->json();
            if ($r2->ok() && is_array($j2) && empty($j2['error'])) {
                $reac = (int) (\Illuminate\Support\Arr::get($j2, 'reactions.summary.total_count') ?? 0);
                $comms = (int) (\Illuminate\Support\Arr::get($j2, 'comments.summary.total_count') ?? 0);
                $shares = (int) (\Illuminate\Support\Arr::get($j2, 'shares.count') ?? 0);
                return $reac + $comms + $shares;
            }

            // Si llega error (p.ej. code 10), regresa null para que puedas loguearlo arriba
            return null;
        } catch (\Throwable $e) {
            Log::debug('[metrics][engagement:error]', ['err' => $e->getMessage(), 'post' => $postId]);
            return null;
        }
    }


    public function updatePostMetrics(\App\Models\MetaPost $post): bool
    {
        if (empty($post->fb_post_id)) {
            return false;
        }

        $ctx = [
            'post_id' => $post->id,
            'meta_page_id' => $post->meta_page_id,
            'fb_post_id' => $post->fb_post_id,
            'published_at' => optional($post->published_at)->toIso8601String()
                ?? $post->created_at?->toIso8601String(),
        ];

        // 1) Page Access Token (pivot), con fallback
        $pageToken = $this->resolvePageToken($post->meta_page_id, $post->user_id);
        if (!$pageToken) {
            Log::warning('[metrics] no-page-token', $ctx + ['user_id' => $post->user_id]);
            return false;
        }

        // Log si el autor NO tiene token propio (usamos otro del pivot)
        if (!empty($post->user_id)) {
            $authorHasToken = DB::table('meta_page_user')
                ->where('meta_page_id', $post->meta_page_id)
                ->where('user_id', $post->user_id)
                ->where('is_active', 1)
                ->whereNotNull('page_access_token')
                ->exists();

            if (!$authorHasToken) {
                Log::info('[metrics] fallback_token_used', $ctx + ['author_user_id' => $post->user_id]);
            }
        }

        // Valores base (para comparar)
        $before = [
            'alcance_before' => $post->alcance,
            'visualizaciones_before' => $post->visualizaciones,
            'interacciones_before' => $post->interacciones,
        ];
        $alcance = $post->alcance;
        $visualizaciones = $post->visualizaciones;
        $interacciones = $post->interacciones;

        $sources = [
            'post_insights' => false,
            'video_insights' => false,
            'resolved_post_id' => null,
            'engagement_from' => null, // fb_post_id o resolved_post_id
        ];

        /**
         * Cierre local para traer engagement (reactions+comments+shares)
         * con timeouts, retries e IPv4 forzado.
         */
        $getEngagement = function (string $postId) use ($pageToken, $ctx): ?int {
            try {
                // Formato 1: summary(true)
                $r = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->get("https://graph.facebook.com/v23.0/{$postId}", [
                        'fields' => 'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares',
                        'access_token' => $pageToken,
                    ]);
                $j = $r->json();

                if ($r->ok() && empty($j['error'])) {
                    $reac = (int) (data_get($j, 'reactions.summary.total_count') ?? 0);
                    $comms = (int) (data_get($j, 'comments.summary.total_count') ?? 0);
                    $shares = (int) (data_get($j, 'shares.count') ?? 0);
                    return $reac + $comms + $shares;
                }

                // Formato 2: summary(total_count)
                $r2 = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->get("https://graph.facebook.com/v23.0/{$postId}", [
                        'fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares',
                        'access_token' => $pageToken,
                    ]);
                $j2 = $r2->json();

                if ($r2->ok() && empty($j2['error'])) {
                    $reac = (int) (data_get($j2, 'reactions.summary.total_count') ?? 0);
                    $comms = (int) (data_get($j2, 'comments.summary.total_count') ?? 0);
                    $shares = (int) (data_get($j2, 'shares.count') ?? 0);
                    return $reac + $comms + $shares;
                }

                Log::warning('[metrics] engagement.fields.fail', $ctx + [
                    'status_1' => $r->status(),
                    'status_2' => $r2->status(),
                    'raw_1' => is_array($j) ? $j : ($r->body() ?? null),
                    'raw_2' => is_array($j2) ? $j2 : ($r2->body() ?? null),
                ]);
                return null;
            } catch (\Throwable $e) {
                Log::debug('[metrics][engagement:error]', $ctx + ['err' => $e->getMessage()]);
                return null;
            }
        };

        // 2) Intento como POST (texto/foto/enlace): post_impressions / post_impressions_unique
        $postErr = null;
        $postIns = $this->fetchPostInsights($post->fb_post_id, $pageToken, $postErr);

        if (is_array($postIns)) {
            $alcance = $postIns['post_impressions_unique'] ?? $alcance;
            $visualizaciones = $postIns['post_impressions'] ?? $visualizaciones;
            $sources['post_insights'] = true;

            Log::info('[metrics] post_insights.ok', $ctx + [
                'alc' => $alcance,
                'vis' => $visualizaciones,
            ]);

            // Engagement desde el propio post del feed
            $eng = $getEngagement($post->fb_post_id);
            if (!is_null($eng)) {
                $interacciones = $eng;
                $sources['engagement_from'] = $post->fb_post_id;
                Log::info('[metrics] engagement.ok', $ctx + ['from' => 'fb_post_id', 'val' => $eng]);
            } else {
                Log::warning('[metrics] engagement.missing', $ctx + ['from' => 'fb_post_id']);
            }

        } else {
            // 3) ¿Podría ser video?
            $isVideoType = strtolower((string) $post->type) === 'video';
            $videoishErr = $this->looksLikeVideoError($postErr);

            if (!($isVideoType || $videoishErr)) {
                // Post normal con error que NO sugiere video
                Log::warning('[metrics] post_insights.fail', $ctx + ['error' => $postErr]);
            } else {
                // 3.a) Intentar como VIDEO (video_id == fb_post_id)
                $vidErr = null;
                $vidIns = $this->fetchVideoInsights($post->fb_post_id, $pageToken, $vidErr);
                if (is_array($vidIns)) {
                    // Mapea video → métricas locales
                    $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
                    $alcance = $vidIns['total_video_impressions'] ?? $alcance;
                    $sources['video_insights'] = true;

                    Log::info('[metrics] video_insights.ok', $ctx + [
                        'alc' => $alcance,
                        'vis' => $visualizaciones,
                    ]);
                } else {
                    Log::warning('[metrics] video_insights.fail', $ctx + ['error' => $vidErr]);
                }

                // 3.b) Resolver el post_id del feed (si el post fue "video en feed")
                $postId = $this->resolvePostIdFromVideo(
                    $post->fb_post_id,
                    $post->meta_page_id,
                    optional($post->published_at)->toIso8601String(),
                    $pageToken
                );

                if ($postId) {
                    $sources['resolved_post_id'] = $postId;

                    // (Opcional) traer impresiones/alcance desde el post del feed si no lo tenías
                    $tmpErr = null;
                    $postIns2 = $this->fetchPostInsights($postId, $pageToken, $tmpErr);
                    if (is_array($postIns2)) {
                        $alcance = $postIns2['post_impressions_unique'] ?? $alcance;
                        Log::info('[metrics] post_from_video.ok', $ctx + [
                            'resolved_post_id' => $postId,
                            'alc' => $alcance,
                        ]);
                    } else {
                        Log::warning('[metrics] post_from_video.fail', $ctx + [
                            'resolved_post_id' => $postId,
                            'error' => $tmpErr,
                        ]);
                    }

                    // Engagement desde el post del feed (aquí suelen estar las reacciones/comentarios/compartidos)
                    $eng = $getEngagement($postId);
                    if (!is_null($eng)) {
                        $interacciones = $eng;
                        $sources['engagement_from'] = $postId;
                        Log::info('[metrics] engagement.ok', $ctx + ['from' => 'resolved_post_id', 'val' => $eng]);
                    } else {
                        Log::warning('[metrics] engagement.missing', $ctx + ['from' => 'resolved_post_id']);
                    }

                } elseif ($isVideoType) {
                    // 3.c) (Opcional avanzado) Si realmente es video, intentar object_id -> video_insights
                    $objErr = null;
                    $objectId = $this->fetchPostObjectId($post->fb_post_id, $pageToken, $objErr);
                    if ($objectId) {
                        $vidErr2 = null;
                        $vidIns2 = $this->fetchVideoInsights($objectId, $pageToken, $vidErr2);
                        if (is_array($vidIns2)) {
                            $visualizaciones = $vidIns2['total_video_views'] ?? $visualizaciones;
                            $alcance = $vidIns2['total_video_impressions'] ?? $alcance;
                            $sources['video_insights'] = true;

                            Log::info('[metrics] video_from_post.ok', $ctx + ['video_id' => $objectId]);
                        } else {
                            Log::warning('[metrics] video_from_post.fail', $ctx + ['error' => $vidErr2]);
                        }
                    } else {
                        Log::info('[metrics] post_id.not_found_from_video', $ctx);
                    }
                }
            }
        }

        // 4) Persistir cambios
        $changed = false;
        if ($alcance !== null && $alcance !== $post->alcance) {
            $post->alcance = $alcance;
            $changed = true;
        }
        if ($visualizaciones !== null && $visualizaciones !== $post->visualizaciones) {
            $post->visualizaciones = $visualizaciones;
            $changed = true;
        }
        if ($interacciones !== null && $interacciones !== $post->interacciones) {
            $post->interacciones = $interacciones;
            $changed = true;
        }

        if ($changed) {
            if (Schema::hasColumn('meta_posts', 'last_insights_at')) {
                $post->last_insights_at = now();
            }
            $post->saveQuietly();

            Log::info('[metrics] updated', $ctx + $before + [
                'alcance_after' => $post->alcance,
                'visualizaciones_after' => $post->visualizaciones,
                'interacciones_after' => $post->interacciones,
                'sources' => $sources,
            ]);
        } else {
            Log::info('[metrics] no-change', $ctx + $before + [
                'alcance_after' => $post->alcance,
                'visualizaciones_after' => $post->visualizaciones,
                'interacciones_after' => $post->interacciones,
                'sources' => $sources,
            ]);
        }

        return $changed;
    }


    /**
     * Señales CLARAS de que el error es “objeto Video”.
     * NO tratamos (#100) "The value must be a valid insights metric" como video.
     */
    private function looksLikeVideoError(?string $err): bool
    {
        if (!$err)
            return false;
        $e = strtolower($err);

        if (str_contains($e, 'node type (video)'))
            return true;
        if (str_contains($e, 'video_insights'))
            return true;
        if (preg_match('/\bobject_id\b/', $e))
            return true;

        return false;
    }




    /** ===== Helpers de Graph ===== */

    private function safeGet(string $path, array $params, string $pageToken, ?string &$err = null, bool $video = false): ?array
    {
        $err = null;
        try {
            $resp = Http::acceptJson()
                ->connectTimeout(15)
                ->timeout(30)
                ->withToken($pageToken)
                ->get(FG::url($path, $video), $params);

            if ($resp->successful()) {
                return $resp->json();
            }

            $this->logGraphError('GET', $resp);
            $err = "HTTP request returned status code {$resp->status()}:\n" . $resp->body();
            return null;

        } catch (\Throwable $e) {
            Log::warning('[FB][GET].exception', ['url' => FG::url($path, $video), 'error' => $e->getMessage()]);
            $err = $e->getMessage();
            return null;
        }
    }

    /** POST insights (post_impressions, post_impressions_unique, post_engaged_users) */
    public function fetchPostInsights(string $postId, string $pageToken, ?string &$err = null): ?array
    {
        $err = null;

        $params = [
            'metric' => 'post_impressions,post_impressions_unique',
            'period' => 'lifetime',
        ];

        $json = $this->safeGet("{$postId}/insights", $params, $pageToken, $err, false);
        if (!$json || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }

        $out = [];
        foreach ($json['data'] as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;

            // Asegura que value sea escalar numérico (int o string numérica)
            if ($name && (is_int($value) || (is_string($value) && is_numeric($value)))) {
                $out[$name] = (int) $value;
            }
        }

        // Normaliza: si no hay nada útil, vuelve null
        if (empty($out)) {
            return null;
        }

        // Solo regresa las claves que te interesan (por si Meta devuelve extras)
        return array_intersect_key($out, array_flip([
            'post_impressions',
            'post_impressions_unique',
            'post_engaged_users',
        ])) ?: null;
    }


    /** VIDEO insights (total_video_impressions, total_video_views) */
    public function fetchVideoInsights(string $videoId, string $pageToken, ?string &$err = null): ?array
    {
        $err = null;
        $params = [
            'metric' => 'total_video_impressions,total_video_views',
        ];
        $json = $this->safeGet("{$videoId}/video_insights", $params, $pageToken, $err, true);
        if (!$json)
            return null;

        $out = [];
        foreach ($json['data'] ?? [] as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;
            if ($name && $value !== null) {
                $out[$name] = (int) $value;
            }
        }
        return $out ?: null;
    }

    /** De un POST sacar object_id (suele ser el video_id si embebe un video). */
    public function fetchPostObjectId(string $postId, string $pageToken, ?string &$err = null): ?string
    {
        $err = null;
        $json = $this->safeGet($postId, ['fields' => 'object_id,created_time'], $pageToken, $err, false);
        if (!$json)
            return null;

        $obj = $json['object_id'] ?? null;
        if ($obj) {
            Log::info('[metrics] post.object_id', ['post_id' => $postId, 'object_id' => $obj]);
        }
        return $obj;
    }


    /**
     * Dado un video y una página, buscar el post (id) que lo contiene
     * recorriendo el feed alrededor de la fecha de publicación.
     */
    public function resolvePostIdFromVideo(string $videoId, int $metaPageId, ?string $publishedAtIso, string $pageToken): ?string
    {
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');
        if (!$pageId)
            return null;

        // Ventana de tiempo
        if ($publishedAtIso) {
            $ts = strtotime($publishedAtIso);
            $since = $ts - 24 * 3600;
            $until = $ts + 24 * 3600;
        } else {
            $until = time();
            $since = $until - 7 * 24 * 3600;
        }

        $params = [
            'fields' => 'id,object_id,created_time',
            'since' => $since,
            'until' => $until,
            'limit' => 100,
        ];

        $after = null;
        for ($i = 0; $i < 10; $i++) {
            if ($after)
                $params['after'] = $after;

            $err = null;
            $json = $this->safeGet("{$pageId}/feed", $params, $pageToken, $err, false);
            if (!$json)
                break;

            foreach ($json['data'] ?? [] as $row) {
                if (!empty($row['object_id']) && (string) $row['object_id'] === (string) $videoId) {
                    $postId = $row['id'] ?? null;
                    if ($postId) {
                        Log::info('[metrics] feed.match', ['video_id' => $videoId, 'resolved_post_id' => $postId]);
                    }
                    return $postId;
                }
            }

            $after = data_get($json, 'paging.cursors.after');
            if (!$after)
                break;
        }

        return null;
    }

    private function pickPivotForPage(int $metaPageId, int $userId): array
    {
        // 1) Pivote del MISMO usuario
        $pivotUser = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first(['page_access_token', 'expires_at', 'social_account_id', 'user_id', 'updated_at']);

        if ($pivotUser && !empty($pivotUser->page_access_token)) {
            if (empty($pivotUser->expires_at) || now()->lt($pivotUser->expires_at)) {
                return [$pivotUser, 'user'];
            }
        }

        // 2) Cualquier pivote ACTIVA de esa página (por si el post lo ve otro admin)
        $pivotAny = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->orderByDesc('updated_at')
            ->first(['page_access_token', 'expires_at', 'social_account_id', 'user_id', 'updated_at']);

        if ($pivotAny && !empty($pivotAny->page_access_token)) {
            if (empty($pivotAny->expires_at) || now()->lt($pivotAny->expires_at)) {
                return [$pivotAny, 'any'];
            }
        }

        return [null, null];
    }

    /** Heurística para decidir si el error de post_insights sugiere que es un video. */

    private function logGraphError(string $where, $resp): void
    {
        try {
            $body = $resp->json();
        } catch (\Throwable) {
            $body = $resp->body();
        }
        Log::warning("[FB][$where] {$resp->status()}", [
            'body' => $body,
            'trace_id' => $resp->header('x-fb-trace-id'),
        ]);
    }
}
