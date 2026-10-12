{{-- Comportamiento de la audiencia e impulsores del alcance. Requiere $av, $tablero, $fmt --}}
@php
    $co = $av['comportamiento'];
    $im = $av['impulsores'];
    $num = fn($v) => $v === null ? '—' : str_replace('.', ',', (string) $v);
    $maxEf = max(1, collect($im['positivos'])->max('efecto_alcance') ?? 1, abs((int) (collect($im['negativos'])->min('efecto_alcance') ?? 0)));
    $rec = $co['frecuencia_recomendada'];
    // El valor «Tema» es el nombre que el usuario le dio al tema: no se traduce (salvo «Sin tema»)
    $valorRasgo = fn(array $x) => $x['rasgo'] === 'Tema' && $x['valor'] !== 'Sin tema' ? $x['valor'] : __($x['valor']);
@endphp

<div class="grid lg:grid-cols-3 gap-5 mb-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">{{ __('Cómo interactúa la gente') }}</h3>
        <p class="text-xs text-gray-500 mb-2">{{ __('Reparto de las interacciones del periodo.') }}</p>
        <div class="h-48"><canvas id="avMezcla"></canvas></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">{{ __('Intensidad por cada 1.000 alcanzados') }}</h3>
        <p class="text-xs text-gray-500 mb-3">{{ __('Normaliza por alcance para comparar páginas y periodos de distinto tamaño.') }}</p>
        <div class="grid grid-cols-2 gap-3">
            @foreach (['viralidad' => [__('Compartidos'), __('qué tanto se riega el contenido')], 'conversacion' => [__('Comentarios'), __('qué tanto conversa la gente')], 'reacciones' => [__('Reacciones'), __('respuesta emocional rápida')], 'guardados' => [__('Guardados'), __('interés por volver a verlo')]] as $k => [$n, $a])
                <div class="rounded-xl bg-gray-50 p-3"><div class="text-[11px] uppercase tracking-wide text-gray-400">{{ $n }}</div><div class="text-xl font-bold text-gray-900">{{ $num($co['por_mil'][$k]) }}</div><div class="text-[10px] text-gray-400">{{ $a }}</div></div>
            @endforeach
        </div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">{{ __('Frecuencia de publicación') }}</h3>
        <p class="text-xs text-gray-500 mb-3">{{ __('Publicaciones por página en un día frente al alcance de cada una. Sirve para ver si publicar más satura a la audiencia.') }}</p>
        <table class="w-full text-sm"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">{{ __('Ritmo') }}</th><th class="text-right">{{ __('Días') }}</th><th class="text-right">{{ __('Alcance / publ.') }}</th><th class="text-right">{{ __('Tasa') }}</th></tr></thead><tbody>
        @foreach ($co['frecuencia'] as $f)
            <tr class="border-t border-gray-100 {{ $rec && $rec['clave'] === $f['clave'] ? 'bg-emerald-50/70 font-semibold' : '' }}"><td class="py-1.5">{{ __($f['nombre']) }} @if ($rec && $rec['clave'] === $f['clave'])<span class="text-[10px] text-emerald-700">★ {{ __('mejor') }}</span>@endif</td><td class="text-right">{{ $f['dias'] }}</td><td class="text-right">{{ $f['alcance_por_publicacion'] !== null ? $fmt($f['alcance_por_publicacion']) : '—' }}</td><td class="text-right">{{ $f['tasa'] !== null ? $num($f['tasa']) . ' %' : '—' }}</td></tr>
        @endforeach
        </tbody></table>
    </div>
</div>

