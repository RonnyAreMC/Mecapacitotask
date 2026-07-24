/* Mecapacito Admin - interacciones del panel */

/* =========================================================
   MC — componente de alertas Mecapacito.
   Nunca usar alert()/confirm() nativos: MC.toast y MC.confirm.
   ========================================================= */
const MC = {
  _iconos: { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' },

  /** Toast apilable con barra de tiempo. tipo: success | error | info */
  toast(mensaje, tipo = 'info', duracion = 4500) {
    const cont = document.getElementById('mc-toasts');
    if (!cont) return;
    if (!this._iconos[tipo]) tipo = 'info';
    cont.insertAdjacentHTML('beforeend',
      '<div class="mc-toast mc-' + tipo + '" data-duracion="' + duracion + '">' +
        '<i class="fa-solid ' + this._iconos[tipo] + '"></i>' +
        '<div class="mc-toast-txt"></div>' +
        '<button type="button" class="mc-toast-x" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>' +
        '<span class="mc-toast-barra"></span>' +
      '</div>');
    const el = cont.lastElementChild;
    el.querySelector('.mc-toast-txt').textContent = mensaje;
    this._activarToast(el);
  },

  /** Cableado de un toast (tambien para los que renderiza PHP). */
  _activarToast(el) {
    const cerrar = () => {
      if (el.classList.contains('out')) return;
      el.classList.add('out');
      el.addEventListener('animationend', () => el.remove(), { once: true });
    };
    el.querySelector('.mc-toast-x').addEventListener('click', cerrar);
    const barra = el.querySelector('.mc-toast-barra');
    barra.style.animationDuration = (parseInt(el.dataset.duracion, 10) || 4500) + 'ms';
    barra.addEventListener('animationend', cerrar);
  },

  /** Dialogo de confirmacion. Devuelve Promise<boolean>. */
  confirm({ titulo = '¿Estás seguro?', mensaje = '', ok = 'Sí, continuar', cancelar = 'Cancelar', peligro = true } = {}) {
    return new Promise((resolver) => {
      const dlg = document.createElement('dialog');
      dlg.className = 'dlg-meca mc-confirm';
      dlg.innerHTML =
        '<div class="mc-confirm-cuerpo">' +
          '<div class="mc-confirm-icono ' + (peligro ? 'es-peligro' : '') + '">' +
            '<i class="fa-solid ' + (peligro ? 'fa-triangle-exclamation' : 'fa-circle-question') + '"></i>' +
          '</div>' +
          '<h3 class="font-display"></h3>' +
          '<p></p>' +
          '<footer>' +
            '<button type="button" class="btn-outline btn-meca mcc-no"></button>' +
            '<button type="button" class="btn-meca mcc-si ' + (peligro ? 'btn-peligro-solido' : 'btn-primary') + '"></button>' +
          '</footer>' +
        '</div>';
      dlg.querySelector('h3').textContent = titulo;
      dlg.querySelector('p').textContent = mensaje;
      dlg.querySelector('.mcc-no').textContent = cancelar;
      dlg.querySelector('.mcc-si').textContent = ok;
      document.body.appendChild(dlg);

      const terminar = (valor) => { dlg.close(); dlg.remove(); resolver(valor); };
      dlg.querySelector('.mcc-si').addEventListener('click', () => terminar(true));
      dlg.querySelector('.mcc-no').addEventListener('click', () => terminar(false));
      dlg.addEventListener('cancel', (e) => { e.preventDefault(); terminar(false); });
      dlg.addEventListener('click', (e) => { if (e.target === dlg) terminar(false); });
      dlg.showModal();
      dlg.querySelector('.mcc-no').focus();
    });
  },
};

// Activar los toasts que ya vienen renderizados por PHP (mensajes flash)
document.querySelectorAll('#mc-toasts .mc-toast').forEach((t) => MC._activarToast(t));

/* =========================================================
   MecaSelect — realza los <select> con buscador y multi-selección.
   Mantiene el <select> nativo oculto y sincronizado (los forms
   envían igual). Reutilizable en todo .select-meca (no .select-pill).
   ========================================================= */
const MecaSelect = {
  init(scope) {
    (scope || document).querySelectorAll('select.select-meca:not(.select-pill):not([data-ms])')
      .forEach((sel) => this.enhance(sel));
  },
  enhance(sel) {
    sel.dataset.ms = '1';
    const multi = sel.multiple;
    const wrap = document.createElement('div');
    wrap.className = 'ms' + (sel.classList.contains('select-sm') ? ' ms-sm' : '') + (multi ? ' ms-multi' : '');
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ms-trigger';
    trigger.innerHTML = '<span class="ms-label"></span><i class="fa-solid fa-chevron-down ms-caret"></i>';
    wrap.appendChild(trigger);

    const panel = document.createElement('div');
    panel.className = 'ms-panel';
    panel.innerHTML = '<div class="ms-search"><i class="fa-solid fa-magnifying-glass"></i>' +
                      '<input type="text" placeholder="Buscar…"></div><div class="ms-opts"></div>';
    const opts = panel.querySelector('.ms-opts');
    const search = panel.querySelector('input');
    const host = sel.closest('dialog') || document.body;
    let abierto = false;

    const buildOpts = () => {
      opts.innerHTML = '';
      [...sel.options].forEach((o) => {
        const el = document.createElement('div');
        el.className = 'ms-opt' + (o.selected ? ' sel' : '') + (o.disabled ? ' dis' : '');
        el.innerHTML = '<span>' + o.textContent + '</span><i class="fa-solid fa-check"></i>';
        el.addEventListener('click', () => {
          if (o.disabled) return;
          if (multi) {
            o.selected = !o.selected;
            el.classList.toggle('sel', o.selected);
          } else {
            [...sel.options].forEach((x) => (x.selected = false));
            o.selected = true;
          }
          sel.dispatchEvent(new Event('change', { bubbles: true }));
          renderLabel();
          if (!multi) cerrar();
        });
        opts.appendChild(el);
      });
    };
    const renderLabel = () => {
      const label = trigger.querySelector('.ms-label');
      const elegidas = [...sel.selectedOptions];
      if (multi) {
        label.innerHTML = elegidas.length
          ? elegidas.map((o) => '<span class="ms-chip">' + o.textContent +
              '<i class="fa-solid fa-xmark" data-val="' + o.value.replace(/"/g, '&quot;') + '"></i></span>').join('')
          : '<span class="ms-ph">' + (sel.dataset.ph || 'Selecciona…') + '</span>';
      } else {
        label.textContent = elegidas[0] ? elegidas[0].textContent : (sel.dataset.ph || '');
      }
    };
    const posicionar = () => {
      const r = trigger.getBoundingClientRect();
      panel.style.left = r.left + 'px';
      panel.style.width = r.width + 'px';
      panel.style.top = (r.bottom + 6) + 'px';
      const h = panel.offsetHeight;
      if (r.bottom + 6 + h > innerHeight && r.top - 6 - h > 0) panel.style.top = (r.top - 6 - h) + 'px';
    };
    const abrir = () => {
      buildOpts(); host.appendChild(panel);
      abierto = true; wrap.classList.add('ms-open');
      posicionar(); search.value = ''; filtrar(''); search.focus();
    };
    const cerrar = () => { if (!abierto) return; abierto = false; wrap.classList.remove('ms-open'); panel.remove(); };
    const filtrar = (q) => {
      q = q.toLowerCase();
      opts.querySelectorAll('.ms-opt').forEach((el) => { el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none'; });
    };

    trigger.addEventListener('click', (e) => {
      const x = e.target.closest('.ms-chip i');
      if (x) {
        e.stopPropagation();
        const o = [...sel.options].find((op) => op.value === x.dataset.val);
        if (o) { o.selected = false; sel.dispatchEvent(new Event('change', { bubbles: true })); renderLabel(); }
        return;
      }
      abierto ? cerrar() : abrir();
    });
    search.addEventListener('input', () => filtrar(search.value));
    search.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
    document.addEventListener('click', (e) => { if (abierto && !panel.contains(e.target) && !wrap.contains(e.target)) cerrar(); });
    addEventListener('scroll', () => cerrar(), true);
    addEventListener('resize', () => cerrar());
    // Sincroniza si el valor cambia por JS (p. ej. al editar en un modal)
    sel.addEventListener('ms-sync', () => { renderLabel(); });
    renderLabel();
  },
};
MecaSelect.init();

// Fija el valor de un <select> y refresca su MecaSelect
function setSelect(el, valor) {
  if (!el) return;
  if (el.multiple) {
    // Varias opciones marcadas: valor es una lista de ids
    const set = new Set((Array.isArray(valor) ? valor : [valor]).map(String));
    [...el.options].forEach((o) => { o.selected = set.has(o.value); });
  } else {
    el.value = String(valor);
  }
  el.dispatchEvent(new Event('ms-sync'));
}

/* =========================================================
   MecaDate — selector de fecha (y hora) con estilo del panel.
   Reemplaza el date picker nativo. Mantiene el input oculto con
   su name para que los forms envíen igual (YYYY-MM-DD / ...THH:MM).
   ========================================================= */
const MecaDate = {
  meses: ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
  dows: ['Lu','Ma','Mi','Ju','Vi','Sá','Do'],
  init(scope) {
    (scope || document).querySelectorAll('input[type="date"]:not([data-md]), input[type="datetime-local"]:not([data-md])')
      .forEach((inp) => this.enhance(inp));
  },
  enhance(inp) {
    inp.dataset.md = '1';
    const conHora = inp.type === 'datetime-local';
    inp.type = 'hidden';   // conserva el name y envía el valor
    const wrap = document.createElement('div');
    wrap.className = 'md';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'md-trigger';
    trigger.innerHTML = '<span class="md-label"></span><i class="fa-regular fa-calendar md-ico"></i>';
    wrap.appendChild(trigger);

    const pop = document.createElement('div');
    pop.className = 'md-pop';
    const host = inp.closest('dialog') || document.body;
    let abierto = false;
    let ver = new Date();   // mes visible

    const parse = (v) => {
      if (!v) return null;
      // Una fecha sola "YYYY-MM-DD" hay que construirla en hora LOCAL: si se
      // pasa a new Date() se interpreta como UTC y en zonas al oeste (Ecuador
      // es UTC-5) cae al día anterior. Por eso se veía corrida.
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v.trim());
      if (m) return new Date(+m[1], +m[2] - 1, +m[3]);
      const d = new Date(v.replace(' ', 'T'));
      return isNaN(d) ? null : d;
    };
    const dos = (n) => String(n).padStart(2, '0');
    const iso = (d) => d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate());

    const label = () => {
      const d = parse(inp.value);
      const lbl = trigger.querySelector('.md-label');
      if (!d) { lbl.innerHTML = '<span class="md-ph">Elegir fecha' + (conHora ? ' y hora' : '') + '</span>'; return; }
      let s = dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear();
      if (conHora) s += ' · ' + dos(d.getHours()) + ':' + dos(d.getMinutes());
      lbl.textContent = s;
    };

    const pintar = () => {
      const sel = parse(inp.value);
      const hoy = new Date();
      const anio = ver.getFullYear(), mes = ver.getMonth();
      const primero = new Date(anio, mes, 1);
      const offset = (primero.getDay() + 6) % 7;   // lunes primero
      const dias = new Date(anio, mes + 1, 0).getDate();
      let cel = '';
      for (let i = 0; i < offset; i++) cel += '<span class="md-d md-vacia"></span>';
      for (let d = 1; d <= dias; d++) {
        const esHoy = hoy.getFullYear() === anio && hoy.getMonth() === mes && hoy.getDate() === d;
        const esSel = sel && sel.getFullYear() === anio && sel.getMonth() === mes && sel.getDate() === d;
        cel += '<button type="button" class="md-d' + (esSel ? ' sel' : '') + (esHoy ? ' hoy' : '') + '" data-d="' + d + '">' + d + '</button>';
      }
      pop.innerHTML =
        '<div class="md-head"><button type="button" class="md-nav" data-nav="-1"><i class="fa-solid fa-chevron-left"></i></button>' +
        '<b>' + this.meses[mes] + ' ' + anio + '</b>' +
        '<button type="button" class="md-nav" data-nav="1"><i class="fa-solid fa-chevron-right"></i></button></div>' +
        '<div class="md-dows">' + this.dows.map((x) => '<span>' + x + '</span>').join('') + '</div>' +
        '<div class="md-grid">' + cel + '</div>' +
        (conHora ? '<div class="md-hora"><i class="fa-regular fa-clock"></i> Hora <input type="time" class="md-time" value="' + (sel ? dos(sel.getHours()) + ':' + dos(sel.getMinutes()) : '09:00') + '"></div>' : '') +
        '<div class="md-pie"><button type="button" class="md-borrar">Borrar</button><button type="button" class="md-hoy">Hoy</button></div>';
    };

    const posicionar = () => {
      const r = trigger.getBoundingClientRect();
      pop.style.left = r.left + 'px';
      pop.style.top = (r.bottom + 6) + 'px';
      const h = pop.offsetHeight;
      if (r.bottom + 6 + h > innerHeight && r.top - 6 - h > 0) pop.style.top = (r.top - 6 - h) + 'px';
    };
    const abrir = () => {
      ver = parse(inp.value) || new Date();
      pintar(); host.appendChild(pop);
      abierto = true; wrap.classList.add('md-open'); posicionar();
    };
    const cerrar = () => { if (!abierto) return; abierto = false; wrap.classList.remove('md-open'); pop.remove(); };

    const fijar = (d) => {
      let val = iso(d);
      if (conHora) {
        const t = pop.querySelector('.md-time')?.value || '09:00';
        val += 'T' + t;
      }
      inp.value = val;
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      label();
    };

    trigger.addEventListener('click', () => abierto ? cerrar() : abrir());
    pop.addEventListener('click', (e) => {
      // pintar() regenera el HTML del pop y desconecta el nodo clicado; sin
      // esto, el clic sigue burbujeando al listener de "clic fuera" (que ya no
      // encuentra el nodo dentro del pop) y cierra el calendario al navegar.
      e.stopPropagation();
      const nav = e.target.closest('[data-nav]');
      if (nav) { ver = new Date(ver.getFullYear(), ver.getMonth() + parseInt(nav.dataset.nav, 10), 1); pintar(); return; }
      const dia = e.target.closest('.md-d[data-d]');
      if (dia) {
        const d = new Date(ver.getFullYear(), ver.getMonth(), parseInt(dia.dataset.d, 10));
        fijar(d); pintar();
        if (!conHora) cerrar();
        return;
      }
      if (e.target.closest('.md-hoy')) { fijar(new Date()); pintar(); if (!conHora) cerrar(); return; }
      if (e.target.closest('.md-borrar')) { inp.value = ''; inp.dispatchEvent(new Event('change', { bubbles: true })); label(); cerrar(); return; }
    });
    pop.addEventListener('change', (e) => {
      if (e.target.classList.contains('md-time') && parse(inp.value)) fijar(parse(inp.value));
    });
    document.addEventListener('click', (e) => { if (abierto && !pop.contains(e.target) && !wrap.contains(e.target)) cerrar(); });
    addEventListener('scroll', () => cerrar(), true);
    addEventListener('resize', () => cerrar());
    inp.addEventListener('md-sync', label);
    label();
  },
};
MecaDate.init();

/* =========================================================
   MecaWizard - asistente por pasos de los modales apaisados.
   Marca el formulario con novalidate y valida panel a panel,
   asi el navegador nunca intenta enfocar un campo escondido.
   ========================================================= */
const MecaWizard = {
  init(scope) {
    (scope || document).querySelectorAll('form.wz:not([data-wz])').forEach((f) => this.montar(f));
  },

  /** Texto legible del valor de un control (para el resumen final). */
  valorDe(ctrl) {
    if (!ctrl) return '';
    if (ctrl.tagName === 'SELECT') {
      const elegidas = [...ctrl.selectedOptions].filter((o) => o.value !== '0' && o.value !== '');
      return elegidas.map((o) => o.textContent.trim()).join(', ');
    }
    return (ctrl.value || '').trim();
  },

  /** Rellena el resumen del ultimo paso con lo que se lleva escrito. */
  resumir(form) {
    const caja = form.querySelector('.wz-resumen');
    if (!caja) return;
    caja.innerHTML = '';
    form.querySelectorAll('.wz-panel .campo').forEach((campo) => {
      if (campo.hasAttribute('data-sin-resumen')) return;
      const etiqueta = campo.querySelector(':scope > span');
      // Los campos de fecha quedan como input[type=hidden] tras MecaDate,
      // asi que se reconocen por su marca data-md en vez de por el tipo.
      const ctrl = [...campo.querySelectorAll('select, textarea, input')].find((c) =>
        c.dataset.md !== undefined ||
        !['radio', 'checkbox', 'color', 'hidden', 'submit', 'button'].includes(c.type));
      if (!etiqueta || !ctrl) return;
      const valor = this.valorDe(ctrl);
      const fila = document.createElement('div');
      const dt = document.createElement('dt');
      const dd = document.createElement('dd');
      dt.textContent = etiqueta.textContent.replace('*', '').trim();
      dd.textContent = valor || '— sin definir —';
      if (!valor) dd.className = 'vacio';
      fila.append(dt, dd);
      caja.appendChild(fila);
    });
  },

  montar(form) {
    form.dataset.wz = '1';
    form.setAttribute('novalidate', '');
    const pasos    = [...form.querySelectorAll('.wz-paso')];
    const paneles  = [...form.querySelectorAll('.wz-panel')];
    const btnAtras = form.querySelector('.wz-atras');
    const btnSig   = form.querySelector('.wz-siguiente');
    const btnOk    = form.querySelector('.wz-guardar');
    const contador = form.querySelector('.wz-contador');
    const tituloEl = form.querySelector('.wz-titulo-paso');
    const ayudaEl  = form.querySelector('.wz-ayuda-paso');
    if (!paneles.length) return;
    let actual = 0;

    // Controles del panel que el navegador puede validar
    const controles = (i) => [...paneles[i].querySelectorAll('input, select, textarea')]
      .filter((c) => c.willValidate && !c.disabled);
    const valido  = (i) => controles(i).every((c) => c.checkValidity());
    const avisar  = (i) => {
      const malo = controles(i).find((c) => !c.checkValidity());
      if (malo) malo.reportValidity();
    };

    const ir = (i) => {
      actual = Math.max(0, Math.min(paneles.length - 1, i));
      const ultimo = actual === paneles.length - 1;
      paneles.forEach((p, n) => p.classList.toggle('activo', n === actual));
      pasos.forEach((p, n) => {
        p.classList.toggle('activo', n === actual);
        p.classList.toggle('hecho', n < actual);
        const num = p.querySelector('.wz-num');
        if (num) num.innerHTML = n < actual ? '<i class="fa-solid fa-check"></i>' : String(n + 1);
      });
      if (contador) contador.textContent = 'Paso ' + (actual + 1) + ' de ' + paneles.length;
      const pasoAct = pasos[actual];
      if (tituloEl && pasoAct) tituloEl.textContent = pasoAct.dataset.titulo || '';
      if (ayudaEl && pasoAct) ayudaEl.textContent = pasoAct.dataset.ayuda || '';
      btnAtras?.classList.toggle('wz-oculto', actual === 0);
      btnSig?.classList.toggle('wz-oculto', ultimo);
      btnOk?.classList.toggle('wz-oculto', !ultimo);
      if (ultimo) this.resumir(form);
      const foco = paneles[actual].querySelector('input:not([type=hidden]):not([type=radio]):not([type=color]), textarea');
      if (foco) setTimeout(() => foco.focus({ preventScroll: true }), 60);
    };

    const avanzar = () => { if (valido(actual)) ir(actual + 1); else avisar(actual); };

    btnSig?.addEventListener('click', avanzar);
    btnAtras?.addEventListener('click', () => ir(actual - 1));
    pasos.forEach((p, n) => p.addEventListener('click', () => {
      if (n <= actual) return ir(n);
      for (let i = actual; i < n; i++) {
        if (!valido(i)) { ir(i); avisar(i); return; }
      }
      ir(n);
    }));

    form.addEventListener('submit', (e) => {
      // Enter a media asistente avanza en vez de enviar
      if (e.submitter !== btnOk) {
        e.preventDefault();
        avanzar();
        return;
      }
      const fallo = paneles.findIndex((_, i) => !valido(i));
      if (fallo !== -1) {
        e.preventDefault();
        ir(fallo);
        avisar(fallo);
      }
    });

    // Cada vez que se abre el modal, vuelve al primer paso
    const dlg = form.closest('dialog');
    if (dlg) {
      new MutationObserver(() => { if (dlg.open) ir(0); })
        .observe(dlg, { attributes: true, attributeFilter: ['open'] });
    }
    ir(0);
  },
};
MecaWizard.init();

// Fija el valor de un input de fecha y refresca su MecaDate
function setFecha(el, valor) {
  if (!el) return;
  el.value = valor || '';
  el.dispatchEvent(new Event('md-sync'));
}

/* ---------- Fechas de inicio/limite en los asistentes ---------- */

const isoDeFecha = (d) =>
  d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

/** Aviso en vivo: cuánto dura la tarea, o si las fechas están al revés. */
function actualizarDuracion(form) {
  const txt = form.querySelector('.wz-duracion');
  if (!txt) return;
  const ini = form.querySelector('input[name="fecha_inicio"]')?.value || '';
  const fin = form.querySelector('input[name="fecha_limite"]')?.value || '';
  txt.classList.remove('duracion-mal');
  if (!ini && !fin) { txt.textContent = 'Sin fechas: la tarea no aparecerá en el calendario.'; return; }
  if (!ini || !fin) {
    txt.textContent = ini ? 'Arranca el ' + ini + ', sin fecha límite.' : 'Con fecha límite el ' + fin + ', sin fecha de inicio.';
    return;
  }
  const dias = Math.round((new Date(fin + 'T12:00') - new Date(ini + 'T12:00')) / 86400000);
  if (dias < 0) {
    txt.textContent = 'El inicio es posterior a la fecha límite: corrige una de las dos.';
    txt.classList.add('duracion-mal');
    return;
  }
  txt.textContent = dias === 0
    ? 'Empieza y termina el mismo día.'
    : 'Ventana de ' + (dias + 1) + ' días (del ' + ini + ' al ' + fin + ').';
}

// Chips "Hoy / Mañana / El lunes que viene / En dos semanas"
document.addEventListener('click', (e) => {
  const chip = e.target.closest('[data-atajos-fecha] .chip-atajo');
  if (!chip) return;
  const form = chip.closest('form');
  const ini = form?.querySelector('input[name="fecha_inicio"]');
  if (!ini) return;

  if (chip.hasAttribute('data-limpiar')) {
    setFecha(ini, '');
  } else {
    const d = new Date();
    d.setHours(12, 0, 0, 0);
    if (chip.hasAttribute('data-lunes')) {
      d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7));   // el próximo lunes
    } else {
      d.setDate(d.getDate() + parseInt(chip.dataset.dias || '0', 10));
    }
    setFecha(ini, isoDeFecha(d));
  }
  form.querySelectorAll('[data-atajos-fecha] .chip-atajo').forEach((c) => c.classList.remove('activo'));
  chip.classList.add('activo');
  actualizarDuracion(form);
});

// Recalcular el aviso cuando se toca cualquiera de las dos fechas
document.addEventListener('change', (e) => {
  const inp = e.target;
  if (inp.name !== 'fecha_inicio' && inp.name !== 'fecha_limite') return;
  const form = inp.closest('form');
  if (form) actualizarDuracion(form);
});

// Formularios con confirmacion propia: <form data-confirmar="mensaje">
// (delegado: funciona también con formularios agregados dinámicamente)
document.addEventListener('submit', (e) => {
  const form = e.target.closest('form[data-confirmar]');
  if (!form || form.dataset.confirmado === '1') return;
  e.preventDefault();
  MC.confirm({
    titulo: form.dataset.confirmarTitulo || '¿Estás seguro?',
    mensaje: form.dataset.confirmar,
    ok: form.dataset.confirmarOk || 'Sí, continuar',
  }).then((si) => {
    if (si) {
      form.dataset.confirmado = '1';
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
  });
}, true);

/* ---------- Barra lateral ---------- */

// La lista de navegacion scrollea sin barra visible: si desborda, se le
// difumina el borde para que se vea que hay mas arriba o abajo.
const sidebarNav = document.querySelector('.sidebar-nav');
function marcarDesborde() {
  if (!sidebarNav) return;
  const resto = sidebarNav.scrollHeight - sidebarNav.clientHeight;
  const arriba = sidebarNav.scrollTop > 4;
  const abajo = resto > 4 && sidebarNav.scrollTop < resto - 4;
  sidebarNav.classList.toggle('hay-mas-arriba', arriba);
  sidebarNav.classList.toggle('hay-mas-abajo', abajo);
}
if (sidebarNav) {
  sidebarNav.addEventListener('scroll', marcarDesborde, { passive: true });
  addEventListener('resize', marcarDesborde);
  marcarDesborde();
  // La pagina activa puede quedar fuera de vista al cargar
  const activo = sidebarNav.querySelector('.sidebar-link.active');
  if (activo) {
    activo.scrollIntoView({ block: 'nearest' });
    marcarDesborde();
  }
}

// Grupo "Proyectos": con la barra plegada, el boton abre un panel flotante
// con todos los proyectos en vez de listarlos como iconos sueltos.
document.querySelectorAll('.nav-grupo').forEach((grupo) => {
  const btn = grupo.querySelector('.nav-grupo-btn');
  const panel = grupo.querySelector('.nav-grupo-items');
  if (!btn || !panel) return;

  const colocar = () => {
    const r = btn.getBoundingClientRect();
    panel.style.top = '0px';                       // mide con la altura real
    const alto = panel.offsetHeight;
    const margen = 12;
    // Si el boton esta en la mitad de abajo (la cuenta), el panel crece
    // hacia arriba alineando su base con la del boton.
    let top = r.top + r.height / 2 > innerHeight / 2 ? r.bottom - alto : r.top - 6;
    if (top + alto > innerHeight - margen) top = innerHeight - alto - margen;
    panel.style.top = Math.max(margen, top) + 'px';
  };
  const cerrar = () => {
    if (!grupo.classList.contains('abierto')) return;
    grupo.classList.remove('abierto');
    btn.setAttribute('aria-expanded', 'false');
  };
  const abrir = () => {
    grupo.classList.add('abierto');
    btn.setAttribute('aria-expanded', 'true');
    colocar();
  };

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    grupo.classList.contains('abierto') ? cerrar() : abrir();
  });
  document.addEventListener('click', (e) => { if (!panel.contains(e.target)) cerrar(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
  addEventListener('resize', cerrar);
});

// Colapsar / expandir la barra lateral (persistido en localStorage)
const sidebarToggle = document.getElementById('sidebar-toggle');
if (sidebarToggle) {
  sidebarToggle.addEventListener('click', () => {
    const min = document.documentElement.classList.toggle('sb-collapsed');
    localStorage.setItem('meca-sidebar', min ? 'min' : 'full');
    setTimeout(marcarDesborde, 320);   // tras la transicion de ancho
  });
}

// Modo oscuro configurable (persistido en localStorage)
const themeToggle = document.getElementById('theme-toggle');
if (themeToggle) {
  themeToggle.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('meca-theme', dark ? 'dark' : 'light');
  });
}

// Abrir modal de "nuevo proyecto" si se llego desde el sidebar
if (sessionStorage.getItem('abrirNuevo') === '1') {
  sessionStorage.removeItem('abrirNuevo');
  const dlg = document.getElementById('dlg-nuevo');
  if (dlg) dlg.showModal();
}

// Equipo maestro-detalle: seleccionar una fila muestra su card
const masterDetail = document.querySelector('.equipo-master-detail');
if (masterDetail) {
  const claveSel = 'persona-sel-' + (masterDetail.dataset.equipo || '');
  const seleccionar = (id) => {
    let hay = false;
    masterDetail.querySelectorAll('.persona-row').forEach((r) => {
      const activa = r.dataset.persona === id;
      r.classList.toggle('active', activa);
      if (activa) hay = true;
    });
    if (!hay) return false;
    masterDetail.querySelectorAll('[data-persona-card]').forEach((c) => {
      c.hidden = c.dataset.personaCard !== id;
    });
    return true;
  };
  masterDetail.querySelectorAll('.persona-row').forEach((row) => {
    row.addEventListener('click', (e) => {
      if (e.target.closest('.btn-copiar')) return;   // copiar no cambia la seleccion
      seleccionar(row.dataset.persona);
      sessionStorage.setItem(claveSel, row.dataset.persona);
      document.querySelector('.equipo-detalle')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });
  const guardada = sessionStorage.getItem(claveSel);
  if (guardada) seleccionar(guardada);
}

// Copiar al portapapeles (usuarios de git, correos...).
// navigator.clipboard solo existe en contexto seguro (HTTPS o localhost),
// asi que hay un respaldo con un textarea temporal para el resto de casos.
function copiarAlPortapapeles(texto) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(texto);
  }
  return new Promise((resolver, rechazar) => {
    const ta = document.createElement('textarea');
    ta.value = texto;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, ta.value.length);   // iOS
    const ok = document.execCommand && document.execCommand('copy');
    ta.remove();
    ok ? resolver() : rechazar(new Error('sin portapapeles'));
  });
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-copiar');
  if (!btn) return;
  const texto = btn.dataset.copiar || '';
  if (!texto) return;
  try {
    await copiarAlPortapapeles(texto);
    MC.toast('Copiado: ' + texto, 'success', 2500);
    // Confirmacion en el propio boton: el icono pasa a un check un momento
    const icono = btn.querySelector('i');
    if (icono && !btn.classList.contains('copiado')) {
      const clasesAntes = icono.className;
      btn.classList.add('copiado');
      icono.className = 'fa-solid fa-check';
      setTimeout(() => {
        btn.classList.remove('copiado');
        icono.className = clasesAntes;
      }, 1200);
    }
  } catch {
    MC.toast('No se pudo copiar. Selecciona el texto y usa Ctrl+C.', 'error');
  }
});

