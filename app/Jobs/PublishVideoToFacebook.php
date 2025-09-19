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

class PublishVideoToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reintentos por job (overridea el --tries del worker) */
    public $tries = 5;

    /** Backoff progresivo entre reintentos */
    public $backoff = [10, 30, 60, 120, 300];

    /** Timeout duro del job (segundos) */
    public $timeout = 1200;

    /** NO declares $queue aquí; el trait Queueable ya la define */
    // public $queue = 'default';  <-- QUITAR

    /** Payload: meta_post_id, page_id, page_name, page_token, public_url, cleanup_rel, cleanup_abs, caption */
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;

        // Si quieres forzar la cola, hazlo así (del trait Queueable):
        $this->onQueue('default');
        // (Opcional) fuerza conexión si usas 'database' u otra:
        // $this->onConnection('database');
    }

    public function handle(): void
    {
        $t0    = microtime(true);
        $trace = (string) \Illuminate\Support\Str::uuid();

        $metaPost = MetaPost::find($this->payload['meta_post_id']);
        if (!$metaPost) {
            Log::warning('[FB][job] MetaPost no encontrado', ['trace' => $trace, 'id' => $this->payload['meta_post_id'] ?? null]);
            return;
        }

        // Mantén solo estados permitidos por tu ENUM (pending/success/fail)
        $metaPost->update(['status' => 'pending']);

        $pageId    = $this->payload['page_id'];
        $pageToken = $this->payload['page_token'];
        $fileUrl   = $this->payload['public_url'];
        $caption   = $this->payload['caption'];

        Log::info('[FB][job][start]', [
            'trace'     => $trace,
            'meta_post' => $metaPost->id,
            'page_id'   => $pageId,
            'file_url'  => $fileUrl,
        ]);

        // 1) Crear el video con file_url (graph-video)
        $endpoint = "https://graph-video.facebook.com/v23.0/{$pageId}/videos";
        $resp = Http::asForm()->post($endpoint, array_filter([
            'file_url'     => $fileUrl,
            'description'  => $caption,
            'published'    => true,
            'access_token' => $pageToken,
        ], fn($v) => !is_null($v)));

        if (!$resp->ok()) {
            $body = $resp->json() ?? $resp->body();
            $msg  = is_array($body) ? data_get($body, 'error.message') : (string) $body;

            $metaPost->update([
                'status' => 'fail',
                'error'  => $msg ?: 'Graph error',
            ]);

            Log::warning('[FB][job][create:fail]', [
                'trace'  => $trace,
                'status' => $resp->status(),
                'msg'    => $msg,
                'raw'    => is_string($body) ? mb_substr($body, 0, 1000) : $body,
            ]);

            $this->cleanupTemp();
            return;
        }

        $jsonCreate = $resp->json();
        $videoId    = data_get($jsonCreate, 'id');

        if (!$videoId) {
            $metaPost->update([
                'status' => 'fail',
                'error'  => 'Sin video_id en respuesta',
            ]);

            Log::warning('[FB][job][create:no_video_id]', ['trace' => $trace, 'resp' => $jsonCreate]);
            $this->cleanupTemp();
            return;
        }

        Log::info('[FB][job][create:ok]', ['trace' => $trace, 'video_id' => $videoId]);

        // 2) Poll para permalink o post_id
        $permalink = null;
        try {
            $maxTries = 12;
            $delays   = [2,3,5,5,6,8,8,10,12,15,15,20];

            for ($i = 0; $i < $maxTries; $i++) {
                $vr = Http::get("https://graph.facebook.com/v23.0/{$videoId}", [
                    'fields'       => 'status,processing_progress,permalink_url,post_id',
                    'access_token' => $pageToken,
                ]);

                if ($vr->ok()) {
                    $vjson     = $vr->json();
                    $state     = data_get($vjson, 'status.video_status'); // processing|ready|error
                    $permalink = data_get($vjson, 'permalink_url');
                    $postId    = data_get($vjson, 'post_id');

                    Log::debug('[FB][job][poll]', [
                        'trace'     => $trace,
                        'try'       => $i+1,
                        'state'     => $state,
                        'progress'  => data_get($vjson, 'processing_progress'),
                        'has_link'  => (bool) $permalink,
                        'post_id'   => $postId,
                    ]);

                    if ($state === 'error') {
                        $reason = data_get($vjson, 'status.failure_reason') ?: 'processing_failed';
                        $metaPost->update(['status' => 'fail', 'error' => $reason]);
                        Log::warning('[FB][job][poll:error]', ['trace' => $trace, 'reason' => $reason]);
                        $this->cleanupTemp();
                        return;
                    }

                    if ($permalink) break;

                    if ($postId && !$permalink) {
                        $pr = Http::get("https://graph.facebook.com/v23.0/{$postId}", [
                            'fields'       => 'permalink_url',
                            'access_token' => $pageToken,
                        ]);
                        if ($pr->ok()) {
                            $permalink = data_get($pr->json(), 'permalink_url') ?: $permalink;
                            if ($permalink) break;
                        }
                    }
                }

                sleep($delays[$i] ?? 10);
            }
        } catch (Throwable $e) {
            Log::warning('[FB][job][poll:exception]', ['trace' => $trace, 'err' => $e->getMessage()]);
        }

        // 3) Finaliza y limpia
        $metaPost->update([
            'status'           => 'success',
            'fb_post_id'       => $videoId,
            'fb_media_ids'     => json_encode([$videoId]),
            'fb_permalink_url' => $permalink,
            'published_at'     => now(),
            'error'            => null,
        ]);

        $this->cleanupTemp();

        Log::info('[FB][job][done]', [
            'trace'      => $trace,
            'video_id'   => $videoId,
            'permalink'  => $permalink,
            'elapsed_ms' => (int) ((microtime(true) - $t0) * 1000),
        ]);
    }

    public function failed(Throwable $e): void
    {
        try {
            $metaPostId = $this->payload['meta_post_id'] ?? null;

            if ($metaPostId) {
                if ($metaPost = MetaPost::find($metaPostId)) {
                    $metaPost->update([
                        'status' => 'fail',
                        'error'  => $e->getMessage() ?: class_basename($e),
                    ]);
                }
            }

            Log::error('[FB][job][failed]', [
                'meta_post_id' => $metaPostId,
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'file'         => $e->getFile() . ':' . $e->getLine(),
            ]);
        } finally {
            $this->cleanupTemp();
        }
    }

    protected function cleanupTemp(): void
    {
        $rel = $this->payload['cleanup_rel'] ?? null;
        $abs = $this->payload['cleanup_abs'] ?? null;

        try {
            if ($rel) Storage::delete($rel);
        } catch (Throwable $e) {
            Log::debug('[FB][job][cleanup:rel:error]', ['err' => $e->getMessage(), 'rel' => $rel]);
        }

        if ($abs && file_exists($abs)) {
            @unlink($abs);
        }
    }

    public function tags(): array
    {
        return [
            'fb',
            'video',
            'page:' . ($this->payload['page_id'] ?? 'n/a'),
            'meta_post:' . ($this->payload['meta_post_id'] ?? 'n/a'),
        ];
    }
}
