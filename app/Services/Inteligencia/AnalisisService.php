<?php

namespace App\Services\Inteligencia;

use App\Models\AudienciaDiaria;
use App\Models\Campana;
use App\Models\ComentarioAnalisis;
use App\Models\PublicacionRed;
use App\Models\Tema;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Análisis agregado de una campaña en un rango de fechas: qué temas y formatos
 * funcionan, con qué público (demografía), cuándo (horarios), qué dice la gente
 * (comentarios) y pronósticos sencillos (tendencia por tema, mejor momento,
 * alcance esperado). Los cálculos se hacen en PHP sobre los datos recolectados,
 * así que funcionan igual en MySQL y en SQLite.
 */
class AnalisisService
{
    public const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    public const TIPOS = ['foto' => 'Foto', 'video' => 'Video', 'reel' => 'Reel', 'carrusel' => 'Carrusel', 'enlace' => 'Enlace', 'texto' => 'Texto', 'en_vivo' => 'En vivo'];

    public function tablero(Campana $campana, Carbon $desde, Carbon $hasta): array
    {
        return $this->tableroPaginas($campana->paginas()->get(), $campana->temas()->get(), $desde, $hasta);
    }

    /**
     * Mismo tablero para cualquier conjunto de páginas (vista general de la organización,
     * un medio, una sola página…). $temas: los temas con los que se agrupa (los de todas
     * las campañas en la vista general).
     */
    public function tableroPaginas(Collection $paginas, Collection $temas, Carbon $desde, Carbon $hasta): array
    {
        $ids = $paginas->pluck('id')->all();
        $pubs = PublicacionRed::with('tema', 'analisis')->whereIn('meta_page_id', $ids)
            ->whereBetween('publicado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->orderBy('publicado_en')->get();
        // La fecha se compara como texto en SQLite y como DATE en MySQL: el tope con hora cubre ambos casos
        $diario = AudienciaDiaria::whereIn('meta_page_id', $ids)->whereBetween('fecha', [$desde->toDateString() . ' 00:00:00', $hasta->toDateString() . ' 23:59:59'])->orderBy('fecha')->get();

        return [
            'rango' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'dias' => $desde->diffInDays($hasta) + 1],
            'resumen' => $this->resumen($pubs, $diario, $ids),
            'por_tema' => $this->porTema($pubs, $temas),
            'por_formato' => $this->porFormato($pubs),
            'por_pagina' => $this->porPagina($pubs, $diario, $paginas),
            'por_red' => $this->porRed($pubs),
            'serie' => $this->serie($pubs, $diario, $desde, $hasta),
            'horarios' => $this->horarios($pubs, $ids),
            'demografia' => $this->demografia($ids),
            'comentarios' => $this->comentarios($pubs),
            'tendencias' => $this->tendencias($ids, $temas, $hasta),
            'mejores' => $pubs->sortByDesc(fn($p) => (int) $p->interacciones)->take(10)->values()->map(fn($p) => $this->pubResumen($p))->all(),
            'sin_tema' => $pubs->whereNull('tema_id')->count(),
        ];
    }

