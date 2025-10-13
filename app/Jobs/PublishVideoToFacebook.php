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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;
use App\Jobs\RefreshMetaPermalink;

class PublishVideoToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reintentos por job */
    public $tries = 5;

    /** Backoff progresivo */
    public $backoff = [10, 30, 60, 120, 300];

    /** Timeout duro del job (segundos) */
    public $timeout = 1200;

    /** Payload: meta_post_id, page_id, page_name, page_token, public_url, cleanup_rel, cleanup_abs, caption */
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $t0 = microtime(true);
        $trace = (string) \Illuminate\Support\Str::uuid();

        $metaPost = MetaPost::find($this->payload['meta_post_id'] ?? null);
        if (!$metaPost) {
            Log::warning('[FB][video][job] MetaPost no encontrado', [
                'trace' => $trace,
                'id' => $this->payload['meta_post_id'] ?? null,
            ]);
            return;
        }

        $metaPost->loadMissing('page.users');
        $metaPost->update(['status' => 'pending']);

        // === Resolver auth de forma robusta (igual que fotos) ===
        [$pageId, $pageToken] = $this->resolveAuth($metaPost, $this->payload);

        if ($pageId === '' || $pageToken === '') {
            $metaPost->update([
                'status' => 'fail',
                'error'  => 'No se pudo resolver page_id o page_token.',
            ]);
            $this->cleanupTemp();
            return;
        }

        $fileUrl = (string) ($this->payload['public_url'] ?? '');
        $caption = $this->payload['caption'] ?? $metaPost->message ?? null;

        Log::info('[FB][video][start]', [
            'trace' => $trace,
            'meta_post' => $metaPost->id,
            'page_id' => $pageId,
            'file_url' => $fileUrl,
        ]);

        if ($fileUrl === '') {
            $metaPost->update(['status' => 'fail', 'error' => 'public_url vacío']);
            $this->cleanupTemp();
            return;
        }

        // === Preflight al file_url (HEAD, redirects, MIME, size) ===
        $pre = $this->preflightUrl($fileUrl);
        Log::debug('[FB][video][preflight]', ['trace' => $trace] + $pre);

        if (($pre['ok'] ?? false) === false) {
            $metaPost->update(['status' => 'fail', 'error' => 'Video URL no accesible: ' . ($pre['reason'] ?? 'unknown')]);
            $this->cleanupTemp();
            return;
        }

        // === 1) Crear video en graph-video ===
        $endpoint = "https://graph-video.facebook.com/v23.0/{$pageId}/videos";
        $resp = $this->http(600, 15, 3, 1500) // más generoso en tiempo
            ->asForm()
            ->post($endpoint, array_filter([
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

            Log::warning('[FB][video][create:fail]', [
                'trace' => $trace,
                'status' => $resp->status(),
                'msg' => $msg,
                'raw' => is_string($body) ? mb_substr($body, 0, 1000) : $body,
            ]);

            $this->cleanupTemp();
            return;
        }

        $jsonCreate = $resp->json();
        $videoId = data_get($jsonCreate, 'id');
        if (!$videoId) {
            $metaPost->update(['status' => 'fail', 'error' => 'Sin video_id en respuesta']);
            Log::warning('[FB][video][create:no_video_id]', ['trace' => $trace, 'resp' => $jsonCreate]);
            $this->cleanupTemp();
            return;
        }

        Log::info('[FB][video][create:ok]', ['trace' => $trace, 'video_id' => $videoId]);

        // === 2) Poll para estado/permalink/post_id ===
        $permalink = null;
        $postId    = null;
        try {
            $maxTries = 12;
            $delays   = [2, 3, 5, 5, 6, 8, 8, 10, 12, 15, 15, 20];

            for ($i = 0; $i < $maxTries; $i++) {
                $vr = $this->http(45, 10, 2, 800)
                    ->get("https://graph.facebook.com/v23.0/{$videoId}", [
                        'fields' => 'status,processing_progress,permalink_url,post_id',
                        'access_token' => $pageToken,
                    ]);

                if ($vr->ok()) {
                    $vjson     = $vr->json();
                    $state     = data_get($vjson, 'status.video_status'); // processing|ready|error
                    $progress  = data_get($vjson, 'processing_progress');
                    $permalink = data_get($vjson, 'permalink_url') ?: $permalink;
                    $postId    = data_get($vjson, 'post_id') ?: $postId;

                    Log::debug('[FB][video][poll]', [
                        'trace' => $trace,
                        'try' => $i + 1,
                        'state' => $state,
                        'progress' => $progress,
                        'has_link' => (bool) $permalink,
                        'post_id' => $postId,
                    ]);

                    if ($state === 'error') {
                        $reason = data_get($vjson, 'status.failure_reason') ?: 'processing_failed';
                        $metaPost->update(['status' => 'fail', 'error' => $reason]);
                        Log::warning('[FB][video][poll:error]', ['trace' => $trace, 'reason' => $reason]);
                        $this->cleanupTemp();
                        return;
                    }

                    // si ya está listo o ya tenemos permalink, cortamos
                    if ($state === 'ready' && ($permalink || $postId)) {
                        break;
                    }

                    // si hay post_id pero sin permalink aún, intenta resolver permalink por el post
                    if ($postId && !$permalink) {
                        $pr = $this->http(30, 10, 2, 800)
                            ->get("https://graph.facebook.com/v23.0/{$postId}", [
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
        } catch (Throwable $e) {
            Log::warning('[FB][video][poll:exception]', ['trace' => $trace, 'err' => $e->getMessage()]);
        }

        // === 3) Finalizar: usar post_id si existe; si no, el videoId ===
        $finalPostId = $postId ?: $videoId;

        $metaPost->update([
            'status'           => 'success',
            'fb_post_id'       => $finalPostId,           // Prioriza post_id (post de feed)
            'fb_media_ids'     => json_encode([$videoId]),
            'fb_permalink_url' => $permalink,
            'published_at'     => now(),
            'error'            => null,
        ]);

        // Refrescar permalink más tarde (videos suelen demorar)
        RefreshMetaPermalink::dispatch([
            'meta_post_id' => $metaPost->id,
            'page_token'   => $pageToken,
        ])->delay(now()->addMinutes(10));

        $this->cleanupTemp();

        Log::info('[FB][video][done]', [
            'trace' => $trace,
            'video_id' => $videoId,
            'post_id' => $finalPostId,
            'permalink' => $permalink,
            'elapsed_ms' => (int) ((microtime(true) - $t0) * 1000),
        ]);
    }

    public function failed(Throwable $e): void
    {
        try {
            $metaPostId = $this->payload['meta_post_id'] ?? null;

            if ($metaPostId && ($metaPost = MetaPost::find($metaPostId))) {
                $metaPost->update([
                    'status' => 'fail',
                    'error'  => $e->getMessage() ?: class_basename($e),
                ]);
            }

            Log::error('[FB][video][failed]', [
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
            Log::debug('[FB][video][cleanup:rel:error]', ['err' => $e->getMessage(), 'rel' => $rel]);
        }

        if ($abs && file_exists($abs)) {
            @unlink($abs);
        }
    }

    /**
     * Igual que en fotos: cliente HTTP con IPv4, retries, timeouts.
     */
    private function http(int $timeout = 60, int $connectTimeout = 10, int $retries = 3, int $sleepMs = 1500)
    {
        return Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $sleepMs, function ($exception, $request) {
                if ($exception instanceof ConnectionException) return true; // DNS/handshake/timeouts
                if ($exception instanceof RequestException) {
                    $resp = $exception->response;
                    $status = $resp ? $resp->status() : null;
                    return in_array($status, [408, 425, 429], true) || ($status !== null && $status >= 500);
                }
                return false;
            })
            ->withHeaders([
                'User-Agent' => 'EditusBot/1.0 (+https://app.editus.online)',
            ])
            ->withOptions([
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4], // fuerza IPv4
                'allow_redirects' => true,  // sigue 301/302 en preflight/url externas
            ]);
    }

    /**
     * HEAD/GET ligero para validar accesibilidad y tipo del file_url.
     */
    private function preflightUrl(string $url): array
    {
        try {
            // HEAD primero, con fallback a GET si el host no soporta HEAD
            $head = $this->http(20, 8, 2, 500)->send('HEAD', $url);
            if (!$head->successful()) {
                // fallback a GET (sin descargar el cuerpo completo)
                $get = $this->http(20, 8, 2, 500)->withHeaders(['Range' => 'bytes=0-0'])->get($url);
                if (!$get->successful()) {
                    return ['ok' => false, 'status' => $get->status(), 'reason' => 'HTTP '. $get->status()];
                }
                $ct = $get->header('Content-Type');
                $cl = $get->header('Content-Length');
                return ['ok' => true, 'status' => $get->status(), 'content_type' => $ct, 'content_length' => $cl];
            }

            $ct = $head->header('Content-Type');
            $cl = $head->header('Content-Length');
            return ['ok' => true, 'status' => $head->status(), 'content_type' => $ct, 'content_length' => $cl];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Replica la resolución de token/page_id robusta del job de fotos.
     */
    private function resolveAuth(MetaPost $metaPost, array $payload): array
    {
        // page_id
        $pageId = (string) ($payload['page_id']
            ?? ($metaPost->page->page_id ?? $metaPost->page_id ?? '')
        );
        if ($pageId === '' && $metaPost->fb_post_id) {
            $pageId = (string) (explode('_', $metaPost->fb_post_id)[0] ?? '');
        }

        // page_token
        $pageToken = (string) ($payload['page_token'] ?? '');
        if ($pageToken === '') {
            $candidates = optional($metaPost->page)->users ?? collect();
            $candidates = $candidates->filter(function ($u) {
                return (int) ($u->pivot->is_active ?? 0) === 1
                    && !empty($u->pivot->page_access_token);
            });

            $ownerTok = optional($candidates->firstWhere('id', $metaPost->user_id))
                ->pivot->page_access_token ?? null;

            $pageToken = $ownerTok ?: ($candidates->first()->pivot->page_access_token ?? '');
        }

        return [$pageId, $pageToken];
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
