<?php

namespace App\Services\Inteligencia;

use App\Models\AudienciaDiaria;
use App\Models\PublicacionRed;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Análisis avanzado (sin IA, reproducible): calidad de los datos, indicadores por enfoque con
 * variación frente al periodo anterior, comportamiento de la audiencia, impulsores del alcance,
 * tendencias semanales, pronósticos con intervalo de confianza, emoción frente a rendimiento,
 * hallazgos en lenguaje claro y un simulador de publicación.
 *
 * Los pronósticos son regresiones lineales sobre semanas: dicen hacia dónde va la serie si nada
 * cambia, con un rango del 80 %. La IA (DiagnosticoService) los interpreta, no los inventa.
 */
class InteligenciaAvanzadaService
{
    public const FRANJAS = ['madrugada' => 'Madrugada (0–5 h)', 'manana' => 'Mañana (6–11 h)', 'tarde' => 'Tarde (12–17 h)', 'noche' => 'Noche (18–23 h)'];
    public const DIAS_LARGOS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    public const COLORES_EMOCION = ['alegria' => '#f59e0b', 'confianza' => '#10b981', 'esperanza' => '#06b6d4', 'enojo' => '#ef4444', 'miedo' => '#8b5cf6', 'tristeza' => '#3b82f6', 'desconfianza' => '#92400e', 'indiferencia' => '#94a3b8'];
    private const SEMANAS = 12;

    public function __construct(private AnalisisService $analisis)
    {
    }

    public function analizar(Collection $paginas, Collection $temas, Carbon $desde, Carbon $hasta, string $enfoque, array $tablero): array
    {
        $ids = $paginas->pluck('id')->all();
        $pubs = $this->publicaciones($ids, $desde, $hasta);
        $dias = (int) round($desde->diffInDays($hasta)) + 1;
        $prevHasta = $desde->copy()->subDay();
        $prevDesde = $prevHasta->copy()->subDays($dias - 1);
        $pubsPrev = $this->publicaciones($ids, $prevDesde, $prevHasta);
        $resPrev = $this->analisis->resumenPaginas($paginas, $prevDesde, $prevHasta);
        $comPrev = $this->analisis->comentarios($pubsPrev);

        $comportamiento = $this->comportamiento($pubs);
        $impulsores = $this->impulsores($pubs);
        $semanal = $this->semanal($ids, $hasta);
        $pronosticos = $this->pronosticos($semanal, $enfoque);
        $emociones = $this->emocionVsRendimiento($pubs);
        $kpis = $this->kpis($enfoque, $tablero, $resPrev, $comPrev, $comportamiento, $this->comportamiento($pubsPrev)['por_mil']);
        $calidad = $this->calidad($pubs, $tablero, $temas, $ids, $desde, $hasta);

        $base = [
            'enfoque' => $enfoque, 'enfoque_nombre' => Enfoque::NOMBRES[$enfoque], 'etiqueta_intencion' => Enfoque::etiquetaIntencion($enfoque),
            'periodo_anterior' => ['desde' => $prevDesde->toDateString(), 'hasta' => $prevHasta->toDateString()],
            'calidad' => $calidad, 'kpis' => $kpis, 'comportamiento' => $comportamiento, 'impulsores' => $impulsores,
            'semanal' => $semanal, 'pronosticos' => $pronosticos, 'emociones' => $emociones,
            'peores' => $pubs->filter(fn($p) => $p->alcance)->sortBy(fn($p) => (int) $p->alcance)->take(5)->values()->map(fn($p) => $this->analisis->pubResumen($p))->all(),
            'opciones_simulador' => [
                'formatos' => $pubs->pluck('tipo')->filter()->unique()->values()->map(fn($t) => ['valor' => $t, 'nombre' => AnalisisService::TIPOS[$t] ?? ucfirst((string) $t)])->all(),
                'franjas' => collect(self::FRANJAS)->map(fn($n, $k) => ['valor' => $k, 'nombre' => $n])->values()->all(),
                'dias' => collect(self::DIAS_LARGOS)->map(fn($n, $k) => ['valor' => $k, 'nombre' => $n])->values()->all(),
                'temas' => $temas->map(fn($t) => ['valor' => $t->id, 'nombre' => $t->nombre])->values()->all(),
                'paginas' => $paginas->map(fn($p) => ['valor' => $p->id, 'nombre' => (string) $p->name])->values()->all(),
            ],
        ];
        $base['hallazgos'] = $this->hallazgos($base, $tablero, $enfoque);
        return $base;
    }

    // ------------------------------------------------------------------ datos

