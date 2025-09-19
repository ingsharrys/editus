<?php

namespace App\Jobs;

use App\Models\MetaPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshMetaPermalink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Una sola ejecución por disparo */
    public $tries = 1;
    public $timeout = 60;

    /** payload: meta_post_id, page_token */
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $metaPost = MetaPost::find($this->payload['meta_post_id'] ?? null);
        if (!$metaPost) return;

        // si ya tiene permalink, no hacemos nada
        if ($metaPost->fb_permalink_url) return;

        $token = $this->payload['page_token'] ?? null;
        if (!$token) return;

        try {
            $permalink = null;

            if ($metaPost->type === 'video') {
                // En video normalmente fb_post_id guarda el video_id (como lo dejaste)
                $videoId = $metaPost->fb_post_id;
                if ($videoId) {
                    $vr = Http::get("https://graph.facebook.com/v23.0/{$videoId}", [
                        'fields'       => 'permalink_url,post_id,status',
                        'access_token' => $token,
                    ]);
                    if ($vr->ok()) {
                        $vj = $vr->json();
                        $permalink = data_get($vj, 'permalink_url');
                        if (!$permalink && ($postId = data_get($vj, 'post_id'))) {
                            $pr = Http::get("https://graph.facebook.com/v23.0/{$postId}", [
                                'fields'       => 'permalink_url',
                                'access_token' => $token,
                            ]);
                            if ($pr->ok()) $permalink = data_get($pr->json(), 'permalink_url');
                        }
                    }
                }
            } else {
                // Texto/Fotos: fb_post_id es el post de /feed
                $postId = $metaPost->fb_post_id;
                if ($postId) {
                    $pr = Http::get("https://graph.facebook.com/v23.0/{$postId}", [
                        'fields'       => 'permalink_url',
                        'access_token' => $token,
                    ]);
                    if ($pr->ok()) $permalink = data_get($pr->json(), 'permalink_url');
                }
            }

            if ($permalink) {
                $metaPost->update(['fb_permalink_url' => $permalink]);
            }
        } catch (Throwable $e) {
            Log::debug('[permalink][refresh:error]', [
                'meta_post_id' => $metaPost->id,
                'err'          => $e->getMessage(),
            ]);
        }
    }
}
