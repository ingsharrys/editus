<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campana;
use App\Models\InformeCampana;
use App\Models\MetaPage;
use App\Models\PublicacionRed;
use App\Models\Tema;
use App\Services\Inteligencia\AnalisisService;
use App\Services\Inteligencia\ClasificadorService;
use App\Services\Inteligencia\ClaudeService;
use App\Services\Inteligencia\ComentariosService;
use App\Services\Inteligencia\InformeService;
use App\Services\Inteligencia\RecolectorAudienciaService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inteligencia de audiencia: campañas con sus páginas y temas, tablero de
 * análisis (temas, formatos, público, horarios, comentarios, tendencias y
 * pronósticos) e informes redactados por la IA. Todo agregado por segmento.
 */
class InteligenciaController extends Controller
{
    public function index(ClaudeService $ia): View
    {
        $campanas = Campana::with('paginas')->withCount('temas')->orderByDesc('activa')->orderBy('nombre')->get();
        $paginas = MetaPage::orderBy('name')->get();
        $datos = PublicacionRed::count();
        return view('admin.inteligencia.index', ['campanas' => $campanas, 'paginas' => $paginas, 'iaLista' => $ia->configurado(), 'totalPublicaciones' => $datos]);
    }

    /**
     * Vista general: el mismo tablero de las campañas pero sobre todas las páginas
     * integradas (o las filtradas por medio / página), más la comparativa de cada
     * campaña activa contra el total de la organización.
     */
    public function general(Request $request, AnalisisService $analisis, ClaudeService $ia): View
    {
        [$desde, $hasta] = $this->rangoFechas($request);
        $universo = $this->paginasConDatos();
        $medios = (array) config('services.editus.medios', []);

        $medio = trim((string) $request->query('medio', ''));
        $idsFiltro = array_values(array_filter(array_map('intval', (array) $request->query('paginas', []))));
        $paginas = $universo
            ->when($medio !== '', fn($c) => $c->where('medio_slug', $medio))
            ->when($idsFiltro, fn($c) => $c->whereIn('id', $idsFiltro))
            ->values();

        $temas = Tema::with('campana')->orderBy('nombre')->get();
        $tablero = $analisis->tableroPaginas($paginas, $temas, $desde, $hasta);

        // Comparativa: cada campaña activa frente al total de la organización
        $total = $tablero['resumen'];
        $comparativa = Campana::where('activa', true)->with('paginas')->orderBy('nombre')->get()->map(function (Campana $c) use ($analisis, $desde, $hasta, $total) {
            $r = $analisis->resumenPaginas($c->paginas, $desde, $hasta);
            return [
                'id' => $c->id, 'nombre' => $c->nombre, 'paginas' => $c->paginas->count(), 'resumen' => $r,
                'participacion' => $total['alcance'] > 0 ? round(100 * $r['alcance'] / $total['alcance'], 1) : null,
                'diferencia_tasa' => ($r['tasa'] !== null && $total['tasa'] !== null) ? round($r['tasa'] - $total['tasa'], 2) : null,
            ];
        })->values()->all();

        $publicaciones = PublicacionRed::with('tema', 'page')->whereIn('meta_page_id', $paginas->pluck('id'))
            ->whereBetween('publicado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->orderByDesc('publicado_en')->paginate(25, ['*'], 'pubs')->withQueryString();
        $ultimaRecoleccion = \App\Models\AudienciaDiaria::whereIn('meta_page_id', $paginas->pluck('id'))->max('updated_at');
        $sinDatos = $paginas->filter(fn($p) => !$p->getAttribute('con_datos'))->count();

        return view('admin.inteligencia.general', [
            'tablero' => $tablero, 'desde' => $desde, 'hasta' => $hasta, 'publicaciones' => $publicaciones, 'comparativa' => $comparativa,
            'universo' => $universo, 'paginas' => $paginas, 'medios' => $medios, 'medio' => $medio, 'idsFiltro' => $idsFiltro,
            'iaLista' => $ia->configurado(), 'ultimaRecoleccion' => $ultimaRecoleccion, 'sinDatos' => $sinDatos,
            'tab' => in_array($request->query('tab'), ['resumen', 'paginas', 'audiencia', 'horarios', 'publicaciones'], true) ? $request->query('tab') : 'resumen',
        ]);
    }

    /**
     * Recolección desde la web, página por página: el navegador llama a "paso" en
     * bucle y muestra el avance. Así no se agota el tiempo de ejecución del hosting
     * aunque sean cientos de páginas.
     */
    public function recolectarIniciar(Request $request): \Illuminate\Http\JsonResponse
    {
        $dias = max(1, min(30, (int) $request->input('dias', 7)));
        $medio = trim((string) $request->input('medio', ''));
        $idsFiltro = array_values(array_filter(array_map('intval', (array) $request->input('paginas', []))));
        $paginas = $this->paginasConDatos()
            ->when($medio !== '', fn($c) => $c->where('medio_slug', $medio))
            ->when($idsFiltro, fn($c) => $c->whereIn('id', $idsFiltro))
            ->filter(fn($p) => $p->vinculos->isNotEmpty())
            ->values();
        $estado = ['ids' => $paginas->pluck('id')->all(), 'dias' => $dias, 'hecho' => 0, 'total' => $paginas->count(), 'errores' => 0, 'lineas' => []];
        \Illuminate\Support\Facades\Cache::put($this->claveRecoleccion(), $estado, now()->addHours(2));
        return response()->json(['success' => true, 'total' => $estado['total'], 'dias' => $dias]);
    }

    public function recolectarPaso(RecolectorAudienciaService $recolector): \Illuminate\Http\JsonResponse
    {
        @set_time_limit(170);
        $clave = $this->claveRecoleccion();
        $estado = \Illuminate\Support\Facades\Cache::get($clave);
        if (!$estado) return response()->json(['success' => false, 'error' => 'No hay una recolección iniciada (o venció). Vuelve a pulsar "Recolectar ahora".'], 422);
        if ($estado['hecho'] >= $estado['total']) {
            \Illuminate\Support\Facades\Cache::forget($clave);
            return response()->json(['success' => true, 'terminado' => true, 'hecho' => $estado['hecho'], 'total' => $estado['total'], 'errores' => $estado['errores']]);
        }
        $id = $estado['ids'][$estado['hecho']];
        $p = MetaPage::find($id);
        $linea = ['pagina' => $p?->name ?? "#{$id}", 'ok' => false, 'detalle' => 'La página ya no existe', 'avisos' => []];
        if ($p) {
            try {
                $r = $recolector->recolectar($p, (int) $estado['dias']);
                $linea = [
                    'pagina' => $p->name, 'ok' => !$r['errores'],
                    'detalle' => sprintf('%s publicaciones · %s con métricas · FB %s días · IG %s días', $r['publicaciones'], $r['metricas'], $r['facebook'] ?? '—', $r['instagram'] ?? '—') . ($r['errores'] ? ' · ' . implode(' | ', $r['errores']) : ''),
                    'avisos' => $r['avisos'] ?? [],
                ];
            } catch (\Throwable $e) {
                $linea = ['pagina' => $p->name, 'ok' => false, 'detalle' => $e->getMessage(), 'avisos' => []];
            }
        }
        $estado['hecho']++;
        if (!$linea['ok']) $estado['errores']++;
        $estado['lineas'][] = $linea;
        $terminado = $estado['hecho'] >= $estado['total'];
        if ($terminado) \Illuminate\Support\Facades\Cache::forget($clave); else \Illuminate\Support\Facades\Cache::put($clave, $estado, now()->addHours(2));
        return response()->json(['success' => true, 'terminado' => $terminado, 'hecho' => $estado['hecho'], 'total' => $estado['total'], 'errores' => $estado['errores'], 'linea' => $linea]);
    }

    private function claveRecoleccion(): string
    {
        return 'inteligencia.recoleccion.' . auth()->id();
    }

    /** Páginas que pueden tener datos: con token activo (se recolectan) o que ya tengan histórico. */
    private function paginasConDatos(): \Illuminate\Support\Collection
    {
        $conDatos = PublicacionRed::query()->distinct()->pluck('meta_page_id')
            ->merge(\App\Models\AudienciaDiaria::query()->distinct()->pluck('meta_page_id'))->unique()->flip();
        return MetaPage::with(['vinculos' => fn($q) => $q->where('is_active', 1)->whereNotNull('page_access_token')])->orderBy('name')->get()
            ->filter(fn(MetaPage $p) => $p->vinculos->isNotEmpty() || $conDatos->has($p->id))
            ->each(fn(MetaPage $p) => $p->setAttribute('con_datos', $conDatos->has($p->id)))
            ->values();
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validarCampana($request);
        $campana = Campana::create($d['campana']);
        $campana->paginas()->sync($d['paginas']);
        foreach ($d['temas'] as $i => $nombre) {
            Tema::create(['campana_id' => $campana->id, 'nombre' => $nombre, 'orden' => $i, 'color' => self::COLORES[$i % count(self::COLORES)]]);
        }
        return redirect()->route('inteligencia.show', $campana)->with('success', 'Campaña creada. Recolecta datos para empezar.');
    }

    public function update(Request $request, Campana $campana): RedirectResponse
    {
        $d = $this->validarCampana($request);
        $campana->update($d['campana']);
        $campana->paginas()->sync($d['paginas']);
        return redirect()->route('inteligencia.show', $campana)->with('success', 'Campaña actualizada.');
    }

    public function destroy(Campana $campana): RedirectResponse
    {
        $campana->delete();
        return redirect()->route('inteligencia.index')->with('success', 'Campaña eliminada.');
    }

    public function show(Request $request, Campana $campana, AnalisisService $analisis, ClaudeService $ia): View
    {
        [$desde, $hasta] = $this->rango($request, $campana);
        $tablero = $analisis->tablero($campana, $desde, $hasta);
        $campana->load('paginas', 'temas');
        $publicaciones = PublicacionRed::with('tema', 'page')->whereIn('meta_page_id', $campana->paginas->pluck('id'))
            ->whereBetween('publicado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->orderByDesc('publicado_en')->paginate(25, ['*'], 'pubs')->withQueryString();
        $informes = $campana->informes()->get(['id', 'desde', 'hasta', 'created_at']);
        $ultimaRecoleccion = \App\Models\AudienciaDiaria::whereIn('meta_page_id', $campana->paginas->pluck('id'))->max('updated_at');
        return view('admin.inteligencia.show', [
            'campana' => $campana, 'tablero' => $tablero, 'desde' => $desde, 'hasta' => $hasta, 'publicaciones' => $publicaciones,
            'informes' => $informes, 'iaLista' => $ia->configurado(), 'ultimaRecoleccion' => $ultimaRecoleccion,
            'paginasTodas' => MetaPage::orderBy('name')->get(), 'tab' => $request->query('tab', 'resumen'),
        ]);
    }

    // ----------------------------------------------------------------- temas

    public function temaStore(Request $request, Campana $campana): RedirectResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:80'], 'descripcion' => ['nullable', 'string', 'max:500'], 'palabras_clave' => ['nullable', 'string', 'max:500'], 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]);
        $n = $campana->temas()->count();
        Tema::create([
            'campana_id' => $campana->id, 'nombre' => $d['nombre'], 'descripcion' => $d['descripcion'] ?? null,
            'palabras_clave' => self::listaPalabras($d['palabras_clave'] ?? ''), 'color' => $d['color'] ?? self::COLORES[$n % count(self::COLORES)], 'orden' => $n,
        ]);
        return back()->with('success', 'Tema agregado.')->withFragment('temas');
    }

    public function temaUpdate(Request $request, Campana $campana, Tema $tema): RedirectResponse
    {
        abort_unless($tema->campana_id === $campana->id, 404);
        $d = $request->validate(['nombre' => ['required', 'string', 'max:80'], 'descripcion' => ['nullable', 'string', 'max:500'], 'palabras_clave' => ['nullable', 'string', 'max:500'], 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]);
        $tema->update(['nombre' => $d['nombre'], 'descripcion' => $d['descripcion'] ?? null, 'palabras_clave' => self::listaPalabras($d['palabras_clave'] ?? ''), 'color' => $d['color'] ?? $tema->color]);
        return back()->with('success', 'Tema actualizado.')->withFragment('temas');
    }

    public function temaDestroy(Campana $campana, Tema $tema): RedirectResponse
    {
        abort_unless($tema->campana_id === $campana->id, 404);
        $tema->delete();
        return back()->with('success', 'Tema eliminado. Sus publicaciones quedaron sin tema.')->withFragment('temas');
    }

    /** Corrección manual del tema de una publicación. */
    public function publicacionTema(Request $request, Campana $campana, PublicacionRed $publicacion): RedirectResponse
    {
        $d = $request->validate(['tema_id' => ['nullable', 'integer']]);
        $temaId = $d['tema_id'] ? (int) $d['tema_id'] : null;
        if ($temaId && !$campana->temas()->whereKey($temaId)->exists()) abort(422, 'Tema no válido');
        $publicacion->fill(['tema_id' => $temaId, 'tema_fuente' => 'manual', 'tema_confianza' => 100])->save();
        return back()->with('success', 'Tema corregido.');
    }

    // --------------------------------------------------------------- acciones

    /** Recolecta ahora (últimos días) las páginas de la campaña; útil al crearla. */
    public function recolectar(Request $request, Campana $campana, RecolectorAudienciaService $recolector): RedirectResponse
    {
        @set_time_limit(280);
        $dias = max(1, min(30, (int) $request->input('dias', 7)));
        $lineas = [];
        foreach ($campana->paginas as $p) {
            $r = $recolector->recolectar($p, $dias);
            $lineas[] = "{$p->name}: {$r['publicaciones']} publicaciones, {$r['metricas']} con métricas" . ($r['errores'] ? ' · ' . implode(' | ', $r['errores']) : '');
        }
        return back()->with('success', 'Recolección terminada. ' . implode(' — ', $lineas));
    }

    public function analizar(Campana $campana, ClasificadorService $clasificador, ComentariosService $comentarios, ClaudeService $ia): RedirectResponse
    {
        if (!$ia->configurado()) return back()->with('error', 'Falta ANTHROPIC_API_KEY en el .env de editus.');
        @set_time_limit(280);
        try {
            $n = $clasificador->clasificarCampana($campana, 100);
            $m = $comentarios->analizarCampana($campana, 15);
        } catch (\Throwable $e) {
            return back()->with('error', 'La IA falló: ' . $e->getMessage());
        }
        return back()->with('success', "{$n} publicaciones clasificadas y {$m} lecturas de comentarios.");
    }

    public function informeGenerar(Request $request, Campana $campana, InformeService $informes, ClaudeService $ia): RedirectResponse
    {
        if (!$ia->configurado()) return back()->with('error', 'Falta ANTHROPIC_API_KEY en el .env de editus.');
        @set_time_limit(280);
        [$desde, $hasta] = $this->rango($request, $campana);
        try {
            $i = $informes->generar($campana, $desde, $hasta);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo redactar el informe: ' . $e->getMessage());
        }
        return redirect()->route('inteligencia.informe', [$campana, $i])->with('success', 'Informe listo.');
    }

    public function informe(Campana $campana, InformeCampana $informe): View
    {
        abort_unless($informe->campana_id === $campana->id, 404);
        return view('admin.inteligencia.informe', ['campana' => $campana, 'informe' => $informe]);
    }

    /** Pronóstico: alcance esperado de un tema en una página (JSON para el tablero). */
    public function proyeccion(Request $request, Campana $campana, AnalisisService $analisis)
    {
        $d = $request->validate(['tema_id' => ['required', 'integer'], 'meta_page_id' => ['required', 'integer'], 'tipo' => ['nullable', 'string', 'max:20']]);
        $tema = $campana->temas()->findOrFail((int) $d['tema_id']);
        abort_unless($campana->paginas()->whereKey((int) $d['meta_page_id'])->exists(), 404);
        return response()->json($analisis->proyeccion($tema, (int) $d['meta_page_id'], $d['tipo'] ?? null));
    }

    // ------------------------------------------------------------------ ayudas

    public const COLORES = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569'];

    private function validarCampana(Request $request): array
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:120'], 'descripcion' => ['nullable', 'string', 'max:2000'], 'territorio' => ['nullable', 'string', 'max:120'],
            'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde'], 'activa' => ['nullable'],
            'paginas' => ['nullable', 'array'], 'paginas.*' => ['integer'], 'temas' => ['nullable', 'string', 'max:2000'],
        ]);
        $temas = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\n,]+/', (string) ($d['temas'] ?? ''))))));
        return [
            'campana' => ['nombre' => $d['nombre'], 'descripcion' => $d['descripcion'] ?? null, 'territorio' => $d['territorio'] ?? null, 'desde' => $d['desde'] ?? null, 'hasta' => $d['hasta'] ?? null, 'activa' => $request->boolean('activa', true)],
            'paginas' => array_map('intval', $d['paginas'] ?? []),
            'temas' => array_slice($temas, 0, 30),
        ];
    }

    private function rango(Request $request, Campana $campana): array
    {
        return $this->rangoFechas($request);
    }

    private function rangoFechas(Request $request): array
    {
        $hasta = $request->filled('hasta') ? Carbon::parse($request->query('hasta')) : Carbon::today();
        $desde = $request->filled('desde') ? Carbon::parse($request->query('desde')) : $hasta->copy()->subDays(29);
        if ($desde->gt($hasta)) [$desde, $hasta] = [$hasta, $desde];
        if ($desde->diffInDays($hasta) > 366) $desde = $hasta->copy()->subDays(366);
        return [$desde->startOfDay(), $hasta->startOfDay()];
    }

    private static function listaPalabras(string $s): array
    {
        return array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), preg_split('/[\n,;]+/', $s)))));
    }
}
