/* =========================================================
   Backlog estilo Jira (lib/backlog/backlog_ui.php): la pestaña «Sprints» del proyecto
   y la pantalla de cada historia (historia.php).
   - Historia: los escenarios Dado · Cuando · Entonces (sumar, quitar,
     renumerar, que crezcan al escribir), las tareas con buscador y borrar.
   - Pestaña: el release en una lista y arrastrar: en el backlog, entre
     sprints o de posición; en el tablero, entre columnas (su estado).
   Las acciones son bl_* de actions.php; lo editan el PO, el SM y el admin.
   ========================================================= */
(() => {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = (m, t) => { if (window.MC && MC.toast) MC.toast(m, t || 'success'); };
  const plano = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  async function post(datos) {
    const fd = new FormData();
    Object.entries(datos).forEach(([k, v]) => fd.set(k, v == null ? '' : v));
    fd.set('ajax', '1');
    let j = null;
    try {
      const r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      j = await r.json();
    } catch (_) { j = null; }
    if (!j || !j.ok) throw new Error(j ? (j.error || 'No se pudo.') : 'No se pudo: revisa tu conexión.');
    return j;
  }

  /* ---------- La historia: escenarios Dado · Cuando · Entonces ---------- */
  const form = document.querySelector('[data-bl-form]');
  const cajaEsc = form && form.querySelector('[data-bl-escenarios]');
  // Que se vea todo lo escrito (medido si está a la vista; si no, por sus líneas)
  const crecer = (t) => {
    t.rows = Math.min(8, Math.max(1, (t.value.match(/\n/g) || []).length + 1));
    if (!t.offsetParent) { t.style.height = ''; return; }
    t.style.height = 'auto';
    t.style.height = Math.min(t.scrollHeight + 2, 260) + 'px';
  };
  const filaEsc = (n) => '<div class="bl-esc" data-bl-esc><div class="bl-esc__cab"><span class="bl-esc__n">Escenario ' + (n + 1) + '</span>'
    + '<input class="bl-esc__nom" data-k="nombre" maxlength="160" placeholder="Nombre (opcional)">'
    + '<button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte mc-iconbtn--danger" data-bl-esc-quitar aria-label="Quitar el escenario" data-ds-tip="Quitar el escenario">'
    + '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>'
    + [['dado', 'Dado', 'Given'], ['cuando', 'Cuando', 'When'], ['entonces', 'Entonces', 'Then']].map(([k, l, en]) =>
      '<label class="bl-esc__fila"><span class="bl-esc__k">' + l + '<small>' + en + '</small></span><textarea class="bl-esc__t" rows="1" maxlength="1500" data-k="' + k + '"></textarea></label>').join('')
    + '</div>';
  // Los nombres van por posición (escenarios[0][dado]…): se rehacen al sumar o quitar
  const numerar = () => cajaEsc.querySelectorAll('[data-bl-esc]').forEach((el, n) => {
    el.querySelector('.bl-esc__n').textContent = 'Escenario ' + (n + 1);
    el.querySelectorAll('[data-k]').forEach((c) => { c.name = 'escenarios[' + n + '][' + c.dataset.k + ']'; });
  });
  if (cajaEsc) {
    cajaEsc.querySelectorAll('textarea').forEach(crecer);
    form.querySelector('[data-bl-esc-mas]')?.addEventListener('click', () => {
      cajaEsc.insertAdjacentHTML('beforeend', filaEsc(cajaEsc.children.length));
      numerar();
      cajaEsc.lastElementChild.querySelector('textarea').focus();
    });
    cajaEsc.addEventListener('click', (e) => {
      const q = e.target.closest('[data-bl-esc-quitar]');
      if (!q) return;
      q.closest('[data-bl-esc]').remove();
      if (!cajaEsc.children.length) cajaEsc.insertAdjacentHTML('beforeend', filaEsc(0));
      numerar();
    });
    cajaEsc.addEventListener('input', (e) => { if (e.target.matches('textarea')) crecer(e.target); });
    addEventListener('resize', () => cajaEsc.querySelectorAll('textarea').forEach(crecer));
  }
  if (form && form.dataset.puede !== '1') {
    // Quien no la gestiona, solo la ve (el editor con formato también queda quieto)
    form.querySelectorAll('.rt-area').forEach((a) => { a.contentEditable = 'false'; });
    form.querySelectorAll('.rt-barra').forEach((x) => { x.hidden = true; });
  }
  // Borrar la historia (sus tareas siguen en el proyecto)
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bl-borrar-hu]');
    if (!b) return;
    if (!confirm('¿Borrar la historia ' + b.dataset.cod + '? Sus tareas siguen en el proyecto.')) return;
    try { const j = await post({ accion: 'bl_historia_borrar', id: b.dataset.blBorrarHu }); toast(j.mensaje); setTimeout(() => { location.href = b.dataset.volver; }, 400); }
    catch (err) { toast(err.message, 'error'); }
  });
  // Las tareas: buscador y casillas (como en Releases)
  document.addEventListener('input', (e) => {
    const q = e.target.closest && e.target.closest('[data-bl-form] [data-rl-multi-q]');
    if (!q) return;
    const t = plano(q.value.trim());
    q.closest('[data-rl-multi]').querySelectorAll('.rl-multi__op').forEach((op) => { op.hidden = t !== '' && !plano(op.dataset.buscar).includes(t); });
  });
  document.addEventListener('change', (e) => {
    const c = e.target;
    if (!(c instanceof HTMLInputElement) || c.type !== 'checkbox' || !c.closest('[data-bl-form] .rl-multi__op')) return;
    c.closest('.rl-multi__op').classList.toggle('is-on', c.checked);
    const m = c.closest('[data-rl-multi]');
    const n = m.querySelector('[data-rl-multi-n]');
    if (n) n.textContent = m.querySelectorAll('.rl-multi__op input:checked').length + ' elegido(s)';
  });

  /* ---------- La pestaña Sprints ---------- */
  const raiz = document.querySelector('[data-bl]');
  if (!raiz) return;
  raiz.querySelector('[data-bl-rel]')?.addEventListener('change', (e) => {
    const u = e.target.dataset.url || '';
    if (u) location.href = u.replace('__R__', encodeURIComponent(e.target.value));
  });
  if (raiz.dataset.puede !== '1') return;

  let arrastrada = null;
  const totales = (cont, sel) => {
    const items = [...cont.querySelectorAll(sel)];
    const sec = cont.closest('[data-bl-sec], [data-bl-col]');
    if (!sec) return;
    const n = sec.querySelector('[data-bl-n]');
    const pts = sec.querySelector('[data-bl-pts]');
    const suma = items.reduce((s, x) => s + (parseInt(x.dataset.pts, 10) || 0), 0);
    if (n) n.textContent = sec.matches('[data-bl-col]') ? items.length : items.length + ' historia(s)';
    if (pts) { const b = pts.querySelector('b'); if (b) b.textContent = suma; else pts.textContent = suma + ' pts'; }
    const vacio = cont.querySelector('.bl-lista__vacio, .bl-col__vacio');
    if (vacio) vacio.hidden = items.length > 0;
  };
  document.addEventListener('dragstart', (e) => {
    const el = e.target.closest && e.target.closest('.bl-fila[draggable], .bl-card[draggable]');
    if (!el) return;
    arrastrada = el;
    el.classList.add('is-arrastrando');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', el.dataset.blId);
  });
  document.addEventListener('dragend', () => {
    if (arrastrada) arrastrada.classList.remove('is-arrastrando');
    document.querySelectorAll('.is-destino').forEach((x) => x.classList.remove('is-destino'));
    arrastrada = null;
  });
  // Dónde cae: antes del primer elemento cuyo centro está por debajo del puntero
  const despuesDe = (cont, y, sel) => [...cont.querySelectorAll(sel + ':not(.is-arrastrando)')].find((x) => { const r = x.getBoundingClientRect(); return y < r.top + r.height / 2; }) || null;
  document.addEventListener('dragover', (e) => {
    if (!arrastrada) return;
    const esFila = arrastrada.classList.contains('bl-fila');
    const cont = e.target.closest && e.target.closest(esFila ? '[data-bl-lista]' : '[data-bl-cards]');
    if (!cont) return;
    e.preventDefault();
    document.querySelectorAll('.is-destino').forEach((x) => { if (x !== cont) x.classList.remove('is-destino'); });
    cont.classList.add('is-destino');
    const det = cont.closest('details');
    if (det && !det.open) det.open = true;
    const ref = despuesDe(cont, e.clientY, esFila ? '.bl-fila' : '.bl-card');
    const vacio = cont.querySelector('.bl-lista__vacio, .bl-col__vacio');
    if (ref) cont.insertBefore(arrastrada, ref); else cont.insertBefore(arrastrada, vacio || null);
  });
  document.addEventListener('drop', async (e) => {
    if (!arrastrada) return;
    const el = arrastrada;
    const esFila = el.classList.contains('bl-fila');
    const cont = el.closest(esFila ? '[data-bl-lista]' : '[data-bl-cards]');
    if (!cont) return;
    e.preventDefault();
    const sel = esFila ? '.bl-fila' : '.bl-card';
    document.querySelectorAll(esFila ? '[data-bl-lista]' : '[data-bl-cards]').forEach((c) => totales(c, sel));
    const d = { accion: 'bl_historia_mover', id: el.dataset.blId };
    if (esFila) {
      d.sprint = cont.dataset.sprint || '';
      const prev = el.previousElementSibling && el.previousElementSibling.matches('.bl-fila') ? el.previousElementSibling : null;
      const next = el.nextElementSibling && el.nextElementSibling.matches('.bl-fila') ? el.nextElementSibling : null;
      d.despues_de = prev ? prev.dataset.blId : 0;
      d.antes_de = next ? next.dataset.blId : 0;
    } else {
      d.estado = cont.closest('[data-bl-col]').dataset.estado;
    }
    try { const j = await post(d); toast(j.mensaje); }
    catch (err) { toast(err.message, 'error'); setTimeout(() => location.reload(), 600); }
  });
})();

