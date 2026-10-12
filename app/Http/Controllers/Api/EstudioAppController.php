<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivoEnVivo;
use App\Models\MarcaEnVivo;
use App\Models\TransmisionEnVivo;
use App\Services\ComentariosEnVivoService;
use App\Services\EstudioEnVivoService;
use App\Services\MetaPageTokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estudio en vivo para la app del editor (vía backend de esnoticia, con X-Editus-Token):
 * marcas, archivos propios, notas y comentarios del usuario de la app ("usuario").
 * Cada usuario solo ve y usa lo suyo. Los archivos se suben con firma: SubidasController::activo.
 */
class EstudioAppController extends Controller
{
    public function __construct(private EstudioEnVivoService $estudio)
    {
    }

    private function usuario(Request $r): string
    {
        $u = trim((string) $r->input('usuario', $r->query('usuario', '')));
        abort_if($u === '' || strlen($u) > 60, 422, 'Falta el usuario');
        return $u;
    }

    /** GET /api/en-vivo/estudio?usuario= */
    public function estudio(Request $request): JsonResponse
    {
        $d = ['usuario_app' => $this->usuario($request)];
        if (!EstudioEnVivoService::disponible()) return $this->error('editus aún no tiene el estudio: falta php artisan migrate --force');
        return response()->json(['success' => true, 'marcas' => $this->estudio->marcas($d), 'activos' => $this->estudio->activos($d)]);
    }

    public function marcaCrear(Request $request): JsonResponse
    {
        $d = $request->validate(['nombre' => ['nullable', 'string', 'max:60'], 'copiar_de' => ['nullable', 'integer']]);
        try {
            return response()->json(['success' => true, 'marca' => $this->estudio->crearMarca(['usuario_app' => $this->usuario($request)], $d['nombre'] ?? null, $d['copiar_de'] ?? null)->paraApi()]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function marcaGuardar(Request $request, MarcaEnVivo $marca): JsonResponse
    {
        $u = $this->usuario($request);
        abort_unless((string) $marca->usuario_app === $u, 403);
        $request->validate([
            'nombre' => ['nullable', 'string', 'max:60'], 'datos' => ['nullable', 'array'], 'datos.banners' => ['nullable', 'array', 'max:40'],
            'transmision_id' => ['nullable', 'integer'],
        ]);
        $this->estudio->guardarMarca($marca, $request->input('nombre'), is_array($request->input('datos')) ? $request->input('datos') : null);
        $plantilla = null;
        if ($request->filled('transmision_id')) {
            $t = $this->transmision((int) $request->input('transmision_id'), $u);
            $plantilla = $this->estudio->aplicar($t, $marca);
        }
        return response()->json(['success' => true, 'marca' => $marca->paraApi(), 'plantilla' => $plantilla]);
    }

    public function marcaBorrar(Request $request, MarcaEnVivo $marca): JsonResponse
    {
        $u = $this->usuario($request);
        abort_unless((string) $marca->usuario_app === $u, 403);
        try {
            $this->estudio->borrarMarca(['usuario_app' => $u], $marca);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return response()->json(['success' => true]);
    }

    /** POST /api/en-vivo/{t}/marca {usuario, marca_id, adoptar?} */
    public function marcaAplicar(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $u = $this->usuario($request);
        $this->propia($transmision, $u);
        $d = $request->validate(['marca_id' => ['required', 'integer'], 'adoptar' => ['nullable', 'boolean']]);
        $marca = $this->estudio->marcaDe(['usuario_app' => $u], $d['marca_id']);
        abort_unless($marca, 403);
        $plantilla = $this->estudio->aplicar($transmision, $marca, null, (bool) ($d['adoptar'] ?? false));
        return response()->json(['success' => true, 'plantilla' => $plantilla, 'marca' => $marca->fresh()->paraApi()]);
    }

    public function activoBorrar(Request $request, ActivoEnVivo $activo): JsonResponse
    {
        abort_unless((string) $activo->usuario_app === $this->usuario($request), 403);
        $this->estudio->borrarActivo($activo);
        return response()->json(['success' => true]);
    }

    public function notas(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision, $this->usuario($request));
        $d = $request->validate(['notas' => ['nullable', 'string', 'max:20000']]);
        $transmision->forceFill(['notas' => $d['notas'] ?? null])->save();
        return response()->json(['success' => true]);
    }

    public function comentarios(Request $request, TransmisionEnVivo $transmision, ComentariosEnVivoService $servicio): JsonResponse
    {
        $u = $this->usuario($request);
        $this->propia($transmision, $u);
        MetaPageTokenResolver::preferirUsuarioApp($u);
        return response()->json(['success' => true] + $servicio->leer($transmision) + ['estado' => $transmision->estado]);
    }

    // ------------------------------------------------------------------

    private function transmision(int $id, string $u): TransmisionEnVivo
    {
        $t = TransmisionEnVivo::findOrFail($id);
        $this->propia($t, $u);
        return $t;
    }

    private function propia(TransmisionEnVivo $t, string $u): void
    {
        abort_unless((string) $t->usuario_app === $u, 403, 'Esa transmisión no es tuya');
    }

    private function error(string $msg): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $msg], 422);
    }
}
