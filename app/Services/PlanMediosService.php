<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignCupo;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de medios de una campaña: subcampañas (temas, p. ej. «Dengue») con sus tipos de publicación, cupos de piezas por tipo
 * y por página (p. ej. 20 Video Corto, 56 Video Largo) y métricas por subcampaña y de toda la campaña.
 *
 * Una «pieza» es una publicación en una página: si se publicó a la vez en Facebook e Instagram
 * (mismo lote) cuenta una sola vez para los cupos.
 */
class PlanMediosService
{
    /** Estados que NO cuentan para los cupos (la publicación falló). */
    private const FALLIDOS = ['fail', 'failed', 'error'];

    public function __construct(private CertificadosService $cert)
    {
    }

    public static function disponible(): bool
    {
        if (app()->bound('plan_medios.disponible')) return true;
        if (!Schema::hasTable('campaign_subcampanas') || !Schema::hasColumn('meta_posts', 'subcampana_id')) return false;
        app()->instance('plan_medios.disponible', true);
        return true;
    }

    public static function clave(?string $tipo): string
    {
        return mb_strtolower(trim((string) $tipo));
    }

    public static function conTipos(): bool
    {
        if (app()->bound('plan_medios.tipos')) return true;
        if (!Schema::hasTable('campaign_tipos')) return false;
        app()->instance('plan_medios.tipos', true);
        return true;
    }

    /**
     * Tipos de pieza de la campaña: los nombres singulares de sus tipos de publicación (p. ej. «Video Largo»)
     * o, si aún no tiene, los escritos en el certificado.
     */
    public function tipos(Campaign $c): array
    {
        if (self::conTipos()) {
            $tipos = TipoPublicacion::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get();
            if ($tipos->isNotEmpty()) return array_values(array_unique($tipos->map(fn($x) => $x->etiquetaSingular())->all()));
        }
        return $this->cert->config($c)['tipos_pieza'];
    }

    /** Devuelve el tipo tal como está escrito en la campaña (o null si no es uno de sus tipos). */
    public function tipoValido(Campaign $c, ?string $tipo): ?string
    {
        foreach ($this->tipos($c) as $t) if (self::clave($t) === self::clave($tipo)) return $t;
        return null;
    }

    // ───────────────────────── Subcampañas (temas) ─────────────────────────

    /**
     * Guarda las filas del formulario de subcampañas: [['id'?, 'nombre', 'programa_id', 'subprograma', 'descripcion',
     * 'is_active', 'tipos' => [tipo_id => ['on' => 1, 'cantidad' => n]], 'borrar'?], ...].
     */
    public function guardarSubcampanas(Campaign $c, array $filas): void
    {
        $conTipos = self::conTipos() && Schema::hasColumn('campaign_subcampanas', 'programa_id');
        DB::transaction(function () use ($c, $filas, $conTipos) {
            $orden = 0;
            $tiposCampana = $conTipos ? TipoPublicacion::where('campaign_id', $c->id)->pluck('id')->map(fn($v) => (int) $v)->all() : [];
            $progs = $conTipos && Schema::hasTable('campaign_programas') ? \App\Models\Programa::where('campaign_id', $c->id)->get()->keyBy('id') : collect();
            foreach ($filas as $f) {
                $id = (int) ($f['id'] ?? 0);
                $sub = $id ? Subcampana::where('campaign_id', $c->id)->find($id) : null;
                $nombre = trim((string) ($f['nombre'] ?? ''));
                if (!empty($f['borrar']) || $nombre === '') {
                    if ($sub) {
                        MetaPost::where('subcampana_id', $sub->id)->update(['subcampana_id' => null]);
                        if (ProductosService::disponible()) \App\Models\Producto::where('subcampana_id', $sub->id)->update(['subcampana_id' => null]);
                        $sub->delete();
                    }
                    continue;
                }
                $sub ??= new Subcampana(['campaign_id' => $c->id]);
                $datos = [
                    'nombre' => mb_substr($nombre, 0, 120),
                    'descripcion' => array_key_exists('descripcion', $f) ? (mb_substr(trim((string) $f['descripcion']), 0, 500) ?: null) : $sub->descripcion,
                    'is_active' => (bool) ($f['is_active'] ?? false),
                    'orden' => $orden++,
                ];
                if (array_key_exists('subprograma', $f)) $datos['subprograma'] = mb_substr(trim((string) $f['subprograma']), 0, 190) ?: null;
                if ($conTipos && array_key_exists('programa_id', $f)) {
                    $prog = $progs->get((int) $f['programa_id']);
                    $datos['programa_id'] = $prog?->id;
                    $datos['programa'] = $prog ? app(ProductosService::class)->textoPrograma($prog) : null;
                } elseif (array_key_exists('programa', $f)) {
                    $datos['programa'] = mb_substr(trim((string) $f['programa']), 0, 190) ?: null;
                }
                $sub->fill($datos);
                $cambioCert = $sub->exists && $sub->isDirty(['programa', 'subprograma']);
                $sub->save();
                if ($cambioCert) $this->copiarAPublicaciones($sub);
                if ($conTipos && array_key_exists('tipos', $f)) {
                    $sync = [];
                    foreach ((array) $f['tipos'] as $tipoId => $t) {
                        if (!in_array((int) $tipoId, $tiposCampana, true) || empty($t['on'])) continue;
                        $cant = $t['cantidad'] ?? null;
                        $sync[(int) $tipoId] = ['cantidad' => $cant === null || $cant === '' ? null : max(0, (int) $cant)];
                    }
                    $sub->tipos()->sync($sync);
                }
            }
        });
    }

