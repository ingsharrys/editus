@extends('layouts.app')

@section('content')
    @php
        $tabs = [
            'paginas' => ['Páginas', $resumen['total']],
            'youtube' => ['YouTube', $canalesYoutube->count()],
            'recursos' => ['Recursos', $recursos->count()],
        ];
        $etiquetaUso = ['intro' => 'Intro', 'plantilla' => 'Plantilla PNG', 'publicidad' => 'Publicidad'];
    @endphp
    <div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">

        {{-- ===================== CABECERA ===================== --}}
        <div class="mt-8 lg:mt-10 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Configuración</div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">App del editor</h1>
                    <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">
                        Decide qué páginas y canales de la organización ve cada periodista en la app. Lo que un periodista conecta desde la app solo lo ve él.
                    </p>
                </div>
                <a href="{{ route('editor-app.plantillas.index') }}" class="inline-flex items-center gap-2 h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>
                    Plantillas de imagen
                </a>
            </div>

            {{-- Pestañas (control segmentado) --}}
            <div class="mt-6 inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-200/70 p-1 gap-1">
                @foreach ($tabs as $clave => [$titulo, $n])
                    <a href="{{ route('editor-app.index', ['tab' => $clave]) }}"
                       class="flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition
                              {{ $tab === $clave ? 'bg-white text-[#00024f] shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                        {{ $titulo }}
                        <span class="rounded-md px-1.5 py-0.5 text-[11px] font-bold {{ $tab === $clave ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-300/60 text-gray-600' }}">{{ $n }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Alertas --}}
        @if ($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">
                <ul class="list-disc ml-5 space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif
        @if (session('success'))
            <div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">{{ session('error') }}</div>
        @endif

        {{-- ===================== PÁGINAS ===================== --}}
        @if ($tab === 'paginas')
            {{-- Indicadores --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                @foreach ([
                    ['Páginas', $resumen['total'], 'bg-slate-100 text-slate-600', 'M4 4h16v16H4z'],
                    ['Visibles en la app', $resumen['visibles'], 'bg-emerald-50 text-emerald-600', 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z'],
                    ['Conectadas desde la app', $resumen['desde_app'], 'bg-indigo-50 text-indigo-600', 'M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z M12 18h.01'],
                    ['Sin token', $resumen['sin_token'], 'bg-rose-50 text-rose-600', 'M12 9v4 M12 17h.01 M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z'],
                ] as [$et, $n, $color, $icono])
                    <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3.5 min-w-0 shadow-sm">
                        <span class="h-9 w-9 shrink-0 rounded-xl flex items-center justify-center {{ $color }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icono }}"/></svg>
                        </span>
                        <div class="min-w-0">
                            <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400 truncate">{{ $et }}</div>
                            <div class="text-xl font-bold text-gray-900 leading-tight">{{ $n }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @unless ($backendListo)
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 mb-5 text-sm">
                    Para elegir periodistas por nombre, el backend de esnoticia debe ser accesible: revisa <code>ESNOTICIA_URL</code> y <code>EDITUS_INGEST_TOKEN</code> en el <code>.env</code>. Mientras tanto puedes escribir los nombres de usuario separados por coma.
                </div>
            @elseif (empty($periodistas))
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 mb-5 text-sm">
                    El backend de esnoticia no devolvió periodistas (¿está actualizado y con el mismo token?). Puedes escribir los nombres separados por coma o <a class="underline" href="{{ route('editor-app.index', ['tab' => 'paginas', 'recargar_usuarios' => 1]) }}">volver a intentar</a>.
                </div>
            @endunless

            <form method="POST" action="{{ route('editor-app.paginas') }}" id="form-paginas" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                @csrf
                {{-- Barra superior --}}
                <div class="flex flex-col md:flex-row md:items-center gap-3 px-4 sm:px-5 py-4 border-b border-gray-100">
                    <div class="flex-1 min-w-0">
                        <h2 class="text-base font-bold text-gray-900">Páginas de la organización</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Activa el interruptor para ofrecerla en la app y elige qué periodistas la ven.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="relative flex-1 md:w-60">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                            <input type="search" id="filtro-paginas" placeholder="Buscar página" class="w-full h-9 pl-9 rounded-lg border-gray-200 text-sm shadow-sm focus:border-indigo-300 focus:ring-indigo-200">
                        </div>
                        <button type="submit" class="h-9 rounded-lg bg-[#00024f] text-white font-semibold px-4 text-sm hover:opacity-90 whitespace-nowrap shadow-sm">Guardar</button>
                    </div>
                </div>

                {{-- Encabezado de columnas (solo escritorio) --}}
                <div class="hidden md:grid md:grid-cols-[2.5rem_minmax(9rem,1fr)_7rem_8rem_10.5rem] lg:grid-cols-[2.75rem_minmax(12rem,1fr)_8.5rem_9rem_12.5rem] gap-x-3 px-4 sm:px-5 py-2 bg-gray-50/80 border-b border-gray-100 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                    <div>App</div>
                    <div>Página</div>
                    <div>Estado</div>
                    <div>Medio</div>
                    <div>Periodistas que la ven</div>
                </div>

                <div id="lista-paginas" class="divide-y divide-gray-100">
                    @foreach ($paginas as $p)
                        @php
                            $conToken = $p->vinculos->isNotEmpty();
                            $deWeb = $p->vinculos->filter(fn($v) => empty($v->usuario_app));
                            $deApp = $conApp ? $p->vinculos->filter(fn($v) => !empty($v->usuario_app)) : collect();
                            $lista = $p->app_usuarios;
                            $seleccion = ($lista === null || in_array('*', (array) $lista, true)) ? ['*'] : array_values((array) $lista);
                            $origen = collect();
                            if ($deWeb->isNotEmpty()) $origen->push('Web · ' . $deWeb->map(fn($v) => $v->user?->name)->filter()->unique()->implode(', '));
                            if ($deApp->isNotEmpty()) $origen->push('App · ' . $deApp->map(fn($v) => '@' . ($nombresApp[(string) $v->usuario_app] ?? ('usuario #' . $v->usuario_app)))->unique()->implode(', '));
                        @endphp
                        <div class="pagina-fila grid grid-cols-[2.75rem_minmax(0,1fr)] md:grid-cols-[2.5rem_minmax(9rem,1fr)_7rem_8rem_10.5rem] lg:grid-cols-[2.75rem_minmax(12rem,1fr)_8.5rem_9rem_12.5rem] gap-x-3 gap-y-3 items-center px-4 sm:px-5 py-3 hover:bg-gray-50/70 transition" data-nombre="{{ strtolower($p->name . ' ' . $p->page_id . ' ' . $p->medio_slug) }}">
                            {{-- Interruptor Visible --}}
                            <label class="relative inline-flex items-center cursor-pointer" title="{{ $p->visible_en_editor ? 'Visible en la app' : 'Oculta en la app' }}">
                                <input type="checkbox" name="visible[]" value="{{ $p->id }}" class="peer sr-only" {{ $p->visible_en_editor ? 'checked' : '' }}>
                                <span class="h-5 w-9 rounded-full bg-gray-200 transition peer-checked:bg-emerald-500 peer-focus:ring-2 peer-focus:ring-emerald-200"></span>
                                <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                            </label>

                            {{-- Identidad --}}
                            <div class="flex items-center gap-3 min-w-0">
                                <img src="{{ $p->pictureUrl('small') }}" alt="" class="h-8 w-8 rounded-full bg-gray-100 object-cover shrink-0 ring-1 ring-gray-200 {{ $conToken ? '' : 'grayscale opacity-60' }}" loading="lazy">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900 leading-tight truncate">{{ $p->name }}</div>
                                    <div class="text-xs text-gray-400 truncate">
                                        {{ $p->page_id }}@if ($p->instagram_business_account_id) · <span class="text-fuchsia-600 font-medium">Instagram</span>@endif
                                    </div>
                                </div>
                            </div>

                            {{-- Estado --}}
                            <div class="col-start-2 md:col-start-auto min-w-0">
                                <div class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $conToken ? 'text-emerald-700' : 'text-rose-600' }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $conToken ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>{{ $conToken ? 'Activa' : 'Sin token' }}
                                </div>
                                <div class="text-[11px] text-gray-400 truncate" title="{{ $origen->implode(' · ') }}">{{ $origen->isNotEmpty() ? $origen->implode(' · ') : 'Sin conexión' }}</div>
                            </div>

                            {{-- Medio --}}
                            <div class="col-start-2 md:col-start-auto min-w-0">
                                <span class="md:hidden block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Medio</span>
                                <select name="medio[{{ $p->id }}]" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm focus:border-indigo-300 focus:ring-indigo-200">
                                    <option value="">Sin medio</option>
                                    @foreach ($medios as $slug => $nombreMedio)
                                        <option value="{{ $slug }}" {{ $p->medio_slug === $slug ? 'selected' : '' }}>{{ $nombreMedio }}</option>
                                    @endforeach
                                    @if ($p->medio_slug && !array_key_exists($p->medio_slug, $medios))
                                        <option value="{{ $p->medio_slug }}" selected>{{ $p->medio_slug }}</option>
                                    @endif
                                </select>
                            </div>

                            {{-- Periodistas --}}
                            <div class="col-start-2 md:col-start-auto min-w-0">
                                <span class="md:hidden block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Periodistas que la ven</span>
                                @include('admin.editor-app._periodistas', ['nombre' => "usuarios[{$p->id}]", 'seleccion' => $seleccion, 'periodistas' => $periodistas])
                            </div>
                        </div>
                    @endforeach
                    @if ($paginas->isEmpty())
                        <div class="px-4 py-12 text-center text-sm text-gray-500">Todavía no hay páginas. Conéctalas en <a class="underline" href="{{ route('meta.pages.index') }}">Mis páginas</a> o desde la app.</div>
                    @endif
                    <div id="sin-resultados" class="hidden px-4 py-10 text-center text-sm text-gray-500">Ninguna página coincide con la búsqueda.</div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 sm:px-5 py-4 border-t border-gray-100 text-xs text-gray-500">
                    <span><strong class="text-gray-700">Todos</strong> = cualquier periodista la ve · <strong class="text-gray-700">Nadie</strong> = solo quien la conecte desde la app</span>
                    <button type="submit" class="h-9 rounded-lg bg-[#00024f] text-white font-semibold px-5 text-sm hover:opacity-90 shadow-sm">Guardar cambios</button>
                </div>
            </form>

            {{-- Barra flotante: aparece cuando hay cambios sin guardar --}}
            <div id="barra-cambios" class="hidden fixed bottom-5 left-1/2 -translate-x-1/2 z-40 w-[calc(100%-2rem)] max-w-md">
                <div class="flex items-center justify-between gap-3 rounded-2xl bg-gray-900 text-white px-4 py-3 shadow-2xl">
                    <span class="text-sm">Tienes cambios sin guardar</span>
                    <button type="submit" form="form-paginas" class="h-9 rounded-lg bg-white text-gray-900 font-semibold px-4 text-sm hover:bg-gray-100">Guardar</button>
                </div>
            </div>

            <script>
                (function () {
                    var f = document.getElementById('filtro-paginas');
                    if (f) f.addEventListener('input', function () {
                        var q = f.value.trim().toLowerCase(), visibles = 0;
                        document.querySelectorAll('#lista-paginas .pagina-fila').forEach(function (fila) {
                            var ok = !q || (fila.dataset.nombre || '').indexOf(q) !== -1;
                            fila.style.display = ok ? '' : 'none';
                            if (ok) visibles++;
                        });
                        var sr = document.getElementById('sin-resultados');
                        if (sr) sr.classList.toggle('hidden', visibles > 0);
                    });
                    var form = document.getElementById('form-paginas'), barra = document.getElementById('barra-cambios');
                    if (form && barra) form.addEventListener('change', function () { barra.classList.remove('hidden'); });
                })();
            </script>
        @endif

        {{-- ===================== YOUTUBE ===================== --}}
        @if ($tab === 'youtube')
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center gap-3 px-4 sm:px-5 py-4 border-b border-gray-100">
                    <div class="flex-1 min-w-0">
                        <h2 class="text-base font-bold text-gray-900">Canales de YouTube de la organización</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Los periodistas transmiten en ellos desde la app, en paralelo a Facebook. El canal debe tener el en vivo activado en YouTube Studio.</p>
                    </div>
                    @if ($googleListo)
                        <a href="{{ route('youtube.connect') }}" class="inline-flex items-center gap-2 h-9 rounded-lg bg-red-600 text-white font-semibold px-4 text-sm hover:bg-red-700 whitespace-nowrap shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                            Conectar canal
                        </a>
                    @else
                        <span class="text-xs text-amber-700">Faltan GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env</span>
                    @endif
                </div>

                @if ($canalesYoutube->isEmpty())
                    <div class="px-4 py-12 text-center text-sm text-gray-500">No hay canales conectados todavía.</div>
                @else
                    <div class="hidden md:grid md:grid-cols-[minmax(9rem,1fr)_8rem_13rem_auto] lg:grid-cols-[minmax(12rem,1fr)_9rem_16rem_auto] gap-x-3 px-4 sm:px-5 py-2 bg-gray-50/80 border-b border-gray-100 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                        <div>Canal</div>
                        <div>Acceso</div>
                        <div>Periodistas que lo ven</div>
                        <div class="text-right">Acciones</div>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @foreach ($canalesYoutube as $c)
                            @php
                                $lista = $c->app_usuarios;
                                $seleccion = ($lista === null || in_array('*', (array) $lista, true)) ? ['*'] : array_values((array) $lista);
                                $deApp = !empty($c->usuario_app);
                            @endphp
                            <div class="grid grid-cols-1 md:grid-cols-[minmax(9rem,1fr)_8rem_13rem_auto] lg:grid-cols-[minmax(12rem,1fr)_9rem_16rem_auto] gap-x-3 gap-y-3 items-center px-4 sm:px-5 py-3 hover:bg-gray-50/70 transition {{ $c->visible_en_editor || $deApp ? '' : 'opacity-60' }}">
                                <div class="flex items-center gap-3 min-w-0">
                                    @if ($c->foto)<img src="{{ $c->foto }}" alt="" class="h-8 w-8 rounded-full bg-gray-100 shrink-0 ring-1 ring-gray-200">@else<div class="h-8 w-8 rounded-full bg-red-50 text-red-600 flex items-center justify-center shrink-0 text-[10px] font-bold">YT</div>@endif
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold text-gray-900 truncate">{{ $c->titulo }}</div>
                                        <div class="text-xs text-gray-400 truncate">
                                            <a href="https://www.youtube.com/channel/{{ $c->channel_id }}" target="_blank" class="hover:underline">Ver canal</a>
                                            @if ($deApp) · App · {{ '@' . ($nombresApp[(string) $c->usuario_app] ?? ('usuario #' . $c->usuario_app)) }} @else · Organización @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $c->refresh_token ? 'text-emerald-700' : 'text-amber-600' }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $c->refresh_token ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>{{ $c->refresh_token ? 'Permanente' : 'Temporal' }}
                                    </div>
                                    <div class="text-[11px] text-gray-400 truncate">@if ($c->expira_en) Token hasta {{ $c->expira_en->format('d/m H:i') }} @else Sin fecha @endif</div>
                                </div>
                                @if (!$deApp)
                                    <form method="POST" action="{{ route('youtube.usuarios', $c) }}" class="flex items-end gap-2 min-w-0">@csrf
                                        <div class="flex-1 min-w-0">
                                            <span class="md:hidden block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Periodistas que lo ven</span>
                                            @include('admin.editor-app._periodistas', ['nombre' => 'usuarios', 'seleccion' => $seleccion, 'periodistas' => $periodistas])
                                        </div>
                                        <button class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm shrink-0">Guardar</button>
                                    </form>
                                    <div class="flex items-center gap-2 md:justify-end">
                                        <form method="POST" action="{{ route('youtube.visible', $c) }}">@csrf<input type="hidden" name="visible" value="{{ $c->visible_en_editor ? 0 : 1 }}"><button class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm whitespace-nowrap">{{ $c->visible_en_editor ? 'Ocultar' : 'Mostrar' }}</button></form>
                                        <form method="POST" action="{{ route('youtube.desconectar', $c) }}" onsubmit="return confirm('¿Desconectar el canal «{{ $c->titulo }}»?')">@csrf @method('DELETE')<button class="h-9 rounded-lg px-3 text-xs font-semibold text-rose-600 hover:bg-rose-50 whitespace-nowrap">Desconectar</button></form>
                                    </div>
                                @else
                                    <div class="text-xs text-gray-400">Lo ve solo quien lo conectó</div>
                                    <div class="flex items-center md:justify-end">
                                        <form method="POST" action="{{ route('youtube.desconectar', $c) }}" onsubmit="return confirm('¿Desconectar el canal «{{ $c->titulo }}»?')">@csrf @method('DELETE')<button class="h-9 rounded-lg px-3 text-xs font-semibold text-rose-600 hover:bg-rose-50 whitespace-nowrap">Desconectar</button></form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- ===================== RECURSOS EN VIVO ===================== --}}
        @if ($tab === 'recursos')
            <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start">
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="px-4 sm:px-5 py-4 border-b border-gray-100">
                        <h2 class="text-base font-bold text-gray-900">Recursos para las transmisiones en vivo</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Intro, plantilla de video (PNG 1920×1080 con transparencia) y publicidad. Los periodistas también los suben desde la app.</p>
                    </div>
                    @if ($recursos->isEmpty())
                        <div class="px-4 py-12 text-center text-sm text-gray-500">Todavía no hay recursos.</div>
                    @else
                        <div class="divide-y divide-gray-100">
                            @foreach ($recursos as $r)
                                <div class="flex items-center gap-3 px-4 sm:px-5 py-3 hover:bg-gray-50/70 transition">
                                    @if ($r->tipo !== 'video')
                                        <img src="{{ $r->url() }}" alt="" class="h-11 w-20 object-cover rounded-lg bg-gray-100 ring-1 ring-gray-200 shrink-0">
                                    @else
                                        <div class="h-11 w-20 rounded-lg bg-gray-900 text-white flex items-center justify-center text-[10px] font-bold tracking-wide shrink-0">VIDEO</div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-gray-900 truncate">{{ $r->nombre }}</div>
                                        <div class="text-xs text-gray-400">
                                            <span class="rounded-md bg-gray-100 text-gray-600 px-1.5 py-0.5 font-medium">{{ $etiquetaUso[$r->uso ?? 'publicidad'] ?? 'Publicidad' }}</span>
                                            · {{ $r->tipo === 'video' ? 'video' : 'imagen' }}{{ $r->duracion ? " · {$r->duracion} s" : '' }}
                                        </div>
                                    </div>
                                    <form method="POST" action="{{ route('editor-app.recursos.destroy', $r) }}" onsubmit="return confirm('¿Eliminar el recurso «{{ $r->nombre }}»?')">
                                        @csrf @method('DELETE')
                                        <button class="h-9 rounded-lg px-3 text-xs font-semibold text-rose-600 hover:bg-rose-50">Eliminar</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <form method="POST" enctype="multipart/form-data" action="{{ route('editor-app.recursos.store') }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5 space-y-3 text-sm">
                    @csrf
                    <h3 class="text-base font-bold text-gray-900">Subir recurso</h3>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Nombre</label><input name="nombre" required maxlength="80" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm" placeholder="Cortinilla Opa, Comercial X"></div>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Archivo</label><input type="file" name="archivo" required accept=".mp4,.webm,.png,.jpg,.jpeg,.webp" class="w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700"></div>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Uso</label>
                        <select name="uso" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm">
                            <option value="publicidad">Publicidad (imagen o video al aire)</option>
                            <option value="intro">Intro (video antes de las cámaras)</option>
                            <option value="plantilla">Plantilla de video (PNG transparente)</option>
                        </select></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Duración (s)</label><input type="number" name="duracion" min="1" max="600" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm" placeholder="solo imágenes"></div>
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Orden</label><input type="number" name="orden" min="0" max="999" value="0" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                    </div>
                    <button class="w-full h-10 rounded-lg bg-[#00024f] text-white font-semibold text-sm hover:opacity-90 shadow-sm">Subir</button>
                    <p class="text-[11px] text-gray-400">Videos en MP4 (H.264 + AAC) 1920×1080, hasta 200 MB.</p>
                </form>
            </div>
        @endif

        <p class="text-[11px] text-gray-400 mt-6">
            Estos datos los lee el backend de esnoticia por <code>/api/paginas</code>, <code>/api/en-vivo/youtube</code> y <code>/api/cuentas</code> (token de integración).
        </p>
    </div>

    <script>
        // Selector de periodistas: "Todos" excluye a los demás; el resumen refleja la elección; se cierra al hacer clic fuera
        (function () {
            function resumir(d) {
                var todos = d.querySelector('input.todos').checked;
                var elegidos = Array.prototype.filter.call(d.querySelectorAll('input.uno'), function (c) { return c.checked; }).map(function (c) { return c.value; });
                var r = d.querySelector('.resumen');
                r.textContent = todos ? 'Todos los periodistas' : (elegidos.length ? elegidos.join(', ') : 'Nadie');
                r.className = 'resumen flex-1 min-w-0 truncate ' + (todos ? 'text-gray-700' : (elegidos.length ? 'text-indigo-700' : 'text-rose-600'));
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
@endsection
