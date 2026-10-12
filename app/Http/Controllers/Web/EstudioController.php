<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\EnVivoController;
use App\Http\Controllers\Controller;
use App\Models\ActivoEnVivo;
use App\Models\MarcaEnVivo;
use App\Models\TransmisionEnVivo;
use App\Services\ComentariosEnVivoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Panel del estudio en vivo (al estilo StreamYard): marcas guardadas, archivos propios
 * (logos, superposiciones, fondos, clips, efectos y música), notas privadas y comentarios en vivo.
 */
class EstudioController extends Controller
{
    /** Límite de tamaño por tipo, en MB */
    private const MAX_MB = ['logo' => 8, 'superposicion' => 8, 'fondo' => 8, 'clip' => 300, 'sonido' => 20, 'musica' => 40];
    private const EXTENSIONES = [
        'logo' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], 'superposicion' => ['png', 'webp', 'gif'], 'fondo' => ['png', 'jpg', 'jpeg', 'webp'],
        'clip' => ['mp4', 'webm', 'mov', 'm4v'], 'sonido' => ['mp3', 'wav', 'ogg', 'm4a', 'aac'], 'musica' => ['mp3', 'wav', 'ogg', 'm4a', 'aac'],
    ];

    public function __construct(private EnVivoController $api)
    {
    }

    /** Marcas del usuario (si no tiene, se crea "Marca 1"). */
    public static function marcasDe(int $userId): array
    {
        $marcas = MarcaEnVivo::where('user_id', $userId)->orderBy('id')->get();
        if ($marcas->isEmpty()) $marcas = collect([MarcaEnVivo::create(['user_id' => $userId, 'nombre' => 'Marca 1', 'datos' => MarcaEnVivo::DEFECTO])]);
        return $marcas->map(fn($m) => $m->paraApi())->values()->all();
    }

    public static function activosDe(int $userId): array
    {
        return ActivoEnVivo::where('user_id', $userId)->orderByDesc('id')->limit(300)->get()->map(fn($a) => $a->paraApi())->values()->all();
    }

    // ------------------------------------------------------------------ marcas

    public function marcaCrear(Request $request): JsonResponse
    {
        $d = $request->validate(['nombre' => ['nullable', 'string', 'max:60'], 'copiar_de' => ['nullable', 'integer']]);
        if (MarcaEnVivo::where('user_id', Auth::id())->count() >= 20) return $this->error('Puedes tener hasta 20 marcas.');
        $base = !empty($d['copiar_de']) ? MarcaEnVivo::where('user_id', Auth::id())->find($d['copiar_de']) : null;
        $n = MarcaEnVivo::where('user_id', Auth::id())->count() + 1;
        $m = MarcaEnVivo::create(['user_id' => Auth::id(), 'nombre' => trim((string) ($d['nombre'] ?? '')) ?: "Marca {$n}", 'datos' => $base ? $base->datosCompletos() : MarcaEnVivo::DEFECTO]);
        return response()->json(['success' => true, 'marca' => $m->paraApi()]);
    }

    /** Guarda cambios de la marca y, si se indica la transmisión, los aplica en pantalla al instante. */
    public function marcaGuardar(Request $request, MarcaEnVivo $marca): JsonResponse
    {
        $this->propiaMarca($marca);
        $d = $request->validate([
            'nombre' => ['nullable', 'string', 'max:60'], 'datos' => ['nullable', 'array'],
            'datos.banners' => ['nullable', 'array', 'max:40'], 'datos.banners.*.texto' => ['nullable', 'string', 'max:300'],
            'datos.banners.*.titulo' => ['nullable', 'string', 'max:60'], 'datos.banners.*.cinta' => ['nullable', 'boolean'],
            'transmision_id' => ['nullable', 'integer'],
        ]);
        if (!empty($d['nombre'])) $marca->nombre = trim($d['nombre']);
        // (validate() solo devuelve las claves con reglas; la marca se normaliza completa aquí)
        if (is_array($request->input('datos'))) $marca->datos = self::normalizarMarca(array_merge($marca->datosCompletos(), $request->input('datos')));
        $marca->save();
        $plantilla = null;
        if (!empty($d['transmision_id'])) {
            $t = TransmisionEnVivo::findOrFail($d['transmision_id']);
            $this->propia($t);
            $plantilla = $this->aplicar($t, $marca);
        }
        return response()->json(['success' => true, 'marca' => $marca->paraApi(), 'plantilla' => $plantilla]);
    }

    public function marcaBorrar(MarcaEnVivo $marca): JsonResponse
    {
        $this->propiaMarca($marca);
        if (MarcaEnVivo::where('user_id', Auth::id())->count() <= 1) return $this->error('Debe quedar al menos una marca.');
        $marca->delete();
        return response()->json(['success' => true]);
    }

    /** POST /en-vivo/{t}/marca {marca_id}: usa esa marca en la transmisión. */
    public function marcaAplicar(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        $d = $request->validate(['marca_id' => ['required', 'integer']]);
        $marca = MarcaEnVivo::findOrFail($d['marca_id']);
        $this->propiaMarca($marca);
        return response()->json(['success' => true, 'plantilla' => $this->aplicar($transmision, $marca), 'marca' => $marca->paraApi()]);
    }

    private function aplicar(TransmisionEnVivo $t, MarcaEnVivo $marca): array
    {
        $plantilla = EnVivoWebController::plantillaDelPlan($marca->paraPlantilla());
        $r = $this->api->plantilla(new Request(['plantilla' => $plantilla]), $t);
        $j = $r->getData(true);
        if (empty($j['success'])) throw new \RuntimeException($j['error'] ?? 'No se pudo aplicar la marca');
        $t->forceFill(['marca_id' => $marca->id])->save();
        return $j['plantilla'];
    }

    public static function normalizarMarca(array $m): array
    {
        $p = EnVivoController::normalizarPlantilla($m, '');
        $out = array_intersect_key($p, array_flip(MarcaEnVivo::A_PLANTILLA));
        $banners = [];
        foreach (array_slice((array) ($m['banners'] ?? []), 0, 40) as $b) {
            if (!is_array($b) || trim((string) ($b['texto'] ?? '')) === '') continue;
            $banners[] = ['id' => preg_match('/^[a-z0-9]{4,20}$/', (string) ($b['id'] ?? '')) ? $b['id'] : Str::lower(Str::random(8)),
                'titulo' => Str::limit(trim((string) ($b['titulo'] ?? '')), 60, ''), 'texto' => Str::limit(trim((string) $b['texto']), 300, ''), 'cinta' => (bool) ($b['cinta'] ?? false)];
        }
        $out['banners'] = $banners;
        return $out;
    }

    // ------------------------------------------------------------------ archivos

    public function activoSubir(Request $request): JsonResponse
    {
        $tipo = (string) $request->input('tipo');
        if (!in_array($tipo, ActivoEnVivo::TIPOS, true)) return $this->error('Tipo de archivo no válido.');
        $request->validate([
            'archivo' => ['required', 'file', 'max:' . (self::MAX_MB[$tipo] * 1024)],
            'nombre' => ['nullable', 'string', 'max:120'], 'duracion' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ], ['archivo.max' => 'El archivo supera ' . self::MAX_MB[$tipo] . ' MB.', 'archivo.uploaded' => 'El archivo no se pudo subir (puede superar el límite del servidor).']);
        if (ActivoEnVivo::where('user_id', Auth::id())->count() >= 300) return $this->error('Llegaste al máximo de 300 archivos. Borra algunos.');
        $f = $request->file('archivo');
        $ext = strtolower($f->getClientOriginalExtension() ?: $f->extension());
        if (!in_array($ext, self::EXTENSIONES[$tipo], true)) return $this->error('Formato no permitido para ' . $tipo . ': usa ' . implode(', ', self::EXTENSIONES[$tipo]) . '.');
        $mime = (string) $f->getMimeType();
        $esperado = in_array($tipo, ActivoEnVivo::IMAGEN, true) ? 'image/' : ($tipo === 'clip' ? 'video/' : 'audio/');
        // Algunos navegadores marcan los .mp3 / .m4a como video/mp4 o application/octet-stream
        if (!str_starts_with($mime, $esperado) && !($esperado === 'audio/' && in_array($mime, ['video/mp4', 'application/octet-stream'], true))
            && !($esperado === 'video/' && $mime === 'application/octet-stream')) {
            return $this->error('El archivo no parece ' . ($esperado === 'image/' ? 'una imagen' : ($esperado === 'video/' ? 'un video' : 'un audio')) . '.');
        }
        $nombreArchivo = 'u' . Auth::id() . '-' . $tipo . '-' . Str::lower(Str::random(16)) . '.' . $ext;
        $ruta = $f->storeAs('en-vivo/recursos', $nombreArchivo, 'public');
        $a = ActivoEnVivo::create([
            'user_id' => Auth::id(), 'tipo' => $tipo, 'archivo' => $ruta, 'mime' => $esperado === 'audio/' && !str_starts_with($mime, 'audio/') ? 'audio/' . $ext : $mime,
            'bytes' => (int) $f->getSize(), 'duracion' => $request->integer('duracion') ?: null,
            'nombre' => Str::limit(trim((string) $request->input('nombre')) ?: pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME), 120, ''),
        ]);
        return response()->json(['success' => true, 'activo' => $a->paraApi()]);
    }

    public function activoBorrar(ActivoEnVivo $activo): JsonResponse
    {
        abort_unless((int) $activo->user_id === (int) Auth::id() || Auth::user()->isAdmin(), 403);
        Storage::disk('public')->delete($activo->archivo);
        $activo->delete();
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
