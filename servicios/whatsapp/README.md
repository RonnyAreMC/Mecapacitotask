# Pasarela de WhatsApp (Baileys)

Servicio en Node que mantiene abierta la conexión de WhatsApp del panel y manda los mensajes que le pide el PHP
(`admin/lib/Whatsapp.php`). Viene del SIGC (`servicios/whatsapp-baileys`), con tres cambios: arranca con `app.cjs`
(cPanel), acepta vivir en una subcarpeta (`BAILEYS_BASE`) y, si cPanel abre dos procesos, solo uno se conecta.

El panel lo usa para dos cosas, y solo cuando en **Ajustes → Reuniones → WhatsApp** está encendido:

1. **Reuniones:** invitación, cambios, cancelación y un recordatorio unos minutos antes. A quien no tenga un
   teléfono válido le sigue llegando por correo. Apagado, todo va por correo como siempre.
2. **Recuperar la contraseña:** un código de 6 dígitos que vence en 10 minutos.

Baileys **no es oficial**: se conecta como WhatsApp Web. WhatsApp puede bloquear el número si ve envíos masivos o
recibe reportes de spam. Usa un número propio de InnoTech (no el de una persona) y escribe solo al equipo.

## Instalar en cPanel

1. **Subdominio** (recomendado): cPanel → Dominios → crea `wa.innotech-solutions.com.ec` y emite su SSL (AutoSSL).
2. **Token:** en la terminal, `openssl rand -hex 32`. Guárdalo: va aquí y en el panel.
3. **La app:** cPanel → **Setup Node.js App** → *Create Application*:
   - Node.js version: **22**
   - Application mode: Production
   - Application root: `innotech-hub/servicios/whatsapp` (esta carpeta, dentro del panel: `git pull` la actualiza)
   - Application URL: `wa.innotech-solutions.com.ec`
   - Application startup file: `app.cjs`
   - Environment variables: `BAILEYS_TOKEN` = el token del paso 2
4. Pulsa **Create**, luego **Run NPM Install** y **Restart**.
5. **En el panel:** Ajustes → Reuniones → WhatsApp: la dirección `https://wa.innotech-solutions.com.ec`, el mismo
   token, enciende el interruptor y guarda. Con «Ver estado y vincular» sale el QR: escanéalo desde el teléfono del
   número (WhatsApp → Dispositivos vinculados → Vincular un dispositivo).
6. **Cron** (cPanel → Cron Jobs, cada minuto). Manda la cola, los recordatorios y mantiene despierta la app:

       * * * * *  /usr/local/bin/php ~/innotech-hub/admin/cron_whatsapp.php

Sin subdominio también funciona en una subcarpeta (Application URL `panel.innotech-solutions.com.ec/whatsapp`): en ese
caso agrega la variable `BAILEYS_BASE=/whatsapp` y en el panel pon `https://panel.innotech-solutions.com.ec/whatsapp`.

Después de un `git pull` que cambie esta carpeta: Setup Node.js App → **Restart** (y **Run NPM Install** si cambió
`package.json`). La sesión de WhatsApp queda guardada en `sesiones/`: reiniciar no pide un QR nuevo.

## Cuánto consume

- Unos **110 MB de RAM** con el servicio cargado y algo más con el número conectado. CPU casi en cero: solo trabaja
  al conectar y al mandar. Disco: pocos MB (la sesión).
- Del lado PHP, cada mensaje es una petición HTTP corta. Los avisos de reuniones salen por una cola que manda el
  cron (3 s entre mensajes), así que crear una reunión no se demora.

## Contrato HTTP (lo que usa el panel)

Toda petición lleva `Authorization: Bearer <token>`; sin él o distinto, `401`. Respuestas en JSON.

| Ruta | Respuesta |
|---|---|
| `GET /salud` | `{"ok": true, "sesiones": 1}` |
| `GET /estado` | `{"conectado": true, "numero": "593991234567", "qr": null}` o, sin vincular, `{"conectado": false, "numero": null, "qr": "data:image/png;base64,…"}` |
| `POST /mensajes` `{"numero": "593991234567", "texto": "…"}` | `201 {"id": "3EB0…"}` |

Números en formato internacional, solo dígitos y sin `+` (el panel convierte `0991234567` en `593991234567`).

Errores: `409` el número no está vinculado (hay que escanear el QR), `422` el número no tiene WhatsApp o los datos no
valen, `429` frena los envíos para cuidar el número, `503` otro proceso maneja la conexión. El panel reintenta los
`409`, `429`, `503` y `5xx` con espera creciente (hasta 5 veces); los `422` no.

La pasarela también trae las rutas de varias sesiones del SIGC (`/sesiones/{id}/estado`, `/sesiones/{id}/mensajes`,
`DELETE /sesiones/{id}`); el panel no las usa.

## Cuidados

- La carpeta `sesiones/` y el `.env` nunca van a git (`.gitignore`) ni a la web (`servicios/.htaccess` lo bloquea todo).
  Quien tenga `sesiones/` puede enviar mensajes como ese número.
- El servicio no registra números, textos ni el token. El panel borra el texto de cada mensaje de la cola en cuanto
  se envía.

## Probar en tu computadora

```bash
cp .env.example .env      # pon un BAILEYS_TOKEN
npm install
npm start                 # escucha en http://127.0.0.1:3101
curl -H "Authorization: Bearer TU_TOKEN" http://127.0.0.1:3101/salud
```
