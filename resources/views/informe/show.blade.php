@extends('layouts.app')

@section('content')
    <div class="max-w-6xl mx-auto px-4 py-8">

        {{-- Header --}}
        <div class="flex items-center justify-between mb-6 mt-10">
            <h1 class="text-2xl text-white md:text-3xl font-bold">Detalle de publicación</h1>

            @php
                $pdfUrl = route('informe.pdf', $key);
            @endphp

            <div class="flex items-center gap-3">
                <a href="{{ $pdfUrl }}" target="_blank"
                    class="text-sm px-3 py-2 rounded-lg border bg-white/90 hover:bg-white">
                    Descargar PDF
                </a>
                <a href="{{ route('informe.index') }}" class="text-sm underline text-white">← Volver</a>
            </div>
        </div>

        {{-- Panel de gráfica (solo barra) --}}
        @php
            $eff = $summary['effective_at']
                ? \Carbon\Carbon::parse($summary['effective_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')
                : '—';

            // Construimos un "resumen por página" directamente desde $posts
            $pageSummary = $posts
                ->groupBy(fn($p) => $p->page->name ?? '—')
                ->map(function ($group) {
                    return (object) [
                        'page_name' => $group->first()->page->name ?? '—',
                        'alcance' => (int) $group->sum(fn($p) => (int) ($p->alcance ?? 0)),
                        'visualizaciones' => (int) $group->sum(fn($p) => (int) ($p->visualizaciones ?? 0)),
                        'interacciones' => (int) $group->sum(fn($p) => (int) ($p->interacciones ?? 0)),
                        'any_permalink' => optional($group->firstWhere('fb_permalink_url'))?->fb_permalink_url,
                    ];
                })
                ->sortBy('page_name')
                ->values();
        @endphp

        <div class="rounded-2xl border bg-white p-6 shadow-sm mb-6">
            <div class="text-sm text-gray-500 mb-1">{{ $eff }}</div>
            <h2 class="text-lg font-semibold mb-4">{{ $summary['message_sample'] ?: '— Sin texto —' }}</h2>

            <div class="flex flex-wrap items-center gap-3 mb-4">
                <label class="text-sm text-gray-600" for="metricSelect">Métrica</label>
                <select id="metricSelect" class="border rounded-lg px-3 py-2">
                    <option value="alcance">Alcance</option>
                    <option value="visualizaciones">Visualizaciones</option>
                    <option value="interacciones">Interacciones</option>
                </select>
            </div>

            <div class="relative">
                <canvas id="summaryChart" height="120"></canvas>
            </div>

            <div class="mt-4 text-sm" id="extremesBox" style="display:none">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <span class="text-gray-500">Mayor</span>:
                        <strong id="maxName">—</strong>
                        (<span id="maxValue">0</span>)
                    </div>
                    <div>
                        <span class="text-gray-500">Menor</span>:
                        <strong id="minName">—</strong>
                        (<span id="minValue">0</span>)
                    </div>
                </div>
            </div>
            <div class="mt-4 text-sm text-gray-500" id="noDataBox" style="display:none">
                No hay datos para graficar.
            </div>
        </div>

        {{-- Desglose por página --}}
        <div class="rounded-2xl border bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold mb-4">Por página</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Página</th>
                            <th class="py-2 pr-4 text-right">Alcance</th>
                            <th class="py-2 pr-4 text-right">Visualizaciones</th>
                            <th class="py-2 pr-4 text-right">Interacciones</th>
                            <th class="py-2 pr-4 text-center">Enlace</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pageSummary as $p)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-4 font-medium">{{ $p->page_name }}</td>
                                <td class="py-2 pr-4 text-right">{{ number_format($p->alcance) }}</td>
                                <td class="py-2 pr-4 text-right">{{ number_format($p->visualizaciones) }}</td>
                                <td class="py-2 pr-4 text-right">{{ number_format($p->interacciones) }}</td>
                                <td class="py-2 pr-4 text-center">
                                    @php
                                        // Si es video y el enlace no contiene 'facebook.com', lo corregimos
                                        $link = $p->any_permalink;
                                        if (
                                            $p->type === 'video' &&
                                            $p->fb_post_id &&
                                            !str_contains($link, 'facebook.com')
                                        ) {
                                            $link = 'https://www.facebook.com/reel/' . $p->fb_post_id;
                                        }
                                    @endphp

                                    @if ($link)
                                        <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                                            class="underline text-indigo-600">Abrir</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-3 pr-4 text-gray-500">No hay datos.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4 text-right">Totales</td>
                            <td class="py-2 pr-4 text-right">{{ number_format((int) ($summary['alcance_sum'] ?? 0)) }}</td>
                            <td class="py-2 pr-4 text-right">
                                {{ number_format((int) ($summary['visualizaciones_sum'] ?? 0)) }}</td>
                            <td class="py-2 pr-4 text-right">
                                {{ number_format((int) ($summary['interacciones_sum'] ?? 0)) }}</td>
                            <td class="py-2 pr-4 text-center">—</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>
