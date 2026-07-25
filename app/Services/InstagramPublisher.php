<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publicación en Instagram Business vía Graph API (flujo contenedor → publish).
 *
 * Requisitos de Meta:
 * - La página de Facebook debe tener una cuenta de Instagram Business conectada.
 * - El token debe incluir instagram_basic + instagram_content_publish.
 * - Instagram NO acepta publicaciones de solo texto: siempre requiere medio.
 * - Las URLs de imagen/video deben ser públicas (Meta las descarga).
 */
class InstagramPublisher
{
    protected function base(): string
    {
        $v = config('services.facebook.version', 'v23.0');
        return "https://graph.facebook.com/{$v}";
    }

    /**
     * Publica 1..N fotos (1 = post simple, 2+ = carrusel).
     *
     * @return array{ok: bool, media_id?: string, permalink?: ?string, error?: string}
     */
    public function publishPhotos(string $igUserId, string $token, array $imageUrls, ?string $caption): array
    {
        $imageUrls = array_values(array_filter($imageUrls));
        if (empty($imageUrls)) {
            return ['ok' => false, 'error' => 'Sin imágenes para publicar.'];
        }

        // --- Post simple ---
        if (count($imageUrls) === 1) {
            $container = $this->createContainer($igUserId, $token, [
                'image_url' => $imageUrls[0],
                'caption' => (string) $caption,
            ]);
            if (!$container['ok']) {
                return $container;
            }
            return $this->publishContainer($igUserId, $token, $container['id']);
        }

        // --- Carrusel (máx. 10 según Meta) ---
        $children = [];
        foreach (array_slice($imageUrls, 0, 10) as $url) {
            $child = $this->createContainer($igUserId, $token, [
                'image_url' => $url,
                'is_carousel_item' => 'true',
            ]);
            if (!$child['ok']) {
                return $child + ['error' => 'Falló un elemento del carrusel: ' . ($child['error'] ?? '')];
            }
            $children[] = $child['id'];
        }

        $carousel = $this->createContainer($igUserId, $token, [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => (string) $caption,
        ]);
        if (!$carousel['ok']) {
            return $carousel;
        }

        return $this->publishContainer($igUserId, $token, $carousel['id']);
    }

    /**
     * Publica un video como REEL (formato estándar de video en IG desde 2023).
     * El procesamiento es asíncrono: se sondea status_code hasta FINISHED.
     *
     * @return array{ok: bool, media_id?: string, permalink?: ?string, error?: string}
     */
    public function publishReel(string $igUserId, string $token, string $videoUrl, ?string $caption): array
    {
        $container = $this->createContainer($igUserId, $token, [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => (string) $caption,
        ]);
        if (!$container['ok']) {
            return $container;
        }

        // Sondeo: hasta ~5 minutos (30 intentos x 10s)
        $status = 'IN_PROGRESS';
        for ($i = 0; $i < 30; $i++) {
            sleep(10);

            $resp = Http::withToken($token)->acceptJson()
                ->timeout(30)->connectTimeout(10)
                ->get("{$this->base()}/{$container['id']}", ['fields' => 'status_code,status']);

            $status = (string) data_get($resp->json(), 'status_code', 'IN_PROGRESS');

            if ($status === 'FINISHED') {
                break;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return [
                    'ok' => false,
                    'error' => 'Instagram no pudo procesar el video: ' . (string) data_get($resp->json(), 'status', $status),
                ];
            }
        }

        if ($status !== 'FINISHED') {
            return ['ok' => false, 'error' => 'Tiempo de espera agotado procesando el video en Instagram.'];
        }

        return $this->publishContainer($igUserId, $token, $container['id']);
    }

    /** Crea un contenedor de medio. @return array{ok: bool, id?: string, error?: string} */
    protected function createContainer(string $igUserId, string $token, array $params): array
    {
        try {
            $resp = Http::asForm()->withToken($token)
                ->timeout(60)->connectTimeout(15)
                ->post("{$this->base()}/{$igUserId}/media", $params);

            $id = data_get($resp->json(), 'id');
            if ($resp->ok() && $id) {
                return ['ok' => true, 'id' => (string) $id];
            }

            $err = (string) (data_get($resp->json(), 'error.message') ?: $resp->body());
            Log::warning('[IG] container.fail', ['ig_user_id' => $igUserId, 'err' => mb_substr($err, 0, 400)]);
            return ['ok' => false, 'error' => $err];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Publica un contenedor y obtiene el permalink. */
    protected function publishContainer(string $igUserId, string $token, string $creationId): array
    {
        try {
            $resp = Http::asForm()->withToken($token)
                ->timeout(60)->connectTimeout(15)
                ->post("{$this->base()}/{$igUserId}/media_publish", ['creation_id' => $creationId]);

            $mediaId = data_get($resp->json(), 'id');
            if (!$resp->ok() || !$mediaId) {
                $err = (string) (data_get($resp->json(), 'error.message') ?: $resp->body());
                return ['ok' => false, 'error' => $err];
            }

            $permalink = null;
            try {
                $p = Http::withToken($token)->acceptJson()->timeout(20)
                    ->get("{$this->base()}/{$mediaId}", ['fields' => 'permalink']);
                $permalink = data_get($p->json(), 'permalink');
            } catch (\Throwable) {
                // el permalink es opcional
            }

            return ['ok' => true, 'media_id' => (string) $mediaId, 'permalink' => $permalink];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
