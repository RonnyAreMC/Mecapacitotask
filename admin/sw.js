/* =========================================================
   Service worker del panel: recibe las notificaciones nativas (Web Push)
   y SIEMPRE las muestra como notificación del sistema (Centro de
   notificaciones de Mac, Windows o Android), con el panel cerrado o
   abierto. Al tocarlas abre la página del aviso (el correo, la tarea, el
   pedido…). No guarda nada en caché: el panel siempre se carga fresco.

   assets/ds.js lo registra como «sw.js?k=<clave pública del panel>»: así,
   si el navegador renueva la suscripción por su cuenta, aquí se sabe con
   qué clave pedir la nueva sin preguntarle nada a la persona.
   ========================================================= */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

/** La clave pública del panel (va en la dirección de este archivo). */
const clavePanel = () => {
  const k = new URL(self.location.href).searchParams.get('k') || '';
  if (!k) return null;
  try {
    const s = atob((k + '='.repeat((4 - k.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(s, (c) => c.charCodeAt(0));
  } catch (err) { return null; }
};

/** El número del ícono de la app instalada (PWA): avisos + correos sin leer. */
const insignia = (n) => {
  const nav = self.navigator;
  if (typeof n !== 'number' || !nav || !('setAppBadge' in nav)) return Promise.resolve();
  try {
    return Promise.resolve(n > 0 ? nav.setAppBadge(n) : nav.clearAppBadge()).catch(() => {});
  } catch (err) { return Promise.resolve(); }   // nunca debe impedir que salga la notificación
};

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { texto: e.data ? e.data.text() : '' }; }
  const titulo = d.titulo || 'InnoTech Hub';
  const opciones = {
    body: d.texto || '',
    icon: 'iconos/pwa/icono-192.png',
    badge: 'iconos/pwa/insignia-96.png',
    data: { url: d.url || 'index.php' },
    lang: 'es',
    timestamp: Date.now(),
  };
  // Mismo tag = reemplaza al anterior en vez de apilarse; renotify hace que igual suene
  if (d.tag) { opciones.tag = d.tag; opciones.renotify = true; }
  e.waitUntil(Promise.all([
    // La del sistema sale siempre, aunque haya una pestaña del panel abierta y con foco
    self.registration.showNotification(titulo, opciones),
    insignia(d.n),
    // Las pestañas abiertas actualizan la campana al momento (sin el aviso dentro de la página)
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((vs) => vs.forEach((v) => v.postMessage({ tipo: 'meca-push', aviso: d })))
      .catch(() => {}),
  ]));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  // Solo páginas del propio panel (nunca una dirección de afuera)
  let url = new URL('index.php', self.registration.scope).href;
  try {
    const u = new URL((e.notification.data && e.notification.data.url) || 'index.php', self.registration.scope);
    if (u.origin === self.location.origin && u.href.startsWith(self.registration.scope)) url = u.href;
  } catch (err) { /* se queda en el inicio */ }
  // La app del correo (correo.php) y el panel pueden estar instalados a la vez: un aviso de
  // correo va a una ventana del correo y uno del panel nunca se mete en la ventana del correo
  const esCorreo = (u) => /\/correo\.php$/.test(new URL(u).pathname);
  const pagina = (u) => new URL(u).pathname;
  e.waitUntil((async () => {
    const ventanas = (await self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
      .filter((v) => v.url.startsWith(self.registration.scope))
      .sort((a, b) => (b.focused - a.focused) || ((b.visibilityState === 'visible') - (a.visibilityState === 'visible')));
    // 1) una ventana que ya está en esa misma página; 2) si no, otra del mismo lado (panel o correo)
    const misma = ventanas.filter((v) => pagina(v.url) === pagina(url));
    const candidatas = misma.length ? misma : ventanas.filter((v) => esCorreo(v.url) === esCorreo(url));
    for (const v of candidatas) {
      try {
        const f = (await v.focus()) || v;
        if (f.url === url) return;
        // navigate() solo sirve en pestañas que este service worker controla; si no, la pestaña va sola
        try { if ('navigate' in f && await f.navigate(url)) return; } catch (err) { /* sigue abajo */ }
        f.postMessage({ tipo: 'meca-ir', url });
        return;
      } catch (err) { /* esa ventana no se dejó enfocar: prueba la siguiente */ }
    }
    await self.clients.openWindow(url);
  })());
});

// El navegador renovó la suscripción por su cuenta: se anota la nueva en el
// panel sin pedir permiso otra vez. Si la sesión ya venció, assets/ds.js la
// anota en cuanto la persona vuelva a entrar.
self.addEventListener('pushsubscriptionchange', (e) => {
  e.waitUntil((async () => {
    const vieja = e.oldSubscription || null;
    let nueva = e.newSubscription || (await self.registration.pushManager.getSubscription());
    if (!nueva) {
      const clave = (vieja && vieja.options && vieja.options.applicationServerKey) || clavePanel();
      if (!clave) return;
      nueva = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: clave });
    }
    if (vieja && vieja.endpoint === nueva.endpoint) return;
    const fd = new FormData();
    fd.append('accion', 'push_suscribir');
    fd.append('sub', JSON.stringify(nueva));
    if (vieja) fd.append('anterior', vieja.endpoint);
    await fetch(new URL('actions.php', self.registration.scope), {
      method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' },
    });
  })().catch(() => {}));
});
