<?php

namespace App\Services;

use App\Models\MetaPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Últimos videos ya publicados en las páginas (Facebook: videos y reels;
 * Instagram: videos / reels), para que la app del editor los lleve a la web
 * como embed sin subir el archivo a ningún servidor.
 */
class MetaVideosService
{
    public function __construct(private MetaPageTokenResolver $tokens)
    {
    }

    /** Videos recientes de una página: ['videos' => [...], 'error' => null|string]. */
    public function dePagina(MetaPage $page, int $limite = 15): array
    {
        $token = $this->tokens->forPage($page->page_id);
        if (!$token) {
            return ['videos' => [], 'error' => 'La página no tiene un token activo en editus'];
        }
        $videos = [];
        $errores = [];

        // Facebook: videos de la página (incluye reels en la mayoría de páginas)
        $r = $this->get("{$page->page_id}/videos", ['fields' => 'id,title,description,permalink_url,created_time,picture,length,status', 'limit' => $limite, 'access_token' => $token]);
        if ($r['ok']) {
            foreach ((array) data_get($r['json'], 'data', []) as $v) {
                if ((string) data_get($v, 'status.video_status', 'ready') !== 'ready') continue;
                $rel = (string) ($v['permalink_url'] ?? '');
                $videos[] = [
                    'red' => 'facebook',
                    'id' => (string) $v['id'],
                    'tipo' => 'video',
                    'titulo' => (string) ($v['title'] ?? ''),
                    'descripcion' => (string) ($v['description'] ?? ''),
                    'permalink' => $rel === '' ? "https://www.facebook.com/{$page->page_id}/videos/{$v['id']}" : (str_starts_with($rel, 'http') ? $rel : 'https://www.facebook.com' . $rel),
                    'miniatura' => $v['picture'] ?? null,
                    'fecha' => $v['created_time'] ?? null,
                    'duracion' => isset($v['length']) ? (int) round($v['length']) : null,
                ];
            }
        } else {
            $errores[] = 'Facebook: ' . $r['error'];
        }

        // Facebook: reels (endpoint aparte; no todas las páginas lo tienen)
        $rr = $this->get("{$page->page_id}/video_reels", ['fields' => 'id,description,permalink_url,created_time,picture,length', 'limit' => $limite, 'access_token' => $token]);
        if ($rr['ok']) {
            $ids = array_column($videos, 'id');
            foreach ((array) data_get($rr['json'], 'data', []) as $v) {
                if (in_array((string) $v['id'], $ids, true)) continue;
                $rel = (string) ($v['permalink_url'] ?? '');
                $videos[] = [
                    'red' => 'facebook',
                    'id' => (string) $v['id'],
                    'tipo' => 'reel',
                    'titulo' => '',
                    'descripcion' => (string) ($v['description'] ?? ''),
                    'permalink' => $rel === '' ? "https://www.facebook.com/reel/{$v['id']}" : (str_starts_with($rel, 'http') ? $rel : 'https://www.facebook.com' . $rel),
                    'miniatura' => $v['picture'] ?? null,
                    'fecha' => $v['created_time'] ?? null,
                    'duracion' => isset($v['length']) ? (int) round($v['length']) : null,
                ];
            }
        }

        // Instagram: videos y reels de la cuenta vinculada
        $ig = (string) ($page->instagram_business_account_id ?? '');
        if ($ig !== '') {
            $ri = $this->get("{$ig}/media", ['fields' => 'id,media_type,media_product_type,caption,permalink,thumbnail_url,timestamp', 'limit' => max(20, $limite * 2), 'access_token' => $token]);
            if ($ri['ok']) {
                $n = 0;
                foreach ((array) data_get($ri['json'], 'data', []) as $m) {
                    if ((string) ($m['media_type'] ?? '') !== 'VIDEO') continue;
                    if (++$n > $limite) break;
                    $videos[] = [
                        'red' => 'instagram',
                        'id' => (string) $m['id'],
                        'tipo' => strtoupper((string) ($m['media_product_type'] ?? '')) === 'REELS' ? 'reel' : 'video',
                        'titulo' => '',
                        'descripcion' => (string) ($m['caption'] ?? ''),
                        'permalink' => (string) ($m['permalink'] ?? ''),
                        'miniatura' => $m['thumbnail_url'] ?? null,
                        'fecha' => $m['timestamp'] ?? null,
                        'duracion' => null,
                    ];
                }
            } else {
                $errores[] = 'Instagram: ' . $ri['error'];
            }
        }

        usort($videos, fn($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']));
        return ['videos' => $videos, 'error' => $errores ? Str::limit(implode(' / ', $errores), 200, '') : null];
    }

    private function get(string $path, array $query): array
    {
        try {
            $r = Http::timeout(25)->connectTimeout(10)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get(SocialPhotoPublisher::graph($path), $query);
            if ($r->ok()) return ['ok' => true, 'json' => $r->json(), 'error' => null];
            $msg = data_get($r->json(), 'error.message') ?: Str::limit($r->body(), 160, '');
            return ['ok' => false, 'json' => null, 'error' => (string) $msg];
        } catch (\Throwable $e) {
            Log::warning('[API][videos] Graph falló', ['path' => $path, 'err' => $e->getMessage()]);
            return ['ok' => false, 'json' => null, 'error' => $e->getMessage()];
        }
    }
}
