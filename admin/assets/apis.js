/* =========================================================
   APIs (apis.php): copiar la clave al portapapeles (la trae el servidor,
   que revisa permisos y lo anota), filtrar por proyecto o buscar, y la
   ventana del administrador para registrar o editar una API.
   Se carga con defer: ya están MC (admin.js) y DS (ds.js).
   ========================================================= */
(() => {
  const raiz = document.querySelector('[data-api-pagina]');
  if (!raiz) return;
  const toast = (m, t = 'success') => (window.MC && MC.toast ? MC.toast(m, t, t === 'error' ? 6000 : 4500) : console.warn(m));
  const plano = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  /* ---------- Copiar la clave ---------- */
  const traer = async (id, que) => {
    const fd = new FormData();
    fd.set('accion', 'apis_copiar');
    fd.set('id', id);
    fd.set('que', que);
    let r;
    try { r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin' }); } catch (e) { throw new Error('No hay conexión con el panel.'); }
    let j = null;
    try { j = await r.json(); } catch (e) { /* no era JSON */ }
    if (!j || !j.ok) throw new Error((j && j.error) || 'No se pudo traer la clave.');
    return String(j.valor || '');
  };
  // Sin la API del portapapeles (http, navegadores viejos): con un textarea oculto
  const copiarViejo = (texto) => {
    const t = document.createElement('textarea');
    t.value = texto;
    t.setAttribute('readonly', '');
    t.style.cssText = 'position:fixed;top:-9999px;opacity:0';
    document.body.append(t);
    t.select();
    const ok = document.execCommand('copy');
    t.remove();
    if (!ok) throw new Error('El navegador no dejó copiar. Prueba otra vez.');
  };
  // Último recurso: la clave seleccionada en una ventanita para copiarla con Ctrl+C
  const mostrarParaCopiar = (v) => {
    const d = document.createElement('dialog');
    d.className = 'ds-modal api-manual';
    d.innerHTML = '<div class="ds"><header class="ds-modal__head"><div><h2 class="ds-modal__title">Cópiala con Ctrl+C</h2>'
      + '<p class="ds-modal__sub">El navegador no dejó copiar solo. Ya está seleccionada.</p></div></header>'
      + '<div class="ds-modal__body"><input class="input-meca api-manual__val" readonly></div>'
      + '<footer class="ds-modal__foot"><button type="button" class="mc-btn mc-btn--primary mc-btn--md"><span class="mc-btn__label">Listo</span></button></footer></div>';
    document.body.append(d);
    const inp = d.querySelector('input');
    inp.value = v;
    d.querySelector('button').addEventListener('click', () => d.close());
    d.addEventListener('close', () => { inp.value = ''; d.remove(); });
    d.showModal();
    inp.focus();
    inp.select();
  };
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-api-copiar]');
    if (!b || b.disabled) return;
    e.preventDefault();
    b.disabled = true;
    const etiqueta = b.querySelector('.mc-btn__label');
    const antes = etiqueta ? etiqueta.textContent : '';
    const promesa = traer(b.dataset.apiCopiar, b.dataset.que || 'clave');
    promesa.catch(() => {});   // el error se muestra abajo
    let listo = false;
    try {
      // El texto se pide en el mismo clic: Safari no deja escribir en el portapapeles después de esperar al servidor
      if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
        await navigator.clipboard.write([new ClipboardItem({ 'text/plain': promesa.then((v) => new Blob([v], { type: 'text/plain' })) })]);
        listo = true;
      }
    } catch (err) { /* sigue por el otro camino */ }
    try {
      if (!listo) {
        const v = await promesa;
        if (navigator.clipboard && window.isSecureContext) {
          try { await navigator.clipboard.writeText(v); } catch (err) { copiarViejo(v); }
        } else copiarViejo(v);
      } else {
        await promesa;
      }
      if (etiqueta) etiqueta.textContent = 'Copiada';
      b.classList.add('is-copiada');
      toast('Clave copiada. Pégala en tu .env y no la compartas.');
    } catch (err) {
      // Si la clave llegó pero el portapapeles no se dejó usar, se muestra para copiarla a mano
      let v = '';
      try { v = await promesa; } catch (e2) { /* no llegó */ }
      if (v) mostrarParaCopiar(v); else toast(err.message, 'error');
    } finally {
      setTimeout(() => { b.disabled = false; b.classList.remove('is-copiada'); if (etiqueta) etiqueta.textContent = antes; }, 1800);
    }
  });

  /* ---------- Filtrar por proyecto y buscar ---------- */
  const buscar = raiz.querySelector('[data-api-buscar]');
  const sin = raiz.querySelector('[data-api-sin]');
  let filtro = '';
  const aplicar = () => {
    const t = plano(buscar ? buscar.value.trim() : '');
    let n = 0;
    raiz.querySelectorAll('[data-api-card]').forEach((c) => {
      const ps = String(c.dataset.proys || '');
      const paraTodos = ps === 'todos';
      // Las que son para todos también sirven en cada proyecto
      const okF = !filtro || (filtro === 'todos' ? paraTodos : (paraTodos || ps.split(',').includes(filtro)));
      const okB = !t || plano(c.dataset.buscar).includes(t);
      c.hidden = !(okF && okB);
      if (!c.hidden) n++;
    });
    if (sin) sin.hidden = n > 0;
  };
  if (buscar) buscar.addEventListener('input', aplicar);
  raiz.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-api-filtro]');
    if (!chip) return;
    filtro = chip.dataset.apiFiltro;
    raiz.querySelectorAll('[data-api-filtro]').forEach((x) => x.classList.toggle('is-on', x === chip));
    aplicar();
  });

  /* ---------- Registrar o editar (solo el administrador) ---------- */
  const dlg = document.getElementById('dlg-api');
  if (!dlg) return;
  const f = dlg.querySelector('form');
  const otro = f.querySelector('[data-api-otro]');
  const campo = (n) => f.querySelector('[name="' + n + '"]');
  const sincProv = () => {
    const r = f.querySelector('input[name="proveedor"]:checked');
    otro.style.display = r && r.value === 'otra' ? '' : 'none';
  };
  const ponerProv = (k) => {
    const r = f.querySelector('input[name="proveedor"][value="' + CSS.escape(k || 'otra') + '"]') || f.querySelector('input[name="proveedor"][value="otra"]');
    r.checked = true;
    sincProv();
  };
  f.addEventListener('change', (e) => {
    if (e.target.name !== 'proveedor') return;
    sincProv();
    // Sin nombre todavía: se pone el del proveedor para no escribirlo
    const nom = campo('nombre');
    if (!nom.value.trim() && e.target.value !== 'otra') nom.value = e.target.closest('label').querySelector('span').textContent;
  });
  const provBuscar = f.querySelector('[data-api-prov-buscar]');
  if (provBuscar) {
    provBuscar.addEventListener('input', () => {
      const t = plano(provBuscar.value.trim());
      f.querySelectorAll('.api-prov__op').forEach((op) => { op.hidden = t !== '' && !plano(op.dataset.nombre).includes(t) && !op.querySelector('input').checked; });
    });
    provBuscar.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
  }
  const clave = f.querySelector('[data-api-clave]');
  f.querySelector('[data-api-ver]').addEventListener('click', () => { clave.type = clave.type === 'password' ? 'text' : 'password'; clave.focus(); });

  const abrir = (a) => {
    f.reset();
    campo('id').value = a ? a.id : '';
    dlg.querySelector('[data-api-dlg-t]').textContent = a ? 'Editar la API' : 'Nueva API';
    ['nombre', 'proveedor_otro', 'uso', 'url', 'docs', 'notas'].forEach((k) => { campo(k).value = a ? (a[k] || '') : ''; });
    const amb = campo('ambiente');
    amb.value = a ? a.ambiente : 'produccion';
    if (window.DS && DS.refrescar) DS.refrescar(amb);
    const proys = f.querySelector('select[data-api-proys]');
    if (typeof window.setSelect === 'function') window.setSelect(proys, a ? a.proyectos.map(String) : []);
    clave.value = '';
    clave.type = 'password';
    clave.placeholder = a && a.pista ? 'Guardada (' + a.pista + '): déjala vacía para no cambiarla' : 'Pega la clave (ej. sk-…)';
    const quitar = f.querySelector('[data-api-quitar-clave]');
    quitar.hidden = !(a && a.pista);
    const chk = campo('quitar_clave');
    chk.checked = false;
    chk.dispatchEvent(new Event('change', { bubbles: true }));
    if (provBuscar) { provBuscar.value = ''; f.querySelectorAll('.api-prov__op').forEach((op) => { op.hidden = false; }); }
    ponerProv(a ? a.proveedor : 'otra');
    if (window.DS && DS.mejorar) DS.mejorar(dlg);
    dlg.showModal();
    const grilla = f.querySelector('.api-prov__grid');
    grilla.scrollTop = 0;
    const elegido = f.querySelector('input[name="proveedor"]:checked');
    if (a && elegido) elegido.closest('label').scrollIntoView({ block: 'nearest' });
  };
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-api-nueva]')) { e.preventDefault(); abrir(null); return; }
    const b = e.target.closest('[data-api-editar]');
    if (b) { e.preventDefault(); abrir(JSON.parse(b.dataset.apiEditar)); }
  });
})();
