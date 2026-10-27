<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\MetaPost;
use App\Models\MetaPostMetric;
use App\Models\Producto;
use App\Models\ProductoPublicacion;
use App\Models\Programa;
use App\Models\Subcampana;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Control de productos del plan de medios: cada pieza (producto) es de un tipo de publicación (subcampaña)
 * y de un programa; se publica en varios medios (desde editus o registrada a mano) y de ahí salen
 * las métricas, el informe y el certificado de cada medio.
 */
class ProductosService
{
    public const DISCO = 'local';

    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public static function disponible(): bool
    {
        if (app()->bound('productos.disponible')) return true;
        if (!Schema::hasTable('campaign_productos') || !Schema::hasColumn('meta_posts', 'producto_id')) return false;
        app()->instance('productos.disponible', true);
        return true;
    }

    /** URL para ver en pantalla una imagen [disco, ruta] (muestra, captura o membrete). */
    public static function url(Campaign $c, ?array $ref): ?string
    {
        if (!$ref || empty($ref[1])) return null;
        if ($ref[0] === 'public') return Storage::disk('public')->url($ref[1]);
        return route('campaigns.archivo', [$c, 'f' => base64_encode($ref[1])]);
    }

    // ───────────────────────── Programas ─────────────────────────

    /** Guarda las filas del formulario de programas: [['id'?, 'codigo', 'nombre', 'subprogramas' (texto), 'borrar'?], ...]. */
    public function guardarProgramas(Campaign $c, array $filas): void
    {
        DB::transaction(function () use ($c, $filas) {
            $orden = 0;
            foreach ($filas as $f) {
                $id = (int) ($f['id'] ?? 0);
                $p = $id ? Programa::where('campaign_id', $c->id)->find($id) : null;
                $nombre = trim((string) ($f['nombre'] ?? ''));
                if (!empty($f['borrar']) || $nombre === '') {
                    if ($p) {
                        Producto::where('programa_id', $p->id)->update(['programa_id' => null]);
                        $p->delete();
                    }
                    continue;
                }
                $p ??= new Programa(['campaign_id' => $c->id]);
                $p->fill([
                    'codigo' => mb_substr(trim((string) ($f['codigo'] ?? '')), 0, 20),
                    'nombre' => mb_substr($nombre, 0, 190),
                    'subprogramas' => $this->lista($f['subprogramas'] ?? ''),
                    'orden' => $orden++,
                ]);
                $cambio = $p->exists && $p->isDirty(['codigo', 'nombre']);
                $p->save();
                if ($cambio) foreach ($p->productos()->get() as $pr) $this->aplicarAPosts($pr);
            }
        });
    }

    /** «Vacunación, PAI; Nutrición» o una por línea → ['Vacunación', 'PAI', 'Nutrición'] */
    public function lista(string|array|null $t): array
    {
        $partes = is_array($t) ? $t : (preg_split('/\r?\n|;|,/', (string) $t) ?: []);
        return array_values(array_unique(array_filter(array_map(fn($s) => mb_substr(trim((string) $s), 0, 190), $partes))));
    }

    /** Texto del programa en las publicaciones y el certificado: «Programa 2-1. Salud infantil y adolescencia». */
    public function textoPrograma(?Programa $p): ?string
    {
        if (!$p) return null;
        return trim($p->codigo) !== '' ? 'Programa ' . trim($p->codigo) . '. ' . $p->nombre : (string) $p->nombre;
    }

    // ───────────────────────── Productos ─────────────────────────

    public function siguienteNumero(Campaign $c, ?int $subcampanaId): int
    {
        return (int) Producto::where('campaign_id', $c->id)->where('subcampana_id', $subcampanaId)->max('numero') + 1;
    }

