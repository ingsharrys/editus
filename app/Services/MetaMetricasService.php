<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Métricas en vivo de publicaciones de Facebook e Instagram (Graph API),
 * para la sección de estadísticas de la app del editor.
 *
 * Entrada: lista de {red: facebook|instagram, post_id, page_id, media_id?, tipo?: foto|video}.
 * Salida por ítem: alcance, impresiones, interacciones, reproducciones,
 * reacciones, comentarios, compartidos, guardados (null cuando Meta no lo da).
 */
class MetaMetricasService
{
    public function __construct(private MetaPageTokenResolver $tokens)
    {
    }

    public function metricas(array $items): array
    {
        $salida = [];
        $tokens = [];
        foreach ($items as $item) {
            $red = strtolower((string) ($item['red'] ?? 'facebook'));
            $postId = trim((string) ($item['post_id'] ?? ''));
            $pageId = trim((string) ($item['page_id'] ?? ''));
            $base = ['red' => $red, 'post_id' => $postId, 'ok' => false, 'alcance' => null, 'impresiones' => null, 'interacciones' => null,
                     'reproducciones' => null, 'reacciones' => null, 'comentarios' => null, 'compartidos' => null, 'guardados' => null, 'error' => null];
            if ($postId === '' || $pageId === '') {
                $salida[] = $base + ['error' => 'Falta post_id o page_id'];
                continue;
            }
            if (!array_key_exists($pageId, $tokens)) {
                $tokens[$pageId] = $this->tokens->forPage($pageId);
            }
            $token = $tokens[$pageId];
            if (!$token) {
                $salida[] = array_merge($base, ['error' => 'La página no tiene un token activo en editus']);
                continue;
            }
            try {
                $m = $red === 'instagram'
                    ? $this->instagram($postId, $token, (string) ($item['tipo'] ?? 'foto'))
                    : $this->facebook($postId, (string) ($item['media_id'] ?? ''), $token, (string) ($item['tipo'] ?? 'foto'));
                $salida[] = array_merge($base, $m, ['ok' => true]);
            } catch (\Throwable $e) {
                Log::warning('[API][metricas] falló', ['red' => $red, 'post' => $postId, 'err' => $e->getMessage()]);
                $salida[] = array_merge($base, ['error' => Str::limit($e->getMessage(), 200, '')]);
            }
        }
        return $salida;
    }

    // ------------------------------------------------------------------

    private function facebook(string $postId, string $mediaId, string $token, string $tipo): array
    {
        $m = ['alcance' => null, 'impresiones' => null, 'reproducciones' => null, 'reacciones' => 0, 'comentarios' => 0, 'compartidos' => 0, 'guardados' => null];
        $errores = [];

        // Alcance (personas únicas) e impresiones del post. Meta retiró post_impressions (nov. 2025) y
        // post_impressions_unique (jun. 2026): ahora son post_media_view y post_total_media_view_unique.
        // Se piden primero las nuevas y, si la página aún no las entrega, las antiguas.
        $r = $this->get("{$postId}/insights", ['metric' => 'post_media_view,post_total_media_view_unique', 'period' => 'lifetime', 'access_token' => $token]);
        if (!$r['ok']) {
            $r = $this->get("{$postId}/insights", ['metric' => 'post_impressions,post_impressions_unique', 'period' => 'lifetime', 'access_token' => $token]);
        }
        if ($r['ok']) {
            $v = $this->valoresInsights($r['json']);
            $m['impresiones'] = $v['post_media_view'] ?? $v['post_impressions'] ?? null;
            $m['alcance'] = $v['post_total_media_view_unique'] ?? $v['post_impressions_unique'] ?? null;
        } else {
            $errores[] = $r['error'];
        }

        // Reacciones, comentarios y compartidos
        $e = $this->get($postId, ['fields' => 'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares', 'access_token' => $token]);
        if ($e['ok']) {
            $m['reacciones'] = (int) data_get($e['json'], 'reactions.summary.total_count', 0);
            $m['comentarios'] = (int) data_get($e['json'], 'comments.summary.total_count', 0);
            $m['compartidos'] = (int) data_get($e['json'], 'shares.count', 0);
        } else {
            $errores[] = $e['error'];
        }

        // Reproducciones del video / reel. Sin "metric" Meta devuelve el conjunto vigente (blue_reels_play_count
        // en reels, total_video_views en videos clásicos, post_impressions_unique…); si falla, se prueban los nombres antiguos.
        if ($tipo === 'video') {
            $vid = $mediaId !== '' ? $mediaId : (str_contains($postId, '_') ? (string) $this->objectId($postId, $token) : $postId);
            if ($vid !== '') {
                $vi = $this->get("{$vid}/video_insights", ['access_token' => $token]);
                if (!$vi['ok'] || empty(data_get($vi['json'], 'data'))) {
                    $vi = $this->get("{$vid}/video_insights", ['metric' => 'total_video_views,total_video_impressions', 'access_token' => $token]);
                }
                if ($vi['ok']) {
                    $v = $this->valoresInsights($vi['json']);
                    $m['reproducciones'] = $v['blue_reels_play_count'] ?? $v['total_video_views'] ?? $v['post_video_views'] ?? null;
                    if ($m['alcance'] === null && isset($v['post_impressions_unique'])) $m['alcance'] = $v['post_impressions_unique'];
                    if ($m['impresiones'] === null && isset($v['total_video_impressions'])) $m['impresiones'] = $v['total_video_impressions'];
                } else {
                    $errores[] = $vi['error'];
                }
            }
            // Si el video no reporta reproducciones propias, las vistas del post (veces que se reprodujo o mostró) son el mejor dato
            if ($m['reproducciones'] === null && $m['impresiones'] !== null) $m['reproducciones'] = $m['impresiones'];
        }

        $m['interacciones'] = (int) $m['reacciones'] + (int) $m['comentarios'] + (int) $m['compartidos'];
        if ($m['alcance'] === null && $m['impresiones'] === null && !$r['ok'] && !$e['ok']) {
            throw new \RuntimeException(implode(' / ', array_filter($errores)) ?: 'Meta no devolvió datos');
        }
        if ($errores) $m['error'] = Str::limit(implode(' / ', array_filter($errores)), 200, '');
        return $m;
    }