/* ---------- «Lista para sprint» (DoR, lib/backlog/sprint_ui.php) ----------
   - En la historia (historia.php): lo que el panel revisa solo (Como · Quiero ·
     Para, un escenario completo, la estimación) se repasa mientras se escribe,
     con el «n de m» y el aviso si va en un sprint sin estar lista.
   - En la pestaña Sprints: al arrastrar a un sprint una historia que no está
     lista, avisa (no lo impide). */
(() => {
  const toast = (m, t, ms, o) => { if (window.MC && MC.toast) MC.toast(m, t || 'success', ms || 4500, o || {}); };
  const form = document.querySelector('[data-bl-form]');
  const pan = form && form.querySelector('[data-bl-dor]');
  if (pan) {
    const valor = (sel) => { const c = form.querySelector(sel); return c ? String(c.value || '').trim() : ''; };
    const auto = {
      historia: () => ['como', 'quiero', 'para'].every((k) => valor('[name="' + k + '"]') !== ''),
      criterios: () => [...form.querySelectorAll('[data-bl-esc]')].some((esc) => ['dado', 'cuando', 'entonces'].every((k) => {
        const t = esc.querySelector('[data-k="' + k + '"]');
        return t && t.value.trim() !== '';
      })),
      estimada: () => { const r = form.querySelector('input[name="estimacion"]:checked'); return !!r && r.value !== '' && r.value !== '?'; },
    };
    const repasar = () => {
      let ok = 0;
      let tot = 0;
      pan.querySelectorAll('.bls-dori').forEach((li) => {
        tot++;
        let si = false;
        if (li.dataset.auto) { si = auto[li.dataset.auto] ? auto[li.dataset.auto]() : false; li.classList.toggle('is-ok', si); }
        else { const c = li.querySelector('input[type="checkbox"]'); si = !!(c && c.checked); }
        if (si) ok++;
      });
      const lista = tot > 0 && ok === tot;
      const est = pan.querySelector('[data-bl-dor-estado]');
      if (est) {
        est.classList.toggle('col-s-ok', lista);
        est.classList.toggle('col-s-warn', !lista);
        const t = est.querySelector('[data-bl-dor-txt]');
        if (t) t.textContent = lista ? 'Lista' : ok + ' de ' + tot;
      }
      const aviso = pan.querySelector('[data-bl-dor-aviso]');
      if (aviso) aviso.hidden = !(valor('select[name="sprint"]') !== '' && !lista && valor('select[name="estado"]') !== 'hecho');
    };
    form.addEventListener('input', repasar);
    form.addEventListener('change', repasar);
    // Al sumar o quitar escenarios
    form.addEventListener('click', (e) => { if (e.target.closest('[data-bl-esc-quitar], [data-bl-esc-mas]')) setTimeout(repasar, 0); });
  }

  const raiz = document.querySelector('[data-bl]');
  if (!raiz || raiz.dataset.puede !== '1') return;
  let fila = null;
  let desde = null;
  document.addEventListener('dragstart', (e) => {
    fila = (e.target.closest && e.target.closest('.bl-fila[draggable]')) || null;
    const l = fila && fila.closest('[data-bl-lista]');
    desde = l ? (l.dataset.sprint || '') : null;
  });
  document.addEventListener('drop', () => {
    const el = fila;
    fila = null;
    if (!el || el.dataset.dor !== '0') return;
    const l = el.closest('[data-bl-lista]');
    const a = l ? (l.dataset.sprint || '') : '';
    if (a === '' || a === desde) return;
    setTimeout(() => toast((el.dataset.cod || 'La historia') + ' no está lista para sprint', 'warning', 8000,
      { desc: 'Se pasó igual al ' + a + '. Falta: ' + (el.dataset.dorFalta || '') }), 350);
  });
})();

