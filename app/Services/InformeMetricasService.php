<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Producto;
use App\Models\Programa;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Informe de métricas del plan de medios (como «Capítulo II. Programa 2 – Métricas Editus»):
 * por programa (capítulo) y tipo de publicación (sección), cada pieza con su descripción, programa,
 * subprograma, fechas, actas, muestra y, por medio, alcance / visualización / interacción / URL y captura.
 * Sale en Word (editable) y PDF. También el Excel del control de productos y su importación.
 */
class InformeMetricasService
{
    public const AMARILLO = 'FFD966';

    public function __construct(private ProductosService $productos)
    {
    }

    // ───────────────────────── Datos ─────────────────────────

    /**
     * $f: agrupar (programa | subcampana), programa_id (null = todos), subcampana_ids y tipo_ids (vacío = todos),
     *     desde, hasta (fechas de publicación),
     *     corte (fecha de corte de las métricas), titulo, resumen (bool).
     */
    public function datos(Campaign $c, array $f = []): array
    {
        $corte = !empty($f['corte']) ? Carbon::parse($f['corte']) : now();
        $desde = !empty($f['desde']) ? Carbon::parse($f['desde'])->startOfDay() : null;
        $hasta = !empty($f['hasta']) ? Carbon::parse($f['hasta'])->endOfDay() : null;
        $tipoIds = array_map('intval', array_filter((array) ($f['tipo_ids'] ?? [])));
        $subIds = array_map('intval', array_filter((array) ($f['subcampana_ids'] ?? [])));
        $porSub = ($f['agrupar'] ?? 'programa') === 'subcampana';

        $tipos = TipoPublicacion::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get()
            ->when($tipoIds, fn($x) => $x->whereIn('id', $tipoIds))->values();
        $productos = Producto::with('tipo', 'subcampana', 'programa')->where('campaign_id', $c->id)
            ->whereIn('tipo_id', $tipos->pluck('id'))
            ->when($subIds, fn($q) => $q->whereIn('subcampana_id', $subIds))
            ->when(!empty($f['programa_id']), fn($q) => $q->where('programa_id', (int) $f['programa_id']))
            ->orderBy('numero')->get();

        // Capítulos: por programa del contrato (como el informe de la Secretaría) o por subcampaña
        if ($porSub) {
            $grupos = Subcampana::with('programaContrato')->where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get()
                ->when($subIds, fn($x) => $x->whereIn('id', $subIds))->values()
                ->map(fn($s) => ['clave' => $s->id, 'titulo' => mb_strtoupper('Subcampaña ' . $s->nombre), 'programa' => $s->programaContrato, 'subcampana' => $s]);
            if (!$subIds) $grupos->push(['clave' => null, 'titulo' => 'PIEZAS SIN SUBCAMPAÑA', 'programa' => null, 'subcampana' => null]);
            $campo = 'subcampana_id';
        } else {
            $progs = Programa::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get();
            if (!empty($f['programa_id'])) $progs = $progs->where('id', (int) $f['programa_id'])->values();
            $grupos = $progs->map(fn($p) => ['clave' => $p->id, 'titulo' => mb_strtoupper('Programa ' . $p->etiqueta()), 'programa' => $p, 'subcampana' => null]);
            if (empty($f['programa_id'])) $grupos->push(['clave' => null, 'titulo' => 'PIEZAS SIN PROGRAMA', 'programa' => null, 'subcampana' => null]);
            $campo = 'programa_id';
        }

        $capitulos = [];
        foreach ($grupos as $g) {
            $secciones = [];
            foreach ($tipos as $t) {
                $piezas = [];
                foreach ($productos->where('tipo_id', $t->id)->where($campo, $g['clave']) as $p) {
                    $pubs = $this->productos->publicaciones($p);
                    if ($pubs->isEmpty()) continue;
                    $fecha = $this->productos->fechaPublicacion($p, $pubs);
                    if ($desde && (!$fecha || $fecha->lt($desde))) continue;
                    if ($hasta && (!$fecha || $fecha->gt($hasta))) continue;
                    $piezas[] = ['producto' => $p, 'pubs' => $pubs, 'fecha' => $fecha];
                }
                if (!$piezas) continue;
                usort($piezas, fn($a, $b) => [$a['fecha']?->timestamp ?? PHP_INT_MAX, $a['producto']->numero] <=> [$b['fecha']?->timestamp ?? PHP_INT_MAX, $b['producto']->numero]);
                $secciones[] = [
                    'tipo' => $t,
                    'titulo' => $t->tituloInforme(),
                    'piezas' => array_map(fn($x, $i) => $this->pieza($x['producto'], $x['pubs'], $x['fecha'], $i + 1, $corte), $piezas, array_keys($piezas)),
                ];
            }
            if (!$secciones) continue;
            $capitulos[] = [
                'programa' => $g['programa'],
                'subcampana' => $g['subcampana'],
                'titulo' => $g['titulo'],
                'secciones' => $secciones,
                'resumen' => array_map(fn($sec) => ['titulo' => $sec['titulo'], 'piezas' => count($sec['piezas'])] + $this->sumar(array_column($sec['piezas'], 'totales')), $secciones),
            ];
        }
        return [
            'campana' => $c,
            'titulo' => trim((string) ($f['titulo'] ?? '')),
            'corte' => $corte,
            'capitulos' => $capitulos,
            'con_resumen' => (bool) ($f['resumen'] ?? true),
            'membrete' => $this->membrete($c),
            'total' => $this->sumar(array_merge([], ...array_column($capitulos, 'resumen'))),
        ];
    }

