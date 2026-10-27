<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\CampanasService;
use App\Services\PlanMediosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Plan de medios de una campaña: tablero de métricas, subcampañas y cupos de piezas por página. */
class PlanMediosController extends Controller
{
    public function __construct(private PlanMediosService $plan)
    {
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->tienePermiso('campanas'), 403);
            return $next($request);
        });
    }

    /** Tablero: métricas de toda la campaña y por subcampaña, y avance de los cupos. */
    public function tablero(Request $request, Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $sub = $request->query('sub');
        $subId = $sub === null || $sub === '' ? null : (int) $sub;
        if ($subId) abort_unless($campaign->subcampanas()->whereKey($subId)->exists(), 404);
        return view('campaigns.plan.tablero', [
            'c' => $campaign, 'm' => $this->plan->metricas($campaign, $subId), 'subId' => $subId,
            'avance' => $this->plan->avance($campaign, $subId ?: null), 'tipos' => $this->plan->tipos($campaign),
            'hayCupos' => (bool) $this->plan->cupos($campaign),
        ]);
    }

    /** Subcampañas = temas de la campaña (p. ej. «Dengue»), cada una con su programa y los tipos de publicación que lleva. */
    public function subcampanas(Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $conTipos = PlanMediosService::conTipos() && \App\Services\ProductosService::disponible();
        $subs = $campaign->subcampanas()->withCount($conTipos ? ['posts', 'productos'] : ['posts'])->with($conTipos ? ['tipos'] : [])->get();
        return view('campaigns.plan.subcampanas', [
            'c' => $campaign, 'subs' => $subs, 'conTipos' => $conTipos,
            'tipos' => $conTipos ? $campaign->tiposPublicacion()->get() : collect(),
            'programas' => $conTipos ? $campaign->programas()->get() : collect(),
            'avance' => $conTipos ? app(\App\Services\ProductosService::class)->avanceSubcampanas($campaign)->keyBy(fn($a) => $a['sub']->id) : collect(),
        ]);
    }

    public function guardarSubcampanas(Request $request, Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $d = $request->validate([
            'subs' => ['nullable', 'array', 'max:200'],
            'subs.*.id' => ['nullable', 'integer'],
            'subs.*.nombre' => ['nullable', 'string', 'max:120'],
            'subs.*.programa_id' => ['nullable', 'integer'],
            'subs.*.programa' => ['nullable', 'string', 'max:190'],
            'subs.*.subprograma' => ['nullable', 'string', 'max:190'],
            'subs.*.descripcion' => ['nullable', 'string', 'max:500'],
            'subs.*.is_active' => ['nullable', 'boolean'],
            'subs.*.tipos' => ['nullable', 'array'],
            'subs.*.tipos.*.on' => ['nullable', 'boolean'],
            'subs.*.tipos.*.cantidad' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'subs.*.borrar' => ['nullable', 'boolean'],
        ]);
        $this->plan->guardarSubcampanas($campaign, array_values($d['subs'] ?? []));
        return redirect()->route('campaigns.plan.subcampanas', $campaign)->with('success', __('Subcampañas guardadas.'));
    }

    /** Tipos de publicación de la campaña (Videos largos, Banner 1600x185, En vivos…). */
    public function tipos(Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        abort_unless(PlanMediosService::conTipos(), 503, __('Falta ejecutar las migraciones (php artisan migrate).'));
        $tipos = $campaign->tiposPublicacion()->withCount(\App\Services\ProductosService::disponible() ? ['productos'] : [])->get();
        return view('campaigns.plan.tipos', ['c' => $campaign, 'tipos' => $tipos]);
    }

    public function guardarTipos(Request $request, Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $d = $request->validate([
            'tipos' => ['nullable', 'array', 'max:200'],
            'tipos.*.id' => ['nullable', 'integer'],
            'tipos.*.nombre' => ['nullable', 'string', 'max:120'],
            'tipos.*.etiqueta' => ['nullable', 'string', 'max:80'],
            'tipos.*.clase' => ['nullable', 'string', 'max:20'],
            'tipos.*.tipo' => ['nullable', 'string', 'max:40'],
            'tipos.*.formato' => ['nullable', 'string', 'max:80'],
            'tipos.*.unidad' => ['nullable', 'string', 'max:120'],
            'tipos.*.plantilla' => ['nullable', 'string', 'max:500'],
            'tipos.*.titulo_informe' => ['nullable', 'string', 'max:120'],
            'tipos.*.is_active' => ['nullable', 'boolean'],
            'tipos.*.borrar' => ['nullable', 'boolean'],
        ]);
        $this->plan->guardarTipos($campaign, array_values($d['tipos'] ?? []));
        if (\App\Services\ProductosService::disponible()) {
            // El nombre singular de un tipo puede haber cambiado: se actualizan las publicaciones de sus piezas
            $prod = app(\App\Services\ProductosService::class);
            foreach ($campaign->productos()->whereNotNull('tipo_id')->get() as $p) $prod->aplicarAPosts($p);
        }
        return redirect()->route('campaigns.plan.tipos', $campaign)->with('success', __('Tipos de publicación guardados.'));
    }

    public function cupos(Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $paginas = app(CampanasService::class)->paginasDe($campaign)->sortBy('name')->values();
        return view('campaigns.plan.cupos', [
            'c' => $campaign, 'paginas' => $paginas, 'tipos' => $this->plan->tipos($campaign),
            'cupos' => $this->plan->cupos($campaign), 'usados' => $this->plan->usados($campaign),
        ]);
    }

    public function guardarCupos(Request $request, Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $d = $request->validate([
            'cupos' => ['nullable', 'array'],
            'cupos.*' => ['array'],
            'cupos.*.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['cupos.*.*.integer' => __('Los cupos deben ser números enteros.'), 'cupos.*.*.min' => __('Los cupos no pueden ser negativos.')]);
        $this->plan->guardarCupos($campaign, (array) ($d['cupos'] ?? []));
        return redirect()->route('campaigns.plan.cupos', $campaign)->with('success', __('Cupos guardados.'));
    }

    private function exigirPlan(Campaign $c): void
    {
        abort_if($c->is_system, 403);
        abort_unless(PlanMediosService::disponible(), 503, __('Falta ejecutar las migraciones (php artisan migrate).'));
        abort_unless($c->plan_medios, 404, __('Esta campaña no es un plan de medios. Actívalo en Editar campaña.'));
    }
}
