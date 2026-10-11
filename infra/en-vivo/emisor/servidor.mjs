// =============================================================================
// Emisor de editus: videos pregrabados que salen EN VIVO a una hora programada.
//
//   1. Subida por partes desde el navegador (token firmado por editus).
//   2. Procesado una sola vez, con baja prioridad: formato de transmisión
//      (H.264 + AAC, cuadro clave cada 2 s), logo, marco y etiqueta "Pregrabado",
//      más un clip de cuenta regresiva con los mismos parámetros.
//   3. Emisión SIN recodificar (-c copy) a uno o varios RTMP(S): un proceso por
//      destino, así si uno se cae los demás siguen. Casi no usa CPU, de modo que
//      caben varias emisiones simultáneas. Si la conexión se cae, retoma desde
//      donde iba.
//
// Solo editus habla con este servicio (peticiones firmadas con EMISOR_SECRETO).
// Node 22 sin dependencias; necesita ffmpeg y ffprobe.
// =============================================================================
import http from 'node:http';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';

const env = process.env;
const CFG = {
  host: env.EMISOR_HOST || '127.0.0.1',
  puerto: Number(env.EMISOR_PUERTO || 7890),
  secreto: env.EMISOR_SECRETO || '',
  dir: env.EMISOR_DIR || '/datos',
  webhook: env.EDITUS_WEBHOOK || '',
  origenes: (env.EMISOR_ORIGENES || '').split(',').map((s) => s.trim()).filter(Boolean),
  maxBytes: Number(env.EMISOR_MAX_GB || 6) * 1024 ** 3,
  parteMax: 80 * 1024 ** 2,
  procesos: Math.max(1, Number(env.EMISOR_PROCESOS || 1)),
  hilos: Math.max(1, Number(env.EMISOR_HILOS || 2)),
  preset: env.EMISOR_PRESET || 'veryfast',
  fuente: env.EMISOR_FUENTE || '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
  ffmpeg: env.FFMPEG || 'ffmpeg',
  ffprobe: env.FFPROBE || 'ffprobe',
  reintentos: Number(env.EMISOR_REINTENTOS || 6),
};
export const VERSION = '1.0.0';
const FPS = 30;
const BITRATE = { 1080: 4500, 720: 2800 };

const log = (...a) => console.log(new Date().toISOString(), ...a);

// ---------------------------------------------------------------- firmas

const sha256 = (b) => crypto.createHash('sha256').update(b).digest('hex');
const hmac = (txt) => crypto.createHmac('sha256', CFG.secreto).update(txt).digest('hex');
const iguales = (a, b) => typeof a === 'string' && typeof b === 'string' && a.length === b.length && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));

/** Firma de una petición editus ⇄ emisor: HMAC(ts \n nonce \n MÉTODO \n ruta \n sha256(cuerpo)). */
export function firmar(ts, nonce, metodo, ruta, cuerpo) {
  return hmac(`${ts}\n${nonce}\n${metodo.toUpperCase()}\n${ruta}\n${sha256(cuerpo || '')}`);
}

const nonces = new Map();
function firmaValida(req, ruta, cuerpo) {
  const ts = Number(req.headers['x-emisor-ts']);
  const nonce = String(req.headers['x-emisor-nonce'] || '');
  const firma = String(req.headers['x-emisor-firma'] || '');
  if (!ts || !nonce || Math.abs(Date.now() / 1000 - ts) > 300) return false;
  if (!iguales(firma, firmar(ts, nonce, req.method, ruta, cuerpo))) return false;
  if (nonces.has(nonce)) return false;
  nonces.set(nonce, Date.now());
  if (nonces.size > 5000) for (const [k, t] of nonces) if (Date.now() - t > 600_000) nonces.delete(k);
  return true;
}

/** Token de subida para el navegador: base64url(json {v, max, exp}) . hmac. Lo genera editus. */
function tokenSubida(token, id) {
  const [cuerpo, firma] = String(token || '').split('.');
  if (!cuerpo || !firma || !iguales(firma, hmac('subida.' + cuerpo))) return null;
  try {
    const d = JSON.parse(Buffer.from(cuerpo, 'base64url').toString());
    if (d.v !== id || !(d.exp > Date.now() / 1000)) return null;
    return d;
  } catch { return null; }
}

// ---------------------------------------------------------------- estado

const ID = /^[a-z0-9][a-z0-9-]{7,63}$/;
const rutaDe = (id, ...p) => path.join(CFG.dir, id, ...p);
const estados = new Map(); // id → estado (en memoria y en estado.json)
const procesos = new Map(); // id (o id#destino) → proceso ffmpeg en curso

