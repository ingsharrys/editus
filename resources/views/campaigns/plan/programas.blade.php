@extends('layouts.app')

@section('content')
@php $inp = 'w-full h-9 rounded-lg border-gray-200 text-sm'; @endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <p class="text-sm text-gray-600 mb-4">{{ __('Programas del contrato (p. ej. «2-1 Salud infantil y adolescencia») con sus subprogramas. Cada pieza es de un programa; el informe de métricas tiene un capítulo por programa y el certificado de los medios muestra el programa y el subprograma de cada pieza.') }}</p>

    <form method="POST" action="{{ route('campaigns.plan.programas.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm" id="tablaProgs">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left w-24">{{ __('Código') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Programa') }} *</th>
                        <th class="px-3 py-2 text-left w-[26rem]">{{ __('Subprogramas (separados por coma)') }}</th>
                        <th class="px-3 py-2 text-right w-16">{{ __('Piezas') }}</th>
                        <th class="px-3 py-2 w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($programas as $i => $p)
                        <tr class="border-t border-gray-100">
                            <td class="px-3 py-2"><input type="hidden" name="programas[{{ $i }}][id]" value="{{ $p->id }}"><input name="programas[{{ $i }}][codigo]" maxlength="20" value="{{ $p->codigo }}" class="{{ $inp }} font-semibold text-center"></td>
                            <td class="px-3 py-2"><input name="programas[{{ $i }}][nombre]" maxlength="190" value="{{ $p->nombre }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2"><input name="programas[{{ $i }}][subprogramas]" maxlength="3000" value="{{ implode(', ', $p->listaSubprogramas()) }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2 text-right tabular-nums"><a href="{{ route('campaigns.productos', [$c, 'programa' => $p->id]) }}" class="text-indigo-700 hover:underline">{{ $p->productos_count }}</a></td>
                            <td class="px-3 py-2 text-right"><label class="inline-flex items-center gap-1 text-xs text-rose-600 cursor-pointer"><input type="checkbox" name="programas[{{ $i }}][borrar]" value="1" class="h-3.5 w-3.5 rounded border-gray-300 text-rose-600"> {{ __('Borrar') }}</label></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <template id="filaProg">
            <tr class="border-t border-gray-100 bg-indigo-50/30">
                <td class="px-3 py-2"><input name="programas[__i__][codigo]" maxlength="20" placeholder="2-1" class="{{ $inp }} font-semibold text-center"></td>
                <td class="px-3 py-2"><input name="programas[__i__][nombre]" maxlength="190" placeholder="{{ __('Ej: Salud infantil y adolescencia') }}" class="{{ $inp }}"></td>
                <td class="px-3 py-2"><input name="programas[__i__][subprogramas]" maxlength="3000" placeholder="{{ __('Ej: Vacunación, PAI, Nutrición') }}" class="{{ $inp }}"></td>
                <td class="px-3 py-2 text-right text-gray-400">0</td>
                <td class="px-3 py-2 text-right"><button type="button" class="text-xs text-rose-600" onclick="this.closest('tr').remove()">{{ __('Quitar') }}</button></td>
            </tr>
        </template>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <button type="button" id="agregarProg" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">+ {{ __('Agregar programa') }}</button>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar programas') }}</button>
        </div>
        <p class="mt-3 text-[11px] text-gray-400">{{ __('Si importas el Excel del control de productos, los programas se crean solos a partir de la columna «Programa» (p. ej. «CAMPAÑA 2-1: Salud infantil y adolescencia»).') }}</p>
    </form>
</div>
<script>
(function () {
    let i = {{ $programas->count() }};
    const agregar = () => {
        document.querySelector('#tablaProgs tbody').insertAdjacentHTML('beforeend', document.getElementById('filaProg').innerHTML.replaceAll('__i__', i++));
        document.querySelector('#tablaProgs tbody tr:last-child input')?.focus();
    };
    document.getElementById('agregarProg').addEventListener('click', agregar);
    if (i === 0) agregar();
})();
</script>
@endsection