    private function pieza(Producto $p, Collection $pubs, ?Carbon $fecha, int $n, Carbon $corte): array
    {
        $s = $p->tipo;
        $clase = $s?->clase ?: 'redes';
        $tot = $this->productos->totales($pubs);
        $datos = [];
        if ($p->programa) $datos[] = ['PROGRAMA', rtrim($p->programa->etiqueta(), '.') . '.'];
        if ($p->subprograma) $datos[] = ['SUBPROGRAMA', rtrim($p->subprograma, '.') . '.'];
        if ($clase === 'web') {
            $datos[] = ['FECHA', $this->productos->rango($fecha, $p->fecha_fin, false) . '.'];
        } else {
            $datos[] = [$clase === 'envivo' ? 'FECHA EMISIÓN' : 'FECHA PUBLICACIÓN', $this->productos->fecha($fecha) . '.'];
            if (!in_array($clase, ['impreso'], true)) $datos[] = ['FECHA MÉTRICAS', $this->productos->rango($fecha, $corte) . '.'];
        }
        if ($p->acta_aprobacion) $datos[] = ['ACTA DE APROBACIÓN', 'No. ' . rtrim($p->acta_aprobacion, '.') . '.'];
        if ($p->acta_publicacion) $datos[] = ['ACTA DE PUBLICACIÓN', 'No. ' . rtrim($p->acta_publicacion, '.') . '.'];
        return [
            'producto' => $p,
            'clase' => $clase,
            'etiqueta' => ($s?->etiquetaSingular() ?: 'Pieza') . ' No. ' . $n . ':',
            'descripcion' => $this->productos->descripcion($p, $tot['medios']),
            'datos' => $datos,
            'muestra' => $clase !== 'envivo' && $p->muestra_path ? [ProductosService::DISCO, $p->muestra_path] : null,
            'pubs' => $pubs,
            'totales' => $tot,
        ];
    }

    private function sumar(array $filas): array
    {
        $out = ['publicaciones' => 0, 'alcance' => 0, 'visualizaciones' => 0, 'interacciones' => 0, 'piezas' => 0];
        foreach ($filas as $f) foreach ($out as $k => $_) $out[$k] += (int) ($f[$k] ?? 0);
        return $out;
    }

    public function membrete(Campaign $c): array
    {
        $m = (array) ($c->informe ?? []);
        return [
            'fondo' => !empty($m['fondo_path']) ? [ProductosService::DISCO, $m['fondo_path']] : null,
            'encabezado' => !empty($m['encabezado_path']) ? [ProductosService::DISCO, $m['encabezado_path']] : null,
            'pie' => !empty($m['pie_path']) ? [ProductosService::DISCO, $m['pie_path']] : null,
            'pie_texto' => trim((string) ($m['pie_texto'] ?? '')),
            'titulo' => trim((string) ($m['titulo'] ?? '')),
        ];
    }