// Editor de repositorios de un proyecto: agregar y quitar filas.
document.querySelectorAll('[data-repos-editor]').forEach((editor) => {
  const filas = editor.querySelector('.repos-filas');
  const tpl   = editor.querySelector('#repo-fila-tpl');

  const sincronizar = () => {
    // El nombre solo tiene sentido si hay mas de un repo del mismo tipo;
    // igual se deja siempre editable, pero al menos una fila siempre existe.
    if (!filas.querySelector('.repo-fila')) agregar();
  };
  const agregar = () => {
    // Indice fresco para que los tres campos caigan en la misma fila del POST
    const i = parseInt(editor.dataset.repoSiguiente || '0', 10);
    editor.dataset.repoSiguiente = i + 1;
    const frag = tpl.content.cloneNode(true);
    frag.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace('__i__', i);
    });
    filas.appendChild(frag);
    const url = filas.querySelector('.repo-fila:last-child .repo-url');
    if (url) url.focus();
  };

  editor.querySelector('.repo-agregar').addEventListener('click', agregar);
  editor.addEventListener('click', (e) => {
    const quitar = e.target.closest('.repo-quitar');
    if (!quitar) return;
    quitar.closest('.repo-fila').remove();
    sincronizar();
  });
});

// Vistas de la pagina de proyecto.
// "Tareas" es un combo: agrupa Tabla / Kanban / Flujo bajo un subselector.
// Al entrar se muestra el Calendario; las subvistas recuerdan su eleccion.
const vistaToggle = document.querySelector('.vista-toggle');
if (vistaToggle) {
  const subToggle = document.querySelector('.subvista-toggle');
  const SUBVISTAS = ['tabla', 'kanban', 'flujo'];
  const claveVista = 'vista-' + location.pathname;
  const claveSub   = 'subvista-' + location.pathname;

  let subActual = sessionStorage.getItem(claveSub) || 'tabla';
  if (!SUBVISTAS.includes(subActual)) subActual = 'tabla';

  const panelActivo = (v) => (v === 'tareas' ? subActual : v);

  const pintar = (v) => {
    const panel = panelActivo(v);
    vistaToggle.querySelectorAll('[data-vista]').forEach((b) => b.classList.toggle('active', b.dataset.vista === v));
    document.querySelectorAll('[data-vista-panel]').forEach((p) => { p.hidden = p.dataset.vistaPanel !== panel; });
    if (subToggle) {
      subToggle.hidden = v !== 'tareas';
      subToggle.querySelectorAll('[data-subvista]').forEach((b) => b.classList.toggle('active', b.dataset.subvista === subActual));
    }
    if (panel === 'flujo') dibujarFlujo();
  };

  // Elegir una vista principal (calendario, tareas, observaciones, ...)
  const activarVista = (v) => {
    // Una subvista suelta (por hash o guardada) equivale a Tareas + esa subvista
    if (SUBVISTAS.includes(v)) { subActual = v; sessionStorage.setItem(claveSub, v); v = 'tareas'; }
    if (!vistaToggle.querySelector('[data-vista="' + v + '"]')) return;
    sessionStorage.setItem(claveVista, v);
    pintar(v);
  };

  vistaToggle.querySelectorAll('[data-vista]').forEach((btn) =>
    btn.addEventListener('click', () => activarVista(btn.dataset.vista)));

  if (subToggle) {
    subToggle.querySelectorAll('[data-subvista]').forEach((btn) =>
      btn.addEventListener('click', () => {
        subActual = btn.dataset.subvista;
        sessionStorage.setItem(claveSub, subActual);
        pintar('tareas');
      }));
  }

  // Al entrar: hash (#vista-X), luego lo ultimo elegido, si no el Calendario
  const porHash = location.hash.startsWith('#vista-') ? location.hash.slice(7) : null;
  activarVista(porHash || sessionStorage.getItem(claveVista) || 'calendario');
}