/* ---------- Las tareas de la historia: la IA las propone (van al Scrum Master) y el SM las reparte ----------
   El modal de la IA pide bl_ia_tareas con lo que hay escrito en la pantalla; las marcadas
   (con su título editado) van en ia_tareas y se crean al guardar la historia, así no se
   pierde nada de lo escrito. Repartir es su propio formulario (bl_tareas_repartir). */
(() => {
  const form = document.querySelector('[data-bl-form]');
  if (!form) return;
  const dlg = document.querySelector('[data-bl-ia-dlg]');
  const rep = document.querySelector('[data-bl-rep-dlg]');
  let sucio = false;
  form.addEventListener('input', () => { sucio = true; });
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const PRIO = { urgente: 'Urgente', alta: 'Alta', media: 'Media', baja: 'Baja' };
  let propuestas = [];
  const contar = () => {
    const n = dlg.querySelectorAll('[data-bl-iat-op]:checked').length;
    dlg.querySelector('[data-bl-iat-n]').textContent = n ? 'Crear ' + n + ' tarea' + (n === 1 ? '' : 's') : 'Marca al menos una';
    dlg.querySelector('[data-bl-iat-crear]').disabled = !n;
  };
  const pedir = async () => {
    const carg = dlg.querySelector('[data-bl-iat-cargando]'), lista = dlg.querySelector('[data-bl-iat-lista]'), err = dlg.querySelector('[data-bl-iat-error]');
    carg.hidden = false; lista.hidden = true; err.hidden = true;
    dlg.querySelector('[data-bl-iat-crear]').disabled = true;
    const fd = new FormData();
    fd.set('accion', 'bl_ia_tareas'); fd.set('ajax', '1'); fd.set('id', form.querySelector('[name="id"]').value);
    ['titulo', 'como', 'quiero', 'para'].forEach((k) => { const el = form.querySelector('[name="' + k + '"]'); if (el) fd.set(k, el.value); });
    const area = form.querySelector('[name="notas"]') && form.querySelector('[name="notas"]').closest('.rt');
    if (area && area.querySelector('.rt-area')) fd.set('notas', area.querySelector('.rt-area').innerHTML);
    // Los escenarios tal como están escritos
    const escs = {};
    form.querySelectorAll('[name^="escenarios["]').forEach((el) => {
      const m = el.name.match(/^escenarios\[(\d+)\]\[(\w+)\]$/);
      if (m) (escs[m[1]] = escs[m[1]] || {})[m[2]] = el.value;
    });
    fd.set('escenarios_json', JSON.stringify(Object.values(escs).filter((e) => (e.dado || e.cuando || e.entonces))));
    let j = null;
    try {
      const r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      j = await r.json();
    } catch (_) { j = null; }
    carg.hidden = true;
    if (!j || !j.ok) { err.textContent = j ? (j.error || 'No se pudo.') : 'No se pudo: revisa tu conexión.'; err.hidden = false; return; }
    propuestas = j.tareas || [];
    lista.innerHTML = propuestas.map((t, i) => '<li class="bl-iat__op">'
      + '<label class="bl-iat__chk"><input type="checkbox" checked data-bl-iat-op value="' + i + '"><span class="rl-multi__caja" aria-hidden="true"><svg viewBox="0 0 12 12" width="11" height="11"><path d="M2.5 6.2l2.3 2.3 4.7-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></label>'
      + '<div class="bl-iat__txt"><input class="bl-iat__tit" type="text" maxlength="200" value="' + esc(t.titulo) + '" data-bl-iat-tit="' + i + '" aria-label="Título de la tarea ' + (i + 1) + '">'
      + '<small>' + esc(String(t.descripcion || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()) + '</small></div>'
      + '<span class="bl-iat__prio bl-iat__prio--' + esc(t.prioridad) + '">' + esc(PRIO[t.prioridad] || t.prioridad || 'Media') + '</span></li>').join('');
    lista.hidden = false;
    contar();
  };
  document.addEventListener('click', (e) => {
    if (dlg && e.target.closest('[data-bl-ia-tareas]')) { dlg.showModal(); pedir(); return; }
    if (rep && e.target.closest('[data-bl-repartir]')) {
      if (sucio && !confirm('Tienes cambios sin guardar en la historia y se perderán al repartir. ¿Repartir igual?')) return;
      rep.showModal(); return;
    }
    const c = e.target.closest('[data-dlg-cerrar]');
    if (c && c.closest('dialog')) c.closest('dialog').close();
  });
  if (dlg) {
    dlg.addEventListener('change', (e) => { if (e.target.matches('[data-bl-iat-op]')) contar(); });
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.querySelector('[data-bl-iat-crear]').addEventListener('click', () => {
      const marcadas = [...dlg.querySelectorAll('[data-bl-iat-op]:checked')].map((c) => {
        const i = +c.value, t = propuestas[i] || {};
        const tit = (dlg.querySelector('[data-bl-iat-tit="' + i + '"]') || {}).value || t.titulo;
        return { titulo: String(tit).trim(), descripcion: t.descripcion || '', prioridad: t.prioridad || 'media' };
      }).filter((t) => t.titulo);
      if (!marcadas.length) return;
      form.querySelector('[data-bl-ia-campo]').value = JSON.stringify(marcadas);
      dlg.close();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  }
  if (rep) rep.addEventListener('click', (e) => { if (e.target === rep) rep.close(); });
})();

/* ---------- Listas largas de la historia (sus tareas): por páginas, para que no crezcan sin fin ----------
   El mismo paginador que Releases (.rl-pag): las páginas cerca de la actual, la primera y la última. */
(() => {
  const IZQ = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>';
  const DER = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
  document.querySelectorAll('ul[data-bl-paginar]').forEach((ul) => {
    const por = Math.max(1, parseInt(ul.dataset.blPaginar, 10) || 5);
    const items = [...ul.children];
    if (items.length <= por) return;
    const total = Math.ceil(items.length / por);
    const nav = document.createElement('nav');
    nav.className = 'rl-pag bl-pag';
    nav.setAttribute('aria-label', 'Páginas de tareas');
    ul.after(nav);
    const ir = (p) => {
      items.forEach((li, i) => { li.hidden = i < p * por || i >= (p + 1) * por; });
      const nums = [...new Set([0, total - 1, p - 1, p, p + 1].filter((x) => x >= 0 && x < total))].sort((x, y) => x - y);
      let h = '<span class="rl-pag__info">' + (p * por + 1) + '–' + Math.min(items.length, (p + 1) * por) + ' de ' + items.length + '</span><span class="rl-pag__btns">'
        + '<a href="#" role="button" class="mc-iconbtn mc-iconbtn--sm' + (p === 0 ? ' is-off' : '') + '" data-p="' + (p - 1) + '"' + (p === 0 ? ' aria-disabled="true"' : '') + ' aria-label="Página anterior">' + IZQ + '</a>';
      nums.forEach((x, i) => {
        if (i && x - nums[i - 1] > 1) h += '<span class="rl-pag__sep">…</span>';
        h += '<a href="#" role="button" class="rl-pag__n' + (x === p ? ' is-on' : '') + '" data-p="' + x + '"' + (x === p ? ' aria-current="page"' : '') + '>' + (x + 1) + '</a>';
      });
      nav.innerHTML = h + '<a href="#" role="button" class="mc-iconbtn mc-iconbtn--sm' + (p === total - 1 ? ' is-off' : '') + '" data-p="' + (p + 1) + '"' + (p === total - 1 ? ' aria-disabled="true"' : '') + ' aria-label="Página siguiente">' + DER + '</a></span>';
    };
    nav.addEventListener('click', (e) => {
      // Enlaces y no botones: dentro de un formulario de solo lectura (fieldset deshabilitado) siguen andando
      const b = e.target.closest('[data-p]');
      if (!b) return;
      e.preventDefault();
      if (b.getAttribute('aria-disabled') === 'true') return;
      ir(Math.max(0, Math.min(total - 1, +b.dataset.p)));
      ul.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    });
    ir(0);
  });
})();

/* ---------- El historial de la historia, en su modal (botón del reloj de la cabecera) ---------- */
(() => {
  const dlg = document.querySelector('[data-bl-hist-dlg]');
  if (!dlg) return;
  document.addEventListener('click', (e) => { if (e.target.closest('[data-bl-hist]')) dlg.showModal(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg || e.target.closest('[data-dlg-cerrar]')) dlg.close(); });
})();
