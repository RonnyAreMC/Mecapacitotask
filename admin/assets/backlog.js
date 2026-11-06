/* =========================================================
   Backlog estilo Jira (lib/backlog_ui.php): la pestaña «Sprints» del proyecto
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
