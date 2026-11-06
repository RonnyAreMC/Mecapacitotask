/* =========================================================
   El sprint del proyecto (sprint.php, lib/sprint_ui.php).
   - Planificación: la capacidad se recalcula mientras se escribe (si cuenta,
     días que falta, dedicación, puntos por día o a mano) frente a lo asignado;
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

  /* ---------- Capacidad: se recalcula al escribir ---------- */
  const tabla = raiz.querySelector('[data-bls-cap]');
  const recalcular = () => {
    const dias = num(tabla.dataset.dias);
    const horas = num(tabla.dataset.horas) || 8;
    const pd = raiz.querySelector('[data-bls-ptsdia]');
    if (!pd) return;   // solo lectura: lo pintó el servidor
    const ptsDia = num(pd.value);
    let total = 0;
    let diasP = 0;
    let personas = 0;
    tabla.querySelectorAll('[data-bls-fila]').forEach((tr) => {
      const c = tr.querySelector('input[type="checkbox"][name$="[cuenta]"]');
      const on = c ? c.checked : true;
      const aus = Math.min(dias, Math.max(0, num((tr.querySelector('[data-bls-aus]') || {}).value)));
      const sel = tr.querySelector('select[data-bls-ded]');
      const ded = sel ? num(sel.value) : 100;
      const diasEf = on ? Math.round((dias - aus) * ded / 100 * 10) / 10 : 0;
      const calc = Math.round(diasEf * ptsDia);
      const pin = tr.querySelector('[data-bls-pts]');
      if (pin) pin.placeholder = String(calc);
      const man = pin && pin.value.trim() !== '' ? Math.max(0, Math.round(num(pin.value))) : null;
      const cap = on ? (man !== null ? man : calc) : 0;
      const celD = tr.querySelector('[data-bls-dias]');
      if (celD) celD.textContent = on ? fmt(diasEf) + ' días · ' + fmt(diasEf * horas) + ' h' : '—';
      tr.classList.toggle('is-fuera', !on);
      pintarCarga(tr.querySelector('[data-bls-carga]'), num(tr.dataset.asig), cap);
      total += cap;
      diasP += diasEf;
      if (on) personas++;
    });
    const poner = (sel, v) => { const el = raiz.querySelector(sel); if (el) el.textContent = v; };
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
  if (tabla) {
    raiz.addEventListener('input', (e) => { if (e.target.closest('[data-bls-cap], [data-bls-ptsdia]')) recalcular(); });
    raiz.addEventListener('change', (e) => { if (e.target.closest('[data-bls-cap], [data-bls-ptsdia]')) recalcular(); });
  }

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
