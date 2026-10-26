<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Inscripción de medios a una campaña (enlace propio) y su certificado de publicación:
 * logo y datos del medio, texto parametrizado por campaña, tabla de piezas que se llena
 * sola con lo publicado en las páginas del medio, y firma digital del representante.
 */
class CertificadosService
{
    public const DISCO = 'local';
    public const TIPOS_PIEZA = ['Video Corto', 'Video Largo', 'Post', 'Carrusel', 'Historia', 'Reel', 'Nota web'];

    public const TEXTO_DEFECTO = 'Que en el medio digital {medio}, se publicó a través de EDITUS por el tiempo comprendido {periodo}, las siguientes piezas alusivas a la {entidad}, según orden de servicios No. {orden} entre {contratista} y el {contratante}:';
    public const CIERRE_DEFECTO = 'Se expide y se firma esta certificación a solicitud del interesado en {ciudad}, {fecha}.';
    public const TERMINOS_DEFECTO = "1. Declaro que la información que registro (nombre del medio, logo, representante, documento y cargo) es veraz y que tengo facultad para firmar en nombre del medio.\n"
        . "2. Autorizo a EDITUS a usar mi firma digital y el logo del medio únicamente para generar las certificaciones de publicación de la campaña «{campana}».\n"
        . "3. Acepto que la tabla de piezas de la certificación se llena con las publicaciones de la campaña registradas en EDITUS en las páginas de mi medio.\n"
        . "4. Autorizo el tratamiento de mis datos personales conforme a la Ley 1581 de 2012 y a la política de privacidad de EDITUS, solo para esta finalidad.\n"
        . "5. La firma que dibujo en esta pantalla tiene la misma validez que mi firma manuscrita para esta certificación.";

    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    private const DIAS = [1 => 'primer', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce',
        'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', 'veintiún', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
        'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve', 'treinta', 'treinta y un'];

    public static function disponible(): bool
    {
        // Se recuerda solo el «sí» y solo en esta instancia de la aplicación (antes de migrar se vuelve a mirar)
        if (app()->bound('certificados.disponible')) return true;
        if (!Schema::hasTable('campaign_participantes')) return false;
        app()->instance('certificados.disponible', true);
        return true;
    }

    // ───────────────────────── Configuración por campaña ─────────────────────────

    /** Datos del certificado de la campaña, con los textos por defecto donde no se ha escrito nada. */
    public function config(Campaign $c): array
    {
        $cfg = (array) ($c->certificado ?? []);
        return [
            'entidad' => trim((string) ($cfg['entidad'] ?? '')),
            'orden' => trim((string) ($cfg['orden'] ?? '')),
            'contratista' => trim((string) ($cfg['contratista'] ?? '')),
            'contratante' => trim((string) ($cfg['contratante'] ?? '')),
            'texto' => trim((string) ($cfg['texto'] ?? '')) ?: self::TEXTO_DEFECTO,
            'cierre' => trim((string) ($cfg['cierre'] ?? '')) ?: self::CIERRE_DEFECTO,
            'terminos' => trim((string) ($cfg['terminos'] ?? '')) ?: self::TERMINOS_DEFECTO,
            'fecha_expedicion' => ($cfg['fecha_expedicion'] ?? null) ?: null,
            'tipos_pieza' => array_values(array_filter((array) ($cfg['tipos_pieza'] ?? []))) ?: self::TIPOS_PIEZA,
            'obligatorio' => (bool) ($cfg['obligatorio'] ?? true),
        ];
    }

    /** Guarda lo que el administrador escribió en la sección «Inscripción y certificado» del formulario de la campaña. */
    public function guardarConfig(Campaign $c, array $d): void
    {
        if (!Schema::hasColumn('campaigns', 'certificado')) return; // sin migrar todavía
        $lineas = fn($t) => array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', (string) $t) ?: [])));
        $piezas = [];
        foreach (preg_split('/\r?\n/', (string) ($d['cert_piezas'] ?? '')) ?: [] as $l) {
            $partes = array_map('trim', explode('|', $l, 2));
            if (($partes[0] ?? '') === '') continue;
            $piezas[] = ['programa' => Str::limit($partes[0], 185, ''), 'subprograma' => Str::limit($partes[1] ?? '', 185, '')];
        }
        $texto = trim((string) ($d['cert_texto'] ?? ''));
        $cierre = trim((string) ($d['cert_cierre'] ?? ''));
        $terminos = trim((string) ($d['cert_terminos'] ?? ''));
        $c->certificado = [
            'entidad' => trim((string) ($d['cert_entidad'] ?? '')),
            'orden' => trim((string) ($d['cert_orden'] ?? '')),
            'contratista' => trim((string) ($d['cert_contratista'] ?? '')),
            'contratante' => trim((string) ($d['cert_contratante'] ?? '')),
            // Si quedó igual al texto por defecto no se guarda: así un cambio futuro del texto base le llega
            'texto' => $texto === self::TEXTO_DEFECTO ? '' : $texto,
            'cierre' => $cierre === self::CIERRE_DEFECTO ? '' : $cierre,
            'terminos' => str_replace("\r\n", "\n", $terminos) === self::TERMINOS_DEFECTO ? '' : $terminos,
            'fecha_expedicion' => ($d['cert_fecha_expedicion'] ?? null) ?: null,
            'tipos_pieza' => $lineas($d['cert_tipos_pieza'] ?? '') ?: [],
            'obligatorio' => (bool) ($d['cert_obligatorio'] ?? false),
        ];
        $c->piezas = $piezas;
        $c->inscripcion_activa = (bool) ($d['inscripcion_activa'] ?? false);
        if ($c->inscripcion_activa && !$c->inscripcion_token) $c->inscripcion_token = $this->nuevoToken();
        $c->save();
    }

