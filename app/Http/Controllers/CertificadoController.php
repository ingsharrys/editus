<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Services\CertificadosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Enlace de inscripción de una campaña y certificado de publicación de cada medio inscrito:
 * el medio llena sus datos y su logo, acepta los términos y firma; el certificado (web y PDF)
 * se arma con las publicaciones de la campaña en las páginas del medio.
 */
class CertificadoController extends Controller
{
    public const SESION = 'inscripcion_campana';

    public function __construct(private CertificadosService $cert)
    {
    }

    /** /inscripcion/{token}: con sesión inscribe de una vez; sin sesión guarda la invitación y pide entrar o registrarse. */
    public function inscripcion(Request $request, string $token)
    {
        $c = $this->cert->porToken($token);
        abort_unless($c, 404, __('Este enlace de inscripción no existe o ya está cerrado.'));
        if ($user = $request->user()) {
            $this->cert->inscribir($c, $user);
            $request->session()->forget(self::SESION);
            return redirect()->route('certificados.formulario', $c);
        }
        $request->session()->put(self::SESION, ['token' => $token, 'nombre' => $c->name]);
        return view('certificados.inscripcion', ['c' => $c]);
    }

    public function formulario(Request $request, Campaign $campaign)
    {
        $p = $this->participanteDe($request, $campaign);
        $paginas = $request->user()->metaPages()->orderBy('name')->get();
        return view('certificados.formulario', [
            'c' => $campaign,
            'p' => $p,
            'paginas' => $paginas,
            'terminos' => $this->cert->terminos($campaign),
            'logo' => $this->cert->dataUri($p->logo_path),
            'datos' => $this->cert->datos($p),
            'documentos' => CampaignParticipante::DOCUMENTOS,
        ]);
    }

    public function guardar(Request $request, Campaign $campaign)
    {
        $p = $this->participanteDe($request, $campaign);
        $suyas = $request->user()->metaPages()->pluck('meta_pages.id')->map(fn($v) => (int) $v)->all();
        $d = $request->validate([
            'medio_nombre' => ['required', 'string', 'max:190'],
            'logo' => [$p->logo_path ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
            'representante' => ['required', 'string', 'max:190'],
            'documento_tipo' => ['required', Rule::in(CampaignParticipante::DOCUMENTOS)],
            'documento' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z.\- ]+$/'],
            'cargo' => ['required', 'string', 'max:120'],
            'ciudad' => ['required', 'string', 'max:120'],
            'paginas' => ['nullable', 'array'],
            'paginas.*' => ['integer', Rule::in($suyas)],
            'acepto_terminos' => ['accepted'],
            'firma' => [$p->firma_path ? 'nullable' : 'required', 'string', 'max:2000000'],
        ], [
            'logo.required' => __('Sube el logo del medio.'),
            'acepto_terminos.accepted' => __('Debes aceptar los términos y condiciones.'),
            'firma.required' => __('Dibuja tu firma en el recuadro.'),
        ]);

        $firmaNueva = null;
        if (!empty($d['firma'])) {
            $firmaNueva = $this->cert->guardarFirma($p, $d['firma']);
            if (!$firmaNueva) return back()->withInput()->withErrors(['firma' => __('No se pudo leer la firma. Vuelve a dibujarla.')]);
        }

        $disco = Storage::disk(CertificadosService::DISCO);
        $anteriores = [];
        if ($request->hasFile('logo')) {
            $anteriores[] = $p->logo_path;
            $p->logo_path = $request->file('logo')->storeAs('certificados/' . $campaign->id,
                'logo-' . $p->id . '-' . Str::random(8) . '.' . $request->file('logo')->extension(), CertificadosService::DISCO);
        }
        if ($firmaNueva) {
            $anteriores[] = $p->firma_path;
            $p->firma_path = $firmaNueva;
        }
        $p->fill([
            'medio_nombre' => trim($d['medio_nombre']),
            'representante' => trim($d['representante']),
            'documento_tipo' => $d['documento_tipo'],
            'documento' => trim($d['documento']),
            'cargo' => trim($d['cargo']),
            'ciudad' => trim($d['ciudad']),
            'meta_page_ids' => array_values(array_map('intval', $d['paginas'] ?? [])) ?: null,
            'acepto_terminos_at' => now(),
            'terminos_hash' => hash('sha256', $this->cert->terminos($campaign)),
            'firmado_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
        ])->save();
        foreach (array_filter($anteriores) as $r) $disco->delete($r);

        $this->cert->sincronizarPaginas($campaign);
        return redirect()->route('certificados.ver', $p)->with('success', __('¡Listo! Tu certificado quedó firmado. La tabla de piezas se llena sola con cada publicación de la campaña en tu medio.'));
    }

    public function ver(Request $request, CampaignParticipante $participante)
    {
        $this->autorizar($request, $participante);
        return view('certificados.ver', $this->cert->datos($participante->load('campaign')) + [
            'esAdmin' => $request->user()->tienePermiso('campanas'),
        ]);
    }

    public function pdf(Request $request, CampaignParticipante $participante)
    {
        $this->autorizar($request, $participante);
        $participante->load('campaign');
        abort_unless($participante->firmado(), 404, __('El certificado aún no está firmado.'));
        return $this->cert->pdf($participante)->download($this->cert->nombreArchivo($participante));
    }

    /** Participación del usuario en la campaña (solo quien entró con el enlace de inscripción la tiene). */
    private function participanteDe(Request $request, Campaign $campaign): CampaignParticipante
    {
        $p = CampaignParticipante::where('campaign_id', $campaign->id)->where('user_id', $request->user()->id)->first();
        abort_unless($p, 404, __('No estás inscrito en esta campaña. Usa el enlace de inscripción que te enviaron.'));
        return $p->setRelation('campaign', $campaign);
    }

    private function autorizar(Request $request, CampaignParticipante $p): void
    {
        $u = $request->user();
        abort_unless($u && ((int) $p->user_id === (int) $u->id || $u->tienePermiso('campanas')), 403);
    }
}
