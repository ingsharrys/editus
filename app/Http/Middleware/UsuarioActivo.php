<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un usuario desactivado pierde la sesión al instante; a los demás se les anota el último
 * acceso (como mucho una vez cada 5 minutos, para no escribir en cada petición).
 */
class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && !$user->estaActivo()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['email' => __('Tu usuario está desactivado. Habla con un administrador.')]);
        }
        if ($user && Schema::hasColumn('users', 'ultimo_acceso') && (!$user->ultimo_acceso || $user->ultimo_acceso->lt(now()->subMinutes(5)))) {
            $user->forceFill(['ultimo_acceso' => now()])->saveQuietly();
        }
        return $next($request);
    }
}
