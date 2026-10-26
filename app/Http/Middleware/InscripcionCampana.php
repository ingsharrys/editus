<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CertificadoController;
use App\Services\CertificadosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1) Quien abrió un enlace de inscripción y luego entró o se registró queda inscrito en ESA campaña.
 * 2) Mientras un medio inscrito no firme su certificado (si la campaña lo pide obligatorio),
 *    cada pantalla lo lleva al formulario del certificado.
 */
class InscripcionCampana
{
    /** Rutas que se pueden usar con el certificado pendiente (salir, conectar Facebook, idioma…). */
    private const LIBRES = ['certificados.*', 'inscripcion.*', 'logout', 'idioma', 'moneda', 'facebook.*', 'privacy', 'terms', 'data-deletion', 'verification.*', 'password.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !CertificadosService::disponible()) return $next($request);
        $cert = app(CertificadosService::class);

        if ($inv = $request->session()->get(CertificadoController::SESION)) {
            $request->session()->forget(CertificadoController::SESION);
            if ($c = $cert->porToken((string) ($inv['token'] ?? ''))) {
                $cert->inscribir($c, $user);
                if ($request->isMethod('GET') && !$request->expectsJson()) {
                    return redirect()->route('certificados.formulario', $c)->with('success', __('Quedaste inscrito en la campaña «:nombre». Completa y firma tu certificado.', ['nombre' => $c->name]));
                }
            }
        }

        if ($request->isMethod('GET') && !$request->expectsJson() && !$request->routeIs(...self::LIBRES)
            && !$user->tienePermiso('campanas') && ($p = $cert->pendiente($user))) {
            return redirect()->route('certificados.formulario', $p->campaign_id)
                ->with('aviso', __('Antes de continuar, completa y firma el certificado de la campaña «:nombre».', ['nombre' => $p->campaign->name]));
        }
        return $next($request);
    }
}
