/* =========================================================
   El sprint del proyecto (sprint.php, lib/backlog/sprint_ui.php).
   - Planificación: la capacidad se lee en su tabla y se edita en un modal (si
     cuenta, días que falta, dedicación, a mano; y los puntos por día), se
     recalcula frente a lo asignado y se guarda;
     pasar una historia del backlog al sprint (avisa si no está lista); cerrar o
     volver tarea una acción que quedó abierta de una retro anterior.
   - Retrospectiva: sumar y quitar acciones.
   - Lista para sprint: sumar y quitar condiciones de la DoR.
   Las acciones son bl_sprint_accion y bl_historia_mover de actions.php (los
   formularios van solos con bl_sprint_plan, bl_sprint_retro y bl_dor_conf).
   ========================================================= */
(() => {
  const raiz = document.querySelector('[data-bls]');
  if (!raiz) return;
  const toast = (m, t, ms, o) => { if (window.MC && MC.toast) MC.toast(m, t || 'success', ms || 4500, o || {}); };
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
  const pid = raiz.dataset.pid;
  const sprint = raiz.dataset.sprint;
  const num = (v) => { const n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return Number.isFinite(n) ? n : 0; };
  const fmt = (n) => String(Math.round(n * 10) / 10).replace('.', ',');
  // Como Backlog::tonoCarga: hasta el 100 % cumple, hasta el 115 % alerta, más es crítico
  const tono = (a, c) => (c <= 0 ? (a > 0 ? 'crit' : 'nm') : (a * 100 / c <= 100 ? 'ok' : (a * 100 / c <= 115 ? 'warn' : 'crit')));
  const pintarCarga = (cel, a, c) => {
    if (!cel) return;
    const k = tono(a, c);
    const pct = c > 0 ? Math.round(a * 100 / c) : null;
    const txt = pct !== null ? pct + ' %' : (a > 0 ? 'Sin capacidad' : '—');
    const bar = cel.querySelector('.rl-pbar');
    const b = cel.querySelector('b');
    if (bar) { bar.className = 'rl-pbar col-s-' + k; bar.setAttribute('aria-label', txt); bar.querySelector('i').style.width = Math.min(100, pct || 0) + '%'; }
    if (b) { b.className = 'mc-num col-s-' + k; b.textContent = txt; }
  };

  /* ---------- Capacidad: se lee en la tabla y se edita en un modal ----------
     Cada fila guarda lo suyo en campos ocultos (cuenta, ausencias, dedicacion,
     puntos); el modal los cambia, se recalcula todo y se guarda el formulario. */
  const tabla = raiz.querySelector('[data-bls-cap]');
  const form = raiz.querySelector('[data-bls-plan]');
  const pdIn = raiz.querySelector('[data-bls-ptsdia]');
  const DIAS = tabla ? num(tabla.dataset.dias) : 0;
  const HORAS = tabla ? (num(tabla.dataset.horas) || 8) : 8;
  const calc = (on, aus, ded, man, ptsDia) => {
    aus = Math.min(DIAS, Math.max(0, aus));
    const diasEf = on ? Math.round((DIAS - aus) * ded / 100 * 10) / 10 : 0;
    const calculado = Math.round(diasEf * ptsDia);
    return { on, aus, ded, diasEf, calculado, man, cap: on ? (man !== null ? man : calculado) : 0 };
  };
  const deFila = (tr, ptsDia) => {
    const v = (k) => (tr.querySelector('[data-bls-in="' + k + '"]') || {}).value || '';
    const m = v('puntos').trim();
    return calc(v('cuenta') === '1', num(v('ausencias')), num(v('dedicacion')) || 100, m !== '' ? Math.max(0, Math.round(num(m))) : null, ptsDia);
  };
  const poner = (sel, v, el) => { const x = (el || raiz).querySelector(sel); if (x) x.textContent = v; };
  const recalcular = () => {
    if (!tabla || !pdIn) return;   // solo lectura: lo pintó el servidor
    const ptsDia = num(pdIn.value);
    let total = 0, diasP = 0, personas = 0;
    tabla.querySelectorAll('[data-bls-fila]').forEach((tr) => {
      const r = deFila(tr, ptsDia);
      const asig = num(tr.dataset.asig);
      poner('[data-bls-dias]', r.on ? fmt(r.diasEf) + ' días · ' + fmt(r.diasEf * HORAS) + ' h' : '—', tr);
      poner('[data-bls-det]', r.ded + ' % · ' + (r.aus > 0 ? 'falta ' + fmt(r.aus) + ' día(s)' : 'sin ausencias'), tr);
      poner('[data-bls-pts]', r.cap, tr);
      poner('[data-bls-modo]', r.man !== null ? 'Puesta a mano' : 'Calculada', tr);
      if (!r.on) poner('[data-bls-est]', 'No cuenta en este sprint', tr);
      tr.classList.toggle('is-fuera', !r.on);
      pintarCarga(tr.querySelector('[data-bls-carga]'), asig, r.cap);
      total += r.cap; diasP += r.diasEf; if (r.on) personas++;
    });
    poner('[data-bls-ptsdia-txt]', fmt(ptsDia));
    poner('[data-bls-tot-cap]', total);
    poner('[data-bls-tot-dias]', fmt(diasP) + ' días-persona');
    poner('[data-bls-kpi-cap]', total);
    poner('[data-bls-kpi-gente]', personas + ' persona(s) · ' + fmt(diasP) + ' días-persona');
    const tot = raiz.querySelector('[data-bls-tot-carga]');
    const comp = tot ? num(tot.dataset.comp) : 0;
    if (tot) pintarCarga(tot.querySelector('[data-bls-carga]'), comp, total);
    const kc = raiz.querySelector('[data-bls-kpi-comp]');
    if (kc) {
      kc.className = 'rl-kpi col-s-' + (total || comp ? tono(comp, total) : 'nm');
      poner('[data-bls-kpi-pct]', total > 0 ? Math.round(comp * 100 / total) + ' % de la capacidad' : 'sin capacidad');
    }
  };
  const guardar = () => { recalcular(); if (form) (form.requestSubmit ? form.requestSubmit() : form.submit()); };
  // Lo que da en el modal: le quedan, capacidad y la carga frente a lo asignado
  const resumen = (caja, r, asig) => {
    const k = tono(asig, r.cap), pct = r.cap > 0 ? Math.round(asig * 100 / r.cap) : null;
    caja.innerHTML = r.on
      ? '<div><small>Le quedan</small><b>' + fmt(r.diasEf) + ' días · ' + fmt(r.diasEf * HORAS) + ' h</b></div>'
        + '<div><small>Capacidad</small><b>' + r.cap + ' pts</b></div><div><small>Asignado</small><b>' + asig + ' pts</b></div>'
        + '<span class="bls-carga"><span class="rl-pbar col-s-' + k + '"><i style="width:' + Math.min(100, pct || 0) + '%"></i></span><b class="mc-num col-s-' + k + '">' + (pct !== null ? pct + ' %' : (asig > 0 ? 'Sin capacidad' : '—')) + '</b></span>'
      : '<p>No cuenta en este sprint: no suma capacidad.</p>';
  };
  const dlgCap = raiz.querySelector('[data-bls-dlg-cap]');
  const dlgPd = raiz.querySelector('[data-bls-dlg-pd]');
  let fila = null;
  const marcarCheck = (c, on) => { c.checked = on; const l = c.closest('.mc-check'); if (l) l.classList.toggle('is-checked', on); };
  const delModal = () => {
    const d = dlgCap;
    const ded = d.querySelector('[name="dlg_ded"]:checked');
    const m = d.querySelector('[data-dlg-pts]').value.trim();
    return calc(d.querySelector('[name="dlg_cuenta"]').checked, num(d.querySelector('[data-dlg-aus]').value), ded ? num(ded.value) : 100,
      m !== '' ? Math.max(0, Math.round(num(m))) : null, num(pdIn ? pdIn.value : 0));
  };
  const pintarModal = () => {
    const r = delModal();
    poner('[data-dlg-pts-ayuda]', 'Vacío: la calculada (' + r.calculado + ' pts).', dlgCap);
    dlgCap.querySelector('[data-dlg-pts]').placeholder = String(r.calculado);
    dlgCap.querySelector('.bls-dlg__cuerpo').classList.toggle('is-fuera', !r.on);
    resumen(dlgCap.querySelector('[data-dlg-res]'), r, num(fila.dataset.asig));
  };
  if (dlgCap) {
    raiz.addEventListener('click', (e) => {
      const b = e.target.closest('[data-bls-editar]');
      if (!b) return;
      fila = b.closest('[data-bls-fila]');
      const r = deFila(fila, num(pdIn.value));
      poner('[data-dlg-tit]', 'Capacidad de ' + fila.dataset.nombre, dlgCap);
      marcarCheck(dlgCap.querySelector('[name="dlg_cuenta"]'), r.on);
      dlgCap.querySelector('[data-dlg-aus]').value = r.aus ? String(r.aus) : '0';
      const op = dlgCap.querySelector('[name="dlg_ded"][value="' + r.ded + '"]') || dlgCap.querySelector('[name="dlg_ded"][value="100"]');
      if (op) op.checked = true;
      dlgCap.querySelector('[data-dlg-pts]').value = r.man !== null ? String(r.man) : '';
      pintarModal();
      dlgCap.showModal();
    });
    dlgCap.addEventListener('input', pintarModal);
    dlgCap.addEventListener('change', (e) => { if (e.target.name === 'dlg_cuenta') marcarCheck(e.target, e.target.checked); pintarModal(); });
    dlgCap.querySelector('[data-dlg-guardar]').addEventListener('click', () => {
      const r = delModal();
      const v = (k, x) => { const el = fila.querySelector('[data-bls-in="' + k + '"]'); if (el) el.value = x; };
      v('cuenta', r.on ? '1' : ''); v('ausencias', String(r.aus)); v('dedicacion', String(r.ded)); v('puntos', r.man !== null ? String(r.man) : '');
      dlgCap.close();
      guardar();
    });
  }
  if (dlgPd) {
    const pintarPd = () => {
      const pd = num(dlgPd.querySelector('[data-dlg-pd]').value);
      let total = 0;
      tabla.querySelectorAll('[data-bls-fila]').forEach((tr) => { total += deFila(tr, pd).cap; });
      dlgPd.querySelector('[data-dlg-pd-res]').innerHTML = '<div><small>Capacidad del equipo</small><b>' + total + ' pts</b></div>';
    };
    raiz.addEventListener('click', (e) => {
      if (!e.target.closest('[data-bls-abrir-pd]')) return;
      dlgPd.querySelector('[data-dlg-pd]').value = pdIn.value;
      pintarPd();
      dlgPd.showModal();
    });
    dlgPd.addEventListener('input', pintarPd);
    dlgPd.querySelector('[data-dlg-pd-guardar]').addEventListener('click', () => { pdIn.value = dlgPd.querySelector('[data-dlg-pd]').value || '0'; dlgPd.close(); guardar(); });
  }
  [dlgCap, dlgPd].forEach((d) => d && d.addEventListener('click', (e) => { if (e.target.closest('[data-dlg-cerrar]') || e.target === d) d.close(); }));
  recalcular();

  /* ---------- Pasar una historia del backlog al sprint (avisa si no está lista) ---------- */
  raiz.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bls-pasar]');
    if (!b) return;
    b.disabled = true;
    try {
      const j = await post({ accion: 'bl_historia_mover', id: b.dataset.blsPasar, sprint });
      toast(j.mensaje);
      const noLista = b.dataset.dor === '0';
      if (noLista) toast(b.dataset.cod + ' no está lista para sprint', 'warning', 7000, { desc: 'Se pasó igual. Falta: ' + (b.dataset.falta || '') });
      setTimeout(() => location.reload(), noLista ? 1800 : 700);
    } catch (err) { b.disabled = false; toast(err.message, 'error'); }
  });

  /* ---------- Lo abierto de las retros anteriores: cerrarlo o volverlo tarea ---------- */
  raiz.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bls-acc-que]');
    if (!b) return;
    const li = b.closest('[data-bls-acc]');
    const que = b.dataset.blsAccQue;
    b.disabled = true;
    try {
      const j = await post({ accion: 'bl_sprint_accion', proyecto_id: pid, sprint: li.dataset.sprint, accion_id: li.dataset.blsAcc, que });
      toast(j.mensaje);
      if (que === 'hecha') {
        const ul = li.parentElement;
        li.remove();
        if (ul && !ul.children.length) ul.outerHTML = '<p class="bl-vacio">No quedó nada abierto de las retrospectivas anteriores.</p>';
      } else {
        const t = j.accion_sp && j.accion_sp.tarea_id;
        b.remove();
        if (t) li.querySelector('small').insertAdjacentHTML('beforeend', ' · <a href="proyecto.php?id=' + encodeURIComponent(pid) + '&amp;tarea=' + Number(t) + '">tarea #' + Number(t) + '</a>');
      }
    } catch (err) { b.disabled = false; toast(err.message, 'error'); }
  });

  /* ---------- Filas que se suman y se quitan: acciones de la retro y condiciones de la DoR ---------- */
  let n = 0;
  const sumar = (caja, tpl, foco = true) => {
    if (!caja || !tpl) return;
    const k = 'n' + Date.now().toString(36) + (n++);
    const div = document.createElement('div');
    div.innerHTML = tpl.innerHTML.split('__K__').join(k);
    const fila = div.firstElementChild;
    caja.appendChild(fila);
    const inp = fila.querySelector('input.mc-input__control');
    if (inp && foco) inp.focus();
  };
  const accs = raiz.querySelector('[data-bls-accs]');
  const tplAcc = raiz.querySelector('[data-bls-accf-tpl]');
  const dorc = raiz.querySelector('[data-bls-dorconf]');
  const tplDor = raiz.querySelector('[data-bls-dorc-tpl]');
  raiz.querySelector('[data-bls-accf-mas]')?.addEventListener('click', () => sumar(accs, tplAcc));
  raiz.querySelector('[data-bls-dorc-mas]')?.addEventListener('click', () => sumar(dorc, tplDor));
  raiz.addEventListener('click', (e) => {
    const q = e.target.closest('[data-bls-accf-quitar], [data-bls-dorc-quitar]');
    if (!q) return;
    const esAcc = q.matches('[data-bls-accf-quitar]');
    q.closest(esAcc ? '[data-bls-accf]' : '[data-bls-dorc]').remove();
    // Que siempre quede una fila para escribir
    if (esAcc && accs && !accs.querySelector('[data-bls-accf]')) sumar(accs, tplAcc, false);
    if (!esAcc && dorc && !dorc.querySelector('[data-bls-dorc]')) sumar(dorc, tplDor, false);
  });
  raiz.closest('body')?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-bls-restaurar]');
    if (b && !confirm('¿Volver a la lista de siempre? Se pierden las condiciones que se agregaron.')) e.preventDefault();
  });
})();
