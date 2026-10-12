{{-- Panorama: calidad de datos, indicadores clave del enfoque con variación, hallazgos y evolución de 12 semanas. Requiere $av, $fmt, $urlTab --}}
@php
    $cal = $av['calidad'];
    $valorKpi = function (array $k) use ($fmt) {
        $v = $k['valor'];
        if ($v === null || $v === '') return '—';
        return match ($k['formato']) {
            'num' => $fmt($v),
            'pct' => str_replace('.', ',', (string) $v) . ' %',
            'mil' => str_replace('.', ',', (string) $v),
            'pts' => ($v > 0 ? '+' : '') . $v,
            default => __((string) $v),
        };
    };
    $sufijoKpi = fn(array $k) => $k['sufijo'] ?? match ($k['formato']) { 'mil' => 'por cada 1.000 alcanzados', 'pts' => 'puntos (−100 a +100)', default => '' }; // se traduce al mostrarlo
    $colorEstado = ['bien' => 'text-emerald-700 bg-emerald-50', 'atencion' => 'text-rose-700 bg-rose-50', 'neutral' => 'text-gray-600 bg-gray-100'];
    $nivelCal = ['alta' => [__('Confiable'), 'bg-emerald-50 text-emerald-800 border-emerald-200'], 'media' => [__('Aceptable'), 'bg-amber-50 text-amber-900 border-amber-200'], 'baja' => [__('Insuficiente'), 'bg-rose-50 text-rose-800 border-rose-200']][$cal['nivel']];
    $iconoHallazgo = ['positivo' => ['▲', 'text-emerald-600 bg-emerald-50'], 'alerta' => ['!', 'text-rose-600 bg-rose-50'], 'info' => ['i', 'text-indigo-600 bg-indigo-50']];
    $pa = $av['pronosticos']['alcance'] ?? null;
    // Los textos de calidad y hallazgos los arma InteligenciaAvanzadaService en español (también van a la IA tal cual):
    // aquí solo se traducen para mostrarlos, reconociendo cada plantilla. Si una no coincide se muestra el texto original.
    $trValor = fn(string $rasgo, string $valor) => $rasgo === 'Tema' && $valor !== 'Sin tema' ? $valor : __($valor);
    $trEmocion = function (string $emo) {
        foreach (\App\Services\Inteligencia\ConsultorService::EMOCIONES as $n) if (mb_strtolower($n) === $emo) return mb_strtolower(__($n));
        return $emo;
    };
    $trFaltante = function (string $t) {
        if (!preg_match('/^(\d+) (.+)$/su', $t, $m)) return __($t);
        $plantilla = ':n ' . $m[2]; // p. ej. «:n publicaciones sin tema: …»
        return __($plantilla, ['n' => $m[1]]);
    };
    $trHallazgo = function (string $t) use ($trValor, $trEmocion) {
        if (preg_match('/^Lo que más impulsa el alcance: «(.+?)» \(([^)]+)\) — la mediana de alcance es (-?\d+) % mayor que la general \((\d+) publicaciones\)\.$/u', $t, $m))
            return __('Lo que más impulsa el alcance: «:valor» (:rasgo) — la mediana de alcance es :efecto % mayor que la general (:n publicaciones).', ['valor' => $trValor($m[2], $m[1]), 'rasgo' => __($m[2]), 'efecto' => $m[3], 'n' => $m[4]]);
        if (preg_match('/^Lo que más frena: «(.+?)» \(([^)]+)\) — alcance (-?\d+) % frente a la mediana\.$/u', $t, $m))
            return __('Lo que más frena: «:valor» (:rasgo) — alcance :efecto % frente a la mediana.', ['valor' => $trValor($m[2], $m[1]), 'rasgo' => __($m[2]), 'efecto' => $m[3]]);
        if (preg_match('/^Frecuencia que mejor rinde: (.+) por página \(([\d.]+) de alcance por publicación\)\.$/u', $t, $m))
            return __('Frecuencia que mejor rinde: :frecuencia por página (:alcance de alcance por publicación).', ['frecuencia' => __($m[1]), 'alcance' => $m[2]]);
        if (preg_match('/^Las publicaciones que despiertan (.+) logran ([\d,]+) veces el alcance mediano\.$/u', $t, $m))
            return __('Las publicaciones que despiertan :emocion logran :veces veces el alcance mediano.', ['emocion' => $trEmocion($m[1]), 'veces' => $m[2]]);
        if (preg_match('/^Emoción dominante en los comentarios: (.+) \((\d+) %\)\.$/u', $t, $m))
            return __('Emoción dominante en los comentarios: :emocion (:pct %).', ['emocion' => $trEmocion($m[1]), 'pct' => $m[2]]);
        if (preg_match('/^Favorabilidad neta en comentarios: ([+-]?\d+) puntos\.$/u', $t, $m))
            return __('Favorabilidad neta en comentarios: :n puntos.', ['n' => $m[1]]);
        if (preg_match('/^El ([\d.]+) % de los comentarios leídos muestra intención de compra\.$/u', $t, $m))
            return __('El :pct % de los comentarios leídos muestra intención de compra.', ['pct' => $m[1]]);
        if (preg_match('/^Pronóstico: si la tendencia sigue, el alcance semanal (subiría|bajaría) (\d+) % en las próximas 4 semanas \(confianza (\w+)\)\.$/u', $t, $m))
            return __($m[1] === 'subiría' ? 'Pronóstico: si la tendencia sigue, el alcance semanal subiría :n % en las próximas 4 semanas (confianza :confianza).' : 'Pronóstico: si la tendencia sigue, el alcance semanal bajaría :n % en las próximas 4 semanas (confianza :confianza).', ['n' => $m[2], 'confianza' => __($m[3])]);
        if (preg_match('/^Pronóstico: si la tendencia sigue, el alcance semanal se mantendría\s+en las próximas 4 semanas \(confianza (\w+)\)\.$/u', $t, $m))
            return __('Pronóstico: si la tendencia sigue, el alcance semanal se mantendría en las próximas 4 semanas (confianza :confianza).', ['confianza' => __($m[1])]);
        if (preg_match('/^Tema en ascenso: (.+) \((-?[\d.]+) % de cambio reciente en interacción\)\.$/u', $t, $m))
            return __('Tema en ascenso: :tema (:cambio % de cambio reciente en interacción).', ['tema' => $m[1], 'cambio' => $m[2]]);
        if (preg_match('/^Tema en ascenso: (.+)\.$/u', $t, $m))
            return __('Tema en ascenso: :tema.', ['tema' => $m[1]]);
        if (preg_match('/^(.+): ([+-]?[\d.]+(?:%|pts)) frente al periodo anterior\.$/u', $t, $m))
            return __(':indicador: :variacion frente al periodo anterior.', ['indicador' => __($m[1]), 'variacion' => $m[2]]);
        return __($t);
    };
    $trSufijo = fn(string $s) => preg_match('/^(\d+) % de las emociones$/u', $s, $m) ? __(':n % de las emociones', ['n' => $m[1]]) : __($s);
