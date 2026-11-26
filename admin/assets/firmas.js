/* =========================================================
   Firma de documentos (firmas.php, firma.php y «Mi firma» del perfil):
   - abrir los modales con data-fd-abrir="id";
   - la lista: filas que abren el documento y el buscador;
   - los firmantes del documento: agregar, ordenar, quitar y el cargo que
     se propone al elegir a la persona;
   - el lienzo para dibujar la firma (o la foto que se sube).
   ========================================================= */
(() => {
  const norm = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  /* ---------- Modales ---------- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fd-abrir]');
    if (!b) return;
    const d = document.getElementById(b.dataset.fdAbrir);
    if (!d) return;
    e.preventDefault();
    const otro = b.closest('dialog[open]');
    if (otro && otro !== d) otro.close();
    if (!d.open) d.showModal();
  });

  /* ---------- La lista ---------- */
  const centrarTab = () => {
    document.querySelectorAll('.fd-pagina .mc-tabs').forEach((tabs) => {
      const t = tabs.querySelector('.mc-tab.is-active');
      if (t && tabs.scrollWidth > tabs.clientWidth) tabs.scrollLeft = t.offsetLeft - (tabs.clientWidth - t.offsetWidth) / 2;
    });
  };
  setTimeout(centrarTab, 60);
  document.addEventListener('click', (e) => { if (e.target.closest('.fd-pagina .mc-tab')) setTimeout(centrarTab, 30); });
  document.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-href]');
    if (!tr || e.target.closest('a, button, input')) return;
    location.href = tr.dataset.href;
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target instanceof HTMLElement && e.target.matches('tr[data-href]')) location.href = e.target.dataset.href;
  });
  /* Formularios que tardan (armar el informe lee los repositorios): el botón queda «cargando» y no se envía dos veces */
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-fd-espera]');
    const b = f && f.querySelector('button');
    if (!b) return;
    if (b.classList.contains('is-loading')) { e.preventDefault(); return; }
    b.classList.add('is-loading');
    b.setAttribute('aria-busy', 'true');
    const lbl = b.querySelector('.mc-btn__label');
    if (lbl) lbl.textContent = f.dataset.fdEspera;
    const ico = b.querySelector('svg, img');
    if (ico) ico.replaceWith(Object.assign(document.createElement('span'), { className: 'mc-spinner' }));
    setTimeout(() => { b.disabled = true; }, 0);
  });
  document.querySelectorAll('[data-fd-buscar]').forEach((inp) => {
    const tabla = inp.closest('.mc-table');
    inp.addEventListener('input', () => {
      const q = norm(inp.value.trim());
      let n = 0;
      tabla.querySelectorAll('tr[data-buscar]').forEach((tr) => {
        const ok = !q || norm(tr.dataset.buscar).includes(q);
        tr.hidden = !ok;
        if (ok) n++;
      });
      const vacio = tabla.querySelector('.fd-sin-resultados');
      if (vacio) vacio.hidden = n > 0;
    });
  });

  /* ---------- Elegir un archivo: se ve su nombre ---------- */
  document.addEventListener('change', (e) => {
    const inp = e.target;
    if (!(inp instanceof HTMLInputElement) || inp.type !== 'file') return;
    const caja = inp.closest('[data-fd-archivo]');
    const nom = caja && caja.querySelector('[data-fd-archivo-nombre]');
    if (nom && inp.files && inp.files[0]) nom.textContent = inp.files[0].name;
  });

  /* ---------- Firmantes de un documento ---------- */
  document.querySelectorAll('[data-fd-form]').forEach((form) => {
    const caja = form.querySelector('[data-fd-firmantes]');
    const lista = caja.querySelector('[data-fd-lista]');
    const tpl = form.closest('dialog').querySelector('template[data-fd-tpl]');
    const agregar = caja.querySelector('[data-fd-agregar]');
    const error = form.querySelector('[data-fd-error]');
    const max = Number(caja.dataset.max) || 8;
    let roles = [];
    let iniciales = [];
    try { roles = JSON.parse(caja.dataset.roles || '[]'); iniciales = JSON.parse(caja.dataset.iniciales || '[]'); } catch (x) { /* sin datos */ }

    const numerar = () => {
      const filas = [...lista.children];
      filas.forEach((li, i) => {
        li.querySelector('[data-fd-n]').textContent = String(i + 1);
        li.querySelector('[data-fd-subir]').disabled = i === 0;
        li.querySelector('[data-fd-bajar]').disabled = i === filas.length - 1;
        li.querySelector('[data-fd-quitar]').disabled = filas.length === 1;
      });
      agregar.hidden = filas.length >= max;
    };
    const fila = (f) => {
      const li = tpl.content.firstElementChild.cloneNode(true);
      const rol = li.querySelector('[data-fd-rol]');
      const otro = li.querySelector('[data-fd-otro]');
      const uid = li.querySelector('[data-fd-uid]');
      const cargo = li.querySelector('[data-fd-cargo]');
      const r = (f && f.rol) || roles[Math.min(lista.children.length, roles.length - 1)] || '';
      if (r && !roles.includes(r)) { rol.value = '__otro'; otro.hidden = false; otro.querySelector('input').value = r; } else if (r) rol.value = r;
      if (f && f.uid) uid.value = String(f.uid);
      cargo.value = (f && f.cargo) || '';
      lista.appendChild(li);   // assets/ds.js convierte los <select> en la lista del sistema
      return li;
    };
    (iniciales.length ? iniciales : [{}]).forEach(fila);
    numerar();

    agregar.addEventListener('click', () => {
      const li = fila({ rol: roles[Math.min(lista.children.length, roles.length - 1)] });
      numerar();
      setTimeout(() => li.querySelector('.mc-select__trigger, [data-fd-uid]')?.focus(), 30);
    });
    lista.addEventListener('click', (e) => {
      const li = e.target.closest('.fd-fila');
      if (!li) return;
      if (e.target.closest('[data-fd-subir]') && li.previousElementSibling) li.previousElementSibling.before(li);
      else if (e.target.closest('[data-fd-bajar]') && li.nextElementSibling) li.nextElementSibling.after(li);
      else if (e.target.closest('[data-fd-quitar]') && lista.children.length > 1) li.remove();
      else return;
      numerar();
    });
    // «Usar la cadena de»: quien crea (Elaborado por) y los revisores de esa coordinación
    const coordCaja = caja.querySelector('[data-fd-coords]');
    coordCaja?.querySelector('select[data-fd-coord]')?.addEventListener('change', (e) => {
      let coords = [];
      let yo = {};
      try { coords = JSON.parse(coordCaja.dataset.fdCoords || '[]'); yo = JSON.parse(coordCaja.dataset.fdYo || '{}'); } catch (x) { /* nada */ }
      const c = coords.find((x) => x.id === e.target.value);
      if (!c) return;
      lista.innerHTML = '';
      fila({ uid: yo.uid, rol: roles[0], cargo: yo.cargo });
      c.firmantes.forEach((f) => fila(f));
      numerar();
    });
    lista.addEventListener('change', (e) => {
      const li = e.target.closest('.fd-fila');
      if (!li) return;
      if (e.target.matches('[data-fd-rol]')) {
        const otro = li.querySelector('[data-fd-otro]');
        otro.hidden = e.target.value !== '__otro';
        if (!otro.hidden) otro.querySelector('input').focus();
      }
      if (e.target.matches('[data-fd-uid]')) {
        // El cargo de la persona, si no se escribió otro a mano
        const op = e.target.selectedOptions[0];
        const c = li.querySelector('[data-fd-cargo]');
        const desc = (op && op.dataset.desc) || '';
        if (!c.value.trim() || c.value === c.dataset.auto) { c.value = desc; c.dataset.auto = desc; }
      }
    });

    form.addEventListener('submit', (e) => {
      let msg = '';
      const tit = form.querySelector('[name="titulo"]');
      const pdf = form.querySelector('input[type="file"][name="pdf"]');
      if (!tit.value.trim()) msg = 'Escribe el título del documento.';
      else if (pdf && pdf.required && !pdf.files.length) msg = 'Elige el PDF que se va a firmar.';
      else if (pdf && pdf.files[0] && !/\.pdf$/i.test(pdf.files[0].name) && pdf.files[0].type !== 'application/pdf') msg = 'El archivo tiene que ser un PDF. Si es un Word, guárdalo antes como PDF.';
      [...lista.children].forEach((li, i) => {
        const uid = li.querySelector('[data-fd-uid]');
        const rol = li.querySelector('[data-fd-rol]');
        const otro = li.querySelector('[data-fd-rol-otro]');
        const cargo = li.querySelector('[data-fd-cargo]');
        uid.name = 'firmantes[' + i + '][uid]';
        rol.name = 'firmantes[' + i + '][rol]';
        otro.name = 'firmantes[' + i + '][rol_otro]';
        cargo.name = 'firmantes[' + i + '][cargo]';
        if (!msg && !uid.value) msg = 'Elige a la persona del paso ' + (i + 1) + '.';
        if (!msg && rol.value === '__otro' && !otro.value.trim()) msg = 'Escribe el rol del paso ' + (i + 1) + ' (por ejemplo, «Validado por»).';
      });
      if (msg) {
        e.preventDefault();
        error.textContent = msg;
        error.hidden = false;
        return;
      }
      error.hidden = true;
      const btn = form.querySelector('[data-fd-enviar]');
      if (btn) { btn.disabled = true; btn.querySelector('.mc-btn__label').textContent = pdf && pdf.files.length ? 'Subiendo…' : 'Guardando…'; }
    });
  });

  /* ---------- Mi firma: dibujarla o subir una foto ---------- */
  document.querySelectorAll('[data-mf-form]').forEach((form) => {
    const canvas = form.querySelector('[data-mf-canvas]');
    const ayuda = form.querySelector('[data-mf-ayuda]');
    const png = form.querySelector('[data-mf-png]');
    const file = form.querySelector('input[name="firma_archivo"]');
    const error = form.querySelector('[data-mf-error]');
    const vista = form.querySelector('[data-mf-vista]');
    let modo = 'dibujar';
    let tipo = (form.querySelector('[data-mf-tipo].is-active') || {}).dataset?.mfTipo || 'grafica';
    const accion = form.querySelector('[data-mf-accion]');
    form.querySelectorAll('[data-mf-tipo]').forEach((b) => b.addEventListener('click', () => {
      tipo = b.dataset.mfTipo;
      form.querySelectorAll('[data-mf-tipo]').forEach((x) => { const on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', String(on)); });
      form.querySelectorAll('[data-mf-tipo-panel]').forEach((p) => { p.hidden = p.dataset.mfTipoPanel !== tipo; });
      if (accion) accion.value = tipo === 'electronica' ? 'firma_cert_guardar' : 'firma_mia_guardar';
      error.hidden = true;
      if (tipo === 'grafica' && modo === 'dibujar') requestAnimationFrame(() => { if (!trazos) canvas.dispatchEvent(new Event('ajustar')); });
    }));
    let trazos = 0;
    let tinta = '#1B2A6B';
    let ctx = null;
    let dibujando = false;
    let ult = null;

    const ajustar = () => {
      const r = canvas.getBoundingClientRect();
      if (!r.width || trazos) return;   // cambiar el tamaño borra lo dibujado
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.round(r.width * dpr);
      canvas.height = Math.round(r.height * dpr);
      ctx = canvas.getContext('2d');
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
    };
    if ('ResizeObserver' in window) new ResizeObserver(ajustar).observe(canvas);
    canvas.addEventListener('ajustar', ajustar);
    const pos = (e) => ({ x: e.offsetX, y: e.offsetY });
    const grosor = (e) => (e.pointerType === 'pen' && e.pressure ? 1.4 + 2.6 * e.pressure : 2.6);

    canvas.addEventListener('pointerdown', (e) => {
      if (!ctx) ajustar();
      if (!ctx) return;
      e.preventDefault();
      canvas.setPointerCapture(e.pointerId);
      dibujando = true;
      ult = pos(e);
      ctx.strokeStyle = tinta;
      ctx.lineWidth = grosor(e);
      ctx.beginPath();
      ctx.moveTo(ult.x, ult.y);
      ctx.lineTo(ult.x + 0.1, ult.y + 0.1);
      ctx.stroke();
      trazos++;
      ayuda.hidden = true;
      error.hidden = true;
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!dibujando) return;
      const p = pos(e);
      const medio = { x: (ult.x + p.x) / 2, y: (ult.y + p.y) / 2 };
      ctx.lineWidth = grosor(e);
      ctx.beginPath();
      ctx.moveTo(ult.mx ?? ult.x, ult.my ?? ult.y);
      ctx.quadraticCurveTo(ult.x, ult.y, medio.x, medio.y);
      ctx.stroke();
      ult = { x: p.x, y: p.y, mx: medio.x, my: medio.y };
    });
    ['pointerup', 'pointercancel'].forEach((t) => canvas.addEventListener(t, () => { dibujando = false; }));

    form.querySelector('[data-mf-borrar]').addEventListener('click', () => {
      if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
      trazos = 0;
      ayuda.hidden = false;
      ajustar();
    });
    form.querySelectorAll('[data-mf-tinta]').forEach((b) => b.addEventListener('click', () => {
      tinta = b.dataset.mfTinta;
      form.querySelectorAll('[data-mf-tinta]').forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-checked', String(x === b)); });
    }));
    form.querySelectorAll('[data-mf-modo]').forEach((b) => b.addEventListener('click', () => {
      modo = b.dataset.mfModo;
      form.querySelectorAll('[data-mf-modo]').forEach((x) => {
        const on = x === b;
        x.classList.toggle('is-active', on);
        x.setAttribute('aria-selected', String(on));
        x.tabIndex = on ? 0 : -1;
      });
      form.querySelectorAll('[data-mf-panel]').forEach((p) => { p.hidden = p.dataset.mfPanel !== modo; });
      error.hidden = true;
      if (modo === 'dibujar') requestAnimationFrame(ajustar);
    }));
    file.addEventListener('change', () => {
      const f = file.files && file.files[0];
      if (!f) return;
      form.querySelector('[data-mf-archivo-nombre]').textContent = f.name;
      vista.querySelector('img').src = URL.createObjectURL(f);
      vista.hidden = false;
      error.hidden = true;
    });

    form.addEventListener('submit', (e) => {
      let msg = '';
      if (tipo === 'electronica') {
        const p12 = form.querySelector('[data-mf-p12]');
        const clave = form.querySelector('[data-mf-clave]');
        png.value = '';
        file.value = '';
        if (!p12.files.length) msg = 'Elige tu archivo de firma electrónica (.p12).';
        else if (!clave.value) msg = 'Escribe la contraseña de tu firma electrónica.';
        if (msg) { e.preventDefault(); error.textContent = msg; error.hidden = false; }
        return;
      }
      const p12x = form.querySelector('[data-mf-p12]');
      if (p12x) p12x.value = '';
      if (modo === 'dibujar') {
        if (!trazos) msg = 'Dibuja tu firma en el recuadro, o elige «Subir una foto».';
        else { png.value = canvas.toDataURL('image/png'); file.value = ''; }
      } else {
        png.value = '';
        if (!file.files.length) msg = 'Elige la foto o el escaneo de tu firma.';
        else if (file.files[0].size > 6 * 1048576) msg = 'La imagen pesa más de 6 MB. Usa una más liviana.';
      }
      if (msg) { e.preventDefault(); error.textContent = msg; error.hidden = false; }
    });
  });

  /* ---------- El PDF: miniatura y visor (pdf.js, cargado desde cdnjs) ---------- */
  const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  const pdfjs = () => new Promise((listo, mal) => {
    let n = 0;
    const ver = () => {
      if (window.pdfjsLib) { window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; listo(window.pdfjsLib); return; }
      if (++n > 80) { mal(new Error('sin pdf.js')); return; }
      setTimeout(ver, 100);
    };
    ver();
  });
  const documentos = new Map();
  const abrirPdf = (url) => {
    if (!documentos.has(url)) documentos.set(url, pdfjs().then((lib) => lib.getDocument({ url, withCredentials: true }).promise));
    return documentos.get(url);
  };
  const dibujar = async (pagina, canvas, escala, giro) => {
    const vp = pagina.getViewport({ scale: escala, rotation: giro });
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.floor(vp.width * dpr);
    canvas.height = Math.floor(vp.height * dpr);
    canvas.style.width = Math.floor(vp.width) + 'px';
    canvas.style.height = Math.floor(vp.height) + 'px';
    await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: vp, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null }).promise;
  };

  // La primera hoja en la tarjeta del documento
  document.querySelectorAll('[data-fd-miniatura]').forEach(async (canvas) => {
    const caja = canvas.closest('.fd-miniatura');
    try {
      const pdf = await abrirPdf(canvas.dataset.pdf);
      const pag = await pdf.getPage(1);
      const ancho = caja.clientWidth || 360;
      await dibujar(pag, canvas, ancho / pag.getViewport({ scale: 1 }).width, 0);
      caja.classList.add('is-lista');
    } catch (e) {
      caja.classList.add('is-error');
    }
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-fd-ver]')) return;
    const d = document.getElementById('dlg-ver');
    if (d && !d.open) d.showModal();
  });

  // El visor
  document.querySelectorAll('dialog[data-fd-visor]').forEach((dlg) => {
    const hojas = dlg.querySelector('[data-vw-hojas]');
    const estado = dlg.querySelector('[data-vw-estado]');
    const inPag = dlg.querySelector('[data-vw-pag]');
    const total = dlg.querySelector('[data-vw-total]');
    const zoomTxt = dlg.querySelector('[data-vw-zoom]');
    let pdf = null, escala = 1, giro = 0, cargado = false, obs = null;
    const lienzos = [];

    const pintarZoom = () => { zoomTxt.textContent = Math.round(escala * 100) + '%'; };
    const ajustar = async () => {
      const p = await pdf.getPage(1);
      const vp = p.getViewport({ scale: 1, rotation: giro });
      escala = Math.max(0.3, Math.min(3, (hojas.clientWidth - 40) / vp.width));
    };
    const redibujar = () => {
      lienzos.forEach((c) => { c.dataset.listo = ''; });
      lienzos.forEach((c) => {
        const vp = c._vp1;
        const w = (giro % 180 === 0 ? vp.width : vp.height) * escala;
        const h = (giro % 180 === 0 ? vp.height : vp.width) * escala;
        c.style.width = Math.floor(w) + 'px';
        c.style.height = Math.floor(h) + 'px';
      });
      pintarZoom();
      if (obs) lienzos.forEach((c) => { obs.unobserve(c); obs.observe(c); });
    };
    const cargar = async () => {
      if (cargado) return;
      cargado = true;
      try {
        pdf = await abrirPdf(dlg.dataset.pdf);
        total.textContent = pdf.numPages;
        inPag.max = pdf.numPages;
        estado.remove();
        await ajustar();
        for (let n = 1; n <= pdf.numPages; n++) {
          const p = await pdf.getPage(n);
          const c = document.createElement('canvas');
          c.className = 'fd-vw__pag-canvas';
          c.dataset.n = n;
          c._pagina = p;
          c._vp1 = p.getViewport({ scale: 1 });
          c.setAttribute('aria-label', 'Página ' + n);
          hojas.appendChild(c);
          lienzos.push(c);
        }
        obs = new IntersectionObserver((ents) => {
          ents.forEach((en) => {
            const c = en.target;
            if (en.isIntersecting && c.dataset.listo !== '1') { c.dataset.listo = '1'; dibujar(c._pagina, c, escala, giro).catch(() => {}); }
          });
          const vis = ents.filter((en) => en.isIntersecting && en.intersectionRatio > 0.35).map((en) => +en.target.dataset.n);
          if (vis.length && document.activeElement !== inPag) inPag.value = Math.min(...vis);
        }, { root: hojas, rootMargin: '300px 0px', threshold: [0, 0.35, 0.7] });
        redibujar();
      } catch (e) {
        estado.textContent = 'No se pudo mostrar el PDF aquí. Descárgalo con el botón de la derecha.';
      }
    };
    const zoom = (f) => { if (!pdf) return; escala = Math.max(0.3, Math.min(3, Math.round((escala + f) * 100) / 100)); redibujar(); };
    dlg.querySelector('[data-vw-menos]').addEventListener('click', () => zoom(-0.15));
    dlg.querySelector('[data-vw-mas]').addEventListener('click', () => zoom(0.15));
    dlg.querySelector('[data-vw-ajustar]').addEventListener('click', async () => { if (!pdf) return; await ajustar(); redibujar(); });
    dlg.querySelector('[data-vw-girar]').addEventListener('click', () => { if (!pdf) return; giro = (giro + 90) % 360; redibujar(); });
    inPag.addEventListener('change', () => {
      const c = lienzos[Math.max(1, Math.min(lienzos.length, +inPag.value || 1)) - 1];
      if (c) c.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
    new MutationObserver(() => { if (dlg.open) cargar(); }).observe(dlg, { attributes: true, attributeFilter: ['open'] });
  });

  /* ---------- Firmando: la firma se dibuja sobre el modal mientras el servidor sella el PDF ---------- */
  const TRAZO = 'M14 62 C 24 30, 40 18, 44 40 S 40 78, 58 60 S 78 22, 88 40 S 96 70, 112 52 C 124 36, 132 30, 140 44 S 150 66, 166 50 S 196 30, 226 40';
  const firmando = (dlg, electronica) => {
    if (!dlg || dlg.querySelector('.fd-firmando')) return;
    const pasos = electronica
      ? ['Abriendo tu certificado…', 'Sellando el PDF con tu firma electrónica…', 'Guardando y avisando a quien sigue…']
      : ['Poniendo tu firma en la hoja de firmas…', 'Guardando el PDF firmado…', 'Avisando a quien sigue…'];
    const ov = document.createElement('div');
    ov.className = 'fd-firmando';
    ov.setAttribute('role', 'status');
    ov.setAttribute('aria-live', 'polite');
    ov.innerHTML = '<svg viewBox="0 0 240 90" aria-hidden="true"><path class="fd-firmando__linea" d="M8 80H232"/>'
      + '<path class="fd-firmando__trazo" pathLength="1" d="' + TRAZO + '"/>'
      + '<g class="fd-firmando__pluma"><path d="M0 0l-3-12 9-9 4 4-9 9z" fill="currentColor"/>'
      + '<animateMotion dur="2.4s" repeatCount="indefinite" keyPoints="0;1;1" keyTimes="0;0.62;1" calcMode="linear" path="' + TRAZO + '"/>'
      + '<animate attributeName="opacity" dur="2.4s" repeatCount="indefinite" values="1;1;0;0" keyTimes="0;0.62;0.86;1"/></g></svg>'
      + '<b>Firmando el documento</b><small></small>';
    dlg.appendChild(ov);
    const sm = ov.querySelector('small');
    let i = 0;
    sm.textContent = pasos[0];
    setInterval(() => { i = Math.min(i + 1, pasos.length - 1); sm.textContent = pasos[i]; }, 1700);
    dlg.addEventListener('cancel', (ev) => ev.preventDefault());   // mientras firma, Esc no lo cierra
  };
  window.addEventListener('pageshow', (ev) => { if (ev.persisted) document.querySelectorAll('.fd-firmando').forEach((x) => x.remove()); });

  /* ---------- Firmar: gráfica o electrónica ---------- */
  document.querySelectorAll('[data-fd-firmar]').forEach((form) => {
    const modo = form.querySelector('[data-fd-modo]');
    const error = form.querySelector('[data-fd-firmar-error]');
    const archivo = form.querySelector('[data-fd-cert-archivo]');
    const nombre = form.querySelector('[data-fd-cert-nombre]');
    const recordar = form.querySelector('[data-fd-cert-recordar]');
    const recordarVal = form.querySelector('[data-fd-recordar-val]');
    const tabs = form.querySelectorAll('[data-fd-tab]');
    const poner = (m) => {
      modo.value = m;
      tabs.forEach((t) => { const on = t.dataset.fdTab === m; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
      form.querySelectorAll('[data-fd-panel]').forEach((p) => { p.hidden = p.dataset.fdPanel !== m; });
      error.hidden = true;
    };
    tabs.forEach((t) => t.addEventListener('click', () => poner(t.dataset.fdTab)));
    form.querySelector('[data-fd-cert-cargar]')?.addEventListener('click', () => archivo.click());
    archivo?.addEventListener('change', () => {
      const f = archivo.files && archivo.files[0];
      if (!f) return;
      nombre.textContent = f.name;
      form.querySelector('.fd-cert__rec')?.remove();
      if (recordar) { recordar.disabled = false; recordar.title = 'Dejarlo cargado hasta que cierres sesión'; }
      error.hidden = true;
    });
    recordar?.addEventListener('click', () => {
      const on = recordar.getAttribute('aria-pressed') !== 'true';
      recordar.setAttribute('aria-pressed', String(on));
      recordar.classList.toggle('is-on', on);
      recordarVal.value = on ? '1' : '';
    });
    form.addEventListener('submit', (e) => {
      if (e.submitter && e.submitter.form !== form) return;
      let msg = '';
      if (modo.value === 'grafica') {
        if (form.dataset.tieneFirma !== '1') msg = 'Primero guarda tu firma gráfica, o usa la firma electrónica.';
        const clave = form.querySelector('input[name="clave"]');
        const conf = form.querySelector('input[name="confirmo"]');
        if (!msg && clave && !clave.value) msg = 'Escribe tu contraseña del Hub.';
        if (!msg && conf && !conf.checked) msg = 'Marca la casilla para confirmar que firmas.';
      } else {
        const hay = (archivo.files && archivo.files.length) || nombre.textContent.trim() !== 'Ningún archivo cargado';
        if (!hay) msg = 'Carga tu archivo de firma electrónica (.p12).';
        else if (!form.querySelector('input[name="clave_cert"]').value) msg = 'Escribe la contraseña de tu firma electrónica.';
      }
      if (msg) { e.preventDefault(); error.textContent = msg; error.hidden = false; return; }
      const b = form.querySelector('.ds-modal__foot .mc-btn--primary');
      if (b) { setTimeout(() => { b.disabled = true; }, 0); b.querySelector('.mc-btn__label').textContent = 'Firmando…'; }
      firmando(form.closest('dialog'), modo.value === 'electronica');
    });
  });

  /* ---------- Observar: la carta se ve mientras se escribe ---------- */
  document.querySelectorAll('[data-fd-observar]').forEach((form) => {
    const txt = form.querySelector('textarea[name="motivo"]');
    const carta = form.querySelector('[data-fd-carta]');
    const error = form.querySelector('[data-fd-obs-error]');
    txt.addEventListener('input', () => { carta.textContent = txt.value; error.hidden = true; });
    form.addEventListener('submit', (e) => {
      const v = txt.value.trim();
      let msg = '';
      if (v.length < 5) msg = 'Escribe las observaciones.';
      else if (v === (txt.dataset.fdPlantilla || '').trim() || /\n1\.\s*\n/.test(txt.value)) msg = 'Escribe al menos una observación en el punto 1.';
      if (msg) { e.preventDefault(); error.textContent = msg; error.hidden = false; txt.focus(); }
    });
  });

  /* ---------- Historial: de a pocas filas ---------- */
  document.querySelectorAll('[data-fd-hist]').forEach((caja) => {
    const filas = [...caja.querySelectorAll('[data-fd-hist-fila]')];
    const por = +caja.dataset.porPagina || 8;
    const paginas = Math.max(1, Math.ceil(filas.length / por));
    let pag = 1;
    const pintar = () => {
      filas.forEach((f, i) => { f.hidden = i < (pag - 1) * por || i >= pag * por; });
      const ini = filas.length ? (pag - 1) * por + 1 : 0;
      caja.querySelector('[data-fd-hist-cuenta]').innerHTML = 'Mostrando <b>' + ini + ' - ' + Math.min(pag * por, filas.length) + '</b> de <b>' + filas.length + '</b> registros';
      caja.querySelector('[data-fd-hist-pag]').textContent = pag;
      caja.querySelector('[data-fd-hist-total]').textContent = paginas;
      caja.querySelectorAll('[data-fd-hist-ir]').forEach((b) => {
        const a = b.dataset.fdHistIr;
        b.disabled = ((a === 'primera' || a === 'antes') && pag === 1) || ((a === 'despues' || a === 'ultima') && pag === paginas);
      });
    };
    caja.addEventListener('click', (e) => {
      const b = e.target.closest('[data-fd-hist-ir]');
      if (!b) return;
      pag = { primera: 1, antes: pag - 1, despues: pag + 1, ultima: paginas }[b.dataset.fdHistIr];
      pag = Math.max(1, Math.min(paginas, pag));
      pintar();
    });
    pintar();
  });

  /* ---------- Informes semanales: las personas elegidas se marcan ---------- */
  document.addEventListener('change', (e) => {
    const inp = e.target;
    if (inp instanceof HTMLInputElement && inp.closest('.fi-persona')) inp.closest('.fi-persona').classList.toggle('is-on', inp.checked);
  });

  /* ---------- Parámetros: el modal del cargo, con los datos de la fila ---------- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fc-editar]');
    if (!b) return;
    const d = document.getElementById('dlg-cargo');
    if (!d) return;
    d.querySelector('[data-fc-uid]').value = b.dataset.uid;
    d.querySelector('[data-fc-nombre]').textContent = b.dataset.nombre;
    d.querySelector('[data-fc-cargo]').value = b.dataset.cargo || '';
    d.querySelector('[data-fc-rol]').textContent = b.dataset.rol || 'sin rol';
    d.showModal();
    setTimeout(() => d.querySelector('[data-fc-cargo]').focus(), 30);
  });
})();