// Compositor de observaciones: pegar (Ctrl+V), arrastrar, adjuntar y enviar por AJAX.
// Soporta varios compositores en paralelo (botón "+ Otra nota").
function initComposer(form) {
  if (form.dataset.init === '1') return;
  form.dataset.init = '1';
  const fileInput = form.querySelector('.oc-file');
  const previews  = form.querySelector('.oc-previews');
  const textarea  = form.querySelector('.oc-texto');
  const bolsa = new DataTransfer();

  const pintar = () => {
    previews.innerHTML = '';
    [...bolsa.files].forEach((f, i) => {
      const chip = document.createElement('div');
      if (f.type.startsWith('image/')) {
        const url = URL.createObjectURL(f);
        chip.className = 'oc-prev oc-prev-img';
        chip.innerHTML = '<img src="' + url + '" alt=""><button type="button" data-i="' + i + '" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
      } else {
        chip.className = 'oc-prev oc-prev-doc';
        chip.innerHTML = '<i class="fa-solid fa-file-lines"></i><span>' + f.name + '</span>' +
                         '<button type="button" data-i="' + i + '" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
      }
      previews.appendChild(chip);
    });
    fileInput.files = bolsa.files;
  };
  const agregar = (files) => { [...files].forEach((f) => bolsa.items.add(f)); pintar(); };

  textarea.addEventListener('paste', (e) => {
    const imgs = [...(e.clipboardData?.items || [])].filter((it) => it.type.startsWith('image/'));
    if (!imgs.length) return;
    imgs.forEach((it, k) => {
      const blob = it.getAsFile();
      if (blob) bolsa.items.add(new File([blob], 'captura-' + Date.now() + '-' + k + '.png', { type: blob.type }));
    });
    pintar();
    MC.toast(imgs.length + ' imagen' + (imgs.length === 1 ? '' : 'es') + ' pegada' + (imgs.length === 1 ? '' : 's'), 'success', 2000);
  });
  form.addEventListener('dragover', (e) => { e.preventDefault(); form.classList.add('oc-drag'); });
  form.addEventListener('dragleave', () => form.classList.remove('oc-drag'));
  form.addEventListener('drop', (e) => {
    e.preventDefault(); form.classList.remove('oc-drag');
    if (e.dataTransfer.files.length) agregar(e.dataTransfer.files);
  });
  fileInput.addEventListener('change', () => agregar(fileInput.files));
  previews.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-i]');
    if (!b) return;
    bolsa.items.remove(parseInt(b.dataset.i, 10));
    pintar();
  });
  textarea.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); form.requestSubmit(); }
  });
  // Botón × para quitar el compositor (deja al menos uno)
  form.querySelector('.oc-cerrar')?.addEventListener('click', () => {
    const cont = document.getElementById('obs-composers');
    if (cont.querySelectorAll('.obs-composer').length > 1) { form.remove(); actualizarAddNota(); }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!textarea.value.trim() && !bolsa.files.length) {
      MC.toast('Escribe la observación o adjunta un archivo.', 'error');
      return;
    }
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    const fd = new FormData(form);
    fd.set('ajax', '1');
    try {
      const res = await fetch('actions.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
      const data = await res.json();
      if (!data.ok) { MC.toast(data.error || 'No se pudo guardar.', 'error'); return; }
      const lista = document.getElementById('obs-lista');
      lista.querySelector('.empty-state')?.remove();
      data.items.reverse().forEach((html) => lista.insertAdjacentHTML('afterbegin', html));
      textarea.value = '';
      while (bolsa.items.length) bolsa.items.remove(0);
      pintar();
      const total = document.querySelector('.obs-card .tabla-count');
      if (total) total.textContent = data.total;
      const chipPend = document.querySelector('#obs-filtros [data-filtro="pendiente"]');
      if (chipPend) chipPend.textContent = 'Pendientes' + (data.pendientes ? ' · ' + data.pendientes : '');
      const tabBadge = document.querySelector('.vista-toggle [data-vista="observaciones"] .tab-badge');
      if (tabBadge) tabBadge.textContent = data.pendientes;
      MC.toast(data.items.length > 1 ? data.items.length + ' observaciones anotadas' : 'Observación anotada', 'success', 1800);
      textarea.focus();
    } catch {
      MC.toast('Error de red al guardar la observación.', 'error');
    } finally {
      btn.disabled = false;
    }
  });
}

