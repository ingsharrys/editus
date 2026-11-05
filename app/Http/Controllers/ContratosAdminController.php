<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contrato;
use App\Models\ContratoTipo;
use App\Models\MedioCampana;
use App\Services\ContratosService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Medios y contratos de una campaña (administrador): numeración y contratante, tipos de contrato (texto con
 * variables), listado de medios con su tipo y el estado de cada contrato (PDF, documentos, reenviar, anular).
 */
class ContratosAdminController extends Controller
{
    public function __construct(private ContratosService $contratos)
    {
    }

    public function index(Campaign $campaign)
    {
        abort_unless(ContratosService::disponible(), 404, __('Falta migrar la base de datos (php artisan migrate).'));
        $medios = MedioCampana::with(['tipo', 'participante.user'])->where('campaign_id', $campaign->id)->orderBy('orden')->orderBy('nombre')->get();
        $contratos = Contrato::where('campaign_id', $campaign->id)->orderByDesc('id')->get();
        $vigentes = $contratos->whereNull('anulado_at')->whereNotNull('firmado_at')->keyBy('medio_id');
        return view('campaigns.plan.contratos', [
            'c' => $campaign,
            'cfg' => $this->contratos->config($campaign),
            'tipos' => ContratoTipo::withCount('medios')->where('campaign_id', $campaign->id)->orderBy('orden')->orderBy('id')->get(),
            'medios' => $medios,
            'vigentes' => $vigentes,
            'anulados' => $contratos->whereNotNull('anulado_at')->groupBy('medio_id'),
            'siguiente' => $this->contratos->codigo($campaign, $this->contratos->siguienteNumero($campaign)),
            'variables' => ContratosService::VARIABLES,
            'canales' => MedioCampana::CANALES,
            'enlace' => $campaign->inscripcion_token ? route('inscripcion.mostrar', $campaign->inscripcion_token) : null,
            'firmaContratante' => app(\App\Services\CertificadosService::class)->dataUri($this->contratos->config($campaign)['contratante_firma']),
        ]);
    }

