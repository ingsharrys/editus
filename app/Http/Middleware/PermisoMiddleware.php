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
        abort(403, __('Tu rol no tiene permiso para este módulo. Pídele acceso a un administrador.'));
    }
}