// Habilita/deshabilita el botón "+ Otra nota" según el máximo permitido
function actualizarAddNota() {
  const cont = document.getElementById('obs-composers');
  const btn = document.getElementById('obs-add-nota');
  if (!cont || !btn) return;
  const n = cont.querySelectorAll('.obs-composer').length;
  const max = parseInt(cont.dataset.max || '3', 10);
  cont.classList.toggle('oc-solo', n <= 1);   // oculta la × cuando solo hay uno
  btn.disabled = n >= max;
  btn.title = n >= max ? 'Máximo ' + max + ' notas a la vez' : 'Abrir otro cuadro para anotar en paralelo';
}

// Inicializa los compositores existentes y el botón de agregar
document.querySelectorAll('.obs-composer').forEach(initComposer);
(() => {
  const btn = document.getElementById('obs-add-nota');
  const cont = document.getElementById('obs-composers');
  const tpl = document.getElementById('tpl-composer');
  if (!btn || !cont || !tpl) return;
  actualizarAddNota();
  btn.addEventListener('click', () => {
    if (cont.querySelectorAll('.obs-composer').length >= parseInt(cont.dataset.max || '3', 10)) return;
    const nodo = tpl.content.firstElementChild.cloneNode(true);
    cont.appendChild(nodo);
    MecaSelect.init(nodo);
    initComposer(nodo);
    actualizarAddNota();
    nodo.querySelector('.oc-texto')?.focus();
  });
})();

