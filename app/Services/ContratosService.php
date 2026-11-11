<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\Contrato;
use App\Models\ContratoTipo;
use App\Models\MedioCampana;
use App\Models\User;
use App\Support\NumeroEnLetras;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contratos de los medios de una campaña.
 *
 * El administrador carga el listado de medios y a cada uno le elige su tipo de contrato (los modelos del PIC 2026
 * vienen listos: al elegir uno, el medio queda con los canales que cubre) y su valor. Al inscribirse con el enlace
 * de la campaña, el medio elige cuál es, llena sus datos (razón social, representante legal, identificación, el dato
 * de cada canal, cuenta bancaria, RUT, certificado bancario, logo, correo), firma y recibe su copia en PDF por correo.
 * Cada contrato lleva un número consecutivo de la campaña. Hasta que no lo firme no puede vincular su medio.
 */
class ContratosService
{
    public const DISCO = 'local';

    /** Variables que se pueden usar en el texto de un contrato. */
    public const VARIABLES = [
        'numero' => 'Número del contrato, con el prefijo',
        'consecutivo' => 'Número del contrato, sin el prefijo (ej.: 007)',
        'fecha' => 'Fecha de la firma (en letras)',
        'fecha_corta' => 'Fecha de la firma (dd/mm/aaaa)',
        'medio' => 'Nombre del medio',
        'razon_social' => 'Razón social del medio (o nombre del titular, si es persona natural)',
        'canales' => 'Canales del medio (ej.: página web, Facebook con pauta y TikTok)',
        'sitio' => 'Página web del medio',
        'nit' => 'NIT del medio',
        'representante' => 'Representante legal',
        'documento_tipo' => 'Tipo de documento del representante (C.C., C.E.…)',
        'documento_nombre' => 'Tipo de documento en palabras (cédula de ciudadanía…)',
        'documento' => 'Número de documento del representante',
        'valor' => 'Valor del contrato del medio (ej.: 2.500.000)',
        'valor_letras' => 'Valor del contrato en letras (ej.: DOS MILLONES QUINIENTOS MIL)',
        'valor_mitad' => 'La mitad del valor: cada una de las dos cuotas mensuales (ej.: 1.250.000)',
        'valor_mitad_letras' => 'La mitad del valor en letras (ej.: UN MILLÓN DOSCIENTOS CINCUENTA MIL)',
        'banco' => 'Banco de la cuenta del medio',
        'tipo_cuenta' => 'Tipo de cuenta (Ahorros o Corriente)',
        'numero_cuenta' => 'Número de la cuenta',
        'titular_cuenta' => 'Titular de la cuenta',
        'documento_titular' => 'NIT o C.C. del titular de la cuenta',
        'correo' => 'Correo',
        'telefono' => 'Teléfono',
        'direccion' => 'Dirección',
        'ciudad' => 'Ciudad',
        'campana' => 'Nombre de la campaña',
        'contratante' => 'Contratante',
        'contratante_nit' => 'NIT del contratante',
        'contratante_representante' => 'Representante del contratante',
        'contratante_documento' => 'Documento del representante del contratante',
    ];

    /** Tipos de documento en palabras, para el texto del contrato. */
    public const DOCUMENTO_NOMBRE = ['C.C.' => 'cédula de ciudadanía', 'C.E.' => 'cédula de extranjería', 'NIT' => 'NIT', 'Pasaporte' => 'pasaporte'];

    public const TIPOS_CUENTA = ['Ahorros', 'Corriente'];

    /**
     * Modelos de contrato que trae editus (resources/contratos). Se cargan solos en la campaña la primera vez que
     * se abre «Medios y contratos»; cada uno con los canales que cubre y cómo se llama cada parte en las firmas.
     */
    public const MODELOS = [
        'pic-2026-1' => ['nombre' => 'Modelo 1 · Publicaciones en el medio digital', 'archivo' => 'pic-2026/modelo-1.html',
            'canales' => ['facebook' => ['pauta' => false]],
            'anteriores' => ['947b95aa72fcb8f9b8edae5c1d1ce18011851e96f587e0e311ee2d25182933ca']],
        'pic-2026-2' => ['nombre' => 'Modelo 2 · Publicaciones y banners en la página web', 'archivo' => 'pic-2026/modelo-2.html',
            'canales' => ['web' => ['pauta' => false], 'facebook' => ['pauta' => false]],
            'anteriores' => ['e040cfa43c634b916abae3bf71c12c017bb151894a37f0fe254e0c2769c8e8f1']],
        'pic-2026-3' => ['nombre' => 'Modelo 3 · Publicaciones, Instagram, TikTok, banners y radio', 'archivo' => 'pic-2026/modelo-3.html',
            'canales' => ['web' => ['pauta' => false], 'facebook' => ['pauta' => false], 'instagram' => ['pauta' => false], 'tiktok' => ['pauta' => false], 'radio' => ['pauta' => false]],
            'anteriores' => ['33bb2258fc3b60ae786b1b127b16e4bda2476337b0872755969c8e7bcb1948bd']],
    ];
    // «anteriores»: huellas (ContratosService::huella) de los textos que ya tuvo cada modelo. Cuando se cambia un Word,
    // se agrega aquí la huella del texto que se reemplaza: así los tipos que nadie editó se ponen al día solos.

