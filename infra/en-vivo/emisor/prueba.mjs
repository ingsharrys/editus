// Prueba de punta a punta del emisor (no toca Facebook): subida por partes, procesado con marco,
// logo y cuenta regresiva, emisión a 2 servidores RTMP locales, retomar, destino caído y avisos.
// Uso: node prueba.mjs   (necesita ffmpeg y ffprobe; tarda unos 2 minutos)
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'emisor-prueba-'));
fs.mkdirSync(DIR + '/web'); fs.mkdirSync(DIR + '/datos');
const FUENTE = process.env.EMISOR_FUENTE || '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
const ff = (...a) => execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', ...a]);
ff('-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=25:duration=20', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=20', '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', '-shortest', DIR + '/original.mp4');
ff('-f', 'lavfi', '-i', 'color=c=0x00024F:s=1920x100', '-vf', 'format=rgba,pad=1920:1080:0:980:color=0x00000000', '-frames:v', '1', DIR + '/web/marco.png');
ff('-f', 'lavfi', '-i', `color=c=0xFFFFFF:s=400x140,drawtext=fontfile=${FUENTE}:text=OPA:fontsize=90:fontcolor=0x00024F:x=(w-text_w)/2:y=(h-text_h)/2`, '-frames:v', '1', DIR + '/web/logo.png');
const SECRETO = 'x'.repeat(40);
const avisos = [];
// "editus": recibe los avisos y sirve marco/logo
const editus = http.createServer((req, res) => {
  if (req.method === 'POST') { let b = ''; req.on('data', (d) => b += d); req.on('end', () => { avisos.push({ firma: req.headers['x-emisor-firma'], ts: req.headers['x-emisor-ts'], nonce: req.headers['x-emisor-nonce'], cuerpo: b, ruta: req.url }); res.end('ok'); }); return; }
  const f = DIR + '/web' + req.url; if (fs.existsSync(f)) { res.end(fs.readFileSync(f)); } else { res.statusCode = 404; res.end(); }
}).listen(8901);

process.env.EMISOR_SECRETO = SECRETO; process.env.EMISOR_DIR = DIR + '/datos'; process.env.EMISOR_PUERTO = '8900';
process.env.EDITUS_WEBHOOK = 'http://127.0.0.1:8901/webhooks/emisor'; process.env.EMISOR_PRESET = 'ultrafast'; process.env.EMISOR_REINTENTOS = '2'; process.env.EMISOR_ORIGENES = ''; process.env.EMISOR_HOST = '127.0.0.1';
const { iniciar, firmar } = await import(path.join(path.dirname(fileURLToPath(import.meta.url)), 'servidor.mjs'));
const srv = iniciar();
await new Promise((r) => setTimeout(r, 300));
const B = 'http://127.0.0.1:8900';

const firmado = async (metodo, ruta, datos) => {
  const cuerpo = datos ? JSON.stringify(datos) : '';
  const ts = Math.floor(Date.now() / 1000), nonce = crypto.randomUUID();
  const r = await fetch(B + ruta, { method: metodo, body: cuerpo || undefined, headers: { 'X-Emisor-Ts': ts, 'X-Emisor-Nonce': nonce, 'X-Emisor-Firma': firmar(ts, nonce, metodo, ruta, cuerpo), 'Content-Type': 'application/json' } });
  return [r.status, await r.json()];
};
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); if (!c) process.exitCode = 1; };

const id = 'prueba-' + Date.now();
const cuerpoTok = Buffer.from(JSON.stringify({ v: id, max: 50 * 1024 ** 2, exp: Math.floor(Date.now() / 1000) + 3600 })).toString('base64url');
const token = cuerpoTok + '.' + crypto.createHmac('sha256', SECRETO).update('subida.' + cuerpoTok).digest('hex');

// --- seguridad
let [s] = await firmado('GET', '/emisor/estado'); ok(s === 200, 'estado firmado responde');
let r = await fetch(B + '/emisor/estado'); ok(r.status === 401, 'sin firma: 401');
r = await fetch(`${B}/emisor/subidas/${id}?token=malo`, { method: 'PUT', body: 'x', headers: { 'Content-Range': 'bytes 0-0/1' } }); ok(r.status === 403, 'token de subida falso: 403');