// Filtro de observaciones (todas / pendientes / resueltas)
const obsFiltros = document.getElementById('obs-filtros');
if (obsFiltros) {
  const items = [...document.querySelectorAll('.obs-item')];
  obsFiltros.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip-filtro');
    if (!chip) return;
    obsFiltros.querySelectorAll('.chip-filtro').forEach((c) => c.classList.toggle('active', c === chip));
    const f = chip.dataset.filtro;
    items.forEach((it) => { it.hidden = f !== 'todas' && it.dataset.estado !== f; });
  });
}

// Conectores SVG entre tareas dependientes (vista Flujo)
function dibujarFlujo() {
  const wrap = document.getElementById('flujo-wrap');
  const svg = document.getElementById('flujo-lineas');
  if (!wrap || !svg) return;

  // Alinear cada nodo a la altura de su dependencia (flechas casi rectas)
  wrap.querySelectorAll('.flujo-nodo').forEach((n) => { n.style.marginTop = ''; });
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    const depId = nodo.dataset.dep;
    if (!depId || depId === '0') return;
    const origen = document.getElementById('fn-' + depId);
    if (!origen) return;
    const delta = origen.getBoundingClientRect().top - nodo.getBoundingClientRect().top;
    if (delta > 0) nodo.style.marginTop = delta + 'px';
  });

  const caja = wrap.getBoundingClientRect();
  svg.setAttribute('width', wrap.scrollWidth);
  svg.setAttribute('height', wrap.scrollHeight);
  const color = getComputedStyle(wrap).getPropertyValue('--pc').trim() || '#2B76F7';
  let trazos = '<defs><marker id="flecha" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5.5" markerHeight="5.5" orient="auto-start-reverse">' +
               '<path d="M 0 1.5 L 8 5 L 0 8.5 z" fill="' + color + '"/></marker></defs>';
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    const depId = nodo.dataset.dep;
    if (!depId || depId === '0') return;
    const origen = document.getElementById('fn-' + depId);
    if (!origen) return;
    const a = origen.getBoundingClientRect();
    const b = nodo.getBoundingClientRect();
    const x1 = a.right - caja.left + wrap.scrollLeft + 2;
    const y1 = a.top + a.height / 2 - caja.top + wrap.scrollTop;
    const x2 = b.left - caja.left + wrap.scrollLeft - 8;
    const y2 = b.top + b.height / 2 - caja.top + wrap.scrollTop;
    const cx = Math.max(34, (x2 - x1) * 0.55);
    trazos += '<circle cx="' + x1 + '" cy="' + y1 + '" r="4" fill="' + color + '"/>' +
              '<path d="M ' + x1 + ' ' + y1 + ' C ' + (x1 + cx) + ' ' + y1 + ', ' + (x2 - cx) + ' ' + y2 + ', ' + x2 + ' ' + y2 + '"' +
              ' fill="none" stroke="' + color + '" stroke-width="2.5" stroke-opacity=".8" stroke-linecap="round" marker-end="url(#flecha)"/>';
  });
  svg.innerHTML = trazos;
}
window.addEventListener('resize', () => {
  const panelFlujo = document.querySelector('[data-vista-panel="flujo"]');
  if (panelFlujo && !panelFlujo.hidden) dibujarFlujo();
});

