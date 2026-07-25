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
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Medio (página)</label>
                <select name="page_id" onchange="this.form.submit()" class="rounded-lg border-gray-200 text-sm min-w-[200px]">
                    <option value="">Todos los medios</option>
                    @foreach ($pagesCatalog as $p)
                        <option value="{{ $p->id }}" {{ (string) $filters['page_id'] === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>

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
