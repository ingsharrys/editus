<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\Contrato;
use App\Models\MedioCampana;
use App\Services\ContratosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Contrato del medio inscrito: elige cuál medio es (del listado de la campaña), llena el encabezado
 * (logo, membrete, nombre, representante legal, identificación, RUT, certificado bancario, correo),
 * lee el contrato de su tipo y firma. Recibe la copia en PDF por correo. Después vincula su medio.
 */
class ContratoController extends Controller
{
    public function __construct(private ContratosService $contratos)
    {
    }

    public function formulario(Request $request, Campaign $campaign)
    {
        $p = $this->participanteDe($request, $campaign);
        if (!$this->contratos->usaContratos($campaign)) return redirect()->route('certificados.formulario', $campaign);
        $medio = $this->contratos->medioDe($p);
        $contrato = $this->contratos->contratoDe($p);
        $anterior = $medio ? Contrato::where('participante_id', $p->id)->latest('id')->first() : null; // para precargar si lo anularon
        $previos = (array) ($anterior?->datos ?? []);
        $datos = array_merge([
            'medio_nombre' => $medio?->nombre, 'representante' => $p->representante, 'documento_tipo' => $p->documento_tipo ?: 'C.C.',
            'documento' => $p->documento, 'ciudad' => $p->ciudad, 'correo' => $request->user()->email,
            'canal' => array_filter(array_map(fn($x) => (string) (((array) $x)['dato'] ?? ''), (array) ($previos['canales'] ?? []))),
        ], array_filter($previos), array_filter((array) $request->old()));
        $valores = $medio ? $this->contratos->valores($campaign, $medio, $datos) : [];
        return view('contratos.formulario', [
            'c' => $campaign, 'p' => $p, 'medio' => $medio, 'contrato' => $contrato,
            'libres' => $this->contratos->mediosLibres($campaign, $p),
            'datos' => $datos, 'valores' => $valores,
            'texto' => $medio && $medio->tipo ? $this->contratos->render($medio->tipo->cuerpo, $valores, true) : '',
            'documentos' => CampaignParticipante::DOCUMENTOS,
            'siguiente' => $this->contratos->codigo($campaign, $this->contratos->siguienteNumero($campaign)),
        ]);
    }

    public function elegir(Request $request, Campaign $campaign)
    {
        $p = $this->participanteDe($request, $campaign);
        $d = $request->validate(['medio_id' => ['required', 'integer']], ['medio_id.required' => __('Elige tu medio del listado.')]);
        if ($error = $this->contratos->elegirMedio($p, (int) $d['medio_id'])) return back()->withErrors(['medio_id' => $error]);
        return redirect()->route('contratos.formulario', $campaign)->with('success', __('Listo. Ahora llena los datos y firma el contrato.'));
    }

