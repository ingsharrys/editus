<?php

namespace App\Services;

use App\Models\MetaPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Facebook Live de una página (Graph API). Requiere el permiso
 * publish_video en el token de la página (FACEBOOK_LINK_SCOPES).
 */
class FacebookLiveService
{
    public function __construct(private MetaPageTokenResolver $tokens)
    {
    }

    /**
     * Crea la transmisión y devuelve id, stream_url (secreto) y permalink.
     * Con $inicio crea un en vivo PROGRAMADO (hasta 7 días antes): Facebook publica el anuncio con
     * "Recordarme" y la pone al aire a esa hora si ya está recibiendo señal.
     */
    public function crear(MetaPage $page, string $titulo, string $descripcion, ?\DateTimeInterface $inicio = null, ?string $miniatura = null): array
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) throw new \RuntimeException('La página no tiene un token activo en editus');

        $datos = [
            'status' => $inicio ? 'SCHEDULED_UNPUBLISHED' : 'LIVE_NOW',
            'title' => Str::limit($titulo, 250, ''),
            'description' => Str::limit($descripcion, 5000, ''),
            'access_token' => $token,
        ];
        if ($inicio) $datos['event_params'] = json_encode(['start_time' => $inicio->getTimestamp()]);
        // Miniatura del anuncio (solo en programados): imagen que se ve en la página antes de empezar
        $peticion = Http::timeout(60);
        $peticion = ($inicio && $miniatura && is_file($miniatura))
            ? $peticion->attach('schedule_custom_profile_image', (string) file_get_contents($miniatura), basename($miniatura))
            : $peticion->asForm();
        $r = $peticion->post(SocialPhotoPublisher::graph("{$page->page_id}/live_videos"), $datos);
        if (!$r->ok() || !data_get($r->json(), 'id')) {
            $msg = (string) (data_get($r->json(), 'error.error_user_msg') ?: data_get($r->json(), 'error.message') ?: Str::limit($r->body(), 200, ''));
            if (stripos($msg, 'permission') !== false || stripos($msg, 'publish_video') !== false) {
                $msg .= ' — agrega publish_video a FACEBOOK_LINK_SCOPES en editus y vuelve a conectar la página';
            }
            throw new \RuntimeException('Facebook no creó la transmisión: ' . $msg);
        }
        $liveId = (string) data_get($r->json(), 'id');
        $streamUrl = (string) (data_get($r->json(), 'secure_stream_url') ?: data_get($r->json(), 'stream_url') ?: '');
        if ($streamUrl === '') throw new \RuntimeException('Facebook no devolvió la URL RTMP de la transmisión');

        $permalink = null;
        $videoId = null;
        try {
            $info = Http::timeout(20)->get(SocialPhotoPublisher::graph($liveId), ['fields' => 'permalink_url,video', 'access_token' => $token]);
            if ($info->ok()) {
                $rel = (string) (data_get($info->json(), 'permalink_url') ?: '');
                $permalink = $rel === '' ? null : (str_starts_with($rel, 'http') ? $rel : 'https://www.facebook.com' . $rel);
                $videoId = data_get($info->json(), 'video.id');
            }
        } catch (\Throwable) {
            // informativo
        }
        return ['id' => $liveId, 'stream_url' => $streamUrl, 'permalink' => $permalink, 'video_id' => $videoId];
    }

    /**
     * Un en vivo programado pasa a "en vivo ahora": sale apenas reciba la señal, aunque se entre
     * antes de la hora o la página no tenga activado el inicio automático. Devuelve si Facebook lo aceptó.
     */
    public function ponerAlAire(MetaPage $page, string $liveId): bool
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) return false;
        try {
            return Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph($liveId), ['status' => 'LIVE_NOW', 'access_token' => $token])->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Cancela un en vivo programado que aún no salió (el anuncio desaparece de la página). */
    public function cancelarProgramado(MetaPage $page, string $liveId): void
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) return;
        try {
            Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph($liveId), ['status' => 'SCHEDULED_CANCELED', 'access_token' => $token]);
        } catch (\Throwable) {
            // si falla, Facebook lo borra solo al no recibir señal
        }
    }

    public function terminar(MetaPage $page, string $liveId): void
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) return;
        try {
            Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph($liveId), ['end_live_video' => 'true', 'access_token' => $token]);
        } catch (\Throwable) {
            // Facebook la cierra sola cuando deja de recibir señal
        }
    }

    /** Estado y espectadores en vivo. */
    public function estado(MetaPage $page, string $liveId): array
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) return ['status' => null, 'espectadores' => null];
        try {
            $r = Http::timeout(20)->get(SocialPhotoPublisher::graph($liveId), ['fields' => 'status,live_views,permalink_url,video', 'access_token' => $token]);
            if (!$r->ok()) return ['status' => null, 'espectadores' => null];
            $rel = (string) (data_get($r->json(), 'permalink_url') ?: '');
            return [
                'status' => data_get($r->json(), 'status'),
                'espectadores' => data_get($r->json(), 'live_views'),
                'permalink' => $rel === '' ? null : (str_starts_with($rel, 'http') ? $rel : 'https://www.facebook.com' . $rel),
                'video_id' => data_get($r->json(), 'video.id'),
            ];
        } catch (\Throwable) {
            return ['status' => null, 'espectadores' => null];
        }
    }
}
