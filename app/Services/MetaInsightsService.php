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
    /**
     * Resuelve el Page Access Token para la página del post.
     * 1) Intenta del dueño del post (activo)
     * 2) Si no, cualquier token activo para esa page
     */
    public function resolvePageToken(int $metaPageId, int $userId): ?string
    {
        $token = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->value('page_access_token');

        if ($token) return $token;

        return DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->value('page_access_token');
    }

    /**
     * /{post-id}/insights (alcance/impresiones/engaged)
     */
    public function fetchPostInsights(string $postId, string $pageToken): ?array
    {
        $resp = Http::asForm()
            ->connectTimeout(10)
            ->timeout(60)
            ->retry(2, 200)
            ->get(FG::url("{$postId}/insights"), [
                'metric'       => 'post_impressions,post_impressions_unique,post_engaged_users',
                'period'       => 'lifetime',
                'access_token' => $pageToken,
            ]);

        if (!$resp->ok()) {
            $this->logGraphError('post_insights', $resp);
            return null;
        }

        $out = [];
        foreach ($resp->json('data', []) as $m) {
            $out[$m['name']] = (int) ($m['values'][0]['value'] ?? 0);
        }
        return $out;
    }

    /**
     * De un post, deduce si es video y saca el ID del video
     * fields: object_id, attachments{target,id,type}
     */
    public function resolveVideoIdFromPost(string $postId, string $pageToken): ?string
    {
        $resp = Http::connectTimeout(10)
            ->timeout(30)
            ->retry(2, 200)
            ->get(FG::url($postId), [
                'fields'       => 'object_id,attachments{target,id,type}',
                'access_token' => $pageToken,
            ]);

        if (!$resp->ok()) {
            $this->logGraphError('resolve_video_id', $resp);
            return null;
        }

        $json = $resp->json();
        $obj  = $json['object_id'] ?? null; // videos viejos
        $att  = $json['attachments']['data'][0] ?? null;
        return $obj ?: ($att['target']['id'] ?? null);
    }

    /**
     * /{video-id}/video_insights (total_video_views / total_video_impressions)
     */
    public function fetchVideoInsights(string $videoId, string $pageToken): ?array
    {
        $resp = Http::asForm()
            ->connectTimeout(10)
            ->timeout(60)
            ->retry(2, 200)
            ->get(FG::url("{$videoId}/video_insights", true), [
                'metric'       => 'total_video_views,total_video_impressions',
                'period'       => 'lifetime',
                'access_token' => $pageToken,
            ]);

        if (!$resp->ok()) {
            $this->logGraphError('video_insights', $resp);
            return null;
        }

        $out = [];
        foreach ($resp->json('data', []) as $m) {
            $out[$m['name']] = (int) ($m['values'][0]['value'] ?? 0);
        }
        return $out;
    }

    /**
     * Acción única para un MetaPost: resuelve token, pide insights y actualiza columnas.
     * Devuelve true si actualizó algo.
     */
    public function updatePostMetrics(MetaPost $post): bool
    {
        if (!$post->fb_post_id) {
            return false;
        }

        $pageToken = $this->resolvePageToken($post->meta_page_id, $post->user_id);
        if (!$pageToken) {
            Log::warning('[FB] Sin page token activo', ['post_id' => $post->id, 'meta_page_id' => $post->meta_page_id]);
            return false;
        }

        // 1) Insights del post
        $postIns = $this->fetchPostInsights($post->fb_post_id, $pageToken);
        $alcance     = $postIns['post_impressions_unique'] ?? null;
        $impresiones = $postIns['post_impressions'] ?? null;
        $engaged     = $postIns['post_engaged_users'] ?? null;

        // 2) Visualizaciones: si es video => total_video_views; si no => impresiones
        $visualizaciones = $impresiones;
        $videoId = $this->resolveVideoIdFromPost($post->fb_post_id, $pageToken);
        if ($videoId) {
            $vidIns = $this->fetchVideoInsights($videoId, $pageToken);
            $visualizaciones = $vidIns['total_video_views'] ?? $visualizaciones;
        }

        // 3) Guardar solo si hay novedades
        $dirty = false;
        if (!is_null($alcance) && $post->alcance !== $alcance) {
            $post->alcance = $alcance; $dirty = true;
        }
        if (!is_null($visualizaciones) && $post->visualizaciones !== $visualizaciones) {
            $post->visualizaciones = $visualizaciones; $dirty = true;
        }
        if (!is_null($engaged) && $post->interacciones !== $engaged) {
            $post->interacciones = $engaged; $dirty = true;
        }

        if ($dirty) {
            if (Schema()->hasColumn('meta_posts', 'last_insights_at')) {
                $post->last_insights_at = now();
            }
            $post->saveQuietly();
        }

        return $dirty;
    }

    private function logGraphError(string $where, $resp): void
    {
        try { $body = $resp->json(); } catch (\Throwable $e) { $body = $resp->body(); }
        Log::warning("[FB][$where] {$resp->status()}", [
            'body' => $body,
            'trace-id' => $resp->header('x-fb-trace-id'),
        ]);
    }
}