    private function instagram(string $mediaId, string $token, string $tipo): array
    {
        $m = ['alcance' => null, 'impresiones' => null, 'reproducciones' => null, 'reacciones' => null, 'comentarios' => null, 'compartidos' => null, 'guardados' => null];
        // Primero las métricas actuales (views), luego el nombre antiguo (impressions)
        $r = $this->get("{$mediaId}/insights", ['metric' => 'reach,views,likes,comments,shares,saved', 'access_token' => $token]);
        if (!$r['ok']) {
            $r = $this->get("{$mediaId}/insights", ['metric' => 'reach,impressions,likes,comments,saved', 'access_token' => $token]);
        }
        if (!$r['ok']) {
            throw new \RuntimeException($r['error'] ?: 'Instagram no devolvió métricas');
        }
        $v = $this->valoresInsights($r['json']);
        $m['alcance'] = $v['reach'] ?? null;
        $m['impresiones'] = $v['views'] ?? $v['impressions'] ?? null;
        $m['reproducciones'] = $tipo === 'video' ? ($v['views'] ?? $v['plays'] ?? null) : null;
        $m['reacciones'] = $v['likes'] ?? null;
        $m['comentarios'] = $v['comments'] ?? null;
        $m['compartidos'] = $v['shares'] ?? null;
        $m['guardados'] = $v['saved'] ?? null;
        $m['interacciones'] = (int) ($m['reacciones'] ?? 0) + (int) ($m['comentarios'] ?? 0) + (int) ($m['compartidos'] ?? 0) + (int) ($m['guardados'] ?? 0);
        return $m;
    }

    /** data[].name → values[0].value (o .value directo en Instagram). */
    private function valoresInsights(?array $json): array
    {
        $out = [];
        foreach ((array) data_get($json, 'data', []) as $fila) {
            $nombre = (string) ($fila['name'] ?? '');
            if ($nombre === '') continue;
            $valor = data_get($fila, 'values.0.value');
            if ($valor === null) $valor = $fila['value'] ?? null;
            if (is_array($valor)) $valor = array_sum(array_map('intval', $valor));
            $out[$nombre] = $valor === null ? null : (int) $valor;
        }
        return $out;
    }

    private function objectId(string $postId, string $token): ?string
    {
        $r = $this->get($postId, ['fields' => 'object_id', 'access_token' => $token]);
        return $r['ok'] ? (data_get($r['json'], 'object_id') ?: null) : null;
    }

    private function get(string $path, array $query): array
    {
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(25)->connectTimeout(10)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get(SocialPhotoPublisher::graph($path), $query);
            if ($r->ok()) return ['ok' => true, 'json' => $r->json(), 'error' => null];
            $msg = data_get($r->json(), 'error.message') ?: Str::limit($r->body(), 160, '');
            return ['ok' => false, 'json' => null, 'error' => (string) $msg];
        } catch (\Throwable $e) {
            return ['ok' => false, 'json' => null, 'error' => $e->getMessage()];
        }
    }
}