    private function publicaciones(array $ids, Carbon $desde, Carbon $hasta): Collection
    {
        return PublicacionRed::with('tema', 'analisis')->whereIn('meta_page_id', $ids)
            ->whereBetween('publicado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])->orderBy('publicado_en')->get();
    }

    public static function franja(Carbon $f): string
    {
        $h = (int) $f->format('G');
        return $h < 6 ? 'madrugada' : ($h < 12 ? 'manana' : ($h < 18 ? 'tarde' : 'noche'));
    }

    private static function mediana(Collection $valores): float
    {
        return AnalisisService::percentil($valores->map(fn($v) => (float) $v)->sort()->values(), 50);
    }

    private static function porMil(int $parte, int $alcance): ?float
    {
        return $alcance > 0 ? round(1000 * $parte / $alcance, 1) : null;
    }

    // ---------------------------------------------------------- comportamiento

    /** Cómo reacciona la audiencia: mezcla de interacciones, intensidad por cada 1.000 alcanzados, franjas, días y frecuencia. */
    public function comportamiento(Collection $pubs): array
    {
        $conAlc = $pubs->filter(fn($p) => $p->alcance);
        $alc = (int) $conAlc->sum('alcance');
        $tipos = ['reacciones' => 'Reacciones', 'comentarios' => 'Comentarios', 'compartidos' => 'Compartidos', 'guardados' => 'Guardados'];
        $sumas = array_map(fn($c) => (int) $pubs->sum($c), array_combine(array_keys($tipos), array_keys($tipos)));
        $totalInt = max(1, array_sum($sumas));
        $mezcla = [];
        foreach ($tipos as $k => $n) $mezcla[] = ['clave' => $k, 'nombre' => $n, 'n' => $sumas[$k], 'pct' => round(100 * $sumas[$k] / $totalInt, 1)];

        $porMil = [
            'viralidad' => self::porMil((int) $conAlc->sum('compartidos'), $alc),
            'conversacion' => self::porMil((int) $conAlc->sum('comentarios'), $alc),
            'guardados' => self::porMil((int) $conAlc->sum('guardados'), $alc),
            'reacciones' => self::porMil((int) $conAlc->sum('reacciones'), $alc),
        ];

        $grupo = function (Collection $g, string $nombre) {
            $s = $this->analisis->statsGrupo($g, $nombre);
            $s['mediana_alcance'] = (int) round(self::mediana($g->filter(fn($p) => $p->alcance)->pluck('alcance')));
            return $s;
        };
        $franjas = [];
        foreach (self::FRANJAS as $k => $n) $franjas[] = $grupo($pubs->filter(fn($p) => self::franja($p->publicado_en) === $k), $n) + ['clave' => $k];
        $dias = [];
        foreach (self::DIAS_LARGOS as $i => $n) $dias[] = $grupo($pubs->filter(fn($p) => (int) $p->publicado_en->format('w') === $i), $n) + ['clave' => $i];

        // Frecuencia: publicaciones por página y por día frente al alcance de cada publicación
        $cubetas = ['1' => 'Una al día', '2-3' => '2 a 3 al día', '4-6' => '4 a 6 al día', '7+' => '7 o más al día'];
        $acum = array_fill_keys(array_keys($cubetas), ['dias' => 0, 'alcance' => [], 'tasa' => []]);
        foreach ($conAlc->groupBy(fn($p) => $p->meta_page_id . '|' . $p->publicado_en->toDateString()) as $g) {
            $n = $g->count();
            $c = $n <= 1 ? '1' : ($n <= 3 ? '2-3' : ($n <= 6 ? '4-6' : '7+'));
            $acum[$c]['dias']++;
            $acum[$c]['alcance'][] = $g->avg(fn($p) => (int) $p->alcance);
            $acum[$c]['tasa'][] = $g->avg(fn($p) => 100 * (int) $p->interacciones / max(1, (int) $p->alcance));
        }
        $frecuencia = [];
        foreach ($cubetas as $k => $n) {
            $a = $acum[$k];
            $frecuencia[] = ['clave' => $k, 'nombre' => $n, 'dias' => $a['dias'],
                'alcance_por_publicacion' => $a['dias'] ? (int) round(array_sum($a['alcance']) / $a['dias']) : null,
                'tasa' => $a['dias'] ? round(array_sum($a['tasa']) / $a['dias'], 2) : null];
        }
        $validas = array_filter($frecuencia, fn($f) => $f['dias'] >= 3 && $f['alcance_por_publicacion'] !== null);
        usort($validas, fn($a, $b) => $b['alcance_por_publicacion'] <=> $a['alcance_por_publicacion']);

        return ['mezcla' => $mezcla, 'por_mil' => $porMil, 'franjas' => $franjas, 'dias' => $dias, 'frecuencia' => $frecuencia,
            'frecuencia_recomendada' => $validas[0] ?? null, 'publicaciones' => $pubs->count()];
    }

    /** Rasgos de cada publicación que se comparan entre sí. */
    private static function rasgos(PublicacionRed $p): array
    {
        $t = trim((string) $p->texto);
        $largo = mb_strlen($t);
        return [
            'Formato' => AnalisisService::TIPOS[$p->tipo] ?? ucfirst((string) $p->tipo),
            'Franja horaria' => self::FRANJAS[self::franja($p->publicado_en)],
            'Día' => self::DIAS_LARGOS[(int) $p->publicado_en->format('w')],
            'Largo del texto' => $largo < 80 ? 'Texto corto (menos de 80 caracteres)' : ($largo <= 300 ? 'Texto medio (80 a 300)' : 'Texto largo (más de 300)'),
            'Pregunta al público' => str_contains($t, '?') ? 'Con pregunta' : 'Sin pregunta',
            'Hashtags' => preg_match('/(^|\s)#\w/u', $t) ? 'Con hashtags' : 'Sin hashtags',
            'Enlace' => preg_match('#https?://#i', $t) ? 'Con enlace en el texto' : 'Sin enlace en el texto',
            'Emojis' => preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $t) ? 'Con emojis' : 'Sin emojis',
            'Tema' => $p->tema?->nombre ?? 'Sin tema',
        ];
    }

