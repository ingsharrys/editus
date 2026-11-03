<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

    /** Consultas a Meta que van a la vez (antes se hacían de a una y 100 publicaciones tardaban minutos). */
    private const A_LA_VEZ = 15;

    /** @param array<string,string> $tokensFijos token a usar por page_id (p. ej. la conexión que ya se comprobó que funciona) */
    public function metricas(array $items, array $tokensFijos = []): array
    {
        $salida = [];
        $tokens = $tokensFijos;
        $fb = [];
        $ig = [];
        foreach (array_values($items) as $i => $item) {
            $red = strtolower((string) ($item['red'] ?? 'facebook'));
            $postId = trim((string) ($item['post_id'] ?? ''));
            $pageId = trim((string) ($item['page_id'] ?? ''));
            $base = ['red' => $red, 'post_id' => $postId, 'ok' => false, 'alcance' => null, 'impresiones' => null, 'interacciones' => null,
                     'reproducciones' => null, 'reacciones' => null, 'comentarios' => null, 'compartidos' => null, 'guardados' => null, 'error' => null];
            if ($postId === '' || $pageId === '') {
                $salida[$i] = array_merge($base, ['error' => 'Falta post_id o page_id']);
                continue;
            }
            if (!array_key_exists($pageId, $tokens)) {
                $tokens[$pageId] = $this->tokens->forPage($pageId);
            }
            $token = $tokens[$pageId];
            if (!$token) {
                $salida[$i] = array_merge($base, ['error' => 'La página no tiene un token activo en editus']);
                continue;
            }
            $salida[$i] = $base;
            $estado = ['post' => $postId, 'media' => trim((string) ($item['media_id'] ?? '')), 'tipo' => (string) ($item['tipo'] ?? 'foto'), 'token' => $token];
            if ($red === 'instagram') $ig[$i] = $estado; else $fb[$i] = $estado;
        }

        // Todas las publicaciones a la vez, por etapas (cada etapa depende de la anterior):
        // 1) insights actuales + reacciones/comentarios (+ id del video si hace falta)
        $res = $this->varias($this->etapaUno($fb, $ig));
        // 2) nombres antiguos de las métricas donde fallaron las actuales + reproducciones del video
        $res = array_merge($res, $this->varias($this->etapaDos($fb, $ig, $res)));
        // 3) reproducciones del video con los nombres antiguos, si la primera vez no dio datos
        $res = array_merge($res, $this->varias($this->etapaTres($fb, $res)));

        foreach ($fb as $i => $e) $salida[$i] = $this->cerrar($salida[$i], fn() => $this->facebook($i, $e, $res));
        foreach ($ig as $i => $e) $salida[$i] = $this->cerrar($salida[$i], fn() => $this->instagram($i, $e, $res));
        ksort($salida);
        return array_values($salida);
    }

    private function cerrar(array $base, callable $calcular): array
    {
        try {
            return array_merge($base, $calcular(), ['ok' => true]);
        } catch (\Throwable $e) {
            Log::warning('[API][metricas] falló', ['red' => $base['red'], 'post' => $base['post_id'], 'err' => $e->getMessage()]);
            return array_merge($base, ['error' => Str::limit($e->getMessage(), 200, '')]);
        }
    }

    // ------------------------------------------------------------------

    private function etapaUno(array $fb, array $ig): array
    {
        $p = [];
        foreach ($fb as $i => $e) {
            // Alcance (personas únicas) e impresiones del post. Meta retiró post_impressions (nov. 2025) y
            // post_impressions_unique (jun. 2026): ahora son post_media_view y post_total_media_view_unique.
            $p["fb{$i}:ins"] = ["{$e['post']}/insights", ['metric' => 'post_media_view,post_total_media_view_unique', 'period' => 'lifetime', 'access_token' => $e['token']]];
            // Reacciones, comentarios y compartidos
            $p["fb{$i}:eng"] = [$e['post'], ['fields' => 'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares', 'access_token' => $e['token']]];
            // Id del video de un post («pagina_post») para pedir sus reproducciones
            if ($e['tipo'] === 'video' && $e['media'] === '' && str_contains($e['post'], '_')) {
                $p["fb{$i}:obj"] = [$e['post'], ['fields' => 'object_id', 'access_token' => $e['token']]];
            }
        }
        foreach ($ig as $i => $e) {
            // Primero las métricas actuales (views), luego el nombre antiguo (impressions)
            $p["ig{$i}:ins"] = ["{$e['post']}/insights", ['metric' => 'reach,views,likes,comments,shares,saved', 'access_token' => $e['token']]];
        }
        return $p;
    }

    private function etapaDos(array $fb, array $ig, array $res): array
    {
        $p = [];
        foreach ($fb as $i => $e) {
            if (!$res["fb{$i}:ins"]['ok']) {
                $p["fb{$i}:ins"] = ["{$e['post']}/insights", ['metric' => 'post_impressions,post_impressions_unique', 'period' => 'lifetime', 'access_token' => $e['token']]];
            }
            // Reproducciones del video / reel. Sin "metric" Meta devuelve el conjunto vigente (blue_reels_play_count
            // en reels, total_video_views en videos clásicos, post_impressions_unique…)
            if ($e['tipo'] === 'video' && ($vid = $this->videoId($i, $e, $res)) !== '') {
                $p["fb{$i}:vi"] = ["{$vid}/video_insights", ['access_token' => $e['token']]];
            }
        }
        foreach ($ig as $i => $e) {
            if (!$res["ig{$i}:ins"]['ok']) {
                $p["ig{$i}:ins"] = ["{$e['post']}/insights", ['metric' => 'reach,impressions,likes,comments,saved', 'access_token' => $e['token']]];
            }
        }
        return $p;
    }

    private function etapaTres(array $fb, array $res): array
    {
        $p = [];
        foreach ($fb as $i => $e) {
            $vi = $res["fb{$i}:vi"] ?? null;
            if ($vi && (!$vi['ok'] || empty(data_get($vi['json'], 'data')))) {
                $p["fb{$i}:vi"] = ["{$this->videoId($i, $e, $res)}/video_insights", ['metric' => 'total_video_views,total_video_impressions', 'access_token' => $e['token']]];
            }
        }
        return $p;
    }

    private function videoId(int $i, array $e, array $res): string
    {
        if ($e['media'] !== '') return $e['media'];
        if (!str_contains($e['post'], '_')) return $e['post'];
        $obj = $res["fb{$i}:obj"] ?? null;
        return $obj && $obj['ok'] ? (string) (data_get($obj['json'], 'object_id') ?: '') : '';
    }

    private function facebook(int $i, array $e, array $res): array
    {
        $m = ['alcance' => null, 'impresiones' => null, 'reproducciones' => null, 'reacciones' => 0, 'comentarios' => 0, 'compartidos' => 0, 'guardados' => null];
        $errores = [];

        $r = $res["fb{$i}:ins"];
        if ($r['ok']) {
            $v = $this->valoresInsights($r['json']);
            $m['impresiones'] = $v['post_media_view'] ?? $v['post_impressions'] ?? null;
            $m['alcance'] = $v['post_total_media_view_unique'] ?? $v['post_impressions_unique'] ?? null;
        } else {
            $errores[] = $r['error'];
        }

        $eng = $res["fb{$i}:eng"];
        if ($eng['ok']) {
            $m['reacciones'] = (int) data_get($eng['json'], 'reactions.summary.total_count', 0);
            $m['comentarios'] = (int) data_get($eng['json'], 'comments.summary.total_count', 0);
            $m['compartidos'] = (int) data_get($eng['json'], 'shares.count', 0);
        } else {
            $errores[] = $eng['error'];
        }

        if ($e['tipo'] === 'video') {
            $vi = $res["fb{$i}:vi"] ?? null;
            if ($vi) {
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
        if ($m['alcance'] === null && $m['impresiones'] === null && !$r['ok'] && !$eng['ok']) {
            throw new \RuntimeException(implode(' / ', array_filter($errores)) ?: 'Meta no devolvió datos');
        }
        if ($errores) $m['error'] = Str::limit(implode(' / ', array_filter($errores)), 200, '');
        return $m;
    }

    private function instagram(int $i, array $e, array $res): array
    {
        $m = ['alcance' => null, 'impresiones' => null, 'reproducciones' => null, 'reacciones' => null, 'comentarios' => null, 'compartidos' => null, 'guardados' => null];
        $r = $res["ig{$i}:ins"];
        if (!$r['ok']) {
            throw new \RuntimeException($r['error'] ?: 'Instagram no devolvió métricas');
        }
        $v = $this->valoresInsights($r['json']);
        $m['alcance'] = $v['reach'] ?? null;
        $m['impresiones'] = $v['views'] ?? $v['impressions'] ?? null;
        $m['reproducciones'] = $e['tipo'] === 'video' ? ($v['views'] ?? $v['plays'] ?? null) : null;
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

    /**
     * Varias consultas GET a Meta a la vez (de a A_LA_VEZ).
     * @param array<string, array{0:string,1:array}> $peticiones clave => [ruta, query]
     * @return array<string, array{ok:bool,json:?array,error:?string}>
     */
    private function varias(array $peticiones): array
    {
        $out = [];
        foreach (array_chunk($peticiones, self::A_LA_VEZ, true) as $grupo) {
            try {
                $respuestas = Http::pool(function (Pool $pool) use ($grupo) {
                    $lista = [];
                    foreach ($grupo as $clave => [$ruta, $query]) {
                        $lista[] = $pool->as((string) $clave)->timeout(25)->connectTimeout(10)
                            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                            ->get(SocialPhotoPublisher::graph($ruta), $query);
                    }
                    return $lista;
                });
            } catch (\Throwable $e) {
                // Si el envío en paralelo falla, se hace de a una
                foreach ($grupo as $clave => [$ruta, $query]) $out[$clave] = $this->get($ruta, $query);
                continue;
            }
            foreach ($grupo as $clave => $_) $out[$clave] = $this->leer($respuestas[(string) $clave] ?? null);
        }
        return $out;
    }

    private function get(string $path, array $query): array
    {
        try {
            return $this->leer(Http::timeout(25)->connectTimeout(10)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get(SocialPhotoPublisher::graph($path), $query));
        } catch (\Throwable $e) {
            return ['ok' => false, 'json' => null, 'error' => $e->getMessage()];
        }
    }

    private function leer($r): array
    {
        if ($r instanceof Response) {
            if ($r->ok()) return ['ok' => true, 'json' => $r->json(), 'error' => null];
            $msg = data_get($r->json(), 'error.message') ?: Str::limit($r->body(), 160, '');
            return ['ok' => false, 'json' => null, 'error' => (string) $msg];
        }
        if ($r instanceof \Throwable) return ['ok' => false, 'json' => null, 'error' => $r->getMessage()];
        return ['ok' => false, 'json' => null, 'error' => 'Meta no respondió'];
    }
}
