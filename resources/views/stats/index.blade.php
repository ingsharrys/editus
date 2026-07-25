@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto mt-20 space-y-6">

        {{-- Encabezado --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl">Estadísticas</h2>
                <p class="text-xs text-gray-500">
                    Alcance, interacciones y audiencia de tus páginas.
                    @if ($lastCollectedAt)
                        Última actualización: {{ \Carbon\Carbon::parse($lastCollectedAt)->diffForHumans() }}
                    @endif
                </p>
            </div>

            @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('stats.collect') }}">
                    @csrf
                    <button class="text-xs px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                        ⟳ Actualizar datos ahora
                    </button>
                </form>
            @endif
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3">
                {{ session('success') }}
            </div>
        @endif

        {{-- Filtros --}}
        <form method="GET" action="{{ route('stats.index') }}"
              class="rounded-xl border border-gray-200 bg-white p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Página</label>
                <select name="page_id" onchange="this.form.submit()"
                        class="rounded-lg border-gray-200 text-sm min-w-[220px]">
                    <option value="">Todas las páginas</option>
                    @foreach ($pages as $p)
                        <option value="{{ $p->id }}" {{ $selectedPageId === $p->id ? 'selected' : '' }}>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Período</label>
                <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                    @foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días'] as $d => $label)
                        <button type="submit" name="days" value="{{ $d }}"
                            class="px-3 py-2 text-xs {{ $days === $d ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </form>

        {{-- KPIs --}}
        @php
            $kpis = [
                ['label' => 'Alcance', 'value' => $totals['reach'], 'var' => $kpiVariation['reach']],
                ['label' => 'Impresiones', 'value' => $totals['impressions'], 'var' => $kpiVariation['impressions']],
                ['label' => 'Interacciones', 'value' => $totals['engagements'], 'var' => $kpiVariation['engagements']],
                ['label' => 'Vistas de video', 'value' => $totals['video_views'], 'var' => null],
                ['label' => 'Seguidores', 'value' => $fansTotal, 'var' => null],
                ['label' => 'Publicaciones', 'value' => $postsCount, 'var' => null],
            ];
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach ($kpis as $kpi)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ number_format($kpi['value'], 0, ',', '.') }}</div>
                    @if (!is_null($kpi['var']))
                        <div class="mt-1 text-[11px] {{ $kpi['var'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $kpi['var'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($kpi['var']), 1, ',', '.') }}%
                            <span class="text-gray-400">vs período anterior</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Evolución diaria --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-sm">Evolución diaria</p>
                <p class="text-[11px] text-gray-400">Alcance · Impresiones · Interacciones</p>
            </div>
            @if ($daily->isEmpty())
                <p class="text-sm text-gray-500 py-8 text-center">
                    Aún no hay datos recolectados.
                    @if (auth()->user()->isAdmin())
                        Usa «Actualizar datos ahora» o ejecuta <code class="bg-gray-100 px-1 rounded">php artisan stats:collect --days=30</code>.
                    @endif
                </p>
            @else
                <div class="h-72"><canvas id="dailyChart"></canvas></div>
            @endif
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            {{-- Reacciones por tipo --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="font-semibold text-sm mb-3">Reacciones por tipo</p>
                @if ($reactions->isEmpty())
                    <p class="text-sm text-gray-500 py-8 text-center">Sin datos de reacciones en el período.</p>
                @else
                    <div class="h-64"><canvas id="reactionsChart"></canvas></div>
                @endif
            </div>

            {{-- Rendimiento por tipo de publicación --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="font-semibold text-sm mb-3">Rendimiento por tipo de publicación</p>
                @if ($byType->isEmpty())
                    <p class="text-sm text-gray-500 py-8 text-center">Sin publicaciones en el período.</p>
                @else
                    <div class="h-64"><canvas id="typeChart"></canvas></div>
                @endif
            </div>
        </div>

        {{-- Audiencia geográfica --}}
        <div class="grid lg:grid-cols-2 gap-4">
            @foreach (['country' => 'Seguidores por país', 'city' => 'Seguidores por ciudad'] as $dim => $title)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-semibold text-sm mb-3">{{ $title }}</p>
                    @if ($audience[$dim]->isEmpty())
                        <p class="text-sm text-gray-500 py-6 text-center">Sin datos de audiencia aún.</p>
                    @else
                        @php $max = max(1, $audience[$dim]->max()); @endphp
                        <div class="space-y-2">
                            @foreach ($audience[$dim] as $key => $value)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="w-36 truncate text-gray-700" title="{{ $key }}">{{ $key }}</span>
                                    <div class="flex-1 bg-gray-100 rounded h-3 overflow-hidden">
                                        <div class="h-3 bg-blue-500" style="width: {{ round($value / $max * 100) }}%"></div>
                                    </div>
                                    <span class="w-16 text-right text-gray-600">{{ number_format($value, 0, ',', '.') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-[11px] text-gray-400">
            ℹ️ Meta eliminó de su API los desgloses por sexo y edad para páginas de Facebook (sept. 2023),
            por lo que esa información solo está disponible dentro de Meta Business Suite.
            La demografía por sexo/edad sí está disponible por API para cuentas de Instagram Business.
        </p>

        {{-- Ranking de páginas --}}
        @if ($pageRanking->isNotEmpty())
            <div class="rounded-xl border border-gray-200 bg-white p-4 overflow-x-auto">
                <p class="font-semibold text-sm mb-3">Ranking de páginas (por alcance)</p>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b">
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Página</th>
                            <th class="py-2 pr-3 text-right">Alcance</th>
                            <th class="py-2 pr-3 text-right">Impresiones</th>
                            <th class="py-2 text-right">Interacciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pageRanking as $i => $row)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-3 text-gray-400">{{ $i + 1 }}</td>
                                <td class="py-2 pr-3">{{ $row->name }}</td>
                                <td class="py-2 pr-3 text-right">{{ number_format($row->reach, 0, ',', '.') }}</td>
                                <td class="py-2 pr-3 text-right">{{ number_format($row->impressions, 0, ',', '.') }}</td>
                                <td class="py-2 text-right">{{ number_format($row->engagements, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Top publicaciones --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 overflow-x-auto">
            <p class="font-semibold text-sm mb-3">Top 10 publicaciones (por alcance)</p>
            @if ($topPosts->isEmpty())
                <p class="text-sm text-gray-500 py-6 text-center">Sin publicaciones en el período.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b">
                            <th class="py-2 pr-3">Publicación</th>
                            <th class="py-2 pr-3">Tipo</th>
                            <th class="py-2 pr-3">Fecha</th>
                            <th class="py-2 pr-3 text-right">Alcance</th>
                            <th class="py-2 pr-3 text-right">Impresiones</th>
                            <th class="py-2 text-right">Interacciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topPosts as $post)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-3 max-w-[280px]">
                                    @if ($post->fb_permalink_url)
                                        <a href="{{ $post->fb_permalink_url }}" target="_blank" rel="noopener"
                                           class="text-blue-600 hover:underline">
                                            {{ \Illuminate\Support\Str::limit($post->message ?: '(sin texto)', 60) }}
                                        </a>
                                    @else
                                        {{ \Illuminate\Support\Str::limit($post->message ?: '(sin texto)', 60) }}
                                    @endif
                                </td>
                                <td class="py-2 pr-3 capitalize">{{ $post->type }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ optional($post->published_at)->format('d/m/Y') }}</td>
                                <td class="py-2 pr-3 text-right">{{ number_format((int) $post->alcance, 0, ',', '.') }}</td>
                                <td class="py-2 pr-3 text-right">{{ number_format((int) $post->visualizaciones, 0, ',', '.') }}</td>
                                <td class="py-2 text-right">{{ number_format((int) $post->interacciones, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <script src="{{ asset('js/chart.umd.min.js') }}"></script>
    <script>
        (function () {
            const daily = @json($daily);
            const reactions = @json($reactions);
            const byType = @json($byType);

            const fmt = new Intl.NumberFormat('es-CO');
            const gridColor = 'rgba(0,0,0,0.05)';

            if (daily.length && document.getElementById('dailyChart')) {
                new Chart(document.getElementById('dailyChart'), {
                    type: 'line',
                    data: {
                        labels: daily.map(d => d.date.slice(0, 10)),
                        datasets: [
                            { label: 'Alcance', data: daily.map(d => +d.reach), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.08)', fill: true, tension: .3, pointRadius: 0 },
                            { label: 'Impresiones', data: daily.map(d => +d.impressions), borderColor: '#7c3aed', tension: .3, pointRadius: 0 },
                            { label: 'Interacciones', data: daily.map(d => +d.engagements), borderColor: '#059669', tension: .3, pointRadius: 0 },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                        scales: {
                            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 10 } } },
                            y: { grid: { color: gridColor }, ticks: { callback: v => fmt.format(v), font: { size: 10 } } },
                        },
                    },
                });
            }

            const reactionLabels = {
                like: '👍 Me gusta', love: '❤️ Me encanta', wow: '😮 Me asombra',
                haha: '😆 Me divierte', sorry: '😢 Me entristece', anger: '😡 Me enoja',
            };
            const reactionEntries = Object.entries(reactions || {});
            if (reactionEntries.length && document.getElementById('reactionsChart')) {
                new Chart(document.getElementById('reactionsChart'), {
                    type: 'doughnut',
                    data: {
                        labels: reactionEntries.map(([k]) => reactionLabels[k] || k),
                        datasets: [{
                            data: reactionEntries.map(([, v]) => +v),
                            backgroundColor: ['#2563eb', '#ef4444', '#f59e0b', '#10b981', '#6366f1', '#f97316'],
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } },
                    },
                });
            }

            const typeLabels = { text: 'Texto', photo: 'Foto', video: 'Video' };
            const typeEntries = Object.entries(byType || {});
            if (typeEntries.length && document.getElementById('typeChart')) {
                new Chart(document.getElementById('typeChart'), {
                    type: 'bar',
                    data: {
                        labels: typeEntries.map(([k]) => typeLabels[k] || k),
                        datasets: [
                            { label: 'Alcance', data: typeEntries.map(([, v]) => +v.alcance), backgroundColor: '#2563eb' },
                            { label: 'Interacciones', data: typeEntries.map(([, v]) => +v.interacciones), backgroundColor: '#059669' },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                        scales: {
                            x: { grid: { display: false } },
                            y: { grid: { color: gridColor }, ticks: { callback: v => fmt.format(v), font: { size: 10 } } },
                        },
                    },
                });
            }
        })();
    </script>
@endsection
