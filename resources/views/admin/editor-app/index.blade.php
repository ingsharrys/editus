@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8">

        <!-- HEADER -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8 mt-10">
            <div class="text-[#00024f]">
                <h1 class="text-3xl font-bold">App del editor</h1>
                <p class="mt-1 text-sm">
                    Decide qué páginas de Facebook / Instagram se ofrecen en la sección <strong>Redes</strong> de la app móvil
                    y administra las plantillas con las que se componen las imágenes.
                </p>
            </div>
        </div>

        {{-- Alertas --}}
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-4">
                <ul class="list-disc ml-5 space-y-1">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-4">
                {{ session('success') }}
            </div>
        @endif

        {{-- ===================== PÁGINAS ===================== --}}
        <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-md mb-8">
            <div class="flex items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Páginas visibles en la app</h2>
                    <p class="text-sm text-gray-500">
                        Solo las páginas marcadas aparecen en la app para publicar. El <em>medio</em> indica de qué sitio
                        se toma el enlace de la nota (por ejemplo <code>opanoticias</code>, <code>neiva24</code>).
                    </p>
                </div>
            </div>

            @if ($paginas->isEmpty())
                <p class="text-sm text-gray-500">No hay páginas conectadas. Conéctalas en <a class="text-indigo-600 underline" href="{{ route('meta.pages.index') }}">Mis Páginas</a>.</p>
            @else
                <form method="POST" action="{{ route('editor-app.paginas') }}">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">Visible</th>
                                    <th class="px-3 py-2 text-left">Página</th>
                                    <th class="px-3 py-2 text-left">Instagram</th>
                                    <th class="px-3 py-2 text-left">Token</th>
                                    <th class="px-3 py-2 text-left">Medio (slug)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($paginas as $p)
                                    @php $conToken = $p->users->isNotEmpty(); @endphp
                                    <tr class="border-t border-gray-100 {{ $conToken ? '' : 'opacity-60' }}">
                                        <td class="px-3 py-2">
                                            <input type="checkbox" name="visible[]" value="{{ $p->id }}"
                                                   class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200"
                                                   {{ $p->visible_en_editor ? 'checked' : '' }}>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-3">
                                                <img src="{{ $p->pictureUrl('small') }}" alt="" class="h-9 w-9 rounded-full bg-gray-100 object-cover">
                                                <div>
                                                    <div class="font-semibold text-gray-800">{{ $p->name }}</div>
                                                    <div class="text-xs text-gray-400">{{ $p->page_id }} · {{ $p->category }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2">
                                            @if ($p->instagram_business_account_id)
                                                <span class="rounded-full bg-fuchsia-50 text-fuchsia-700 px-2 py-0.5 text-xs font-semibold">Vinculado</span>
                                            @else
                                                <span class="text-xs text-gray-400">No</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @if ($conToken)
                                                <span class="rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 text-xs font-semibold">Activo</span>
                                            @else
                                                <span class="rounded-full bg-red-50 text-red-700 px-2 py-0.5 text-xs font-semibold" title="Sin token activo: no aparecerá en la app aunque esté marcada">Sin token</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="text" name="medio[{{ $p->id }}]" value="{{ $p->medio_slug }}" list="medios-lista"
                                                   placeholder="opanoticias"
                                                   class="w-44 border border-gray-300 rounded-xl px-3 py-1.5 bg-white focus:ring-2 focus:ring-indigo-500 text-sm">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <datalist id="medios-lista">
                        @foreach ($medios as $m)
                            <option value="{{ $m }}"></option>
                        @endforeach
                    </datalist>
                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-xl hover:bg-indigo-700 transition">
                            Guardar páginas
                        </button>
                    </div>
                </form>
            @endif
        </div>

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
                                    <a href="{{ route('editor-app.index', ['editar' => $t->id]) }}#plantilla-form"
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
                    <a href="{{ route('editor-app.index') }}" class="text-xs text-indigo-600 underline">Cancelar edición</a>
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

        {{-- ===================== RECURSOS EN VIVO ===================== --}}
        <div id="recursos" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-md mb-8">
            <div class="mb-4">
                <h2 class="text-xl font-bold text-gray-800">Recursos para las transmisiones en vivo</h2>
                <p class="text-sm text-gray-500">
                    Cortinillas y comerciales (video MP4 o WebM, hasta 200 MB) e imágenes a pantalla completa (PNG, JPG, WEBP).
                    Desde la app, durante la transmisión, el director los saca al aire con un toque. Los videos se reproducen una vez con su audio y vuelven a las cámaras al terminar.
                </p>
            </div>
            <div class="grid lg:grid-cols-5 gap-6">
                <div class="lg:col-span-3">
                    @if ($recursos->isEmpty())
                        <p class="text-sm text-gray-500">Todavía no hay recursos.</p>
                    @else
                        <div class="space-y-2">
                            @foreach ($recursos as $r)
                                <div class="flex items-center gap-3 rounded-2xl border border-gray-100 p-3">
                                    @if ($r->tipo === 'imagen')
                                        <img src="{{ $r->url() }}" alt="" class="h-12 w-20 object-cover rounded-lg bg-gray-100">
                                    @else
                                        <div class="h-12 w-20 rounded-lg bg-gray-900 text-white flex items-center justify-center text-xs font-bold">VIDEO</div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-gray-800 truncate">{{ $r->nombre }}</div>
                                        <div class="text-xs text-gray-500">{{ $r->tipo === 'video' ? 'Cortinilla / comercial' : 'Imagen' }}{{ $r->duracion ? " · {$r->duracion} s" : '' }} · orden {{ $r->orden }}</div>
                                    </div>
                                    <form method="POST" action="{{ route('editor-app.recursos.destroy', $r) }}" onsubmit="return confirm('¿Eliminar el recurso «{{ $r->nombre }}»?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Eliminar</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <form method="POST" enctype="multipart/form-data" action="{{ route('editor-app.recursos.store') }}" class="lg:col-span-2 space-y-3 text-sm">
                    @csrf
                    <h3 class="font-semibold text-gray-800">Subir recurso</h3>
                    <div><label class="block text-gray-600 mb-1">Nombre</label><input name="nombre" required maxlength="80" class="w-full rounded-lg border-gray-300" placeholder="Ej: Cortinilla Opa, Comercial Ferretería X"></div>
                    <div><label class="block text-gray-600 mb-1">Archivo</label><input type="file" name="archivo" required accept=".mp4,.webm,.png,.jpg,.jpeg,.webp" class="w-full text-sm"></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="block text-gray-600 mb-1">Duración (s, solo imágenes)</label><input type="number" name="duracion" min="1" max="600" class="w-full rounded-lg border-gray-300" placeholder="vacío = hasta quitarla"></div>
                        <div><label class="block text-gray-600 mb-1">Orden</label><input type="number" name="orden" min="0" max="999" value="0" class="w-full rounded-lg border-gray-300"></div>
                    </div>
                    <button class="rounded-lg bg-[#00024f] text-white font-semibold px-4 py-2 hover:opacity-90">Subir</button>
                    <p class="text-xs text-gray-400">Para videos, usa MP4 (H.264 + AAC) en 1920×1080: es lo que mejor reproduce el mezclador.</p>
                </form>
            </div>
        </div>

        <p class="text-xs text-gray-400 mt-6">
            Estos datos los lee el backend de esnoticia por <code>/api/paginas</code> y <code>/api/plantillas</code> (token de integración).
            Si subes logos, el servidor necesita el enlace <code>php artisan storage:link</code>.
        </p>
    </div>
@endsection