    /**
     * Impulsores del alcance: para cada rasgo (formato, franja, día, largo, pregunta…) compara la
     * mediana de alcance y de tasa de interacción con la mediana general. El efecto se suaviza
     * cuando hay pocas publicaciones (n/(n+5)) para no exagerar casos aislados.
     */
    public function impulsores(Collection $pubs): array
    {
        $con = $pubs->filter(fn($p) => (int) $p->alcance > 0)->values();
        $baseAlc = self::mediana($con->pluck('alcance'));
        $baseTasa = self::mediana($con->map(fn($p) => 100 * (int) $p->interacciones / (int) $p->alcance));
        $grupos = [];
        foreach ($con as $p) foreach (self::rasgos($p) as $rasgo => $valor) $grupos[$rasgo][$valor][] = $p;
        $lista = [];
        foreach ($grupos as $rasgo => $valores) {
            if (count($valores) < 2) continue; // un rasgo con un solo valor no compara nada
            foreach ($valores as $valor => $items) {
                $n = count($items);
                if ($n < 3 || $baseAlc <= 0) continue;
                $g = collect($items);
                $mAlc = self::mediana($g->pluck('alcance'));
                $mTasa = self::mediana($g->map(fn($p) => 100 * (int) $p->interacciones / (int) $p->alcance));
                $peso = $n / ($n + 5);
                $efAlc = ($mAlc / $baseAlc - 1) * $peso;
                $efTasa = $baseTasa > 0 ? ($mTasa / $baseTasa - 1) * $peso : 0;
                $lista[] = ['rasgo' => $rasgo, 'valor' => $valor, 'n' => $n, 'mediana_alcance' => (int) round($mAlc), 'mediana_tasa' => round($mTasa, 2),
                    'efecto_alcance' => (int) round(100 * $efAlc), 'efecto_tasa' => (int) round(100 * $efTasa)];
            }
        }
        usort($lista, fn($a, $b) => $b['efecto_alcance'] <=> $a['efecto_alcance']);
        return [
            'base' => ['n' => $con->count(), 'mediana_alcance' => (int) round($baseAlc), 'mediana_tasa' => round($baseTasa, 2)],
            'positivos' => array_values(array_slice(array_filter($lista, fn($x) => $x['efecto_alcance'] >= 5), 0, 8)),
            'negativos' => array_values(array_slice(array_reverse(array_filter($lista, fn($x) => $x['efecto_alcance'] <= -5)), 0, 6)),
            'todos' => $lista,
        ];
    }

    // ------------------------------------------------------- tendencias y pronóstico

    /** Últimas 12 semanas (terminando en la fecha "hasta"): alcance, interacciones, tasa, seguidores, emociones y favorabilidad. */
    public function semanal(array $ids, Carbon $hasta): array
    {
        $inicio = $hasta->copy()->subDays(7 * self::SEMANAS - 1)->startOfDay();
        $pubs = $this->publicaciones($ids, $inicio, $hasta);
        $diario = AudienciaDiaria::whereIn('meta_page_id', $ids)->whereBetween('fecha', [$inicio->toDateString() . ' 00:00:00', $hasta->toDateString() . ' 23:59:59'])->orderBy('fecha')->get();
        $out = [];
        for ($i = 0; $i < self::SEMANAS; $i++) {
            $a = $inicio->copy()->addDays(7 * $i);
            $b = $a->copy()->addDays(6)->endOfDay();
            $g = $pubs->filter(fn($p) => $p->publicado_en->between($a, $b));
            $d = $diario->filter(fn($r) => $r->fecha->between($a->copy()->startOfDay(), $b));
            $alc = (int) $g->sum('alcance');
            $seg = 0; $hay = false;
            foreach ($d->groupBy(fn($r) => $r->meta_page_id . ':' . $r->red) as $serie) {
                $ult = $serie->whereNotNull('seguidores')->last();
                if ($ult) { $seg += (int) $ult->seguidores; $hay = true; }
            }
            $an = $g->filter(fn($p) => $p->analisis);
            $tot = (int) $an->sum(fn($p) => $p->analisis->total);
            $fav = (int) $an->sum(fn($p) => $p->analisis->a_favor); $con = (int) $an->sum(fn($p) => $p->analisis->en_contra);
            $emo = array_fill_keys(array_keys(ConsultorService::EMOCIONES), 0);
            foreach ($an as $p) foreach ((array) ($p->analisis->emociones ?? []) as $k => $v) if (isset($emo[$k])) $emo[$k] += (int) $v;
            $sumEmo = array_sum($emo);
            $out[] = [
                'inicio' => $a->toDateString(), 'fin' => $b->toDateString(), 'etiqueta' => $a->format('d/m'),
                'publicaciones' => $g->count(), 'alcance' => $alc, 'interacciones' => (int) $g->sum('interacciones'),
                'tasa' => $alc > 0 ? round(100 * (int) $g->sum('interacciones') / $alc, 2) : null,
                'alcance_prom' => $g->count() ? (int) round($alc / $g->count()) : null,
                'compartidos' => (int) $g->sum('compartidos'), 'reproducciones' => (int) $g->sum('reproducciones'),
                'alcance_pagina' => (int) $d->sum('alcance'), 'nuevos_seguidores' => (int) $d->sum('nuevos_seguidores'),
                'seguidores' => $hay ? $seg : null,
                'comentarios_leidos' => $tot,
                'favorabilidad' => $tot >= 15 ? (int) round(100 * ($fav - $con) / max(1, $tot)) : null,
                'intencion' => $tot >= 15 ? round(100 * (int) $an->sum(fn($p) => (int) ($p->analisis->intencion ?? 0)) / $tot, 1) : null,
                'emociones' => $sumEmo ? array_map(fn($v) => round(100 * $v / $sumEmo), $emo) : null,
            ];
        }
        return $out;
    }