// Kanban: arrastrar tarjetas entre columnas cambia el estado
const kanban = document.querySelector('.kanban');
if (kanban) {
  let arrastrando = null;
  kanban.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.kb-card');
    if (!card) return;
    arrastrando = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  kanban.addEventListener('dragend', () => {
    arrastrando?.classList.remove('dragging');
    kanban.querySelectorAll('.kb-cards').forEach((c) => c.classList.remove('drag-over'));
  });
  kanban.querySelectorAll('.kb-cards').forEach((zona) => {
    zona.addEventListener('dragover', (e) => { e.preventDefault(); zona.classList.add('drag-over'); });
    zona.addEventListener('dragleave', () => zona.classList.remove('drag-over'));
    zona.addEventListener('drop', (e) => {
      e.preventDefault();
      if (!arrastrando) return;
      const nuevoEstado = zona.dataset.estadoDrop;
      zona.appendChild(arrastrando);
      document.getElementById('kb-id').value = arrastrando.dataset.tarea;
      document.getElementById('kb-estado').value = nuevoEstado;
      document.getElementById('frm-kanban').submit();
    });
  });
}

// Paginador de la tabla de tareas (8 por página)
const cuerpoTabla = document.querySelector('[data-vista-panel="tabla"] .tabla-meca tbody');
if (cuerpoTabla) {
  const filas = [...cuerpoTabla.rows];
  const porPagina = 8;
  if (filas.length > porPagina) {
    const totalPaginas = Math.ceil(filas.length / porPagina);
    const cont = document.createElement('div');
    cont.className = 'paginador';
    document.querySelector('[data-vista-panel="tabla"] .tabla-scroll').after(cont);
    let pagina = 1;
    const pintar = () => {
      filas.forEach((f, i) => {
        f.style.display = (i >= (pagina - 1) * porPagina && i < pagina * porPagina) ? '' : 'none';
      });
      let html = '<button type="button" class="pg-btn" data-pg="prev" ' + (pagina === 1 ? 'disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>';
      for (let p = 1; p <= totalPaginas; p++) {
        html += '<button type="button" class="pg-btn ' + (p === pagina ? 'active' : '') + '" data-pg="' + p + '">' + p + '</button>';
      }
      html += '<button type="button" class="pg-btn" data-pg="next" ' + (pagina === totalPaginas ? 'disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>';
      cont.innerHTML = html;
    };
    cont.addEventListener('click', (e) => {
      const btn = e.target.closest('.pg-btn');
      if (!btn || btn.disabled) return;
      if (btn.dataset.pg === 'prev') pagina--;
      else if (btn.dataset.pg === 'next') pagina++;
      else pagina = parseInt(btn.dataset.pg, 10);
      pintar();
    });
    pintar();
  }
}

// Abrir modales por hash (ej. equipo.php#nuevo-colaborador)
if (location.hash === '#nuevo-colaborador') {
  const dlg = document.getElementById('dlg-nuevo-miembro');
  if (dlg) dlg.showModal();
}

// Rellenar y abrir el modal de edicion de tarea
document.querySelectorAll('[data-editar-tarea]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const t = JSON.parse(btn.dataset.editarTarea);
    const dlg = document.getElementById('dlg-editar-tarea');
    dlg.querySelector('#et-id').value = t.id;
    dlg.querySelector('#et-titulo').value = t.titulo;
    dlg.querySelector('#et-descripcion').value = t.descripcion;
    setFecha(dlg.querySelector('#et-inicio'), t.fecha_inicio);
    setFecha(dlg.querySelector('#et-fecha'), t.fecha_limite);
    dlg.querySelectorAll('[data-atajos-fecha] .chip-atajo').forEach((c) => c.classList.remove('activo'));
    setSelect(dlg.querySelector('.js-et-asignado'), t.asignados || (t.asignado_id ? [t.asignado_id] : []));
    setSelect(dlg.querySelector('.js-et-prioridad'), t.prioridad);
    setSelect(dlg.querySelector('.js-et-estado'), t.estado);
    const dep = dlg.querySelector('.js-et-depende');
    if (dep) {
      // Una tarea no puede depender de si misma
      [...dep.options].forEach((o) => { o.disabled = o.value === String(t.id); });
      setSelect(dep, t.depende_de || 0);
    }
    actualizarDuracion(dlg.querySelector('form'));
    dlg.showModal();
  });
});

