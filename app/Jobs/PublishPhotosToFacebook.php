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
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Jobs\RefreshMetaPermalink;

class PublishPhotosToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reintentos / backoff / timeout */
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 120];
    public $timeout = 600; // 10 min

    /** Payload: meta_post_id, page_id, page_name, page_token, photo_urls[], cleanup_rel[], cleanup_abs[], caption */
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $trace = (string) \Illuminate\Support\Str::uuid();

        $metaPost = MetaPost::find($this->payload['meta_post_id'] ?? null);
        if (!$metaPost) {
            Log::warning('[FB][photos][job] MetaPost no encontrado', ['trace' => $trace, 'id' => $this->payload['meta_post_id'] ?? null]);
            return;
        }

        // Mantener status 'pending' (tu enum es: pending|success|fail)
        $pageId = $this->payload['page_id'];
        $pageToken = $this->payload['page_token'];
        $caption = $this->payload['caption'] ?? null;
        $urls = (array) ($this->payload['photo_urls'] ?? []);

        Log::info('[FB][photos][start]', ['trace' => $trace, 'meta_post' => $metaPost->id, 'count' => count($urls)]);

        if (empty($urls)) {
            $metaPost->update(['status' => 'fail', 'error' => 'No llegaron URLs de fotos']);
            $this->cleanupTemp();
            return;
        }

        // 1) Subir fotos como unpublished (url param)
        $media = [];
        foreach ($urls as $u) {
            $r = Http::asForm()->post("https://graph.facebook.com/v23.0/{$pageId}/photos", [
                'published' => false,
                'url' => $u,
                'access_token' => $pageToken,
            ]);

            if ($r->ok() && ($id = data_get($r->json(), 'id'))) {
                $media[] = ['media_fbid' => $id];
            } else {
                Log::warning('[FB][photos][create:fail]', [
                    'trace' => $trace,
                    'url' => $u,
                    'raw' => $r->json() ?? $r->body(),
                ]);
            }
        }

        if (empty($media)) {
            $metaPost->update(['status' => 'fail', 'error' => 'Ninguna imagen se pudo subir a Meta.']);
            $this->cleanupTemp();
            return;
        }

        // 2) Crear el post en /feed con attached_media
        $payload = ['access_token' => $pageToken];
        if ($caption)
            $payload['message'] = $caption;
        foreach ($media as $i => $m) {
            $payload["attached_media[$i]"] = json_encode($m);
        }

        $resp = Http::asForm()->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);
        if (!$resp->ok()) {
            $metaPost->update(['status' => 'fail', 'error' => (data_get($resp->json(), 'error.message') ?: $resp->body())]);
            Log::warning('[FB][photos][feed:fail]', ['trace' => $trace, 'raw' => $resp->json() ?? $resp->body()]);
            $this->cleanupTemp();
            return;
        }

        $postId = data_get($resp->json(), 'id');
        $permalink = $this->fetchPermalinkQuick($postId, $pageToken);

        // 3) Finalizar
        $metaPost->update([
            'status' => 'success',
            'fb_post_id' => $postId,
            'fb_media_ids' => json_encode(array_map(fn($m) => $m['media_fbid'], $media)),
            'fb_permalink_url' => $permalink,
            'published_at' => now(),
            'error' => null,
        ]);

        // refresco diferido del permalink (por si Graph aún no lo tenía)
        RefreshMetaPermalink::dispatch([
            'meta_post_id' => $metaPost->id,
            'page_token' => $pageToken,
        ])->delay(now()->addMinute());

        $this->cleanupTemp();

        Log::info('[FB][photos][done]', ['trace' => $trace, 'post_id' => $postId, 'permalink' => $permalink]);
    }

    /** Si falla definitivamente */
    public function failed(Throwable $e): void
    {
        try {
            $metaPost = MetaPost::find($this->payload['meta_post_id'] ?? null);
            if ($metaPost) {
                $metaPost->update([
                    'status' => 'fail',
                    'error' => $e->getMessage() ?: class_basename($e),
                ]);
            }
        } catch (Throwable $inner) {
            Log::error('[FB][photos][failed-update:error]', ['err' => $inner->getMessage()]);
        } finally {
            $this->cleanupTemp();
        }

        Log::error('[FB][photos][failed]', [
            'meta_post_id' => $this->payload['meta_post_id'] ?? null,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    protected function cleanupTemp(): void
    {
        // Puede venir como arrays
        $rels = (array) ($this->payload['cleanup_rel'] ?? []);
        $abss = (array) ($this->payload['cleanup_abs'] ?? []);

        foreach ($rels as $r) {
            try {
                if ($r)
                    Storage::delete($r);
            } catch (Throwable $e) {
            }
        }
        foreach ($abss as $a) {
            if ($a && file_exists($a))
                @unlink($a);
        }
    }

    private function fetchPermalinkQuick(?string $postId, string $token): ?string
    {
        if (!$postId)
            return null;
        try {
            $r = Http::get("https://graph.facebook.com/v23.0/{$postId}", [
                'fields' => 'permalink_url',
                'access_token' => $token,
            ]);
            return $r->ok() ? (data_get($r->json(), 'permalink_url') ?: null) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