async function leerEstado(id) {
  if (estados.has(id)) return estados.get(id);
  try {
    const e = JSON.parse(await fsp.readFile(rutaDe(id, 'estado.json'), 'utf8'));
    estados.set(id, e);
    return e;
  } catch { return null; }
}

const escrituras = new Map(); // id → promesa de la última escritura (se escriben en orden)
function guardar(e) {
  e.actualizado = Date.now();
  estados.set(e.id, e);
  const previa = escrituras.get(e.id) || Promise.resolve();
  const esta = previa.then(async () => {
    if (!fs.existsSync(rutaDe(e.id))) return; // se borró
    const tmp = rutaDe(e.id, `estado.${crypto.randomUUID()}.tmp`);
    await fsp.writeFile(tmp, JSON.stringify(e), { mode: 0o600 });
    await fsp.rename(tmp, rutaDe(e.id, 'estado.json'));
  }).catch((err) => log('[guardar]', e.id, err.message));
  escrituras.set(e.id, esta);
  return esta;
}

/** Lo que se le muestra a editus (sin las URLs de transmisión, que son secretas). */
function publico(e) {
  if (!e) return null;
  const { emision, opciones, ...resto } = e;
  return { ...resto, emision: emision ? {
    inicio: emision.inicio, total: emision.total, cuenta: emision.cuenta,
    posicion: Math.max(0, ...emision.destinos.map((d) => d.posicion || 0)),
    destinos: emision.destinos.map((d) => ({ estado: d.estado, posicion: d.posicion, intentos: d.intentos, error: d.error || null })),
  } : null };
}

async function avisar(e, evento) {
  if (!CFG.webhook) return;
  const cuerpo = JSON.stringify({ evento, video: publico(e) });
  const url = new URL(CFG.webhook);
  for (let i = 0; i < 3; i++) {
    const ts = Math.floor(Date.now() / 1000), nonce = crypto.randomUUID();
    try {
      const r = await fetch(url, { method: 'POST', body: cuerpo, signal: AbortSignal.timeout(15000), headers: {
        'Content-Type': 'application/json', 'X-Emisor-Ts': String(ts), 'X-Emisor-Nonce': nonce, 'X-Emisor-Firma': firmar(ts, nonce, 'POST', url.pathname, cuerpo) } });
      if (r.ok) return;
    } catch { /* reintenta */ }
    await new Promise((r) => setTimeout(r, 3000 * (i + 1)));
  }
  log('[aviso] editus no respondió', e.id, evento);
}

async function libre() {
  try { const s = await fsp.statfs(CFG.dir); return s.bavail * s.bsize; } catch { return null; }
}

// ---------------------------------------------------------------- ffmpeg

function correr(args, { id, alProgreso, nice = false } = {}) { // id: clave en "procesos" para poder detenerlo
  return new Promise((resolve) => {
    const cmd = nice ? 'nice' : CFG.ffmpeg;
    const p = spawn(cmd, nice ? ['-n', '15', CFG.ffmpeg, ...args] : args, { stdio: ['ignore', 'pipe', 'pipe'] });
    if (id) procesos.set(id, p);
    let err = '';
    let buf = '';
    p.stdout.on('data', (d) => {
      buf += d;
      let i;
      while ((i = buf.indexOf('\n')) >= 0) {
        const linea = buf.slice(0, i); buf = buf.slice(i + 1);
        const m = linea.match(/^out_time_us=(\d+)/);
        if (m && alProgreso) alProgreso(Number(m[1]) / 1e6);
      }
    });
    p.stderr.on('data', (d) => { err = (err + d).slice(-4000); });
    p.on('close', (codigo, senal) => { if (id && procesos.get(id) === p) procesos.delete(id); resolve({ codigo, senal, error: err }); });
    p.on('error', (e) => resolve({ codigo: -1, error: String(e) }));
  });
}

function sondear(archivo) {
  return new Promise((resolve) => {
    const p = spawn(CFG.ffprobe, ['-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', archivo]);
    let out = '';
    p.stdout.on('data', (d) => { out += d; });
    p.on('close', () => { try { resolve(JSON.parse(out)); } catch { resolve(null); } });
    p.on('error', () => resolve(null));
  });
}

/** Último renglón útil del error de ffmpeg (para mostrarlo en editus). */
const errorCorto = (txt) => (String(txt).trim().split('\n').filter((l) => l.trim() && !/^\s*(frame|size)=/.test(l)).slice(-2).join(' · ') || 'error de ffmpeg').slice(0, 400);

