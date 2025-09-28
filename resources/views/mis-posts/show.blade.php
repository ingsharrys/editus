@extends('layouts.app')

@section('content')
    <div class="max-w-6xl mx-auto px-4 py-8 mt-10" x-data="{ showToast: {{ session('ok') ? 'true' : 'false' }} }" x-init="if (showToast) setTimeout(() => showToast = false, 2500)">

        {{-- Toast éxito --}}
        <div class="fixed top-4 right-4 z-50" x-show="showToast" x-transition.opacity.duration.250ms>
            <div class="flex items-center gap-3 bg-green-600 text-white px-4 py-3 rounded-xl shadow-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z"
                        clip-rule="evenodd" />
                </svg>
                <span class="font-medium">{{ session('ok') }}</span>
            </div>
        </div>

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">
                Informe del grupo
                <span class="ml-2 text-gray-300 text-base align-middle px-2 py-1 rounded-xl bg-white/10">
                    {{ $key }}
                </span>
            </h1>

            <a href="{{ route('informe.index') }}"
                class="bg-white border border-gray-200 shadow-sm px-4 py-2 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                ← Volver
            </a>
        </div>

        {{-- Resumen --}}
        @php
            $eff = $summary['effective_at']
                ? \Carbon\Carbon::parse($summary['effective_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')
                : '—';
        @endphp
        <div class="grid sm:grid-cols-5 gap-4 mb-6">
            <div class="rounded-2xl bg-white p-4 border shadow-sm">
                <div class="text-xs text-gray-500">Publicaciones</div>
                <div class="text-xl font-bold">{{ number_format((int) ($summary['total_posts'] ?? 0)) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-4 border shadow-sm">
                <div class="text-xs text-gray-500">Alcance total</div>
                <div class="text-xl font-bold">{{ number_format((int) ($summary['alcance_sum'] ?? 0)) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-4 border shadow-sm">
                <div class="text-xs text-gray-500">Visualizaciones totales</div>
                <div class="text-xl font-bold">{{ number_format((int) ($summary['visualizaciones_sum'] ?? 0)) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-4 border shadow-sm">
                <div class="text-xs text-gray-500">Interacciones totales</div>
                <div class="text-xl font-bold">{{ number_format((int) ($summary['interacciones_sum'] ?? 0)) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-4 border shadow-sm">
                <div class="text-xs text-gray-500">Última publicación</div>
                <div class="text-xl font-bold">{{ $eff }}</div>
            </div>
        </div>

        {{-- Gráficas --}}
        <div class="grid lg:grid-cols-2 gap-6">
            <div class="rounded-2xl bg-white border p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-sm text-gray-600">Suma por página</div>
                </div>
                <div class="h-72">
                    <canvas id="chartByPage"></canvas>
                </div>
            </div>

            <div class="rounded-2xl bg-white border p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-sm text-gray-600">Suma por publicación</div>
                </div>
                <div class="h-72">
                    <canvas id="chartByPost"></canvas>
                </div>
            </div>
        </div>

        {{-- Detalle de publicaciones --}}
        <div class="mt-8 rounded-2xl bg-white border shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                <div class="text-sm text-gray-700">Detalle de publicaciones ({{ number_format($posts->count()) }})</div>
                @if (!empty($summary['any_permalink']))
                    <a href="{{ $summary['any_permalink'] }}" target="_blank" rel="noopener noreferrer"
                        class="text-indigo-600 text-sm hover:underline">Ver un ejemplo</a>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-left px-4 py-3">Fecha</th>
                            <th class="text-left px-4 py-3">Página</th>
                            <th class="text-left px-4 py-3">Tipo</th>
                            <th class="text-right px-4 py-3">Alcance</th>
                            <th class="text-right px-4 py-3">Vistas</th>
                            <th class="text-right px-4 py-3">Interacciones</th>
                            <th class="text-center px-4 py-3">Enlace</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($pageSummary as $p)
                            <tr>
                                <td class="px-4 py-2">{{ $p->page_name }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($p->posts_count) }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($p->alcance) }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($p->visualizaciones) }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($p->interacciones) }}</td>
                                <td class="px-4 py-2 text-center">
                                    @if ($p->any_permalink)
                                        <a href="{{ $p->any_permalink }}" target="_blank" rel="noopener noreferrer"
                                            class="text-indigo-600 hover:underline">Ver</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    {{-- Totales del grupo al pie --}}
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 text-right font-semibold text-gray-700">Totales del grupo
                            </td>
                            <td class="px-4 py-3 text-right font-bold">
                                {{ number_format((int) ($summary['alcance_sum'] ?? 0)) }}</td>
                            <td class="px-4 py-3 text-right font-bold">
                                {{ number_format((int) ($summary['visualizaciones_sum'] ?? 0)) }}</td>
                            <td class="px-4 py-3 text-right font-bold">
                                {{ number_format((int) ($summary['interacciones_sum'] ?? 0)) }}</td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>

    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Datos desde el controlador
        const byPage = @json($chartByPage);
        const byPost = @json($chartByPost);

        // Helper para crear datasets sin fijar colores manuales
        const makeDatasets = (datasetsObj) =>
            Object.entries(datasetsObj).map(([label, data]) => ({
                label,
                data,
                borderWidth: 1
            }));

        // Chart por página
        new Chart(document.getElementById('chartByPage').getContext('2d'), {
            type: 'bar',
            data: {
                labels: byPage.labels,
                datasets: makeDatasets(byPage.datasets),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Chart por publicación
        new Chart(document.getElementById('chartByPost').getContext('2d'), {
            type: 'bar',
            data: {
                labels: byPost.labels,
                datasets: makeDatasets(byPost.datasets),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
@endsection
