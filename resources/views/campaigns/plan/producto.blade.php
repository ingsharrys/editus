@extends('layouts.app')

@section('content')
@php
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $inp = 'w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm';
    $chico = 'w-full h-9 rounded-lg border-gray-200 text-xs';
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $muestra = \App\Services\ProductosService::url($c, $p->muestra_path ? ['local', $p->muestra_path] : null);
    $mapaSubprog = $programas->mapWithKeys(fn($x) => [$x->id => $x->listaSubprogramas()]);
    $esWeb = ($p->tipo?->clase) === 'web';
    $tiposPorSub = $subs->mapWithKeys(fn($x) => [$x->id => $x->tipos->pluck('id')->all()]);
    $progPorSub = $subs->mapWithKeys(fn($x) => [$x->id => ['programa' => $x->programa_id, 'subprograma' => $x->subprograma]]);
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <a href="{{ route('campaigns.productos', $c) }}" class="text-xs text-indigo-700 hover:underline">← {{ __('Productos') }}</a>
            <h2 class="text-xl font-bold text-gray-900 mt-0.5">{{ $p->exists ? $p->nombre : __('Nueva pieza') }}</h2>
            @if ($p->exists)<p class="text-xs text-gray-500">{{ $p->codigo() }}{{ $p->subcampana ? ' · ' . __('Subcampaña') . ' ' . $p->subcampana->nombre : '' }}</p>@endif
        </div>
        @if ($p->exists)
            <form method="POST" action="{{ route('campaigns.productos.borrar', [$c, $p]) }}" onsubmit="return confirm(@js(__('¿Borrar esta pieza? Sus publicaciones de editus quedan sin pieza.')))">@csrf @method('DELETE')
                <button class="h-9 rounded-lg px-3 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Borrar pieza') }}</button></form>
        @endif
    </div>

    <form method="POST" action="{{ $p->exists ? route('campaigns.productos.actualizar', [$c, $p]) : route('campaigns.productos.guardar', $c) }}" enctype="multipart/form-data"
          class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
        @csrf
        <div class="grid lg:grid-cols-[minmax(0,1fr)_16rem] gap-5">
            <div class="space-y-3">
                <div class="grid sm:grid-cols-3 gap-3">
                    <div><label class="{{ $lbl }}">{{ __('Subcampaña') }}</label>
                        <select name="subcampana_id" id="subSel" class="{{ $inp }}"><option value="">—</option>@foreach ($subs as $s)<option value="{{ $s->id }}" @selected((int) old('subcampana_id', $p->subcampana_id) === $s->id)>{{ $s->nombre }}</option>@endforeach</select></div>
                    <div><label class="{{ $lbl }}">{{ __('Tipo de publicación') }} *</label>
                        <select name="tipo_id" id="tipoSel" required class="{{ $inp }}"><option value="">—</option>@foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((int) old('tipo_id', $p->tipo_id) === $t->id)>{{ $t->nombre }}</option>@endforeach</select></div>
                    <div><label class="{{ $lbl }}">{{ __('Estado') }} *</label>
                        <select name="estado" class="{{ $inp }}">@foreach ($estados as $e)<option value="{{ $e }}" @selected(old('estado', $p->estado) === $e)>{{ __($e) }}</option>@endforeach</select></div>
                </div>
                <div><label class="{{ $lbl }}">{{ __('Nombre de la pieza') }} *</label><input name="nombre" required maxlength="255" value="{{ old('nombre', $p->nombre) }}" placeholder="{{ __('Ej: P2 Salud Infantil Sp2 Vacunación') }}" class="{{ $inp }}"></div>
                <div class="grid sm:grid-cols-2 gap-3">
                    <div><label class="{{ $lbl }}">{{ __('Programa') }}</label>
                        <select name="programa_id" id="progSel" class="{{ $inp }}"><option value="">—</option>@foreach ($programas as $x)<option value="{{ $x->id }}" @selected((int) old('programa_id', $p->programa_id) === $x->id)>{{ $x->etiqueta() }}</option>@endforeach</select></div>
                    <div><label class="{{ $lbl }}">{{ __('Subprograma') }}</label>
                        <input name="subprograma" list="dlSubprog" maxlength="190" value="{{ old('subprograma', $p->subprograma) }}" placeholder="{{ __('Ej: Vacunación') }}" class="{{ $inp }}"><datalist id="dlSubprog"></datalist></div>
                </div>
                <div class="grid sm:grid-cols-4 gap-3">
                    <div><label class="{{ $lbl }}">{{ __('Solicitud') }}</label><input type="date" name="fecha_solicitud" value="{{ old('fecha_solicitud', $p->fecha_solicitud?->toDateString()) }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Entrega') }}</label><input type="date" name="fecha_entrega" value="{{ old('fecha_entrega', $p->fecha_entrega?->toDateString()) }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}" title="{{ __('Vacía = la de su primera publicación') }}">{{ __('Publicación / emisión') }}</label><input type="date" name="fecha_publicacion" value="{{ old('fecha_publicacion', $p->fecha_publicacion?->toDateString()) }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}" title="{{ __('Para banners: hasta cuándo estuvo publicado') }}">{{ __('Publicado hasta') }}</label><input type="date" name="fecha_fin" value="{{ old('fecha_fin', $p->fecha_fin?->toDateString()) }}" class="{{ $inp }}"></div>
                </div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <div><label class="{{ $lbl }}">{{ __('Acta de aprobación No.') }}</label><input name="acta_aprobacion" maxlength="60" value="{{ old('acta_aprobacion', $p->acta_aprobacion) }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Acta de publicación No.') }}</label><input name="acta_publicacion" maxlength="60" value="{{ old('acta_publicacion', $p->acta_publicacion) }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Responsable') }}</label><input name="responsable" maxlength="120" value="{{ old('responsable', $p->responsable) }}" class="{{ $inp }}"></div>
                </div>
                <div><label class="{{ $lbl }}">{{ __('Link a la carpeta / archivo') }}</label><input type="url" name="link_archivo" maxlength="500" value="{{ old('link_archivo', $p->link_archivo) }}" placeholder="https://drive.google.com/…" class="{{ $inp }}"></div>
                <div><label class="{{ $lbl }}">{{ __('Descripción en el informe') }}</label>
                    <textarea name="descripcion" rows="3" maxlength="5000" placeholder="{{ $descripcion ?: __('Vacía = la del tipo de publicación, p. ej. «Publicación de un (1) video largo en quince (15) medios digitales.»') }}" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('descripcion', $p->descripcion) }}</textarea>
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('Para un en vivo escribe de qué trató, p. ej. «Transmisión de un (1) en vivo realizado en la Secretaría de Salud… con la participación de…».') }}</p></div>
                <div><label class="{{ $lbl }}">{{ __('Observaciones') }}</label><textarea name="observaciones" rows="2" maxlength="5000" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('observaciones', $p->observaciones) }}</textarea></div>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Muestra') }}</label>
                <label class="flex flex-col items-center justify-center gap-1 min-h-[12rem] rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 cursor-pointer hover:border-indigo-300 overflow-hidden p-2">
                    <img id="muestraPrev" src="{{ $muestra }}" alt="" class="{{ $muestra ? '' : 'hidden' }} max-h-60 max-w-full object-contain">
                    <span id="muestraTxt" class="{{ $muestra ? 'hidden' : '' }} text-xs text-gray-500 text-center px-2">{{ __('Toca para subir la imagen de la pieza (o un fotograma del video)') }}</span>
                    <input type="file" name="muestra" accept="image/*" class="hidden" onchange="const f=this.files[0]; if(!f) return; const i=document.getElementById('muestraPrev'); i.src=URL.createObjectURL(f); i.classList.remove('hidden'); document.getElementById('muestraTxt').classList.add('hidden');">
                </label>
                @if ($p->exists)
                    <div class="mt-4 rounded-xl bg-gray-50 p-3 text-xs text-gray-600 space-y-1">
                        <div><span class="font-semibold">{{ __('Fecha de publicación') }}:</span> {{ $fechaPub?->format('d/m/Y') ?? '—' }}</div>
                        <div><span class="font-semibold">{{ __('Medios') }}:</span> {{ $tot['medios'] }}</div>
                        <div><span class="font-semibold">{{ __('Alcance') }}:</span> {{ $fmt($tot['alcance']) }}</div>
                        <div><span class="font-semibold">{{ __('Visualizaciones') }}:</span> {{ $fmt($tot['visualizaciones']) }}</div>
                        <div><span class="font-semibold">{{ __('Interacciones') }}:</span> {{ $fmt($tot['interacciones']) }}</div>
                    </div>
                @endif
            </div>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('campaigns.productos', $c) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancelar') }}</a>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ $p->exists ? __('Guardar pieza') : __('Crear pieza') }}</button>
        </div>
    </form>

    @if ($p->exists)
        {{-- Publicaciones de la pieza --}}
        <div id="publicaciones" class="mt-6 rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden scroll-mt-24">
            <div class="px-4 py-3 border-b border-gray-100">
                <h3 class="text-sm font-bold text-gray-900">{{ $esWeb ? __('Páginas web donde se publicó') : __('Medios donde se publicó') }} <span class="font-normal text-gray-400">({{ $pubs->count() }})</span></h3>
                <p class="text-[11px] text-gray-500">{{ __('Las publicaciones hechas desde editus aparecen solas con sus métricas. Puedes subir la captura de evidencia o corregir una cifra; las de otros medios o páginas web se agregan abajo.') }}</p>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($pubs as $i => $pub)
                    @php $ev = \App\Services\ProductosService::url($c, $pub['evidencia']); $ex = $pub['extra']; @endphp
                    <div class="p-4">
                        <div class="flex flex-wrap items-start gap-4">
                            <div class="w-28 shrink-0">@if ($ev)<a href="{{ $ev }}" target="_blank"><img src="{{ $ev }}" alt="" class="h-20 w-28 object-cover rounded-lg border border-gray-100"></a>@else<div class="h-20 w-28 rounded-lg border border-dashed border-gray-200 bg-gray-50 flex items-center justify-center text-[10px] text-gray-400 text-center px-1">{{ __('Sin captura') }}</div>@endif</div>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-gray-900">{{ $i + 1 }}. {{ $pub['medio'] }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $pub['origen'] === 'editus' ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">{{ $pub['origen'] === 'editus' ? 'editus' : __('registrada') }}</span>
                                    @if ($pub['fecha'])<span class="text-[11px] text-gray-400">{{ \Carbon\Carbon::parse($pub['fecha'])->format('d/m/Y') }}</span>@endif
                                </div>
                                @if ($pub['url'])<a href="{{ $pub['url'] }}" target="_blank" rel="noopener" class="block text-xs text-indigo-700 hover:underline truncate">{{ $pub['url'] }}</a>@endif
                                @unless ($esWeb)
                                    <div class="mt-1 flex flex-wrap gap-4 text-xs text-gray-600 tabular-nums">
                                        <span>{{ __('Alcance') }}: <b>{{ $fmt($pub['alcance']) }}</b></span><span>{{ __('Visualización') }}: <b>{{ $fmt($pub['visualizaciones']) }}</b></span><span>{{ __('Interacción') }}: <b>{{ $fmt($pub['interacciones']) }}</b></span>
                                    </div>
                                @endunless
                            </div>
                        </div>
                        <details class="mt-2">
                            <summary class="text-xs font-semibold text-indigo-700 cursor-pointer">{{ __('Subir captura o corregir datos') }}</summary>
                            <form method="POST" enctype="multipart/form-data" class="mt-2 grid sm:grid-cols-6 gap-2 items-end"
                                  action="{{ $pub['origen'] === 'manual' ? route('campaigns.productos.publicaciones.actualizar', [$c, $p, $ex]) : route('campaigns.productos.publicaciones.guardar', [$c, $p]) }}">
                                @csrf
                                @if ($pub['origen'] === 'editus')<input type="hidden" name="meta_post_id" value="{{ $pub['post']->id }}">@else
                                    <div class="sm:col-span-2"><label class="block text-[10px] text-gray-500">{{ __('Medio / página web') }}</label><input name="medio" value="{{ $ex->medio }}" maxlength="190" class="{{ $chico }}"></div>
                                    <div><label class="block text-[10px] text-gray-500">{{ __('Fecha') }}</label><input type="date" name="fecha" value="{{ $ex->fecha?->toDateString() }}" class="{{ $chico }}"></div>
                                @endif
                                <div class="sm:col-span-3"><label class="block text-[10px] text-gray-500">URL</label><input type="url" name="url" value="{{ $ex?->url ?? '' }}" placeholder="{{ $pub['origen'] === 'editus' ? $pub['url'] : 'https://' }}" class="{{ $chico }}"></div>
                                @unless ($esWeb)
                                    <div><label class="block text-[10px] text-gray-500">{{ __('Alcance') }}</label><input type="number" min="0" name="alcance" value="{{ $ex?->alcance }}" placeholder="{{ $pub['alcance'] }}" class="{{ $chico }}"></div>
                                    <div><label class="block text-[10px] text-gray-500">{{ __('Visualización') }}</label><input type="number" min="0" name="visualizaciones" value="{{ $ex?->visualizaciones }}" placeholder="{{ $pub['visualizaciones'] }}" class="{{ $chico }}"></div>
                                    <div><label class="block text-[10px] text-gray-500">{{ __('Interacción') }}</label><input type="number" min="0" name="interacciones" value="{{ $ex?->interacciones }}" placeholder="{{ $pub['interacciones'] }}" class="{{ $chico }}"></div>
                                @endunless
                                <div class="sm:col-span-2"><label class="block text-[10px] text-gray-500">{{ __('Captura de evidencia') }}</label><input type="file" name="evidencia" accept="image/*" class="text-xs"></div>
                                @if ($ex?->evidencia_path)<label class="flex items-center gap-1 text-[11px] text-rose-600"><input type="checkbox" name="quitar_evidencia" value="1" class="h-3.5 w-3.5 rounded"> {{ __('Quitar captura') }}</label>@endif
                                <div class="flex gap-2 sm:col-span-6 justify-end">
                                    <button class="h-9 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">{{ __('Guardar') }}</button>
                                </div>
                            </form>
                            <div class="flex justify-end gap-3 mt-1">
                                @if ($pub['origen'] === 'manual')
                                    <form method="POST" action="{{ route('campaigns.productos.publicaciones.borrar', [$c, $p, $ex]) }}" onsubmit="return confirm(@js(__('¿Quitar esta publicación?')))">@csrf @method('DELETE')<button class="text-[11px] text-rose-600 hover:underline">{{ __('Quitar publicación') }}</button></form>
                                @else
                                    <form method="POST" action="{{ route('campaigns.productos.vincular', [$c, $p]) }}" onsubmit="return confirm(@js(__('¿Desvincular esta publicación de la pieza?')))">@csrf<input type="hidden" name="quitar" value="{{ $pub['post']->id }}"><button class="text-[11px] text-rose-600 hover:underline">{{ __('Desvincular de la pieza') }}</button></form>
                                @endif
                            </div>
                        </details>
                    </div>
                @empty
                    <p class="p-6 text-center text-sm text-gray-500">{{ __('Aún no tiene publicaciones. Publica la pieza desde Mis páginas eligiéndola en la campaña, vincula publicaciones ya hechas o regístralas abajo.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-6 mt-6">
            {{-- Registrar publicación fuera de editus --}}
            <form method="POST" action="{{ route('campaigns.productos.publicaciones.guardar', [$c, $p]) }}" enctype="multipart/form-data" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 space-y-2">
                @csrf
                <h3 class="text-sm font-bold text-gray-900">{{ $esWeb ? __('Agregar página web') : __('Registrar publicación en otro medio') }}</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="col-span-2"><label class="block text-[10px] text-gray-500">{{ __('Medio o página web') }} *</label>
                        <input name="medio" list="dlMedios" maxlength="190" placeholder="{{ $esWeb ? __('Ej: Neiva al Día') : __('Ej: Canal 4') }}" class="{{ $chico }}">
                        <datalist id="dlMedios">@foreach ($paginas as $pg)<option value="{{ $pg->name }}">@endforeach @foreach (config('services.editus.medios', []) as $m)<option value="{{ $m }}">@endforeach</datalist></div>
                    <div class="col-span-2"><label class="block text-[10px] text-gray-500">URL</label><input type="url" name="url" placeholder="https://" class="{{ $chico }}"></div>
                    <div><label class="block text-[10px] text-gray-500">{{ __('Fecha') }}</label><input type="date" name="fecha" class="{{ $chico }}"></div>
                    @unless ($esWeb)
                        <div><label class="block text-[10px] text-gray-500">{{ __('Alcance') }}</label><input type="number" min="0" name="alcance" class="{{ $chico }}"></div>
                        <div><label class="block text-[10px] text-gray-500">{{ __('Visualización') }}</label><input type="number" min="0" name="visualizaciones" class="{{ $chico }}"></div>
                        <div><label class="block text-[10px] text-gray-500">{{ __('Interacción') }}</label><input type="number" min="0" name="interacciones" class="{{ $chico }}"></div>
                    @endunless
                    <div class="col-span-2"><label class="block text-[10px] text-gray-500">{{ __('Captura de evidencia') }}</label><input type="file" name="evidencia" accept="image/*" class="text-xs"></div>
                </div>
                <div class="flex justify-end"><button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold">{{ __('Agregar') }}</button></div>
            </form>

            {{-- Vincular publicaciones de editus ya hechas --}}
            <form method="POST" action="{{ route('campaigns.productos.vincular', [$c, $p]) }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4">
                @csrf
                <h3 class="text-sm font-bold text-gray-900">{{ __('Vincular publicaciones de editus') }}</h3>
                <p class="text-[11px] text-gray-500 mb-2">{{ __('Publicaciones de la campaña que aún no tienen pieza.') }}</p>
                <input type="search" placeholder="{{ __('Buscar por página o texto') }}" class="{{ $chico }} mb-2" oninput="const q=this.value.toLowerCase(); this.form.querySelectorAll('[data-t]').forEach(e=>e.style.display=!q||e.dataset.t.includes(q)?'':'none')">
                <div class="max-h-64 overflow-y-auto space-y-1">
                    @forelse ($sinPieza as $x)
                        <label class="flex items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 text-xs" data-t="{{ mb_strtolower(($x->page?->name ?? '') . ' ' . $x->message) }}">
                            <input type="checkbox" name="posts[]" value="{{ $x->id }}" class="mt-0.5 h-3.5 w-3.5 rounded border-gray-300 text-indigo-600">
                            <span class="min-w-0"><span class="font-semibold text-gray-800">{{ $x->page?->name ?? '—' }}</span> <span class="text-gray-400">· {{ $x->published_at?->format('d/m/Y') }}{{ $x->network === 'instagram' ? ' · IG' : '' }}</span>
                                <span class="block text-gray-500 truncate">{{ \Illuminate\Support\Str::limit((string) $x->message, 90) }}</span></span>
                        </label>
                    @empty
                        <p class="text-xs text-gray-400">{{ __('No hay publicaciones sin pieza.') }}</p>
                    @endforelse
                </div>
                @if ($sinPieza->isNotEmpty())<div class="flex justify-end mt-2"><button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold">{{ __('Vincular seleccionadas') }}</button></div>@endif
            </form>
        </div>
    @endif
</div>
<script>
(function () {
    const mapa = @json($mapaSubprog);
    const sel = document.getElementById('progSel'), dl = document.getElementById('dlSubprog');
    const llenar = () => { dl.innerHTML = ''; (mapa[sel.value] || []).forEach(v => { const o = document.createElement('option'); o.value = v; dl.appendChild(o); }); };
    sel.addEventListener('change', llenar); llenar();
    // Al elegir la subcampaña: solo sus tipos de publicación y, si están vacíos, su programa y subprograma
    const porSub = @json($tiposPorSub), progSub = @json($progPorSub);
    const subSel = document.getElementById('subSel'), tipoSel = document.getElementById('tipoSel');
    const subInp = document.querySelector('input[name="subprograma"]');
    const filtrar = () => {
        const ids = (porSub[subSel.value] || []).map(String);
        Array.from(tipoSel.options).forEach(o => { if (o.value) o.hidden = ids.length > 0 && !ids.includes(o.value); });
        if (tipoSel.selectedOptions[0]?.hidden) tipoSel.value = '';
        if (ids.length === 1 && !tipoSel.value) tipoSel.value = ids[0];
    };
    subSel.addEventListener('change', () => {
        filtrar();
        const d = progSub[subSel.value];
        if (d && d.programa && !sel.value) { sel.value = d.programa; llenar(); }
        if (d && d.subprograma && !subInp.value) subInp.value = d.subprograma;
    });
    filtrar();
})();
</script>
@endsection
