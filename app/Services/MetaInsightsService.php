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
        // A) Intentar con la pivote
        [$pivot, $origin] = $this->pickPivotForPage($metaPageId, $userId);
        if ($pivot) {
            Log::info('[FB] using pivot token', [
                'meta_page_id' => $metaPageId,
                'origin' => $origin,
                'pivot_user_id' => $pivot->user_id ?? null,
            ]);
            return $pivot->page_access_token;
        }

        // B) Refrescar con /me/accounts DEL USUARIO ORIGINAL
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');
        if (!$pageId) {
            Log::warning('[FB] meta_page no encontrada', ['meta_page_id' => $metaPageId]);
            return null;
        }

        // access_token del usuario original (solo access_token)
        $userToken = $this->getUserAccessToken($userId, null);
        if (!$userToken) {
            Log::warning('[FB] user token no encontrado para refrescar page token', ['user_id' => $userId]);
            return null;
        }

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
            Log::info('[FB] pivot token refreshed via /me/accounts', [
                'meta_page_id' => $metaPageId,
                'user_id' => $userId,
            ]);
            return $pageToken;
        }

        Log::warning('[FB] no se pudo resolver page token', [
            'meta_page_id' => $metaPageId,
            'user_id' => $userId,
        ]);
        return null;
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
    public function updatePostMetrics(MetaPost $post): bool
    {
        if (!$post->fb_post_id)
            return false;

        $pageToken = $this->resolvePageToken($post->meta_page_id, $post->user_id);
        if (!$pageToken) {
            Log::warning('[metrics] no-page-token', ['post_id' => $post->id, 'meta_page_id' => $post->meta_page_id]);
            return false;
        }

        $ctx = [
            'post_id' => $post->id,
            'meta_page_id' => $post->meta_page_id,
            'fb_post_id' => $post->fb_post_id,
            'published_at' => optional($post->published_at)->toIso8601String() ?? $post->created_at?->toIso8601String(),
        ];

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
        ];

        // 1) Intento como POST
        $postErr = null;
        $postIns = $this->fetchPostInsights($post->fb_post_id, $pageToken, $postErr);
        if ($postIns) {
            $alcance = $postIns['post_impressions_unique'] ?? $alcance;
            $visualizaciones = $postIns['post_impressions'] ?? $visualizaciones;
            $interacciones = $postIns['post_engaged_users'] ?? $interacciones;
            $sources['post_insights'] = true;

            Log::info('[metrics] post_insights.ok', $ctx + [
                'alc' => $alcance,
                'vis' => $visualizaciones,
                'int' => $interacciones,
            ]);
        } else {
            // ¿Parece “es video” o “métrica inválida”?
            if ($this->looksLikeVideoError($postErr)) {
                // 2.a) ¿El fb_post_id ya es video_id?
                $vidErr = null;
                $vidIns = $this->fetchVideoInsights($post->fb_post_id, $pageToken, $vidErr);
                if ($vidIns) {
                    $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
                    $alcance = $vidIns['total_video_impressions'] ?? $alcance;
                    $sources['video_insights'] = true;

                    Log::info('[metrics] video_insights.ok', $ctx + [
                        'alc' => $alcance,
                        'vis' => $visualizaciones
                    ]);
                } else {
                    Log::warning('[metrics] video_insights.fail', $ctx + ['error' => $vidErr]);
                }

                // 2.b) Resolver el post real desde el video (feed de la página cerca de published_at)
                $postId = $this->resolvePostIdFromVideo(
                    $post->fb_post_id,
                    $post->meta_page_id,
                    optional($post->published_at)->toIso8601String(),
                    $pageToken
                );

                if ($postId) {
                    $sources['resolved_post_id'] = $postId;

                    $tmpErr = null;
                    $postIns2 = $this->fetchPostInsights($postId, $pageToken, $tmpErr);
                    if ($postIns2) {
                        $alcance = $postIns2['post_impressions_unique'] ?? $alcance;
                        $interacciones = $postIns2['post_engaged_users'] ?? $interacciones;

                        Log::info('[metrics] post_from_video.ok', $ctx + [
                            'resolved_post_id' => $postId,
                            'alc' => $alcance,
                            'int' => $interacciones,
                        ]);
                    } else {
                        Log::warning('[metrics] post_from_video.fail', $ctx + [
                            'resolved_post_id' => $postId,
                            'error' => $tmpErr
                        ]);
                    }
                } else {
                    // 2.c) Último intento: si fb_post_id era post con video, saca object_id (video_id) y pide video_insights
                    $objErr = null;
                    $objectId = $this->fetchPostObjectId($post->fb_post_id, $pageToken, $objErr);
                    if ($objectId) {
                        $vidErr2 = null;
                        $vidIns2 = $this->fetchVideoInsights($objectId, $pageToken, $vidErr2);
                        if ($vidIns2) {
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
            } else {
                Log::warning('[metrics] post_insights.fail', $ctx + ['error' => $postErr]);
                // Intento extra: tal vez es post con video pero sin mensaje de video explícito
                $objErr = null;
                $objectId = $this->fetchPostObjectId($post->fb_post_id, $pageToken, $objErr);
                if ($objectId) {
                    $vidErr = null;
                    $vidIns = $this->fetchVideoInsights($objectId, $pageToken, $vidErr);
                    if ($vidIns) {
                        $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
                        $alcance = $vidIns['total_video_impressions'] ?? $alcance;
                        $sources['video_insights'] = true;
                        Log::info('[metrics] video_from_post.ok', $ctx + ['video_id' => $objectId]);
                    } else {
                        Log::warning('[metrics] video_from_post.fail', $ctx + ['error' => $vidErr]);
                    }
                }
            }
        }

        // 3) Persistir si hay cambios
        $changed = false;
        if (!is_null($alcance) && $alcance !== $post->alcance) {
            $post->alcance = $alcance;
            $changed = true;
        }
        if (!is_null($visualizaciones) && $visualizaciones !== $post->visualizaciones) {
            $post->visualizaciones = $visualizaciones;
            $changed = true;
        }
        if (!is_null($interacciones) && $interacciones !== $post->interacciones) {
            $post->interacciones = $interacciones;
            $changed = true;
        }

        if ($changed) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('meta_posts', 'last_insights_at')) {
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
            'metric' => 'post_impressions,post_impressions_unique,post_engaged_users',
            'period' => 'lifetime',
        ];
        $json = $this->safeGet("{$postId}/insights", $params, $pageToken, $err, false);
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
        return $json['object_id'] ?? null;
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

        // ventana ±24h por si acaso; si no hay fecha, cae a 7 días
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
        for ($i = 0; $i < 10; $i++) { // límite de 10 páginas
            if ($after)
                $params['after'] = $after;

            $err = null;
            $json = $this->safeGet("{$pageId}/feed", $params, $pageToken, $err, false);
            if (!$json)
                break;

            foreach ($json['data'] ?? [] as $row) {
                // coincide si el object_id coincide con el video
                if (!empty($row['object_id']) && (string) $row['object_id'] === (string) $videoId) {
                    return $row['id'] ?? null;
                }
            }

            $after = $json['paging']['cursors']['after'] ?? null;
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
    private function looksLikeVideoError(?string $err): bool
    {
        if (!$err)
            return false;
        $e = strtolower($err);
        return str_contains($e, 'node type (video)') ||
            str_contains($e, 'video_insights') ||
            str_contains($e, 'invalid insights metric') ||
            str_contains($e, 'must be a valid insights metric');
    }

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
