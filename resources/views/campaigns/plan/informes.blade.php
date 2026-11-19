@extends('layouts.app')

@section('content')
@php
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $inp = 'w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm';
    $fondo = \App\Services\ProductosService::url($c, $membrete['fondo']);
    $enc = \App\Services\ProductosService::url($c, $membrete['encabezado']);
    $pie = \App\Services\ProductosService::url($c, $membrete['pie']);
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    {{-- Informes por medio: el informe de publicaciones de cada medio en PDF, Word y Excel --}}
    @php
        $estados = ['inscrito' => ['Inscrito', 'bg-emerald-50 text-emerald-800'], 'inscribiendose' => ['Inscribiéndose', 'bg-amber-50 text-amber-800'],
            'sin_inscribir' => ['Sin inscribir', 'bg-gray-100 text-gray-600'], 'pagina' => ['Página de la campaña', 'bg-indigo-50 text-indigo-800'],
            'registrado' => ['Registrado a mano', 'bg-sky-50 text-sky-800']];
        $btn = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50';
    @endphp
    <form method="GET" action="{{ route('campaigns.informes.generar', [$c, 'vista']) }}" id="informe-medios" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 mb-6 space-y-4">
        <input type="hidden" name="agrupar" value="programa"><input type="hidden" name="resumen" value="1">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-gray-900">{{ __('Informes por medio') }}</h2>
                <p class="text-xs text-gray-500">{{ __('Elige un medio y descarga el informe de sus publicaciones en la campaña: PDF o Word (con capítulos por programa, métricas y capturas) y Excel (una fila por publicación y el resumen).') }}</p>
            </div>
            <input type="search" id="buscar-medio" placeholder="{{ __('Buscar medio…') }}" class="h-9 w-56 rounded-lg border-gray-200 text-sm shadow-sm">
        </div>
        <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="{{ $lbl }}">{{ __('Publicadas desde') }}</label><input type="date" name="desde" value="{{ $c->starts_on?->toDateString() }}" class="{{ $inp }}"></div>
            <div><label class="{{ $lbl }}">{{ __('Hasta') }}</label><input type="date" name="hasta" value="{{ $c->ends_on?->toDateString() }}" class="{{ $inp }}"></div>
            <div><label class="{{ $lbl }}">{{ __('Corte de métricas') }}</label><input type="date" name="corte" value="{{ now()->toDateString() }}" class="{{ $inp }}"></div>
        </div>
        @if ($medios->isEmpty())
            <p class="rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">{{ __('Todavía no hay medios en la campaña: aparecen cuando se inscriben, cuando se agregan en «Medios y contratos» o cuando se publica en sus páginas.') }}</p>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-100">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                        <tr><th class="px-3 py-2 text-left">{{ __('Medio') }}</th><th class="px-3 py-2 text-center">{{ __('Publicaciones') }}</th><th class="px-3 py-2 text-right">{{ __('Informe') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($medios as $m)
                            @php [$etq, $color] = $estados[$m['estado']] ?? [$m['estado'], 'bg-gray-100 text-gray-600']; @endphp
                            <tr data-medio="{{ mb_strtolower(\Illuminate\Support\Str::ascii($m['nombre'] . ' ' . implode(' ', $m['nombres_pagina']))) }}" class="{{ $m['publicaciones'] ? '' : 'opacity-60' }}">
                                <td class="px-3 py-2.5">
                                    <div class="font-semibold text-gray-900">{{ $m['nombre'] }}</div>
                                    <span class="mt-0.5 inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $color }}">{{ __($etq) }}</span>
                                    @if ($m['nombres_pagina'] && $m['nombres_pagina'] !== [$m['nombre']])<div class="mt-0.5 text-xs text-gray-500">{{ __('Páginas:') }} {{ implode(', ', $m['nombres_pagina']) }}</div>@endif
                                </td>
                                <td class="px-3 py-2.5 text-center font-semibold {{ $m['publicaciones'] ? 'text-gray-900' : 'text-gray-400' }}">{{ number_format($m['publicaciones'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2.5">
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                        @if ($m['publicaciones'])
                                            <button name="medio" value="{{ $m['clave'] }}" formtarget="_blank" class="{{ $btn }}">{{ __('Ver') }}</button>
                                            <button name="medio" value="{{ $m['clave'] }}" formaction="{{ route('campaigns.informes.generar', [$c, 'pdf']) }}" class="{{ $btn }}">PDF</button>
                                            <button name="medio" value="{{ $m['clave'] }}" formaction="{{ route('campaigns.informes.generar', [$c, 'word']) }}" class="{{ $btn }}">Word</button>
                                            <button name="medio" value="{{ $m['clave'] }}" formaction="{{ route('campaigns.informes.generar', [$c, 'excel']) }}" class="h-8 rounded-lg bg-emerald-700 px-2.5 text-xs font-semibold text-white hover:opacity-90">Excel</button>
                                        @else
                                            <span class="text-xs text-gray-400">{{ __('Sin publicaciones aún') }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p id="sin-resultados" class="hidden text-center text-sm text-gray-500">{{ __('Ningún medio coincide con la búsqueda.') }}</p>
        @endif
        <p class="text-[11px] text-gray-400">{{ __('Cada informe lleva solo las publicaciones de ese medio: las de sus páginas en editus y lo que reportó desde su portal (TikTok, banners). Las fechas filtran por fecha de publicación.') }}</p>
    </form>
    <script>
    (function () {
        const q = document.getElementById('buscar-medio');
        if (!q) return;
        q.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); }); // no envía el formulario
        q.addEventListener('input', () => {
            const t = q.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
            let visibles = 0;
            document.querySelectorAll('#informe-medios tr[data-medio]').forEach(tr => { const si = !t || tr.dataset.medio.includes(t); tr.classList.toggle('hidden', !si); if (si) visibles++; });
            const v = document.getElementById('sin-resultados'); if (v) v.classList.toggle('hidden', visibles > 0);
        });
    })();
    </script>

    <h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 mb-3">{{ __('Informe general de la campaña') }}</h2>
    <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-6">
        {{-- Informe de métricas --}}
        <form method="GET" action="{{ route('campaigns.informes.generar', [$c, 'vista']) }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
            <div>
                <h2 class="text-base font-bold text-gray-900">{{ __('Informe de métricas') }}</h2>
                <p class="text-xs text-gray-500">{{ __('Un capítulo por programa (o por subcampaña) y una sección por tipo de publicación. Cada pieza con su descripción, programa, subprograma, fechas, actas, muestra y, por cada medio, alcance, visualización, interacción, URL y captura.') }}</p>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Un capítulo por') }}</label>
                <div class="flex flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50"><input type="radio" name="agrupar" value="programa" @checked(!$subElegida) class="text-indigo-600"> {{ __('Programa (como el informe de la Secretaría)') }}</label>
                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50"><input type="radio" name="agrupar" value="subcampana" @checked($subElegida) class="text-indigo-600"> {{ __('Subcampaña (Dengue, Vacunación…)') }}</label>
                </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                <div><label class="{{ $lbl }}">{{ __('Programa') }}</label>
                    <select name="programa_id" class="{{ $inp }}"><option value="">{{ __('Todos los programas') }}</option>@foreach ($programas as $p)<option value="{{ $p->id }}">{{ $p->etiqueta() }}</option>@endforeach</select></div>
                <div><label class="{{ $lbl }}">{{ __('Título del documento') }}</label>
                    <input name="titulo" maxlength="255" value="{{ $membrete['titulo'] }}" placeholder="{{ __('Ej: CAPÍTULO II. PROGRAMA 2 – MÉTRICAS EDITUS') }}" class="{{ $inp }}"></div>
            </div>
            <div class="grid sm:grid-cols-3 gap-3">
                <div><label class="{{ $lbl }}">{{ __('Publicadas desde') }}</label><input type="date" name="desde" value="{{ $c->starts_on?->toDateString() }}" class="{{ $inp }}"></div>
                <div><label class="{{ $lbl }}">{{ __('Hasta') }}</label><input type="date" name="hasta" value="{{ $c->ends_on?->toDateString() }}" class="{{ $inp }}"></div>
                <div><label class="{{ $lbl }}" title="{{ __('«FECHA MÉTRICAS: Del (publicación) al (corte)»') }}">{{ __('Corte de métricas') }}</label><input type="date" name="corte" value="{{ now()->toDateString() }}" class="{{ $inp }}"></div>
            </div>
            @if ($subs->isNotEmpty())
                <div>
                    <label class="{{ $lbl }}">{{ __('Subcampañas') }} <span class="normal-case font-normal text-gray-400">({{ __('ninguna marcada = todas') }})</span></label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($subs as $s)
                            <label class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1 text-xs has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50"><input type="checkbox" name="subcampana_ids[]" value="{{ $s->id }}" @checked($subElegida === $s->id) class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600"> {{ $s->nombre }}</label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div>
                <label class="{{ $lbl }}">{{ __('Tipos de publicación') }}</label>
                <div class="flex flex-wrap gap-2">
                    @forelse ($tipos as $t)
                        <label class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1 text-xs has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50"><input type="checkbox" name="tipo_ids[]" value="{{ $t->id }}" checked class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600"> {{ $t->nombre }}</label>
                    @empty
                        <span class="text-xs text-gray-500">{{ __('Sin tipos de publicación.') }}</span>
                    @endforelse
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-800"><input type="hidden" name="resumen" value="0"><input type="checkbox" name="resumen" value="1" checked class="h-4 w-4 rounded border-gray-300 text-indigo-600"> {{ __('Agregar al final de cada capítulo una tabla resumen de métricas') }}</label>
            <div class="flex flex-wrap justify-end gap-2 pt-1">
                <button formtarget="_blank" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Vista previa') }}</button>
                <button formaction="{{ route('campaigns.informes.generar', [$c, 'pdf']) }}" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Descargar PDF') }}</button>
                <button formaction="{{ route('campaigns.informes.generar', [$c, 'excel']) }}" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-emerald-800 hover:bg-gray-50">{{ __('Descargar Excel') }}</button>
                <button formaction="{{ route('campaigns.informes.generar', [$c, 'word']) }}" class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Descargar Word') }}</button>
            </div>
            <p class="text-[11px] text-gray-400">{{ __('Solo salen las piezas con al menos una publicación. El Word queda editable para ajustes finales.') }}</p>
        </form>

        <div class="space-y-6">
            {{-- Excel --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                <h2 class="text-base font-bold text-gray-900">{{ __('Control de productos (Excel)') }}</h2>
                <p class="text-xs text-gray-500 mb-3">{{ __('Una hoja por tipo de publicación con la miniatura de cada pieza, la hoja «Resumen» por programa y la hoja «Métricas» con cada publicación.') }}</p>
                <a href="{{ route('campaigns.informes.excel', $c) }}" class="h-10 inline-flex items-center rounded-xl bg-emerald-700 text-white px-4 text-sm font-semibold hover:opacity-90">{{ __('Descargar Excel') }}</a>
            </div>

            {{-- Membrete --}}
            <form method="POST" action="{{ route('campaigns.informes.membrete', $c) }}" enctype="multipart/form-data" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                @csrf
                <h2 class="text-base font-bold text-gray-900">{{ __('Membrete del informe') }}</h2>
                <div><label class="{{ $lbl }}">{{ __('Hoja membretada (página completa)') }}</label>
                    <p class="text-[11px] text-gray-500 mb-1">{{ __('Imagen tamaño carta (p. ej. 2550 × 3300 px) con el logo, las franjas y la dirección; va detrás del texto en cada página.') }}</p>
                    @if ($fondo)<img src="{{ $fondo }}" alt="" class="h-28 mb-1 rounded border border-gray-100"><label class="flex items-center gap-1 text-[11px] text-rose-600 mb-1"><input type="checkbox" name="quitar_fondo" value="1" class="h-3.5 w-3.5 rounded"> {{ __('Quitar') }}</label>@endif
                    <input type="file" name="fondo" accept="image/*" class="text-xs"></div>
                <p class="text-[11px] text-gray-400">{{ __('O, en vez de la hoja completa, un encabezado y un pie:') }}</p>
                <div><label class="{{ $lbl }}">{{ __('Encabezado (imagen)') }}</label>
                    @if ($enc)<img src="{{ $enc }}" alt="" class="max-h-16 mb-1 rounded border border-gray-100"><label class="flex items-center gap-1 text-[11px] text-rose-600 mb-1"><input type="checkbox" name="quitar_encabezado" value="1" class="h-3.5 w-3.5 rounded"> {{ __('Quitar') }}</label>@endif
                    <input type="file" name="encabezado" accept="image/*" class="text-xs"></div>
                <div><label class="{{ $lbl }}">{{ __('Pie de página (imagen)') }}</label>
                    @if ($pie)<img src="{{ $pie }}" alt="" class="max-h-12 mb-1 rounded border border-gray-100"><label class="flex items-center gap-1 text-[11px] text-rose-600 mb-1"><input type="checkbox" name="quitar_pie" value="1" class="h-3.5 w-3.5 rounded"> {{ __('Quitar') }}</label>@endif
                    <input type="file" name="pie" accept="image/*" class="text-xs"></div>
                <div><label class="{{ $lbl }}">{{ __('O texto del pie') }}</label>
                    <textarea name="pie_texto" rows="2" maxlength="500" placeholder="Calle 14 No. 1F-02 Neiva (H) E-mail: comercial@dimedia.com.co Cel. 313 8071986" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ $membrete['pie_texto'] }}</textarea></div>
                <div><label class="{{ $lbl }}">{{ __('Título por defecto') }}</label><input name="titulo" maxlength="255" value="{{ $membrete['titulo'] }}" class="{{ $inp }}"></div>
                <p class="text-[11px] text-gray-500">{{ __('Al final del informe sale la firma del representante legal del contratante: es la misma de los contratos.') }}
                    @if (Route::has('campaigns.contratos'))<a href="{{ route('campaigns.contratos', $c) }}" class="underline text-indigo-700">{{ __('Cambiarla en Contratos') }}</a>@endif</p>
                <div class="flex justify-end"><button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold">{{ __('Guardar membrete') }}</button></div>
            </form>
        </div>
    </div>
</div>
@endsection
