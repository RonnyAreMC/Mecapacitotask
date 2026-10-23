/* =========================================================
   Cuentas de correo del cPanel (buzones.php): abrir las ventanas con los
   datos de la cuenta, generar contraseñas, mandar cada acción por fetch y
   mostrar la contraseña nueva UNA sola vez para copiarla.
   Se carga con defer: ya están MC (admin.js) y DS (ds.js).
   ========================================================= */
(() => {
  const raiz = document.querySelector('[data-bz]');
  if (!raiz) return;
  const toast = (m, t = 'success') => (window.MC && MC.toast ? MC.toast(m, t, t === 'error' ? 7000 : 4500) : console.warn(m));
  const confirmar = (o) => (window.MC && MC.confirm ? MC.confirm(o) : Promise.resolve(window.confirm(o.mensaje || o.titulo)));
  const dlg = (id) => document.getElementById(id);
  let recargar = false;

  /** POST a buzones.php. Devuelve el JSON si ok; si no, lanza el error. */
  async function post(datos) {
    let r;
    try {
      r = await fetch('buzones.php', { method: 'POST', body: datos, credentials: 'same-origin' });
    } catch (e) {
      throw new Error('No hay conexión con el panel.');
    }
    let j = null;
    try { j = await r.json(); } catch (e) { /* no era JSON */ }
    if (!j) throw new Error('El panel respondió algo inesperado (' + r.status + ').');
    if (!j.ok) throw new Error(j.error || 'No se pudo.');
    return j;
  }

  /** Contraseña segura en el navegador (sin letras que se confunden). */
  function generar(largo = 16) {
    const grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+-=?@_'];
    const todo = grupos.join('');
    const n = new Uint32Array(largo * 2);
    crypto.getRandomValues(n);
    const c = grupos.map((g, i) => g[n[i] % g.length]);
    for (let i = c.length; i < largo; i++) c.push(todo[n[i] % todo.length]);
    for (let i = c.length - 1; i > 0; i--) { const j = n[largo + i] % (i + 1); [c[i], c[j]] = [c[j], c[i]]; }
    return c.join('');
  }

  const abrir = (id) => { const d = dlg(id); if (d && d.showModal) { if (window.DS && DS.mejorar) DS.mejorar(d); d.showModal(); } };
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-bz-abrir]');
    if (!b) return;
    e.preventDefault();
    abrir(b.dataset.bzAbrir);
  });

  // Generar contraseña: se ve en el campo para poder copiarla antes de guardar
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-bz-generar]');
    if (!b) return;
    const inp = b.closest('.mc-field').querySelector('input[name="clave"]');
    inp.value = generar();
    inp.focus();
    inp.select();
  });

  // «Otro tamaño…» muestra el campo en MB
  document.addEventListener('change', (e) => {
    const s = e.target.closest('select[data-bz-cuota]');
    if (!s) return;
    const otro = s.closest('.mc-field').querySelector('.bz-otro');
    if (otro) otro.hidden = s.value !== 'otro';
  });

  /* ---------- Acciones de una cuenta ---------- */
  let cuenta = null;
  const ponerCuenta = (d) => {
    d.querySelectorAll('[data-bz-email]').forEach((x) => { x.textContent = cuenta.email + (cuenta.nombre ? ' · ' + cuenta.nombre : ''); });
    d.querySelectorAll('input[name="email"]').forEach((x) => { x.value = cuenta.email; });
  };
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-bz-accion]');
    if (!b) return;
    cuenta = JSON.parse(b.closest('[data-bz-cuenta]').dataset.bzCuenta);
    const que = b.dataset.bzAccion;
    const d = dlg('bz-dlg-' + que);
    if (!d) return;
    ponerCuenta(d);
    if (que === 'clave') {
      d.querySelector('input[name="clave"]').value = '';
      const sinc = d.querySelector('[data-bz-sinc]');
      if (sinc) sinc.hidden = !cuenta.sinc;
    }
    if (que === 'cuota') {
      const s = d.querySelector('select[data-bz-cuota]');
      const ops = [...s.options].map((o) => o.value);
      const actual = String(cuenta.cuotaMb || 0);
      s.value = ops.includes(actual) ? actual : 'otro';
      s.dispatchEvent(new Event('change', { bubbles: true }));
      if (s.value === 'otro') d.querySelector('input[name="cuota_mb"]').value = cuenta.cuotaMb;
    }
    if (que === 'mas') {
      d.querySelector('[data-bz-t-login]').textContent = cuenta.login ? 'Dejarla entrar otra vez' : 'Que no pueda entrar';
      d.querySelector('[data-bz-t-incoming]').textContent = cuenta.entrada ? 'Que vuelva a recibir correos' : 'Que no reciba correos';
      d.querySelector('[data-bz-d-login]').textContent = cuenta.login ? 'Hoy no puede entrar. Su contraseña vuelve a funcionar.' : 'Su contraseña deja de funcionar en todas partes. Sigue recibiendo correos.';
      d.querySelector('[data-bz-d-incoming]').textContent = cuenta.entrada ? 'Hoy los correos que le mandan rebotan. Vuelve a recibirlos.' : 'Los correos que le manden rebotan. Puede seguir entrando.';
      d.querySelector('input[name="confirmar"]').value = '';
    }
    abrir('bz-dlg-' + que);
  });

  // Suspender o reactivar (lo contrario de como está)
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bz-suspender]');
    if (!b || !cuenta) return;
    const que = b.dataset.bzSuspender;
    const ahora = que === 'login' ? cuenta.login : cuenta.entrada;
    if (!ahora) {
      const si = await confirmar({ titulo: '¿Seguro?', ok: 'Sí, suspender', peligro: true,
        mensaje: que === 'login' ? cuenta.email + ' no podrá entrar a su correo hasta que lo reactives.' : 'Los correos que le manden a ' + cuenta.email + ' van a rebotar.' });
      if (!si) return;
    }
    const fd = new FormData();
    fd.set('accion', 'suspender');
    fd.set('email', cuenta.email);
    fd.set('que', que);
    fd.set('valor', ahora ? '' : '1');
    try {
      const j = await post(fd);
      toast(j.mensaje);
      setTimeout(() => location.reload(), 700);
    } catch (err) { toast(err.message, 'error'); }
  });

  // Quitar un reenvío
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bz-reenvio-borrar]');
    if (!b) return;
    const f = JSON.parse(b.dataset.bzReenvioBorrar);
    const si = await confirmar({ titulo: '¿Quitar el reenvío?', ok: 'Sí, quitar', mensaje: 'Lo que llegue a ' + f.de + ' ya no se mandará a ' + f.a + '.' });
    if (!si) return;
    const fd = new FormData();
    fd.set('accion', 'reenvio_borrar');
    fd.set('de', f.de);
    fd.set('a', f.a);
    try {
      const j = await post(fd);
      toast(j.mensaje);
      setTimeout(() => location.reload(), 600);
    } catch (err) { toast(err.message, 'error'); }
  });

  /* ---------- Formularios (crear, contraseña, espacio, borrar, reenvío) ---------- */
  document.addEventListener('submit', async (e) => {
    const f = e.target.closest('form[data-bz-form]');
    if (!f) return;
    e.preventDefault();
    const accion = f.dataset.bzForm;
    const fd = new FormData(f);
    fd.set('accion', accion);
    const btn = e.submitter || f.querySelector('button:not([type="button"])');
    if (btn) btn.disabled = true;
    try {
      const j = await post(fd);
      const d = f.closest('dialog');
      if (d) d.close();
      if (j.clave) {
        mostrarClave(j, accion === 'crear' ? 'Cuenta creada' : 'Contraseña cambiada');
        recargar = true;
      } else {
        toast(j.mensaje);
        setTimeout(() => location.reload(), 700);
      }
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  });

  /* ---------- La contraseña, una sola vez ---------- */
  let texto = '';
  function mostrarClave(j, titulo) {
    const d = dlg('bz-dlg-listo');
    d.querySelector('[data-bz-li-t]').textContent = titulo;
    const filas = [['Correo', j.email], ['Contraseña', j.clave], ['Webmail', j.acceso && j.acceso.webmail],
      ['Servidor de entrada (IMAP)', j.acceso && j.acceso.imap], ['Servidor de salida (SMTP)', j.acceso && j.acceso.smtp]].filter((x) => x[1]);
    const dl = d.querySelector('[data-bz-datos]');
    dl.textContent = '';
    filas.forEach(([k, v]) => {
      const fila = document.createElement('div');
      const dt = document.createElement('dt');
      dt.textContent = k;
      const dd = document.createElement('dd');
      dd.textContent = v;
      if (k === 'Contraseña') dd.className = 'bz-clave-ver';
      fila.append(dt, dd);
      dl.append(fila);
    });
    texto = filas.map(([k, v]) => k + ': ' + v).join('\n');
    if (j.mensaje) toast(j.mensaje);
    d.showModal();
  }
  const listo = dlg('bz-dlg-listo');
  if (listo) {
    listo.querySelector('[data-bz-copiar]').addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(texto); toast('Copiado.'); } catch (e) { toast('No se pudo copiar: selecciona los datos y usa Ctrl+C.', 'error'); }
    });
    // Al cerrar, la contraseña se borra de la página y se recarga la lista
    listo.addEventListener('close', () => {
      listo.querySelector('[data-bz-datos]').textContent = '';
      texto = '';
      if (recargar) location.reload();
    });
  }

  /* ---------- Buscar ---------- */
  const q = raiz.querySelector('[data-bz-buscar]');
  if (q) {
    const plano = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    q.addEventListener('input', () => {
      const t = plano(q.value.trim());
      let n = 0;
      raiz.querySelectorAll('[data-bz-fila]').forEach((tr) => {
        const ve = t === '' || plano(tr.dataset.buscar).includes(t);
        tr.hidden = !ve;
        if (ve) n++;
      });
      const sin = raiz.querySelector('[data-bz-sin-resultados]');
      if (sin) sin.hidden = n > 0;
    });
  }
})();
