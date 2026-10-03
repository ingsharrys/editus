<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\MetaPost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publica un VIDEO (mp4 en una URL pública) como video de página en
 * Facebook y como Reel en Instagram. Mismo contrato que SocialPhotoPublisher.
 *
 * Facebook: POST {page}/videos (file_url) en graph-video y se espera a que
 * termine de procesarse (hasta ~60 s; si sigue, se informa "procesando").
 * Instagram: contenedor REELS → se espera a FINISHED (hasta ~150 s) →
 * media_publish → permalink.
 */
class SocialVideoPublisher extends SocialPhotoPublisher
{
    /** Segundos máximos de espera por el procesamiento en cada red. */
    public int $esperaFacebook = 60;
    public int $esperaInstagram = 150;
    /** Pausa entre consultas de estado (segundos); en pruebas se pone en 0. */
    public int $pausa = 5;

    public function publicarVideo(MetaPage $page, string $videoUrl, string $mensaje, bool $facebook, bool $instagram, ?string $enlace = null, ?string $batch = null, ?string $captionInstagram = null, ?string $portadaUrl = null): array
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
            $salida['facebook'] = $this->videoFacebook($page, $token, $videoUrl, $mensaje, $enlace, $batch, $portadaUrl);
        }
        if ($instagram) {
            $igId = (string) ($page->instagram_business_account_id ?? '');
            $salida['instagram'] = $igId === ''
                ? ['ok' => false, 'no_configurado' => true, 'error' => 'La página no tiene una cuenta de Instagram vinculada']
                : $this->reelInstagram($igId, $token, $videoUrl, $captionInstagram ?? $mensaje, $portadaUrl);
        }
        return $salida;
    }

    // ------------------------------------------------------------------

    private function videoFacebook(MetaPage $page, string $token, string $videoUrl, string $mensaje, ?string $enlace, ?string $batch, ?string $portadaUrl): array
    {
        $usuario = $page->users()->wherePivot('is_active', 1)->first();
        $post = MetaPost::create([
            'batch_uuid' => $batch ?: (string) Str::uuid(),
            'user_id' => $usuario?->id ?? $page->users()->first()?->id ?? 1,
            'meta_page_id' => $page->id,
            'type' => 'video',
            'message' => $mensaje,
            'link' => $enlace,
            'local_media' => json_encode(['video_url' => $videoUrl, 'thumb_url' => $portadaUrl]),
            'status' => 'pending',
        ]);

        try {
            $datos = [
                'file_url' => $videoUrl,
                'description' => $mensaje,
                'published' => 'true',
                'access_token' => $token,
            ];
            $r = $this->http(180)->asForm()->post(self::graphVideo("{$page->page_id}/videos"), $datos);
            if (!$r->ok() || !data_get($r->json(), 'id')) {
                $error = $this->errorDe($r->json(), $r->body());
                $post->update(['status' => 'fail', 'error' => $error]);
                return ['ok' => false, 'error' => $error];
            }
            $videoId = (string) data_get($r->json(), 'id');

            // Esperar a que Facebook procese el video (para tener post_id y permalink)
            $postId = '';
            $permalink = null;
            $estado = 'processing';
            $limite = time() + $this->esperaFacebook;
            do {
                $info = $this->http(20)->get(self::graph($videoId), ['fields' => 'status,permalink_url,post_id', 'access_token' => $token]);
                if ($info->ok()) {
                    $estado = (string) data_get($info->json(), 'status.video_status', 'processing');
                    $postId = (string) (data_get($info->json(), 'post_id') ?: '');
                    $rel = (string) (data_get($info->json(), 'permalink_url') ?: '');
                    if ($rel !== '') $permalink = str_starts_with($rel, 'http') ? $rel : 'https://www.facebook.com' . $rel;
                }
                if ($estado === 'ready' || $estado === 'error') break;
                if (time() >= $limite) break;
                if ($this->pausa > 0) sleep($this->pausa);
            } while (true);

            if ($estado === 'error') {
                $post->update(['status' => 'fail', 'error' => 'Facebook no pudo procesar el video']);
                return ['ok' => false, 'error' => 'Facebook no pudo procesar el video (formato o tamaño no admitidos)'];
            }

            $permalink = $permalink ?: "https://www.facebook.com/{$page->page_id}/videos/{$videoId}";
            // Miniatura que generó Facebook (portada de la nota en la web)
            $miniatura = null;
            try {
                $pic = $this->http(20)->get(self::graph($videoId), ['fields' => 'picture', 'access_token' => $token]);
                $miniatura = $pic->ok() ? (data_get($pic->json(), 'picture') ?: null) : null;
            } catch (\Throwable) {
                // solo informativo
            }
            $post->update([
                'status' => 'success',
                'fb_post_id' => $postId ?: $videoId,
                'fb_media_ids' => json_encode([$videoId]),
                'fb_permalink_url' => $permalink,
                'published_at' => now(),
                'error' => null,
            ]);

            return ['ok' => true, 'post_id' => $postId ?: $videoId, 'media_id' => $videoId, 'permalink' => $permalink, 'miniatura' => $miniatura, 'procesando' => $estado !== 'ready', 'error' => null];
        } catch (\Throwable $e) {
            $post->update(['status' => 'fail', 'error' => $e->getMessage()]);
            Log::warning('[API][video][facebook] excepción', ['page' => $page->page_id, 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $this->corto($e->getMessage())];
        }
    }

    private function reelInstagram(string $igId, string $token, string $videoUrl, string $caption, ?string $portadaUrl): array
    {
        try {
            $caption = Str::limit($caption, 2200, '');
            $datos = [
                'media_type' => 'REELS',
                'video_url' => $videoUrl,
                'caption' => $caption,
                'share_to_feed' => 'true',
                'access_token' => $token,
            ];
            if ($portadaUrl) $datos['cover_url'] = $portadaUrl;

            $c = $this->http()->asForm()->post(self::graph("{$igId}/media"), $datos);
            if (!$c->ok() || !data_get($c->json(), 'id')) {
                return ['ok' => false, 'error' => $this->errorDe($c->json(), $c->body())];
            }
            $creationId = (string) data_get($c->json(), 'id');

            // Esperar a que Instagram procese el contenedor
            $estado = 'IN_PROGRESS';
            $detalle = '';
            $limite = time() + $this->esperaInstagram;
            do {
                $st = $this->http(20)->get(self::graph($creationId), ['fields' => 'status_code,status', 'access_token' => $token]);
                if ($st->ok()) {
                    $estado = (string) data_get($st->json(), 'status_code', 'IN_PROGRESS');
                    $detalle = (string) data_get($st->json(), 'status', '');
                }
                if (in_array($estado, ['FINISHED', 'ERROR', 'EXPIRED'], true)) break;
                if (time() >= $limite) break;
                if ($this->pausa > 0) sleep($this->pausa);
            } while (true);

            if ($estado !== 'FINISHED') {
                $motivo = $estado === 'IN_PROGRESS'
                    ? 'Instagram sigue procesando el video; vuelve a intentarlo en unos minutos'
                    : 'Instagram rechazó el video: ' . ($detalle ?: $estado) . ' (los Reels deben ser MP4 vertical, máx. 15 min)';
                return ['ok' => false, 'error' => $this->corto($motivo)];
            }

            $p = $this->http()->asForm()->post(self::graph("{$igId}/media_publish"), [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]);
            if (!$p->ok() || !data_get($p->json(), 'id')) {
                return ['ok' => false, 'error' => $this->errorDe($p->json(), $p->body())];
            }
            $mediaId = (string) data_get($p->json(), 'id');

            $permalink = null;
            try {
                $info = $this->http(20)->get(self::graph($mediaId), ['fields' => 'permalink', 'access_token' => $token]);
                $permalink = $info->ok() ? data_get($info->json(), 'permalink') : null;
            } catch (\Throwable) {
                // solo informativo
            }
            return ['ok' => true, 'post_id' => $mediaId, 'media_id' => $mediaId, 'permalink' => $permalink, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('[API][video][instagram] excepción', ['ig' => $igId, 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $this->corto($e->getMessage())];
        }
    }

    /** Host de subida de videos de Facebook. */
    public static function graphVideo(string $path): string
    {
        $v = config('services.facebook.version', 'v23.0');
        $host = rtrim((string) (config('services.facebook.graph_video_url') ?: 'https://graph-video.facebook.com'), '/');
        return "{$host}/{$v}/{$path}";
    }
}
