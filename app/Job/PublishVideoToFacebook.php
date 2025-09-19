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

class PublishVideoToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 5;
    public $backoff = [10, 30, 60, 120, 300];   // backoff progresivo
    public $timeout = 1200;                     // 20 min para videos pesados

    protected array $payload;

    public function __construct(array $payload)
    {
        // payload: meta_post_id, page_id, page_name, page_token, public_url, cleanup_rel, cleanup_abs, caption
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $t0 = microtime(true);
        $trace = (string) \Illuminate\Support\Str::uuid();

        $metaPost = MetaPost::find($this->payload['meta_post_id']);
        if (!$metaPost) {
            Log::warning('[FB][job] MetaPost no encontrado', ['trace' => $trace, 'id' => $this->payload['meta_post_id']]);
            return;
        }

        // Marcar en progreso
        $metaPost->update(['status' => 'processing']);

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
                'trace' => $trace,
                'status'=> $resp->status(),
                'msg'   => $msg,
                'raw'   => is_string($body) ? mb_substr($body, 0, 1000) : $body,
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
                            'fields' => 'permalink_url',
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
        } catch (\Throwable $e) {
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

    protected function cleanupTemp(): void
    {
        // Intentamos eliminar el temporal si vino en payload
        $rel = $this->payload['cleanup_rel'] ?? null;
        $abs = $this->payload['cleanup_abs'] ?? null;

        try {
            if ($rel) Storage::delete($rel);
        } catch (\Throwable $e) {}

        if ($abs && file_exists($abs)) {
            @unlink($abs);
        }
    }
}