    /** Regresión lineal con intervalo de predicción del 80 %. null si hay menos de 4 puntos. */
    public static function regresion(array $valores, int $pasos = 4, ?float $min = 0.0, ?float $max = null): ?array
    {
        $xs = []; $ys = [];
        foreach (array_values($valores) as $i => $v) if ($v !== null) { $xs[] = $i; $ys[] = (float) $v; }
        $n = count($xs);
        if ($n < 4) return null;
        $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
        $sxx = 0.0; $sxy = 0.0;
        for ($i = 0; $i < $n; $i++) { $sxx += ($xs[$i] - $mx) ** 2; $sxy += ($xs[$i] - $mx) * ($ys[$i] - $my); }
        if ($sxx <= 0) return null;
        $b = $sxy / $sxx; $a = $my - $b * $mx;
        $sse = 0.0; $sst = 0.0;
        for ($i = 0; $i < $n; $i++) { $sse += ($ys[$i] - ($a + $b * $xs[$i])) ** 2; $sst += ($ys[$i] - $my) ** 2; }
        $s = sqrt($sse / max(1, $n - 2));
        $r2 = $sst > 0 ? max(0, 1 - $sse / $sst) : 0;
        $acotar = function ($v) use ($min, $max) { if ($min !== null) $v = max($min, $v); if ($max !== null) $v = min($max, $v); return $v; };
        $ultimo = count($valores) - 1;
        $futuro = [];
        for ($k = 1; $k <= $pasos; $k++) {
            $x = $ultimo + $k;
            $y = $a + $b * $x;
            $e = 1.2816 * $s * sqrt(1 + 1 / $n + (($x - $mx) ** 2) / $sxx);
            $futuro[] = ['paso' => $k, 'esperado' => $acotar($y), 'bajo' => $acotar($y - $e), 'alto' => $acotar($y + $e)];
        }
        return ['pendiente' => $b, 'r2' => round($r2, 2), 'n' => $n, 'futuro' => $futuro, 'promedio' => $my];
    }

    public function pronosticos(array $semanal, string $enfoque): array
    {
        $metricas = [
            'alcance' => ['Alcance semanal de publicaciones', 0, null, 'num'],
            'interacciones' => ['Interacciones semanales', 0, null, 'num'],
            'tasa' => ['Tasa de interacción semanal', 0, 100, 'pct'],
            'seguidores' => ['Seguidores', 0, null, 'num'],
        ];
        if ($enfoque === 'politica') $metricas['favorabilidad'] = ['Favorabilidad neta en comentarios', -100, 100, 'pts'];
        if ($enfoque === 'comercio') $metricas['intencion'] = ['Comentarios con intención de compra', 0, 100, 'pct'];
        if ($enfoque === 'medio') $metricas['compartidos'] = ['Compartidos por semana', 0, null, 'num'];

        $etiquetaFutura = function (int $k) use ($semanal) {
            $ultimo = end($semanal)['inicio'] ?? now()->toDateString();
            return Carbon::parse($ultimo)->addDays(7 * $k)->format('d/m');
        };
        $out = [];
        foreach ($metricas as $clave => [$nombre, $min, $max, $formato]) {
            $serie = array_map(fn($s) => $s[$clave], $semanal);
            // Semanas sin publicaciones no cuentan para alcance e interacciones (no son "cero alcance")
            if (in_array($clave, ['alcance', 'interacciones', 'tasa', 'compartidos'], true)) {
                $serie = array_map(fn($s) => $s['publicaciones'] > 0 ? $s[$clave] : null, $semanal);
            }
            $r = self::regresion($serie, 4, $min, $max);
            $hist = array_values(array_filter($serie, fn($v) => $v !== null));
            $ult4 = array_slice($hist, -4);
            $item = ['clave' => $clave, 'nombre' => $nombre, 'formato' => $formato, 'historico' => $serie, 'etiquetas' => array_column($semanal, 'etiqueta'), 'disponible' => (bool) $r];
            if ($r) {
                $fut = array_map(fn($f) => $f + ['etiqueta' => $etiquetaFutura($f['paso'])], $r['futuro']);
                $promFut = array_sum(array_column($fut, 'esperado')) / count($fut);
                $promUlt = $ult4 ? array_sum($ult4) / count($ult4) : 0;
                $cambio = $formato === 'num' ? ($promUlt != 0 ? round(100 * ($promFut - $promUlt) / abs($promUlt)) : null) : round($promFut - $promUlt, 1);
                $umbral = $formato === 'num' ? 5 : 1;
                $item += [
                    'futuro' => $fut, 'r2' => $r['r2'], 'n' => $r['n'],
                    'cambio' => $cambio, 'cambio_unidad' => $formato === 'num' ? '%' : ' pts',
                    'direccion' => $cambio === null ? 'estable' : ($cambio >= $umbral ? 'sube' : ($cambio <= -$umbral ? 'baja' : 'estable')),
                    'confianza' => ($r['n'] >= 8 && $r['r2'] >= 0.5) ? 'alta' : (($r['n'] >= 5 && $r['r2'] >= 0.25) ? 'media' : 'baja'),
                ];
            }
            $out[$clave] = $item;
        }
        return $out;
    }

