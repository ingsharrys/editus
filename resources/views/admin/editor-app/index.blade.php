@extends('layouts.app')

@section('content')
    @php
        $tabs = [
            'paginas' => ['Páginas de Facebook', 'M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z'],
            'youtube' => ['Canales de YouTube', 'M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33zM9.75 15.02V8.48l5.75 3.27z'],
            'recursos' => ['Recursos en vivo', 'M4 4h16v12H4zM2 20h20M10 8l5 2-5 2z'],
        ];
        $periodistasPorNombre = collect($periodistas)->keyBy(fn($u) => strtolower($u['username']));
    @endphp
    <div class="max-w-7xl mx-auto px-4 py-8">

        <!-- HEADER -->
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6 mt-10">
            <div class="text-[#00024f]">
                <h1 class="text-3xl font-bold">App del editor</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Qué páginas y canales de la organización ve cada periodista en la app, y los recursos de las transmisiones en vivo.
                    Las páginas y canales que cada periodista conecta desde la app solo los ve él.
                </p>
            </div>
            <a href="{{ route('editor-app.plantillas.index') }}" class="text-sm text-indigo-700 hover:underline whitespace-nowrap">Plantillas de imagen →</a>
        </div>

        {{-- Alertas --}}
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
        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 text-red-800 p-3 mb-4 text-sm">{{ session('error') }}</div>
        @endif

        {{-- Pestañas --}}
        <div class="flex gap-1 border-b border-gray-200 mb-6 overflow-x-auto -mx-1 px-1">
            @foreach ($tabs as $clave => [$titulo, $icono])
                <a href="{{ route('editor-app.index', ['tab' => $clave]) }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px whitespace-nowrap transition
                          {{ $tab === $clave ? 'border-[#00024f] text-[#00024f]' : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icono }}"/></svg>
                    {{ $titulo }}
                    @if ($clave === 'paginas')<span class="ml-1 rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-xs">{{ $resumen['total'] }}</span>@endif
                    @if ($clave === 'youtube')<span class="ml-1 rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-xs">{{ $canalesYoutube->count() }}</span>@endif
                    @if ($clave === 'recursos')<span class="ml-1 rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-xs">{{ $recursos->count() }}</span>@endif
                </a>
            @endforeach
        </div>

        {{-- ===================== PÁGINAS ===================== --}}
        @if ($tab === 'paginas')
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                @foreach ([['Páginas', $resumen['total'], 'text-gray-800'], ['Visibles en la app', $resumen['visibles'], 'text-emerald-700'], ['Conectadas desde la app', $resumen['desde_app'], 'text-indigo-700'], ['Sin token', $resumen['sin_token'], 'text-red-700']] as [$et, $n, $color])
                    <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3 min-w-0">
                        <div class="text-xs text-gray-500 truncate">{{ $et }}</div>
                        <div class="text-2xl font-bold {{ $color }}">{{ $n }}</div>
                    </div>
                @endforeach
            </div>

            @unless ($backendListo)
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-3 mb-4 text-sm">
                    Para elegir periodistas por nombre, el backend de esnoticia debe ser accesible: revisa <code>ESNOTICIA_URL</code> y <code>EDITUS_INGEST_TOKEN</code> en el <code>.env</code>. Mientras tanto puedes escribir los nombres de usuario separados por coma.
                </div>
            @elseif (empty($periodistas))
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-3 mb-4 text-sm">
                    El backend de esnoticia no devolvió periodistas (¿está actualizado y con el mismo token?). Puedes escribir los nombres de usuario separados por coma o <a class="underline" href="{{ route('editor-app.index', ['tab' => 'paginas', 'recargar_usuarios' => 1]) }}">volver a intentar</a>.
                </div>
            @endunless

            <form method="POST" action="{{ route('editor-app.paginas') }}" class="rounded-3xl border border-gray-200 bg-white shadow-md">
                @csrf
                <div class="flex flex-col lg:flex-row lg:items-center gap-3 px-4 sm:px-5 py-4 border-b border-gray-100">
                    <div class="flex-1 min-w-0">
                        <h2 class="text-lg font-bold text-gray-800">Páginas de la organización</h2>
                        <p class="text-xs text-gray-500">
                            Marca <strong>Visible</strong> para ofrecerla en la app y elige en <strong>Periodistas</strong> quién la ve.
                            Las páginas conectadas desde la app aparecen aquí también, pero cada periodista solo ve las suyas.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <input type="search" id="filtro-paginas" placeholder="Buscar página…" class="flex-1 lg:w-56 rounded-xl border-gray-300 text-sm">
                        <button type="submit" class="rounded-xl bg-[#00024f] text-white font-semibold px-4 py-2 hover:opacity-90 text-sm whitespace-nowrap">Guardar</button>
                    </div>
                </div>

                <div id="lista-paginas" class="divide-y divide-gray-100">
                    @foreach ($paginas as $p)
                        @php
                            $conToken = $p->vinculos->isNotEmpty();
                            $deWeb = $p->vinculos->filter(fn($v) => empty($v->usuario_app));
                            $deApp = $conApp ? $p->vinculos->filter(fn($v) => !empty($v->usuario_app)) : collect();
                            $lista = $p->app_usuarios;
                            $seleccion = ($lista === null || in_array('*', (array) $lista, true)) ? ['*'] : array_values((array) $lista);
                        @endphp
                        <div class="pagina-fila flex flex-wrap items-center gap-x-4 gap-y-2 px-4 sm:px-5 py-3 {{ $conToken ? '' : 'bg-gray-50/60' }}" data-nombre="{{ strtolower($p->name . ' ' . $p->page_id . ' ' . $p->medio_slug) }}">
                            {{-- Visible + identidad --}}
                            <label class="flex items-center gap-3 min-w-0 flex-1 basis-64 cursor-pointer">
                                <input type="checkbox" name="visible[]" value="{{ $p->id }}" class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200 shrink-0" {{ $p->visible_en_editor ? 'checked' : '' }}>
                                <img src="{{ $p->pictureUrl('small') }}" alt="" class="h-9 w-9 rounded-full bg-gray-100 object-cover shrink-0 {{ $conToken ? '' : 'grayscale opacity-60' }}" loading="lazy">
                                <span class="min-w-0">
                                    <span class="block font-semibold text-gray-800 leading-tight truncate">{{ $p->name }}</span>
                                    <span class="block text-[11px] text-gray-400 truncate">
                                        {{ $p->page_id }}
                                        @if ($p->category) · {{ $p->category }} @endif
                                    </span>
                                </span>
                            </label>

                            {{-- Origen y estado --}}
                            <div class="flex flex-wrap items-center gap-1.5 text-xs basis-56 grow">
                                @if ($conToken)
                                    <span class="rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 font-semibold">Activa</span>
                                @else
                                    <span class="rounded-full bg-red-50 text-red-700 px-2 py-0.5 font-semibold" title="Sin token activo: no aparecerá en la app aunque esté marcada">Sin token</span>
                                @endif
                                @if ($p->instagram_business_account_id)<span class="rounded-full bg-fuchsia-50 text-fuchsia-700 px-2 py-0.5 font-semibold">Instagram</span>@endif
                                @if ($deWeb->isNotEmpty())
                                    <span class="rounded-full bg-sky-50 text-sky-700 px-2 py-0.5" title="Conectada desde la web de editus"><strong>web</strong> {{ $deWeb->map(fn($v) => $v->user?->name)->filter()->unique()->implode(', ') }}</span>
                                @endif
                                @if ($deApp->isNotEmpty())
                                    <span class="rounded-full bg-indigo-50 text-indigo-700 px-2 py-0.5" title="Conectada desde la app"><strong>app</strong> {{ $deApp->map(fn($v) => '@' . ($nombresApp[(string) $v->usuario_app] ?? ('usuario #' . $v->usuario_app)))->unique()->implode(', ') }}</span>
                                @endif
                            </div>

                            {{-- Medio y periodistas --}}
                            <div class="flex flex-wrap gap-2 w-full xl:w-auto">
                                <label class="block w-full sm:w-44">
                                    <span class="block text-[11px] uppercase tracking-wide text-gray-400 mb-0.5">Medio</span>
                                    <select name="medio[{{ $p->id }}]" class="w-full rounded-xl border-gray-300 text-sm py-1.5">
                                        <option value="">— sin medio —</option>
                                        @foreach ($medios as $slug => $nombreMedio)
                                            <option value="{{ $slug }}" {{ $p->medio_slug === $slug ? 'selected' : '' }}>{{ $nombreMedio }}</option>
                                        @endforeach
                                        @if ($p->medio_slug && !array_key_exists($p->medio_slug, $medios))
                                            <option value="{{ $p->medio_slug }}" selected>{{ $p->medio_slug }}</option>
                                        @endif
                                    </select>
                                </label>
                                <div class="block w-full sm:w-60">
                                    <span class="block text-[11px] uppercase tracking-wide text-gray-400 mb-0.5">Periodistas que la ven</span>
                                    @include('admin.editor-app._periodistas', ['nombre' => "usuarios[{$p->id}]", 'seleccion' => $seleccion, 'periodistas' => $periodistas])
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @if ($paginas->isEmpty())
                        <div class="px-4 py-10 text-center text-sm text-gray-500">Todavía no hay páginas. Conéctalas en <a class="underline" href="{{ route('meta.pages.index') }}">Mis páginas</a> o desde la app.</div>
                    @endif
                    <div id="sin-resultados" class="hidden px-4 py-8 text-center text-sm text-gray-500">Ninguna página coincide con la búsqueda.</div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 sm:px-5 py-4 border-t border-gray-100 text-xs text-gray-500">
                    <span>★ Todos = cualquier periodista la ve. Nadie = solo quien la conecte desde la app.</span>
                    <button type="submit" class="rounded-xl bg-[#00024f] text-white font-semibold px-5 py-2 hover:opacity-90 text-sm">Guardar cambios</button>
                </div>
            </form>

            <script>
                (function () {
                    var f = document.getElementById('filtro-paginas');
                    if (!f) return;
                    f.addEventListener('input', function () {
                        var q = f.value.trim().toLowerCase(), visibles = 0;
                        document.querySelectorAll('#lista-paginas .pagina-fila').forEach(function (fila) {
                            var ok = !q || (fila.dataset.nombre || '').indexOf(q) !== -1;
                            fila.style.display = ok ? '' : 'none';
                            if (ok) visibles++;
                        });
                        var sr = document.getElementById('sin-resultados');
                        if (sr) sr.classList.toggle('hidden', visibles > 0);
                    });
                })();
            </script>
        @endif

        <script>
            // Selector de periodistas: "Todos" excluye a los demás; el resumen refleja la elección; se cierra al hacer clic fuera
            (function () {
                function resumir(d) {
                    var todos = d.querySelector('input.todos').checked;
                    var elegidos = Array.prototype.filter.call(d.querySelectorAll('input.uno'), function (c) { return c.checked; }).map(function (c) { return c.value; });
                    var r = d.querySelector('.resumen');
                    r.textContent = todos ? 'Todos los periodistas' : (elegidos.length ? elegidos.join(', ') : 'Nadie (solo desde la app)');
                    r.className = 'resumen truncate ' + (todos ? 'text-gray-800' : (elegidos.length ? 'text-indigo-700 font-semibold' : 'text-red-600'));
                }
                document.querySelectorAll('details.periodistas').forEach(function (d) {
                    d.addEventListener('change', function (e) {
                        var t = e.target;
                        if (t.classList.contains('todos') && t.checked) d.querySelectorAll('input.uno').forEach(function (c) { c.checked = false; });
                        if (t.classList.contains('uno') && t.checked) d.querySelector('input.todos').checked = false;
                        resumir(d);
                    });
                    d.addEventListener('toggle', function () {
                        if (d.open) document.querySelectorAll('details.periodistas[open]').forEach(function (o) { if (o !== d) o.open = false; });
                    });
                });
                document.addEventListener('click', function (e) {
                    document.querySelectorAll('details.periodistas[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
                });
            })();
        </script>

        {{-- ===================== YOUTUBE ===================== --}}
        @if ($tab === 'youtube')
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-md">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-5">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800">Canales de YouTube de la organización</h2>
                        <p class="text-xs text-gray-500">
                            Con estos canales transmiten los periodistas desde la app, en paralelo a Facebook. Cada transmisión crea el video en YouTube con el título y la descripción de la app.
                            El canal debe tener las transmisiones en vivo activadas en YouTube Studio. Los canales que un periodista conecta desde la app solo los ve él.
                        </p>
                    </div>
                    @if ($googleListo)
                        <a href="{{ route('youtube.connect') }}" class="shrink-0 rounded-xl bg-red-600 text-white font-semibold px-4 py-2 hover:opacity-90 text-sm">+ Conectar canal</a>
                    @else
                        <span class="shrink-0 text-xs text-amber-700">Faltan GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env</span>
                    @endif
                </div>

                @if ($canalesYoutube->isEmpty())
                    <p class="text-sm text-gray-500">No hay canales conectados.</p>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($canalesYoutube as $c)
                            @php
                                $lista = $c->app_usuarios;
                                $seleccion = ($lista === null || in_array('*', (array) $lista, true)) ? ['*'] : array_values((array) $lista);
                                $deApp = !empty($c->usuario_app);
                            @endphp
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 {{ $c->visible_en_editor || $deApp ? '' : 'opacity-60' }}">
                                @if ($c->foto)<img src="{{ $c->foto }}" alt="" class="h-9 w-9 rounded-full bg-gray-100 shrink-0">@else<div class="h-9 w-9 rounded-full bg-gray-200 shrink-0"></div>@endif
                                <div class="flex-1 min-w-0 basis-56">
                                    <div class="font-semibold text-gray-800 truncate">{{ $c->titulo }}</div>
                                    <div class="text-xs text-gray-500">
                                        <a href="https://www.youtube.com/channel/{{ $c->channel_id }}" target="_blank" class="underline">Ver canal</a>
                                        · {{ $c->refresh_token ? 'acceso permanente' : 'sin acceso permanente' }}
                                        @if ($c->expira_en) · token hasta {{ $c->expira_en->format('d/m H:i') }} @endif
                                        @if ($deApp) · <span class="rounded-full bg-indigo-50 text-indigo-700 px-2 py-0.5 font-semibold">app</span> {{ '@' . ($nombresApp[(string) $c->usuario_app] ?? ('usuario #' . $c->usuario_app)) }} @endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-end gap-2 w-full xl:w-auto">
                                    @if (!$deApp)
                                        <form method="POST" action="{{ route('youtube.usuarios', $c) }}" class="flex items-end gap-2 w-full sm:w-auto">@csrf
                                            <div class="w-full sm:w-60">
                                                <span class="block text-[11px] uppercase tracking-wide text-gray-400 mb-0.5">Periodistas que lo ven</span>
                                                @include('admin.editor-app._periodistas', ['nombre' => 'usuarios', 'seleccion' => $seleccion, 'periodistas' => $periodistas])
                                            </div>
                                            <button class="text-xs rounded-xl border border-gray-300 px-3 py-2 hover:bg-gray-50 whitespace-nowrap">Guardar</button>
                                        </form>
                                        <form method="POST" action="{{ route('youtube.visible', $c) }}">@csrf<input type="hidden" name="visible" value="{{ $c->visible_en_editor ? 0 : 1 }}"><button class="text-xs rounded-xl border border-gray-300 px-3 py-2 hover:bg-gray-50 whitespace-nowrap">{{ $c->visible_en_editor ? 'Ocultar en la app' : 'Mostrar en la app' }}</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('youtube.desconectar', $c) }}" onsubmit="return confirm('¿Desconectar el canal «{{ $c->titulo }}»?')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline px-2 py-2">Desconectar</button></form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- ===================== RECURSOS EN VIVO ===================== --}}
        @if ($tab === 'recursos')
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-md">
                <div class="mb-4">
                    <h2 class="text-lg font-bold text-gray-800">Recursos para las transmisiones en vivo</h2>
                    <p class="text-xs text-gray-500">
                        Intro, plantilla de video (PNG 1920×1080 con transparencia) y publicidad (video MP4/WebM hasta 200 MB, o imagen PNG/JPG/WEBP).
                        Los periodistas también pueden subirlos desde la app, en la sala, antes de salir al aire.
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
                                        @if ($r->tipo !== 'video')
                                            <img src="{{ $r->url() }}" alt="" class="h-12 w-20 object-cover rounded-lg bg-gray-100">
                                        @else
                                            <div class="h-12 w-20 rounded-lg bg-gray-900 text-white flex items-center justify-center text-xs font-bold">VIDEO</div>
                                        @endif
                                        <div class="flex-1 min-w-0">
                                            <div class="font-semibold text-gray-800 truncate">{{ $r->nombre }}</div>
                                            <div class="text-xs text-gray-500">{{ ['intro' => 'Intro', 'plantilla' => 'Plantilla de video (PNG)', 'publicidad' => 'Publicidad'][$r->uso ?? 'publicidad'] ?? 'Publicidad' }} · {{ $r->tipo === 'video' ? 'video' : 'imagen' }}{{ $r->duracion ? " · {$r->duracion} s" : '' }}</div>
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
                        <div><label class="block text-gray-600 mb-1">Nombre</label><input name="nombre" required maxlength="80" class="w-full rounded-xl border-gray-300" placeholder="Ej: Cortinilla Opa, Comercial Ferretería X"></div>
                        <div><label class="block text-gray-600 mb-1">Archivo</label><input type="file" name="archivo" required accept=".mp4,.webm,.png,.jpg,.jpeg,.webp" class="w-full text-sm"></div>
                        <div><label class="block text-gray-600 mb-1">Uso</label>
                            <select name="uso" class="w-full rounded-xl border-gray-300">
                                <option value="publicidad">Publicidad: imagen o video que se saca al aire durante la transmisión</option>
                                <option value="intro">Intro: video que abre la transmisión antes de las cámaras</option>
                                <option value="plantilla">Plantilla de video: PNG transparente 1920×1080 que va sobre las cámaras</option>
                            </select></div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-gray-600 mb-1">Duración (s, solo imágenes)</label><input type="number" name="duracion" min="1" max="600" class="w-full rounded-xl border-gray-300" placeholder="vacío = hasta quitarla"></div>
                            <div><label class="block text-gray-600 mb-1">Orden</label><input type="number" name="orden" min="0" max="999" value="0" class="w-full rounded-xl border-gray-300"></div>
                        </div>
                        <button class="rounded-xl bg-[#00024f] text-white font-semibold px-4 py-2 hover:opacity-90">Subir</button>
                        <p class="text-xs text-gray-400">Para videos, usa MP4 (H.264 + AAC) en 1920×1080: es lo que mejor reproduce el mezclador.</p>
                    </form>
                </div>
            </div>
        @endif

        <p class="text-xs text-gray-400 mt-6">
            Estos datos los lee el backend de esnoticia por <code>/api/paginas</code>, <code>/api/en-vivo/youtube</code> y <code>/api/cuentas</code> (token de integración).
        </p>
    </div>
@endsection
