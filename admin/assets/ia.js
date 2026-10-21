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
  /* Inno, la mascota de la IA (iconos/ilustraciones/ia-mascota.svg), y la carga mientras la IA
     piensa: la mascota que flota y parpadea, un mensaje que va cambiando y un esqueleto con la
     forma de lo que va a llegar (tarjetas de tareas, la respuesta o el requerimiento).
     mostrarCarga(contenedor, forma, mensajes) la pone y devuelve la función que la quita. */
  const MASCOTA_IA = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160" class="ia-masc" aria-hidden="true" focusable="false"><ellipse class="ia-masc__sombra" cx="80" cy="151" rx="34" ry="5.5" fill="#10285A" opacity=".12"/><g class="ia-masc__bot"><path d="M80 31V17" stroke="#10285A" stroke-width="4" stroke-linecap="round"/><circle class="ia-masc__luz" cx="80" cy="12" r="7.5" fill="#21D866"/><rect x="21" y="54" width="15" height="30" rx="7.5" fill="#3399FF"/><rect x="124" y="54" width="15" height="30" rx="7.5" fill="#3399FF"/><rect x="32" y="30" width="96" height="78" rx="32" fill="#FFFFFF" stroke="#BFD3F7" stroke-width="3"/><rect x="44" y="44" width="72" height="50" rx="21" fill="#10285A"/><g class="ia-masc__ojos"><rect x="58" y="58" width="12" height="17" rx="6" fill="#21D866"/><rect x="90" y="58" width="12" height="17" rx="6" fill="#3399FF"/></g><path d="M71 83q9 7 18 0" fill="none" stroke="#FFFFFF" stroke-width="3.2" stroke-linecap="round"/><path class="ia-masc__brazo-i" d="M54 120q-15 5-17 19" fill="none" stroke="#10285A" stroke-width="7" stroke-linecap="round"/><path class="ia-masc__brazo-d" d="M106 120q15-5 19-19" fill="none" stroke="#10285A" stroke-width="7" stroke-linecap="round"/><rect x="52" y="110" width="56" height="36" rx="17" fill="#FFFFFF" stroke="#BFD3F7" stroke-width="3"/><g transform="translate(67.8 115.5) scale(.4)"><path d="M8.14482 11.0762C10.2741 8.99499 12.3771 6.93687 14.5502 4.81236C18.2004 8.44943 21.9266 12.1644 25.6558 15.8823C25.7406 15.839 25.8225 15.7957 25.9073 15.7553C25.9073 15.5157 25.9073 15.2733 25.9073 15.0337C25.9073 10.2997 25.919 5.56287 25.8956 0.828902C25.8927 0.193858 26.0565 -0.0110884 26.7234 0.000457877C29.1685 0.0408698 31.6166 0.0379832 34.0618 0.00334444C34.7316 -0.00531525 34.9509 0.159219 34.948 0.849108C34.9188 5.5138 34.9334 10.1785 34.9334 14.8432C34.9334 15.1347 34.9334 15.4291 34.9334 15.8419C38.7737 12.0432 42.5029 8.35417 46.1706 4.72576C48.4081 6.92821 50.5169 9.00365 52.6754 11.1253C52.5848 11.2205 52.4356 11.3937 52.2747 11.5554C48.683 15.103 45.0884 18.6477 41.4967 22.1981C35.3867 28.2426 25.7523 28.3523 19.5692 22.3944C15.8167 18.7718 12.1343 15.077 8.42268 11.4139C8.31153 11.3014 8.21794 11.1657 8.14482 11.0762Z" fill="#000080"/><path d="M24.5483 59.1869C21.7726 58.4566 19.0467 57.735 16.3149 57.0278C15.765 56.8863 15.9229 56.5515 16.0165 56.2109C16.9905 52.6344 17.9645 49.0608 18.9355 45.4844C19.2719 44.2461 19.6024 43.0077 19.9358 41.7694C19.8861 41.7261 19.8393 41.6799 19.7896 41.6366C15.2268 44.2316 10.6612 46.8295 6.0253 49.465C5.47251 48.5297 4.95774 47.6666 4.44881 46.7978C3.58014 45.317 2.73779 43.8217 1.83694 42.3611C1.52398 41.8531 1.61172 41.6222 2.11772 41.3364C6.28269 38.9867 10.433 36.6111 14.5863 34.2441C14.8027 34.12 15.0133 33.9843 15.3701 33.7678C15.0016 33.6466 14.794 33.5629 14.5775 33.5052C9.94458 32.2813 5.31457 31.0458 0.675777 29.8479C0.0557122 29.6862 -0.113928 29.4842 0.0703364 28.8462C0.763522 26.4331 1.40991 24.0084 2.0446 21.5779C2.17037 21.0958 2.36048 21.0034 2.84016 21.1305C7.90304 22.4871 12.9835 23.7919 18.0376 25.1745C26.1306 27.3885 30.9215 35.5517 28.8302 43.5677C27.5228 48.5817 26.1277 53.5754 24.7706 58.575C24.715 58.7741 24.6302 58.9646 24.5483 59.1869Z" fill="#21D866"/><path d="M36.2513 59.2002C35.7161 57.2316 35.1984 55.3582 34.6953 53.4819C33.7448 49.9487 32.6596 46.4445 31.8846 42.8738C30.2086 35.1349 34.7948 27.5144 42.4783 25.2859C47.7167 23.7647 53.0194 22.4686 58.2958 21.0716C58.3631 21.0542 58.4333 21.0571 58.6439 21.0398C59.4073 23.8571 60.1765 26.6917 60.9691 29.6158C58.3543 30.3086 55.8156 30.9811 53.2768 31.6537C50.7381 32.3263 48.1964 32.9988 45.6576 33.6714C45.6371 33.7349 45.6196 33.7984 45.5991 33.8619C50.1589 36.4627 54.7158 39.0664 59.3517 41.7105C58.9276 42.4437 58.5386 43.1307 58.1379 43.8148C57.1639 45.4803 56.1636 47.1286 55.2248 48.8114C54.9147 49.3657 54.6661 49.3974 54.1309 49.0886C49.9688 46.6898 45.7863 44.3199 41.6096 41.9414C41.4312 41.8404 41.2411 41.7538 40.855 41.5575C42.2648 46.7562 43.6278 51.7788 45.0141 56.8909C42.0747 57.6674 39.2171 58.4179 36.2513 59.2002Z" fill="#3399FF"/></g></g><path class="ia-masc__chispa ia-masc__chispa--1" d="M141 28l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#21D866"/><path class="ia-masc__chispa ia-masc__chispa--2" d="M17 100l2.4 5.6 5.6 2.4-5.6 2.4-2.4 5.6-2.4-5.6-5.6-2.4 5.6-2.4z" fill="#3399FF"/><path class="ia-masc__chispa ia-masc__chispa--3" d="M147 94l2 4.6 4.6 2-4.6 2-2 4.6-2-4.6-4.6-2 4.6-2z" fill="#000080"/></svg>';
  const mascota = (extra) => MASCOTA_IA.replace('class="ia-masc"', 'class="ia-masc' + (extra ? ' ' + extra : '') + '"');
  const skel = (ancho, alto) => '<span class="ia-skel" style="width:' + ancho + ';height:' + (alto || 10) + 'px"></span>';
  const ESQUELETOS = {
    tareas: [1, 2].map(() => '<div class="ia-skel-card">' + skel('58%', 14) + skel('100%') + skel('86%')
      + '<div class="ia-skel-fila">' + skel('34%', 32) + skel('22%', 32) + skel('22%', 32) + skel('16%', 32) + '</div></div>').join(''),
    respuesta: '<div class="ia-skel-card ia-skel-card--info">' + skel('30%', 9) + skel('94%') + skel('72%') + '</div>'
      + '<div class="ia-skel-card">' + skel('100%') + skel('97%') + skel('93%') + skel('58%') + '</div>',
    requerimiento: '<div class="ia-skel-card">' + skel('52%', 14) + skel('100%') + skel('90%')
      + '<div class="ia-skel-fila">' + skel('42%', 32) + skel('30%', 32) + '</div></div>',
  };
  function mostrarCarga(cont, forma, mensajes) {
    const el = document.createElement('div');
    el.className = 'ia-carga';
    el.setAttribute('role', 'status');
    el.innerHTML = '<div class="ia-carga__cab">' + mascota() + '<div class="ia-carga__txt">'
      + '<b class="ia-carga__msg">' + esc(mensajes[0]) + '</b>'
      + '<span class="ia-carga__sub">Suele tardar unos segundos<span class="ia-puntos" aria-hidden="true"><i></i><i></i><i></i></span></span></div></div>'
      + '<div class="ia-carga__skel" aria-hidden="true">' + (ESQUELETOS[forma] || '') + '</div>';
    cont.prepend(el);
    const msg = el.querySelector('.ia-carga__msg');
    let i = 0;
    const reloj = setInterval(() => {
      i = (i + 1) % mensajes.length;
      msg.classList.remove('is-entra');
      void msg.offsetWidth;                 // reinicia la animación del mensaje nuevo
      msg.textContent = mensajes[i];
      msg.classList.add('is-entra');
    }, 2400);
    return () => { clearInterval(reloj); el.remove(); };
  }

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
     3c. Asistente «Analizar este correo» (solo el administrador), en tres pasos:
     1) qué es: lo elige él (tareas de un proyecto o requerimiento suelto);
     2) el proyecto y sus indicaciones, o las indicaciones del requerimiento;
     3) revisar las tareas y crearlas (lib/TareasCorreo.php), o abrir el
        formulario de Requerimientos ya lleno (lib/ReqCorreo.php).
     La IA solo corre cuando él pulsa el botón. Sin responsable no se crea nada.
     ========================================================= */
  const marcaCheck = '<svg class="mc-draw-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  const fechaCorta = (f) => (/^\d{4}-\d{2}-\d{2}$/.test(f || '') ? f.slice(8, 10) + '/' + f.slice(5, 7) + '/' + f.slice(0, 4) : '');
  // [corto, título del paso, ayuda]: los nombres cambian según lo que se elija en el paso 1
  const PASOS_TCW = {
    '': [['Qué es', '¿Qué pide este correo?', 'Elige tú a dónde va el trabajo. La IA todavía no lee nada.'],
         ['Detalles', '', 'Lo que la IA necesita saber'], ['Revisar', '', 'Antes de guardar']],
    proyecto: [['Qué es', '¿Qué pide este correo?', 'Elige tú a dónde va el trabajo. La IA todavía no lee nada.'],
               ['Proyecto', '¿De qué proyecto es?', 'Elige el proyecto y, si quieres, dile a la IA cómo armar las tareas.'],
               ['Tareas', 'Revisa las tareas', 'Cada una necesita su responsable. Desmarca las que no quieras crear.']],
    requerimiento: [['Qué es', '¿Qué pide este correo?', 'Elige tú a dónde va el trabajo. La IA todavía no lee nada.'],
                    ['Requerimiento', 'Armar el requerimiento', 'Si quieres, dile a la IA qué tener en cuenta.'],
                    ['Registrar', 'Revisar y registrar', 'Se abre el formulario de Requerimientos ya lleno.']],
  };
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-correo]');
    if (!b) return;
    e.preventDefault();
    let proyectos = [];
    try { proyectos = JSON.parse(b.dataset.proyectos || '[]'); } catch (x) { proyectos = []; }
    const conReq = b.dataset.req === '1';
    const conTareas = b.dataset.tareas === '1' && proyectos.length > 0;
    const nombreProy = (id) => ((proyectos.find((p) => String(p.id) === String(id)) || {}).nombre || '').replace(/ \(no activo\)$/, '');

    const opcion = (valor, icono, titulo, desc, on, porque) => '<label class="tc-opcion' + (on ? '' : ' is-apagada') + '">'
      + '<input type="radio" class="mc-sr-only" name="tc-destino" value="' + valor + '"' + (on ? '' : ' disabled') + '>'
      + '<span class="tc-opcion__ico" aria-hidden="true"><i class="fa-solid ' + icono + '"></i></span>'
      + '<span class="tc-opcion__punto" aria-hidden="true"></span>'
      + '<span class="tc-opcion__txt"><b>' + titulo + '</b><small>' + desc + '</small>'
      + (on ? '' : '<small class="tc-opcion__no">' + porque + '</small>') + '</span></label>';
    const ind = (attr, ej) => '<div class="mc-field"><label class="mc-field__label" for="tcw-' + attr + '">Indicaciones para la IA <span class="ds-opcional">(opcional)</span></label>'
      + '<div class="mc-input mc-input--md ds-input-area"><textarea class="mc-input__control" id="tcw-' + attr + '" rows="5" data-' + attr + ' maxlength="1500" placeholder="' + ej + '"></textarea></div></div>';

    const dlg = document.createElement('dialog');
    dlg.className = 'dlg-meca dlg-wizard dlg-tcw';
    dlg.innerHTML = '<div class="dlg-form wz">'
      + '<aside class="wz-riel"><div class="wz-marca"><span class="wz-icono wz-icono--masc">' + mascota('ia-masc--mini') + '</span>'
      + '<h3 class="font-display">Analizar este correo</h3><p' + (b.dataset.asunto ? ' title="' + esc(b.dataset.asunto) + '"' : '') + '>' + (b.dataset.asunto ? '«' + esc(b.dataset.asunto) + '»' : 'Tareas de un proyecto o un requerimiento suelto') + '</p></div>'
      + '<div class="wz-pasos">' + [0, 1, 2].map((i) => '<button type="button" class="wz-paso" data-i="' + i + '"><span class="wz-num">' + (i + 1) + '</span>'
        + '<span class="wz-txt"><b></b><small></small></span></button>').join('') + '</div></aside>'
      + '<div class="wz-cuerpo"><header><div><h4 class="wz-titulo-paso"></h4><p class="wz-ayuda-paso"></p></div>'
      + '<button type="button" class="dlg-close" title="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>'
      + '<div class="ia-cuerpo tcw-paneles">'
      // 1. Qué es
      + '<section class="wz-panel" data-p="que"><div class="tc-opciones tcw-opciones" role="radiogroup" aria-label="¿Qué pide este correo?">'
      + opcion('proyecto', 'fa-list-check', 'Tareas de un proyecto', 'Trabajo de uno de los proyectos del panel. Se crean en su tablero, cada una con su responsable, sus fechas y la descripción del correo.',
        conTareas, 'No hay proyectos abiertos, o la función está apagada en Ajustes de IA.')
      + opcion('requerimiento', 'fa-inbox', 'Requerimiento suelto', 'Un pedido de una institución que no es de ningún proyecto. Queda listo en Requerimientos para revisarlo y registrarlo.',
        conReq, 'La función está apagada en Ajustes de IA.')
      + '</div><p class="tcw-nota"><i class="fa-solid fa-circle-info"></i> La IA no se usa hasta que tú lo pidas en el paso siguiente.</p></section>'
      // 2a. Proyecto
      + '<section class="wz-panel" data-p="proyecto">'
      + '<div class="mc-field"><label class="mc-field__label">Proyecto</label><select data-ds-select data-ds-buscar data-tc-proyecto aria-label="Proyecto">'
      + '<option value="">Elige el proyecto</option>' + proyectos.map((p) => '<option value="' + p.id + '">' + esc(p.nombre) + '</option>').join('') + '</select></div>'
      + ind('tc-ind', 'Ej.: que sean dos tareas, una de diseño y otra de publicación; asígnalas a Kevin; para el viernes')
      + '<p class="tcw-nota"><i class="fa-solid fa-wand-magic-sparkles"></i> Al pulsar «Proponer tareas» la IA lee el correo y arma las tareas. Las revisas antes de crearlas.</p></section>'
      // 2b. Requerimiento
      + '<section class="wz-panel" data-p="requerimiento"><div class="tcw-incluye"><p>La IA lee el correo y deja lleno el formulario con:</p><ul>'
      + ['El título y el detalle de lo que piden', 'Quién lo pide y su institución', 'El responsable y el plazo según el tamaño del trabajo', 'Los adjuntos del correo, como documentos']
        .map((t) => '<li><i class="fa-solid fa-check"></i> ' + t + '</li>').join('') + '</ul></div>'
      + ind('tc-ind-req', 'Ej.: es para la web de la UBE; asígnalo a Kevin; prioridad alta')
      + '<p class="tcw-nota"><i class="fa-solid fa-circle-info"></i> Nada se registra hasta que lo revises y pulses «Registrar».</p></section>'
      // 3a. Tareas · Listo
      + '<section class="wz-panel" data-p="tareas"><div class="tc-lista" data-tc-lista></div></section>'
      + '<section class="wz-panel" data-p="listo"></section>'
      + '</div>'
      + '<div class="wz-pie"><span class="wz-contador"></span><div class="wz-acciones">'
      + '<button type="button" class="btn-outline btn-meca btn-neutro" data-tcw-atras><i class="fa-solid fa-arrow-left"></i> Atrás</button>'
      + '<button type="button" class="btn-outline btn-meca" data-tc-proponer><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Proponer tareas</span></button>'
      + '<button type="button" class="btn-primary btn-meca" data-tcw-sig>Siguiente <i class="fa-solid fa-arrow-right"></i></button>'
      + '<button type="button" class="btn-primary btn-meca" data-tc-crear><i class="fa-solid fa-check"></i> <span>Crear tareas</span></button>'
      + '<button type="button" class="btn-primary btn-meca" data-tc-req><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Armar con IA</span></button>'
      + '</div></div></div></div>';
    document.body.appendChild(dlg);

    const $ = (s) => dlg.querySelector(s);
    const pasos = [...dlg.querySelectorAll('.wz-paso')];
    const selProy = $('[data-tc-proyecto]');
    const indProy = $('[data-tc-ind]');
    const indReq = $('[data-tc-ind-req]');
    const lista = $('[data-tc-lista]');
    const bAtras = $('[data-tcw-atras]');
    const bSig = $('[data-tcw-sig]');
    const bProponer = $('[data-tc-proponer]');
    const bCrear = $('[data-tc-crear]');
    const bReq = $('[data-tc-req]');
    let tipo = '';        // proyecto | requerimiento
    let paso = 'que';     // que | proyecto | requerimiento | tareas | listo
    let datos = null;     // la última propuesta de tareas
    let pidPropuesta = '';
    let indPropuesta = '';
    let creadas = false;
    const NUM = { que: 0, proyecto: 1, requerimiento: 1, tareas: 2, listo: 3 };
    // ¿La propuesta que hay sirve? (mismo proyecto y mismas indicaciones: no se gasta IA otra vez)
    const propuestaVale = () => !!datos && pidPropuesta === selProy.value && indPropuesta === indProy.value.trim();
    const ver = (btn, si) => btn.classList.toggle('wz-oculto', !si);

    const ir = (p) => {
      paso = p;
      dlg.dataset.paso = p;          // el alto del modal: justo al contenido, salvo al revisar tareas
      const n = NUM[p];
      const def = PASOS_TCW[tipo] || PASOS_TCW[''];
      const viejo = dlg.querySelector('.ia-error');
      if (viejo) viejo.remove();
      dlg.querySelectorAll('.wz-panel').forEach((el) => el.classList.toggle('activo', el.dataset.p === p));
      pasos.forEach((el, i) => {
        el.querySelector('b').textContent = def[i][0];
        el.querySelector('small').textContent = i === 0 ? 'Proyecto o requerimiento' : def[i][2];
        el.classList.toggle('activo', i === n);
        el.classList.toggle('hecho', i < n);
        el.querySelector('.wz-num').innerHTML = i < n ? '<i class="fa-solid fa-check"></i>' : String(i + 1);
        el.disabled = p === 'listo' || !(i < n || (i === 2 && n === 1 && tipo === 'proyecto' && propuestaVale()));
      });
      $('.wz-titulo-paso').textContent = p === 'listo' ? '¡Listo!' : def[Math.min(n, 2)][1];
      $('.wz-ayuda-paso').textContent = p === 'listo' ? 'Las tareas ya están en el tablero del proyecto.' : def[Math.min(n, 2)][2];
      $('.wz-contador').textContent = 'Paso ' + (n + 1) + ' de 3';
      $('.wz-pie').classList.toggle('wz-oculto', p === 'listo');
      ver(bAtras, n === 1 || n === 2);
      ver(bSig, p === 'que' || (p === 'proyecto' && propuestaVale()));
      bSig.disabled = p === 'que' && !tipo;
      ver(bProponer, p === 'proyecto');
      // Con una propuesta que sirve, «Siguiente» va a ella; proponer otra vez queda al lado
      bProponer.className = (propuestaVale() ? 'btn-outline' : 'btn-primary') + ' btn-meca' + (p === 'proyecto' ? '' : ' wz-oculto');
      bProponer.querySelector('span').textContent = datos ? 'Volver a proponer' : 'Proponer tareas';
      ver(bCrear, p === 'tareas');
      ver(bReq, p === 'requerimiento');
      if (p === 'tareas') contar();
      const foco = { proyecto: null, requerimiento: indReq }[p];
      if (foco) setTimeout(() => foco.focus({ preventScroll: true }), 60);
    };

    /* ---------- 1. Qué es ---------- */
    dlg.addEventListener('change', (ev) => {
      if (ev.target.name !== 'tc-destino') return;
      tipo = ev.target.value;
      dlg.querySelectorAll('.tc-opcion').forEach((o) => o.classList.toggle('is-elegida', o.querySelector('input').checked));
      ir('que');
    });
    // Doble clic en una opción: elegir y seguir
    dlg.querySelector('.tcw-opciones').addEventListener('dblclick', (ev) => {
      const o = ev.target.closest('.tc-opcion:not(.is-apagada)');
      if (o) { o.querySelector('input').checked = true; tipo = o.querySelector('input').value; bSig.click(); }
    });
    bSig.addEventListener('click', () => {
      if (paso === 'que' && tipo) ir(tipo);
      else if (paso === 'proyecto' && propuestaVale()) ir('tareas');
    });
    bAtras.addEventListener('click', () => ir(paso === 'tareas' ? 'proyecto' : 'que'));
    pasos.forEach((el, i) => el.addEventListener('click', () => {
      if (el.disabled) return;
      ir(i === 0 ? 'que' : i === 1 ? tipo : 'tareas');
    }));
    selProy.addEventListener('change', () => ir(paso));
    indProy.addEventListener('input', () => { if (datos) ir(paso); });

    /* ---------- 2b. Requerimiento suelto: el flujo de siempre, con indicaciones ---------- */
    bReq.addEventListener('click', () => conBoton(bReq, async () => {
      // Mientras la IA arma el requerimiento: Inno y el esqueleto del formulario
      const panel = $('[data-p="requerimiento"]');
      panel.classList.add('is-cargando');
      dlg.classList.add('is-pensando');
      const parar = mostrarCarga(panel, 'requerimiento', ['Leyendo el correo…', 'Viendo quién lo pide…', 'Armando el requerimiento…']);
      const soltar = () => { parar(); panel.classList.remove('is-cargando'); dlg.classList.remove('is-pensando'); };
      let r;
      try { r = await pedir('ia_req_correo', { c: b.dataset.c, uid: b.dataset.uid, instrucciones: indReq.value.trim() }); }
      catch (err) { soltar(); throw err; }
      if (r.url) location.href = r.url;    // la carga sigue mientras se abre el formulario
      else soltar();
    }));

    /* ---------- 3a. Tareas de un proyecto ---------- */
    const leer = () => [...lista.querySelectorAll('.tc-tarea')].map((el) => {
      const t = datos.tareas[+el.dataset.i];
      const v = (k) => ((el.querySelector('[data-k="' + k + '"]') || {}).value || '');
      return {
        usar: el.querySelector('[data-tc-usar]').checked, el, ya: t.ya_existe || null, ref: t.ref, depende_de: t.depende_de,
        titulo: v('titulo').trim(), descripcion: v('descripcion'), responsables: v('responsable') ? [+v('responsable')] : [],
        fecha_inicio: v('fecha_inicio'), fecha_limite: v('fecha_limite'), prioridad: v('prioridad'),
      };
    });
    function contar() {
      if (!datos) return;
      const todas = leer();
      todas.forEach((x) => {
        x.el.classList.toggle('is-apagada', !x.usar);
        x.el.classList.toggle('is-sin-resp', x.usar && !x.responsables.length);
      });
      const l = todas.filter((x) => x.usar);
      const sin = l.filter((x) => !x.responsables.length).length;
      bCrear.querySelector('span').textContent = !l.length ? 'Marca al menos una tarea'
        : sin ? 'Falta elegir ' + sin + ' responsable' + (sin > 1 ? 's' : '')
        : 'Crear ' + l.length + ' tarea' + (l.length > 1 ? 's' : '');
      bCrear.disabled = !l.length || sin > 0;
    }
    lista.addEventListener('change', contar);
    lista.addEventListener('input', contar);

    const pintar = (r) => {
      datos = r;
      const gente = r.gente || [];
      const prios = r.prioridades || { baja: 'Baja', media: 'Media', alta: 'Alta' };
      const opGente = (sel) => '<option value="">Elige el responsable</option>' + gente.map((g) => '<option value="' + g.id + '"' + (g.id === sel ? ' selected' : '') + '>'
        + esc(g.nombre) + (g.rol ? ' · ' + esc(g.rol) : '') + '</option>').join('');
      const opPrio = (sel) => Object.entries(prios).map(([k, v]) => '<option value="' + esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + esc(v) + '</option>').join('');
      const n = r.tareas.length;
      const rep = r.tareas.filter((t) => t.ya_existe).length;
      lista.innerHTML = '<p class="tcw-resumen">' + n + ' tarea' + (n === 1 ? '' : 's') + ' para <b>«' + esc(nombreProy(pidPropuesta) || r.proyecto || '') + '»</b>'
        + (rep ? ' · <span class="tcw-resumen__rep">' + (rep === n ? (n === 1 ? 'ya existe' : 'todas ya existen') : rep + ' ya existe' + (rep > 1 ? 'n' : '')) + ' en el proyecto: ' + (rep > 1 ? 'quedan' : 'queda') + ' sin marcar</span>' : '') + '.</p>'
        + (r.notas ? '<div class="mc-alert mc-alert--info mc-alert--compact" role="status"><div class="mc-alert__content"><p class="mc-alert__desc">' + esc(r.notas) + '</p></div></div>' : '')
        + r.tareas.map((t, i) => '<article class="tc-tarea' + (t.ya_existe ? ' is-repetida' : '') + '" data-i="' + i + '">'
          // Ya existe en el proyecto (lo vio la IA o un título casi igual): sin marcar, y se dice cuál es
          + (t.ya_existe ? '<p class="tc-ya"><i class="fa-solid fa-clone" aria-hidden="true"></i><span>Ya existe en el proyecto: <b>«' + esc(t.ya_existe.titulo) + '»</b> (#' + t.ya_existe.id + ' · '
            + esc(t.ya_existe.estado) + '). Márcala solo si de verdad es otra.</span></p>' : '')
          + '<label class="mc-check' + (t.ya_existe ? '' : ' is-checked') + ' tc-tarea__usar"><input type="checkbox" class="mc-sr-only" data-tc-usar' + (t.ya_existe ? '' : ' checked') + '>'
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
    };

    bProponer.addEventListener('click', () => {
      if (!selProy.value) { avisarError(bProponer, 'Elige el proyecto donde van las tareas.'); return; }
      conBoton(bProponer, async () => {
        // Mientras la IA piensa: el paso de tareas con Inno y el esqueleto de las tarjetas
        const antes = datos;
        lista.innerHTML = '';
        dlg.classList.add('is-pensando');
        const parar = mostrarCarga(lista, 'tareas', ['Leyendo el correo…', 'Entendiendo lo que piden…', 'Armando las tareas…', 'Buscando a quién le toca cada una…']);
        ir('tareas');
        let r;
        try {
          r = await pedir('ia_tareas_correo', { c: b.dataset.c, uid: b.dataset.uid, proyecto_id: selProy.value, instrucciones: indProy.value.trim() });
        } catch (err) {
          parar();
          dlg.classList.remove('is-pensando');
          if (antes) pintar(antes);          // la propuesta anterior sigue ahí
          ir('proyecto');
          throw err;
        }
        parar();
        dlg.classList.remove('is-pensando');
        pidPropuesta = selProy.value;
        indPropuesta = indProy.value.trim();
        pintar(r);
        ir('tareas');
        $('[data-p="tareas"]').scrollTop = 0;
      });
    });

    bCrear.addEventListener('click', () => {
      // «Después de» una que no se crea por repetida: va después de la que ya existía (#id)
      const todas = leer();
      const porRef = new Map(todas.map((x) => [x.ref, x]));
      const l = todas.filter((x) => x.usar).map((x) => {
        const d = porRef.get(x.depende_de);
        return d && !d.usar && d.ya ? { ...x, depende_de: '#' + d.ya.id } : x;
      });
      if (!l.length) return;
      if (l.some((x) => !x.titulo)) { avisarError(bCrear, 'Cada tarea necesita un título.'); return; }
      if (l.some((x) => !x.responsables.length)) { avisarError(bCrear, 'Elige el responsable de cada tarea.'); return; }
      conBoton(bCrear, async () => {
        const r = await pedir('tareas_correo_crear', {
          proyecto_id: pidPropuesta, mensaje_id: datos.mensaje_id || '', origen: datos.origen || '',
          tareas: JSON.stringify(l.map(({ el, usar, ya, ...x }) => x)),
        });
        creadas = true;
        listo(r, l);
      });
    });

    /* ---------- Listo: grande, con lo que se creó y la flecha para ir al proyecto ---------- */
    const listo = (r, l) => {
      const gente = new Map((datos.gente || []).map((g) => [g.id, g.nombre]));
      const nombre = nombreProy(pidPropuesta);
      $('[data-p="listo"]').innerHTML = '<div class="tcw-listo">'
        + '<div class="tcw-listo__cab"><span class="tcw-listo__ok" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>'
        + '<h3 class="font-display">' + esc(r.mensaje) + '</h3><p>Cada responsable recibió su aviso. El correo queda marcado con lo que salió de él.</p></div>'
        + '<ol class="tcw-listo__tareas">' + l.map((x, i) => '<li><span class="tcw-listo__num">' + (i + 1) + '</span><div><b>' + esc(x.titulo) + '</b><small>'
          + esc(x.responsables.map((id) => gente.get(id) || '').filter(Boolean).join(', ')) + (x.fecha_limite ? ' · vence el ' + fechaCorta(x.fecha_limite) : '') + '</small></div></li>').join('') + '</ol>'
        + '<div class="tcw-listo__acc"><a class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block tcw-listo__ir" href="' + esc(r.url) + '">'
        + '<span class="mc-btn__label">Ver las tareas' + (nombre ? ' en «' + esc(nombre) + '»' : '') + '</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>'
        + '<button type="button" class="mc-btn mc-btn--ghost mc-btn--block" data-tcw-cerrar><span class="mc-btn__label">Volver al correo</span></button></div></div>';
      $('[data-tcw-cerrar]').addEventListener('click', () => dlg.close());
      ir('listo');
    };

    $('.dlg-close').addEventListener('click', () => dlg.close());
    dlg.addEventListener('close', () => { dlg.remove(); if (creadas) location.reload(); });
    // Con una sola opción posible, ya viene elegida (igual se pulsa «Siguiente»)
    if (conTareas !== conReq) {
      const unica = dlg.querySelector('input[name="tc-destino"]:not([disabled])');
      if (unica) { unica.checked = true; tipo = unica.value; unica.closest('.tc-opcion').classList.add('is-elegida'); }
    }
    ir('que');
    dlg.showModal();
  });

  /* =========================================================
     3d. Responder un correo con la IA (solo el administrador): primero dice
     cómo quiere responder (o nada) y el tono; la IA solo corre cuando pulsa
     el botón. Lee, resume y propone la respuesta, que se puede editar y va al
     formulario de responder con la cita del original debajo.
     ========================================================= */
  const IDEAS_RESPUESTA = [
    ['Confirmar que lo hacemos', 'Confirma que recibimos el pedido y que lo hacemos; da una fecha de entrega.'],
    ['Pedir más información', 'Agradece y pide los datos que faltan para poder hacerlo.'],
    ['Ya está listo', 'Avisa que ya está hecho y pide que lo revisen.'],
    ['Lo revisamos', 'Agradece y di que lo revisamos y respondemos pronto.'],
    ['No se puede así', 'Explica con amabilidad que así no se puede y propone otra opción.'],
  ];
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ia-responder]');
    if (!b) return;
    e.preventDefault();
    const dlg = modal('Responder con IA', 'fa-wand-magic-sparkles',
      '<div class="rc-intro">' + mascota('ia-masc--mini') + '<p class="tc-ayuda">Dile a la IA cómo quieres responder a <b>' + esc(b.dataset.de || 'este correo') + '</b>, o déjalo vacío y responde lo que corresponda. '
      + 'La IA lee el correo solo cuando pulses el botón.</p></div>'
      + '<div class="rc-tono" role="radiogroup" aria-label="Tono de la respuesta"><span class="rc-tono__rot">Tono</span>'
      + [['formal', 'Formal'], ['cercano', 'Cercano'], ['breve', 'Breve']].map(([v, t], i) => '<label class="rc-tono__op"><input type="radio" class="mc-sr-only" name="rc-tono" value="' + v + '"'
        + (i === 0 ? ' checked' : '') + '><span>' + t + '</span></label>').join('') + '</div>'
      + '<div class="rc-ideas" role="group" aria-label="Ideas">' + IDEAS_RESPUESTA.map(([t, p]) => '<button type="button" class="rc-idea" data-rc-idea="' + esc(p) + '">' + esc(t) + '</button>').join('') + '</div>'
      + '<div class="rc-fila">'
      + '<div class="mc-field rc-ind"><label class="mc-field__label" for="rc-ind">¿Cómo quieres responder? <span class="ds-opcional">(opcional)</span></label>'
      + '<div class="mc-input mc-input--md ds-input-area"><textarea class="mc-input__control" id="rc-ind" rows="3" maxlength="1500" data-rc-ind placeholder="Ej.: confirma que lo publicamos el viernes y pide el logo en alta"></textarea></div></div>'
      + '</div>'
      + '<div class="rc-carga" data-rc-carga></div>'
      + '<div class="rc-resultado" data-rc-resultado hidden>'
      + '<div class="rc-resumen"><h4>Lo que dice el correo</h4><ul data-rc-resumen></ul><p class="rc-pide" data-rc-pide hidden></p></div>'
      + '<div class="mc-field"><label class="mc-field__label" for="rc-respuesta">Respuesta propuesta <span class="ds-opcional">(puedes editarla)</span></label>'
      + '<div class="mc-input mc-input--md ds-input-area"><textarea class="mc-input__control rc-respuesta" id="rc-respuesta" rows="11" data-rc-respuesta></textarea></div></div>'
      + '<div data-rc-firma></div>'
      + '</div>',
      '<button type="button" class="btn-primary btn-meca" data-rc-generar><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Leer y proponer respuesta</span></button>'
      + '<button type="button" class="btn-primary btn-meca wz-oculto" data-rc-usar><i class="fa-solid fa-reply"></i> <span>Usar esta respuesta</span></button>');
    dlg.classList.add('dlg-ia-ancho', 'dlg-rc');
    const ind = dlg.querySelector('[data-rc-ind]');
    const tono = () => (dlg.querySelector('input[name="rc-tono"]:checked') || {}).value || 'formal';
    const res = dlg.querySelector('[data-rc-resultado]');
    const bGen = dlg.querySelector('[data-rc-generar]');
    const bUsar = dlg.querySelector('[data-rc-usar]');
    const txt = dlg.querySelector('[data-rc-respuesta]');

    // Una idea rellena las indicaciones (se pueden juntar varias)
    dlg.querySelector('.rc-ideas').addEventListener('click', (ev) => {
      const i = ev.target.closest('[data-rc-idea]');
      if (!i) return;
      const v = ind.value.trim();
      ind.value = v ? v + ' ' + i.dataset.rcIdea : i.dataset.rcIdea;
      i.classList.add('is-usada');
      ind.focus();
    });

    bGen.addEventListener('click', () => conBoton(bGen, async () => {
      // Mientras la IA lee y escribe: Inno y el esqueleto del resumen y la respuesta
      res.hidden = true;
      dlg.classList.add('is-pensando');
      const parar = mostrarCarga(dlg.querySelector('[data-rc-carga]'), 'respuesta', ['Leyendo el correo…', 'Entendiendo lo que piden…', 'Escribiendo la respuesta…']);
      let r;
      try { r = await pedir('ia_responder_correo', { c: b.dataset.c, uid: b.dataset.uid, instrucciones: ind.value.trim(), tono: tono() }); }
      finally { parar(); dlg.classList.remove('is-pensando'); }
      dlg.querySelector('[data-rc-resumen]').innerHTML = (r.resumen || []).map((x) => '<li>' + esc(x) + '</li>').join('') || '<li>Sin resumen.</li>';
      const pide = dlg.querySelector('[data-rc-pide]');
      pide.textContent = r.pide ? 'Lo que esperan de nosotros: ' + r.pide : '';
      pide.hidden = !r.pide;
      txt.value = r.respuesta || '';
      dlg.querySelector('[data-rc-firma]').innerHTML = firma(r);
      res.hidden = false;
      bGen.className = 'btn-outline btn-meca';
      bGen.querySelector('span').textContent = 'Volver a proponer';
      bUsar.classList.remove('wz-oculto');
      res.scrollIntoView({ block: 'nearest' });
      txt.focus();
    }));

    // Al formulario de responder, con la respuesta arriba y la cita del original debajo
    bUsar.addEventListener('click', () => {
      if (!txt.value.trim()) { avisarError(bUsar, 'La respuesta está vacía.'); return; }
      const f = document.createElement('form');
      f.method = 'post';
      f.action = 'actions.php';
      f.hidden = true;
      [['accion', 'correo_respuesta_ia'], ['c', b.dataset.c], ['uid', b.dataset.uid], ['texto', txt.value]].forEach(([k, v]) => {
        const i = document.createElement('textarea');
        i.name = k;
        i.value = v;
        f.appendChild(i);
      });
      document.body.appendChild(f);
      bUsar.disabled = true;
      f.submit();
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