    /** Solo el resumen (publicaciones, alcance, interacciones, tasa, seguidores) de un conjunto de páginas: para comparar campañas contra el total. */
    public function resumenPaginas(Collection $paginas, Carbon $desde, Carbon $hasta): array
    {
        $ids = $paginas->pluck('id')->all();
        $pubs = PublicacionRed::whereIn('meta_page_id', $ids)->whereBetween('publicado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])->get();
        $diario = AudienciaDiaria::whereIn('meta_page_id', $ids)->whereBetween('fecha', [$desde->toDateString() . ' 00:00:00', $hasta->toDateString() . ' 23:59:59'])->orderBy('fecha')->get();
        return $this->resumen($pubs, $diario, $ids);
    }

    // ------------------------------------------------------------- bloques

    private function resumen(Collection $pubs, Collection $diario, array $ids): array
    {
        $alcance = (int) $pubs->sum('alcance');
        $inter = (int) $pubs->sum('interacciones');
        // Seguidores: último y primer valor disponible por página y red
        $seguidoresFin = 0; $seguidoresIni = 0; $nuevos = 0;
        foreach ($diario->groupBy(fn($d) => $d->meta_page_id . ':' . $d->red) as $serie) {
            $con = $serie->whereNotNull('seguidores');
            if ($con->isNotEmpty()) { $seguidoresFin += (int) $con->last()->seguidores; $seguidoresIni += (int) $con->first()->seguidores; }
            $nuevos += (int) $serie->sum('nuevos_seguidores');
        }
        return [
            'publicaciones' => $pubs->count(),
            'alcance' => $alcance,
            'interacciones' => $inter,
            'tasa' => $alcance > 0 ? round(100 * $inter / $alcance, 2) : null,
            'alcance_pagina_dia' => (int) $diario->sum('alcance'),
            'seguidores' => $seguidoresFin,
            'seguidores_variacion' => $seguidoresFin - $seguidoresIni,
            'nuevos_seguidores' => $nuevos,
            'reproducciones' => (int) $pubs->sum('reproducciones'),
            'con_tema' => $pubs->whereNotNull('tema_id')->count(),
        ];
    }

    private function porTema(Collection $pubs, Collection $temas): array
    {
        $out = [];
        $grupos = $pubs->groupBy(fn($p) => $p->tema_id ?? 0);
        foreach ($temas as $t) $out[$t->id] = $this->statsGrupo($grupos->get($t->id, collect()), $t->nombre, $t->color);
        if ($grupos->has(0)) $out[0] = $this->statsGrupo($grupos->get(0), 'Sin tema', '#94a3b8');
        $lista = array_values(array_filter($out, fn($x) => $x['n'] > 0));
        usort($lista, fn($a, $b) => ($b['tasa'] ?? -1) <=> ($a['tasa'] ?? -1));
        return $lista;
    }

    private function porFormato(Collection $pubs): array
    {
        $lista = [];
        foreach ($pubs->groupBy('tipo') as $tipo => $g) $lista[] = $this->statsGrupo($g, self::TIPOS[$tipo] ?? ucfirst((string) $tipo)) + ['tipo' => $tipo];
        usort($lista, fn($a, $b) => ($b['alcance_prom'] ?? 0) <=> ($a['alcance_prom'] ?? 0));
        return $lista;
    }

    private function porPagina(Collection $pubs, Collection $diario, Collection $paginas): array
    {
        $out = [];
        foreach ($paginas as $p) {
            $g = $pubs->where('meta_page_id', $p->id);
            $d = $diario->where('meta_page_id', $p->id);
            $seg = $d->where('red', 'facebook')->whereNotNull('seguidores')->last()?->seguidores;
            $segIg = $d->where('red', 'instagram')->whereNotNull('seguidores')->last()?->seguidores;
            $out[] = $this->statsGrupo($g, $p->name) + [
                'id' => $p->id, 'page_id' => $p->page_id, 'foto' => $p->picture_url,
                'seguidores_facebook' => $seg !== null ? (int) $seg : null,
                'seguidores_instagram' => $segIg !== null ? (int) $segIg : null,
                'nuevos_seguidores' => (int) $d->sum('nuevos_seguidores'),
                'alcance_pagina' => (int) $d->sum('alcance'),
            ];
        }
        usort($out, fn($a, $b) => $b['alcance'] <=> $a['alcance']);
        return $out;
    }

    private function porRed(Collection $pubs): array
    {
        $out = [];
        foreach ($pubs->groupBy('red') as $red => $g) $out[$red] = $this->statsGrupo($g, ucfirst((string) $red));
        return $out;
    }

    private function serie(Collection $pubs, Collection $diario, Carbon $desde, Carbon $hasta): array
    {
        $out = [];
        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            $f = $d->toDateString();
            $out[$f] = ['fecha' => $f, 'alcance_pagina' => 0, 'publicaciones' => 0, 'alcance' => 0, 'interacciones' => 0];
        }
        foreach ($diario as $r) {
            $f = $r->fecha->toDateString();
            if (isset($out[$f])) $out[$f]['alcance_pagina'] += (int) $r->alcance;
        }
        foreach ($pubs as $p) {
            $f = $p->publicado_en->toDateString();
            if (!isset($out[$f])) continue;
            $out[$f]['publicaciones']++;
            $out[$f]['alcance'] += (int) $p->alcance;
            $out[$f]['interacciones'] += (int) $p->interacciones;
        }
        return array_values($out);
    }

