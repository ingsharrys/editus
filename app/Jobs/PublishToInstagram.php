<?php

namespace App\Jobs;

use App\Models\MetaPost;
use App\Services\InstagramPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PublishToInstagram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [15, 60, 180];
    public $timeout = 900; // los reels sondean hasta 5 min

    /**
     * Payload: meta_post_id, ig_user_id, page_token, kind (photos|video),
     * photo_urls[]?, video_url?, caption, cleanup_rel[]?, cleanup_abs[]?
     */
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->onQueue('default');
    }

    public function handle(InstagramPublisher $ig): void
    {
        $metaPost = MetaPost::find($this->payload['meta_post_id'] ?? null);
        if (!$metaPost) {
            Log::warning('[IG][job] MetaPost no encontrado', ['id' => $this->payload['meta_post_id'] ?? null]);
            return;
        }

        $igUserId = (string) ($this->payload['ig_user_id'] ?? '');
        $token = (string) ($this->payload['page_token'] ?? '');
        $caption = $this->payload['caption'] ?? null;

        if ($igUserId === '' || $token === '') {
            $metaPost->update(['status' => 'fail', 'error' => 'Falta cuenta de Instagram o token.']);
            return;
        }

        $result = $this->payload['kind'] === 'video'
            ? $ig->publishReel($igUserId, $token, (string) $this->payload['video_url'], $caption)
            : $ig->publishPhotos($igUserId, $token, (array) ($this->payload['photo_urls'] ?? []), $caption);

        if ($result['ok']) {
            $metaPost->update([
                'status' => 'success',
                'fb_post_id' => $result['media_id'],
                'fb_permalink_url' => $result['permalink'] ?? null,
                'published_at' => now(),
                'error' => null,
            ]);
            Log::info('[IG][job] publicado', ['meta_post_id' => $metaPost->id, 'media_id' => $result['media_id']]);
        } else {
            $metaPost->update([
                'status' => 'fail',
                'error' => mb_substr('Instagram: ' . ($result['error'] ?? 'error desconocido'), 0, 60000),
            ]);
            Log::warning('[IG][job] falló', ['meta_post_id' => $metaPost->id, 'err' => $result['error'] ?? null]);
        }

        $this->cleanup();
    }

    public function failed(?\Throwable $e): void
    {
        if ($metaPost = MetaPost::find($this->payload['meta_post_id'] ?? null)) {
            $metaPost->update([
                'status' => 'fail',
                'error' => mb_substr('Instagram (job): ' . ($e?->getMessage() ?? 'fallo'), 0, 60000),
            ]);
        }
        $this->cleanup();
    }

    protected function cleanup(): void
    {
        foreach ((array) ($this->payload['cleanup_rel'] ?? []) as $rel) {
            if ($rel) {
                try {
                    Storage::delete($rel);
                } catch (\Throwable) {
                }
            }
        }
        foreach ((array) ($this->payload['cleanup_abs'] ?? []) as $abs) {
            if ($abs && is_file($abs)) {
                @unlink($abs);
            }
        }
    }
}
