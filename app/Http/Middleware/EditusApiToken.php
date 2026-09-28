<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege la API de integración (routes/api.php) con un token compartido:
 * el backend de esnoticia envía el header X-Editus-Token con el mismo
 * valor que EDITUS_INGEST_TOKEN en el .env de editus.
 */
class EditusApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('services.editus.ingest_token');
        $recibido = (string) ($request->header('X-Editus-Token') ?? $request->bearerToken() ?? '');

        if ($esperado === '' || $recibido === '' || !hash_equals($esperado, $recibido)) {
            return response()->json([
                'success' => false,
                'error' => $esperado === ''
                    ? 'EDITUS_INGEST_TOKEN no está configurado en editus'
                    : 'Token de integración inválido',
            ], 401);
        }

        return $next($request);
    }
}