    // ---------------------------------------------------------------- emociones

    /** Qué emoción domina en los comentarios de cada publicación y cómo rinde cada una. */
    public function emocionVsRendimiento(Collection $pubs): array
    {
        $con = $pubs->filter(fn($p) => $p->analisis && $p->alcance && array_sum((array) ($p->analisis->emociones ?? [])) > 0);
        if ($con->count() < 3) return ['suficiente' => false, 'n' => $con->count(), 'lista' => []];
        $base = self::mediana($con->pluck('alcance'));
        $lista = [];
        foreach ($con->groupBy(function ($p) { $e = (array) $p->analisis->emociones; arsort($e); return array_key_first($e); }) as $emo => $g) {
            $m = self::mediana($g->pluck('alcance'));
            $lista[] = ['clave' => $emo, 'nombre' => ConsultorService::EMOCIONES[$emo] ?? $emo, 'n' => $g->count(), 'mediana_alcance' => (int) round($m),
                'mediana_tasa' => round(self::mediana($g->map(fn($p) => 100 * (int) $p->interacciones / (int) $p->alcance)), 2),
                'veces' => $base > 0 ? round($m / $base, 2) : null];
        }
        usort($lista, fn($a, $b) => ($b['veces'] ?? 0) <=> ($a['veces'] ?? 0));
        return ['suficiente' => true, 'n' => $con->count(), 'lista' => $lista];
    }

    // --------------------------------------------------------------------- KPIs

    private function kpi(string $clave, string $nombre, $valor, string $formato, $previo, string $ayuda, bool $mejorSiSube = true): array
    {
        $variacion = null;
        if (is_numeric($valor) && is_numeric($previo)) {
            $variacion = $formato === 'num'
                ? ($previo != 0 ? (int) round(100 * ($valor - $previo) / abs($previo)) : null)
                : round($valor - $previo, 1);
        }
        $umbral = $formato === 'num' ? 5 : 1;
        $estado = $variacion === null ? 'neutral' : (($mejorSiSube ? $variacion : -$variacion) >= $umbral ? 'bien' : ((($mejorSiSube ? $variacion : -$variacion) <= -$umbral) ? 'atencion' : 'neutral'));
        return ['clave' => $clave, 'nombre' => $nombre, 'valor' => $valor, 'formato' => $formato, 'previo' => $previo, 'variacion' => $variacion,
            'variacion_unidad' => $formato === 'num' ? '%' : ' pts', 'estado' => $estado, 'ayuda' => $ayuda];
    }

