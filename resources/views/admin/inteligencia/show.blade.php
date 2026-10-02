@extends('layouts.app')

@php
    $r = $tablero['resumen'];
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $dias = \App\Services\Inteligencia\AnalisisService::DIAS;
    $tabs = ['resumen' => 'Resumen', 'temas' => 'Temas', 'audiencia' => 'Audiencia', 'horarios' => 'Horarios', 'comentarios' => 'Comentarios', 'pronostico' => 'Pronóstico', 'publicaciones' => 'Publicaciones', 'informes' => 'Informes', 'config' => 'Configuración'];
    $urlTab = fn($t) => route('inteligencia.show', [$campana, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'tab' => $t]);
@endphp

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-5 mt-10">
        <div class="text-[#00024f]">
            <a href="{{ route('inteligencia.index') }}" class="text-sm underline">← Campañas</a>
            <h1 class="text-3xl font-bold mt-1">{{ $campana->nombre }}</h1>
            <p class="text-sm text-gray-600">{{ $campana->territorio ?: 'Sin territorio' }} · {{ $campana->paginas->count() }} página(s) · {{ $campana->temas->count() }} tema(s)
                @if ($ultimaRecoleccion) · datos actualizados {{ \Carbon\Carbon::parse($ultimaRecoleccion)->diffForHumans() }} @else · <span class="text-amber-700">sin datos todavía</span> @endif</p>
        </div>
        <form method="GET" action="{{ route('inteligencia.show', $campana) }}" class="flex items-end gap-2 text-sm">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div><label class="block text-gray-500 text-xs">Desde</label><input type="date" name="desde" value="{{ $desde->toDateString() }}" class="rounded-lg border-gray-300 text-sm"></div>
            <div><label class="block text-gray-500 text-xs">Hasta</label><input type="date" name="hasta" value="{{ $hasta->toDateString() }}" class="rounded-lg border-gray-300 text-sm"></div>
            <button class="rounded-lg bg-[#00024f] text-white px-3 py-2">Ver</button>
            @foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días'] as $n => $et)
                <a href="{{ route('inteligencia.show', [$campana, 'desde' => now()->subDays($n - 1)->toDateString(), 'hasta' => now()->toDateString(), 'tab' => $tab]) }}" class="rounded-lg border border-gray-300 bg-white px-2 py-2 text-xs">{{ $et }}</a>
            @endforeach
        </form>
    </div>

    @if (session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-4 text-sm">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-4 text-sm">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-4 text-sm"><ul class="list-disc ml-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    {{-- Acciones --}}
    <div class="flex flex-wrap gap-2 mb-5 text-sm">
        <form method="POST" action="{{ route('inteligencia.recolectar', $campana) }}">@csrf<input type="hidden" name="dias" value="7"><button class="rounded-lg border border-gray-300 bg-white px-3 py-2 hover:bg-gray-50">⟳ Recolectar datos (7 días)</button></form>
        <form method="POST" action="{{ route('inteligencia.recolectar', $campana) }}">@csrf<input type="hidden" name="dias" value="30"><button class="rounded-lg border border-gray-300 bg-white px-3 py-2 hover:bg-gray-50">⟳ Recolectar 30 días</button></form>
        <form method="POST" action="{{ route('inteligencia.analizar', $campana) }}">@csrf<button class="rounded-lg border border-indigo-300 bg-indigo-50 text-indigo-800 px-3 py-2 hover:bg-indigo-100" @disabled(!$iaLista)>✦ Clasificar y leer comentarios (IA)</button></form>
        <form method="POST" action="{{ route('inteligencia.informes.generar', $campana) }}">@csrf<input type="hidden" name="desde" value="{{ $desde->toDateString() }}"><input type="hidden" name="hasta" value="{{ $hasta->toDateString() }}"><button class="rounded-lg bg-[#00024f] text-white px-3 py-2 hover:opacity-90" @disabled(!$iaLista)>✦ Redactar informe del periodo</button></form>
        @unless ($iaLista)<span class="self-center text-xs text-amber-700">IA no configurada (ANTHROPIC_API_KEY)</span>@endunless
    </div>

    {{-- Pestañas --}}
    <div class="flex flex-wrap gap-1 border-b border-gray-200 mb-5">
        @foreach ($tabs as $k => $et)
            <a href="{{ $urlTab($k) }}" class="px-3 py-2 text-sm rounded-t-lg {{ $tab === $k ? 'bg-white border border-b-white border-gray-200 font-semibold text-[#00024f]' : 'text-gray-500 hover:text-gray-800' }}">{{ $et }}</a>
        @endforeach
    </div>

    {{-- ============================================================ RESUMEN --}}
    @if ($tab === 'resumen')
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
            @foreach ([
                ['Publicaciones', $fmt($r['publicaciones']), $r['con_tema'] . ' con tema'],
                ['Alcance de publicaciones', $fmt($r['alcance']), 'personas únicas'],
                ['Interacciones', $fmt($r['interacciones']), 'reacciones, comentarios, compartidos, guardados'],
                ['Tasa de interacción', $r['tasa'] !== null ? $r['tasa'] . '%' : '—', 'por cada 100 alcanzados'],
                ['Alcance de página / día', $fmt($r['alcance_pagina_dia']), 'suma de días'],
                ['Seguidores', $fmt($r['seguidores']), ($r['seguidores_variacion'] >= 0 ? '+' : '') . $fmt($r['seguidores_variacion']) . ' en el periodo'],
                ['Reproducciones', $fmt($r['reproducciones']), 'videos y reels'],
            ] as [$t, $v, $s])
                <div class="rounded-2xl border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">{{ $t }}</div><div class="text-2xl font-bold text-gray-900">{{ $v }}</div><div class="text-[11px] text-gray-400">{{ $s }}</div></div>
            @endforeach
        </div>

        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Alcance e interacciones por día</h3>
                <canvas id="gSerie" height="110"></canvas>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Temas que más conectan</h3>
                @forelse (array_slice($tablero['por_tema'], 0, 6) as $t)
                    <div class="flex items-center justify-between py-1.5 border-b border-gray-100 text-sm">
                        <span class="flex items-center gap-2"><i class="inline-block w-2.5 h-2.5 rounded-full" style="background: {{ $t['color'] ?? '#94a3b8' }}"></i>{{ $t['nombre'] }} <span class="text-gray-400 text-xs">({{ $t['n'] }})</span></span>
                        <span class="font-semibold">{{ $t['tasa'] !== null ? $t['tasa'] . '%' : '—' }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Sin publicaciones clasificadas. Recolecta y clasifica.</p>
                @endforelse
                <p class="text-[11px] text-gray-400 mt-2">Tasa de interacción promedio por publicación.</p>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-6 mt-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Por página</h3>
                <table class="w-full text-sm"><thead class="text-gray-500 text-xs"><tr><th class="text-left py-1">Página</th><th class="text-right">Publ.</th><th class="text-right">Alcance</th><th class="text-right">Interac.</th><th class="text-right">Tasa</th><th class="text-right">Seguidores</th></tr></thead><tbody>
                @foreach ($tablero['por_pagina'] as $p)
                    <tr class="border-t border-gray-100"><td class="py-1.5">{{ $p['nombre'] }}</td><td class="text-right">{{ $p['n'] }}</td><td class="text-right">{{ $fmt($p['alcance']) }}</td><td class="text-right">{{ $fmt($p['interacciones']) }}</td><td class="text-right">{{ $p['tasa'] !== null ? $p['tasa'] . '%' : '—' }}</td><td class="text-right">{{ $p['seguidores_facebook'] !== null ? $fmt($p['seguidores_facebook']) : '—' }}@if ($p['seguidores_instagram'] !== null) <span class="text-pink-600 text-xs">+{{ $fmt($p['seguidores_instagram']) }} IG</span>@endif</td></tr>
                @endforeach
                </tbody></table>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Por formato</h3>
                <canvas id="gFormato" height="150"></canvas>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 mt-6">
            <h3 class="font-bold text-gray-800 mb-2">Publicaciones con más interacción</h3>
            <table class="w-full text-sm"><thead class="text-gray-500 text-xs"><tr><th class="text-left py-1">Publicación</th><th class="text-left">Tema</th><th class="text-right">Alcance</th><th class="text-right">Interac.</th><th class="text-right">Tasa</th></tr></thead><tbody>
            @forelse ($tablero['mejores'] as $m)
                <tr class="border-t border-gray-100"><td class="py-1.5"><a href="{{ $m['permalink'] }}" target="_blank" class="text-indigo-700 hover:underline">{{ $m['texto'] ?: '(sin texto)' }}</a><div class="text-[11px] text-gray-400">{{ ucfirst($m['red']) }} · {{ $m['tipo'] }} · {{ $m['fecha'] }}</div></td><td>{{ $m['tema'] ?? '—' }}</td><td class="text-right">{{ $fmt($m['alcance']) }}</td><td class="text-right">{{ $fmt($m['interacciones']) }}</td><td class="text-right">{{ $m['tasa'] !== null ? $m['tasa'] . '%' : '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-3 text-gray-500">Sin publicaciones en el periodo.</td></tr>
            @endforelse
            </tbody></table>
        </div>
    @endif

    {{-- ============================================================== TEMAS --}}
    @if ($tab === 'temas')
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Rendimiento por tema</h3>
                <canvas id="gTemas" height="{{ max(120, 28 * count($tablero['por_tema'])) }}"></canvas>
                <table class="w-full text-sm mt-4"><thead class="text-gray-500 text-xs"><tr><th class="text-left py-1">Tema</th><th class="text-right">Publ.</th><th class="text-right">Alcance prom.</th><th class="text-right">Interac. prom.</th><th class="text-right">Tasa</th><th class="text-left pl-3">Mejor formato</th></tr></thead><tbody>
                @forelse ($tablero['por_tema'] as $t)
                    <tr class="border-t border-gray-100"><td class="py-1.5"><i class="inline-block w-2.5 h-2.5 rounded-full mr-1" style="background: {{ $t['color'] ?? '#94a3b8' }}"></i>{{ $t['nombre'] }}</td><td class="text-right">{{ $t['n'] }}</td><td class="text-right">{{ $fmt($t['alcance_prom']) }}</td><td class="text-right">{{ $fmt($t['interacciones_prom']) }}</td><td class="text-right font-semibold">{{ $t['tasa'] !== null ? $t['tasa'] . '%' : '—' }}</td><td class="pl-3">{{ $t['mejor_formato'] ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-3 text-gray-500">Sin datos. Recolecta y luego clasifica con la IA.</td></tr>
                @endforelse
                </tbody></table>
                @if ($tablero['sin_tema'])<p class="text-xs text-amber-700 mt-2">{{ $tablero['sin_tema'] }} publicaciones sin tema en el periodo: usa "Clasificar" o corrígelas en la pestaña Publicaciones.</p>@endif
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5" id="temas">
                <h3 class="font-bold text-gray-800 mb-2">Temas de la campaña</h3>
                <p class="text-xs text-gray-500 mb-3">La IA clasifica cada publicación en uno de estos temas. La descripción y las palabras clave la ayudan a decidir.</p>
                @foreach ($campana->temas as $t)
                    <details class="border-t border-gray-100 py-2">
                        <summary class="cursor-pointer text-sm flex items-center gap-2"><i class="inline-block w-2.5 h-2.5 rounded-full" style="background: {{ $t->color }}"></i><span class="font-semibold">{{ $t->nombre }}</span></summary>
                        <form method="POST" action="{{ route('inteligencia.temas.update', [$campana, $t]) }}" class="mt-2 space-y-2 text-sm">@csrf @method('PUT')
                            <input name="nombre" value="{{ $t->nombre }}" maxlength="80" required class="w-full rounded-lg border-gray-300 text-sm">
                            <input name="descripcion" value="{{ $t->descripcion }}" maxlength="500" placeholder="Descripción (qué entra en este tema)" class="w-full rounded-lg border-gray-300 text-sm">
                            <input name="palabras_clave" value="{{ implode(', ', (array) $t->palabras_clave) }}" maxlength="500" placeholder="Palabras clave, separadas por coma" class="w-full rounded-lg border-gray-300 text-sm">
                            <div class="flex items-center gap-2"><input type="color" name="color" value="{{ $t->color ?: '#2563eb' }}" class="h-8 w-10 rounded border-gray-300"><button class="rounded-lg bg-gray-800 text-white px-3 py-1.5 text-xs">Guardar</button></div>
                        </form>
                        <form method="POST" action="{{ route('inteligencia.temas.destroy', [$campana, $t]) }}" onsubmit="return confirm('¿Eliminar el tema? Sus publicaciones quedarán sin tema.')" class="mt-1">@csrf @method('DELETE')<button class="text-xs text-red-600">Eliminar</button></form>
                    </details>
                @endforeach
                <form method="POST" action="{{ route('inteligencia.temas.store', $campana) }}" class="mt-3 space-y-2 text-sm border-t border-gray-200 pt-3">@csrf
                    <input name="nombre" maxlength="80" required placeholder="Nuevo tema" class="w-full rounded-lg border-gray-300 text-sm">
                    <input name="descripcion" maxlength="500" placeholder="Descripción (opcional)" class="w-full rounded-lg border-gray-300 text-sm">
                    <input name="palabras_clave" maxlength="500" placeholder="Palabras clave (opcional)" class="w-full rounded-lg border-gray-300 text-sm">
                    <button class="rounded-lg bg-[#00024f] text-white px-3 py-1.5 text-xs">Agregar tema</button>
                </form>
            </div>
        </div>
    @endif

    {{-- ========================================================== AUDIENCIA --}}
    @if ($tab === 'audiencia')
        @php $d = $tablero['demografia']; @endphp
        @if (!$d['fuentes'])
            <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-3 text-sm mb-4">Todavía no hay demografía. Se recoge al recolectar datos; Facebook puede no entregarla para páginas con pocos seguidores, Instagram la entrega a partir de 100 seguidores.</div>
        @endif
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-5"><h3 class="font-bold text-gray-800 mb-2">Edad y género</h3><canvas id="gEdad" height="220"></canvas>
                @php $tg = max(1, array_sum($d['genero'])); @endphp
                <p class="text-xs text-gray-500 mt-2">Mujeres {{ round(100 * $d['genero']['F'] / $tg) }}% · Hombres {{ round(100 * $d['genero']['M'] / $tg) }}%{{ $d['genero']['U'] ? ' · Sin dato ' . round(100 * $d['genero']['U'] / $tg) . '%' : '' }}</p></div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5"><h3 class="font-bold text-gray-800 mb-2">Ciudades</h3>
                @php $tc = max(1, array_sum($d['ciudades'])); @endphp
                @forelse ($d['ciudades'] as $c => $v)
                    <div class="text-sm py-1"><div class="flex justify-between"><span>{{ $c }}</span><span class="text-gray-500">{{ $fmt($v) }} · {{ round(100 * $v / $tc) }}%</span></div><div class="h-1.5 bg-gray-100 rounded"><div class="h-1.5 rounded bg-[#00024f]" style="width: {{ round(100 * $v / max(1, max($d['ciudades']))) }}%"></div></div></div>
                @empty <p class="text-sm text-gray-500">Sin datos.</p> @endforelse
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5"><h3 class="font-bold text-gray-800 mb-2">Países</h3>
                @forelse ($d['paises'] as $c => $v)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $c }}</span><span class="text-gray-500">{{ $fmt($v) }}</span></div>@empty <p class="text-sm text-gray-500">Sin datos.</p> @endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Por red</h3>
                @foreach ($tablero['por_red'] as $red => $x)<div class="text-sm py-1 border-b border-gray-100 flex justify-between"><span>{{ ucfirst($red) }} <span class="text-gray-400 text-xs">({{ $x['n'] }} publ.)</span></span><span>alcance {{ $fmt($x['alcance']) }} · tasa {{ $x['tasa'] !== null ? $x['tasa'] . '%' : '—' }}</span></div>@endforeach
            </div>
        </div>
    @endif

    {{-- =========================================================== HORARIOS --}}
    @if ($tab === 'horarios')
        @php $h = $tablero['horarios']; $maxProm = 1; foreach ($h['matriz'] as $f) foreach ($f as $c) $maxProm = max($maxProm, (int) ($c['prom'] ?? 0)); $maxOn = 1; foreach ($h['en_linea'] as $f) foreach ($f as $v) $maxOn = max($maxOn, (int) $v); @endphp
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 overflow-x-auto">
                <h3 class="font-bold text-gray-800 mb-1">Interacción promedio según día y hora de publicación</h3>
                <p class="text-xs text-gray-500 mb-3">Cada celda: promedio de interacciones de las publicaciones hechas en esa franja (en el periodo). Más oscuro = mejor.</p>
                <table class="text-[10px]"><thead><tr><th></th>@for ($i = 0; $i < 24; $i++)<th class="font-normal text-gray-400 px-0.5">{{ $i }}</th>@endfor</tr></thead><tbody>
                @for ($dd = 0; $dd < 7; $dd++)
                    <tr><td class="pr-1 text-gray-600">{{ $dias[$dd] }}</td>@for ($i = 0; $i < 24; $i++)@php $c = $h['matriz'][$dd][$i]; $op = $c['prom'] ? 0.15 + 0.85 * $c['prom'] / $maxProm : 0; @endphp<td class="p-0"><div title="{{ $dias[$dd] }} {{ $i }}:00 · {{ $c['n'] }} publ. · prom {{ $c['prom'] ?? '—' }}" class="w-5 h-5 m-px rounded-sm" style="background: rgba(0,2,79,{{ $op }}); {{ !$c['n'] ? 'background:#f3f4f6' : '' }}"></div></td>@endfor</tr>
                @endfor
                </tbody></table>
                <h3 class="font-bold text-gray-800 mt-6 mb-1">Seguidores en línea (dato de Meta)</h3>
                <p class="text-xs text-gray-500 mb-3">Cuántos seguidores suelen estar conectados en cada franja.</p>
                @if ($h['en_linea'])
                <table class="text-[10px]"><thead><tr><th></th>@for ($i = 0; $i < 24; $i++)<th class="font-normal text-gray-400 px-0.5">{{ $i }}</th>@endfor</tr></thead><tbody>
                @for ($dd = 0; $dd < 7; $dd++)
                    <tr><td class="pr-1 text-gray-600">{{ $dias[$dd] }}</td>@for ($i = 0; $i < 24; $i++)@php $v = (int) ($h['en_linea'][$dd][$i] ?? 0); @endphp<td class="p-0"><div title="{{ $dias[$dd] }} {{ $i }}:00 · {{ $fmt($v) }} en línea" class="w-5 h-5 m-px rounded-sm" style="background: rgba(220,38,38,{{ $v ? 0.1 + 0.9 * $v / $maxOn : 0 }})"></div></td>@endfor</tr>
                @endfor
                </tbody></table>
                @else <p class="text-sm text-gray-500">Meta aún no entregó este dato para estas páginas.</p> @endif
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Mejores momentos para publicar</h3>
                <p class="text-xs text-gray-500 mb-2">Según el rendimiento real de tus publicaciones (mínimo 2 por franja).</p>
                @forelse ($h['mejores_publicar'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['prom']) }} interac. prom. ({{ $m['n'] }})</span></div>@empty <p class="text-sm text-gray-500">Aún no hay suficientes publicaciones.</p>@endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Cuándo hay más gente conectada</h3>
                @forelse ($h['mejores_en_linea'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['en_linea']) }}</span></div>@empty <p class="text-sm text-gray-500">Sin dato.</p>@endforelse
            </div>
        </div>
    @endif

    {{-- ======================================================== COMENTARIOS --}}
    @if ($tab === 'comentarios')
        @php $c = $tablero['comentarios']; @endphp
        @if (!$c['publicaciones'])
            <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-3 text-sm mb-4">Todavía no hay lecturas de comentarios en el periodo. Se analizan las publicaciones con al menos {{ \App\Services\Inteligencia\ComentariosService::MINIMO }} comentarios al pulsar "Clasificar y leer comentarios" o en la tarea nocturna.</div>
        @endif
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Tono general</h3>
                <p class="text-xs text-gray-500 mb-2">{{ $fmt($c['comentarios']) }} comentarios leídos en {{ $c['publicaciones'] }} publicaciones.</p>
                <div class="flex h-4 rounded overflow-hidden text-[10px] text-white"><div class="bg-emerald-500 text-center" style="width: {{ $c['pct_favor'] }}%">{{ $c['pct_favor'] }}%</div><div class="bg-gray-400 text-center" style="width: {{ $c['pct_neutro'] }}%">{{ $c['pct_neutro'] }}%</div><div class="bg-red-500 text-center" style="width: {{ $c['pct_contra'] }}%">{{ $c['pct_contra'] }}%</div></div>
                <p class="text-xs text-gray-500 mt-1">A favor · neutro · en contra</p>
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Por tema</h3>
                @foreach ($c['por_tema'] as $t)@php $b = max(1, $t['a_favor'] + $t['en_contra'] + $t['neutro']); @endphp
                    <div class="text-sm py-1"><div class="flex justify-between"><span>{{ $t['tema'] }}</span><span class="text-gray-400 text-xs">{{ $t['comentarios'] }}</span></div><div class="flex h-2 rounded overflow-hidden"><div class="bg-emerald-500" style="width: {{ round(100 * $t['a_favor'] / $b) }}%"></div><div class="bg-gray-300" style="width: {{ round(100 * $t['neutro'] / $b) }}%"></div><div class="bg-red-500" style="width: {{ round(100 * $t['en_contra'] / $b) }}%"></div></div></div>
                @endforeach
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Preocupaciones más repetidas</h3>
                @forelse ($c['preocupaciones'] as $p => $n)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ ucfirst($p) }}</span><span class="text-gray-400">{{ $n }}</span></div>@empty <p class="text-sm text-gray-500">Sin datos.</p>@endforelse
                <h3 class="font-bold text-gray-800 mt-5 mb-2">Palabras frecuentes</h3>
                <div class="flex flex-wrap gap-1">@foreach ($c['palabras'] as $p => $n)<span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs" style="font-size: {{ min(18, 11 + $n) }}px">{{ $p }}</span>@endforeach</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-2">Lecturas recientes</h3>
                @forelse ($c['resumenes'] as $x)<div class="py-2 border-b border-gray-100 text-sm"><a href="{{ $x['publicacion']['permalink'] }}" target="_blank" class="text-indigo-700 text-xs hover:underline">{{ $x['publicacion']['texto'] ?: '(sin texto)' }}</a><p class="text-gray-700 mt-1">{{ $x['resumen'] }}</p></div>@empty <p class="text-sm text-gray-500">Sin datos.</p>@endforelse
            </div>
        </div>
    @endif

    {{-- ========================================================= PRONÓSTICO --}}
    @if ($tab === 'pronostico')
        @php $tr = $tablero['tendencias']; @endphp
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-1">Tendencia por tema (últimas {{ $tr['semanas'] }} semanas)</h3>
                <p class="text-xs text-gray-500 mb-3">Tasa de interacción semanal promedio. La dirección sale de la pendiente de la serie; el cambio compara las 2 últimas semanas con las 2 anteriores.</p>
                <canvas id="gTendencia" height="130"></canvas>
                <table class="w-full text-sm mt-4"><thead class="text-gray-500 text-xs"><tr><th class="text-left py-1">Tema</th><th class="text-right">Publ.</th><th class="text-right">Dirección</th><th class="text-right">Cambio</th></tr></thead><tbody>
                @foreach ($tr['temas'] as $t)
                    <tr class="border-t border-gray-100"><td class="py-1.5"><i class="inline-block w-2.5 h-2.5 rounded-full mr-1" style="background: {{ $t['color'] ?? '#94a3b8' }}"></i>{{ $t['tema'] }}</td><td class="text-right">{{ $t['publicaciones'] }}</td><td class="text-right">@if ($t['direccion'] === 'sube')<span class="text-emerald-700 font-semibold">▲ sube</span>@elseif ($t['direccion'] === 'baja')<span class="text-red-700 font-semibold">▼ baja</span>@elseif ($t['direccion'] === 'estable')<span class="text-gray-600">= estable</span>@else<span class="text-gray-400">sin datos</span>@endif</td><td class="text-right">{{ $t['cambio_pct'] !== null ? ($t['cambio_pct'] >= 0 ? '+' : '') . $t['cambio_pct'] . '%' : '—' }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h3 class="font-bold text-gray-800 mb-1">¿Qué pasa si publico…?</h3>
                <p class="text-xs text-gray-500 mb-3">Alcance e interacciones esperados según el historial del tema en esa página (mediana y rango), ajustado por tendencia.</p>
                <div class="space-y-2 text-sm">
                    <select id="pTema" class="w-full rounded-lg border-gray-300 text-sm">@foreach ($campana->temas as $t)<option value="{{ $t->id }}">{{ $t->nombre }}</option>@endforeach</select>
                    <select id="pPagina" class="w-full rounded-lg border-gray-300 text-sm">@foreach ($campana->paginas as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                    <select id="pTipo" class="w-full rounded-lg border-gray-300 text-sm"><option value="">Cualquier formato</option>@foreach (\App\Services\Inteligencia\AnalisisService::TIPOS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    <button id="pBoton" class="w-full rounded-lg bg-[#00024f] text-white py-2">Calcular</button>
                </div>
                <div id="pResultado" class="mt-4 text-sm text-gray-700"></div>
                <h3 class="font-bold text-gray-800 mt-6 mb-2">Mejores momentos</h3>
                @forelse ($tablero['horarios']['mejores_publicar'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['prom']) }} interac.</span></div>@empty <p class="text-sm text-gray-500">Aún sin datos suficientes.</p>@endforelse
            </div>
        </div>
    @endif

    {{-- ====================================================== PUBLICACIONES --}}
    @if ($tab === 'publicaciones')
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h3 class="font-bold text-gray-800 mb-2">Publicaciones del periodo</h3>
            <p class="text-xs text-gray-500 mb-3">Corrige el tema cuando la IA se equivoque: la corrección se guarda como manual y la IA no la vuelve a tocar.</p>
            <table class="w-full text-sm"><thead class="text-gray-500 text-xs"><tr><th class="text-left py-1">Publicación</th><th class="text-left">Tema</th><th class="text-right">Alcance</th><th class="text-right">Interac.</th><th class="text-right">Coment.</th></tr></thead><tbody>
            @forelse ($publicaciones as $p)
                <tr class="border-t border-gray-100 align-top">
                    <td class="py-2"><a href="{{ $p->permalink }}" target="_blank" class="text-indigo-700 hover:underline">{{ \Illuminate\Support\Str::limit(trim((string) $p->texto), 120, '…') ?: '(sin texto)' }}</a><div class="text-[11px] text-gray-400">{{ $p->page?->name }} · {{ ucfirst($p->red) }} · {{ $p->tipo }} · {{ $p->publicado_en->format('d/m/Y H:i') }}</div></td>
                    <td class="py-2"><form method="POST" action="{{ route('inteligencia.publicacion.tema', [$campana, $p]) }}" class="flex items-center gap-1">@csrf<select name="tema_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-xs py-1"><option value="">Sin tema</option>@foreach ($campana->temas as $t)<option value="{{ $t->id }}" @selected($p->tema_id === $t->id)>{{ $t->nombre }}</option>@endforeach</select>@if ($p->tema_fuente === 'ia')<span title="Asignado por la IA ({{ $p->tema_confianza }}% de confianza)" class="text-[10px] text-indigo-600">IA {{ $p->tema_confianza }}%</span>@elseif ($p->tema_fuente === 'manual')<span class="text-[10px] text-gray-500">manual</span>@endif</form></td>
                    <td class="py-2 text-right">{{ $p->alcance !== null ? $fmt($p->alcance) : '—' }}</td><td class="py-2 text-right">{{ $p->interacciones !== null ? $fmt($p->interacciones) : '—' }}</td><td class="py-2 text-right">{{ $p->comentarios !== null ? $fmt($p->comentarios) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-3 text-gray-500">Sin publicaciones en el periodo. Pulsa "Recolectar datos".</td></tr>
            @endforelse
            </tbody></table>
            <div class="mt-3">{{ $publicaciones->appends(['tab' => 'publicaciones'])->links() }}</div>
        </div>
    @endif

    {{-- =========================================================== INFORMES --}}
    @if ($tab === 'informes')
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h3 class="font-bold text-gray-800 mb-2">Informes</h3>
            <p class="text-xs text-gray-500 mb-3">Cada lunes se redacta el informe de la semana anterior. También puedes pedir uno del periodo elegido con "Redactar informe del periodo".</p>
            @forelse ($informes as $i)
                <a href="{{ route('inteligencia.informe', [$campana, $i]) }}" class="flex justify-between py-2 border-b border-gray-100 text-sm hover:bg-gray-50"><span>Del {{ $i->desde->format('d/m/Y') }} al {{ $i->hasta->format('d/m/Y') }}</span><span class="text-gray-400">{{ $i->created_at->format('d/m/Y H:i') }}</span></a>
            @empty
                <p class="text-sm text-gray-500">Todavía no hay informes.</p>
            @endforelse
        </div>
    @endif

    {{-- ====================================================== CONFIGURACIÓN --}}
    @if ($tab === 'config')
        <div class="grid lg:grid-cols-2 gap-6">
            <form method="POST" action="{{ route('inteligencia.update', $campana) }}" class="rounded-2xl border border-gray-200 bg-white p-5 space-y-3 text-sm">@csrf @method('PUT')
                <h3 class="font-bold text-gray-800">Campaña</h3>
                <div><label class="block text-gray-600 mb-1">Nombre</label><input name="nombre" required maxlength="120" value="{{ $campana->nombre }}" class="w-full rounded-lg border-gray-300"></div>
                <div><label class="block text-gray-600 mb-1">Territorio</label><input name="territorio" maxlength="120" value="{{ $campana->territorio }}" class="w-full rounded-lg border-gray-300"></div>
                <div><label class="block text-gray-600 mb-1">Contexto (lo lee la IA al clasificar y redactar)</label><textarea name="descripcion" rows="4" class="w-full rounded-lg border-gray-300">{{ $campana->descripcion }}</textarea></div>
                <div class="grid grid-cols-2 gap-2"><div><label class="block text-gray-600 mb-1">Desde</label><input type="date" name="desde" value="{{ $campana->desde?->toDateString() }}" class="w-full rounded-lg border-gray-300"></div><div><label class="block text-gray-600 mb-1">Hasta</label><input type="date" name="hasta" value="{{ $campana->hasta?->toDateString() }}" class="w-full rounded-lg border-gray-300"></div></div>
                <label class="flex items-center gap-2"><input type="hidden" name="activa" value="0"><input type="checkbox" name="activa" value="1" class="rounded" @checked($campana->activa)> Activa (recolección y análisis automáticos)</label>
                <div><label class="block text-gray-600 mb-1">Páginas</label><div class="max-h-56 overflow-y-auto rounded-lg border border-gray-200 p-2 space-y-1">@foreach ($paginasTodas as $p)<label class="flex items-center gap-2"><input type="checkbox" name="paginas[]" value="{{ $p->id }}" class="rounded" @checked($campana->paginas->contains('id', $p->id))> {{ $p->name }}@if ($p->instagram_business_account_id)<span class="text-xs text-pink-600">+IG</span>@endif</label>@endforeach</div></div>
                <button class="rounded-lg bg-[#00024f] text-white px-4 py-2">Guardar</button>
            </form>
            <div class="rounded-2xl border border-red-200 bg-white p-5 text-sm">
                <h3 class="font-bold text-gray-800 mb-2">Eliminar campaña</h3>
                <p class="text-gray-600 mb-3">Se borran la campaña, sus temas e informes. Las publicaciones y el histórico de las páginas se conservan (sirven para otras campañas).</p>
                <form method="POST" action="{{ route('inteligencia.destroy', $campana) }}" onsubmit="return confirm('¿Eliminar la campaña {{ $campana->nombre }}?')">@csrf @method('DELETE')<button class="rounded-lg bg-red-600 text-white px-4 py-2">Eliminar</button></form>
            </div>
        </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(function () {
  const T = @json($tablero);
  const fmt = (n) => new Intl.NumberFormat('es-CO').format(n);
  const g = (id) => document.getElementById(id);
  if (g('gSerie')) new Chart(g('gSerie'), { type: 'line', data: { labels: T.serie.map(d => d.fecha.slice(5)), datasets: [
    { label: 'Alcance de página', data: T.serie.map(d => d.alcance_pagina), borderColor: '#00024f', backgroundColor: 'rgba(0,2,79,.08)', fill: true, tension: .3, yAxisID: 'y' },
    { label: 'Interacciones de publicaciones', data: T.serie.map(d => d.interacciones), borderColor: '#dc2626', tension: .3, yAxisID: 'y1' },
    { label: 'Publicaciones', data: T.serie.map(d => d.publicaciones), type: 'bar', backgroundColor: 'rgba(148,163,184,.4)', yAxisID: 'y2' } ] },
    options: { responsive: true, interaction: { mode: 'index', intersect: false }, scales: { y: { position: 'left', ticks: { callback: fmt } }, y1: { position: 'right', grid: { drawOnChartArea: false } }, y2: { display: false } } } });
  if (g('gFormato')) new Chart(g('gFormato'), { type: 'bar', data: { labels: T.por_formato.map(f => f.nombre + ' (' + f.n + ')'), datasets: [
    { label: 'Alcance promedio', data: T.por_formato.map(f => f.alcance_prom), backgroundColor: 'rgba(0,2,79,.75)' },
    { label: 'Interacciones promedio', data: T.por_formato.map(f => f.interacciones_prom), backgroundColor: 'rgba(220,38,38,.7)', yAxisID: 'y1' } ] },
    options: { scales: { y: { ticks: { callback: fmt } }, y1: { position: 'right', grid: { drawOnChartArea: false } } } } });
  if (g('gTemas')) new Chart(g('gTemas'), { type: 'bar', data: { labels: T.por_tema.map(t => t.nombre + ' (' + t.n + ')'), datasets: [
    { label: 'Tasa de interacción %', data: T.por_tema.map(t => t.tasa), backgroundColor: T.por_tema.map(t => t.color || '#94a3b8') } ] },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });
  if (g('gEdad')) { const d = T.demografia.edad_genero; const edades = Object.keys(d);
    new Chart(g('gEdad'), { type: 'bar', data: { labels: edades, datasets: [
      { label: 'Mujeres', data: edades.map(e => d[e].F || 0), backgroundColor: 'rgba(219,39,119,.75)' },
      { label: 'Hombres', data: edades.map(e => d[e].M || 0), backgroundColor: 'rgba(37,99,235,.75)' } ] },
      options: { scales: { x: { stacked: true }, y: { stacked: true, ticks: { callback: fmt } } } } }); }
  if (g('gTendencia')) { const tr = T.tendencias; const labels = Array.from({ length: tr.semanas }, (_, i) => 'S-' + (tr.semanas - i));
    new Chart(g('gTendencia'), { type: 'line', data: { labels, datasets: tr.temas.map(t => ({ label: t.tema, data: t.serie, borderColor: t.color || '#94a3b8', spanGaps: true, tension: .3 })) },
      options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Tasa de interacción %' } } } } }); }
  const pb = g('pBoton');
  if (pb) pb.addEventListener('click', async () => {
    const out = g('pResultado'); out.textContent = 'Calculando…';
    const q = new URLSearchParams({ tema_id: g('pTema').value, meta_page_id: g('pPagina').value, tipo: g('pTipo').value });
    try {
      const r = await fetch(@json(route('inteligencia.proyeccion', $campana)) + '?' + q, { headers: { 'Accept': 'application/json' } });
      const d = await r.json();
      if (!d.suficiente) { out.innerHTML = '<span class="text-amber-700">Faltan datos: solo ' + d.n + ' publicaciones de ese tema en esa página (se necesitan 3).</span>'; return; }
      out.innerHTML = '<div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Alcance esperado</div><div class="text-2xl font-bold">' + fmt(d.alcance.esperado) + '</div><div class="text-xs text-gray-500">entre ' + fmt(d.alcance.bajo) + ' y ' + fmt(d.alcance.alto) + '</div>'
        + '<div class="text-xs text-gray-500 mt-2">Interacciones esperadas</div><div class="text-xl font-bold">' + fmt(d.interacciones.esperado) + '</div><div class="text-xs text-gray-500">entre ' + fmt(d.interacciones.bajo) + ' y ' + fmt(d.interacciones.alto) + '</div>'
        + '<div class="text-[11px] text-gray-400 mt-2">Base: ' + d.n + ' publicaciones · factor de tendencia ' + d.factor_tendencia + '</div></div>';
    } catch (e) { out.textContent = 'No se pudo calcular.'; }
  });
})();
</script>
@endsection