// --- subida en 3 partes, con una parte repetida y una fuera de orden
const archivo = fs.readFileSync(DIR + '/original.mp4');
const T = archivo.length, P = Math.ceil(T / 3);
const parte = async (i) => { const ini = i * P, fin = Math.min(T, ini + P) - 1; const x = await fetch(`${B}/emisor/subidas/${id}?token=${token}`, { method: 'PUT', body: archivo.subarray(ini, fin + 1), headers: { 'Content-Range': `bytes ${ini}-${fin}/${T}`, Origin: 'https://app.editus.online' } }); return [x.status, await x.json(), x.headers.get('access-control-allow-origin')]; };
let [c0, , cors] = await parte(0); ok(c0 === 200 && cors === '*', 'parte 1 y CORS');
let [c2, j2] = await parte(2); ok(c2 === 409 && j2.recibido === P, 'parte fuera de orden: 409 con lo recibido');
await parte(1); let [c1b] = await parte(1); ok(c1b === 200, 'parte repetida: se acepta sin duplicar');
let [c3, j3] = await parte(2); ok(c3 === 200 && j3.terminado, 'subida completa');
ok(execFileSync('cmp', [DIR + '/original.mp4', `${DIR}/datos/${id}/original`]).length === 0, 'archivo idéntico al original');
r = await fetch(`${B}/emisor/subidas/${id}?token=${token}`); ok((await r.json()).estado === 'subido', 'GET de la subida informa el estado');

// --- procesar
[s] = await firmado('POST', `/emisor/videos/${id}/procesar`, { alto: 1080, max_segundos: 600, marco_url: 'http://127.0.0.1:8901/marco.png', logo_url: 'http://127.0.0.1:8901/logo.png', etiqueta: 'PREGRABADO', cuenta: { segundos: 6, titulo: 'Así va el plan de seguridad en Neiva', subtitulo: 'Comenzamos en', color: '#00024f' } });
ok(s === 200, 'procesar aceptado');
const t0 = Date.now();
let v;
for (;;) { await new Promise((r) => setTimeout(r, 1000)); [, v] = await firmado('GET', `/emisor/videos/${id}`); if (!['en_cola', 'procesando'].includes(v.estado)) break; }
ok(v.estado === 'listo', `procesado (${Math.round((Date.now() - t0) / 1000)} s): ${v.estado} ${v.error || ''}`);
ok(v.salida?.alto === 720 && Math.abs(v.duracion - 20) < 0.6, `origen 720p → salida 1280x720 (no se agranda) y duración ${v.duracion}`);
const pr = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-print_format', 'json', '-show_streams', `${DIR}/datos/${id}/listo.mp4`]).toString());
const vv = pr.streams.find((x) => x.codec_type === 'video');
ok(vv.codec_name === 'h264' && vv.width === 1280 && vv.r_frame_rate === '30/1' && pr.streams.some((x) => x.codec_type === 'audio' && x.sample_rate === '44100'), 'listo.mp4: h264 720p30 + aac 44.1k');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-ss', '10', '-i', `${DIR}/datos/${id}/listo.mp4`, '-frames:v', '1', `${DIR}/cuadro-video.png`]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-ss', '2', '-i', `${DIR}/datos/${id}/cuenta.mp4`, '-frames:v', '1', `${DIR}/cuadro-cuenta.png`]);

