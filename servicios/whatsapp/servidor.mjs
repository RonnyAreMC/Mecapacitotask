// Pasarela de WhatsApp del panel (InnoTech Hub), con Baileys (se conecta como WhatsApp Web). Viene del SIGC: la sesión
// única (/estado, /mensajes), que es la que usa el panel (admin/lib/Whatsapp.php), y la de varias sesiones (/sesiones/...).
// El contrato HTTP está en README.md. En cPanel arranca con app.cjs (Setup Node.js App).
// Cuidados: escucha solo en 127.0.0.1 y exige el token; las credenciales de cada sesión van en su carpeta (quien las
// tenga puede enviar como esa clínica); espacia los envíos para cuidar el número; no registra números, textos, archivos
// ni el token. Un mensaje puede llevar un PDF o una imagen ("adjunto", validado en adjunto.mjs) con el texto como pie.
// Uso: cp .env.example .env (poner un token) && npm install && npm start

import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { mkdir, readdir, rm, access, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import QRCode from 'qrcode';
import pino from 'pino';
import { makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } from '@whiskeysockets/baileys';
import { validarAdjunto, contenidoMensaje, AdjuntoInvalido } from './adjunto.mjs';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const TOKEN = process.env.BAILEYS_TOKEN ?? '';
const HOST = process.env.BAILEYS_HOST ?? '127.0.0.1';
const PUERTO = Number(process.env.BAILEYS_PUERTO ?? 3101);
const DATOS = path.resolve(DIR, process.env.BAILEYS_DATOS ?? 'sesiones');
// Si la app queda en una subcarpeta (cPanel: «Application URL» con ruta, p. ej. /whatsapp), se quita de cada petición.
const BASE = String(process.env.BAILEYS_BASE ?? process.env.PASSENGER_BASE_URI ?? '').replace(/\/+$/, '');
const NOMBRE = process.env.BAILEYS_NOMBRE ?? 'InnoTech Hub';   // como aparece en «Dispositivos vinculados» del teléfono
// Entre un mensaje y otro de la misma sesión. Si falta poco se espera; si falta más, 429 (el PHP reintenta luego).
const ESPERA_MS = Number(process.env.BAILEYS_ESPERA_MS ?? 3000);
const ESPERA_MAX_MS = 2000;           // nunca esperar más que esto dentro de una petición (el PHP corta a los 6 s; 45 s con archivo)
const ESPERA_QR_MS = 4000;            // al crear una sesión, cuánto esperar el primer QR antes de responder
const ESPERA_RECONEXION_MS = 2500;    // al enviar con la sesión reconectando: espera + 2 s de pausa + envío < los 6 s del PHP
const UNICA = 'unica';                // id interno de la sesión única (/estado, /mensajes)
const ID_VALIDO = /^[a-z0-9][a-z0-9-]{0,63}$/;
const NUMERO_VALIDO = /^\d{8,15}$/;
const TEXTO_MAX = 4096;
const CUERPO_MAX = 64 * 1024;
const CUERPO_MAX_MENSAJE = 15 * 1024 * 1024;   // solo los envíos: un archivo de hasta 10 MB en base64 (4/3) y el texto

if (!/^[\x21-\x7e]{16,500}$/.test(TOKEN)) {
  console.error('Falta BAILEYS_TOKEN en .env: de 16 a 500 caracteres imprimibles, sin espacios.');
  process.exit(1);
}

const logger = pino({ level: process.env.BAILEYS_LOG ?? 'silent' });
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const registro = (texto) => process.stdout.write(`${new Date().toISOString()} ${texto}\n`);
// La librería de cifrado de Baileys (libsignal) escribe con console.log el detalle de las sesiones (claves incluidas).
// Nada de eso debe quedar en el registro: se silencia todo lo que no escriba este archivo.
for (const metodo of ['log', 'info', 'warn', 'debug']) console[metodo] = () => {};

class ErrorHttp extends Error {
  constructor(estado, codigo) { super(codigo); this.estado = estado; this.codigo = codigo; }
}

/* ------------------------------------------------------------------ sesiones de WhatsApp */

/** id → { id, sock, conectado, numero, qr, parada, cerrando, enviando, ultimoEnvio } */
const sesiones = new Map();
const carpetaDe = (id) => path.join(DATOS, id);
const numeroDe = (jid) => (jid ? String(jid).split(':')[0].split('@')[0] : null);
const existe = (ruta) => access(ruta).then(() => true, () => false);
let versionWa = null;

async function versionWhatsapp() {
  if (versionWa) return versionWa;
  try { versionWa = (await fetchLatestBaileysVersion()).version; } catch { versionWa = undefined; }
  return versionWa;
}

/** La sesión (la crea si no existe). */
function sesion(id) {
  let s = sesiones.get(id);
  if (!s) {
    s = { id, sock: null, conectado: false, numero: null, qr: null, parada: false, cerrando: false, enviando: false, ultimoEnvio: 0 };
    sesiones.set(id, s);
    conectar(s).catch((e) => registro(`sesión ${id}: no se pudo abrir (${e.message})`));
  }
  return s;
}

async function conectar(s) {
  const carpeta = carpetaDe(s.id);
  await mkdir(carpeta, { recursive: true, mode: 0o700 });
  const { state, saveCreds } = await useMultiFileAuthState(carpeta);
  const version = await versionWhatsapp();
  s.parada = false;
  // Vinculada por QR: Baileys deja `registered` en false (solo lo pone el código de emparejamiento); lo que cuenta es `me`.
  s.vinculada = Boolean(state.creds?.me?.id || state.creds?.registered);
  const sock = makeWASocket({
    auth: state, logger, browser: Browsers.macOS(NOMBRE), markOnlineOnConnect: false, syncFullHistory: false,
    ...(version ? { version } : {}),
  });
  s.sock = sock;
  sock.ev.on('creds.update', saveCreds);
  sock.ev.on('connection.update', async (cambio) => {
    if (sesiones.get(s.id) !== s || s.sock !== sock) return;           // un socket viejo de esta sesión
    if (cambio.qr) {
      s.qr = await QRCode.toDataURL(cambio.qr, { margin: 1, width: 320 });
      s.conectado = false;
    }
    if (cambio.connection === 'open') {
      s.conectado = true;
      s.vinculada = true;
      s.qr = null;
      s.reintentos = 0;
      s.numero = numeroDe(sock.user?.id);
      registro(`sesión ${s.id}: vinculada`);
    }
    if (cambio.connection === 'close') {
      s.conectado = false;
      if (s.cerrando) return;
      const codigo = cambio.lastDisconnect?.error?.output?.statusCode;
      if (codigo === DisconnectReason.loggedOut) {
        // WhatsApp cerró la sesión (la desvincularon desde el teléfono): se borran las credenciales y queda para un QR nuevo.
        registro(`sesión ${s.id}: WhatsApp cerró la sesión; hay que escanear un QR nuevo`);
        s.numero = null;
        s.qr = null;
        s.vinculada = false;
        await rm(carpeta, { recursive: true, force: true });
        s.parada = true;
        return;
      }
      if (!s.vinculada && codigo !== DisconnectReason.restartRequired) {
        // Nadie escaneó el QR a tiempo: se detiene (sin generar QR para siempre) hasta que la clínica vuelva a pedir el estado.
        s.qr = null;
        s.parada = true;
        return;
      }
      // Se cayó la conexión (o WhatsApp pide reiniciar tras vincular): reconecta sola, cada vez con un poco más de espera
      // (3 s, 6 s, 12 s... hasta 1 min) para no insistir sin parar si no hay internet.
      s.reintentos = (s.reintentos ?? 0) + 1;
      const espera = codigo === DisconnectReason.restartRequired ? 200 : Math.min(3000 * 2 ** (s.reintentos - 1), 60000);
      if (codigo !== DisconnectReason.restartRequired) {
        registro(`sesión ${s.id}: se cortó la conexión (código ${codigo ?? '?'}); reconecta en ${Math.round(espera / 1000)} s`);
      }
      await dormir(espera);
      if (sesiones.get(s.id) === s && !s.cerrando) conectar(s).catch((e) => registro(`sesión ${s.id}: no reconectó (${e.message})`));
    }
  });
}

/** Estado para la clínica: si no está vinculada, espera un poco a tener el QR. */
async function estadoDe(id) {
  const s = sesion(id);
  if (s.parada && !s.cerrando) { s.parada = false; conectar(s).catch(() => {}); }
  for (let t = 0; !s.conectado && !s.qr && t < ESPERA_QR_MS; t += 100) await dormir(100);
  return { conectado: s.conectado, numero: s.conectado ? s.numero : null, qr: s.conectado ? null : s.qr };
}

async function enviar(id, numero, texto, adjunto = null) {
  const s = sesiones.get(id);
  if (s && !s.conectado && s.vinculada && !s.cerrando) {
    // Vinculada pero reconectando (o detenida): se despierta y se espera un momento en vez de devolver el mensaje a la cola,
    // que lo reintentaría recién en 30 s.
    if (s.parada) { s.parada = false; conectar(s).catch(() => {}); }
    for (let t = 0; !s.conectado && t < ESPERA_RECONEXION_MS; t += 100) await dormir(100);
  }
  if (!s || !s.conectado || !s.sock) throw new ErrorHttp(409, 'desconectado');
  if (s.enviando) throw new ErrorHttp(429, 'limite');
  const falta = s.ultimoEnvio + ESPERA_MS - Date.now();
  if (falta > ESPERA_MAX_MS) throw new ErrorHttp(429, 'limite');
  s.enviando = true;
  try {
    if (falta > 0) await dormir(falta);
    const [encontrado] = await s.sock.onWhatsApp(`${numero}@s.whatsapp.net`).catch(() => []);
    if (!encontrado?.exists) throw new ErrorHttp(422, 'sin_whatsapp');
    const mensaje = await s.sock.sendMessage(encontrado.jid, contenidoMensaje(texto, adjunto));
    s.ultimoEnvio = Date.now();
    if (!mensaje?.key?.id) throw new ErrorHttp(502, 'sin_id');
    return mensaje.key.id;
  } finally {
    s.enviando = false;
  }
}

async function borrar(id) {
  const s = sesiones.get(id);
  if (!s && !(await existe(carpetaDe(id)))) throw new ErrorHttp(404, 'no_existe');
  if (s) {
    s.cerrando = true;
    try { await s.sock?.logout(); } catch { /* ya estaba cerrada */ }
    try { s.sock?.end(undefined); } catch { /* idem */ }
    sesiones.delete(id);
  }
  await rm(carpetaDe(id), { recursive: true, force: true });
  registro(`sesión ${id}: desvinculada y credenciales borradas`);
}

/* ------------------------------------------------------------------ un solo proceso conectado */

// cPanel puede abrir un segundo proceso de la app (o el nuevo arranca antes de que el viejo termine). Dos conexiones con
// las mismas credenciales se pisan en WhatsApp, así que solo una maneja las sesiones: la que tiene el candado
// (sesiones/.proceso con su PID). La otra responde 503 y lo vuelve a intentar en cada petición; el PHP reintenta luego.
const CANDADO = path.join(DATOS, '.proceso');
let dueno = false;

async function tomarCandado() {
  if (dueno) return true;
  await mkdir(DATOS, { recursive: true, mode: 0o700 });
  try {
    const otro = Number((await readFile(CANDADO, 'utf8')).trim());
    if (otro && otro !== process.pid) {
      try { process.kill(otro, 0); return false; } catch { /* ese proceso ya no existe: se toma */ }
    }
  } catch { /* no había candado */ }
  await writeFile(CANDADO, String(process.pid), { mode: 0o600 });
  dueno = true;
  await levantarGuardadas();
  return true;
}

/** Al arrancar: vuelve a levantar las sesiones que tienen credenciales. */
async function levantarGuardadas() {
  await mkdir(DATOS, { recursive: true, mode: 0o700 });
  for (const entrada of await readdir(DATOS, { withFileTypes: true })) {
    if (entrada.isDirectory() && ID_VALIDO.test(entrada.name) && await existe(path.join(DATOS, entrada.name, 'creds.json'))) {
      sesion(entrada.name);
    }
  }
}

/* ------------------------------------------------------------------ HTTP */

function tokenValido(cabecera) {
  const recibido = Buffer.from(String(cabecera ?? '').replace(/^Bearer\s+/i, ''));
  const esperado = Buffer.from(TOKEN);
  return recibido.length === esperado.length && timingSafeEqual(recibido, esperado);
}

function leerJson(peticion, maximo = CUERPO_MAX) {
  return new Promise((resolver, rechazar) => {
    let tamano = 0;
    const partes = [];
    if (Number(peticion.headers['content-length'] ?? 0) > maximo) { rechazar(new ErrorHttp(422, 'cuerpo_grande')); peticion.resume(); return; }
    peticion.on('data', (parte) => {
      tamano += parte.length;
      if (tamano > maximo) { rechazar(new ErrorHttp(422, 'cuerpo_grande')); peticion.destroy(); return; }
      partes.push(parte);
    });
    peticion.on('end', () => {
      try { resolver(JSON.parse(Buffer.concat(partes).toString('utf8') || '{}')); } catch { rechazar(new ErrorHttp(400, 'json_invalido')); }
    });
    peticion.on('error', rechazar);
  });
}

/** {numero, texto, adjunto?}: con adjunto el texto es su pie y puede ir vacío. */
function datosMensaje(cuerpo) {
  const numero = typeof cuerpo?.numero === 'string' ? cuerpo.numero : '';
  const texto = typeof cuerpo?.texto === 'string' ? cuerpo.texto : '';
  let adjunto = null;
  if (cuerpo?.adjunto !== undefined && cuerpo?.adjunto !== null) {
    try {
      adjunto = validarAdjunto(cuerpo.adjunto);
    } catch (error) {
      if (error instanceof AdjuntoInvalido) throw new ErrorHttp(422, error.codigo);
      throw error;
    }
  }
  if (!NUMERO_VALIDO.test(numero) || texto.length > TEXTO_MAX || (!adjunto && !texto.trim())) throw new ErrorHttp(422, 'datos_invalidos');
  return { numero, texto, adjunto };
}

async function atender(peticion) {
  if (!tokenValido(peticion.headers.authorization)) throw new ErrorHttp(401, 'no_autorizado');
  if (!(await tomarCandado())) throw new ErrorHttp(503, 'otro_proceso');
  let { pathname } = new URL(peticion.url, 'http://local');
  if (BASE && (pathname === BASE || pathname.startsWith(BASE + '/'))) pathname = pathname.slice(BASE.length) || '/';
  const metodo = peticion.method;
  const partes = pathname.split('/').filter(Boolean);

  if (metodo === 'GET' && pathname === '/salud') return [200, { ok: true, sesiones: sesiones.size }];
  if (metodo === 'GET' && pathname === '/sesiones') {
    return [200, { sesiones: [...sesiones.values()].filter((s) => s.id !== UNICA)
      .map((s) => ({ id: s.id, conectado: s.conectado, numero: s.conectado ? s.numero : null })) }];
  }
  if (metodo === 'GET' && pathname === '/estado') return [200, await estadoDe(UNICA)];
  if (metodo === 'POST' && pathname === '/mensajes') {
    const { numero, texto, adjunto } = datosMensaje(await leerJson(peticion, CUERPO_MAX_MENSAJE));
    return [201, { id: await enviar(UNICA, numero, texto, adjunto) }];
  }
  if (partes[0] === 'sesiones' && partes[1]) {
    const id = partes[1];
    if (!ID_VALIDO.test(id) || id === UNICA) throw new ErrorHttp(400, 'id_invalido');
    if (metodo === 'GET' && partes[2] === 'estado' && partes.length === 3) return [200, await estadoDe(id)];
    if (metodo === 'POST' && partes[2] === 'mensajes' && partes.length === 3) {
      const { numero, texto, adjunto } = datosMensaje(await leerJson(peticion, CUERPO_MAX_MENSAJE));
      return [201, { id: await enviar(id, numero, texto, adjunto) }];
    }
    if (metodo === 'DELETE' && partes.length === 2) { await borrar(id); return [200, { ok: true }]; }
  }
  throw new ErrorHttp(404, 'no_encontrado');
}

const servidor = http.createServer(async (peticion, respuesta) => {
  let estado; let cuerpo;
  try {
    [estado, cuerpo] = await atender(peticion);
  } catch (error) {
    estado = error instanceof ErrorHttp ? error.estado : 500;
    cuerpo = { error: error instanceof ErrorHttp ? error.codigo : 'interno' };
    if (!(error instanceof ErrorHttp)) registro(`error interno: ${error.message}`);
  }
  respuesta.writeHead(estado, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  respuesta.end(JSON.stringify(cuerpo));
});

await tomarCandado().catch((e) => registro(`no se pudo tomar el candado (${e.message})`));
if (!dueno) registro('otro proceso ya maneja WhatsApp: este responde 503 hasta que ese termine');
// En cPanel (Passenger) el puerto lo pone Passenger: toma este listen() y lo cambia por el suyo.
servidor.listen(PUERTO, HOST, () => registro(`pasarela de WhatsApp en http://${HOST}:${PUERTO} (${sesiones.size} sesiones guardadas)`));

// Al apagar: cierra las conexiones SIN desvincular (las credenciales quedan para la próxima vez).
for (const senal of ['SIGINT', 'SIGTERM']) {
  process.on(senal, () => {
    for (const s of sesiones.values()) { s.cerrando = true; try { s.sock?.end(undefined); } catch { /* nada */ } }
    if (dueno) rm(CANDADO, { force: true }).catch(() => {});   // el próximo proceso lo toma sin esperar
    servidor.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 1500).unref();
  });
}
