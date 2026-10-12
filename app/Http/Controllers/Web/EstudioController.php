<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ActivoEnVivo;
use App\Models\MarcaEnVivo;
use App\Models\TransmisionEnVivo;
use App\Services\ComentariosEnVivoService;
use App\Services\EstudioEnVivoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Panel del estudio en vivo (al estilo StreamYard): marcas guardadas, archivos propios
 * (logos, superposiciones, fondos, clips, efectos y música), notas privadas y comentarios en vivo.
 * La lógica la comparte con la app del editor (EstudioEnVivoService).
 */
class EstudioController extends Controller
{
    public function __construct(private EstudioEnVivoService $estudio)
    {
    }

    private function dueno(): array
    {
        return ['user_id' => (int) Auth::id()];
    }

    /** Marcas del usuario (si no tiene, se crea "Marca 1"). */
    public static function marcasDe(int $userId): array
    {
        return app(EstudioEnVivoService::class)->marcas(['user_id' => $userId]);
    }

    public static function activosDe(int $userId): array
    {
        return app(EstudioEnVivoService::class)->activos(['user_id' => $userId]);
    }

    // ------------------------------------------------------------------ marcas

    public function marcaCrear(Request $request): JsonResponse
    {
        $d = $request->validate(['nombre' => ['nullable', 'string', 'max:60'], 'copiar_de' => ['nullable', 'integer']]);
        try {
            return response()->json(['success' => true, 'marca' => $this->estudio->crearMarca($this->dueno(), $d['nombre'] ?? null, $d['copiar_de'] ?? null)->paraApi()]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /** Guarda cambios de la marca y, si se indica la transmisión, los aplica en pantalla al instante. */
    public function marcaGuardar(Request $request, MarcaEnVivo $marca): JsonResponse
    {
        $this->propiaMarca($marca);
        $request->validate([
            'nombre' => ['nullable', 'string', 'max:60'], 'datos' => ['nullable', 'array'],
            'datos.banners' => ['nullable', 'array', 'max:40'], 'datos.banners.*.texto' => ['nullable', 'string', 'max:300'],
            'datos.banners.*.titulo' => ['nullable', 'string', 'max:60'], 'datos.banners.*.cinta' => ['nullable', 'boolean'],
            'transmision_id' => ['nullable', 'integer'],
        ]);
        // (validate() solo devuelve las claves con reglas; la marca se normaliza completa en el servicio)
        $this->estudio->guardarMarca($marca, $request->input('nombre'), is_array($request->input('datos')) ? $request->input('datos') : null);
        $plantilla = null;
        if ($request->filled('transmision_id')) {
            $t = TransmisionEnVivo::findOrFail((int) $request->input('transmision_id'));
            $this->propia($t);
            $plantilla = $this->estudio->aplicar($t, $marca, [EnVivoWebController::class, 'plantillaDelPlan']);
        }
        return response()->json(['success' => true, 'marca' => $marca->paraApi(), 'plantilla' => $plantilla]);
    }

    public function marcaBorrar(MarcaEnVivo $marca): JsonResponse
    {
        $this->propiaMarca($marca);
        try {
            $this->estudio->borrarMarca($this->dueno(), $marca);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return response()->json(['success' => true]);
    }

    /** POST /en-vivo/{t}/marca {marca_id}: usa esa marca en la transmisión. */
    public function marcaAplicar(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        $d = $request->validate(['marca_id' => ['required', 'integer'], 'adoptar' => ['nullable', 'boolean']]);
        $marca = MarcaEnVivo::findOrFail($d['marca_id']);
        $this->propiaMarca($marca);
        $plantilla = $this->estudio->aplicar($transmision, $marca, [EnVivoWebController::class, 'plantillaDelPlan'], (bool) ($d['adoptar'] ?? false));
        return response()->json(['success' => true, 'plantilla' => $plantilla, 'marca' => $marca->fresh()->paraApi()]);
    }

    // ------------------------------------------------------------------ archivos

    public function activoSubir(Request $request): JsonResponse
    {
        $request->validate([
            'tipo' => ['required', 'string'], 'archivo' => ['required', 'file'],
            'nombre' => ['nullable', 'string', 'max:120'], 'duracion' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ], ['archivo.uploaded' => 'El archivo no se pudo subir (puede superar el límite del servidor).']);
        try {
            $a = $this->estudio->subirActivo($this->dueno(), $request->file('archivo'), (string) $request->input('tipo'), $request->input('nombre'), $request->integer('duracion') ?: null);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return response()->json(['success' => true, 'activo' => $a->paraApi()]);
    }

    public function activoBorrar(ActivoEnVivo $activo): JsonResponse
    {
        abort_unless((int) $activo->user_id === (int) Auth::id() || Auth::user()->isAdmin(), 403);
        $this->estudio->borrarActivo($activo);
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ notas y comentarios

    public function notas(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        $d = $request->validate(['notas' => ['nullable', 'string', 'max:20000']]);
        $transmision->forceFill(['notas' => $d['notas'] ?? null])->save();
        return response()->json(['success' => true]);
    }

    public function comentarios(TransmisionEnVivo $transmision, ComentariosEnVivoService $servicio): JsonResponse
    {
        $this->propia($transmision);
        \App\Services\MetaPageTokenResolver::preferirUsuarioWeb((int) Auth::id());
        return response()->json(['success' => true] + $servicio->leer($transmision) + ['estado' => $transmision->estado]);
    }

    // ------------------------------------------------------------------

    private function propia(TransmisionEnVivo $t): void
    {
        abort_unless(Auth::user()->isAdmin() || (int) ($t->user_id ?? 0) === (int) Auth::id(), 403);
    }

    private function propiaMarca(MarcaEnVivo $m): void
    {
        abort_unless((int) $m->user_id === (int) Auth::id(), 403);
    }

    private function error(string $msg): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $msg], 422);
    }
}
