<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\Contrato;
use App\Models\ContratoTipo;
use App\Models\MedioCampana;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Contratos de los medios de una campaña.
 *
 * El administrador carga el listado de medios y les asigna un tipo de contrato (un texto con variables).
 * Al inscribirse con el enlace de la campaña, el medio elige cuál es, llena el encabezado (logo, membrete,
 * nombre, representante legal, identificación, RUT, certificado bancario, correo), firma y recibe su copia
 * en PDF por correo. Cada contrato lleva un número consecutivo de la campaña. Hasta que no lo firme no puede
 * vincular su medio (certificado y páginas).
 */
class ContratosService
{
    public const DISCO = 'local';

    /** Variables que se pueden usar en el texto de un contrato. */
    public const VARIABLES = [
        'numero' => 'Número del contrato',
        'fecha' => 'Fecha de la firma (en letras)',
        'fecha_corta' => 'Fecha de la firma (dd/mm/aaaa)',
        'medio' => 'Nombre del medio',
        'clase_medio' => 'Medio digital / página web / emisora…',
        'sitio' => 'Página web o frecuencia',
        'nit' => 'NIT del medio',
        'representante' => 'Representante legal',
        'documento_tipo' => 'Tipo de documento del representante',
        'documento' => 'Número de documento del representante',
        'correo' => 'Correo',
        'telefono' => 'Teléfono',
        'direccion' => 'Dirección',
        'ciudad' => 'Ciudad',
        'campana' => 'Nombre de la campaña',
        'contratante' => 'Contratante',
        'contratante_nit' => 'NIT del contratante',
        'contratante_representante' => 'Representante del contratante',
    ];

    public static function disponible(): bool
    {
        // Se mira en cada pantalla (middleware): se recuerda solo el «sí» y solo en esta instancia de la aplicación
        if (app()->bound('contratos.disponible')) return true;
        if (!Schema::hasTable('campaign_contratos') || !Schema::hasColumn('campaigns', 'contratos')) return false;
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
            'copia_a' => array_values(array_filter(array_map('trim', (array) ($cfg['copia_a'] ?? [])))),
        ];
    }

    public function guardarConfig(Campaign $c, array $d, ?UploadedFile $firma = null, bool $quitarFirma = false): void
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
            'copia_a' => $correos,
        ];
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
        return [
            'numero' => $numero ? $this->codigo($c, $numero) : __('(se asigna al firmar)'),
            'fecha' => $cert->fechaEnLetras($fecha),
            'fecha_corta' => $fecha->format('d/m/Y'),
            'medio' => $v('medio_nombre') ?: $m->nombre,
            'clase_medio' => mb_strtolower($m->claseTexto()),
            'sitio' => $v('sitio'),
            'nit' => $v('nit'),
            'representante' => $v('representante'),
            'documento_tipo' => $v('documento_tipo') ?: 'C.C.',
            'documento' => $v('documento') !== '' ? $cert->formatoDocumento($v('documento')) : '',
            'correo' => $v('correo'),
            'telefono' => $v('telefono'),
            'direccion' => $v('direccion'),
            'ciudad' => $v('ciudad'),
            'campana' => $c->name,
            'contratante' => $cfg['contratante'],
            'contratante_nit' => $cfg['contratante_nit'],
            'contratante_representante' => $cfg['contratante_representante'],
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
                    ['medio_nombre', 'sitio', 'nit', 'representante', 'documento_tipo', 'documento', 'correo', 'telefono', 'direccion', 'ciudad'])));
                $fecha = now();
                $cuerpo = $this->render($m->tipo->cuerpo, $this->valores($c, $m, $limpios, $numero, $fecha));
                return Contrato::create([
                    'campaign_id' => $c->id, 'medio_id' => $m->id, 'participante_id' => $p->id, 'user_id' => $p->user_id, 'tipo_id' => $m->tipo_id,
                    'numero' => $numero, 'codigo' => $this->codigo($c, $numero), 'datos' => $limpios + ['tipo_nombre' => $m->tipo->nombre, 'clase_medio' => $m->claseTexto()],
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
        $k->loadMissing(['campaign', 'medio']);
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
        if ($k->pdf_path && Storage::disk(self::DISCO)->exists($k->pdf_path)) return Storage::disk(self::DISCO)->get($k->pdf_path);
        return $this->pdfBinario($k);
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
     * Agrega medios en bloque: una línea por medio. Cada línea puede traer «Nombre; correo».
     * Devuelve cuántos se agregaron (los repetidos se omiten).
     */
    public function agregarEnBloque(Campaign $c, string $texto, ?int $tipoId, ?string $clase): int
    {
        $existentes = MedioCampana::where('campaign_id', $c->id)->pluck('nombre')->map(fn($n) => mb_strtolower(trim($n)))->flip();
        $orden = (int) MedioCampana::where('campaign_id', $c->id)->max('orden');
        $n = 0;
        foreach (preg_split('/\R/u', $texto) as $linea) {
            $partes = array_map('trim', preg_split('/[;\t]/', $linea));
            $nombre = mb_substr($partes[0] ?? '', 0, 190);
            if ($nombre === '' || $existentes->has(mb_strtolower($nombre))) continue;
            $correo = collect(array_slice($partes, 1))->first(fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
            MedioCampana::create(['campaign_id' => $c->id, 'nombre' => $nombre, 'clase' => $clase, 'tipo_id' => $tipoId, 'correo' => $correo, 'activo' => true, 'orden' => ++$orden]);
            $existentes->put(mb_strtolower($nombre), true);
            $n++;
        }
        return $n;
    }
}
