{{-- Tendencias, pronósticos con rango y simulador de publicación. Requiere $av, $tablero, $fmt --}}
@php
    $num = fn($v) => $v === null ? '—' : str_replace('.', ',', (string) $v);
    $tr = $tablero['tendencias'];
    $op = $av['opciones_simulador'];
    $colDir = ['sube' => 'text-emerald-700 bg-emerald-50', 'baja' => 'text-rose-700 bg-rose-50', 'estable' => 'text-gray-700 bg-gray-100'];
    $valor = fn($v, $f) => $v === null ? '—' : ($f === 'num' ? $fmt($v) : ($f === 'pts' ? (($v > 0 ? '+' : '') . round($v)) : $num(round($v, 1)) . ' %'));
@endphp

<div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 px-4 py-3 mb-5 text-xs text-gray-600">
    <b class="text-gray-800">{{ __('Cómo se calcula:') }}</b> {{ __('regresión lineal sobre las últimas 12 semanas (las semanas sin publicaciones no cuentan). La línea punteada es el valor esperado') }}
    {{ __('de las próximas 4 semanas si nada cambia y la banda es el rango probable (80 %). La confianza sube con más semanas de datos y una tendencia más estable (R²).') }}
    {{ __('Es una proyección, no una promesa: úsala para decidir qué corregir.') }}
</div>