    /** Las partes en los modelos del PIC 2026: el medio arrienda el espacio (arrendador) a DIME MEDIA (arrendatario). */
    public const MODELOS_ROLES = ['rol_medio' => 'EL ARRENDADOR', 'rol_contratante' => 'EL ARRENDATARIO'];

    /** El contratante de los modelos del PIC 2026 (se llena si la campaña no lo tiene). Su firma la sube el administrador. */
    public const MODELOS_CONTRATANTE = [
        'contratante' => 'DIME MEDIA S.A.S.', 'contratante_nit' => '901.908.077-3',
        'contratante_representante' => 'JUAN CARLOS CHARRY GONZALEZ', 'contratante_documento' => 'C.C. 12.133.895',
    ];

    /** Hoja membreteada del contratante de los modelos (fondo de cada página del contrato). */
    public const MODELOS_MEMBRETE = 'pic-2026/membrete-dime-media.jpg';

    public static function disponible(): bool
    {
        // Se mira en cada pantalla (middleware): se recuerda solo el «sí» y solo en esta instancia de la aplicación
        if (app()->bound('contratos.disponible')) return true;
        if (!Schema::hasTable('campaign_contratos') || !Schema::hasColumn('campaigns', 'contratos') || !Schema::hasColumn('campaign_medios', 'canales')
            || !Schema::hasColumn('campaign_medios', 'valor') || !Schema::hasColumn('campaign_contrato_tipos', 'canales')) return false;
        app()->instance('contratos.disponible', true);
        return true;
    }

    /** ¿La campaña pide contrato? (tiene listado de medios) */
    public function usaContratos(Campaign $c): bool
    {
        return self::disponible() && MedioCampana::where('campaign_id', $c->id)->where('activo', true)->exists();
    }

    public function config(Campaign $c): array
    {
        $cfg = (array) ($c->contratos ?? []);
        return [
            'prefijo' => trim((string) ($cfg['prefijo'] ?? '')),
            'inicio' => max(1, (int) ($cfg['inicio'] ?? 1)),
            'digitos' => min(6, max(1, (int) ($cfg['digitos'] ?? 3))),
            'contratante' => trim((string) ($cfg['contratante'] ?? '')),
            'contratante_nit' => trim((string) ($cfg['contratante_nit'] ?? '')),
            'contratante_representante' => trim((string) ($cfg['contratante_representante'] ?? '')),
            'contratante_documento' => trim((string) ($cfg['contratante_documento'] ?? '')),
            'contratante_firma' => ($cfg['contratante_firma'] ?? null) ?: null,
            'contratante_membrete' => ($cfg['contratante_membrete'] ?? null) ?: null,
            'copia_a' => array_values(array_filter(array_map('trim', (array) ($cfg['copia_a'] ?? [])))),
            'modelos_cargados' => (bool) ($cfg['modelos_cargados'] ?? false),
        ];
    }

