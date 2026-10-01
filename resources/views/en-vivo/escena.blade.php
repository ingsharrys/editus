<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Escena en vivo</title>
{{--
  Escena que compone el egress de LiveKit (plantilla en tiempo real):
  recibe ?url=&token=&layout=&room= del egress, se une a la sala como
  participante oculto, muestra la cámara principal a pantalla completa y
  dibuja encima el logo, el cintillo con título y etiqueta y el pie, leyendo
  la plantilla desde la metadata de la sala (se actualiza al instante).
  Avisa al egress con console.log('START_RECORDING') / 'END_RECORDING'.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@600;700;900&family=Playfair+Display:wght@800&display=swap" rel="stylesheet">
<style>
  html, body { margin: 0; width: 1280px; height: 720px; background: #000; overflow: hidden; font-family: Inter, system-ui, sans-serif; }
  #video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; background: #000; }
  #espera { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 34px; font-weight: 700; background: linear-gradient(160deg, #18345a, #0b1c33); }
  .oculto { display: none !important; }
  #sombra { position: absolute; left: 0; right: 0; bottom: 0; height: 46%; background: linear-gradient(to top, rgba(0,0,0,.85), rgba(0,0,0,0)); pointer-events: none; }
  #logo { position: absolute; left: 44px; top: 36px; display: flex; align-items: center; gap: 4px; }
  #logo .caja { width: 44px; height: 44px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 26px; }
  #logo .resto { font-weight: 700; font-size: 26px; margin-left: 8px; }
  #vivo { position: absolute; right: 44px; top: 40px; display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,.55); border-radius: 8px; padding: 8px 14px; color: #fff; font-weight: 800; font-size: 20px; letter-spacing: 2px; }
  #vivo i { width: 12px; height: 12px; border-radius: 50%; background: #e11d48; animation: latir 1.2s infinite; }
  @keyframes latir { 0%,100% { opacity: 1 } 50% { opacity: .25 } }
  #cintillo { position: absolute; left: 44px; bottom: 96px; max-width: 980px; }
  #etiqueta { display: inline-block; padding: 6px 18px; font-weight: 700; font-size: 20px; letter-spacing: 3px; }
  #titulo { font-family: 'Playfair Display', serif; font-weight: 800; font-size: 44px; line-height: 1.12; margin-top: 10px; text-shadow: 2px 2px 3px rgba(0,0,0,.5); }
  #pie { position: absolute; left: 44px; right: 44px; bottom: 36px; display: flex; justify-content: space-between; color: #fff; font-weight: 600; font-size: 20px; }
</style>
</head>
<body>
  <div id="espera">Esperando la cámara…</div>
  <video id="video" autoplay playsinline muted class="oculto"></video>
  <div id="sombra"></div>
  <div id="logo"></div>
  <div id="vivo"><i></i>EN VIVO</div>
  <div id="cintillo"><span id="etiqueta"></span><div id="titulo"></div></div>
  <div id="pie"><span id="pie-izq"></span><span id="pie-der"></span></div>

  <script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
  <script>
    const q = new URLSearchParams(location.search);
    const url = q.get('url'); const token = q.get('token');

    function contraste(hex) {
      const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
      if (!m) return '#fff';
      const l = (0.299 * parseInt(m[1], 16) + 0.587 * parseInt(m[2], 16) + 0.114 * parseInt(m[3], 16)) / 255;
      return l > 0.6 ? '#111' : '#fff';
    }

    function pintar(p) {
      p = p || {};
      const logo = document.getElementById('logo');
      logo.innerHTML = '';
      const texto = (p.logo_texto || '').trim();
      if (texto) {
        const [marca, ...resto] = texto.split(/\s+/);
        for (const ch of marca.toUpperCase()) {
          const c = document.createElement('div'); c.className = 'caja';
          c.style.background = p.color_logo || '#fff'; c.style.color = contraste(p.color_logo || '#fff'); c.textContent = ch; logo.appendChild(c);
        }
        if (resto.length) { const r = document.createElement('span'); r.className = 'resto'; r.style.color = p.color_logo || '#fff'; r.textContent = resto.join(' '); logo.appendChild(r); }
      }
      document.getElementById('vivo').classList.toggle('oculto', p.en_vivo === false);
      const mostrar = p.mostrar !== false;
      document.getElementById('cintillo').classList.toggle('oculto', !mostrar);
      document.getElementById('sombra').classList.toggle('oculto', !mostrar);
      const et = document.getElementById('etiqueta');
      et.textContent = (p.etiqueta || '').toUpperCase(); et.classList.toggle('oculto', !p.etiqueta);
      et.style.background = p.color_etiqueta || '#C8102E'; et.style.color = contraste(p.color_etiqueta || '#C8102E');
      const t = document.getElementById('titulo'); t.textContent = p.titulo || ''; t.style.color = p.color_titulo || '#fff';
      document.getElementById('pie-izq').textContent = p.pie || '';
      document.getElementById('pie-der').textContent = p.hashtag || '';
    }

    function leerMetadata(room) {
      try { pintar(JSON.parse(room.metadata || '{}')); } catch (e) { pintar({}); }
    }

    let grabando = false;
    function empezar() { if (!grabando) { grabando = true; console.log('START_RECORDING'); } }

    async function main() {
      const { Room, RoomEvent, Track } = LivekitClient;
      const room = new Room({ adaptiveStream: false, dynacast: false });
      const video = document.getElementById('video');
      const espera = document.getElementById('espera');
      let pistaActual = null;

      const mostrarPista = (track) => {
        if (pistaActual) pistaActual.detach(video);
        pistaActual = track;
        track.attach(video);
        video.classList.remove('oculto'); espera.classList.add('oculto');
        empezar();
      };
      const elegirCamara = () => {
        // La cámara "principal" (identidad camara-principal) o la primera disponible
        const parts = Array.from(room.remoteParticipants.values()).sort((a, b) => (a.identity === 'camara-principal' ? -1 : 1));
        for (const p of parts) {
          for (const pub of p.videoTrackPublications.values()) {
            if (pub.track && pub.source === Track.Source.Camera) { mostrarPista(pub.track); return; }
          }
        }
        if (pistaActual) { pistaActual.detach(video); pistaActual = null; }
        video.classList.add('oculto'); espera.classList.remove('oculto');
      };

      room.on(RoomEvent.TrackSubscribed, elegirCamara);
      room.on(RoomEvent.TrackUnsubscribed, elegirCamara);
      room.on(RoomEvent.ParticipantDisconnected, elegirCamara);
      room.on(RoomEvent.RoomMetadataChanged, () => leerMetadata(room));
      room.on(RoomEvent.Disconnected, () => { console.log('END_RECORDING'); });

      await room.connect(url, token, { autoSubscribe: true });
      leerMetadata(room);
      elegirCamara();
      // Si nadie publica en 20 s, igual se empieza (pantalla de espera) para que Facebook reciba señal
      setTimeout(empezar, 20000);
    }
    main().catch((e) => { document.getElementById('espera').textContent = 'No se pudo conectar: ' + e.message; console.error(e); });
  </script>
</body>
</html>
