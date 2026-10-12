<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Enviar mi cámara · {{ $transmision->titulo }}</title>
{{--
  Página del invitado: cualquier persona con el enlace envía su cámara y micrófono
  a la transmisión desde el navegador (celular o computador), sin instalar nada.
  El director decide desde la app cuándo sale al aire.
--}}
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; background: #0b1c33; color: #fff; font-family: Inter, system-ui, -apple-system, sans-serif; }
  main { max-width: 560px; margin: 0 auto; padding: 20px 16px 40px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  p.sub { margin: 0 0 16px; color: #cbd5e1; font-size: 14px; }
  .marco { position: relative; width: 100%; aspect-ratio: 16/9; background: #000; border-radius: 12px; overflow: hidden; border: 2px solid #1e3a5f; }
  .marco video { width: 100%; height: 100%; object-fit: cover; display: block; transform: scaleX(-1); }
  .marco video.trasera { transform: none; }
  .estado { position: absolute; left: 10px; top: 10px; padding: 4px 10px; border-radius: 6px; background: rgba(0,0,0,.6); font-size: 12px; font-weight: 700; letter-spacing: 1px; }
  .estado.aire { background: #e11d48; }
  label { display: block; font-size: 13px; color: #cbd5e1; margin: 14px 0 6px; }
  input { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #1e3a5f; background: #122b4a; color: #fff; font-size: 16px; }
  .fila { display: flex; gap: 10px; margin-top: 14px; }
  button { flex: 1; padding: 14px; border: 0; border-radius: 10px; font-size: 16px; font-weight: 700; cursor: pointer; background: #1e3a5f; color: #fff; }
  button.primario { background: #e11d48; }
  button:disabled { opacity: .5; cursor: default; }
  .aviso { margin-top: 14px; padding: 12px; border-radius: 10px; background: #122b4a; color: #cbd5e1; font-size: 14px; line-height: 1.4; }
  .aviso.error { background: #4c0519; color: #fecdd3; }
  .oculto { display: none !important; }
  /* Chat privado con el estudio (no sale al aire) */
  .chat { margin-top: 16px; border: 1px solid #1e3a5f; border-radius: 12px; background: #0e2440; }
  .chat h2 { margin: 0; padding: 10px 12px; font-size: 14px; border-bottom: 1px solid #1e3a5f; }
  .chat .lista { max-height: 220px; overflow-y: auto; padding: 8px 12px; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
  .chat .msg b { color: #93c5fd; } .chat .msg.mio b { color: #fda4af; }
  .chat form { display: flex; gap: 8px; padding: 8px; border-top: 1px solid #1e3a5f; }
  .chat form input { flex: 1; padding: 10px; font-size: 15px; } .chat form button { flex: none; padding: 10px 16px; font-size: 15px; }
  .chat .vacio { color: #94a3b8; font-size: 13px; }
</style>
</head>
<body>
<main>
  <h1>{{ $transmision->titulo }}</h1>
  @if ($modo === 'pantalla')
    <p class="sub">Vas a compartir la pantalla de este computador con la transmisión. El director decide cuándo sale al aire.</p>
  @else
    <p class="sub">Vas a enviar tu cámara a esta transmisión. El director decide cuándo sales al aire.</p>
  @endif

  @if (!$activa && !empty($programada))
    <div class="aviso" style="margin:0 0 14px;background:#1e3a8a;color:#e0e7ff">📅 Programada para el <strong>{{ $programada->translatedFormat('l j \d\e F \a \l\a\s g:i a') }}</strong>: vuelve a este enlace unos minutos antes: cuando el director abra el estudio podrás enviar tu cámara. Mientras tanto puedes probar tu cámara aquí.</div>
  @endif
  <div class="marco">
    <video id="previa" autoplay playsinline muted></video>
    <span id="estado" class="estado">VISTA PREVIA</span>
  </div>

  <div id="paso1">
    <label for="nombre">{{ $modo === 'pantalla' ? 'Nombre de esta pantalla' : 'Tu nombre (se ve en pantalla)' }}</label>
    <input id="nombre" maxlength="40" placeholder="{{ $modo === 'pantalla' ? 'Ej: Presentación, Computador de control' : 'Ej: Carlos, Reportero en Pitalito' }}" value="{{ $invitacion['nombre'] ?? '' }}">
    <div class="fila">
      @if ($modo === 'pantalla')
        <button id="unirse" class="primario" type="button">Compartir mi pantalla</button>
      @else
        <button id="voltear" type="button">Voltear cámara</button>
        <button id="unirse" class="primario" type="button">Enviar mi cámara</button>
      @endif
    </div>
    @if ($modo === 'pantalla')
      <div id="aviso-pantalla" class="aviso oculto">Este enlace es para compartir pantalla desde un computador (Chrome, Edge o Firefox). En un celular el navegador no lo permite.</div>
    @endif
  </div>
  <div id="paso2" class="oculto">
    <div class="fila">
      <button id="mic" type="button">Silenciar micrófono</button>
      @if ($modo !== 'pantalla')<button id="voltear2" type="button">Voltear cámara</button>@endif
      <button id="pantalla" type="button" class="oculto">Compartir pantalla</button>
      <button id="salir" type="button">Salir</button>
    </div>
    <div class="chat">
      <h2>💬 Chat privado con el estudio</h2>
      <div id="chat-lista" class="lista"><div class="vacio">Solo lo ven el director y los invitados. No sale al aire.</div></div>
      <form id="chat-form"><input id="chat-texto" maxlength="500" placeholder="Escribe un mensaje…" autocomplete="off"><button type="submit">Enviar</button></form>
    </div>
  </div>

  <div id="aviso" class="aviso {{ $activa ? 'oculto' : '' }}">
    @if (!$activa && !empty($programada)) Aún no empieza: vuelve a la hora programada.
    @elseif (!$activa) La transmisión todavía no está en vivo. Deja esta página abierta y vuelve a intentar cuando el director la inicie. @endif
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
<script>
  const CODIGO = @json($codigo);
  const MODO = @json($modo);
  const TOKEN_URL = @json(route('en-vivo.invitado.token', $codigo));
  const previa = document.getElementById('previa');
  const aviso = document.getElementById('aviso');
  const estado = document.getElementById('estado');
  let frontal = true, local = null, room = null, micActivo = true;

  function mostrar(msg, error) { aviso.textContent = msg; aviso.classList.toggle('error', !!error); aviso.classList.remove('oculto'); }

  // Cámara frontal o trasera. Se elige por el nombre del dispositivo ("Cámara trasera",
  // "Back Camera", "facing back"…): es lo más confiable en iPhone y Android. Si el navegador
  // no da los nombres, se pide por facingMode.
  const TRASERA = /back|rear|trasera|posterior|environment|facing back/i;
  const FRONTAL = /front|frontal|delantera|user|facing front/i;
  async function camaraElegida(quiereFrontal) {
    try {
      const lista = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput' && d.label);
      const opciones = lista.filter(d => (quiereFrontal ? FRONTAL : TRASERA).test(d.label));
      // En iPhone hay varias traseras (gran angular, telefoto…): la principal es la de nombre más corto
      opciones.sort((a, b) => a.label.length - b.label.length);
      if (opciones[0]) return { deviceId: opciones[0].deviceId };
      if (lista.length === 2) { // sin nombres reconocibles: la otra cámara
        const actual = local && local.getVideoTracks()[0] ? local.getVideoTracks()[0].getSettings().deviceId : null;
        const otra = lista.find(d => d.deviceId !== actual);
        if (otra && actual) return { deviceId: otra.deviceId };
      }
    } catch (e) {}
    return { facingMode: quiereFrontal ? 'user' : 'environment' };
  }
  async function abrirCamara(quiereFrontal) {
    const c = await camaraElegida(quiereFrontal);
    const base = { width: { ideal: 1280 }, height: { ideal: 720 } };
    const intentos = c.deviceId
      ? [{ deviceId: { exact: c.deviceId }, ...base }, { facingMode: { exact: quiereFrontal ? 'user' : 'environment' }, ...base }]
      : [{ facingMode: { exact: c.facingMode }, ...base }, { facingMode: c.facingMode, ...base }];
    let ultimo = null;
    for (const video of intentos) {
      try { return { stream: await navigator.mediaDevices.getUserMedia({ video, audio: true }), eleccion: c }; } catch (e) { ultimo = e; }
    }
    throw ultimo || new Error('No se pudo abrir la cámara');
  }
  let eleccion = { facingMode: 'user' };

  async function previsualizar() {
    try {
      if (local) local.getTracks().forEach(t => t.stop());
      const r = await abrirCamara(frontal);
      local = r.stream; eleccion = r.eleccion;
      previa.srcObject = local; previa.classList.toggle('trasera', !frontal);
    } catch (e) { mostrar('No se pudo abrir la cámara: ' + e.message + '. Revisa los permisos del navegador.', true); }
  }

  async function voltear() {
    frontal = !frontal;
    if (!room) return previsualizar();
    const { Track } = LivekitClient;
    const pub = room.localParticipant.getTrackPublication(Track.Source.Camera);
    try {
      const c = await camaraElegida(frontal);
      if (pub && pub.track) {
        // Cambia la pista que ya se está enviando (el estudio no pierde la cámara)
        await pub.track.restartTrack(c.deviceId ? { deviceId: { exact: c.deviceId }, resolution: { width: 1920, height: 1080, frameRate: 30 } } : { facingMode: c.facingMode, resolution: { width: 1920, height: 1080, frameRate: 30 } });
        pub.track.attach(previa);
      } else {
        await room.localParticipant.setCameraEnabled(true, c.deviceId ? { deviceId: c.deviceId } : { facingMode: c.facingMode });
      }
      eleccion = c;
      previa.classList.toggle('trasera', !frontal);
    } catch (e) {
      frontal = !frontal;
      mostrar('No se pudo cambiar de cámara: ' + e.message, true);
    }
  }
  const btnVoltear = document.getElementById('voltear');
  if (btnVoltear) btnVoltear.onclick = voltear;
  const btnVoltear2 = document.getElementById('voltear2');
  if (btnVoltear2) btnVoltear2.onclick = voltear;

  document.getElementById('unirse').onclick = async () => {
    const btn = document.getElementById('unirse'); btn.disabled = true; btn.textContent = 'Conectando…';
    try {
      const r = await fetch(TOKEN_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ nombre: document.getElementById('nombre').value }) });
      const j = await r.json();
      if (!j.success) throw new Error(j.error || 'No se pudo entrar');
      const { Room, RoomEvent, Track } = LivekitClient;
      if (local) local.getTracks().forEach(t => t.stop());
      // Entra con la misma cámara de la vista previa (frontal o trasera)
      const captura = eleccion.deviceId ? { deviceId: eleccion.deviceId } : { facingMode: frontal ? 'user' : 'environment' };
      room = new Room({ adaptiveStream: true, dynacast: true, videoCaptureDefaults: { ...captura, resolution: { width: 1920, height: 1080, frameRate: 30 } }, publishDefaults: { videoEncoding: { maxBitrate: 3000000, maxFramerate: 30 }, simulcast: false } });
      room.on(RoomEvent.Disconnected, () => { estado.textContent = 'DESCONECTADO'; estado.classList.remove('aire'); mostrar('Te desconectaron de la transmisión.'); document.getElementById('paso2').classList.add('oculto'); document.getElementById('paso1').classList.remove('oculto'); btn.disabled = false; btn.textContent = 'Enviar mi cámara'; room = null; previsualizar(); });
      room.on(RoomEvent.RoomMetadataChanged, alAire);
      room.on(RoomEvent.DataReceived, (payload, participante, tipo, tema) => {
        if (tema !== 'chat') return;
        try { const m = JSON.parse(new TextDecoder().decode(payload)); agregarMensaje(m.nombre || (participante && participante.name) || 'Estudio', m.texto, false); } catch (e) {}
      });
      await room.connect(j.url, j.token);
      if (MODO === 'pantalla') {
        await room.localParticipant.setScreenShareEnabled(true, { audio: true, resolution: { width: 1920, height: 1080, frameRate: 15 } });
        try { await room.localParticipant.setMicrophoneEnabled(true); } catch (e) { /* sin micrófono */ }
        const sc = room.localParticipant.getTrackPublication(Track.Source.ScreenShare);
        if (sc && sc.track) { previa.classList.add('trasera'); sc.track.attach(previa); }
        pantallaActiva = true; btnPantalla.textContent = 'Dejar de compartir pantalla';
      } else {
        await room.localParticipant.enableCameraAndMicrophone();
        const cam = room.localParticipant.getTrackPublication(Track.Source.Camera);
        if (cam && cam.track) cam.track.attach(previa);
      }
      document.getElementById('paso1').classList.add('oculto'); document.getElementById('paso2').classList.remove('oculto');
      aviso.classList.add('oculto');
      estado.textContent = 'CONECTADO · EN ESPERA';
      alAire();
    } catch (e) {
      mostrar(e.message, true); btn.disabled = false; btn.textContent = 'Enviar mi cámara';
    }
  };

  // Aviso de "estás al aire" según la escena de la sala (metadata)
  function alAire() {
    if (!room) return;
    let m = {}; try { m = JSON.parse(room.metadata || '{}'); } catch (e) {}
    const e = m.escena || {}; const yo = room.localParticipant.identity;
    const ids = [yo, yo + '#pantalla'];
    const sale = ids.includes(e.principal) || (Array.isArray(e.visibles) && e.visibles.some((v) => ids.includes(v)));
    estado.textContent = sale ? '● AL AIRE' : 'CONECTADO · EN ESPERA';
    estado.classList.toggle('aire', sale);
  }

  // Compartir la pantalla del computador (en celulares el navegador no lo permite)
  const btnPantalla = document.getElementById('pantalla');
  if (navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) btnPantalla.classList.remove('oculto');
  let pantallaActiva = false;
  btnPantalla.onclick = async () => {
    if (!room) return;
    try {
      pantallaActiva = !pantallaActiva;
      await room.localParticipant.setScreenShareEnabled(pantallaActiva, { audio: true, resolution: { width: 1920, height: 1080, frameRate: 15 } });
      btnPantalla.textContent = pantallaActiva ? 'Dejar de compartir pantalla' : 'Compartir pantalla';
    } catch (e) { pantallaActiva = false; btnPantalla.textContent = 'Compartir pantalla'; if (e && e.name !== 'NotAllowedError') mostrar('No se pudo compartir la pantalla: ' + e.message, true); }
  };
  if (typeof LivekitClient !== 'undefined') {
    // Si el usuario detiene la captura desde el navegador, el botón vuelve a su estado
    document.addEventListener('visibilitychange', () => { if (room && pantallaActiva && !room.localParticipant.isScreenShareEnabled) { pantallaActiva = false; btnPantalla.textContent = 'Compartir pantalla'; } });
  }

  document.getElementById('mic').onclick = async () => {
    if (!room) return; micActivo = !micActivo;
    await room.localParticipant.setMicrophoneEnabled(micActivo);
    document.getElementById('mic').textContent = micActivo ? 'Silenciar micrófono' : 'Activar micrófono';
  };
  // Chat privado (mensajes de datos de la sala; la escena no los muestra)
  const chatLista = document.getElementById('chat-lista');
  function agregarMensaje(nombre, texto, mio) {
    if (!texto) return;
    const vacio = chatLista.querySelector('.vacio'); if (vacio) vacio.remove();
    const d = document.createElement('div'); d.className = 'msg' + (mio ? ' mio' : '');
    const b = document.createElement('b'); b.textContent = (mio ? 'Tú' : nombre) + ': ';
    d.appendChild(b); d.appendChild(document.createTextNode(texto));
    chatLista.appendChild(d); chatLista.scrollTop = chatLista.scrollHeight;
    if (!mio && navigator.vibrate) navigator.vibrate(60);
  }
  document.getElementById('chat-form').onsubmit = async (ev) => {
    ev.preventDefault();
    const inp = document.getElementById('chat-texto'), texto = inp.value.trim();
    if (!texto || !room) return;
    const nombre = document.getElementById('nombre').value || 'Invitado';
    try {
      await room.localParticipant.publishData(new TextEncoder().encode(JSON.stringify({ nombre, texto })), { reliable: true, topic: 'chat' });
      agregarMensaje(nombre, texto, true); inp.value = '';
    } catch (e) { mostrar('No se pudo enviar el mensaje: ' + e.message, true); }
  };

  document.getElementById('salir').onclick = async () => { if (room) await room.disconnect(); };

  if (MODO === 'pantalla') {
    if (!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia)) { document.getElementById('aviso-pantalla').classList.remove('oculto'); document.getElementById('unirse').disabled = true; }
  } else {
    previsualizar();
  }
</script>
</body>
</html>