async function descargar(url, destino) {
  const r = await fetch(url, { signal: AbortSignal.timeout(30000), headers: { 'User-Agent': 'editus-emisor' } });
  if (!r.ok) throw new Error(`no se pudo descargar ${url} (${r.status})`);
  const b = Buffer.from(await r.arrayBuffer());
  if (b.length > 15 * 1024 ** 2) throw new Error('la imagen supera 15 MB');
  await fsp.writeFile(destino, b);
  return destino;
}

/** Codificación común del video y de la cuenta regresiva: deben coincidir para unirlos sin recodificar. */
function codificacion(alto) {
  const kb = BITRATE[alto] || BITRATE[1080];
  return ['-c:v', 'libx264', '-preset', CFG.preset, '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', String(FPS),
    '-b:v', `${kb}k`, '-maxrate', `${kb}k`, '-bufsize', `${2 * kb}k`, '-g', String(2 * FPS), '-keyint_min', String(2 * FPS), '-sc_threshold', '0',
    '-threads', String(CFG.hilos), '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
    '-video_track_timescale', '15360', '-movflags', '+faststart'];
}

/** Capas comunes (marco y logo) sobre la entrada [base]; devuelve el nombre de la salida. */
function capas(filtros, base, entradas, W, H) {
  let actual = base;
  if (entradas.marco !== undefined) {
    filtros.push(`[${entradas.marco}:v]scale=${W}:${H}[marco]`, `[${actual}][marco]overlay=0:0[conmarco]`);
    actual = 'conmarco';
  }
  if (entradas.logo !== undefined) {
    filtros.push(`[${entradas.logo}:v]scale=${Math.round(W * 0.14)}:-1[logo]`, `[${actual}][logo]overlay=W-w-${Math.round(W * 0.03)}:${Math.round(H * 0.04)}[conlogo]`);
    actual = 'conlogo';
  }
  return actual;
}

const cola = [];
let procesando = 0;

function encolar(id) {
  if (!cola.includes(id)) cola.push(id);
  siguiente();
}

function siguiente() {
  while (procesando < CFG.procesos && cola.length) {
    const id = cola.shift();
    procesando++;
    procesar(id).catch((err) => log('[procesar]', id, err)).finally(() => { procesando--; siguiente(); });
  }
}

