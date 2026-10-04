@extends('layouts.app')

@php
    $r = $tablero['resumen'];
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $dias = \App\Services\Inteligencia\AnalisisService::DIAS;
    $tabs = ['resumen' => 'Resumen', 'paginas' => 'Páginas y campañas', 'audiencia' => 'Audiencia', 'horarios' => 'Horarios', 'comentarios' => 'Comentarios y emociones', 'publicaciones' => 'Publicaciones', 'consultor' => '✦ Consultor IA'];
    $filtros = array_filter(['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'medio' => $medio ?: null, 'paginas' => $idsFiltro ?: null]);
    $urlTab = fn($t) => route('inteligencia.general', $filtros + ['tab' => $t]);
    $tituloFiltro = $idsFiltro ? ($paginas->count() === 1 ? $paginas->first()->name : $paginas->count() . ' páginas elegidas') : ($medio !== '' ? ($medios[$medio] ?? $medio) : 'Toda la organización');
@endphp

@section('content')
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    {{-- Cabecera --}}
    <div class="mt-8 lg:mt-10 mb-5">
        <a href="{{ route('inteligencia.index') }}" class="text-sm text-indigo-700 hover:underline">← Inteligencia de audiencia</a>
        <div class="flex flex-wrap items-end justify-between gap-4 mt-1">
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600">Vista general</div>
                <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">{{ $tituloFiltro }}</h1>
                <p class="text-sm text-gray-500 mt-1">
                    {{ $paginas->count() }} de {{ $universo->count() }} página(s) integradas · {{ $desde->format('d/m/Y') }} a {{ $hasta->format('d/m/Y') }}
                    @if ($ultimaRecoleccion) · datos actualizados {{ \Carbon\Carbon::parse($ultimaRecoleccion)->diffForHumans() }} @else · <span class="text-amber-700">sin datos todavía</span> @endif
                    @if ($sinDatos) · <span class="text-amber-700">{{ $sinDatos }} página(s) aún sin histórico</span> @endif
                </p>
            </div>
            <div class="flex items-center gap-2" id="recoleccion-controles">
                <select id="rec-dias" class="h-10 rounded-xl border-gray-200 text-sm shadow-sm"><option value="7">últimos 7 días</option><option value="30">últimos 30 días</option></select>
                <button type="button" id="rec-boton" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 whitespace-nowrap">⟳ Recolectar ahora</button>
            </div>
        </div>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-4 text-sm">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-4 text-sm">{{ session('error') }}</div>@endif

    {{-- Progreso de la recolección (página por página) --}}
    <div id="rec-panel" class="hidden rounded-2xl border border-gray-200 bg-white shadow-sm px-4 py-4 mb-5">
        <div class="flex items-center justify-between gap-3 mb-2">
            <div class="text-sm font-semibold text-gray-800" id="rec-titulo">Recolectando…</div>
            <div class="text-xs text-gray-500" id="rec-contador">0 / 0</div>
        </div>
        <div class="h-2 w-full rounded-full bg-gray-100 overflow-hidden"><div id="rec-barra" class="h-2 rounded-full bg-[#00024f] transition-all" style="width: 0%"></div></div>
        <div id="rec-avisos" class="hidden mt-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-3 py-2 text-xs"></div>
        <div id="rec-log" class="mt-3 max-h-56 overflow-auto divide-y divide-gray-100 text-xs"></div>
        <div class="mt-3 flex items-center gap-2">
            <button type="button" id="rec-recargar" class="hidden h-9 rounded-lg bg-[#00024f] text-white px-4 text-sm font-semibold">Ver los datos nuevos</button>
            <button type="button" id="rec-detener" class="h-9 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700">Detener</button>
        </div>
    </div>

    {{-- Filtros --}}
    <form method="GET" action="{{ route('inteligencia.general') }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm px-4 py-3 mb-5 flex flex-wrap items-end gap-3 text-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div>
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Medio</label>
            <select name="medio" class="h-9 rounded-lg border-gray-200 text-sm shadow-sm w-44">
                <option value="">Todos los medios</option>
                @foreach ($medios as $slug => $nombre)<option value="{{ $slug }}" @selected($medio === $slug)>{{ $nombre }}</option>@endforeach
            </select>
        </div>
        <div class="relative">
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Páginas</label>
            <details class="relative">
                <summary class="list-none cursor-pointer select-none flex items-center justify-between gap-2 h-9 w-56 rounded-lg border border-gray-200 bg-white px-3 shadow-sm hover:border-gray-300">
                    <span class="truncate {{ $idsFiltro ? 'text-indigo-700 font-semibold' : 'text-gray-700' }}">{{ $idsFiltro ? count($idsFiltro) . ' elegida(s)' : 'Todas las páginas' }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-gray-400 shrink-0"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="absolute z-30 mt-1.5 w-72 max-h-72 overflow-auto rounded-xl border border-gray-200 bg-white shadow-xl p-1.5">
                    @foreach ($universo as $p)
                        <label class="flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="paginas[]" value="{{ $p->id }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(in_array($p->id, $idsFiltro, true))>
                            <img src="{{ $p->pictureUrl('small') }}" alt="" class="h-6 w-6 rounded-full bg-gray-100" loading="lazy">
                            <span class="flex-1 min-w-0 truncate text-gray-800">{{ $p->name }}</span>
                            @unless ($p->getAttribute('con_datos'))<span class="text-[10px] text-amber-600">sin datos</span>@endunless
                        </label>
                    @endforeach
                </div>
            </details>
        </div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Desde</label><input type="date" name="desde" value="{{ $desde->toDateString() }}" class="h-9 rounded-lg border-gray-200 text-sm shadow-sm"></div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Hasta</label><input type="date" name="hasta" value="{{ $hasta->toDateString() }}" class="h-9 rounded-lg border-gray-200 text-sm shadow-sm"></div>
        <button class="h-9 rounded-lg bg-[#00024f] text-white px-4 font-semibold shadow-sm">Aplicar</button>
        <div class="flex gap-1">
            @foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días'] as $n => $et)
                <a href="{{ route('inteligencia.general', array_filter(['medio' => $medio ?: null, 'paginas' => $idsFiltro ?: null]) + ['desde' => now()->subDays($n - 1)->toDateString(), 'hasta' => now()->toDateString(), 'tab' => $tab]) }}" class="h-9 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-medium text-gray-600 hover:bg-gray-50">{{ $et }}</a>
            @endforeach
        </div>
        @if ($medio !== '' || $idsFiltro)<a href="{{ route('inteligencia.general', ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'tab' => $tab]) }}" class="h-9 inline-flex items-center text-xs text-rose-600 hover:underline">Quitar filtros</a>@endif
    </form>

    {{-- Pestañas --}}
    <div class="inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-200/70 p-1 gap-1 mb-5">
        @foreach ($tabs as $k => $et)
            <a href="{{ $urlTab($k) }}" class="rounded-lg px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition {{ $tab === $k ? 'bg-white text-[#00024f] shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">{{ $et }}</a>
        @endforeach
    </div>

    {{-- ============================================================ RESUMEN --}}
    @if ($tab === 'resumen')
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">
            @foreach ([
                ['Publicaciones', $fmt($r['publicaciones']), $r['con_tema'] . ' con tema'],
                ['Alcance de publicaciones', $fmt($r['alcance']), 'personas únicas'],
                ['Interacciones', $fmt($r['interacciones']), 'reacciones, comentarios, compartidos'],
                ['Tasa de interacción', $r['tasa'] !== null ? $r['tasa'] . '%' : '—', 'por cada 100 alcanzados'],
                ['Alcance de página / día', $fmt($r['alcance_pagina_dia']), 'suma de días'],
                ['Seguidores', $fmt($r['seguidores']), ($r['seguidores_variacion'] >= 0 ? '+' : '') . $fmt($r['seguidores_variacion']) . ' en el periodo'],
                ['Reproducciones', $fmt($r['reproducciones']), 'videos y reels'],
            ] as [$t, $v, $s])
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm min-w-0"><div class="text-[11px] uppercase tracking-wide text-gray-400 truncate">{{ $t }}</div><div class="text-2xl font-bold text-gray-900">{{ $v }}</div><div class="text-[11px] text-gray-400 truncate">{{ $s }}</div></div>
            @endforeach
        </div>

        <div class="grid lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-2">Alcance e interacciones por día</h3>
                <div class="h-64 sm:h-80"><canvas id="gSerie"></canvas></div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-2">Por red</h3>
                @forelse ($tablero['por_red'] as $red => $x)
                    <div class="flex items-center justify-between py-2 border-b border-gray-100 text-sm">
                        <span class="font-medium">{{ ucfirst($red) }} <span class="text-gray-400 text-xs">({{ $x['n'] }} publ.)</span></span>
                        <span class="text-gray-600">alcance {{ $fmt($x['alcance']) }} · tasa {{ $x['tasa'] !== null ? $x['tasa'] . '%' : '—' }}</span>
                    </div>
                @empty <p class="text-sm text-gray-500">Sin publicaciones en el periodo.</p> @endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Temas que más conectan</h3>
                @forelse (array_slice($tablero['por_tema'], 0, 6) as $t)
                    <div class="flex items-center justify-between py-1.5 border-b border-gray-100 text-sm">
                        <span class="flex items-center gap-2 min-w-0"><i class="inline-block w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $t['color'] ?? '#94a3b8' }}"></i><span class="truncate">{{ $t['nombre'] }}</span> <span class="text-gray-400 text-xs">({{ $t['n'] }})</span></span>
                        <span class="font-semibold">{{ $t['tasa'] !== null ? $t['tasa'] . '%' : '—' }}</span>
                    </div>
                @empty <p class="text-sm text-gray-500">Sin publicaciones clasificadas por tema (los temas se clasifican en cada campaña).</p> @endforelse
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-5 mt-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-2">Por formato</h3>
                <div class="h-56 sm:h-64"><canvas id="gFormato"></canvas></div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-2">Páginas con más alcance</h3>
                <div class="divide-y divide-gray-100">
                    @foreach (collect($tablero['por_pagina'])->sortByDesc('alcance')->take(8) as $p)
                        <div class="flex items-center justify-between py-1.5 text-sm gap-3"><span class="truncate">{{ $p['nombre'] }} <span class="text-gray-400 text-xs">({{ $p['n'] }})</span></span><span class="text-gray-600 whitespace-nowrap">{{ $fmt($p['alcance']) }} · {{ $p['tasa'] !== null ? $p['tasa'] . '%' : '—' }}</span></div>
                    @endforeach
                </div>
                <a href="{{ $urlTab('paginas') }}" class="text-xs text-indigo-700 hover:underline mt-2 inline-block">Ver todas las páginas →</a>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm mt-5 overflow-x-auto">
            <h3 class="font-bold text-gray-800 mb-2">Publicaciones con más interacción</h3>
            <table class="w-full text-sm min-w-[640px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">Publicación</th><th class="text-left">Tema</th><th class="text-right">Alcance</th><th class="text-right">Interac.</th><th class="text-right">Tasa</th></tr></thead><tbody>
            @forelse ($tablero['mejores'] as $m)
                <tr class="border-t border-gray-100"><td class="py-1.5"><a href="{{ $m['permalink'] }}" target="_blank" class="text-indigo-700 hover:underline">{{ $m['texto'] ?: '(sin texto)' }}</a><div class="text-[11px] text-gray-400">{{ ucfirst($m['red']) }} · {{ $m['tipo'] }} · {{ $m['fecha'] }}</div></td><td>{{ $m['tema'] ?? '—' }}</td><td class="text-right">{{ $fmt($m['alcance']) }}</td><td class="text-right">{{ $fmt($m['interacciones']) }}</td><td class="text-right">{{ $m['tasa'] !== null ? $m['tasa'] . '%' : '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-3 text-gray-500">Sin publicaciones en el periodo. Pulsa "Recolectar ahora" o espera la recolección nocturna.</td></tr>
            @endforelse
            </tbody></table>
        </div>
    @endif

    {{-- ================================================== PÁGINAS Y CAMPAÑAS --}}
    @if ($tab === 'paginas')
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto">
            <div class="flex items-center justify-between gap-3 mb-2">
                <h3 class="font-bold text-gray-800">Todas las páginas del periodo</h3>
                <span class="text-xs text-gray-400">Toca un encabezado para ordenar</span>
            </div>
            <table class="w-full text-sm min-w-[720px]" id="tabla-paginas-gral"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr>
                <th class="text-left py-1 cursor-pointer" data-col="0">Página</th><th class="text-right cursor-pointer" data-col="1" data-num>Publ.</th><th class="text-right cursor-pointer" data-col="2" data-num>Alcance</th><th class="text-right cursor-pointer" data-col="3" data-num>Interac.</th><th class="text-right cursor-pointer" data-col="4" data-num>Tasa</th><th class="text-right cursor-pointer" data-col="5" data-num>Seguidores FB</th><th class="text-right cursor-pointer" data-col="6" data-num>Seguidores IG</th><th class="text-right cursor-pointer" data-col="7" data-num>Nuevos</th>
            </tr></thead><tbody>
            @foreach ($tablero['por_pagina'] as $p)
                <tr class="border-t border-gray-100 hover:bg-gray-50/70">
                    <td class="py-1.5"><a href="{{ route('inteligencia.general', ['paginas' => [$p['id']], 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()]) }}" class="hover:underline">{{ $p['nombre'] }}</a></td>
                    <td class="text-right" data-v="{{ $p['n'] }}">{{ $p['n'] }}</td>
                    <td class="text-right" data-v="{{ $p['alcance'] }}">{{ $fmt($p['alcance']) }}</td>
                    <td class="text-right" data-v="{{ $p['interacciones'] }}">{{ $fmt($p['interacciones']) }}</td>
                    <td class="text-right" data-v="{{ $p['tasa'] ?? -1 }}">{{ $p['tasa'] !== null ? $p['tasa'] . '%' : '—' }}</td>
                    <td class="text-right" data-v="{{ $p['seguidores_facebook'] ?? -1 }}">{{ $p['seguidores_facebook'] !== null ? $fmt($p['seguidores_facebook']) : '—' }}</td>
                    <td class="text-right" data-v="{{ $p['seguidores_instagram'] ?? -1 }}">{{ $p['seguidores_instagram'] !== null ? $fmt($p['seguidores_instagram']) : '—' }}</td>
                    <td class="text-right" data-v="{{ $p['nuevos_seguidores'] }}">{{ $fmt($p['nuevos_seguidores']) }}</td>
                </tr>
            @endforeach
            </tbody></table>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm mt-5 overflow-x-auto">
            <h3 class="font-bold text-gray-800 mb-1">Campañas frente al total de la organización</h3>
            <p class="text-xs text-gray-500 mb-3">Mismo periodo. "Participación" es qué parte del alcance total aportan las páginas de la campaña; "Tasa vs. total" compara su tasa de interacción con la de toda la organización.</p>
            <table class="w-full text-sm min-w-[640px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">Campaña</th><th class="text-right">Páginas</th><th class="text-right">Publ.</th><th class="text-right">Alcance</th><th class="text-right">Interac.</th><th class="text-right">Tasa</th><th class="text-right">Participación</th><th class="text-right">Tasa vs. total</th></tr></thead><tbody>
            <tr class="border-t border-gray-200 bg-gray-50 font-semibold"><td class="py-1.5">Toda la organización</td><td class="text-right">{{ $paginas->count() }}</td><td class="text-right">{{ $fmt($r['publicaciones']) }}</td><td class="text-right">{{ $fmt($r['alcance']) }}</td><td class="text-right">{{ $fmt($r['interacciones']) }}</td><td class="text-right">{{ $r['tasa'] !== null ? $r['tasa'] . '%' : '—' }}</td><td class="text-right">100%</td><td class="text-right">—</td></tr>
            @forelse ($comparativa as $c)
                <tr class="border-t border-gray-100"><td class="py-1.5"><a href="{{ route('inteligencia.show', $c['id']) }}" class="text-indigo-700 hover:underline">{{ $c['nombre'] }}</a></td><td class="text-right">{{ $c['paginas'] }}</td><td class="text-right">{{ $fmt($c['resumen']['publicaciones']) }}</td><td class="text-right">{{ $fmt($c['resumen']['alcance']) }}</td><td class="text-right">{{ $fmt($c['resumen']['interacciones']) }}</td><td class="text-right">{{ $c['resumen']['tasa'] !== null ? $c['resumen']['tasa'] . '%' : '—' }}</td><td class="text-right">{{ $c['participacion'] !== null ? $c['participacion'] . '%' : '—' }}</td><td class="text-right {{ ($c['diferencia_tasa'] ?? 0) > 0 ? 'text-emerald-700' : (($c['diferencia_tasa'] ?? 0) < 0 ? 'text-rose-600' : '') }}">{{ $c['diferencia_tasa'] !== null ? (($c['diferencia_tasa'] > 0 ? '+' : '') . $c['diferencia_tasa'] . ' pts') : '—' }}</td></tr>
            @empty
                <tr><td colspan="8" class="py-3 text-gray-500">No hay campañas activas.</td></tr>
            @endforelse
            </tbody></table>
        </div>
    @endif

    {{-- ========================================================== AUDIENCIA --}}
    @if ($tab === 'audiencia')
        @php $d = $tablero['demografia']; @endphp
        @if (!$d['fuentes'])
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm mb-4">Todavía no hay demografía. Se recoge al recolectar datos; Facebook puede no entregarla para páginas con pocos seguidores e Instagram la entrega a partir de 100 seguidores.</div>
        @endif
        <div class="grid lg:grid-cols-3 gap-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0"><h3 class="font-bold text-gray-800 mb-2">Edad y género</h3><div class="h-56"><canvas id="gEdad"></canvas></div>
                @php $tg = max(1, array_sum($d['genero'])); @endphp
                <p class="text-xs text-gray-500 mt-2">Mujeres {{ round(100 * $d['genero']['F'] / $tg) }}% · Hombres {{ round(100 * $d['genero']['M'] / $tg) }}%{{ $d['genero']['U'] ? ' · Sin dato ' . round(100 * $d['genero']['U'] / $tg) . '%' : '' }}</p></div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0"><h3 class="font-bold text-gray-800 mb-2">Ciudades</h3>
                @php $tc = max(1, array_sum($d['ciudades'])); @endphp
                @forelse ($d['ciudades'] as $c => $v)
                    <div class="text-sm py-1"><div class="flex justify-between"><span class="truncate">{{ $c }}</span><span class="text-gray-500 whitespace-nowrap">{{ $fmt($v) }} · {{ round(100 * $v / $tc) }}%</span></div><div class="h-1.5 bg-gray-100 rounded"><div class="h-1.5 rounded bg-[#00024f]" style="width: {{ round(100 * $v / max(1, max($d['ciudades']))) }}%"></div></div></div>
                @empty <p class="text-sm text-gray-500">Sin datos.</p> @endforelse
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0"><h3 class="font-bold text-gray-800 mb-2">Países</h3>
                @forelse ($d['paises'] as $c => $v)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $c }}</span><span class="text-gray-500">{{ $fmt($v) }}</span></div>@empty <p class="text-sm text-gray-500">Sin datos.</p> @endforelse
            </div>
        </div>
    @endif

    {{-- =========================================================== HORARIOS --}}
    @if ($tab === 'horarios')
        @php $h = $tablero['horarios']; $maxProm = 1; foreach ($h['matriz'] as $f) foreach ($f as $c) $maxProm = max($maxProm, (int) ($c['prom'] ?? 0)); $maxOn = 1; foreach ($h['en_linea'] as $f) foreach ($f as $v) $maxOn = max($maxOn, (int) $v); @endphp
        <div class="grid lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto">
                <h3 class="font-bold text-gray-800 mb-1">Interacción promedio según día y hora de publicación</h3>
                <p class="text-xs text-gray-500 mb-3">Cada celda: promedio de interacciones de las publicaciones hechas en esa franja. Más oscuro = mejor.</p>
                <table class="text-[10px]"><thead><tr><th></th>@for ($i = 0; $i < 24; $i++)<th class="font-normal text-gray-400 px-0.5">{{ $i }}</th>@endfor</tr></thead><tbody>
                @for ($dd = 0; $dd < 7; $dd++)
                    <tr><td class="pr-1 text-gray-600">{{ $dias[$dd] }}</td>@for ($i = 0; $i < 24; $i++)@php $c = $h['matriz'][$dd][$i]; $op = $c['prom'] ? 0.15 + 0.85 * $c['prom'] / $maxProm : 0; @endphp<td class="p-0"><div title="{{ $dias[$dd] }} {{ $i }}:00 · {{ $c['n'] }} publ. · prom {{ $c['prom'] ?? '—' }}" class="w-5 h-5 m-px rounded-sm" style="background: rgba(0,2,79,{{ $op }}); {{ !$c['n'] ? 'background:#f3f4f6' : '' }}"></div></td>@endfor</tr>
                @endfor
                </tbody></table>
                <h3 class="font-bold text-gray-800 mt-6 mb-1">Seguidores en línea (dato de Meta)</h3>
                @if ($h['en_linea'])
                <table class="text-[10px]"><thead><tr><th></th>@for ($i = 0; $i < 24; $i++)<th class="font-normal text-gray-400 px-0.5">{{ $i }}</th>@endfor</tr></thead><tbody>
                @for ($dd = 0; $dd < 7; $dd++)
                    <tr><td class="pr-1 text-gray-600">{{ $dias[$dd] }}</td>@for ($i = 0; $i < 24; $i++)@php $v = (int) ($h['en_linea'][$dd][$i] ?? 0); @endphp<td class="p-0"><div title="{{ $dias[$dd] }} {{ $i }}:00 · {{ $fmt($v) }} en línea" class="w-5 h-5 m-px rounded-sm" style="background: rgba(220,38,38,{{ $v ? 0.1 + 0.9 * $v / $maxOn : 0 }})"></div></td>@endfor</tr>
                @endfor
                </tbody></table>
                @else <p class="text-sm text-gray-500">Meta aún no entregó este dato para estas páginas.</p> @endif
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-gray-800 mb-2">Mejores momentos para publicar</h3>
                @forelse ($h['mejores_publicar'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['prom']) }} interac. ({{ $m['n'] }})</span></div>@empty <p class="text-sm text-gray-500">Aún no hay suficientes publicaciones.</p>@endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Cuándo hay más gente conectada</h3>
                @forelse ($h['mejores_en_linea'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['en_linea']) }}</span></div>@empty <p class="text-sm text-gray-500">Sin dato.</p>@endforelse
            </div>
        </div>
    @endif

    {{-- ================================================ COMENTARIOS Y EMOCIONES --}}
    @if ($tab === 'comentarios')
        @php $c = $tablero['comentarios']; $maxEmo = max(1, max(array_map(fn($e) => $e['n'], $c['emociones'] ?? [[ 'n' => 0 ]]))); @endphp
        @if (!$c['publicaciones'])
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm mb-4">Todavía no hay comentarios leídos por la IA en este periodo. La lectura se hace cada madrugada para las campañas activas (publicaciones con 5 o más comentarios); también puedes pedirla desde una campaña con "Clasificar y leer comentarios".</div>
        @endif
        <div class="grid lg:grid-cols-3 gap-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-1">Tono general</h3>
                <p class="text-xs text-gray-500 mb-3">{{ $fmt($c['comentarios']) }} comentarios leídos en {{ $c['publicaciones'] }} publicaciones.</p>
                <div class="flex h-3 rounded-full overflow-hidden bg-gray-100">
                    <div class="bg-emerald-500" style="width: {{ $c['pct_favor'] }}%"></div><div class="bg-rose-500" style="width: {{ $c['pct_contra'] }}%"></div><div class="bg-gray-300" style="width: {{ $c['pct_neutro'] }}%"></div>
                </div>
                <div class="flex justify-between text-xs mt-2"><span class="text-emerald-700 font-semibold">A favor {{ $c['pct_favor'] }}%</span><span class="text-rose-600 font-semibold">En contra {{ $c['pct_contra'] }}%</span><span class="text-gray-500">Neutro {{ $c['pct_neutro'] }}%</span></div>
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Emociones del público</h3>
                @forelse (array_filter($c['emociones'] ?? [], fn($e) => $e['n'] > 0) as $e)
                    <div class="py-1"><div class="flex justify-between text-sm"><span>{{ $e['nombre'] }}</span><span class="text-gray-500">{{ $e['pct'] }}%</span></div><div class="h-1.5 rounded bg-gray-100"><div class="h-1.5 rounded bg-[#00024f]" style="width: {{ round(100 * $e['n'] / $maxEmo) }}%"></div></div></div>
                @empty <p class="text-sm text-gray-500">Sin lectura de emociones todavía (se agrega en cada nueva lectura de comentarios).</p> @endforelse
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-2">Preocupaciones más repetidas</h3>
                @forelse ($c['preocupaciones'] as $k => $v)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span class="truncate">{{ ucfirst($k) }}</span><span class="text-gray-400 shrink-0 ml-2">{{ $v }}</span></div>@empty <p class="text-sm text-gray-500">Sin datos.</p>@endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Palabras frecuentes</h3>
                <div class="flex flex-wrap gap-1.5">@foreach ($c['palabras'] as $k => $v)<span class="rounded-full bg-gray-100 text-gray-700 px-2.5 py-1 text-xs">{{ $k }} <span class="text-gray-400">{{ $v }}</span></span>@endforeach</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
                <h3 class="font-bold text-gray-800 mb-2">Por tema</h3>
                @forelse ($c['por_tema'] as $t)
                    @php $b = max(1, $t['a_favor'] + $t['en_contra'] + $t['neutro']); @endphp
                    <div class="py-1.5 border-b border-gray-100"><div class="flex justify-between text-sm"><span class="truncate">{{ $t['tema'] }}</span><span class="text-gray-400 text-xs">{{ $t['comentarios'] }} coment.</span></div><div class="flex h-1.5 rounded-full overflow-hidden bg-gray-100 mt-1"><div class="bg-emerald-500" style="width: {{ round(100 * $t['a_favor'] / $b) }}%"></div><div class="bg-rose-500" style="width: {{ round(100 * $t['en_contra'] / $b) }}%"></div></div></div>
                @empty <p class="text-sm text-gray-500">Sin datos.</p>@endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Lecturas recientes</h3>
                @forelse ($c['resumenes'] as $x)
                    <div class="py-2 border-b border-gray-100 text-sm"><a href="{{ $x['publicacion']['permalink'] }}" target="_blank" class="text-indigo-700 hover:underline">{{ $x['publicacion']['texto'] ?: '(sin texto)' }}</a><p class="text-xs text-gray-600 mt-1">{{ $x['resumen'] }}</p></div>
                @empty <p class="text-sm text-gray-500">Sin lecturas todavía.</p>@endforelse
            </div>
        </div>
        <div class="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 text-sm text-gray-700 flex flex-wrap items-center justify-between gap-3">
            <span>¿Quieres un diagnóstico y recomendaciones a partir de esto? Pregúntale al consultor.</span>
            <a href="{{ $urlTab('consultor') }}" class="rounded-lg bg-[#00024f] text-white px-4 py-2 text-sm font-semibold">✦ Abrir el consultor IA</a>
        </div>
    @endif

    {{-- ========================================================= CONSULTOR IA --}}
    @if ($tab === 'consultor')
        @include('admin.inteligencia._consultor', ['contextoConsulta' => ['medio' => $medio ?: null, 'paginas' => $idsFiltro, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()], 'tituloAlcance' => $tituloFiltro])
    @endif

    {{-- ====================================================== PUBLICACIONES --}}
    @if ($tab === 'publicaciones')
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto">
            <h3 class="font-bold text-gray-800 mb-3">Publicaciones del periodo</h3>
            <table class="w-full text-sm min-w-[640px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">Publicación</th><th class="text-left">Tema</th><th class="text-right">Alcance</th><th class="text-right">Interac.</th><th class="text-right">Coment.</th></tr></thead><tbody>
            @forelse ($publicaciones as $p)
                <tr class="border-t border-gray-100 align-top">
                    <td class="py-2"><a href="{{ $p->permalink }}" target="_blank" class="text-indigo-700 hover:underline">{{ \Illuminate\Support\Str::limit(trim((string) $p->texto), 120, '…') ?: '(sin texto)' }}</a><div class="text-[11px] text-gray-400">{{ $p->page?->name }} · {{ ucfirst($p->red) }} · {{ $p->tipo }} · {{ $p->publicado_en->format('d/m/Y H:i') }}</div></td>
                    <td class="py-2 text-gray-600">{{ $p->tema?->nombre ?? '—' }}</td>
                    <td class="py-2 text-right">{{ $p->alcance !== null ? $fmt($p->alcance) : '—' }}</td><td class="py-2 text-right">{{ $p->interacciones !== null ? $fmt($p->interacciones) : '—' }}</td><td class="py-2 text-right">{{ $p->comentarios !== null ? $fmt($p->comentarios) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-3 text-gray-500">Sin publicaciones en el periodo.</td></tr>
            @endforelse
            </tbody></table>
            <div class="mt-3">{{ $publicaciones->appends(['tab' => 'publicaciones'])->links() }}</div>
        </div>
    @endif
</div>

@if (file_exists(public_path('js/chart.umd.min.js')))
<script src="{{ asset('js/chart.umd.min.js') }}"></script>
@else
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
@endif
<script>
(function () {
  const T = @json($tablero);
  const fmt = (n) => new Intl.NumberFormat('es-CO').format(n);
  const g = (id) => document.getElementById(id);
  if (g('gSerie')) new Chart(g('gSerie'), { type: 'line', data: { labels: T.serie.map(d => d.fecha.slice(5)), datasets: [
    { label: 'Alcance de página', data: T.serie.map(d => d.alcance_pagina), borderColor: '#00024f', backgroundColor: 'rgba(0,2,79,.08)', fill: true, tension: .3, yAxisID: 'y' },
    { label: 'Interacciones de publicaciones', data: T.serie.map(d => d.interacciones), borderColor: '#dc2626', tension: .3, yAxisID: 'y1' },
    { label: 'Publicaciones', data: T.serie.map(d => d.publicaciones), type: 'bar', backgroundColor: 'rgba(148,163,184,.4)', yAxisID: 'y2' } ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { y: { position: 'left', ticks: { callback: fmt } }, y1: { position: 'right', grid: { drawOnChartArea: false } }, y2: { display: false } } } });
  if (g('gFormato')) new Chart(g('gFormato'), { type: 'bar', data: { labels: T.por_formato.map(f => f.nombre + ' (' + f.n + ')'), datasets: [
    { label: 'Alcance promedio', data: T.por_formato.map(f => f.alcance_prom), backgroundColor: 'rgba(0,2,79,.75)' },
    { label: 'Interacciones promedio', data: T.por_formato.map(f => f.interacciones_prom), backgroundColor: 'rgba(220,38,38,.7)', yAxisID: 'y1' } ] },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { callback: fmt } }, y1: { position: 'right', grid: { drawOnChartArea: false } } } } });
  if (g('gEdad')) { const d = T.demografia.edad_genero; const edades = Object.keys(d);
    new Chart(g('gEdad'), { type: 'bar', data: { labels: edades, datasets: [
      { label: 'Mujeres', data: edades.map(e => d[e].F || 0), backgroundColor: 'rgba(219,39,119,.75)' },
      { label: 'Hombres', data: edades.map(e => d[e].M || 0), backgroundColor: 'rgba(37,99,235,.75)' } ] },
      options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, ticks: { callback: fmt } } } } }); }
  // Ordenar la tabla de páginas al tocar un encabezado
  const tabla = g('tabla-paginas-gral');
  if (tabla) tabla.querySelectorAll('th[data-col]').forEach(th => th.addEventListener('click', () => {
    const col = +th.dataset.col, num = th.hasAttribute('data-num'), asc = th.dataset.asc === '1';
    const filas = Array.from(tabla.tBodies[0].rows);
    filas.sort((a, b) => { const x = num ? +a.cells[col].dataset.v : a.cells[col].textContent.trim().toLowerCase(); const y = num ? +b.cells[col].dataset.v : b.cells[col].textContent.trim().toLowerCase(); return (x > y ? 1 : x < y ? -1 : 0) * (asc ? 1 : -1); });
    filas.forEach(f => tabla.tBodies[0].appendChild(f));
    tabla.querySelectorAll('th').forEach(t => delete t.dataset.asc); th.dataset.asc = asc ? '0' : '1';
  }));
  document.addEventListener('click', e => { document.querySelectorAll('details[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; }); });

  // Recolección página por página con avance visible (cada paso es una petición corta)
  const boton = g('rec-boton');
  if (boton) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const filtros = { medio: @json($medio), paginas: @json($idsFiltro) };
    let detener = false;
    const post = async (url, body) => { const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) }); return r.json(); };
    const avisosVistos = new Set();
    boton.addEventListener('click', async () => {
      detener = false; boton.disabled = true; boton.textContent = 'Recolectando…';
      const panel = g('rec-panel'); panel.classList.remove('hidden'); g('rec-log').innerHTML = ''; g('rec-avisos').classList.add('hidden'); g('rec-avisos').innerHTML = ''; g('rec-recargar').classList.add('hidden'); g('rec-detener').classList.remove('hidden');
      let ini;
      try { ini = await post(@json(route('inteligencia.general.recolectar.iniciar')), { dias: g('rec-dias').value, medio: filtros.medio, paginas: filtros.paginas }); } catch (e) { ini = null; }
      if (!ini || !ini.success) { g('rec-titulo').textContent = 'No se pudo iniciar la recolección.'; boton.disabled = false; boton.textContent = '⟳ Recolectar ahora'; return; }
      g('rec-titulo').textContent = 'Recolectando ' + ini.total + ' página(s), ' + ini.dias + ' días hacia atrás…'; g('rec-contador').textContent = '0 / ' + ini.total;
      let hecho = 0, errores = 0;
      while (!detener) {
        let d;
        try { d = await post(@json(route('inteligencia.general.recolectar.paso'))); } catch (e) { d = { success: false, error: 'Se perdió la conexión con el servidor; vuelve a pulsar el botón para continuar.' }; }
        if (!d.success) { g('rec-titulo').textContent = d.error || 'Error en la recolección.'; break; }
        if (d.linea) {
          const l = d.linea; const fila = document.createElement('div'); fila.className = 'py-1.5 flex gap-2';
          fila.innerHTML = '<span class="' + (l.ok ? 'text-emerald-600' : 'text-rose-600') + '">' + (l.ok ? '✓' : '✕') + '</span><span class="font-semibold text-gray-800">' + l.pagina + '</span><span class="text-gray-500">' + l.detalle + '</span>';
          g('rec-log').prepend(fila);
          (l.avisos || []).forEach(a => { if (!avisosVistos.has(a)) { avisosVistos.add(a); const p = document.createElement('div'); p.textContent = '⚠ ' + a; g('rec-avisos').appendChild(p); g('rec-avisos').classList.remove('hidden'); } });
        }
        hecho = d.hecho; errores = d.errores; g('rec-contador').textContent = hecho + ' / ' + d.total; g('rec-barra').style.width = (d.total ? Math.round(100 * hecho / d.total) : 100) + '%';
        if (d.terminado) { g('rec-titulo').textContent = 'Listo: ' + hecho + ' página(s) recolectadas' + (errores ? ', ' + errores + ' con errores' : '') + '.'; break; }
      }
      if (detener) g('rec-titulo').textContent = 'Detenido en ' + hecho + ' página(s). Los datos recogidos hasta aquí ya quedaron guardados.';
      g('rec-detener').classList.add('hidden'); g('rec-recargar').classList.remove('hidden');
      boton.disabled = false; boton.textContent = '⟳ Recolectar ahora';
    });
    g('rec-detener').addEventListener('click', () => { detener = true; });
    g('rec-recargar').addEventListener('click', () => location.reload());
  }
})();
</script>
@endsection