    /** Crea o actualiza una pieza (y su muestra). Si cambia su tipo o programa, se actualizan sus publicaciones. */
    public function guardar(Campaign $c, array $d, ?Producto $p = null, ?UploadedFile $muestra = null): Producto
    {
        $p ??= new Producto(['campaign_id' => $c->id]);
        $sub = isset($d['subcampana_id']) ? Subcampana::where('campaign_id', $c->id)->find((int) $d['subcampana_id']) : null;
        $prog = !empty($d['programa_id']) ? Programa::where('campaign_id', $c->id)->find((int) $d['programa_id']) : null;
        $txt = fn($k, $max) => ($v = trim((string) ($d[$k] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
        $p->fill([
            'subcampana_id' => $sub?->id,
            'programa_id' => $prog?->id,
            'subprograma' => $txt('subprograma', 190),
            'nombre' => $txt('nombre', 255) ?? 'Pieza',
            'descripcion' => $txt('descripcion', 5000),
            'responsable' => $txt('responsable', 120),
            'fecha_solicitud' => ($d['fecha_solicitud'] ?? null) ?: null,
            'fecha_entrega' => ($d['fecha_entrega'] ?? null) ?: null,
            'fecha_publicacion' => ($d['fecha_publicacion'] ?? null) ?: null,
            'fecha_fin' => ($d['fecha_fin'] ?? null) ?: null,
            'estado' => in_array($d['estado'] ?? '', Producto::ESTADOS, true) ? $d['estado'] : ($p->estado ?: 'Pendiente'),
            'acta_aprobacion' => $txt('acta_aprobacion', 60),
            'acta_publicacion' => $txt('acta_publicacion', 60),
            'link_archivo' => $txt('link_archivo', 500),
            'observaciones' => $txt('observaciones', 5000),
        ]);
        if (!$p->exists || $p->isDirty('subcampana_id')) $p->numero = $this->siguienteNumero($c, $p->subcampana_id);
        $cambio = $p->exists && $p->isDirty(['subcampana_id', 'programa_id', 'subprograma']);
        $p->save();
        if ($muestra) $this->guardarMuestra($p, $muestra);
        if ($cambio) $this->aplicarAPosts($p->fresh());
        return $p;
    }

    public function guardarMuestra(Producto $p, UploadedFile|string $archivo, ?string $extension = null): void
    {
        $disco = Storage::disk(self::DISCO);
        $ext = $extension ?: ($archivo instanceof UploadedFile ? ($archivo->extension() ?: 'jpg') : 'png');
        $ruta = 'campanas/' . $p->campaign_id . '/productos/muestra-' . $p->id . '-' . Str::random(6) . '.' . $ext;
        $disco->put($ruta, $archivo instanceof UploadedFile ? $archivo->get() : $archivo);
        if ($p->muestra_path) $disco->delete($p->muestra_path);
        $p->forceFill(['muestra_path' => $ruta])->save();
    }

    public function borrar(Producto $p): void
    {
        $disco = Storage::disk(self::DISCO);
        foreach ($p->publicaciones()->get() as $x) if ($x->evidencia_path) $disco->delete($x->evidencia_path);
        if ($p->muestra_path) $disco->delete($p->muestra_path);
        MetaPost::where('producto_id', $p->id)->update(['producto_id' => null]);
        $p->delete();
    }

    /** Campos de una publicación de editus según su pieza (tipo, programa y subprograma del certificado). */
    public function camposDe(?Producto $p): array
    {
        if (!$p) return [];
        $p->loadMissing('subcampana', 'programa');
        return array_filter([
            'producto_id' => $p->id,
            'subcampana_id' => $p->subcampana_id,
            'tipo_pieza' => $p->subcampana?->etiquetaSingular(),
            'programa' => $this->textoPrograma($p->programa),
            'subprograma' => $p->subprograma,
        ], fn($v) => $v !== null) + ['producto_id' => $p->id];
    }

    public function aplicarAPosts(Producto $p): int
    {
        $p->loadMissing('subcampana', 'programa');
        return MetaPost::where('producto_id', $p->id)->update([
            'subcampana_id' => $p->subcampana_id,
            'tipo_pieza' => $p->subcampana?->etiquetaSingular(),
            'programa' => $this->textoPrograma($p->programa),
            'subprograma' => $p->subprograma,
        ]);
    }

    /**
     * Todas las publicaciones de la pieza, una fila por medio: las de editus (con sus métricas y la captura
     * subida en Mis publicaciones o aquí) y las registradas a mano (páginas web, otros medios).
     * @return Collection<int, array>
     */
    public function publicaciones(Producto $p): Collection
    {
        $extras = $p->publicaciones()->get();
        $porPost = $extras->whereNotNull('meta_post_id')->keyBy('meta_post_id');
        $posts = MetaPost::with('page:id,name')->where('producto_id', $p->id)->where('status', 'success')
            ->orderBy('published_at')->orderBy('id')->get();
        $evidencias = $posts->isEmpty() || !Schema::hasTable('meta_post_metrics') ? collect() : MetaPostMetric::whereIn('meta_post_id', $posts->pluck('id'))
            ->whereNotNull('evidencia_path')->orderByDesc('round')->get()->unique('meta_post_id')->keyBy('meta_post_id');

        $filas = $posts->map(function (MetaPost $x) use ($porPost, $evidencias) {
            $e = $porPost->get($x->id);
            $evid = $e?->evidencia_path ? [self::DISCO, $e->evidencia_path]
                : (($m = $evidencias->get($x->id)) ? ['public', $m->evidencia_path] : (($x->evidencia_path ?? null) ? ['public', $x->evidencia_path] : null));
            return [
                'origen' => 'editus', 'post' => $x, 'extra' => $e,
                'medio' => ($x->page?->name ?? '—') . ($x->network === 'instagram' ? ' (Instagram)' : ''),
                'url' => $e?->url ?: $x->fb_permalink_url,
                'fecha' => $x->published_at,
                'alcance' => (int) ($e?->alcance ?? $x->alcance ?? 0),
                'visualizaciones' => (int) ($e?->visualizaciones ?? $x->visualizaciones ?? 0),
                'interacciones' => (int) ($e?->interacciones ?? $x->interacciones ?? 0),
                'evidencia' => $evid,
            ];
        });
        $manuales = $extras->whereNull('meta_post_id')->map(fn(ProductoPublicacion $e) => [
            'origen' => 'manual', 'post' => null, 'extra' => $e,
            'medio' => $e->medio ?: ($e->page?->name ?? '—'),
            'url' => $e->url, 'fecha' => $e->fecha,
            'alcance' => (int) $e->alcance, 'visualizaciones' => (int) $e->visualizaciones, 'interacciones' => (int) $e->interacciones,
            'evidencia' => $e->evidencia_path ? [self::DISCO, $e->evidencia_path] : null,
        ]);
        // Las de editus por nombre de la página; las registradas, en el orden en que se agregaron
        return $filas->sortBy(fn($f) => mb_strtolower(Str::ascii((string) $f['medio'])))->values()->concat($manuales->values())->values();
    }

    /** Fecha de publicación de la pieza: la escrita o la de su primera publicación. */
    public function fechaPublicacion(Producto $p, ?Collection $pubs = null): ?Carbon
    {
        if ($p->fecha_publicacion) return $p->fecha_publicacion->copy();
        $pubs ??= $this->publicaciones($p);
        $f = $pubs->pluck('fecha')->filter()->min();
        return $f ? Carbon::parse($f)->startOfDay() : null;
    }

    /** Totales de un conjunto de publicaciones. */
    public function totales(Collection $pubs): array
    {
        return [
            'medios' => $pubs->pluck('medio')->unique()->count(),
            'publicaciones' => $pubs->count(),
            'alcance' => (int) $pubs->sum('alcance'),
            'visualizaciones' => (int) $pubs->sum('visualizaciones'),
            'interacciones' => (int) $pubs->sum('interacciones'),
        ];
    }

    /**
     * Resumen como la hoja «Resumen» del control: piezas por programa (filas) y tipo de publicación (columnas).
     * @return array{subs: Collection, filas: array, totales: array, total: int}
     */
    public function resumen(Campaign $c): array
    {
        $subs = Subcampana::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get();
        $progs = Programa::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get();
        $conteo = Producto::where('campaign_id', $c->id)->select('programa_id', 'subcampana_id', DB::raw('COUNT(*) as n'))
            ->groupBy('programa_id', 'subcampana_id')->get();
        $filas = [];
        foreach ($progs->concat([null]) as $prog) {
            $porSub = [];
            foreach ($subs as $s) $porSub[$s->id] = (int) $conteo->where('programa_id', $prog?->id)->where('subcampana_id', $s->id)->sum('n');
            $sinTipo = (int) $conteo->where('programa_id', $prog?->id)->whereNull('subcampana_id')->sum('n');
            $total = array_sum($porSub) + $sinTipo;
            if ($prog === null && $total === 0) continue;
            $filas[] = ['programa' => $prog, 'por_sub' => $porSub, 'sin_tipo' => $sinTipo, 'total' => $total];
        }
        $tot = [];
        foreach ($subs as $s) $tot[$s->id] = array_sum(array_map(fn($f) => $f['por_sub'][$s->id], $filas));
        return ['subs' => $subs, 'filas' => $filas, 'totales' => $tot, 'total' => array_sum(array_column($filas, 'total'))];
    }

    // ───────────────────────── Textos del informe ─────────────────────────

    /** «17 de Diciembre de 2025» */
    public function fecha(?Carbon $f): string
    {
        return $f ? $f->format('d') . ' de ' . self::MESES[$f->month] . ' de ' . $f->year : '—';
    }

    /** «Del 17 de Diciembre de 2025 al 06 de Enero de 2026» / «01 al 30 de Diciembre de 2025» */
    public function rango(?Carbon $a, ?Carbon $b, bool $conDel = true): string
    {
        if (!$a) return '—';
        if (!$b || $b->lte($a)) return $this->fecha($a);
        $pre = $conDel ? 'Del ' : '';
        if ($a->isSameMonth($b)) return $pre . $a->format('d') . ' al ' . $b->format('d') . ' de ' . self::MESES[$b->month] . ' de ' . $b->year;
        return $pre . $this->fecha($a) . ' al ' . $this->fecha($b);
    }

    /** Número en letras (0–999 999): 15 → «quince», 21 → «veintiún»/«veintiuna». */
    public function enLetras(int $n, bool $femenino = false): string
    {
        $uno = $femenino ? 'una' : 'un';
        $u = ['cero', $uno, 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
            'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', $femenino ? 'veintiuna' : 'veintiún', 'veintidós', 'veintitrés', 'veinticuatro',
            'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
        $d = [3 => 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $c = [1 => 'ciento', $femenino ? 'doscientas' : 'doscientos', $femenino ? 'trescientas' : 'trescientos', $femenino ? 'cuatrocientas' : 'cuatrocientos',
            $femenino ? 'quinientas' : 'quinientos', $femenino ? 'seiscientas' : 'seiscientos', $femenino ? 'setecientas' : 'setecientos',
            $femenino ? 'ochocientas' : 'ochocientos', $femenino ? 'novecientas' : 'novecientos'];
        if ($n < 0) return 'menos ' . $this->enLetras(-$n, $femenino);
        if ($n < 30) return $u[$n];
        if ($n < 100) return $d[intdiv($n, 10)] . ($n % 10 ? ' y ' . $u[$n % 10] : '');
        if ($n === 100) return 'cien';
        if ($n < 1000) return $c[intdiv($n, 100)] . ($n % 100 ? ' ' . $this->enLetras($n % 100, $femenino) : '');
        if ($n < 1_000_000) {
            $miles = intdiv($n, 1000);
            return ($miles === 1 ? 'mil' : $this->enLetras($miles) . ' mil') . ($n % 1000 ? ' ' . $this->enLetras($n % 1000, $femenino) : '');
        }
        return number_format($n, 0, ',', '.');
    }

    /** «quince (15) medios digitales» / «un (1) medio digital» / «tres (3) páginas web» */
    public function cantidad(int $n, string $singular, string $plural, bool $femenino = false): string
    {
        return $this->enLetras($n, $femenino) . ' (' . $n . ') ' . ($n === 1 ? $singular : $plural);
    }

    public function plantillaDefecto(?string $clase): string
    {
        return match ($clase) {
            'web' => 'Publicación de un (1) {unidad} en {paginas}.',
            'envivo' => 'Transmisión de un (1) en vivo en {medios}.',
            'audio' => 'Emisión de un (1) {unidad} en {medios}.',
            'impreso' => 'Entrega de un (1) {unidad}.',
            default => 'Publicación de un (1) {unidad} en {medios}.',
        };
    }

    /** Descripción de la pieza en el informe: la escrita en la pieza o la plantilla de su tipo. */
    public function descripcion(Producto $p, int $nMedios): string
    {
        if (trim((string) $p->descripcion) !== '') return trim($p->descripcion);
        $s = $p->subcampana;
        $plantilla = trim((string) $s?->plantilla) ?: $this->plantillaDefecto($s?->clase);
        return strtr($plantilla, [
            '{unidad}' => trim((string) $s?->unidad) ?: mb_strtolower((string) ($s?->etiquetaSingular() ?? 'pieza')),
            '{medios}' => $this->cantidad($nMedios, 'medio digital', 'medios digitales'),
            '{paginas}' => $this->cantidad($nMedios, 'página web', 'páginas web', true),
            '{n}' => (string) $nMedios,
            '{nombre}' => (string) $p->nombre,
        ]);
    }
}
