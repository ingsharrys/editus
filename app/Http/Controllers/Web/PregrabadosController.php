<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmisionProgramada;
use App\Models\MetaPage;
use App\Models\PlantillaEditor;
use App\Models\RecursoEnVivo;
use App\Models\YoutubeCanal;
use App\Services\CuentasAppService;
use App\Services\EmisionesService;
use App\Services\EmisorClient;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Videos pregrabados que salen EN VIVO a una hora programada (como el "pregrabado" de StreamYard):
 * se sube el video editado, se eligen páginas / canales, fecha, hora y plantilla, y a esa hora sale
 * solo, sin que nadie esté conectado. El video viaja del navegador directo al emisor del VPS.
 */
class PregrabadosController extends Controller
{
    public function __construct(private EmisionesService $emisiones, private EmisorClient $emisor, private PlanService $planes)
    {
    }

    public function index(CuentasAppService $cuentas): View
    {
        $user = Auth::user();
        $lim = $this->planes->limites($user);
        return view('en-vivo.pregrabados', [
            'paginas' => $this->paginasPermitidas()->map(fn(MetaPage $p) => ['id' => $p->id, 'nombre' => (string) $p->name, 'foto' => $p->picture_url ?: $p->pictureUrl('small')])->values(),
            'canales' => $cuentas->canalesDe(null)->map(fn($c) => ['id' => $c->id, 'nombre' => (string) $c->titulo])->values(),
            'logos' => $this->logos(),
            'marcos' => $this->marcos(),
            'emisiones' => $this->lista(),
            'configurado' => $this->emisor->configurado() && Schema::hasTable('emisiones_programadas'),
            'limites' => $lim,
            'esCliente' => !$this->planes->sinLimites($user),
            'maxBytes' => $this->maxBytes(),
            'zona' => config('app.timezone'),
        ]);
    }

    /** Lista para refrescar la página sin recargar. */
    public function estado(): JsonResponse
    {
        return response()->json(['emisiones' => $this->lista()]);
    }