    public function config(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'prefijo' => ['nullable', 'string', 'max:40'], 'inicio' => ['required', 'integer', 'min:1', 'max:999999'], 'digitos' => ['required', 'integer', 'min:1', 'max:6'],
            'contratante' => ['nullable', 'string', 'max:190'], 'contratante_nit' => ['nullable', 'string', 'max:40'],
            'contratante_representante' => ['nullable', 'string', 'max:190'], 'contratante_documento' => ['nullable', 'string', 'max:60'],
            'copia_a' => ['nullable', 'string', 'max:1000'], 'firma' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'], 'quitar_firma' => ['nullable', 'boolean'],
        ]);
        $this->contratos->guardarConfig($campaign, $d, $request->file('firma'), (bool) ($d['quitar_firma'] ?? false));
        return back()->with('success', __('Numeración y datos del contratante guardados.'));
    }

    public function guardarTipo(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'nombre' => ['required', 'string', 'max:190'],
            'cuerpo' => ['nullable', 'string', 'max:500000'],
            'activo' => ['nullable', 'boolean'],
        ]);
        $t = !empty($d['id']) ? ContratoTipo::where('campaign_id', $campaign->id)->findOrFail($d['id']) : new ContratoTipo(['campaign_id' => $campaign->id, 'orden' => (int) ContratoTipo::where('campaign_id', $campaign->id)->max('orden') + 1]);
        $t->fill(['nombre' => trim($d['nombre']), 'cuerpo' => ContratosService::limpiarHtml((string) ($d['cuerpo'] ?? '')), 'activo' => (bool) ($d['activo'] ?? true)])->save();
        return redirect()->to(route('campaigns.contratos', $campaign) . '#tipo-' . $t->id)->with('success', __('Tipo de contrato «:n» guardado.', ['n' => $t->nombre]));
    }

    /** Vista previa del contrato con datos de ejemplo. */
    public function vistaTipo(Campaign $campaign, int $tipo)
    {
        $t = ContratoTipo::where('campaign_id', $campaign->id)->findOrFail($tipo);
        $m = new MedioCampana(['nombre' => __('Medio de ejemplo'), 'canales' => ['web' => ['pauta' => false], 'facebook' => ['pauta' => true], 'tiktok' => ['pauta' => false]]]);
        $valores = $this->contratos->valores($campaign, $m, [
            'representante' => 'Nombre del Representante', 'documento_tipo' => 'C.C.', 'documento' => '12345678', 'correo' => 'correo@medio.com',
            'ciudad' => 'Neiva', 'canal' => ['web' => 'www.medio.com'], 'nit' => '900.000.000-1', 'telefono' => '300 000 0000', 'direccion' => 'Calle 1 # 2-3',
        ], $this->contratos->siguienteNumero($campaign));
        return view('contratos.vista', ['c' => $campaign, 't' => $t, 'texto' => $this->contratos->render($t->cuerpo, $valores, true)]);
    }

    public function borrarTipo(Campaign $campaign, int $tipo)
    {
        $t = ContratoTipo::where('campaign_id', $campaign->id)->findOrFail($tipo);
        if (MedioCampana::where('tipo_id', $t->id)->exists()) return back()->with('error', __('Hay medios con este tipo de contrato: cámbiales el tipo antes de borrarlo.'));
        $t->delete();
        return back()->with('success', __('Tipo de contrato borrado.'));
    }

    public function guardarMedio(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'nombre' => ['required', 'string', 'max:190'],
            'tipo_id' => ['nullable', 'integer', Rule::exists('campaign_contrato_tipos', 'id')->where('campaign_id', $campaign->id)],
            'correo' => ['nullable', 'email', 'max:190'],
            'notas' => ['nullable', 'string', 'max:500'],
            'activo' => ['nullable', 'boolean'],
        ] + $this->reglasCanales(), $this->mensajesCanales());
        $m = !empty($d['id']) ? MedioCampana::where('campaign_id', $campaign->id)->findOrFail($d['id']) : new MedioCampana(['campaign_id' => $campaign->id, 'orden' => (int) MedioCampana::where('campaign_id', $campaign->id)->max('orden') + 1]);
        $m->fill(['nombre' => trim($d['nombre']), 'canales' => MedioCampana::desdeFormulario($d['canales'], $d['pauta'] ?? []), 'tipo_id' => $d['tipo_id'] ?? null,
            'correo' => $d['correo'] ?? null, 'notas' => $d['notas'] ?? null, 'activo' => (bool) ($d['activo'] ?? true)])->save();
        return redirect()->to(route('campaigns.contratos', $campaign) . '#medios')->with('success', __('Medio «:n» guardado.', ['n' => $m->nombre]));
    }

    public function mediosEnBloque(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'lista' => ['required', 'string', 'max:20000'],
            'tipo_id' => ['nullable', 'integer', Rule::exists('campaign_contrato_tipos', 'id')->where('campaign_id', $campaign->id)],
        ] + $this->reglasCanales(), ['lista.required' => __('Escribe al menos un medio (uno por línea).')] + $this->mensajesCanales());
        $n = $this->contratos->agregarEnBloque($campaign, $d['lista'], $d['tipo_id'] ?? null, MedioCampana::desdeFormulario($d['canales'], $d['pauta'] ?? []));
        return redirect()->to(route('campaigns.contratos', $campaign) . '#medios')->with('success', trans_choice('{0} No se agregó ningún medio (ya estaban).|{1} Se agregó 1 medio.|[2,*] Se agregaron :count medios.', $n, ['count' => $n]));
    }

    public function liberarMedio(Campaign $campaign, int $medio)
    {
        $m = MedioCampana::where('campaign_id', $campaign->id)->findOrFail($medio);
        if (Contrato::where('medio_id', $m->id)->whereNull('anulado_at')->whereNotNull('firmado_at')->exists()) return back()->with('error', __('Este medio tiene un contrato vigente: anúlalo primero.'));
        $m->forceFill(['participante_id' => null])->save();
        return back()->with('success', __('«:n» quedó libre: otra persona puede elegirlo al inscribirse.', ['n' => $m->nombre]));
    }

    public function borrarMedio(Campaign $campaign, int $medio)
    {
        $m = MedioCampana::where('campaign_id', $campaign->id)->findOrFail($medio);
        if (Contrato::where('medio_id', $m->id)->exists()) return back()->with('error', __('Este medio tiene contratos: desactívalo en lugar de borrarlo.'));
        $m->delete();
        return back()->with('success', __('Medio borrado.'));
    }

    public function anular(Campaign $campaign, int $contrato)
    {
        $k = Contrato::where('campaign_id', $campaign->id)->whereNull('anulado_at')->findOrFail($contrato);
        $this->contratos->anular($k);
        return back()->with('success', __('Contrato No. :n anulado. El medio deberá firmar uno nuevo (con un número nuevo).', ['n' => $k->codigo]));
    }

    /** Canales del medio: al menos uno; «con pauta» solo en las redes sociales. */
    private function reglasCanales(): array
    {
        return [
            'canales' => ['required', 'array', 'min:1'],
            'canales.*' => ['string', Rule::in(array_keys(MedioCampana::CANALES))],
            'pauta' => ['nullable', 'array'],
            'pauta.*' => ['string', Rule::in(array_keys(array_filter(MedioCampana::CANALES, fn($c) => $c['pauta'])))],
        ];
    }

    private function mensajesCanales(): array
    {
        return ['canales.required' => __('Marca los canales del medio (página web, Facebook, Instagram, TikTok, emisora…).')];
    }
}
