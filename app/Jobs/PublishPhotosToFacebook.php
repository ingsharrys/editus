<?php

namespace App\Jobs;

use App\Models\MetaPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Jobs\RefreshMetaPermalink;

class PublishPhotosToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Más tolerante a redes lentas / intermitentes */
    public $tries = 6;
    public $backoff = [5, 15, 30, 60, 120, 180];
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
            Log::warning('[FB][photos][job] MetaPost no encontrado', [
                'trace' => $trace,
                'id' => $this->payload['meta_post_id'] ?? null,
            ]);
            return;
        }

        // Cargar relación para resolver token desde el pivot meta_page_user
        $metaPost->loadMissing('page.users');

        // 1) Resolver page_id: payload > relación > columna suelta > inferir de fb_post_id
        $pageId = (string) ($this->payload['page_id']
            ?? ($metaPost->page->page_id ?? $metaPost->page_id ?? '')
        );
        if ($pageId === '' && $metaPost->fb_post_id) {
            $pageId = (string) (explode('_', $metaPost->fb_post_id)[0] ?? '');
        }

        // 2) Resolver page_token: payload > pivot (dueño) > pivot (cualquiera activo)
        $pageToken = (string) ($this->payload['page_token'] ?? '');
        if ($pageToken === '') {
            $candidates = optional($metaPost->page)->users ?? collect();

            // válidos: pivot activo y con token
            $candidates = $candidates->filter(function ($u) {
                return (int) ($u->pivot->is_active ?? 0) === 1
                    && !empty($u->pivot->page_access_token);
            });

            // primero el dueño del MetaPost
            $ownerTok = optional($candidates->firstWhere('id', $metaPost->user_id))
                ->pivot->page_access_token ?? null;

            $pageToken = $ownerTok ?: ($candidates->first()->pivot->page_access_token ?? '');
        }

        if ($pageId === '' || $pageToken === '') {
            $metaPost->update([
                'status' => 'fail',
                'error' => 'No se pudo resolver page_id o page_token desde meta_page_user.',
            ]);
            Log::warning('[FB][photos][auth:resolve:fail]', [
                'trace' => $trace,
                'page_id' => $pageId,
                'meta_post_id' => $metaPost->id,
            ]);
            $this->cleanupTemp();
            return;
        }

        // 3) Caption: payload o el mensaje del MetaPost (útil en reintentos)
        $caption = $this->payload['caption'] ?? $metaPost->message ?? null;

        // 4) URLs: payload > local_media > (opcional) columna photo_urls
        $urls = (array) ($this->payload['photo_urls'] ?? []);
        if (empty($urls)) {
            $lm = json_decode($metaPost->local_media ?? '[]', true) ?: [];
            $urls = array_values(array_filter((array) ($lm['photo_urls'] ?? [])));

            // Inyectar rutas de limpieza si no vinieron en el payload
            if (!empty($lm['cleanup_rel']) && empty($this->payload['cleanup_rel'])) {
                $this->payload['cleanup_rel'] = (array) $lm['cleanup_rel'];
            }
            if (!empty($lm['cleanup_abs']) && empty($this->payload['cleanup_abs'])) {
                $this->payload['cleanup_abs'] = (array) $lm['cleanup_abs'];
            }

            // (fallback opcional) si guardaste JSON en $metaPost->photo_urls
            if (empty($urls) && !empty($metaPost->photo_urls)) {
                $pj = json_decode($metaPost->photo_urls, true) ?: [];
                $urls = array_values(array_filter((array) ($pj['photo_urls'] ?? $pj)));
            }
        }

        Log::info('[FB][photos][start]', [
            'trace' => $trace,
            'meta_post' => $metaPost->id,
            'count' => count($urls),
        ]);

        if (empty($urls)) {
            $metaPost->update(['status' => 'fail', 'error' => 'No llegaron URLs de fotos']);
            $this->cleanupTemp();
            return;
        }

        // 5) Subir cada foto como "unpublished"
        $media = [];
        foreach ($urls as $idx => $u) {
            try {
                $r = $this->http()
                    ->asForm()
                    ->post("https://graph.facebook.com/v23.0/{$pageId}/photos", [
                        'published' => false,
                        'url' => $u,
                        'access_token' => $pageToken,
                    ]);

                if ($r->ok() && ($id = data_get($r->json(), 'id'))) {
                    $media[] = ['media_fbid' => $id];
                } else {
                    Log::warning('[FB][photos][create:fail]', [
                        'trace' => $trace,
                        'idx' => $idx,
                        'url' => $u,
                        'status' => $r->status(),
                        'raw' => $r->json() ?? $r->body(),
                    ]);
                }
            } catch (ConnectionException $e) {
                Log::warning('[FB][photos][create:timeout]', [
                    'trace' => $trace,
                    'idx' => $idx,
                    'url' => $u,
                    'err' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                Log::warning('[FB][photos][create:error]', [
                    'trace' => $trace,
                    'idx' => $idx,
                    'url' => $u,
                    'err' => $e->getMessage(),
                ]);
            }

            usleep(200 * 1000); // 200ms
        }

        if (empty($media)) {
            $metaPost->update([
                'status' => 'fail',
                'error' => 'Ninguna imagen se pudo subir a Meta (timeouts / errores).',
            ]);
            $this->cleanupTemp();
            return;
        }

        // 6) Crear el post en /feed con attached_media[*]
        $payload = ['access_token' => $pageToken];
        if (!empty($caption)) {
            $payload['message'] = $caption;
        }
        foreach ($media as $i => $m) {
            $payload["attached_media[$i]"] = json_encode($m);
        }

        try {
            $resp = $this->http()
                ->asForm()
                ->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);

            if (!$resp->ok()) {
                $metaPost->update([
                    'status' => 'fail',
                    'error' => (data_get($resp->json(), 'error.message') ?: $resp->body()) ?: 'Graph error',
                ]);
                Log::warning('[FB][photos][feed:fail]', [
                    'trace' => $trace,
                    'status' => $resp->status(),
                    'raw' => $resp->json() ?? $resp->body(),
                ]);
                $this->cleanupTemp();
                return;
            }
        } catch (Throwable $e) {
            $metaPost->update(['status' => 'fail', 'error' => 'Excepción al publicar en feed: ' . $e->getMessage()]);
            Log::warning('[FB][photos][feed:exception]', ['trace' => $trace, 'err' => $e->getMessage()]);
            $this->cleanupTemp();
            return;
        }

        $postId = data_get($resp->json(), 'id');
        $permalink = $this->fetchPermalinkQuick($postId, $pageToken);

        // 7) Finalizar
        $metaPost->update([
            'status' => 'success',
            'fb_post_id' => $postId,
            'fb_media_ids' => json_encode(array_map(fn($m) => $m['media_fbid'], $media)),
            'fb_permalink_url' => $permalink,
            'published_at' => now(),
            'error' => null,
        ]);

        RefreshMetaPermalink::dispatch([
            'meta_post_id' => $metaPost->id,
            'page_token' => $pageToken,
        ])->delay(now()->addMinute());

        $this->cleanupTemp();

        Log::info('[FB][photos][done]', [
            'trace' => $trace,
            'post_id' => $postId,
            'permalink' => $permalink,
        ]);
    }


    /** Se ejecuta cuando el Job se agota sin éxito (tras reintentos) */
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
        $rels = (array) ($this->payload['cleanup_rel'] ?? []);
        $abss = (array) ($this->payload['cleanup_abs'] ?? []);

        foreach ($rels as $r) {
            try {
                if ($r)
                    Storage::delete($r);
            } catch (Throwable $e) {
                // noop
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
            $r = $this->http(30, 10, 2, 800) // un poco más corto para GET
                ->get("https://graph.facebook.com/v23.0/{$postId}", [
                    'fields' => 'permalink_url',
                    'access_token' => $token,
                ]);

            return $r->ok() ? (data_get($r->json(), 'permalink_url') ?: null) : null;
        } catch (Throwable $e) {
            Log::debug('[FB][photos][permalink:exception]', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Factory para requests HTTP “blindados” (IPv4, timeout y retries).
     *
     * @param int $timeout         segundos de timeout total (por request)
     * @param int $connectTimeout  segundos de timeout de conexión
     * @param int $retries         cantidad de reintentos
     * @param int $sleepMs         milisegundos entre reintentos
     */
    private function http(int $timeout = 60, int $connectTimeout = 10, int $retries = 3, int $sleepMs = 1500)
    {
        return Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $sleepMs, function ($exception, $request) {
                // Reintentar solo por condiciones recuperables
                if ($exception instanceof ConnectionException)
                    return true; // timeouts, DNS, handshake
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
            // Fuerza IPv4 (evita timeouts típicos por IPv6 roto en algunos hosts)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
    }
}