async function procesar(id) {
  const e = await leerEstado(id);
  if (!e || !['en_cola', 'procesando'].includes(e.estado)) return;
  const o = e.opciones || {};
  const fallar = async (msg) => { e.estado = 'error'; e.error = msg; await guardar(e); await avisar(e, 'error'); };
  e.estado = 'procesando'; e.progreso = 0; e.error = null;
  await guardar(e);

  const info = await sondear(rutaDe(id, 'original'));
  const video = info?.streams?.find((s) => s.codec_type === 'video');
  if (!video) return fallar('El archivo no tiene video o está dañado.');
  const audio = info.streams.some((s) => s.codec_type === 'audio');
  const duracion = Number(info.format?.duration || video.duration || 0);
  if (!(duracion > 1)) return fallar('No se pudo leer la duración del video.');
  if (o.max_segundos && duracion > o.max_segundos + 1) return fallar(`El video dura ${Math.round(duracion / 60)} min y tu plan permite hasta ${Math.round(o.max_segundos / 60)} min.`);
  e.duracion = Math.round(duracion * 10) / 10;
  e.origen = { ancho: video.width, alto: video.height, codec: video.codec_name, audio };

  const H = Number(o.alto) === 720 || (video.height && video.height < 1000) ? 720 : 1080;
  const W = H === 720 ? 1280 : 1920;
  e.salida = { ancho: W, alto: H, fps: FPS };

  try {
    if (o.marco_url) await descargar(o.marco_url, rutaDe(id, 'marco.png'));
    if (o.logo_url) await descargar(o.logo_url, rutaDe(id, 'logo.png'));
  } catch (err) { return fallar('Plantilla: ' + err.message); }
  if (o.etiqueta) await fsp.writeFile(rutaDe(id, 'etiqueta.txt'), String(o.etiqueta).slice(0, 40));

  // 1) Video principal
  const args = ['-hide_banner', '-y', '-nostats', '-progress', 'pipe:1', '-i', rutaDe(id, 'original')];
  const entradas = {};
  let n = 1;
  if (o.marco_url) { args.push('-i', rutaDe(id, 'marco.png')); entradas.marco = n++; }
  if (o.logo_url) { args.push('-i', rutaDe(id, 'logo.png')); entradas.logo = n++; }
  let audioIn = '0:a:0';
  if (!audio) { args.push('-f', 'lavfi', '-t', String(duracion), '-i', 'anullsrc=r=44100:cl=stereo'); audioIn = `${n++}:a`; }
  const filtros = [`[0:v:0]scale=${W}:${H}:force_original_aspect_ratio=decrease,pad=${W}:${H}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=${FPS},format=yuv420p[base]`];
  let salida = capas(filtros, 'base', entradas, W, H);
  if (o.etiqueta) {
    const fs_ = Math.round(H * 0.026);
    filtros.push(`[${salida}]drawtext=fontfile='${CFG.fuente}':textfile='${rutaDe(id, 'etiqueta.txt')}':fontsize=${fs_}:fontcolor=white:box=1:boxcolor=0xDC2626@0.92:boxborderw=${Math.round(fs_ * 0.45)}:x=${Math.round(W * 0.03)}:y=${Math.round(H * 0.045)}[final]`);
    salida = 'final';
  }
  args.push('-filter_complex', filtros.join(';'), '-map', `[${salida}]`, '-map', audioIn, ...codificacion(H), '-t', String(duracion), rutaDe(id, 'listo.tmp.mp4'));
  let ultimo = 0;
  const r = await correr(args, { id, nice: true, alProgreso: (s) => {
    const p = Math.min(99, Math.floor(100 * s / duracion));
    if (p !== e.progreso && Date.now() - ultimo > 2000) { ultimo = Date.now(); e.progreso = p; guardar(e).catch(() => {}); }
  } });
  if ((await leerEstado(id))?.estado !== 'procesando') return; // se canceló
  if (r.codigo !== 0) return fallar('No se pudo procesar el video: ' + errorCorto(r.error));
  await fsp.rename(rutaDe(id, 'listo.tmp.mp4'), rutaDe(id, 'listo.mp4'));

  // 2) Cuenta regresiva con la misma codificación (se une al video sin recodificar)
  const c = o.cuenta || {};
  const S = Math.max(0, Math.min(300, Math.round(Number(c.segundos ?? 60))));
  if (S > 0) {
    await fsp.writeFile(rutaDe(id, 'titulo.txt'), String(c.titulo || '').slice(0, 90));
    await fsp.writeFile(rutaDe(id, 'aviso.txt'), String(c.subtitulo || 'Comenzamos en').slice(0, 60));
    const color = /^#?[0-9a-fA-F]{6}$/.test(String(c.color || '')) ? String(c.color).replace('#', '0x') : '0x00024F';
    const a2 = ['-hide_banner', '-y', '-nostats', '-f', 'lavfi', '-i', `color=c=${color}:s=${W}x${H}:r=${FPS}:d=${S}`, '-f', 'lavfi', '-t', String(S), '-i', 'anullsrc=r=44100:cl=stereo'];
    const ent2 = {};
    let k = 2;
    if (o.marco_url) { a2.push('-i', rutaDe(id, 'marco.png')); ent2.marco = k++; }
    if (o.logo_url) { a2.push('-i', rutaDe(id, 'logo.png')); ent2.logo = k++; }
    const f2 = ['[0:v]format=yuv420p,setsar=1[fondo]'];
    let s2 = capas(f2, 'fondo', ent2, W, H);
    const fuente = `fontfile='${CFG.fuente}'`;
    const reloj = `%{eif\\:trunc((${S}-t)/60)\\:d}\\:%{eif\\:mod(trunc(${S}-t)\\,60)\\:d\\:2}`;
    f2.push(`[${s2}]drawtext=${fuente}:textfile='${rutaDe(id, 'titulo.txt')}':fontsize=${Math.round(H * 0.05)}:fontcolor=white:x=(w-text_w)/2:y=h*0.36,`
      + `drawtext=${fuente}:textfile='${rutaDe(id, 'aviso.txt')}':fontsize=${Math.round(H * 0.035)}:fontcolor=white@0.8:x=(w-text_w)/2:y=h*0.50,`
      + `drawtext=${fuente}:text='${reloj}':fontsize=${Math.round(H * 0.11)}:fontcolor=white:x=(w-text_w)/2:y=h*0.57[cuenta]`);
    a2.push('-filter_complex', f2.join(';'), '-map', '[cuenta]', '-map', '1:a', ...codificacion(H), '-t', String(S), rutaDe(id, 'cuenta.mp4'));
    const r2 = await correr(a2, { id, nice: true });
    if (r2.codigo !== 0) return fallar('No se pudo crear la cuenta regresiva: ' + errorCorto(r2.error));
  } else {
    await fsp.rm(rutaDe(id, 'cuenta.mp4'), { force: true });
  }
  e.cuenta_segundos = S;
  e.estado = 'listo'; e.progreso = 100;
  await guardar(e);
  await avisar(e, 'listo');
  log('[listo]', id, `${W}x${H}`, `${e.duracion}s`);
}