    private function kpis(string $enfoque, array $t, array $prev, array $comPrev, array $comp, array $porMilPrev): array
    {
        $r = $t['resumen']; $c = $t['comentarios'];
        $prom = $r['publicaciones'] ? (int) round($r['alcance'] / $r['publicaciones']) : null;
        $promPrev = $prev['publicaciones'] ? (int) round($prev['alcance'] / $prev['publicaciones']) : null;
        $emo = collect($c['emociones'] ?? [])->sortByDesc('n')->first();
        $emoTexto = ($emo && $emo['n'] > 0) ? $emo['nombre'] : '—';
        $emoSufijo = ($emo && $emo['n'] > 0) ? $emo['pct'] . ' % de las emociones' : 'sin lectura todavía';
        $alcance = $this->kpi('alcance', 'Alcance de publicaciones', $r['alcance'], 'num', $prev['alcance'], 'Personas únicas que vieron las publicaciones del periodo.');
        $tasa = $this->kpi('tasa', 'Tasa de interacción', $r['tasa'], 'pct', $prev['tasa'], 'Interacciones por cada 100 personas alcanzadas.');
        $seg = $this->kpi('nuevos_seguidores', 'Nuevos seguidores', $r['nuevos_seguidores'], 'num', $prev['nuevos_seguidores'], 'Seguidores ganados en el periodo (Facebook e Instagram).');
        return match ($enfoque) {
            'politica' => [
                $this->kpi('favorabilidad', 'Favorabilidad neta', $c['favorabilidad_neta'], 'pts', $comPrev['favorabilidad_neta'], 'Comentarios a favor menos en contra, sobre el total leído (de −100 a +100).'),
                ['clave' => 'emocion', 'nombre' => 'Emoción dominante', 'valor' => $emoTexto, 'sufijo' => $emoSufijo, 'formato' => 'texto', 'variacion' => null, 'estado' => 'neutral', 'ayuda' => 'La emoción más frecuente en los comentarios leídos.'],
                $alcance, $tasa,
                $this->kpi('conversacion', 'Conversación', $comp['por_mil']['conversacion'], 'mil', $porMilPrev['conversacion'], 'Comentarios por cada 1.000 personas alcanzadas.'),
                $seg,
            ],
            'comercio' => [
                $this->kpi('interacciones', 'Interacciones', $r['interacciones'], 'num', $prev['interacciones'], 'Reacciones, comentarios, compartidos y guardados.'),
                $tasa,
                $this->kpi('intencion', 'Intención de compra', $c['pct_intencion'], 'pct', $comPrev['pct_intencion'], 'Porcentaje de comentarios leídos que preguntan precio, disponibilidad o cómo comprar.'),
                $this->kpi('preguntas', 'Preguntas de clientes', array_sum($c['preguntas'] ?? []), 'num', array_sum($comPrev['preguntas'] ?? []), 'Preguntas repetidas detectadas en los comentarios.'),
                $alcance,
                $this->kpi('guardados', 'Guardados', $comp['por_mil']['guardados'], 'mil', $porMilPrev['guardados'], 'Guardados por cada 1.000 alcanzados: señal de interés por volver a ver el producto.'),
            ],
            default => [
                $alcance,
                $this->kpi('alcance_prom', 'Alcance por publicación', $prom, 'num', $promPrev, 'Alcance promedio de cada publicación.'),
                $tasa,
                $this->kpi('viralidad', 'Viralidad', $comp['por_mil']['viralidad'], 'mil', $porMilPrev['viralidad'], 'Compartidos por cada 1.000 personas alcanzadas.'),
                $this->kpi('reproducciones', 'Reproducciones', $r['reproducciones'], 'num', $prev['reproducciones'], 'Reproducciones de videos y reels.'),
                $seg,
            ],
        };
    }

    // ----------------------------------------------------------------- calidad

    private function calidad(Collection $pubs, array $t, Collection $temas, array $ids, Carbon $desde, Carbon $hasta): array
    {
        $n = $pubs->count();
        $conMet = $pubs->filter(fn($p) => $p->alcance !== null)->count();
        $clasif = $pubs->whereNotNull('tema_id')->count();
        $conCom = $pubs->filter(fn($p) => (int) $p->comentarios >= ComentariosService::MINIMO)->count();
        $leidas = $pubs->filter(fn($p) => $p->analisis)->count();
        $diasDatos = AudienciaDiaria::whereIn('meta_page_id', $ids)->whereBetween('fecha', [$desde->toDateString() . ' 00:00:00', $hasta->toDateString() . ' 23:59:59'])->distinct()->count('fecha');
        $pct = fn($a, $b) => $b ? (int) round(100 * $a / $b) : null;
        $faltantes = [];
        if ($n === 0) $faltantes[] = ['texto' => 'No hay publicaciones en el periodo: pulsa «Recolectar» o amplía las fechas.', 'accion' => 'recolectar'];
        if ($n && $pct($conMet, $n) < 80) $faltantes[] = ['texto' => ($n - $conMet) . ' publicaciones sin métricas de alcance: pulsa «Recolectar» (Meta las entrega con unas horas de retraso).', 'accion' => 'recolectar'];
        if ($n && $temas->isNotEmpty() && $pct($clasif, $n) < 70) $faltantes[] = ['texto' => ($n - $clasif) . ' publicaciones sin tema: pulsa «Clasificar y leer comentarios».', 'accion' => 'clasificar'];
        if ($conCom > $leidas) $faltantes[] = ['texto' => ($conCom - $leidas) . ' publicaciones con comentarios aún sin leer: sin esa lectura no hay emociones ni favorabilidad.', 'accion' => 'clasificar'];
        if ($n && $n < 10) $faltantes[] = ['texto' => 'Con menos de 10 publicaciones los patrones y pronósticos son poco confiables.', 'accion' => null];
        $nivel = ($n >= 30 && $pct($conMet, $n) >= 80 && ($conCom === 0 || $leidas >= 0.5 * $conCom)) ? 'alta' : ($n >= 10 ? 'media' : 'baja');
        return ['nivel' => $nivel, 'publicaciones' => $n, 'pct_metricas' => $pct($conMet, $n), 'pct_clasificadas' => $temas->isNotEmpty() ? $pct($clasif, $n) : null,
            'con_comentarios' => $conCom, 'leidas' => $leidas, 'comentarios_leidos' => (int) ($t['comentarios']['comentarios'] ?? 0), 'dias_con_datos' => $diasDatos, 'faltantes' => $faltantes];
    }

