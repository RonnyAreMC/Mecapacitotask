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

  /** Botón en "pensando…" mientras dura la llamada. */
  async function conBoton(btn, fn) {
    if (!btn || btn.dataset.iaOcupado) return;
    btn.dataset.iaOcupado = '1';
    const antes = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Pensando…';
    try {
      const viejo = btn.closest('dialog')?.querySelector('.ia-error');
      if (viejo) viejo.remove();
      await fn();
    }
    catch (e) { avisarError(btn, e.message); }
    finally { btn.disabled = false; btn.innerHTML = antes; delete btn.dataset.iaOcupado; }
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

  /* =========================================================
     1. Planificar desde un documento
     ========================================================= */
  const fPlan = document.getElementById('ia-plan-form');
  if (fPlan) fPlan.addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = fPlan.querySelector('button[type="submit"], button:not([type])');
    conBoton(btn, async () => {
      const r = await pedir('ia_plan_documento', new FormData(fPlan));
      const ta = document.getElementById('plan-json');
      ta.value = r.json;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      const notas = document.getElementById('ia-plan-notas');
      if (notas) notas.innerHTML = '<b>' + r.n + ' tareas propuestas.</b> ' + esc(r.notas || '')
        + ' Revísalas en el editor, pulsa «Validar sin crear» y después «Importar tareas».' + firma(r);
      ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
      toast(r.n + ' tareas propuestas: revísalas y valídalas antes de importar.', 'success', 6000);
    });
  });

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