    /** Matriz día×hora de interacciones promedio por publicación + seguidores en línea (de Meta). */
    private function horarios(Collection $pubs, array $ids): array
    {
        $suma = []; $cuenta = [];
        foreach ($pubs as $p) {
            $d = (int) $p->publicado_en->format('w'); $h = (int) $p->publicado_en->format('G');
            $suma[$d][$h] = ($suma[$d][$h] ?? 0) + (int) $p->interacciones;
            $cuenta[$d][$h] = ($cuenta[$d][$h] ?? 0) + 1;
        }
        $matriz = [];
        $ranking = [];
        for ($d = 0; $d < 7; $d++) for ($h = 0; $h < 24; $h++) {
            $n = $cuenta[$d][$h] ?? 0;
            $prom = $n ? (int) round($suma[$d][$h] / $n) : null;
            $matriz[$d][$h] = ['n' => $n, 'prom' => $prom];
            if ($n >= 2) $ranking[] = ['dia' => $d, 'hora' => $h, 'n' => $n, 'prom' => $prom, 'etiqueta' => self::DIAS[$d] . ' ' . $h . ':00'];
        }
        usort($ranking, fn($a, $b) => $b['prom'] <=> $a['prom']);

        // Seguidores en línea (última foto con horarios de cada página)
        $online = [];
        foreach (AudienciaDiaria::whereIn('meta_page_id', $ids)->whereNotNull('horarios')->orderByDesc('fecha')->get()->unique(fn($r) => $r->meta_page_id . ':' . $r->red) as $r) {
            foreach ((array) $r->horarios as $d => $horas) foreach ((array) $horas as $h => $v) $online[(int) $d][(int) $h] = ($online[(int) $d][(int) $h] ?? 0) + (int) $v;
        }
        $mejorOnline = [];
        foreach ($online as $d => $horas) foreach ($horas as $h => $v) $mejorOnline[] = ['dia' => $d, 'hora' => $h, 'en_linea' => $v, 'etiqueta' => self::DIAS[$d] . ' ' . $h . ':00'];
        usort($mejorOnline, fn($a, $b) => $b['en_linea'] <=> $a['en_linea']);

        return ['matriz' => $matriz, 'mejores_publicar' => array_slice($ranking, 0, 6), 'en_linea' => $online, 'mejores_en_linea' => array_slice($mejorOnline, 0, 6)];
    }