@endphp

{{-- Calidad de los datos --}}
<div class="rounded-2xl border {{ $nivelCal[1] }} px-4 py-3 mb-5">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
        <span class="font-semibold">{{ __('Calidad de los datos: :nivel', ['nivel' => $nivelCal[0]]) }}</span>
        <span>{{ __(':n publicaciones', ['n' => $fmt($cal['publicaciones'])]) }}</span>
        @if ($cal['pct_metricas'] !== null)<span>{{ __(':n % con métricas', ['n' => $cal['pct_metricas']]) }}</span>@endif
        @if ($cal['pct_clasificadas'] !== null)<span>{{ __(':n % con tema', ['n' => $cal['pct_clasificadas']]) }}</span>@endif
        <span>{{ __(':leidas de :total publicaciones con comentarios leídas (:comentarios comentarios)', ['leidas' => $cal['leidas'], 'total' => $cal['con_comentarios'], 'comentarios' => $fmt($cal['comentarios_leidos'])]) }}</span>
        <span>{{ __(':n días con datos de página', ['n' => $cal['dias_con_datos']]) }}</span>
    </div>
    @if ($cal['faltantes'])
        <ul class="mt-2 text-xs space-y-0.5 list-disc ml-5">@foreach ($cal['faltantes'] as $f)<li>{{ $trFaltante($f['texto']) }}</li>@endforeach</ul>
    @endif
