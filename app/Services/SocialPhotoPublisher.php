<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\MetaPost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publica UNA foto (por URL pública) con un texto en la página de
 * Facebook y/o en la cuenta de Instagram vinculada de una MetaPage, de
 * forma síncrona (la app espera el resultado). Usa el token de página
 * guardado en meta_page_user, igual que el resto de editus.
 */
class SocialPhotoPublisher
{
    public function __construct(private MetaPageTokenResolver $tokens)
    {
    }

    /**
     * @param  MetaPage $page      página conectada en editus
     * @param  string   $imagenUrl URL pública de la foto (JPEG)
     * @param  string   $mensaje   texto de Facebook (ya con el enlace al final)
     * @param  ?string  $captionInstagram  texto de Instagram (sin enlace: allí no es clicable); si es null se usa $mensaje
     * @param  bool     $facebook  publicar en la página de Facebook
     * @param  bool     $instagram publicar en la cuenta de Instagram vinculada
     * @param  string|null $enlace enlace de la nota web (se guarda en MetaPost.link)
     * @param  string|null $batch  agrupa los posts de una misma publicación
     * @return array{facebook: array|null, instagram: array|null}
     */
    public function publicar(MetaPage $page, string $imagenUrl, string $mensaje, bool $facebook, bool $instagram, ?string $enlace = null, ?string $batch = null, ?string $captionInstagram = null): array
    {
        $salida = ['facebook' => null, 'instagram' => null];

        $token = $this->tokens->forPage($page->page_id);
        if (!$token) {
            $sinToken = ['ok' => false, 'error' => 'La página no tiene un token activo en editus: vuelve a conectarla desde "Páginas".'];
            if ($facebook) $salida['facebook'] = $sinToken;
            if ($instagram) $salida['instagram'] = $sinToken;
            return $salida;
        }

        if ($facebook) {
            $salida['facebook'] = $this->publicarFacebook($page, $token, $imagenUrl, $mensaje, $enlace, $batch);
        }

        if ($instagram) {
            $igId = (string) ($page->instagram_business_account_id ?? '');
            $salida['instagram'] = $igId === ''
                ? ['ok' => false, 'no_configurado' => true, 'error' => 'La página no tiene una cuenta de Instagram vinculada.']
                : $this->publicarInstagram($igId, $token, $imagenUrl, $captionInstagram ?? $mensaje);
        }

        return $salida;
    }

    // ------------------------------------------------------------------