    public function guardarConfig(Campaign $c, array $d, ?UploadedFile $firma = null, bool $quitarFirma = false, ?UploadedFile $membrete = null, bool $quitarMembrete = false): void
    {
        $cfg = $this->config($c);
        $correos = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($d['copia_a'] ?? '')) ?: []), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
        $nuevo = [
            'prefijo' => mb_substr(trim((string) ($d['prefijo'] ?? '')), 0, 40),
            'inicio' => max(1, (int) ($d['inicio'] ?? 1)),
            'digitos' => min(6, max(1, (int) ($d['digitos'] ?? 3))),
            'contratante' => mb_substr(trim((string) ($d['contratante'] ?? '')), 0, 190),
            'contratante_nit' => mb_substr(trim((string) ($d['contratante_nit'] ?? '')), 0, 40),
            'contratante_representante' => mb_substr(trim((string) ($d['contratante_representante'] ?? '')), 0, 190),
            'contratante_documento' => mb_substr(trim((string) ($d['contratante_documento'] ?? '')), 0, 60),
            'contratante_firma' => $cfg['contratante_firma'],
            'contratante_membrete' => $cfg['contratante_membrete'],
            'copia_a' => $correos,
            'modelos_cargados' => $cfg['modelos_cargados'],
        ];
        if ($quitarMembrete && $nuevo['contratante_membrete']) {
            Storage::disk(self::DISCO)->delete($nuevo['contratante_membrete']);
            $nuevo['contratante_membrete'] = null;
        }
        if ($membrete) {
            if ($nuevo['contratante_membrete']) Storage::disk(self::DISCO)->delete($nuevo['contratante_membrete']);
            $nuevo['contratante_membrete'] = $membrete->storeAs('contratos/' . $c->id, 'membrete-contratante-' . Str::random(10) . '.' . ($membrete->extension() ?: 'jpg'), self::DISCO);
        }
        if ($quitarFirma && $nuevo['contratante_firma']) {
            Storage::disk(self::DISCO)->delete($nuevo['contratante_firma']);
            $nuevo['contratante_firma'] = null;
        }
        if ($firma) {
            if ($nuevo['contratante_firma']) Storage::disk(self::DISCO)->delete($nuevo['contratante_firma']);
            $nuevo['contratante_firma'] = $firma->storeAs('contratos/' . $c->id, 'firma-contratante-' . Str::random(10) . '.' . ($firma->extension() ?: 'png'), self::DISCO);
        }
        $c->forceFill(['contratos' => $nuevo])->save();
    }

    public function codigo(Campaign $c, int $numero): string
    {
        $cfg = $this->config($c);
        return $cfg['prefijo'] . str_pad((string) $numero, $cfg['digitos'], '0', STR_PAD_LEFT);
    }

    /** El número sin el prefijo: 7 → «007». */
    public function consecutivo(Campaign $c, int $numero): string
    {
        return str_pad((string) $numero, $this->config($c)['digitos'], '0', STR_PAD_LEFT);
    }

    /**
     * Carga en la campaña los modelos de contrato que le falten (por su clave) y, si la campaña no los tiene,
     * los datos y la hoja membreteada del contratante de los modelos. Devuelve cuántos tipos agregó.
     */
    public function cargarModelos(Campaign $c): int
    {
        $n = 0;
        $orden = (int) ContratoTipo::where('campaign_id', $c->id)->max('orden');
        foreach (self::MODELOS as $clave => $m) {
            if (ContratoTipo::where('campaign_id', $c->id)->where('clave', $clave)->exists()) continue;
            $archivo = resource_path('contratos/' . $m['archivo']);
            if (!is_file($archivo)) continue;
            ContratoTipo::create(['campaign_id' => $c->id, 'clave' => $clave, 'nombre' => $m['nombre'], 'cuerpo' => self::limpiarHtml((string) file_get_contents($archivo)),
                'canales' => MedioCampana::ordenar($m['canales']), 'activo' => true, 'orden' => ++$orden] + self::MODELOS_ROLES);
            $n++;
        }
        $cfg = (array) ($c->contratos ?? []);
        foreach (self::MODELOS_CONTRATANTE as $k => $v) {
            if (trim((string) ($cfg[$k] ?? '')) === '') $cfg[$k] = $v;
        }
        $membrete = resource_path('contratos/' . self::MODELOS_MEMBRETE);
        if (empty($cfg['contratante_membrete']) && is_file($membrete)) {
            $ruta = 'contratos/' . $c->id . '/membrete-contratante-' . Str::random(10) . '.' . pathinfo($membrete, PATHINFO_EXTENSION);
            Storage::disk(self::DISCO)->put($ruta, (string) file_get_contents($membrete));
            $cfg['contratante_membrete'] = $ruta;
        }
        $cfg['modelos_cargados'] = true;
        $c->forceFill(['contratos' => $cfg])->save();
        return $n;
    }

    /**
     * Pone al día los tipos de contrato hechos con un modelo de editus cuando el modelo cambió (un Word nuevo), solo si
     * nadie los editó: su texto todavía es el de una versión anterior del modelo. Los contratos ya firmados no cambian.
     * Sin campaña, revisa todas. Devuelve cuántos actualizó.
     */
    public function actualizarModelos(?Campaign $c = null): int
    {
        $n = 0;
        foreach (self::MODELOS as $clave => $m) {
            $archivo = resource_path('contratos/' . $m['archivo']);
            if (!is_file($archivo) || empty($m['anteriores'])) continue;
            $nuevo = self::limpiarHtml((string) file_get_contents($archivo));
            ContratoTipo::where('clave', $clave)->when($c, fn($q) => $q->where('campaign_id', $c->id))->get()
                ->each(function (ContratoTipo $t) use ($m, $nuevo, &$n) {
                    if (!in_array(self::huella((string) $t->cuerpo), $m['anteriores'], true)) return;
                    $t->forceFill(['cuerpo' => $nuevo])->save();
                    $n++;
                });
        }
        return $n;
    }

    /** Número que le tocaría al próximo contrato (para mostrarlo; el definitivo se asigna al firmar). */
    public function siguienteNumero(Campaign $c): int
    {
        return max((int) Contrato::where('campaign_id', $c->id)->max('numero') + 1, $this->config($c)['inicio']);
    }

    // ───────────────────────── Medio y participante ─────────────────────────

    public function medioDe(CampaignParticipante $p): ?MedioCampana
    {
        return self::disponible() ? MedioCampana::with('tipo')->where('participante_id', $p->id)->first() : null;
    }

    public function contratoDe(CampaignParticipante $p): ?Contrato
    {
        return self::disponible() ? Contrato::where('participante_id', $p->id)->whereNull('anulado_at')->whereNotNull('firmado_at')->latest('id')->first() : null;
    }

    /**
     * Canales del medio que eligió el participante (['web', 'tiktok', …]), o null si no se sabe
     * (campaña sin listado de medios o medio sin canales): en ese caso el portal muestra todo, como antes.
     */
    public function canalesDe(CampaignParticipante $p): ?array
    {
        $m = $this->medioDe($p);
        return $m && $m->canales() ? array_keys($m->canales()) : null;
    }

    /** ¿El medio del participante tiene ese canal? (si no se saben sus canales, sí) */
    public function tieneCanal(CampaignParticipante $p, string $canal): bool
    {
        $canales = $this->canalesDe($p);
        return $canales === null || in_array($canal, $canales, true);
    }

    /** ¿Le falta firmar el contrato para seguir? */
    public function requiereContrato(CampaignParticipante $p): bool
    {
        $p->loadMissing('campaign');
        return $p->campaign && $this->usaContratos($p->campaign) && !$this->contratoDe($p);
    }

    /** Inscripción con el contrato pendiente (campaña activa con listado de medios). */
    public function pendiente(User $u): ?CampaignParticipante
    {
        if (!self::disponible()) return null;
        return CampaignParticipante::where('user_id', $u->id)
            ->whereHas('campaign', fn($q) => $q->where('is_active', true)->whereHas('medios', fn($m) => $m->where('activo', true)))
            ->with('campaign')->oldest()->get()
            ->first(fn($p) => !$this->contratoDe($p));
    }

    /** Medios que el participante puede elegir: los activos que nadie ha tomado (o el suyo). */
    public function mediosLibres(Campaign $c, CampaignParticipante $p): Collection
    {
        return MedioCampana::with('tipo')->where('campaign_id', $c->id)->where('activo', true)
            ->where(fn($q) => $q->whereNull('participante_id')->orWhere('participante_id', $p->id))
            ->orderBy('orden')->orderBy('nombre')->get();
    }

    /** El participante dice cuál medio es. Devuelve un mensaje de error o null. */
    public function elegirMedio(CampaignParticipante $p, int $medioId): ?string
    {
        if ($this->contratoDe($p)) return __('Ya firmaste el contrato: el medio no se puede cambiar. Si te equivocaste, pide al administrador que lo anule.');
        return DB::transaction(function () use ($p, $medioId) {
            $m = MedioCampana::where('campaign_id', $p->campaign_id)->where('activo', true)->lockForUpdate()->find($medioId);
            if (!$m) return __('Ese medio no está en el listado de la campaña.');
            if ($m->participante_id && (int) $m->participante_id !== (int) $p->id) return __('Otra persona ya se inscribió como «:m». Si eres tú, escribe al administrador de la campaña.', ['m' => $m->nombre]);
            if (!$m->tipo_id) return __('Este medio todavía no tiene un tipo de contrato asignado. Escribe al administrador de la campaña.');
            MedioCampana::where('campaign_id', $p->campaign_id)->where('participante_id', $p->id)->where('id', '!=', $m->id)->update(['participante_id' => null]);
            $m->forceFill(['participante_id' => $p->id])->save();
            if (!$p->medio_nombre || $p->medio_nombre !== $m->nombre) $p->forceFill(['medio_nombre' => $m->nombre])->save();
            return null;
        });
    }

    // ───────────────────────── Texto del contrato ─────────────────────────

    /** Valores de las variables para un medio (los del contrato firmado, o los que va escribiendo). */
    public function valores(Campaign $c, MedioCampana $m, array $datos, ?int $numero = null, ?Carbon $fecha = null): array
    {
        $cfg = $this->config($c);
        $fecha ??= now();
        $cert = app(CertificadosService::class);
        $v = fn(string $k) => trim((string) ($datos[$k] ?? ''));
        $tipoDoc = $v('documento_tipo') ?: 'C.C.';
        return [
            'numero' => $numero ? $this->codigo($c, $numero) : __('(se asigna al firmar)'),
            'consecutivo' => $numero ? $this->consecutivo($c, $numero) : __('(se asigna al firmar)'),
            'fecha' => $cert->fechaEnLetras($fecha),
            'fecha_corta' => $fecha->format('d/m/Y'),
            'medio' => $v('medio_nombre') ?: $m->nombre,
            'razon_social' => $v('razon_social'),
            'canales' => $m->canalesTexto(),
            'sitio' => trim((string) ($datos['canal']['web'] ?? '')),
            'nit' => $v('nit'),
            'representante' => $v('representante'),
            'documento_tipo' => $tipoDoc,
            'documento_nombre' => self::DOCUMENTO_NOMBRE[$tipoDoc] ?? mb_strtolower($tipoDoc),
            'documento' => $v('documento') !== '' ? $cert->formatoDocumento($v('documento')) : '',
            'valor' => $m->valor ? number_format((int) $m->valor, 0, ',', '.') : '',
            'valor_letras' => $m->valor ? NumeroEnLetras::pesos((int) $m->valor) : '',
            'valor_mitad' => $m->valor ? number_format(intdiv((int) $m->valor, 2), 0, ',', '.') : '',
            'valor_mitad_letras' => $m->valor ? NumeroEnLetras::pesos(intdiv((int) $m->valor, 2)) : '',
            'banco' => $v('banco'),
            'tipo_cuenta' => $v('tipo_cuenta'),
            'numero_cuenta' => $v('numero_cuenta'),
            'titular_cuenta' => $v('titular_cuenta'),
            'documento_titular' => $v('documento_titular'),
            'correo' => $v('correo'),
            'telefono' => $v('telefono'),
            'direccion' => $v('direccion'),
            'ciudad' => $v('ciudad'),
            'campana' => $c->name,
            'contratante' => $cfg['contratante'],
            'contratante_nit' => $cfg['contratante_nit'],
            'contratante_representante' => $cfg['contratante_representante'],
            'contratante_documento' => $cfg['contratante_documento'],
        ];
    }

    /**
     * Texto del contrato con las variables llenas. Con $marcar, cada variable va en <span data-var>
     * para que el formulario la actualice mientras el medio escribe.
     */
    public function render(?string $cuerpo, array $valores, bool $marcar = false): string
    {
        $html = self::limpiarHtml((string) $cuerpo);
        return preg_replace_callback('/\{\s*([a-z_]+)\s*\}/i', function ($m) use ($valores, $marcar) {
            $k = strtolower($m[1]);
            if (!array_key_exists($k, $valores)) return $m[0];
            $texto = e($valores[$k] !== '' ? $valores[$k] : '________');
            return $marcar ? '<span data-var="' . $k . '" class="var">' . $texto . '</span>' : $texto;
        }, $html);
    }

    /** Huella de un texto de contrato, sin contar espacios ni saltos de línea (para saber si alguien lo modificó). */
    public static function huella(string $html): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($html)) ?? '');
    }

    /** HTML del modelo de contrato: solo etiquetas de texto (lo escribe el administrador o se importa de Word). */
    public static function limpiarHtml(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|textarea|select)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|textarea|select)\b[^>]*/?>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*(javascript|data|vbscript):[^"\']*\2#i', '$1=$2#$2', $html);
        $html = strip_tags($html, '<p><br><b><strong><i><em><u><h2><h3><h4><ol><ul><li><table><thead><tbody><tr><th><td><blockquote><span><div><a><hr>');
        // Texto sin etiquetas (pegado como texto): un párrafo por línea
        if (!preg_match('#<(p|div|h[2-4]|ol|ul|table|br)\b#i', $html)) {
            $html = implode("\n", array_map(fn($l) => '<p>' . $l . '</p>', array_filter(array_map('trim', preg_split('/\R/u', $html)), fn($l) => $l !== '')));
        }
        return $html;
    }

    // ───────────────────────── Firmar ─────────────────────────

    /**
     * Firma el contrato: número consecutivo de la campaña, documentos, firma, el texto tal como se firmó y el PDF.
     * Luego envía la copia por correo. $archivos: logo, membrete, rut, certificado_bancario (UploadedFile|null).
     */
    public function firmar(CampaignParticipante $p, array $datos, array $archivos, string $firmaPng, string $ip = '', string $agente = ''): Contrato
    {
        $p->loadMissing('campaign');
        $c = $p->campaign;
        $m = $this->medioDe($p);
        if (!$m || !$m->tipo) throw new \RuntimeException(__('Primero elige tu medio del listado.'));
        if ($aviso = $m->avisoValor()) throw new \RuntimeException($aviso);
        $dir = 'contratos/' . $c->id;
        $guardados = [];
        $guardar = function (?UploadedFile $f, string $nombre) use ($dir, &$guardados) {
            if (!$f) return null;
            $ruta = $f->storeAs($dir, $nombre . '-' . Str::random(12) . '.' . (strtolower($f->extension() ?: $f->getClientOriginalExtension()) ?: 'bin'), self::DISCO);
            return $guardados[] = $ruta;
        };
        $firmaRuta = $dir . '/firma-' . Str::random(12) . '.png';
        Storage::disk(self::DISCO)->put($firmaRuta, $firmaPng);
        $guardados[] = $firmaRuta;

        $repetido = false;
        try {
            $contrato = DB::transaction(function () use ($p, $c, $m, $datos, $archivos, $guardar, $firmaRuta, $firmaPng, $ip, $agente, &$repetido) {
                // Un contrato a la vez por campaña: así el consecutivo no se repite
                Campaign::whereKey($c->id)->lockForUpdate()->first();
                // Doble clic en «Firmar»: el segundo envío encuentra el contrato recién firmado y no gasta otro número
                if ($ya = $this->contratoDe($p)) {
                    $repetido = true;
                    return $ya;
                }
                $numero = $this->siguienteNumero($c);
                $limpios = array_map(fn($x) => is_string($x) ? trim($x) : $x, array_intersect_key($datos, array_flip(
                    ['medio_nombre', 'razon_social', 'nit', 'representante', 'documento_tipo', 'documento', 'correo', 'telefono', 'direccion', 'ciudad',
                     'banco', 'tipo_cuenta', 'numero_cuenta', 'titular_cuenta', 'documento_titular'])));
                // Los canales los define el administrador; el medio escribe el dato de cada uno (dirección, usuario, frecuencia)
                $canales = [];
                foreach ($m->canales() as $k => $info) $canales[$k] = ['pauta' => $info['pauta'], 'dato' => mb_substr(trim((string) ($datos['canal'][$k] ?? '')), 0, 190)];
                $fecha = now();
                $cuerpo = $this->render($m->tipo->cuerpo, $this->valores($c, $m, $limpios + ['canal' => array_map(fn($x) => $x['dato'], $canales)], $numero, $fecha));
                return Contrato::create([
                    'campaign_id' => $c->id, 'medio_id' => $m->id, 'participante_id' => $p->id, 'user_id' => $p->user_id, 'tipo_id' => $m->tipo_id,
                    'numero' => $numero, 'codigo' => $this->codigo($c, $numero),
                    // También queda cómo estaba todo al firmar: valor, partes y contratante
                    'datos' => $limpios + ['sitio' => ($canales['web']['dato'] ?? '') ?: null, 'canales' => $canales, 'tipo_nombre' => $m->tipo->nombre,
                        'valor' => $m->valor ? (int) $m->valor : null, 'rol_medio' => $m->tipo->rolMedio(), 'rol_contratante' => $m->tipo->rolContratante(),
                        'contratante_datos' => array_intersect_key($this->config($c), self::MODELOS_CONTRATANTE)],
                    'logo_path' => $guardar($archivos['logo'] ?? null, 'logo'),
                    'membrete_path' => $guardar($archivos['membrete'] ?? null, 'membrete'),
                    'rut_path' => $guardar($archivos['rut'] ?? null, 'rut'),
                    'certificado_bancario_path' => $guardar($archivos['certificado_bancario'] ?? null, 'certificado-bancario'),
                    'firma_path' => $firmaRuta, 'firma_hash' => hash('sha256', $firmaPng),
                    'cuerpo' => $cuerpo, 'cuerpo_hash' => hash('sha256', $cuerpo),
                    'firmado_at' => $fecha, 'ip' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($agente, 0, 490),
                ]);
            });
        } catch (\Throwable $e) {
            foreach ($guardados as $r) Storage::disk(self::DISCO)->delete($r);
            throw $e;
        }
        if ($repetido) {
            foreach ($guardados as $r) Storage::disk(self::DISCO)->delete($r);
            return $contrato;
        }

        // El certificado arranca con los datos del contrato
        $p->forceFill(array_filter([
            'medio_nombre' => $contrato->dato('medio_nombre') ?: null,
            'representante' => $contrato->dato('representante') ?: null,
            'documento_tipo' => $contrato->dato('documento_tipo') ?: null,
            'documento' => $contrato->dato('documento') ?: null,
            'ciudad' => $contrato->dato('ciudad') ?: null,
        ]))->save();
        if ($contrato->logo_path && !$p->logo_path) {
            $copia = 'certificados/' . $c->id . '/logo-' . $p->id . '-' . Str::random(8) . '.' . pathinfo($contrato->logo_path, PATHINFO_EXTENSION);
            Storage::disk(self::DISCO)->copy($contrato->logo_path, $copia);
            $p->forceFill(['logo_path' => $copia])->save();
        }

        $this->guardarPdf($contrato);
        $this->enviar($contrato);
        return $contrato->fresh();
    }

    // ───────────────────────── PDF y correo ─────────────────────────

    public function datosPdf(Contrato $k): array
    {
        $k->loadMissing(['campaign', 'medio', 'tipo']);
        $cert = app(CertificadosService::class);
        $cfg = $this->config($k->campaign);
        return [
            'k' => $k, 'c' => $k->campaign,
            'logo' => $cert->dataUri($k->logo_path),
            'membrete' => $cert->dataUri($k->membrete_path),
            'firma' => $cert->dataUri($k->firma_path),
            'firmaContratante' => $cert->dataUri($cfg['contratante_firma']),
            'cfg' => $cfg,
            'documento' => trim($k->dato('documento_tipo', 'C.C.') . ' ' . $cert->formatoDocumento($k->dato('documento'))),
            // Lo que quedó al firmar: canales, partes, contratante y valor (si no quedó, lo de ahora)
            'canales' => $k->canales(),
            'membreteContratante' => $cert->dataUri($cfg['contratante_membrete']),
            'rolMedio' => $k->dato('rol_medio') ?: ($k->tipo?->rolMedio() ?? __('EL CONTRATISTA')),
            'rolContratante' => $k->dato('rol_contratante') ?: ($k->tipo?->rolContratante() ?? __('EL CONTRATANTE')),
            'contratante' => array_merge(array_intersect_key($cfg, self::MODELOS_CONTRATANTE), array_filter((array) (($k->datos ?? [])['contratante_datos'] ?? []))),
            'valor' => ($v = (int) (($k->datos ?? [])['valor'] ?? 0)) ? '$ ' . number_format($v, 0, ',', '.') : '',
        ];
    }

    public function pdfBinario(Contrato $k): string
    {
        // Solo las letras usadas de la fuente: el PDF pasa de ~900 KB a unas decenas de KB (va por correo)
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('contratos.pdf', $this->datosPdf($k))
            ->setPaper('letter')->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** El PDF se guarda al firmar (así queda igual aunque luego cambie la configuración). */
    public function guardarPdf(Contrato $k): void
    {
        try {
            $ruta = 'contratos/' . $k->campaign_id . '/contrato-' . Str::slug($k->codigo) . '-' . Str::random(8) . '.pdf';
            Storage::disk(self::DISCO)->put($ruta, $this->pdfBinario($k));
            $k->forceFill(['pdf_path' => $ruta])->save();
        } catch (\Throwable $e) {
            Log::warning('[contratos] no se pudo guardar el PDF', ['contrato' => $k->id, 'err' => $e->getMessage()]);
        }
    }

    public function pdf(Contrato $k): string
    {
        $disco = Storage::disk(self::DISCO);
        if ($k->pdf_path && $disco->exists($k->pdf_path)) {
            if (!$this->faltaFirmaContratante($k)) return $disco->get($k->pdf_path);
            // El medio firmó antes de que se cargara la firma del contratante: se rehace el PDF con ella (el texto
            // firmado no cambia: sale de lo que quedó guardado en el contrato)
            $anterior = $k->pdf_path;
            $this->guardarPdf($k);
            if ($k->pdf_path !== $anterior && $disco->exists($k->pdf_path)) {
                $disco->delete($anterior);
                return $disco->get($k->pdf_path);
            }
        }
        return $this->pdfBinario($k);
    }

    /** ¿El PDF guardado es anterior a la firma del contratante que está cargada ahora? */
    private function faltaFirmaContratante(Contrato $k): bool
    {
        $firma = $this->config($k->loadMissing('campaign')->campaign)['contratante_firma'];
        $disco = Storage::disk(self::DISCO);
        return $firma && $disco->exists($firma) && $disco->lastModified($firma) > $disco->lastModified($k->pdf_path);
    }

    public function nombreArchivo(Contrato $k): string
    {
        return self::nombreSeguro('Contrato ' . $k->codigo . ' - ' . Str::limit($k->dato('medio_nombre', $k->medio?->nombre ?? 'medio'), 60, '')) . '.pdf';
    }

    /** Nombre de archivo sin «/», «\», «:» ni otros caracteres que rompen la descarga (p. ej. un número «CPS-001/2026»). */
    public static function nombreSeguro(string $nombre): string
    {
        $limpio = preg_replace('#[\\\\/:*?"<>|\x00-\x1F\x7F]+#u', '-', $nombre) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $limpio) ?? '', ' .-') ?: 'contrato';
    }

    /** Content-Disposition de descarga con el nombre en UTF-8 y una versión ASCII para clientes viejos. */
    public static function descarga(string $nombre): string
    {
        $nombre = self::nombreSeguro($nombre);
        $ascii = preg_replace('/[^\x20-\x7E]/', '', str_replace('%', '', Str::ascii($nombre))) ?: 'contrato';
        return \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $nombre, $ascii);
    }

    /** Envía la copia del contrato firmado al correo del medio (y a las copias de la campaña). */
    public function enviar(Contrato $k): bool
    {
        $k->loadMissing(['campaign', 'medio']);
        $para = $k->dato('correo');
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            $k->forceFill(['envio_error' => __('El contrato no tiene un correo válido.')])->save();
            return false;
        }
        if (!NotificadorService::correoConfigurado()) {
            $k->forceFill(['envio_error' => __('El correo de editus no está configurado (MAIL_MAILER en el .env).')])->save();
            return false;
        }
        try {
            $copias = $this->config($k->campaign)['copia_a'];
            $correo = Mail::to($para);
            if ($copias) $correo->bcc($copias);
            $correo->send(new \App\Mail\ContratoFirmado($k, $this->pdf($k), $this->nombreArchivo($k)));
            $k->forceFill(['enviado_at' => now(), 'envio_error' => null])->save();
            return true;
        } catch (\Throwable $e) {
            Log::warning('[contratos] no se pudo enviar el correo', ['contrato' => $k->id, 'err' => $e->getMessage()]);
            $k->forceFill(['envio_error' => Str::limit($e->getMessage(), 490, '')])->save();
            return false;
        }
    }

    /** El administrador anula un contrato: el medio debe firmar otro (con un número nuevo). */
    public function anular(Contrato $k): void
    {
        $k->forceFill(['anulado_at' => now()])->save();
    }

    // ───────────────────────── Listado de medios ─────────────────────────

    /**
     * Medios del listado que ya tienen actividad en la campaña: publicaciones o en vivos en sus páginas, reportes de
     * TikTok o banners, o cuñas de radio. Esos ya no se pueden borrar, solo deshabilitar. Devuelve sus ids.
     */
    public function medioIdsConActividad(Campaign $c, ?array $soloMedios = null): array
    {
        $medios = MedioCampana::where('campaign_id', $c->id)->whereNotNull('participante_id')
            ->when($soloMedios !== null, fn($q) => $q->whereIn('id', $soloMedios))->pluck('id', 'participante_id');
        if ($medios->isEmpty()) return [];
        $participantes = CampaignParticipante::whereIn('id', $medios->keys())->get(['id', 'user_id', 'meta_page_ids']);
        $conActividad = [];
        // Piezas y en vivos publicados para la campaña en las páginas de cada medio
        $paginasDe = DB::table('meta_page_user')->whereIn('user_id', $participantes->pluck('user_id'))->get(['user_id', 'meta_page_id'])
            ->groupBy('user_id')->map(fn($g) => $g->pluck('meta_page_id')->map(fn($v) => (int) $v)->all());
        $paginas = [];
        foreach ($participantes as $p) {
            $suyas = $paginasDe[$p->user_id] ?? [];
            $elegidas = array_map('intval', (array) $p->meta_page_ids);
            $paginas[$p->id] = $elegidas ? array_values(array_intersect($suyas, $elegidas)) : $suyas;
        }
        $todas = array_values(array_unique(array_merge([], ...array_values($paginas))));
        $conPublicaciones = $todas ? \App\Models\MetaPost::where('campaign_id', $c->id)->whereIn('meta_page_id', $todas)->where('status', 'success')
            ->distinct()->pluck('meta_page_id')->map(fn($v) => (int) $v)->all() : [];
        foreach ($paginas as $pid => $ids) if (array_intersect($ids, $conPublicaciones)) $conActividad[$pid] = true;
        // Lo que reportó el propio medio: TikTok, banners y radio
        if (ReportesMedioService::disponible()) {
            foreach (\App\Models\Producto::whereIn('participante_id', $medios->keys())->distinct()->pluck('participante_id') as $pid) $conActividad[$pid] = true;
        }
        if (EmisionesRadioService::disponible()) {
            foreach (\App\Models\EmisionRadio::whereIn('participante_id', $medios->keys())->distinct()->pluck('participante_id') as $pid) $conActividad[$pid] = true;
        }
        return $medios->only(array_keys($conActividad))->values()->map(fn($v) => (int) $v)->all();
    }

    public function tieneActividad(MedioCampana $m): bool
    {
        return $m->participante_id && $this->medioIdsConActividad($m->campaign, [$m->id]) !== [];
    }

    /**
     * Borra un medio que no tiene actividad. Si alguien ya había firmado su contrato, el contrato queda anulado
     * (se guarda, con su número, para el registro) y esa persona tendrá que elegir su medio y firmar de nuevo.
     */
    public function borrarMedio(MedioCampana $m): void
    {
        DB::transaction(function () use ($m) {
            Contrato::where('medio_id', $m->id)->whereNull('anulado_at')->update(['anulado_at' => now()]);
            $m->delete();
        });
        app(CertificadosService::class)->sincronizarPaginas($m->campaign);
    }

    /** ¿El medio del participante está habilitado? (sin medio del listado, sí: campañas sin contratos) */
    public function medioActivo(CampaignParticipante $p): bool
    {
        $m = $this->medioDe($p);
        return !$m || $m->activo;
    }

    /**
     * Participantes cuyas páginas entran a la campaña: con listado de medios, solo los que tienen un medio habilitado
     * (un medio deshabilitado o borrado deja de recibir publicaciones). Sin listado, null: todos, como antes.
     */
    public function participantesQuePublican(Campaign $c): ?array
    {
        if (!self::disponible() || !MedioCampana::where('campaign_id', $c->id)->exists()) return null;
        return MedioCampana::where('campaign_id', $c->id)->where('activo', true)->whereNotNull('participante_id')
            ->pluck('participante_id')->map(fn($v) => (int) $v)->all();
    }

    /**
     * Agrega medios en bloque: una línea por medio, «Nombre; correo; valor» (el correo y el valor son opcionales).
     * Todos quedan con el mismo tipo de contrato y los mismos canales ($canales de MedioCampana::desdeFormulario).
     * Devuelve cuántos se agregaron (los repetidos se omiten).
     */
    public function agregarEnBloque(Campaign $c, string $texto, ?int $tipoId, array $canales): int
    {
        $existentes = MedioCampana::where('campaign_id', $c->id)->pluck('nombre')->map(fn($n) => mb_strtolower(trim($n)))->flip();
        $nuevos = [];
        foreach (preg_split('/\R/u', $texto) as $linea) {
            $partes = array_map('trim', preg_split('/[;\t]/', $linea));
            $nombre = mb_substr($partes[0] ?? '', 0, 190);
            if ($nombre === '' || $existentes->has(mb_strtolower($nombre)) || isset($nuevos[mb_strtolower($nombre)])) continue;
            $otros = array_slice($partes, 1);
            $correo = collect($otros)->first(fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
            $valor = collect($otros)->filter(fn($x) => preg_match('/^\$?\s*[\d.,\s]+$/', $x))->map(fn($x) => MedioCampana::leerValor($x))->filter()->first();
            $nuevos[mb_strtolower($nombre)] = ['nombre' => $nombre, 'correo' => $correo, 'valor' => $valor];
        }
        // Si el contrato divide el valor en dos cuotas iguales, cada valor tiene que ser par
        $tipo = $tipoId ? ContratoTipo::find($tipoId) : null;
        $impares = collect($nuevos)->filter(fn($x) => $x['valor'] && $x['valor'] % 2 && $tipo?->usaMitad())->pluck('nombre');
        if ($impares->isNotEmpty()) throw ValidationException::withMessages([
            'lista' => __('Este contrato divide el valor en dos cuotas mensuales iguales y estos medios tienen un valor impar: :m.', ['m' => $impares->implode(', ')]),
        ]);
        $orden = (int) MedioCampana::where('campaign_id', $c->id)->max('orden');
        foreach ($nuevos as $x) {
            MedioCampana::create(['campaign_id' => $c->id, 'nombre' => $x['nombre'], 'canales' => $canales, 'tipo_id' => $tipoId, 'valor' => $x['valor'],
                'correo' => $x['correo'], 'activo' => true, 'orden' => ++$orden]);
        }
        return count($nuevos);
    }
}
