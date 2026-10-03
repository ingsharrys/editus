@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6 mt-10">
            <div class="text-[#00024f]">
                <h1 class="text-3xl font-bold">Plantillas de imagen</h1>
                <p class="mt-1 text-sm text-gray-600">Plantillas con las que la app compone las piezas de Redes (logo, etiqueta, título, pie y hashtag).</p>
            </div>
            <a href="{{ route('editor-app.index') }}" class="text-sm text-indigo-700 hover:underline whitespace-nowrap">← App del editor</a>
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
                <h2 class="text-xl font-bold text-gray-800">Plantillas de imagen</h2>
                <p class="text-sm text-gray-500 mb-4">
                    La app siempre incluye la plantilla del sistema <strong>Opa Noticias</strong> (logo en cajas, etiqueta roja, título grande).
                    Aquí puedes crear variantes con otro logo, otros colores o textos. En la app el periodista elige la plantilla y
                    puede cambiar el color del título y del logo antes de publicar.
                </p>

                @if ($plantillas->isEmpty())
                    <p class="text-sm text-gray-500">Todavía no has creado plantillas.</p>
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
                                            <span class="rounded-full bg-indigo-50 text-indigo-700 px-2 py-0.5 text-xs font-semibold">Predeterminada</span>
                                        @endif
                                        @unless ($t->activa)
                                            <span class="rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-xs font-semibold">Inactiva</span>
                                        @endunless
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1 flex items-center gap-3 flex-wrap">
                                        <span>Etiqueta: <strong>{{ $t->etiqueta ?: '—' }}</strong></span>
                                        <span>Pie: {{ $t->pie ?: '—' }}</span>
                                        <span>{{ $t->hashtag }}</span>
                                        <span class="inline-flex items-center gap-1">Título <i class="inline-block h-3 w-3 rounded-full border" style="background:{{ $t->color_titulo }}"></i></span>
                                        <span class="inline-flex items-center gap-1">Logo <i class="inline-block h-3 w-3 rounded-full border" style="background:{{ $t->color_logo }}"></i></span>
                                        <span class="inline-flex items-center gap-1">Etiqueta <i class="inline-block h-3 w-3 rounded-full border" style="background:{{ $t->color_etiqueta }}"></i></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <a href="{{ route('editor-app.plantillas.index', ['editar' => $t->id]) }}#plantilla-form"
                                       class="text-sm px-3 py-2 rounded-xl border hover:bg-gray-50 transition">Editar</a>
                                    <form method="POST" action="{{ route('editor-app.plantillas.destroy', $t) }}"
                                          onsubmit="return confirm('¿Eliminar la plantilla «{{ $t->nombre }}»?');">
                                        @csrf @method('DELETE')
                                        <button class="text-sm px-3 py-2 rounded-xl border border-red-200 text-red-700 hover:bg-red-50 transition">Eliminar</button>
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
                <h2 class="text-xl font-bold text-gray-800">{{ $t ? 'Editar plantilla' : 'Nueva plantilla' }}</h2>
                @if ($t)
                    <a href="{{ route('editor-app.plantillas.index') }}" class="text-xs text-indigo-600 underline">Cancelar edición</a>
                @endif

                <form method="POST" enctype="multipart/form-data" class="mt-4 space-y-4"
                      action="{{ $t ? route('editor-app.plantillas.update', $t) : route('editor-app.plantillas.store') }}">
                    @csrf
                    @if ($t) @method('PUT') @endif

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nombre</label>
                        <input type="text" name="nombre" required maxlength="120" value="{{ old('nombre', $t->nombre ?? '') }}"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500"
                               placeholder="Opa Noticias · Judicial">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Logo (PNG con fondo transparente)</label>
                        <input type="file" name="logo" accept="image/png,image/webp" class="w-full text-sm">
                        @if ($t && $t->logoUrl())
                            <div class="mt-2 flex items-center gap-3">
                                <img src="{{ $t->logoUrl() }}" alt="" class="h-10 rounded bg-gray-800 p-1">
                                <label class="text-xs text-gray-600 inline-flex items-center gap-1">
                                    <input type="checkbox" name="quitar_logo" value="1" class="h-4 w-4 rounded border-gray-300"> Quitar logo
                                </label>
                            </div>
                        @endif
                        <label class="mt-2 text-xs text-gray-600 inline-flex items-center gap-1">
                            <input type="checkbox" name="logo_tintar" value="1" class="h-4 w-4 rounded border-gray-300" {{ old('logo_tintar', $t->logo_tintar ?? true) ? 'checked' : '' }}>
                            Pintar el logo con el color elegido en la app
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Logo en texto (si no subes PNG)</label>
                        <input type="text" name="logo_texto" maxlength="60" value="{{ old('logo_texto', $t->logo_texto ?? 'OPA Noticias') }}"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                        <p class="text-xs text-gray-500 mt-1">La primera palabra va letra por letra en cajas de color (O·P·A) y el resto al lado.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Etiqueta por defecto</label>
                            <input type="text" name="etiqueta" maxlength="40" value="{{ old('etiqueta', $t->etiqueta ?? 'NOTICIAS') }}"
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Hashtag</label>
                            <input type="text" name="hashtag" maxlength="60" value="{{ old('hashtag', $t->hashtag ?? '#EsNoticia') }}"
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Pie (sitio web)</label>
                        <input type="text" name="pie" maxlength="80" value="{{ old('pie', $t->pie ?? 'Opanoticias.com') }}"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        @foreach (['color_titulo' => ['Título', '#FFFFFF'], 'color_logo' => ['Logo', '#FFFFFF'], 'color_etiqueta' => ['Etiqueta', '#C8102E']] as $campo => [$label, $def])
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">{{ $label }}</label>
                                <input type="color" name="{{ $campo }}" value="{{ old($campo, $t->$campo ?? $def) }}"
                                       class="h-10 w-full rounded-xl border border-gray-300 bg-white p-1">
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-5 text-sm text-gray-700">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="predeterminada" value="1" class="h-4 w-4 rounded border-gray-300" {{ old('predeterminada', $t->predeterminada ?? false) ? 'checked' : '' }}>
                            Predeterminada en la app
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="activa" value="1" class="h-4 w-4 rounded border-gray-300" {{ old('activa', $t->activa ?? true) ? 'checked' : '' }}>
                            Activa
                        </label>
                        <label class="inline-flex items-center gap-2">
                            Orden
                            <input type="number" name="orden" min="0" max="999" value="{{ old('orden', $t->orden ?? 0) }}"
                                   class="w-20 border border-gray-300 rounded-xl px-2 py-1 bg-white">
                        </label>
                    </div>

                    <button type="submit" class="w-full bg-indigo-600 text-white px-4 py-2.5 rounded-xl hover:bg-indigo-700 transition font-semibold">
                        {{ $t ? 'Guardar cambios' : 'Crear plantilla' }}
                    </button>
                </form>
            </div>
        </div>


        <p class="text-xs text-gray-400 mt-6">La app las lee por <code>/api/plantillas</code>. Si subes logos, el servidor necesita el enlace <code>php artisan storage:link</code>.</p>
    </div>
@endsection
