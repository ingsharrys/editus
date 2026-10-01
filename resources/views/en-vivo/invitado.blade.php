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
</style>
</head>
<body>
<main>
  <h1>{{ $transmision->titulo }}</h1>
  <p class="sub">Vas a enviar tu cámara a esta transmisión. El director decide cuándo sales al aire.</p>

  <div class="marco">
    <video id="previa" autoplay playsinline muted></video>
    <span id="estado" class="estado">VISTA PREVIA</span>
  </div>

  <div id="paso1">
    <label for="nombre">Tu nombre (se ve en pantalla)</label>
    <input id="nombre" maxlength="40" placeholder="Ej: Carlos, Reportero en Pitalito" value="{{ $invitacion['nombre'] ?? '' }}">
    <div class="fila">
      <button id="voltear" type="button">Voltear cámara</button>
      <button id="unirse" class="primario" type="button">Enviar mi cámara</button>
    </div>
  </div>
  <div id="paso2" class="oculto">
    <div class="fila">
      <button id="mic" type="button">Silenciar micrófono</button>
      <button id="salir" type="button">Salir</button>
    </div>
  </div>

  <div id="aviso" class="aviso {{ $activa ? 'oculto' : '' }}">
    @if (!$activa) La transmisión todavía no está en vivo. Deja esta página abierta y vuelve a intentar cuando el director la inicie. @endif
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
<script>
  const CODIGO = @json($codigo);
  const TOKEN_URL = @json(route('en-vivo.invitado.token', $codigo));
  const previa = document.getElementById('previa');
  const aviso = document.getElementById('aviso');
  const estado = document.getElementById('estado');
  let frontal = true, local = null, room = null, micActivo = true;

  function mostrar(msg, error) { aviso.textContent = msg; aviso.classList.toggle('error', !!error); aviso.classList.remove('oculto'); }

  async function previsualizar() {
    try {
      if (local) local.getTracks().forEach(t => t.stop());
      local = await navigator.mediaDevices.getUserMedia({ video: { facingMode: frontal ? 'user' : 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: true });
      previa.srcObject = local; previa.classList.toggle('trasera', !frontal);
    } catch (e) { mostrar('No se pudo abrir la cámara: ' + e.message + '. Revisa los permisos del navegador.', true); }
  }

  document.getElementById('voltear').onclick = async () => {
    frontal = !frontal;
    if (room) { await room.localParticipant.setCameraEnabled(false); await room.localParticipant.setCameraEnabled(true, { facingMode: frontal ? 'user' : 'environment' }); previa.classList.toggle('trasera', !frontal); }
    else await previsualizar();
  };

  document.getElementById('unirse').onclick = async () => {
    const btn = document.getElementById('unirse'); btn.disabled = true; btn.textContent = 'Conectando…';
    try {
      const r = await fetch(TOKEN_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ nombre: document.getElementById('nombre').value }) });
      const j = await r.json();
      if (!j.success) throw new Error(j.error || 'No se pudo entrar');
      const { Room, RoomEvent, Track } = LivekitClient;
      if (local) local.getTracks().forEach(t => t.stop());
      room = new Room({ adaptiveStream: true, dynacast: true, videoCaptureDefaults: { facingMode: frontal ? 'user' : 'environment', resolution: { width: 1280, height: 720, frameRate: 30 } } });
      room.on(RoomEvent.Disconnected, () => { estado.textContent = 'DESCONECTADO'; estado.classList.remove('aire'); mostrar('Te desconectaron de la transmisión.'); document.getElementById('paso2').classList.add('oculto'); document.getElementById('paso1').classList.remove('oculto'); btn.disabled = false; btn.textContent = 'Enviar mi cámara'; room = null; previsualizar(); });
      room.on(RoomEvent.RoomMetadataChanged, alAire);
      await room.connect(j.url, j.token);
      await room.localParticipant.enableCameraAndMicrophone();
      const cam = room.localParticipant.getTrackPublication(Track.Source.Camera);
      if (cam && cam.track) cam.track.attach(previa);
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
    const sale = e.principal === yo || (Array.isArray(e.visibles) && e.visibles.includes(yo));
    estado.textContent = sale ? '● AL AIRE' : 'CONECTADO · EN ESPERA';
    estado.classList.toggle('aire', sale);
  }

  document.getElementById('mic').onclick = async () => {
    if (!room) return; micActivo = !micActivo;
    await room.localParticipant.setMicrophoneEnabled(micActivo);
    document.getElementById('mic').textContent = micActivo ? 'Silenciar micrófono' : 'Activar micrófono';
  };
  document.getElementById('salir').onclick = async () => { if (room) await room.disconnect(); };

  previsualizar();
</script>
</body>
</html>
