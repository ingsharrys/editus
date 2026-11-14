<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\Contrato;
use App\Models\MedioCampana;
use App\Services\ContratosService;
use App\Services\ImagenLigera;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
        // Si deshabilitaron el medio que eligió (y aún no firma), vuelve a elegir
        $deshabilitado = $medio && !$medio->activo && !$contrato;
        if ($deshabilitado) $medio = null;
        $anterior = $medio ? Contrato::where('participante_id', $p->id)->latest('id')->first() : null; // para precargar si lo anularon
        $previos = (array) ($anterior?->datos ?? []);
        $datos = array_merge([
            'medio_nombre' => $medio?->nombre, 'representante' => $p->representante, 'documento_tipo' => $p->documento_tipo ?: 'C.C.',
            'documento' => $p->documento, 'ciudad' => $p->ciudad, 'correo' => $request->user()->email,
            'canal' => array_filter(array_map(fn($x) => (string) (((array) $x)['dato'] ?? ''), (array) ($previos['canales'] ?? []))),
        ], array_filter($previos), array_filter((array) $request->old()));
        $valores = $medio ? $this->contratos->valores($campaign, $medio, $datos) : [];
        return view('contratos.formulario', [
            'c' => $campaign, 'p' => $p, 'medio' => $medio, 'contrato' => $contrato, 'deshabilitado' => $deshabilitado,
            'libres' => $this->contratos->mediosLibres($campaign, $p),
            'datos' => $datos, 'valores' => $valores, 'subidos' => $this->paraLaVista($this->subidos($request, $campaign, $p)),
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
        if (!$medio->activo) return redirect()->route('contratos.formulario', $campaign)->withErrors(['medio_id' => __('Ese medio está deshabilitado en la campaña. Elige otro o escribe al administrador.')]);
        if ($aviso = $medio->avisoValor()) return redirect()->route('contratos.formulario', $campaign)->withErrors(['valor' => $aviso]);
        $archivo = self::REGLAS['rut'];
        // Lo que ya subió queda guardado aunque falte otro dato: si la validación falla, no tiene que volver a subirlo
        [$subidos, $erroresArchivos] = $this->guardarSubidos($request, $campaign, $p);
        // Cada canal del medio (los marca el administrador) pide su dato: dirección, usuario, frecuencia…
        $porCanal = $mensajesCanal = [];
        foreach (array_keys($medio->canales()) as $k) {
            $porCanal["canal.$k"] = ['required', 'string', 'max:190'];
            $mensajesCanal["canal.$k.required"] = __('Completa «:dato».', ['dato' => MedioCampana::CANALES[$k]['pide']]);
        }
        $validador = Validator::make($request->all(), $porCanal + [
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
            'logo' => array_merge([isset($subidos['logo']) ? 'nullable' : 'required'], self::REGLAS['logo']),
            'membrete' => array_merge(['nullable'], self::REGLAS['membrete']),
            'rut' => array_merge([isset($subidos['rut']) ? 'nullable' : 'required'], $archivo),
            'certificado_bancario' => array_merge([isset($subidos['certificado_bancario']) ? 'nullable' : 'required'], self::REGLAS['certificado_bancario']),
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
            'logo.uploaded' => __('El logo no alcanzó a subir (puede ser muy pesado para el servidor). Vuelve a elegirlo.'),
            'membrete.uploaded' => __('El membrete no alcanzó a subir (puede ser muy pesado para el servidor). Vuelve a elegirlo.'),
            'rut.uploaded' => __('El RUT no alcanzó a subir: pesa demasiado para el servidor. Adjúntalo más liviano (PDF).'),
            'certificado_bancario.uploaded' => __('El certificado bancario no alcanzó a subir: pesa demasiado para el servidor. Adjúntalo más liviano (PDF).'),
        ] + $mensajesCanal, ['logo' => __('el logo'), 'membrete' => __('el membrete'), 'rut' => __('el RUT'), 'certificado_bancario' => __('el certificado bancario')]);
        $validador->after(function ($v) use ($erroresArchivos) {
            foreach ($erroresArchivos as $campo => $msg) $v->errors()->add($campo, $msg);
        });
        $d = $validador->validate();
        $png = $this->firmaPng($d['firma']);
        if (!$png) return back()->withInput()->withErrors(['firma' => __('No se pudo leer la firma. Vuelve a dibujarla.')]);

        $archivos = [];
        foreach (array_keys(self::REGLAS) as $campo) {
            $x = $subidos[$campo] ?? null;
            $archivos[$campo] = $x ? new UploadedFile(Storage::disk(ContratosService::DISCO)->path($x['ruta']), $x['nombre'], null, null, true) : null;
        }
        $k = $this->contratos->firmar($p, $d, $archivos, $png, (string) $request->ip(), (string) $request->userAgent());
        $this->olvidarSubidos($request, $campaign, $p);

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

    // ─────────── Archivos subidos antes de que pase la validación ───────────

    /** Reglas de cada archivo. Las imágenes pueden llegar pesadas: se reducen a 400 px y 100 KB (ImagenLigera). */
    private const REGLAS = [
        'logo' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:20480'],
        'membrete' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:20480'],
        'rut' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        'certificado_bancario' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
    ];
    private const IMAGENES = ['logo', 'membrete'];
    private const TEMPORALES = 'contratos-temporales';

    private function claveSesion(Campaign $c): string
    {
        return 'contrato_subidos.' . $c->id;
    }

    private function carpetaTemporal(Campaign $c, CampaignParticipante $p): string
    {
        return self::TEMPORALES . '/' . $c->id . '/' . $p->id;
    }

    /** [campo => ['ruta', 'nombre']] de lo que este medio ya subió (y sigue en el disco). */
    private function subidos(Request $request, Campaign $c, CampaignParticipante $p): array
    {
        $carpeta = $this->carpetaTemporal($c, $p) . '/';
        $disco = Storage::disk(ContratosService::DISCO);
        return array_filter((array) $request->session()->get($this->claveSesion($c), []), fn($x, $campo) => isset(self::REGLAS[$campo])
            && is_array($x) && is_string($x['ruta'] ?? null) && str_starts_with($x['ruta'], $carpeta) && !str_contains($x['ruta'], '..') && $disco->exists($x['ruta']),
            ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Guarda cada archivo que llegó bien (las imágenes ya reducidas) y lo recuerda en la sesión.
     * @return array{0: array, 1: array} [subidos, errores por campo de las imágenes que no se pudieron leer]
     */
    private function guardarSubidos(Request $request, Campaign $c, CampaignParticipante $p): array
    {
        $disco = Storage::disk(ContratosService::DISCO);
        $subidos = $this->subidos($request, $c, $p);
        $errores = [];
        foreach (self::IMAGENES as $campo) {
            if ($request->boolean('quitar_' . $campo) && !$request->hasFile($campo) && isset($subidos[$campo])) unset($subidos[$campo]);
        }
        foreach (self::REGLAS as $campo => $reglas) {
            $f = $request->file($campo);
            if (!$f instanceof UploadedFile || Validator::make([$campo => $f], [$campo => $reglas])->fails()) continue;
            try {
                [$bytes, $ext] = in_array($campo, self::IMAGENES, true)
                    ? ImagenLigera::reducir((string) file_get_contents($f->getRealPath()))
                    : [(string) file_get_contents($f->getRealPath()), strtolower($f->extension() ?: $f->getClientOriginalExtension()) ?: 'bin'];
            } catch (\InvalidArgumentException $e) {
                $errores[$campo] = $e->getMessage();
                continue;
            }
            $ruta = $this->carpetaTemporal($c, $p) . '/' . $campo . '-' . Str::random(16) . '.' . $ext;
            $disco->put($ruta, $bytes);
            $nombre = pathinfo((string) $f->getClientOriginalName(), PATHINFO_FILENAME);
            $subidos[$campo] = ['ruta' => $ruta, 'nombre' => mb_substr(($nombre !== '' ? $nombre : $campo), 0, 100) . '.' . $ext];
        }
        // Lo que ya no se usa (reemplazado o quitado) se borra
        $vigentes = array_column($subidos, 'ruta');
        foreach ($disco->files($this->carpetaTemporal($c, $p)) as $r) if (!in_array($r, $vigentes, true)) $disco->delete($r);
        $request->session()->put($this->claveSesion($c), $subidos);
        return [$subidos, $errores];
    }

    private function olvidarSubidos(Request $request, Campaign $c, CampaignParticipante $p): void
    {
        $request->session()->forget($this->claveSesion($c));
        Storage::disk(ContratosService::DISCO)->deleteDirectory($this->carpetaTemporal($c, $p));
    }

    /** Para el formulario: nombre y, en las imágenes (pesan menos de 100 KB), la vista previa. */
    private function paraLaVista(array $subidos): array
    {
        $disco = Storage::disk(ContratosService::DISCO);
        foreach ($subidos as $campo => &$x) {
            $x['vista'] = null;
            if (in_array($campo, self::IMAGENES, true) && $disco->size($x['ruta']) <= 2 * ImagenLigera::MAX_BYTES) {
                $bin = (string) $disco->get($x['ruta']);
                $mime = @getimagesizefromstring($bin)['mime'] ?? null;
                if ($mime) $x['vista'] = 'data:' . $mime . ';base64,' . base64_encode($bin);
            }
        }
        return $subidos;
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
