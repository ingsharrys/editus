@extends('layouts.app')

@section('content')
@php
    $inp = 'w-full h-9 rounded-lg border-gray-200 text-xs';
    $selF = 'h-9 rounded-lg border-gray-200 text-sm';
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <p class="text-sm text-gray-600 mb-4">{{ __('Aquí mueves a su subcampaña las publicaciones que quedaron sin subcampaña o en la equivocada. Cada fila es una pieza (la misma publicación en varias páginas) y se mueve completa.') }}</p>

    <form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Mostrar') }}</label>
            <select name="sub" class="{{ $selF }}" onchange="this.form.submit()">
                <option value="0" @selected($sub === '0')>{{ __('Sin subcampaña') }} ({{ $sinSub }})</option>
                <option value="todas" @selected($sub === 'todas')>{{ __('Todas') }}</option>
                @foreach ($subs as $s)<option value="{{ $s->id }}" @selected((string) $sub === (string) $s->id)>{{ $s->nombre }}</option>@endforeach
            </select></div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Tipo') }}</label>
            <select name="tipo" class="{{ $selF }}" onchange="this.form.submit()">
                <option value="">{{ __('Todos') }}</option><option value="-1" @selected(request('tipo') === '-1')>{{ __('Sin tipo') }}</option>
                @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((string) request('tipo') === (string) $t->id)>{{ $t->nombre }}</option>@endforeach
            </select></div>
    </form>

    <form method="POST" action="{{ route('campaigns.piezas.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-3 py-2 text-left">{{ __('Pieza') }}</th>
                    <th class="px-3 py-2 text-left w-56">{{ __('Subcampaña') }}</th>
                    <th class="px-3 py-2 text-left w-48">{{ __('Tipo de publicación') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($piezas as $p)
                        @php $medios = $p->posts->where('status', 'success')->map(fn($x) => ($x->page?->name ?? '—') . ($x->network === 'instagram' ? ' (IG)' : ''))->unique()->values(); @endphp
                        <tr class="border-t border-gray-100 align-top">
                            <td class="px-3 py-2">
                                <a href="{{ route('campaigns.productos.ver', [$c, $p]) }}" class="text-sm font-semibold text-gray-900 hover:text-indigo-700">{{ $p->nombre }}</a>
                                <div class="text-[11px] text-gray-500">{{ $p->fecha_publicacion?->format('d/m/Y') ?? '—' }} · {{ __(':n medio(s)', ['n' => $medios->count() + $p->publicaciones_count]) }}</div>
                                <div class="text-[11px] text-gray-400 truncate max-w-md">{{ $medios->take(6)->implode(', ') }}{{ $medios->count() > 6 ? '…' : '' }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <select name="mover[{{ $p->id }}][subcampana_id]" class="{{ $inp }}">
                                    <option value="">{{ __('— Sin subcampaña —') }}</option>
                                    @foreach ($subs as $s)<option value="{{ $s->id }}" @selected((int) $p->subcampana_id === $s->id)>{{ $s->nombre }}</option>@endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <select name="mover[{{ $p->id }}][tipo_id]" class="{{ $inp }}">
                                    <option value="">{{ $p->tipo_id ? '' : __('— Sin tipo —') }}</option>
                                    @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((int) $p->tipo_id === $t->id)>{{ $t->nombre }}</option>@endforeach
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-10 text-center text-sm text-gray-500">{{ $sub === '0' ? __('Todas las publicaciones tienen subcampaña.') : __('No hay piezas con este filtro.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div>{{ $piezas->links() }}</div>
            @if ($piezas->count())<button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar cambios') }}</button>@endif
        </div>
    </form>
</div>
@endsection