// --------------------------------------------------------------- emisión

/** Calcula lo que dura la emisión (cuenta regresiva + video). */
function prepararEmision(e) {
  const em = e.emision;
  if (!fs.existsSync(rutaDe(e.id, 'cuenta.mp4'))) em.cuenta = false;
  em.total = (em.cuenta ? (e.cuenta_segundos || 0) : 0) + (e.duracion || 0);
}

/**
 * Lista para el demuxer concat desde el segundo "desde" (para retomar): se recorta con
 * "inpoint", que con -c copy arranca en el cuadro clave anterior (hay uno cada 2 s).
 */
async function listaDesde(e, i, desde) {
  const cuenta = e.emision.cuenta ? (e.cuenta_segundos || 0) : 0;
  const lineas = [];
  if (cuenta > 0 && desde < cuenta) {
    lineas.push(`file '${rutaDe(e.id, 'cuenta.mp4')}'`);
    if (desde > 0.5) lineas.push(`inpoint ${desde.toFixed(2)}`);
  }
  lineas.push(`file '${rutaDe(e.id, 'listo.mp4')}'`);
  if (desde - cuenta > 0.5) lineas.push(`inpoint ${(desde - cuenta).toFixed(2)}`);
  const archivo = rutaDe(e.id, `lista-${i}.txt`);
  await fsp.writeFile(archivo, lineas.join('\n') + '\n');
  return archivo;
}

/** Cuando todos los destinos terminaron (bien o mal), cierra la emisión. */
async function cerrarSiTermino(e) {
  const ds = e.emision.destinos;
  if (ds.some((d) => d.estado === 'emitiendo')) return;
  if (ds.some((d) => d.estado === 'terminado')) {
    e.estado = 'emitido'; e.emitido_en = Date.now();
    e.error = ds.some((d) => d.estado === 'error') ? 'Algún destino no completó la transmisión: ' + ds.filter((d) => d.error).map((d) => d.error).join(' · ') : null;
    await guardar(e);
    await avisar(e, 'terminado');
    log('[emitido]', e.id);
  } else {
    e.estado = 'error'; e.error = 'La transmisión se cortó y no se pudo retomar: ' + (ds.find((d) => d.error)?.error || 'sin detalle');
    await guardar(e);
    await avisar(e, 'error');
  }
}

/** Emite a UN destino desde su posición; si se corta, retoma solo hasta agotar los reintentos. */
async function emitirDestino(id, i) {
  const e = await leerEstado(id);
  if (!e?.emision || e.estado !== 'emitiendo') return;
  const d = e.emision.destinos[i];
  if (!d || d.estado !== 'emitiendo') return;
  const desde = Math.max(0, d.posicion || 0);
  const lista = await listaDesde(e, i, desde);
  const args = ['-hide_banner', '-nostats', '-progress', 'pipe:1', '-re', '-f', 'concat', '-safe', '0', '-i', lista,
    '-map', '0:v', '-map', '0:a', '-c', 'copy', '-f', 'flv', '-flvflags', 'no_duration_filesize', d.url];
  log('[emitir]', id, `destino ${i + 1}`, `desde ${Math.round(desde)}s`, `intento ${d.intentos}`);
  let ultimo = 0;
  const r = await correr(args, { id: `${id}#${i}`, alProgreso: (s) => {
    d.posicion = desde + s; d.latido = Date.now();
    if (Date.now() - ultimo > 5000) { ultimo = Date.now(); guardar(e).catch(() => {}); }
  } });
  const actual = await leerEstado(id);
  if (!actual || actual.estado !== 'emitiendo') return; // detenida o borrada
  if (r.codigo === 0 && d.posicion >= e.emision.total - 3) {
    d.estado = 'terminado'; d.error = null;
  } else {
    d.intentos = (d.intentos || 0) + 1;
    d.error = errorCorto(r.error);
    if (d.intentos > CFG.reintentos) d.estado = 'error';
  }
  await guardar(e);
  if (d.estado === 'emitiendo') {
    await avisar(e, 'reintento');
    setTimeout(() => emitirDestino(id, i).catch((err) => log('[emitir]', id, err)), Math.min(15000, 2000 * d.intentos));
    return;
  }
  await cerrarSiTermino(e);
}

// ------------------------------------------------------------------ HTTP

function responder(res, codigo, datos, extra = {}) {
  res.writeHead(codigo, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...extra });
  res.end(JSON.stringify(datos));
}