    public function nuevoToken(): string
    {
        do {
            $t = Str::random(40);
        } while (Campaign::where('inscripcion_token', $t)->exists());
        return $t;
    }

    public function porToken(string $token): ?Campaign
    {
        if (strlen($token) < 20) return null;
        return Campaign::where('inscripcion_token', $token)->where('inscripcion_activa', true)
            ->where('is_active', true)->where('is_system', false)->first();
    }

    // ───────────────────────── Inscripción ─────────────────────────

    public function inscribir(Campaign $c, User $u): CampaignParticipante
    {
        $p = CampaignParticipante::firstOrCreate(['campaign_id' => $c->id, 'user_id' => $u->id]);
        if (!$p->medio_nombre && !$p->representante) {
            // Datos de partida: la página que el usuario ya tenga conectada y su nombre
            $pagina = $u->metaPages()->orderBy('name')->first();
            $p->fill(['medio_nombre' => $pagina?->name, 'representante' => $u->name])->save();
        }
        return $p;
    }

    /** Certificado que el usuario debe firmar antes de seguir (null si no tiene ninguno pendiente). */
    public function pendiente(User $u): ?CampaignParticipante
    {
        if (!self::disponible()) return null;
        return CampaignParticipante::where('user_id', $u->id)->whereNull('firmado_at')
            ->whereHas('campaign', fn($q) => $q->where('is_active', true)->where('inscripcion_activa', true))
            ->with('campaign')->oldest()->get()
            ->first(fn($p) => $this->config($p->campaign)['obligatorio']);
    }

    /** Páginas del medio: las que eligió el participante (si siguen siendo suyas) o todas las que tiene conectadas. */
    public function paginas(CampaignParticipante $p): Collection
    {
        $suyas = MetaPage::whereIn('id', DB::table('meta_page_user')->where('user_id', $p->user_id)->pluck('meta_page_id'))->orderBy('name')->get();
        $elegidas = array_map('intval', (array) $p->meta_page_ids);
        return $elegidas ? $suyas->whereIn('id', $elegidas)->values() : $suyas;
    }

    /** Las páginas de los medios inscritos quedan en la campaña (marcadas al publicar en ella). */
    public function sincronizarPaginas(Campaign $c): void
    {
        app(CampanasService::class)->sincronizarPerfil($c->fresh());
    }