    /** Crea la programación y devuelve la URL a la que el navegador sube el video por partes. */
    public function store(Request $request): JsonResponse
    {
        if (!$this->emisor->configurado()) return $this->error(__('El servidor de pregrabados no está configurado (EMISOR_URL y EMISOR_SECRETO en el .env). Ver infra/en-vivo/README.md'));
        $user = Auth::user();
        $lim = $this->planes->limites($user);
        if (!$lim) return $this->error(__('Necesitas un plan activo para programar pregrabados.'), 403);
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:200'], 'descripcion' => ['nullable', 'string', 'max:5000'],
            'programada_en' => ['required', 'date_format:Y-m-d\TH:i'],
            'meta_page_ids' => ['nullable', 'array', 'max:10'], 'meta_page_ids.*' => ['integer'],
            'youtube_canal_ids' => ['nullable', 'array', 'max:5'], 'youtube_canal_ids.*' => ['integer'],
            'archivo_nombre' => ['required', 'string', 'max:255'], 'archivo_bytes' => ['required', 'integer', 'min:1000'],
            'logo_id' => ['nullable', 'integer'], 'marco_id' => ['nullable', 'integer'],
            'etiqueta' => ['nullable', 'boolean'], 'cuenta' => ['nullable', 'boolean'], 'calidad' => ['nullable', 'in:auto,720'],
            'miniatura' => ['nullable', 'string', 'max:3000000'], 'anuncio_facebook' => ['nullable', 'boolean'],
        ]);
        $cuando = Carbon::createFromFormat('Y-m-d\TH:i', $d['programada_en'], config('app.timezone'))->seconds(0);
        if ($cuando->lt(now()->addMinutes(10))) return $this->error(__('Programa el video al menos 10 minutos en el futuro: hay que subirlo y prepararlo.'));
        if ($cuando->gt(now()->addDays(365))) return $this->error(__('Se puede programar hasta con un año de anticipación.'));
        if ($d['archivo_bytes'] > $this->maxBytes()) return $this->error(__('El video supera el máximo de :gb GB.', ['gb' => config('services.emisor.max_gb', 6)]));
        $cola = $lim['pregrabados_cola'] ?? null;
        if ($cola !== null && EmisionProgramada::where('user_id', $user->id)->whereIn('estado', EmisionProgramada::PENDIENTES)->count() >= $cola) {
            return $this->error(__('Tu plan :plan permite :n pregrabados programados a la vez. Espera a que salga alguno o cancela uno.', ['plan' => $lim['nombre'], 'n' => $cola]));
        }

        $permitidas = $this->paginasPermitidas()->keyBy('id');
        $paginas = collect((array) ($d['meta_page_ids'] ?? []))->map(fn($id) => $permitidas->get((int) $id));
        if ($paginas->contains(null)) return $this->error(__('Alguna de las páginas elegidas no está conectada a tu cuenta.'));
        if ($motivo = $this->planes->validarPaginas($user, $paginas->pluck('id')->all())) return $this->error($motivo);
        $canalesOk = app(CuentasAppService::class)->canalesDe(null)->keyBy('id');
        $canales = collect((array) ($d['youtube_canal_ids'] ?? []))->map(fn($id) => $canalesOk->get((int) $id));
        if ($canales->contains(null)) return $this->error(__('Alguno de los canales de YouTube no está disponible.'));
        if ($paginas->isEmpty() && $canales->isEmpty()) return $this->error(__('Elige al menos una página de Facebook o un canal de YouTube.'));

        $conLogo = !empty($lim['plantillas_logo']);
        $logo = $conLogo && !empty($d['logo_id']) ? PlantillaEditor::whereNotNull('logo_path')->find($d['logo_id']) : null;
        $marco = $conLogo && !empty($d['marco_id']) && Schema::hasTable('recursos_en_vivo') ? RecursoEnVivo::where('activo', true)->where('uso', 'plantilla')->find($d['marco_id']) : null;

        try {
            $miniatura = !empty($d['miniatura']) ? app(\App\Services\MiniaturaService::class)->guardarBase64($d['miniatura']) : null;
        } catch (\InvalidArgumentException $ex) {
            return $this->error($ex->getMessage());
        }
        $e = $this->emisiones->crear($user->id, [
            'miniatura' => $miniatura, 'anuncio_facebook' => (bool) ($d['anuncio_facebook'] ?? true),
            'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'] ?? null, 'programada_en' => $cuando,
            'archivo_nombre' => $d['archivo_nombre'], 'archivo_bytes' => $d['archivo_bytes'],
            'plantilla' => [
                'logo_id' => $logo?->id, 'logo_url' => $logo?->logoUrl(), 'marco_id' => $marco?->id, 'marco_url' => $marco?->url(),
                'etiqueta' => $request->boolean('etiqueta', true), 'cuenta' => $request->boolean('cuenta', true), 'calidad' => ($d['calidad'] ?? 'auto') === '720' ? '720' : 'auto',
            ],
        ], $paginas, $canales);
        return response()->json(['success' => true, 'emision' => $e->paraVista(), 'subida' => $this->emisor->urlSubida($e->emisor_id, (int) $d['archivo_bytes'])]);
    }

    /** Retomar una subida interrumpida (se vuelve a elegir el mismo archivo): URL nueva con su token. */
    public function reanudar(Request $request, EmisionProgramada $emision): JsonResponse
    {
        $this->propia($emision);
        if ($emision->estado !== 'subiendo') return $this->error(__('Este video ya no está esperando la subida.'));
        if ((int) $request->input('archivo_bytes') !== (int) $emision->archivo_bytes) return $this->error(__('Ese no es el mismo archivo: elige el video «:archivo».', ['archivo' => $emision->archivo_nombre]));
        $emision->touch();
        return response()->json(['success' => true, 'emision' => $emision->paraVista(), 'subida' => $this->emisor->urlSubida($emision->emisor_id, (int) $emision->archivo_bytes)]);
    }

    /** El navegador terminó de subir: se le pide al emisor que prepare el video. */
    public function subido(EmisionProgramada $emision): JsonResponse
    {
        $this->propia($emision);
        try {
            $v = $this->emisor->estado($emision->emisor_id);
            if (!$v) return $this->error(__('El servidor de pregrabados no tiene el video. Vuelve a subirlo.'));
            $this->emisiones->aplicar($emision, $v);
        } catch (\Throwable $e) {
            return $this->error(__('No se pudo preparar el video: :error', ['error' => $e->getMessage()]));
        }
        return response()->json(['success' => true, 'emision' => $emision->refresh()->paraVista()]);
    }

    public function cancelar(EmisionProgramada $emision): JsonResponse
    {
        $this->propia($emision);
        $this->emisiones->cancelar($emision);
        return response()->json(['success' => true, 'emision' => $emision->refresh()->paraVista()]);
    }

    /** Adelanta la salida: el video ya listo sale en 1-2 minutos (sirve para probar). */
    public function ahora(EmisionProgramada $emision): JsonResponse
    {
        $this->propia($emision);
        if ($emision->estado !== 'listo') return $this->error(__('El video todavía no está listo para salir.'));
        $emision->fill(['programada_en' => now()->addMinutes(2)->seconds(0)])->save();
        return response()->json(['success' => true, 'emision' => $emision->refresh()->paraVista()]);
    }

    /** Quita del historial (si estaba pendiente, primero se cancela). */
    public function destroy(EmisionProgramada $emision): JsonResponse
    {
        $this->propia($emision);
        if ($emision->pendiente()) $this->emisiones->cancelar($emision->refresh());
        try { $this->emisor->borrar($emision->emisor_id); } catch (\Throwable) {}
        $emision->delete();
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function lista(): array
    {
        if (!Schema::hasTable('emisiones_programadas')) return [];
        $user = Auth::user();
        return EmisionProgramada::with('user')->when(!$user->isAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->orderByRaw("CASE WHEN estado IN ('subiendo','procesando','listo','al_aire') THEN 0 ELSE 1 END")
            ->orderBy('programada_en')->limit(60)->get()
            ->sortBy(fn($e) => [$e->pendiente() ? 0 : 1, $e->pendiente() ? $e->programada_en->timestamp : -$e->programada_en->timestamp])
            ->values()->map(fn($e) => $e->paraVista())->all();
    }

    private function logos(): Collection
    {
        if (!Schema::hasTable('plantillas_editor')) return collect();
        return PlantillaEditor::whereNotNull('logo_path')->where('activa', true)->orderBy('orden')->get()
            ->map(fn($p) => ['id' => $p->id, 'nombre' => (string) $p->nombre, 'url' => $p->logoUrl()])->values();
    }

    private function marcos(): Collection
    {
        if (!Schema::hasTable('recursos_en_vivo')) return collect();
        return RecursoEnVivo::where('activo', true)->where('uso', 'plantilla')->orderBy('orden')->get()
            ->map(fn($r) => ['id' => $r->id, 'nombre' => (string) $r->nombre, 'url' => $r->url()])->values();
    }

    private function maxBytes(): int
    {
        return (int) config('services.emisor.max_gb', 6) * 1024 ** 3;
    }

    /** Páginas a las que puede transmitir (las del plan; el administrador, todas las conectadas). */
    private function paginasPermitidas(): Collection
    {
        $user = Auth::user();
        $delPlan = $this->planes->paginasPermitidas($user);
        if ($delPlan !== null) return $delPlan->sortBy('name')->values();
        return MetaPage::query()->whereHas('vinculos', function ($q) use ($user) {
            $q->where('is_active', 1)->whereNotNull('page_access_token');
            if (!$user->isAdmin()) $q->where('user_id', $user->id);
        })->orderBy('name')->get();
    }

    private function propia(EmisionProgramada $e): void
    {
        $user = Auth::user();
        abort_unless($user->isAdmin() || (int) $e->user_id === (int) $user->id, 403);
    }

    private function error(string $msg, int $codigo = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $msg], $codigo);
    }
}