function leerCuerpo(req, max = 1024 * 1024) {
  return new Promise((resolve, reject) => {
    let total = 0; const partes = [];
    req.on('data', (d) => { total += d.length; if (total > max) { reject(new Error('cuerpo muy grande')); req.destroy(); } else partes.push(d); });
    req.on('end', () => resolve(Buffer.concat(partes).toString()));
    req.on('error', reject);
  });
}

function cors(req) {
  const origen = String(req.headers.origin || '');
  const permitido = !CFG.origenes.length ? '*' : (CFG.origenes.includes(origen) ? origen : '');
  return permitido ? { 'Access-Control-Allow-Origin': permitido, 'Access-Control-Allow-Methods': 'GET, PUT, OPTIONS', 'Access-Control-Allow-Headers': 'Content-Range, Content-Type', 'Access-Control-Max-Age': '600', Vary: 'Origin' } : {};
}

/** PUT /emisor/subidas/:id  (Content-Range: bytes INICIO-FIN/TOTAL) */
async function recibirParte(req, res, id, tok, h) {
  const m = String(req.headers['content-range'] || '').match(/^bytes (\d+)-(\d+)\/(\d+)$/);
  if (!m) return responder(res, 400, { error: 'Falta Content-Range' }, h);
  const [ini, fin, total] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const largo = fin - ini + 1;
  if (largo <= 0 || largo > CFG.parteMax || fin >= total) return responder(res, 400, { error: 'Rango no válido' }, h);
  if (total > Math.min(CFG.maxBytes, tok.max || CFG.maxBytes)) return responder(res, 413, { error: `El video supera el máximo permitido (${Math.round(Math.min(CFG.maxBytes, tok.max || CFG.maxBytes) / 1024 ** 3)} GB).` }, h);

  let e = await leerEstado(id);
  if (!e) {
    const espacio = await libre();
    if (espacio !== null && espacio < total * 2.2 + 2 * 1024 ** 3) return responder(res, 507, { error: 'El servidor de transmisión no tiene espacio suficiente para este video.' }, h);
    await fsp.mkdir(rutaDe(id), { recursive: true, mode: 0o700 });
    e = { id, creado: Date.now(), estado: 'subiendo', total, recibido: 0, progreso: 0 };
    await guardar(e);
  }
  if (e.estado !== 'subiendo') { req.resume(); return responder(res, 409, { error: 'El video ya se subió.', estado: e.estado }, h); }
  if (e.total !== total) { req.resume(); return responder(res, 409, { error: 'El tamaño no coincide con la subida iniciada.' }, h); }
  const archivo = rutaDe(id, 'original');
  const actual = fs.existsSync(archivo) ? (await fsp.stat(archivo)).size : 0;
  if (ini > actual) { req.resume(); return responder(res, 409, { error: 'Falta una parte anterior', recibido: actual }, h); }
  if (fin < actual) { req.resume(); return responder(res, 200, { recibido: actual, total }, h); } // ya la tenía (reintento)
  if (ini < actual) await fsp.truncate(archivo, ini);

  const salida = fs.createWriteStream(archivo, { flags: ini === 0 ? 'w' : 'a', mode: 0o600 });
  let escritos = 0;
  req.on('data', (d) => { escritos += d.length; });
  try {
    await new Promise((resolve, reject) => { req.pipe(salida); salida.on('finish', resolve); salida.on('error', reject); req.on('error', reject); req.on('aborted', () => reject(new Error('cancelada'))); });
  } catch {
    await fsp.truncate(archivo, ini).catch(() => {});
    return responder(res, 400, { error: 'La parte llegó incompleta, vuelve a enviarla.', recibido: ini }, h);
  }
  if (escritos !== largo) {
    await fsp.truncate(archivo, ini).catch(() => {});
    return responder(res, 400, { error: 'La parte llegó incompleta, vuelve a enviarla.', recibido: ini }, h);
  }
  e.recibido = ini + escritos;
  if (e.recibido >= total) { e.estado = 'subido'; e.progreso = 0; }
  await guardar(e);
  if (e.estado === 'subido') avisar(e, 'subido');
  return responder(res, 200, { recibido: e.recibido, total, terminado: e.estado === 'subido' }, h);
}

/** Detiene el procesado o todas las emisiones de un video. */
async function detenerProceso(id) {
  const lista = [...procesos.entries()].filter(([k]) => k === id || k.startsWith(id + '#'));
  for (const [, p] of lista) p.kill('SIGINT');
  if (!lista.length) return;
  await new Promise((r) => setTimeout(r, 1500));
  for (const [k, p] of lista) if (procesos.get(k) === p) p.kill('SIGKILL');
}

