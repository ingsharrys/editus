@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto mt-20 space-y-5">

        {{-- Encabezado --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl">Informe de publicaciones</h2>
                <p class="text-xs text-gray-500">Explora, filtra y exporta todas las publicaciones de tu plataforma.</p>
            </div>
            <a href="{{ route('reports.posts.csv', request()->query()) }}"
               class="inline-flex items-center gap-2 text-xs px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                ⬇️ Exportar CSV (Excel)
            </a>
        </div>

        {{-- KPIs del conjunto filtrado --}}
        @php $fmt = fn($n) => number_format((int) $n, 0, ',', '.'); @endphp
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3">
            @foreach ([
                ['label' => 'Publicaciones', 'value' => $kpis->total, 'class' => ''],
                ['label' => 'Exitosas', 'value' => $kpis->exitosas, 'class' => 'text-emerald-600'],
                ['label' => 'Fallidas', 'value' => $kpis->fallidas, 'class' => 'text-red-600'],
                ['label' => 'Pendientes', 'value' => $kpis->pendientes, 'class' => 'text-amber-600'],
                ['label' => 'Alcance', 'value' => $kpis->alcance, 'class' => ''],
                ['label' => 'Impresiones', 'value' => $kpis->visualizaciones, 'class' => ''],
                ['label' => 'Interacciones', 'value' => $kpis->interacciones, 'class' => ''],
            ] as $kpi)
                <div class="rounded-xl border border-gray-200 bg-white p-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-500">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-xl font-semibold {{ $kpi['class'] }}">{{ $fmt($kpi['value']) }}</div>
                </div>
            @endforeach
        </div>

        {{-- Filtros --}}
        <form method="GET" action="{{ route('reports.posts') }}" id="filtersForm"
              class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="lg:col-span-2">
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Buscar en el mensaje o ID</label>
                    <div class="relative">
                        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Escribe y presiona Enter…"
                               class="w-full rounded-lg border-gray-200 text-sm pl-8">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400">🔎</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Página</label>
                    <select name="page_id" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                        <option value="">Todas</option>
                        @foreach ($pages as $p)
                            <option value="{{ $p->id }}" {{ (string) $filters['page_id'] === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Red</label>
                    <select name="network" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                        <option value="">Todas</option>
                        <option value="facebook" {{ $filters['network'] === 'facebook' ? 'selected' : '' }}>📘 Facebook</option>
                        <option value="instagram" {{ $filters['network'] === 'instagram' ? 'selected' : '' }}>📸 Instagram</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Tipo</label>
                    <select name="type" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                        <option value="">Todos</option>
                        <option value="text" {{ $filters['type'] === 'text' ? 'selected' : '' }}>📝 Texto</option>
                        <option value="photo" {{ $filters['type'] === 'photo' ? 'selected' : '' }}>🖼️ Foto</option>
                        <option value="video" {{ $filters['type'] === 'video' ? 'selected' : '' }}>🎬 Video</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Estado</label>
                    <select name="status" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                        <option value="">Todos</option>
                        <option value="success" {{ $filters['status'] === 'success' ? 'selected' : '' }}>✅ Exitosa</option>
                        <option value="fail" {{ $filters['status'] === 'fail' ? 'selected' : '' }}>❌ Fallida</option>
                        <option value="pending" {{ $filters['status'] === 'pending' ? 'selected' : '' }}>⏳ Pendiente</option>
                    </select>
                </div>
                @if ($authors->isNotEmpty())
                    <div>
                        <label class="block text-[11px] font-medium text-gray-600 mb-1">Autor</label>
                        <select name="user_id" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                            <option value="">Todos</option>
                            @foreach ($authors as $a)
                                <option value="{{ $a->id }}" {{ (string) $filters['user_id'] === (string) $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Desde</label>
                    <input type="date" name="from" value="{{ optional($filters['from'])->toDateString() }}"
                           onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Hasta</label>
                    <input type="date" name="to" value="{{ optional($filters['to'])->toDateString() }}"
                           onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 text-sm">
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between">
                <a href="{{ route('reports.posts') }}" class="text-[11px] text-gray-500 hover:text-gray-800 underline">
                    Limpiar filtros
                </a>
                <div class="flex items-center gap-2 text-[11px] text-gray-500">
                    <span>Distribución:</span>
                    <span class="text-blue-700 bg-blue-50 border border-blue-200 rounded px-1.5 py-0.5">📘 {{ $fmt($byNetwork['facebook'] ?? 0) }}</span>
                    <span class="text-pink-700 bg-pink-50 border border-pink-200 rounded px-1.5 py-0.5">📸 {{ $fmt($byNetwork['instagram'] ?? 0) }}</span>
                    <span class="bg-gray-50 border border-gray-200 rounded px-1.5 py-0.5">📝 {{ $fmt($byType['text'] ?? 0) }}</span>
                    <span class="bg-gray-50 border border-gray-200 rounded px-1.5 py-0.5">🖼️ {{ $fmt($byType['photo'] ?? 0) }}</span>
                    <span class="bg-gray-50 border border-gray-200 rounded px-1.5 py-0.5">🎬 {{ $fmt($byType['video'] ?? 0) }}</span>
                </div>
            </div>
        </form>

        {{-- Actividad por día --}}
        @if ($activity->count() > 1)
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="font-semibold text-sm mb-3">Actividad del período filtrado
                    <span class="text-[11px] font-normal text-gray-400 ml-2">Publicaciones por día y alcance</span>
                </p>
                <div class="h-56"><canvas id="activityChart"></canvas></div>
            </div>
        @endif

        {{-- Tabla --}}
        @php
            $sortLink = function (string $col, string $label) use ($filters) {
                $dir = $filters['sort'] === $col && $filters['dir'] === 'desc' ? 'asc' : 'desc';
                $icon = $filters['sort'] === $col ? ($filters['dir'] === 'desc' ? ' ↓' : ' ↑') : '';
                $url = route('reports.posts', array_merge(request()->query(), ['sort' => $col, 'dir' => $dir]));
                return '<a href="' . $url . '" class="hover:text-gray-900">' . $label . $icon . '</a>';
            };
        @endphp
        <div class="rounded-xl border border-gray-200 bg-white overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
                        <th class="py-2.5 px-3">Publicación</th>
                        <th class="py-2.5 px-3">Página</th>
                        <th class="py-2.5 px-3">Red</th>
                        <th class="py-2.5 px-3">Tipo</th>
                        <th class="py-2.5 px-3">Autor</th>
                        <th class="py-2.5 px-3 whitespace-nowrap">{!! $sortLink('published_at', 'Fecha') !!}</th>
                        <th class="py-2.5 px-3 text-right whitespace-nowrap">{!! $sortLink('alcance', 'Alcance') !!}</th>
                        <th class="py-2.5 px-3 text-right whitespace-nowrap">{!! $sortLink('visualizaciones', 'Impresiones') !!}</th>
                        <th class="py-2.5 px-3 text-right whitespace-nowrap">{!! $sortLink('interacciones', 'Interacc.') !!}</th>
                        <th class="py-2.5 px-3">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        <tr class="border-b last:border-0 hover:bg-gray-50/60">
                            <td class="py-2 px-3 max-w-[260px]">
                                @if ($post->fb_permalink_url)
                                    <a href="{{ $post->fb_permalink_url }}" target="_blank" rel="noopener"
                                       class="text-blue-600 hover:underline">
                                        {{ \Illuminate\Support\Str::limit($post->message ?: '(sin texto)', 70) }}
                                    </a>
                                @else
                                    <span class="text-gray-700">{{ \Illuminate\Support\Str::limit($post->message ?: '(sin texto)', 70) }}</span>
                                @endif
                                @if ($post->status === 'fail' && $post->error)
                                    <div class="text-[10px] text-red-500 truncate max-w-[260px]" title="{{ $post->error }}">
                                        {{ \Illuminate\Support\Str::limit($post->error, 80) }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2 px-3 whitespace-nowrap max-w-[160px] truncate">{{ $post->page->name ?? '—' }}</td>
                            <td class="py-2 px-3">
                                @if ($post->network === 'instagram')
                                    <span class="text-[10px] text-pink-700 bg-pink-50 border border-pink-200 rounded px-1.5 py-0.5 whitespace-nowrap">📸 IG</span>
                                @else
                                    <span class="text-[10px] text-blue-700 bg-blue-50 border border-blue-200 rounded px-1.5 py-0.5 whitespace-nowrap">📘 FB</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-xs">
                                {{ ['text' => '📝 Texto', 'photo' => '🖼️ Foto', 'video' => '🎬 Video'][$post->type] ?? $post->type }}
                            </td>
                            <td class="py-2 px-3 text-xs whitespace-nowrap max-w-[120px] truncate">{{ $post->user->name ?? '—' }}</td>
                            <td class="py-2 px-3 text-xs whitespace-nowrap">
                                {{ optional($post->published_at ?? $post->created_at)->format('d/m/Y H:i') }}
                            </td>
                            <td class="py-2 px-3 text-right">{{ $fmt($post->alcance) }}</td>
                            <td class="py-2 px-3 text-right">{{ $fmt($post->visualizaciones) }}</td>
                            <td class="py-2 px-3 text-right">{{ $fmt($post->interacciones) }}</td>
                            <td class="py-2 px-3">
                                @if ($post->status === 'success')
                                    <span class="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1.5 py-0.5">✅ Exitosa</span>
                                @elseif ($post->status === 'fail')
                                    <span class="text-[10px] text-red-700 bg-red-50 border border-red-200 rounded px-1.5 py-0.5">❌ Fallida</span>
                                @else
                                    <span class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5">⏳ {{ $post->status }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-10 text-center text-sm text-gray-500">
                                No hay publicaciones que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Paginación + tamaño de página --}}
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="text-xs text-gray-500">
                Mostrando {{ $posts->firstItem() ?? 0 }}–{{ $posts->lastItem() ?? 0 }} de {{ $fmt($posts->total()) }} publicaciones
                ·
                <span>Por página:</span>
                @foreach ([15, 30, 50, 100] as $n)
                    <a href="{{ route('reports.posts', array_merge(request()->query(), ['per_page' => $n, 'page' => null])) }}"
                       class="{{ $filters['per_page'] === $n ? 'font-bold text-blue-600' : 'underline' }}">{{ $n }}</a>
                @endforeach
            </div>
            <div>{{ $posts->links() }}</div>
        </div>
    </div>

    <script src="{{ asset('js/chart.umd.min.js') }}"></script>
    <script>
        (function () {
            const activity = @json($activity);
            if (!activity.length || !document.getElementById('activityChart')) return;

            const fmt = new Intl.NumberFormat('es-CO');
            new Chart(document.getElementById('activityChart'), {
                data: {
                    labels: activity.map(d => d.day),
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Publicaciones',
                            data: activity.map(d => +d.posts),
                            backgroundColor: 'rgba(37,99,235,.65)',
                            yAxisID: 'y',
                            borderRadius: 3,
                        },
                        {
                            type: 'line',
                            label: 'Alcance',
                            data: activity.map(d => +d.alcance),
                            borderColor: '#059669',
                            tension: .3,
                            pointRadius: 0,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 12, font: { size: 10 } } },
                        y: { position: 'left', grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { precision: 0, font: { size: 10 } } },
                        y1: { position: 'right', grid: { display: false }, ticks: { callback: v => fmt.format(v), font: { size: 10 } } },
                    },
                },
            });
        })();
    </script>
@endsection
