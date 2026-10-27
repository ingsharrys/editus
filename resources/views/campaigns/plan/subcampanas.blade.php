@extends('layouts.app')

@section('content')
@php
    $inp = 'w-full h-9 rounded-lg border-gray-200 text-sm';
    $clases = \App\Models\Subcampana::CLASES;
    $conInforme = \Illuminate\Support\Facades\Schema::hasColumn('campaign_subcampanas', 'etiqueta');
    $sugeridos = [
        ['Videos largos', 'Video Largo', 'redes', 'VIDEO', '', 'video largo'],
        ['Videos cortos', 'Video Corto', 'redes', 'VIDEO', 'Reel / short', 'video corto'],
        ['Post 600x600', 'Aviso Publicitario', 'redes', 'IMAGEN', '600 x 600 px', 'post de 600x600 px'],
        ['Banner 1600x185', 'Banner', 'web', 'IMAGEN', '1600 x 185 px', 'banner de 1.600x185 px'],
        ['Banner 360x300', 'Banner', 'web', 'IMAGEN', '360 x 300 px', 'banner de 360x300 px'],
        ['En vivos', 'En vivo', 'envivo', 'VIDEO', '', 'en vivo'],
        ['Podcast', 'Podcast', 'audio', 'AUDIO', 'Episodio de podcast', 'podcast'],
        ['Cuñas', 'Cuña', 'audio', 'AUDIO', 'Cuña radial / digital', 'cuña'],
    ];
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <p class="text-sm text-gray-600 mb-4">{{ __('Cada subcampaña es un tipo de publicación (Videos largos, Banner 1600x185, En vivos…). Las piezas del control de productos son de un tipo; el informe de métricas tiene una sección por tipo y los cupos se ponen por tipo.') }}</p>

    <form method="POST" action="{{ route('campaigns.plan.subcampanas.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm" id="tablaSubs">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-2 py-2 text-left w-44">{{ __('Tipo de publicación') }} *</th>
                        @if ($conInforme)
                            <th class="px-2 py-2 text-left w-36" title="{{ __('Para numerar en el informe: «Video Largo No. 1»') }}">{{ __('Nombre singular') }}</th>
                            <th class="px-2 py-2 text-left w-44">{{ __('En el informe') }}</th>
                            <th class="px-2 py-2 text-left w-24">{{ __('Tipo') }}</th>
                            <th class="px-2 py-2 text-left w-32">{{ __('Formato / medida') }}</th>
                            <th class="px-2 py-2 text-left w-44" title="{{ __('Va en la descripción: «Publicación de un (1) video largo en…»') }}">{{ __('Unidad (descripción)') }}</th>
                        @endif
                        <th class="px-2 py-2 text-center w-14">{{ __('Activa') }}</th>
                        <th class="px-2 py-2 text-right w-16">{{ __('Piezas') }}</th>
                        <th class="px-2 py-2 w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subs as $i => $s)
                        <tr class="border-t border-gray-100 align-top">
                            <td class="px-2 py-2"><input type="hidden" name="subs[{{ $i }}][id]" value="{{ $s->id }}"><input name="subs[{{ $i }}][nombre]" maxlength="120" value="{{ $s->nombre }}" class="{{ $inp }} font-semibold"></td>
                            @if ($conInforme)
                                <td class="px-2 py-2"><input name="subs[{{ $i }}][etiqueta]" maxlength="80" value="{{ $s->etiqueta }}" placeholder="{{ $s->nombre }}" class="{{ $inp }}"></td>
                                <td class="px-2 py-2"><select name="subs[{{ $i }}][clase]" class="{{ $inp }}">@foreach ($clases as $k => $et)<option value="{{ $k }}" @selected(($s->clase ?: 'redes') === $k)>{{ __($et) }}</option>@endforeach</select></td>
                                <td class="px-2 py-2"><input name="subs[{{ $i }}][tipo]" maxlength="40" value="{{ $s->tipo }}" placeholder="VIDEO" class="{{ $inp }}"></td>
                                <td class="px-2 py-2"><input name="subs[{{ $i }}][formato]" maxlength="80" value="{{ $s->formato }}" class="{{ $inp }}"></td>
                                <td class="px-2 py-2">
                                    <input name="subs[{{ $i }}][unidad]" maxlength="120" value="{{ $s->unidad }}" placeholder="{{ mb_strtolower($s->etiquetaSingular()) }}" class="{{ $inp }}">
                                    <details class="mt-1"><summary class="text-[11px] text-indigo-700 cursor-pointer">{{ __('Más opciones') }}</summary>
                                        <label class="block text-[10px] text-gray-500 mt-1">{{ __('Título de la sección') }}</label><input name="subs[{{ $i }}][titulo_informe]" maxlength="120" value="{{ $s->titulo_informe }}" placeholder="{{ $s->tituloInforme() }}" class="{{ $inp }}">
                                        <label class="block text-[10px] text-gray-500 mt-1">{{ __('Descripción de cada pieza') }}</label><input name="subs[{{ $i }}][plantilla]" maxlength="500" value="{{ $s->plantilla }}" placeholder="{{ app(\App\Services\ProductosService::class)->plantillaDefecto($s->clase) }}" class="{{ $inp }}">
                                        <p class="text-[10px] text-gray-400 mt-0.5">{{ __('Usa {unidad}, {medios} («quince (15) medios digitales»), {paginas} y {nombre}.') }}</p>
                                    </details>
                                </td>
                            @endif
                            <td class="px-2 py-2 text-center"><input type="hidden" name="subs[{{ $i }}][is_active]" value="0"><input type="checkbox" name="subs[{{ $i }}][is_active]" value="1" @checked($s->is_active) class="h-4 w-4 mt-2 rounded border-gray-300 text-indigo-600"></td>
                            <td class="px-2 py-2 text-right tabular-nums pt-4">
                                @if (\App\Services\ProductosService::disponible())<a href="{{ route('campaigns.productos', [$c, 'sub' => $s->id]) }}" class="text-indigo-700 hover:underline">{{ $s->productos_count ?? 0 }}</a>@else{{ $s->posts_count }}@endif
                            </td>
                            <td class="px-2 py-2 text-right pt-3"><label class="inline-flex items-center gap-1 text-xs text-rose-600 cursor-pointer"><input type="checkbox" name="subs[{{ $i }}][borrar]" value="1" class="h-3.5 w-3.5 rounded border-gray-300 text-rose-600"> {{ __('Borrar') }}</label></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <template id="filaSub">
            <tr class="border-t border-gray-100 bg-indigo-50/30 align-top">
                <td class="px-2 py-2"><input name="subs[__i__][nombre]" maxlength="120" placeholder="{{ __('Ej: Videos largos') }}" class="{{ $inp }} font-semibold" data-campo="nombre"></td>
                @if ($conInforme)
                    <td class="px-2 py-2"><input name="subs[__i__][etiqueta]" maxlength="80" placeholder="{{ __('Ej: Video Largo') }}" class="{{ $inp }}" data-campo="etiqueta"></td>
                    <td class="px-2 py-2"><select name="subs[__i__][clase]" class="{{ $inp }}" data-campo="clase">@foreach ($clases as $k => $et)<option value="{{ $k }}">{{ __($et) }}</option>@endforeach</select></td>
                    <td class="px-2 py-2"><input name="subs[__i__][tipo]" maxlength="40" placeholder="VIDEO" class="{{ $inp }}" data-campo="tipo"></td>
                    <td class="px-2 py-2"><input name="subs[__i__][formato]" maxlength="80" class="{{ $inp }}" data-campo="formato"></td>
                    <td class="px-2 py-2"><input name="subs[__i__][unidad]" maxlength="120" placeholder="{{ __('Ej: video largo') }}" class="{{ $inp }}" data-campo="unidad"></td>
                @endif
                <td class="px-2 py-2 text-center"><input type="hidden" name="subs[__i__][is_active]" value="0"><input type="checkbox" name="subs[__i__][is_active]" value="1" checked class="h-4 w-4 mt-2 rounded border-gray-300 text-indigo-600"></td>
                <td class="px-2 py-2 text-right text-gray-400 pt-4">0</td>
                <td class="px-2 py-2 text-right pt-3"><button type="button" class="text-xs text-rose-600" onclick="this.closest('tr').remove()">{{ __('Quitar') }}</button></td>
            </tr>
        </template>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="agregarSub" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">+ {{ __('Agregar tipo') }}</button>
                @if ($conInforme)
                    <span class="text-xs text-gray-500">{{ __('Agregar rápido:') }}</span>
                    @foreach ($sugeridos as $sg)
                        @unless ($subs->contains(fn($s) => mb_strtolower($s->nombre) === mb_strtolower($sg[0])))
                            <button type="button" class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs text-gray-700 hover:bg-indigo-50" data-sugerido='@json($sg)'>{{ $sg[0] }}</button>
                        @endunless
                    @endforeach
                @endif
            </div>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar subcampañas') }}</button>
        </div>
        <p class="mt-3 text-[11px] text-gray-400">{{ __('Si cambias el nombre singular, se actualizan sus publicaciones y sus cupos. Si borras un tipo, sus piezas y publicaciones quedan sin tipo.') }}</p>
    </form>
</div>
<script>
(function () {
    let i = {{ $subs->count() }};
    const agregar = (valores) => {
        const html = document.getElementById('filaSub').innerHTML.replaceAll('__i__', i++);
        const tbody = document.querySelector('#tablaSubs tbody');
        tbody.insertAdjacentHTML('beforeend', html);
        const fila = tbody.lastElementChild;
        if (valores) ['nombre', 'etiqueta', 'clase', 'tipo', 'formato', 'unidad'].forEach((k, j) => { const el = fila.querySelector('[data-campo="' + k + '"]'); if (el) el.value = valores[j]; });
        fila.querySelector('input')?.focus();
    };
    document.getElementById('agregarSub').addEventListener('click', () => agregar());
    document.querySelectorAll('[data-sugerido]').forEach(b => b.addEventListener('click', () => { agregar(JSON.parse(b.dataset.sugerido)); b.remove(); }));
    if (i === 0) agregar();
})();
</script>
@endsection
