@extends('layouts.app')

@section('content')
@php
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $sel = $m['seleccion'];
    $maxAlc = max(1, (int) collect($m['por_subcampana'])->max('alcance'));
    $nombreSel = $subId === null ? __('Toda la campaña') : ($subId === 0 ? __('Sin subcampaña') : optional($m['subcampanas']->firstWhere('id', $subId))->nombre);
    $chip = fn($activo) => 'shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold border ' . ($activo ? 'border-[#00024f] bg-[#00024f] text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50');
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    {{-- Filtro: toda la campaña o una subcampaña --}}
    <div class="flex gap-2 overflow-x-auto pb-1 mb-4">
        <a href="{{ route('campaigns.plan', $c) }}" class="{{ $chip($subId === null) }}">{{ __('Toda la campaña') }}</a>
        @foreach ($m['subcampanas'] as $s)
            <a href="{{ route('campaigns.plan', [$c, 'sub' => $s->id]) }}" class="{{ $chip($subId === $s->id) }}">{{ $s->nombre }}</a>
        @endforeach
        @if (collect($m['por_subcampana'])->contains(fn($r) => $r['sub'] === null))
            <a href="{{ route('campaigns.plan', [$c, 'sub' => 0]) }}" class="{{ $chip($subId === 0) }}">{{ __('Sin subcampaña') }}</a>
        @endif
    </div>

    <h2 class="text-sm font-bold text-gray-900 mb-2">{{ $nombreSel }}</h2>
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        @foreach ([[__('Piezas'), $sel['piezas']], [__('Publicaciones'), $sel['publicaciones']], [__('Alcance'), $sel['alcance']], [__('Visualizaciones'), $sel['visualizaciones']], [__('Interacciones'), $sel['interacciones']]] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm min-w-0"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400 truncate">{{ $et }}</div><div class="text-xl font-bold text-gray-900 tabular-nums">{{ $fmt($v) }}</div></div>
        @endforeach
    </div>

    {{-- Por subcampaña (siempre de toda la campaña) --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-gray-900">{{ __('Por subcampaña') }}</h3>
            <a href="{{ route('campaigns.plan.subcampanas', $c) }}" class="text-xs font-semibold text-indigo-700 hover:underline">{{ __('Gestionar subcampañas') }}</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-3 py-2 text-left">{{ __('Subcampaña') }}</th><th class="px-3 py-2 text-right">{{ __('Piezas') }}</th><th class="px-3 py-2 text-right">{{ __('Alcance') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Visualizaciones') }}</th><th class="px-3 py-2 text-right">{{ __('Interacciones') }}</th><th class="px-3 py-2 text-left w-40">{{ __('% del alcance') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($m['por_subcampana'] as $r)
                        @php $pct = $m['total']['alcance'] ? round($r['alcance'] * 100 / $m['total']['alcance']) : 0; @endphp
                        <tr class="border-t border-gray-100">
                            <td class="px-3 py-2">
                                <a href="{{ route('campaigns.plan', [$c, 'sub' => $r['sub']?->id ?? 0]) }}" class="font-semibold text-gray-900 hover:text-indigo-700">{{ $r['sub']?->nombre ?? __('Sin subcampaña') }}</a>
                                @if ($r['sub'] && !$r['sub']->is_active)<span class="text-[10px] text-gray-400">· {{ __('inactiva') }}</span>@endif
                                @if ($r['sub']?->programa)<div class="text-[11px] text-gray-500">{{ $r['sub']->programa }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['piezas']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['alcance']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['visualizaciones']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['interacciones']) }}</td>
                            <td class="px-3 py-2"><div class="flex items-center gap-2"><div class="h-1.5 flex-1 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-indigo-500" style="width: {{ (int) round($r['alcance'] * 100 / $maxAlc) }}%"></div></div><span class="text-[11px] text-gray-500 tabular-nums w-8 text-right">{{ $pct }}%</span></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('Aún no hay subcampañas ni publicaciones.') }} <a href="{{ route('campaigns.plan.subcampanas', $c) }}" class="text-indigo-700 underline">{{ __('Crear subcampañas') }}</a></td></tr>
                    @endforelse
                </tbody>
                @if (count($m['por_subcampana']) > 1)
                    <tfoot class="bg-gray-50 font-semibold"><tr class="border-t border-gray-200">
                        <td class="px-3 py-2">{{ __('Toda la campaña') }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['total']['piezas']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['total']['alcance']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['total']['visualizaciones']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['total']['interacciones']) }}</td><td></td>
                    </tr></tfoot>
                @endif
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        {{-- Por tipo de pieza --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <h3 class="px-4 py-3 border-b border-gray-100 text-sm font-bold text-gray-900">{{ __('Por tipo de pieza') }}</h3>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500"><tr><th class="px-3 py-2 text-left">{{ __('Tipo') }}</th><th class="px-3 py-2 text-right">{{ __('Piezas') }}</th><th class="px-3 py-2 text-right">{{ __('Alcance') }}</th><th class="px-3 py-2 text-right">{{ __('Interac.') }}</th></tr></thead>
                <tbody>
                    @forelse ($m['por_tipo'] as $tipo => $r)
                        <tr class="border-t border-gray-100"><td class="px-3 py-2">{{ $tipo }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['piezas']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['alcance']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['interacciones']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('Sin publicaciones.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{-- Por página --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <h3 class="px-4 py-3 border-b border-gray-100 text-sm font-bold text-gray-900">{{ __('Por página') }}</h3>
            <div class="max-h-96 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500 sticky top-0"><tr><th class="px-3 py-2 text-left">{{ __('Página') }}</th><th class="px-3 py-2 text-right">{{ __('Piezas') }}</th><th class="px-3 py-2 text-right">{{ __('Alcance') }}</th><th class="px-3 py-2 text-right">{{ __('Interac.') }}</th></tr></thead>
                    <tbody>
                        @forelse ($m['por_pagina'] as $r)
                            <tr class="border-t border-gray-100"><td class="px-3 py-2 truncate max-w-[14rem]">{{ $r['pagina'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['piezas']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['alcance']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmt($r['interacciones']) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('Sin publicaciones.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Avance de los cupos (o piezas por página y tipo en una subcampaña) --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-gray-900">{{ $subId === null ? __('Avance de cupos por página') : __('Piezas por página y tipo') }}</h3>
            <a href="{{ route('campaigns.plan.cupos', $c) }}" class="text-xs font-semibold text-indigo-700 hover:underline">{{ $hayCupos ? __('Editar cupos') : __('Definir cupos') }}</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-3 py-2 text-left sticky left-0 bg-gray-50">{{ __('Página') }}</th>
                    @foreach ($tipos as $t)<th class="px-3 py-2 text-left whitespace-nowrap">{{ $t }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @forelse ($avance as $fila)
                        <tr class="border-t border-gray-100">
                            <td class="px-3 py-2 sticky left-0 bg-white font-medium text-gray-900 truncate max-w-[14rem]">{{ $fila['pagina']->name }}</td>
                            @foreach ($fila['tipos'] as $t => [$u, $cupo])
                                @php $pct = $cupo ? min(100, (int) round($u * 100 / max(1, $cupo))) : 0; $lleno = $cupo !== null && $u >= $cupo; @endphp
                                <td class="px-3 py-2 min-w-[7rem]">
                                    @if ($cupo !== null)
                                        <div class="text-xs tabular-nums {{ $lleno ? 'text-rose-700 font-semibold' : 'text-gray-700' }}">{{ $u }} / {{ $cupo }}</div>
                                        <div class="h-1.5 mt-1 rounded-full bg-gray-100"><div class="h-1.5 rounded-full {{ $lleno ? 'bg-rose-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $pct }}%"></div></div>
                                    @else
                                        <span class="text-xs tabular-nums {{ $u ? 'text-gray-700' : 'text-gray-300' }}">{{ $u }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($tipos) + 1 }}" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('La campaña no tiene páginas.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="px-4 py-2 text-[11px] text-gray-400 border-t border-gray-100">{{ __('Cuentan las piezas publicadas o en cola con su tipo de pieza. Una pieza en Facebook e Instagram a la vez cuenta una sola vez.') }}</p>
    </div>
</div>
@endsection
