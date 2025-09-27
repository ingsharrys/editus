<?php

namespace App\Services;

use App\Models\MetaPost;
use App\Support\FacebookGraph as FG;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MetaInsightsService
{
    public function resolvePageToken(int $metaPageId, int $userId): ?string
    {
        // 1) token del dueño
        $token = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->value('page_access_token');

        if ($token)
            return $token;

        // 2) cualquiera activo
        return DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->value('page_access_token');
    }

    /** --------- INSIGHTS (POST) --------- */
    public function fetchPostInsights(string $postId, string $pageToken, ?array &$err = null): ?array
    {
        $resp = Http::asForm()
            ->connectTimeout(10)->timeout(60)->retry(2, 200)
            ->get(FG::url("{$postId}/insights"), [
                'metric' => 'post_impressions,post_impressions_unique,post_engaged_users',
                'period' => 'lifetime',
                'access_token' => $pageToken,
            ]);

        if (!$resp->ok()) {
            $this->logGraphError('post_insights', $resp);
            $err = $this->extractError($resp);
            return null;
        }

        $out = [];
        foreach ($resp->json('data', []) as $m) {
            $out[$m['name']] = (int) ($m['values'][0]['value'] ?? 0);
        }
        return $out;
    }

    /** --------- INSIGHTS (VIDEO) --------- */
    public function fetchVideoInsights(string $videoId, string $pageToken, ?array &$err = null): ?array
    {
        $resp = Http::asForm()
            ->connectTimeout(10)->timeout(60)->retry(2, 200)
            ->get(FG::url("{$videoId}/video_insights", true), [
                'metric' => 'total_video_views,total_video_impressions',
                'period' => 'lifetime',
                'access_token' => $pageToken,
            ]);

        if (!$resp->ok()) {
            $this->logGraphError('video_insights', $resp);
            $err = $this->extractError($resp);
            return null;
        }

        $out = [];
        foreach ($resp->json('data', []) as $m) {
            $out[$m['name']] = (int) ($m['values'][0]['value'] ?? 0);
        }
        return $out;
    }

    /**
     * Intenta deducir el post-id desde un video-id buscando en el feed de la página
     * por un post cuyo object_id == videoId (ventana alrededor de published_at).
     */
    public function resolvePostIdFromVideo(string $videoId, int $metaPageId, ?string $publishedAtIso, string $pageToken): ?string
    {
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');
        if (!$pageId)
            return null;

        // Ventana de búsqueda (±3 días)
        $since = $publishedAtIso ? date('U', strtotime($publishedAtIso . ' -3 days')) : null;
        $until = $publishedAtIso ? date('U', strtotime($publishedAtIso . ' +3 days')) : null;

        $params = [
            'fields' => 'id,object_id,created_time',
            'limit' => 100,
            'access_token' => $pageToken,
        ];
        if ($since)
            $params['since'] = $since;
        if ($until)
            $params['until'] = $until;

        // Paginar hasta encontrarlo o agotar
        while (true) {
            $resp = Http::connectTimeout(10)->timeout(60)->retry(2, 200)
                ->get(FG::url("{$pageId}/posts"), $params);

            if (!$resp->ok()) {
                $this->logGraphError('page_posts_lookup', $resp);
                return null;
            }

            foreach ($resp->json('data', []) as $row) {
                if (($row['object_id'] ?? null) === $videoId) {
                    return $row['id'] ?? null; // ← post-id real
                }
            }

            $after = data_get($resp->json(), 'paging.cursors.after');
            if (!$after)
                break;
            $params['after'] = $after;
        }

        return null;
    }

    /**
     * Orquesta todo: maneja casos donde fb_post_id en realidad es video-id.
     * - Si es Post normal → usa post_insights.
     * - Si parece Video → usa video_insights; y si logra resolver el post-id,
     *   completa alcance/interacciones desde post_insights.
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
            'resolved_video_id' => null, // por si luego cachéas el video-id
        ];

        // 1) Intentar como POST
        $postErr = null;
        $postIns = $this->fetchPostInsights($post->fb_post_id, $pageToken, $postErr);
        if ($postIns) {
            $sources['post_insights'] = true;
            Log::info('[metrics] post_insights.ok', $ctx + [
                'post_impressions' => $postIns['post_impressions'] ?? null,
                'post_impressions_unique' => $postIns['post_impressions_unique'] ?? null,
                'post_engaged_users' => $postIns['post_engaged_users'] ?? null,
            ]);

            $alcance = $postIns['post_impressions_unique'] ?? $alcance;
            $visualizaciones = $postIns['post_impressions'] ?? $visualizaciones;
            $interacciones = $postIns['post_engaged_users'] ?? $interacciones;

        } else {
            // 2) Si falló como POST por "Video" o "metric inválida", tratamos como VIDEO
            if ($this->looksLikeVideoError($postErr)) {
                $vidErr = null;
                $vidIns = $this->fetchVideoInsights($post->fb_post_id, $pageToken, $vidErr);
                if ($vidIns) {
                    $sources['video_insights'] = true;
                    Log::info('[metrics] video_insights.ok', $ctx + [
                        'total_video_views' => $vidIns['total_video_views'] ?? null,
                        'total_video_impressions' => $vidIns['total_video_impressions'] ?? null,
                    ]);

                    // Mapeo video:
                    $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
                    $alcance = $vidIns['total_video_impressions'] ?? $alcance;
                } else {
                    Log::warning('[metrics] video_insights.fail', $ctx + ['error' => $vidErr]);
                }

                // b) (opcional) intenta hallar el post-id real para completar alcance/interacciones
                $postId = $this->resolvePostIdFromVideo(
                    $post->fb_post_id,
                    $post->meta_page_id,
                    optional($post->published_at)->toIso8601String(),
                    $pageToken
                );

                if ($postId) {
                    $sources['resolved_post_id'] = $postId;
                    $postIns2 = $this->fetchPostInsights($postId, $pageToken, $tmp);
                    if ($postIns2) {
                        Log::info('[metrics] post_insights.from_resolved_post_id.ok', $ctx + [
                            'resolved_post_id' => $postId,
                            'post_impressions_unique' => $postIns2['post_impressions_unique'] ?? null,
                            'post_engaged_users' => $postIns2['post_engaged_users'] ?? null,
                        ]);

                        $alcance = $postIns2['post_impressions_unique'] ?? $alcance;
                        $interacciones = $postIns2['post_engaged_users'] ?? $interacciones;
                    } else {
                        Log::warning('[metrics] post_insights.from_resolved_post_id.fail', $ctx + [
                            'resolved_post_id' => $postId,
                        ]);
                    }
                } else {
                    Log::info('[metrics] post_id.not_found_from_video', $ctx);
                }

            } else {
                Log::warning('[metrics] post_insights.fail', $ctx + ['error' => $postErr]);
            }
        }

        // 3) Persistir si hay cambios
        $dirty = false;
        if ($post->alcance !== $alcance && !is_null($alcance)) {
            $post->alcance = $alcance;
            $dirty = true;
        }
        if ($post->visualizaciones !== $visualizaciones && !is_null($visualizaciones)) {
            $post->visualizaciones = $visualizaciones;
            $dirty = true;
        }
        if ($post->interacciones !== $interacciones && !is_null($interacciones)) {
            $post->interacciones = $interacciones;
            $dirty = true;
        }

        if ($dirty) {
            if (Schema::hasColumn('meta_posts', 'last_insights_at')) {
                $post->last_insights_at = now();
            }
            $post->saveQuietly();

            $after = [
                'alcance_after' => $post->alcance,
                'visualizaciones_after' => $post->visualizaciones,
                'interacciones_after' => $post->interacciones,
            ];

            Log::info('[metrics] updated', $ctx + $before + $after + ['sources' => $sources]);

        } else {
            Log::info('[metrics] no-change', $ctx + $before + [
                'alcance_after' => $alcance,
                'visualizaciones_after' => $visualizaciones,
                'interacciones_after' => $interacciones,
                'sources' => $sources,
            ]);
        }

        return $dirty;
    }

    /** --------- Helpers --------- */
    private function looksLikeVideoError(?array $err): bool
    {
        if (!$err)
            return false;
        $msg = strtolower($err['message'] ?? '');
        return str_contains($msg, 'node type (video)') || str_contains($msg, 'valid insights metric');
    }

    private function extractError($resp): array
    {
        try {
            return $resp->json('error') ?? [];
        } catch (\Throwable) {
            return ['message' => (string) $resp->body()];
        }
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
            'trace-id' => $resp->header('x-fb-trace-id'),
        ]);
    }
}