    /** Guarda la firma dibujada (data URL PNG). Devuelve la ruta en el disco privado o null si no es válida. */
    public function guardarFirma(CampaignParticipante $p, string $dataUrl): ?string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) return null;
        $png = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($png === false || strlen($png) < 200 || strlen($png) > 1_500_000) return null;
        $info = @getimagesizefromstring($png);
        if (!$info || ($info[2] ?? null) !== IMAGETYPE_PNG) return null;
        $ruta = 'certificados/' . $p->campaign_id . '/firma-' . $p->id . '-' . Str::random(8) . '.png';
        Storage::disk(self::DISCO)->put($ruta, $png);
        $p->firma_hash = hash('sha256', $png);
        return $ruta;
    }

    // ───────────────────────── Contenido del certificado ─────────────────────────

    /**
     * Piezas publicadas para la campaña en las páginas del medio: una fila por cada combinación
     * programa / subprograma / tipo de pieza (con cuántas publicaciones la componen).
     */
    public function piezas(CampaignParticipante $p): Collection
    {
        $ids = $this->paginas($p)->pluck('id')->all();
        if (!$ids) return collect();
        $posts = MetaPost::where('campaign_id', $p->campaign_id)->whereIn('meta_page_id', $ids)
            ->where('status', 'success')->orderBy('published_at')->orderBy('id')
            ->get(['id', 'type', 'network', 'programa', 'subprograma', 'tipo_pieza', 'published_at']);
        return $posts->groupBy(fn($x) => mb_strtolower(trim(($x->programa ?? '') . '|' . ($x->subprograma ?? '') . '|' . $this->tipoPieza($x))))
            ->map(fn($g) => [
                'programa' => trim((string) $g->first()->programa) ?: '—',
                'subprograma' => trim((string) $g->first()->subprograma) ?: '—',
                'tipo' => $this->tipoPieza($g->first()),
                'cantidad' => $g->count(),
            ])->values();
    }

    public function tipoPieza(MetaPost $x): string
    {
        if ($t = trim((string) $x->tipo_pieza)) return $t;
        return match ($x->type) {
            'video' => 'Video',
            'photo' => $x->network === 'instagram' ? 'Post Instagram' : 'Post',
            default => 'Post',
        };
    }

    /** Todo lo que pinta el certificado (vista web y PDF). */
    public function datos(CampaignParticipante $p): array
    {
        $c = $p->campaign;
        $cfg = $this->config($c);
        $medio = trim((string) $p->medio_nombre) ?: '—';
        $reemplazos = [
            '{medio}' => mb_strtoupper($medio),
            '{periodo}' => $this->periodo($c),
            '{entidad}' => $cfg['entidad'] ?: '—',
            '{orden}' => $cfg['orden'] ?: '—',
            '{contratista}' => $cfg['contratista'] ?: '—',
            '{contratante}' => $cfg['contratante'] ?: '—',
            '{campana}' => $c->name,
            '{ciudad}' => trim((string) $p->ciudad) ?: '—',
            '{fecha}' => $this->fechaEnLetras($this->fechaExpedicion($c)),
        ];
        // El texto se escapa primero y luego se resaltan en negrita el medio y la entidad
        $html = fn(string $t) => strtr(e($t), array_map(fn($v) => e($v), $reemplazos));
        $cuerpo = $html($cfg['texto']);
        foreach (['{medio}', '{entidad}'] as $k) {
            $v = e($reemplazos[$k]);
            if ($v !== '' && $v !== '—') $cuerpo = str_replace($v, '<strong>' . $v . '</strong>', $cuerpo);
        }
        return [
            'participante' => $p,
            'campana' => $c,
            'logo' => $this->dataUri($p->logo_path),
            'firma' => $this->dataUri($p->firma_path),
            'cuerpo' => $cuerpo,
            'cierre' => $html($cfg['cierre']),
            'piezas' => $this->piezas($p),
            'nombre' => mb_strtoupper(trim((string) $p->representante) ?: '—'),
            'documento' => trim(($p->documento_tipo ?: 'C.C.') . ' ' . $this->formatoDocumento((string) $p->documento)),
            'cargo' => mb_strtoupper(trim((string) $p->cargo)),
        ];
    }

    public function terminos(Campaign $c): string
    {
        return str_replace('{campana}', $c->name, $this->config($c)['terminos']);
    }

    public function periodo(Campaign $c): string
    {
        $a = $c->starts_on;
        $b = $c->ends_on;
        if (!$a && !$b) return 'durante la vigencia de la campaña';
        if ($a && !$b) return 'desde el ' . $this->fecha($a);
        if (!$a && $b) return 'hasta el ' . $this->fecha($b);
        if ($a->isSameMonth($b)) return 'entre el ' . $a->format('d') . ' y ' . $b->format('d') . ' de ' . self::MESES[$b->month] . ' de ' . $b->year;
        if ($a->year === $b->year) return 'entre el ' . $a->format('d') . ' de ' . self::MESES[$a->month] . ' y el ' . $this->fecha($b);
        return 'entre el ' . $this->fecha($a) . ' y el ' . $this->fecha($b);
    }

    public function fechaExpedicion(Campaign $c): Carbon
    {
        $f = $this->config($c)['fecha_expedicion'];
        try {
            return $f ? Carbon::parse($f) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    /** «al primer (01) día del mes de diciembre de 2025» / «a los quince (15) días del mes de …». */
    public function fechaEnLetras(Carbon $f): string
    {
        $dia = self::DIAS[$f->day];
        $mes = self::MESES[$f->month];
        return $f->day === 1
            ? "al primer (01) día del mes de {$mes} de {$f->year}"
            : "a los {$dia} ({$f->format('d')}) días del mes de {$mes} de {$f->year}";
    }

    private function fecha(Carbon $f): string
    {
        return $f->format('d') . ' de ' . self::MESES[$f->month] . ' de ' . $f->year;
    }

    /** 36276152 → 36.276.152 (si no son solo números, se deja como está). */
    public function formatoDocumento(string $d): string
    {
        $d = trim($d);
        return preg_match('/^\d{5,}$/', $d) ? number_format((int) $d, 0, ',', '.') : $d;
    }

    public function dataUri(?string $ruta): ?string
    {
        if (!$ruta || !Storage::disk(self::DISCO)->exists($ruta)) return null;
        $bin = Storage::disk(self::DISCO)->get($ruta);
        $info = @getimagesizefromstring($bin);
        $mime = $info['mime'] ?? 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }

    public function pdf(CampaignParticipante $p)
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('certificados.pdf', $this->datos($p))
            ->setPaper('letter', 'portrait')
            ->setOptions(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false, 'dpi' => 96]);
    }

    public function nombreArchivo(CampaignParticipante $p): string
    {
        return 'certificado-' . Str::slug(Str::limit((string) ($p->medio_nombre ?: 'medio'), 60, '')) . '-' . Str::slug($p->campaign->name) . '.pdf';
    }
}
