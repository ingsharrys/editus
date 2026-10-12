@extends('layouts.app')

@section('content')
{{--
  Pregrabados programados: se sube el video ya editado y sale EN VIVO solo a la fecha y hora
  elegidas. El archivo va del navegador directo al emisor del VPS, por partes.
--}}
@php
    $conLogo = empty($esCliente) || !empty($limites['plantillas_logo']);
    $minimo = now()->addMinutes(15)->format('Y-m-d\TH:i');
    $sugerido = now()->addHours(2)->minute(0)->format('Y-m-d\TH:i');
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Producción</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Pregrabados programados</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">Sube un video ya editado y elige la fecha y la hora: sale <strong>en vivo</strong> solo en tus páginas de Facebook y canales de YouTube, aunque no estés conectado.</p>
        </div>
        @include('en-vivo._pestanas')
    </div>

    @unless ($configurado)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">El servidor de pregrabados no está configurado: instala el emisor en el VPS (<code>infra/en-vivo/instalar-emisor.sh</code>) y agrega <code>EMISOR_URL</code> y <code>EMISOR_SECRETO</code> al .env de editus.</div>
    @endunless
    @if (!empty($esCliente))
        @if ($limites)
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-900 px-4 py-3 mb-5 text-sm">Plan <strong>{{ $limites['nombre'] }}</strong>: videos de hasta {{ $limites['pregrabado_horas'] }} horas{{ $limites['pregrabados_cola'] ? ', ' . $limites['pregrabados_cola'] . ' programados a la vez' : ', sin límite de programados' }}{{ empty($limites['plantillas_logo']) ? ', sin logo ni marco' : '' }}. <a href="{{ route('suscripcion.index') }}" class="underline">Mi suscripción</a></div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">Necesitas un plan activo para programar pregrabados. <a href="{{ route('suscripcion.index') }}" class="font-semibold underline">Ver planes</a></div>
        @endif
    @endif
    <div id="pg-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm"></div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start">
        {{-- ===================================================== FORMULARIO --}}
        <form id="pg-form" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-5 min-w-0" novalidate>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Video *</label>
                <label id="pg-zona" class="flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-7 text-center cursor-pointer hover:border-indigo-300 hover:bg-indigo-50/40 transition">
                    <input type="file" id="pg-archivo" accept="video/mp4,video/quicktime,video/x-matroska,video/webm,.mp4,.mov,.mkv,.webm" class="sr-only">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="text-indigo-500"><path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                    <span class="text-sm font-semibold text-gray-800" id="pg-zona-texto">Elige o arrastra el video editado</span>
                    <span class="text-xs text-gray-500">MP4, MOV, MKV o WEBM · hasta {{ round($maxBytes / 1024 ** 3) }} GB{{ $limites ? ' · hasta ' . $limites['pregrabado_horas'] . ' h' : '' }}</span>
                </label>
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Título *</label>
                <input name="titulo" required maxlength="200" placeholder="Ej: Especial: así avanza el plan de seguridad en Neiva" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Descripción</label>
                <textarea name="descripcion" rows="2" maxlength="5000" placeholder="Lo que verán en Facebook y YouTube debajo del video" class="w-full rounded-lg border-gray-200 text-sm shadow-sm"></textarea>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Fecha y hora de salida *</label>
                    <input type="datetime-local" name="programada_en" required min="{{ $minimo }}" value="{{ $sugerido }}" step="60" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Hora de Colombia ({{ $zona }}). Deja al menos 30 min para un video de 1 hora: hay que subirlo y prepararlo.</p>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Calidad</label>
                    <select name="calidad" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
                        <option value="auto">Automática (1080p si el video lo es)</option>
                        <option value="720">720p (más liviano)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-2">Páginas de Facebook</label>
                <div class="grid sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto pr-1">
                    @forelse ($paginas as $p)
                        <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                            <input type="checkbox" name="meta_page_ids[]" value="{{ $p['id'] }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600">
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
                                <span class="h-8 w-8 rounded-full bg-rose-600 text-white grid place-items-center text-xs font-bold shrink-0">▶</span>
                                <span class="text-sm font-medium text-gray-800 truncate">{{ $c['nombre'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-xl border border-gray-200 p-4 space-y-3">
                <div class="text-sm font-bold text-gray-800">Plantilla</div>
                @if ($conLogo)
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Logo</label>
                            <select name="logo_id" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                                <option value="">Sin logo</option>
                                @foreach ($logos as $l)<option value="{{ $l['id'] }}">{{ $l['nombre'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Marco (PNG 1920×1080)</label>
                            <select name="marco_id" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                                <option value="">Sin marco</option>
                                @foreach ($marcos as $m)<option value="{{ $m['id'] }}">{{ $m['nombre'] }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                @else
                    <p class="text-xs text-gray-500">Logo y marco: disponibles en el plan Full.</p>
                @endif
<div class="">
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Miniatura del anuncio (opcional)</label>
                            <input type="file" name="miniatura_archivo" accept="image/jpeg,image/png" class="block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-200">
                            <p class="text-[11px] text-gray-400 mt-1">JPG o PNG de 1280×720, hasta 2 MB. Va en la publicación de anuncio de Facebook y en YouTube antes de empezar (en YouTube, si el canal está verificado).</p>
                        </div>
                <label class="flex items-start gap-2.5 text-sm text-gray-700"><input type="checkbox" name="anuncio_facebook" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span><strong>Publicar anuncio en Facebook</strong>. <span class="text-gray-500">Meta ya no permite programar en vivos desde aplicaciones: publicamos un post con la fecha y la hora (se borra si cancelas). El en vivo se crea solo a la hora.</span></span></label>
                <label class="flex items-start gap-2.5 text-sm text-gray-700"><input type="checkbox" name="etiqueta" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span><strong>Etiqueta «PREGRABADO»</strong> en una esquina. <span class="text-gray-500">Facebook exige que el contenido pregrabado se distinga del que es en vivo.</span></span></label>
                <label class="flex items-start gap-2.5 text-sm text-gray-700"><input type="checkbox" name="cuenta" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span><strong>Cuenta regresiva</strong> de 1 minuto antes de la hora, con el título y el logo.</span></label>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3 pt-1">
                <span class="text-xs text-gray-400">No cierres esta pestaña mientras sube el video.</span>
                <button type="submit" id="pg-boton" class="h-11 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm disabled:opacity-50" @disabled(!$configurado || (!empty($esCliente) && !$limites))>Subir y programar</button>
            </div>

            {{-- Avance de la subida --}}
            <div id="pg-subida" class="hidden rounded-xl border border-indigo-100 bg-indigo-50/50 p-4">
                <div class="flex items-center justify-between gap-3 text-sm"><span class="font-semibold text-gray-800" id="pg-subida-titulo">Subiendo…</span><span class="text-gray-500 whitespace-nowrap" id="pg-subida-dato"></span></div>
                <div class="mt-2 h-2.5 w-full rounded-full bg-white overflow-hidden"><div id="pg-subida-barra" class="h-2.5 rounded-full bg-[#00024f] transition-all" style="width:0%"></div></div>
            </div>
        </form>

        {{-- ================================================== CÓMO FUNCIONA --}}
        <aside class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 text-sm">
            <h3 class="font-bold text-gray-900 mb-3">Cómo funciona</h3>
            <ol class="space-y-3">
                @foreach ([
                    ['Subes el video', 'Va directo al servidor de transmisión, por partes. Si se corta, vuelves a elegir el mismo archivo y continúa.'],
                    ['Se prepara una sola vez', 'Formato de transmisión, logo, marco y etiqueta. Un video de 1 hora tarda unos 30 a 60 minutos.'],
                    ['Se anuncia', 'Apenas el video está listo se publica un anuncio en tu página de Facebook (con la miniatura, la fecha y la hora) y la transmisión próxima en YouTube.'],
                    ['Sale en vivo solo', 'Un minuto antes empieza la cuenta regresiva y, a la hora, el video. Al terminar se cierra y queda publicado.'],
                ] as $i => [$t, $d])
                    <li class="flex gap-3"><span class="shrink-0 h-6 w-6 rounded-full bg-[#00024f] text-white grid place-items-center text-xs font-bold">{{ $i + 1 }}</span><span><b class="text-gray-800">{{ $t }}.</b> <span class="text-gray-600">{{ $d }}</span></span></li>
                @endforeach
            </ol>
            <div class="mt-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 p-3 text-xs">Facebook pide que la página tenga al menos 100 seguidores y 60 días para salir en vivo. Instagram no permite en vivos desde aplicaciones externas.</div>
            <p class="mt-3 text-xs text-gray-400">El archivo se borra del servidor {{ config('services.emisor.dias_borrado', 7) }} días después de emitido; el video queda publicado en Facebook y YouTube.</p>
        </aside>
    </div>

    {{-- ===================================================== PROGRAMADOS --}}
    <div class="mt-8">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-bold text-gray-900">Programados e historial</h2>
            <span class="text-xs text-gray-400" id="pg-actualizado"></span>
        </div>
        <div id="pg-lista" class="grid md:grid-cols-2 xl:grid-cols-3 gap-4"></div>
    </div>
</div>

<script>
(function () {
  const RUTAS = {
    crear: @json(route('pregrabados.store')), estado: @json(route('pregrabados.estado')),
    base: @json(url('/en-vivo/pregrabados')),
  };
  const MAX = @json($maxBytes);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const g = (id) => document.getElementById(id);
  const esc = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const tam = (b) => b >= 1024 ** 3 ? (b / 1024 ** 3).toFixed(2) + ' GB' : (b / 1024 ** 2).toFixed(0) + ' MB';
  const dur = (s) => { s = Math.round(s || 0); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? `${h} h ${m} min` : `${m} min ${s % 60} s`; };
  const post = async (url, body) => { const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) }); const d = await r.json().catch(() => ({})); if (!r.ok || d.success === false) throw new Error(d.error || d.message || ('Error ' + r.status)); return d; };
  const error = (t) => { const e = g('pg-error'); e.textContent = t || ''; e.classList.toggle('hidden', !t); if (t) e.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
  let lista = @json($emisiones);
  let subiendo = null; // id de la emisión que se está subiendo desde esta pestaña

  // ------------------------------------------------------------ archivo
  const entrada = g('pg-archivo'), zona = g('pg-zona');
  const mostrarArchivo = () => { const f = entrada.files[0]; g('pg-zona-texto').textContent = f ? `${f.name} · ${tam(f.size)}` : 'Elige o arrastra el video editado';
    if (f && !g('pg-form').titulo.value) g('pg-form').titulo.value = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').slice(0, 200); };
  entrada.addEventListener('change', mostrarArchivo);
  ['dragover', 'dragenter'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('border-indigo-400'); }));
  ['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.remove('border-indigo-400'); }));
  zona.addEventListener('drop', e => { if (e.dataTransfer.files.length) { entrada.files = e.dataTransfer.files; mostrarArchivo(); } });

  // --------------------------------------------- subida por partes (XHR)
  const PARTE = 16 * 1024 * 1024;
  const enviarParte = (url, archivo, ini, fin, alAvance) => new Promise((resolve) => {
    const x = new XMLHttpRequest();
    x.open('PUT', url);
    x.setRequestHeader('Content-Range', `bytes ${ini}-${fin}/${archivo.size}`);
    x.upload.onprogress = (e) => alAvance(ini + e.loaded);
    x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch {} resolve({ status: x.status, d }); };
    x.onerror = () => resolve({ status: 0, d: {} });
    x.send(archivo.slice(ini, fin + 1));
  });
  async function subir(url, archivo, alAvance) {
    let pos = 0, r = null, d = {};
    try { r = await fetch(url); d = await r.json(); } catch { /* sin respuesta: se empieza por la primera parte */ }
    if (r && r.status === 403) throw new Error(d.error || 'El permiso de subida venció.');
    if (r && r.ok) pos = d.recibido || 0;
    while (pos < archivo.size) {
      const fin = Math.min(archivo.size, pos + PARTE) - 1;
      let intento = 0;
      for (;;) {
        const { status, d } = await enviarParte(url, archivo, pos, fin, alAvance);
        if (status === 200) { pos = d.recibido; break; }
        if (status === 409 && typeof d.recibido === 'number') { pos = d.recibido; break; }
        if ([403, 413, 507].includes(status)) throw new Error(d.error || 'El servidor rechazó la subida.');
        if (++intento > 8) throw new Error(d.error || 'Se perdió la conexión con el servidor de transmisión. Vuelve a elegir el mismo archivo para continuar.');
        await new Promise(r => setTimeout(r, Math.min(20000, 1500 * intento)));
      }
      alAvance(pos);
    }
  }
  function panelSubida(titulo, hecho, total, inicio) {
    g('pg-subida').classList.remove('hidden');
    g('pg-subida-titulo').textContent = titulo;
    const pct = total ? Math.min(100, Math.round(100 * hecho / total)) : 0;
    g('pg-subida-barra').style.width = pct + '%';
    const seg = (Date.now() - inicio) / 1000, vel = seg > 2 ? hecho / seg : 0;
    g('pg-subida-dato').textContent = `${pct} % · ${tam(hecho)} de ${tam(total)}` + (vel ? ` · ${tam(vel)}/s · faltan ${dur((total - hecho) / vel)}` : '');
  }
  async function subirYPreparar(emision, url, archivo) {
    subiendo = emision.id;
    window.onbeforeunload = () => 'El video se está subiendo';
    const inicio = Date.now();
    try {
      await subir(url, archivo, (h) => panelSubida('Subiendo «' + archivo.name + '»…', h, archivo.size, inicio));
      panelSubida('Subido. Preparando el video en el servidor…', archivo.size, archivo.size, inicio);
      await post(`${RUTAS.base}/${emision.id}/subido`);
      g('pg-subida-titulo').textContent = '✓ Listo: el video se está preparando. Puedes cerrar esta página.';
      g('pg-form').reset(); mostrarArchivo();
    } finally { subiendo = null; window.onbeforeunload = null; refrescar(); }
  }

  g('pg-form').addEventListener('submit', async (ev) => {
    ev.preventDefault(); error('');
    const f = new FormData(ev.target), archivo = entrada.files[0];
    if (!archivo) return error('Elige el video que quieres programar.');
    if (archivo.size > MAX) return error(`El video pesa ${tam(archivo.size)} y el máximo es ${tam(MAX)}.`);
    const datos = { titulo: f.get('titulo'), descripcion: f.get('descripcion'), programada_en: f.get('programada_en'), calidad: f.get('calidad'),
      meta_page_ids: f.getAll('meta_page_ids[]').map(Number), youtube_canal_ids: f.getAll('youtube_canal_ids[]').map(Number),
      logo_id: f.get('logo_id') ? Number(f.get('logo_id')) : null, marco_id: f.get('marco_id') ? Number(f.get('marco_id')) : null,
      etiqueta: f.get('etiqueta') === '1', cuenta: f.get('cuenta') === '1', anuncio_facebook: f.get('anuncio_facebook') === '1', archivo_nombre: archivo.name, archivo_bytes: archivo.size };
    if (!datos.titulo) return error('Escribe el título.');
    if (!datos.meta_page_ids.length && !datos.youtube_canal_ids.length) return error('Elige al menos una página de Facebook o un canal de YouTube.');
    const img = f.get('miniatura_archivo');
    if (img && img.size) {
      if (img.size > 2 * 1024 * 1024) return error('La miniatura pesa más de 2 MB. Usa una imagen JPG de 1280×720.');
      datos.miniatura = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.onerror = () => ok(null); fr.readAsDataURL(img); });
    }
    const b = g('pg-boton'); b.disabled = true;
    try {
      const r = await post(RUTAS.crear, datos);
      lista.unshift(r.emision); pintar();
      await subirYPreparar(r.emision, r.subida, archivo);
    } catch (e) { error(e.message); g('pg-subida').classList.add('hidden'); }
    finally { b.disabled = false; }
  });

  // ---------------------------------------------------------- lista
  const COLORES = { subiendo: 'bg-sky-50 text-sky-800', procesando: 'bg-amber-50 text-amber-800', listo: 'bg-indigo-50 text-indigo-800', al_aire: 'bg-rose-600 text-white', terminado: 'bg-emerald-50 text-emerald-800', error: 'bg-rose-50 text-rose-700', cancelado: 'bg-gray-100 text-gray-600' };
  const DEST = { pendiente: 'text-gray-500', anunciado: 'text-indigo-700', emitiendo: 'text-rose-600', terminado: 'text-emerald-700', error: 'text-rose-600', cancelado: 'text-gray-400' };
  const faltaPara = (iso) => { const s = (new Date(iso) - Date.now()) / 1000; if (s <= 0) return 'ahora'; const d = Math.floor(s / 86400); return d ? `en ${d} día${d > 1 ? 's' : ''} ${Math.floor(s % 86400 / 3600)} h` : 'en ' + dur(s); };
  function tarjeta(e) {
    const barra = ['subiendo', 'procesando', 'al_aire'].includes(e.estado);
    const texto = e.estado === 'subiendo' ? (subiendo === e.id ? 'Subiendo…' : 'Subida pausada: vuelve a elegir «' + esc(e.archivo) + '» para continuar')
      : e.estado === 'procesando' ? `Preparando el video · ${e.progreso} %`
      : e.estado === 'listo' ? `Sale ${faltaPara(e.programada_en)}${e.anunciada ? ' · anunciado' : ''}`
      : e.estado === 'al_aire' ? `Al aire · ${e.progreso} %` : e.estado === 'terminado' ? `Emitido ${e.inicio_real ? 'el ' + e.inicio_real : ''}` : '';
    return `<div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 flex flex-col min-w-0" data-id="${e.id}">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0"><div class="font-semibold text-gray-900 leading-snug">${esc(e.titulo)}</div><div class="text-xs text-gray-500 mt-0.5">${esc(e.programada_texto)}${e.duracion ? ' · ' + dur(e.duracion) : ''}</div></div>
        <span class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold ${COLORES[e.estado] || ''}">${e.estado === 'al_aire' ? '● ' : ''}${esc(e.estado_texto)}</span>
      </div>
      ${texto ? `<div class="text-xs text-gray-600 mt-2">${texto}</div>` : ''}
      ${barra ? `<div class="mt-2 h-1.5 rounded-full bg-gray-100 overflow-hidden"><div class="h-1.5 rounded-full ${e.estado === 'al_aire' ? 'bg-rose-500' : 'bg-[#00024f]'}" style="width:${e.progreso}%"></div></div>` : ''}
      ${e.error ? `<div class="mt-2 rounded-lg bg-rose-50 text-rose-700 text-xs px-2.5 py-1.5">${esc(e.error)}</div>` : ''}
      <div class="mt-3 space-y-1">${e.destinos.map(d => `<div class="flex items-center justify-between gap-2 text-xs"><span class="truncate">${d.red === 'youtube' ? '▶' : 'f'} ${esc(d.nombre)}</span><span class="shrink-0 ${DEST[d.estado] || ''}">${d.permalink ? `<a href="${esc(d.permalink)}" target="_blank" rel="noopener" class="underline">${esc(d.estado)}</a>` : d.anuncio && d.estado === 'pendiente' ? `<span class="text-indigo-700">${d.anuncio_permalink ? `<a href="${esc(d.anuncio_permalink)}" target="_blank" rel="noopener" class="underline">anuncio publicado</a>` : 'anuncio publicado'}</span>` : esc(d.estado)}</span></div>${d.error ? `<div class="text-[11px] text-rose-600">${esc(d.error)}</div>` : ''}`).join('')}</div>
      <div class="mt-auto pt-3 flex flex-wrap gap-2">
        ${e.estado === 'subiendo' && subiendo !== e.id ? `<label class="h-8 inline-flex items-center rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold cursor-pointer">Continuar subida<input type="file" class="sr-only" data-reanudar="${e.id}" accept="video/*,.mkv"></label>` : ''}
        ${e.estado === 'listo' ? `<button type="button" data-ahora="${e.id}" class="h-8 rounded-lg border border-gray-200 px-3 text-xs text-gray-700 hover:bg-gray-50">Salir en 2 minutos</button>` : ''}
        ${e.puede_cancelar ? `<button type="button" data-cancelar="${e.id}" class="h-8 rounded-lg border border-rose-200 px-3 text-xs text-rose-700 hover:bg-rose-50">${e.estado === 'al_aire' ? 'Detener transmisión' : 'Cancelar'}</button>` : `<button type="button" data-quitar="${e.id}" class="h-8 rounded-lg border border-gray-200 px-3 text-xs text-gray-500 hover:bg-gray-50">Quitar</button>`}
      </div></div>`;
  }
  function pintar() {
    g('pg-lista').innerHTML = lista.length ? lista.map(tarjeta).join('') : '<div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-3">Todavía no hay pregrabados programados.</div>';
    g('pg-actualizado').textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }
  async function refrescar() {
    try { const r = await fetch(RUTAS.estado, { headers: { Accept: 'application/json' } }); if (r.ok) { lista = (await r.json()).emisiones; pintar(); } } catch {}
  }
  g('pg-lista').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    try {
      if (b.dataset.cancelar) { if (!confirm(b.textContent.includes('Detener') ? '¿Detener la transmisión ahora? Se cierra en Facebook y YouTube.' : '¿Cancelar este pregrabado? Se quita el anuncio y se borra el video del servidor.')) return; await post(`${RUTAS.base}/${b.dataset.cancelar}/cancelar`); }
      if (b.dataset.ahora) { if (!confirm('¿Sacar este video al aire en 2 minutos?')) return; await post(`${RUTAS.base}/${b.dataset.ahora}/ahora`); }
      if (b.dataset.quitar) { if (!confirm('¿Quitar del historial?')) return; await fetch(`${RUTAS.base}/${b.dataset.quitar}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } }); }
      refrescar();
    } catch (e) { error(e.message); }
  });
  g('pg-lista').addEventListener('change', async (ev) => {
    const i = ev.target.closest('input[data-reanudar]'); if (!i || !i.files[0]) return;
    error('');
    try { const r = await post(`${RUTAS.base}/${i.dataset.reanudar}/reanudar`, { archivo_bytes: i.files[0].size }); await subirYPreparar(r.emision, r.subida, i.files[0]); }
    catch (e) { error(e.message); }
  });
  pintar();
  setInterval(() => { if (!document.hidden) refrescar(); }, 6000);
})();
</script>
@endsection
