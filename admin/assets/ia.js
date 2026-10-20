/* =========================================================
   IA del panel (GPT y Gemini). Solo se carga para administradores y Scrum
   Masters con la IA configurada (UI::fin). Cada botón pide un BORRADOR a
   actions.php (ia_*) y lo deja en su sitio para que la persona lo revise:
   nada se guarda solo.

   Va en un archivo aparte a propósito: si falta en el servidor, el resto del
   panel funciona igual y solo desaparece la IA.
   ========================================================= */
(function () {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Nunca el alert() del navegador: el aviso del sistema (o, si faltara, la consola)
  const toast = (m, t, ms) => (window.MC && MC.toast ? MC.toast(m, t || 'info', ms || 4000) : console.warn(m));

  /** Un error: dentro del modal si el botón está en uno (un aviso suelto quedaría detrás), si no, aviso. */
  function avisarError(btn, msg) {
    const dlg = btn && btn.closest && btn.closest('dialog');
    const cuerpo = dlg && dlg.querySelector('.ia-cuerpo');
    if (!cuerpo) { toast(msg, 'error', 6000); return; }
    let a = cuerpo.querySelector('.ia-error');
    if (!a) {
      a = document.createElement('div');
      a.className = 'mc-alert mc-alert--danger mc-alert--compact ia-error';
      a.setAttribute('role', 'alert');
      cuerpo.prepend(a);
    }
    a.innerHTML = '<div class="mc-alert__content"><p class="mc-alert__desc">' + esc(msg) + '</p></div>';
    a.scrollIntoView({ block: 'nearest' });
  }

  /* ---------- Llamar al servidor ---------- */
  async function pedir(accion, datos) {
    const fd = datos instanceof FormData ? datos : new FormData();
    if (!(datos instanceof FormData)) Object.entries(datos || {}).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x)); else if (v != null) fd.append(k, v);
    });
    fd.set('accion', accion);
    let r;
    try {
      r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    } catch (e) {
      throw new Error('No hay conexión con el panel.');
    }
    let j = null;
    try { j = await r.json(); } catch (e) { /* respuesta no JSON */ }
    if (!j) throw new Error('El panel respondió algo inesperado (' + r.status + ').');
    if (!j.ok) throw new Error(j.error || 'La IA no pudo responder.');
    return j;
  }

  /** Mientras dura la llamada el botón muestra solo el giro, sin «Pensando…»
      (la misma clase btn-cargando que usan los botones de guardar). */
  async function conBoton(btn, fn) {
    if (!btn || btn.dataset.iaOcupado) return;
    btn.dataset.iaOcupado = '1';
    const cs = getComputedStyle(btn);
    const posAntes = btn.style.position;
    btn.style.setProperty('--spin-c', cs.color);
    if (cs.position === 'static') btn.style.position = 'relative';
    btn.classList.add('btn-cargando');
    btn.setAttribute('aria-busy', 'true');
    try {
      const viejo = btn.closest('dialog')?.querySelector('.ia-error');
      if (viejo) viejo.remove();
      await fn();
    }
    catch (e) { avisarError(btn, e.message); }
    finally {
      btn.classList.remove('btn-cargando');
      btn.removeAttribute('aria-busy');
      btn.style.position = posAntes;
      delete btn.dataset.iaOcupado;
    }
  }

  /** Pie de cada resultado: qué modelo respondió y cuánto costó. */
  const firma = (r) => r && r.modelo
    ? '<p class="ia-firma"><i class="fa-solid fa-wand-magic-sparkles"></i> ' + esc(r.modelo)
      + (r.respaldo ? ' (respaldo)' : '') + ' · US$ ' + Number(r.costo || 0).toFixed(4)
      + ' · Es un borrador: revísalo antes de usarlo.</p>'
    : '';

  /* ---------- Modal genérico ---------- */
  function modal(titulo, icono, cuerpo, botones) {
    const dlg = document.createElement('dialog');
    dlg.className = 'dlg-meca dlg-ia';
    dlg.innerHTML = '<div class="dlg-form"><header><h3 class="font-display"><i class="fa-solid ' + icono + '"></i> '
      + esc(titulo) + '</h3><button type="button" class="dlg-close" title="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>'
      + '<div class="ia-cuerpo">' + cuerpo + '</div><footer>' + (botones || '')
      + '<button type="button" class="btn-outline btn-meca ia-cerrar">Cerrar</button></footer></div>';
    document.body.appendChild(dlg);
    const cerrar = () => dlg.close();
    dlg.querySelector('.dlg-close').addEventListener('click', cerrar);
    dlg.querySelector('.ia-cerrar').addEventListener('click', cerrar);
    dlg.addEventListener('close', () => dlg.remove());
    dlg.showModal();
    return dlg;
  }

  /* ---------- Editor enriquecido del panel ---------- */
  function editor(raiz) {
    const rt = raiz && (raiz.matches && raiz.matches('[data-editor-rico]') ? raiz : raiz.querySelector('[data-editor-rico]'));
    if (!rt) return null;
    const area = rt.querySelector('.rt-area'), fuente = rt.querySelector('.rt-fuente');
    return {
      html: () => (fuente ? fuente.value : (area ? area.innerHTML : '')),
      vacio: () => !(area && area.innerText.trim()),
      poner(html, agregar) {
        if (!area) return;
        const prev = area.innerHTML;
        area.innerHTML = agregar && area.innerText.trim() ? prev + html : html;
        if (fuente) fuente.value = area.innerHTML;
        rt.classList.toggle('rt-vacio', !area.innerText.trim());
        area.dispatchEvent(new Event('input', { bubbles: true }));
        return prev;
      },
    };
  }

  /** Deja un "Deshacer" junto al botón para volver al texto anterior. */
  function ofrecerDeshacer(btn, ed, prev) {
    let d = btn.parentElement.querySelector('.ia-deshacer');
    if (!d) {
      d = document.createElement('button');
      d.type = 'button';
      d.className = 'btn-ghost btn-meca btn-sm ia-deshacer';
      d.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Deshacer';
      btn.after(d);
    }
    d.onclick = () => { ed.poner(prev, false); d.remove(); };
  }

  /** Mientras dura la llamada el botón muestra solo el giro, sin texto: la
      misma clase btn-cargando (admin.css) que pone admin.js al guardar. */
  async function conGiro(btn, fn) {
    if (!btn || btn.dataset.iaOcupado) return;
    btn.dataset.iaOcupado = '1';
    const cs = getComputedStyle(btn);
    const posAntes = btn.style.position;
    btn.style.setProperty('--spin-c', cs.color);   // el giro, del color del texto del botón
    if (cs.position === 'static') btn.style.position = 'relative';
    btn.classList.add('btn-cargando');             // sin disabled: lo pondría gris
    btn.setAttribute('aria-busy', 'true');
    try { await fn(); }
    finally {
      btn.classList.remove('btn-cargando');
      btn.removeAttribute('aria-busy');
      btn.style.position = posAntes;
      delete btn.dataset.iaOcupado;
    }
  }

  /* =========================================================
     1. Planificar desde un documento (modal «Extraer con IA» de planificar.php)
     ========================================================= */
  const fPlan = document.getElementById('ia-plan-form');
  if (fPlan) {
    const dlgPlan = fPlan.closest('dialog');
    const quitarError = () => { const v = dlgPlan && dlgPlan.querySelector('.ia-error'); if (v) v.remove(); };
    // Al cerrar se va el error; el documento y las indicaciones se quedan por si quiere volver a generar.
    if (dlgPlan) dlgPlan.addEventListener('close', quitarError);

    fPlan.addEventListener('submit', (e) => {
      e.preventDefault();
      const btn = e.submitter || fPlan.querySelector('button[type="submit"]');
      // Sin documento: el aviso va en el propio campo de subida (UI::archivo)
      const doc = fPlan.querySelector('input[type="file"][name="archivo"]');
      if (!doc || !doc.files.length) {
        const fx = doc && doc.closest('[data-archivo]');
        const errFx = fx && fx.querySelector('.fx-error');
        if (!errFx) { avisarError(btn, 'Sube el documento del plan (Excel, PDF, Word o CSV).'); return; }
        fx.classList.add('fx-err');
        errFx.textContent = 'Elige el documento del plan.';
        errFx.hidden = false;
        const zona = fx.querySelector('.fx-disparo');
        if (zona) zona.focus();
        return;
      }
      conGiro(btn, async () => {
        quitarError();
        try {
          const r = await pedir('ia_plan_documento', new FormData(fPlan));
          const ta = document.getElementById('plan-json');
          ta.value = r.json;
          ta.dispatchEvent(new Event('input', { bubbles: true }));
          const notas = document.getElementById('ia-plan-notas');
          if (notas) notas.innerHTML = '<div class="mc-alert mc-alert--tip" role="status"><div class="mc-alert__content">'
            + '<p class="mc-alert__title">' + esc(r.n) + ' tareas propuestas</p><div class="mc-alert__desc">'
            + (r.notas ? esc(r.notas) + ' ' : '')
            + 'Revísalas en el editor, pulsa «Validar sin crear» y después «Importar tareas».' + firma(r) + '</div></div></div>';
          if (dlgPlan) dlgPlan.close();
          ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
          toast(r.n + ' tareas propuestas: revísalas y valídalas antes de importar.', 'success', 6000);
        } catch (err) {
          // Dentro del modal; si ya lo cerraron mientras esperaba, como aviso
          if (dlgPlan && !dlgPlan.open) toast(err.message, 'error', 6000);
          else avisarError(btn, err.message);
        }
      });
    });
  }

  /* =========================================================
     Lista de tareas propuestas con casillas (acta y desglose)
     ========================================================= */
  function listaTareas(tareas) {
    return '<ul class="ia-tareas">' + tareas.map((t, i) => '<li><label class="chk-linea"><input type="checkbox" data-i="' + i + '" checked>'
      + '<span class="chk-caja"><i class="fa-solid fa-check"></i></span><span><b>' + esc(t.titulo) + '</b>'
      + (t.descripcion ? '<small>' + t.descripcion.replace(/<[^>]+>/g, ' ').slice(0, 220) + '</small>' : '')
      + '<small class="ia-meta">Prioridad: ' + esc(t.prioridad || 'media') + (t.depende_de ? ' · después de ' + esc(t.depende_de) : '') + '</small>'
      + '</span></label></li>').join('') + '</ul>';
  }

  async function crearMarcadas(dlg, pid, tareas, btn) {
    const marcadas = [...dlg.querySelectorAll('.ia-tareas input[data-i]:checked')].map((c) => tareas[+c.dataset.i]);
    if (!marcadas.length) { toast('Marca al menos una tarea.', 'error'); return; }
    await conBoton(btn, async () => {
      const r = await pedir('ia_crear_tareas', { proyecto_id: pid, tareas: JSON.stringify(marcadas) });
      toast(r.creadas + ' tarea' + (r.creadas === 1 ? '' : 's') + ' creada' + (r.creadas === 1 ? '' : 's') + '.', 'success');
      dlg.close();
      setTimeout(() => location.reload(), 700);
    });
  }

  const copiar = async (texto, btn) => {
    try { await navigator.clipboard.writeText(texto); toast('Copiado.', 'success', 2000); }
    catch (e) { toast('No se pudo copiar.', 'error'); }
  };

  /* =========================================================
     2. Acta de reunión
     ========================================================= */
  function pintarActa(r) {
    const bloque = (tit, lista) => lista && lista.length ? '<h4>' + tit + '</h4><ul>' + lista.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '';
    let h = '<div class="ia-acta"><h4>Resumen</h4><p>' + esc(r.resumen) + '</p>'
      + bloque('Temas', r.temas) + bloque('Acuerdos', r.acuerdos);
    if (r.pendientes && r.pendientes.length) {
      h += '<h4>Pendientes</h4><ul>' + r.pendientes.map((p) => '<li>' + (p.responsable ? '<b>' + esc(p.responsable) + ':</b> ' : '')
        + esc(p.que) + (p.fecha ? ' <small>(' + esc(p.fecha) + ')</small>' : '') + '</li>').join('') + '</ul>';
    }
    h += bloque('Riesgos y bloqueos', r.riesgos);
    if (r.tareas && r.tareas.length) h += '<h4>Tareas propuestas</h4><p class="campo-ayuda">Marca las que quieras crear en el proyecto.</p>' + listaTareas(r.tareas);
    return h + firma(r) + '</div>';
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-acta]');
    if (!b) return;
    e.preventDefault();
    const conZoom = b.dataset.zoom === '1';
    const ICO = '<i class="fa-solid fa-wand-magic-sparkles"></i>';
    const dlg = modal('Acta con IA — ' + (b.dataset.titulo || 'Reunión'), 'fa-file-signature',
      '<div class="ia-acta-origen">'
      // «Generar desde la transcripción» solo aparece si Zoom la tiene (se pregunta al abrir)
      + (conZoom ? '<div class="ia-acta-zoom" data-ia-acta-zoom-caja><span class="ia-buscando"><span class="mc-spinner"></span> Buscando la transcripción en Zoom…</span></div>' : '')
      + '<label class="mc-field__label" for="ia-acta-texto" data-ia-acta-rotulo>Pega el texto o las notas de la reunión</label>'
      + '<div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="ia-acta-texto" rows="6" data-ia-acta-texto placeholder="Texto de la reunión…"></textarea></div>'
      + '<button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md mc-btn--block" data-ia-acta-pegado>' + ICO + '<span class="mc-btn__label">Generar desde el texto</span></button>'
      + '</div><div data-ia-acta-res></div>',
      '<button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" data-ia-acta-copiar hidden><i class="fa-regular fa-copy"></i><span class="mc-btn__label">Copiar acta</span></button>'
      + '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-ia-acta-crear hidden><i class="fa-solid fa-list-check"></i><span class="mc-btn__label">Crear tareas marcadas</span></button>');
    dlg.classList.add('dlg-ia-ancho');
    if (conZoom) {
      const caja = dlg.querySelector('[data-ia-acta-zoom-caja]');
      pedir('ia_acta_hay', { reunion_id: b.dataset.reunion, ocurrencia: b.dataset.ocurrencia || '' }).then((r) => {
        if (!r.hay) { caja.remove(); return; }
        caja.innerHTML = '<button type="button" class="mc-btn mc-btn--primary mc-btn--md mc-btn--block" data-ia-acta-zoom>' + ICO + '<span class="mc-btn__label">Generar desde la transcripción de Zoom</span></button>';
        dlg.querySelector('[data-ia-acta-rotulo]').textContent = 'O, si prefieres, pega el texto de la reunión';
        caja.querySelector('[data-ia-acta-zoom]').addEventListener('click', (ev) => generar(ev.currentTarget, ''));
      }).catch(() => caja.remove());
    }
    let res = null;
    const generar = (btn, texto) => conBoton(btn, async () => {
      res = await pedir('ia_acta', { reunion_id: b.dataset.reunion, ocurrencia: b.dataset.ocurrencia || '', texto: texto || '' });
      dlg.querySelector('[data-ia-acta-res]').innerHTML = pintarActa(res);
      dlg.querySelector('.ia-acta-origen').hidden = true;
      dlg.querySelector('[data-ia-acta-copiar]').hidden = false;
      dlg.querySelector('[data-ia-acta-crear]').hidden = !(res.tareas && res.tareas.length);
    });
    dlg.querySelector('[data-ia-acta-pegado]').addEventListener('click', (ev) => {
      const t = dlg.querySelector('[data-ia-acta-texto]').value.trim();
      if (t.length < 30) { avisarError(ev.currentTarget, 'Pega el texto de la reunión (al menos unas líneas).'); return; }
      generar(ev.currentTarget, t);
    });
    dlg.querySelector('[data-ia-acta-copiar]').addEventListener('click', () => res && copiar(res.texto));
    dlg.querySelector('[data-ia-acta-crear]').addEventListener('click', (ev) => res && crearMarcadas(dlg, res.proyecto_id, res.tareas, ev.currentTarget));
  });

  /* =========================================================
     3. Requerimiento desde un texto
     ========================================================= */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-req]');
    if (!b) return;
    e.preventDefault();
    const form = b.closest('form');
    const dlg = modal('Requerimiento desde un texto', 'fa-envelope-open-text',
      '<p class="campo-ayuda">Pega el correo, el WhatsApp o la nota con el pedido. La IA propone los campos y busca si ya hay uno parecido.</p>'
      + '<textarea class="input-meca" rows="9" data-ia-req-texto placeholder="Pega aquí el mensaje…"></textarea>'
      + '<div data-ia-req-res></div>',
      '<button type="button" class="btn-primary btn-meca" data-ia-req-ir><i class="fa-solid fa-wand-magic-sparkles"></i> Rellenar el formulario</button>');
    dlg.querySelector('[data-ia-req-ir]').addEventListener('click', (ev) => {
      const texto = dlg.querySelector('[data-ia-req-texto]').value.trim();
      if (texto.length < 10) { toast('Pega el mensaje con el pedido.', 'error'); return; }
      conBoton(ev.currentTarget, async () => {
        const r = await pedir('ia_req_texto', { texto });
        const set = (sel, v) => { const el = form.querySelector(sel); if (el && v) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); } };
        set('input[name="titulo"]', r.titulo);
        set('input[name="solicitante"]', r.solicitante);
        const ed = editor(form.querySelector('[data-editor-rico]')?.closest('.campo') || form);
        if (ed && r.detalle) ed.poner(r.detalle, true);
        const pr = form.querySelector('select[name="prioridad"]');
        if (pr && r.prioridad) (window.setSelect ? setSelect(pr, r.prioridad) : (pr.value = r.prioridad));
        const fin = form.querySelector('input[name="fecha_fin"]');
        if (fin && r.fecha_fin) (window.setFecha ? setFecha(fin, r.fecha_fin) : (fin.value = r.fecha_fin));
        if (r.parecidos && r.parecidos.length) {
          dlg.querySelector('[data-ia-req-res]').innerHTML = '<div class="ia-aviso"><b>Ojo, puede que ya exista:</b><ul>'
            + r.parecidos.map((p) => '<li>#' + p.id + ' ' + esc(p.titulo) + '</li>').join('') + '</ul>'
            + 'El formulario ya quedó relleno; revísalo antes de crear.</div>' + firma(r);
          toast('Formulario relleno. Hay requerimientos parecidos: revísalos.', 'info', 6000);
        } else {
          toast('Formulario relleno: revísalo antes de crear.', 'success');
          dlg.close();
        }
      });
    });
  });

  /* =========================================================
     3c. Analizar un correo con la IA (solo el administrador): decide si el
     trabajo es de un proyecto (y arma sus tareas con responsable, fechas y
     descripción, lib/TareasCorreo.php) o un requerimiento suelto (y lo deja
     listo en Requerimientos, lib/ReqCorreo.php). Las dos opciones quedan a la
     vista; la sugerida va marcada. Sin responsable no se crea ninguna tarea.
     ========================================================= */
  const marcaCheck = '<svg class="mc-draw-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-correo]');
    if (!b) return;
    e.preventDefault();
    let proyectos = [];
    try { proyectos = JSON.parse(b.dataset.proyectos || '[]'); } catch (x) { proyectos = []; }
    const conReq = b.dataset.req === '1';
    const conTareas = b.dataset.tareas === '1' && proyectos.length > 0;
    const opcion = (valor, titulo, desc, on) => '<label class="tc-opcion' + (on ? '' : ' is-apagada') + '">'
      + '<input type="radio" class="mc-sr-only" name="tc-destino" value="' + valor + '"' + (on ? '' : ' disabled') + '>'
      + '<span class="tc-opcion__punto" aria-hidden="true"></span>'
      + '<span class="tc-opcion__txt"><b>' + titulo + '</b><small>' + desc + '</small></span>'
      + '<span class="tc-opcion__sug" hidden>Sugerido por la IA</span></label>';

    const dlg = modal('Analizar este correo', 'fa-wand-magic-sparkles',
      '<div class="tc-analizando" data-tc-analizando><span class="tc-giro" aria-hidden="true"></span>La IA está leyendo el correo para ver a dónde va el trabajo…</div>'
      + '<p class="tc-sugerencia" data-tc-sugerencia hidden></p>'
      + '<div class="tc-opciones" role="radiogroup" aria-label="¿A dónde va este trabajo?">'
      + opcion('proyecto', 'Tareas de un proyecto', 'Se crean en su tablero, cada una con su responsable, sus fechas y la descripción del correo.', conTareas)
      + opcion('requerimiento', 'Requerimiento suelto', 'Para una institución, sin proyecto: queda listo en Requerimientos para revisarlo y registrarlo.', conReq)
      + '</div>'
      + '<div class="tc-paso1" data-tc-paso-proyecto hidden>'
      + '<div class="mc-field"><label class="mc-field__label">Proyecto</label><select data-ds-select data-tc-proyecto aria-label="Proyecto">'
      + '<option value="">Elige el proyecto</option>' + proyectos.map((p) => '<option value="' + p.id + '">' + esc(p.nombre) + '</option>').join('') + '</select></div>'
      + '<div class="mc-field"><label class="mc-field__label" for="tc-ind">Indicaciones <span class="ds-opcional">(opcional)</span></label>'
      + '<div class="mc-input mc-input--md ds-input-area"><textarea class="mc-input__control" id="tc-ind" rows="2" data-tc-ind maxlength="1500" placeholder="Ej.: que sean dos tareas, diseño y publicación; asígnalas a Kevin"></textarea></div></div>'
      + '</div><div class="tc-lista" data-tc-lista></div>',
      '<button type="button" class="btn-primary btn-meca" data-tc-proponer hidden><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Proponer tareas</span></button>'
      + '<button type="button" class="btn-primary btn-meca" data-tc-crear hidden><i class="fa-solid fa-check"></i> <span>Crear tareas</span></button>'
      + '<button type="button" class="btn-primary btn-meca" data-tc-req hidden><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Armar el requerimiento</span></button>');
    dlg.classList.add('dlg-tc', 'dlg-ia-ancho');
    const analizando = dlg.querySelector('[data-tc-analizando]');
    const sugerencia = dlg.querySelector('[data-tc-sugerencia]');
    const pasoProy = dlg.querySelector('[data-tc-paso-proyecto]');
    const selProy = dlg.querySelector('[data-tc-proyecto]');
    const ind = dlg.querySelector('[data-tc-ind]');
    const lista = dlg.querySelector('[data-tc-lista]');
    const bProponer = dlg.querySelector('[data-tc-proponer]');
    const bCrear = dlg.querySelector('[data-tc-crear]');
    const bReq = dlg.querySelector('[data-tc-req]');
    let datos = null;
    let pidPropuesta = '';

    /* ---------- Las dos opciones ---------- */
    const destino = () => (dlg.querySelector('input[name="tc-destino"]:checked') || {}).value || '';
    const mostrar = () => {
      const d = destino();
      dlg.querySelectorAll('.tc-opcion').forEach((o) => o.classList.toggle('is-elegida', o.querySelector('input').checked));
      pasoProy.hidden = d !== 'proyecto';
      lista.hidden = d !== 'proyecto';
      bProponer.hidden = d !== 'proyecto';
      bCrear.hidden = d !== 'proyecto' || !datos;
      bReq.hidden = d !== 'requerimiento';
    };
    const elegir = (valor) => {
      const r = dlg.querySelector('input[name="tc-destino"][value="' + valor + '"]:not([disabled])')
        || dlg.querySelector('input[name="tc-destino"]:not([disabled])');
      if (r) r.checked = true;
      mostrar();
    };
    dlg.addEventListener('change', (ev) => { if (ev.target.name === 'tc-destino') mostrar(); });

    /* ---------- Requerimiento suelto: el flujo de siempre ---------- */
    bReq.addEventListener('click', () => conBoton(bReq, async () => {
      const r = await pedir('ia_req_correo', { c: b.dataset.c, uid: b.dataset.uid });
      if (r.url) location.href = r.url;
    }));

    /* ---------- Tareas de un proyecto ---------- */
    const leer = () => [...lista.querySelectorAll('.tc-tarea')].map((el) => {
      const t = datos.tareas[+el.dataset.i];
      const v = (k) => ((el.querySelector('[data-k="' + k + '"]') || {}).value || '');
      return {
        usar: el.querySelector('[data-tc-usar]').checked, el, ref: t.ref, depende_de: t.depende_de,
        titulo: v('titulo').trim(), descripcion: v('descripcion'), responsables: v('responsable') ? [+v('responsable')] : [],
        fecha_inicio: v('fecha_inicio'), fecha_limite: v('fecha_limite'), prioridad: v('prioridad'),
      };
    });
    const contar = () => {
      if (!datos) return;
      const todas = leer();
      todas.forEach((x) => {
        x.el.classList.toggle('is-apagada', !x.usar);
        x.el.classList.toggle('is-sin-resp', x.usar && !x.responsables.length);
      });
      const l = todas.filter((x) => x.usar);
      const sin = l.filter((x) => !x.responsables.length).length;
      bCrear.hidden = destino() !== 'proyecto';
      bCrear.querySelector('span').textContent = !l.length ? 'Marca al menos una tarea'
        : sin ? 'Falta elegir ' + sin + ' responsable' + (sin > 1 ? 's' : '')
        : 'Crear ' + l.length + ' tarea' + (l.length > 1 ? 's' : '');
      bCrear.disabled = !l.length || sin > 0;
    };
    lista.addEventListener('change', contar);
    lista.addEventListener('input', contar);
    // Otro proyecto: la propuesta era para el anterior (su equipo es otro)
    selProy.addEventListener('change', () => {
      if (datos && selProy.value !== pidPropuesta) {
        datos = null; lista.innerHTML = ''; bCrear.hidden = true;
        bProponer.className = 'btn-primary btn-meca';
        bProponer.querySelector('span').textContent = 'Proponer tareas';
      }
    });

    const pintar = (r) => {
      datos = r;
      const gente = r.gente || [];
      const prios = r.prioridades || { baja: 'Baja', media: 'Media', alta: 'Alta' };
      const opGente = (sel) => '<option value="">Elige el responsable</option>' + gente.map((g) => '<option value="' + g.id + '"' + (g.id === sel ? ' selected' : '') + '>'
        + esc(g.nombre) + (g.rol ? ' · ' + esc(g.rol) : '') + '</option>').join('');
      const opPrio = (sel) => Object.entries(prios).map(([k, v]) => '<option value="' + esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + esc(v) + '</option>').join('');
      lista.innerHTML = (r.notas ? '<div class="mc-alert mc-alert--info mc-alert--compact" role="status"><div class="mc-alert__content"><p class="mc-alert__desc">' + esc(r.notas) + '</p></div></div>' : '')
        + r.tareas.map((t, i) => '<article class="tc-tarea" data-i="' + i + '">'
          + '<label class="mc-check is-checked tc-tarea__usar"><input type="checkbox" class="mc-sr-only" data-tc-usar checked>'
          + '<span class="mc-check__box" aria-hidden="true">' + marcaCheck + '</span><span class="mc-check__text"><span class="mc-check__label">Crear esta tarea</span></span></label>'
          + '<div class="mc-field"><label class="mc-field__label">Título</label><div class="mc-input mc-input--md"><input class="mc-input__control" data-k="titulo" maxlength="150" value="' + esc(t.titulo) + '"></div></div>'
          + '<div class="mc-field"><label class="mc-field__label">Descripción</label><div class="mc-input mc-input--md ds-input-area"><textarea class="mc-input__control" rows="4" data-k="descripcion">' + esc(t.descripcion) + '</textarea></div></div>'
          + '<div class="tc-tarea__fila">'
          + '<div class="mc-field tc-resp"><label class="mc-field__label">Responsable</label><select data-ds-select data-ds-buscar data-k="responsable" aria-label="Responsable">' + opGente((t.responsables || [])[0] || 0) + '</select>'
          + (!(t.responsables || []).length ? '<small class="tc-falta">' + (t.responsables_texto ? 'El correo nombra a «' + esc(t.responsables_texto) + '», que no está en el equipo del proyecto: elige a quién.' : 'La IA no supo a quién: elige el responsable.') + '</small>' : '') + '</div>'
          + '<div class="mc-field"><label class="mc-field__label">Inicio</label><input type="date" data-k="fecha_inicio" value="' + esc(t.fecha_inicio) + '"></div>'
          + '<div class="mc-field"><label class="mc-field__label">Fecha límite</label><input type="date" data-k="fecha_limite" value="' + esc(t.fecha_limite) + '"></div>'
          + '<div class="mc-field"><label class="mc-field__label">Prioridad</label><select data-ds-select data-k="prioridad" aria-label="Prioridad">' + opPrio(t.prioridad) + '</select></div>'
          + '</div></article>').join('') + firma(r);
      if (typeof MecaDate !== 'undefined') MecaDate.init(lista);   // el calendario del panel
      bProponer.className = 'btn-outline btn-meca';
      bProponer.querySelector('span').textContent = 'Volver a proponer';
      contar();
    };

    bProponer.addEventListener('click', () => {
      if (!selProy.value) { avisarError(bProponer, 'Elige el proyecto donde van las tareas.'); return; }
      conBoton(bProponer, async () => {
        const r = await pedir('ia_tareas_correo', { c: b.dataset.c, uid: b.dataset.uid, proyecto_id: selProy.value, instrucciones: ind.value });
        pidPropuesta = selProy.value;
        pintar(r);
      });
    });

    bCrear.addEventListener('click', () => {
      const l = leer().filter((x) => x.usar);
      if (!l.length) return;
      if (l.some((x) => !x.titulo)) { avisarError(bCrear, 'Cada tarea necesita un título.'); return; }
      if (l.some((x) => !x.responsables.length)) { avisarError(bCrear, 'Elige el responsable de cada tarea.'); return; }
      conBoton(bCrear, async () => {
        const r = await pedir('tareas_correo_crear', {
          proyecto_id: pidPropuesta, mensaje_id: datos.mensaje_id || '', origen: datos.origen || '',
          tareas: JSON.stringify(l.map(({ el, usar, ...x }) => x)),
        });
        // Listo: queda a la vista con su enlace; al cerrar, el correo muestra lo que salió de él
        dlg.querySelector('.ia-cuerpo').innerHTML = '<div class="mc-alert mc-alert--success" role="status"><div class="mc-alert__content">'
          + '<p class="mc-alert__title">' + esc(r.mensaje) + '</p><p class="mc-alert__desc">Cada responsable recibió su aviso.</p></div></div>'
          + '<p class="tc-ayuda"><a class="btn-primary btn-meca" href="' + esc(r.url) + '">Ver el proyecto</a></p>';
        bProponer.hidden = true; bCrear.hidden = true; bReq.hidden = true;
        dlg.addEventListener('close', () => location.reload(), { once: true });
      });
    });

    /* ---------- Primero, la IA dice a dónde va (si esa función está encendida) ---------- */
    (async () => {
      if (b.dataset.clasificar !== '1') {
        analizando.hidden = true;
        elegir(conTareas ? 'proyecto' : 'requerimiento');
        return;
      }
      try {
        const r = await pedir('ia_destino_correo', { c: b.dataset.c, uid: b.dataset.uid });
        analizando.hidden = true;
        const sug = r.destino === 'proyecto' && conTareas ? 'proyecto' : (conReq ? 'requerimiento' : 'proyecto');
        sugerencia.textContent = (r.es_trabajo === false ? 'Ojo: parece que este correo no pide un trabajo. ' : '')
          + (r.destino === 'proyecto' ? 'La IA cree que es trabajo de ' + (r.proyecto ? '«' + r.proyecto + '»' : 'un proyecto') : 'La IA cree que es un requerimiento suelto')
          + '.' + (r.motivo ? ' ' + r.motivo : '');
        sugerencia.hidden = false;
        const marca = dlg.querySelector('input[name="tc-destino"][value="' + sug + '"]');
        if (marca) marca.closest('.tc-opcion').querySelector('.tc-opcion__sug').hidden = false;
        if (r.proyecto_id) {
          selProy.value = String(r.proyecto_id);
          if (window.DS && DS.refrescar) DS.refrescar(selProy);
        }
        elegir(sug);
        // Es de un proyecto conocido: de una vez propone sus tareas (igual se revisan antes de crear)
        if (sug === 'proyecto' && r.proyecto_id && r.es_trabajo !== false) bProponer.click();
      } catch (err) {
        analizando.hidden = true;
        elegir(conTareas ? 'proyecto' : 'requerimiento');
        avisarError(bProponer, err.message + ' Elige tú a dónde va el trabajo.');
      }
    })();
  });

  /* =========================================================
     4. Observaciones (en el compositor y en cada hilo)
     ========================================================= */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-obs]');
    if (!b) return;
    e.preventDefault();
    const form = b.closest('form.obs-composer');
    if (!form) return;
    const ed = editor(form);
    const fd = new FormData();
    fd.append('proyecto_id', form.querySelector('input[name="proyecto_id"]')?.value || '');
    fd.append('texto', ed ? ed.html() : '');
    form.querySelectorAll('select[name="tarea_id[]"] option:checked').forEach((o) => fd.append('tareas[]', o.value));
    if (b.dataset.iaObs === 'adjunto') {
      const files = form.querySelector('.oc-file')?.files || [];
      const vistos = [...files].filter((f) => /^image\//.test(f.type) || f.type === 'application/pdf' || /\.docx$/i.test(f.name));
      if (!vistos.length) { toast('Adjunta o pega primero una captura o un PDF.', 'error'); return; }
      vistos.slice(0, 6).forEach((f) => fd.append('adjuntos[]', f, f.name));
    }
    conBoton(b, async () => {
      const r = await pedir(b.dataset.iaObs === 'adjunto' ? 'ia_obs_adjunto' : 'ia_obs_redaccion', fd);
      if (!ed) return;
      const prev = ed.poner(r.html, b.dataset.iaObs === 'adjunto');
      ofrecerDeshacer(b, ed, prev);
      toast(b.dataset.iaObs === 'adjunto' ? 'Observación redactada: revísala antes de anotar.' : 'Redacción mejorada: revísala antes de anotar.', 'success');
    });
  });

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-obs-resumen]');
    if (!b) return;
    e.preventDefault();
    conBoton(b, async () => {
      const r = await pedir('ia_obs_resumen', { obs_id: b.dataset.iaObsResumen });
      modal('Resumen del hilo', 'fa-comments',
        '<p>' + esc(r.resumen) + '</p><p><span class="ia-estado ia-estado-' + esc(r.estado.replace(/\s+/g, '-')) + '">' + esc(r.estado) + '</span></p>'
        + (r.siguiente ? '<p><b>Siguiente paso:</b> ' + esc(r.siguiente) + '</p>' : '') + firma(r));
    });
  });

  /* =========================================================
     5. Tareas (modales de nueva y de editar tarea)
     ========================================================= */
  function datosTarea(b) {
    const form = b.closest('form');
    const ed = editor(form.querySelector('[data-editor-rico]')?.closest('.campo') || form);
    return {
      form, ed,
      d: {
        proyecto_id: b.dataset.proyecto || form.querySelector('input[name="proyecto_id"]')?.value || '',
        titulo: form.querySelector('input[name="titulo"]')?.value || '',
        descripcion: ed ? ed.html() : '',
        tarea_id: form.querySelector('input[name="id"]')?.value || '',
      },
    };
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-tarea]');
    if (!b) return;
    e.preventDefault();
    const { form, ed, d } = datosTarea(b);
    if (!d.titulo.trim()) { toast('Escribe primero el título de la tarea.', 'error'); form.querySelector('input[name="titulo"]')?.focus(); return; }
    const tipo = b.dataset.iaTarea;

    if (tipo === 'descripcion') {
      conBoton(b, async () => {
        const r = await pedir('ia_tarea_descripcion', d);
        if (ed) ofrecerDeshacer(b, ed, ed.poner(r.html, true));
        toast('Descripción propuesta: revísala.', 'success');
      });
    } else if (tipo === 'dependencias') {
      conBoton(b, async () => {
        const r = await pedir('ia_tarea_dependencias', d);
        const picker = form.querySelector('[data-dep-picker]');
        if (!r.dependencias.length) { toast('La IA no ve dependencias claras con otras tareas del proyecto.', 'info'); return; }
        if (picker && picker.depAPI) picker.depAPI.setDeps(r.dependencias.map((x) => x.id));
        modal('Dependencias sugeridas', 'fa-link',
          '<p class="campo-ayuda">Quedaron marcadas en el campo «Dependencias»; quita las que no correspondan.</p><ul>'
          + r.dependencias.map((x) => '<li><b>#' + x.id + ' ' + esc(x.titulo) + '</b>' + (x.motivo ? '<br><small>' + esc(x.motivo) + '</small>' : '') + '</li>').join('')
          + '</ul>' + firma(r));
      });
    } else if (tipo === 'desglose') {
      conBoton(b, async () => {
        const r = await pedir('ia_tarea_desglose', d);
        const dlg = modal('Subtareas propuestas', 'fa-sitemap',
          '<p class="campo-ayuda">Se crean como tareas del proyecto, en este orden y encadenadas. Desmarca las que no quieras.</p>'
          + listaTareas(r.tareas) + firma(r),
          '<button type="button" class="btn-primary btn-meca" data-ia-crear><i class="fa-solid fa-list-check"></i> Crear subtareas marcadas</button>');
        dlg.querySelector('[data-ia-crear]').addEventListener('click', (ev) => {
          form.closest('dialog')?.close();
          crearMarcadas(dlg, d.proyecto_id, r.tareas, ev.currentTarget);
        });
      });
    }
  });

  /* =========================================================
     6. Notas de despliegue
     ========================================================= */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-deploy]');
    if (!b) return;
    e.preventDefault();
    const form = b.closest('form');
    const proys = [...form.querySelectorAll('input[name="proyectos[]"]:checked')].map((c) => c.value);
    if (!proys.length) { toast('Marca al menos un proyecto.', 'error'); return; }
    conBoton(b, async () => {
      const r = await pedir('ia_deploy_notas', { proyectos: proys });
      const ta = form.querySelector('textarea[name="nota"]');
      if (ta) { ta.value = r.notas; ta.dispatchEvent(new Event('input', { bubbles: true })); }
      toast('Notas redactadas: revísalas antes de registrar.', 'success');
    });
  });

  /* =========================================================
     7. Resumen para el daily
     ========================================================= */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-daily]');
    if (!b) return;
    e.preventDefault();
    const dlg = modal('Resumen para el daily', 'fa-people-group',
      '<div class="ia-daily-pedir"><label class="campo" style="max-width:240px"><span>Desde</span>'
      + '<input class="input-meca" type="date" data-ia-daily-desde value="' + esc(b.dataset.desde || '') + '"></label>'
      + '<p class="campo-ayuda">Junta los commits de los repositorios del proyecto y las tareas que cada uno completó o tiene en curso.</p></div>'
      + '<div data-ia-daily-res></div>',
      '<button type="button" class="btn-outline btn-meca" data-ia-daily-copiar hidden><i class="fa-regular fa-copy"></i> Copiar</button>'
      + '<button type="button" class="btn-primary btn-meca" data-ia-daily-ir><i class="fa-solid fa-wand-magic-sparkles"></i> Generar</button>');
    dlg.classList.add('dlg-ia-ancho');
    let res = null;
    dlg.querySelector('[data-ia-daily-ir]').addEventListener('click', (ev) => conBoton(ev.currentTarget, async () => {
      res = await pedir('ia_daily', { proyecto_id: b.dataset.iaDaily, desde: dlg.querySelector('[data-ia-daily-desde]').value });
      const li = (xs) => xs && xs.length ? '<ul>' + xs.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '';
      dlg.querySelector('[data-ia-daily-res]').innerHTML = (res.general ? '<p class="ia-daily-gen">' + esc(res.general) + '</p>' : '')
        + '<div class="ia-daily-grid">' + res.personas.map((p) => '<div class="ia-daily-p"><h4>' + esc(p.nombre) + '</h4>'
          + (p.hizo.length ? '<b>Hizo</b>' + li(p.hizo) : '') + (p.sigue.length ? '<b>Sigue con</b>' + li(p.sigue) : '')
          + (p.bloqueos.length ? '<b class="ia-bloq">Bloqueos</b>' + li(p.bloqueos) : '') + '</div>').join('') + '</div>'
        + '<p class="campo-ayuda">' + res.commits + ' commits leídos desde el ' + esc(res.desde) + '.</p>' + firma(res);
      dlg.querySelector('[data-ia-daily-copiar]').hidden = false;
    }));
    dlg.querySelector('[data-ia-daily-copiar]').addEventListener('click', () => res && copiar(res.texto));
  });
})();
