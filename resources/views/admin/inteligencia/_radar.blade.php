@php
    $radar = $radares->firstWhere('id', $radarId) ?? $radares->firstWhere('estado', 'listo') ?? $radares->first();
    $s = $radar?->sintesis ?: [];
    $hallazgos = collect($radar?->hallazgos ?? []);
    $usadas = $hallazgos->pluck('url')->flip();
    $otras = collect($radar?->fuentes ?? [])->reject(fn($f) => $usadas->has($f['url']));
    $tonoColor = ['favorable' => 'bg-emerald-50 text-emerald-700', 'desfavorable' => 'bg-rose-50 text-rose-700', 'neutral' => 'bg-gray-100 text-gray-600'];
    $relColor = ['oportunidad' => 'bg-indigo-50 text-indigo-700', 'riesgo' => 'bg-amber-50 text-amber-800', 'contexto' => 'bg-slate-100 text-slate-600'];
    $nivelColor = ['alta' => 'bg-rose-50 text-rose-700', 'media' => 'bg-amber-50 text-amber-800', 'baja' => 'bg-gray-100 text-gray-600'];
@endphp
<div class="grid lg:grid-cols-[20rem_minmax(0,1fr)] gap-5 items-start">
    {{-- Lateral: nueva investigación + historial --}}
    <div class="space-y-4 min-w-0">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-bold text-gray-900">Investigar en la web</h3>
            <p class="text-xs text-gray-500 mt-1">La IA busca en internet lo publicado sobre cada tema de la campaña en <strong>{{ $campana->territorio ?: 'su territorio' }}</strong>: noticias, sitios de instituciones, columnas y contenido público. Te entrega las fuentes con su enlace, el tono y un diagnóstico.</p>
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-4 mb-1">Enfoque (opcional)</label>
            <input id="radar-enfoque" maxlength="300" placeholder="Ej: qué se dice de las vías rurales y quién lo critica" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
            <button type="button" id="radar-boton" class="w-full h-10 mt-3 rounded-lg bg-[#00024f] text-white text-sm font-semibold shadow-sm hover:opacity-90 disabled:opacity-50" @disabled(!$iaLista)>🌐 Investigar ahora</button>
            <div class="mt-4 rounded-xl bg-gray-50 p-3 text-[11px] text-gray-600 space-y-1">
                <div>• Investiga {{ min($campana->temas->count(), \App\Services\Inteligencia\RadarWebService::MAX_TEMAS) }} tema(s) y la conversación general, uno por uno (tarda unos minutos).</div>
                <div>• No entra a Facebook ni a Instagram: solo lo que está abierto en la web.</div>
                <div>• Cada investigación cuesta aprox. 1 a 2 dólares de IA; se hace solo cuando la pides.</div>
                <div>• El consultor de IA usa la última investigación como contexto del territorio.</div>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-gray-900 mb-2">Investigaciones</h3>
            @forelse ($radares as $r)
                <a href="{{ route('inteligencia.show', [$campana, 'tab' => 'radar', 'radar' => $r->id]) }}" class="flex items-center justify-between gap-2 rounded-lg px-2 py-2 text-sm hover:bg-gray-50 {{ $radar && $radar->id === $r->id ? 'bg-indigo-50/60' : '' }}">
                    <span class="min-w-0"><span class="block font-medium text-gray-800 truncate">{{ $r->enfoque ?: 'Todos los temas' }}</span><span class="block text-[11px] text-gray-400">{{ $r->created_at->format('d/m/Y H:i') }} · {{ count($r->hallazgos ?? []) }} fuentes</span></span>
                    @if ($r->estado === 'listo')<span class="text-[11px] font-semibold text-emerald-700">lista</span>@elseif ($r->estado === 'error')<span class="text-[11px] font-semibold text-rose-700">error</span>@else<span class="text-[11px] font-semibold text-amber-700">incompleta</span>@endif
                </a>
            @empty
                <p class="text-xs text-gray-500">Aún no hay investigaciones.</p>
            @endforelse
        </div>
    </div>

    {{-- Resultado --}}
    <div class="min-w-0 space-y-4">
        @if (!$radar)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">
                Pulsa <strong>«Investigar ahora»</strong> para que la IA busque en internet qué se dice de los temas de la campaña en {{ $campana->territorio ?: 'el territorio' }}.
            </div>
        @else
            @if ($radar->estado === 'error')<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 text-sm">{{ $radar->error }}</div>@endif
            @if ($radar->estado === 'en_curso')<div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">Esta investigación quedó a medias ({{ $radar->avance }} de {{ count($radar->plan ?? []) }} frentes). Se muestran las fuentes encontradas; inicia otra para tener el diagnóstico completo.</div>@endif

            @if ($s)
                <div class="rounded-2xl bg-[#00024f] text-white p-5 shadow-md">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-200">Diagnóstico del territorio · {{ $radar->created_at->format('d/m/Y') }}</div>
                    <p class="mt-2 text-sm leading-relaxed text-indigo-50">{{ $s['resumen'] ?? '' }}</p>
                    <div class="mt-3 text-[11px] text-indigo-200">{{ $hallazgos->count() }} fuentes analizadas · {{ $radar->busquedas }} búsquedas{{ $radar->enfoque ? ' · enfoque: ' . $radar->enfoque : '' }}</div>
                </div>

                @if (!empty($s['temas']))
                    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach ($s['temas'] as $t)
                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm min-w-0">
                                <div class="flex items-start justify-between gap-2"><h4 class="text-sm font-bold text-gray-900">{{ $t['tema'] }}</h4>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $nivelColor[$t['intensidad']] ?? '' }}">{{ $t['intensidad'] }}</span></div>
                                <span class="inline-block mt-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $tonoColor[$t['tono']] ?? '' }}">{{ $t['tono'] }}</span>
                                <p class="text-xs text-gray-600 mt-2">{{ $t['que_se_dice'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="grid md:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><h4 class="text-sm font-bold text-emerald-800 mb-2">Oportunidades</h4>
                        @forelse ($s['oportunidades'] ?? [] as $o)<div class="text-sm text-gray-700 py-1.5 border-b border-gray-100 last:border-0">{{ $o }}</div>@empty<p class="text-xs text-gray-500">Sin oportunidades claras.</p>@endforelse</div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><h4 class="text-sm font-bold text-rose-800 mb-2">Alertas</h4>
                        @forelse ($s['alertas'] ?? [] as $a)<div class="text-sm text-gray-700 py-1.5 border-b border-gray-100 last:border-0">{{ $a }}</div>@empty<p class="text-xs text-gray-500">Sin alertas.</p>@endforelse</div>
                </div>

                @if (!empty($s['recomendaciones']))
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><h4 class="text-sm font-bold text-gray-900 mb-2">Qué hacer esta semana</h4>
                        @foreach ($s['recomendaciones'] as $r)
                            <div class="flex gap-3 py-2 border-b border-gray-100 last:border-0"><span class="shrink-0 h-fit rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $nivelColor[$r['prioridad']] ?? '' }}">{{ $r['prioridad'] }}</span>
                                <div class="min-w-0"><div class="text-sm font-semibold text-gray-800">{{ $r['accion'] }}</div><div class="text-xs text-gray-500">{{ $r['por_que'] }}</div></div></div>
                        @endforeach
                    </div>
                @endif

                @if (!empty($s['actores']))
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm overflow-x-auto"><h4 class="text-sm font-bold text-gray-900 mb-2">Actores en la conversación</h4>
                        <table class="w-full text-sm"><thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="py-1.5">Actor</th><th>Tipo</th><th>Postura</th></tr></thead><tbody>
                            @foreach ($s['actores'] as $a)<tr class="border-t border-gray-100"><td class="py-1.5 font-medium text-gray-800">{{ $a['nombre'] }}</td><td class="text-gray-500">{{ $a['tipo'] }}</td><td class="text-gray-600">{{ $a['postura'] }}</td></tr>@endforeach
                        </tbody></table></div>
                @endif
            @endif

            {{-- Listado de fuentes analizadas --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h4 class="text-sm font-bold text-gray-900">Fuentes analizadas ({{ $hallazgos->count() }})</h4>
                    <span class="text-[11px] text-gray-400">✓ = la URL vino en los resultados reales de la búsqueda</span>
                </div>
                @forelse ($hallazgos->groupBy('frente') as $frente => $lista)
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 mt-3 mb-1">{{ $frente }} · {{ $lista->count() }}</div>
                    @foreach ($lista as $h)
                        <div class="py-2.5 border-b border-gray-100 last:border-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="{{ $h['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-[#00024f] hover:underline">{{ $h['titulo'] }}</a>
                                @if (!empty($h['verificada']))<span class="text-emerald-600 text-xs" title="Fuente devuelta por la búsqueda">✓</span>@endif
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 mt-1 text-[11px]">
                                <span class="text-gray-500">{{ $h['medio'] }}{{ !empty($h['fecha']) ? ' · ' . \Carbon\Carbon::parse($h['fecha'])->format('d/m/Y') : '' }}</span>
                                <span class="rounded-full px-2 py-0.5 font-semibold {{ $tonoColor[$h['tono']] ?? '' }}">{{ $h['tono'] }}</span>
                                <span class="rounded-full px-2 py-0.5 font-semibold {{ $relColor[$h['relevancia']] ?? '' }}">{{ $h['relevancia'] }}</span>
                                @foreach ($h['actores'] ?? [] as $a)<span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600">{{ $a }}</span>@endforeach
                            </div>
                            @if (!empty($h['resumen']))<p class="text-xs text-gray-600 mt-1">{{ $h['resumen'] }}</p>@endif
                        </div>
                    @endforeach
                @empty
                    <p class="text-sm text-gray-500">No se encontraron fuentes.</p>
                @endforelse

                @if ($otras->isNotEmpty())
                    <details class="mt-4 rounded-xl border border-gray-200">
                        <summary class="cursor-pointer select-none px-3 py-2.5 text-sm font-semibold text-gray-700">Otras páginas que la búsqueda consultó ({{ $otras->count() }})</summary>
                        <div class="border-t border-gray-100 p-3 space-y-1">
                            @foreach ($otras as $f)<a href="{{ $f['url'] }}" target="_blank" rel="noopener noreferrer" class="block text-xs text-indigo-700 hover:underline truncate">{{ $f['titulo'] }}</a>@endforeach
                        </div>
                    </details>
                @endif
            </div>

            @if ($radar->lineas)
                <details class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <summary class="cursor-pointer select-none px-5 py-3 text-sm font-semibold text-gray-700">Bitácora de la investigación</summary>
                    <div class="border-t border-gray-100 px-5 py-3 text-xs space-y-1">
                        @foreach ($radar->lineas as $l)<div><span class="{{ $l['ok'] ? 'text-emerald-600' : 'text-rose-600' }}">{{ $l['ok'] ? '✓' : '✕' }}</span> <strong>{{ $l['frente'] }}</strong> — {{ $l['detalle'] }}</div>@endforeach
                    </div>
                </details>
            @endif
        @endif
    </div>
</div>
