/* =========================================================
   Service worker del panel: recibe las notificaciones nativas (Web Push)
   aunque el panel esté cerrado y, al tocarlas, abre la página del aviso
   (el correo, la tarea, el pedido…). No guarda nada en caché: el panel
   siempre se carga fresco del servidor.
   ========================================================= */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

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
  };
  if (d.tag) { opciones.tag = d.tag; opciones.renotify = true; }
  e.waitUntil(self.registration.showNotification(titulo, opciones));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  // Solo páginas del propio panel (nunca una dirección de afuera)
  let url = new URL('index.php', self.registration.scope).href;
  try {
    const u = new URL((e.notification.data && e.notification.data.url) || 'index.php', self.registration.scope);
    if (u.origin === self.location.origin && u.href.startsWith(self.registration.scope)) url = u.href;
  } catch (err) { /* se queda en el inicio */ }
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ventanas) => {
    for (const v of ventanas) {
      if (v.url.startsWith(self.registration.scope) && 'focus' in v) {
        return v.focus().then((f) => (f && 'navigate' in f ? f.navigate(url) : null));
      }
    }
    return self.clients.openWindow(url);
  }));
});

// Si el navegador renueva la suscripción por su cuenta, se la avisa al panel
self.addEventListener('pushsubscriptionchange', (e) => {
  e.waitUntil((async () => {
    const vieja = e.oldSubscription;
    const opciones = vieja ? vieja.options : null;
    if (!opciones) return;
    const nueva = await self.registration.pushManager.subscribe(opciones);
    const fd = new FormData();
    fd.append('accion', 'push_suscribir');
    fd.append('sub', JSON.stringify(nueva));
    if (vieja) fd.append('anterior', vieja.endpoint);
    await fetch(new URL('actions.php', self.registration.scope), { method: 'POST', body: fd, credentials: 'same-origin' });
  })());
});
