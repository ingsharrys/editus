<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Services\CampanasService;
use App\Services\CertificadosService;
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
    public function __construct(private CampanasService $campanas, private CertificadosService $cert)
    {
        // La gestión de campañas es para los roles con el permiso «Campañas»
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->tienePermiso('campanas'), 403);
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
        abort_if($campaign->is_system, 403, __('La campaña de sistema no se edita.'));
        return $this->formulario($campaign->load('perfil.paginas'));
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $c = $this->campanas->guardar(new Campaign(), $data, Auth::id());
        $this->cert->guardarConfig($c, $data);
        return redirect()->route('campaigns.index')->with('success', __('Campaña «:nombre» creada en :n medio(s). Ya puedes elegirla al publicar y analizarla en Inteligencia.', ['nombre' => $c->name, 'n' => count($c->medios ?? [])]));
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, __('La campaña de sistema no se edita.'));
        $data = $this->validar($request, $campaign);
        $this->campanas->guardar($campaign, $data);
        $this->cert->guardarConfig($campaign, $data);
        if ($request->boolean('quedarse')) return redirect()->route('campaigns.edit', $campaign)->with('success', __('Campaña «:nombre» actualizada.', ['nombre' => $campaign->name]));
        return redirect()->route('campaigns.index')->with('success', __('Campaña «:nombre» actualizada.', ['nombre' => $campaign->name]));
    }

    public function toggle(Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, __('La campaña de sistema no se puede desactivar.'));
        $campaign->update(['is_active' => !$campaign->is_active]);
        $this->campanas->sincronizarPerfil($campaign);
        return back()->with('success', $campaign->is_active
            ? __('Campaña «:nombre» activada.', ['nombre' => $campaign->name])
            : __('Campaña «:nombre» desactivada.', ['nombre' => $campaign->name]));
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
            // Inscripción de medios y certificado
            'inscripcion_activa' => ['nullable', 'boolean'],
            'cert_obligatorio' => ['nullable', 'boolean'],
            'cert_entidad' => ['nullable', 'string', 'max:255'],
            'cert_orden' => ['nullable', 'string', 'max:120'],
            'cert_contratista' => ['nullable', 'string', 'max:255'],
            'cert_contratante' => ['nullable', 'string', 'max:255'],
            'cert_texto' => ['nullable', 'string', 'max:3000'],
            'cert_cierre' => ['nullable', 'string', 'max:1000'],
            'cert_terminos' => ['nullable', 'string', 'max:8000'],
            'cert_fecha_expedicion' => ['nullable', 'date'],
            'cert_piezas' => ['nullable', 'string', 'max:10000'],
            'cert_tipos_pieza' => ['nullable', 'string', 'max:1000'],
        ], [
            'medios.required' => __('Elige al menos un medio donde se publicará la campaña.'),
            'ends_on.after_or_equal' => __('La fecha de fin debe ser posterior a la de inicio.'),
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
        $participantes = $c->exists && CertificadosService::disponible() ? $c->participantes()->with('user:id,name,email')->latest()->get() : collect();
        return view('campaigns.form', [
            'c' => $c, 'medios' => $medios, 'porMedio' => $porMedio, 'paginas' => $paginas, 'extra' => $extra, 'tipos' => CampanasService::TIPOS,
            'cert' => $this->cert->config($c), 'participantes' => $participantes,
            'piezasPorParticipante' => $participantes->mapWithKeys(fn($p) => [$p->id => $this->cert->piezas($p->setRelation('campaign', $c))->sum('cantidad')]),
            'textoDefecto' => CertificadosService::TEXTO_DEFECTO, 'cierreDefecto' => CertificadosService::CIERRE_DEFECTO,
        ]);
    }

    /** Cambia el enlace de inscripción (el anterior deja de servir). */
    public function regenerarEnlace(Campaign $campaign)
    {
        abort_if($campaign->is_system, 403);
        $campaign->forceFill(['inscripcion_token' => $this->cert->nuevoToken()])->save();
        return redirect()->route('campaigns.edit', $campaign)->withFragment('inscripcion')
            ->with('success', __('Se creó un enlace de inscripción nuevo. El anterior ya no funciona.'));
    }

    public function quitarParticipante(Campaign $campaign, CampaignParticipante $participante)
    {
        abort_unless((int) $participante->campaign_id === (int) $campaign->id, 404);
        $disco = \Illuminate\Support\Facades\Storage::disk(CertificadosService::DISCO);
        foreach (array_filter([$participante->logo_path, $participante->firma_path]) as $r) $disco->delete($r);
        $participante->delete();
        $this->cert->sincronizarPaginas($campaign);
        return redirect()->route('campaigns.edit', $campaign)->withFragment('inscripcion')->with('success', __('Medio retirado de la campaña.'));
    }

    /** Publicaciones de la campaña para clasificar su programa, subprograma y tipo de pieza (llenan los certificados). */
    public function piezas(Request $request, Campaign $campaign)
    {
        $q = MetaPost::with('page:id,name')->where('campaign_id', $campaign->id)->where('status', 'success')
            ->when($request->query('pagina'), fn($x, $v) => $x->where('meta_page_id', (int) $v))
            ->when($request->query('sin'), fn($x) => $x->where(fn($y) => $y->whereNull('programa')->orWhere('programa', '')))
            ->orderByDesc('published_at')->orderByDesc('id');
        $posts = $q->paginate(50)->withQueryString();
        $paginas = MetaPage::whereIn('id', MetaPost::where('campaign_id', $campaign->id)->select('meta_page_id'))->orderBy('name')->get(['id', 'name']);
        return view('campaigns.piezas', [
            'c' => $campaign, 'posts' => $posts, 'paginas' => $paginas, 'programas' => $campaign->programas(),
            'tipos' => $this->cert->config($campaign)['tipos_pieza'], 'cert' => $this->cert,
        ]);
    }

    public function guardarPiezas(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'piezas' => ['required', 'array'],
            'piezas.*.programa' => ['nullable', 'string', 'max:190'],
            'piezas.*.subprograma' => ['nullable', 'string', 'max:190'],
            'piezas.*.tipo_pieza' => ['nullable', 'string', 'max:60'],
        ]);
        $n = 0;
        foreach ($d['piezas'] as $id => $f) {
            $post = MetaPost::where('campaign_id', $campaign->id)->whereKey((int) $id)->first();
            if (!$post) continue;
            $post->fill([
                'programa' => trim((string) ($f['programa'] ?? '')) ?: null,
                'subprograma' => trim((string) ($f['subprograma'] ?? '')) ?: null,
                'tipo_pieza' => trim((string) ($f['tipo_pieza'] ?? '')) ?: null,
            ]);
            if ($post->isDirty()) {
                $post->save();
                $n++;
            }
        }
        return back()->with('success', __(':n publicación(es) actualizada(s).', ['n' => $n]));
    }
}
