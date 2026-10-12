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
     * Crea la transmisión (LIVE_NOW) y devuelve id, stream_url (secreto) y permalink.
     * Meta eliminó los en vivos programados de la API ("Scheduled Live has been deprecated"): un en
     * vivo programado se anuncia con publicarAnuncio() y la transmisión se crea a la hora.
     */
    public function crear(MetaPage $page, string $titulo, string $descripcion): array
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) throw new \RuntimeException('La página no tiene un token activo en editus');

        $r = Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph("{$page->page_id}/live_videos"), [
            'status' => 'LIVE_NOW',
            'title' => Str::limit($titulo, 250, ''),
            'description' => Str::limit($descripcion, 5000, ''),
            'access_token' => $token,
        ]);
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

    /**
     * Anuncio de un en vivo o pregrabado programado: una publicación en la página con la fecha y
     * la hora (y la miniatura, si hay, subida como foto). Devuelve id y permalink de la publicación.
     */
    public function publicarAnuncio(MetaPage $page, string $mensaje, ?string $imagen = null): array
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) throw new \RuntimeException('La página no tiene un token activo en editus');
        $r = ($imagen && is_file($imagen))
            ? Http::timeout(60)->attach('source', (string) file_get_contents($imagen), basename($imagen))
                ->post(SocialPhotoPublisher::graph("{$page->page_id}/photos"), ['message' => $mensaje, 'published' => 'true', 'access_token' => $token])
            : Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph("{$page->page_id}/feed"), ['message' => $mensaje, 'access_token' => $token]);
        $id = (string) (data_get($r->json(), 'post_id') ?: data_get($r->json(), 'id') ?: '');
        if (!$r->ok() || $id === '') {
            throw new \RuntimeException('Facebook no publicó el anuncio: ' . (string) (data_get($r->json(), 'error.error_user_msg') ?: data_get($r->json(), 'error.message') ?: Str::limit($r->body(), 200, '')));
        }
        $permalink = null;
        try {
            $info = Http::timeout(20)->get(SocialPhotoPublisher::graph($id), ['fields' => 'permalink_url', 'access_token' => $token]);
            $permalink = data_get($info->json(), 'permalink_url') ?: null;
        } catch (\Throwable) {
        }
        return ['id' => $id, 'permalink' => $permalink];
    }

    /** Borra la publicación de anuncio (si el en vivo se cancela o no se hizo). */
    public function borrarAnuncio(MetaPage $page, string $postId): void
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) return;
        try {
            Http::timeout(20)->delete(SocialPhotoPublisher::graph($postId) . '?' . http_build_query(['access_token' => $token]));
        } catch (\Throwable) {
        }
    }

    /** Texto del anuncio: fecha y hora en español, título y descripción. */
    public static function textoAnuncio(string $titulo, ?string $descripcion, \DateTimeInterface $cuando, bool $pregrabado = false): string
    {
        $c = \Carbon\Carbon::instance(\DateTime::createFromInterface($cuando))->setTimezone(config('app.timezone'));
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $fecha = $dias[$c->dayOfWeek] . ' ' . $c->day . ' de ' . $meses[$c->month - 1] . ' a las ' . $c->format('g:i') . ($c->hour < 12 ? ' a. m.' : ' p. m.');
        return "🔴 EN VIVO el {$fecha}\n\n{$titulo}" . (trim((string) $descripcion) !== '' ? "\n\n" . trim((string) $descripcion) : '')
            . ($pregrabado ? '' : "\n\nAquí mismo, en esta página. ¡Te esperamos!");
    }

    /** Comentarios del en vivo (los más recientes primero). Sin permiso de leer usuarios, llegan sin nombre. */
    public function comentarios(MetaPage $page, string $liveId): array
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) throw new \RuntimeException('La página no tiene un token activo en editus');
        $r = Http::timeout(20)->get(SocialPhotoPublisher::graph("{$liveId}/comments"), [
            'fields' => 'id,message,created_time,from{name,picture.type(small){url}}', 'order' => 'reverse_chronological', 'limit' => 50,
            'filter' => 'stream', 'live_filter' => 'no_filter', 'access_token' => $token,
        ]);
        if (!$r->ok()) throw new \RuntimeException((string) (data_get($r->json(), 'error.message') ?: 'Facebook no entregó los comentarios'));
        $lista = [];
        foreach ((array) data_get($r->json(), 'data', []) as $c) {
            if (trim((string) ($c['message'] ?? '')) === '') continue;
            $lista[] = ['id' => 'fb-' . $c['id'], 'red' => 'facebook', 'nombre' => (string) data_get($c, 'from.name', 'Usuario de Facebook'),
                'foto' => data_get($c, 'from.picture.data.url'), 'texto' => (string) $c['message'], 'fecha' => $c['created_time'] ?? null];
        }
        return $lista;
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
