<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Producto;
use App\Models\ProductoPublicacion;
use App\Models\Subcampana;
use App\Services\PlanMediosService;
use App\Services\ProductosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Una subcampaña del plan de medios (p. ej. «Dengue»): su avance por tipo de publicación, sus piezas publicadas,
 * publicar dentro de ella y registrar banners en páginas web con su pantallazo.
 */
class SubcampanaController extends Controller
{
    public function __construct(private ProductosService $prod)
    {
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->tienePermiso('campanas'), 403);
            return $next($request);
        });
    }

    public function ver(Campaign $campaign, Subcampana $subcampana)
    {
        $this->exigir($campaign, $subcampana);
        $this->prod->asegurarPiezas($campaign);
        $subcampana->load('tipos', 'programaContrato');
        $piezas = Producto::with('tipo')->where('campaign_id', $campaign->id)->where('subcampana_id', $subcampana->id)
            ->orderByDesc('fecha_publicacion')->orderByDesc('id')->get()
            ->map(function (Producto $p) {
                $pubs = $this->prod->publicaciones($p);
                return ['pieza' => $p, 'tot' => $this->prod->totales($pubs), 'fecha' => $this->prod->fechaPublicacion($p, $pubs),
                    'sinCaptura' => $pubs->filter(fn($x) => !$x['evidencia'])->count()];
            });
        $matriz = $this->prod->matrizSubcampanas($campaign)[$subcampana->id] ?? [];
        $m = app(PlanMediosService::class)->metricas($campaign, $subcampana->id);
        return view('campaigns.plan.subcampana', [
            'c' => $campaign, 's' => $subcampana, 'piezas' => $piezas, 'matriz' => $matriz, 'm' => $m,
            'tipos' => $campaign->tiposPublicacion()->get(),
        ]);
    }

    /** Publicar dentro de la subcampaña: la pantalla de publicar con la campaña y la subcampaña fijas. */
    public function publicar(Request $request, Campaign $campaign, Subcampana $subcampana)
    {
        $this->exigir($campaign, $subcampana);
        abort_unless($request->user()->tienePermiso('paginas.publicar'), 403);
        abort_unless($campaign->is_active && $subcampana->is_active, 404, __('La campaña o la subcampaña está inactiva.'));
        $request->attributes->set('plan_fijo', ['campana' => $campaign, 'sub' => $subcampana]);
        return app(\App\Http\Controllers\Meta\FacebookPageController::class)->index($request);
    }

    /** Formulario para registrar un banner publicado en páginas web, con el pantallazo de cada página. */
    public function banner(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $tipos = $campaign->tiposPublicacion()->get();
        return view('campaigns.plan.banner', [
            'c' => $campaign, 'subs' => $campaign->subcampanas()->with('tipos')->get(), 'tipos' => $tipos,
            'subElegida' => $request->integer('subcampana') ?: null,
            'tipoElegido' => $tipos->firstWhere('clase', 'web')?->id,
        ]);
    }

    public function guardarBanner(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $d = $request->validate([
            'subcampana_id' => ['nullable', 'integer', Rule::exists('campaign_subcampanas', 'id')->where('campaign_id', $campaign->id)],
            'tipo_id' => ['required', 'integer', Rule::exists('campaign_tipos', 'id')->where('campaign_id', $campaign->id)],
            'nombre' => ['nullable', 'string', 'max:255'],
            'fecha_publicacion' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_publicacion'],
            'acta_aprobacion' => ['nullable', 'string', 'max:60'],
            'acta_publicacion' => ['nullable', 'string', 'max:60'],
            'muestra' => ['nullable', 'image', 'max:8192'],
            'paginas' => ['required', 'array', 'min:1', 'max:60'],
            'paginas.*.medio' => ['nullable', 'string', 'max:190'],
            'paginas.*.url' => ['nullable', 'url', 'max:1000'],
            'paginas.*.captura' => ['nullable', 'image', 'max:8192'],
        ], [
            'tipo_id.required' => __('Elige el tipo de publicación del banner.'),
            'fecha_publicacion.required' => __('Escribe desde cuándo está publicado el banner.'),
            'paginas.*.url.url' => __('La URL de cada página debe ser una dirección completa (https://…).'),
            'fecha_fin.after_or_equal' => __('La fecha final debe ser igual o posterior a la inicial.'),
        ]);
        $filas = collect($d['paginas'])->filter(fn($f) => trim((string) ($f['medio'] ?? '')) !== '')->values();
        if ($filas->isEmpty()) return back()->withInput()->withErrors(['paginas' => __('Agrega al menos una página web con su nombre.')]);

        $tipo = $campaign->tiposPublicacion()->find((int) $d['tipo_id']);
        $p = $this->prod->guardar($campaign, [
            'tipo_id' => $tipo->id, 'subcampana_id' => $d['subcampana_id'] ?? null,
            'nombre' => trim((string) ($d['nombre'] ?? '')) ?: $tipo->etiquetaSingular() . ' ' . ($filas->count() === 1 ? $filas[0]['medio'] : __('en :n páginas web', ['n' => $filas->count()])),
            'estado' => 'Publicado / Entregado', 'fecha_publicacion' => $d['fecha_publicacion'], 'fecha_fin' => $d['fecha_fin'] ?? null,
            'acta_aprobacion' => $d['acta_aprobacion'] ?? null, 'acta_publicacion' => $d['acta_publicacion'] ?? null,
        ], null, $request->file('muestra'));
        $i = 0;
        foreach ($d['paginas'] as $k => $f) {
            if (trim((string) ($f['medio'] ?? '')) === '') continue;
            $archivo = $request->file("paginas.{$k}.captura");
            $ruta = $archivo ? $archivo->storeAs('campanas/' . $campaign->id . '/evidencias', 'banner-' . $p->id . '-' . Str::random(10) . '.' . ($archivo->extension() ?: 'jpg'), ProductosService::DISCO) : null;
            ProductoPublicacion::create(['producto_id' => $p->id, 'medio' => trim($f['medio']), 'url' => ($f['url'] ?? null) ?: null,
                'fecha' => $d['fecha_publicacion'], 'evidencia_path' => $ruta, 'orden' => $i++]);
        }
        $volver = !empty($d['subcampana_id']) ? route('campaigns.subcampanas.ver', [$campaign, (int) $d['subcampana_id']]) : route('campaigns.plan', $campaign);
        return redirect()->to($volver)->with('success', __('Banner registrado en :n página(s) web.', ['n' => $filas->count()]));
    }

    private function exigir(Campaign $c, ?Subcampana $s = null): void
    {
        abort_if($c->is_system, 403);
        abort_unless(ProductosService::disponible() && PlanMediosService::conTipos(), 503, __('Falta ejecutar las migraciones (php artisan migrate).'));
        abort_unless($c->plan_medios, 404, __('Esta campaña no es un plan de medios. Actívalo en Editar campaña.'));
        if ($s) abort_unless((int) $s->campaign_id === (int) $c->id, 404);
    }
}
