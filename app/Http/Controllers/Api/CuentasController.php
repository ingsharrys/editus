<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\YoutubeCanal;
use App\Services\CuentasAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas de cada usuario de la app del editor (API con token de integración):
 *
 *   GET  /api/cuentas?usuario=                     → páginas y canales que ve ese usuario (propios + organización)
 *   POST /api/cuentas/facebook/sincronizar         → vuelve a leer las páginas de su cuenta de Facebook
 *   POST /api/cuentas/facebook/desconectar         → borra su cuenta de Facebook y los tokens de sus páginas
 *   POST /api/cuentas/youtube/{canal}/desconectar  → quita un canal que él conectó
 *
 * La conexión en sí se hace en el navegador con un enlace firmado por el backend
 * (/auth/app/facebook y /auth/app/youtube, ver Web\CuentasAppController).
 */
class CuentasController extends Controller
{
    public function __construct(private CuentasAppService $cuentas)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        if ($usuario === null) return response()->json(['success' => false, 'error' => 'Falta el usuario'], 422);
        return response()->json(['success' => true] + $this->cuentas->resumen($usuario, $this->nombre($request)));
    }

    public function sincronizarFacebook(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        if ($usuario === null) return response()->json(['success' => false, 'error' => 'Falta el usuario'], 422);
        try {
            $n = $this->cuentas->resincronizarFacebook($usuario);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'paginas' => $n] + $this->cuentas->resumen($usuario, $this->nombre($request)));
    }

    public function desconectarFacebook(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        if ($usuario === null) return response()->json(['success' => false, 'error' => 'Falta el usuario'], 422);
        $n = $this->cuentas->desconectarFacebook($usuario);
        return response()->json(['success' => true, 'paginas' => $n] + $this->cuentas->resumen($usuario, $this->nombre($request)));
    }

    public function desconectarYoutube(Request $request, YoutubeCanal $canal): JsonResponse
    {
        $usuario = $this->usuario($request);
        if ($usuario === null) return response()->json(['success' => false, 'error' => 'Falta el usuario'], 422);
        try {
            $this->cuentas->desconectarCanal($canal, $usuario);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true] + $this->cuentas->resumen($usuario, $this->nombre($request)));
    }

    private function nombre(Request $request): ?string
    {
        $n = trim((string) $request->input('usuario_nombre', $request->query('usuario_nombre', '')));
        return $n === '' ? null : $n;
    }

    private function usuario(Request $request): ?string
    {
        $u = trim((string) $request->input('usuario', $request->query('usuario', '')));
        return $u === '' || strlen($u) > 60 ? null : $u;
    }
}
