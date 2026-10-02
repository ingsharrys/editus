<?php

namespace App\Services\Inteligencia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Cliente mínimo de la Graph API para la recolección de audiencia (lecturas, con paginación). */
class GraphClient
{
    public function version(): string
    {
        return (string) config('services.facebook.version', 'v23.0');
    }

    /** GET que devuelve el JSON o lanza una excepción con el mensaje de Meta. */
    public function get(string $path, array $params, string $token): array
    {
        $r = Http::timeout(40)->connectTimeout(10)->retry(2, 700, throw: false)
            ->get("https://graph.facebook.com/{$this->version()}/" . ltrim($path, '/'), $params + ['access_token' => $token]);
        $json = $r->json();
        if (!$r->ok() || !is_array($json) || isset($json['error'])) {
            $msg = data_get($json, 'error.message') ?: Str::limit((string) $r->body(), 200, '');
            throw new \RuntimeException("Meta {$path}: {$msg}");
        }
        return $json;
    }

    /** Igual que get() pero devuelve [] en vez de lanzar (para métricas opcionales). */
    public function intentar(string $path, array $params, string $token): array
    {
        try {
            return $this->get($path, $params, $token);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Recorre `data` siguiendo `paging.next` hasta `$maxPaginas` páginas o hasta que `$parar($item)` sea true. */
    public function listar(string $path, array $params, string $token, int $maxPaginas = 5, ?callable $parar = null): array
    {
        $items = [];
        $url = null;
        for ($i = 0; $i < $maxPaginas; $i++) {
            if ($url === null) {
                $json = $this->get($path, $params, $token);
            } else {
                $r = Http::timeout(40)->connectTimeout(10)->get($url);
                $json = $r->json();
                if (!$r->ok() || !is_array($json) || isset($json['error'])) break;
            }
            foreach ((array) ($json['data'] ?? []) as $item) {
                if ($parar && $parar($item)) return $items;
                $items[] = $item;
            }
            $url = data_get($json, 'paging.next');
            if (!$url) break;
        }
        return $items;
    }

    /** Convierte la respuesta de /insights en [metric => [end_time(Y-m-d) => value]] (period day) o [metric => value] (lifetime). */
    public static function insightsPorFecha(array $json): array
    {
        $out = [];
        foreach ((array) ($json['data'] ?? []) as $m) {
            $nombre = (string) ($m['name'] ?? '');
            foreach ((array) ($m['values'] ?? []) as $v) {
                $fecha = isset($v['end_time']) ? substr((string) $v['end_time'], 0, 10) : null;
                // Meta marca el día con end_time = día siguiente a las 07:00 UTC; se guarda el día real
                if ($fecha) $fecha = date('Y-m-d', strtotime($fecha . ' -1 day'));
                $out[$nombre][$fecha ?? 'lifetime'] = $v['value'] ?? null;
            }
            if (isset($m['total_value']['value']) && !isset($out[$nombre])) {
                $out[$nombre]['total'] = $m['total_value']['value'];
            }
            if (isset($m['total_value']['breakdowns'][0]['results'])) {
                $bd = [];
                foreach ((array) $m['total_value']['breakdowns'][0]['results'] as $r) {
                    $bd[implode('|', (array) ($r['dimension_values'] ?? []))] = (int) ($r['value'] ?? 0);
                }
                $out[$nombre]['breakdown'] = $bd;
            }
        }
        return $out;
    }
}