    private function publicarFacebook(MetaPage $page, string $token, string $imagenUrl, string $mensaje, ?string $enlace, ?string $batch): array
    {
        // El dueño del post en editus: el usuario con el token activo de la página
        $usuario = $page->users()->wherePivot('is_active', 1)->first();
        $post = MetaPost::create([
            'batch_uuid' => $batch ?: (string) Str::uuid(),
            'user_id' => $usuario?->id ?? $page->users()->first()?->id ?? 1,
            'meta_page_id' => $page->id,
            'type' => 'photo',
            'message' => $mensaje,
            'link' => $enlace,
            'local_media' => json_encode(['photo_urls' => [$imagenUrl]]),
            'status' => 'pending',
        ]);

        try {
            $r = $this->http()->asForm()->post(self::graph("{$page->page_id}/photos"), [
                'url' => $imagenUrl,
                'message' => $mensaje,
                'published' => 'true',
                'access_token' => $token,
            ]);

            if (!$r->ok()) {
                $error = $this->errorDe($r->json(), $r->body());
                $post->update(['status' => 'fail', 'error' => $error]);
                return ['ok' => false, 'error' => $error];
            }

            $postId = (string) (data_get($r->json(), 'post_id') ?: data_get($r->json(), 'id') ?: '');
            $permalink = $postId !== '' ? $this->permalinkFacebook($postId, $token) : null;

            $post->update([
                'status' => 'success',
                'fb_post_id' => $postId ?: null,
                'fb_media_ids' => json_encode([data_get($r->json(), 'id')]),
                'fb_permalink_url' => $permalink,
                'published_at' => now(),
                'error' => null,
            ]);

            return ['ok' => true, 'post_id' => $postId, 'permalink' => $permalink, 'error' => null];
        } catch (\Throwable $e) {
            $post->update(['status' => 'fail', 'error' => $e->getMessage()]);
            Log::warning('[API][foto][facebook] excepción', ['page' => $page->page_id, 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $this->corto($e->getMessage())];
        }
    }

    private function publicarInstagram(string $igId, string $token, string $imagenUrl, string $caption): array
    {
        try {
            $caption = Str::limit($caption, 2200, '');

            $c = $this->http()->asForm()->post(self::graph("{$igId}/media"), [
                'image_url' => $imagenUrl,
                'caption' => $caption,
                'access_token' => $token,
            ]);
            if (!$c->ok() || !data_get($c->json(), 'id')) {
                return ['ok' => false, 'error' => $this->errorDe($c->json(), $c->body())];
            }
            $creationId = (string) data_get($c->json(), 'id');

            // El contenedor puede tardar unos segundos en estar listo (código 9007)
            $mediaId = '';
            $ultimo = '';
            for ($i = 0; $i < 6; $i++) {
                $p = $this->http()->asForm()->post(self::graph("{$igId}/media_publish"), [
                    'creation_id' => $creationId,
                    'access_token' => $token,
                ]);
                if ($p->ok() && data_get($p->json(), 'id')) {
                    $mediaId = (string) data_get($p->json(), 'id');
                    break;
                }
                $ultimo = $this->errorDe($p->json(), $p->body());
                $codigo = (int) data_get($p->json(), 'error.code', 0);
                if ($codigo !== 9007 && !str_contains(strtolower($ultimo), 'not available')) {
                    return ['ok' => false, 'error' => $ultimo];
                }
                sleep(3);
            }
            if ($mediaId === '') {
                return ['ok' => false, 'error' => 'Instagram no terminó de procesar la imagen: ' . $ultimo];
            }

            $permalink = null;
            try {
                $info = $this->http(20)->get(self::graph($mediaId), ['fields' => 'permalink', 'access_token' => $token]);
                $permalink = $info->ok() ? data_get($info->json(), 'permalink') : null;
            } catch (\Throwable) {
                // solo informativo
            }

            return ['ok' => true, 'post_id' => $mediaId, 'permalink' => $permalink, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('[API][foto][instagram] excepción', ['ig' => $igId, 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $this->corto($e->getMessage())];
        }
    }

    private function permalinkFacebook(string $postId, string $token): string
    {
        try {
            $r = $this->http(20)->get(self::graph($postId), ['fields' => 'permalink_url', 'access_token' => $token]);
            $p = $r->ok() ? data_get($r->json(), 'permalink_url') : null;
            if ($p) return (string) $p;
        } catch (\Throwable) {
            // se usa el enlace genérico
        }
        return 'https://www.facebook.com/' . $postId;
    }

    // ------------------------------------------------------------------

    public static function graph(string $path): string
    {
        $v = config('services.facebook.version', 'v23.0');
        $host = rtrim((string) (config('services.facebook.graph_url') ?: 'https://graph.facebook.com'), '/');
        return "{$host}/{$v}/{$path}";
    }

    private function http(int $timeout = 90): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(15)
            ->retry(2, 1500, function ($e) {
                if ($e instanceof ConnectionException) return true;
                if ($e instanceof RequestException) {
                    $s = $e->response?->status();
                    return $s !== null && $s >= 500;
                }
                return false;
            }, throw: false)
            ->withHeaders(['User-Agent' => 'EditusBot/1.0 (+https://app.editus.online)'])
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
    }

    private function errorDe(?array $json, string $body): string
    {
        $msg = data_get($json, 'error.error_user_msg') ?: data_get($json, 'error.message') ?: Str::limit($body, 200, '');
        $code = data_get($json, 'error.code');
        return $this->corto((string) $msg . ($code ? " (código {$code})" : ''));
    }

    private function corto(string $s): string
    {
        return Str::limit(trim($s), 300, '');
    }
}