    /** Demografía combinada (última foto por página y red): edad/género, ciudades y países. */
    private function demografia(array $ids): array
    {
        $edadGenero = []; $ciudad = []; $pais = []; $fuentes = 0;
        $filas = AudienciaDiaria::whereIn('meta_page_id', $ids)->whereNotNull('demografia')->orderByDesc('fecha')->get()->unique(fn($r) => $r->meta_page_id . ':' . $r->red);
        foreach ($filas as $r) {
            $d = (array) $r->demografia;
            if (!array_filter($d)) continue;
            $fuentes++;
            foreach ((array) ($d['edad_genero'] ?? []) as $k => $v) {
                // Facebook: "F.25-34"; Instagram: "25-34|F" o "25-34|M"
                [$edad, $genero] = self::partirEdadGenero((string) $k);
                $edadGenero[$edad][$genero] = ($edadGenero[$edad][$genero] ?? 0) + (int) $v;
            }
            foreach ((array) ($d['ciudad'] ?? []) as $k => $v) $ciudad[$k] = ($ciudad[$k] ?? 0) + (int) $v;
            foreach ((array) ($d['pais'] ?? []) as $k => $v) $pais[$k] = ($pais[$k] ?? 0) + (int) $v;
        }
        ksort($edadGenero);
        arsort($ciudad); arsort($pais);
        $totalEG = array_sum(array_map('array_sum', $edadGenero));
        $genero = ['F' => 0, 'M' => 0, 'U' => 0];
        foreach ($edadGenero as $e => $g) foreach ($g as $gen => $v) $genero[$gen] = ($genero[$gen] ?? 0) + $v;
        return [
            'fuentes' => $fuentes,
            'edad_genero' => $edadGenero,
            'genero' => $genero,
            'total' => $totalEG,
            'ciudades' => array_slice($ciudad, 0, 12, true),
            'paises' => array_slice($pais, 0, 8, true),
        ];
    }

    private static function partirEdadGenero(string $k): array
    {
        if (str_contains($k, '|')) { [$edad, $genero] = explode('|', $k, 2); }
        elseif (str_contains($k, '.')) { [$genero, $edad] = explode('.', $k, 2); }
        else { return [$k, 'U']; }
        $genero = strtoupper(substr(trim($genero), 0, 1));
        return [trim($edad), in_array($genero, ['F', 'M'], true) ? $genero : 'U'];
    }

    /** Lectura agregada de comentarios en el rango: tono global, por tema, preocupaciones y palabras más repetidas. */
    private function comentarios(Collection $pubs): array
    {
        $con = $pubs->filter(fn($p) => $p->analisis);
        $tot = ['publicaciones' => $con->count(), 'comentarios' => 0, 'a_favor' => 0, 'en_contra' => 0, 'neutro' => 0];
        $preocupaciones = []; $palabras = []; $porTema = []; $resumenes = [];
        $emociones = array_fill_keys(array_keys(ConsultorService::EMOCIONES), 0);
        foreach ($con as $p) {
            $a = $p->analisis;
            $tot['comentarios'] += $a->total; $tot['a_favor'] += $a->a_favor; $tot['en_contra'] += $a->en_contra; $tot['neutro'] += $a->neutro;
            foreach ((array) ($a->emociones ?? []) as $k => $v) if (isset($emociones[$k])) $emociones[$k] += (int) $v;
            foreach ((array) $a->preocupaciones as $x) { $k = mb_strtolower(trim($x)); if ($k !== '') $preocupaciones[$k] = ($preocupaciones[$k] ?? 0) + 1; }
            foreach ((array) $a->palabras as $x) { $k = mb_strtolower(trim($x)); if ($k !== '') $palabras[$k] = ($palabras[$k] ?? 0) + 1; }
            $nombre = $p->tema?->nombre ?? 'Sin tema';
            $porTema[$nombre] ??= ['tema' => $nombre, 'comentarios' => 0, 'a_favor' => 0, 'en_contra' => 0, 'neutro' => 0];
            $porTema[$nombre]['comentarios'] += $a->total; $porTema[$nombre]['a_favor'] += $a->a_favor; $porTema[$nombre]['en_contra'] += $a->en_contra; $porTema[$nombre]['neutro'] += $a->neutro;
            if ($a->resumen) $resumenes[] = ['publicacion' => $this->pubResumen($p), 'resumen' => $a->resumen];
        }
        arsort($preocupaciones); arsort($palabras); arsort($emociones);
        $base = max(1, $tot['a_favor'] + $tot['en_contra'] + $tot['neutro']);
        $baseEmo = max(1, array_sum($emociones));
        return $tot + [
            'emociones' => array_map(fn($k, $v) => ['clave' => $k, 'nombre' => ConsultorService::EMOCIONES[$k], 'n' => $v, 'pct' => (int) round(100 * $v / $baseEmo)], array_keys($emociones), $emociones),
            'pct_favor' => (int) round(100 * $tot['a_favor'] / $base), 'pct_contra' => (int) round(100 * $tot['en_contra'] / $base), 'pct_neutro' => (int) round(100 * $tot['neutro'] / $base),
            'preocupaciones' => array_slice($preocupaciones, 0, 12, true),
            'palabras' => array_slice($palabras, 0, 25, true),
            'por_tema' => array_values($porTema),
            'resumenes' => array_slice($resumenes, 0, 8),
        ];
    }

