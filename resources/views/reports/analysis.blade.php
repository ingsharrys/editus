@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto mt-20 space-y-5">
        @php $fmt = fn($n) => number_format((int) $n, 0, ',', '.'); @endphp

        {{-- Encabezado --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl">Análisis de rendimiento</h2>
                <p class="text-xs text-gray-500">
                    Basado en {{ $fmt($totalPosts) }} publicaciones exitosas con métricas.
                    Los promedios exigen mínimo {{ $minN }} publicaciones para considerarse confiables.
                </p>
            </div>
            <a href="{{ route('reports.posts') }}"
               class="text-xs px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                📋 Ver informe detallado
            </a>
        </div>

        {{-- Filtros --}}
        <form method="GET" action="{{ route('reports.analytics') }}"
              class="rounded-xl border border-gray-200 bg-white p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Período</label>
                <select name="days" onchange="this.form.submit()" class="rounded-lg border-gray-200 text-sm">
                    @foreach ([30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 180 => 'Últimos 6 meses', 365 => 'Último año', 0 => 'Todo el histórico'] as $d => $label)
                        <option value="{{ $d }}" {{ $filters['days'] === $d ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Red</label>
                <select name="network" onchange="this.form.submit()" class="rounded-lg border-gray-200 text-sm">
                    <option value="">Todas</option>
                    <option value="facebook" {{ $filters['network'] === 'facebook' ? 'selected' : '' }}>📘 Facebook</option>
                    <option value="instagram" {{ $filters['network'] === 'instagram' ? 'selected' : '' }}>📸 Instagram</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Campaña</label>
                <select name="campaign_id" onchange="this.form.submit()" class="rounded-lg border-gray-200 text-sm">
                    <option value="">Todas</option>
                    @foreach ($campaignsCatalog as $c)
                        <option value="{{ $c->id }}" {{ (string) $filters['campaign_id'] === (string) $c->id ? 'selected' : '' }}>
                            {{ $c->name }}{{ $c->is_system ? ' ⚙️' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Medios a comparar</label>
                <details class="relative" id="mediosDropdown">
                    <summary class="cursor-pointer select-none rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm min-w-[220px] list-none">
                        {{ count($filters['page_ids'])
                            ? '⚖️ ' . count($filters['page_ids']) . ' medio(s) seleccionados'
                            : 'Todos los medios' }}
                        <span class="float-right text-gray-400">▾</span>
                    </summary>
                    <div class="absolute z-30 mt-1 w-80 max-h-72 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg p-3">
                        <input type="search" id="mediosSearch" placeholder="Buscar medio..."
                               class="w-full rounded-md border-gray-200 text-xs mb-2">
                        @foreach ($pagesCatalog as $p)
                            <label class="medioOption flex items-center gap-2 text-sm py-1 px-1 rounded hover:bg-gray-50 cursor-pointer"
                                   data-name="{{ mb_strtolower($p->name) }}">
                                <input type="checkbox" name="page_ids[]" value="{{ $p->id }}"
                                       class="rounded border-gray-300"
                                       {{ in_array($p->id, $filters['page_ids']) ? 'checked' : '' }}>
                                <span class="truncate">{{ $p->name }}</span>
                            </label>
                        @endforeach
                        <div class="flex gap-2 mt-2 sticky bottom-0 bg-white pt-2 border-t">
                            <button type="submit" class="flex-1 text-xs px-2 py-1.5 rounded-md bg-blue-600 text-white hover:bg-blue-700">
                                Aplicar comparación
                            </button>
                            <a href="{{ route('reports.analytics', ['days' => $filters['days'], 'network' => $filters['network']]) }}"
                               class="text-xs px-2 py-1.5 rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50">
                                Quitar
                            </a>
                        </div>
                    </div>
                </details>
            </div>
        </form>

        @if (count($filters['page_ids']) > 1)
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-800 px-4 py-2 text-xs">
                ⚖️ Modo comparación: todo el análisis de abajo está limitado a los
                <strong>{{ count($filters['page_ids']) }} medios seleccionados</strong> — el ranking, las horas,
                los días y las conclusiones comparan solo entre ellos.
            </div>
        @endif

        {{-- Comparador por publicación --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="font-semibold text-sm mb-1">🎯 Comparar una publicación entre medios</p>
            <p class="text-[11px] text-gray-500 mb-3">
                Selecciona una publicación que se envió a varios medios a la vez y descubre en cuál rindió mejor.
            </p>
            <form method="GET" action="{{ route('reports.analytics') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="days" value="{{ $filters['days'] }}">
                @if ($filters['network'])<input type="hidden" name="network" value="{{ $filters['network'] }}">@endif
                @foreach ($filters['page_ids'] as $pid)
                    <input type="hidden" name="page_ids[]" value="{{ $pid }}">
                @endforeach
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Buscar publicación</label>
                    <input type="search" name="batch_q" value="{{ $filters['batch_q'] }}" placeholder="Texto del mensaje…"
                           class="rounded-lg border-gray-200 text-sm min-w-[220px]">
                </div>
                <div class="flex-1 min-w-[280px]">
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Publicación (las 100 más recientes multi-medio)</label>
                    <select name="batch" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                        <option value="">— Selecciona una publicación —</option>
                        @foreach ($batchCatalog as $b)
                            <option value="{{ $b->batch_uuid }}" {{ $filters['batch'] === $b->batch_uuid ? 'selected' : '' }}>
                                {{ \Carbon\Carbon::parse($b->published_at)->format('d/m/Y') }} · {{ $b->pages_count }} medios · {{ \Illuminate\Support\Str::limit($b->message ?: '(sin texto)', 70) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="text-xs px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Buscar</button>
            </form>

            @if ($batchCompare)
                <div class="mt-4 border-t pt-4">
                    <div class="flex flex-col lg:flex-row gap-4">
                        <div class="lg:w-72 shrink-0 space-y-3">
                            <div class="rounded-xl border border-amber-200 bg-gradient-to-br from-amber-50 to-white p-4">
                                <div class="text-[11px] uppercase tracking-wide text-gray-500">🥇 Medio ganador</div>
                                <div class="mt-1 text-lg font-semibold text-amber-900">{{ $batchCompare['winner']['page'] }}</div>
                                <div class="text-[11px] text-gray-500">
                                    {{ number_format($batchCompare['winner']['alcance'], 0, ',', '.') }} de alcance
                                    ({{ $batchCompare['total_reach'] > 0 ? round($batchCompare['winner']['alcance'] / $batchCompare['total_reach'] * 100, 1) : 0 }}% del total de la publicación)
                                </div>
                            </div>
                            <div class="text-xs text-gray-600">
                                <p class="font-medium text-gray-800 mb-1">Publicación analizada:</p>
                                <p class="italic">"{{ $batchCompare['message'] }}"</p>
                                <p class="mt-1 text-gray-400">{{ optional($batchCompare['date'])->format('d/m/Y H:i') }} · {{ $batchCompare['rows']->count() }} medios</p>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="h-64"><canvas id="batchChart"></canvas></div>
                        </div>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
                                    <th class="py-2 px-3">#</th>
                                    <th class="py-2 px-3">Medio</th>
                                    <th class="py-2 px-3">Red</th>
                                    <th class="py-2 px-3 text-right">Alcance</th>
                                    <th class="py-2 px-3 text-right">Impresiones</th>
                                    <th class="py-2 px-3 text-right">Interacciones</th>
                                    <th class="py-2 px-3 text-right">Tasa interacción</th>
                                    <th class="py-2 px-3">Ver</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($batchCompare['rows'] as $i => $row)
                                    <tr class="border-b last:border-0 {{ $i === 0 ? 'bg-amber-50/50' : '' }}">
                                        <td class="py-2 px-3 text-gray-400">{{ $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i + 1)) }}</td>
                                        <td class="py-2 px-3 font-medium">{{ $row['page'] }}</td>
                                        <td class="py-2 px-3">
                                            <span class="text-[10px] {{ $row['network'] === 'instagram' ? 'text-pink-700 bg-pink-50 border-pink-200' : 'text-blue-700 bg-blue-50 border-blue-200' }} border rounded px-1.5 py-0.5">
                                                {{ $row['network'] === 'instagram' ? '📸 IG' : '📘 FB' }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-right font-semibold">{{ number_format($row['alcance'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-3 text-right">{{ number_format($row['visualizaciones'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-3 text-right">{{ number_format($row['interacciones'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-3 text-right">{{ $row['engagement'] !== null ? number_format($row['engagement'], 2, ',', '.') . '%' : '—' }}</td>
                                        <td class="py-2 px-3">
                                            @if ($row['permalink'])
                                                <a href="{{ $row['permalink'] }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline text-xs">Abrir ↗</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($filters['batch'])
                <p class="mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    No se encontró esa publicación o no tienes acceso a sus medios.
                </p>
            @endif
        </div>

        @if ($totalPosts === 0)
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-6 text-sm">
                No hay publicaciones exitosas con métricas en este filtro. Publica contenido o ejecuta
                <code class="bg-white/70 px-1 rounded">php artisan stats:collect</code> para actualizar métricas.
            </div>
        @else

            {{-- Conclusiones automáticas --}}
            <div>
                <p class="font-semibold text-sm mb-2">📌 Conclusiones del período</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($insights as $ins)
                        <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4">
                            <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $ins['icon'] }} {{ $ins['title'] }}</div>
                            <div class="mt-1 text-lg font-semibold text-indigo-900">{{ $ins['value'] }}</div>
                            <div class="mt-0.5 text-[11px] text-gray-500">{{ $ins['detail'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Rendimiento por campaña --}}
            @if ($byCampaign->count() > 1)
                <div class="rounded-xl border border-gray-200 bg-white p-4 overflow-x-auto">
                    <p class="font-semibold text-sm mb-3">🎯 Rendimiento por campaña</p>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
                                <th class="py-2 px-3">#</th>
                                <th class="py-2 px-3">Campaña</th>
                                <th class="py-2 px-3 text-right">Publicaciones</th>
                                <th class="py-2 px-3 text-right">Alcance total</th>
                                <th class="py-2 px-3 text-right">Alcance promedio</th>
                                <th class="py-2 px-3 text-right">Interacc. promedio</th>
                                <th class="py-2 px-3 text-right">Tasa interacción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byCampaign as $i => $row)
                                <tr class="border-b last:border-0 {{ $i === 0 ? 'bg-indigo-50/50' : '' }}">
                                    <td class="py-2 px-3 text-gray-400">{{ $i === 0 ? '🥇' : $i + 1 }}</td>
                                    <td class="py-2 px-3 font-medium">{{ $row['name'] }}</td>
                                    <td class="py-2 px-3 text-right">{{ $fmt($row['n']) }}</td>
                                    <td class="py-2 px-3 text-right font-semibold">{{ $fmt($row['reach']) }}</td>
                                    <td class="py-2 px-3 text-right">{{ $fmt($row['avg_reach']) }}</td>
                                    <td class="py-2 px-3 text-right">{{ $fmt($row['avg_inter']) }}</td>
                                    <td class="py-2 px-3 text-right">{{ $row['engagement'] !== null ? number_format($row['engagement'], 2, ',', '.') . '%' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Ranking de medios --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="font-semibold text-sm mb-3">🏆 Ranking de medios
                    <span class="text-[11px] font-normal text-gray-400 ml-2">ordenado por alcance total del período</span>
                </p>
                @if ($byPage->count() > 1)
                    <div class="h-64 mb-4"><canvas id="pagesChart"></canvas></div>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
                                <th class="py-2 px-3">#</th>
                                <th class="py-2 px-3">Medio</th>
                                <th class="py-2 px-3 text-right">Publicaciones</th>
                                <th class="py-2 px-3 text-right">Alcance total</th>
                                <th class="py-2 px-3 text-right">Alcance promedio</th>
                                <th class="py-2 px-3 text-right">Interacc. promedio</th>
                                <th class="py-2 px-3 text-right">Tasa interacción</th>
                                <th class="py-2 px-3">Mejor publicación</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byPage->take(15) as $i => $row)
                                <tr class="border-b last:border-0 {{ $i === 0 ? 'bg-amber-50/50' : '' }}">
                                    <td class="py-2 px-3 text-gray-400">{{ $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i + 1)) }}</td>
                                    <td class="py-2 px-3 font-medium max-w-[180px] truncate">{{ $row['name'] }}</td>
                                    <td class="py-2 px-3 text-right">{{ $fmt($row['n']) }}</td>
                                    <td class="py-2 px-3 text-right font-semibold">{{ $fmt($row['reach']) }}</td>
                                    <td class="py-2 px-3 text-right">{{ $fmt($row['avg_reach']) }}</td>
                                    <td class="py-2 px-3 text-right">{{ $fmt($row['avg_inter']) }}</td>
                                    <td class="py-2 px-3 text-right">{{ $row['engagement'] !== null ? number_format($row['engagement'], 2, ',', '.') . '%' : '—' }}</td>
                                    <td class="py-2 px-3 text-xs text-gray-500 max-w-[220px] truncate" title="{{ $row['best_post'] }}">
                                        {{ $row['best_post'] }} <span class="text-gray-400">({{ $fmt($row['best_reach']) }})</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Hora y día --}}
            <div class="grid lg:grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-semibold text-sm mb-3">⏰ Alcance promedio según la hora de publicación</p>
                    <div class="h-56"><canvas id="hourChart"></canvas></div>
                    <p class="mt-2 text-[11px] text-gray-400">Barras grises: menos de {{ $minN }} publicaciones (muestra insuficiente).</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-semibold text-sm mb-3">📅 Alcance promedio según el día de publicación</p>
                    <div class="h-56"><canvas id="dowChart"></canvas></div>
                </div>
            </div>

            {{-- Mapa de calor --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 overflow-x-auto">
                <p class="font-semibold text-sm mb-3">🔥 Mapa de calor: alcance promedio por día y franja horaria</p>
                <table class="w-full text-[11px] border-separate" style="border-spacing: 3px;">
                    <thead>
                        <tr>
                            <th class="text-left text-gray-500 font-normal"></th>
                            @foreach ($heatBlocks as $b)
                                <th class="text-gray-500 font-normal py-1">{{ $b }}h</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dowLabels as $d => $label)
                            <tr>
                                <td class="text-gray-600 pr-2 whitespace-nowrap">{{ $label }}</td>
                                @foreach ($heatBlocks as $bi => $b)
                                    @php
                                        $cell = $heatmap[$d][$bi];
                                        $alpha = $cell['avg'] > 0 ? max(0.08, min(0.95, $cell['avg'] / $heatMax)) : 0;
                                    @endphp
                                    <td class="text-center rounded-md py-2 {{ $alpha > 0.55 ? 'text-white' : 'text-gray-700' }}"
                                        style="background: {{ $alpha > 0 ? "rgba(37,99,235,{$alpha})" : '#f3f4f6' }}"
                                        title="{{ $label }} {{ $b }}h — alcance prom: {{ $fmt($cell['avg']) }} ({{ $cell['n'] }} publicaciones)">
                                        {{ $cell['n'] > 0 ? $fmt($cell['avg']) : '·' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-[11px] text-gray-400">Más azul = mayor alcance promedio. El punto (·) indica que no hay publicaciones en esa franja.</p>
            </div>

            {{-- Formato y red --}}
            <div class="grid lg:grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-semibold text-sm mb-3">🎨 Rendimiento por formato de contenido</p>
                    <div class="h-52"><canvas id="typeChart"></canvas></div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-semibold text-sm mb-3">🌐 Rendimiento por red</p>
                    @if ($byNetwork->count() > 1)
                        <div class="h-52"><canvas id="netChart"></canvas></div>
                    @else
                        <div class="py-8 text-center text-sm text-gray-500">
                            Solo hay datos de {{ $byNetwork->first()['label'] ?? 'una red' }} en este filtro.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Top y peores --}}
            <div class="grid lg:grid-cols-2 gap-4">
                @foreach ([['🚀 Publicaciones con mayor alcance', $topPosts], ['🐢 Publicaciones con menor alcance', $worstPosts]] as [$title, $list])
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <p class="font-semibold text-sm mb-3">{{ $title }}</p>
                        <div class="space-y-2">
                            @foreach ($list as $post)
                                <div class="flex items-center gap-3 text-sm border-b last:border-0 pb-2">
                                    <span class="text-[10px] {{ $post->network === 'instagram' ? 'text-pink-700 bg-pink-50 border-pink-200' : 'text-blue-700 bg-blue-50 border-blue-200' }} border rounded px-1.5 py-0.5 shrink-0">
                                        {{ $post->network === 'instagram' ? '📸' : '📘' }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        @if ($post->fb_permalink_url)
                                            <a href="{{ $post->fb_permalink_url }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline block truncate">
                                                {{ \Illuminate\Support\Str::limit($post->message ?: '(sin texto)', 60) }}
                                            </a>
                                        @else
                                            <span class="block truncate">{{ \Illuminate\Support\Str::limit($post->message ?: '(sin texto)', 60) }}</span>
                                        @endif
                                        <span class="text-[11px] text-gray-400">{{ $post->published_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="font-semibold">{{ $fmt($post->alcance) }}</div>
                                        <div class="text-[10px] text-gray-400">alcance</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <script src="{{ asset('js/chart.umd.min.js') }}"></script>
    <script>
        (function () {
            // Buscador del selector múltiple de medios
            const mediosSearch = document.getElementById('mediosSearch');
            mediosSearch && mediosSearch.addEventListener('input', () => {
                const q = mediosSearch.value.trim().toLowerCase();
                document.querySelectorAll('.medioOption').forEach(el => {
                    el.style.display = (!q || el.dataset.name.includes(q)) ? '' : 'none';
                });
            });

            const batchRows = @json($batchCompare['rows'] ?? []);
            const byPage = @json($byPage->take(10)->values());
            const byHour = @json($byHour);
            const byDow = @json($byDow);
            const byType = @json($byType->values());
            const byNet = @json($byNetwork->values());
            const MIN_N = {{ $minN }};

            const fmt = new Intl.NumberFormat('es-CO');
            const grid = { color: 'rgba(0,0,0,0.05)' };
            const money = { callback: v => fmt.format(v), font: { size: 10 } };

            const mk = (id, cfg) => document.getElementById(id) && new Chart(document.getElementById(id), cfg);

            if (batchRows.length) mk('batchChart', {
                type: 'bar',
                data: {
                    labels: batchRows.map(r => r.page.length > 24 ? r.page.slice(0, 24) + '…' : r.page),
                    datasets: [
                        { label: 'Alcance', data: batchRows.map(r => r.alcance), backgroundColor: batchRows.map((r, i) => i === 0 ? '#f59e0b' : '#2563eb'), borderRadius: 3 },
                        { label: 'Interacciones', data: batchRows.map(r => r.interacciones), backgroundColor: '#059669', borderRadius: 3 },
                    ],
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: { x: { grid, ticks: money }, y: { grid: { display: false }, ticks: { font: { size: 10 } } } },
                },
            });

            if (byPage.length > 1) mk('pagesChart', {
                type: 'bar',
                data: {
                    labels: byPage.map(r => r.name.length > 22 ? r.name.slice(0, 22) + '…' : r.name),
                    datasets: [
                        { label: 'Alcance total', data: byPage.map(r => r.reach), backgroundColor: '#2563eb', borderRadius: 3 },
                        { label: 'Alcance promedio', data: byPage.map(r => r.avg_reach), backgroundColor: '#93c5fd', borderRadius: 3 },
                    ],
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: { x: { grid, ticks: money }, y: { grid: { display: false }, ticks: { font: { size: 10 } } } },
                },
            });

            mk('hourChart', {
                type: 'bar',
                data: {
                    labels: byHour.map(r => String(r.hour).padStart(2, '0') + ':00'),
                    datasets: [{
                        label: 'Alcance promedio',
                        data: byHour.map(r => r.avg_reach),
                        backgroundColor: byHour.map(r => r.n >= MIN_N ? '#2563eb' : '#d1d5db'),
                        borderRadius: 2,
                    }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { afterLabel: ctx => `${byHour[ctx.dataIndex].n} publicaciones` } },
                    },
                    scales: { x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 90 } }, y: { grid, ticks: money } },
                },
            });

            mk('dowChart', {
                type: 'bar',
                data: {
                    labels: byDow.map(r => r.label),
                    datasets: [{
                        label: 'Alcance promedio',
                        data: byDow.map(r => r.avg_reach),
                        backgroundColor: byDow.map(r => r.n >= MIN_N ? '#7c3aed' : '#d1d5db'),
                        borderRadius: 3,
                    }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { afterLabel: ctx => `${byDow[ctx.dataIndex].n} publicaciones` } },
                    },
                    scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { grid, ticks: money } },
                },
            });

            if (byType.length) mk('typeChart', {
                type: 'bar',
                data: {
                    labels: byType.map(r => r.label),
                    datasets: [
                        { label: 'Alcance promedio', data: byType.map(r => r.avg_reach), backgroundColor: '#2563eb', borderRadius: 3 },
                        { label: 'Interacciones promedio', data: byType.map(r => r.avg_inter), backgroundColor: '#059669', borderRadius: 3 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: { x: { grid: { display: false } }, y: { grid, ticks: money } },
                },
            });

            if (byNet.length > 1) mk('netChart', {
                type: 'bar',
                data: {
                    labels: byNet.map(r => r.label),
                    datasets: [
                        { label: 'Alcance promedio', data: byNet.map(r => r.avg_reach), backgroundColor: ['#2563eb', '#ec4899'], borderRadius: 3 },
                        { label: 'Interacciones promedio', data: byNet.map(r => r.avg_inter), backgroundColor: ['#93c5fd', '#f9a8d4'], borderRadius: 3 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: { x: { grid: { display: false } }, y: { grid, ticks: money } },
                },
            });
        })();
    </script>
@endsection
