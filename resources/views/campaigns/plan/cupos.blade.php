@extends('layouts.app')

@section('content')
@php
    $k = fn($t) => \App\Services\PlanMediosService::clave($t);
    $num = 'w-20 h-9 rounded-lg border-gray-200 text-sm text-right tabular-nums';
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <p class="text-sm text-gray-600 mb-4">{{ __('Límite de piezas por tipo en cada página durante la campaña. La primera fila es el cupo por defecto de cada página; en una página puedes poner un cupo distinto. Vacío = sin límite (o el de por defecto). Al llegar al cupo, editus no deja publicar más piezas de ese tipo en esa página.') }}</p>

    <form method="POST" action="{{ route('campaigns.plan.cupos.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left sticky left-0 bg-gray-50">{{ __('Página') }}</th>
                        @foreach ($tipos as $t)<th class="px-3 py-2 text-right whitespace-nowrap">{{ $t }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t border-gray-100 bg-indigo-50/50">
                        <td class="px-3 py-2 font-semibold text-gray-900 sticky left-0 bg-indigo-50">{{ __('Por defecto (cada página)') }}</td>
                        @foreach ($tipos as $i => $t)
                            <td class="px-3 py-2 text-right"><input type="number" min="0" name="cupos[defecto][{{ $i }}]" value="{{ $cupos[0][$k($t)] ?? '' }}" class="{{ $num }} font-semibold"></td>
                        @endforeach
                    </tr>
                    @forelse ($paginas as $pg)
                        <tr class="border-t border-gray-100">
                            <td class="px-3 py-2 sticky left-0 bg-white"><div class="font-medium text-gray-900 truncate max-w-[16rem]">{{ $pg->name }}</div></td>
                            @foreach ($tipos as $i => $t)
                                @php $u = $usados[(int) $pg->id][$k($t)] ?? 0; $def = $cupos[0][$k($t)] ?? null; @endphp
                                <td class="px-3 py-2 text-right">
                                    <input type="number" min="0" name="cupos[{{ $pg->id }}][{{ $i }}]" value="{{ $cupos[(int) $pg->id][$k($t)] ?? '' }}" placeholder="{{ $def ?? '∞' }}" class="{{ $num }}">
                                    <div class="text-[10px] text-gray-400 mt-0.5">{{ __(':n usadas', ['n' => $u]) }}</div>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($tipos) + 1 }}" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('La campaña no tiene páginas. Elige sus medios en Editar campaña o espera a que se inscriban los medios.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[11px] text-gray-400">{{ __('Los tipos de pieza (columnas) se cambian en Editar campaña → Plan de medios.') }}</p>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar cupos') }}</button>
        </div>
    </form>
</div>
@endsection
