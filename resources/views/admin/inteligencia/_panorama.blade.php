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
            default => $v,
        };
    };
    $sufijoKpi = fn(array $k) => $k['sufijo'] ?? match ($k['formato']) { 'mil' => 'por cada 1.000 alcanzados', 'pts' => 'puntos (−100 a +100)', default => '' };
    $colorEstado = ['bien' => 'text-emerald-700 bg-emerald-50', 'atencion' => 'text-rose-700 bg-rose-50', 'neutral' => 'text-gray-600 bg-gray-100'];
    $nivelCal = ['alta' => ['Confiable', 'bg-emerald-50 text-emerald-800 border-emerald-200'], 'media' => ['Aceptable', 'bg-amber-50 text-amber-900 border-amber-200'], 'baja' => ['Insuficiente', 'bg-rose-50 text-rose-800 border-rose-200']][$cal['nivel']];
    $iconoHallazgo = ['positivo' => ['▲', 'text-emerald-600 bg-emerald-50'], 'alerta' => ['!', 'text-rose-600 bg-rose-50'], 'info' => ['i', 'text-indigo-600 bg-indigo-50']];
    $pa = $av['pronosticos']['alcance'] ?? null;
@endphp

{{-- Calidad de los datos --}}
<div class="rounded-2xl border {{ $nivelCal[1] }} px-4 py-3 mb-5">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
        <span class="font-semibold">Calidad de los datos: {{ $nivelCal[0] }}</span>
        <span>{{ $fmt($cal['publicaciones']) }} publicaciones</span>
        @if ($cal['pct_metricas'] !== null)<span>{{ $cal['pct_metricas'] }} % con métricas</span>@endif
        @if ($cal['pct_clasificadas'] !== null)<span>{{ $cal['pct_clasificadas'] }} % con tema</span>@endif
        <span>{{ $cal['leidas'] }} de {{ $cal['con_comentarios'] }} publicaciones con comentarios leídas ({{ $fmt($cal['comentarios_leidos']) }} comentarios)</span>
        <span>{{ $cal['dias_con_datos'] }} días con datos de página</span>
    </div>
    @if ($cal['faltantes'])
        <ul class="mt-2 text-xs space-y-0.5 list-disc ml-5">@foreach ($cal['faltantes'] as $f)<li>{{ $f['texto'] }}</li>@endforeach</ul>
    @endif
</div>

{{-- Indicadores clave --}}
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">
    @foreach ($av['kpis'] as $k)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm min-w-0" title="{{ $k['ayuda'] }}">
            <div class="text-[11px] uppercase tracking-wide text-gray-400 truncate">{{ $k['nombre'] }}</div>
            <div class="{{ $k['formato'] === 'texto' ? 'text-xl leading-tight break-words' : 'text-2xl truncate' }} font-bold text-gray-900 mt-0.5">{{ $valorKpi($k) }}</div>
            @if ($sufijoKpi($k))<div class="text-[11px] text-gray-400 truncate">{{ $sufijoKpi($k) }}</div>@endif
            @if (($k['variacion'] ?? null) !== null)
                <div class="mt-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold {{ $colorEstado[$k['estado']] }}">
                    {{ $k['variacion'] > 0 ? '▲' : ($k['variacion'] < 0 ? '▼' : '=') }} {{ $k['variacion'] > 0 ? '+' : '' }}{{ str_replace('.', ',', (string) $k['variacion']) }}{{ $k['variacion_unidad'] }}
                </div>
                <div class="text-[10px] text-gray-400 mt-0.5">vs. periodo anterior</div>
            @elseif ($k['formato'] !== 'texto')
                <div class="text-[10px] text-gray-400 mt-1.5">sin periodo anterior para comparar</div>
            @endif
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-5 gap-5 mb-5">
    {{-- Hallazgos --}}
    <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 class="font-bold text-gray-800">Lo que dicen los datos</h3>
            <a href="{{ $urlTab('diagnostico') }}" class="text-xs font-semibold text-indigo-700 hover:underline whitespace-nowrap">✦ Diagnóstico IA →</a>
        </div>
        @forelse ($av['hallazgos'] as $h)
            @php [$ic, $cl] = $iconoHallazgo[$h['tipo']] ?? $iconoHallazgo['info']; @endphp
            <div class="flex gap-3 py-2 border-t border-gray-100 first:border-0 text-sm">
                <span class="shrink-0 w-6 h-6 rounded-full grid place-items-center text-xs font-bold {{ $cl }}">{{ $ic }}</span>
                <span class="text-gray-700">{{ $h['texto'] }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">Todavía no hay datos suficientes para sacar conclusiones. Recolecta y lee comentarios.</p>
        @endforelse
    </div>
    {{-- Evolución 12 semanas --}}
    <div class="lg:col-span-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
            <h3 class="font-bold text-gray-800">Evolución de las últimas 12 semanas</h3>
            @if ($pa && $pa['disponible'])
                <a href="{{ $urlTab('tendencias') }}" class="text-xs text-gray-500 hover:underline">Pronóstico de alcance:
                    <b class="{{ $pa['direccion'] === 'sube' ? 'text-emerald-700' : ($pa['direccion'] === 'baja' ? 'text-rose-600' : 'text-gray-700') }}">{{ $pa['direccion'] === 'sube' ? '▲ sube' : ($pa['direccion'] === 'baja' ? '▼ baja' : '= estable') }}{{ $pa['cambio'] !== null && $pa['direccion'] !== 'estable' ? ' ' . abs($pa['cambio']) . ' %' : '' }}</b> · confianza {{ $pa['confianza'] }} →</a>
            @endif
        </div>
        <div class="h-64"><canvas id="avSemanal"></canvas></div>
    </div>
</div>
