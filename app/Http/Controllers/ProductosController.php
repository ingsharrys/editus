<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Producto;
use App\Models\ProductoPublicacion;
use App\Services\CampanasService;
use App\Services\InformeMetricasService;
use App\Services\PlanMediosService;
use App\Services\ProductosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Control de productos del plan de medios: piezas, programas, publicaciones de cada pieza e importación del Excel. */
class ProductosController extends Controller
{
    public function __construct(private ProductosService $prod)
    {
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->tienePermiso('campanas'), 403);
            return $next($request);
        });
    }

    // ───────────────────────── Piezas ─────────────────────────

    public function index(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $q = Producto::with('subcampana', 'programa')->withCount(['posts' => fn($x) => $x->where('status', 'success')])->withCount('publicaciones')
            ->where('campaign_id', $campaign->id)
            ->when($request->query('sub'), fn($x, $v) => $x->where('subcampana_id', (int) $v))
            ->when($request->query('programa'), fn($x, $v) => $x->where('programa_id', (int) $v))
            ->when($request->query('estado'), fn($x, $v) => $x->where('estado', $v))
            ->when(trim((string) $request->query('q')), fn($x, $v) => $x->where('nombre', 'like', '%' . $v . '%'))
            ->orderBy('subcampana_id')->orderByDesc('numero');
        return view('campaigns.plan.productos', [
            'c' => $campaign, 'productos' => $q->paginate(50)->withQueryString(), 'resumen' => $this->prod->resumen($campaign),
            'subs' => $campaign->subcampanas()->get(), 'programas' => $campaign->programas()->get(), 'estados' => Producto::ESTADOS,
        ]);
    }

    public function crear(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $p = new Producto(['campaign_id' => $campaign->id, 'estado' => 'Pendiente', 'subcampana_id' => $request->integer('sub') ?: null]);
        return $this->formulario($campaign, $p);
    }

    public function guardar(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $p = $this->prod->guardar($campaign, $this->validar($request, $campaign), null, $request->file('muestra'));
        return redirect()->route('campaigns.productos.ver', [$campaign, $p])->with('success', __('Pieza «:nombre» creada.', ['nombre' => $p->nombre]));
    }

    public function ver(Campaign $campaign, Producto $producto)
    {
        $this->exigir($campaign, $producto);
        return $this->formulario($campaign, $producto->load('subcampana', 'programa'));
    }

    public function actualizar(Request $request, Campaign $campaign, Producto $producto)
    {
        $this->exigir($campaign, $producto);
        $this->prod->guardar($campaign, $this->validar($request, $campaign), $producto, $request->file('muestra'));
        return redirect()->route('campaigns.productos.ver', [$campaign, $producto])->with('success', __('Pieza guardada.'));
    }

    public function borrar(Campaign $campaign, Producto $producto)
    {
        $this->exigir($campaign, $producto);
        $this->prod->borrar($producto);
        return redirect()->route('campaigns.productos', $campaign)->with('success', __('Pieza borrada. Sus publicaciones quedan sin pieza.'));
    }

    private function formulario(Campaign $c, Producto $p)
    {
        $pubs = $p->exists ? $this->prod->publicaciones($p) : collect();
        $sinPieza = $p->exists ? MetaPost::with('page:id,name')->where('campaign_id', $c->id)->whereNull('producto_id')->where('status', 'success')
            ->orderByDesc('published_at')->limit(200)->get() : collect();
        return view('campaigns.plan.producto', [
            'c' => $c, 'p' => $p, 'pubs' => $pubs, 'tot' => $this->prod->totales($pubs), 'sinPieza' => $sinPieza,
            'subs' => $c->subcampanas()->get(), 'programas' => $c->programas()->get(), 'estados' => Producto::ESTADOS,
            'paginas' => app(CampanasService::class)->paginasDe($c)->sortBy('name')->values(),
            'fechaPub' => $p->exists ? $this->prod->fechaPublicacion($p, $pubs) : null,
            'descripcion' => $p->exists ? $this->prod->descripcion($p, $this->prod->totales($pubs)['medios']) : null,
        ]);
    }

    private function validar(Request $request, Campaign $c): array
    {
        return $request->validate([
            'subcampana_id' => ['required', 'integer', Rule::exists('campaign_subcampanas', 'id')->where('campaign_id', $c->id)],
            'programa_id' => ['nullable', 'integer', Rule::exists('campaign_programas', 'id')->where('campaign_id', $c->id)],
            'subprograma' => ['nullable', 'string', 'max:190'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'fecha_solicitud' => ['nullable', 'date'],
            'fecha_entrega' => ['nullable', 'date'],
            'fecha_publicacion' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'estado' => ['required', Rule::in(Producto::ESTADOS)],
            'acta_aprobacion' => ['nullable', 'string', 'max:60'],
            'acta_publicacion' => ['nullable', 'string', 'max:60'],
            'link_archivo' => ['nullable', 'url', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'muestra' => ['nullable', 'image', 'max:8192'],
        ], [
            'subcampana_id.required' => __('Elige el tipo de publicación (subcampaña) de la pieza.'),
            'link_archivo.url' => __('El link debe ser una dirección completa (https://…).'),
        ]);
    }

    // ───────────────────────── Publicaciones de una pieza ─────────────────────────

    /** Registra una publicación hecha fuera de editus, o la captura / métricas corregidas de una de editus (meta_post_id). */
    public function guardarPublicacion(Request $request, Campaign $campaign, Producto $producto, ?ProductoPublicacion $publicacion = null)
    {
        $this->exigir($campaign, $producto);
        if ($publicacion) abort_unless((int) $publicacion->producto_id === (int) $producto->id, 404);
        $d = $request->validate([
            'meta_post_id' => ['nullable', 'integer'],
            'meta_page_id' => ['nullable', 'integer'],
            'medio' => ['nullable', 'string', 'max:190'],
            'url' => ['nullable', 'url', 'max:1000'],
            'fecha' => ['nullable', 'date'],
            'alcance' => ['nullable', 'integer', 'min:0'],
            'visualizaciones' => ['nullable', 'integer', 'min:0'],
            'interacciones' => ['nullable', 'integer', 'min:0'],
            'evidencia' => ['nullable', 'image', 'max:8192'],
            'quitar_evidencia' => ['nullable', 'boolean'],
        ], ['url.url' => __('La URL debe ser una dirección completa (https://…).')]);

        $postId = (int) ($d['meta_post_id'] ?? 0);
        if ($postId) {
            abort_unless(MetaPost::whereKey($postId)->where('producto_id', $producto->id)->exists(), 404);
            $publicacion ??= ProductoPublicacion::firstOrNew(['producto_id' => $producto->id, 'meta_post_id' => $postId]);
        } else {
            $publicacion ??= new ProductoPublicacion(['producto_id' => $producto->id, 'orden' => (int) $producto->publicaciones()->max('orden') + 1]);
            $pagina = !empty($d['meta_page_id']) ? MetaPage::find((int) $d['meta_page_id']) : null;
            $medio = trim((string) ($d['medio'] ?? '')) ?: $pagina?->name;
            if (!$medio) return back()->withErrors(['medio' => __('Escribe el medio o la página web.')])->withInput();
            $publicacion->fill(['meta_page_id' => $pagina?->id, 'medio' => $medio, 'fecha' => ($d['fecha'] ?? null) ?: null]);
        }
        $num = fn($k) => isset($d[$k]) && $d[$k] !== '' ? (int) $d[$k] : null;
        $publicacion->fill(['url' => ($d['url'] ?? null) ?: null, 'alcance' => $num('alcance'), 'visualizaciones' => $num('visualizaciones'), 'interacciones' => $num('interacciones')]);
        $disco = Storage::disk(ProductosService::DISCO);
        if (!empty($d['quitar_evidencia']) && $publicacion->evidencia_path) {
            $disco->delete($publicacion->evidencia_path);
            $publicacion->evidencia_path = null;
        }
        if ($request->hasFile('evidencia')) {
            if ($publicacion->evidencia_path) $disco->delete($publicacion->evidencia_path);
            $publicacion->evidencia_path = $request->file('evidencia')->storeAs('campanas/' . $campaign->id . '/evidencias',
                'evidencia-' . $producto->id . '-' . Str::random(10) . '.' . ($request->file('evidencia')->extension() ?: 'jpg'), ProductosService::DISCO);
        }
        $publicacion->save();
        return redirect()->to(route('campaigns.productos.ver', [$campaign, $producto]) . '#publicaciones')->with('success', __('Publicación guardada.'));
    }

    public function borrarPublicacion(Campaign $campaign, Producto $producto, ProductoPublicacion $publicacion)
    {
        $this->exigir($campaign, $producto);
        abort_unless((int) $publicacion->producto_id === (int) $producto->id, 404);
        if ($publicacion->evidencia_path) Storage::disk(ProductosService::DISCO)->delete($publicacion->evidencia_path);
        $publicacion->delete();
        return redirect()->to(route('campaigns.productos.ver', [$campaign, $producto]) . '#publicaciones')->with('success', __('Publicación quitada.'));
    }

    /** Asigna a la pieza publicaciones de editus de la campaña que aún no tienen pieza (o se la quita a una). */
    public function vincular(Request $request, Campaign $campaign, Producto $producto)
    {
        $this->exigir($campaign, $producto);
        $d = $request->validate(['posts' => ['nullable', 'array'], 'posts.*' => ['integer'], 'quitar' => ['nullable', 'integer']]);
        if (!empty($d['quitar'])) {
            MetaPost::where('campaign_id', $campaign->id)->where('producto_id', $producto->id)->whereKey((int) $d['quitar'])->update(['producto_id' => null]);
            ProductoPublicacion::where('producto_id', $producto->id)->where('meta_post_id', (int) $d['quitar'])->delete();
            return redirect()->to(route('campaigns.productos.ver', [$campaign, $producto]) . '#publicaciones')->with('success', __('Publicación desvinculada de la pieza.'));
        }
        $ids = MetaPost::where('campaign_id', $campaign->id)->whereNull('producto_id')->whereIn('id', array_map('intval', $d['posts'] ?? []))->pluck('id');
        MetaPost::whereIn('id', $ids)->update(['producto_id' => $producto->id]);
        $this->prod->aplicarAPosts($producto);
        return redirect()->to(route('campaigns.productos.ver', [$campaign, $producto]) . '#publicaciones')
            ->with('success', __(':n publicación(es) vinculada(s) a la pieza.', ['n' => $ids->count()]));
    }

    // ───────────────────────── Programas ─────────────────────────

    public function programas(Campaign $campaign)
    {
        $this->exigir($campaign);
        return view('campaigns.plan.programas', ['c' => $campaign, 'programas' => $campaign->programas()->withCount('productos')->get()]);
    }

    public function guardarProgramas(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $d = $request->validate([
            'programas' => ['nullable', 'array', 'max:200'],
            'programas.*.id' => ['nullable', 'integer'],
            'programas.*.codigo' => ['nullable', 'string', 'max:20'],
            'programas.*.nombre' => ['nullable', 'string', 'max:190'],
            'programas.*.subprogramas' => ['nullable', 'string', 'max:3000'],
            'programas.*.borrar' => ['nullable', 'boolean'],
        ]);
        $this->prod->guardarProgramas($campaign, array_values($d['programas'] ?? []));
        return redirect()->route('campaigns.plan.programas', $campaign)->with('success', __('Programas guardados.'));
    }

    // ───────────────────────── Importar ─────────────────────────

    public function importar(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx,xlsm', 'max:51200']], [
            'archivo.required' => __('Elige el archivo de Excel.'), 'archivo.mimes' => __('El archivo debe ser un Excel (.xlsx).'),
        ]);
        try {
            $r = app(InformeMetricasService::class)->importarExcel($campaign, $request->file('archivo')->getRealPath());
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['archivo' => __('No se pudo leer el Excel: :error', ['error' => Str::limit($e->getMessage(), 160)])]);
        }
        app(CampanasService::class)->sincronizarPerfil($campaign);
        return redirect()->route('campaigns.productos', $campaign)->with('success', __('Importación lista: :p pieza(s) nuevas, :t tipo(s) de publicación y :g programa(s) creados, :m muestra(s). :r ya existían.', [
            'p' => $r['piezas'], 't' => $r['tipos'], 'g' => $r['programas'], 'm' => $r['muestras'], 'r' => $r['repetidas'],
        ]));
    }

    // ───────────────────────── Archivos (muestras, capturas, membrete) ─────────────────────────

    public function archivo(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $ruta = (string) base64_decode((string) $request->query('f'), true);
        abort_unless($ruta !== '' && str_starts_with($ruta, 'campanas/' . $campaign->id . '/') && !str_contains($ruta, '..'), 404);
        $disco = Storage::disk(ProductosService::DISCO);
        abort_unless($disco->exists($ruta), 404);
        return $disco->response($ruta, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    private function exigir(Campaign $c, ?Producto $p = null): void
    {
        abort_if($c->is_system, 403);
        abort_unless(ProductosService::disponible(), 503, __('Falta ejecutar las migraciones (php artisan migrate).'));
        abort_unless($c->plan_medios, 404, __('Esta campaña no es un plan de medios. Actívalo en Editar campaña.'));
        if ($p) abort_unless((int) $p->campaign_id === (int) $c->id, 404);
    }
}
