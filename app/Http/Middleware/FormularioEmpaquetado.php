<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Formularios grandes (p. ej. todas las subcampañas con sus tipos de publicación) superan el límite de campos de PHP
 * (max_input_vars, 1000 por defecto) y lo que sobra se pierde. Esos formularios (data-empaquetar) se envían como un
 * solo campo «_empaquetado» con la lista [nombre, valor] de sus campos, y aquí se vuelven a armar igual que PHP.
 */
class FormularioEmpaquetado
{
    public function handle(Request $request, Closure $next)
    {
        $json = $request->request->get('_empaquetado');
        if (is_string($json) && $json !== '' && $request->isMethod('POST')) {
            $pares = json_decode($json, true);
            if (is_array($pares)) {
                $datos = $request->request->all();
                unset($datos['_empaquetado']);
                foreach ($pares as $par) {
                    if (!is_array($par) || count($par) !== 2 || !is_string($par[0]) || (!is_string($par[1]) && !is_numeric($par[1]))) continue;
                    if (in_array($par[0], ['_token', '_method'], true)) continue;
                    $this->poner($datos, $par[0], (string) $par[1]);
                }
                $request->request->replace($datos);
            }
        }
        return $next($request);
    }

    /** «subs[3][tipos][12][on]» = «1», como lo haría PHP (el último valor gana, «[]» agrega). */
    private function poner(array &$datos, string $nombre, string $valor): void
    {
        if (!preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/', $nombre, $m)) return;
        preg_match_all('/\[([^\[\]]*)\]/', $m[2], $k);
        $claves = array_merge([$m[1]], $k[1]);
        if (count($claves) > 8) return;
        $ref = &$datos;
        foreach ($claves as $i => $clave) {
            $ultima = $i === count($claves) - 1;
            if ($clave === '') {
                if (!is_array($ref)) $ref = [];
                if ($ultima) { $ref[] = $valor; return; }
                $ref[] = [];
                end($ref);
                $ref = &$ref[key($ref)];
                continue;
            }
            if (!is_array($ref)) $ref = [];
            if ($ultima) { $ref[$clave] = $valor; return; }
            if (!isset($ref[$clave]) || !is_array($ref[$clave])) $ref[$clave] = [];
            $ref = &$ref[$clave];
        }
    }
}
