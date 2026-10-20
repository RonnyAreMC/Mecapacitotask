// Arranque para cPanel → Setup Node.js App («Application startup file»: app.cjs).
// cPanel (Passenger) arranca un archivo CommonJS: este lee el .env de esta carpeta, si existe, y carga servidor.mjs.
// Las variables puestas en la pantalla de cPanel mandan sobre las del .env.
const path = require('node:path');
const fs = require('node:fs');

const env = path.join(__dirname, '.env');
if (fs.existsSync(env)) {
  try { process.loadEnvFile(env); } catch (e) { console.error('No se pudo leer .env: ' + e.message); }
}
import('./servidor.mjs').catch((e) => { console.error(e); process.exit(1); });
