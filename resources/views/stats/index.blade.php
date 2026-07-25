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
              class="rounded-xl border border-gray-200 bg-white p-4 flex flex-wrap items-end gap-4">
            <input type="hidden" name="network" value="{{ $network }}">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Página</label>
                <select name="page_id" onchange="this.form.submit()"
                        class="rounded-lg border-gray-200 text-sm min-w-[220px]">
                    <option value="">Todas las páginas</option>
                    @foreach ($pages as $p)
                        <option value="{{ $p->id }}" {{ $selectedPageId === $p->id ? 'selected' : '' }}>
                            {{ $p->name }}{{ $p->instagram_business_account_id ? ' 📸' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Red</label>
                <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                    @foreach (['all' => '🌐 Todas', 'facebook' => '📘 Facebook', 'instagram' => '📸 Instagram'] as $net => $label)
                        <a href="{{ route('stats.index', array_merge(request()->except('network'), ['network' => $net])) }}"
                           class="px-3 py-2 text-xs {{ $network === $net ? ($net === 'instagram' ? 'bg-pink-600 text-white' : 'bg-blue-600 text-white') : 'bg-white text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
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
            $showSplit = $network === 'all';
            $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
            $kpis = [
                ['label' => 'Alcance', 'value' => $totals['reach'], 'var' => $kpiVariation['reach'], 'fb' => $perNetwork['facebook']['reach'], 'ig' => $perNetwork['instagram']['reach']],
                ['label' => 'Impresiones', 'value' => $totals['impressions'], 'var' => $kpiVariation['impressions'], 'fb' => $perNetwork['facebook']['impressions'], 'ig' => null],
                ['label' => 'Interacciones', 'value' => $totals['engagements'], 'var' => $kpiVariation['engagements'], 'fb' => $perNetwork['facebook']['engagements'], 'ig' => null],
                ['label' => 'Vistas de video', 'value' => $totals['video_views'], 'var' => null, 'fb' => $perNetwork['facebook']['video_views'], 'ig' => null],
                ['label' => 'Seguidores', 'value' => $fansTotal, 'var' => null, 'fb' => $fansByNetwork['facebook'], 'ig' => $fansByNetwork['instagram']],
                ['label' => 'Publicaciones', 'value' => $postsCount, 'var' => null, 'fb' => $postsByNetwork['facebook'], 'ig' => $postsByNetwork['instagram']],
            ];
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach ($kpis as $kpi)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $fmt($kpi['value']) }}</div>
                    @if (!is_null($kpi['var']))
                        <div class="mt-1 text-[11px] {{ $kpi['var'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $kpi['var'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($kpi['var']), 1, ',', '.') }}%
                            <span class="text-gray-400">vs período anterior</span>
                        </div>
                    @endif
                    @if ($showSplit)
                        <div class="mt-1 text-[11px] text-gray-500 space-x-1">
                            <span class="text-blue-600">📘 {{ $fmt($kpi['fb']) }}</span>
                            @if (!is_null($kpi['ig']))
                                <span class="text-pink-600">📸 {{ $fmt($kpi['ig']) }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Evolución diaria --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-sm">Evolución diaria
                    @if ($network !== 'all')
                        <span class="text-[10px] font-normal {{ $network === 'instagram' ? 'text-pink-600 bg-pink-50' : 'text-blue-600 bg-blue-50' }} rounded px-1.5 py-0.5 ml-1 capitalize">{{ $network }}</span>
                    @endif
                </p>
                <p class="text-[11px] text-gray-400">
                    {{ $network === 'all' ? 'Comparativo Facebook vs Instagram' : 'Alcance · Interacciones' }}
                </p>
            </div>
            @if (empty($chart['labels']))
                <p class="text-sm text-gray-500 py-8 text-center">
                    Aún no hay datos recolectados para este filtro.
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
                <p class="font-semibold text-sm mb-3">Reacciones por tipo <span class="text-[10px] font-normal text-blue-600 bg-blue-50 rounded px-1.5 py-0.5 ml-1">Facebook</span></p>
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
                    <p class="font-semibold text-sm mb-3">{{ $title }} <span class="text-[10px] font-normal text-blue-600 bg-blue-50 rounded px-1.5 py-0.5 ml-1">Facebook</span></p>
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

        {{-- Demografía (Instagram Business) --}}
        <div class="grid lg:grid-cols-2 gap-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="font-semibold text-sm mb-1">Seguidores por sexo <span class="text-[10px] font-normal text-pink-600 bg-pink-50 rounded px-1.5 py-0.5 ml-1">Instagram</span></p>
                @if ($audience['ig_gender']->isEmpty())
                    <p class="text-sm text-gray-500 py-8 text-center">
                        Sin datos aún. Requiere páginas con Instagram Business conectado
                        y el permiso <code class="bg-gray-100 px-1 rounded">instagram_manage_insights</code>.
                    </p>
                @else
                    <div class="h-56"><canvas id="genderChart"></canvas></div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="font-semibold text-sm mb-1">Seguidores por edad <span class="text-[10px] font-normal text-pink-600 bg-pink-50 rounded px-1.5 py-0.5 ml-1">Instagram</span></p>
                @if ($audience['ig_age']->isEmpty())
                    <p class="text-sm text-gray-500 py-8 text-center">Sin datos de edad aún.</p>
                @else
                    <div class="h-56"><canvas id="ageChart"></canvas></div>
                @endif
            </div>
        </div>

        <p class="text-[11px] text-gray-400">
            ℹ️ Meta eliminó de su API los desgloses por sexo y edad para páginas de Facebook (sept. 2023),
            por lo que esos datos provienen de las cuentas de <strong>Instagram Business</strong> conectadas
            a tus páginas (requieren mínimo 100 seguidores). La geografía por país/ciudad proviene de los
            seguidores de la página de Facebook.
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
                            <th class="py-2 pr-3">Red</th>
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
                                <td class="py-2 pr-3">
                                    @if ($post->network === 'instagram')
                                        <span class="text-[10px] text-pink-700 bg-pink-50 border border-pink-200 rounded px-1.5 py-0.5 whitespace-nowrap">📸 Instagram</span>
                                    @else
                                        <span class="text-[10px] text-blue-700 bg-blue-50 border border-blue-200 rounded px-1.5 py-0.5 whitespace-nowrap">📘 Facebook</span>
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
            const chartData = @json($chart);
            const reactions = @json($reactions);
            const byType = @json($byType);
            const igGender = @json($audience['ig_gender']);
            const igAge = @json($audience['ig_age']);

            const fmt = new Intl.NumberFormat('es-CO');
            const gridColor = 'rgba(0,0,0,0.05)';

            if (chartData.labels.length && document.getElementById('dailyChart')) {
                new Chart(document.getElementById('dailyChart'), {
                    type: 'line',
                    data: chartData,
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
            const genderLabels = { F: 'Mujeres', M: 'Hombres', U: 'No especificado' };
            const genderEntries = Object.entries(igGender || {});
            if (genderEntries.length && document.getElementById('genderChart')) {
                new Chart(document.getElementById('genderChart'), {
                    type: 'doughnut',
                    data: {
                        labels: genderEntries.map(([k]) => genderLabels[k] || k),
                        datasets: [{
                            data: genderEntries.map(([, v]) => +v),
                            backgroundColor: ['#ec4899', '#2563eb', '#9ca3af'],
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => {
                                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                        const pct = total ? (ctx.parsed / total * 100).toFixed(1) : 0;
                                        return `${ctx.label}: ${fmt.format(ctx.parsed)} (${pct}%)`;
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const ageEntries = Object.entries(igAge || {});
            if (ageEntries.length && document.getElementById('ageChart')) {
                new Chart(document.getElementById('ageChart'), {
                    type: 'bar',
                    data: {
                        labels: ageEntries.map(([k]) => k),
                        datasets: [{
                            label: 'Seguidores',
                            data: ageEntries.map(([, v]) => +v),
                            backgroundColor: '#8b5cf6',
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
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
