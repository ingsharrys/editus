<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ->middleware('permiso:clave') o 'permiso:a|b' (basta con uno). Claves en config/permisos.php. */
class PermisoMiddleware
{
    public function handle(Request $request, Closure $next, string $permisos): Response
    {
        $user = $request->user();
        if (!$user) abort(403, __('No autorizado.'));
        foreach (explode('|', $permisos) as $p) {
            if ($user->tienePermiso(trim($p))) return $next($request);
        }
        // Navegando (no una petición de datos): se le lleva a su módulo de inicio en vez de una página de error
        if ($request->isMethod('GET') && !$request->expectsJson()) {
            $inicio = $user->rutaInicio();
            if (!$request->routeIs($inicio)) {
                return redirect()->route($inicio)->with('aviso_permiso', __('Tu rol no tiene acceso a ese módulo; te llevamos a uno que sí puedes usar.'));
            }
        }
        abort(403, __('Tu rol no tiene permiso para este módulo. Pídele acceso a un administrador.'));
    }
}