<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5 mb-5">
    @foreach ($av['pronosticos'] as $clave => $p)
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-bold text-gray-800 text-sm">{{ __($p['nombre']) }}</h3>
                @if ($p['disponible'])<span class="shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-semibold {{ $colDir[$p['direccion']] }}">{{ $p['direccion'] === 'sube' ? '▲ ' . __('sube') : ($p['direccion'] === 'baja' ? '▼ ' . __('baja') : '= ' . __('estable')) }}</span>@endif
            </div>
            @if ($p['disponible'])
                @php $f4 = end($p['futuro']); @endphp
                <div class="text-xs text-gray-500 mt-1">{{ __('En 4 semanas:') }} <b class="text-gray-800">{{ $valor($f4['esperado'], $p['formato']) }}</b> ({{ __('entre :bajo y :alto', ['bajo' => $valor($f4['bajo'], $p['formato']), 'alto' => $valor($f4['alto'], $p['formato'])]) }})
                    · {{ __('cambio :n', ['n' => $p['cambio'] !== null ? ($p['cambio'] > 0 ? '+' : '') . $num($p['cambio']) . $p['cambio_unidad'] : '—']) }} · {{ __('confianza') }} <b>{{ __($p['confianza']) }}</b></div>
                <div class="h-40 mt-2"><canvas data-pronostico="{{ $clave }}"></canvas></div>
            @else
                <p class="text-sm text-gray-500 mt-2">{{ __('Se necesitan al menos 4 semanas con datos para pronosticar.') }}</p>
            @endif
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-5 mb-5">
    <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto">
        <h3 class="font-bold text-gray-800 mb-2">{{ __('Semana a semana') }}</h3>
        <table class="w-full text-sm min-w-[680px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">{{ __('Semana') }}</th><th class="text-right">{{ __('Publ.') }}</th><th class="text-right">{{ __('Alcance') }}</th><th class="text-right">{{ __('Alcance / publ.') }}</th><th class="text-right">{{ __('Tasa') }}</th><th class="text-right">{{ __('Compartidos') }}</th><th class="text-right">{{ __('Nuevos seg.') }}</th>
            @if ($av['enfoque'] === 'politica')<th class="text-right">{{ __('Favorab.') }}</th>@elseif ($av['enfoque'] === 'comercio')<th class="text-right">{{ __('Intención') }}</th>@endif</tr></thead><tbody>
        @foreach (array_reverse($av['semanal']) as $s)
            <tr class="border-t border-gray-100"><td class="py-1.5 whitespace-nowrap">{{ \Carbon\Carbon::parse($s['inicio'])->format('d/m') }} – {{ \Carbon\Carbon::parse($s['fin'])->format('d/m') }}</td><td class="text-right">{{ $s['publicaciones'] }}</td><td class="text-right">{{ $fmt($s['alcance']) }}</td><td class="text-right">{{ $s['alcance_prom'] !== null ? $fmt($s['alcance_prom']) : '—' }}</td><td class="text-right">{{ $s['tasa'] !== null ? $num($s['tasa']) . ' %' : '—' }}</td><td class="text-right">{{ $fmt($s['compartidos']) }}</td><td class="text-right">{{ $fmt($s['nuevos_seguidores']) }}</td>
                @if ($av['enfoque'] === 'politica')<td class="text-right">{{ $s['favorabilidad'] !== null ? ($s['favorabilidad'] > 0 ? '+' : '') . $s['favorabilidad'] : '—' }}</td>@elseif ($av['enfoque'] === 'comercio')<td class="text-right">{{ $s['intencion'] !== null ? $num($s['intencion']) . ' %' : '—' }}</td>@endif</tr>
        @endforeach
        </tbody></table>
    </div>

    {{-- Simulador --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" id="simulador">
        <h3 class="font-bold text-gray-800 mb-1">{{ __('Simulador: ¿cuánto alcanzaría esta publicación?') }}</h3>
        <p class="text-xs text-gray-500 mb-3">{{ __('Parte del alcance mediano de los últimos 90 días y lo ajusta por formato, franja, día, tema y tendencia, según tu propio historial.') }}</p>
        <div class="space-y-2 text-sm">
            <select id="simPagina" class="w-full rounded-lg border-gray-300 text-sm"><option value="">{{ __('Todas las páginas del análisis') }}</option>@foreach ($op['paginas'] as $o)<option value="{{ $o['valor'] }}">{{ $o['nombre'] }}</option>@endforeach</select>
            <select id="simFormato" class="w-full rounded-lg border-gray-300 text-sm"><option value="">{{ __('Cualquier formato') }}</option>@foreach ($op['formatos'] as $o)<option value="{{ $o['valor'] }}">{{ __($o['nombre']) }}</option>@endforeach</select>
            <div class="grid grid-cols-2 gap-2">
                <select id="simFranja" class="w-full rounded-lg border-gray-300 text-sm"><option value="">{{ __('Cualquier hora') }}</option>@foreach ($op['franjas'] as $o)<option value="{{ $o['valor'] }}">{{ __($o['nombre']) }}</option>@endforeach</select>
                <select id="simDia" class="w-full rounded-lg border-gray-300 text-sm"><option value="">{{ __('Cualquier día') }}</option>@foreach ($op['dias'] as $o)<option value="{{ $o['valor'] }}">{{ __($o['nombre']) }}</option>@endforeach</select>
            </div>
            <select id="simTema" class="w-full rounded-lg border-gray-300 text-sm"><option value="">{{ __('Cualquier tema') }}</option>@foreach ($op['temas'] as $o)<option value="{{ $o['valor'] }}">{{ $o['nombre'] }}</option>@endforeach</select>
            <button type="button" id="simBoton" class="w-full rounded-lg bg-[#00024f] text-white py-2 font-semibold">{{ __('Calcular') }}</button>
        </div>
        <div id="simResultado" class="mt-4 text-sm text-gray-700"></div>
    </div>
</div>

<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto">
    <h3 class="font-bold text-gray-800 mb-1">{{ __('Tendencia por tema (últimas :n semanas)', ['n' => $tr['semanas']]) }}</h3>
    <p class="text-xs text-gray-500 mb-3">{{ __('Tasa de interacción semanal por tema. El cambio compara las 2 últimas semanas con las 2 anteriores.') }}</p>
    @if ($tr['temas'])
        <div class="h-56"><canvas id="gTendencia"></canvas></div>
        <table class="w-full text-sm mt-4 min-w-[480px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">{{ __('Tema') }}</th><th class="text-right">{{ __('Publ.') }}</th><th class="text-right">{{ __('Dirección') }}</th><th class="text-right">{{ __('Cambio') }}</th></tr></thead><tbody>
        @foreach ($tr['temas'] as $t)
            <tr class="border-t border-gray-100"><td class="py-1.5"><i class="inline-block w-2.5 h-2.5 rounded-full mr-1" style="background: {{ $t['color'] ?? '#94a3b8' }}"></i>{{ $t['tema'] }}</td><td class="text-right">{{ $t['publicaciones'] }}</td>
                <td class="text-right">@if ($t['direccion'] === 'sube')<span class="text-emerald-700 font-semibold">▲ {{ __('sube') }}</span>@elseif ($t['direccion'] === 'baja')<span class="text-rose-600 font-semibold">▼ {{ __('baja') }}</span>@elseif ($t['direccion'] === 'estable')<span class="text-gray-600">= {{ __('estable') }}</span>@else<span class="text-gray-400">{{ __('sin datos') }}</span>@endif</td>
                <td class="text-right">{{ $t['cambio_pct'] !== null ? ($t['cambio_pct'] >= 0 ? '+' : '') . $t['cambio_pct'] . ' %' : '—' }}</td></tr>
        @endforeach
        </tbody></table>
    @else
        <p class="text-sm text-gray-500">{{ __('No hay temas definidos en este alcance.') }}</p>
    @endif
</div>