    /** Programa y subprograma de la subcampaña en sus publicaciones sin pieza (las con pieza usan los de la pieza). */
    public function copiarAPublicaciones(Subcampana $s): int
    {
        return MetaPost::where('subcampana_id', $s->id)->when(ProductosService::disponible(), fn($q) => $q->whereNull('producto_id'))
            ->update(['programa' => $s->programa, 'subprograma' => $s->subprograma]);
    }

    /** Campos de la publicación según la subcampaña y el tipo elegidos (sin pieza). */
    public function camposDe(?Subcampana $s, ?string $tipo = null): array
    {
        $out = [];
        if ($s) $out['subcampana_id'] = $s->id;
        if ($tipo) $out['tipo_pieza'] = $tipo;
        if ($s && ($s->programa || $s->subprograma)) $out += ['programa' => $s->programa, 'subprograma' => $s->subprograma];
        return $out;
    }

    /** Tipos (nombres singulares) que se pueden publicar en la subcampaña: los asignados o, si no tiene, todos. */
    public function tiposDe(Campaign $c, ?Subcampana $s): array
    {
        if ($s && self::conTipos()) {
            $asignados = $s->tipos()->where('campaign_tipos.is_active', true)->get()->map(fn($x) => $x->etiquetaSingular())->unique()->values()->all();
            if ($asignados) return $asignados;
        }
        return $this->tipos($c);
    }

    // ───────────────────────── Tipos de publicación ─────────────────────────

    /**
     * Guarda las filas del formulario de tipos: [['id'?, 'nombre', 'etiqueta', 'clase', 'tipo', 'formato', 'unidad',
     * 'plantilla', 'titulo_informe', 'is_active', 'borrar'?], ...]. Si cambia el nombre singular, se actualizan
     * las publicaciones y los cupos de ese tipo.
     */
    public function guardarTipos(Campaign $c, array $filas): void
    {
        DB::transaction(function () use ($c, $filas) {
            $orden = 0;
            foreach ($filas as $f) {
                $id = (int) ($f['id'] ?? 0);
                $t = $id ? TipoPublicacion::where('campaign_id', $c->id)->find($id) : null;
                $nombre = trim((string) ($f['nombre'] ?? ''));
                if (!empty($f['borrar']) || $nombre === '') {
                    if ($t) {
                        if (ProductosService::disponible()) \App\Models\Producto::where('tipo_id', $t->id)->update(['tipo_id' => null]);
                        $t->delete();
                    }
                    continue;
                }
                $t ??= new TipoPublicacion(['campaign_id' => $c->id]);
                $antes = $t->exists ? $t->etiquetaSingular() : null;
                $txt = fn($k, $max) => array_key_exists($k, $f) ? (mb_substr(trim((string) $f[$k]), 0, $max) ?: null) : $t->{$k};
                $clase = (string) ($f['clase'] ?? $t->clase ?? 'redes');
                $t->fill([
                    'nombre' => mb_substr($nombre, 0, 120),
                    'etiqueta' => $txt('etiqueta', 80), 'tipo' => $txt('tipo', 40), 'formato' => $txt('formato', 80),
                    'unidad' => $txt('unidad', 120), 'plantilla' => $txt('plantilla', 500), 'titulo_informe' => $txt('titulo_informe', 120),
                    'clase' => array_key_exists($clase, TipoPublicacion::CLASES) ? $clase : 'redes',
                    'is_active' => (bool) ($f['is_active'] ?? false),
                    'orden' => $orden++,
                ]);
                $t->save();
                $despues = $t->etiquetaSingular();
                if ($antes !== null && $antes !== $despues) {
                    MetaPost::where('campaign_id', $c->id)->where('tipo_pieza', $antes)->update(['tipo_pieza' => $despues]);
                    CampaignCupo::where('campaign_id', $c->id)->get()->filter(fn($q) => self::clave($q->tipo_pieza) === self::clave($antes))
                        ->each(fn($q) => $q->update(['tipo_pieza' => $despues]));
                }
            }
        });
    }

