<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Services\CampanasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Campañas: el ÚNICO lugar donde se crean y editan. Cada campaña define en qué medios
 * se publica (sus páginas quedan preseleccionadas al publicar, con opción de excluir),
 * su tipo y el contexto que lee la IA. Su análisis vive en Inteligencia.
 */
class CampaignController extends Controller
{
    public function __construct(private CampanasService $campanas)
    {
        // Toda la gestión de campañas es de administradores
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->isAdmin(), 403);
            return $next($request);
        });
    }

    public function index()
    {
        $this->campanas->asegurarPerfiles();
        $campaigns = Campaign::query()
            ->with(['perfil' => fn($q) => $q->withCount('temas', 'informes')])
            ->withCount('posts')
            ->leftJoin(
                DB::raw('(SELECT campaign_id, COALESCE(SUM(alcance),0) reach, COALESCE(SUM(interacciones),0) inter
                          FROM meta_posts WHERE status = "success" GROUP BY campaign_id) m'),
                'm.campaign_id', '=', 'campaigns.id'
            )
            ->orderByDesc('campaigns.is_system')
            ->orderByDesc('campaigns.is_active')
            ->orderBy('campaigns.name')
            ->get(['campaigns.*', DB::raw('COALESCE(m.reach,0) as total_reach'), DB::raw('COALESCE(m.inter,0) as total_inter')]);
        $paginas = $this->campanas->mapaPaginas($campaigns);

        return view('campaigns.index', ['campaigns' => $campaigns, 'paginasPorCampana' => $paginas, 'tipos' => CampanasService::TIPOS]);
    }

    public function create()
    {
        return $this->formulario(new Campaign(['tipo' => 'institucional', 'medios' => []]));
    }

    public function edit(Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, 'La campaña de sistema no se edita.');
        return $this->formulario($campaign->load('perfil.paginas'));
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $c = $this->campanas->guardar(new Campaign(), $data, Auth::id());
        return redirect()->route('campaigns.index')->with('success', "Campaña «{$c->name}» creada en " . count($c->medios ?? []) . ' medio(s). Ya puedes elegirla al publicar y analizarla en Inteligencia.');
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, 'La campaña de sistema no se edita.');
        $data = $this->validar($request, $campaign);
        $this->campanas->guardar($campaign, $data);
        return redirect()->route('campaigns.index')->with('success', "Campaña «{$campaign->name}» actualizada.");
    }

    public function toggle(Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, 'La campaña de sistema no se puede desactivar.');
        $campaign->update(['is_active' => !$campaign->is_active]);
        $this->campanas->sincronizarPerfil($campaign);
        return back()->with('success', "Campaña «{$campaign->name}» " . ($campaign->is_active ? 'activada' : 'desactivada') . '.');
    }

    private function validar(Request $request, ?Campaign $c = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('campaigns', 'name')->ignore($c?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'tipo' => ['required', Rule::in(array_keys(CampanasService::TIPOS))],
            'contexto' => ['nullable', 'string', 'max:3000'],
            'territorio' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'medios' => ['required', 'array', 'min:1'],
            'medios.*' => ['string', Rule::in(array_keys($this->campanas->medios()))],
            'paginas_extra' => ['nullable', 'array'],
            'paginas_extra.*' => ['integer', 'exists:meta_pages,id'],
            'temas' => ['nullable', 'string', 'max:2000'],
        ], [
            'medios.required' => 'Elige al menos un medio donde se publicará la campaña.',
            'ends_on.after_or_equal' => 'La fecha de fin debe ser posterior a la de inicio.',
        ]);
    }

    private function formulario(Campaign $c)
    {
        $medios = $this->campanas->medios();
        $paginas = MetaPage::orderBy('name')->get(['id', 'name', 'page_id', 'medio_slug', 'instagram_business_account_id']);
        $porMedio = $paginas->whereNotNull('medio_slug')->groupBy('medio_slug');
        $extra = $c->exists && $c->perfil
            ? $c->perfil->paginas->filter(fn($p) => ($p->pivot->origen ?? 'manual') === 'manual')->pluck('id')->all()
            : [];
        return view('campaigns.form', [
            'c' => $c, 'medios' => $medios, 'porMedio' => $porMedio, 'paginas' => $paginas, 'extra' => $extra, 'tipos' => CampanasService::TIPOS,
        ]);
    }
}