// Formularios de persona: vista previa en vivo (avatar, nombre, rol, git, color, foto)
document.querySelectorAll('.form-persona').forEach((form) => {
  const campo = (n) => form.querySelector('[name="' + n + '"]');
  const av = form.querySelector('.pp-avatar-circle');
  const img = form.querySelector('.pp-img');
  const iniEl = form.querySelector('.pp-iniciales');

  const iniciales = (texto) => {
    const partes = texto.trim().split(/\s+/).filter(Boolean);
    if (!partes.length) return '?';
    let ini = partes[0][0].toUpperCase();
    if (partes.length > 1) ini += partes[partes.length - 1][0].toUpperCase();
    return ini;
  };

  const refrescar = () => {
    const nombre = campo('nombre').value.trim();
    form.querySelector('.pp-nombre').textContent = nombre || 'Nuevo colaborador';
    iniEl.textContent = iniciales(nombre);
    form.querySelector('.pp-rol-texto').textContent = campo('rol').value.trim() || 'Rol del equipo';
    form.querySelector('.pp-git-user').textContent =
      campo('git_user').value.trim().replace(/^@/, '') || 'usuario';
    const radio = form.querySelector('.color-picker input[type="radio"]:checked');
    const hex = radio && radio.value === 'custom'
      ? form.querySelector('.color-picker input[type="color"]').value
      : (radio ? radio.dataset.hex : null);
    if (hex) av.style.setProperty('--av-c1', hex);
  };
  form._refrescarPersona = refrescar;
  form._fotoPreview = (src) => {
    img.src = src || '';
    img.hidden = !src;
    iniEl.hidden = !!src;
  };

  ['nombre', 'rol', 'git_user'].forEach((n) => campo(n).addEventListener('input', refrescar));
  form.querySelectorAll('.color-picker input[type="radio"]').forEach((r) => r.addEventListener('change', refrescar));
  form.querySelector('.color-picker input[type="color"]').addEventListener('input', refrescar);
  form.querySelector('.pp-file').addEventListener('change', () => {
    const file = form.querySelector('.pp-file').files[0];
    if (file) form._fotoPreview(URL.createObjectURL(file));
  });
  refrescar();
});

// Rellenar y abrir el modal de edicion de miembro
document.querySelectorAll('[data-editar-miembro]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const m = JSON.parse(btn.dataset.editarMiembro);
    const dlg = document.getElementById('dlg-editar-miembro');
    const form = dlg.querySelector('form');
    dlg.querySelector('#em-id').value = m.id;
    form.querySelector('[name="nombre"]').value = m.nombre;
    form.querySelector('[name="rol"]').value = m.rol;
    form.querySelector('[name="git_user"]').value = m.git_user;
    form.querySelector('[name="email"]').value = m.email || '';
    const selEquipo = form.querySelector('[name="equipo"]');
    if (selEquipo && m.equipo) setSelect(selEquipo, m.equipo);
    form.querySelector('.pp-file').value = '';
    if (String(m.color).startsWith('#')) {
      form.querySelector('.color-picker input[value="custom"]').checked = true;
      form.querySelector('.color-picker input[type="color"]').value = m.color;
    } else {
      const radio = form.querySelector('.color-picker input[value="' + m.color + '"]');
      if (radio) {
        radio.checked = true;
        const mas = radio.closest('details');
        if (mas) mas.open = true;   // abrir "Más colores" si el color vive ahí
      }
    }
    form._fotoPreview(m.foto || '');
    form._refrescarPersona();
    dlg.showModal();
  });
});

// Al usar el picker de color personalizado, marcar su radio automaticamente
document.querySelectorAll('.color-picker .cp-custom input[type="color"]').forEach((inp) => {
  const marcar = () => {
    const radio = inp.closest('.cp-custom').querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
  };
  inp.addEventListener('input', marcar);
  inp.addEventListener('click', marcar);
});

// Cerrar dialogs al hacer click en el fondo
document.querySelectorAll('dialog.dlg-meca').forEach((dlg) => {
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg) dlg.close();
  });
});

// Comprimir fotos en el navegador antes de subirlas (evita el limite de PHP).
// Redimensiona a max 900px y convierte a JPEG; si algo falla, valida el tamano.
const LIMITE_SUBIDA = parseInt(document.body.dataset.limiteSubida || '2097152', 10);
document.querySelectorAll('.pp-file').forEach((input) => {
  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;
    try {
      const comprimida = await comprimirImagen(file, 900, 0.85);
      const dt = new DataTransfer();
      dt.items.add(new File([comprimida], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
      input.files = dt.files;
    } catch (e) {
      if (file.size > LIMITE_SUBIDA) {
        MC.toast('Esa foto pesa ' + (file.size / 1048576).toFixed(1) + ' MB y el límite es ' +
                 (LIMITE_SUBIDA / 1048576).toFixed(1) + ' MB. Usa una imagen más liviana.', 'error', 7000);
        input.value = '';
      }
    }
  });
});

function comprimirImagen(file, maxLado, calidad) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      const escala = Math.min(1, maxLado / Math.max(img.width, img.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.width * escala);
      canvas.height = Math.round(img.height * escala);
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('sin blob')), 'image/jpeg', calidad);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('no es imagen')); };
    img.src = url;
  });
}

