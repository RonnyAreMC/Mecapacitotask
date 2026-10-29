/* =========================================================
   Mis notas (notas.php): la lista y la nota abierta, al estilo de las Notas
   del iPhone. Habla con notas_api.php (datos y permisos en lib/Notas.php).

   - Se guarda sola: ~600 ms después de dejar de escribir, una vuelta a la vez.
     Si no hay red, lo escrito queda también en el navegador y se reintenta
     cada vez más espaciado; al volver la red, sale solo.
   - Una nota que se deja vacía (sin título, sin texto y sin nada puesto) se
     descarta al salir de ella, como en el iPhone.
   - Borrar se puede deshacer unos segundos (el aviso trae «Deshacer»).
   - Ctrl/⌘ N (o Alt N, porque el navegador se guarda Ctrl N): nota nueva.
     Ctrl/⌘ F: buscar. Recuerda la última nota abierta.
   - En el celular (≤ 900 px) primero la lista; la nota se abre a pantalla
     completa con «volver» (y el botón atrás del teléfono también vuelve).
   ========================================================= */
(() => {
  'use strict';

  const raiz = document.getElementById('nt');
  if (!raiz) return;
  const $ = (s, r = document) => r.querySelector(s);
  const DATOS = JSON.parse($('#nt-datos')?.textContent || '{}');
  const ICONOS = {};
  $('#nt-iconos')?.content.querySelectorAll('[data-ico]').forEach((s) => { ICONOS[s.dataset.ico] = s.innerHTML; });
  const ico = (n) => ICONOS[n] || '';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MAC = /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent || '');
  const movReducido = matchMedia('(prefers-reduced-motion: reduce)');
  const anchoCel = matchMedia('(max-width: 900px)');
  const celular = () => anchoCel.matches;
  const toast = (m, tipo = 'info', ms = 4500, op = {}) => { if (window.MC && MC.toast) MC.toast(m, tipo, ms, op); };

  // Almacenamiento del navegador: puede no estar (ventana privada). Nunca rompe nada.
  const local = {
    leer(k, d = null) { try { const v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    poner(k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } },
  };

  /* ---------- Piezas de la página ---------- */
  const filasEl = $('#nt-filas'), nadaEl = $('#nt-nada'), buscar = $('#nt-buscar'), cuentaEl = $('#nt-cuenta');
  const hoja = $('#nt-hoja'), bienvenida = $('#nt-bienvenida'), papel = $('#nt-papel'), metaEl = $('#nt-meta');
  const tituloEl = $('#nt-titulo'), rt = $('#nt-editor'), area = rt?.querySelector('.rt-area');
  const estadoEl = $('#nt-estado');
  const pzEl = $('#nt-pz'), pzMarco = $('#nt-pz-marco'), pzIframe = $('#nt-pz-iframe'), pzVacia = $('#nt-pz-vacia'), pzAbrir = $('#nt-pz-abrir');
  const pops = { color: $('#nt-pop-color'), aviso: $('#nt-pop-aviso'), compartir: $('#nt-pop-compartir') };
  const btn = (a) => hoja.querySelector(`[data-accion="${a}"]`);
  if (!area) return;

  // En Mac, los atajos dicen ⌘; la barra de formato usa el tooltip del panel, no el del navegador
  if (MAC) raiz.querySelectorAll('kbd[data-mod]').forEach((k) => { k.textContent = '⌘'; });
  rt.querySelectorAll('.rt-b[title]').forEach((b) => { b.dataset.dsTip = b.title; b.removeAttribute('title'); });
  const tipNueva = 'Nueva nota (' + (MAC ? '⌘N o ⌥N' : 'Ctrl N o Alt N') + ')';
  raiz.querySelectorAll('#nt-nueva, [data-nueva]').forEach((b) => { b.dataset.dsTip = tipNueva; });

  /* ---------- Fechas (con la hora del panel, no la del equipo) ---------- */
  const desfase = (() => { const t = aFecha(DATOS.ahora); return t ? t.getTime() - Date.now() : 0; })();
  function aFecha(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null;
  }
  const dos = (n) => String(n).padStart(2, '0');
  const ahora = () => new Date(Date.now() + desfase);
  const sello = (d = ahora()) => `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())} ${dos(d.getHours())}:${dos(d.getMinutes())}:${dos(d.getSeconds())}`;
  const DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
  const DIAS_L = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const hora = (d) => d.getHours() + ':' + dos(d.getMinutes());
  const diasEntre = (a, b) => Math.round((new Date(a.getFullYear(), a.getMonth(), a.getDate()) - new Date(b.getFullYear(), b.getMonth(), b.getDate())) / 864e5);
  /** «hoy 9:40», «ayer», «lun», «12/10» (o «12/10/25» si es de otro año). */
  function fechaCorta(s) {
    const d = aFecha(s);
    if (!d) return '';
    const hoy = ahora(), dif = diasEntre(hoy, d);
    if (dif <= 0) return 'hoy ' + hora(d);
    if (dif === 1) return 'ayer';
    if (dif < 7) return DIAS[d.getDay()];
    return d.getDate() + '/' + (d.getMonth() + 1) + (d.getFullYear() !== hoy.getFullYear() ? '/' + String(d.getFullYear()).slice(2) : '');
  }
  /** «hoy a las 9:40», «mañana a las 9:00», «el jueves 30 oct a las 9:00». */
  function fechaLarga(s) {
    const d = aFecha(s);
    if (!d) return '';
    const dif = diasEntre(d, ahora());
    const dia = dif === 0 ? 'hoy' : dif === 1 ? 'mañana' : dif === -1 ? 'ayer' : 'el ' + DIAS_L[d.getDay()] + ' ' + d.getDate() + ' ' + MESES[d.getMonth()];
    return dia + ' a las ' + hora(d);
  }

  /* ---------- Las notas ---------- */
  const notas = new Map();
  (DATOS.notas || []).forEach((n) => { n._base = n.actualizado; notas.set(n.id, n); });
  let abierta = 0;        // id de la nota abierta
  let filtro = '';

  // Texto plano de la nota (para la vista previa y el buscador), cacheado por html
  const parser = new DOMParser();
  const cacheTexto = new Map();
  function lineas(html) {
    if (!html) return [];
    if (cacheTexto.has(html)) return cacheTexto.get(html);
    const conSaltos = html.replace(/<\/(p|div|h[1-6]|li|tr|blockquote|pre)>|<br\s*\/?>/gi, '\n$&');
    const t = (parser.parseFromString(conSaltos, 'text/html').body.textContent || '');
    const ls = t.split('\n').map((l) => l.replace(/\s+/g, ' ').trim()).filter(Boolean);
    if (cacheTexto.size > 400) cacheTexto.clear();
    cacheTexto.set(html, ls);
    return ls;
  }
  const nombre = (n) => (n.titulo || '').trim() || lineas(n.html)[0] || 'Nota nueva';
  function previa(n) {
    const ls = lineas(n.html);
    const resto = (n.titulo || '').trim() ? ls : ls.slice(1);
    return resto.join(' ').slice(0, 160) || 'Sin más texto';
  }
  const vacia = (n) => !n.titulo.trim() && !lineas(n.html).length && !/<(table|hr)\b/i.test(n.html || '') && !n.pizarra && !n.compartida && !n.aviso;
  const avisoPendiente = (n) => !!n.aviso && !n.aviso_enviado;
  const normalizar = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  function ordenadas() {
    const q = normalizar(filtro.trim());
    return [...notas.values()]
      .filter((n) => !n._borrando && (!q || normalizar(nombre(n) + ' ' + lineas(n.html).join(' ')).includes(q)))
      .sort((a, b) => (b.fijada - a.fijada) || (b.actualizado > a.actualizado ? 1 : b.actualizado < a.actualizado ? -1 : b.id - a.id));
  }

  /* ---------- La lista ---------- */
  const nodos = new Map();   // id → su fila
  const cabFijadas = Object.assign(document.createElement('div'), { className: 'nt-grupo', textContent: 'Fijadas' });
  const cabNotas = Object.assign(document.createElement('div'), { className: 'nt-grupo', textContent: 'Notas' });
  [cabFijadas, cabNotas].forEach((c) => c.setAttribute('role', 'presentation'));

  function resaltar(texto) {
    const t = esc(texto), q = filtro.trim();
    if (!q) return t;
    // Busca sin tildes, resalta sobre el texto original (misma longitud tras quitar las marcas)
    const base = normalizar(texto), i = base.indexOf(normalizar(q));
    if (i < 0 || base.length !== texto.length) return t;
    return esc(texto.slice(0, i)) + '<mark>' + esc(texto.slice(i, i + q.length)) + '</mark>' + esc(texto.slice(i + q.length));
  }
  function pintarFila(n) {
    let b = nodos.get(n.id);
    if (!b) {
      b = document.createElement('button');
      b.type = 'button';
      b.className = 'nt-fila';
      b.dataset.id = n.id;
      // La entrada se ve una vez: si la clase quedara, volvería a correr cada vez que la fila se mueve
      b.addEventListener('animationend', (e) => { if (e.animationName === 'nt-entra') b.classList.remove('is-nueva'); });
      nodos.set(n.id, b);
    }
    b.dataset.color = n.color || '';
    const es = n.id === abierta;
    b.classList.toggle('is-abierta', es);
    if (es) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
    const iconos = [], leer = [];
    if (n.pizarra) { iconos.push(ico('pz-pizarra')); leer.push('con pizarra'); }
    if (avisoPendiente(n)) { iconos.push(ico('ds-notificaciones')); leer.push('con recordatorio'); }
    if (n.compartida) { iconos.push(ico('ds-enlace')); leer.push('compartida'); }
    b.innerHTML = `<span class="nt-fila__l1"><b class="nt-fila__t">${resaltar(nombre(n))}</b>`
      + (iconos.length ? `<span class="nt-fila__ic" aria-hidden="true">${iconos.join('')}</span>` : '') + '</span>'
      + `<span class="nt-fila__l2"><time datetime="${esc(n.actualizado)}">${esc(fechaCorta(n.actualizado))}</time><span class="nt-fila__p">${resaltar(previa(n))}</span></span>`;
    b.setAttribute('aria-label', nombre(n) + ' · ' + fechaCorta(n.actualizado) + (n.fijada ? ' · fijada' : '') + (leer.length ? ' · ' + leer.join(', ') : ''));
    return b;
  }
  function pintarLista() {
    const lista = ordenadas();
    const fij = lista.filter((n) => n.fijada), resto = lista.filter((n) => !n.fijada);
    const hijos = [];
    if (fij.length) { hijos.push(cabFijadas, ...fij.map(pintarFila)); if (resto.length) hijos.push(cabNotas); }
    hijos.push(...resto.map(pintarFila));
    // Solo se mueve lo que cambió de lugar (mientras se escribe, casi nada); las que se
    // están yendo se quedan donde estaban hasta terminar su salida
    const quedan = new Set(hijos);
    let ref = filasEl.firstChild;
    for (const h of hijos) {
      while (ref && ref.classList?.contains('is-saliendo')) ref = ref.nextSibling;
      if (ref === h) { ref = ref.nextSibling; continue; }
      filasEl.insertBefore(h, ref);
    }
    [...filasEl.children].forEach((c) => { if (!quedan.has(c) && !c.classList.contains('is-saliendo')) c.remove(); });
    const total = [...notas.values()].filter((n) => !n._borrando).length;
    cuentaEl.innerHTML = ico('ds-candado') + ' ' + (total ? total + (total === 1 ? ' nota' : ' notas') + ' · solo tú las ves' : 'Solo tú las ves');
    nadaEl.hidden = !!lista.length || !total;
    if (!lista.length && total) nadaEl.textContent = 'Nada coincide con «' + filtro.trim() + '».';
    bienvenida.hidden = !!total;
    if (!celular()) hoja.hidden = !abierta || !notas.has(abierta);
    else hoja.hidden = !abierta;
  }

  /* ---------- La nota abierta ---------- */
  const nota = () => notas.get(abierta) || null;
  function htmlDelEditor() {
    const vac = area.textContent.trim() === '' && !area.querySelector('table, hr');
    return vac ? '' : area.innerHTML;
  }
  function ponerEditor(html) {
    if (window.MecaRT) MecaRT.set('nt-editor', html || '');
    else { area.innerHTML = html || ''; rt.classList.toggle('rt-vacio', !html); }
  }

  function pintarBarra() {
    const n = nota();
    if (!n) return;
    const fijar = btn('fijar');
    fijar.setAttribute('aria-pressed', String(!!n.fijada));
    fijar.dataset.dsTip = n.fijada ? 'Desfijar' : 'Fijar arriba';
    fijar.setAttribute('aria-label', fijar.dataset.dsTip);
    btn('aviso').classList.toggle('is-on', avisoPendiente(n));
    btn('aviso').dataset.dsTip = avisoPendiente(n) ? 'Recordatorio: ' + fechaLarga(n.aviso) : 'Recordatorio';
    btn('compartir').classList.toggle('is-on', !!n.compartida);
    btn('compartir').dataset.dsTip = n.compartida ? 'Compartida con un enlace' : 'Compartir un enlace';
    const pz = btn('pizarra');
    if (pz) {
      pz.dataset.dsTip = n.pizarra ? 'Abrir la pizarra' : 'Agregar pizarra';
      pz.setAttribute('aria-label', pz.dataset.dsTip);
      pz.classList.toggle('is-on', !!n.pizarra);
    }
    hoja.dataset.color = n.color || '';
  }
  function pintarMeta() {
    const n = nota();
    if (!n) { metaEl.innerHTML = ''; return; }
    const d = aFecha(n.actualizado);
    let h = '<span>' + (d ? 'Editada ' + esc(fechaLarga(n.actualizado)) : '') + '</span>';
    if (avisoPendiente(n)) h += `<button type="button" class="nt-meta__chip nt-meta__chip--azul" data-meta="aviso">${ico('ds-notificaciones')} ${esc(fechaLarga(n.aviso))}</button>`;
    if (n.compartida) h += `<button type="button" class="nt-meta__chip" data-meta="compartir">${ico('ds-enlace')} Compartida</button>`;
    metaEl.innerHTML = h;
  }
  // La vista previa de la pizarra: solo si la nota tiene una (vacía: una línea, sin cargar nada)
  let pzCargada = 0;
  function pintarPizarra(recargar = false) {
    if (!pzEl) return;
    const n = nota();
    const con = !!(n && n.pizarra);
    pzEl.hidden = !con;
    hoja.classList.toggle('con-pizarra', con);
    if (!con) { if (pzIframe.src) pzIframe.removeAttribute('src'); return; }
    const url = 'pizarra.php?nota=' + n.id;
    pzAbrir.href = url;
    pzMarco.href = url;
    pzEl.querySelector('[data-pz-abrir]').href = url;
    const llena = (n.pizarra_n || 0) > 0;
    pzMarco.hidden = !llena;
    pzVacia.hidden = llena;
    const src = url + '&embed=1';
    if (llena && (recargar || !pzIframe.getAttribute('src') || !pzIframe.getAttribute('src').startsWith(src))) {
      pzIframe.src = src + '&r=' + Date.now().toString(36);
      pzCargada = Date.now();
    }
    if (!llena && pzIframe.getAttribute('src')) pzIframe.removeAttribute('src');
  }

  function abrir(id, { foco = '', historia = true } = {}) {
    const n = notas.get(id);
    if (!n) return;
    cerrarPops();
    if (abierta && abierta !== id) dejar(abierta);
    const antes = abierta;
    abierta = id;
    tituloEl.value = n.titulo || '';
    if (antes !== id) ponerEditor(n.html);
    pintarBarra();
    pintarMeta();
    pintarPizarra();
    pintarLista();
    papel.scrollTop = 0;
    local.poner('nt-ultima', id);
    if (celular()) {
      document.documentElement.classList.add('nt-abierta');
      if (historia && !history.state?.ntHoja) history.pushState({ ntHoja: 1 }, '', 'notas.php?id=' + id);
      else history.replaceState(history.state, '', 'notas.php?id=' + id);
    } else {
      history.replaceState(history.state, '', 'notas.php?id=' + id);
    }
    if (!celular()) nodos.get(id)?.scrollIntoView({ block: 'nearest' });
    if (foco === 'titulo') tituloEl.focus();
    else if (foco === 'texto') enfocarFinal();
  }
  function enfocarFinal() {
    area.focus();
    const r = document.createRange();
    r.selectNodeContents(area);
    r.collapse(false);
    const s = getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }
  /** Al salir de una nota: lo pendiente sale ya; si quedó vacía, se descarta. */
  function dejar(id) {
    const n = notas.get(id);
    if (!n || n._borrando) return;
    if (vacia(n)) { n._sucia = false; soltarRespaldo(n.id); borrarDeVerdad(n, { callado: true }); return; }
    if (n._sucia) guardarYa();
  }
  /** En el celular: de la nota a la lista. */
  function volverALista({ desdeHistoria = false } = {}) {
    if (!document.documentElement.classList.contains('nt-abierta')) return;
    cerrarPops();
    document.activeElement?.blur?.();
    const id = abierta;
    document.documentElement.classList.remove('nt-abierta');
    if (!desdeHistoria && history.state?.ntHoja) { history.back(); }
    history.replaceState(null, '', 'notas.php');
    if (id) dejar(id);
    abierta = 0;
    pintarLista();
  }
  addEventListener('popstate', () => { if (celular() && document.documentElement.classList.contains('nt-abierta')) volverALista({ desdeHistoria: true }); });

  /* ---------- Guardar ---------- */
  const API = 'notas_api.php?accion=';
  async function api(accion, cuerpo = null, { keepalive = false } = {}) {
    const op = { method: cuerpo ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', keepalive, headers: { 'X-Requested-With': 'fetch' } };
    if (cuerpo) { op.headers['Content-Type'] = 'application/json'; op.body = JSON.stringify(cuerpo); }
    let r;
    try { r = await fetch(API + accion, op); } catch (e) { throw Object.assign(new Error('No hay conexión con el panel.'), { red: true }); }
    let j = null;
    try { j = await r.json(); } catch (e) { /* no es JSON */ }
    if (r.status === 401) throw Object.assign(new Error(j?.error || 'Tu sesión se cerró.'), { sesion: true });
    if (!r.ok || !j || !j.ok) throw Object.assign(new Error(j?.error || 'El panel no respondió bien (' + r.status + ').'), { status: r.status });
    return j;
  }
  /** Lo que llega del panel, sin pisar lo que se está escribiendo. */
  function mezclar(n, s) {
    for (const k of ['color', 'fijada', 'aviso', 'aviso_enviado', 'compartida', 'enlace', 'pizarra', 'pizarra_n', 'creado']) if (k in s) n[k] = s[k];
    n._base = s.actualizado;                       // la versión del panel (para el respaldo)
    if (!n._sucia) n.actualizado = s.actualizado;  // la fecha que se ve, solo si no se está escribiendo
    return n;
  }

  const sync = { timer: 0, corriendo: false, otra: false, fallos: 0, parado: false };
  const ESTADOS = {
    ok:        ['ds-check', 'Guardado'],
    guardando: ['', 'Guardando…'],
    red:       ['pz-sin-red', 'Sin conexión · se guarda al volver'],
    error:     ['ds-alerta', 'No se guardó · reintentando'],
    sesion:    ['ds-alerta', 'Tu sesión se cerró · recarga'],
  };
  function pintarEstado(e, txt) {
    const [i, t] = ESTADOS[e] || ESTADOS.ok;
    estadoEl.dataset.estado = e;
    estadoEl.querySelector('.nt-estado__ico').innerHTML = i ? ico(i) : '<span class="nt-giro"></span>';
    estadoEl.querySelector('.nt-estado__txt').textContent = txt || t;
    estadoEl.title = txt || t;
  }
  const sucias = () => [...notas.values()].filter((n) => n._sucia && !n._borrando);

  function alEscribir() {
    const n = nota();
    if (!n) return;
    n.titulo = tituloEl.value;
    n.html = htmlDelEditor();
    n.actualizado = sello();
    n._sucia = true;
    respaldar(n);
    pintarLista();
    if (!sync.parado) pintarEstado('guardando');
    clearTimeout(sync.timer);
    sync.timer = setTimeout(guardar, 600);
  }
  tituloEl.addEventListener('input', alEscribir);
  area.addEventListener('input', alEscribir);
  // Enter en el título baja al texto; flecha abajo al final, también
  tituloEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || (e.key === 'ArrowDown' && tituloEl.selectionStart === tituloEl.value.length)) { e.preventDefault(); area.focus(); }
  });
  // Un clic en el papel vacío (debajo del texto) escribe al final
  papel.addEventListener('mousedown', (e) => {
    if (e.target === papel || e.target === rt) { e.preventDefault(); enfocarFinal(); }
  });

  async function guardar() {
    clearTimeout(sync.timer);
    if (sync.parado) return;
    if (sync.corriendo) { sync.otra = true; return; }
    const lista = sucias();
    if (!lista.length) { pintarEstado('ok'); return; }
    sync.corriendo = true;
    pintarEstado('guardando');
    try {
      for (const n of lista) {
        const env = { id: n.id, titulo: n.titulo, html: n.html };
        n._sucia = false;
        try {
          const j = await api('guardar', env);
          // Si mientras iba no cambió nada, queda lo que el panel guardó (ya saneado)
          if (!n._sucia && n.titulo === env.titulo && n.html === env.html) { n.html = j.nota.html; n.titulo = j.nota.titulo; }
          mezclar(n, j.nota);
          if (n._sucia) respaldar(n); else soltarRespaldo(n.id);   // si se siguió escribiendo, el respaldo pasa a la versión nueva
        } catch (err) {
          if (n._borrando) continue;   // se está borrando: no importa
          // Ya no estaba en el panel (se borró en otra pestaña): vuelve a nacer con lo escrito
          if (err.status === 404 && !n._borrando) {
            try { await renacer(n, env); continue; } catch (e2) { n._sucia = true; throw e2; }
          }
          n._sucia = true;
          throw err;
        }
      }
      sync.fallos = 0;
      pintarEstado(sucias().length ? 'guardando' : 'ok');
    } catch (err) {
      if (err.sesion) { sync.parado = true; pintarEstado('sesion'); toast(err.message + ' Recarga la página para seguir: lo escrito quedó guardado en este navegador.', 'error', 9000); return; }
      sync.fallos++;
      pintarEstado(err.red ? 'red' : 'error', err.red ? null : 'No se guardó: ' + err.message);
      sync.timer = setTimeout(guardar, Math.min(30000, 2000 * 2 ** (sync.fallos - 1)));
    } finally {
      sync.corriendo = false;
      if (sync.otra) { sync.otra = false; if (!sync.fallos) guardar(); }
      if (!sync.fallos) pintarLista();
    }
  }
  /** Crea de nuevo una nota que el panel ya no tenía, con lo escrito; la fila y la URL pasan al id nuevo. */
  async function renacer(n, env) {
    const j = await api('guardar', { titulo: env.titulo, html: env.html, color: n.color || '', fijada: !!n.fijada });
    const viejo = n.id;
    notas.delete(viejo);
    nodos.get(viejo)?.remove();
    nodos.delete(viejo);
    soltarRespaldo(viejo);
    n.id = j.nota.id;
    mezclar(n, j.nota);
    notas.set(n.id, n);
    if (abierta === viejo) {
      abierta = n.id;
      local.poner('nt-ultima', n.id);
      history.replaceState(history.state, '', 'notas.php?id=' + n.id);
      pintarBarra();
      pintarMeta();
      pintarPizarra();
    }
  }
  const guardarYa = () => { clearTimeout(sync.timer); guardar(); };
  addEventListener('online', () => { if (sucias().length) guardarYa(); });
  // Al irse (otra pestaña, cerrar, ir a la pizarra): lo pendiente sale igual
  function despedida() {
    for (const n of sucias()) {
      try { api('guardar', { id: n.id, titulo: n.titulo, html: n.html }, { keepalive: true }).then(() => soltarRespaldo(n.id)).catch(() => {}); } catch (e) { /* queda el respaldo */ }
      n._sucia = false;
    }
    for (const [id, t] of porBorrar) { clearTimeout(t); porBorrar.delete(id); try { api('eliminar', { id }, { keepalive: true }).catch(() => {}); } catch (e) { /* nada */ } }
    const n = nota();
    if (n && vacia(n) && !n._borrando) { try { api('eliminar', { id: n.id }, { keepalive: true }).catch(() => {}); } catch (e) { /* nada */ } }
  }
  addEventListener('pagehide', despedida);
  document.addEventListener('visibilitychange', () => { if (document.hidden && sucias().length) guardarYa(); });

  // Respaldo en el navegador de lo que aún no llegó al panel (por si se corta la red o se cierra)
  const CLAVE_RESP = 'nt-respaldo';
  function respaldar(n) {
    const r = local.leer(CLAVE_RESP, {}) || {};
    r[n.id] = { titulo: n.titulo, html: n.html, base: n._base };
    local.poner(CLAVE_RESP, r);
  }
  function soltarRespaldo(id) {
    const r = local.leer(CLAVE_RESP, {}) || {};
    if (!(id in r)) return;
    delete r[id];
    local.poner(CLAVE_RESP, Object.keys(r).length ? r : null);
  }
  function recuperarRespaldos() {
    const r = local.leer(CLAVE_RESP, {}) || {};
    let hubo = 0;
    for (const [id, x] of Object.entries(r)) {
      const n = notas.get(+id);
      // Solo si el panel no cambió desde entonces (si cambió, ya llegó o se editó en otro lado: gana el panel)
      if (n && x && x.base === n._base && (x.titulo !== n.titulo || x.html !== n.html)) {
        n.titulo = String(x.titulo || ''); n.html = String(x.html || ''); n._sucia = true; n.actualizado = sello(); hubo++;
      } else soltarRespaldo(+id);
    }
    if (hubo) { toast(hubo === 1 ? 'Recuperé lo que habías escrito sin conexión.' : 'Recuperé ' + hubo + ' notas escritas sin conexión.', 'info', 4000); guardarYa(); }
  }

  /* ---------- Nueva ---------- */
  let creando = false;
  async function nueva() {
    if (creando) return;
    creando = true;
    if (filtro) { filtro = ''; buscar.value = ''; }
    try {
      const j = await api('guardar', { titulo: '', html: '' });
      const n = j.nota;
      n._base = n.actualizado;
      notas.set(n.id, n);
      if (!movReducido.matches) pintarFila(n).classList.add('is-nueva');
      abrir(n.id, { foco: 'titulo' });
    } catch (err) {
      toast(err.red ? 'Sin conexión: la nota nueva se crea cuando vuelva la red.' : 'No se pudo crear la nota: ' + err.message, 'error');
    } finally {
      creando = false;
    }
  }
  raiz.querySelectorAll('#nt-nueva, [data-nueva]').forEach((b) => b.addEventListener('click', nueva));

  /* ---------- Borrar (se puede deshacer) ---------- */
  const porBorrar = new Map();   // id → reloj
  function sacarFila(id, alFinal) {
    const f = nodos.get(id);
    nodos.delete(id);
    if (!f || !f.isConnected || movReducido.matches) { f?.remove(); alFinal?.(); return; }
    f.classList.add('is-saliendo');
    let listo = false;
    const fin = () => { if (listo) return; listo = true; f.remove(); alFinal?.(); };
    f.addEventListener('animationend', fin, { once: true });
    setTimeout(fin, 420);
  }
  /** La que sigue en la lista (para abrirla al borrar). */
  function vecina(id) {
    const l = ordenadas().filter((n) => n.id !== id);
    const todas = ordenadas(), i = todas.findIndex((n) => n.id === id);
    return l[Math.min(Math.max(0, i), l.length - 1)] || null;
  }
  function eliminar() {
    const n = nota();
    if (!n) return;
    cerrarPops();
    const sig = celular() ? null : vecina(n.id);
    n._borrando = true;
    n._sucia = false;
    soltarRespaldo(n.id);
    sacarFila(n.id);
    if (celular()) volverALista(); else { abierta = 0; if (sig) abrir(sig.id); else pintarLista(); }
    pintarLista();
    // Se borra de verdad un poco después de que se va el aviso (así «Deshacer» nunca llega tarde)
    porBorrar.set(n.id, setTimeout(() => borrarDeVerdad(n), 5800));
    toast('Nota eliminada', 'info', 5200, {
      desc: n.pizarra ? 'Con su pizarra.' : '',
      accion: { texto: 'Deshacer', fn: () => {
        if (!porBorrar.has(n.id)) { toast('Ya se había eliminado.', 'warning', 2600); return; }
        clearTimeout(porBorrar.get(n.id));
        porBorrar.delete(n.id);
        n._borrando = false;
        if (!movReducido.matches) pintarFila(n).classList.add('is-nueva');
        abrir(n.id);
      } },
    });
  }
  async function borrarDeVerdad(n, { callado = false } = {}) {
    porBorrar.delete(n.id);
    n._borrando = true;
    if (callado) sacarFila(n.id);
    try {
      await api('eliminar', { id: n.id });
      notas.delete(n.id);
    } catch (err) {
      // Una vacía que no se pudo borrar se va de la vista igual; si ya no existía (404), ya está
      if (callado || err.status === 404) { notas.delete(n.id); if (!callado) pintarLista(); return; }
      n._borrando = false;
      toast('No se pudo eliminar: ' + err.message, 'error');
    }
    pintarLista();
  }

  /* ---------- Fijar y color ---------- */
  async function cambiar(n, datos, deshacer) {
    try {
      const j = await api('guardar', Object.assign({ id: n.id }, datos));
      mezclar(n, j.nota);
    } catch (err) {
      deshacer();
      toast('No se pudo cambiar: ' + err.message, 'error');
    }
    if (n.id === abierta) { pintarBarra(); pintarMeta(); }
    pintarLista();
  }
  function fijar() {
    const n = nota();
    if (!n) return;
    const antes = n.fijada;
    n.fijada = !antes;
    pintarBarra();
    pintarLista();
    nodos.get(n.id)?.scrollIntoView({ block: 'nearest', behavior: movReducido.matches ? 'auto' : 'smooth' });
    cambiar(n, { fijada: n.fijada }, () => { n.fijada = antes; });
  }
  function ponerColor(c) {
    const n = nota();
    if (!n || n.color === c) return;
    const antes = n.color;
    n.color = c;
    pintarBarra();
    pintarLista();
    marcarColor();
    cambiar(n, { color: c }, () => { n.color = antes; marcarColor(); });
  }
  function marcarColor() {
    const n = nota();
    pops.color.querySelectorAll('.nt-color').forEach((b) => b.setAttribute('aria-checked', String(!!n && (n.color || '') === b.dataset.color)));
  }
  pops.color.addEventListener('click', (e) => { const b = e.target.closest('.nt-color'); if (b) ponerColor(b.dataset.color); });
  pops.color.addEventListener('keydown', (e) => {
    if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    const bs = [...pops.color.querySelectorAll('.nt-color')], i = bs.indexOf(document.activeElement);
    const j = (i + (e.key === 'ArrowRight' ? 1 : -1) + bs.length) % bs.length;
    bs[j].focus();
    ponerColor(bs[j].dataset.color);
    e.preventDefault();
  });

  /* ---------- Los cuadros que flotan ---------- */
  let popAbierto = null, popBoton = null;
  function ubicar(pop, boton) {
    const r = boton.getBoundingClientRect();
    pop.style.left = '0px';
    pop.style.top = '0px';
    const w = pop.offsetWidth, h = pop.offsetHeight;
    const x = Math.max(12, Math.min(r.right - w, innerWidth - w - 12));
    let y = r.bottom + 8;
    if (y + h > innerHeight - 12) y = Math.max(12, r.top - h - 8);
    pop.style.left = Math.round(x) + 'px';
    pop.style.top = Math.round(y) + 'px';
  }
  function abrirPop(nombre, boton) {
    const pop = pops[nombre];
    if (popAbierto === pop) { cerrarPops(); return; }
    cerrarPops();
    if (nombre === 'color') marcarColor();
    if (nombre === 'aviso') prepararAviso();
    if (nombre === 'compartir') prepararCompartir();
    pop.hidden = false;
    popAbierto = pop;
    popBoton = boton;
    const ancla = btn(nombre) || boton;
    ancla.setAttribute('aria-expanded', 'true');
    ubicar(pop, boton);
    const primero = pop.querySelector('[aria-checked="true"]') || pop.querySelector('button:not([hidden]), input:not([readonly])');
    setTimeout(() => primero?.focus({ preventScroll: true }), 30);
  }
  function cerrarPops({ devolverFoco = false } = {}) {
    if (!popAbierto) return;
    popAbierto.hidden = true;
    Object.keys(pops).forEach((k) => btn(k)?.setAttribute('aria-expanded', 'false'));
    if (devolverFoco) popBoton?.focus({ preventScroll: true });
    popAbierto = null;
    popBoton = null;
  }
  document.addEventListener('pointerdown', (e) => {
    if (popAbierto && !popAbierto.contains(e.target) && !e.target.closest('[data-accion="color"], [data-accion="aviso"], [data-accion="compartir"], [data-meta]')) cerrarPops();
  }, true);
  addEventListener('resize', () => { if (popAbierto && popBoton?.isConnected) ubicar(popAbierto, popBoton); });
  papel.addEventListener('scroll', () => { if (popAbierto && popBoton && metaEl.contains(popBoton)) cerrarPops(); }, { passive: true });

  /* ---------- Recordatorio ---------- */
  const avisoCuando = $('#nt-aviso-cuando'), avisoActual = $('#nt-aviso-actual'), avisoError = $('#nt-aviso-error');
  const aLocal = (d) => `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}T${dos(d.getHours())}:${dos(d.getMinutes())}`;
  function prepararAviso() {
    const n = nota();
    avisoError.hidden = true;
    const pend = avisoPendiente(n);
    avisoActual.textContent = pend ? 'Te aviso ' + fechaLarga(n.aviso) + ', en la campana, en tus dispositivos y por correo.' : 'A esa hora te aviso en la campana, en tus dispositivos y por correo.';
    const d = pend ? aFecha(n.aviso) : new Date(ahora().getTime() + 3600e3);
    avisoCuando.value = aLocal(d);
    avisoCuando.min = aLocal(ahora());
    pops.aviso.querySelector('[data-aviso="quitar"]').hidden = !n.aviso;
  }
  async function ponerAviso(cuando) {
    const n = nota();
    if (!n) return;
    try {
      const j = await api('recordatorio', { id: n.id, cuando });
      mezclar(n, j.nota);
      cerrarPops({ devolverFoco: true });
      pintarBarra();
      pintarMeta();
      pintarLista();
      toast(cuando ? 'Te aviso ' + fechaLarga(n.aviso) + '.' : 'Recordatorio quitado.', 'success', 2600);
    } catch (err) {
      avisoError.textContent = err.red ? 'Sin conexión: inténtalo cuando vuelva la red.' : err.message;
      avisoError.hidden = false;
    }
  }
  pops.aviso.addEventListener('click', (e) => {
    const b = e.target.closest('[data-aviso]');
    if (!b) return;
    const a = b.dataset.aviso;
    if (a === 'hora') { const d = new Date(ahora().getTime() + 3600e3); ponerAviso(aLocal(d)); }
    else if (a === 'manana') { const d = ahora(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); ponerAviso(aLocal(d)); }
    else if (a === 'quitar') ponerAviso('');
    else if (a === 'guardar') {
      if (!avisoCuando.value) { avisoError.textContent = 'Elige una fecha y una hora.'; avisoError.hidden = false; return; }
      ponerAviso(avisoCuando.value);
    }
  });
  avisoCuando.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); pops.aviso.querySelector('[data-aviso="guardar"]').click(); } });

  /* ---------- Compartir un enlace ---------- */
  const enlaceEl = $('#nt-enlace');
  const puedeNativo = typeof navigator.share === 'function';
  function prepararCompartir() {
    const n = nota();
    pops.compartir.querySelector('[data-compartir="no"]').hidden = !!n.compartida;
    pops.compartir.querySelector('[data-compartir="si"]').hidden = !n.compartida;
    enlaceEl.value = n.enlace || '';
    pops.compartir.querySelector('[data-compartir-accion="nativo"]').hidden = !puedeNativo;
  }
  async function copiar(texto) {
    try {
      if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(texto); return true; }
    } catch (e) { /* cae al plan B */ }
    enlaceEl.focus();
    enlaceEl.select();
    try { return document.execCommand('copy'); } catch (e) { return false; }
  }
  pops.compartir.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-compartir-accion]');
    if (!b) return;
    const n = nota();
    const a = b.dataset.compartirAccion;
    if (a === 'crear' || a === 'apagar') {
      b.disabled = true;
      try {
        const j = await api('compartir', { id: n.id, activar: a === 'crear' });
        mezclar(n, j.nota);
        pintarBarra();
        pintarMeta();
        pintarLista();
        if (a === 'crear') {
          prepararCompartir();
          ubicar(pops.compartir, popBoton || btn('compartir'));
          const ok = await copiar(n.enlace);
          toast(ok ? 'Enlace creado y copiado' : 'Enlace creado', 'success', 2600, { desc: ok ? 'Pégalo donde quieras: la nota se lee sin iniciar sesión.' : '' });
          enlaceEl.select();
        } else {
          cerrarPops({ devolverFoco: true });
          toast('Dejaste de compartirla: el enlace ya no sirve.', 'success', 3200);
        }
      } catch (err) {
        toast((err.red ? 'Sin conexión. ' : '') + 'No se pudo: ' + err.message, 'error');
      } finally {
        b.disabled = false;
      }
    } else if (a === 'copiar') {
      const ok = await copiar(n.enlace);
      toast(ok ? 'Enlace copiado' : 'No se pudo copiar: selecciona el enlace y usa Ctrl+C.', ok ? 'success' : 'error', 2200);
    } else if (a === 'nativo') {
      try { await navigator.share({ title: nombre(n), text: nombre(n), url: n.enlace }); } catch (e) { /* lo cerró */ }
    }
  });
  enlaceEl.addEventListener('focus', () => enlaceEl.select());

  /* ---------- Pizarra ---------- */
  async function irAPizarra() {
    const n = nota();
    if (!n) return;
    if (sucias().length) await guardar();
    if (n.pizarra) { location.href = 'pizarra.php?nota=' + n.id; return; }
    const b = btn('pizarra');
    b.disabled = true;
    try {
      const j = await api('pizarra', { id: n.id, poner: true });
      mezclar(n, j.nota);
      location.href = j.url;
    } catch (err) {
      b.disabled = false;
      toast('No se pudo agregar la pizarra: ' + err.message, 'error');
    }
  }
  pzEl?.querySelector('[data-pz-quitar]')?.addEventListener('click', async () => {
    const n = nota();
    if (!n) return;
    try {
      const j = await api('pizarra', { id: n.id, poner: false });
      mezclar(n, j.nota);
      pintarBarra();
      pintarPizarra();
      pintarLista();
    } catch (err) { toast(err.message, 'error'); }
  });
  // Antes de ir a la pizarra, lo escrito sale (los enlaces «Abrir»)
  pzEl?.addEventListener('click', async (e) => {
    const a = e.target.closest('a[href^="pizarra.php"]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    if (sucias().length) { e.preventDefault(); await guardar(); location.href = a.href; }
  });
  // La vista previa se pone al día al volver (otra pestaña, botón atrás, cambio de tema)
  const refrescarPrevia = () => { if (pzMarco && !pzMarco.hidden && nota()?.pizarra) pintarPizarra(true); };
  document.addEventListener('visibilitychange', () => { if (!document.hidden && Date.now() - pzCargada > 15000) refrescarPrevia(); });
  addEventListener('pageshow', (e) => { if (e.persisted) refrescarPrevia(); });
  document.addEventListener('meca:tema', refrescarPrevia);

  /* ---------- La barra de la nota ---------- */
  hoja.querySelector('.nt-barra').addEventListener('click', (e) => {
    const b = e.target.closest('[data-accion]');
    if (!b || b.disabled) return;
    switch (b.dataset.accion) {
      case 'volver': volverALista(); break;
      case 'fijar': fijar(); break;
      case 'color': abrirPop('color', b); break;
      case 'aviso': abrirPop('aviso', b); break;
      case 'compartir': abrirPop('compartir', b); break;
      case 'pizarra': irAPizarra(); break;
      case 'eliminar': eliminar(); break;
    }
  });
  metaEl.addEventListener('click', (e) => {
    const c = e.target.closest('[data-meta]');
    if (c) abrirPop(c.dataset.meta, c);
  });

  /* ---------- La lista: clic, flechas y buscar ---------- */
  filasEl.addEventListener('click', (e) => {
    const f = e.target.closest('.nt-fila');
    if (!f || f.classList.contains('is-saliendo')) return;
    const id = +f.dataset.id;
    if (id === abierta && !celular()) return;
    abrir(id);
  });
  filasEl.addEventListener('keydown', (e) => {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
    const fs = [...filasEl.querySelectorAll('.nt-fila:not(.is-saliendo)')];
    const i = fs.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault();
    const j = e.key === 'Home' ? 0 : e.key === 'End' ? fs.length - 1 : Math.max(0, Math.min(fs.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)));
    fs[j].focus();
    if (!celular()) abrir(+fs[j].dataset.id);
  });
  let relojBuscar = 0;
  buscar.addEventListener('input', () => {
    clearTimeout(relojBuscar);
    relojBuscar = setTimeout(() => { filtro = buscar.value; pintarLista(); }, 90);
  });
  buscar.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && buscar.value) { e.preventDefault(); buscar.value = ''; filtro = ''; pintarLista(); }
    if (e.key === 'ArrowDown' || e.key === 'Enter') {
      const f = filasEl.querySelector('.nt-fila:not(.is-saliendo)');
      if (f) { e.preventDefault(); if (e.key === 'Enter') { abrir(+f.dataset.id, { foco: 'texto' }); } else f.focus(); }
    }
  });

  /* ---------- Teclado ---------- */
  document.addEventListener('keydown', (e) => {
    const mod = MAC ? e.metaKey : e.ctrlKey;
    // Ctrl/⌘ N (el navegador suele quedarse con él) o Alt N: nota nueva. En Mac, ⌥N es la
    // tecla de la «ñ» con teclado inglés: mientras se escribe se deja pasar.
    const escribiendo = !!e.target.closest?.('input, textarea, [contenteditable="true"]');
    const altN = e.altKey && !e.ctrlKey && !e.metaKey && !(MAC && escribiendo);
    if (e.code === 'KeyN' && !e.shiftKey && ((mod && !e.altKey) || altN)) { e.preventDefault(); nueva(); return; }
    if (mod && !e.altKey && !e.shiftKey && e.code === 'KeyF') {
      // La segunda vez, con el buscador ya enfocado, queda el «buscar» del navegador
      if (document.activeElement === buscar) return;
      e.preventDefault();
      if (celular()) volverALista();
      buscar.focus();
      buscar.select();
      return;
    }
    if (e.key === 'Escape') {
      if (popAbierto) { e.preventDefault(); cerrarPops({ devolverFoco: true }); return; }
      if (celular() && document.documentElement.classList.contains('nt-abierta') && !e.target.closest?.('.rt-area, input')) volverALista();
    }
  });

  /* ---------- Alto: la app ocupa la pantalla, sin desplazar la página ---------- */
  function medir() {
    if (celular()) { raiz.style.removeProperty('--nt-alto'); return; }
    const arriba = raiz.getBoundingClientRect().top + scrollY;
    raiz.style.setProperty('--nt-alto', Math.max(460, Math.round(innerHeight - arriba - 20)) + 'px');
  }
  let relojMedir = 0;
  addEventListener('resize', () => { cancelAnimationFrame(relojMedir); relojMedir = requestAnimationFrame(medir); });
  anchoCel.addEventListener?.('change', () => {
    medir();
    // Al pasar de celular a ancho con una nota abierta, sigue abierta en su lugar
    if (!celular()) document.documentElement.classList.remove('nt-abierta');
    else if (abierta) document.documentElement.classList.add('nt-abierta');
    pintarLista();
  });

  /* ---------- Arranque ---------- */
  recuperarRespaldos();
  medir();
  const pedida = Number(DATOS.abrir) || 0;
  const ultima = Number(local.leer('nt-ultima', 0)) || 0;
  // Las que quedaron vacías (se cerró la página antes de escribir, o su borrado aún va en
  // camino): fuera, como en el iPhone. Si otra pestaña la estaba usando, renacer() la recupera.
  for (const n of [...notas.values()]) if (n.id !== pedida && !n._sucia && vacia(n)) borrarDeVerdad(n, { callado: true });
  const viva = (id) => notas.has(id) && !notas.get(id)._borrando;
  if (pedida && viva(pedida)) abrir(pedida, { historia: false });
  else if (!celular()) {
    const primera = ordenadas()[0];
    const id = viva(ultima) ? ultima : primera?.id;
    if (id) abrir(id, { historia: false }); else pintarLista();
  } else pintarLista();
  if (pedida && !viva(pedida)) toast('Esa nota ya no existe o no es tuya.', 'warning', 4000);
  if (!sync.parado) pintarEstado(sucias().length ? 'guardando' : 'ok');
  // En el celular, si se entró directo a una nota, «volver» lleva a la lista sin salir de la página
  if (celular() && abierta) { history.replaceState(null, '', 'notas.php'); history.pushState({ ntHoja: 1 }, '', 'notas.php?id=' + abierta); }

  // Para las pruebas: una mirada al estado (no cambia nada)
  window.__notas = { notas, get abierta() { return abierta; }, sync };
})();
