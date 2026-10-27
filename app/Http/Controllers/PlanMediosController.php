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

    public function subcampanas(Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $subs = $campaign->subcampanas()->withCount(\App\Services\ProductosService::disponible() ? ['posts', 'productos'] : ['posts'])->get();
        return view('campaigns.plan.subcampanas', ['c' => $campaign, 'subs' => $subs]);
    }

    public function guardarSubcampanas(Request $request, Campaign $campaign)
    {
        $this->exigirPlan($campaign);
        $d = $request->validate([
            'subs' => ['nullable', 'array', 'max:200'],
            'subs.*.id' => ['nullable', 'integer'],
            'subs.*.nombre' => ['nullable', 'string', 'max:120'],
            'subs.*.programa' => ['nullable', 'string', 'max:190'],
            'subs.*.subprograma' => ['nullable', 'string', 'max:190'],
            'subs.*.descripcion' => ['nullable', 'string', 'max:500'],
            'subs.*.is_active' => ['nullable', 'boolean'],
            'subs.*.etiqueta' => ['nullable', 'string', 'max:80'],
            'subs.*.clase' => ['nullable', 'string', 'max:20'],
            'subs.*.tipo' => ['nullable', 'string', 'max:40'],
            'subs.*.formato' => ['nullable', 'string', 'max:80'],
            'subs.*.unidad' => ['nullable', 'string', 'max:120'],
            'subs.*.plantilla' => ['nullable', 'string', 'max:500'],
            'subs.*.titulo_informe' => ['nullable', 'string', 'max:120'],
            'subs.*.borrar' => ['nullable', 'boolean'],
        ]);
        $this->plan->guardarSubcampanas($campaign, array_values($d['subs'] ?? []));
        if (\App\Services\ProductosService::disponible()) {
            // El tipo de las piezas puede haber cambiado de nombre: se actualizan sus publicaciones
            $prod = app(\App\Services\ProductosService::class);
            foreach ($campaign->productos()->whereNotNull('subcampana_id')->get() as $p) $prod->aplicarAPosts($p);
        }
        return redirect()->route('campaigns.plan.subcampanas', $campaign)->with('success', __('Subcampañas guardadas.'));
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
