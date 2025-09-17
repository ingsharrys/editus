@extends('layouts.app')

@section('content')
    <div class="max-w-6xl mx-auto px-4 py-8">

       
       
        <div class="flex items-center justify-between mb-6 mt-10">
            <h1 class="text-2xl text-white md:text-3xl font-bold">Detalle de publicación</h1>
            <div class="flex items-center gap-3">
                <a href="{{ route('informe.pdf', $key) }}" target="_blank"
                    class="text-sm px-3 py-2 rounded-lg border bg-white/90 hover:bg-white">
                    Descargar PDF
                </a>
                <a href="{{ route('informe.index') }}" class="text-sm underline text-white">← Volver</a>
            </div>
        </div>

        {{-- Panel de gráfica (solo barra) --}}
        <div class="rounded-2xl border bg-white p-6 shadow-sm mb-6">
            <div class="text-sm text-gray-500 mb-1">
                {{ optional($summary['effective_at'])->timezone(config('app.timezone'))?->format('d/m/Y H:i') ?? '—' }}
            </div>
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

        {{-- Desglose por página + modal de evidencia (solo Alpine aquí) --}}
        <div class="rounded-2xl border bg-white p-6 shadow-sm" x-data="{ show: false, img: null, open(src) { this.img = src;
                this.show = true }, close() { this.show = false;
                this.img = null } }" @keydown.escape.window="close()">
            <h3 class="text-lg font-semibold mb-4">Por página</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Página</th>
                            <th class="py-2 pr-4">Alcance</th>
                            <th class="py-2 pr-4">Visualizaciones</th>
                            <th class="py-2 pr-4">Interacciones</th>
                            <th class="py-2 pr-4">Evidencia</th>
                            <th class="py-2 pr-4">Enlace</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byPage as $p)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-4 font-medium">{{ $p->page?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ number_format((int) ($p->alcance ?? 0)) }}</td>
                                <td class="py-2 pr-4">{{ number_format((int) ($p->visualizaciones ?? 0)) }}</td>
                                <td class="py-2 pr-4">{{ number_format((int) ($p->interacciones ?? 0)) }}</td>

                                <td class="py-2 pr-4">
                                    @if ($p->evidencia_path)
                                        @php $src = Storage::url($p->evidencia_path); @endphp
                                        <button type="button" @click="open(@js($src))"
                                            class="group inline-flex items-center gap-2">
                                            <img src="{{ $src }}" alt="Evidencia"
                                                class="h-10 w-10 rounded object-cover border">
                                            <span
                                                class="text-indigo-600 underline opacity-0 group-hover:opacity-100 text-xs">Ver</span>
                                        </button>
                                    @else
                                        <div class="h-10 w-10 rounded bg-gray-100 flex items-center justify-center text-gray-400"
                                            title="Sin evidencia">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24"
                                                fill="currentColor">
                                                <path
                                                    d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm0 2v9.586l-3.293-3.293a1 1 0 0 0-1.414 0L8 18H5V5h14ZM9 9a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z" />
                                            </svg>
                                        </div>
                                    @endif
                                </td>

                                <td class="py-2 pr-4">
                                    @if ($p->fb_permalink_url)
                                        <a href="{{ $p->fb_permalink_url }}" target="_blank" class="underline">Abrir</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4 text-right">Totales</td>
                            <td class="py-2 pr-4">{{ number_format($summary['alcance_sum']) }}</td>
                            <td class="py-2 pr-4">{{ number_format($summary['visualizaciones_sum']) }}</td>
                            <td class="py-2 pr-4">{{ number_format($summary['interacciones_sum']) }}</td>
                            <td class="py-2 pr-4">—</td>
                            <td class="py-2 pr-4">—</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- MODAL --}}
            <div x-show="show" x-transition.opacity class="fixed inset-0 z-50">
                <div class="absolute inset-0 bg-black/60" @click="close()"></div>
                <div class="absolute inset-0 flex items-center justify-center p-4">
                    <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b">
                            <h4 class="font-medium text-sm">Evidencia</h4>
                            <div class="flex items-center gap-2">
                                <template x-if="img">
                                    <a :href="img" target="_blank"
                                        class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50">Abrir en pestaña</a>
                                </template>
                                <button @click="close()" class="p-1 rounded hover:bg-gray-100" aria-label="Cerrar">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 8.586 4.293 2.879A1 1 0 1 0 2.879 4.293L8.586 10l-5.707 5.707a1 1 0 0 0 1.414 1.414L10 11.414l5.707 5.707a1 1 0 0 0 1.414-1.414L11.414 10l5.707-5.707A1 1 0 0 0 15.707 2.879L10 8.586Z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="p-4">
                            <img :src="img" alt="Evidencia"
                                class="w-full h-auto max-h-[70vh] object-contain rounded-lg border">
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@section('scripts')
    {{-- Chart.js (CDN) --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function() {
            const labels = @json($byPage->map(fn($p) => $p->page?->name ?? '—')->values());
            const datasets = {
                alcance: @json($byPage->map(fn($p) => (int) ($p->alcance ?? 0))->values()),
                visualizaciones: @json($byPage->map(fn($p) => (int) ($p->visualizaciones ?? 0))->values()),
                interacciones: @json($byPage->map(fn($p) => (int) ($p->interacciones ?? 0))->values()),
            };

            const fmt = (n) => (n ?? 0).toLocaleString();

            function computeMinMax(data) {
                const noDataBox = document.getElementById('noDataBox');
                const extremesBox = document.getElementById('extremesBox');

                if (!data || !data.length || data.every(v => (v ?? 0) === 0)) {
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

                // Si Vite HMR reinyecta, destruye instancia previa
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