    // --------------------------------------------------------------- hallazgos

    /** Hallazgos en lenguaje claro, calculados (no inventados): lo que sube, lo que baja, qué impulsa y qué viene. */
    private function hallazgos(array $x, array $t, string $enfoque): array
    {
        $h = [];
        $fmt = fn($n) => number_format((float) $n, 0, ',', '.');
        foreach ($x['kpis'] as $k) {
            if (($k['variacion'] ?? null) === null || $k['formato'] === 'texto') continue;
            if (abs($k['variacion']) < ($k['formato'] === 'num' ? 15 : 3)) continue;
            $h[] = ['tipo' => $k['estado'] === 'bien' ? 'positivo' : ($k['estado'] === 'atencion' ? 'alerta' : 'info'),
                'texto' => "{$k['nombre']}: " . ($k['variacion'] > 0 ? '+' : '') . $k['variacion'] . trim($k['variacion_unidad']) . ' frente al periodo anterior.'];
        }
        if ($p = $x['impulsores']['positivos'][0] ?? null) {
            $h[] = ['tipo' => 'positivo', 'texto' => "Lo que más impulsa el alcance: «{$p['valor']}» ({$p['rasgo']}) — la mediana de alcance es {$p['efecto_alcance']} % mayor que la general ({$p['n']} publicaciones)."];
        }
        if ($p = $x['impulsores']['negativos'][0] ?? null) {
            $h[] = ['tipo' => 'alerta', 'texto' => "Lo que más frena: «{$p['valor']}» ({$p['rasgo']}) — alcance {$p['efecto_alcance']} % frente a la mediana."];
        }
        if ($f = $x['comportamiento']['frecuencia_recomendada']) {
            $h[] = ['tipo' => 'info', 'texto' => "Frecuencia que mejor rinde: {$f['nombre']} por página ({$fmt($f['alcance_por_publicacion'])} de alcance por publicación)."];
        }
        $e = $x['emociones'];
        if ($e['suficiente'] && ($top = $e['lista'][0] ?? null) && ($top['veces'] ?? 0) >= 1.2) {
            $h[] = ['tipo' => 'positivo', 'texto' => "Las publicaciones que despiertan {$this->minus($top['nombre'])} logran " . str_replace('.', ',', (string) $top['veces']) . " veces el alcance mediano."];
        }
        $emo = collect($t['comentarios']['emociones'] ?? [])->sortByDesc('n')->first();
        if ($emo && $emo['n'] > 0) {
            $neg = in_array($emo['clave'], ['enojo', 'miedo', 'tristeza', 'desconfianza'], true);
            $h[] = ['tipo' => $neg ? 'alerta' : 'info', 'texto' => "Emoción dominante en los comentarios: {$this->minus($emo['nombre'])} ({$emo['pct']} %)."];
        }
        if ($enfoque === 'politica' && ($t['comentarios']['favorabilidad_neta'] ?? null) !== null) {
            $fv = $t['comentarios']['favorabilidad_neta'];
            $h[] = ['tipo' => $fv >= 10 ? 'positivo' : ($fv <= -10 ? 'alerta' : 'info'), 'texto' => "Favorabilidad neta en comentarios: " . ($fv > 0 ? '+' : '') . "{$fv} puntos."];
        }
        if ($enfoque === 'comercio' && ($t['comentarios']['pct_intencion'] ?? null) !== null) {
            $h[] = ['tipo' => 'info', 'texto' => "El {$t['comentarios']['pct_intencion']} % de los comentarios leídos muestra intención de compra."];
        }
        $pa = $x['pronosticos']['alcance'] ?? null;
        if ($pa && $pa['disponible'] && $pa['cambio'] !== null) {
            $h[] = ['tipo' => $pa['direccion'] === 'sube' ? 'positivo' : ($pa['direccion'] === 'baja' ? 'alerta' : 'info'),
                'texto' => 'Pronóstico: si la tendencia sigue, el alcance semanal ' . ($pa['direccion'] === 'sube' ? 'subiría' : ($pa['direccion'] === 'baja' ? 'bajaría' : 'se mantendría')) . ' ' . ($pa['direccion'] !== 'estable' ? abs($pa['cambio']) . ' %' : '') . " en las próximas 4 semanas (confianza {$pa['confianza']})."];
        }
        $sube = collect($t['tendencias']['temas'] ?? [])->firstWhere('direccion', 'sube');
        if ($sube) $h[] = ['tipo' => 'positivo', 'texto' => "Tema en ascenso: {$sube['tema']}" . ($sube['cambio_pct'] !== null ? " ({$sube['cambio_pct']} % de cambio reciente en interacción)." : '.')];
        return array_slice($h, 0, 9);
    }