    /** Tendencia por tema: tasa de interacción semanal de las últimas 8 semanas y pendiente (sube/baja/estable). */
    public function tendencias(array $ids, Collection $temas, Carbon $hasta, int $semanas = 8): array
    {
        $desde = $hasta->copy()->subWeeks($semanas)->startOfDay();
        $pubs = PublicacionRed::whereIn('meta_page_id', $ids)->where('publicado_en', '>=', $desde)->where('publicado_en', '<=', $hasta->copy()->endOfDay())->get();
        $out = [];
        foreach ($temas as $t) {
            $serie = array_fill(0, $semanas, null);
            $n = array_fill(0, $semanas, 0);
            $suma = array_fill(0, $semanas, 0.0);
            foreach ($pubs->where('tema_id', $t->id) as $p) {
                $i = (int) floor($desde->diffInDays($p->publicado_en) / 7);
                if ($i < 0 || $i >= $semanas || !$p->alcance) continue;
                $suma[$i] += 100 * (int) $p->interacciones / $p->alcance;
                $n[$i]++;
            }
            for ($i = 0; $i < $semanas; $i++) if ($n[$i]) $serie[$i] = round($suma[$i] / $n[$i], 2);
            $pend = self::pendiente($serie);
            $ult = array_values(array_filter(array_slice($serie, -2), fn($v) => $v !== null));
            $prev = array_values(array_filter(array_slice($serie, -4, 2), fn($v) => $v !== null));
            $cambio = ($ult && $prev && array_sum($prev) > 0) ? round(100 * (array_sum($ult) / count($ult) - array_sum($prev) / count($prev)) / (array_sum($prev) / count($prev))) : null;
            $puntos = count(array_filter($serie, fn($v) => $v !== null));
            $out[] = [
                'tema_id' => $t->id, 'tema' => $t->nombre, 'color' => $t->color, 'serie' => $serie, 'publicaciones' => array_sum($n),
                'pendiente' => $pend, 'cambio_pct' => $cambio,
                'direccion' => $puntos < 2 ? 'sin datos' : ($pend > 0.08 ? 'sube' : ($pend < -0.08 ? 'baja' : 'estable')),
            ];
        }
        usort($out, fn($a, $b) => ($b['pendiente'] ?? 0) <=> ($a['pendiente'] ?? 0));
        return ['semanas' => $semanas, 'temas' => $out];
    }