// Tabs genericos (.tabs-meca + .tab-panel), con memoria de la pestana activa
document.querySelectorAll('.tabs-meca').forEach((tabs) => {
  const clave = 'tab-' + (tabs.dataset.clave || location.pathname);
  const activar = (id) => {
    tabs.querySelectorAll('.tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === id));
    document.querySelectorAll('.tab-panel').forEach((p) => { p.hidden = p.dataset.panel !== id; });
  };
  tabs.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      activar(btn.dataset.tab);
      sessionStorage.setItem(clave, btn.dataset.tab);
    });
  });
  const porHash = location.hash.startsWith('#tab-') ? location.hash.slice(5) : null;
  const guardado = porHash || sessionStorage.getItem(clave);
  if (guardado && tabs.querySelector('[data-tab="' + guardado + '"]')) activar(guardado);
});

// Galeria de iconos: clic para elegir; el valor se arma solo en el hidden
const galeriaIconos = document.querySelector('.icon-galeria');
if (galeriaIconos) {
  const valor = document.getElementById('iconos-valor');
  const conteo = document.getElementById('iconos-conteo');
  const sincronizar = () => {
    const sel = [...galeriaIconos.querySelectorAll('.ig-btn.sel')].map((b) => b.dataset.icono);
    valor.value = sel.join('\n');
    if (conteo) conteo.textContent = sel.length;
  };
  galeriaIconos.addEventListener('click', (e) => {
    const btn = e.target.closest('.ig-btn');
    if (!btn) return;
    btn.classList.toggle('sel');
    sincronizar();
  });

  // Agregar un icono que no este en la galeria (por clase FA)
  const extraBtn = document.getElementById('icono-extra-btn');
  const extraInp = document.getElementById('icono-extra');
  if (extraBtn && extraInp) {
    extraBtn.addEventListener('click', () => {
      const ic = extraInp.value.trim();
      if (!/^fa-[a-z0-9-]+$/.test(ic)) {
        MC.toast('Escribe una clase válida de Font Awesome, ej. fa-rocket', 'error');
        return;
      }
      let btn = galeriaIconos.querySelector('[data-icono="' + ic + '"]');
      if (!btn) {
        galeriaIconos.insertAdjacentHTML('afterbegin',
          '<button type="button" class="ig-btn sel" data-icono="' + ic + '" title="' + ic + '"><i class="fa-solid ' + ic + '"></i></button>');
      } else {
        btn.classList.add('sel');
      }
      extraInp.value = '';
      sincronizar();
    });
  }
}

// Stepper de catalogos: un paso a la vez con navegacion
const stepper = document.querySelector('.stepper');
if (stepper) {
  const pasos = [...stepper.querySelectorAll('.paso')];
  const paneles = [...document.querySelectorAll('[data-paso-panel]')];
  const btnPrev = document.getElementById('paso-prev');
  const btnNext = document.getElementById('paso-next');
  const indicador = document.getElementById('paso-indicador');
  const total = pasos.length;
  const clave = 'paso-' + (stepper.dataset.clave || '');

  const ir = (n) => {
    n = Math.min(total, Math.max(1, n));
    pasos.forEach((p) => {
      const num = parseInt(p.dataset.paso, 10);
      p.classList.toggle('active', num === n);
      p.classList.toggle('hecho', num < n);
    });
    paneles.forEach((panel) => { panel.hidden = panel.dataset.pasoPanel !== String(n); });
    if (btnPrev) btnPrev.disabled = n === 1;
    if (btnNext) btnNext.disabled = n === total;
    if (indicador) indicador.textContent = 'Paso ' + n + ' de ' + total;
    sessionStorage.setItem(clave, n);
    return n;
  };

  let actual = parseInt(sessionStorage.getItem(clave), 10) || 1;
  actual = ir(actual);
  pasos.forEach((p) => p.addEventListener('click', () => { actual = ir(parseInt(p.dataset.paso, 10)); }));
  btnPrev?.addEventListener('click', () => { actual = ir(actual - 1); });
  btnNext?.addEventListener('click', () => { actual = ir(actual + 1); });
}

// Tarjetas de colores de marca: vista previa en vivo y contraste del texto
document.querySelectorAll('.tarjeta-color').forEach((card) => {
  const inp = card.querySelector('input[type="color"]');
  const hex = card.querySelector('.tc-hex');
  const aplicar = () => {
    const c = inp.value;
    card.style.setProperty('--tc', c);
    hex.textContent = c.toUpperCase();
    const r = parseInt(c.slice(1, 3), 16), g = parseInt(c.slice(3, 5), 16), b = parseInt(c.slice(5, 7), 16);
    card.classList.toggle('claro', (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.62);
  };
  inp.addEventListener('input', aplicar);
  aplicar();
});

// Ajustes: agregar y quitar filas de catalogos (estados, prioridades...)
let filaContador = 1000;
document.querySelectorAll('.btn-agregar-fila').forEach((btn) => {
  btn.addEventListener('click', () => {
    const tpl = document.getElementById(btn.dataset.plantilla);
    const lista = document.getElementById(btn.dataset.lista);
    if (!tpl || !lista) return;
    const alInicio = btn.dataset.insertar === 'inicio';
    const html = tpl.innerHTML.replaceAll('__i__', String(filaContador++));
    lista.insertAdjacentHTML(alInicio ? 'afterbegin' : 'beforeend', html);
    const fila = alInicio ? lista.firstElementChild : lista.lastElementChild;
    fila.querySelector('input:not([type="hidden"]):not([type="color"]):not(.input-icono)')?.focus();
  });
});

// mc-tabla: edicion en linea (lapiz <-> check); Enter confirma sin enviar el form
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-editar-fila');
  if (!btn) return;
  const fila = btn.closest('.mc-fila');
  const dato = fila.querySelector('.mc-fila-dato');
  if (dato.readOnly) {
    dato.readOnly = false;
    fila.classList.add('editando');
    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
    btn.title = 'Listo';
    dato.focus();
    dato.select();
  } else {
    dato.readOnly = true;
    fila.classList.remove('editando');
    btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
    btn.title = 'Editar';
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' || !e.target.classList?.contains('mc-fila-dato')) return;
  e.preventDefault();   // que no envie todo el formulario
  e.target.closest('.mc-fila').querySelector('.btn-editar-fila').click();
});
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-quitar-fila');
  if (!btn) return;
  const lista = btn.closest('.ajuste-lista');
  if (lista && lista.children.length <= 1) {
    MC.toast('Debe quedar al menos una opción en el catálogo.', 'error');
    return;
  }
  btn.closest('.ajuste-fila').remove();
});

// Quitar toasts del DOM cuando termina su animacion de salida
document.querySelectorAll('.toast-float').forEach((t) => {
  t.addEventListener('animationend', (e) => {
    if (e.animationName === 'toast-out') t.remove();
  });
});

// Abrir el modal "ver como" por hash (para enlaces directos)
if (location.hash === '#abrir-ver-como') {
  document.getElementById('dlg-ver-como')?.showModal();
}

/* Respaldo de configuracion: muestra el nombre del .json elegido */
document.addEventListener('change', (e) => {
  const input = e.target.closest('.respaldo-archivo input[type="file"]');
  if (!input) return;
  const label = input.closest('.respaldo-archivo');
  const txt   = label.querySelector('span');
  const f     = input.files && input.files[0];
  label.classList.toggle('tiene-archivo', !!f);
  txt.innerHTML = f
    ? '<i class="fa-solid fa-file-circle-check"></i> ' + f.name
    : '<i class="fa-solid fa-file-arrow-up"></i> Elegir archivo .json';
});
