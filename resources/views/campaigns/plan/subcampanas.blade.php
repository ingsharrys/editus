@extends('layouts.app')

@section('content')
@php $inp = 'w-full h-9 rounded-lg border-gray-200 text-sm'; @endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <p class="text-sm text-gray-600 mb-4">{{ __('Cada subcampaña agrupa las publicaciones de un mismo contenido (p. ej. «Dengue»). Al publicar en la campaña eliges la subcampaña y así tienes métricas por subcampaña y de toda la campaña. El programa y el subprograma son los que salen en el certificado de los medios.') }}</p>

    <form method="POST" action="{{ route('campaigns.plan.subcampanas.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm" id="tablaSubs">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left w-52">{{ __('Subcampaña') }} *</th>
                        <th class="px-3 py-2 text-left">{{ __('Programa (certificado)') }}</th>
                        <th class="px-3 py-2 text-left w-48">{{ __('Subprograma (certificado)') }}</th>
                        <th class="px-3 py-2 text-left w-56">{{ __('Descripción') }}</th>
                        <th class="px-3 py-2 text-center w-16">{{ __('Activa') }}</th>
                        <th class="px-3 py-2 text-right w-20">{{ __('Publ.') }}</th>
                        <th class="px-3 py-2 w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subs as $i => $s)
                        <tr class="border-t border-gray-100">
                            <td class="px-3 py-2"><input type="hidden" name="subs[{{ $i }}][id]" value="{{ $s->id }}"><input name="subs[{{ $i }}][nombre]" maxlength="120" value="{{ $s->nombre }}" class="{{ $inp }} font-semibold"></td>
                            <td class="px-3 py-2"><input name="subs[{{ $i }}][programa]" maxlength="190" value="{{ $s->programa }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2"><input name="subs[{{ $i }}][subprograma]" maxlength="190" value="{{ $s->subprograma }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2"><input name="subs[{{ $i }}][descripcion]" maxlength="500" value="{{ $s->descripcion }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2 text-center"><input type="hidden" name="subs[{{ $i }}][is_active]" value="0"><input type="checkbox" name="subs[{{ $i }}][is_active]" value="1" @checked($s->is_active) class="h-4 w-4 rounded border-gray-300 text-indigo-600"></td>
                            <td class="px-3 py-2 text-right tabular-nums"><a href="{{ route('campaigns.plan', [$c, 'sub' => $s->id]) }}" class="text-indigo-700 hover:underline">{{ $s->posts_count }}</a></td>
                            <td class="px-3 py-2 text-right"><label class="inline-flex items-center gap-1 text-xs text-rose-600 cursor-pointer"><input type="checkbox" name="subs[{{ $i }}][borrar]" value="1" class="h-3.5 w-3.5 rounded border-gray-300 text-rose-600"> {{ __('Borrar') }}</label></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <template id="filaSub">
            <tr class="border-t border-gray-100 bg-indigo-50/30">
                <td class="px-3 py-2"><input name="subs[__i__][nombre]" maxlength="120" placeholder="{{ __('Ej: Dengue') }}" class="{{ $inp }} font-semibold"></td>
                <td class="px-3 py-2"><input name="subs[__i__][programa]" maxlength="190" placeholder="{{ __('Ej: Programa 6. ETV: Dengue y Fiebre Amarilla') }}" class="{{ $inp }}"></td>
                <td class="px-3 py-2"><input name="subs[__i__][subprograma]" maxlength="190" placeholder="{{ __('Ej: Dengue') }}" class="{{ $inp }}"></td>
                <td class="px-3 py-2"><input name="subs[__i__][descripcion]" maxlength="500" class="{{ $inp }}"></td>
                <td class="px-3 py-2 text-center"><input type="hidden" name="subs[__i__][is_active]" value="0"><input type="checkbox" name="subs[__i__][is_active]" value="1" checked class="h-4 w-4 rounded border-gray-300 text-indigo-600"></td>
                <td class="px-3 py-2 text-right text-gray-400">0</td>
                <td class="px-3 py-2 text-right"><button type="button" class="text-xs text-rose-600" onclick="this.closest('tr').remove()">{{ __('Quitar') }}</button></td>
            </tr>
        </template>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <button type="button" id="agregarSub" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">+ {{ __('Agregar subcampaña') }}</button>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar subcampañas') }}</button>
        </div>
        <p class="mt-3 text-[11px] text-gray-400">{{ __('Si cambias el programa o el subprograma de una subcampaña, se actualizan sus publicaciones y los certificados. Si borras una subcampaña, sus publicaciones quedan «sin subcampaña».') }}</p>
    </form>
</div>
<script>
(function () {
    let i = {{ $subs->count() }};
    const agregar = () => {
        const html = document.getElementById('filaSub').innerHTML.replaceAll('__i__', i++);
        document.querySelector('#tablaSubs tbody').insertAdjacentHTML('beforeend', html);
        document.querySelector('#tablaSubs tbody tr:last-child input')?.focus();
    };
    document.getElementById('agregarSub').addEventListener('click', agregar);
    if (i === 0) agregar();
})();
</script>
@endsection
