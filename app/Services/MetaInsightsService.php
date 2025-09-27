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
    private function safeGet(string $path, array $params, string $label, bool $video = false, ?array &$err = null): ?array
    {
        try {
            $resp = Http::acceptJson()
                ->connectTimeout(15)   // ↑
                ->timeout(60)         // ↑
                ->retry(3, 300)       // reintentos
                ->get(FacebookGraph::url($path, $video), $params);

            if (!$resp->ok()) {
                $err = $this->extractError($resp);
                $this->logGraphError($label, $resp);
                return null;
            }
            return $resp->json();
        } catch (\Throwable $e) {
            $err = ['message' => $e->getMessage()];
            Log::warning("[FB][$label].exception", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** --------- INSIGHTS (POST) --------- */
    public function fetchPostInsights(string $postId, string $pageToken, ?array &$err = null): ?array
    {
        $json = $this->safeGet("{$postId}/insights", [
            'metric' => 'post_impressions,post_impressions_unique,post_engaged_users',
            'period' => 'lifetime',
            'access_token' => $pageToken,
        ], 'post_insights', false, $err);

        if (!$json)
            return null;

        $out = [];
        foreach ($json['data'] ?? [] as $m) {
            $out[$m['name']] = (int) ($m['values'][0]['value'] ?? 0);
        }
        return $out;
    }

    /** --------- INSIGHTS (VIDEO) --------- */
    public function fetchVideoInsights(string $videoId, string $pageToken, ?array &$err = null): ?array
    {
        $json = $this->safeGet("{$videoId}/video_insights", [
            'metric' => 'total_video_views,total_video_impressions',
            'period' => 'lifetime',
            'access_token' => $pageToken,
        ], 'video_insights', true, $err);

        if (!$json)
            return null;

        $out = [];
        foreach ($json['data'] ?? [] as $m) {
            $out[$m['name']] = (int) ($m['values'][0]['value'] ?? 0);
        }
        return $out;
    }
    public function resolveVideoIdFromPost(string $postId, string $pageToken, ?array &$err = null): ?string
    {
        $json = $this->safeGet($postId, [
            'fields' => 'object_id,attachments{target,id,type}',
            'access_token' => $pageToken,
        ], 'resolve_video_id', false, $err);

        if (!$json)
            return null;

        $obj = $json['object_id'] ?? null;
        $att = $json['attachments']['data'][0] ?? null;
        return $obj ?: ($att['target']['id'] ?? null);
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

        $after = null;
        do {
            if ($after)
                $params['after'] = $after;

            $err = null;
            $json = $this->safeGet("{$pageId}/posts", $params, 'page_posts_lookup', false, $err);
            if (!$json)
                return null;

            foreach ($json['data'] ?? [] as $row) {
                if (($row['object_id'] ?? null) === $videoId) {
                    return $row['id'] ?? null;
                }
            }
            $after = data_get($json, 'paging.cursors.after');
        } while ($after);

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
        // KILL SWITCH
    if (file_exists(storage_path('app/disable-metrics'))) {
        Log::warning('[metrics] service.disabled', ['post_id' => $post->id ?? null]);
        return false;
    }
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
            // 2) Si falló, ¿parece error de Video?
            if ($this->looksLikeVideoError($postErr)) {
                $vidErr = null;
                $vidIns = $this->fetchVideoInsights($post->fb_post_id, $pageToken, $vidErr);
                if ($vidIns) {
                    $sources['video_insights'] = true;
                    Log::info('[metrics] video_insights.ok', $ctx + [
                        'total_video_views' => $vidIns['total_video_views'] ?? null,
                        'total_video_impressions' => $vidIns['total_video_impressions'] ?? null,
                    ]);
                    // video → views & impresiones
                    $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
                    $alcance = $vidIns['total_video_impressions'] ?? $alcance;
                } else {
                    Log::warning('[metrics] video_insights.fail', $ctx + ['error' => $vidErr]);
                }

                // Intentar localizar el post-id real para completar alcance/interacciones
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
                            'error' => $tmpErr
                        ]);
                    }
                } else {
                    Log::info('[metrics] post_id.not_found_from_video', $ctx);
                }

            } else {
                // No parece video; loguea el error y sal
                Log::warning('[metrics] post_insights.fail', $ctx + ['error' => $postErr]);
            }
        }

        // 3) Guardar si cambió algo
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