    // ───────────────────────── Cupos ─────────────────────────

    /** [0 => [clave tipo => cantidad] (por defecto), meta_page_id => [clave tipo => cantidad]]. */
    public function cupos(Campaign $c): array
    {
        $out = [];
        foreach (CampaignCupo::where('campaign_id', $c->id)->get() as $q) $out[(int) $q->meta_page_id][self::clave($q->tipo_pieza)] = (int) $q->cantidad;
        return $out;
    }

    /** Cupo de una página para un tipo: el de la página o, si no tiene, el de por defecto (null = sin límite). */
    public function cupoDe(array $cupos, int $paginaId, string $tipo): ?int
    {
        $k = self::clave($tipo);
        return $cupos[$paginaId][$k] ?? $cupos[0][$k] ?? null;
    }

    /**
     * Guarda la matriz del formulario: $matriz['defecto'][tipo] = por defecto, $matriz[page_id][tipo] = de la página.
     * Vacío = sin cupo propio (la página usa el de por defecto).
     */
    public function guardarCupos(Campaign $c, array $matriz): void
    {
        $tipos = $this->tipos($c);
        $paginas = app(CampanasService::class)->paginasDe($c)->pluck('id')->map(fn($v) => (int) $v)->all();
        DB::transaction(function () use ($c, $matriz, $tipos, $paginas) {
            CampaignCupo::where('campaign_id', $c->id)->delete();
            $filas = [];
            foreach ($matriz as $pagina => $porTipo) {
                $paginaId = $pagina === 'defecto' ? null : (int) $pagina;
                if ($paginaId !== null && !in_array($paginaId, $paginas, true)) continue;
                foreach ($tipos as $i => $t) {
                    $v = $porTipo[$i] ?? null;
                    if ($v === null || $v === '' || !is_numeric($v) || (int) $v < 0) continue;
                    $filas[] = ['campaign_id' => $c->id, 'meta_page_id' => $paginaId, 'tipo_pieza' => $t, 'cantidad' => (int) $v, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            if ($filas) CampaignCupo::insert($filas);
        });
    }

    /**
     * Piezas ya publicadas (o en cola) por página y tipo: [meta_page_id => [clave tipo => n]].
     * Opcionalmente solo de una subcampaña.
     */
    public function usados(Campaign $c, ?int $subcampanaId = null): array
    {
        $filas = MetaPost::where('campaign_id', $c->id)->whereNotIn('status', self::FALLIDOS)->whereNotNull('tipo_pieza')
            ->when($subcampanaId, fn($q, $v) => $q->where('subcampana_id', $v))
            ->get(['id', 'meta_page_id', 'tipo_pieza', 'batch_uuid']);
        $vistos = [];
        $out = [];
        foreach ($filas as $p) {
            $k = self::clave($p->tipo_pieza);
            $lote = $p->meta_page_id . '|' . $k . '|' . ($p->batch_uuid ?: 'id' . $p->id);
            if (isset($vistos[$lote])) continue;
            $vistos[$lote] = true;
            $out[(int) $p->meta_page_id][$k] = ($out[(int) $p->meta_page_id][$k] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Antes de publicar: páginas que ya llegaron a su cupo de ese tipo.
     * @return array<int, array{pagina: string, usados: int, cupo: int}>
     */
    public function excedidos(Campaign $c, array $paginaIds, string $tipo): array
    {
        $cupos = $this->cupos($c);
        if (!$cupos) return [];
        $usados = $this->usados($c);
        $k = self::clave($tipo);
        $out = [];
        $nombres = MetaPage::whereIn('id', $paginaIds)->pluck('name', 'id');
        foreach ($paginaIds as $id) {
            $id = (int) $id;
            $cupo = $this->cupoDe($cupos, $id, $tipo);
            if ($cupo === null) continue;
            $u = $usados[$id][$k] ?? 0;
            if ($u + 1 > $cupo) $out[] = ['pagina' => (string) ($nombres[$id] ?? "#{$id}"), 'usados' => $u, 'cupo' => $cupo];
        }
        return $out;
    }

    /**
     * Avance de cupos para el tablero: filas por página con [tipo => [usados, cupo|null]].
     * @return Collection<int, array{pagina: MetaPage, tipos: array}>
     */
    public function avance(Campaign $c, ?int $subcampanaId = null): Collection
    {
        $tipos = $this->tipos($c);
        $cupos = $this->cupos($c);
        $usados = $this->usados($c, $subcampanaId);
        return app(CampanasService::class)->paginasDe($c)->sortBy('name')->values()->map(function ($pg) use ($tipos, $cupos, $usados, $subcampanaId) {
            $fila = [];
            foreach ($tipos as $t) {
                $k = self::clave($t);
                $fila[$t] = [$usados[(int) $pg->id][$k] ?? 0, $subcampanaId ? null : $this->cupoDe($cupos, (int) $pg->id, $t)];
            }
            return ['pagina' => $pg, 'tipos' => $fila];
        });
    }

    /** Para el formulario de publicar: [meta_page_id => [clave tipo => [usados, cupo]]] solo donde hay cupo. */
    public function cuposParaPublicar(Campaign $c): array
    {
        $cupos = $this->cupos($c);
        if (!$cupos) return [];
        $usados = $this->usados($c);
        $out = [];
        foreach (app(CampanasService::class)->paginasDe($c) as $pg) {
            foreach ($this->tipos($c) as $t) {
                $cupo = $this->cupoDe($cupos, (int) $pg->id, $t);
                if ($cupo !== null) $out[(int) $pg->id][self::clave($t)] = [$usados[(int) $pg->id][self::clave($t)] ?? 0, $cupo];
            }
        }
        return $out;
    }

    // ───────────────────────── Métricas ─────────────────────────

    /**
     * Métricas de la campaña (publicaciones exitosas): totales, por subcampaña, por tipo de pieza y por página.
     * Si se pasa una subcampaña (o 0 = sin subcampaña), todo se limita a ella salvo «por_subcampana».
     */
    public function metricas(Campaign $c, ?int $subcampanaId = null): array
    {
        $posts = MetaPost::with('page:id,name')->where('campaign_id', $c->id)->where('status', 'success')
            ->get(['id', 'meta_page_id', 'subcampana_id', 'tipo_pieza', 'type', 'network', 'batch_uuid', 'alcance', 'visualizaciones', 'interacciones', 'published_at']);
        $resumen = function (Collection $g) {
            $piezas = $g->map(fn($p) => $p->meta_page_id . '|' . ($p->batch_uuid ?: 'id' . $p->id))->unique()->count();
            return [
                'piezas' => $piezas, 'publicaciones' => $g->count(),
                'alcance' => (int) $g->sum('alcance'), 'visualizaciones' => (int) $g->sum('visualizaciones'), 'interacciones' => (int) $g->sum('interacciones'),
                'ultima' => $g->max('published_at'),
            ];
        };
        $subs = Subcampana::where('campaign_id', $c->id)->orderBy('orden')->orderBy('id')->get();
        $porSub = $subs->map(fn($s) => ['sub' => $s] + $resumen($posts->where('subcampana_id', $s->id)));
        $sinSub = $posts->whereNull('subcampana_id');
        if ($sinSub->isNotEmpty()) $porSub->push(['sub' => null] + $resumen($sinSub));

        $sel = $subcampanaId === null ? $posts : ($subcampanaId === 0 ? $sinSub : $posts->where('subcampana_id', $subcampanaId));
        return [
            'total' => $resumen($posts),
            'seleccion' => $resumen($sel),
            'por_subcampana' => $porSub,
            'por_tipo' => $sel->groupBy(fn($p) => $this->cert->tipoPieza($p))->map($resumen)->sortByDesc('piezas'),
            'por_pagina' => $sel->groupBy('meta_page_id')->map(fn($g) => ['pagina' => $g->first()->page?->name ?? '—'] + $resumen($g))->sortByDesc('alcance')->values(),
            'subcampanas' => $subs,
        ];
    }
}
