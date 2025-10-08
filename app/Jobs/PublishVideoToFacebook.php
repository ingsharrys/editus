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

    /** Videos suelen tardar más */
    public $tries = 6;
    public $backoff = [10, 30, 60, 120, 180, 240];
    public $timeout = 1800; // 30 min

    /** Payload: meta_post_id, page_id, page_token, video_url, caption, cleanup_rel[], cleanup_abs[] */
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
            Log::warning('[FB][video][job] MetaPost no encontrado', [
                'trace' => $trace,
                'id' => $this->payload['meta_post_id'] ?? null,
            ]);
            return;
        }

        // Cargar relación para resolver token desde meta_page_user
        $metaPost->loadMissing('page.users');

        // 1) page_id
        $pageId = (string) ($this->payload['page_id']
            ?? ($metaPost->page->page_id ?? $metaPost->page_id ?? '')
        );
        if ($pageId === '' && $metaPost->fb_post_id) {
            $pageId = (string) (explode('_', $metaPost->fb_post_id)[0] ?? '');
        }

        // 2) page_token: payload > pivot (dueño) > pivot (cualquiera activo)
        $pageToken = (string) ($this->payload['page_token'] ?? '');
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

        if ($pageId === '' || $pageToken === '') {
            $metaPost->update([
                'status' => 'fail',
                'error' => 'No se pudo resolver page_id o page_token para video.',
            ]);
            Log::warning('[FB][video][auth:resolve:fail]', [
                'trace' => $trace,
                'page_id' => $pageId,
                'meta_post_id' => $metaPost->id,
            ]);
            $this->cleanupTemp();
            return;
        }

        // 3) caption
        $caption = $this->payload['caption'] ?? $metaPost->message ?? null;

        // 4) video_url: payload > local_media.video_url > columna video_url (si la tienes)
        $videoUrl = $this->payload['video_url'] ?? null;
        if (empty($videoUrl)) {
            $lm = json_decode($metaPost->local_media ?? '[]', true) ?: [];
            $videoUrl = $lm['video_url'] ?? null;

            // Inyecta rutas cleanup si no venían
            if (!empty($lm['cleanup_rel']) && empty($this->payload['cleanup_rel'])) {
                $this->payload['cleanup_rel'] = (array) $lm['cleanup_rel'];
            }
            if (!empty($lm['cleanup_abs']) && empty($this->payload['cleanup_abs'])) {
                $this->payload['cleanup_abs'] = (array) $lm['cleanup_abs'];
            }

            // Fallback opcional si guardas en una columna explicitamente
            if (!$videoUrl && !empty($metaPost->video_url ?? null)) {
                $videoUrl = $metaPost->video_url;
            }
        }

        Log::info('[FB][video][start]', [
            'trace' => $trace,
            'meta_post' => $metaPost->id,
            'has_video' => (bool) $videoUrl,
        ]);

        if (empty($videoUrl)) {
            $metaPost->update(['status' => 'fail', 'error' => 'No llegó video_url para publicar.']);
            $this->cleanupTemp();
            return;
        }

        // (Opcional) HEAD rápido
        try {
            $head = $this->http(15, 5, 0)->head($videoUrl);
            if (!$head->ok()) {
                Log::info('[FB][video][head:notok]', [
                    'trace' => $trace,
                    'status' => $head->status(),
                    'ct' => $head->header('Content-Type'),
                ]);
            }
        } catch (Throwable $e) {
            Log::debug('[FB][video][head:exception]', ['err' => $e->getMessage()]);
        }

        // 5) Subir video: /{page-id}/videos   (file_url + description + published=true)
        $payload = [
            'access_token' => $pageToken,
            'file_url'     => $videoUrl,
            'published'    => true, // publicarlo de una
        ];
        if (!empty($caption)) {
            $payload['description'] = $caption;
        }

        try {
            $resp = $this->http()->asForm()
                ->post("https://graph.facebook.com/v23.0/{$pageId}/videos", $payload);

            if (!$resp->ok()) {
                $metaPost->update([
                    'status' => 'fail',
                    'error' => (data_get($resp->json(), 'error.message') ?: $resp->body()) ?: 'Graph error (video)',
                ]);
                Log::warning('[FB][video][upload:fail]', [
                    'trace' => $trace,
                    'status' => $resp->status(),
                    'raw' => $resp->json() ?? $resp->body(),
                ]);
                $this->cleanupTemp();
                return;
            }
        } catch (Throwable $e) {
            $metaPost->update(['status' => 'fail', 'error' => 'Excepción al subir video: ' . $e->getMessage()]);
            Log::warning('[FB][video][upload:exception]', ['trace' => $trace, 'err' => $e->getMessage()]);
            $this->cleanupTemp();
            return;
        }

        $videoId  = data_get($resp->json(), 'id');
        $permalink = $this->fetchPermalinkQuick($videoId, $pageToken);

        // 6) Finalizar
        $metaPost->update([
            'status' => 'success',
            'fb_post_id' => $videoId, // usamos el id del video como referencia del post
            'fb_media_ids' => json_encode([$videoId]),
            'fb_permalink_url' => $permalink,
            'published_at' => now(),
            'error' => null,
        ]);

        RefreshMetaPermalink::dispatch([
            'meta_post_id' => $metaPost->id,
            'page_token' => $pageToken,
        ])->delay(now()->addMinutes(2));

        $this->cleanupTemp();

        Log::info('[FB][video][done]', [
            'trace' => $trace,
            'video_id' => $videoId,
            'permalink' => $permalink,
        ]);
    }

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
            Log::error('[FB][video][failed-update:error]', ['err' => $inner->getMessage()]);
        } finally {
            $this->cleanupTemp();
        }

        Log::error('[FB][video][failed]', [
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
                if ($r) Storage::delete($r);
            } catch (Throwable $e) {}
        }
        foreach ($abss as $a) {
            if ($a && file_exists($a)) @unlink($a);
        }
    }

    private function fetchPermalinkQuick(?string $id, string $token): ?string
    {
        if (!$id) return null;
        try {
            $r = $this->http(30, 10, 2, 800)
                ->get("https://graph.facebook.com/v23.0/{$id}", [
                    'fields' => 'permalink_url',
                    'access_token' => $token,
                ]);
            return $r->ok() ? (data_get($r->json(), 'permalink_url') ?: null) : null;
        } catch (Throwable $e) {
            Log::debug('[FB][video][permalink:exception]', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function http(int $timeout = 120, int $connectTimeout = 15, int $retries = 3, int $sleepMs = 2000)
    {
        return Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $sleepMs, function ($exception) {
                if ($exception instanceof ConnectionException) return true;
                if ($exception instanceof RequestException) {
                    $resp = $exception->response;
                    $status = $resp ? $resp->status() : null;
                    return in_array($status, [408, 425, 429], true) || ($status !== null && $status >= 500);
                }
                return false;
            })
            ->withHeaders(['User-Agent' => 'EditusBot/1.0 (+https://app.editus.online)'])
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
    }
}