</div>

{{-- Indicadores clave --}}
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">
    @foreach ($av['kpis'] as $k)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm min-w-0" title="{{ __($k['ayuda']) }}">
            <div class="text-[11px] uppercase tracking-wide text-gray-400 truncate">{{ __($k['nombre']) }}</div>
            <div class="{{ $k['formato'] === 'texto' ? 'text-xl leading-tight break-words' : 'text-2xl truncate' }} font-bold text-gray-900 mt-0.5">{{ $valorKpi($k) }}</div>
            @if ($sufijoKpi($k))<div class="text-[11px] text-gray-400 truncate">{{ $trSufijo($sufijoKpi($k)) }}</div>@endif
            @if (($k['variacion'] ?? null) !== null)
                <div class="mt-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold {{ $colorEstado[$k['estado']] }}">
                    {{ $k['variacion'] > 0 ? '▲' : ($k['variacion'] < 0 ? '▼' : '=') }} {{ $k['variacion'] > 0 ? '+' : '' }}{{ str_replace('.', ',', (string) $k['variacion']) }}{{ $k['variacion_unidad'] }}
                </div>
                <div class="text-[10px] text-gray-400 mt-0.5">{{ __('vs. periodo anterior') }}</div>
            @elseif ($k['formato'] !== 'texto')
                <div class="text-[10px] text-gray-400 mt-1.5">{{ __('sin periodo anterior para comparar') }}</div>
            @endif
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-5 gap-5 mb-5">
    {{-- Hallazgos --}}
    <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 class="font-bold text-gray-800">{{ __('Lo que dicen los datos') }}</h3>
            <a href="{{ $urlTab('diagnostico') }}" class="text-xs font-semibold text-indigo-700 hover:underline whitespace-nowrap">✦ {{ __('Diagnóstico IA') }} →</a>
        </div>
        @forelse ($av['hallazgos'] as $h)
            @php [$ic, $cl] = $iconoHallazgo[$h['tipo']] ?? $iconoHallazgo['info']; @endphp
            <div class="flex gap-3 py-2 border-t border-gray-100 first:border-0 text-sm">
                <span class="shrink-0 w-6 h-6 rounded-full grid place-items-center text-xs font-bold {{ $cl }}">{{ $ic }}</span>
                <span class="text-gray-700">{{ $trHallazgo($h['texto']) }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('Todavía no hay datos suficientes para sacar conclusiones. Recolecta y lee comentarios.') }}</p>
        @endforelse
    </div>
    {{-- Evolución 12 semanas --}}
    <div class="lg:col-span-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
            <h3 class="font-bold text-gray-800">{{ __('Evolución de las últimas 12 semanas') }}</h3>
            @if ($pa && $pa['disponible'])
                <a href="{{ $urlTab('tendencias') }}" class="text-xs text-gray-500 hover:underline">{{ __('Pronóstico de alcance:') }}
                    <b class="{{ $pa['direccion'] === 'sube' ? 'text-emerald-700' : ($pa['direccion'] === 'baja' ? 'text-rose-600' : 'text-gray-700') }}">{{ $pa['direccion'] === 'sube' ? '▲ ' . __('sube') : ($pa['direccion'] === 'baja' ? '▼ ' . __('baja') : '= ' . __('estable')) }}{{ $pa['cambio'] !== null && $pa['direccion'] !== 'estable' ? ' ' . abs($pa['cambio']) . ' %' : '' }}</b> · {{ __('confianza :nivel', ['nivel' => __($pa['confianza'])]) }} →</a>
            @endif
        </div>
        <div class="h-64"><canvas id="avSemanal"></canvas></div>
    </div>
</div>