{{-- Impulsores --}}
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm mb-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
        <h3 class="font-bold text-gray-800">{{ __('¿Qué hace que una publicación llegue a más gente?') }}</h3>
        <span class="text-xs text-gray-500">{{ __('Base: :n publicaciones · alcance mediano :alcance · tasa mediana :tasa %', ['n' => $im['base']['n'], 'alcance' => $fmt($im['base']['mediana_alcance']), 'tasa' => $num($im['base']['mediana_tasa'])]) }}</span>
    </div>
    <p class="text-xs text-gray-500 mb-4">{{ __('Compara el alcance mediano de las publicaciones con cada rasgo (formato, franja, día, largo del texto, preguntas, hashtags, enlaces, emojis, tema) contra la mediana general. Con pocas publicaciones el efecto se reduce para no exagerar casos aislados (mínimo 3). Si varios rasgos siempre van juntos (por ejemplo, reels publicados de noche), comparten el mismo efecto: prueba variarlos por separado para saber cuál pesa más.') }}</p>
    @if ($im['base']['n'] < 6)
        <p class="text-sm text-amber-700">{{ __('Faltan publicaciones con métricas para comparar rasgos (hay :n).', ['n' => $im['base']['n']]) }}</p>
    @else
        <div class="grid lg:grid-cols-2 gap-6">
            <div>
                <h4 class="text-sm font-bold text-emerald-700 mb-2">▲ {{ __('Lo que impulsa') }}</h4>
                @forelse ($im['positivos'] as $x)
                    <div class="py-1.5">
                        <div class="flex justify-between gap-3 text-sm"><span class="min-w-0"><b>{{ $valorRasgo($x) }}</b> <span class="text-gray-400 text-xs">· {{ __($x['rasgo']) }} · {{ __(':n publ.', ['n' => $x['n']]) }}</span></span><span class="font-semibold text-emerald-700 shrink-0">+{{ $x['efecto_alcance'] }} %</span></div>
                        <div class="h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ min(100, round(100 * $x['efecto_alcance'] / $maxEf)) }}%"></div></div>
                    </div>
                @empty <p class="text-sm text-gray-500">{{ __('Ningún rasgo destaca todavía.') }}</p> @endforelse
            </div>
            <div>
                <h4 class="text-sm font-bold text-rose-600 mb-2">▼ {{ __('Lo que frena') }}</h4>
                @forelse ($im['negativos'] as $x)
                    <div class="py-1.5">
                        <div class="flex justify-between gap-3 text-sm"><span class="min-w-0"><b>{{ $valorRasgo($x) }}</b> <span class="text-gray-400 text-xs">· {{ __($x['rasgo']) }} · {{ __(':n publ.', ['n' => $x['n']]) }}</span></span><span class="font-semibold text-rose-600 shrink-0">{{ $x['efecto_alcance'] }} %</span></div>
                        <div class="h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-rose-500" style="width: {{ min(100, round(100 * abs($x['efecto_alcance']) / $maxEf)) }}%"></div></div>
                    </div>
                @empty <p class="text-sm text-gray-500">{{ __('Ningún rasgo frena de forma clara.') }}</p> @endforelse
            </div>
        </div>
        <details class="mt-4">
            <summary class="cursor-pointer text-xs font-semibold text-indigo-700">{{ __('Ver todos los rasgos comparados') }}</summary>
            <div class="overflow-x-auto mt-2">
                <table class="w-full text-sm min-w-[600px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">{{ __('Rasgo') }}</th><th class="text-left">{{ __('Valor') }}</th><th class="text-right">{{ __('Publ.') }}</th><th class="text-right">{{ __('Alcance mediano') }}</th><th class="text-right">{{ __('Efecto alcance') }}</th><th class="text-right">{{ __('Tasa mediana') }}</th><th class="text-right">{{ __('Efecto tasa') }}</th></tr></thead><tbody>
                @foreach (collect($im['todos'])->sortBy('rasgo') as $x)
                    <tr class="border-t border-gray-100"><td class="py-1 text-gray-500">{{ __($x['rasgo']) }}</td><td>{{ $valorRasgo($x) }}</td><td class="text-right">{{ $x['n'] }}</td><td class="text-right">{{ $fmt($x['mediana_alcance']) }}</td>
                        <td class="text-right {{ $x['efecto_alcance'] > 0 ? 'text-emerald-700' : ($x['efecto_alcance'] < 0 ? 'text-rose-600' : '') }}">{{ $x['efecto_alcance'] > 0 ? '+' : '' }}{{ $x['efecto_alcance'] }} %</td>
                        <td class="text-right">{{ $num($x['mediana_tasa']) }} %</td><td class="text-right {{ $x['efecto_tasa'] > 0 ? 'text-emerald-700' : ($x['efecto_tasa'] < 0 ? 'text-rose-600' : '') }}">{{ $x['efecto_tasa'] > 0 ? '+' : '' }}{{ $x['efecto_tasa'] }} %</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </details>
    @endif
</div>

<div class="grid lg:grid-cols-2 gap-5 mb-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">{{ __('Franja horaria') }}</h3>
        <p class="text-xs text-gray-500 mb-2">{{ __('Alcance mediano y tasa de interacción según la hora de publicación.') }}</p>
        <div class="h-56"><canvas id="avFranjas"></canvas></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">{{ __('Día de la semana') }}</h3>
        <p class="text-xs text-gray-500 mb-2">{{ __('Alcance mediano y tasa de interacción según el día.') }}</p>
        <div class="h-56"><canvas id="avDias"></canvas></div>
    </div>
</div>

@include('admin.inteligencia._horarios')
