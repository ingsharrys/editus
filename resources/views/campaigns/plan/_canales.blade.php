{{--
    Avance por canal de la ficha técnica: radio (lo firman las emisoras), medios digitales (orgánico)
    y redes sociales con pauta paga (meta de alcance y presupuesto). Variables: $c, $canales, $s (subcampaña, opcional),
    $emisoras (opcional).
--}}
@php
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $peso = fn($n) => '$ ' . number_format((float) $n, 0, ',', '.');
    $s = $s ?? null;
    $emisoras = $emisoras ?? collect();
    $iconos = ['radio' => '📻', 'digital' => '🌐', 'pauta' => '💰'];
    $ayuda = [
        'radio' => __('No se publica desde editus: cada emisora inscrita reporta y firma sus cuñas desde su certificado.'),
        'digital' => __('Se publica desde editus en las páginas de los medios. Alcance orgánico.'),
        'pauta' => __('Publicaciones con pauta paga: meta de alcance por pieza y presupuesto asignado y gastado. TikTok se registra a mano.'),
    ];
    $puedePublicar = auth()->user()->tienePermiso('paginas.publicar');
@endphp
<div class="grid lg:grid-cols-2 gap-4 mb-6">
    @foreach ($canales as $g => $x)
        @php $pct = $x['planeadas'] ? min(100, (int) round($x['hechas'] * 100 / max(1, $x['planeadas']))) : null; @endphp
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden flex flex-col {{ $g === 'pauta' || count($canales) === 1 ? 'lg:col-span-2' : '' }}">
            <div class="px-4 py-3 border-b border-gray-100">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-sm font-bold text-gray-900">{{ $iconos[$g] ?? '' }} {{ $x['nombre'] }}</h3>
                    <span class="text-lg font-bold tabular-nums {{ $pct !== null && $pct >= 100 ? 'text-emerald-700' : 'text-gray-900' }}">{{ $fmt($x['hechas']) }}@if ($x['planeadas'])<span class="text-sm font-semibold text-gray-400"> / {{ $fmt($x['planeadas']) }}</span>@endif</span>
                </div>
                @if ($pct !== null)<div class="h-1.5 mt-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full {{ $pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' }}" style="width: {{ $pct }}%"></div></div>@endif
                <p class="text-[11px] text-gray-500 mt-1">{{ $ayuda[$g] ?? '' }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('Tipo') }}</th>
                            <th class="px-3 py-2 text-right">{{ $g === 'radio' ? __('Emitidas') : __('Hechas') }}</th>
                            <th class="px-3 py-2 text-right whitespace-nowrap">{{ __('Valor contratado') }}</th>
                            @if ($g === 'pauta')
                                <th class="px-3 py-2 text-right whitespace-nowrap">{{ __('Meta por pieza') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Alcance') }}</th>
                                <th class="px-3 py-2 text-right whitespace-nowrap">{{ __('Cumplen meta') }}</th>
                                <th class="px-3 py-2 text-right whitespace-nowrap">{{ __('Pauta asignada') }}</th>
                                <th class="px-3 py-2 text-right whitespace-nowrap">{{ __('Pauta gastada') }}</th>
                            @elseif ($g === 'digital')
                                <th class="px-3 py-2 text-right">{{ __('Alcance') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($x['tipos'] as $f)
                            <tr class="border-t border-gray-100">
                                <td class="px-3 py-2"><div class="font-semibold text-gray-800">{{ $f['tipo']->nombre }}</div>@if ($f['tipo']->caracteristica)<div class="text-[10px] text-gray-400 max-w-xs truncate" title="{{ $f['tipo']->caracteristica }}">{{ $f['tipo']->caracteristica }}</div>@endif</td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold {{ $f['planeadas'] && $f['hechas'] >= $f['planeadas'] ? 'text-emerald-700' : '' }}">{{ $fmt($f['hechas']) }}@if ($f['planeadas'])<span class="text-gray-400 font-normal"> / {{ $fmt($f['planeadas']) }}</span>@endif</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-600">{{ $f['valor_contratado'] ? $peso($f['valor_contratado']) : '—' }}</td>
                                @if ($g === 'pauta')
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-600">{{ $f['meta'] ? $fmt($f['meta']) : '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($f['alcance']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $f['con_meta'] ? $f['cumplen'] . ' / ' . $f['con_meta'] : '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $peso($f['pauta_asignada']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums {{ $f['pauta_gastada'] > $f['pauta_asignada'] && $f['pauta_asignada'] > 0 ? 'text-rose-700 font-semibold' : '' }}">{{ $peso($f['pauta_gastada']) }}</td>
                                @elseif ($g === 'digital')
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($f['alcance']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 font-bold text-gray-900">
                        <tr>
                            <td class="px-3 py-2">{{ __('Total') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($x['hechas']) }}@if ($x['planeadas'])<span class="text-gray-400 font-normal"> / {{ $fmt($x['planeadas']) }}</span>@endif</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $x['valor_contratado'] ? $peso($x['valor_contratado']) : '—' }}</td>
                            @if ($g === 'pauta')
                                <td></td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($x['alcance']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $x['con_meta'] ? $x['cumplen'] . ' / ' . $x['con_meta'] : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $peso($x['pauta_asignada']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $peso($x['pauta_gastada']) }}</td>
                            @elseif ($g === 'digital')
                                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($x['alcance']) }}</td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($g === 'radio' && $emisoras->isNotEmpty())
                <div class="px-4 py-3 border-t border-gray-100">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Por emisora') }}</div>
                    <ul class="space-y-1 text-xs">
                        @foreach ($emisoras as $e)
                            <li class="flex flex-wrap justify-between gap-2"><span>@if ($e['participante'])<a href="{{ route('certificados.ver', $e['participante']) }}#radio" class="font-semibold text-indigo-700 hover:underline">{{ $e['emisora'] }}</a>@else{{ $e['emisora'] }}@endif <span class="text-gray-400">· {{ $e['tipo'] }}</span></span>
                                <span class="tabular-nums"><b>{{ $fmt($e['cantidad']) }}</b> <span class="text-gray-400">({{ $e['desde']?->format('d/m') }}{{ $e['hasta'] && $e['hasta']->ne($e['desde']) ? '–' . $e['hasta']->format('d/m') : '' }})</span></span></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($s)
                <div class="mt-auto px-4 py-3 border-t border-gray-100 bg-gray-50/60 flex flex-wrap gap-2">
                    @if ($g === 'radio')
                        @if ($c->inscripcion_activa && $c->enlaceInscripcion())
                            <button type="button" onclick="navigator.clipboard.writeText(@js($c->enlaceInscripcion())); this.textContent=@js(__('Enlace copiado ✓'))" class="h-8 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ __('Copiar enlace de inscripción para las emisoras') }}</button>
                        @else
                            <a href="{{ route('campaigns.edit', $c) }}" class="text-xs text-indigo-700 hover:underline">{{ __('Activa el enlace de inscripción para que las emisoras reporten') }}</a>
                        @endif
                    @elseif ($g === 'digital')
                        @if ($puedePublicar && $s->is_active && $c->is_active)<a href="{{ route('campaigns.subcampanas.publicar', [$c, $s]) }}" class="h-8 inline-flex items-center rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">{{ __('Publicar (orgánico)') }}</a>@endif
                        <a href="{{ route('campaigns.banners.crear', [$c, 'subcampana' => $s->id]) }}" class="h-8 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ __('Registrar banner') }}</a>
                    @else
                        @if ($puedePublicar && $s->is_active && $c->is_active)<a href="{{ route('campaigns.subcampanas.publicar', [$c, $s]) }}" class="h-8 inline-flex items-center rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">{{ __('Publicar en Facebook / Instagram') }}</a>@endif
                        @foreach (collect($x['tipos'])->filter(fn($f) => $f['tipo']->canal === 'tiktok') as $f)
                            <a href="{{ route('campaigns.productos.crear', [$c, 'subcampana' => $s->id, 'tipo' => $f['tipo']->id]) }}" class="h-8 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ __('Registrar :tipo', ['tipo' => $f['tipo']->nombre]) }}</a>
                        @endforeach
                        <span class="text-[11px] text-gray-500 self-center">{{ __('Abre cada pieza para escribir la pauta asignada y lo gastado al final.') }}</span>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
