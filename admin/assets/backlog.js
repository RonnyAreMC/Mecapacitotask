/* =========================================================
   Backlog estilo Jira (pestaña «Sprints» del proyecto, lib/backlog_ui.php):
   abrir una historia en su asistente (nueva, para editar o solo para verla),
   guardarla con sus diagramas y tareas, y arrastrar: en el backlog, entre
   sprints o de posición; en el tablero, entre columnas (su estado).
   Las acciones son bl_* de actions.php; lo editan el PO, el SM y el admin.
   ========================================================= */
(() => {
  const raiz = document.querySelector('[data-bl]');
  if (!raiz) return;
  const puede = raiz.dataset.puede === '1';
  const dlg = document.getElementById('bl-dlg');
  const form = document.getElementById('bl-form');
  let datos = {};
  try { datos = JSON.parse((document.getElementById('bl-datos') || {}).textContent || '{}').historias || {}; } catch (_) { datos = {}; }
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = (m, t) => { if (window.MC && MC.toast) MC.toast(m, t || 'success'); };
  const plano = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  async function post(datosPost) {
    const fd = datosPost instanceof FormData ? datosPost : new FormData();
    if (!(datosPost instanceof FormData)) Object.entries(datosPost).forEach(([k, v]) => fd.set(k, v == null ? '' : v));
    fd.set('ajax', '1');
    let j = null;
    try {
      const r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      j = await r.json();
    } catch (_) { j = null; }
    if (!j || !j.ok) throw new Error(j ? (j.error || 'No se pudo.') : 'No se pudo: revisa tu conexión.');
    return j;
  }
  const recargar = () => setTimeout(() => location.reload(), 500);

  /* ---------- El release elegido (lista de arriba): la pestaña se abre en él ---------- */
  raiz.querySelector('[data-bl-rel]')?.addEventListener('change', (e) => {
    const u = e.target.dataset.url || '';
    if (u) location.href = u.replace('__R__', encodeURIComponent(e.target.value));
  });

  /* ---------- El asistente ---------- */
  const fijarSelect = (name, valor) => {
    const sel = form.querySelector('select[name="' + name + '"]');
    if (!sel) return;
    sel.value = String(valor);
    if (sel.selectedIndex < 0) sel.selectedIndex = 0;
    if (window.DS && DS.refrescar && sel.closest('.mc-select')) DS.refrescar(sel);
  };
  const fijarRadio = (name, valor) => {
    const r = form.querySelector('input[name="' + name + '"][value="' + CSS.escape(String(valor)) + '"]') || form.querySelector('input[name="' + name + '"]');
    if (r) r.checked = true;
  };
  function llenar(h, sprint) {
    form.reset();
    const nueva = !h;
    h = h || { id: 0, titulo: '', como: '', quiero: '', para: '', criterios: '', notas: '', item_id: 0, sprint: sprint || '', estimacion: '', prioridad: 'media', estado: 'por_hacer', responsable: 0, tareas: [], adjuntos: [], historial: [] };
    form.querySelector('[name="id"]').value = h.id;
    ['titulo', 'como', 'quiero', 'para', 'criterios', 'notas'].forEach((k) => { const c = form.querySelector('[name="' + k + '"]'); if (c) c.value = h[k] || ''; });
    fijarSelect('item_id', h.item_id || 0);
    // Un sprint que ya pasó no está en la lista: se agrega para no perderlo
    const selS = form.querySelector('select[name="sprint"]');
    if (selS && h.sprint && ![...selS.options].some((o) => o.value === h.sprint)) selS.insertAdjacentHTML('beforeend', '<option value="' + esc(h.sprint) + '">' + esc(h.sprint) + ' (ya pasó)</option>');
    fijarSelect('sprint', h.sprint || '');
    fijarSelect('estado', h.estado || 'por_hacer');
    fijarSelect('responsable', h.responsable || 0);
    fijarRadio('estimacion', h.estimacion || '');
    fijarRadio('prioridad', h.prioridad || 'media');
    const ts = new Set((h.tareas || []).map(Number));
    form.querySelectorAll('input[name="tareas[]"][type="checkbox"]').forEach((c) => {
      c.checked = ts.has(Number(c.value));
      c.closest('.rl-multi__op')?.classList.toggle('is-on', c.checked);
    });
    const m = form.querySelector('[data-rl-multi]');
    const n = m && m.querySelector('[data-rl-multi-n]');
    if (n) n.textContent = ts.size + ' elegido(s)';
    // Sus archivos: se abren en otra pestaña; quien edita puede quitarlos
    const adj = form.querySelector('[data-bl-adj]');
    adj.innerHTML = (h.adjuntos || []).map((a) => '<li class="bl-adj__item"><a href="' + esc(a.ruta) + '" target="_blank" rel="noopener">'
      + (a.tipo === 'img' ? '<img src="' + esc(a.ruta) + '" alt="" loading="lazy">' : '<span class="bl-adj__doc">' + esc((a.nombre.split('.').pop() || 'doc').toUpperCase()) + '</span>')
      + '<span>' + esc(a.nombre) + '</span></a>'
      + (puede ? '<label class="bl-adj__quitar"><input type="checkbox" name="quitar_adjuntos[]" value="' + esc(a.ruta) + '"> Quitar</label>' : '') + '</li>').join('')
      || '<li class="bl-adj__vacio">' + (puede ? 'Todavía no tiene diagramas ni documentos.' : 'Sin diagramas ni documentos.') + '</li>';
    // Lo que pasó con ella
    const caja = form.querySelector('[data-bl-hist-caja]');
    const hist = form.querySelector('[data-bl-hist]');
    if (caja && hist) {
      caja.hidden = nueva || !(h.historial || []).length;
      hist.innerHTML = (h.historial || []).map((ev) => '<li><b>' + esc(ev.texto) + '</b><small>' + esc([ev.fecha, ev.quien].filter(Boolean).join(' · ')) + '</small></li>').join('');
    }
    const ceja = form.querySelector('[data-bl-ceja]');
    if (ceja) ceja.textContent = nueva ? 'Nueva historia' : h.codigo;
    const borrar = form.querySelector('[data-bl-borrar]');
    if (borrar) borrar.hidden = nueva;
    // Quien no la gestiona, solo la ve
    form.querySelectorAll('.bl-fs').forEach((fs) => { fs.disabled = !puede; });
    form.querySelectorAll('.bl-solo-edita').forEach((x) => { x.hidden = !puede; });
  }
  const abrir = (h, sprint) => { if (!dlg) return; llenar(h, sprint); dlg.showModal(); };
  document.addEventListener('click', (e) => {
    const nueva = e.target.closest('[data-bl-nueva]');
    if (nueva) { e.preventDefault(); abrir(null, nueva.dataset.sprint || ''); return; }
    const ab = e.target.closest('[data-bl-abrir]');
    if (ab) { e.preventDefault(); const h = datos[ab.dataset.blAbrir]; if (h) abrir(h); }
  });

  if (form && puede) {
    form.addEventListener('submit', async (e) => {
      if (e.defaultPrevented) return;   // el asistente por pasos lo frenó (avanzar o un campo pendiente)
      e.preventDefault();
      const btn = e.submitter || form.querySelector('[type="submit"]');
      if (btn) btn.disabled = true;
      try {
        const j = await post(new FormData(form));
        toast(j.mensaje || 'Guardada.');
        dlg.close();
        recargar();
      } catch (err) { toast(err.message, 'error'); if (btn) btn.disabled = false; }
    });
    form.querySelector('[data-bl-borrar]')?.addEventListener('click', async () => {
      const id = form.querySelector('[name="id"]').value;
      const h = datos[id];
      if (!h || !confirm('¿Borrar la historia ' + h.codigo + ' «' + h.titulo + '»? Sus tareas siguen en el proyecto.')) return;
      try { const j = await post({ accion: 'bl_historia_borrar', id }); toast(j.mensaje); dlg.close(); recargar(); } catch (err) { toast(err.message, 'error'); }
    });
  }

  /* ---------- Listas de casillas con buscador (como las de Releases) ---------- */
  document.addEventListener('input', (e) => {
    const q = e.target.closest && e.target.closest('#bl-form [data-rl-multi-q]');
    if (!q) return;
    const t = plano(q.value.trim());
    q.closest('[data-rl-multi]').querySelectorAll('.rl-multi__op').forEach((op) => { op.hidden = t !== '' && !plano(op.dataset.buscar).includes(t); });
  });
  document.addEventListener('change', (e) => {
    const c = e.target;
    if (!(c instanceof HTMLInputElement) || c.type !== 'checkbox' || !c.closest('#bl-form .rl-multi__op')) return;
    c.closest('.rl-multi__op').classList.toggle('is-on', c.checked);
    const m = c.closest('[data-rl-multi]');
    const n = m.querySelector('[data-rl-multi-n]');
    if (n) n.textContent = m.querySelectorAll('.rl-multi__op input:checked').length + ' elegido(s)';
  });

  if (!puede) return;

  /* ---------- Arrastrar ---------- */
  let arrastrada = null;
  const totales = (cont, cuenta, puntos, item) => {
    const items = [...cont.querySelectorAll(item)];
    const sec = cont.closest('[data-bl-sec], [data-bl-col]');
    if (!sec) return;
    const n = sec.querySelector(cuenta);
    const pts = sec.querySelector(puntos);
    const suma = items.reduce((s, x) => s + (parseInt(x.dataset.pts, 10) || 0), 0);
    if (n) n.textContent = sec.matches('[data-bl-col]') ? items.length : items.length + ' historia(s)';
    if (pts) {
      const b = pts.querySelector('b');
      if (b) b.textContent = suma; else pts.textContent = suma + ' pts';
    }
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
    // Una sección plegada se abre al pasar por encima
    const det = cont.closest('details');
    if (det && !det.open) det.open = true;
    const sel = esFila ? '.bl-fila' : '.bl-card';
    const ref = despuesDe(cont, e.clientY, sel);
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
    document.querySelectorAll(esFila ? '[data-bl-lista]' : '[data-bl-cards]').forEach((c) => totales(c, '[data-bl-n]', '[data-bl-pts]', sel));
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
    try { const j = await post(d); toast(j.mensaje); if (datos[el.dataset.blId] && j.historia) Object.assign(datos[el.dataset.blId], { sprint: j.historia.sprint, estado: j.historia.estado }); }
    catch (err) { toast(err.message, 'error'); recargar(); }
  });
})();