@endsection

@section('scripts')
    {{-- Chart.js (CDN) --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function() {
            // Datos precalculados desde el controlador
            const byPage =
            @json($chartByPage); // { labels: [...], datasets: { Alcance:[...], Visualizaciones:[...], Interacciones:[...] } }

            const labels = byPage.labels || [];
            const datasets = {
                alcance: byPage.datasets?.['Alcance'] || [],
                visualizaciones: byPage.datasets?.['Visualizaciones'] || [],
                interacciones: byPage.datasets?.['Interacciones'] || [],
            };

            const fmt = (n) => (n ?? 0).toLocaleString();

            function computeMinMax(data) {
                const noDataBox = document.getElementById('noDataBox');
                const extremesBox = document.getElementById('extremesBox');

                const hasData = Array.isArray(data) && data.length && data.some(v => (v ?? 0) !== 0);

                if (!hasData) {
                    extremesBox.style.display = 'none';
                    noDataBox.style.display = 'block';
                    return;
                }
                noDataBox.style.display = 'none';
                extremesBox.style.display = 'block';

                let maxV = -Infinity,
                    minV = Infinity,
                    maxI = 0,
                    minI = 0;
                data.forEach((v, i) => {
                    if (v > maxV) {
                        maxV = v;
                        maxI = i;
                    }
                    if (v < minV) {
                        minV = v;
                        minI = i;
                    }
                });
                document.getElementById('maxName').textContent = labels[maxI] ?? '—';
                document.getElementById('maxValue').textContent = fmt(maxV);
                document.getElementById('minName').textContent = labels[minI] ?? '—';
                document.getElementById('minValue').textContent = fmt(minV);
            }

            document.addEventListener('DOMContentLoaded', function() {
                const select = document.getElementById('metricSelect');
                const canvas = document.getElementById('summaryChart');

                // Evitar instancias duplicadas (HMR)
                const existing = Chart.getChart(canvas);
                if (existing) existing.destroy();

                const initialMetric = select.value || 'alcance';
                const ctx = canvas.getContext('2d');

                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: initialMetric.charAt(0).toUpperCase() + initialMetric.slice(
                                1),
                            data: datasets[initialMetric],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.label}: ${fmt(ctx.raw)}`
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: v => fmt(v)
                                }
                            }
                        }
                    }
                });

                computeMinMax(datasets[initialMetric]);

                select.addEventListener('change', function() {
                    const metric = this.value;
                    chart.data.datasets[0].label = metric.charAt(0).toUpperCase() + metric.slice(1);
                    chart.data.datasets[0].data = datasets[metric];
                    chart.update();
                    computeMinMax(datasets[metric]);
                }, {
                    passive: true
                });
            });
        })();
    </script>
@endsection