    /** Primera letra en mayúscula aunque la frase empiece con ¿ o ¡. */
    public static function frase(string $s): string
    {
        return preg_replace_callback('/^([¿¡"«\s]*)(\p{Ll})/u', fn($m) => $m[1] . mb_strtoupper($m[2]), $s);
    }

    private function minus(string $s): string
    {
        return mb_strtolower($s);
    }

    // --------------------------------------------------------------- simulador

    /**
     * ¿Cuánto alcanzaría una publicación? Base: mediana de la página elegida (o de todas) en los
     * últimos 90 días; se multiplica por el efecto (suavizado) de formato, franja, día y tema,
     * y por la tendencia reciente. Devuelve el esperado, un rango (cuartiles) y la explicación.
     */
    public function simular(Collection $paginas, array $p): array
    {
        $ids = $paginas->pluck('id')->all();
        $pubs = $this->publicaciones($ids, now()->subDays(90), now())->filter(fn($x) => (int) $x->alcance > 0)->values();
        $muestra = !empty($p['meta_page_id']) ? $pubs->where('meta_page_id', (int) $p['meta_page_id'])->values() : $pubs;
        if ($muestra->count() < 5) $muestra = $pubs;
        if ($muestra->count() < 5) return ['suficiente' => false, 'n' => $muestra->count()];
        $alc = $muestra->pluck('alcance')->map(fn($v) => (int) $v)->sort()->values();
        $base = AnalisisService::percentil($alc, 50);
        $baseGeneral = self::mediana($pubs->pluck('alcance'));
        $factores = [];
        $aplicar = function (string $nombre, Collection $grupo) use (&$factores, $baseGeneral) {
            $n = $grupo->count();
            if ($n < 2 || $baseGeneral <= 0) { $factores[] = ['nombre' => $nombre, 'factor' => 1.0, 'n' => $n, 'nota' => 'sin historial suficiente']; return 1.0; }
            $f = 1 + (self::mediana($grupo->pluck('alcance')) / $baseGeneral - 1) * ($n / ($n + 5));
            $f = max(0.5, min(2.0, $f));
            $factores[] = ['nombre' => $nombre, 'factor' => round($f, 2), 'n' => $n];
            return $f;
        };
        $prod = 1.0;
        if (!empty($p['formato'])) $prod *= $aplicar('Formato: ' . (AnalisisService::TIPOS[$p['formato']] ?? $p['formato']), $pubs->where('tipo', $p['formato']));
        if (!empty($p['franja']) && isset(self::FRANJAS[$p['franja']])) $prod *= $aplicar(self::FRANJAS[$p['franja']], $pubs->filter(fn($x) => self::franja($x->publicado_en) === $p['franja']));
        if (isset($p['dia']) && $p['dia'] !== '' && $p['dia'] !== null) $prod *= $aplicar(self::DIAS_LARGOS[(int) $p['dia']] ?? 'Día', $pubs->filter(fn($x) => (int) $x->publicado_en->format('w') === (int) $p['dia']));
        if (!empty($p['tema_id'])) $prod *= $aplicar('Tema: ' . ($pubs->firstWhere('tema_id', (int) $p['tema_id'])?->tema?->nombre ?? 'elegido'), $pubs->where('tema_id', (int) $p['tema_id']));
        // Tendencia: pendiente semanal relativa del alcance (acotada a ±15 %)
        $sem = $this->semanal($ids, now());
        $r = self::regresion(array_map(fn($s) => $s['publicaciones'] ? $s['alcance_prom'] : null, $sem), 1);
        $tend = $r && $r['promedio'] > 0 ? max(-0.15, min(0.15, $r['pendiente'] / $r['promedio'])) : 0.0;
        $factores[] = ['nombre' => 'Tendencia reciente', 'factor' => round(1 + $tend, 2), 'n' => $r['n'] ?? 0];
        $prod = max(0.3, min(3.0, $prod * (1 + $tend)));
        $tasa = self::mediana((!empty($p['formato']) && $pubs->where('tipo', $p['formato'])->count() >= 3 ? $pubs->where('tipo', $p['formato']) : $pubs)->map(fn($x) => 100 * (int) $x->interacciones / (int) $x->alcance));
        $esperado = $base * $prod;
        $n = $muestra->count();
        return [
            'suficiente' => true, 'n' => $n, 'confianza' => $n >= 30 ? 'alta' : ($n >= 10 ? 'media' : 'baja'),
            // Rango: la dispersión habitual de la página (cuartiles relativos a la mediana) alrededor del esperado
            'alcance' => ['bajo' => (int) round($esperado * min(1, AnalisisService::percentil($alc, 25) / max(1, $base))), 'esperado' => (int) round($esperado), 'alto' => (int) round($esperado * max(1, AnalisisService::percentil($alc, 75) / max(1, $base)))],
            'interacciones' => (int) round($esperado * $tasa / 100), 'tasa' => round($tasa, 2),
            'base' => (int) round($base), 'factores' => $factores, 'factor_total' => round($prod, 2),
        ];
    }
}