    /**
     * Alcance e interacciones esperados si se publica un tema en una página
     * (mediana de las últimas publicaciones del tema en esa página, ajustada por tendencia).
     */
    public function proyeccion(Tema $tema, int $metaPageId, ?string $tipo = null): array
    {
        $q = PublicacionRed::where('meta_page_id', $metaPageId)->where('tema_id', $tema->id)->whereNotNull('alcance')->orderByDesc('publicado_en');
        if ($tipo) $q->where('tipo', $tipo);
        $pubs = $q->limit(12)->get();
        if ($pubs->count() < 3) return ['suficiente' => false, 'n' => $pubs->count()];
        $alc = $pubs->pluck('alcance')->map(fn($v) => (int) $v)->sort()->values();
        $int = $pubs->pluck('interacciones')->map(fn($v) => (int) $v)->sort()->values();
        $tend = $this->tendencias([$metaPageId], collect([$tema]), Carbon::today())['temas'][0] ?? null;
        $factor = 1 + max(-0.3, min(0.3, (float) ($tend['pendiente'] ?? 0) / 10));
        return [
            'suficiente' => true, 'n' => $pubs->count(), 'factor_tendencia' => round($factor, 2),
            'alcance' => ['bajo' => (int) round(self::percentil($alc, 25) * $factor), 'esperado' => (int) round(self::percentil($alc, 50) * $factor), 'alto' => (int) round(self::percentil($alc, 75) * $factor)],
            'interacciones' => ['bajo' => (int) round(self::percentil($int, 25) * $factor), 'esperado' => (int) round(self::percentil($int, 50) * $factor), 'alto' => (int) round(self::percentil($int, 75) * $factor)],
        ];
    }

    // -------------------------------------------------------------- ayudas

    private function statsGrupo(Collection $g, string $nombre, ?string $color = null): array
    {
        $n = $g->count();
        $alc = (int) $g->sum('alcance'); $int = (int) $g->sum('interacciones');
        $conAlc = $g->filter(fn($p) => $p->alcance);
        $tasas = $conAlc->map(fn($p) => 100 * (int) $p->interacciones / $p->alcance);
        $formatos = $g->groupBy('tipo')->map(fn($x) => $x->avg(fn($p) => (int) $p->interacciones))->sortDesc();
        return [
            'nombre' => $nombre, 'color' => $color, 'n' => $n,
            'alcance' => $alc, 'interacciones' => $int, 'reproducciones' => (int) $g->sum('reproducciones'),
            'alcance_prom' => $n ? (int) round($alc / $n) : 0, 'interacciones_prom' => $n ? (int) round($int / $n) : 0,
            'tasa' => $tasas->isNotEmpty() ? round($tasas->avg(), 2) : null,
            'mejor_formato' => $formatos->keys()->first() ? (self::TIPOS[$formatos->keys()->first()] ?? $formatos->keys()->first()) : null,
            'comentarios' => (int) $g->sum('comentarios'), 'compartidos' => (int) $g->sum('compartidos'),
        ];
    }

    private function pubResumen(PublicacionRed $p): array
    {
        return [
            'id' => $p->id, 'red' => $p->red, 'tipo' => $p->tipo, 'texto' => \Illuminate\Support\Str::limit(trim((string) $p->texto), 140, '…'),
            'permalink' => $p->permalink, 'fecha' => $p->publicado_en->format('d/m/Y H:i'), 'tema' => $p->tema?->nombre,
            'alcance' => (int) $p->alcance, 'interacciones' => (int) $p->interacciones, 'tasa' => $p->tasa(), 'comentarios' => (int) $p->comentarios,
        ];
    }

    /** Pendiente de una regresión lineal sobre una serie con huecos (null). */
    public static function pendiente(array $serie): float
    {
        $xs = []; $ys = [];
        foreach ($serie as $i => $v) if ($v !== null) { $xs[] = $i; $ys[] = (float) $v; }
        $n = count($xs);
        if ($n < 2) return 0.0;
        $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
        $num = 0; $den = 0;
        for ($i = 0; $i < $n; $i++) { $num += ($xs[$i] - $mx) * ($ys[$i] - $my); $den += ($xs[$i] - $mx) ** 2; }
        return $den > 0 ? round($num / $den, 4) : 0.0;
    }

    public static function percentil(Collection $ordenada, int $p): float
    {
        $n = $ordenada->count();
        if ($n === 0) return 0.0;
        $pos = ($p / 100) * ($n - 1);
        $lo = (int) floor($pos); $hi = (int) ceil($pos);
        return $ordenada[$lo] + ($ordenada[$hi] - $ordenada[$lo]) * ($pos - $lo);
    }
}
