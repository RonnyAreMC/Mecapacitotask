// Validación del archivo adjunto de un mensaje (POST /mensajes y /sesiones/{id}/mensajes). Sin dependencias ni Baileys:
// se puede probar sola (node -e "import('./adjunto.mjs')..."). Las mismas reglas cumple el PHP (app/Modules/Whatsapp/Adjuntos.php)
// y el servicio falso de las pruebas (tests/servidores/baileys_falso.php).
//
// "adjunto": {"tipo": "documento"|"imagen", "nombre": "Receta-000123.pdf", "mime": "application/pdf", "datos": "<base64>"}
// - mime permitido: application/pdf, image/jpeg, image/png o image/webp; "documento" solo para PDF e "imagen" solo para imágenes;
// - nombre limpio: letras, números, espacio y . _ - ( ) , ; empieza con letra o número, sin "..", hasta 120 caracteres;
// - datos en base64, de 1 byte a 10 MB ya decodificados, y que de verdad sean del tipo que dice (sus primeros bytes).

export const ADJUNTO_MAX_BYTES = 10 * 1024 * 1024;
export const MIMES = { 'application/pdf': 'documento', 'image/jpeg': 'imagen', 'image/png': 'imagen', 'image/webp': 'imagen' };
const NOMBRE_VALIDO = /^[\p{L}\p{N}][\p{L}\p{M}\p{N} ._(),-]*$/u;
const BASE64 = /^[A-Za-z0-9+/]*={0,2}$/;

export class AdjuntoInvalido extends Error {
  constructor(codigo) { super(codigo); this.codigo = codigo; }
}

/** ¿Los primeros bytes son de ese tipo de archivo? */
function contenidoEs(mime, b) {
  switch (mime) {
    case 'application/pdf': return b.subarray(0, 5).toString('latin1') === '%PDF-';
    case 'image/jpeg': return b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff;
    case 'image/png': return b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]));
    case 'image/webp': return b.length > 12 && b.subarray(0, 4).toString('latin1') === 'RIFF' && b.subarray(8, 12).toString('latin1') === 'WEBP';
    default: return false;
  }
}

/**
 * Valida el adjunto y lo devuelve listo para Baileys: { tipo, nombre, mime, datos: Buffer }.
 * Lanza AdjuntoInvalido (el servidor responde 422) si algo no cumple.
 */
export function validarAdjunto(adjunto) {
  if (adjunto === null || typeof adjunto !== 'object' || Array.isArray(adjunto)) throw new AdjuntoInvalido('adjunto_invalido');
  const { tipo, nombre, mime, datos } = adjunto;
  if (typeof mime !== 'string' || !Object.hasOwn(MIMES, mime)) throw new AdjuntoInvalido('adjunto_tipo');
  if (tipo !== MIMES[mime]) throw new AdjuntoInvalido('adjunto_tipo');   // "documento" solo para PDF
  if (typeof nombre !== 'string' || nombre.length > 120 || !NOMBRE_VALIDO.test(nombre) || nombre.includes('..')) {
    throw new AdjuntoInvalido('adjunto_nombre');
  }
  if (typeof datos !== 'string' || datos.length === 0 || datos.length % 4 !== 0 || !BASE64.test(datos)) {
    throw new AdjuntoInvalido('adjunto_datos');
  }
  // Antes de decodificar: base64 ocupa 4/3 del archivo.
  if (datos.length > Math.ceil(ADJUNTO_MAX_BYTES / 3) * 4) throw new AdjuntoInvalido('adjunto_grande');
  const buffer = Buffer.from(datos, 'base64');
  if (buffer.length === 0) throw new AdjuntoInvalido('adjunto_datos');
  if (buffer.length > ADJUNTO_MAX_BYTES) throw new AdjuntoInvalido('adjunto_grande');
  if (!contenidoEs(mime, buffer)) throw new AdjuntoInvalido('adjunto_contenido');
  return { tipo, nombre, mime, datos: buffer };
}

/** El mensaje para Baileys (sock.sendMessage): texto solo, o el archivo con el texto como pie. */
export function contenidoMensaje(texto, adjunto) {
  if (!adjunto) return { text: texto };
  const caption = texto || undefined;
  if (adjunto.tipo === 'documento') return { document: adjunto.datos, mimetype: adjunto.mime, fileName: adjunto.nombre, caption };
  return { image: adjunto.datos, mimetype: adjunto.mime, caption };
}