async function manejar(req, res) {
  const url = new URL(req.url, 'http://emisor');
  const ruta = url.pathname;
  const partes = ruta.split('/').filter(Boolean); // ['emisor', ...]
  if (partes[0] !== 'emisor') return responder(res, 404, { error: 'No encontrado' });

  if (ruta === '/emisor/salud' && req.method === 'GET') return responder(res, 200, { ok: true, version: VERSION });

  // Subidas desde el navegador (token de subida)
  if (partes[1] === 'subidas' && partes[2]) {
    const id = partes[2];
    const h = cors(req);
    if (req.method === 'OPTIONS') { res.writeHead(204, h); return res.end(); }
    if (!ID.test(id)) return responder(res, 400, { error: 'Id no válido' }, h);
    const tok = tokenSubida(url.searchParams.get('token'), id);
    if (!tok) { req.resume(); return responder(res, 403, { error: 'El permiso de subida venció. Vuelve a empezar desde editus.' }, h); }
    if (req.method === 'GET') {
      const e = await leerEstado(id);
      return responder(res, 200, { recibido: e?.recibido || 0, total: e?.total || null, estado: e?.estado || 'nuevo' }, h);
    }
    if (req.method === 'PUT') return recibirParte(req, res, id, tok, h);
    return responder(res, 405, { error: 'Método no permitido' }, h);
  }

  // Todo lo demás: solo editus (firmado)
  let cuerpo = '';
  try { cuerpo = await leerCuerpo(req); } catch { return responder(res, 413, { error: 'Cuerpo muy grande' }); }
  if (!firmaValida(req, ruta, cuerpo)) return responder(res, 401, { error: 'Firma no válida' });
  let datos = {};
  if (cuerpo) { try { datos = JSON.parse(cuerpo); } catch { return responder(res, 400, { error: 'JSON no válido' }); } }

  if (ruta === '/emisor/estado' && req.method === 'GET') {
    const em = [...estados.values()].filter((e) => e.estado === 'emitiendo').length;
    return responder(res, 200, { ok: true, version: VERSION, libre: await libre(), procesando, cola: cola.length, emitiendo: em });
  }

  if (partes[1] !== 'videos' || !partes[2] || !ID.test(partes[2])) return responder(res, 404, { error: 'No encontrado' });
  const id = partes[2];
  const accion = partes[3] || '';
  const e = await leerEstado(id);

  if (req.method === 'GET' && !accion) return e ? responder(res, 200, publico(e)) : responder(res, 404, { error: 'No existe' });

  if (req.method === 'DELETE' && !accion) {
    if (e) { e.estado = 'borrado'; estados.set(id, e); }
    const i = cola.indexOf(id); if (i >= 0) cola.splice(i, 1);
    await detenerProceso(id);
    await fsp.rm(rutaDe(id), { recursive: true, force: true });
    estados.delete(id);
    return responder(res, 200, { ok: true });
  }

  if (!e) return responder(res, 404, { error: 'No existe ese video en el servidor de transmisión' });

  if (req.method === 'POST' && accion === 'procesar') {
    if (!['subido', 'listo', 'error', 'emitido'].includes(e.estado) || !fs.existsSync(rutaDe(id, 'original'))) return responder(res, 409, { error: `No se puede procesar en estado ${e.estado}`, estado: e.estado });
    e.opciones = {
      alto: Number(datos.alto) === 720 ? 720 : 1080,
      max_segundos: Number(datos.max_segundos) || null,
      marco_url: /^https?:\/\//.test(datos.marco_url || '') ? datos.marco_url : null,
      logo_url: /^https?:\/\//.test(datos.logo_url || '') ? datos.logo_url : null,
      etiqueta: datos.etiqueta ? String(datos.etiqueta).slice(0, 40) : null,
      cuenta: datos.cuenta && typeof datos.cuenta === 'object' ? datos.cuenta : { segundos: 0 },
    };
    e.estado = 'en_cola'; e.progreso = 0; e.error = null;
    await guardar(e);
    encolar(id);
    return responder(res, 200, publico(e));
  }

  if (req.method === 'POST' && accion === 'emitir') {
    const destinos = (Array.isArray(datos.destinos) ? datos.destinos : []).filter((d) => /^rtmps?:\/\/\S+$/.test(String(d)));
    if (!destinos.length) return responder(res, 422, { error: 'No hay destinos RTMP válidos' });
    if (e.estado === 'emitiendo') return responder(res, 200, publico(e));
    if (!['listo', 'emitido', 'detenido', 'error'].includes(e.estado) || !fs.existsSync(rutaDe(id, 'listo.mp4'))) return responder(res, 409, { error: `El video no está listo (estado: ${e.estado})`, estado: e.estado });
    let desde = Math.max(0, Number(datos.desde) || 0);
    let cuenta = datos.cuenta_regresiva !== false && fs.existsSync(rutaDe(id, 'cuenta.mp4'));
    // Hora exacta en que debe empezar el VIDEO: la cuenta regresiva termina justo a esa hora.
    // Si editus avisó antes, se espera; si avisó un poco tarde, la cuenta empieza por la mitad.
    let espera = 0;
    const hora = Number(datos.hora_video) * 1000;
    if (cuenta && hora > 0 && !desde) {
      const S = (e.cuenta_segundos || 0) * 1000, falta = hora - Date.now();
      if (falta <= 1000) cuenta = false;
      else if (falta > S) espera = Math.min(falta - S, 10 * 60_000);
      else desde = (S - falta) / 1000;
    }
    e.estado = 'emitiendo'; e.error = null;
    e.emision = { cuenta, inicio: Date.now() + espera, espera,
      destinos: destinos.map((url) => ({ url, estado: 'emitiendo', posicion: desde, intentos: 0, latido: Date.now() + espera })) };
    prepararEmision(e);
    await guardar(e);
    const salir = () => e.emision.destinos.forEach((_, i) => emitirDestino(id, i).catch((err) => log('[emitir]', id, err)));
    if (espera > 0) { log('[emitir]', id, `espera ${Math.round(espera / 1000)} s para que la cuenta regresiva termine a la hora`); setTimeout(salir, espera); } else salir();
    return responder(res, 200, publico(e));
  }

  if (req.method === 'POST' && accion === 'detener') {
    if (e.estado === 'emitiendo') { e.estado = 'detenido'; await guardar(e); }
    if (['en_cola', 'procesando'].includes(e.estado)) {
      const i = cola.indexOf(id); if (i >= 0) cola.splice(i, 1);
      e.estado = 'subido'; e.progreso = 0; await guardar(e);
    }
    await detenerProceso(id);
    return responder(res, 200, publico(e));
  }

  return responder(res, 404, { error: 'No encontrado' });
}

