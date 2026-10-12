<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmisionProgramada;
use App\Models\MetaPage;
use App\Models\PlantillaEditor;
use App\Models\RecursoEnVivo;
use App\Models\TransmisionEnVivo;
use App\Models\YoutubeCanal;
use App\Services\CuentasAppService;
use App\Services\EmisionesService;
use App\Services\EmisorClient;
use App\Services\EnVivoProgramadoService;
use App\Services\MetaPageTokenResolver;
use App\Services\MiniaturaService;
use App\Services\PushService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * API para la app del editor (llega por el backend de esnoticia con X-Editus-Token y el "usuario"
 * de la app): celulares para notificaciones, en vivos programados con cámaras y pregrabados.
 * Cada usuario de la app solo ve y toca lo suyo.
 */
class ProgramadosAppController extends Controller
{
    public function __construct(private CuentasAppService $cuentas)
    {
    }

    // ---------------------------------------------------------- celulares

    /** POST /api/dispositivos {usuario, token, plataforma} */
    public function registrarDispositivo(Request $request, PushService $push): JsonResponse
    {
        $d = $request->validate(['usuario' => ['required', 'string', 'max:60'], 'token' => ['required', 'string', 'max:255'], 'plataforma' => ['nullable', 'string', 'max:10']]);
        if (!PushService::tokenValido($d['token'])) return $this->error('El token de notificaciones no es válido.');
        $push->registrar($d['token'], null, $d['usuario'], $d['plataforma'] ?? null);
        return response()->json(['success' => true]);
    }

    /** POST /api/dispositivos/quitar {token} (al cerrar sesión) */
    public function quitarDispositivo(Request $request, PushService $push): JsonResponse
    {
        $push->quitar((string) $request->input('token', ''));
        return response()->json(['success' => true]);
    }

    // ------------------------------------------- en vivo programado (cámaras)

