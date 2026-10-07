{{-- Emociones y voz del público. Requiere $av, $tablero, $fmt --}}
@php
    $c = $tablero['comentarios'];
    $coloresEmo = \App\Services\Inteligencia\InteligenciaAvanzadaService::COLORES_EMOCION;
    $emos = collect($c['emociones'] ?? [])->filter(fn($e) => $e['n'] > 0)->sortByDesc('n')->values();
    $maxEmo = max(1, (int) $emos->max('n'));
    $fav = $c['favorabilidad_neta'];
    $voz = [
        'preguntas' => ['Preguntas que hace la gente', 'Respóndelas en una publicación o en los comentarios: generan confianza y conversación.'],
        'quejas' => ['Quejas y críticas', 'Lo que molesta. Atiéndelas rápido para bajar el enojo y la desconfianza.'],
        'pedidos' => ['Lo que la gente pide', 'Peticiones concretas: temas, productos, soluciones o cobertura.'],
        'menciones' => ['Personas, marcas y lugares mencionados', 'Los nombres que más aparecen en la conversación.'],
    ];
@endphp

@if (!$c['publicaciones'])
    <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm mb-5">
        Todavía no hay comentarios leídos por la IA en este periodo. Se leen las publicaciones con al menos {{ \App\Services\Inteligencia\ComentariosService::MINIMO }} comentarios
        al pulsar «Clasificar y leer comentarios» en la campaña (o cada madrugada en las campañas activas). Sin esa lectura no hay emociones, favorabilidad ni intención.
    </div>
@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Favorabilidad neta</div>
        <div class="text-3xl font-bold {{ $fav === null ? 'text-gray-400' : ($fav >= 10 ? 'text-emerald-700' : ($fav <= -10 ? 'text-rose-600' : 'text-gray-800')) }}">{{ $fav === null ? '—' : ($fav > 0 ? '+' : '') . $fav }}</div>
        <div class="text-[11px] text-gray-400">a favor menos en contra (−100 a +100)</div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ $av['etiqueta_intencion'] }}</div>
        <div class="text-3xl font-bold text-gray-900">{{ $c['pct_intencion'] !== null ? str_replace('.', ',', (string) $c['pct_intencion']) . ' %' : '—' }}</div>
        <div class="text-[11px] text-gray-400">de los comentarios leídos</div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Emoción dominante</div>
        @php $top = $emos->first(); @endphp
        <div class="text-2xl font-bold" style="color: {{ $top ? ($coloresEmo[$top['clave']] ?? '#111827') : '#9ca3af' }}">{{ $top ? $top['nombre'] : '—' }}</div>
        <div class="text-[11px] text-gray-400">{{ $top ? $top['pct'] . ' % de las emociones detectadas' : 'sin lectura todavía' }}</div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Comentarios leídos</div>
        <div class="text-3xl font-bold text-gray-900">{{ $fmt($c['comentarios']) }}</div>
        <div class="text-[11px] text-gray-400">en {{ $c['publicaciones'] }} publicaciones</div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-5 mb-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">Tono de la conversación</h3>
        <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 mt-3">
            <div class="bg-emerald-500" style="width: {{ $c['pct_favor'] }}%"></div><div class="bg-gray-300" style="width: {{ $c['pct_neutro'] }}%"></div><div class="bg-rose-500" style="width: {{ $c['pct_contra'] }}%"></div>
        </div>
        <div class="flex justify-between text-xs mt-2"><span class="text-emerald-700 font-semibold">A favor {{ $c['pct_favor'] }} %</span><span class="text-gray-500">Neutro {{ $c['pct_neutro'] }} %</span><span class="text-rose-600 font-semibold">En contra {{ $c['pct_contra'] }} %</span></div>
        <h3 class="font-bold text-gray-800 mt-6 mb-2">Emociones del público</h3>
        @forelse ($emos as $e)
            <div class="py-1"><div class="flex justify-between text-sm"><span>{{ $e['nombre'] }}</span><span class="text-gray-500">{{ $e['pct'] }} %</span></div><div class="h-2 rounded-full bg-gray-100"><div class="h-2 rounded-full" style="width: {{ round(100 * $e['n'] / $maxEmo) }}%; background: {{ $coloresEmo[$e['clave']] ?? '#00024f' }}"></div></div></div>
        @empty <p class="text-sm text-gray-500">Sin lectura de emociones todavía.</p> @endforelse
    </div>
    <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-1">Cómo cambian las emociones semana a semana</h3>
        <p class="text-xs text-gray-500 mb-2">Reparto de emociones en los comentarios leídos de cada semana (las semanas sin lecturas quedan vacías).</p>
        <div class="h-64"><canvas id="avEmocionesSemana"></canvas></div>
    </div>
</div>