// ---------------------------------------------------------------- arranque

/** Al reiniciar: vuelve a encolar lo que se estaba procesando y retoma las emisiones recientes. */
async function recuperar() {
  await fsp.mkdir(CFG.dir, { recursive: true });
  for (const id of await fsp.readdir(CFG.dir)) {
    if (!ID.test(id)) continue;
    const e = await leerEstado(id);
    if (!e) continue;
    if (['en_cola', 'procesando'].includes(e.estado)) { e.estado = 'en_cola'; await guardar(e); encolar(id); }
    if (e.estado === 'emitiendo' && e.emision) {
      const latido = Math.max(...e.emision.destinos.map((d) => d.latido || 0));
      if (Date.now() - latido < 10 * 60_000) {
        log('[recuperar] retomando', id);
        const salir = () => e.emision.destinos.forEach((d, i) => { if (d.estado === 'emitiendo') emitirDestino(id, i).catch(() => {}); });
        const falta = (e.emision.inicio || 0) - Date.now(); // seguía esperando la hora
        if (falta > 0) setTimeout(salir, falta); else salir();
      } else { e.estado = 'error'; e.error = 'La transmisión se interrumpió porque el servidor se reinició.'; await guardar(e); avisar(e, 'error'); }
    }
  }
}

export function iniciar() {
  if (CFG.secreto.length < 32) { console.error('Falta EMISOR_SECRETO (mínimo 32 caracteres)'); process.exit(1); }
  const servidor = http.createServer((req, res) => {
    manejar(req, res).catch((err) => { log('[http]', err); if (!res.headersSent) responder(res, 500, { error: 'Error interno del emisor' }); else res.end(); });
  });
  servidor.requestTimeout = 0; // las subidas grandes pueden tardar
  servidor.listen(CFG.puerto, CFG.host, () => log(`emisor ${VERSION} escuchando en ${CFG.host}:${CFG.puerto}, datos en ${CFG.dir}`));
  recuperar().catch((err) => log('[recuperar]', err));
  for (const s of ['SIGTERM', 'SIGINT']) process.on(s, () => { for (const p of procesos.values()) p.kill('SIGINT'); setTimeout(() => process.exit(0), 1500); });
  return servidor;
}

if (import.meta.url === `file://${process.argv[1]}`) iniciar();
