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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class RefreshMetaPermalink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Una sola ejecución por disparo (lo dejamos así como querías) */
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
        if (!$metaPost)
            return;

        // si ya tiene permalink, no hacemos nada
        if ($metaPost->fb_permalink_url)
            return;

        $token = $this->payload['page_token'] ?? null;
        if (!$token)
            return;

        $trace = (string) \Illuminate\Support\Str::uuid();

        try {
            $permalink = null;

            if ($metaPost->type === 'video') {
                // En video normalmente fb_post_id guarda el video_id
                $videoId = $metaPost->fb_post_id;
                if ($videoId) {
                    $vr = $this->http(30, 10, 2, 800)->get("https://graph.facebook.com/v23.0/{$videoId}", [
                        'fields' => 'permalink_url,post_id,status',
                        'access_token' => $token,
                    ]);

                    if ($vr->ok()) {
                        $vj = $vr->json();
                        $permalink = data_get($vj, 'permalink_url');

                        if (!$permalink && ($postId = data_get($vj, 'post_id'))) {
                            $pr = $this->http(30, 10, 2, 800)->get("https://graph.facebook.com/v23.0/{$postId}", [
                                'fields' => 'permalink_url',
                                'access_token' => $token,
                            ]);
                            if ($pr->ok()) {
                                $permalink = data_get($pr->json(), 'permalink_url');
                            } else {
                                Log::debug('[permalink][fetch:post:fail]', [
                                    'trace' => $trace,
                                    'status' => $pr->status(),
                                    'raw' => $pr->json() ?? $pr->body(),
                                ]);
                            }
                        }
                    } else {
                        Log::debug('[permalink][fetch:video:fail]', [
                            'trace' => $trace,
                            'status' => $vr->status(),
                            'raw' => $vr->json() ?? $vr->body(),
                        ]);
                    }
                }
            } else {
                // Texto/Fotos: fb_post_id es el post de /feed
                $postId = $metaPost->fb_post_id;
                if ($postId) {
                    $pr = $this->http(30, 10, 2, 800)->get("https://graph.facebook.com/v23.0/{$postId}", [
                        'fields' => 'permalink_url',
                        'access_token' => $token,
                    ]);
                    if ($pr->ok()) {
                        $permalink = data_get($pr->json(), 'permalink_url');
                    } else {
                        Log::debug('[permalink][fetch:feed:fail]', [
                            'trace' => $trace,
                            'status' => $pr->status(),
                            'raw' => $pr->json() ?? $pr->body(),
                        ]);
                    }
                }
            }

            if ($permalink) {
                $metaPost->update(['fb_permalink_url' => $permalink]);
                Log::info('[permalink][refresh:ok]', [
                    'trace' => $trace,
                    'meta_post' => $metaPost->id,
                    'permalink' => $permalink,
                ]);
            }
        } catch (Throwable $e) {
            Log::debug('[permalink][refresh:error]', [
                'trace' => $trace,
                'meta_post_id' => $metaPost->id ?? null,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * HTTP client endurecido: timeout, connectTimeout, retries y IPv4 forzado.
     *
     * @param int $timeout         segundos de timeout total
     * @param int $connectTimeout  segundos de timeout de conexión
     * @param int $retries         reintentos
     * @param int $sleepMs         milisegundos entre reintentos
     */
    private function http(int $timeout = 30, int $connectTimeout = 10, int $retries = 2, int $sleepMs = 800)
    {
        return Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $sleepMs, function ($exception, $request) {
                if ($exception instanceof ConnectionException)
                    return true; // timeouts/DNS
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
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
    }
}
