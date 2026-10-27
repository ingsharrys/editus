@extends('layouts.app')

@section('content')
@php
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $inp = 'w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm';
    $filas = old('paginas', [['medio' => ''], ['medio' => ''], ['medio' => '']]);
    $sugeridas = collect(config('services.editus.medios', []))->values();
@endphp
<div class="max-w-4xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <h2 class="text-xl font-bold text-gray-900 mb-1">{{ __('Registrar banner en páginas web') }}</h2>
    <p class="text-sm text-gray-600 mb-4">{{ __('Para banners que salen en sitios web (no en Facebook): escribe cada página web con su dirección y sube el pantallazo donde se ve el banner. Sale en el informe en «PÁGINAS WEB».') }}</p>

    <form method="POST" action="{{ route('campaigns.banners.guardar', $c) }}" enctype="multipart/form-data" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
        @csrf
        <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="{{ $lbl }}">{{ __('Subcampaña') }}</label>
                <select name="subcampana_id" class="{{ $inp }}"><option value="">{{ __('— De toda la campaña —') }}</option>
                    @foreach ($subs as $s)<option value="{{ $s->id }}" @selected((int) old('subcampana_id', $subElegida) === $s->id)>{{ $s->nombre }}</option>@endforeach</select></div>
            <div><label class="{{ $lbl }}">{{ __('Tipo de publicación') }} *</label>
                <select name="tipo_id" required class="{{ $inp }}"><option value="">—</option>
                    @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((int) old('tipo_id', $tipoElegido) === $t->id)>{{ $t->nombre }}{{ $t->formato ? ' · ' . $t->formato : '' }}</option>@endforeach</select></div>
        </div>
        <div class="grid sm:grid-cols-4 gap-3">
            <div class="sm:col-span-2"><label class="{{ $lbl }}">{{ __('Nombre (opcional)') }}</label><input name="nombre" maxlength="255" value="{{ old('nombre') }}" placeholder="{{ __('Ej: Banner vacunación diciembre') }}" class="{{ $inp }}"></div>
            <div><label class="{{ $lbl }}">{{ __('Publicado desde') }} *</label><input type="date" name="fecha_publicacion" required value="{{ old('fecha_publicacion', now()->toDateString()) }}" class="{{ $inp }}"></div>
            <div><label class="{{ $lbl }}">{{ __('Hasta') }}</label><input type="date" name="fecha_fin" value="{{ old('fecha_fin') }}" class="{{ $inp }}"></div>
        </div>
        <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="{{ $lbl }}">{{ __('Acta de aprobación No.') }}</label><input name="acta_aprobacion" maxlength="60" value="{{ old('acta_aprobacion') }}" class="{{ $inp }}"></div>
            <div><label class="{{ $lbl }}">{{ __('Acta de publicación No.') }}</label><input name="acta_publicacion" maxlength="60" value="{{ old('acta_publicacion') }}" class="{{ $inp }}"></div>
            <div><label class="{{ $lbl }}">{{ __('Muestra (la imagen del banner)') }}</label><input type="file" name="muestra" accept="image/*" class="text-xs mt-2"></div>
        </div>

        <div>
            <label class="{{ $lbl }}">{{ __('Páginas web donde salió') }} *</label>
            <datalist id="dlWeb">@foreach ($sugeridas as $m)<option value="{{ $m }}">@endforeach</datalist>
            <div id="filasWeb" class="space-y-2">
                @foreach ($filas as $i => $f)
                    <div class="grid sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_minmax(0,14rem)_auto] gap-2 items-center rounded-xl border border-gray-200 p-2">
                        <input name="paginas[{{ $i }}][medio]" list="dlWeb" maxlength="190" value="{{ $f['medio'] ?? '' }}" placeholder="{{ __('Ej: Neiva al Día') }}" class="h-9 rounded-lg border-gray-200 text-sm">
                        <input type="url" name="paginas[{{ $i }}][url]" maxlength="1000" value="{{ $f['url'] ?? '' }}" placeholder="https://neivaaldia.com/" class="h-9 rounded-lg border-gray-200 text-sm">
                        <label class="text-[11px] text-gray-500">{{ __('Pantallazo') }}<input type="file" name="paginas[{{ $i }}][captura]" accept="image/*" class="block text-xs"></label>
                        <button type="button" class="text-xs text-rose-600 px-2" onclick="this.parentElement.remove()">{{ __('Quitar') }}</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="otraWeb" class="mt-2 h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">+ {{ __('Otra página web') }}</button>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ $subElegida ? route('campaigns.subcampanas.ver', [$c, $subElegida]) : route('campaigns.plan', $c) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancelar') }}</a>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar banner') }}</button>
        </div>
    </form>
</div>
<script>
(function () {
    const cont = document.getElementById('filasWeb');
    let i = {{ count($filas) }};
    document.getElementById('otraWeb').addEventListener('click', () => {
        const modelo = cont.firstElementChild;
        const fila = modelo ? modelo.cloneNode(true) : null;
        if (!fila) return;
        fila.querySelectorAll('input').forEach(inp => { inp.value = ''; inp.name = inp.name.replace(/paginas\[\d+\]/, 'paginas[' + i + ']'); });
        i++;
        cont.appendChild(fila);
        fila.querySelector('input')?.focus();
    });
})();
</script>
@endsection