// --- emitir a 2 servidores RTMP locales
const escuchar = (puerto, salida) => spawn('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-listen', '1', '-i', `rtmp://127.0.0.1:${puerto}/live/clave`, '-c', 'copy', salida]);
const l1 = escuchar(19351, DIR + '/recibido1.flv'), l2 = escuchar(19352, DIR + '/recibido2.flv');
await new Promise((r) => setTimeout(r, 800));
[s, v] = await firmado('POST', `/emisor/videos/${id}/emitir`, { destinos: ['rtmp://127.0.0.1:19351/live/clave', 'rtmp://127.0.0.1:19352/live/clave'], cuenta_regresiva: true });
ok(s === 200 && v.estado === 'emitiendo' && !JSON.stringify(v).includes('19351'), 'emitiendo (sin exponer las URLs)');
const t1 = Date.now();
for (;;) { await new Promise((r) => setTimeout(r, 1000)); [, v] = await firmado('GET', `/emisor/videos/${id}`); if (v.estado !== 'emitiendo') break; }
const seg = (Date.now() - t1) / 1000;
ok(v.estado === 'emitido', `emitido en ${seg.toFixed(1)} s (esperado ~26 s en tiempo real): ${v.estado} ${v.error || ''}`);
await new Promise((r) => setTimeout(r, 1500));
for (const f of ['recibido1.flv', 'recibido2.flv']) {
  const d = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', DIR + '/' + f]).toString());
  ok(Math.abs(d - 26) < 1.5, `${f}: ${d.toFixed(1)} s recibidos (cuenta 6 s + video 20 s)`);
}
l1.kill(); l2.kill();

// --- avisos a editus firmados
const eventos = avisos.map((a) => JSON.parse(a.cuerpo).evento);
ok(['subido', 'listo', 'terminado'].every((x) => eventos.includes(x)), 'avisos: ' + eventos.join(', '));
ok(avisos.every((a) => a.firma === firmar(Number(a.ts), a.nonce, 'POST', a.ruta, a.cuerpo)), 'avisos firmados correctamente');

// --- retomar: emitir desde el segundo 15 a un destino
const l3 = escuchar(19353, DIR + '/recibido3.flv'); await new Promise((r) => setTimeout(r, 800));
[s] = await firmado('POST', `/emisor/videos/${id}/emitir`, { destinos: ['rtmp://127.0.0.1:19353/live/clave'], cuenta_regresiva: true, desde: 15 });
for (;;) { await new Promise((r) => setTimeout(r, 1000)); [, v] = await firmado('GET', `/emisor/videos/${id}`); if (v.estado !== 'emitiendo') break; }
await new Promise((r) => setTimeout(r, 1200));
const d3 = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', DIR + '/recibido3.flv']).toString());
ok(v.estado === 'emitido' && Math.abs(d3 - 11) < 2, `retoma desde 15 s: recibió ${d3.toFixed(1)} s (esperado ~11)`);
l3.kill();

// --- un destino se cae a mitad: el otro termina igual
const l4 = escuchar(19354, DIR + '/recibido4.flv'), l5 = escuchar(19355, DIR + '/recibido5.flv'); await new Promise((r) => setTimeout(r, 800));
[s] = await firmado('POST', `/emisor/videos/${id}/emitir`, { destinos: ['rtmp://127.0.0.1:19354/live/clave', 'rtmp://127.0.0.1:19355/live/clave'], cuenta_regresiva: false });
await new Promise((r) => setTimeout(r, 5000)); l5.kill('SIGKILL');
for (;;) { await new Promise((r) => setTimeout(r, 1000)); [, v] = await firmado('GET', `/emisor/videos/${id}`); if (v.estado !== 'emitiendo') break; }
await new Promise((r) => setTimeout(r, 1200));
const d4 = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', DIR + '/recibido4.flv']).toString());
ok(v.estado === 'emitido' && v.emision.destinos[0].estado === 'terminado' && v.emision.destinos[1].estado === 'error' && Math.abs(d4 - 20) < 1.5, `un destino caído no tumba al otro: ${v.emision.destinos.map((d) => d.estado + '/' + d.intentos).join(', ')}; recibió ${d4.toFixed(1)} s`);
ok(/Algún destino/.test(v.error || ''), 'se informa el destino que falló');
l4.kill();
[s, v] = await firmado('DELETE', `/emisor/videos/${id}`); ok(s === 200 && !fs.existsSync(`${DIR}/datos/${id}`), 'borrado del video');
srv.close(); editus.close();
console.log(process.exitCode ? '\nHAY FALLAS' : '\nTodo bien. Cuadros de muestra en ' + DIR);
setTimeout(() => process.exit(), 500);
