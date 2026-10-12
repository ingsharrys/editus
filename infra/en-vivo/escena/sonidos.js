/*
 * Sonidos y música de fondo del estudio, sintetizados con Web Audio (sin archivos ni licencias).
 * Lo usan la escena (sale al aire) y el estudio (vista previa para quien dirige).
 *
 *   EditusAudio.EFECTOS / EditusAudio.MUSICAS     catálogo { clave: nombre }
 *   EditusAudio.efecto(ctx, destino, clave)        dispara un efecto
 *   new EditusAudio.Musica(ctx, destino, clave)    .iniciar() / .detener()  (bucle sin fin)
 */
(function () {
  const EFECTOS = {
    aplausos: 'Aplausos', redoble: 'Redoble', gong: 'Gong', campana: 'Campana', latido: 'Latido',
    rebote: 'Rebote', fanfarria: 'Fanfarria', transicion: 'Transición', alerta: 'Alerta de noticia', risas: 'Risas',
  };
  const MUSICAS = { ambiente: 'Ambiente suave', noticias: 'Pulso de noticias', lofi: 'Lo-fi tranquilo', tension: 'Suspenso', celebracion: 'Celebración' };

  const ruidoCache = new WeakMap();
  function ruido(ctx) {
    if (ruidoCache.has(ctx)) return ruidoCache.get(ctx);
    const b = ctx.createBuffer(1, ctx.sampleRate * 2, ctx.sampleRate);
    const d = b.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    ruidoCache.set(ctx, b);
    return b;
  }
  function env(ctx, g, t, a, pico, dec, fin = 0.0001) {
    pico = Math.max(pico, 0.0002);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(pico, t + a);
    // Notas largas (acordes, gong): se sostienen y luego se apagan; las cortas caen de una vez
    if (dec > 0.5) g.gain.linearRampToValueAtTime(pico * 0.6, t + a + dec * 0.7);
    g.gain.exponentialRampToValueAtTime(fin, t + a + dec);
  }
  function tono(ctx, dest, t, frec, dur, { tipo = 'sine', vol = 0.3, ataque = 0.01, hasta = null, filtro = null } = {}) {
    const o = ctx.createOscillator(); const g = ctx.createGain();
    o.type = tipo; o.frequency.setValueAtTime(frec, t);
    if (hasta) o.frequency.exponentialRampToValueAtTime(hasta, t + dur);
    let nodo = o;
    if (filtro) { const f = ctx.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = filtro; o.connect(f); nodo = f; }
    nodo.connect(g); g.connect(dest);
    env(ctx, g, t, ataque, vol, dur);
    o.start(t); o.stop(t + ataque + dur + 0.05);
  }
  function golpeRuido(ctx, dest, t, { dur = 0.08, vol = 0.4, tipo = 'bandpass', frec = 1500, q = 1 } = {}) {
    const s = ctx.createBufferSource(); s.buffer = ruido(ctx);
    const f = ctx.createBiquadFilter(); f.type = tipo; f.frequency.value = frec; f.Q.value = q;
    const g = ctx.createGain();
    s.connect(f); f.connect(g); g.connect(dest);
    env(ctx, g, t, 0.002, vol, dur);
    s.start(t, Math.random() * 1.5); s.stop(t + dur + 0.05);
  }

  function efecto(ctx, dest, clave) {
    const t = ctx.currentTime + 0.02;
    switch (clave) {
      case 'aplausos': {
        for (let i = 0; i < 140; i++) {
          const ti = t + Math.random() * 3.2 * Math.pow(Math.random(), 0.4);
          const fade = 1 - (ti - t) / 3.6;
          golpeRuido(ctx, dest, ti, { dur: 0.03 + Math.random() * 0.04, vol: 0.12 * fade + 0.02, frec: 900 + Math.random() * 2200, q: 0.7 });
        }
        break;
      }
      case 'redoble': {
        let ti = t;
        for (let i = 0; i < 46; i++) { golpeRuido(ctx, dest, ti, { dur: 0.05, vol: 0.12 + i * 0.006, frec: 2500, q: 0.6, tipo: 'highpass' }); ti += Math.max(0.035, 0.09 - i * 0.0015); }
        golpeRuido(ctx, dest, ti + 0.05, { dur: 1.6, vol: 0.35, frec: 6000, tipo: 'highpass', q: 0.3 });
        tono(ctx, dest, ti + 0.05, 70, 0.6, { vol: 0.6, hasta: 40 });
        break;
      }
      case 'gong': {
        [[110, 0.5], [164, 0.28], [221, 0.22], [297, 0.14], [413, 0.09], [529, 0.06]].forEach(([f, v]) => tono(ctx, dest, t, f, 5.5, { vol: v, ataque: 0.02 }));
        golpeRuido(ctx, dest, t, { dur: 0.4, vol: 0.12, frec: 400, q: 0.5 });
        break;
      }
      case 'campana': {
        [[880, 0.3], [1760, 0.12], [2640, 0.06], [1320, 0.08]].forEach(([f, v]) => tono(ctx, dest, t, f, 2.2, { vol: v, ataque: 0.003 }));
        break;
      }
      case 'latido': {
        for (let k = 0; k < 4; k++) { const b = t + k * 0.85; tono(ctx, dest, b, 60, 0.18, { vol: 0.8, hasta: 40 }); tono(ctx, dest, b + 0.22, 55, 0.22, { vol: 0.6, hasta: 38 }); }
        break;
      }
      case 'rebote': {
        tono(ctx, dest, t, 180, 0.55, { vol: 0.4, hasta: 720, tipo: 'triangle' });
        tono(ctx, dest, t + 0.5, 720, 0.4, { vol: 0.25, hasta: 300, tipo: 'triangle' });
        break;
      }
      case 'fanfarria': {
        const notas = [[523, 0], [659, 0.16], [784, 0.32], [1046, 0.5]];
        notas.forEach(([f, d], i) => [1, 1.5, 2].forEach((m, j) => tono(ctx, dest, t + d, f * m, i === 3 ? 1.4 : 0.22, { tipo: 'sawtooth', vol: 0.11 / (j + 1), ataque: 0.02, filtro: 2600 })));
        golpeRuido(ctx, dest, t + 0.5, { dur: 1.2, vol: 0.12, frec: 7000, tipo: 'highpass' });
        break;
      }
      case 'transicion': {
        const s = ctx.createBufferSource(); s.buffer = ruido(ctx);
        const f = ctx.createBiquadFilter(); f.type = 'bandpass'; f.Q.value = 3;
        f.frequency.setValueAtTime(300, t); f.frequency.exponentialRampToValueAtTime(5000, t + 0.6); f.frequency.exponentialRampToValueAtTime(800, t + 1.1);
        const g = ctx.createGain(); s.connect(f); f.connect(g); g.connect(dest);
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.5, t + 0.5); g.gain.exponentialRampToValueAtTime(0.0001, t + 1.2);
        s.start(t); s.stop(t + 1.3);
        break;
      }
      case 'alerta': {
        [0, 0.28, 0.56].forEach((d, i) => { tono(ctx, dest, t + d, i === 2 ? 1175 : 988, 0.22, { tipo: 'square', vol: 0.09, filtro: 3000 }); });
        tono(ctx, dest, t + 0.9, 196, 1.2, { tipo: 'sawtooth', vol: 0.12, filtro: 900 });
        break;
      }
      case 'risas': {
        for (let k = 0; k < 9; k++) {
          const b = t + k * 0.17 + Math.random() * 0.03;
          const f = 330 + Math.sin(k) * 40 - k * 6;
          tono(ctx, dest, b, f, 0.12, { tipo: 'sawtooth', vol: 0.08, hasta: f * 0.8, filtro: 1800 });
          golpeRuido(ctx, dest, b, { dur: 0.1, vol: 0.05, frec: 1200, q: 2 });
        }
        break;
      }
    }
  }

  // ------------------------------------------------------------- música generativa
  const N = (n) => 440 * Math.pow(2, (n - 69) / 12); // MIDI → Hz
  const PIEZAS = {
    ambiente: { bpm: 70, acordes: [[57, 60, 64], [53, 57, 60], [48, 52, 55], [55, 59, 62]], bajo: [45, 41, 36, 43], pad: true, arpegio: true },
    noticias: { bpm: 112, acordes: [[57, 60, 64], [57, 60, 64], [53, 57, 60], [55, 59, 62]], bajo: [33, 33, 29, 31], pulso: true, bateria: 'noticias' },
    lofi: { bpm: 78, acordes: [[62, 65, 69, 72], [55, 59, 62, 65], [60, 64, 67, 71], [57, 60, 64, 67]], bajo: [38, 31, 36, 33], pad: true, bateria: 'lofi', swing: 0.12 },
    tension: { bpm: 90, acordes: [[57, 60, 63], [57, 60, 63], [56, 59, 63], [55, 58, 62]], bajo: [33, 33, 32, 31], pulso: true, pad: true },
    celebracion: { bpm: 124, acordes: [[60, 64, 67], [65, 69, 72], [67, 71, 74], [65, 69, 72]], bajo: [36, 41, 43, 41], pulso: true, arpegio: true, bateria: 'noticias' },
  };

  class Musica {
    constructor(ctx, destino, clave) {
      this.ctx = ctx; this.clave = PIEZAS[clave] ? clave : 'ambiente'; this.p = PIEZAS[this.clave];
      this.salida = ctx.createGain(); this.salida.gain.value = 0.0001; this.salida.connect(destino);
      this.paso = 0; this.timer = null;
    }
    iniciar() {
      const ctx = this.ctx;
      this.salida.gain.setValueAtTime(0.0001, ctx.currentTime);
      this.salida.gain.exponentialRampToValueAtTime(0.5, ctx.currentTime + 1.5);
      this.siguiente = ctx.currentTime + 0.1;
      const corchea = 60 / this.p.bpm / 2;
      const programar = () => {
        while (this.siguiente < ctx.currentTime + 0.35) { this.nota(this.siguiente, this.paso, corchea); this.siguiente += corchea; this.paso++; }
      };
      programar();
      this.timer = setInterval(programar, 100);
    }
    nota(t, paso, corchea) {
      const p = this.p, ctx = this.ctx, d = this.salida;
      const compas = Math.floor(paso / 8) % p.acordes.length, enCompas = paso % 8;
      const sw = p.swing && enCompas % 2 === 1 ? corchea * p.swing : 0;
      t += sw;
      const acorde = p.acordes[compas];
      if (enCompas === 0) {
        if (p.pad) acorde.forEach((n) => { tono(ctx, d, t, N(n), corchea * 8, { tipo: 'triangle', vol: 0.06, ataque: corchea * 2, filtro: 1400 }); tono(ctx, d, t, N(n) * 1.004, corchea * 8, { tipo: 'sine', vol: 0.04, ataque: corchea * 2 }); });
        tono(ctx, d, t, N(p.bajo[compas]), corchea * (p.pulso ? 1 : 7), { tipo: 'sine', vol: 0.22, ataque: 0.01 });
      }
      if (p.pulso && enCompas > 0) tono(ctx, d, t, N(p.bajo[compas]), corchea * 0.9, { tipo: 'sawtooth', vol: 0.05, filtro: 400 + (enCompas % 2) * 200 });
      if (p.arpegio) { const n = acorde[enCompas % acorde.length] + 12; tono(ctx, d, t, N(n), corchea * 1.5, { tipo: 'sine', vol: 0.045, ataque: 0.005 }); }
      if (p.bateria === 'noticias') {
        if (enCompas % 4 === 0) tono(ctx, d, t, 90, 0.18, { vol: 0.35, hasta: 45 });
        if (enCompas % 4 === 2) golpeRuido(ctx, d, t, { dur: 0.12, vol: 0.14, frec: 1800, q: 0.7 });
        golpeRuido(ctx, d, t, { dur: 0.03, vol: 0.05, frec: 8000, tipo: 'highpass' });
      } else if (p.bateria === 'lofi') {
        if (enCompas === 0 || enCompas === 5) tono(ctx, d, t, 70, 0.22, { vol: 0.3, hasta: 40 });
        if (enCompas === 2 || enCompas === 6) golpeRuido(ctx, d, t, { dur: 0.16, vol: 0.08, frec: 1200, q: 0.5 });
        if (enCompas % 2 === 0) golpeRuido(ctx, d, t, { dur: 0.025, vol: 0.03, frec: 7000, tipo: 'highpass' });
      }
    }
    detener() {
      clearInterval(this.timer); this.timer = null;
      const g = this.salida.gain, t = this.ctx.currentTime;
      try { g.cancelScheduledValues(t); g.setValueAtTime(Math.max(g.value, 0.0001), t); g.exponentialRampToValueAtTime(0.0001, t + 0.8); } catch (e) {}
      setTimeout(() => { try { this.salida.disconnect(); } catch (e) {} }, 1200);
    }
  }

  window.EditusAudio = { EFECTOS, MUSICAS, efecto, Musica };
})();