    /** POST /api/en-vivo/programar */
    public function programarEnVivo(Request $request, EnVivoProgramadoService $programados): JsonResponse
    {
        $d = $request->validate([
            'usuario' => ['required', 'string', 'max:60'], 'usuario_nombre' => ['nullable', 'string', 'max:100'],
            'page_ids' => ['nullable', 'array', 'max:10'], 'page_ids.*' => ['string'],
            'youtube_canal_ids' => ['nullable', 'array', 'max:5'], 'youtube_canal_ids.*' => ['integer'],
            'titulo' => ['required', 'string', 'max:200'], 'descripcion' => ['nullable', 'string', 'max:5000'],
            'plantilla' => ['nullable', 'array'], 'programada_en' => ['required', 'string', 'max:40'], 'miniatura' => ['nullable', 'string', 'max:3000000'], 'anuncio_facebook' => ['nullable', 'boolean'],
        ]);
        try {
            $cuando = $this->hora($d['programada_en']);
            [$paginas, $canales] = $this->destinos($d);
            MetaPageTokenResolver::preferirUsuarioApp($d['usuario']);
            $miniatura = !empty($d['miniatura']) ? app(MiniaturaService::class)->guardarBase64($d['miniatura']) : null;
            $t = $programados->programar(null, [
                'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'] ?? null, 'programada_en' => $cuando, 'miniatura' => $miniatura, 'usuario_app' => $d['usuario'],
                'anuncio_facebook' => (bool) ($d['anuncio_facebook'] ?? true),
            ], $paginas, $canales, EnVivoController::normalizarPlantilla((array) ($d['plantilla'] ?? []), $d['titulo']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
        return response()->json(['success' => true, 'transmision' => $t->paraApi()]);
    }

    /** GET /api/en-vivo/programadas?usuario= */
    public function enVivoProgramadas(Request $request): JsonResponse
    {
        $usuario = (string) $request->query('usuario', '');
        if ($usuario === '' || !Schema::hasColumn('transmisiones_en_vivo', 'programada_en')) return response()->json(['success' => true, 'transmisiones' => []]);
        $lista = TransmisionEnVivo::with('page')->where('usuario_app', $usuario)->where('estado', 'programada')->orderBy('programada_en')->limit(30)->get();
        return response()->json(['success' => true, 'transmisiones' => $lista->map(fn($t) => $t->paraApi())->values()]);
    }

    /** POST /api/en-vivo/{id}/abrir {usuario} */
    public function abrirEnVivo(Request $request, TransmisionEnVivo $transmision, EnVivoController $api): JsonResponse
    {
        if (!$this->esSuya($transmision->usuario_app, $request)) return $this->error('Esta transmisión no es tuya.', 403);
        MetaPageTokenResolver::preferirUsuarioApp($transmision->usuario_app);
        return $api->abrirSala($transmision);
    }

    /** POST /api/en-vivo/{id}/cancelar {usuario} */
    public function cancelarEnVivo(Request $request, TransmisionEnVivo $transmision, EnVivoProgramadoService $programados): JsonResponse
    {
        if (!$this->esSuya($transmision->usuario_app, $request)) return $this->error('Esta transmisión no es tuya.', 403);
        $programados->cancelar($transmision, 'Cancelada desde la app.');
        return response()->json(['success' => true, 'transmision' => $transmision->fresh()->paraApi()]);
    }

    // --------------------------------------------------------- pregrabados

    /** GET /api/pregrabados?usuario= */
    public function pregrabados(Request $request, EmisorClient $emisor): JsonResponse
    {
        $usuario = (string) $request->query('usuario', '');
        $lista = Schema::hasTable('emisiones_programadas') && $usuario !== ''
            ? EmisionProgramada::where('usuario_app', $usuario)->orderByRaw("CASE WHEN estado IN ('subiendo','procesando','listo','al_aire') THEN 0 ELSE 1 END")->orderByDesc('programada_en')->limit(40)->get()
            : collect();
        return response()->json(['success' => true, 'configurado' => $emisor->configurado(), 'max_bytes' => (int) config('services.emisor.max_gb', 6) * 1024 ** 3,
            'pregrabados' => $lista->map(fn($e) => $e->paraVista())->values()]);
    }

    /** POST /api/pregrabados: crea la programación y devuelve la URL a la que la app sube el video por partes. */
    public function crearPregrabado(Request $request, EmisionesService $emisiones, EmisorClient $emisor): JsonResponse
    {
        if (!$emisor->configurado()) return $this->error('El servidor de pregrabados no está configurado en editus (EMISOR_URL y EMISOR_SECRETO).');
        $d = $request->validate([
            'usuario' => ['required', 'string', 'max:60'], 'usuario_nombre' => ['nullable', 'string', 'max:100'],
            'page_ids' => ['nullable', 'array', 'max:10'], 'page_ids.*' => ['string'],
            'youtube_canal_ids' => ['nullable', 'array', 'max:5'], 'youtube_canal_ids.*' => ['integer'],
            'titulo' => ['required', 'string', 'max:200'], 'descripcion' => ['nullable', 'string', 'max:5000'],
            'programada_en' => ['required', 'string', 'max:40'], 'archivo_nombre' => ['required', 'string', 'max:255'], 'archivo_bytes' => ['required', 'integer', 'min:1000'],
            'logo_id' => ['nullable', 'integer'], 'marco_id' => ['nullable', 'integer'], 'etiqueta' => ['nullable', 'boolean'], 'cuenta' => ['nullable', 'boolean'],
            'calidad' => ['nullable', 'in:auto,720'], 'miniatura' => ['nullable', 'string', 'max:3000000'], 'anuncio_facebook' => ['nullable', 'boolean'],
        ]);
        $max = (int) config('services.emisor.max_gb', 6) * 1024 ** 3;
        if ($d['archivo_bytes'] > $max) return $this->error('El video supera el máximo de ' . config('services.emisor.max_gb', 6) . ' GB.');
        try {
            $cuando = $this->hora($d['programada_en'], 10);
            [$paginas, $canales] = $this->destinos($d);
            $logo = !empty($d['logo_id']) ? PlantillaEditor::whereNotNull('logo_path')->find($d['logo_id']) : null;
            $marco = !empty($d['marco_id']) && Schema::hasTable('recursos_en_vivo') ? RecursoEnVivo::where('activo', true)->where('uso', 'plantilla')->find($d['marco_id']) : null;
            $miniatura = !empty($d['miniatura']) ? app(MiniaturaService::class)->guardarBase64($d['miniatura']) : null;
            $e = $emisiones->crear(null, [
                'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'] ?? null, 'programada_en' => $cuando, 'usuario_app' => $d['usuario'], 'miniatura' => $miniatura,
                'anuncio_facebook' => (bool) ($d['anuncio_facebook'] ?? true),
                'archivo_nombre' => $d['archivo_nombre'], 'archivo_bytes' => $d['archivo_bytes'],
                'plantilla' => ['logo_id' => $logo?->id, 'logo_url' => $logo?->logoUrl(), 'marco_id' => $marco?->id, 'marco_url' => $marco?->url(),
                    'etiqueta' => $request->boolean('etiqueta', true), 'cuenta' => $request->boolean('cuenta', true), 'calidad' => ($d['calidad'] ?? 'auto') === '720' ? '720' : 'auto'],
            ], $paginas, $canales);
        } catch (\Throwable $ex) {
            return $this->error($ex->getMessage());
        }
        return response()->json(['success' => true, 'pregrabado' => $e->paraVista(), 'subida' => $emisor->urlSubida($e->emisor_id, (int) $d['archivo_bytes'])]);
    }

    /** POST /api/pregrabados/{id}/reanudar {usuario, archivo_bytes} */
    public function reanudarPregrabado(Request $request, EmisionProgramada $emision, EmisorClient $emisor): JsonResponse
    {
        if (!$this->esSuya($emision->usuario_app, $request)) return $this->error('Este pregrabado no es tuyo.', 403);
        if ($emision->estado !== 'subiendo') return $this->error('Este video ya no está esperando la subida.');
        if ((int) $request->input('archivo_bytes') !== (int) $emision->archivo_bytes) return $this->error('Ese no es el mismo archivo: elige el video «' . $emision->archivo_nombre . '».');
        $emision->touch();
        return response()->json(['success' => true, 'pregrabado' => $emision->paraVista(), 'subida' => $emisor->urlSubida($emision->emisor_id, (int) $emision->archivo_bytes)]);
    }

    /** POST /api/pregrabados/{id}/subido {usuario} */
    public function pregrabadoSubido(Request $request, EmisionProgramada $emision, EmisorClient $emisor, EmisionesService $emisiones): JsonResponse
    {
        if (!$this->esSuya($emision->usuario_app, $request)) return $this->error('Este pregrabado no es tuyo.', 403);
        try {
            $v = $emisor->estado($emision->emisor_id);
            if (!$v) return $this->error('El servidor de pregrabados no tiene el video. Vuelve a subirlo.');
            $emisiones->aplicar($emision, $v);
        } catch (\Throwable $e) {
            return $this->error('No se pudo preparar el video: ' . $e->getMessage());
        }
        return response()->json(['success' => true, 'pregrabado' => $emision->refresh()->paraVista()]);
    }

    /** POST /api/pregrabados/{id}/cancelar {usuario} */
    public function cancelarPregrabado(Request $request, EmisionProgramada $emision, EmisionesService $emisiones): JsonResponse
    {
        if (!$this->esSuya($emision->usuario_app, $request)) return $this->error('Este pregrabado no es tuyo.', 403);
        $emisiones->cancelar($emision);
        return response()->json(['success' => true, 'pregrabado' => $emision->refresh()->paraVista()]);
    }

    /** POST /api/pregrabados/{id}/ahora {usuario}: sale en 2 minutos (para probar). */
    public function pregrabadoAhora(Request $request, EmisionProgramada $emision): JsonResponse
    {
        if (!$this->esSuya($emision->usuario_app, $request)) return $this->error('Este pregrabado no es tuyo.', 403);
        if ($emision->estado !== 'listo') return $this->error('El video todavía no está listo para salir.');
        $emision->fill(['programada_en' => now()->addMinutes(2)->seconds(0)])->save();
        return response()->json(['success' => true, 'pregrabado' => $emision->refresh()->paraVista()]);
    }

    /** POST /api/pregrabados/{id}/quitar {usuario}: lo quita del historial. */
    public function quitarPregrabado(Request $request, EmisionProgramada $emision, EmisionesService $emisiones, EmisorClient $emisor): JsonResponse
    {
        if (!$this->esSuya($emision->usuario_app, $request)) return $this->error('Este pregrabado no es tuyo.', 403);
        if ($emision->pendiente()) $emisiones->cancelar($emision);
        try { $emisor->borrar($emision->emisor_id); } catch (\Throwable) {}
        $emision->delete();
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- ayudas

    /** Hora enviada por la app (ISO 8601 con zona, o "Y-m-d H:i" en hora de Colombia). */
    private function hora(string $valor, int $minimoMin = 10): Carbon
    {
        try {
            $c = Carbon::parse($valor, config('app.timezone'))->setTimezone(config('app.timezone'))->seconds(0);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('La fecha y hora no son válidas.');
        }
        if ($c->lt(now()->addMinutes($minimoMin))) throw new \InvalidArgumentException("Programa al menos {$minimoMin} minutos en el futuro.");
        if ($c->gt(now()->addDays(365))) throw new \InvalidArgumentException('Se puede programar hasta con un año de anticipación.');
        return $c;
    }

    /** Páginas y canales elegidos, solo si el usuario de la app puede usarlos. */
    private function destinos(array $d): array
    {
        $ids = array_values(array_unique(array_map('strval', (array) ($d['page_ids'] ?? []))));
        $paginas = MetaPage::whereIn('page_id', $ids)->get()->sortBy(fn($p) => array_search((string) $p->page_id, $ids, true))->values();
        $canales = Schema::hasTable('youtube_canales') ? YoutubeCanal::whereIn('id', array_map('intval', (array) ($d['youtube_canal_ids'] ?? [])))->get() : collect();
        if ($paginas->isEmpty() && $canales->isEmpty()) throw new \InvalidArgumentException('Elige al menos una página de Facebook o un canal de YouTube.');
        if (!$this->cuentas->puedeUsarPaginas($d['usuario'], $paginas->pluck('page_id')->all(), $d['usuario_nombre'] ?? null)
            || !$this->cuentas->puedeUsarCanales($d['usuario'], $canales->pluck('id')->all(), $d['usuario_nombre'] ?? null)) {
            throw new \InvalidArgumentException('Alguna de las páginas o canales elegidos no está conectada a tu cuenta. Revisa "Mis cuentas" en la app.');
        }
        return [$paginas, $canales];
    }

    private function esSuya(?string $dueno, Request $request): bool
    {
        $usuario = (string) $request->input('usuario', $request->query('usuario', ''));
        return $usuario !== '' && $dueno !== null && $dueno === $usuario;
    }

    private function error(string $msg, int $codigo = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $msg], $codigo);
    }
}
