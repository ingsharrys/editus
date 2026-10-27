@extends('layouts.app')

@section('content')
@php
    $inp = 'w-full h-9 rounded-lg border-gray-200 text-sm';
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $mapaSubprog = $programas->mapWithKeys(fn($x) => [$x->id => $x->listaSubprogramas()]);
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <p class="text-sm text-gray-600 mb-4">{{ __('Cada subcampaña es un tema de la campaña (p. ej. «Dengue»). Dentro de ella asignas los tipos de publicación que lleva (Videos largos, Post 600x600, En vivo…) y cuántas piezas de cada uno. Al publicar eliges la subcampaña y el tipo, y tienes métricas por subcampaña y de toda la campaña.') }}</p>

    @if ($conTipos && $tipos->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">
            {{ __('Primero crea los tipos de publicación de la campaña en') }} <a href="{{ route('campaigns.plan.tipos', $c) }}" class="font-semibold underline">{{ __('Tipos de publicación') }}</a>.
        </div>
    @endif

    <form method="POST" action="{{ route('campaigns.plan.subcampanas.guardar', $c) }}">
        @csrf
        <div id="listaSubs" class="space-y-4">
            @foreach ($subs as $i => $s)
                @php $asig = $conTipos ? $s->tipos->keyBy('id') : collect(); $av = $avance->get($s->id); @endphp
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 space-y-3">
                    <input type="hidden" name="subs[{{ $i }}][id]" value="{{ $s->id }}">
                    <div class="grid sm:grid-cols-12 gap-3 items-end">
                        <div class="sm:col-span-3"><label class="{{ $lbl }}">{{ __('Subcampaña') }} *</label><input name="subs[{{ $i }}][nombre]" maxlength="120" value="{{ $s->nombre }}" class="{{ $inp }} font-semibold"></div>
                        @if ($conTipos)
                            <div class="sm:col-span-4"><label class="{{ $lbl }}">{{ __('Programa') }}</label>
                                <select name="subs[{{ $i }}][programa_id]" class="{{ $inp }}" data-prog="{{ $i }}"><option value="">—</option>@foreach ($programas as $p)<option value="{{ $p->id }}" @selected((int) $s->programa_id === $p->id)>{{ $p->etiqueta() }}</option>@endforeach</select></div>
                        @else
                            <div class="sm:col-span-4"><label class="{{ $lbl }}">{{ __('Programa') }}</label><input name="subs[{{ $i }}][programa]" maxlength="190" value="{{ $s->programa }}" class="{{ $inp }}"></div>
                        @endif
                        <div class="sm:col-span-3"><label class="{{ $lbl }}">{{ __('Subprograma') }}</label><input name="subs[{{ $i }}][subprograma]" list="dlSub{{ $i }}" maxlength="190" value="{{ $s->subprograma }}" class="{{ $inp }}"><datalist id="dlSub{{ $i }}" data-dl="{{ $i }}"></datalist></div>
                        <div class="sm:col-span-2 flex items-center justify-between gap-2 pb-2">
                            <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="hidden" name="subs[{{ $i }}][is_active]" value="0"><input type="checkbox" name="subs[{{ $i }}][is_active]" value="1" @checked($s->is_active) class="h-4 w-4 rounded border-gray-300 text-indigo-600"> {{ __('Activa') }}</label>
                            <label class="inline-flex items-center gap-1 text-xs text-rose-600 cursor-pointer"><input type="checkbox" name="subs[{{ $i }}][borrar]" value="1" class="h-3.5 w-3.5 rounded border-gray-300 text-rose-600"> {{ __('Borrar') }}</label>
                        </div>
                    </div>
                    @if ($conTipos)
                        <div>
                            <label class="{{ $lbl }}">{{ __('Tipos de publicación de esta subcampaña') }} <span class="normal-case font-normal text-gray-400">({{ __('y cuántas piezas se planean') }})</span></label>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($tipos as $t)
                                    @php $on = $asig->has($t->id); $hecho = collect($av['tipos'] ?? [])->first(fn($x) => $x['tipo']->id === $t->id); @endphp
                                    <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs {{ $on ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200 bg-white' }} has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50">
                                        <input type="hidden" name="subs[{{ $i }}][tipos][{{ $t->id }}][on]" value="0">
                                        <input type="checkbox" name="subs[{{ $i }}][tipos][{{ $t->id }}][on]" value="1" @checked($on) class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600">
                                        <span class="font-semibold text-gray-800">{{ $t->nombre }}</span>
                                        <input type="number" min="0" name="subs[{{ $i }}][tipos][{{ $t->id }}][cantidad]" value="{{ $asig->get($t->id)?->pivot->cantidad }}" placeholder="#" title="{{ __('Piezas planeadas') }}" class="w-14 h-7 rounded-md border-gray-200 text-xs text-right">
                                        @if ($hecho)<span class="text-[10px] text-gray-500 tabular-nums" title="{{ __('Piezas creadas / planeadas') }}">{{ $hecho['piezas'] }}{{ $hecho['planeadas'] !== null ? '/' . $hecho['planeadas'] : '' }}</span>@endif
                                    </label>
                                @empty
                                    <span class="text-xs text-gray-500">{{ __('Sin tipos de publicación.') }}</span>
                                @endforelse
                            </div>
                        </div>
                    @endif
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <input name="subs[{{ $i }}][descripcion]" maxlength="500" value="{{ $s->descripcion }}" placeholder="{{ __('Descripción (opcional)') }}" class="flex-1 min-w-[12rem] h-9 rounded-lg border-gray-200 text-xs">
                        <div class="flex flex-wrap gap-3 text-xs">
                            <a href="{{ route('campaigns.plan', [$c, 'sub' => $s->id]) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Métricas') }} ({{ $s->posts_count }})</a>
                            @if ($conTipos)
                                <a href="{{ route('campaigns.productos', [$c, 'subcampana' => $s->id]) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Piezas') }} ({{ $s->productos_count }})</a>
                                <a href="{{ route('campaigns.informes', [$c, 'subcampana' => $s->id]) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Informe') }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <template id="filaSub">
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50/30 shadow-sm p-4 space-y-3">
                <div class="grid sm:grid-cols-12 gap-3 items-end">
                    <div class="sm:col-span-3"><label class="{{ $lbl }}">{{ __('Subcampaña') }} *</label><input name="subs[__i__][nombre]" maxlength="120" placeholder="{{ __('Ej: Dengue') }}" class="{{ $inp }} font-semibold"></div>
                    @if ($conTipos)
                        <div class="sm:col-span-4"><label class="{{ $lbl }}">{{ __('Programa') }}</label>
                            <select name="subs[__i__][programa_id]" class="{{ $inp }}" data-prog="__i__"><option value="">—</option>@foreach ($programas as $p)<option value="{{ $p->id }}">{{ $p->etiqueta() }}</option>@endforeach</select></div>
                    @else
                        <div class="sm:col-span-4"><label class="{{ $lbl }}">{{ __('Programa') }}</label><input name="subs[__i__][programa]" maxlength="190" class="{{ $inp }}"></div>
                    @endif
                    <div class="sm:col-span-3"><label class="{{ $lbl }}">{{ __('Subprograma') }}</label><input name="subs[__i__][subprograma]" list="dlSub__i__" maxlength="190" placeholder="{{ __('Ej: Dengue') }}" class="{{ $inp }}"><datalist id="dlSub__i__" data-dl="__i__"></datalist></div>
                    <div class="sm:col-span-2 flex items-center justify-between gap-2 pb-2">
                        <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="hidden" name="subs[__i__][is_active]" value="0"><input type="checkbox" name="subs[__i__][is_active]" value="1" checked class="h-4 w-4 rounded border-gray-300 text-indigo-600"> {{ __('Activa') }}</label>
                        <button type="button" class="text-xs text-rose-600" onclick="this.closest('.rounded-2xl').remove()">{{ __('Quitar') }}</button>
                    </div>
                </div>
                @if ($conTipos)
                    <div>
                        <label class="{{ $lbl }}">{{ __('Tipos de publicación de esta subcampaña') }}</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($tipos as $t)
                                <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-xs has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50">
                                    <input type="hidden" name="subs[__i__][tipos][{{ $t->id }}][on]" value="0">
                                    <input type="checkbox" name="subs[__i__][tipos][{{ $t->id }}][on]" value="1" class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600">
                                    <span class="font-semibold text-gray-800">{{ $t->nombre }}</span>
                                    <input type="number" min="0" name="subs[__i__][tipos][{{ $t->id }}][cantidad]" placeholder="#" class="w-14 h-7 rounded-md border-gray-200 text-xs text-right">
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
                <input name="subs[__i__][descripcion]" maxlength="500" placeholder="{{ __('Descripción (opcional)') }}" class="w-full h-9 rounded-lg border-gray-200 text-xs">
            </div>
        </template>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <button type="button" id="agregarSub" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">+ {{ __('Agregar subcampaña') }}</button>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar subcampañas') }}</button>
        </div>
        <p class="mt-3 text-[11px] text-gray-400">{{ __('Si borras una subcampaña, sus piezas y publicaciones quedan «sin subcampaña». El número junto a cada tipo es cuántas piezas se planean; al lado ves cuántas van creadas.') }}</p>
    </form>
</div>
<script>
(function () {
    const mapa = @json($mapaSubprog);
    const llenar = (i) => {
        const sel = document.querySelector('[data-prog="' + i + '"]'), dl = document.querySelector('[data-dl="' + i + '"]');
        if (!sel || !dl) return;
        dl.innerHTML = '';
        (mapa[sel.value] || []).forEach(v => { const o = document.createElement('option'); o.value = v; dl.appendChild(o); });
    };
    const enlazar = (i) => { const sel = document.querySelector('[data-prog="' + i + '"]'); if (sel) { sel.addEventListener('change', () => llenar(i)); llenar(i); } };
    let i = {{ $subs->count() }};
    for (let j = 0; j < i; j++) enlazar(j);
    const agregar = () => {
        const n = i++;
        document.getElementById('listaSubs').insertAdjacentHTML('beforeend', document.getElementById('filaSub').innerHTML.replaceAll('__i__', n));
        enlazar(n);
        document.querySelector('#listaSubs > div:last-child input[name$="[nombre]"]')?.focus();
    };
    document.getElementById('agregarSub').addEventListener('click', agregar);
    if (i === 0) agregar();
})();
</script>
@endsection
