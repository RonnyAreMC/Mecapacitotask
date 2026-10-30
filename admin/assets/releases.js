/* =========================================================
   Releases (releases.php): el panel lateral del ítem, los tooltips del Gantt y
   la hoja de ruta, los formularios por fetch (actions.php · rel_*), la IA
   (resumen general, asociar tareas de un equipo, resumen de una revisión),
   registrar revisiones, hitos, ajustes e importar los Excel.

   Todo va por delegación sobre document: también funciona con lo que llega
   después (el detalle del ítem que se carga en el panel lateral).
   Se carga con defer: ya están MC (admin.js), DS (ds.js) y MecaDate.
   ========================================================= */
(() => {
  const raiz = document.querySelector('[data-rl]');
  if (!raiz) return;
  let D = {};
  try { D = JSON.parse((document.getElementById('rl-datos') || {}).textContent || '{}'); } catch (e) { D = {}; }

  /* ---------- Ayudas ---------- */
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = (m, t = 'success', op) => (window.MC && MC.toast ? MC.toast(m, t, t === 'error' ? 7000 : 4500, op || {}) : console.warn(m));
  const confirmar = (o) => (window.MC && MC.confirm ? MC.confirm(o) : Promise.resolve(false));
  const plano = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const ICONOS = {};
  const plantilla = document.getElementById('rl-iconos');
  ((plantilla && plantilla.content) || document).querySelectorAll('[data-i]').forEach((x) => { ICONOS[x.dataset.i] = x.innerHTML; });
  const ico = (n, px) => {
    const s = ICONOS[n] || '';
    return px ? s.replace(/width="18" height="18" style="width:18px;height:18px"/, 'width="' + px + '" height="' + px + '" style="width:' + px + 'px;height:' + px + 'px"') : s;
  };
  const CHECK = '<svg class="mc-draw-check" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  const fCorta = (f) => (/^\d{4}-\d{2}-\d{2}/.test(f || '') ? f.slice(8, 10) + '/' + f.slice(5, 7) : '');
  const fLarga = (f) => (/^\d{4}-\d{2}-\d{2}/.test(f || '') ? f.slice(8, 10) + '/' + f.slice(5, 7) + '/' + f.slice(0, 4) : '');
  const leerJSON = (el) => { try { return el ? JSON.parse(el.textContent || el.value || 'null') : null; } catch (e) { return null; } };
  const guardarLocal = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } };
  const leerLocal = (k, def) => { try { const v = localStorage.getItem(k); return v == null ? def : JSON.parse(v); } catch (e) { return def; } };
  const mejorar = (cont) => { if (typeof MecaDate !== 'undefined' && MecaDate.init) MecaDate.init(cont); if (window.DS && DS.mejorar) DS.mejorar(cont); };

  /** POST a actions.php con fetch. Devuelve el JSON si ok; si no, lanza el error del servidor. */
  async function post(accion, datos) {
    const fd = datos instanceof FormData ? datos : new FormData();
    if (!(datos instanceof FormData)) {
      Object.entries(datos || {}).forEach(([k, v]) => {
        if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x));
        else if (v != null) fd.append(k, v);
      });
    }
    fd.set('accion', accion);
    fd.set('ajax', '1');
    let r;
    try {
      r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
    } catch (e) {
      throw new Error('No hay conexión con el panel. Revisa tu internet y prueba otra vez.');
    }
    let j = null;
    try { j = await r.json(); } catch (e) { /* no era JSON */ }
    if (!j) {
      const err = new Error(r.status === 413 ? 'El archivo pesa más de lo que acepta el servidor.' : 'El panel respondió algo inesperado (' + r.status + ').');
      err.status = r.status;
      throw err;
    }
    if (!j.ok) {
      const err = new Error(j.error || j.mensaje || 'No se pudo hacer.');
      err.status = r.status;
      err.datos = j;
      throw err;
    }
    return j;
  }

  /** El botón muestra el giro del sistema mientras dura la llamada. */
  async function ocupado(btn, fn) {
    if (!btn) return fn();
    if (btn.dataset.rlOcupado) return undefined;
    btn.dataset.rlOcupado = '1';
    btn.classList.add('is-loading');
    btn.setAttribute('aria-busy', 'true');
    const antes = btn.disabled;
    btn.disabled = true;
    const svg = btn.querySelector('svg');
    const giro = document.createElement('span');
    giro.className = 'mc-spinner';
    if (svg) { svg.hidden = true; svg.before(giro); } else btn.prepend(giro);
    try { return await fn(); }
    finally {
      giro.remove();
      if (svg) svg.hidden = false;
      btn.disabled = antes;
      btn.classList.remove('is-loading');
      btn.removeAttribute('aria-busy');
      delete btn.dataset.rlOcupado;
    }
  }

  const copiar = async (texto) => {
    try { await navigator.clipboard.writeText(texto); toast('Copiado.', 'success'); } catch (e) { toast('No se pudo copiar: selecciona el texto y usa Ctrl+C.', 'error'); }
  };

  /* ---------- La carga de la IA (como ia.js: Nexo pensando, el mensaje que cambia y el esqueleto) ---------- */
  const skel = (ancho, alto) => '<span class="ia-skel" style="width:' + ancho + ';height:' + (alto || 10) + 'px"></span>';
  const ESQUELETOS = {
    resumen: '<div class="ia-skel-card ia-skel-card--info">' + skel('46%', 14) + skel('96%') + skel('82%') + '</div>'
      + '<div class="ia-skel-fila">' + [1, 2, 3].map(() => '<div class="ia-skel-card" style="flex:1">' + skel('40%', 12) + skel('100%') + skel('76%') + '</div>').join('') + '</div>',
    equipo: '<div class="ia-skel-card ia-skel-card--info">' + skel('38%', 12) + skel('94%') + skel('70%') + '</div>'
      + [1, 2].map(() => '<div class="ia-skel-card">' + skel('52%', 14) + skel('100%') + '<div class="ia-skel-fila">' + skel('22%', 28) + skel('22%', 28) + skel('16%', 28) + '</div></div>').join(''),
  };
  function mostrarCarga(cont, forma, mensajes) {
    const el = document.createElement('div');
    el.className = 'ia-carga';
    el.setAttribute('role', 'status');
    el.innerHTML = '<div class="ia-carga__cab"><img class="ia-masc nexo" src="iconos/nexo/pensando.webp" alt="" width="104" height="104" decoding="async">'
      + '<div class="ia-carga__txt"><b class="ia-carga__msg">' + esc(mensajes[0]) + '</b>'
      + '<span class="ia-carga__sub">Suele tardar unos segundos<span class="ia-puntos" aria-hidden="true"><i></i><i></i><i></i></span></span></div></div>'
      + '<div class="ia-carga__skel" aria-hidden="true">' + (ESQUELETOS[forma] || '') + '</div>';
    cont.prepend(el);
    const msg = el.querySelector('.ia-carga__msg');
    let i = 0;
    const reloj = setInterval(() => {
      i = (i + 1) % mensajes.length;
      msg.classList.remove('is-entra');
      void msg.offsetWidth;
      msg.textContent = mensajes[i];
      msg.classList.add('is-entra');
    }, 2400);
    return () => { clearInterval(reloj); el.remove(); };
  }
  new Image().src = 'iconos/nexo/pensando.webp';

  /** Un error de la IA, bonito: qué pasó y qué hacer. */
  function errorIA(err) {
    const sinIA = err && (err.status === 503 || err.status === 403);
    return '<div class="mc-alert mc-alert--warning rl-ia-error" role="alert">' + ico('alerta', 20)
      + '<div class="mc-alert__content"><p class="mc-alert__title">' + (sinIA ? 'La IA no está disponible ahora' : 'La IA no pudo responder') + '</p>'
      + '<div class="mc-alert__desc">' + esc(err && err.message ? err.message : 'Prueba otra vez en un momento.')
      + (sinIA && D.admin ? ' <a class="mc-alert__link" href="ia.php">Revisa la Consola de IA</a>' : '') + '</div></div></div>';
  }
  const firma = (r) => (r && r.modelo ? '<p class="rl-ia-firma">' + ico('ia', 14) + ' ' + esc(r.modelo) + (r.costo != null ? ' · US$ ' + Number(r.costo).toFixed(4) : '') + ' · Es un borrador: revísalo.</p>' : '');
  const lista = (xs, cls) => (xs && xs.length ? '<ul class="rl-ia-lista' + (cls ? ' ' + cls : '') + '">' + xs.map((x) => '<li>' + esc(typeof x === 'string' ? x : (x.texto || x.mensaje || JSON.stringify(x))) + '</li>').join('') + '</ul>' : '');
  const cabIA = (titulo, sub, extra) => '<header class="rl-ia-res__cab"><img class="ia-masc ia-masc--mini nexo" src="iconos/nexo/idea.webp" alt="" width="52" height="52">'
    + '<div><h3 class="rl-card__tit">' + esc(titulo) + '</h3>' + (sub ? '<p class="rl-card__sub">' + esc(sub) + '</p>' : '') + '</div>'
    + '<div class="rl-ia-res__acc">' + (extra || '') + '<button type="button" class="mc-iconbtn mc-iconbtn--sm" data-rl-ia-cerrar aria-label="Cerrar" data-ds-tip="Cerrar">' + ico('cerrar', 16) + '</button></div></header>';

  /* =========================================================
     Panel lateral del ítem
     ========================================================= */
  let cajon = null;
  let cajonCambio = false;
  let cajonId = 0;
  const SKEL_ITEM = '<div class="rl-cajon__skel">' + skel('30%', 22) + skel('80%', 24) + skel('46%', 14)
    + '<div class="ia-skel-card">' + skel('40%', 30) + skel('100%', 12) + '</div><div class="ia-skel-card">' + skel('60%', 12) + skel('94%') + skel('88%') + '</div></div>';
  function cajonListo() {
    if (cajon) return cajon;
    cajon = document.createElement('dialog');
    cajon.className = 'rl-cajon';
    cajon.setAttribute('aria-label', 'Detalle del ítem');
    cajon.innerHTML = '<div class="rl-cajon__barra"><span class="rl-cajon__tit">' + ico('documento', 18) + ' Detalle del ítem</span>'
      + '<button type="button" class="mc-iconbtn" data-cerrar-dlg aria-label="Cerrar el detalle">' + ico('cerrar', 20) + '</button></div>'
      + '<div class="rl-cajon__cuerpo" data-rl-cajon-cuerpo></div>';
    document.body.appendChild(cajon);
    cajon.addEventListener('click', (e) => { if (e.target === cajon) cajon.close(); });
    cajon.addEventListener('close', () => {
      document.documentElement.classList.remove('rl-sin-scroll');
      if (cajonCambio) { cajonCambio = false; location.reload(); }
    });
    return cajon;
  }
  async function cargarItem(id) {
    const c = cajonListo();
    const cuerpo = c.querySelector('[data-rl-cajon-cuerpo]');
    cajonId = id;
    cuerpo.innerHTML = SKEL_ITEM;
    try {
      const r = await fetch('releases.php?v=item&parcial=1&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const html = await r.text();
      if (cajonId !== id) return;
      cuerpo.innerHTML = html;
      cuerpo.scrollTop = 0;
      mejorar(cuerpo);
    } catch (e) {
      cuerpo.innerHTML = '<div class="mc-alert mc-alert--danger" role="alert">' + ico('error', 20) + '<div class="mc-alert__content"><p class="mc-alert__title">No se pudo abrir el ítem</p>'
        + '<div class="mc-alert__desc">Revisa tu conexión. <a class="mc-alert__link" href="releases.php?v=item&id=' + encodeURIComponent(id) + '">Abrirlo en su página</a></div></div></div>';
    }
  }
  function abrirItem(id) {
    const c = cajonListo();
    if (!c.open) { c.showModal(); document.documentElement.classList.add('rl-sin-scroll'); }
    cargarItem(id);
  }
  /** Después de guardar algo del ítem: si está en el panel lateral, se vuelve a pedir; en su página, se recarga. */
  function refrescarItem(desde) {
    if (cajon && cajon.open && cajon.contains(desde)) { cajonCambio = true; cargarItem(cajonId); } else location.reload();
  }

  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-rl-item]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (e.target.closest('button, input, label, select, textarea, [data-ds-select]') && !e.target.closest('a[data-rl-item]')) return;
    e.preventDefault();
    abrirItem(a.dataset.rlItem);
  });
  document.addEventListener('keydown', (e) => {
    const tr = e.target;
    if ((e.key === 'Enter' || e.key === ' ') && tr instanceof HTMLElement && tr.matches('tr[data-rl-item]')) { e.preventDefault(); abrirItem(tr.dataset.rlItem); }
  });

  /* ---------- Guardar algo del ítem: solo lo que cambió ---------- */
  document.addEventListener('submit', async (e) => {
    const f = e.target.closest('form[data-rl-item-form]');
    if (!f) return;
    e.preventDefault();
    const fd = new FormData();
    let cambios = 0;
    [...f.elements].forEach((el) => {
      if (!el.name || el.disabled || ['accion', 'volver'].includes(el.name)) return;
      if ('siempre' in el.dataset) { fd.set(el.name, el.value); return; }
      if (el.type === 'radio') { if (el.checked && el.dataset.inicial !== '1') { fd.set(el.name, el.value); cambios++; } return; }
      if (el.type === 'checkbox') {
        const ini = el.dataset.inicial !== undefined ? el.dataset.inicial === '1' : el.defaultChecked;
        if (el.checked !== ini) { fd.set(el.name, el.checked ? el.value : (el.dataset.apagado || 'tareas')); cambios++; }
        return;
      }
      if (el.name === 'motivo') { if (el.value.trim() !== '') fd.set('motivo', el.value.trim()); return; }
      const ini = el.dataset.inicial !== undefined ? el.dataset.inicial : (el.defaultValue !== undefined ? el.defaultValue : '');
      if (el.value !== ini) { fd.set(el.name, el.value); cambios++; }
    });
    const enBloqueo = f.hasAttribute('data-rl-bloqueo');
    if (!cambios && !enBloqueo) { toast('No hay cambios para guardar.', 'info'); return; }
    if (enBloqueo && !(fd.get('motivo') || '').trim()) { toast('Escribe el motivo: queda en el historial.', 'warning'); f.querySelector('[name="motivo"]').focus(); return; }
    const delicado = ['inicio', 'fin', 'equipo', 'release'].some((k) => fd.has(k));
    if (delicado && !fd.get('motivo')) {
      const si = await confirmar({ titulo: '¿Guardar sin motivo?', mensaje: 'Cambiaste fechas, equipo o release. El motivo queda en el historial y ayuda a entender el reajuste.', ok: 'Guardar igual', peligro: false });
      if (!si) { const m = f.querySelector('[name="motivo"]'); if (m) m.focus(); return; }
    }
    const btn = f.querySelector('[type="submit"]');
    try {
      const j = await ocupado(btn, () => post('rel_item_guardar', fd));
      if (!j) return;
      toast(j.mensaje || 'Ítem actualizado.');
      refrescarItem(f);
    } catch (err) { toast(err.message, 'error'); }
  });

  // Bloquear / desbloquear: abre el formulario con el motivo
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rl-bloqueo-abrir]');
    if (b) {
      const f = b.closest('.rl-sm').querySelector('[data-rl-bloqueo]');
      f.hidden = !f.hidden;
      if (!f.hidden) f.querySelector('[name="motivo"]').focus();
      return;
    }
    const c = e.target.closest('[data-rl-bloqueo-cerrar]');
    if (c) c.closest('[data-rl-bloqueo]').hidden = true;
  });

  // Ajuste manual: el estado sugiere el % típico, el deslizador se ve en vivo
  document.addEventListener('change', (e) => {
    const r = e.target;
    if (!(r instanceof HTMLInputElement) || r.name !== 'estado' || r.type !== 'radio') return;
    const f = r.closest('form');
    const rango = f && f.querySelector('[data-rl-rango]');
    if (!rango) return;
    const tip = r.dataset.tipico;
    if (r.value === 'cerrado') rango.value = 100;
    else if (tip !== '' && +rango.value < +tip) rango.value = tip;
    pintarRango(rango);
  });
  const pintarRango = (rango) => {
    rango.style.setProperty('--v', rango.value + '%');
    const o = rango.parentElement.querySelector('[data-rl-rango-val]');
    if (o) o.textContent = rango.value + ' %';
  };
  document.addEventListener('input', (e) => { if (e.target.matches && e.target.matches('[data-rl-rango]')) pintarRango(e.target); });

  /* ---------- Tareas del ítem: vincular, quitar, crear ---------- */
  const raizItem = (el) => el.closest('[data-rl-item-raiz]');
  const datosItem = (el) => leerJSON({ textContent: (raizItem(el) || {}).dataset ? raizItem(el).dataset.rlItemDatos : 'null' });

  document.addEventListener('click', (e) => {
    const ab = e.target.closest('[data-rl-vincular-abrir]');
    if (ab) {
      const panel = ab.closest('[data-rl-tareas-bloque]').querySelector('[data-rl-vincular]');
      panel.hidden = !panel.hidden;
      ab.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      if (!panel.hidden) panel.querySelector('[data-rl-vincular-q]').focus();
      return;
    }
    const ce = e.target.closest('[data-rl-vincular-cerrar]');
    if (ce) { ce.closest('[data-rl-vincular]').hidden = true; return; }
  });
  document.addEventListener('input', (e) => {
    const q = e.target.closest && e.target.closest('[data-rl-vincular-q]');
    if (!q) return;
    const t = plano(q.value.trim());
    q.closest('[data-rl-vincular]').querySelectorAll('.rl-vincular__op').forEach((op) => { op.hidden = t !== '' && !plano(op.dataset.buscar).includes(t); });
  });
  document.addEventListener('change', (e) => {
    const c = e.target;
    if (!(c instanceof HTMLInputElement) || !c.closest('.rl-vincular__op')) return;
    c.closest('.rl-vincular__op').classList.toggle('is-on', c.checked);
    const panel = c.closest('[data-rl-vincular]');
    const n = panel.querySelectorAll('.rl-vincular__op input:checked').length;
    panel.querySelector('[data-rl-vincular-n]').textContent = n + (n === 1 ? ' elegida' : ' elegidas');
    panel.querySelector('[data-rl-vincular-ok]').disabled = n === 0;
  });
  document.addEventListener('click', async (e) => {
    const ok = e.target.closest('[data-rl-vincular-ok]');
    if (!ok) return;
    const panel = ok.closest('[data-rl-vincular]');
    const ids = [...panel.querySelectorAll('.rl-vincular__op input:checked')].map((c) => c.value);
    const it = raizItem(ok);
    try {
      const j = await ocupado(ok, () => post('rel_item_tareas', { id: it.dataset.rlItemRaiz, tareas: ids, sumar: 1 }));
      if (!j) return;
      toast(j.mensaje || 'Tareas vinculadas.');
      refrescarItem(ok);
    } catch (err) { toast(err.message, 'error'); }
  });
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rl-desvincular]');
    if (!b) return;
    const bloque = b.closest('[data-rl-tareas-bloque]');
    const it = raizItem(b);
    const actuales = (leerJSON({ textContent: bloque.dataset.rlTareas }) || []).map(String);
    const quitar = b.dataset.rlDesvincular;
    try {
      const j = await ocupado(b, () => post('rel_item_tareas', { id: it.dataset.rlItemRaiz, tareas: actuales.filter((x) => x !== quitar).length ? actuales.filter((x) => x !== quitar) : [''] }));
      if (!j) return;
      const li = b.closest('li');
      if (li) li.classList.add('is-saliendo');
      toast('Tarea #' + quitar + ' quitada del ítem.', 'success', {
        desc: 'Si la había puesto la IA, ya no la volverá a poner.',
        accion: { texto: 'Deshacer', fn: async () => { try { await post('rel_item_tareas', { id: it.dataset.rlItemRaiz, tareas: [quitar], sumar: 1 }); refrescarItem(bloque); } catch (err) { toast(err.message, 'error'); } } },
      });
      setTimeout(() => refrescarItem(bloque), 350);
    } catch (err) { toast(err.message, 'error'); }
  });

  /* ---------- Crear una tarea para un ítem (las fechas del release no la limitan) ---------- */
  function dlgTarea(item, sug, alCrear) {
    sug = sug || {};
    const ini = sug.fecha_inicio || item.inicio || '';
    const fin = sug.fecha_limite || item.fin || '';
    const proys = item.proyectos || [];
    const pidSug = String(sug.proyecto_id || (proys.find((p) => p.equipo) || proys[0] || {}).id || '');
    const delEq = new Set((item.miembros || []).map(Number));
    const gente = (D.miembros || []).slice().sort((a, b) => (delEq.has(b.id) - delEq.has(a.id)) || a.nombre.localeCompare(b.nombre, 'es'));
    const opP = (eq) => proys.filter((p) => !!p.equipo === eq).map((p) => '<option value="' + p.id + '"' + (String(p.id) === pidSug ? ' selected' : '') + '>' + esc(p.nombre) + '</option>').join('');
    const prios = D.prioridades || { baja: 'Baja', media: 'Media', alta: 'Alta' };
    const dlg = document.createElement('dialog');
    dlg.className = 'dlg-meca rl-dlg rl-dlg--tarea';
    dlg.innerHTML = '<form class="dlg-form" novalidate>'
      + '<header><h3 class="font-display"><i class="fa-solid fa-list-check"></i> Crear tarea para ' + esc(item.codigo) + '<small>' + esc(item.titulo) + '</small></h3>'
      + '<button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>'
      + '<div class="mc-field"><label class="mc-field__label">Proyecto</label><select name="proyecto_id" data-ds-select data-ds-buscar required>'
      + (proys.some((p) => p.equipo) ? '<optgroup label="Del equipo">' + opP(true) + '</optgroup>' : '')
      + (proys.some((p) => !p.equipo) ? '<optgroup label="Otros proyectos">' + opP(false) + '</optgroup>' : '') + '</select></div>'
      + '<div class="mc-field"><label class="mc-field__label">Título</label><div class="mc-input"><input class="mc-input__control" name="titulo" maxlength="250" required value="' + esc(sug.titulo || '') + '" placeholder="Qué hay que hacer"></div></div>'
      + '<div class="mc-field"><label class="mc-field__label">Descripción</label><div class="mc-input mc-input--multiline"><textarea class="mc-input__control rl-textarea" name="descripcion" rows="3" placeholder="Detalle, criterio de aceptación…">' + esc(sug.descripcion || '') + '</textarea></div></div>'
      + '<div class="rl-form-grid rl-form-grid--3">'
      + '<div class="mc-field"><label class="mc-field__label">Inicio</label><input class="input-meca" type="date" name="fecha_inicio" value="' + esc(ini) + '"></div>'
      + '<div class="mc-field"><label class="mc-field__label">Fecha límite</label><input class="input-meca" type="date" name="fecha_limite" value="' + esc(fin) + '"></div>'
      + '<div class="mc-field"><label class="mc-field__label">Prioridad</label><select name="prioridad" data-ds-select>' + Object.entries(prios).map(([k, v]) => '<option value="' + esc(k) + '"' + (k === 'media' ? ' selected' : '') + '>' + esc(v) + '</option>').join('') + '</select></div>'
      + '</div>'
      + '<p class="rl-dlg__nota" data-rl-tarea-aviso>' + ico('info', 15) + ' <span>Las fechas vienen del ítem (' + esc(fCorta(item.inicio) || '—') + ' → ' + esc(fCorta(item.fin) || '—') + '), pero puedes cambiarlas: el release no las limita.</span></p>'
      + '<div class="mc-field"><label class="mc-field__label">Responsables</label><div class="rl-multi" data-rl-multi>'
      + '<div class="mc-input mc-input--sm rl-multi__buscar">' + ico('buscar', 16).replace('mc-icon', 'mc-icon mc-input__icon') + '<input class="mc-input__control" type="search" placeholder="Buscar persona…" data-rl-multi-q aria-label="Buscar persona"></div>'
      + '<div class="rl-multi__lista">' + gente.map((m) => '<label class="rl-multi__op" data-buscar="' + esc(plano(m.nombre)) + '"><input type="checkbox" name="asignados[]" value="' + m.id + '">'
        + '<span class="rl-multi__caja" aria-hidden="true">' + CHECK + '</span><span class="rl-multi__txt"><b>' + esc(m.nombre) + '</b>' + (delEq.has(m.id) ? '<small>Del equipo</small>' : '') + '</span></label>').join('') + '</div>'
      + '<p class="rl-multi__cuenta" data-rl-multi-n>0 elegido(s)</p></div></div>'
      + '<footer><button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button>'
      + '<button type="submit" class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-plus"></i> Crear y vincular</button></footer></form>';
    document.body.appendChild(dlg);
    dlg.addEventListener('close', () => dlg.remove());
    mejorar(dlg);
    const form = dlg.querySelector('form');
    const aviso = dlg.querySelector('[data-rl-tarea-aviso]');
    const revisar = () => {
      const fl = form.querySelector('[name="fecha_limite"]').value;
      const fuera = fl && item.fin && fl > item.fin;
      aviso.classList.toggle('is-aviso', !!fuera);
      aviso.querySelector('span').textContent = fuera
        ? 'Termina el ' + fLarga(fl) + ', después del fin del ítem (' + fLarga(item.fin) + '). Se puede: solo es un aviso.'
        : 'Las fechas vienen del ítem (' + (fCorta(item.inicio) || '—') + ' → ' + (fCorta(item.fin) || '—') + '), pero puedes cambiarlas: el release no las limita.';
    };
    form.addEventListener('change', revisar);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(form);
      if (!String(fd.get('titulo') || '').trim()) { toast('Escribe el título de la tarea.', 'warning'); form.querySelector('[name="titulo"]').focus(); return; }
      fd.set('item_id', item.id);
      const btn = form.querySelector('[type="submit"]');
      try {
        const j = await ocupado(btn, () => post('rel_tarea_crear', fd));
        if (!j) return;
        toast(j.mensaje || 'Tarea creada.');
        dlg.close();
        if (alCrear) alCrear(j);
      } catch (err) { toast(err.message, 'error'); }
    });
    dlg.showModal();
    setTimeout(() => form.querySelector('[name="titulo"]').focus(), 60);
    return dlg;
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rl-crear-tarea]');
    if (!b) return;
    const it = datosItem(b);
    if (it) dlgTarea(it, null, () => refrescarItem(b));
  });

  /* =========================================================
     Tooltips (Gantt, hoja de ruta, matriz, filas)
     ========================================================= */
  const tip = document.createElement('div');
  tip.className = 'rl-tip';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);
  let tipEl = null;
  function tipHtml(d) {
    if (d.h) {
      return '<span class="rl-tip__cab"><b>' + esc(d.h) + '</b>' + (d.ok ? '<em class="rl-s--cerrado">Cumplido</em>' : '<em class="rl-s--futuro">Pendiente</em>') + '</span>'
        + '<span class="rl-tip__fila">' + esc(d.f) + (d.r ? ' · ' + esc(d.r) : '') + '</span>' + (d.x ? '<span class="rl-tip__txt">' + esc(d.x) + '</span>' : '');
    }
    let h = '<span class="rl-tip__cab"><b>' + esc(d.c) + '</b>' + (d.sa ? '<em class="rl-s--' + esc(d.s) + '">' + esc(d.sa) + '</em>' : '') + '</span>';
    if (d.t) h += '<span class="rl-tip__tit">' + esc(d.t) + '</span>';
    if (d.f) h += '<span class="rl-tip__fila">' + ico('agenda', 13) + ' ' + esc(d.f) + '</span>';
    if (d.a != null) {
      h += '<span class="rl-tip__barra rl-s--' + esc(d.s || 'al_dia') + '"><i style="width:' + (+d.a) + '%"></i>' + (d.e > 0 ? '<u style="left:' + (+d.e) + '%"></u>' : '') + '</span>'
        + '<span class="rl-tip__fila"><b>' + (+d.a) + ' %</b> real · ' + (+d.e) + ' % esperado hoy</span>';
    }
    if (d.es) h += '<span class="rl-tip__fila">' + esc(d.es) + (d.d ? ' · ' + esc(d.d) : '') + (d.eq ? ' · ' + esc(d.eq) : '') + '</span>';
    if (d.k != null) h += '<span class="rl-tip__fila">' + (+d.k) + ' de ' + (+d.n) + ' ítems terminados' + (d.z ? ' · ' + d.z + ' atrasado(s)' : '') + (d.y ? ' · ' + d.y + ' en riesgo' : '') + '</span>';
    else if (d.n != null) h += '<span class="rl-tip__fila">' + (+d.n ? (+d.n) + ' tarea(s) vinculada(s)' : 'Sin tareas vinculadas') + '</span>';
    if (d.o) h += '<span class="rl-tip__fila rl-tip__orig">' + esc(d.o) + '</span>';
    return h;
  }
  function ponerTip(el, x, y) {
    let d = null;
    try { d = JSON.parse(el.dataset.rlTip); } catch (e) { return; }
    if (!d) return;
    tipEl = el;
    tip.innerHTML = tipHtml(d);
    tip.classList.add('is-visible');
    moverTip(x, y);
  }
  function moverTip(x, y) {
    const w = tip.offsetWidth, h = tip.offsetHeight;
    let l = x + 14, t = y + 16;
    if (l + w > innerWidth - 8) l = Math.max(8, x - w - 14);
    // En el Gantt nunca encima de la columna de nombres
    const lbl = tipEl && tipEl.matches('.rl-gfila') && tipEl.querySelector('.rl-gfila__lbl');
    if (lbl) l = Math.min(Math.max(l, lbl.getBoundingClientRect().right + 8), Math.max(8, innerWidth - w - 8));
    if (t + h > innerHeight - 8) t = Math.max(8, y - h - 12);
    tip.style.left = l + 'px';
    tip.style.top = t + 'px';
  }
  const quitarTip = () => { tipEl = null; tip.classList.remove('is-visible'); };
  document.addEventListener('pointerover', (e) => {
    if (e.pointerType && e.pointerType !== 'mouse') return;
    const el = e.target.closest && e.target.closest('[data-rl-tip]');
    // En el Gantt el nombre del ítem se lee sin que el globo lo tape: sale solo sobre la barra
    if (el && e.target.closest('.rl-gfila__lbl')) { quitarTip(); return; }
    if (el === tipEl) return;
    if (!el) { quitarTip(); return; }
    ponerTip(el, e.clientX, e.clientY);
  });
  document.addEventListener('pointermove', (e) => { if (tipEl && e.pointerType === 'mouse') moverTip(e.clientX, e.clientY); });
  document.addEventListener('focusin', (e) => {
    const el = e.target.closest && e.target.closest('[data-rl-tip]');
    if (el && el.matches(':focus-visible')) { const r = el.getBoundingClientRect(); ponerTip(el, r.left + 10, r.bottom); } else quitarTip();
  });
  ['scroll', 'pointerdown', 'resize'].forEach((ev) => addEventListener(ev, quitarTip, true));

  /* =========================================================
     Release: Gantt / lista
     ========================================================= */
  const modos = document.querySelectorAll('[data-rl-modo]');
  if (modos.length) {
    const poner = (m, guardar) => {
      modos.forEach((b) => { const on = b.dataset.rlModo === m; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
      document.querySelectorAll('[data-rl-modo-panel]').forEach((p) => { p.hidden = p.dataset.rlModoPanel !== m; });
      if (guardar) guardarLocal('rl-modo', m);
    };
    poner(leerLocal('rl-modo', innerWidth < 700 ? 'lista' : 'gantt'), false);
    modos.forEach((b) => b.addEventListener('click', () => poner(b.dataset.rlModo, true)));
  }

  /* ---------- Hoja de ruta y Gantt: si no caben, arrancan mostrando «hoy» ---------- */
  document.querySelectorAll('.rl-ruta-scroll, .rl-gantt-scroll').forEach((sc) => {
    if (sc.scrollWidth <= sc.clientWidth + 4) return;
    const hoy = sc.querySelector('.rl-hoy');
    if (!hoy) return;
    const x = hoy.getBoundingClientRect().left - sc.getBoundingClientRect().left + sc.scrollLeft;
    sc.scrollLeft = Math.max(0, x - sc.clientWidth * 0.45);
  });

  /* =========================================================
     Tablero: personalizar qué secciones se ven
     ========================================================= */
  const btnPers = document.querySelector('[data-rl-personalizar]');
  const popPers = document.querySelector('[data-rl-personalizar-pop]');
  if (btnPers && popPers) {
    document.body.appendChild(popPers);
    const ocultos = new Set(leerLocal('rl-tablero-ocultos', []));
    const aplicar = () => document.querySelectorAll('[data-rl-sec]').forEach((s) => { s.hidden = ocultos.has(s.dataset.rlSec); });
    popPers.querySelectorAll('input[type="checkbox"]').forEach((c) => {
      const k = c.name.replace('rl-sec-', '');
      if (ocultos.has(k)) { c.checked = false; c.dispatchEvent(new Event('change', { bubbles: true })); }
      c.addEventListener('change', () => { if (c.checked) ocultos.delete(k); else ocultos.add(k); guardarLocal('rl-tablero-ocultos', [...ocultos]); aplicar(); });
    });
    aplicar();
    const cerrar = () => { popPers.hidden = true; btnPers.setAttribute('aria-expanded', 'false'); };
    btnPers.addEventListener('click', () => {
      if (!popPers.hidden) { cerrar(); return; }
      popPers.hidden = false;
      btnPers.setAttribute('aria-expanded', 'true');
      const r = btnPers.getBoundingClientRect();
      popPers.style.top = (r.bottom + 8) + 'px';
      popPers.style.left = Math.max(8, Math.min(r.right - popPers.offsetWidth, innerWidth - popPers.offsetWidth - 8)) + 'px';
    });
    document.addEventListener('mousedown', (e) => { if (!popPers.hidden && !popPers.contains(e.target) && !btnPers.contains(e.target)) cerrar(); });
    addEventListener('scroll', cerrar, { passive: true });
  }

  /* =========================================================
     IA: resumen general (tablero, release o equipo)
     ========================================================= */
  const ESTADO_TXT = { al_dia: 'Al día', riesgo: 'En riesgo', atrasado: 'Atrasado', vencido: 'Vencido', bloqueado: 'Bloqueado', futuro: 'Aún no inicia', cerrado: 'Terminado' };
  function pintarGeneral(r, ctx, j) {
    const porEq = (r.por_equipo || []).map((x) => {
      const k = plano(x.estado || '').replace(/\s+/g, '_').replace('en_riesgo', 'riesgo').replace('al_dia', 'al_dia');
      const s = ESTADO_TXT[k] ? k : (/atras|venc|bloq|crit/.test(k) ? 'atrasado' : /riesg|aten/.test(k) ? 'riesgo' : 'al_dia');
      return '<article class="rl-ia-eq rl-s--' + s + '"><span class="rl-ia-eq__cab"><b>' + esc(x.equipo) + '</b><em>' + esc(ESTADO_TXT[k] || x.estado || '') + '</em></span><p>' + esc(x.texto) + '</p></article>';
    }).join('');
    return '<section class="mc-card rl-card rl-ia-res">'
      + cabIA('Resumen con IA', ctx, '<button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rl-ia-copiar>' + ico('copiar', 16) + '<span class="mc-btn__label">Copiar</span></button>')
      + (r.titular ? '<p class="rl-ia-res__titular font-display">' + esc(r.titular) + '</p>' : '')
      + (r.resumen ? '<p class="rl-ia-res__resumen">' + esc(r.resumen) + '</p>' : '')
      + (porEq ? '<div class="rl-ia-eqs">' + porEq + '</div>' : '')
      + '<div class="rl-ia-cols">'
      + (r.riesgos && r.riesgos.length ? '<div class="rl-ia-col rl-ia-col--riesgo"><h4>' + ico('alerta', 16) + ' Riesgos</h4>' + lista(r.riesgos) + '</div>' : '')
      + (r.recomendaciones && r.recomendaciones.length ? '<div class="rl-ia-col rl-ia-col--reco"><h4>' + ico('ia', 16) + ' Recomendaciones</h4>' + lista(r.recomendaciones) + '</div>' : '')
      + '</div>' + firma(j) + '</section>';
  }
  const textoGeneral = (r) => [r.titular, r.resumen, ...(r.por_equipo || []).map((x) => x.equipo + ': ' + x.texto),
    r.riesgos && r.riesgos.length ? 'Riesgos:\n- ' + r.riesgos.join('\n- ') : '', r.recomendaciones && r.recomendaciones.length ? 'Recomendaciones:\n- ' + r.recomendaciones.join('\n- ') : ''].filter(Boolean).join('\n\n');
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rl-ia-general]');
    if (!b) return;
    const slot = document.querySelector('[data-rl-ia-slot]');
    if (!slot) return;
    slot.hidden = false;
    slot.innerHTML = '';
    slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const ctx = [b.dataset.release ? 'Release ' + b.dataset.release : '', b.dataset.equipo ? 'Equipo ' + b.dataset.equipo : ''].filter(Boolean).join(' · ') || 'Todo el programa';
    const parar = mostrarCarga(slot, 'resumen', ['Leyendo el avance de cada release…', 'Comparando con lo que el plan espera hoy…', 'Mirando las revisiones registradas…', 'Escribiendo el resumen…']);
    try {
      const j = await ocupado(b, () => post('rel_ia_general', { release: b.dataset.release || '', equipo: b.dataset.equipo || '' }));
      parar();
      if (!j) return;
      slot.innerHTML = pintarGeneral(j.resumen || {}, ctx, j);
      slot.querySelector('[data-rl-ia-copiar]').addEventListener('click', () => copiar(textoGeneral(j.resumen || {})));
    } catch (err) {
      parar();
      slot.innerHTML = errorIA(err);
    }
  });
  document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-rl-ia-cerrar]');
    if (!c) return;
    const slot = c.closest('[data-rl-ia-slot], [data-rl-resumen]');
    if (slot) { slot.innerHTML = ''; if (slot.matches('[data-rl-ia-slot]')) slot.hidden = true; }
  });

  /* =========================================================
     IA: asociar las tareas de un equipo a sus ítems
     ========================================================= */
  const cajaEq = document.querySelector('[data-rl-iaeq]');
  if (cajaEq) {
    const ctx = leerJSON(cajaEq.querySelector('[data-rl-analisis-datos]')) || {};
    const cuerpo = cajaEq.querySelector('[data-rl-analisis]');
    const tarea = (id) => (ctx.tareas || {})[id];
    const linkItem = (cod) => { const it = (ctx.items || {})[cod]; return it ? '<a href="releases.php?v=item&id=' + it.id + '" data-rl-item="' + it.id + '" class="rl-code-link">' + esc(cod) + '</a>' : esc(cod || ''); };
    const COB = { completa: ['Completa', 'verde'], parcial: ['Parcial', 'amarillo'], sin_tareas: ['Sin tareas', 'rojo'] };
    const TIPO = { fecha: 'Fecha', sin_tareas: 'Sin tareas', fuera_de_plan: 'Fuera del plan', riesgo: 'Riesgo' };
    const tono = { verde: 'success', amarillo: 'warning', rojo: 'danger', gris: 'neutral' };
    const chip = (txt, t) => '<span class="mc-status mc-status--sm mc-status--' + (tono[t] || 'neutral') + '"><span class="mc-status__dot"></span>' + esc(txt) + '</span>';
    function pintar(an, recien) {
      const r = an.resultado || {};
      const aplicadas = new Set(an.aplicadas || []);
      const hora = an.fecha ? fLarga(an.fecha) + ' a las ' + String(an.fecha).slice(11, 16) : '';
      let h = '<p class="rl-iaeq__meta">' + ico('reloj', 14) + ' ' + (recien ? 'Recién asociado' : 'Última vez: ' + esc(hora) + (an.quien ? ' · ' + esc(an.quien) : '')) + '</p>';
      if (recien && recien.mensaje) {
        h += '<div class="mc-alert mc-alert--success mc-alert--compact" role="status">' + ico('exito', 18) + '<div class="mc-alert__content"><p class="mc-alert__title">' + esc(recien.mensaje) + '</p>'
          + (recien.vinculadas ? '<button type="button" class="mc-alert__link" data-rl-recargar>Ver el avance al día</button>' : '') + '</div></div>';
      }
      if (r.resumen) h += '<p class="rl-ia-res__resumen">' + esc(r.resumen) + '</p>';
      // Lo vinculado por ítem
      const items = r.items || [];
      if (items.length) {
        h += '<h3 class="rl-subtit">' + ico('relacionar', 16) + ' Lo que asoció a cada ítem</h3><ul class="rl-iaeq__items">';
        items.forEach((x) => {
          const it = (ctx.items || {})[x.codigo] || {};
          const ya = new Set((it.tareas || []).map(Number));
          const quitadas = new Set((it.quitadas || []).map(Number));
          const ids = (x.tareas || []).map(Number);
          const faltan = ids.filter((id) => !ya.has(id) && tarea(id));
          const [lbl, t] = COB[x.cobertura] || [x.cobertura || '—', 'gris'];
          h += '<li class="rl-iaeq__item"><div class="rl-iaeq__fila">' + '<span class="rl-code" style="--rc:var(--primary)">' + linkItem(x.codigo) + '</span>'
            + '<b>' + esc(it.titulo || '') + '</b>' + chip(lbl, t) + '</div>'
            + (x.comentario ? '<p>' + esc(x.comentario) + '</p>' : '')
            + (ids.length ? '<div class="rl-iaeq__tareas">' + ids.map((id) => {
              const tt = tarea(id);
              const est = ya.has(id) ? 'is-ya' : (quitadas.has(id) ? 'is-quitada' : 'is-falta');
              return '<span class="rl-tchip ' + est + '" title="' + esc(ya.has(id) ? 'Vinculada' : quitadas.has(id) ? 'Alguien la quitó a mano: la IA no la vuelve a poner' : 'Sin vincular') + '">'
                + (ya.has(id) ? ico('check', 12) : '') + '#' + id + ' ' + esc(tt ? tt.titulo : '(ya no existe)') + '</span>';
            }).join('') + '</div>' : '')
            + (faltan.length && ctx.puede && !aplicadas.has('items:' + x.codigo)
              ? '<button type="button" class="mc-btn mc-btn--secondary mc-btn--sm" data-rl-ia-vincular="' + esc(x.codigo) + '" data-tareas="' + esc(faltan.join(',')) + '">' + ico('relacionar', 14) + '<span class="mc-btn__label">' + (faltan.length === 1 ? 'Vincular la que falta' : 'Vincular las ' + faltan.length + ' que faltan') + '</span></button>' : '')
            + '</li>';
        });
        h += '</ul>';
      }
      // Alertas por gravedad
      const alertas = (r.alertas || []).slice().sort((a, b) => ['alta', 'media', 'baja'].indexOf(a.gravedad) - ['alta', 'media', 'baja'].indexOf(b.gravedad));
      if (alertas.length) {
        h += '<h3 class="rl-subtit">' + ico('alerta', 16) + ' Alertas</h3><ul class="rl-iaeq__alertas">' + alertas.map((a) => {
          const tt = a.tarea_id ? tarea(a.tarea_id) : null;
          return '<li class="rl-g--' + esc(a.gravedad || 'baja') + '"><span class="rl-g__punto" aria-hidden="true"></span><div><p>' + esc(a.mensaje) + '</p><small>'
            + esc(TIPO[a.tipo] || a.tipo || '') + ' · gravedad ' + esc(a.gravedad || 'baja') + (a.codigo ? ' · ' + linkItem(a.codigo) : '') + (tt ? ' · #' + a.tarea_id + ' ' + esc(tt.titulo) : '') + '</small></div></li>';
        }).join('') + '</ul>';
      }
      // Sugerencias de tareas nuevas
      const sugs = r.sugerencias || [];
      if (sugs.length) {
        h += '<h3 class="rl-subtit">' + ico('mas', 16) + ' Tareas que faltan</h3><div class="rl-iaeq__sugs">' + sugs.map((s, i) => {
          const hecha = aplicadas.has('tarea:' + s.codigo + ':' + i);
          return '<article class="rl-sug"><span class="rl-code" style="--rc:var(--primary)">' + linkItem(s.codigo) + '</span><b>' + esc(s.titulo) + '</b>'
            + (s.descripcion ? '<p>' + esc(s.descripcion) + '</p>' : '')
            + '<small>' + ico('agenda', 13) + ' ' + esc(fCorta(s.fecha_inicio) || '—') + ' → ' + esc(fCorta(s.fecha_limite) || '—') + '</small>'
            + (ctx.puede ? '<button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-rl-ia-sug="' + i + '"' + (hecha ? ' disabled' : '') + '>' + ico('mas', 14) + '<span class="mc-btn__label">' + (hecha ? 'Creada' : 'Crear tarea') + '</span></button>' : '')
            + '</article>';
        }).join('') + '</div>';
      }
      cuerpo.innerHTML = h;
      cuerpo._an = an;
    }
    if (ctx.analisis) pintar(ctx.analisis, null);
    cajaEq.addEventListener('click', async (e) => {
      const ir = e.target.closest('[data-rl-ia-equipo]');
      if (ir) {
        cuerpo.innerHTML = '';
        const parar = mostrarCarga(cuerpo, 'equipo', ['Leyendo las tareas del equipo…', 'Cruzándolas con los ítems del release…', 'Revisando las fechas…', 'Asociando cada tarea a su ítem…']);
        try {
          const j = await ocupado(ir, () => post('rel_ia_equipo', { equipo: ir.dataset.rlIaEquipo }));
          parar();
          if (!j) return;
          // Lo que la IA vinculó ya está en los ítems: se refleja en los chips
          const res = (j.analisis && j.analisis.resultado) || {};
          (res.items || []).forEach((x) => { const it = (ctx.items || {})[x.codigo]; if (it) it.tareas = [...new Set([...(it.tareas || []), ...(x.tareas || []).filter((id) => !(it.quitadas || []).map(Number).includes(Number(id)))])]; });
          pintar({ ...j.analisis, aplicadas: ['auto'] }, { mensaje: j.mensaje, vinculadas: j.vinculadas });
          toast(j.mensaje || 'Listo.');
        } catch (err) {
          parar();
          cuerpo.innerHTML = errorIA(err);
        }
        return;
      }
      const vin = e.target.closest('[data-rl-ia-vincular]');
      if (vin) {
        const an = cuerpo._an || {};
        const ids = vin.dataset.tareas.split(',').filter(Boolean);
        try {
          const j = await ocupado(vin, () => post('rel_ia_aplicar', { analisis_id: an.id || 0, codigo: vin.dataset.rlIaVincular, tareas: ids }));
          if (!j) return;
          toast(j.mensaje || 'Tareas vinculadas.');
          const it = (ctx.items || {})[vin.dataset.rlIaVincular];
          if (it) it.tareas = [...new Set([...(it.tareas || []), ...ids.map(Number)])];
          an.aplicadas = [...(an.aplicadas || []), 'items:' + vin.dataset.rlIaVincular];
          pintar(an, null);
        } catch (err) { toast(err.message, 'error'); }
        return;
      }
      const sg = e.target.closest('[data-rl-ia-sug]');
      if (sg) {
        const an = cuerpo._an || {};
        const s = ((an.resultado || {}).sugerencias || [])[+sg.dataset.rlIaSug];
        const it = (ctx.items || {})[s && s.codigo];
        if (!s || !it) { toast('Ese ítem ya no está en el equipo.', 'warning'); return; }
        dlgTarea({ id: it.id, codigo: s.codigo, titulo: it.titulo, inicio: it.inicio, fin: it.fin, proyectos: ctx.proyectos || [], miembros: ctx.miembrosEq || [] }, s, () => {
          an.aplicadas = [...(an.aplicadas || []), 'tarea:' + s.codigo + ':' + sg.dataset.rlIaSug];
          sg.disabled = true;
          sg.querySelector('.mc-btn__label').textContent = 'Creada';
        });
        return;
      }
      if (e.target.closest('[data-rl-recargar]')) location.reload();
    });
  }

  /* ---------- Tareas abiertas sin ítem: vincular una ---------- */
  document.addEventListener('submit', async (e) => {
    const f = e.target.closest('form[data-rl-vincular-una]');
    if (!f) return;
    e.preventDefault();
    const sel = f.querySelector('select[name="item"]');
    if (!sel || !sel.value) { toast('Elige el ítem al que pertenece la tarea.', 'warning'); return; }
    try {
      const j = await ocupado(f.querySelector('button'), () => post('rel_item_tareas', { id: sel.value, tareas: [f.dataset.rlVincularUna], sumar: 1 }));
      if (!j) return;
      toast(j.mensaje || 'Tarea vinculada.');
      const li = f.closest('li');
      li.classList.add('is-saliendo');
      setTimeout(() => li.remove(), 300);
    } catch (err) { toast(err.message, 'error'); }
  });

  /* =========================================================
     Revisión: registrar fila por fila
     ========================================================= */
  const revForm = document.querySelector('[data-rl-rev-form]');
  if (revForm) {
    const revId = revForm.querySelector('[name="id"]').value;
    const sucio = document.querySelector('[data-rl-rev-sucio]');
    let hayCambios = false;
    const marcar = (si) => { hayCambios = si; if (sucio) sucio.hidden = !si; };
    // Días hábiles de retraso de «real» frente a «plan» (lunes a viernes, sin los feriados del módulo)
    const feriados = new Set(leerJSON({ textContent: revForm.dataset.feriados || '[]' }) || []);
    const diasTarde = (plan, real) => {
      if (real <= plan) return 0;
      let n = 0;
      const d = new Date(plan + 'T12:00:00');
      for (let i = 0; i < 800; i++) {
        d.setDate(d.getDate() + 1);
        const iso = d.toISOString().slice(0, 10);
        if (iso > real) break;
        if (d.getDay() !== 0 && d.getDay() !== 6 && !feriados.has(iso)) n++;
      }
      return n;
    };
    revForm.addEventListener('input', () => marcar(true));
    revForm.addEventListener('change', (e) => {
      marcar(true);
      const r = e.target;
      if (r.type === 'radio' && /\[estado\]$/.test(r.name)) {
        const fila = r.closest('[data-rl-rfila]');
        fila.className = fila.className.replace(/rl-r--[a-z_]+/, 'rl-r--' + r.value);
        const pct = fila.querySelector('input[type="number"]');
        // Cumplido sin % anotado: lo esperado (o 100 si es un cierre)
        if (r.value === 'cumplido' && pct && pct.value === '') {
          const v = fila.hasAttribute('data-cierre') ? '100' : (fila.dataset.esperado || '');
          if (v !== '') { pct.value = v; pct.dispatchEvent(new Event('input', { bubbles: true })); }
        }
        // No cumplido o reprogramado: lo que importa es la observación
        if ((r.value === 'no_cumplido' || r.value === 'reprogramado') && fila.querySelector('textarea')) fila.querySelector('textarea').focus();
        pintarCumple();
      }
    });
    // Acordeón: una fila abierta a la vez (abrir otra cierra la anterior)
    const filasRev = [...revForm.querySelectorAll('[data-rl-rfila]')];
    const alternar = (fila) => {
      const abrir = !fila.classList.contains('is-abierta');
      filasRev.forEach((f) => {
        const si = abrir && f === fila;
        f.classList.toggle('is-abierta', si);
        const b = f.querySelector('[data-rl-abrir-fila]');
        if (b) b.setAttribute('aria-expanded', si ? 'true' : 'false');
      });
      if (abrir) requestAnimationFrame(() => fila.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    };
    filasRev.forEach((fila) => {
      const cab = fila.querySelector('[data-rl-acordeon]');
      if (!cab) return;
      cab.addEventListener('click', (e) => {
        if (e.target.closest('a, [data-rl-quitar]')) return;
        alternar(fila);
      });
    });
    // Cada fila: deslizador ↔ número, atajos de %, frases rápidas, «Ver más» y observaciones que crecen
    revForm.querySelectorAll('[data-rl-rfila]').forEach((fila) => {
      const num = fila.querySelector('input[type="number"]');
      const rango = fila.querySelector('[data-rl-rango]');
      const caja = fila.querySelector('.rl-rango');
      const obs = fila.querySelector('textarea');
      const crecer = () => { if (!obs) return; obs.style.height = 'auto'; obs.style.height = Math.min(obs.scrollHeight + 2, 260) + 'px'; };
      // La pista se llena en azul hasta el % (--v)
      const llenar = () => { if (rango) rango.style.setProperty('--v', (caja.hasAttribute('data-vacio') ? 0 : rango.value) + '%'); };
      if (rango && num) {
        rango.addEventListener('input', () => { num.value = rango.value; caja.removeAttribute('data-vacio'); llenar(); });
        num.addEventListener('input', () => {
          if (num.value === '') { caja.setAttribute('data-vacio', ''); llenar(); return; }
          rango.value = String(Math.max(0, Math.min(100, +num.value)));
          caja.removeAttribute('data-vacio');
          llenar();
        });
        llenar();
      }
      fila.querySelectorAll('[data-rl-pct]').forEach((b) => b.addEventListener('click', () => {
        if (!num) return;
        num.value = b.dataset.rlPct;
        num.dispatchEvent(new Event('input', { bubbles: true }));
      }));
      // Frases rápidas: cada una entra como viñeta en una línea nueva
      fila.querySelectorAll('[data-rl-frase]').forEach((b) => b.addEventListener('click', () => {
        if (!obs) return;
        const lineas = obs.value.replace(/\s+$/, '').split('\n').filter((l, i, a) => l.trim() !== '' || i < a.length - 1);
        if (lineas.length && lineas[lineas.length - 1].trim() === '•') lineas.pop();
        lineas.push('• ' + b.dataset.rlFrase);
        obs.value = lineas.join('\n');
        obs.dispatchEvent(new Event('input', { bubbles: true }));
        crecer();
        obs.focus();
        obs.setSelectionRange(obs.value.length, obs.value.length);
      }));
      // En una lista con viñetas, Enter sigue la lista (y en una viñeta vacía, la corta)
      if (obs) obs.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.shiftKey) return;
        const ini = obs.selectionStart;
        const antes = obs.value.slice(0, ini);
        const linea = antes.slice(antes.lastIndexOf('\n') + 1);
        if (!/^\s*•/.test(linea)) return;
        e.preventDefault();
        if (linea.trim() === '•') {
          obs.value = antes.slice(0, antes.length - linea.length) + obs.value.slice(ini);
          obs.setSelectionRange(ini - linea.length, ini - linea.length);
        } else {
          obs.value = antes + '\n• ' + obs.value.slice(ini);
          obs.setSelectionRange(ini + 3, ini + 3);
        }
        obs.dispatchEvent(new Event('input', { bubbles: true }));
      });
      // Cuándo terminó de verdad, frente al fin del plan (días hábiles, sin feriados), y el porqué si no llegó
      const real = fila.querySelector('[data-rl-fecha-real]');
      const porque = fila.querySelector('[data-rl-porque]');
      const tardeDe = () => (real && real.value && real.dataset.plan ? diasTarde(real.dataset.plan, real.value) : null);
      const pedirPorque = () => {
        if (!porque) return;
        const est = (fila.querySelector('input[type="radio"]:checked') || {}).value || 'pendiente';
        const mot = porque.querySelector('select');
        const txt = porque.querySelector('[data-rl-porque-txt]');
        porque.classList.toggle('is-pide', (tardeDe() > 0 || ['parcial', 'no_cumplido', 'reprogramado'].includes(est)) && !(mot && mot.value));
        const ver = fila.querySelector('[data-rl-porque-ver]');
        if (ver) {
          const t = [mot && mot.value ? mot.options[mot.selectedIndex].text : '', txt ? txt.value.trim() : ''].filter(Boolean).join(' — ');
          ver.hidden = !t;
          ver.querySelector('span').textContent = t;
        }
      };
      const pintarReal = () => {
        if (!real) return;
        const tarde = tardeDe();
        const txt = !real.value ? (real.hasAttribute('data-cerrado') ? 'Falta anotar la fecha' : 'Aún no termina') : (tarde === null ? 'Sin fecha del plan' : (tarde > 0 ? tarde + (tarde === 1 ? ' día hábil' : ' días hábiles') + ' tarde' : 'A tiempo'));
        const tono = !real.value ? '' : (tarde > 0 ? ' is-tarde' : ' is-a-tiempo');
        const valida = fila.querySelector('[data-rl-valida]');
        if (valida) {
          valida.className = 'rl-valida' + tono;
          valida.innerHTML = (real.dataset.plan ? 'El plan decía ' + esc(fCorta(real.dataset.plan)) + ' · ' : '') + '<b>' + esc(txt) + '</b>';
        }
        const celda = fila.querySelector('[data-rl-termino]');
        if (celda) {
          celda.className = 'rl-fechas__c rl-fechas__c--real' + (tono || ' is-vacia');
          celda.querySelector('[data-rl-termino-fecha]').textContent = real.value ? fCorta(real.value) : '—';
          celda.querySelector('[data-rl-termino-valida]').textContent = txt;
        }
        pedirPorque();
      };
      if (real) {
        real.addEventListener('change', pintarReal);
        fila.querySelectorAll('[data-rl-fecha]').forEach((b) => b.addEventListener('click', () => {
          real.value = b.dataset.rlFecha;
          real.dispatchEvent(new Event('md-sync'));   // el selector de fecha del panel repinta su etiqueta
          real.dispatchEvent(new Event('change', { bubbles: true }));
        }));
      }
      if (porque) { porque.addEventListener('input', pedirPorque); porque.addEventListener('change', pedirPorque); }
      fila.addEventListener('change', (e) => { if (e.target.type === 'radio') pedirPorque(); });
      // El resumen de la fila plegada (resultado y %) al día
      const resTxt = fila.querySelector('[data-rl-res-txt]');
      const resPct = fila.querySelector('[data-rl-res-pct]');
      fila.addEventListener('change', (e) => {
        if (e.target.type === 'radio' && resTxt) resTxt.textContent = (e.target.closest('label') || {}).textContent.trim();
      });
      if (num && resPct) num.addEventListener('input', () => { resPct.textContent = num.value === '' ? '' : num.value + ' %'; });
      if (obs) { obs.addEventListener('input', crecer); crecer(); }
      fila.querySelectorAll('[data-rl-recorte]').forEach((p) => {
        if (p.scrollHeight <= p.clientHeight + 2) return;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'rl-vermas';
        b.textContent = 'Ver más';
        b.addEventListener('click', () => { const abierto = p.classList.toggle('is-abierto'); b.textContent = abierto ? 'Ver menos' : 'Ver más'; });
        p.after(b);
      });
    });
    addEventListener('beforeunload', (e) => { if (hayCambios) { e.preventDefault(); e.returnValue = ''; } });
    function pintarCumple() {
      const c = { cumplido: 0, parcial: 0, no_cumplido: 0, reprogramado: 0, no_aplica: 0, pendiente: 0 };
      revForm.querySelectorAll('[data-rl-rfila]').forEach((f) => { const r = f.querySelector('input[type="radio"]:checked'); c[r ? r.value : 'pendiente']++; });
      const base = c.cumplido + c.parcial + c.no_cumplido;
      const tot = Object.values(c).reduce((a, b) => a + b, 0);
      const caja = document.querySelector('[data-rl-rev-cumple]');
      if (!caja) return;
      caja.querySelector('.rl-pila').innerHTML = Object.entries(c).filter(([, n]) => n).map(([k, n]) => '<i class="rl-r--' + k + '" style="flex:' + n + '"></i>').join('');
      caja.querySelector('.rl-hero__pct b').textContent = base ? Math.round(c.cumplido / base * 100) + ' %' : '—';
      caja.querySelector('.rl-hero__pct small').textContent = 'cumplimiento · ' + (tot - c.pendiente) + ' de ' + tot + ' revisados';
    }
    const guardar = async (btn, extra) => {
      const fd = new FormData(revForm);
      fd.delete('agregar_codigo');
      Object.entries(extra || {}).forEach(([k, v]) => (Array.isArray(v) ? v.forEach((x) => fd.append(k + '[]', x)) : fd.set(k, v)));
      return ocupado(btn, () => post('rel_revision_guardar', fd));
    };
    revForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { const j = await guardar(revForm.querySelector('[type="submit"]')); if (j) { marcar(false); toast(j.mensaje || 'Revisión guardada.'); } } catch (err) { toast(err.message, 'error'); }
    });
    revForm.addEventListener('click', async (e) => {
      const reg = e.target.closest('[data-rl-registrar]');
      if (reg) {
        const pend = [...revForm.querySelectorAll('[data-rl-rfila]')].filter((f) => !f.querySelector('input[type="radio"]:checked') || f.querySelector('input[type="radio"]:checked').value === 'pendiente').length;
        const si = await confirmar({ titulo: '¿Registrar la revisión?', ok: 'Sí, registrar', peligro: false,
          mensaje: (pend ? pend + ' ítem(s) siguen «Sin revisar». ' : '') + 'El % real pasa al avance de cada ítem, un «Cumplido» en un cierre lo termina y se avisa al equipo.' });
        if (!si) return;
        try { const j = await guardar(reg, { realizada: 1 }); if (j) { marcar(false); toast(j.mensaje || 'Revisión registrada.'); setTimeout(() => location.reload(), 700); } } catch (err) { toast(err.message, 'error'); }
        return;
      }
      const ag = e.target.closest('[data-rl-agregar]');
      if (ag) {
        const sel = revForm.querySelector('select[name="agregar_codigo"]');
        if (!sel || !sel.value) { toast('Elige el ítem que quieres sumar a la sesión.', 'warning'); return; }
        try { const j = await guardar(ag, { agregar: [sel.value] }); if (j) { marcar(false); toast(sel.value + ' sumado a la sesión.'); location.reload(); } } catch (err) { toast(err.message, 'error'); }
        return;
      }
      const ex = e.target.closest('[data-rl-ext]');
      if (ex) {
        const quitar = ex.dataset.rlExt === 'quitar';
        if (quitar && !(await confirmar({ titulo: '¿Quitar a este invitado?', mensaje: 'Sale de la lista. No se le manda ningún correo.', ok: 'Sí, quitar' }))) return;
        try {
          const j = await ocupado(ex, () => post('rel_revision_externo', { id: revId, op: ex.dataset.rlExt, email: ex.dataset.email }));
          if (j) { toast(j.mensaje || 'Listo.'); if (quitar && !hayCambios) setTimeout(() => location.reload(), 500); else if (quitar) ex.closest('li').remove(); }
        } catch (err) { toast(err.message, 'error'); }
        return;
      }
      const qu = e.target.closest('[data-rl-quitar]');
      if (qu) {
        const si = await confirmar({ titulo: '¿Quitar este ítem de la sesión?', mensaje: 'Se quita de esta revisión. El ítem no cambia.', ok: 'Sí, quitar' });
        if (!si) return;
        try { const j = await guardar(qu, { quitar: [qu.dataset.rlQuitar] }); if (j) { marcar(false); location.reload(); } } catch (err) { toast(err.message, 'error'); }
        return;
      }
      const bo = e.target.closest('[data-rl-rev-borrar]');
      if (bo) {
        const si = await confirmar({ titulo: '¿Borrar la revisión?', mensaje: 'Se borra la sesión con todo lo anotado. Los ítems no cambian.', ok: 'Sí, borrar' });
        if (!si) return;
        try { const j = await ocupado(bo, () => post('rel_revision_borrar', { id: bo.dataset.rlRevBorrar })); if (j) { marcar(false); location.href = 'releases.php?v=revisiones'; } } catch (err) { toast(err.message, 'error'); }
      }
    });

    // Guía: lo marcado se recuerda en este navegador, por revisión
    const clave = 'rl-guia-' + revId;
    const marcados = new Set(leerLocal(clave, []));
    document.querySelectorAll('[data-rl-guia-check]').forEach((c) => {
      c.checked = marcados.has(c.dataset.rlGuiaCheck);
      c.closest('label').classList.toggle('is-on', c.checked);
      c.addEventListener('change', () => {
        if (c.checked) marcados.add(c.dataset.rlGuiaCheck); else marcados.delete(c.dataset.rlGuiaCheck);
        c.closest('label').classList.toggle('is-on', c.checked);
        guardarLocal(clave, [...marcados]);
      });
    });
  }

  /* ---------- IA: resumen de la revisión ---------- */
  const slotRes = document.querySelector('[data-rl-resumen]');
  function pintarResumen(r, j) {
    const comp = (r.compromisos || []).map((c) => '<tr><td>' + esc(c.quien) + '</td><td>' + esc(c.que) + '</td><td class="mc-num">' + esc(c.cuando) + '</td></tr>').join('');
    return '<section class="mc-card rl-card rl-ia-res">'
      + cabIA('Resumen de la revisión', (r.fecha ? 'Hecho el ' + fLarga(r.fecha) + ' a las ' + String(r.fecha).slice(11, 16) : '') + (r.con_transcripcion ? ' · Incluye lo que se dijo en la reunión' : ''),
        '<button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rl-ia-copiar>' + ico('copiar', 16) + '<span class="mc-btn__label">Copiar</span></button>')
      + (r.resumen ? '<p class="rl-ia-res__resumen">' + esc(r.resumen) + '</p>' : '')
      + '<div class="rl-ia-cols">'
      + (r.logros && r.logros.length ? '<div class="rl-ia-col rl-ia-col--logro"><h4>' + ico('exito', 16) + ' Logros</h4>' + lista(r.logros) + '</div>' : '')
      + (r.riesgos && r.riesgos.length ? '<div class="rl-ia-col rl-ia-col--riesgo"><h4>' + ico('alerta', 16) + ' Riesgos</h4>' + lista(r.riesgos) + '</div>' : '')
      + '</div>'
      + (comp ? '<h4 class="rl-subtit">' + ico('completar', 16) + ' Compromisos</h4><div class="rl-matriz-scroll"><table class="rl-comp"><thead><tr><th>Quién</th><th>Qué</th><th>Cuándo</th></tr></thead><tbody>' + comp + '</tbody></table></div>' : '')
      + (r.siguiente ? '<p class="rl-ia-res__sig">' + ico('agenda', 16) + ' <span><b>Para la próxima sesión:</b> ' + esc(r.siguiente) + '</span></p>' : '')
      + firma(j) + '</section>';
  }
  const textoResumen = (r) => [r.resumen, r.logros && r.logros.length ? 'Logros:\n- ' + r.logros.join('\n- ') : '', r.riesgos && r.riesgos.length ? 'Riesgos:\n- ' + r.riesgos.join('\n- ') : '',
    (r.compromisos || []).length ? 'Compromisos:\n' + r.compromisos.map((c) => '- ' + c.quien + ': ' + c.que + (c.cuando ? ' (' + c.cuando + ')' : '')).join('\n') : '', r.siguiente ? 'Próxima sesión: ' + r.siguiente : ''].filter(Boolean).join('\n\n');
  const ponerResumen = (r, j) => {
    slotRes.innerHTML = pintarResumen(r, j);
    slotRes.querySelector('[data-rl-ia-copiar]').addEventListener('click', () => copiar(textoResumen(r)));
  };
  if (slotRes) {
    const r0 = leerJSON(slotRes.querySelector('[data-rl-resumen-datos]'));
    if (r0) ponerResumen(r0, null);
  }
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rl-ia-revision]');
    if (!b || !slotRes) return;
    slotRes.innerHTML = '';
    slotRes.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const parar = mostrarCarga(slotRes, 'resumen', ['Leyendo lo revisado…', 'Viendo qué se cumplió y qué no…', 'Anotando los compromisos…', 'Escribiendo el resumen…']);
    try {
      // Primero lo que se anotó y no se guardó: la IA lee lo guardado
      if (revForm && document.querySelector('[data-rl-rev-sucio]') && !document.querySelector('[data-rl-rev-sucio]').hidden) {
        const fd = new FormData(revForm);
        fd.delete('agregar_codigo');
        await post('rel_revision_guardar', fd);
        document.querySelector('[data-rl-rev-sucio]').hidden = true;
      }
      const j = await ocupado(b, () => post('rel_ia_revision', { id: b.dataset.rlIaRevision }));
      parar();
      if (j) ponerResumen(j.resumen || {}, j);
    } catch (err) { parar(); slotRes.innerHTML = errorIA(err); }
  });

  /* =========================================================
     Formularios genéricos (equipos, releases, avisos, hitos, nuevo ítem, nueva revisión)
     ========================================================= */
  document.addEventListener('submit', async (e) => {
    const f = e.target.closest('form[data-rl-form]');
    if (!f) return;
    e.preventDefault();
    const fd = new FormData(f);
    const btn = e.submitter || f.querySelector('[type="submit"]');
    try {
      const j = await ocupado(btn, () => post(f.dataset.rlForm, fd));
      if (!j) return;
      toast(j.mensaje || 'Guardado.');
      const tras = f.dataset.rlTras || '';
      if (tras === 'recargar') setTimeout(() => location.reload(), 400);
      else if (tras === 'ir-revision' && j.revision) location.href = 'releases.php?v=revision&id=' + j.revision.id;
      else { const dlg = f.closest('dialog'); if (dlg) dlg.close(); if (window.MC && MC.estrenar) MC.estrenar(f); }
    } catch (err) {
      toast(err.message, 'error');
      // La revisión quedó guardada aunque la reunión no se pudo crear: se abre igual (sin duplicarla)
      const rev = err.datos && err.datos.revision;
      if (rev && rev.id && (f.dataset.rlTras === 'ir-revision' || f.dataset.rlTras === 'recargar')) setTimeout(() => { location.href = 'releases.php?v=revision&id=' + rev.id; }, 1800);
    }
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rl-abrir]');
    if (!b) return;
    const d = document.getElementById(b.dataset.rlAbrir);
    if (d && d.showModal) d.showModal();
  });

  /* =========================================================
     Modalidad de la revisión: presencial (lugar) o virtual (crear la reunión
     de Zoom en el proyecto del equipo o usar una que ya existe)
     ========================================================= */
  const PLAT = { zoom: 'Crear la reunión de Zoom', meet: 'Crear la reunión de Meet', enlace: 'Crear la reunión con mi enlace' };
  function montarModalidad(box) {
    const form = box.closest('form');
    if (!form || box.dataset.rlMontado) return;
    box.dataset.rlMontado = '1';
    const q = (s) => box.querySelector(s);
    const valor = (n, def) => { const el = form.querySelector('[name="' + n + '"]'); return el ? el.value : def; };
    const equipo = () => valor('equipo', box.dataset.equipo || '') || box.dataset.equipo || '';
    const fecha = () => valor('fecha', box.dataset.fecha || '') || box.dataset.fecha || '';
    const modo = () => (form.querySelector('[name="modalidad"]:checked') || {}).value || 'presencial';
    const cual = () => (form.querySelector('[name="rl_reu"]:checked') || {}).value || 'crear';
    let ultima = '';
    const aviso = (tono, txt) => {
      q('[data-rl-reu-aviso]').innerHTML = txt ? '<div class="mc-alert mc-alert--' + tono + ' mc-alert--compact" role="status">' + ico(tono === 'warning' ? 'alerta' : 'info', 18)
        + '<div class="mc-alert__content"><p class="mc-alert__desc">' + txt + '</p></div></div>' : '';
    };
    function pintar(j) {
      const plat = j.plataforma || '';
      q('[data-rl-reu-crear-lbl]').textContent = PLAT[plat] || 'Crear la reunión';
      if (plat === 'zoom' && !j.zoom_listo) aviso('warning', 'Zoom no está configurado en Ajustes → Zoom. Puedes guardar la revisión como virtual y crear la reunión después.');
      else if (!plat) aviso('warning', 'No hay ninguna plataforma de reuniones configurada (Ajustes → Reuniones).');
      else if (!(j.proyectos || []).length) aviso('warning', 'El equipo no tiene proyectos del panel donde crear la reunión: elígelos en Releases → Ajustes → Equipos.');
      // Sin Zoom ni Meet conectados, el panel cae al enlace propio (como en «Nueva reunión»)
      else if (plat === 'enlace' && !j.zoom_listo) aviso('info', 'Este panel no tiene Zoom conectado, por eso pide un enlace. Con Zoom conectado (Ajustes → Zoom), la reunión se crea sola en Zoom, igual que las demás reuniones del panel.');
      else aviso('', '');
      const sp = q('[data-rl-reu-proy]');
      sp.innerHTML = (j.proyectos || []).map((p) => '<option value="' + p.id + '"' + (p.puede ? '' : ' disabled') + '>' + esc(p.nombre) + (p.puede ? '' : ' (sin permiso)') + '</option>').join('') || '<option value="">Sin proyectos</option>';
      const primero = (j.proyectos || []).find((p) => p.puede);
      if (primero) sp.value = String(primero.id);
      if (window.DS && DS.refrescar) DS.refrescar(sp);
      q('[data-rl-reu-enlace]').hidden = plat !== 'enlace';
      const inv = j.invitados || [];
      q('[data-rl-reu-inv]').innerHTML = inv.length ? '<div class="rl-multi" data-rl-multi><div class="rl-multi__lista">' + inv.map((m) => '<label class="rl-multi__op is-on" data-buscar="' + esc(plano(m.nombre)) + '">'
        + '<input type="checkbox" name="invitados[]" value="' + m.id + '" checked><span class="rl-multi__caja" aria-hidden="true">' + CHECK + '</span><span class="rl-multi__txt"><b>' + esc(m.nombre) + '</b></span></label>').join('')
        + '</div><p class="rl-multi__cuenta" data-rl-multi-n>' + inv.length + ' elegido(s)</p></div>' : '<p class="rl-multi__vacio">No hay invitados sugeridos: agrega miembros al equipo en Ajustes.</p>';
      const sl = q('[data-rl-reu-lista]');
      const reus = j.reuniones || [];
      sl.innerHTML = reus.length ? reus.map((r) => '<option value="' + r.id + '">' + esc(String(r.inicio).slice(11, 16) + ' · ' + r.topic + ' · ' + ({ zoom: 'Zoom', meet: 'Meet', enlace: 'Enlace' }[r.plataforma] || r.plataforma) + (r.recurrente ? ' · se repite' : '')) + '</option>').join('')
        : '<option value="">No hay reuniones del equipo ese día</option>';
      if (window.DS && DS.refrescar) DS.refrescar(sl);
      const radioEx = form.querySelector('[name="rl_reu"][value="existente"]');
      radioEx.disabled = !reus.length;
      radioEx.closest('label').title = reus.length ? '' : 'No hay reuniones de los proyectos del equipo ese día';
      if (!reus.length && cual() === 'existente') { form.querySelector('[name="rl_reu"][value="crear"]').checked = true; }
      paneles(false);
    }
    async function cargar(forzar) {
      const clave = equipo() + '|' + fecha();
      if (!forzar && clave === ultima) return;
      ultima = clave;
      if (!equipo()) { aviso('info', 'Elige el equipo para ver sus reuniones.'); return; }
      aviso('info', 'Buscando las reuniones del equipo…');
      try { pintar(await post('rel_reuniones_equipo', { equipo: equipo(), fecha: fecha() })); }
      catch (err) { ultima = ''; aviso('warning', esc(err.message)); }
    }
    function paneles(conCarga) {
      const m = modo(), c = cual();
      box.querySelectorAll('[data-rl-mod]').forEach((p) => {
        p.hidden = p.dataset.rlMod !== m;
        if (p.dataset.rlMod === 'presencial') p.querySelectorAll('input').forEach((x) => { x.disabled = m !== 'presencial'; });
      });
      box.querySelectorAll('[data-rl-reu]').forEach((p) => {
        p.hidden = p.dataset.rlReu !== c;
        p.querySelectorAll('input, select, textarea').forEach((x) => { x.disabled = m !== 'virtual' || p.dataset.rlReu !== c; });
      });
      if (m === 'virtual' && conCarga !== false) cargar(false);
    }
    form.addEventListener('change', (e) => {
      const n = e.target.name;
      if (n === 'modalidad' || n === 'rl_reu') paneles();
      else if ((n === 'equipo' || n === 'fecha') && modo() === 'virtual') cargar(true);
    });
    const dlg = box.closest('dialog');
    if (dlg) {
      const obs = new MutationObserver(() => { if (dlg.open && modo() === 'virtual') cargar(false); });
      obs.observe(dlg, { attributes: true, attributeFilter: ['open'] });
    }
    paneles(dlg ? false : undefined);
  }
  document.querySelectorAll('[data-rl-modalidad]').forEach(montarModalidad);
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rl-crear-reunion]');
    if (!b) return;
    try {
      const j = await ocupado(b, () => post('rel_revision_guardar', { id: b.dataset.rlCrearReunion, modalidad: 'virtual', crear_reunion: 1 }));
      if (!j) return;
      toast(j.mensaje || 'Reunión creada.');
      setTimeout(() => location.reload(), 600);
    } catch (err) { toast(err.message, 'error'); }
  });

  /* ---------- Interruptor del DS (la casilla real viaja en el formulario) ---------- */
  document.addEventListener('click', (e) => {
    const t = e.target.closest('.rl [data-ds-switch] .mc-switch__track, dialog [data-ds-switch] .mc-switch__track, .rl-cajon [data-ds-switch] .mc-switch__track');
    if (!t) return;
    const sw = t.closest('[data-ds-switch]');
    const on = !sw.classList.contains('is-on');
    sw.classList.toggle('is-on', on);
    t.setAttribute('aria-checked', on ? 'true' : 'false');
    const c = sw.querySelector('.ds-switch-casilla');
    c.checked = on;
    c.dispatchEvent(new Event('change', { bubbles: true }));
  });

  /* ---------- Listas de casillas con buscador ---------- */
  document.addEventListener('input', (e) => {
    const q = e.target.closest && e.target.closest('[data-rl-multi-q]');
    if (!q) return;
    const t = plano(q.value.trim());
    q.closest('[data-rl-multi]').querySelectorAll('.rl-multi__op').forEach((op) => { op.hidden = t !== '' && !plano(op.dataset.buscar).includes(t); });
  });
  document.addEventListener('change', (e) => {
    const c = e.target;
    if (!(c instanceof HTMLInputElement) || c.type !== 'checkbox' || !c.closest('.rl-multi__op')) return;
    c.closest('.rl-multi__op').classList.toggle('is-on', c.checked);
    const m = c.closest('[data-rl-multi]');
    const n = m.querySelector('[data-rl-multi-n]');
    if (n) n.textContent = m.querySelectorAll('.rl-multi__op input:checked').length + ' elegido(s)';
  });

  /* =========================================================
     Hitos
     ========================================================= */
  const dlgHito = document.getElementById('rl-dlg-hito');
  const abrirHito = (h) => {
    if (!dlgHito) return;
    const f = dlgHito.querySelector('form');
    f.reset();
    f.querySelector('[name="id"]').value = h ? h.id : 0;
    f.querySelector('[name="titulo"]').value = h ? h.titulo : '';
    f.querySelector('[name="release"]').value = h ? h.release : '';
    f.querySelector('[name="descripcion"]').value = h ? h.descripcion : '';
    const fe = f.querySelector('[name="fecha"]');
    if (window.setFecha) setFecha(fe, h ? h.fecha : ''); else fe.value = h ? h.fecha : '';
    const c = f.querySelector('input[type="checkbox"][name="hecho"]');
    c.checked = !!(h && h.hecho);
    c.dispatchEvent(new Event('change', { bubbles: true }));
    dlgHito.querySelector('[data-rl-hito-titulo]').textContent = h ? 'Editar hito' : 'Nuevo hito';
    dlgHito.querySelector('[data-rl-hito-borrar]').hidden = !h;
    dlgHito.showModal();
  };
  document.addEventListener('click', async (e) => {
    if (e.target.closest('[data-rl-hito-nuevo]')) { abrirHito(null); return; }
    const ed = e.target.closest('[data-rl-hito-editar]');
    if (ed) { abrirHito(leerJSON({ textContent: ed.dataset.rlHitoEditar })); return; }
    const bo = e.target.closest('[data-rl-hito-borrar]');
    if (bo && dlgHito) {
      const id = dlgHito.querySelector('[name="id"]').value;
      const si = await confirmar({ titulo: '¿Borrar el hito?', mensaje: 'Se quita de la línea de tiempo y de la hoja de ruta.', ok: 'Sí, borrar' });
      if (!si) return;
      try { const j = await ocupado(bo, () => post('rel_hito_borrar', { id })); if (j) { toast(j.mensaje || 'Hito borrado.'); location.reload(); } } catch (err) { toast(err.message, 'error'); }
    }
  });
  document.addEventListener('change', async (e) => {
    const c = e.target;
    const f = c.closest && c.closest('[data-rl-hito-hecho]');
    if (!f || !c.classList.contains('ds-switch-casilla')) return;
    const li = f.closest('.rl-tl__hito');
    try {
      const j = await post('rel_hito_guardar', { id: f.dataset.rlHitoHecho, hecho: c.checked ? 1 : 0 });
      li.classList.toggle('is-hecho', c.checked);
      li.classList.toggle('is-atrasado', !c.checked && li.dataset.pasado === '1');
      toast(c.checked ? 'Hito marcado como cumplido.' : 'Hito marcado como pendiente.', 'success');
      void j;
    } catch (err) { toast(err.message, 'error'); }
  });

  /* =========================================================
     Importar los Excel (previsualizar y luego importar) y la semilla
     ========================================================= */
  const imp = document.querySelector('[data-rl-importar]');
  function pintarImport(r, seco, extra) {
    const n = (k, lbl, ic) => '<div class="rl-num"><span class="mc-tile mc-tile--sm">' + ico(ic, 18) + '</span><b class="mc-num">' + (+r[k] || 0) + '</b><small>' + lbl + '</small></div>';
    const v = r.vista || {};
    return '<div class="rl-impres' + (seco ? ' is-seco' : ' is-hecho') + '">'
      + '<h3 class="rl-impres__tit">' + ico(seco ? 'ver' : 'exito', 20) + (seco ? ' Así quedaría' : ' Listo: plan cargado') + (extra ? ' <small>' + esc(extra) + '</small>' : '') + '</h3>'
      + '<div class="rl-nums">' + n('items_nuevos', 'ítems nuevos', 'mas') + n('items_actualizados', seco ? 'ya estaban' : 'actualizados', 'reintentar')
      + n('releases', 'releases', 'ruta') + n('equipos', 'equipos', 'usuarios') + n('hitos', seco ? 'hitos' : 'hitos nuevos', 'bandera')
      + n('revisiones', seco ? 'sesiones' : 'sesiones nuevas', 'agenda') + n('filas_revision', 'filas de revisión', 'completar') + n('feriados', 'feriados', 'reloj') + '</div>'
      + (v.releases && v.releases.length ? '<div class="rl-impres__cols"><div><h4>Releases</h4><ul>' + v.releases.map((x) => '<li><span class="rl-code" style="--rc:' + esc(x.color || 'var(--primary)') + '">' + esc(x.codigo) + '</span> ' + esc(x.nombre || '')
          + ' <small>' + esc(fLarga(x.inicio)) + ' → ' + esc(fLarga(x.fin)) + '</small></li>').join('') + '</ul></div>'
        + (v.equipos && v.equipos.length ? '<div><h4>Equipos</h4><ul>' + v.equipos.map((x) => '<li><b>' + esc(x.nombre) + '</b> <small>' + esc([x.sm_nombre ? 'SM ' + x.sm_nombre : '', x.po_nombre ? 'PO ' + x.po_nombre : ''].filter(Boolean).join(' · ')) + '</small></li>').join('') + '</ul></div>' : '')
        + '</div>' : '')
      + (!seco ? '<p class="rl-impres__pie"><a class="mc-btn mc-btn--primary mc-btn--sm" href="releases.php">' + ico('chevron-der', 16) + '<span class="mc-btn__label">Ver el tablero</span></a></p>' : '')
      + '</div>';
  }
  if (imp) {
    const res = imp.querySelector('[data-rl-importar-res]');
    const ok = imp.querySelector('[data-rl-importar-ok]');
    const ver = imp.querySelector('[data-rl-importar-ver]');
    const datos = (seco) => { const fd = new FormData(imp); if (seco) fd.set('seco', '1'); return fd; };
    const hayArchivo = () => [...imp.querySelectorAll('input[type="file"]')].some((i) => i.files && i.files.length);
    imp.addEventListener('change', (e) => {
      if (e.target.type === 'file') {
        const lbl = e.target.closest('[data-rl-archivo]');
        const f = e.target.files && e.target.files[0];
        lbl.classList.toggle('is-listo', !!f);
        if (f) lbl.querySelector('[data-rl-archivo-nombre]').textContent = f.name + ' · ' + Math.max(1, Math.round(f.size / 1024)) + ' KB';
      }
      ok.disabled = true;
      res.hidden = true;
    });
    ver.addEventListener('click', async () => {
      if (!hayArchivo()) { toast('Elige al menos uno de los dos Excel.', 'warning'); return; }
      try {
        const j = await ocupado(ver, () => post('rel_importar', datos(true)));
        if (!j) return;
        res.hidden = false;
        res.innerHTML = pintarImport(j.resultado || {}, true);
        ok.disabled = false;
      } catch (err) { res.hidden = false; res.innerHTML = '<div class="mc-alert mc-alert--danger" role="alert">' + ico('error', 20) + '<div class="mc-alert__content"><p class="mc-alert__title">No se pudo leer</p><div class="mc-alert__desc">' + esc(err.message) + '</div></div></div>'; }
    });
    ok.addEventListener('click', async () => {
      const modo = (imp.querySelector('[name="modo"]:checked') || {}).value;
      const si = await confirmar({ titulo: '¿Importar ahora?', ok: 'Sí, importar', peligro: modo === 'actualizar',
        mensaje: modo === 'actualizar' ? 'Las fechas, el estado y el avance del Excel van a reemplazar lo que se cambió en el panel.' : 'Se agregan los ítems y sesiones nuevos y se completa lo vacío. Lo cambiado en el panel se respeta.' });
      if (!si) return;
      try {
        const j = await ocupado(ok, () => post('rel_importar', datos(false)));
        if (!j) return;
        res.innerHTML = pintarImport(j.resultado || {}, false);
        ok.disabled = true;
        toast(j.mensaje || 'Importado.');
      } catch (err) { toast(err.message, 'error'); }
    });
  }
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rl-sembrar]');
    if (!b) return;
    const caja = b.closest('[data-rl-semilla]');
    const res = caja.querySelector('[data-rl-semilla-res]');
    const modo = (document.querySelector('[data-rl-importar] [name="modo"]:checked') || {}).value || 'agregar';
    const seco = b.dataset.rlSembrar === 'ver';
    if (!seco) {
      const si = await confirmar({ titulo: '¿Cargar el plan incluido?', ok: 'Sí, cargar', peligro: modo === 'actualizar',
        mensaje: modo === 'actualizar' ? 'Lo del plan incluido va a reemplazar las fechas, el estado y el equipo de lo que ya está.' : 'Se agrega lo que falta y se completa lo vacío. Lo cambiado en el panel se respeta.' });
      if (!si) return;
    }
    try {
      const j = await ocupado(b, () => post('rel_sembrar', { modo, seco: seco ? 1 : '' }));
      if (!j) return;
      res.hidden = false;
      res.innerHTML = pintarImport(j.resultado || {}, seco, j.semilla || '');
      if (!seco) toast(j.mensaje || 'Plan cargado.');
    } catch (err) { toast(err.message, 'error'); }
  });

  /* =========================================================
     Agenda de revisiones: arrastrar a otro día de la semana o editarla
     ========================================================= */
  const agenda = document.querySelector('[data-rl-agenda]');
  if (agenda) {
    let arrastrada = null;
    agenda.addEventListener('dragstart', (e) => {
      const c = e.target.closest && e.target.closest('[data-rl-ses]');
      if (!c) return;
      arrastrada = c;
      c.classList.add('is-arrastrando');
      agenda.classList.add('is-arrastrando');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', c.dataset.rlSes); } catch (err) { /* algunos navegadores no dejan */ }
    });
    const limpiar = () => {
      agenda.classList.remove('is-arrastrando');
      agenda.querySelectorAll('.is-sobre').forEach((d) => d.classList.remove('is-sobre'));
      if (arrastrada) arrastrada.classList.remove('is-arrastrando');
    };
    agenda.addEventListener('dragend', () => { limpiar(); arrastrada = null; });
    agenda.addEventListener('dragover', (e) => {
      const dia = arrastrada && e.target.closest('[data-rl-dia]');
      if (!dia) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      agenda.querySelectorAll('.is-sobre').forEach((d) => { if (d !== dia) d.classList.remove('is-sobre'); });
      dia.classList.add('is-sobre');
    });
    agenda.addEventListener('dragleave', (e) => {
      const dia = e.target.closest('[data-rl-dia]');
      if (dia && !dia.contains(e.relatedTarget)) dia.classList.remove('is-sobre');
    });
    agenda.addEventListener('drop', async (e) => {
      const dia = arrastrada && e.target.closest('[data-rl-dia]');
      if (!dia) return;
      e.preventDefault();
      const c = arrastrada;
      limpiar();
      arrastrada = null;
      const fecha = dia.dataset.rlDia;
      if (fecha === c.dataset.fecha) return;
      const origen = c.parentElement;
      const lista = dia.querySelector('.rl-dia__lista');
      // Se ve en su día nuevo enseguida; si el panel dice que no, vuelve a donde estaba
      const nada = lista.querySelector('.rl-dia__nada');
      if (nada) nada.hidden = true;
      lista.appendChild(c);
      c.classList.add('is-guardando');
      try {
        const j = await post('rel_revision_guardar', { id: c.dataset.rlSes, fecha });
        toast((j.mensaje || 'Revisión movida.').replace(/^Revisión guardada\./, 'Revisión movida al ' + fLarga(fecha) + '.'));
        setTimeout(() => location.reload(), 900);
      } catch (err) {
        origen.appendChild(c);
        if (nada) nada.hidden = false;
        c.classList.remove('is-guardando');
        toast(err.message, 'error');
      }
    });
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rl-editar-rev]');
    if (!b) return;
    e.preventDefault();
    const r = leerJSON({ textContent: b.dataset.rlEditarRev }) || {};
    const dlg = document.getElementById('rl-dlg-editar-rev');
    if (!dlg) return;
    const f = dlg.querySelector('form');
    mejorar(dlg);
    // md-sync: la fecha la pinta MecaDate, que se entera así del valor nuevo
    const pon = (n, v) => { const el = f.querySelector('[name="' + n + '"]'); if (el) { el.value = v == null ? '' : v; el.dispatchEvent(new Event('change', { bubbles: true })); el.dispatchEvent(new Event('md-sync')); } };
    pon('id', r.id);
    pon('fecha', r.fecha);
    pon('hora', r.hora);
    pon('titulo', r.titulo);
    pon('responsable', r.responsable);
    const est = f.querySelector('.rl-er-estado');
    const sel = f.querySelector('[name="estado"]');
    // Una realizada se queda realizada: aquí no se le cambia el estado
    if (est) est.hidden = r.estado === 'realizada';
    if (sel) { sel.disabled = r.estado === 'realizada'; if (r.estado !== 'realizada') pon('estado', r.estado === 'cancelada' ? 'cancelada' : 'programada'); }
    dlg.querySelector('[data-rl-er-eq]').textContent = 'Equipo ' + (r.equipo || '');
    dlg.querySelector('[data-rl-er-reu]').hidden = !r.reunion;
    dlg.querySelector('[data-rl-er-abrir]').href = 'releases.php?v=revision&id=' + r.id;
    dlg.showModal();
  });

  /* =========================================================
     Reuniones diarias del equipo: registrarla a mano, traer de Zoom y elegir cuál es
     ========================================================= */
  const cajaDl = document.querySelector('[data-rl-dailies]');
  const DL = leerJSON(cajaDl && cajaDl.querySelector('[data-rl-dailies-datos]')) || {};
  const dlgDl = document.getElementById('rl-dlg-daily');
  function llenarDaily(fecha) {
    if (!dlgDl) return;
    const f = dlgDl.querySelector('form');
    const x = (DL.dias || {})[fecha] || {};
    const manual = x.fuente === 'manual';
    const marca = (v) => { const r = f.querySelector('[name="estado"][value="' + v + '"]'); if (r) r.checked = true; };
    marca(!manual ? 'hecha' : ({ cumplida: 'hecha', incompleta: 'hecha', no_hecha: 'no_hecha', no_aplica: 'no_aplica' }[x.estado] || 'hecha'));
    dlgDl.querySelector('[data-rl-dl-auto]').hidden = !manual;
    dlgDl.querySelector('[data-rl-dl-zoom]').hidden = !(x.zoom && !manual);
    const mod = f.querySelector('[name="modalidad"][value="' + (x.modalidad === 'virtual' || (!manual && x.zoom) ? 'virtual' : 'presencial') + '"]');
    if (mod) mod.checked = true;
    // Quiénes estuvieron: lo registrado, lo que vio Zoom o, si no hay nada, todo el equipo
    const hay = Array.isArray(x.presentes) && (manual || x.zoom);
    const presentes = new Set((hay ? x.presentes : (DL.esperados || []).map((m) => m.id)).map(Number));
    f.querySelectorAll('[name="presentes[]"]').forEach((c) => { c.checked = presentes.has(Number(c.value)); c.dispatchEvent(new Event('change', { bubbles: true })); });
    const n = f.querySelector('[data-rl-multi-n]');
    if (n) n.textContent = presentes.size + ' elegido(s)';
    f.querySelector('[name="nota"]').value = manual ? (x.nota || '') : '';
    verHecha();
  }
  function verHecha() {
    if (!dlgDl) return;
    const v = (dlgDl.querySelector('[name="estado"]:checked') || {}).value;
    dlgDl.querySelector('[data-rl-dl-hecha]').hidden = v !== 'hecha';
    const nota = dlgDl.querySelector('[name="nota"]');
    nota.required = v === 'no_aplica';
    nota.closest('.mc-field').hidden = v === 'auto';
  }
  if (dlgDl) {
    dlgDl.addEventListener('change', (e) => {
      if (e.target.matches('[name="estado"]')) verHecha();
      if (e.target.matches('[data-rl-dl-fecha]')) llenarDaily(e.target.value);
    });
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rl-daily-reg]');
    if (!b || !dlgDl) return;
    const fecha = b.dataset.rlDailyReg || DL.hoy;
    if (fecha > DL.hoy) { toast('Ese día todavía no llega: se registra cuando pase.', 'info'); return; }
    // Hoy no se registra la daily de ayer: los días pasados los mide Zoom (o los corrige un editor)
    if (fecha < DL.hoy && !DL.pasados) { toast('Solo se registra la daily de hoy. Los días que ya pasaron los mide Zoom.', 'info'); return; }
    mejorar(dlgDl);
    const inp = dlgDl.querySelector('[data-rl-dl-fecha]');
    inp.value = fecha;
    inp.dispatchEvent(new Event('md-sync'));
    llenarDaily(fecha);
    dlgDl.showModal();
  });
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rl-daily-zoom]');
    if (!b) return;
    try {
      const j = await ocupado(b, () => post('rel_daily_zoom', { equipo: b.dataset.rlDailyZoom }));
      if (!j) return;
      toast(j.mensaje || 'Medido de nuevo.');
      setTimeout(() => location.reload(), 900);
    } catch (err) { toast(err.message, 'error'); }
  });
  if (cajaDl) {
    cajaDl.addEventListener('change', async (e) => {
      const sel = e.target.closest('.rl-dailies__elegir select');
      if (!sel) return;
      try {
        const j = await post('rel_daily_reunion', { equipo: DL.equipo, reunion_id: sel.value });
        toast(j.mensaje || 'Listo.');
        setTimeout(() => location.reload(), 700);
      } catch (err) { toast(err.message, 'error'); }
    });
  }
})();