<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm mb-5 overflow-x-auto">
    <h3 class="font-bold text-gray-800 mb-1">¿Qué emociones generan más alcance?</h3>
    <p class="text-xs text-gray-500 mb-3">Cada publicación se agrupa por la emoción que más despertó en sus comentarios; «veces» compara su alcance mediano con el de todas las publicaciones leídas.</p>
    @if ($av['emociones']['suficiente'])
        <table class="w-full text-sm min-w-[520px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1">Emoción dominante</th><th class="text-right">Publicaciones</th><th class="text-right">Alcance mediano</th><th class="text-right">Tasa mediana</th><th class="text-right">Veces el alcance</th></tr></thead><tbody>
        @foreach ($av['emociones']['lista'] as $e)
            <tr class="border-t border-gray-100"><td class="py-1.5"><i class="inline-block w-2.5 h-2.5 rounded-full mr-1.5" style="background: {{ $coloresEmo[$e['clave']] ?? '#94a3b8' }}"></i>{{ $e['nombre'] }}</td><td class="text-right">{{ $e['n'] }}</td><td class="text-right">{{ $fmt($e['mediana_alcance']) }}</td><td class="text-right">{{ str_replace('.', ',', (string) $e['mediana_tasa']) }} %</td>
                <td class="text-right font-semibold {{ ($e['veces'] ?? 1) >= 1.15 ? 'text-emerald-700' : (($e['veces'] ?? 1) <= 0.85 ? 'text-rose-600' : 'text-gray-700') }}">{{ $e['veces'] !== null ? str_replace('.', ',', (string) $e['veces']) . '×' : '—' }}</td></tr>
        @endforeach
        </tbody></table>
    @else
        <p class="text-sm text-gray-500">Se necesitan al menos 3 publicaciones con comentarios leídos y métricas de alcance (hay {{ $av['emociones']['n'] }}).</p>
    @endif
</div>

<h3 class="font-bold text-gray-900 mb-3">La voz del público</h3>
<div class="grid md:grid-cols-2 xl:grid-cols-4 gap-5 mb-5">
    @foreach ($voz as $campo => [$titulo, $ayuda])
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
            <h4 class="font-bold text-gray-800">{{ $titulo }}</h4>
            <p class="text-[11px] text-gray-500 mb-2">{{ $ayuda }}</p>
            @forelse ($c[$campo] ?? [] as $frase => $n)
                <div class="flex justify-between gap-2 text-sm py-1 border-b border-gray-100"><span class="min-w-0">{{ \App\Services\Inteligencia\InteligenciaAvanzadaService::frase($frase) }}</span><span class="text-gray-400 shrink-0">{{ $n }}</span></div>
            @empty <p class="text-sm text-gray-400">Sin datos todavía.</p> @endforelse
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-2">Preocupaciones más repetidas</h3>
        @forelse ($c['preocupaciones'] as $k => $v)<div class="flex justify-between text-sm py-1 border-b border-gray-100"><span class="min-w-0">{{ \App\Services\Inteligencia\InteligenciaAvanzadaService::frase($k) }}</span><span class="text-gray-400 shrink-0 ml-2">{{ $v }}</span></div>@empty <p class="text-sm text-gray-500">Sin datos.</p>@endforelse
        <h3 class="font-bold text-gray-800 mt-5 mb-2">Palabras frecuentes</h3>
        <div class="flex flex-wrap gap-1.5">@forelse ($c['palabras'] as $k => $v)<span class="rounded-full bg-gray-100 text-gray-700 px-2.5 py-1 text-xs">{{ $k }} <span class="text-gray-400">{{ $v }}</span></span>@empty <span class="text-sm text-gray-500">Sin datos.</span>@endforelse</div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-2">Tono por tema</h3>
        @forelse ($c['por_tema'] as $t)
            @php $b = max(1, $t['a_favor'] + $t['en_contra'] + $t['neutro']); @endphp
            <div class="py-1.5 border-b border-gray-100"><div class="flex justify-between text-sm"><span class="truncate">{{ $t['tema'] }}</span><span class="text-gray-400 text-xs">{{ $t['comentarios'] }} coment.</span></div>
                <div class="flex h-1.5 rounded-full overflow-hidden bg-gray-100 mt-1"><div class="bg-emerald-500" style="width: {{ round(100 * $t['a_favor'] / $b) }}%"></div><div class="bg-gray-300" style="width: {{ round(100 * $t['neutro'] / $b) }}%"></div><div class="bg-rose-500" style="width: {{ round(100 * $t['en_contra'] / $b) }}%"></div></div></div>
        @empty <p class="text-sm text-gray-500">Sin datos.</p>@endforelse
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0">
        <h3 class="font-bold text-gray-800 mb-2">Lecturas recientes de la IA</h3>
        @forelse ($c['resumenes'] as $x)
            <div class="py-2 border-b border-gray-100 text-sm"><a href="{{ $x['publicacion']['permalink'] }}" target="_blank" rel="noopener" class="text-indigo-700 hover:underline">{{ $x['publicacion']['texto'] ?: '(sin texto)' }}</a><p class="text-xs text-gray-600 mt-1">{{ $x['resumen'] }}</p></div>
        @empty <p class="text-sm text-gray-500">Sin lecturas todavía.</p>@endforelse
    </div>
</div>
