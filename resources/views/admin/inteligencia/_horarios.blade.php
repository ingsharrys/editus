{{-- Mapas de calor día × hora (rendimiento real y seguidores en línea). Requiere $tablero, $fmt --}}
@php
    $dias = \App\Services\Inteligencia\AnalisisService::DIAS;
    $h = $tablero['horarios'];
    $maxProm = 1; foreach ($h['matriz'] as $f) foreach ($f as $c) $maxProm = max($maxProm, (int) ($c['prom'] ?? 0));
    $maxOn = 1; foreach ($h['en_linea'] as $f) foreach ($f as $v) $maxOn = max($maxOn, (int) $v);
@endphp
<div class="grid lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto">
        <h3 class="font-bold text-gray-800 mb-1">Interacción promedio según día y hora de publicación</h3>
        <p class="text-xs text-gray-500 mb-3">Cada celda: promedio de interacciones de las publicaciones hechas en esa franja. Más oscuro = mejor.</p>
        <table class="text-[10px]"><thead><tr><th></th>@for ($i = 0; $i < 24; $i++)<th class="font-normal text-gray-400 px-0.5">{{ $i }}</th>@endfor</tr></thead><tbody>
        @for ($dd = 0; $dd < 7; $dd++)
            <tr><td class="pr-1 text-gray-600">{{ $dias[$dd] }}</td>@for ($i = 0; $i < 24; $i++)@php $c = $h['matriz'][$dd][$i]; $op = $c['prom'] ? 0.15 + 0.85 * $c['prom'] / $maxProm : 0; @endphp<td class="p-0"><div title="{{ $dias[$dd] }} {{ $i }}:00 · {{ $c['n'] }} publ. · prom {{ $c['prom'] ?? '—' }}" class="w-5 h-5 m-px rounded-sm" style="background: {{ $c['n'] ? 'rgba(0,2,79,' . $op . ')' : '#f3f4f6' }}"></div></td>@endfor</tr>
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
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <h3 class="font-bold text-gray-800 mb-2">Mejores momentos para publicar</h3>
        <p class="text-xs text-gray-500 mb-2">Según el rendimiento real (mínimo 2 publicaciones por franja).</p>
        @forelse ($h['mejores_publicar'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['prom']) }} interac. ({{ $m['n'] }})</span></div>@empty <p class="text-sm text-gray-500">Aún no hay suficientes publicaciones.</p>@endforelse
        <h3 class="font-bold text-gray-800 mt-5 mb-2">Cuándo hay más gente conectada</h3>
        @forelse ($h['mejores_en_linea'] as $m)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span>{{ $m['etiqueta'] }}</span><span class="text-gray-500">{{ $fmt($m['en_linea']) }}</span></div>@empty <p class="text-sm text-gray-500">Sin dato.</p>@endforelse
    </div>
</div>
