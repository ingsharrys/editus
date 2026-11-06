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
        // Los modelos de contrato que trae editus quedan listos para elegir la primera vez que se abre esta pantalla
        if (!$this->contratos->config($campaign)['modelos_cargados']) $this->contratos->cargarModelos($campaign->refresh());
        $this->contratos->actualizarModelos($campaign); // si cambió un Word de los modelos, los tipos que nadie editó se ponen al día
        $cfg = $this->contratos->config($campaign);
        $cert = app(\App\Services\CertificadosService::class);
        $tipos = ContratoTipo::withCount('medios')->where('campaign_id', $campaign->id)->orderBy('orden')->orderBy('id')->get();
        $medios = MedioCampana::with(['tipo', 'participante.user'])->where('campaign_id', $campaign->id)->orderBy('orden')->orderBy('nombre')->get();
        $contratos = Contrato::where('campaign_id', $campaign->id)->orderByDesc('id')->get();
        $vigentes = $contratos->whereNull('anulado_at')->whereNotNull('firmado_at')->keyBy('medio_id');
        return view('campaigns.plan.contratos', [
            'c' => $campaign,
            'cfg' => $cfg,
            'tipos' => $tipos,
            'faltanModelos' => array_diff(array_keys(ContratosService::MODELOS), $tipos->pluck('clave')->filter()->all()) !== [],
            'medios' => $medios,
            'vigentes' => $vigentes,
            'anulados' => $contratos->whereNotNull('anulado_at')->groupBy('medio_id'),
            'siguiente' => $this->contratos->codigo($campaign, $this->contratos->siguienteNumero($campaign)),
            'variables' => ContratosService::VARIABLES,
            'canales' => MedioCampana::CANALES,
            'enlace' => $campaign->inscripcion_token ? route('inscripcion.mostrar', $campaign->inscripcion_token) : null,
            'firmaContratante' => $cert->dataUri($cfg['contratante_firma']),
            'membreteContratante' => $cert->dataUri($cfg['contratante_membrete']),
        ]);
    }

    public function config(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'prefijo' => ['nullable', 'string', 'max:40'], 'inicio' => ['required', 'integer', 'min:1', 'max:999999'], 'digitos' => ['required', 'integer', 'min:1', 'max:6'],
            'contratante' => ['nullable', 'string', 'max:190'], 'contratante_nit' => ['nullable', 'string', 'max:40'],
            'contratante_representante' => ['nullable', 'string', 'max:190'], 'contratante_documento' => ['nullable', 'string', 'max:60'],
            'copia_a' => ['nullable', 'string', 'max:1000'], 'firma' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'], 'quitar_firma' => ['nullable', 'boolean'],
            'membrete' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:4096'], 'quitar_membrete' => ['nullable', 'boolean'],
        ]);
        $this->contratos->guardarConfig($campaign, $d, $request->file('firma'), (bool) ($d['quitar_firma'] ?? false),
            $request->file('membrete'), (bool) ($d['quitar_membrete'] ?? false));
        return back()->with('success', __('Numeración y datos del contratante guardados.'));
    }

    public function guardarTipo(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'nombre' => ['required', 'string', 'max:190'],
            'cuerpo' => ['nullable', 'string', 'max:500000'],
            'activo' => ['nullable', 'boolean'],
            'rol_medio' => ['nullable', 'string', 'max:60'],
            'rol_contratante' => ['nullable', 'string', 'max:60'],
        ] + $this->reglasCanales(false));
        $t = !empty($d['id']) ? ContratoTipo::where('campaign_id', $campaign->id)->findOrFail($d['id']) : new ContratoTipo(['campaign_id' => $campaign->id, 'orden' => (int) ContratoTipo::where('campaign_id', $campaign->id)->max('orden') + 1]);
        $t->fill(['nombre' => trim($d['nombre']), 'cuerpo' => ContratosService::limpiarHtml((string) ($d['cuerpo'] ?? '')), 'activo' => (bool) ($d['activo'] ?? true),
            'canales' => MedioCampana::desdeFormulario($d['canales'] ?? [], $d['pauta'] ?? []),
            'rol_medio' => trim((string) ($d['rol_medio'] ?? '')) ?: null, 'rol_contratante' => trim((string) ($d['rol_contratante'] ?? '')) ?: null])->save();
        return redirect()->to(route('campaigns.contratos', $campaign) . '#tipo-' . $t->id)->with('success', __('Tipo de contrato «:n» guardado.', ['n' => $t->nombre]));
    }

    /** Vista previa del contrato con datos de ejemplo. */
    public function vistaTipo(Campaign $campaign, int $tipo)
    {
        $t = ContratoTipo::where('campaign_id', $campaign->id)->findOrFail($tipo);
        $m = new MedioCampana(['nombre' => __('Medio de ejemplo'), 'valor' => 2500000,
            'canales' => $t->canalesPorDefecto() ?: ['web' => ['pauta' => false], 'facebook' => ['pauta' => true], 'tiktok' => ['pauta' => false]]]);
        $valores = $this->contratos->valores($campaign, $m, [
            'razon_social' => 'Medio de Ejemplo S.A.S.', 'representante' => 'Nombre del Representante', 'documento_tipo' => 'C.C.', 'documento' => '12345678',
            'correo' => 'correo@medio.com', 'ciudad' => 'Neiva', 'canal' => ['web' => 'www.medio.com'], 'nit' => '900.000.000-1', 'telefono' => '300 000 0000',
            'direccion' => 'Calle 1 # 2-3', 'banco' => 'Banco de Ejemplo', 'tipo_cuenta' => 'Ahorros', 'numero_cuenta' => '000-000000-00',
            'titular_cuenta' => 'Medio de Ejemplo S.A.S.', 'documento_titular' => '900.000.000-1',
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
            'valor' => ['nullable', 'string', 'max:30', 'regex:/^\$?\s*[\d.,\s]+$/'],
        ] + $this->reglasCanales(false), ['valor.regex' => __('Escribe el valor solo con números (ej.: 2.500.000).')]);
        $canales = $this->canalesDe($d);
        $valor = MedioCampana::leerValor($d['valor'] ?? null);
        if ($valor && $valor % 2 && !empty($d['tipo_id']) && ContratoTipo::find($d['tipo_id'])?->usaMitad()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['valor' => __('Este contrato divide el valor en dos cuotas mensuales iguales: escribe un valor par.')]);
        }
        $m = !empty($d['id']) ? MedioCampana::where('campaign_id', $campaign->id)->findOrFail($d['id']) : new MedioCampana(['campaign_id' => $campaign->id, 'orden' => (int) MedioCampana::where('campaign_id', $campaign->id)->max('orden') + 1]);
        $m->fill(['nombre' => trim($d['nombre']), 'canales' => $canales, 'tipo_id' => $d['tipo_id'] ?? null, 'valor' => $valor,
            'correo' => $d['correo'] ?? null, 'notas' => $d['notas'] ?? null, 'activo' => (bool) ($d['activo'] ?? true)])->save();
        return redirect()->to(route('campaigns.contratos', $campaign) . '#medios')->with('success', __('Medio «:n» guardado.', ['n' => $m->nombre]));
    }

    public function mediosEnBloque(Request $request, Campaign $campaign)
    {
        $d = $request->validate([
            'lista' => ['required', 'string', 'max:20000'],
            'tipo_id' => ['nullable', 'integer', Rule::exists('campaign_contrato_tipos', 'id')->where('campaign_id', $campaign->id)],
        ] + $this->reglasCanales(false), ['lista.required' => __('Escribe al menos un medio (uno por línea).')]);
        $n = $this->contratos->agregarEnBloque($campaign, $d['lista'], $d['tipo_id'] ?? null, $this->canalesDe($d));
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

    /** Canales marcados; «con pauta» solo en las redes sociales. */
    private function reglasCanales(bool $obligatorios = true): array
    {
        return [
            'canales' => [$obligatorios ? 'required' : 'nullable', 'array'],
            'canales.*' => ['string', Rule::in(array_keys(MedioCampana::CANALES))],
            'pauta' => ['nullable', 'array'],
            'pauta.*' => ['string', Rule::in(array_keys(array_filter(MedioCampana::CANALES, fn($c) => $c['pauta'])))],
        ];
    }

    /**
     * Canales de un medio: los que marcó el administrador o, si no marcó ninguno, los del tipo de contrato
     * (así basta con elegir el modelo). Sin ninguno de los dos, se pide marcarlos.
     */
    private function canalesDe(array $d): array
    {
        $canales = MedioCampana::desdeFormulario($d['canales'] ?? [], $d['pauta'] ?? []);
        if (!$canales && !empty($d['tipo_id'])) $canales = ContratoTipo::find($d['tipo_id'])?->canalesPorDefecto() ?? [];
        if (!$canales) throw \Illuminate\Validation\ValidationException::withMessages([
            'canales' => __('Elige el tipo de contrato o marca los canales del medio (página web, Facebook, Instagram, TikTok, emisora…).'),
        ]);
        return $canales;
    }

    /** Vuelve a cargar los modelos de contrato que trae editus (los que falten). */
    public function cargarModelos(Campaign $campaign)
    {
        $n = $this->contratos->cargarModelos($campaign);
        return redirect()->to(route('campaigns.contratos', $campaign) . '#tipos')->with('success', trans_choice('{0} Los modelos ya estaban cargados.|{1} Se cargó 1 modelo de contrato.|[2,*] Se cargaron :count modelos de contrato.', $n, ['count' => $n]));
    }
}