    public function numero(int $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    // ───────────────────────── Imágenes ─────────────────────────

    /**
     * Imagen lista para el documento: JPG reducido (máx. $max px) en caché. Devuelve [ruta absoluta, ancho, alto] o null.
     * @param array{0: string, 1: string}|null $ref [disco, ruta]
     */
    public function imagen(?array $ref, int $max = 1400): ?array
    {
        if (!$ref) return null;
        [$disco, $ruta] = $ref;
        try {
            $d = Storage::disk($disco);
            if (!$ruta || !$d->exists($ruta)) return null;
            $cacheRel = 'cache/informes/' . sha1($disco . '|' . $ruta . '|' . $d->lastModified($ruta) . '|' . $max) . '.jpg';
            $cache = Storage::disk(ProductosService::DISCO);
            if (!$cache->exists($cacheRel)) {
                $img = Image::read($d->get($ruta))->scaleDown($max, $max);
                $cache->put($cacheRel, (string) $img->blendTransparency('ffffff')->toJpeg(80));
            }
            $abs = $cache->path($cacheRel);
            [$w, $h] = getimagesize($abs) ?: [0, 0];
            return $w && $h ? [$abs, $w, $h] : null;
        } catch (\Throwable $e) {
            Log::info('[informe] imagen no disponible', ['ruta' => $ruta, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Tamaño en puntos que cabe en $maxW × $maxH conservando la proporción. */
    public function encajar(array $img, float $maxW, float $maxH): array
    {
        // Tamaño natural en puntos (96 ppp) y, si no cabe, se reduce
        $w = $img[1] * 0.75;
        $h = $img[2] * 0.75;
        $r = min($maxW / $w, $maxH / $h, 1);
        return [round($w * $r, 1), round($h * $r, 1)];
    }

    public function dataUri(?array $img): ?string
    {
        return $img ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($img[0])) : null;
    }

    // ───────────────────────── Word ─────────────────────────

    public function word(array $d): string
    {
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $pw = new \PhpOffice\PhpWord\PhpWord();
        $pw->setDefaultFontName('Arial Narrow');
        $pw->setDefaultFontSize(11);
        $pw->getDocInfo()->setTitle('Informe de métricas – ' . $d['campana']->name)->setCreator('editus');
        $pw->addNumberingStyle('vinetas', ['type' => 'multilevel', 'levels' => [
            // Viñeta cuadrada de Wingdings, como en los informes de la Secretaría
            ['format' => 'bullet', 'text' => "\u{F0A7}", 'font' => 'Wingdings', 'hint' => 'default', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
        ]]);
        $Jc = \PhpOffice\PhpWord\SimpleType\Jc::class;
        $tw = fn(float $cm) => \PhpOffice\PhpWord\Shared\Converter::cmToTwip($cm);

        $sec = $pw->addSection([
            'pageSizeW' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(8.5), 'pageSizeH' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(11),
            'marginLeft' => $tw(3), 'marginRight' => $tw(3), 'marginTop' => $tw(3.75), 'marginBottom' => $tw(3),
            'headerHeight' => $tw(0.6), 'footerHeight' => $tw(0.8),
        ]);
        $anchoPt = (8.5 * 72) - 2 * (3 / 2.54 * 72); // ancho útil en puntos
        $m = $d['membrete'];
        $cabecera = $sec->addHeader();
        // Hoja membretada: imagen de página completa detrás del texto (logo, franjas y dirección)
        if ($img = $this->imagen($m['fondo'], 2550)) {
            $cabecera->addWatermark($img[0], ['width' => 612, 'height' => 792, 'marginTop' => 0, 'marginLeft' => 0,
                'posHorizontalRel' => 'page', 'posVerticalRel' => 'page', 'wrappingStyle' => 'behind']);
        }
        if ($img = $this->imagen($m['encabezado'], 2000)) {
            [$w, $h] = $this->encajar($img, (8.5 * 72) - 40, 90);
            $cabecera->addImage($img[0], ['width' => $w, 'height' => $h, 'alignment' => $Jc::CENTER]);
        }
        $pie = $sec->addFooter();
        if ($img = $this->imagen($m['pie'], 2000)) {
            [$w, $h] = $this->encajar($img, (8.5 * 72) - 40, 70);
            $pie->addImage($img[0], ['width' => $w, 'height' => $h, 'alignment' => $Jc::CENTER]);
        } elseif ($m['pie_texto'] !== '') {
            foreach (preg_split('/\r?\n/', $m['pie_texto']) as $l) $pie->addText($l, ['smallCaps' => true, 'color' => '808080', 'size' => 10, 'bold' => true], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
        }

        $titulo = $d['titulo'] ?: $m['titulo'];
        if ($titulo !== '') $sec->addText($titulo, ['bold' => true, 'size' => 13], ['alignment' => $Jc::CENTER, 'spaceAfter' => 240]);

        foreach ($d['capitulos'] as $ci => $cap) {
            if ($ci > 0) $sec->addPageBreak();
            if (count($d['capitulos']) > 1 || $titulo === '') $sec->addText($cap['titulo'], ['bold' => true, 'size' => 12], ['alignment' => $Jc::CENTER, 'spaceAfter' => 200]);
            foreach ($cap['secciones'] as $si => $s) {
                if ($si > 0) $sec->addPageBreak();
                $sec->addText($s['titulo'], ['bold' => true], ['alignment' => $Jc::CENTER, 'spaceAfter' => 160]);
                foreach ($s['piezas'] as $pz) $this->wordPieza($sec, $pz, $anchoPt);
            }
            if ($d['con_resumen']) $this->wordResumen($sec, $cap);
        }
        if (!$d['capitulos']) $sec->addText('No hay piezas con publicaciones para los filtros elegidos.', ['italic' => true], ['alignment' => $Jc::CENTER]);

        $tmp = tempnam(sys_get_temp_dir(), 'inf') . '.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($pw, 'Word2007')->save($tmp);
        return $tmp;
    }

    private function wordPieza($sec, array $pz, float $anchoPt): void
    {
        $Jc = \PhpOffice\PhpWord\SimpleType\Jc::class;
        $sec->addText($pz['etiqueta'], ['bold' => true, 'italic' => true, 'underline' => 'single'], ['alignment' => $Jc::BOTH, 'spaceBefore' => 120, 'spaceAfter' => 120]);
        $sec->addText($pz['descripcion'], [], ['alignment' => $Jc::BOTH, 'spaceAfter' => 120, 'lineHeight' => 1.15]);
        foreach ($pz['datos'] as [$k, $v]) {
            $r = $sec->addListItemRun(0, 'vinetas', ['spaceAfter' => 0]);
            $r->addText($k . ': ', ['bold' => true]);
            $r->addText($v);
        }
        if ($pz['muestra'] && ($img = $this->imagen($pz['muestra']))) {
            $sec->addListItemRun(0, 'vinetas', ['spaceAfter' => 60])->addText('MUESTRA:', ['bold' => true]);
            [$w, $h] = $this->encajar($img, $anchoPt, 320);
            $sec->addImage($img[0], ['width' => $w, 'height' => $h, 'alignment' => $Jc::CENTER]);
        }
        $web = $pz['clase'] === 'web';
        $sec->addListItemRun(0, 'vinetas', ['spaceBefore' => 120, 'spaceAfter' => 120])->addText($web ? 'PÁGINAS WEB:' : 'MEDIOS:', ['bold' => true]);
        foreach ($pz['pubs']->values() as $i => $pub) {
            if ($web) {
                $r = $sec->addTextRun(['indentation' => ['left' => 1080], 'spaceAfter' => 80]);
                $r->addText('➢ ' . $pub['medio'] . ': ');
                if ($pub['url']) $r->addLink($pub['url'], $pub['url'], ['color' => '0563C1', 'underline' => 'single']);
            } else {
                $sec->addText(($i + 1) . '. ' . $pub['medio'], [], ['indentation' => ['left' => 720], 'spaceAfter' => 80]);
                $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '000000', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER, 'cellMarginLeft' => 60, 'cellMarginRight' => 60]);
                $t->addRow(320);
                $enc = ['bgColor' => self::AMARILLO, 'valign' => 'center'];
                foreach ([['ALCANCE', 1250], ['VISUALIZACIÓN', 1700], ['INTERACCIÓN', 1550], ['URL', 4300]] as [$h, $w]) {
                    $t->addCell($w, $enc)->addText($h, ['bold' => true, 'size' => 9], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
                }
                $t->addRow(300);
                foreach (['alcance' => 1250, 'visualizaciones' => 1700, 'interacciones' => 1550] as $k => $w) {
                    $t->addCell($w, ['valign' => 'center'])->addText($this->numero($pub[$k]), ['size' => 10], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
                }
                $celda = $t->addCell(4300, ['valign' => 'center']);
                if ($pub['url']) $celda->addLink($pub['url'], $pub['url'], ['color' => '0563C1', 'underline' => 'single', 'size' => 9], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
                else $celda->addText('—', ['size' => 10], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
            }
            if ($img = $this->imagen($pub['evidencia'])) {
                [$w, $h] = $this->encajar($img, $anchoPt, 300);
                $sec->addTextBreak(1, ['size' => 4]);
                $sec->addImage($img[0], ['width' => $w, 'height' => $h, 'alignment' => $Jc::CENTER]);
            }
            $sec->addTextBreak(1, ['size' => 6]);
        }
    }

    private function wordResumen($sec, array $cap): void
    {
        $Jc = \PhpOffice\PhpWord\SimpleType\Jc::class;
        $sec->addTextBreak(1);
        $sec->addText('RESUMEN DE MÉTRICAS – ' . $cap['titulo'], ['bold' => true], ['alignment' => $Jc::CENTER, 'spaceAfter' => 120]);
        $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '000000', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER, 'cellMarginLeft' => 60, 'cellMarginRight' => 60]);
        $cols = [['TIPO DE PUBLICACIÓN', 2350], ['PIEZAS', 850], ['PUBLICACIONES', 1650], ['ALCANCE', 1150], ['VISUALIZACIÓN', 1500], ['INTERACCIÓN', 1350]];
        $t->addRow();
        foreach ($cols as [$h, $w]) $t->addCell($w, ['bgColor' => self::AMARILLO, 'valign' => 'center'])->addText($h, ['bold' => true, 'size' => 8], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
        $tot = $this->sumar($cap['resumen']);
        foreach (array_merge($cap['resumen'], [['titulo' => 'TOTAL'] + $tot]) as $i => $r) {
            $b = $r['titulo'] === 'TOTAL' && $i === count($cap['resumen']);
            $t->addRow();
            $t->addCell(2350)->addText($r['titulo'], ['size' => 9, 'bold' => $b], ['spaceAfter' => 0]);
            foreach (['piezas' => 850, 'publicaciones' => 1650, 'alcance' => 1150, 'visualizaciones' => 1500, 'interacciones' => 1350] as $k => $w) {
                $t->addCell($w)->addText($this->numero($r[$k]), ['size' => 9, 'bold' => $b], ['alignment' => $Jc::CENTER, 'spaceAfter' => 0]);
            }
        }
    }

    // ───────────────────────── PDF ─────────────────────────

    public function pdf(array $d)
    {
        $img = fn($ref, $max = 1400) => $this->dataUri($this->imagen($ref, $max));
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('campaigns.plan.informe-pdf', $d + ['img' => $img, 'fmt' => fn($n) => $this->numero((int) $n)])
            ->setPaper('letter', 'portrait')
            ->setOptions(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false, 'dpi' => 96]);
    }

    public function nombreArchivo(array $d, string $ext): string
    {
        $cap = count($d['capitulos']) === 1 ? $d['capitulos'][0] : null;
        $prog = $cap && $cap['subcampana'] ? '-' . Str::slug($cap['subcampana']->nombre)
            : ($cap && $cap['programa'] ? '-programa-' . Str::slug($cap['programa']->codigo ?: $cap['programa']->nombre) : '');
        return 'informe-metricas-' . Str::slug($d['campana']->name) . $prog . '-' . $d['corte']->format('Ymd') . '.' . $ext;
    }

    // ───────────────────────── Excel: control de productos ─────────────────────────

    public function excelControl(Campaign $c): string
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ss->getProperties()->setTitle('Control de productos – ' . $c->name)->setCreator('editus');
        $ss->removeSheetByIndex(0);
        $tipos = TipoPublicacion::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get();
        $productos = Producto::with('tipo', 'subcampana', 'programa')->where('campaign_id', $c->id)->orderBy('numero')->get();
        $nombres = [];
        $encabezado = ['#', 'Programa', 'Subprograma', 'Categoría', 'Tipo', 'Formato / medida', 'Muestra', 'Nombre de la pieza', 'Responsable',
            'Fecha solicitud', 'Fecha entrega', 'Fecha publicación', 'Estado', 'Acta aprobación', 'Acta publicación', 'Link a la carpeta / archivo',
            'Medios', 'Alcance', 'Visualizaciones', 'Interacciones', 'Observaciones', 'Subcampaña'];
        $grupos = $tipos->map(fn($s) => [$s, $productos->where('tipo_id', $s->id)->values()]);
        $sinTipo = $productos->whereNull('tipo_id')->values();
        if ($sinTipo->isNotEmpty()) $grupos->push([null, $sinTipo]);

        foreach ($grupos as [$s, $lista]) {
            $ws = $ss->createSheet();
            $ws->setTitle($this->nombreHoja($s?->nombre ?? 'Sin tipo', $nombres));
            $ws->setCellValue('A1', 'CONTROL DE PRODUCTOS – ' . mb_strtoupper($s?->nombre ?? 'Sin tipo'));
            $ws->setCellValue('A2', $c->name . ' · generado por editus el ' . now()->format('d/m/Y H:i'));
            $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1F3864');
            $ws->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
            $ws->fromArray($encabezado, null, 'A4', true);
            $this->estiloEncabezado($ws, 'A4:V4');
            $fila = 5;
            foreach ($lista as $i => $p) {
                $pubs = $this->productos->publicaciones($p);
                $tot = $this->productos->totales($pubs);
                $fp = $this->productos->fechaPublicacion($p, $pubs);
                $ws->fromArray([
                    $i + 1, $p->programa ? 'Campaña ' . $p->programa->codigo . ': ' . $p->programa->nombre : '', $p->subprograma,
                    $s?->nombre, $s?->tipo, $s?->formato, '', $p->nombre, $p->responsable,
                    $this->excelFecha($p->fecha_solicitud), $this->excelFecha($p->fecha_entrega), $this->excelFecha($fp),
                    $p->estado, $p->acta_aprobacion, $p->acta_publicacion, $p->link_archivo,
                    $tot['medios'], $tot['alcance'], $tot['visualizaciones'], $tot['interacciones'], $p->observaciones, $p->subcampana?->nombre,
                ], null, 'A' . $fila, true);
                if ($p->link_archivo) $ws->getCell('P' . $fila)->getHyperlink()->setUrl($p->link_archivo);
                if ($img = $this->imagen($p->muestra_path ? [ProductosService::DISCO, $p->muestra_path] : null, 400)) {
                    $dr = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $dr->setPath($img[0])->setCoordinates('G' . $fila)->setOffsetX(4)->setOffsetY(4);
                    $dr->setHeight(68);
                    if ($dr->getWidth() > 150) $dr->setWidth(150);
                    $dr->setWorksheet($ws);
                    $ws->getRowDimension($fila)->setRowHeight(58);
                }
                $fila++;
            }
            foreach (['J', 'K', 'L'] as $col) $ws->getStyle("{$col}5:{$col}" . max(5, $fila - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            foreach (['R', 'S', 'T'] as $col) $ws->getStyle("{$col}5:{$col}" . max(5, $fila - 1))->getNumberFormat()->setFormatCode('#,##0');
            $anchos = ['A' => 5, 'B' => 34, 'C' => 18, 'D' => 18, 'E' => 10, 'F' => 14, 'G' => 22, 'H' => 40, 'I' => 16, 'J' => 12, 'K' => 12, 'L' => 13, 'M' => 16,
                'N' => 11, 'O' => 11, 'P' => 40, 'Q' => 8, 'R' => 11, 'S' => 13, 'T' => 12, 'U' => 30, 'V' => 20];
            foreach ($anchos as $col => $w) $ws->getColumnDimension($col)->setWidth($w);
            $ws->getStyle('A5:V' . max(5, $fila - 1))->getAlignment()->setVertical('center')->setWrapText(true);
            if ($fila > 5) $ws->getStyle('A4:V' . ($fila - 1))->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('BFBFBF');
            $ws->freezePane('A5');
            $ws->setAutoFilter('A4:V' . max(4, $fila - 1));
        }

        // Resumen: piezas por programa y tipo de publicación
        $r = $this->productos->resumen($c);
        $ws = $ss->createSheet(0);
        $ws->setTitle('Resumen');
        $ws->setCellValue('A1', 'RESUMEN POR PROGRAMA Y TIPO DE PUBLICACIÓN');
        $ws->setCellValue('A2', $c->name);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1F3864');
        $cab = array_merge(['Programa'], $r['tipos']->pluck('nombre')->all(), ['Sin tipo', 'TOTAL']);
        $ws->fromArray($cab, null, 'A4', true);
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cab));
        $this->estiloEncabezado($ws, "A4:{$ultima}4");
        $fila = 5;
        foreach ($r['filas'] as $f) {
            $ws->fromArray(array_merge([$f['programa'] ? 'Campaña ' . $f['programa']->codigo . ': ' . $f['programa']->nombre : 'Sin programa'],
                array_values($f['por_tipo']), [$f['sin_tipo'], $f['total']]), null, 'A' . $fila++, true);
        }
        $ws->fromArray(array_merge(['TOTAL'], array_values($r['totales']), [array_sum(array_column($r['filas'], 'sin_tipo')), $r['total']]), null, 'A' . $fila, true);
        $ws->getStyle("A{$fila}:{$ultima}{$fila}")->getFont()->setBold(true);
        $ws->getStyle("A{$fila}:{$ultima}{$fila}")->getFill()->setFillType('solid')->getStartColor()->setRGB('DDEBF7');
        $ws->getStyle("A4:{$ultima}{$fila}")->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('BFBFBF');
        $ws->getColumnDimension('A')->setWidth(55);
        for ($i = 2; $i <= count($cab); $i++) $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(14);
        $ws->getStyle("B4:{$ultima}{$fila}")->getAlignment()->setHorizontal('center');
        $ws->getStyle("A4:{$ultima}4")->getAlignment()->setWrapText(true);

        // Debajo: avance de cada subcampaña (piezas creadas / planeadas por tipo)
        $avance = $this->productos->avanceSubcampanas($c);
        if ($avance->isNotEmpty()) {
            $fila += 3;
            $ws->setCellValue('A' . $fila, 'AVANCE POR SUBCAMPAÑA (piezas creadas / planeadas)');
            $ws->getStyle('A' . $fila)->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('1F3864');
            $fila++;
            $ini = $fila;
            $ws->fromArray(array_merge(['Subcampaña'], $r['tipos']->pluck('nombre')->all(), ['', 'TOTAL']), null, 'A' . $fila, true);
            $this->estiloEncabezado($ws, "A{$fila}:{$ultima}{$fila}");
            $fila++;
            foreach ($avance as $a) {
                $celdas = [$a['sub']->nombre];
                foreach ($r['tipos'] as $t) {
                    $x = collect($a['tipos'])->first(fn($y) => $y['tipo']->id === $t->id);
                    $celdas[] = $x ? $x['piezas'] . ($x['planeadas'] !== null ? ' / ' . $x['planeadas'] : '') : '';
                }
                $celdas[] = '';
                $celdas[] = $a['total'];
                $ws->fromArray($celdas, null, 'A' . $fila++, true);
            }
            $ws->getStyle("A{$ini}:{$ultima}" . ($fila - 1))->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('BFBFBF');
            $ws->getStyle("B{$ini}:{$ultima}" . ($fila - 1))->getAlignment()->setHorizontal('center');
        }

        // Métricas: una fila por publicación
        $ws = $ss->createSheet(1);
        $ws->setTitle('Métricas');
        $ws->fromArray(['Programa', 'Subprograma', 'Tipo de publicación', 'Pieza', 'Medio', 'Fecha', 'Alcance', 'Visualizaciones', 'Interacciones', 'URL', 'Origen', 'Subcampaña'], null, 'A1', true);
        $this->estiloEncabezado($ws, 'A1:L1');
        $fila = 2;
        foreach ($productos as $p) {
            foreach ($this->productos->publicaciones($p) as $pub) {
                $ws->fromArray([
                    $p->programa?->etiqueta(), $p->subprograma, $p->tipo?->nombre, $p->codigo() . ' · ' . $p->nombre, $pub['medio'],
                    $this->excelFecha($pub['fecha'] ? Carbon::parse($pub['fecha']) : null), $pub['alcance'], $pub['visualizaciones'], $pub['interacciones'], $pub['url'],
                    $pub['origen'] === 'editus' ? 'editus' : 'registrada', $p->subcampana?->nombre,
                ], null, 'A' . $fila, true);
                if ($pub['url']) $ws->getCell('J' . $fila)->getHyperlink()->setUrl($pub['url']);
                $fila++;
            }
        }
        $ws->getStyle('F2:F' . max(2, $fila - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle('G2:I' . max(2, $fila - 1))->getNumberFormat()->setFormatCode('#,##0');
        foreach (['A' => 34, 'B' => 18, 'C' => 18, 'D' => 40, 'E' => 26, 'F' => 12, 'G' => 11, 'H' => 14, 'I' => 13, 'J' => 50, 'K' => 10, 'L' => 20] as $col => $w) $ws->getColumnDimension($col)->setWidth($w);
        $ws->freezePane('A2');
        if ($fila > 2) $ws->setAutoFilter('A1:L' . ($fila - 1));

        $ss->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'ctl') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();
        return $tmp;
    }

    private function estiloEncabezado($ws, string $rango): void
    {
        $st = $ws->getStyle($rango);
        $st->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $st->getFill()->setFillType('solid')->getStartColor()->setRGB('1F3864');
        $st->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        $ws->getRowDimension((int) preg_replace('/\D/', '', explode(':', $rango)[0]))->setRowHeight(30);
    }

    private function excelFecha(?Carbon $f)
    {
        return $f ? \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($f->copy()->startOfDay()) : null;
    }

    private function nombreHoja(string $n, array &$usados): string
    {
        $base = mb_substr(trim(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $n)) ?: 'Hoja', 0, 28);
        $nombre = $base;
        $i = 2;
        while (in_array(mb_strtolower($nombre), $usados, true)) $nombre = $base . ' ' . $i++;
        $usados[] = mb_strtolower($nombre);
        return $nombre;
    }

    // ───────────────────────── Importar el Excel del control ─────────────────────────

    /**
     * Lee un Excel con el formato «Control de productos» (una hoja por tipo de publicación, encabezados
     * «Programa», «Nombre de la pieza», «Estado»…, la miniatura en la fila de cada pieza) y crea los tipos,
     * programas y piezas que falten. Las piezas ya existentes (mismo tipo y nombre) no se repiten.
     * @return array{tipos: int, programas: int, piezas: int, repetidas: int, muestras: int, hojas: array}
     */
    public function importarExcel(Campaign $c, string $ruta): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($ruta);
        $ss = $reader->load($ruta);
        $res = ['tipos' => 0, 'programas' => 0, 'subcampanas' => 0, 'piezas' => 0, 'repetidas' => 0, 'muestras' => 0, 'hojas' => []];
        $orden = (int) TipoPublicacion::where('campaign_id', $c->id)->max('orden') + 1;
        $ordenSub = (int) Subcampana::where('campaign_id', $c->id)->max('orden') + 1;
        $ordenProg = (int) Programa::where('campaign_id', $c->id)->max('orden') + 1;

        foreach ($ss->getWorksheetIterator() as $ws) {
            [$filaEnc, $cols] = $this->buscarEncabezado($ws);
            if (!$filaEnc || !isset($cols['nombre'])) continue;
            $nombreTipo = trim(preg_replace('/\s+/', ' ', $ws->getTitle()));
            $sub = TipoPublicacion::where('campaign_id', $c->id)->get()->first(fn($s) => Str::lower(Str::ascii($s->nombre)) === Str::lower(Str::ascii($nombreTipo)));
            if (!$sub) {
                $sub = TipoPublicacion::create([
                    'campaign_id' => $c->id, 'nombre' => mb_substr($nombreTipo, 0, 120), 'etiqueta' => $this->singular($nombreTipo),
                    'clase' => $this->claseDe($nombreTipo), 'is_active' => true, 'orden' => $orden++,
                ]);
                $res['tipos']++;
            }
            // Miniaturas por fila
            $imagenes = [];
            foreach ($ws->getDrawingCollection() as $dr) {
                [$col, $fila] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($dr->getCoordinates());
                $imagenes[(int) $fila] ??= $dr;
            }
            $n = 0;
            for ($fila = $filaEnc + 1, $max = $ws->getHighestDataRow(); $fila <= $max; $fila++) {
                $val = fn($k) => isset($cols[$k]) ? $ws->getCell($cols[$k] . $fila)->getCalculatedValue() : null;
                $nombre = trim(preg_replace('/\s+/', ' ', (string) $val('nombre')));
                if ($nombre === '') continue;
                if (Producto::where('campaign_id', $c->id)->where('tipo_id', $sub->id)->where('nombre', mb_substr($nombre, 0, 255))->exists()) {
                    $res['repetidas']++;
                    continue;
                }
                [$codigo, $nombreProg] = $this->leerPrograma((string) $val('programa'));
                $prog = null;
                if ($nombreProg !== '') {
                    $prog = Programa::where('campaign_id', $c->id)->where(fn($q) => $codigo !== '' ? $q->where('codigo', $codigo) : $q->where('nombre', $nombreProg))->first();
                    if (!$prog) {
                        $prog = Programa::create(['campaign_id' => $c->id, 'codigo' => $codigo, 'nombre' => mb_substr($nombreProg, 0, 190), 'subprogramas' => [], 'orden' => $ordenProg++]);
                        $res['programas']++;
                    }
                }
                // Columna opcional «Subcampaña» (tema, p. ej. «Dengue»)
                $tema = null;
                if (($nombreTema = trim(preg_replace('/\s+/', ' ', (string) $val('subcampana')))) !== '') {
                    $tema = Subcampana::where('campaign_id', $c->id)->get()->first(fn($x) => Str::lower(Str::ascii($x->nombre)) === Str::lower(Str::ascii($nombreTema)));
                    if (!$tema) {
                        $tema = Subcampana::create(['campaign_id' => $c->id, 'nombre' => mb_substr($nombreTema, 0, 120), 'is_active' => true, 'orden' => $ordenSub++,
                            'programa_id' => $prog?->id, 'programa' => $this->productos->textoPrograma($prog)]);
                        $res['subcampanas'] = ($res['subcampanas'] ?? 0) + 1;
                    }
                    $tema->tipos()->syncWithoutDetaching([$sub->id]);
                }
                $p = $this->productos->guardar($c, [
                    'tipo_id' => $sub->id, 'subcampana_id' => $tema?->id, 'programa_id' => $prog?->id, 'nombre' => $nombre,
                    'responsable' => $val('responsable'), 'estado' => $this->estadoDe((string) $val('estado')),
                    'fecha_solicitud' => $this->fechaDe($val('fecha_solicitud')), 'fecha_entrega' => $this->fechaDe($val('fecha_entrega')),
                    'link_archivo' => trim((string) $val('link')), 'observaciones' => $val('observaciones'),
                ]);
                if (isset($imagenes[$fila]) && ($bin = $this->binarioDibujo($imagenes[$fila]))) {
                    $this->productos->guardarMuestra($p, $bin[0], $bin[1]);
                    $res['muestras']++;
                }
                if (!$sub->tipo && ($t = trim((string) $val('tipo')))) $sub->forceFill(['tipo' => mb_substr($t, 0, 40)])->save();
                $res['piezas']++;
                $n++;
            }
            $res['hojas'][$nombreTipo] = $n;
        }
        $ss->disconnectWorksheets();
        return $res;
    }

    private function buscarEncabezado($ws): array
    {
        $mapa = ['programa' => ['programa'], 'nombre' => ['nombre de la pieza', 'nombre'], 'responsable' => ['responsable'], 'tipo' => ['tipo'],
            'fecha_solicitud' => ['fecha solicitud'], 'fecha_entrega' => ['fecha entrega'], 'estado' => ['estado'],
            'link' => ['link a la carpeta / archivo', 'link', 'enlace'], 'observaciones' => ['observaciones'], 'subcampana' => ['subcampaña', 'subcampana']];
        $maxCol = min(30, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestDataColumn()));
        for ($fila = 1; $fila <= 12; $fila++) {
            $cols = [];
            for ($i = 1; $i <= $maxCol; $i++) {
                $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
                $t = Str::lower(trim(preg_replace('/\s+/', ' ', (string) $ws->getCell($letra . $fila)->getValue())));
                foreach ($mapa as $k => $opciones) if (!isset($cols[$k]) && in_array($t, $opciones, true)) $cols[$k] = $letra;
            }
            if (isset($cols['nombre']) && (isset($cols['programa']) || isset($cols['estado']))) return [$fila, $cols];
        }
        return [null, []];
    }

    /** «CAMPAÑA 2-1: Salud infantil y adolescencia» → ['2-1', 'Salud infantil y adolescencia'] */
    public function leerPrograma(string $t): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '') return ['', ''];
        if (preg_match('/^(?:cam\p{L}*|programa|p)\s*([0-9]+(?:\s*-\s*[0-9]+)?)\s*[:.\-–]\s*(.+)$/iu', $t, $m)) {
            return [preg_replace('/\s+/', '', $m[1]), trim($m[2])];
        }
        return ['', $t];
    }

    private function estadoDe(string $t): string
    {
        $t = Str::lower(Str::ascii(trim($t)));
        foreach (Producto::ESTADOS as $e) if ($t !== '' && str_starts_with(Str::lower(Str::ascii($e)), substr($t, 0, 6))) return $e;
        return 'Pendiente';
    }

    private function fechaDe($v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            if (is_numeric($v)) return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v))->toDateString();
            if ($v instanceof \DateTimeInterface) return Carbon::instance($v)->toDateString();
            return Carbon::parse((string) $v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string}|null [binario, extensión] */
    private function binarioDibujo($dr): ?array
    {
        try {
            if ($dr instanceof \PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing) {
                ob_start();
                call_user_func($dr->getRenderingFunction(), $dr->getImageResource());
                return [(string) ob_get_clean(), 'png'];
            }
            $ruta = $dr->getPath();
            $bin = @file_get_contents($ruta);
            return $bin ? [$bin, strtolower(pathinfo(parse_url($ruta, PHP_URL_PATH) ?: $ruta, PATHINFO_EXTENSION)) ?: 'png'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function claseDe(string $nombre): string
    {
        $n = Str::lower(Str::ascii($nombre));
        return match (true) {
            str_contains($n, 'vivo') => 'envivo',
            str_contains($n, 'banner') && (str_contains($n, '1600') || str_contains($n, '360')) => 'web',
            str_contains($n, 'podcast') || str_contains($n, 'cuna') => 'audio',
            str_contains($n, 'impreso') => 'impreso',
            default => 'redes',
        };
    }

    /** «Videos largos» → «Video Largo», «Cuñas» → «Cuña», «Banner 600x600» → «Banner 600x600». */
    public function singular(string $nombre): string
    {
        $palabras = preg_split('/\s+/', trim($nombre));
        $out = array_map(function ($w) {
            $l = mb_strtolower($w);
            if (preg_match('/\d/', $w)) return $w;
            if (preg_match('/(es)$/u', $l) && mb_strlen($l) > 4 && preg_match('/[lrnd]es$/u', $l)) $w = mb_substr($w, 0, -2);
            elseif (preg_match('/s$/u', $l) && mb_strlen($l) > 3) $w = mb_substr($w, 0, -1);
            return mb_convert_case($w, MB_CASE_TITLE);
        }, $palabras);
        return mb_substr(implode(' ', $out), 0, 80);
    }
}
