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
            // Fecha efectiva formateada (published_at o created_at, según lo que mandó el controller en $summary['effective_at'])
            $eff = $summary['effective_at']
                ? \Carbon\Carbon::parse($summary['effective_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')
                : '—';

            // Tu pageSummary tal cual:
            $pageSummary = $posts
                ->groupBy(fn($p) => $p->page->name ?? '—')
                ->map(function ($group) {
                    $sample = $group->filter(fn($p) => !empty($p->fb_permalink_url))->last() ?? $group->last();

                    return (object) [
                        'page_name' => $group->first()->page->name ?? '—',
                        'alcance' => (int) $group->sum(fn($p) => (int) ($p->alcance ?? 0)),
                        'visualizaciones' => (int) $group->sum(fn($p) => (int) ($p->visualizaciones ?? 0)),
                        'interacciones' => (int) $group->sum(fn($p) => (int) ($p->interacciones ?? 0)),
                        'sample_permalink' => $sample->fb_permalink_url ?? null,
                        'sample_link' => $sample->link ?? null,
                        'sample_type' => $sample->type ?? null,
                        'sample_fb_post_id' => $sample->fb_post_id ?? null,
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
                                <td class="py-2 pr-4 font-medium">
                                    {{ $p->page_name }}
                                </td>

                                <td class="py-2 pr-4 text-right">
                                    {{ number_format($p->alcance) }}
                                </td>

                                <td class="py-2 pr-4 text-right">
                                    {{ number_format($p->visualizaciones) }}
                                </td>

                                <td class="py-2 pr-4 text-right">
                                    {{ number_format($p->interacciones) }}
                                </td>

                                <td class="py-2 pr-4 text-center">
                                    @php
                                        $link = $p->sample_permalink ?: $p->sample_link;

                                        if ($p->sample_type === 'video' && $p->sample_fb_post_id) {
                                            $isFacebook =
                                                $link &&
                                                (str_contains($link, 'facebook.com') ||
                                                    str_contains($link, 'fb.watch'));
                                            if (!$link || !$isFacebook) {
                                                $link = 'https://www.facebook.com/reel/' . $p->sample_fb_post_id;
                                            }
                                        }
                                    @endphp

                                    @if ($link)
                                        <div class="flex flex-col items-start">
                                            {{-- Ver publicación --}}
                                            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                                                class="text-indigo-600 underline text-xs">
                                                Ver publicación
                                            </a>

                                            {{-- Párrafo con el link --}}
                                            <p class="mt-1 text-[11px] text-gray-500 leading-snug break-all">
                                                {{ $link }}
                                            </p>
                                        </div>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif

                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-3 pr-4 text-gray-500">
                                    No hay datos.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4 text-right">Totales</td>
                            <td class="py-2 pr-4 text-right">
                                {{ number_format((int) ($summary['alcance_sum'] ?? 0)) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ number_format((int) ($summary['visualizaciones_sum'] ?? 0)) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ number_format((int) ($summary['interacciones_sum'] ?? 0)) }}
                            </td>
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
            function copyText(text, onSuccess) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text)
                        .then(onSuccess)
                        .catch(function() {
                            fallbackCopy(text, onSuccess);
                        });
                } else {
                    fallbackCopy(text, onSuccess);
                }
            }

            function fallbackCopy(text, onSuccess) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.top = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                } catch (e) {
                    alert('No se pudo copiar el enlace');
                }
                document.body.removeChild(textarea);
            }

            function markAsCopied(btn) {
                // Si ya está copiado, no hacer nada
                if (btn.dataset.copied === '1') return;

                const icon = btn.querySelector('.copy-icon');
                const label = btn.querySelector('.copy-label');

                if (icon) {
                    icon.style.display = 'none'; // oculta el icono 📋
                }

                if (label) {
                    label.textContent = 'Copiado';
                }

                btn.classList.remove('text-gray-500', 'hover:text-gray-700');
                btn.classList.add('text-green-600', 'font-semibold', 'cursor-default');

                btn.dataset.copied = '1';
                btn.disabled = true; // opcional: ya no hace nada después
            }

            document.addEventListener('DOMContentLoaded', function() {
                const buttons = document.querySelectorAll('.copy-btn');

                buttons.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        // Si ya está copiado, ignorar
                        if (btn.dataset.copied === '1') return;

                        const link = btn.dataset.link;
                        if (!link) return;

                        copyText(link, function() {
                            markAsCopied(btn);
                        });
                    });
                });
            });
        })();
    </script>
@endsection
