@extends('layouts.app')

@section('content')
{{--
  Estudio en vivo (web): misma producción que la app del editor. La cámara de este navegador
  entra a la sala como "Cámara principal"; la escena (plantilla, rótulos, diseño) se compone en el
  servidor y sale a la vez a las páginas de Facebook y canales de YouTube elegidos.
--}}
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Producción</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Estudio en vivo</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">Transmite desde este navegador a tus páginas de Facebook y canales de YouTube a la vez, con plantilla, rótulos, cámaras invitadas y cortinillas.</p>
        </div>
    </div>

    @unless ($configurado)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">El servidor de transmisión (LiveKit) no está configurado en editus: faltan LIVEKIT_URL, LIVEKIT_API_KEY y LIVEKIT_API_SECRET en el .env.</div>
    @endunless
    @if (!empty($esCliente))
        @if ($limites)
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-900 px-4 py-3 mb-5 text-sm">Plan <strong>{{ $limites['nombre'] }}</strong>: hasta {{ $limites['camaras'] }} cámaras por transmisión, en las páginas de tu plan{{ empty($limites['plantillas_logo']) ? ', sin plantillas con logo' : '' }}. <a href="{{ route('suscripcion.index') }}" class="underline">Mi suscripción</a></div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">Necesitas un plan activo para transmitir. <a href="{{ route('suscripcion.index') }}" class="font-semibold underline">Ver planes</a></div>
        @endif
    @endif
    <div id="ev-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm"></div>

    {{-- =============================================== 1. NUEVA TRANSMISIÓN --}}
    <div id="vista-nueva" class="{{ $activa ? 'hidden' : '' }}">
        <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start">
            <form id="form-nueva" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-5 min-w-0">
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Título de la transmisión *</label>
                    <input name="titulo" required maxlength="200" placeholder="Ej: Rueda de prensa de la Gobernación" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Descripción</label>
                    <textarea name="descripcion" rows="2" maxlength="5000" placeholder="Lo que verán en Facebook y YouTube debajo del video" class="w-full rounded-lg border-gray-200 text-sm shadow-sm"></textarea>
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <label class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Páginas de Facebook</label>
                        @if (count($paginas) > 6)<input type="search" id="buscar-pagina" placeholder="Buscar página" class="h-8 w-44 rounded-lg border-gray-200 text-xs shadow-sm">@endif
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2 max-h-72 overflow-y-auto pr-1" id="lista-paginas">
                        @forelse ($paginas as $p)
                            <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40" data-nombre="{{ mb_strtolower($p['nombre']) }}">
                                <input type="checkbox" name="page_ids[]" value="{{ $p['page_id'] }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                                <img src="{{ $p['foto'] }}" alt="" class="h-8 w-8 rounded-full bg-gray-100 shrink-0">
                                <span class="text-sm font-medium text-gray-800 truncate">{{ $p['nombre'] }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-gray-500 sm:col-span-2">No tienes páginas conectadas. Conéctalas en <a href="{{ route('meta.pages.index') }}" class="text-indigo-700 underline">Mis páginas</a>.</p>
                        @endforelse
                    </div>
                </div>

                @if (count($canales))
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-2">Canales de YouTube</label>
                        <div class="grid sm:grid-cols-2 gap-2">
                            @foreach ($canales as $c)
                                <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-rose-300 has-[:checked]:bg-rose-50/40">
                                    <input type="checkbox" name="youtube_canal_ids[]" value="{{ $c['id'] }}" class="h-4 w-4 rounded border-gray-300 text-rose-600">
                                    <span class="h-8 w-8 rounded-full bg-rose-600 text-white text-xs font-bold flex items-center justify-center shrink-0">▶</span>
                                    <span class="text-sm font-medium text-gray-800 truncate">{{ $c['titulo'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <details class="rounded-xl border border-gray-200">
                    <summary class="cursor-pointer select-none px-3 py-2.5 text-sm font-semibold text-gray-700">Plantilla en pantalla</summary>
                    <div class="border-t border-gray-100 p-3 grid sm:grid-cols-3 gap-3">
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Etiqueta</label><input name="etiqueta" value="EN VIVO" maxlength="30" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                        @if (empty($esCliente) || !empty($limites['plantillas_logo']))
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Logo (texto)</label><input name="logo_texto" maxlength="40" placeholder="Opanoticias" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                        @else
                        <div class="text-[11px] text-gray-400 self-end pb-2">Logo: disponible en el plan Full</div>
                        @endif
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Color de la etiqueta</label><input type="color" name="color_etiqueta" value="#C8102E" class="w-full h-10 rounded-lg border-gray-200 shadow-sm"></div>
                        <div class="sm:col-span-2"><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Pie</label><input name="pie" maxlength="60" placeholder="www.opanoticias.com" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Hashtag</label><input name="hashtag" maxlength="40" placeholder="#Huila" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                    </div>
                </details>

                <div class="flex items-center justify-between gap-3 pt-1">
                    <p class="text-xs text-gray-500">Primero se abre el estudio (sala de espera). Sales al aire cuando pulses <strong>«Salir al aire»</strong>.</p>
                    <button id="btn-preparar" class="h-11 shrink-0 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90 disabled:opacity-50" @disabled(!$configurado || (!empty($esCliente) && !$limites))>Abrir el estudio</button>
                </div>
            </form>

            <div class="space-y-4 min-w-0">
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">Cómo funciona</h3>
                    @foreach ([['1', 'Abre el estudio', 'Elige las páginas y canales. Se prepara la sala sin salir al aire.'], ['2', 'Prepara la escena', 'Tu cámara, cámaras invitadas por enlace, diseño, rótulos y cortinillas. Lo ves en el monitor de programa.'], ['3', 'Sal al aire', 'Se crea el en vivo en cada página y canal, y la escena sale a todos a la vez.'], ['4', 'Termina', 'El video queda publicado en cada página con su enlace.']] as [$n, $t, $d])
                        <div class="flex gap-3 mb-3 last:mb-0"><span class="h-6 w-6 shrink-0 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">{{ $n }}</span><div><div class="text-sm font-semibold text-gray-800">{{ $t }}</div><div class="text-xs text-gray-500">{{ $d }}</div></div></div>
                    @endforeach
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-2">Transmisiones anteriores</h3>
                    @forelse ($historial as $h)
                        <div class="py-2 border-b border-gray-100 last:border-0">
                            <div class="flex items-center justify-between gap-2"><span class="text-sm font-medium text-gray-800 truncate">{{ $h['titulo'] }}</span>
                                <span class="text-[11px] font-semibold {{ $h['estado'] === 'error' ? 'text-rose-700' : 'text-gray-500' }}">{{ $h['estado'] === 'error' ? 'error' : 'terminada' }}</span></div>
                            <div class="text-[11px] text-gray-400">{{ $h['fecha'] }} · {{ implode(', ', $h['paginas']) }}</div>
                            @foreach ($h['destinos'] as $d)@if (!empty($d['permalink']))<a href="{{ $d['permalink'] }}" target="_blank" rel="noopener" class="inline-block mr-2 text-[11px] text-indigo-700 hover:underline">Ver en {{ $d['pagina'] }}</a>@endif @endforeach
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Aún no hay transmisiones desde la web.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- =============================================== 2. ESTUDIO --}}
    <div id="vista-estudio" class="{{ $activa ? '' : 'hidden' }}">
        {{-- Barra de estado --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm px-4 py-3 mb-4 flex flex-wrap items-center gap-3">
            <span id="ev-estado" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 text-gray-700 px-3 py-1 text-xs font-bold tracking-wide">SALA DE ESPERA</span>
            <span id="ev-reloj" class="hidden font-mono text-sm font-semibold text-rose-700">00:00</span>
            <div class="min-w-0 flex-1"><div id="ev-titulo" class="text-sm font-bold text-gray-900 truncate"></div><div id="ev-destinos" class="flex flex-wrap gap-1.5 mt-1"></div></div>
            <div class="flex items-center gap-2 ml-auto">
                <div id="ev-espectadores" class="hidden text-right mr-2"><div class="text-[10px] uppercase tracking-wide text-gray-400">Espectadores</div><div class="text-lg font-bold text-gray-900 leading-tight" id="ev-espectadores-n">0</div></div>
                <select id="ev-intro" class="h-10 rounded-lg border-gray-200 text-sm shadow-sm max-w-[12rem]">
                    <option value="">Sin intro</option>
                    @foreach ($recursos as $r)<option value="{{ $r['id'] }}">Intro: {{ $r['nombre'] }}</option>@endforeach
                </select>
                <button id="btn-aire" class="h-10 rounded-xl bg-rose-600 text-white px-5 text-sm font-bold shadow-sm hover:bg-rose-700 disabled:opacity-50">● Salir al aire</button>
                <button id="btn-terminar" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">Terminar</button>
            </div>
        </div>

        <div class="grid xl:grid-cols-[minmax(0,1fr)_22rem] gap-4 items-start">
            <div class="space-y-4 min-w-0">
                {{-- Monitor de programa --}}
                <div class="rounded-2xl bg-[#0b1c33] p-3 shadow-sm">
                    <div class="flex items-center justify-between mb-2 px-1"><span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-200">Programa · lo que ve la audiencia</span><span class="text-[11px] text-indigo-300" id="ev-egress"></span></div>
                    <div class="relative w-full aspect-video rounded-xl overflow-hidden bg-black"><iframe id="ev-monitor" class="absolute inset-0 w-full h-full" allow="autoplay" title="Monitor de programa"></iframe></div>
                </div>

                {{-- Diseño --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <h3 class="text-sm font-bold text-gray-900">Diseño y cámaras</h3>
                        <div class="grid grid-cols-2 sm:inline-flex w-full sm:w-auto rounded-xl bg-gray-100 p-1 gap-1" id="ev-layouts">
                            @foreach (['solo' => 'Una cámara', 'pip' => 'Imagen en imagen', 'dos' => 'Dos cámaras', 'cuadricula' => 'Cuadrícula'] as $k => $et)
                                <button type="button" data-layout="{{ $k }}" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600">{{ $et }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div id="ev-camaras" class="divide-y divide-gray-100"></div>
                    <p class="text-[11px] text-gray-400 mt-2">● principal = la cámara grande · ☐ visible = entra en los diseños de varias cámaras.</p>
                </div>

                {{-- Rótulos y plantilla --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4">
                    <div class="flex items-center justify-between gap-3 mb-3"><h3 class="text-sm font-bold text-gray-900">Rótulos y plantilla</h3>
                        <label class="inline-flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" id="pl-mostrar" class="h-4 w-4 rounded border-gray-300 text-indigo-600"> Mostrar plantilla</label></div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Título en pantalla</label><input id="pl-titulo" maxlength="140" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Rótulo: nombre</label><input id="pl-rotulo-nombre" maxlength="60" placeholder="Ana Pérez" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Rótulo: cargo</label><input id="pl-rotulo-cargo" maxlength="60" placeholder="Periodista" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                        <label class="inline-flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" id="pl-rotulo-mostrar" class="h-4 w-4 rounded border-gray-300 text-indigo-600"> Mostrar el rótulo</label>
                        <button type="button" id="btn-plantilla" class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold">Aplicar en pantalla</button>
                    </div>
                </div>
            </div>

            <div class="space-y-4 min-w-0">
                {{-- Mi cámara --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4">
                    <div class="flex items-center justify-between mb-2"><h3 class="text-sm font-bold text-gray-900">Mi cámara</h3><span id="cam-estado" class="text-[11px] font-semibold text-gray-400">desconectada</span></div>
                    <div class="relative w-full aspect-video rounded-xl overflow-hidden bg-black"><video id="cam-video" autoplay playsinline muted class="w-full h-full object-cover" style="transform: scaleX(-1)"></video></div>
                    <div class="grid grid-cols-2 gap-2 mt-3">
                        <select id="cam-dispositivo" class="h-9 rounded-lg border-gray-200 text-xs shadow-sm" title="Cámara"></select>
                        <select id="mic-dispositivo" class="h-9 rounded-lg border-gray-200 text-xs shadow-sm" title="Micrófono"></select>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mt-2">
                        <button type="button" id="btn-cam" class="h-9 rounded-lg border border-gray-200 bg-white text-xs font-semibold text-gray-700">Cámara</button>
                        <button type="button" id="btn-mic" class="h-9 rounded-lg border border-gray-200 bg-white text-xs font-semibold text-gray-700">Micrófono</button>
                        <button type="button" id="btn-pantalla" class="h-9 rounded-lg border border-gray-200 bg-white text-xs font-semibold text-gray-700">Pantalla</button>
                    </div>
                </div>

                {{-- Invitar --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4">
                    <h3 class="text-sm font-bold text-gray-900 mb-1">Invitar una cámara</h3>
                    <p class="text-xs text-gray-500 mb-3">Envía el enlace a un reportero o invitado: abre su cámara desde el celular o el computador, sin instalar nada.@if (!empty($esCliente) && $limites) Tu plan permite {{ $limites['camaras'] }} cámaras en total (incluida la tuya).@endif</p>
                    <div class="flex gap-2"><input id="inv-nombre" maxlength="60" placeholder="Nombre (opcional)" class="flex-1 min-w-0 h-9 rounded-lg border-gray-200 text-xs shadow-sm">
                        <button type="button" data-invitar="camara" class="h-9 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">Cámara</button>
                        <button type="button" data-invitar="pantalla" class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700">Pantalla</button></div>
                    <div id="inv-lista" class="mt-3 space-y-2"></div>
                </div>

                {{-- Recursos --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4">
                    <div class="flex items-center justify-between mb-2"><h3 class="text-sm font-bold text-gray-900">Cortinillas y comerciales</h3><button type="button" id="btn-quitar-recurso" class="text-[11px] font-semibold text-rose-700 hover:underline">Quitar del aire</button></div>
                    @forelse ($recursos as $r)
                        <div class="flex items-center justify-between gap-2 py-1.5 border-b border-gray-100 last:border-0">
                            <span class="min-w-0"><span class="block text-sm text-gray-800 truncate">{{ $r['nombre'] }}</span><span class="block text-[11px] text-gray-400">{{ $r['tipo'] }}{{ $r['duracion'] ? ' · ' . $r['duracion'] . ' s' : '' }}</span></span>
                            <button type="button" data-recurso="{{ $r['id'] }}" class="shrink-0 h-8 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">Al aire</button>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">No hay recursos. Se suben desde la app del editor.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
<script>
(function () {
  const RUTAS = {
    preparar: @json(route('en-vivo.web.preparar')),
    base: @json(url('/en-vivo')),
  };
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const g = (id) => document.getElementById(id);
  const esc = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const pedir = async (metodo, url, body) => {
    let r;
    try { r = await fetch(url, { method: metodo, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined }); }
    catch (e) { throw new Error('Sin conexión con el servidor de editus. Revisa tu internet y vuelve a intentar.'); }
    let j = null; try { j = await r.json(); } catch (e) {}
    if (!j) throw new Error('El servidor no respondió (HTTP ' + r.status + ')');
    if (j.success === false) throw new Error(j.error || 'Error');
    if (!r.ok && j.message) throw new Error(j.message);
    return j;
  };
  const error = (msg) => { const e = g('ev-error'); if (!msg) { e.classList.add('hidden'); return; } e.textContent = msg; e.classList.remove('hidden'); e.scrollIntoView({ behavior: 'smooth', block: 'center' }); };

  let T = @json($activa);          // transmisión actual
  let room = null, camOn = true, micOn = true, pantallaOn = false;
  let participantes = [], relojInicio = null, sondeo = null;

  // ------------------------------------------------ búsqueda de páginas
  const bp = g('buscar-pagina');
  if (bp) bp.addEventListener('input', () => { const q = bp.value.toLowerCase(); document.querySelectorAll('#lista-paginas [data-nombre]').forEach(e => e.style.display = !q || e.dataset.nombre.includes(q) ? '' : 'none'); });

  // ------------------------------------------------ 1. preparar
  g('form-nueva').addEventListener('submit', async (ev) => {
    ev.preventDefault(); error(null);
    const f = new FormData(ev.target);
    const datos = {
      titulo: f.get('titulo'), descripcion: f.get('descripcion') || '',
      page_ids: f.getAll('page_ids[]'), youtube_canal_ids: f.getAll('youtube_canal_ids[]').map(Number),
      plantilla: { etiqueta: f.get('etiqueta'), logo_texto: f.get('logo_texto'), color_etiqueta: f.get('color_etiqueta'), pie: f.get('pie'), hashtag: f.get('hashtag') },
    };
    if (!datos.page_ids.length && !datos.youtube_canal_ids.length) return error('Elige al menos una página de Facebook o un canal de YouTube.');
    const b = g('btn-preparar'); b.disabled = true; b.textContent = 'Abriendo el estudio…';
    try {
      const j = await pedir('POST', RUTAS.preparar, datos);
      T = j.transmision; abrirEstudio(j.livekit, j.monitor);
    } catch (e) { error(e.message); }
    b.disabled = false; b.textContent = 'Abrir el estudio';
  });

  // ------------------------------------------------ 2. estudio
  const url = (accion) => RUTAS.base + '/' + T.id + '/' + accion;

  async function abrirEstudio(livekit, monitor) {
    g('vista-nueva').classList.add('hidden'); g('vista-estudio').classList.remove('hidden');
    pintarTransmision();
    if (!livekit) { try { const j = await pedir('GET', url('estado')); livekit = j.livekit; monitor = j.monitor; aplicarEstado(j); } catch (e) { error(e.message); } }
    if (monitor && monitor.url) g('ev-monitor').src = monitor.url;
    if (livekit) conectar(livekit);
    clearInterval(sondeo); sondeo = setInterval(sondear, 5000); sondear();
  }

  async function conectar(lk) {
    const { Room, RoomEvent, Track } = LivekitClient;
    try {
      room = new Room({ adaptiveStream: true, dynacast: true, videoCaptureDefaults: { resolution: { width: 1920, height: 1080, frameRate: 30 } }, publishDefaults: { videoEncoding: { maxBitrate: 3500000, maxFramerate: 30 }, simulcast: false } });
      room.on(RoomEvent.Disconnected, () => { g('cam-estado').textContent = 'desconectada'; });
      room.on(RoomEvent.LocalTrackPublished, adjuntarCamara);
      await room.connect(lk.url, lk.token);
      await room.localParticipant.enableCameraAndMicrophone();
      adjuntarCamara();
      g('cam-estado').textContent = 'conectada'; g('cam-estado').className = 'text-[11px] font-semibold text-emerald-600';
      await listarDispositivos();
    } catch (e) { error('No se pudo abrir la cámara o conectar con el estudio: ' + e.message + '. Revisa los permisos de cámara y micrófono del navegador.'); }
  }
  function adjuntarCamara() {
    if (!room) return;
    const pub = room.localParticipant.getTrackPublication(LivekitClient.Track.Source.Camera);
    if (pub && pub.track) pub.track.attach(g('cam-video'));
  }
  async function listarDispositivos() {
    const llenar = async (tipo, sel) => {
      const lista = await LivekitClient.Room.getLocalDevices(tipo);
      sel.innerHTML = lista.map(d => '<option value="' + esc(d.deviceId) + '">' + esc(d.label || tipo) + '</option>').join('');
      const actual = room.getActiveDevice(tipo); if (actual) sel.value = actual;
      sel.onchange = () => room.switchActiveDevice(tipo, sel.value);
    };
    await llenar('videoinput', g('cam-dispositivo')); await llenar('audioinput', g('mic-dispositivo'));
  }
  const marcar = (btn, on, texto) => { btn.textContent = texto + (on ? '' : ' (apagada)'); btn.classList.toggle('bg-rose-50', !on); btn.classList.toggle('text-rose-700', !on); };
  g('btn-cam').onclick = async () => { if (!room) return; camOn = !camOn; await room.localParticipant.setCameraEnabled(camOn); marcar(g('btn-cam'), camOn, 'Cámara'); };
  g('btn-mic').onclick = async () => { if (!room) return; micOn = !micOn; await room.localParticipant.setMicrophoneEnabled(micOn); g('btn-mic').textContent = micOn ? 'Micrófono' : 'Micrófono (silenciado)'; g('btn-mic').classList.toggle('text-rose-700', !micOn); };
  g('btn-pantalla').onclick = async () => {
    if (!room) return;
    try { pantallaOn = !pantallaOn; await room.localParticipant.setScreenShareEnabled(pantallaOn, { audio: true, resolution: { width: 1920, height: 1080, frameRate: 15 } }); }
    catch (e) { pantallaOn = false; if (e && e.name !== 'NotAllowedError') error('No se pudo compartir la pantalla: ' + e.message); }
    g('btn-pantalla').textContent = pantallaOn ? 'Dejar pantalla' : 'Pantalla'; pintarCamaras();
  };

  // ------------------------------------------------ escena
  async function escena(cambios) {
    try { const j = await pedir('POST', url('escena'), cambios); T.escena = j.escena; pintarCamaras(); } catch (e) { error(e.message); }
  }
  document.querySelectorAll('[data-layout]').forEach(b => b.onclick = () => {
    const visibles = (T.escena.visibles && T.escena.visibles.length) ? T.escena.visibles : fuentes().map(f => f.identity).slice(0, 4);
    escena({ layout: b.dataset.layout, visibles });
  });
  function fuentes() {
    const lista = participantes.slice();
    if (pantallaOn) lista.push({ identity: 'camara-principal#pantalla', nombre: 'Mi pantalla', video: true, audio: false });
    if (!lista.some(p => p.identity === 'camara-principal')) lista.unshift({ identity: 'camara-principal', nombre: 'Cámara principal (este navegador)', video: camOn, audio: micOn });
    return lista;
  }
  function pintarCamaras() {
    const e = T.escena || {};
    document.querySelectorAll('[data-layout]').forEach(b => { const on = b.dataset.layout === (e.layout || 'solo'); b.classList.toggle('bg-white', on); b.classList.toggle('shadow-sm', on); b.classList.toggle('text-[#00024f]', on); });
    const visibles = e.visibles || [];
    g('ev-camaras').innerHTML = fuentes().map(p => {
      const principal = (e.principal || 'camara-principal') === p.identity;
      return '<div class="flex items-center gap-3 py-2">'
        + '<button type="button" data-principal="' + esc(p.identity) + '" title="Principal" class="h-5 w-5 rounded-full border-2 ' + (principal ? 'border-rose-600 bg-rose-600' : 'border-gray-300') + '"></button>'
        + '<span class="min-w-0 flex-1"><span class="block text-sm font-medium text-gray-800 truncate">' + esc(p.nombre) + (principal ? ' <span class="text-[10px] font-bold text-rose-600">AL AIRE</span>' : '') + '</span>'
        + '<span class="block text-[11px] text-gray-400">' + (p.video ? 'video' : 'sin video') + ' · ' + (p.audio ? 'audio' : 'sin audio') + '</span></span>'
        + '<label class="inline-flex items-center gap-1 text-[11px] text-gray-600"><input type="checkbox" data-visible="' + esc(p.identity) + '" ' + (visibles.includes(p.identity) ? 'checked' : '') + ' class="h-4 w-4 rounded border-gray-300 text-indigo-600"> visible</label>'
        + (p.identity.startsWith('invitado-') ? '<button type="button" data-expulsar="' + esc(p.identity) + '" class="text-[11px] text-rose-700 hover:underline">sacar</button>' : '')
        + '</div>';
    }).join('');
    g('ev-camaras').querySelectorAll('[data-principal]').forEach(b => b.onclick = () => escena({ principal: b.dataset.principal }));
    g('ev-camaras').querySelectorAll('[data-visible]').forEach(c => c.onchange = () => {
      const set = new Set(T.escena.visibles || []); c.checked ? set.add(c.dataset.visible) : set.delete(c.dataset.visible); escena({ visibles: Array.from(set) });
    });
    g('ev-camaras').querySelectorAll('[data-expulsar]').forEach(b => b.onclick = async () => { if (!confirm('¿Sacar esta cámara de la transmisión?')) return; try { await pedir('POST', url('participantes/' + encodeURIComponent(b.dataset.expulsar) + '/expulsar')); } catch (e) { error(e.message); } });
  }

  // ------------------------------------------------ plantilla, recursos, invitaciones
  g('btn-plantilla').onclick = async () => {
    try {
      const j = await pedir('POST', url('plantilla'), { plantilla: { mostrar: g('pl-mostrar').checked, titulo: g('pl-titulo').value, rotulo_nombre: g('pl-rotulo-nombre').value, rotulo_cargo: g('pl-rotulo-cargo').value, rotulo_mostrar: g('pl-rotulo-mostrar').checked } });
      T.plantilla = j.plantilla; error(null);
    } catch (e) { error(e.message); }
  };
  document.querySelectorAll('[data-recurso]').forEach(b => b.onclick = () => escena({ recurso_id: Number(b.dataset.recurso) }));
  g('btn-quitar-recurso').onclick = () => escena({ quitar_recurso: true });
  document.querySelectorAll('[data-invitar]').forEach(b => b.onclick = async () => {
    try { await pedir('POST', url('invitacion'), { nombre: g('inv-nombre').value || null, modo: b.dataset.invitar }); g('inv-nombre').value = ''; await sondear(); } catch (e) { error(e.message); }
  });
  function pintarInvitaciones() {
    g('inv-lista').innerHTML = (T.invitaciones || []).slice().reverse().map(i => '<div class="flex items-center gap-2 rounded-lg bg-gray-50 px-2 py-1.5"><span class="text-[11px] font-semibold text-gray-700 shrink-0">' + esc(i.nombre || (i.modo === 'pantalla' ? 'Pantalla' : 'Invitado')) + '</span>'
      + '<input readonly value="' + esc(i.url) + '" class="flex-1 min-w-0 h-7 rounded border-gray-200 text-[11px] bg-white"><button type="button" data-copiar="' + esc(i.url) + '" class="text-[11px] font-semibold text-indigo-700">Copiar</button></div>').join('');
    g('inv-lista').querySelectorAll('[data-copiar]').forEach(b => b.onclick = () => { navigator.clipboard.writeText(b.dataset.copiar); b.textContent = '¡Copiado!'; setTimeout(() => b.textContent = 'Copiar', 1500); });
  }

  // ------------------------------------------------ al aire / terminar
  g('btn-aire').onclick = async () => {
    if (!confirm('¿Salir al aire ahora en ' + (T.paginas || []).length + ' destino(s)?')) return;
    const b = g('btn-aire'); b.disabled = true; b.textContent = 'Saliendo al aire…'; error(null);
    try { const intro = g('ev-intro').value; const j = await pedir('POST', url('aire'), intro ? { intro_recurso_id: Number(intro) } : {}); T = j.transmision; pintarTransmision(); }
    catch (e) { error('No se pudo salir al aire: ' + e.message); }
    b.disabled = false; b.textContent = '● Salir al aire';
  };
  g('btn-terminar').onclick = async () => {
    if (!confirm(T.estado === 'en_vivo' ? '¿Terminar la transmisión en todas las páginas?' : '¿Cerrar el estudio sin salir al aire?')) return;
    try {
      const j = await pedir('POST', url('terminar')); T = j.transmision; clearInterval(sondeo);
      if (room) await room.disconnect();
      const enlaces = (T.destinos || []).filter(d => d.permalink).map(d => d.pagina + ': ' + d.permalink).join('\n');
      alert('Transmisión terminada.' + (enlaces ? '\n\n' + enlaces : '')); location.reload();
    } catch (e) { error(e.message); }
  };

  // ------------------------------------------------ estado
  async function sondear() {
    if (!T) return;
    try {
      const [est, par] = await Promise.all([pedir('GET', url('estado')), pedir('GET', url('participantes')).catch(() => null)]);
      aplicarEstado(est);
      if (par) { participantes = par.participantes || []; if (par.escena) T.escena = par.escena; pintarCamaras(); }
    } catch (e) { /* reintenta en el próximo ciclo */ }
  }
  function aplicarEstado(j) {
    T = j.transmision; pintarTransmision();
    const fb = j.facebook || {};
    if (T.estado === 'en_vivo') { g('ev-espectadores').classList.remove('hidden'); g('ev-espectadores-n').textContent = fb.espectadores ?? '—'; }
    g('ev-egress').textContent = j.egress ? 'salida: ' + String(j.egress).replace('EGRESS_', '').toLowerCase() : '';
    if (T.estado === 'error') error(T.error || 'La transmisión tuvo un error.');
  }
  let primeraVez = true;
  function pintarTransmision() {
    if (!T) return;
    g('ev-titulo').textContent = T.titulo;
    const enVivo = T.estado === 'en_vivo';
    const e = g('ev-estado'); e.textContent = enVivo ? '● EN VIVO' : (T.estado === 'sala' ? 'SALA DE ESPERA' : T.estado.toUpperCase());
    e.className = 'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold tracking-wide ' + (enVivo ? 'bg-rose-600 text-white' : 'bg-gray-100 text-gray-700');
    g('btn-aire').classList.toggle('hidden', T.estado !== 'sala'); g('ev-intro').classList.toggle('hidden', T.estado !== 'sala');
    g('btn-terminar').textContent = enVivo ? 'Terminar transmisión' : 'Cerrar estudio';
    g('ev-destinos').innerHTML = (T.destinos || []).map(d => {
      const color = d.estado === 'ok' ? 'bg-emerald-50 text-emerald-700' : (d.estado === 'error' ? 'bg-rose-50 text-rose-700' : 'bg-gray-100 text-gray-600');
      const icono = (d.red === 'youtube') ? '▶ ' : 'f ';
      const cont = esc(icono + d.pagina) + (d.error ? ' · ' + esc(d.error).slice(0, 80) : '');
      return d.permalink ? '<a href="' + esc(d.permalink) + '" target="_blank" rel="noopener" class="rounded-md px-2 py-0.5 text-[11px] font-semibold ' + color + ' hover:underline">' + cont + ' ↗</a>' : '<span class="rounded-md px-2 py-0.5 text-[11px] font-semibold ' + color + '">' + cont + '</span>';
    }).join('');
    if (enVivo && T.iniciada_en && !relojInicio) { relojInicio = new Date(T.iniciada_en); g('ev-reloj').classList.remove('hidden'); }
    if (primeraVez && T.plantilla) {
      primeraVez = false; const p = T.plantilla;
      g('pl-mostrar').checked = p.mostrar !== false; g('pl-titulo').value = p.titulo || ''; g('pl-rotulo-nombre').value = p.rotulo_nombre || ''; g('pl-rotulo-cargo').value = p.rotulo_cargo || ''; g('pl-rotulo-mostrar').checked = !!p.rotulo_mostrar;
    }
    pintarInvitaciones(); pintarCamaras();
  }
  setInterval(() => { if (!relojInicio) return; const s = Math.max(0, Math.floor((Date.now() - relojInicio) / 1000)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60; g('ev-reloj').textContent = (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0'); }, 1000);
  window.addEventListener('beforeunload', (ev) => { if (T && T.estado === 'en_vivo') { ev.preventDefault(); ev.returnValue = ''; } });

  if (T) abrirEstudio(null, null);
})();
</script>
@endsection
