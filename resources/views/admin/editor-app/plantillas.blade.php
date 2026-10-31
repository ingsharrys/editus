@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6 mt-10">
            <div class="text-[#00024f]">
                <h1 class="text-3xl font-bold">{{ __('Plantillas de imagen') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('Plantillas con las que la app compone las piezas de Redes (logo, etiqueta, título, pie y hashtag).') }}</p>
            </div>
            <a href="{{ route('editor-app.index') }}" class="text-sm text-indigo-700 hover:underline whitespace-nowrap">← {{ __('App del editor') }}</a>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 text-red-800 p-3 mb-4 text-sm">
                <ul class="list-disc ml-5 space-y-1">
                    @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-4 text-sm">{{ session('success') }}</div>
        @endif

        {{-- ===================== PLANTILLAS ===================== --}}
        <div class="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-6">

            {{-- Lista --}}
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-md">
                <h2 class="text-xl font-bold text-gray-800">{{ __('Plantillas de imagen') }}</h2>
                <p class="text-sm text-gray-500 mb-4">
                    {!! __('La app siempre incluye la plantilla del sistema <strong>Opa Noticias</strong> (logo en cajas, etiqueta roja, título grande). Aquí puedes crear variantes con otro logo, otros colores o textos. En la app el periodista elige la plantilla y puede cambiar el color del título y del logo antes de publicar.') !!}
                </p>

                @if ($plantillas->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Todavía no has creado plantillas.') }}</p>
                @else
                    <div class="space-y-3">
                        @foreach ($plantillas as $t)
                            <div class="flex items-center gap-4 rounded-2xl border border-gray-100 p-3 {{ $t->activa ? '' : 'opacity-60' }}">
                                <div class="h-14 w-24 shrink-0 rounded-xl flex items-center justify-center overflow-hidden" style="background:#1f2937">
                                    @if ($t->logoUrl())
                                        <img src="{{ $t->logoUrl() }}" alt="" class="max-h-12 max-w-[88px] object-contain">
                                    @else
                                        <span class="text-white font-black text-sm tracking-wide">{{ $t->logo_texto ?: '—' }}</span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-semibold text-gray-800">{{ $t->nombre }}</span>
                                        @if ($t->predeterminada)
                                            <span class="rounded-full bg-indigo-50 text-indigo-700 px-2 py-0.5 text-xs font-semibold">{{ __('Predeterminada') }}</span>
                                        @endif
                                        @unless ($t->activa)
                                            <span class="rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-xs font-semibold">{{ __('Inactiva') }}</span>
                                        @endunless
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1 flex items-center gap-3 flex-wrap">
                                        <span>{!! __('Etiqueta: <strong>:etiqueta</strong>', ['etiqueta' => e($t->etiqueta ?: '—')]) !!}</span>
                                        <span>{{ __('Pie: :pie', ['pie' => $t->pie ?: '—']) }}</span>
                                        <span>{{ $t->hashtag }}</span>
                                        <span class="inline-flex items-center gap-1">{{ __('Título') }} <i class="inline-block h-3 w-3 rounded-full border" style="background:{{ $t->color_titulo }}"></i></span>
                                        <span class="inline-flex items-center gap-1">{{ __('Logo') }} <i class="inline-block h-3 w-3 rounded-full border" style="background:{{ $t->color_logo }}"></i></span>
                                        <span class="inline-flex items-center gap-1">{{ __('Etiqueta') }} <i class="inline-block h-3 w-3 rounded-full border" style="background:{{ $t->color_etiqueta }}"></i></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <a href="{{ route('editor-app.plantillas.index', ['editar' => $t->id]) }}#plantilla-form"
                                       class="text-sm px-3 py-2 rounded-xl border hover:bg-gray-50 transition">{{ __('Editar') }}</a>
                                    <form method="POST" action="{{ route('editor-app.plantillas.destroy', $t) }}"
                                          onsubmit="return confirm(@js(__('¿Eliminar la plantilla «:plantilla»?', ['plantilla' => $t->nombre])));">
                                        @csrf @method('DELETE')
                                        <button class="text-sm px-3 py-2 rounded-xl border border-red-200 text-red-700 hover:bg-red-50 transition">{{ __('Eliminar') }}</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Formulario --}}
            <div id="plantilla-form" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-md">
                @php $t = $editar; @endphp
                <h2 class="text-xl font-bold text-gray-800">{{ $t ? __('Editar plantilla') : __('Nueva plantilla') }}</h2>
                @if ($t)
                    <a href="{{ route('editor-app.plantillas.index') }}" class="text-xs text-indigo-600 underline">{{ __('Cancelar edición') }}</a>
                @endif

                <form method="POST" enctype="multipart/form-data" class="mt-4 space-y-4" x-data="plantillaMarco()" x-init="iniciar()"
                      action="{{ $t ? route('editor-app.plantillas.update', $t) : route('editor-app.plantillas.store') }}">
                    @csrf
                    @if ($t) @method('PUT') @endif

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Nombre') }}</label>
                        <input type="text" name="nombre" required maxlength="120" value="{{ old('nombre', $t->nombre ?? '') }}"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500"
                               placeholder="{{ __('Opa Noticias · Judicial') }}">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Tipo') }}</label>
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <label class="rounded-xl border px-3 py-2 cursor-pointer" :class="tipo === 'diseno' ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'">
                                <input type="radio" name="tipo" value="diseno" x-model="tipo" class="mr-1"> {{ __('Diseño de editus') }}
                                <span class="block text-[11px] text-gray-500">{{ __('Logo, colores y textos') }}</span></label>
                            <label class="rounded-xl border px-3 py-2 cursor-pointer" :class="tipo === 'marco' ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'">
                                <input type="radio" name="tipo" value="marco" x-model="tipo" class="mr-1"> {{ __('Marco de Canva') }}
                                <span class="block text-[11px] text-gray-500">{{ __('PNG con el diseño; la foto va debajo') }}</span></label>
                        </div>
                    </div>

                    {{-- ======= Marco de Canva ======= --}}
                    <div x-show="tipo === 'marco'" class="space-y-4 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-3" style="display:none">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Medio') }}</label>
                            <select name="medio_slug" class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white text-sm">
                                <option value="">{{ __('— Para cualquier medio —') }}</option>
                                @foreach ($medios as $slug => $nombreMedio)<option value="{{ $slug }}" @selected(old('medio_slug', $t->medio_slug ?? '') === $slug)>{{ $nombreMedio }}</option>@endforeach
                            </select>
                            <p class="text-[11px] text-gray-500 mt-1">{{ __('Al publicar, cada medio usa su propio marco (el de menor orden si tiene varios). Se puede cambiar por medio antes de publicar.') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Marco (PNG con fondo transparente, 1080 × 1350)') }}</label>
                            <input type="file" name="marco" accept="image/png" class="w-full text-sm" @change="leerMarco($event.target.files[0])">
                            @if ($t && $t->marcoUrl())<p class="text-[11px] text-emerald-700 mt-1">✓ {{ __('Ya tiene marco. Sube otro solo si lo quieres cambiar.') }}</p>@endif
                            <details class="mt-2 text-xs text-gray-600">
                                <summary class="cursor-pointer font-semibold text-indigo-700">{{ __('¿Canva gratis? Sube la versión de fondo blanco y la de fondo negro') }}</summary>
                                <p class="mt-1">{{ __('Canva gratis no exporta con fondo transparente. Quita la foto y los textos de ejemplo, descarga el diseño como PNG con fondo blanco, ponle un fondo negro y descárgalo otra vez: editus calcula la transparencia comparando las dos.') }}</p>
                                <div class="grid grid-cols-2 gap-2 mt-2">
                                    <label>{{ __('Fondo blanco') }}<input type="file" name="marco_blanco" accept="image/png,image/jpeg" class="block w-full text-xs" @change="leerPar('blanco', $event.target.files[0])"></label>
                                    <label>{{ __('Fondo negro') }}<input type="file" name="marco_negro" accept="image/png,image/jpeg" class="block w-full text-xs" @change="leerPar('negro', $event.target.files[0])"></label>
                                </div>
                            </details>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Dónde van la foto y los textos') }}</label>
                            <select class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white text-sm" @change="aplicarPreset($event.target.value); $event.target.value = ''">
                                <option value="">{{ __('Usar las medidas de un diseño…') }}</option>
                                @foreach ($presets as $clave => $pr)<option value="{{ $clave }}">{{ $pr['nombre'] }}</option>@endforeach
                            </select>
                            <p class="text-[11px] text-gray-500 mt-1">{{ __('Arrastra los recuadros en la vista previa o ajusta los números. Medidas en píxeles del lienzo de 1080 × 1350.') }}</p>
                        </div>

                        {{-- Vista previa --}}
                        <div class="relative mx-auto w-full max-w-[320px] aspect-[4/5] rounded-xl overflow-hidden bg-gray-900 select-none" x-ref="caja">
                            <canvas x-ref="lienzo" width="1080" height="1350" class="absolute inset-0 h-full w-full"></canvas>
                            <template x-for="k in ['foto', 'etiqueta', 'titulo']" :key="k">
                                <div x-show="z[k]" class="absolute border-2 border-dashed cursor-move" :class="{'border-sky-300': k === 'foto', 'border-amber-300': k === 'etiqueta', 'border-emerald-300': k === 'titulo'}"
                                     :style="z[k] ? `left:${z[k].x / 10.8}%;top:${z[k].y / 13.5}%;width:${z[k].w / 10.8}%;height:${z[k].h / 13.5}%` : ''" @pointerdown.prevent="arrastrar($event, k)">
                                    <span class="absolute -top-0.5 left-0.5 text-[9px] font-bold text-white drop-shadow" x-text="nombres[k]"></span>
                                    <span class="absolute bottom-0 right-0 h-3 w-3 bg-white/80 cursor-se-resize" @pointerdown.prevent.stop="arrastrar($event, k, true)"></span>
                                </div>
                            </template>
                        </div>

                        <template x-for="k in ['foto', 'etiqueta', 'titulo']" :key="'c' + k">
                            <div class="rounded-xl border border-gray-200 bg-white p-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-gray-800" x-text="nombres[k]"></span>
                                    <label x-show="k !== 'foto'" class="text-[11px] text-gray-600"><input type="checkbox" :checked="!!z[k]" @change="alternar(k, $event.target.checked)" class="h-3.5 w-3.5 rounded"> {{ __('Usar') }}</label>
                                </div>
                                <template x-if="z[k]">
                                    <div class="mt-1 grid grid-cols-4 gap-1 text-[11px]">
                                        <template x-for="c in ['x', 'y', 'w', 'h']" :key="c"><label><span x-text="c.toUpperCase()"></span><input type="number" min="0" max="1350" x-model.number="z[k][c]" @input="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"></label></template>
                                        <template x-if="k !== 'foto'">
                                            <label class="col-span-2">{{ __('Fuente') }}<select x-model="z[k].fuente" @change="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs">@foreach ($fuentes as $fc => $fn)<option value="{{ $fc }}">{{ $fn }}</option>@endforeach</select></label>
                                        </template>
                                        <template x-if="k !== 'foto'"><label>{{ __('Color') }}<input type="color" x-model="z[k].color" @input="dibujar()" class="h-6 w-full rounded border-gray-200"></label></template>
                                        <template x-if="k !== 'foto'"><label>{{ __('Alinear') }}<select x-model="z[k].alinear" @change="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"><option value="izquierda">{{ __('Izq.') }}</option><option value="centro">{{ __('Centro') }}</option></select></label></template>
                                        <template x-if="k === 'etiqueta'"><label>{{ __('Tamaño') }}<input type="number" min="10" max="120" x-model.number="z[k].tam" @input="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"></label></template>
                                        <template x-if="k === 'etiqueta'"><label class="col-span-3">{{ __('Caja de color (si el marco no la trae)') }} <input type="checkbox" :checked="!!z[k].fondo" @change="z[k].fondo = $event.target.checked ? '#AB1010' : null; dibujar()"> <input type="color" x-show="z[k].fondo" x-model="z[k].fondo" @input="dibujar()" class="h-5 w-10 align-middle"></label></template>
                                        <template x-if="k === 'titulo'"><label>{{ __('Máx.') }}<input type="number" min="12" max="160" x-model.number="z[k].tam_max" @input="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"></label></template>
                                        <template x-if="k === 'titulo'"><label>{{ __('Mín.') }}<input type="number" min="10" max="160" x-model.number="z[k].tam_min" @input="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"></label></template>
                                        <template x-if="k === 'titulo'"><label>{{ __('Líneas') }}<input type="number" min="1" max="6" x-model.number="z[k].max_lineas" @input="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"></label></template>
                                        <template x-if="k === 'titulo'"><label>{{ __('Interlín.') }}<input type="number" min="0.8" max="1.6" step="0.02" x-model.number="z[k].interlineado" @input="dibujar()" class="w-full rounded border-gray-200 px-1 py-0.5 text-xs"></label></template>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <input type="hidden" name="zonas" :value="JSON.stringify(z)">
                    </div>

                    <div x-show="tipo === 'diseno'" class="space-y-4">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Logo (PNG con fondo transparente)') }}</label>
                        <input type="file" name="logo" accept="image/png,image/webp" class="w-full text-sm">
                        @if ($t && $t->logoUrl())
                            <div class="mt-2 flex items-center gap-3">
                                <img src="{{ $t->logoUrl() }}" alt="" class="h-10 rounded bg-gray-800 p-1">
                                <label class="text-xs text-gray-600 inline-flex items-center gap-1">
                                    <input type="checkbox" name="quitar_logo" value="1" class="h-4 w-4 rounded border-gray-300"> {{ __('Quitar logo') }}
                                </label>
                            </div>
                        @endif
                        <label class="mt-2 text-xs text-gray-600 inline-flex items-center gap-1">
                            <input type="checkbox" name="logo_tintar" value="1" class="h-4 w-4 rounded border-gray-300" {{ old('logo_tintar', $t->logo_tintar ?? true) ? 'checked' : '' }}>
                            {{ __('Pintar el logo con el color elegido en la app') }}
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Logo en texto (si no subes PNG)') }}</label>
                        <input type="text" name="logo_texto" maxlength="60" value="{{ old('logo_texto', $t->logo_texto ?? 'OPA Noticias') }}"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                        <p class="text-xs text-gray-500 mt-1">{{ __('La primera palabra va letra por letra en cajas de color (O·P·A) y el resto al lado.') }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Etiqueta por defecto') }}</label>
                            <input type="text" name="etiqueta" maxlength="40" value="{{ old('etiqueta', $t->etiqueta ?? 'NOTICIAS') }}"
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Hashtag') }}</label>
                            <input type="text" name="hashtag" maxlength="60" value="{{ old('hashtag', $t->hashtag ?? '#EsNoticia') }}"
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">{{ __('Pie (sitio web)') }}</label>
                        <input type="text" name="pie" maxlength="80" value="{{ old('pie', $t->pie ?? 'Opanoticias.com') }}"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        @foreach (['color_titulo' => [__('Título'), '#FFFFFF'], 'color_logo' => [__('Logo'), '#FFFFFF'], 'color_etiqueta' => [__('Etiqueta'), '#C8102E']] as $campo => [$label, $def])
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">{{ $label }}</label>
                                <input type="color" name="{{ $campo }}" value="{{ old($campo, $t->$campo ?? $def) }}"
                                       class="h-10 w-full rounded-xl border border-gray-300 bg-white p-1">
                            </div>
                        @endforeach
                    </div>

                    </div>{{-- /diseño --}}

                    <div class="flex items-center gap-5 text-sm text-gray-700">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="predeterminada" value="1" class="h-4 w-4 rounded border-gray-300" {{ old('predeterminada', $t->predeterminada ?? false) ? 'checked' : '' }}>
                            {{ __('Predeterminada en la app') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="activa" value="1" class="h-4 w-4 rounded border-gray-300" {{ old('activa', $t->activa ?? true) ? 'checked' : '' }}>
                            {{ __('Activa') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            {{ __('Orden') }}
                            <input type="number" name="orden" min="0" max="999" value="{{ old('orden', $t->orden ?? 0) }}"
                                   class="w-20 border border-gray-300 rounded-xl px-2 py-1 bg-white">
                        </label>
                    </div>

                    <button type="submit" class="w-full bg-indigo-600 text-white px-4 py-2.5 rounded-xl hover:bg-indigo-700 transition font-semibold">
                        {{ $t ? __('Guardar cambios') : __('Crear plantilla') }}
                    </button>
                </form>
            </div>
        </div>


        <p class="text-xs text-gray-400 mt-6">{!! __('La app las lee por <code>/api/plantillas</code>. Si subes logos, el servidor necesita el enlace <code>php artisan storage:link</code>.') !!}</p>
    </div>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bree+Serif&family=Montserrat:wght@800;900&family=Playfair+Display:wght@800&family=Inter:wght@700;900&display=swap">
<script>
function plantillaMarco() {
    const PRESETS = @js(collect($presets)->map(fn($p) => $p['zonas']));
    const FUENTES = {bree: '400 {t}px "Bree Serif"', 'montserrat-black': '900 {t}px Montserrat', 'montserrat-extrabold': '800 {t}px Montserrat', playfair: '800 {t}px "Playfair Display"', 'inter-black': '900 {t}px Inter', 'inter-bold': '700 {t}px Inter'};
    const copia = (o) => JSON.parse(JSON.stringify(o));
    return {
        tipo: @js(old('tipo', $editar->tipo ?? 'diseno') === 'marco' ? 'marco' : 'diseno'),
        z: copia(@js(old('zonas') ? json_decode(old('zonas'), true) : ($editar?->zonas ?: null)) || PRESETS['opa-titulo']),
        nombres: {foto: @js(__('Foto')), etiqueta: @js(__('Categoría')), titulo: @js(__('Título'))},
        marco: null, par: {blanco: null, negro: null},
        iniciar() {
            const url = @js($editar?->marcoUrl());
            if (url) { const im = new Image(); im.crossOrigin = 'anonymous'; im.onload = () => { this.marco = im; this.dibujar(); }; im.src = url; }
            (document.fonts?.ready || Promise.resolve()).then(() => this.dibujar());
            this.$watch('tipo', () => this.$nextTick(() => this.dibujar()));
        },
        aplicarPreset(k) { if (PRESETS[k]) { this.z = copia(PRESETS[k]); this.dibujar(); } },
        alternar(k, si) {
            const base = PRESETS['opa-titulo'][k];
            this.z[k] = si ? copia(base) : null; this.dibujar();
        },
        leerMarco(f) { if (!f) return; const im = new Image(); im.onload = () => { this.marco = im; this.dibujar(); }; im.src = URL.createObjectURL(f); },
        leerPar(cual, f) {
            if (!f) return;
            const im = new Image();
            im.onload = () => { this.par[cual] = im; if (this.par.blanco && this.par.negro) this.combinar(); };
            im.src = URL.createObjectURL(f);
        },
        combinar() {
            const {blanco, negro} = this.par; const W = blanco.width, H = blanco.height;
            const c = document.createElement('canvas'); c.width = W; c.height = H; const x = c.getContext('2d');
            x.drawImage(blanco, 0, 0); const b = x.getImageData(0, 0, W, H);
            x.clearRect(0, 0, W, H); x.drawImage(negro, 0, 0, W, H); const n = x.getImageData(0, 0, W, H);
            const o = x.createImageData(W, H);
            for (let i = 0; i < o.data.length; i += 4) {
                const a = Math.max(0, Math.min(1, 1 - ((b.data[i] - n.data[i]) + (b.data[i + 1] - n.data[i + 1]) + (b.data[i + 2] - n.data[i + 2])) / 765));
                o.data[i] = a ? Math.min(255, n.data[i] / a) : 0; o.data[i + 1] = a ? Math.min(255, n.data[i + 1] / a) : 0; o.data[i + 2] = a ? Math.min(255, n.data[i + 2] / a) : 0; o.data[i + 3] = Math.round(a * 255);
            }
            x.putImageData(o, 0, 0);
            const im = new Image(); im.onload = () => { this.marco = im; this.dibujar(); }; im.src = c.toDataURL('image/png');
        },
        arrastrar(ev, k, redimensionar = false) {
            const caja = this.$refs.caja.getBoundingClientRect(); const esc = 1080 / caja.width;
            const z0 = {...this.z[k]}; const x0 = ev.clientX, y0 = ev.clientY;
            const mover = (e) => {
                const dx = Math.round((e.clientX - x0) * esc), dy = Math.round((e.clientY - y0) * esc);
                if (redimensionar) { this.z[k].w = Math.max(10, z0.w + dx); this.z[k].h = Math.max(10, z0.h + dy); }
                else { this.z[k].x = Math.max(0, Math.min(1080 - z0.w, z0.x + dx)); this.z[k].y = Math.max(0, Math.min(1350 - z0.h, z0.y + dy)); }
                this.dibujar();
            };
            const soltar = () => { window.removeEventListener('pointermove', mover); window.removeEventListener('pointerup', soltar); };
            window.addEventListener('pointermove', mover); window.addEventListener('pointerup', soltar);
        },
        dibujar() {
            const cv = this.$refs.lienzo; if (!cv) return; const x = cv.getContext('2d');
            x.fillStyle = '#000'; x.fillRect(0, 0, 1080, 1350);
            const f = this.z.foto || {x: 0, y: 0, w: 1080, h: 1350};
            const g = x.createLinearGradient(0, f.y, 0, f.y + f.h); g.addColorStop(0, '#4b6584'); g.addColorStop(1, '#8a6d5a');
            x.fillStyle = g; x.fillRect(f.x, f.y, f.w, f.h);
            x.fillStyle = 'rgba(230,190,160,.9)'; x.beginPath(); x.ellipse(f.x + f.w / 2, f.y + f.h * 0.38, f.w * 0.2, f.h * 0.25, 0, 0, 7); x.fill();
            if (this.marco) x.drawImage(this.marco, 0, 0, 1080, 1350);
            const fuente = (k, t) => (FUENTES[k] || FUENTES.bree).replace('{t}', t);
            const e = this.z.etiqueta;
            if (e) {
                const txt = 'NACIONAL'; let t = e.tam || 40; const pad = Math.round(e.h * 0.35); x.letterSpacing = (e.espaciado ?? 4) + 'px';
                x.font = fuente(e.fuente, t); while (t > 12 && x.measureText(txt).width > e.w - 2 * pad) { t--; x.font = fuente(e.fuente, t); }
                const ancho = x.measureText(txt).width; let tx;
                if (e.fondo) { const wc = Math.min(e.w, ancho + 2 * pad); x.fillStyle = e.fondo; x.fillRect(e.x, e.y, wc, e.h); tx = e.x + (wc - ancho) / 2; }
                else tx = e.alinear === 'izquierda' ? e.x + pad : e.x + (e.w - ancho) / 2;
                x.fillStyle = e.color || '#fff'; x.textBaseline = 'middle'; x.fillText(txt, tx, e.y + e.h / 2 + t * 0.04); x.letterSpacing = '0px';
            }
            const ti = this.z.titulo;
            if (ti) {
                const titulo = @js(__('Así se verá el título de tu publicación con este marco'));
                let t = ti.tam_max || 60, lineas = [];
                const envolver = () => { const out = []; let act = ''; titulo.split(' ').forEach(p => { const pr = act ? act + ' ' + p : p; if (x.measureText(pr).width <= ti.w) act = pr; else { if (act) out.push(act); act = p; } }); if (act) out.push(act); return out; };
                for (; t >= (ti.tam_min || 36); t -= 2) { x.font = fuente(ti.fuente, t); lineas = envolver(); if (lineas.length <= (ti.max_lineas || 3) && lineas.length * t * (ti.interlineado || 1.05) <= ti.h + t * 0.2) break; }
                lineas = lineas.slice(0, ti.max_lineas || 3);
                x.fillStyle = ti.color || '#fff'; x.textBaseline = 'alphabetic';
                let y = ti.y + t * 0.92;
                lineas.forEach(l => { const w = x.measureText(l).width; x.fillText(l, ti.alinear === 'centro' ? ti.x + (ti.w - w) / 2 : ti.x, y); y += t * (ti.interlineado || 1.05); });
            }
        },
    };
}
</script>
@endsection