    public function firmar(Request $request, Campaign $campaign)
    {
        $p = $this->participanteDe($request, $campaign);
        if ($this->contratos->contratoDe($p)) return redirect()->route('contratos.formulario', $campaign);
        $medio = $this->contratos->medioDe($p);
        if (!$medio || !$medio->tipo) return redirect()->route('contratos.formulario', $campaign)->withErrors(['medio_id' => __('Primero elige tu medio del listado.')]);
        if ($medio->faltaValor()) return redirect()->route('contratos.formulario', $campaign)->withErrors(['valor' => __('Tu contrato todavía no tiene valor. Escribe al administrador de la campaña.')]);
        $archivo = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'];
        // Cada canal del medio (los marca el administrador) pide su dato: dirección, usuario, frecuencia…
        $porCanal = $mensajesCanal = [];
        foreach (array_keys($medio->canales()) as $k) {
            $porCanal["canal.$k"] = ['required', 'string', 'max:190'];
            $mensajesCanal["canal.$k.required"] = __('Completa «:dato».', ['dato' => MedioCampana::CANALES[$k]['pide']]);
        }
        $d = $request->validate($porCanal + [
            'medio_nombre' => ['required', 'string', 'max:190'],
            'razon_social' => ['required', 'string', 'max:190'],
            'nit' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z.\- ]+$/'],
            'representante' => ['required', 'string', 'max:190'],
            'documento_tipo' => ['required', Rule::in(CampaignParticipante::DOCUMENTOS)],
            'documento' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z.\- ]+$/'],
            'correo' => ['required', 'email', 'max:190'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'direccion' => ['nullable', 'string', 'max:190'],
            'ciudad' => ['required', 'string', 'max:120'],
            // Cuenta donde se le paga (va en el contrato)
            'banco' => ['required', 'string', 'max:120'],
            'tipo_cuenta' => ['required', Rule::in(ContratosService::TIPOS_CUENTA)],
            'numero_cuenta' => ['required', 'string', 'max:40', 'regex:/^[0-9\- ]+$/'],
            'titular_cuenta' => ['required', 'string', 'max:190'],
            'documento_titular' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z.\- ]+$/'],
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
            'membrete' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'rut' => array_merge(['required'], $archivo),
            'certificado_bancario' => array_merge(['required'], $archivo),
            'acepto' => ['accepted'],
            'firma' => ['required', 'string', 'max:2000000'],
        ], [
            'logo.required' => __('Sube el logo del medio.'),
            'rut.required' => __('Adjunta el RUT.'),
            'certificado_bancario.required' => __('Adjunta el certificado bancario.'),
            'acepto.accepted' => __('Debes leer y aceptar el contrato.'),
            'firma.required' => __('Dibuja tu firma en el recuadro.'),
            'razon_social.required' => __('Escribe la razón social del medio (o tu nombre completo, si eres persona natural).'),
            'numero_cuenta.regex' => __('El número de cuenta solo lleva números.'),
        ] + $mensajesCanal);
        $png = $this->firmaPng($d['firma']);
        if (!$png) return back()->withInput()->withErrors(['firma' => __('No se pudo leer la firma. Vuelve a dibujarla.')]);

        $k = $this->contratos->firmar($p, $d, [
            'logo' => $request->file('logo'), 'membrete' => $request->file('membrete'),
            'rut' => $request->file('rut'), 'certificado_bancario' => $request->file('certificado_bancario'),
        ], $png, (string) $request->ip(), (string) $request->userAgent());

        $aviso = $k->enviado_at
            ? __('Contrato No. :n firmado. Te enviamos la copia en PDF a :correo. Ahora vincula tu medio.', ['n' => $k->codigo, 'correo' => $k->dato('correo')])
            : __('Contrato No. :n firmado. Descarga tu copia en PDF desde tu certificado. Ahora vincula tu medio.', ['n' => $k->codigo]);
        return redirect()->route('certificados.formulario', $campaign)->with('success', $aviso);
    }

    public function pdf(Request $request, Contrato $contrato)
    {
        $this->autorizar($request, $contrato);
        return response($this->contratos->pdf($contrato), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContratosService::descarga($this->contratos->nombreArchivo($contrato)),
        ]);
    }

    public function reenviar(Request $request, Contrato $contrato)
    {
        $this->autorizar($request, $contrato);
        return $this->contratos->enviar($contrato)
            ? back()->with('success', __('Enviamos la copia del contrato a :c.', ['c' => $contrato->dato('correo')]))
            : back()->with('error', __('No se pudo enviar: :e', ['e' => $contrato->fresh()->envio_error]));
    }

    /** RUT, certificado bancario, logo o membrete (solo el medio y el administrador). */
    public function archivo(Request $request, Contrato $contrato, string $cual)
    {
        $this->autorizar($request, $contrato);
        $ruta = match ($cual) {
            'rut' => $contrato->rut_path, 'certificado-bancario' => $contrato->certificado_bancario_path,
            'logo' => $contrato->logo_path, 'membrete' => $contrato->membrete_path, default => null,
        };
        abort_unless($ruta && Storage::disk(ContratosService::DISCO)->exists($ruta), 404);
        return Storage::disk(ContratosService::DISCO)->download($ruta, ContratosService::nombreSeguro($cual . '-' . $contrato->codigo) . '.' . pathinfo($ruta, PATHINFO_EXTENSION));
    }

    /** Firma dibujada (data URL PNG) → binario PNG, o null si no es válida. */
    private function firmaPng(string $dataUrl): ?string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) return null;
        $png = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($png === false || strlen($png) < 200 || strlen($png) > 1_500_000) return null;
        $info = @getimagesizefromstring($png);
        return $info && ($info[2] ?? null) === IMAGETYPE_PNG ? $png : null;
    }

    private function participanteDe(Request $request, Campaign $campaign): CampaignParticipante
    {
        $p = CampaignParticipante::where('campaign_id', $campaign->id)->where('user_id', $request->user()->id)->first();
        abort_unless($p, 404, __('No estás inscrito en esta campaña. Usa el enlace de inscripción que te enviaron.'));
        return $p->setRelation('campaign', $campaign);
    }

    private function autorizar(Request $request, Contrato $k): void
    {
        $u = $request->user();
        abort_unless($u && ((int) $k->user_id === (int) $u->id || $u->tienePermiso('campanas')), 403);
    }
}
