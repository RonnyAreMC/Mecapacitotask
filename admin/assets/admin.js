/* Mecapacito Admin - interacciones del panel */

/* =========================================================
   Feedback de carga: barra verde arriba al navegar + spinner en el
   botón que se envía. Hace que "cargar la pestaña" se vea, no un salto seco.
   ========================================================= */
(function () {
  const barra = document.createElement('div');
  barra.className = 'nav-progreso';
  document.addEventListener('DOMContentLoaded', () => document.body.appendChild(barra));
  const mostrar = () => barra.classList.add('activa');

  // Al abandonar la página (submit de formulario, clic en enlace, redirección)
  addEventListener('beforeunload', mostrar);

  // Volver con el botón "atrás" restaura la página tal cual quedó (bfcache),
  // barra encendida incluida: hay que apagarla a mano.
  addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    barra.classList.remove('activa');
    document.querySelectorAll('.btn-cargando').forEach((b) => b.classList.remove('btn-cargando'));
  });

  // Spinner en el botón que envía el formulario.
  document.addEventListener('submit', (e) => {
    // Las descargas no recargan la página: si mostráramos spinner/barra se
    // quedarían girando para siempre. Los formularios de descarga se marcan
    // con data-descarga.
    if (e.target && e.target.dataset && e.target.dataset.descarga !== undefined) return;
    const b = e.submitter;
    // Este listener va en captura, así que corre ANTES que los de burbuja: aún
    // no se sabe si alguno hará preventDefault para guardar por AJAX. Se decide
    // al final del ciclo; si nadie navega, no hay quien apague la barra y se
    // quedaría colgada arriba.
    setTimeout(() => {
      if (e.defaultPrevented) return;
      if (b && b.tagName === 'BUTTON' && !b.dataset.noSpin) b.classList.add('btn-cargando');
      mostrar();
    }, 0);
  }, true);
})();

/* =========================================================
   MC — componente de alertas Mecapacito.
   Nunca usar alert()/confirm() nativos: MC.toast y MC.confirm.
   ========================================================= */
const MC = {
  _iconos: { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' },

  /**
   * Sonidos cortos, sintetizados con Web Audio: no hay archivos que cargar ni
   * peticiones que esperar. Se apagan con localStorage['mc-sin-sonido'] = '1'.
   * El navegador solo deja sonar después de que la persona haya interactuado;
   * como todos salen de un clic o de Ctrl+Enter, eso ya se cumple.
   */
  _audio: null,
  _ctx() {
    try {
      if (localStorage.getItem('mc-sin-sonido') === '1') return null;
    } catch { /* modo privado: se deja sonar */ }
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    if (!this._audio) this._audio = new AC();
    if (this._audio.state === 'suspended') this._audio.resume();
    return this._audio;
  },
  /** Una nota con caída suave; f2 la desliza para dar el "pop". */
  _nota(ctx, f1, f2, inicio, dur, vol, tipo = 'sine') {
    const osc = ctx.createOscillator();
    const g = ctx.createGain();
    const t = ctx.currentTime + inicio;
    osc.type = tipo;
    osc.frequency.setValueAtTime(f1, t);
    if (f2 !== f1) osc.frequency.exponentialRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    osc.connect(g).connect(ctx.destination);
    osc.start(t);
    osc.stop(t + dur + 0.02);
  },
  /** Al enviar: dos notas que suben, cortas. */
  sonidoEnviar() {
    const ctx = this._ctx(); if (!ctx) return;
    this._nota(ctx, 620, 880, 0, 0.10, 0.055);
    this._nota(ctx, 900, 1180, 0.07, 0.12, 0.038);
  },
  /** Al abrir el cuadro de responder: un toque casi imperceptible. */
  sonidoAbrir() {
    const ctx = this._ctx(); if (!ctx) return;
    this._nota(ctx, 420, 520, 0, 0.06, 0.022);
  },
  /**
   * Estrena un elemento recién insertado: entra con su animación y se queda
   * un momento resaltado, para saber cuál es el nuevo sin buscarlo.
   */
  estrenar(el) {
    if (!el || !el.classList) return;
    el.classList.add('obs-estreno');
    el.addEventListener('animationend', () => el.classList.remove('obs-estreno'), { once: true });
  },

  /** Cuando algo sale mal: una nota que baja. */
  sonidoError() {
    const ctx = this._ctx(); if (!ctx) return;
    this._nota(ctx, 330, 190, 0, 0.16, 0.05, 'triangle');
  },

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
            '<button type="button" class="btn-outline btn-meca btn-neutro mcc-no"></button>' +
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
      // El panel copiaba el ancho del disparador y en los filtros estrechos los
      // nombres salían cortados ("Carlos …"). Se le da un mínimo, sin pasarse
      // del borde de la ventana.
      const ancho = Math.min(Math.max(r.width, 260), innerWidth - 20);
      panel.style.width = ancho + 'px';
      panel.style.left = Math.max(10, Math.min(r.left, innerWidth - ancho - 10)) + 'px';
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
    // El panel es fixed: si la página se mueve, se cierra para no quedar
    // suelto. Pero con captura llegan también los scrolls de DENTRO del panel,
    // y con ellos la lista de opciones no había manera de recorrerla.
    addEventListener('scroll', (e) => {
      if (e.target instanceof Node && panel.contains(e.target)) return;
      cerrar();
    }, true);
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
      if (!etiqueta) return;

      const dt = document.createElement('dt');
      dt.textContent = etiqueta.textContent.replace('*', '').trim();
      const dd = document.createElement('dd');
      const fila = document.createElement('div');

      // Editor de texto enriquecido: se muestra RENDERIZADO (con su tabla y
      // formato), no como HTML crudo. Es lo mismo que se va a guardar.
      const rico = campo.querySelector('[data-editor-rico]');
      if (rico) {
        const html = (rico.querySelector('.rt-fuente')?.value || '').trim();
        if (html) { dd.innerHTML = html; dd.classList.add('rt-render', 'wz-rico'); }
        else { dd.textContent = '— sin definir —'; dd.className = 'vacio'; }
        fila.classList.add('wz-resumen-bloque');
        fila.append(dt, dd);
        caja.appendChild(fila);
        return;
      }

      // Los campos de fecha quedan como input[type=hidden] tras MecaDate,
      // asi que se reconocen por su marca data-md en vez de por el tipo.
      const ctrl = [...campo.querySelectorAll('select, textarea, input')].find((c) =>
        c.dataset.md !== undefined ||
        !['radio', 'checkbox', 'color', 'hidden', 'submit', 'button'].includes(c.type));
      if (!ctrl) return;
      const valor = this.valorDe(ctrl);
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

/* =========================================================
   Selector de dependencias (crear / editar tarea).
   Paso 1: ¿tiene dependencias? Paso 2 (si sí): se elige el equipo y sus
   tareas (buscador + casillas). Las elegidas quedan como chips con el icono y
   color de su equipo, y como <input hidden name="dependencias[]"> del form.
   Puede depender de tareas de OTRO equipo (dependencia cruzada).
   ========================================================= */
(function () {
  const fuente = document.getElementById('dep-datos');
  if (!fuente) return;
  let DATOS = [];
  try { DATOS = JSON.parse(fuente.textContent || '[]'); } catch (_) { DATOS = []; }
  if (!Array.isArray(DATOS) || !DATOS.length) return;

  // Índice global: id de tarea → su equipo (título, color, icono, actual)
  const IDX = {};
  DATOS.forEach((eq) => (eq.tareas || []).forEach((t) => {
    IDX[t.id] = { titulo: t.titulo, final: t.final, equipo: eq.nombre, color: eq.color, icono: eq.icono, actual: eq.actual };
  }));

  function montar(el) {
    const body   = el.querySelector('.dp-body');
    const ops    = [...el.querySelectorAll('.dp-op')];
    const selEq  = el.querySelector('.dp-equipo');
    const buscar = el.querySelector('.dp-buscar');
    const lista  = el.querySelector('.dp-opciones');
    const chips  = el.querySelector('.dp-chips');
    const hidden = el.querySelector('.dp-hidden');
    const sel    = new Set();      // ids elegidos
    let selfId   = 0;              // la tarea que se edita no depende de sí misma

    selEq.innerHTML = DATOS.map((eq) =>
      `<option value="${eq.id}">${eq.actual ? 'Este equipo · ' : ''}${esc(eq.nombre)}</option>`).join('');

    const equipoActivo = () => DATOS.find((eq) => String(eq.id) === String(selEq.value)) || DATOS[0];

    function pintarLista() {
      const eq = equipoActivo();
      const q  = (buscar.value || '').trim().toLowerCase();
      const items = (eq ? eq.tareas || [] : []).filter((t) =>
        t.id !== selfId && (!q || t.titulo.toLowerCase().includes(q)));
      lista.innerHTML = items.length
        ? items.map((t) =>
            `<label class="dp-op-tarea${sel.has(t.id) ? ' sel' : ''}">
               <input type="checkbox" value="${t.id}" ${sel.has(t.id) ? 'checked' : ''}>
               <span class="dp-t-tit">${esc(t.titulo)}</span>
               ${t.final ? '<span class="dp-t-fin" title="Ya completada"><i class="fa-solid fa-check"></i></span>' : ''}
             </label>`).join('')
        : '<p class="dp-vacio">No hay tareas que coincidan.</p>';
    }

    function pintarChips() {
      const arr = [...sel];
      chips.innerHTML = arr.map((id) => {
        const info = IDX[id]; if (!info) return '';
        return `<span class="dp-chip${info.actual ? '' : ' externo'}" style="--dc:${esc(info.color)}">
                  ${info.actual ? '<i class="fa-solid fa-link"></i>' : info.icono}
                  <span class="dp-chip-tit">${esc(info.titulo)}</span>
                  ${info.actual ? '' : `<em class="dp-chip-eq">${esc(info.equipo)}</em>`}
                  <button type="button" class="dp-quita" data-id="${id}" title="Quitar" aria-label="Quitar">&times;</button>
                </span>`;
      }).join('');
      hidden.innerHTML = arr.map((id) => `<input type="hidden" name="dependencias[]" value="${id}">`).join('');
    }

    function setModo(si) {
      ops.forEach((o) => o.classList.toggle('active', (o.dataset.dep === 'si') === si));
      body.hidden = !si;
      if (si) { pintarLista(); pintarChips(); }
      else { hidden.innerHTML = ''; }   // en "Sin dependencias" no se envía ninguna (la selección se conserva por si vuelve)
    }

    ops.forEach((o) => o.addEventListener('click', () => setModo(o.dataset.dep === 'si')));
    selEq.addEventListener('change', () => { buscar.value = ''; pintarLista(); });
    buscar.addEventListener('input', pintarLista);
    lista.addEventListener('change', (e) => {
      const cb = e.target.closest('input[type=checkbox]'); if (!cb) return;
      const id = parseInt(cb.value, 10);
      if (cb.checked) { if (IDX[id]) sel.add(id); } else { sel.delete(id); }
      cb.closest('.dp-op-tarea')?.classList.toggle('sel', cb.checked);
      pintarChips();
    });
    chips.addEventListener('click', (e) => {
      const b = e.target.closest('.dp-quita'); if (!b) return;
      sel.delete(parseInt(b.dataset.id, 10));
      pintarChips();
      pintarLista();
    });

    el.depAPI = {
      reset() { sel.clear(); selfId = 0; selEq.value = DATOS[0].id; buscar.value = ''; setModo(false); pintarChips(); },
      excludeSelf(id) { selfId = parseInt(id, 10) || 0; sel.delete(selfId); pintarLista(); pintarChips(); },
      setDeps(ids) {
        (ids || []).map(Number).forEach((id) => { if (IDX[id] && id !== selfId) sel.add(id); });
        pintarChips();
        if (sel.size) setModo(true);
      },
    };
    el.depAPI.reset();

    // Al cerrar su modal se limpia, para no arrastrar lo elegido a la próxima.
    const dlg = el.closest('dialog');
    if (dlg) dlg.addEventListener('close', () => el.depAPI.reset());
  }

  document.querySelectorAll('[data-dep-picker]').forEach(montar);
})();

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
  // Las tareas la llaman fecha_limite y los requerimientos fecha_fin: es la
  // misma fecha de cierre y el aviso vale igual para las dos.
  const fin = form.querySelector('input[name="fecha_limite"], input[name="fecha_fin"]')?.value || '';
  txt.classList.remove('duracion-mal');
  if (!ini && !fin) { txt.textContent = 'Sin fechas: no aparecerá en el calendario.'; return; }
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
  if (!['fecha_inicio', 'fecha_limite', 'fecha_fin'].includes(inp.name)) return;
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
    // Lo que se pinta en un <canvas>/SVG no se entera de que cambió el CSS:
    // los gráficos del panel escuchan esto para repintarse con la otra paleta.
    document.dispatchEvent(new CustomEvent('meca:tema', { detail: { oscuro: dark } }));
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

  // Ramas del repo: el <select> arranca con lo guardado y pide la lista real a
  // ramas.php (cacheada una hora en el servidor). Se hace por fila y solo una
  // vez por URL, para no consultar la API en cada clic.
  const cargarRamas = (fila) => {
    const sel = fila.querySelector('.repo-rama');
    const url = (fila.querySelector('.repo-url')?.value || '').trim();
    if (!sel || !url || sel.dataset.url === url) return;
    sel.dataset.url = url;
    const guardada = sel.value;
    fila.classList.add('repo-cargando');
    fetch('ramas.php?url=' + encodeURIComponent(url))
      .then((r) => r.json())
      .then((j) => {
        const ramas = (j && j.ramas) || [];
        // Sin lista (repo privado sin token, host desconocido) se deja lo que
        // ya había: no se le borra la rama al guardar.
        if (!ramas.length) { sel.dataset.url = ''; return; }
        sel.innerHTML = '';
        sel.add(new Option('Rama por defecto', ''));
        ramas.forEach((rn) => sel.add(new Option(rn, rn)));
        if (guardada && !ramas.includes(guardada)) sel.add(new Option(guardada + ' (ya no existe)', guardada));
        sel.value = guardada;
      })
      .catch(() => { sel.dataset.url = ''; })
      .finally(() => fila.classList.remove('repo-cargando'));
  };
  const cargarTodas = () => filas.querySelectorAll('.repo-fila').forEach(cargarRamas);

  // Al abrir el modal de proyecto (o al entrar en la página, si el editor no
  // vive en un diálogo) ya están las ramas listas cuando se despliega.
  const dlg = editor.closest('dialog');
  if (dlg) {
    new MutationObserver(() => { if (dlg.hasAttribute('open')) cargarTodas(); })
      .observe(dlg, { attributes: true, attributeFilter: ['open'] });
  } else {
    cargarTodas();
  }
  // Respaldo: si se pega la URL después, la lista se rehace al salir del campo
  editor.addEventListener('change', (e) => {
    const f = e.target.closest('.repo-fila');
    if (f && e.target.classList.contains('repo-url')) cargarRamas(f);
  });
  editor.addEventListener('mousedown', (e) => {
    const f = e.target.closest('.repo-fila');
    if (f && e.target.classList.contains('repo-rama')) cargarRamas(f);
  });

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
  // El campo pasó a editor enriquecido: el HTML viaja en el textarea oculto
  // (.rt-fuente) y se escribe en el contenteditable (.rt-area). Los eventos de
  // teclado y de pegar van en el área; el valor, en la fuente.
  const area      = form.querySelector('.rt-area');
  const fuente    = form.querySelector('.rt-fuente');
  const rico      = form.querySelector('[data-editor-rico]');
  const hayTexto  = () => (area?.innerText || '').trim() !== '' || /<(img|table|li)\b/i.test(fuente?.value || '');
  const limpiar   = () => {
    if (area) area.innerHTML = '';
    if (fuente) fuente.value = '';
    rico?.classList.add('rt-vacio');
  };
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

  area.addEventListener('paste', (e) => {
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
  area.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); form.requestSubmit(); }
  });
  // Botón × para quitar el compositor (deja al menos uno)
  form.querySelector('.oc-cerrar')?.addEventListener('click', () => {
    const cont = document.getElementById('obs-composers');
    if (cont.querySelectorAll('.obs-composer').length > 1) { form.remove(); actualizarAddNota(); }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!hayTexto() && !bolsa.files.length) {
      MC.sonidoError();
      MC.toast('Escribe la observación o adjunta un archivo.', 'error');
      return;
    }
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.classList.add('btn-enviando');
    const fd = new FormData(form);
    fd.set('ajax', '1');
    try {
      const res = await fetch('actions.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
      const data = await res.json();
      if (!data.ok) { MC.sonidoError(); MC.toast(data.error || 'No se pudo guardar.', 'error'); return; }
      const lista = document.getElementById('obs-lista');
      lista.querySelector('.empty-state')?.remove();
      data.items.reverse().forEach((html) => {
        lista.insertAdjacentHTML('afterbegin', html);
        MC.estrenar(lista.firstElementChild);
      });
      limpiar();
      while (bolsa.items.length) bolsa.items.remove(0);
      pintar();
      const total = document.querySelector('.obs-card .tabla-count');
      if (total) total.textContent = data.total;
      const chipPend = document.querySelector('#obs-filtros [data-filtro="pendiente"]');
      if (chipPend) chipPend.textContent = 'Pendientes' + (data.pendientes ? ' · ' + data.pendientes : '');
      const tabBadge = document.querySelector('.vista-toggle [data-vista="observaciones"] .tab-badge');
      if (tabBadge) tabBadge.textContent = data.pendientes;
      MC.sonidoEnviar();
      MC.toast(data.items.length > 1 ? data.items.length + ' observaciones anotadas' : 'Observación anotada', 'success', 1800);
      area.focus();
    } catch {
      MC.sonidoError();
      MC.toast('Error de red al guardar la observación.', 'error');
    } finally {
      btn.disabled = false;
      btn.classList.remove('btn-enviando');
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
    nodo.querySelector('.rt-area')?.focus();
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

  const depsDe = (nodo) => (nodo.dataset.deps || nodo.dataset.dep || '').split(',').map((s) => s.trim()).filter((s) => s && s !== '0');
  // Alinear cada nodo a la altura de su PRIMERA dependencia (flechas casi rectas)
  wrap.querySelectorAll('.flujo-nodo').forEach((n) => { n.style.marginTop = ''; });
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    const deps = depsDe(nodo);
    if (!deps.length) return;
    const origen = document.getElementById('fn-' + deps[0]);
    if (!origen) return;
    const delta = origen.getBoundingClientRect().top - nodo.getBoundingClientRect().top;
    if (delta > 0) nodo.style.marginTop = delta + 'px';
  });

  const caja = wrap.getBoundingClientRect();
  svg.setAttribute('width', wrap.scrollWidth);
  svg.setAttribute('height', wrap.scrollHeight);
  const color = getComputedStyle(wrap).getPropertyValue('--pc').trim() || '#2B76F7';
  let trazos = '<defs><marker id="flecha" viewBox="0 0 10 10" refX="7.5" refY="5" markerWidth="6" markerHeight="6" orient="auto">' +
               '<path d="M 0.5 1 L 8.5 5 L 0.5 9 Q 3 5 0.5 1 z" fill="' + color + '"/></marker></defs>';
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    // Todas las dependencias que apuntan a ESTE nodo, ordenadas por la altura
    // de su origen para que las flechas no se crucen.
    const origenes = depsDe(nodo)
      .map((id) => document.getElementById('fn-' + id))
      .filter(Boolean)
      .sort((a, c) => a.getBoundingClientRect().top - c.getBoundingClientRect().top);
    if (!origenes.length) return;
    const b = nodo.getBoundingClientRect();
    const x2 = b.left - caja.left + wrap.scrollLeft - 9;
    origenes.forEach((origen, i) => {
      const a = origen.getBoundingClientRect();
      const x1 = a.right - caja.left + wrap.scrollLeft + 2;
      const y1 = a.top + a.height / 2 - caja.top + wrap.scrollTop;
      // Reparte los puntos de llegada a lo alto del nodo destino (abanico), así
      // varias dependencias no chocan en el mismo punto.
      const frac = origenes.length === 1 ? 0.5 : 0.28 + 0.44 * (i / (origenes.length - 1));
      const y2 = b.top + b.height * frac - caja.top + wrap.scrollTop;
      const cx = Math.max(42, (x2 - x1) * 0.5);
      trazos += '<circle cx="' + x1 + '" cy="' + y1 + '" r="3.5" fill="' + color + '"/>' +
                '<path d="M ' + x1 + ' ' + y1 + ' C ' + (x1 + cx) + ' ' + y1 + ', ' + (x2 - cx) + ' ' + y2 + ', ' + x2 + ' ' + y2 + '"' +
                ' fill="none" stroke="' + color + '" stroke-width="2.2" stroke-opacity=".9" stroke-linecap="round" marker-end="url(#flecha)"/>';
    });
  });
  svg.innerHTML = trazos;
}
window.addEventListener('resize', () => {
  const panelFlujo = document.querySelector('[data-vista-panel="flujo"]');
  if (panelFlujo && !panelFlujo.hidden) dibujarFlujo();
});

/* Guarda el estado de una tarea por AJAX (sin recargar → sin "brinco").
   Actualiza los contadores del kanban con lo que devuelve el servidor.
   Devuelve una promesa con {ok, ...}. */
function guardarEstadoTarea(id, estado) {
  const body = new URLSearchParams({ accion: 'tarea_estado', id, estado, ajax: '1' });
  return fetch('actions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
    body,
  }).then((r) => r.json()).then((res) => {
    if (res && res.ok) pintarAvanceProyecto(res);
    return res || { ok: false };
  }).catch(() => ({ ok: false, error: 'Sin conexión' }));
}

/* Refresca lo que la recarga pintaba sola: contadores del kanban, tiles de
   resumen por estado y la barra de avance de la cabecera. Sin esto, guardar
   por AJAX deja esos números en el valor viejo. */
function pintarAvanceProyecto(res) {
  if (res.conteo) {
    // Con el filtro de fechas puesto, el tablero NO muestra todo el proyecto:
    // el conteo del servidor (que sí es del proyecto entero) dejaria columnas
    // diciendo 6 con dos tarjetas dentro. Ahi se cuentan las tarjetas reales.
    const kbFiltrado = !!document.querySelector('.kanban[data-filtrado]');
    document.querySelectorAll('.kanban .kb-cards[data-estado-drop]').forEach((z) => {
      const k = z.dataset.estadoDrop;
      const head = z.closest('.kb-col')?.querySelector('.kb-count');
      if (!head) return;
      if (kbFiltrado) head.textContent = z.querySelectorAll('.kb-card').length;
      else if (res.conteo[k] !== undefined) head.textContent = res.conteo[k];
    });
    // Los estados del encabezado del proyecto (.phk) y las tarjetas sueltas
    // que siguen usando otras pantallas (.estado-tile).
    const total = Object.values(res.conteo).reduce((a, n) => a + n, 0);
    Object.keys(res.conteo).forEach((k) => {
      const n = res.conteo[k];
      const viejo = document.querySelector('.estado-tile.estado-' + k + ' .et-datos b');
      if (viejo) viejo.textContent = n;

      const kpi = document.querySelector('.phk.estado-' + k);
      if (!kpi) return;
      const cifra = kpi.querySelector('.phk-linea b');
      if (cifra) cifra.textContent = n;
      // La barra es la parte del total: sin esto se queda con el ancho viejo.
      const relleno = kpi.querySelector('.phk-barra > span');
      if (relleno) relleno.style.width = (total > 0 ? Math.round(n / total * 100) : 0) + '%';
      const lbl = kpi.querySelector('.phk-linea small');
      kpi.title = n + ' de ' + total + (lbl ? ' · ' + lbl.textContent : '');
    });
  }

  if (typeof res.avance !== 'number') return;
  // Mismo corte de semáforo que proyecto.php
  const nivel = res.avance >= 67 ? 'verde' : (res.avance >= 34 ? 'amarillo' : 'rojo');

  const hero = document.querySelector('.proyecto-hero .ph-barra');
  if (hero) {
    hero.title = 'Avance del proyecto: ' + res.avance + '%';
    const relleno = hero.querySelector('span');
    if (relleno) relleno.style.width = res.avance + '%';
  }

  const caja = document.querySelector('.ph-avance-abajo');
  if (!caja) return;
  caja.title = res.completadas + ' de ' + res.total + ' tareas completadas';
  const texto = caja.querySelector('small');
  if (texto) texto.textContent = res.completadas + '/' + res.total + ' tareas';
  const semaforo = caja.querySelector('.barra-semaforo');
  if (semaforo) {
    semaforo.className = 'barra-semaforo sem-' + nivel;
    const relleno = semaforo.querySelector('span');
    if (relleno) relleno.style.width = res.avance + '%';
  }
  const pct = caja.querySelector('.pam-num');
  if (pct) {
    pct.className = 'pam-num sem-txt-' + nivel;
    pct.textContent = res.avance + '%';
  }
}

// Kanban: arrastrar tarjetas entre columnas cambia el estado (AJAX, sin recargar)
const kanban = document.querySelector('.kanban');
if (kanban) {
  let arrastrando = null;
  let origen = null;
  kanban.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.kb-card');
    if (!card || card.getAttribute('draggable') === 'false') return;
    arrastrando = card;
    origen = card.parentElement;   // por si hay que revertir
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
      zona.classList.remove('drag-over');
      if (!arrastrando || zona === origen) return;
      const card = arrastrando;
      const previo = origen;
      const nuevoEstado = zona.dataset.estadoDrop;
      zona.appendChild(card);                 // movimiento optimista
      card.classList.add('kb-guardando');     // feedback (pulso), sin recargar
      guardarEstadoTarea(card.dataset.tarea, nuevoEstado).then((res) => {
        card.classList.remove('kb-guardando');
        if (res.ok) {
          MC?.toast?.('Estado actualizado', 'success', 1600);
        } else {
          previo.appendChild(card);           // revertir si falló
          MC?.toast?.(res.error || 'No se pudo mover la tarea', 'error');
        }
      });
    });
  });

  /* Mover RÁPIDO sin arrastrar: el botón "⋮" de la tarjeta abre un menú con las
     otras columnas; un clic la mueve. Imprescindible cuando una columna tiene
     muchas tarjetas y arrastrar hasta otra es un suplicio. */
  const kbEstados = (() => { try { return JSON.parse(kanban.dataset.estados || '[]'); } catch (_) { return []; } })();
  const cerrarMenus = () => kanban.querySelectorAll('.kb-menu').forEach((m) => m.remove());

  const moverTarjeta = (card, est) => {
    const destino = kanban.querySelector('.kb-cards[data-estado-drop="' + est.k + '"]');
    if (!destino) return;
    const previo = card.parentElement;
    destino.prepend(card);
    card.classList.add('kb-guardando');
    guardarEstadoTarea(card.dataset.tarea, est.k).then((res) => {
      card.classList.remove('kb-guardando');
      if (res.ok) MC?.toast?.('Movida a «' + est.label + '»', 'success', 1400);
      else { previo.appendChild(card); MC?.toast?.(res.error || 'No se pudo mover la tarea', 'error'); }
    });
  };

  kanban.addEventListener('click', (e) => {
    const btn = e.target.closest('.kb-mover');
    if (!btn) return;
    e.stopPropagation();                 // no abrir el detalle de la tarjeta
    const yaAbierto = btn.parentElement.querySelector('.kb-menu');
    cerrarMenus();
    if (yaAbierto) return;               // segundo clic: cerrar
    const card = btn.closest('.kb-card');
    const actual = card.closest('.kb-cards')?.dataset.estadoDrop;
    const menu = document.createElement('div');
    menu.className = 'kb-menu';
    menu.innerHTML = '<span class="kb-menu-tit">Mover a…</span>';
    kbEstados.filter((s) => s.k !== actual).forEach((s) => {
      const op = document.createElement('button');
      op.type = 'button';
      op.className = 'kb-menu-op';
      op.innerHTML = (s.svg || '') + ' ' + s.label;
      op.addEventListener('click', (ev) => { ev.stopPropagation(); cerrarMenus(); moverTarjeta(card, s); });
      menu.appendChild(op);
    });
    btn.parentElement.appendChild(menu);
  });
  document.addEventListener('click', cerrarMenus);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarMenus(); });

  /* Auto-scroll al arrastrar cerca del borde superior/inferior de la ventana:
     sin esto, con muchas tarjetas no se alcanza la columna destino. */
  let autoScroll = 0, rafId = null;
  const tick = () => { if (autoScroll) window.scrollBy(0, autoScroll); rafId = requestAnimationFrame(tick); };
  const pararScroll = () => { autoScroll = 0; if (rafId) { cancelAnimationFrame(rafId); rafId = null; } };
  kanban.addEventListener('dragstart', () => { if (!rafId) rafId = requestAnimationFrame(tick); });
  kanban.addEventListener('dragover', (e) => {
    const m = 90, paso = 20;
    autoScroll = e.clientY < m ? -paso : (e.clientY > window.innerHeight - m ? paso : 0);
  });
  kanban.addEventListener('dragend', pararScroll);
  kanban.addEventListener('drop', pararScroll);
}

// Tabla: el select de estado también guarda por AJAX (no recarga la página)
document.addEventListener('submit', (e) => {
  const form = e.target;
  const acc = form.querySelector?.('input[name="accion"]');
  if (!acc || acc.value !== 'tarea_estado' || form.id === 'frm-kanban') return;
  const id = form.querySelector('input[name="id"]')?.value;
  const estado = form.querySelector('[name="estado"]')?.value;
  if (!id || !estado) return;
  e.preventDefault();
  const sel = form.querySelector('[name="estado"]');
  const previo = [...(sel?.classList || [])].find((c) => c.startsWith('estado-'));
  guardarEstadoTarea(id, estado).then((res) => {
    // Recolorear la píldora: sin recarga, la clase estado-* quedaría en el valor viejo.
    if (res.ok && sel) {
      if (previo) sel.classList.remove(previo);
      sel.classList.add('estado-' + estado);
    } else if (!res.ok && sel && previo) {
      sel.value = previo.slice('estado-'.length);   // revertir lo que no se guardó
    }
    MC?.toast?.(res.ok ? 'Estado actualizado' : (res.error || 'No se pudo cambiar'), res.ok ? 'success' : 'error', res.ok ? 1600 : 4000);
  });
});

// Paginador de la tabla de tareas (8 por página).
//
// Pagina sobre las filas que PASAN EL FILTRO, no sobre todas. Antes repartía
// por el índice en la lista completa, así que al buscar solo salían las
// coincidencias que caían en la página en la que estabas: el contador decía
// "4" y en pantalla había 2. Se recalcula en cada búsqueda (evento
// 'tabla-filtrada') y se vuelve a la página 1.
const cuerpoTabla = document.querySelector('[data-vista-panel="tabla"] .tabla-meca tbody');
if (cuerpoTabla) {
  // La fila de "ninguna tarea coincide" vive en el mismo tbody y no es una
  // tarea: no cuenta para las páginas.
  const filas = [...cuerpoTabla.rows].filter((f) => !f.hasAttribute('data-no-buscar'));
  const porPagina = 8;
  if (filas.length > porPagina) {
    const cont = document.createElement('div');
    cont.className = 'paginador';
    document.querySelector('[data-vista-panel="tabla"] .tabla-scroll').after(cont);
    let pagina = 1;
    const pintar = () => {
      const visibles = filas.filter((f) => !f.classList.contains('fila-oculta'));
      const totalPaginas = Math.max(1, Math.ceil(visibles.length / porPagina));
      if (pagina > totalPaginas) pagina = totalPaginas;

      filas.forEach((f) => { f.style.display = 'none'; });
      visibles.slice((pagina - 1) * porPagina, pagina * porPagina)
              .forEach((f) => { f.style.display = ''; });

      // Con los resultados en una sola página, el paginador estorba
      cont.hidden = visibles.length <= porPagina;
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
    // Al buscar, se reparte de nuevo desde la primera página
    cuerpoTabla.addEventListener('tabla-filtrada', () => { pagina = 1; pintar(); });
    pintar();
  }
}

// Abrir modales por hash (ej. equipo.php#nuevo-colaborador)
if (location.hash === '#nuevo-colaborador') {
  const dlg = document.getElementById('dlg-nuevo-miembro');
  if (dlg) dlg.showModal();
}

/* ---------- Detalle de tarea (solo lectura, para cualquiera) ---------- */
function abrirDetalleTarea(t) {
  const esc = (x) => String(x == null ? '' : x).replace(/[&<>"]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const dlg = document.getElementById('dlg-detalle-tarea');
  if (!dlg) return;
  const set = (sel, val) => { const el = dlg.querySelector(sel); if (el) el.textContent = val; };

  set('.dt-proyecto', t.proyecto || '');
  set('.dt-titulo', t.titulo || '');

  const desc = dlg.querySelector('.dt-desc');
  // La descripción es HTML ya saneado en el servidor (tablas, formato): innerHTML.
  if (desc) { desc.innerHTML = t.descripcion || ''; desc.hidden = !t.descripcion; }

  const chips = dlg.querySelector('.dt-chips');
  if (chips) {
    // Reutiliza los badges de color del tablero (estado y prioridad)
    chips.innerHTML = (t.estado_badge || '') + (t.prio_badge || '');
  }

  const asig = t.asignados && t.asignados.length ? t.asignados.join(', ') : 'Sin asignar';
  set('.dt-asignados', asig);

  let fechas = '';
  if (t.fecha_inicio && t.fecha_limite) {
    const d = Math.round((new Date(t.fecha_limite + 'T12:00') - new Date(t.fecha_inicio + 'T12:00')) / 86400000);
    fechas = 'Del ' + t.fecha_inicio + ' al ' + t.fecha_limite + (d >= 0 ? ' · ' + (d + 1) + ' días' : '');
  } else if (t.fecha_limite) { fechas = 'Límite: ' + t.fecha_limite; }
  else if (t.fecha_inicio) { fechas = 'Arranca: ' + t.fecha_inicio; }
  else { fechas = 'Sin fechas'; }
  set('.dt-fechas', fechas);

  // Carga cruzada: requerimientos sueltos que le cayeron a la misma persona en
  // esas fechas. Explica por qué la tarea puede ir lenta sin tener que
  // preguntar; el servidor ya cruzó los rangos.
  const carga = dlg.querySelector('.dt-carga');
  if (carga) {
    const lista = t.carga_extra || [];
    carga.hidden = !lista.length;
    carga.innerHTML = lista.length
      ? '<b>También tiene trabajo en paralelo en estas fechas</b><ul>' + lista.map((c) =>
          '<li>' + esc(c.persona) + ' — <i>' + esc(c.origen || '') + '</i>: «' + esc(c.titulo) +
          '» del ' + esc(c.ini) + ' al ' + esc(c.fin) + '</li>'
        ).join('') + '</ul>'
      : '';
  }

  // Cuánto queda (o cuánto lleva vencida): es lo primero que uno mira
  const restante = dlg.querySelector('.dt-restante');
  if (restante) {
    const lim = t.fecha_limite ? new Date(t.fecha_limite + 'T12:00') : null;
    const hoy = new Date(); hoy.setHours(12, 0, 0, 0);
    const dd = lim ? Math.round((lim - hoy) / 86400000) : null;
    restante.hidden = dd === null;
    if (dd !== null) {
      restante.textContent = dd > 1 ? 'Faltan ' + dd + ' días'
        : dd === 1 ? 'Falta 1 día'
        : dd === 0 ? 'Vence hoy'
        : dd === -1 ? 'Venció ayer' : 'Venció hace ' + (-dd) + ' días';
      restante.classList.toggle('urgente', dd <= 0);
    }
  }

  const filaCreada = dlg.querySelector('.dt-fila-creada');
  if (filaCreada) {
    filaCreada.hidden = !t.creado;
    if (t.creado) set('.dt-creada', t.creado);
  }

  const filaDep = dlg.querySelector('.dt-fila-dep');
  if (filaDep) {
    const deps = t.deps || [];
    filaDep.hidden = deps.length === 0;
    dlg.querySelector('.dt-dep').innerHTML = deps.map((d) => {
      const urg = d.urgencia === 'vencida' ? '<span class="dt-dep-urg venc"><i class="fa-solid fa-triangle-exclamation"></i> vencida</span>'
                : d.urgencia === 'proxima' ? '<span class="dt-dep-urg prox"><i class="fa-regular fa-clock"></i> próxima a vencer</span>' : '';
      const eq = d.externa
        ? '<span class="dt-dep-eq" style="--dc:' + esc(d.color) + '">' + (d.icono || '') + ' ' + esc(d.equipo) + '</span>'
        : '<span class="dt-dep-eq propia"><i class="fa-solid fa-link"></i> este tablero</span>';
      const est = d.final ? '<i class="fa-solid fa-check dt-dep-ok" title="Ya completada"></i>' : '';
      const btn = d.externa
        ? '<button type="button" class="dt-dep-recordar" data-tarea-id="' + t.id + '" data-dep-id="' + d.id + '"' +
          ' data-dep-titulo="' + esc(d.titulo) + '" data-dep-eq="' + esc(d.equipo) + '"' +
          ' data-dep-avisar="' + esc((d.avisar || []).join(', ')) + '">' +
          '<i class="fa-solid fa-bell"></i> Recordar</button>'
        : '';
      return '<div class="dt-dep-item' + (d.externa ? ' externo' : '') + '" style="--dc:' + esc(d.color || '#64748b') + '">' +
               '<div class="dt-dep-l">' + eq + ' <span class="dt-dep-t">' + esc(d.titulo) + '</span> ' + est + ' ' + urg + '</div>' +
               btn +
             '</div>';
    }).join('');
  }
  const filaObs = dlg.querySelector('.dt-fila-obs');
  if (filaObs) {
    filaObs.hidden = !t.obs;
    if (t.obs) set('.dt-obs', t.obs + ' observación' + (t.obs === 1 ? '' : 'es') + ' pendiente' + (t.obs === 1 ? '' : 's'));
  }
  // Documentos de respaldo: se listan a la izquierda y el primero ya se
  // previsualiza a la derecha, sin tener que pulsar nada.
  const filaAdj = dlg.querySelector('.dt-fila-adj');
  if (filaAdj) {
    dtAdjuntos = t.adjuntos || [];
    filaAdj.hidden = dtAdjuntos.length === 0;
    const cajaAdj = dlg.querySelector('.dt-adjuntos');
    cajaAdj.innerHTML = dtAdjuntos.map((a, i) => {
      const ver = previsualizable(a.ext);
      // El que no se puede previsualizar es un enlace de descarga normal
      return '<a class="adj-chip' + (ver ? '' : ' adj-bajar') + '" href="' + esc(a.ruta) + '"' +
        (ver ? ' data-i="' + i + '"' : ' download') + ' target="_blank" rel="noopener"' +
        ' title="' + (ver ? 'Ver ' : 'Descargar ') + esc(a.nombre) + '">' +
        '<i class="fa-solid ' + iconoAdjunto(a.ext) + '"></i><span>' + esc(a.nombre) + '</span>' +
        '<i class="fa-solid ' + (ver ? 'fa-eye' : 'fa-download') + ' adj-chip-acc"></i></a>';
    }).join('');

    // La previsualización solo aparece si hay algo que enseñar
    const verIdx = dtAdjuntos.findIndex((a) => previsualizable(a.ext));
    dlg.classList.toggle('dt-con-docs', verIdx >= 0);
    dlg.querySelector('.dt-previa').hidden = verIdx < 0;
    dlg.querySelector('.dt-previa-cuerpo').innerHTML = '';
    if (verIdx >= 0) dtVerDoc(verIdx);
  }
  dlg.showModal();
}
// Escapa para texto Y para atributos. Ojo con las comillas: sin ellas, un
// nombre de archivo o un mensaje de commit que las lleve rompe el atributo
// donde se interpola (que es como se perdían los datos de los adjuntos).
function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Icono por extensión (mismo criterio que iconoAdjunto() en PHP)
function iconoAdjunto(ext) {
  const e = String(ext || '').toLowerCase();
  if (e === 'pdf') return 'fa-file-pdf';
  if (e === 'doc' || e === 'docx') return 'fa-file-word';
  if (e === 'xls' || e === 'xlsx' || e === 'csv') return 'fa-file-excel';
  if (e === 'ppt' || e === 'pptx') return 'fa-file-powerpoint';
  if (e === 'txt') return 'fa-file-lines';
  if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(e)) return 'fa-file-image';
  return 'fa-paperclip';
}

/* ---------- Avisar al equipo: a quién y con qué tareas ---------- */
document.querySelectorAll('[data-avisar]').forEach((caja) => {
  const form = caja.closest('form');
  const resumen = form.querySelector('.av-resumen');
  const enviar = form.querySelector('button[type="submit"], .btn-primary');

  const contar = () => {
    let personas = 0, tareas = 0;
    caja.querySelectorAll('.av-persona').forEach((p) => {
      const marcadas = p.querySelectorAll('.av-tareas input:checked').length;
      if (marcadas) { personas++; tareas += marcadas; }
      // El "todas" de la persona refleja el estado real de sus tareas
      const todo = p.querySelector('.av-todo');
      const total = p.querySelectorAll('.av-tareas input:not(:disabled)').length;
      if (todo && !todo.disabled) {
        todo.checked = marcadas > 0;
        todo.indeterminate = marcadas > 0 && marcadas < total;
      }
      p.classList.toggle('av-fuera', total > 0 && marcadas === 0);
    });
    resumen.textContent = personas === 0
      ? 'Nada marcado: no se enviará ningún correo'
      : personas + (personas === 1 ? ' correo' : ' correos') + ' · ' + tareas + (tareas === 1 ? ' tarea' : ' tareas');
    resumen.classList.toggle('av-nada', personas === 0);
    if (enviar) enviar.disabled = personas === 0;
  };

  caja.addEventListener('change', (e) => {
    // La casilla de la persona marca o desmarca todas las suyas
    if (e.target.classList.contains('av-todo')) {
      const on = e.target.checked;
      e.target.closest('.av-persona').querySelectorAll('.av-tareas input:not(:disabled)')
        .forEach((c) => { c.checked = on; });
    }
    contar();
  });

  // Las tareas van plegadas: con diez por persona, la lista entera abierta era
  // un muro. Se despliega solo a quien quieras afinar.
  caja.addEventListener('click', (e) => {
    const btn = e.target.closest('.av-abrir');
    if (!btn) return;
    const p = btn.closest('.av-persona');
    const abierto = p.classList.toggle('av-abierta');
    p.querySelector('.av-tareas').hidden = !abierto;
    btn.title = abierto ? 'Ocultar sus tareas' : 'Ver y elegir sus tareas';
  });

  // Marcar todo / ninguno
  form.querySelectorAll('[data-marcar]').forEach((b) => {
    b.addEventListener('click', () => {
      const on = b.dataset.marcar === '1';
      caja.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach((c) => { c.checked = on; });
      contar();
    });
  });

  contar();
});

/* ---------- Previsualización de un documento ---------- */
// Imágenes, PDF y texto se ven en el panel. Word/Excel/PowerPoint no se pueden
// mostrar sin mandar el archivo a un servicio de terceros, así que en esos se
// ofrece la descarga y se dice por qué.
// Solo el PDF se previsualiza. Todo lo demás —imágenes incluidas— se descarga
// desde su chip: menos elementos moviéndose en la ficha y una regla sola.
/* Qué se puede ver sin descargar: el PDF en un iframe y las imágenes tal
   cual. Lo demás (doc, docx) no lo pinta el navegador, así que se baja. */
const IMAGENES_VISIBLES = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg'];
const esImagen = (ext) => IMAGENES_VISIBLES.includes(String(ext || '').toLowerCase());
const previsualizable = (ext) => String(ext || '').toLowerCase() === 'pdf' || esImagen(ext);

function pintarPrevia(cont, a) {
  if (!cont || !a || !previsualizable(a.ext)) return;
  cont.innerHTML = esImagen(a.ext)
    ? '<img class="adj-visor-img" src="' + esc(a.ruta) + '" alt="' + esc(a.nombre || '') + '">'
    : '<iframe class="adj-visor-frame" src="' + esc(a.ruta) + '#view=FitH" title="' + esc(a.nombre || '') + '"></iframe>';
}

/* ---------- Previsualización dentro del detalle de la tarea ---------- */
let dtAdjuntos = [], dtIdx = 0;

function dtVerDoc(i) {
  const dlg = document.getElementById('dlg-detalle-tarea');
  const a = dtAdjuntos[i];
  if (!dlg || !a || !previsualizable(a.ext)) return;
  dtIdx = i;
  dlg.querySelectorAll('.dt-adjuntos .adj-chip').forEach((el) =>
    el.classList.toggle('activo', parseInt(el.dataset.i, 10) === i));
  dlg.querySelector('.dt-previa-nom b').textContent = a.nombre || '';
  dlg.querySelector('.dt-previa-nom i').className = 'fa-solid ' + iconoAdjunto(a.ext);
  // Sin botón de descarga aquí: el visor de PDF ya trae el suyo
  pintarPrevia(dlg.querySelector('.dt-previa-cuerpo'), a);
}

const dlgDetalle = document.getElementById('dlg-detalle-tarea');
if (dlgDetalle) {
  // Al cerrar se descarga el visor: si no, el PDF se queda vivo por detrás
  dlgDetalle.addEventListener('close', () => {
    dlgDetalle.querySelector('.dt-previa-cuerpo').innerHTML = '';
    dtAdjuntos = [];
  });
  dlgDetalle.addEventListener('click', (e) => {
    // Pulsar un documento cambia el de la derecha; con Ctrl/Cmd, pestaña nueva
    const chip = e.target.closest('.dt-adjuntos .adj-chip[data-i]');
    if (!chip || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    dtVerDoc(parseInt(chip.dataset.i, 10));
  });
}

/* ---------- Documentos de respaldo de una tarea (campo de los modales) ---------- */
document.querySelectorAll('[data-adjuntos-tarea]').forEach((campo) => {
  const lista  = campo.querySelector('.adj-lista');
  const nuevos = campo.querySelector('.adj-nuevos');
  const input  = campo.querySelector('input[type="file"]');

  // Los que ya tiene la tarea: se marcan para quitar, y solo se borran al
  // guardar. Aquí NO se abre el visor (esto es el formulario de edición): el
  // nombre abre el archivo en una pestaña y ya está.
  campo.pintarAdjuntos = (adj) => {
    const items = adj || [];
    lista.hidden = items.length === 0;
    lista.innerHTML = items.map((a) =>
      '<li class="adj-item"><i class="fa-solid ' + iconoAdjunto(a.ext) + '"></i>' +
      '<a href="' + esc(a.ruta) + '" target="_blank" rel="noopener"' +
      ' title="Abrir ' + esc(a.nombre) + ' en una pestaña">' + esc(a.nombre) + '</a>' +
      '<input type="checkbox" name="quitar_adjunto[]" value="' + esc(a.ruta) + '" hidden>' +
      '<button type="button" class="adj-quitar" title="Quitar al guardar">' +
      '<i class="fa-solid fa-xmark"></i></button></li>').join('');
  };
  campo.pintarAdjuntos([]);

  lista.addEventListener('click', (e) => {
    const btn = e.target.closest('.adj-quitar');
    if (!btn) return;
    const li = btn.closest('.adj-item');
    const chk = li.querySelector('input[type="checkbox"]');
    chk.checked = !chk.checked;
    li.classList.toggle('adj-fuera', chk.checked);
    btn.title = chk.checked ? 'Recuperar' : 'Quitar al guardar';
    btn.innerHTML = '<i class="fa-solid ' + (chk.checked ? 'fa-rotate-left' : 'fa-xmark') + '"></i>';
  });

  // El input nativo REEMPLAZA lo elegido en cada pasada, así que eligiendo de
  // uno en uno solo sobrevivía el último. Se acumulan aparte y se le devuelven
  // al input, que es lo que se envía.
  const cola = new DataTransfer();
  const peso = (n) => (n < 1024 * 1024 ? Math.max(1, Math.round(n / 1024)) + ' KB'
                                       : (n / 1048576).toFixed(1) + ' MB');

  const pintarNuevos = () => {
    // Se pinta lo que el input lleva de verdad, no la cola: si algún navegador
    // no dejara reescribir input.files, la lista no promete lo que no se envía.
    const arch = [...input.files];
    nuevos.hidden = arch.length === 0;
    nuevos.innerHTML = arch.map((f, i) =>
      '<li class="adj-item adj-nuevo"><i class="fa-solid ' + iconoAdjunto(f.name.split('.').pop()) + '"></i>' +
      '<span>' + esc(f.name) + '</span><small class="adj-peso">' + peso(f.size) + '</small>' +
      '<button type="button" class="adj-quitar" data-i="' + i + '" title="Quitar de la lista">' +
      '<i class="fa-solid fa-xmark"></i></button></li>').join('');
  };

  campo.limpiarNuevos = () => {
    while (cola.items.length) cola.items.remove(0);
    input.value = '';
    pintarNuevos();
  };

  input.addEventListener('change', () => {
    [...input.files].forEach((f) => {
      // Mismo nombre y tamaño = el mismo archivo elegido dos veces
      const repetido = [...cola.files].some((x) => x.name === f.name && x.size === f.size);
      if (!repetido) cola.items.add(f);
    });
    input.files = cola.files;
    pintarNuevos();
  });

  nuevos.addEventListener('click', (e) => {
    const btn = e.target.closest('.adj-quitar');
    if (!btn) return;
    cola.items.remove(parseInt(btn.dataset.i, 10));
    input.files = cola.files;
    pintarNuevos();
  });
});

// "Recordar" en el detalle de una dependencia de otro equipo → abre el modal
// de recordatorio con el contexto y a quién le llegará.
document.addEventListener('click', (e) => {
  const b = e.target.closest('.dt-dep-recordar');
  if (!b) return;
  const dlg = document.getElementById('dlg-dep-recordar');
  if (!dlg) return;
  dlg.querySelector('#dr-tarea').value = b.dataset.tareaId || '';
  dlg.querySelector('#dr-dep').value = b.dataset.depId || '';
  dlg.querySelector('.dr-contexto').textContent =
    'Tu tarea espera por «' + (b.dataset.depTitulo || '') + '» del equipo ' + (b.dataset.depEq || 'otro equipo') + '.';
  const avisar = (b.dataset.depAvisar || '').trim();
  dlg.querySelector('.dr-para').innerHTML = '<i class="fa-solid fa-paper-plane"></i> Se avisará a: <b>' +
    (avisar ? esc(avisar) : 'los responsables y el Scrum Master de ese equipo') + '</b>';
  const detalle = document.getElementById('dlg-detalle-tarea');
  if (detalle && detalle.open) detalle.close();
  dlg.showModal();
});

// Clic en una tarea (fila, kanban, flujo, calendario) → detalle de solo lectura
document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-ver-tarea]');
  if (!el) return;
  // No abrir si se clicó un control interno (estado, editar, borrar, enlaces),
  // salvo que ese control sea la tarjeta misma (el evento del calendario es button).
  const ctrl = e.target.closest('button, select, a, input, textarea, form, .ms, .md, .btn-copiar');
  if (ctrl && ctrl !== el && el.contains(ctrl)) return;
  try { abrirDetalleTarea(JSON.parse(el.dataset.verTarea)); } catch (_) {}
});

/* El flujo del estándar movió tareas según los commits (lo hace el servidor al
   traer los aportes). Refleja el nuevo estado en la tabla —sin re-guardar, porque
   fijar .value no dispara el onchange— y avisa con un toast. */
function aplicarMovidasEnTabla(movidas) {
  let n = 0;
  movidas.forEach((mv) => {
    const idi = [...document.querySelectorAll('form.inline-form input[name="id"]')]
      .find((el) => el.value == mv.tarea && el.form && el.form.querySelector('[name="accion"]') &&
                    el.form.querySelector('[name="accion"]').value === 'tarea_estado');
    if (idi) {
      const sel = idi.form.querySelector('select[name="estado"]');
      if (sel && sel.value !== mv.a) {
        sel.value = mv.a;
        sel.className = sel.className.replace(/\bestado-[a-z0-9_]+/gi, '').replace(/\s+/g, ' ').trim() + ' estado-' + mv.a;
      }
      const fila = idi.closest('.fila-tarea');
      if (fila) fila.classList.toggle('fila-hecha', mv.a === 'hecho');
    }
    // Kanban: mover la tarjeta a la columna del nuevo estado
    const card = document.querySelector('.kb-card[data-tarea="' + mv.tarea + '"]');
    const destino = document.querySelector('.kb-cards[data-estado-drop="' + mv.a + '"]');
    if (card && destino && card.parentElement !== destino) destino.appendChild(card);
    n++;
  });
  // Recontar las columnas del kanban tras mover
  document.querySelectorAll('.kb-cards[data-estado-drop]').forEach((z) => {
    const head = z.closest('.kb-col') ? z.closest('.kb-col').querySelector('.kb-count') : null;
    if (head) head.textContent = z.querySelectorAll('.kb-card').length;
  });
  if (n && window.MC && MC.toast) {
    MC.toast(n === 1 ? 'Una tarea avanzó según los commits.'
                     : n + ' tareas avanzaron según los commits.', 'success');
  }
}

/* ---------- Aportes del equipo: commits por persona (Métricas) ---------- */
document.querySelectorAll('[data-aportes]').forEach((caja) => {
  const dataEl = caja.querySelector('[data-aportes-data]');
  if (!dataEl) return;
  let data;
  try { data = JSON.parse(dataEl.textContent); } catch { return; }
  let commits = data.commits || [];
  const miembros = data.miembros || [];
  const tareas = data.tareas || {};
  const repos = data.repos || [];

  // A cada commit le asigna el miembro del panel cruzando por CUALQUIERA de sus
  // identidades de Git: usuario(s), correo, parte local del correo o nombre del
  // autor. Así una persona con varios usuarios (misma cuenta) igual suma sus
  // commits, y GitLab (cuyo login es la parte local del correo) también cuadra.
  const norm = (s) => (s || '').toLowerCase().trim().replace(/\s+/g, ' ');
  const porGit = {};
  miembros.forEach((m) => { (m.gits || (m.git ? [m.git] : [])).forEach((g) => { const k = norm(g); if (k) porGit[k] = m; }); });
  const mapear = (cs) => { cs.forEach((c) => {
    const local = (c.email || '').split('@')[0];
    c.miembro = porGit[norm(c.login)] || porGit[norm(c.email)] || porGit[norm(local)] || porGit[norm(c.nombre)] || null;
  }); };
  mapear(commits);

  const sel = caja.querySelector('.ap-persona');
  const selRama = caja.querySelector('.ap-rama');
  const lb = caja.querySelector('.ap-leaderboard');
  const mapas = caja.querySelector('.ap-mapas');
  const vacio = caja.querySelector('.ap-vacio');
  const totalEl = caja.querySelector('.ap-total');
  const cargandoEl = caja.querySelector('.ap-cargando');
  const skel = caja.querySelector('.ap-skeleton');
  const lazy = caja.dataset.aportesLazy === '1';
  const proyecto = caja.dataset.proyecto;
  const ramaWrap = selRama ? selRama.closest('.ms') : null;
  let cargado = false;
  let dias = 182;        // rango de fechas activo
  let ramaDefecto = '';  // la fijada en Editar proyecto → Repos (vacía = la del repo)
  let truncado = false;  // ¿se alcanzó el tope de commits que se leen del repo?
  let diasCargados = 0;  // rango que se pidió al servidor la última vez
  const selRepo = caja.querySelector('.ap-repo');   // filtro de repo (si hay varios)
  let ramasRepo = {}, ramasUnion = [];
  const repoSel = () => (selRepo ? selRepo.value : '');

  const esc = (s) => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  const avatarHtml = (m, sz) => {
    if (!m) return '<span class="ap-av ap-av-x" style="--sz:' + sz + 'px">?</span>';
    if (m.foto) return '<span class="ap-av" style="--sz:' + sz + 'px"><img src="' + esc(m.foto) + '" alt=""></span>';
    return '<span class="ap-av" style="--sz:' + sz + 'px;--c:' + m.c + '">' + esc(m.ini) + '</span>';
  };
  const conTareas = (msg) => esc(msg).replace(/#(\d+)/g, (m, id) =>
    tareas[id] ? '<span class="ap-tarea" title="Tarea: ' + esc(tareas[id]) + '">#' + id + '</span>' : m);

  const dos = (n) => String(n).padStart(2, '0');
  const iso = (d) => d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate());
  const hoy0 = () => { const d = new Date(); d.setHours(12, 0, 0, 0); return d; };
  // Fecha límite del rango (los commits anteriores no cuentan)
  const desde = () => { const d = hoy0(); d.setDate(d.getDate() - dias + 1); return iso(d); };

  // Commits dentro del filtro (persona + rango de fechas + repo)
  const filtrar = () => {
    const pid = parseInt(sel.value, 10) || 0;
    const min = desde();
    const rp = repoSel();
    return commits.filter((c) => (!pid || (c.miembro && c.miembro.id === pid))
      && (c.fecha || '') >= min && (!rp || (c.repo || '') === rp));
  };

  const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  // Ancho por semana segun el rango: con pocas columnas las celdas crecen, y en
  // un año se quedan pequeñas para que el mapa entre sin scroll.
  const pasoRango = () => (dias <= 30 ? 34 : dias <= 90 ? 26 : 20);

  // Heatmap tipo GitHub (verde): una celda por día, una columna por semana, con
  // el mes rotulado arriba para saber qué se está mirando. 'paso' es el ancho
  // máximo por semana: las celdas no pasan de ahí porque un mapa de calor se
  // lee por la mancha, no por el tamaño del cuadro.
  const heatmap = (visibles, paso) => {
    const cont = {};
    visibles.forEach((c) => { if (c.fecha) cont[c.fecha] = (cont[c.fecha] || 0) + 1; });
    const hoy = hoy0();
    const semanas = Math.ceil(dias / 7);
    const ini = new Date(hoy); ini.setDate(ini.getDate() - ((semanas - 1) * 7 + hoy.getDay()));
    let max = 1; Object.values(cont).forEach((v) => { if (v > max) max = v; });
    let celdas = '', meses = '', col = 0, mesPrev = -1, colRotulo = -9;
    for (let d = new Date(ini); d <= hoy; d.setDate(d.getDate() + 1)) {
      if (d.getDay() === 0) {
        col++;
        // Un rótulo al empezar mes, y solo si cabe desde el anterior
        if (d.getMonth() !== mesPrev && col - colRotulo >= 3) {
          meses += '<span style="grid-column:' + col + '">' + MESES[d.getMonth()] + '</span>';
          colRotulo = col;
        }
        mesPrev = d.getMonth();
      }
      const k = iso(d), n = cont[k] || 0;
      const nivel = n === 0 ? 0 : Math.ceil(n / max * 4);
      celdas += '<span class="hm-celda hm-' + nivel + '" title="' + n + ' commit' + (n === 1 ? '' : 's') + ' · ' + k + '"></span>';
    }
    // Ancho exacto (no solo maximo) para que la columna del mapa se ajuste a el
    // y las cifras de al lado se queden con el resto del ancho.
    return '<div class="hm-envoltura" style="width:' + (semanas * (paso || 30)) + 'px">' +
      '<div class="hm-meses" style="grid-template-columns:repeat(' + semanas + ',1fr)">' + meses + '</div>' +
      '<div class="hm-grid hm-verde hm-grande">' + celdas + '</div></div>';
  };

  // Un mes en rejilla de 7 filas queda diminuto, y en tira de cuadros todos los
  // días parecen iguales. Para «30 d» van barras: la altura es el número de
  // commits, que se entiende de un vistazo. Los rangos largos siguen con mapa.
  const tira = (visibles) => {
    const cont = {};
    visibles.forEach((c) => { if (c.fecha) cont[c.fecha] = (cont[c.fecha] || 0) + 1; });
    let max = 1; Object.values(cont).forEach((v) => { if (v > max) max = v; });
    const hoy = hoy0();
    let barras = '', dias30 = '';
    for (let i = dias - 1; i >= 0; i--) {
      const d = new Date(hoy); d.setDate(d.getDate() - i);
      const k = iso(d), n = cont[k] || 0;
      const titulo = n + ' commit' + (n === 1 ? '' : 's') + ' · ' + k;
      barras += '<span class="ap-barra' + (n === 0 ? ' cero' : '') + '"' +
                ' style="--h:' + Math.round(n / max * 100) + '%" title="' + titulo + '">' +
                '<b>' + (n || '') + '</b></span>';
      // Un número cada cinco días para ubicarse, y siempre el de hoy
      dias30 += '<span>' + (i % 5 === 0 ? d.getDate() : '') + '</span>';
    }
    const cols = 'grid-template-columns:repeat(' + dias + ',1fr)';
    return '<div class="ap-tira">' +
      '<div class="ap-tira-eje"><small>' + max + '</small><small>0</small></div>' +
      '<div class="ap-tira-datos">' +
        '<div class="ap-barras" style="' + cols + '">' + barras + '</div>' +
        '<div class="ap-tira-dias" style="' + cols + '">' + dias30 + '</div>' +
      '</div></div>';
  };

  // Con un solo repo sobra ancho al lado del mapa: se llena con las cifras que
  // uno querría saber, en vez de estirar las celdas hasta parecer un tablero.
  const resumen = (cs) => {
    const porDia = {};
    cs.forEach((c) => { if (c.fecha) porDia[c.fecha] = (porDia[c.fecha] || 0) + 1; });
    const d7 = hoy0(); d7.setDate(d7.getDate() - 6);
    const ultimos = cs.filter((c) => (c.fecha || '') >= iso(d7)).length;
    let pico = 0, diaPico = '';
    Object.keys(porDia).forEach((f) => { if (porDia[f] > pico) { pico = porDia[f]; diaPico = f; } });
    const gente = new Set(cs.map((c) => (c.miembro ? c.miembro.n : c.nombre) || '?')).size;
    const dato = (k, v, sub) => '<div class="ap-dato"><small>' + k + '</small><b>' + v + '</b>' +
      (sub ? '<span>' + esc(sub) + '</span>' : '') + '</div>';

    // Solo las cifras: el detalle de quién hizo qué está a un clic en "Ver
    // commits", y meterlo aquí siempre alargaba la tarjeta de más.
    return '<div class="ap-resumen">' +
      dato('Últimos 7 días', ultimos, '') +
      dato('Días con commits', Object.keys(porDia).length, '') +
      dato('Mejor día', pico || '—', diaPico) +
      dato('Personas', gente, '') +
      '</div>';
  };

  const render = () => {
    if (skel) skel.hidden = true;
    const pid = parseInt(sel.value, 10) || 0;

    const vis = filtrar();
    totalEl.textContent = vis.length + ' commits';
    const rp = repoSel();
    // Leaderboard según el rango de fechas y el repo (no según la persona filtrada)
    const enRango = commits.filter((c) => (c.fecha || '') >= desde() && (!rp || (c.repo || '') === rp));
    const cuenta = {};
    enRango.forEach((c) => { if (c.miembro) cuenta[c.miembro.id] = (cuenta[c.miembro.id] || 0) + 1; });
    const rank = miembros.map((m) => ({ m, n: cuenta[m.id] || 0 })).filter((x) => x.n > 0).sort((a, b) => b.n - a.n);
    const maxN = Math.max(1, ...rank.map((x) => x.n));
    const rankHtml = rank.map((x) =>
      '<button type="button" class="ap-lb-fila' + (pid === x.m.id ? ' activo' : '') + '" data-persona="' + x.m.id + '">' +
      avatarHtml(x.m, 30) + '<span class="ap-lb-n" title="' + esc(x.m.n) + '">' + esc(x.m.n) + '</span>' +
      '<span class="ap-lb-barra"><span style="width:' + Math.round(x.n * 100 / maxN) + '%"></span></span>' +
      '<b class="ap-lb-num">' + x.n + '</b></button>').join('');

    // "Sin asignar": commits que no calzan con ninguna ficha, agrupados por su
    // identidad real (correo / usuario) para saber QUÉ registrar. Es la clave
    // para cuadrar a quien no sale: se copia ese correo/usuario a su ficha.
    const grupos = {};
    enRango.forEach((c) => {
      if (c.miembro) return;
      const key = (c.email || c.login || c.nombre || '?').toLowerCase();
      if (!grupos[key]) grupos[key] = { nombre: c.nombre || '', email: c.email || '', login: c.login || '', prov: c.prov || '', n: 0 };
      grupos[key].n++;
    });
    const sinList = Object.values(grupos).sort((a, b) => b.n - a.n);
    const sinTotal = sinList.reduce((s, g) => s + g.n, 0);
    let sinHtml = '';
    if (sinList.length) {
      const filas = sinList.slice(0, 15).map((g) =>
        '<div class="ap-sin-fila">' +
          '<span class="ap-sin-id">' + esc(g.nombre || '(sin nombre)') +
            (g.email ? ' · <b>' + esc(g.email) + '</b>'
                     : (g.login ? ' · <b>@' + esc(g.login) + '</b>' : '')) +
            (g.prov ? ' <span class="ap-sin-prov">' + esc(g.prov) + '</span>' : '') +
          '</span>' +
          '<b class="ap-sin-num">' + g.n + '</b>' +
        '</div>').join('');
      sinHtml = '<details class="ap-sin">' +
        '<summary><i class="fa-solid fa-user-slash"></i> Sin asignar: ' + sinTotal +
        ' commit' + (sinTotal === 1 ? '' : 's') + ' de ' + sinList.length + ' identidad' + (sinList.length === 1 ? '' : 'es') + '</summary>' +
        '<p class="ap-sin-ayuda">No calzan con ninguna ficha. Copia el <b>correo</b> (o el usuario) tal cual sale aquí a "Correos de Git" / "Usuario(s) de Git" de esa persona y volverán a contarse.</p>' +
        '<div class="ap-sin-lista">' + filas + '</div></details>';
    }

    lb.innerHTML = rankHtml + sinHtml;
    // Se esconde solo si no hay nada que mostrar (ni ranking ni sin-asignar).
    lb.hidden = rank.length === 0 && sinList.length === 0;

    // Un mapa por repositorio, con lo filtrado por persona y fechas
    vacio.hidden = vis.length > 0;
    mapas.hidden = vis.length === 0;
    const ramaActiva = (selRama && selRama.value) || ramaDefecto;
    const todosRepos = repos.length ? repos : [...new Set(vis.map((c) => c.repo))];
    const lista = rp ? [rp] : todosRepos;   // con un repo elegido, solo su mapa
    // Con uno o dos repos la rejilla automática los dejaba en una columna
    // estrecha con medio panel vacío: se reparten el ancho completo.
    const uno = lista.length === 1;
    const corto = uno && dias <= 30;      // un mes: tira de días, no rejilla
    mapas.classList.toggle('ap-uno', uno);
    mapas.classList.toggle('ap-corto', corto);
    mapas.classList.toggle('ap-dos', lista.length === 2);
    mapas.innerHTML = lista.map((repo) => {
      const cs = vis.filter((c) => (c.repo || '') === repo);
      return '<div class="ap-repo">' +
        '<div class="ap-repo-cab"><span class="ap-repo-nom"><i class="fa-solid fa-code-branch"></i> ' + esc(repo) +
        (ramaActiva ? '<span class="ap-repo-rama">' + esc(ramaActiva) + '</span>' : '') + '</span>' +
        '<b class="ap-repo-n">' + cs.length + '</b></div>' +
        (corto ? tira(cs) : heatmap(cs, uno ? pasoRango() : 30)) +
        (uno ? resumen(cs) : '') + '</div>';
    }).join('') +
      '<div class="hm-leyenda"><small>Menos</small>' +
      '<span class="hm-celda hm-0 hm-verde"></span><span class="hm-celda hm-1 hm-verde"></span>' +
      '<span class="hm-celda hm-2 hm-verde"></span><span class="hm-celda hm-3 hm-verde"></span>' +
      '<span class="hm-celda hm-4 hm-verde"></span><small>Más</small></div>' +
      // Si se alcanzó el tope de lectura, decirlo: si no, el mapa aparenta que
      // eso es todo lo que hay en el rango.
      (truncado ? '<p class="ap-tope"><i class="fa-solid fa-circle-info"></i> ' +
        'El repositorio tiene más commits de los que se leen de una vez en este rango: se trajeron ' +
        'los más recientes, así que el tramo más antiguo puede quedar corto. Acorta el rango para verlo completo.</p>' : '');
  };

  sel.addEventListener('change', render);
  lb.addEventListener('click', (e) => {
    const f = e.target.closest('.ap-lb-fila'); if (!f) return;
    const pid = f.dataset.persona;
    sel.value = (sel.value === pid) ? '0' : pid;
    sel.dispatchEvent(new Event('ms-sync'));
    render();
  });
  caja.querySelector('.ap-rango').addEventListener('click', (e) => {
    const b = e.target.closest('[data-dias]'); if (!b) return;
    dias = parseInt(b.dataset.dias, 10);
    caja.querySelectorAll('.ap-rango [data-dias]').forEach((x) => x.classList.toggle('active', x === b));
    // Si el rango nuevo cabe en lo ya traído, se filtra en el navegador; solo
    // se vuelve a pedir cuando hace falta más historial del que hay.
    if (dias <= diasCargados) render();
    else cargar(selRama ? selRama.value : '');
  });

  // Rellena el selector de ramas una vez que el proveedor las devuelve (solo si
  // hay >1). La opción vacía es "la del proyecto": si hay una rama fijada en
  // Editar → Repos, se dice cuál, para no dejar dudas de qué se está mirando.
  const actualizarRamas = () => {
    if (!selRama) return;
    const rp = repoSel();
    const lista = rp ? (ramasRepo[rp] || []) : ramasUnion;
    // Deja solo la opción vacía ("por defecto") y re-agrega el resto
    [...selRama.querySelectorAll('option')].forEach((o) => { if (o.value !== '') o.remove(); });
    const op0 = selRama.querySelector('option[value=""]');
    if (op0) op0.textContent = (!rp && ramaDefecto) ? ramaDefecto + ' (del proyecto)' : 'Rama por defecto';
    lista.forEach((rn) => {
      if (!rn || (!rp && rn === ramaDefecto)) return;   // la del proyecto ya es la de arriba
      const o = document.createElement('option');
      o.value = rn; o.textContent = rn;
      selRama.appendChild(o);
    });
    selRama.value = '';
    selRama.dispatchEvent(new Event('ms-sync'));   // MecaSelect relee las opciones
    if (ramaWrap) ramaWrap.hidden = lista.length < 2;   // sin ramas que elegir, se oculta
  };

  // Trae por AJAX los commits (y ramas) del repo/rama, sin recargar la página
  const cargar = (rama) => {
    cargandoEl.hidden = false;
    const pedidos = dias;
    return fetch('aportes.php?id=' + proyecto + '&rama=' + encodeURIComponent(rama || '') + '&dias=' + pedidos)
      .then((r) => r.json())
      .then((j) => {
        if (j) {
          commits = j.commits || [];
          mapear(commits);
          diasCargados = pedidos;
          ramaDefecto = j.rama_defecto || '';
          truncado = !!j.truncado;
          ramasUnion = j.ramas || [];
          ramasRepo = j.ramas_repo || {};
          actualizarRamas();
          cargado = true;
          render();
          if (Array.isArray(j.movidas) && j.movidas.length) aplicarMovidasEnTabla(j.movidas);
        }
      })
      .catch(() => { if (skel) skel.hidden = true; lb.hidden = false; vacio.hidden = false; })
      .finally(() => { cargandoEl.hidden = true; });
  };

  if (selRama) selRama.addEventListener('change', () => cargar(selRama.value));
  // Cambiar de repo: reconstruye las ramas de ese repo y re-filtra (sin recargar)
  if (selRepo) selRepo.addEventListener('change', () => { actualizarRamas(); render(); });

  /* ----- Commits a pantalla completa, paginados de 25 en 25 ----- */
  const dlg = caja.querySelector('.ap-dialogo');
  const apcLista = dlg.querySelector('.apc-lista');
  const apcSub = dlg.querySelector('.apc-sub');
  const apcPag = dlg.querySelector('.apc-pag');
  const POR_PAG = 25;
  let pagina = 0, visCommits = [];

  const pintarPagina = () => {
    const total = visCommits.length;
    const paginas = Math.max(1, Math.ceil(total / POR_PAG));
    pagina = Math.max(0, Math.min(pagina, paginas - 1));
    const trozo = visCommits.slice(pagina * POR_PAG, pagina * POR_PAG + POR_PAG);
    apcLista.innerHTML = trozo.map((c) =>
      '<li class="ap-commit">' + avatarHtml(c.miembro, 28) +
      '<div class="ap-commit-txt"><span class="ap-msg">' + conTareas(c.msg) + '</span>' +
      '<small>' + esc(c.miembro ? c.miembro.n : (c.nombre || '?')) + ' · ' + esc(c.repo || '') + ' · ' + esc(c.fecha || '') +
      ' · <a href="' + esc(c.url) + '" target="_blank" rel="noopener">' + esc(c.sha) + '</a></small></div></li>').join('');
    apcPag.textContent = 'Página ' + (pagina + 1) + ' de ' + paginas + ' · ' + total + ' commits';
    dlg.querySelector('.apc-prev').disabled = pagina === 0;
    dlg.querySelector('.apc-next').disabled = pagina >= paginas - 1;
  };
  caja.querySelector('.ap-ver-commits').addEventListener('click', () => {
    visCommits = filtrar();
    pagina = 0;
    const pid = parseInt(sel.value, 10) || 0;
    const quien = pid ? (miembros.find((m) => m.id === pid) || {}).n : 'Todo el equipo';
    apcSub.textContent = '· ' + quien;
    pintarPagina();
    dlg.showModal();
  });
  dlg.querySelector('.apc-prev').addEventListener('click', () => { pagina--; pintarPagina(); });
  dlg.querySelector('.apc-next').addEventListener('click', () => { pagina++; pintarPagina(); });

  if (ramaWrap) ramaWrap.hidden = true;   // se muestra solo si hay varias ramas
  if (lazy) {
    // Se cargan los commits al abrir el PROYECTO (en segundo plano, tras pintar la
    // página), no solo al ver Métricas: así el flujo del estándar mueve las tareas
    // aunque nadie abra esa pestaña. El fetch usa caché, y el render va a una
    // sección oculta hasta que se elige Métricas. Los movimientos se reflejan en
    // la tabla y el kanban (aplicarMovidasEnTabla).
    const arranque = () => { if (!cargado) cargar(''); };
    if ('requestIdleCallback' in window) requestIdleCallback(arranque, { timeout: 2500 });
    else setTimeout(arranque, 1200);
  } else {
    render();
  }
});

// Rellenar y abrir el modal de edicion de tarea
document.querySelectorAll('[data-editar-tarea]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const t = JSON.parse(btn.dataset.editarTarea);
    const dlg = document.getElementById('dlg-editar-tarea');
    dlg.querySelector('#et-id').value = t.id;
    dlg.querySelector('#et-titulo').value = t.titulo;
    window.MecaRT.set('et-descripcion', t.descripcion || '');
    setFecha(dlg.querySelector('#et-inicio'), t.fecha_inicio);
    setFecha(dlg.querySelector('#et-fecha'), t.fecha_limite);
    dlg.querySelectorAll('[data-atajos-fecha] .chip-atajo').forEach((c) => c.classList.remove('activo'));
    setSelect(dlg.querySelector('.js-et-asignado'), t.asignados || (t.asignado_id ? [t.asignado_id] : []));
    setSelect(dlg.querySelector('.js-et-prioridad'), t.prioridad);
    setSelect(dlg.querySelector('.js-et-estado'), t.estado);
    const picker = dlg.querySelector('[data-dep-picker]');
    if (picker && picker.depAPI) {
      // El selector se rellena vía su API (excluye la propia tarea de la lista).
      picker.depAPI.reset();
      picker.depAPI.excludeSelf(t.id);
      picker.depAPI.setDeps(t.dependencias || (t.depende_de ? [t.depende_de] : []));
    }
    // Documentos que ya tiene: se listan para poder abrirlos o quitarlos
    const campoAdj = dlg.querySelector('[data-adjuntos-tarea]');
    if (campoAdj && campoAdj.pintarAdjuntos) {
      campoAdj.pintarAdjuntos(t.adjuntos || []);
      campoAdj.limpiarNuevos();          // sin arrastrar lo elegido para otra tarea
    }
    actualizarDuracion(dlg.querySelector('form'));
    dlg.showModal();
  });
});

// Calendario: chip "+N" que abre el resto de eventos del día en un desplegable
document.addEventListener('click', (e) => {
  const chip = e.target.closest('[data-cal-mas]');
  // Cierra los abiertos (menos el que corresponde al chip pulsado)
  document.querySelectorAll('.cal-mas-lista:not([hidden])').forEach((l) => {
    if (!chip || l !== chip.nextElementSibling) l.hidden = true;
  });
  if (chip) {
    const lista = chip.nextElementSibling;
    if (lista && lista.classList.contains('cal-mas-lista')) lista.hidden = !lista.hidden;
  }
});

// Toggle de plataforma (Zoom / Meet / enlace propio) en "Nueva reunión"
document.querySelectorAll('.nr-plat').forEach((tg) => {
  const campo = tg.closest('.campo');
  const hidden = campo?.querySelector('input[name="plataforma"]');
  const hint = campo?.querySelector('.nr-meet-hint');
  // El campo del enlace es hermano del .campo de la plataforma, no hijo.
  const enlace = campo?.parentElement?.querySelector('.nr-enlace');
  const urlInp = enlace?.querySelector('input[name="join_url"]');
  tg.addEventListener('click', (e) => {
    const b = e.target.closest('[data-plat]');
    if (!b) return;
    const plat = b.dataset.plat;
    tg.querySelectorAll('[data-plat]').forEach((x) => x.classList.toggle('active', x === b));
    if (hidden) hidden.value = plat;
    if (hint) hint.hidden = plat !== 'meet';
    if (enlace) enlace.hidden = plat !== 'enlace';
    // Obligatorio solo cuando es la opción elegida: si no, el navegador
    // bloquearía el envío de una reunión de Zoom por un campo oculto.
    if (urlInp) urlInp.required = plat === 'enlace';
  });
});

// Rellenar y abrir el modal de edicion de reunion (editar / invitar a mas gente)
document.querySelectorAll('.js-editar-reunion').forEach((btn) => {
  btn.addEventListener('click', () => {
    const r = JSON.parse(btn.dataset.editarReunion);
    const dlg = document.getElementById('dlg-editar-reunion');
    if (!dlg) return;
    dlg.querySelector('#er-id').value = r.id;
    dlg.querySelector('#er-topic').value = r.topic || '';
    // datetime-local exige "YYYY-MM-DDTHH:MM". Aceptamos espacio o T y con o
    // sin segundos, para que la hora guardada de la reunión SIEMPRE se cargue.
    const m = String(r.inicio || '').match(/(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    dlg.querySelector('#er-inicio').value = m ? (m[1] + 'T' + m[2]) : '';
    setSelect(dlg.querySelector('.js-er-duracion'), r.duracion || 60);
    setSelect(dlg.querySelector('.js-er-invitados'), r.invitados || []);

    // Repetición semanal: marcar los días guardados y abrir el bloque si aplica
    const rec = dlg.querySelector('.js-er-recurrente');
    if (rec) {
      const dias = (r.dias || []).map(String);
      dlg.querySelectorAll('.js-er-dias input[type="checkbox"]').forEach((c) => {
        c.checked = dias.includes(c.value);
      });
      const hasta = dlg.querySelector('.js-er-hasta');
      if (hasta) hasta.value = r.hasta || '';
      rec.checked = !!r.recurrente;
      rec.dispatchEvent(new Event('change', { bubbles: true }));
    }
    dlg.showModal();
  });
});

// Bloque "Repetir todas las semanas": muestra u oculta días y fecha final
document.querySelectorAll('.js-repetir').forEach((chk) => {
  const detalle = chk.closest('.repetir-caja')?.querySelector('.repetir-detalle');
  if (!detalle) return;
  const pintar = () => { detalle.hidden = !chk.checked; };
  chk.addEventListener('change', pintar);
  pintar();
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

// Componente único de subida (UI::archivo): drag & drop, estado "con archivo"
// (nombre + peso + quitar) y validación de tipo/tamaño (error). Envuelve un
// <input type="file"> nativo, así el formulario se envía igual que siempre.
(() => {
  const humano = (b) => b < 1024 ? b + ' B'
    : b < 1048576 ? Math.round(b / 1024) + ' KB'
    : (b / 1048576).toFixed(1) + ' MB';
  const iconoDe = (nombre) => {
    const ext = (nombre.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf') return 'fa-file-pdf';
    if (['doc', 'docx'].includes(ext)) return 'fa-file-word';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return 'fa-file-excel';
    if (['ppt', 'pptx'].includes(ext)) return 'fa-file-powerpoint';
    if (['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'].includes(ext)) return 'fa-file-image';
    if (['zip', 'rar', '7z'].includes(ext)) return 'fa-file-zipper';
    return 'fa-file';
  };
  const aceptado = (file, accept) => {
    if (!accept) return true;
    const nom = file.name.toLowerCase(), tipo = (file.type || '').toLowerCase();
    return accept.split(',').map((s) => s.trim().toLowerCase()).some((a) => {
      if (!a) return false;
      if (a.startsWith('.')) return nom.endsWith(a);
      if (a.endsWith('/*')) return tipo.startsWith(a.slice(0, -1));
      return tipo === a;
    });
  };

  document.querySelectorAll('[data-archivo]').forEach((fx) => {
    const input = fx.querySelector('.fx-input');
    if (!input || fx.dataset.fxListo) return;
    fx.dataset.fxListo = '1';
    const disparo = fx.querySelector('.fx-disparo');
    const cont    = fx.querySelector('.fx-files');
    const errEl   = fx.querySelector('.fx-error');
    const maxMB   = parseFloat(fx.dataset.max || '0');
    const multi   = fx.dataset.multi === '1';
    const off     = fx.classList.contains('fx-off') || input.disabled;

    const error = (msg) => { fx.classList.toggle('fx-err', !!msg); errEl.textContent = msg || ''; errEl.hidden = !msg; };
    const rebuild = (files) => { const dt = new DataTransfer(); files.forEach((f) => dt.items.add(f)); input.files = dt.files; };

    const pintar = () => {
      const files = [...input.files];
      cont.innerHTML = '';
      fx.classList.toggle('fx-lleno', files.length > 0);
      files.forEach((f, i) => {
        const chip = document.createElement('div');
        chip.className = 'fx-file';
        chip.innerHTML = '<span class="fx-file-ic"><i class="fa-solid ' + iconoDe(f.name) + '"></i></span>'
          + '<span class="fx-file-info"><b class="truncate">' + f.name.replace(/[<>&]/g, '') + '</b>'
          + '<small>' + humano(f.size) + '</small></span>'
          + '<button type="button" class="fx-file-x" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
        chip.querySelector('.fx-file-x').addEventListener('click', (e) => {
          e.stopPropagation();
          rebuild([...input.files].filter((_, j) => j !== i));
          error(''); pintar();
        });
        cont.appendChild(chip);
      });
    };

    const validar = (files) => {
      for (const f of files) {
        if (!aceptado(f, input.accept)) return 'Ese tipo de archivo no se admite aquí.';
        if (maxMB > 0 && f.size > maxMB * 1048576) return f.name + ' pesa ' + humano(f.size) + ': el máximo es ' + maxMB + ' MB.';
      }
      return '';
    };

    const tomar = (lista) => {
      let files = [...lista];
      if (!multi) files = files.slice(0, 1);
      const msg = validar(files);
      if (msg) { error(msg); rebuild([]); pintar(); return; }
      error(''); rebuild(files); pintar();
    };

    if (!off) {
      disparo.addEventListener('click', () => input.click());
      input.addEventListener('change', () => tomar(input.files));
      ['dragenter', 'dragover'].forEach((ev) => fx.addEventListener(ev, (e) => { e.preventDefault(); fx.classList.add('fx-drag'); }));
      ['dragleave', 'dragend'].forEach((ev) => fx.addEventListener(ev, (e) => {
        if (e.target === fx || !fx.contains(e.relatedTarget)) fx.classList.remove('fx-drag');
      }));
      fx.addEventListener('drop', (e) => {
        e.preventDefault(); fx.classList.remove('fx-drag');
        if (e.dataTransfer && e.dataTransfer.files.length) tomar(e.dataTransfer.files);
      });
    }
  });
})();

// Correos de Git: campo repetible (un input por cuenta) con agregar / quitar
(() => {
  const MAX = 5;
  document.addEventListener('click', (e) => {
    const add = e.target.closest('.git-email-agregar');
    if (add) {
      const cont = add.parentElement.querySelector('[data-git-emails]');
      if (!cont) return;
      if (cont.querySelectorAll('.git-email-fila').length >= MAX) return;
      const f = cont.querySelector('.git-email-fila').cloneNode(true);
      f.querySelector('input').value = '';
      cont.appendChild(f);
      f.querySelector('input').focus();
      cont.dispatchEvent(new Event('input', { bubbles: true }));   // que se note el cambio
      return;
    }
    const quitar = e.target.closest('.git-email-quitar');
    if (quitar) {
      const cont = quitar.closest('[data-git-emails]');
      const filas = cont.querySelectorAll('.git-email-fila');
      if (filas.length > 1) quitar.closest('.git-email-fila').remove();
      else quitar.closest('.git-email-fila').querySelector('input').value = '';   // la última solo se vacía
      cont.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  // Para el modal de editar: rellena las filas con una lista de correos
  window.setGitEmails = (cont, lista) => {
    if (!cont) return;
    const filas = [...cont.querySelectorAll('.git-email-fila')];
    filas.slice(1).forEach((f) => f.remove());
    const arr = (lista || []).filter(Boolean);
    cont.querySelector('.git-email-fila input').value = arr[0] || '';
    arr.slice(1, MAX).forEach((v) => {
      const f = filas[0].cloneNode(true);
      f.querySelector('input').value = v;
      cont.appendChild(f);
    });
  };
})();

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
    if (window.setGitEmails) {
      window.setGitEmails(form.querySelector('[data-git-emails]'),
        (m.git_emails || '').split(',').map((s) => s.trim()).filter(Boolean));
    }
    form.querySelector('[name="email"]').value = m.email || '';
    const selEquipo = form.querySelector('[name="equipo"]');
    if (selEquipo && m.equipo) setSelect(selEquipo, m.equipo);
    const selAcceso = form.querySelector('[name="acceso"]');
    if (selAcceso && m.acceso) setSelect(selAcceso, m.acceso);   // sin esto el admin editado se degradaba a lector
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

// Supervisor: el admin elige qué proyectos ve (rellena el modal con los suyos)
document.querySelectorAll('[data-config-super]').forEach((btn) => {
  btn.addEventListener('click', () => {
    let d;
    try { d = JSON.parse(btn.dataset.configSuper); } catch (_) { return; }
    const dlg = document.getElementById('dlg-config-super');
    if (!dlg) return;
    dlg.querySelector('#cs-id').value = d.id;
    dlg.querySelector('#cs-nombre').textContent = d.nombre || 'supervisor';
    const marcados = new Set((d.proyectos || []).map(Number));
    dlg.querySelectorAll('input[name="proyectos[]"]').forEach((c) => {
      c.checked = marcados.has(Number(c.value));
    });
    dlg.showModal();
  });
});

// Supervisor: alterna entre Kanban y Flujo (sus únicas dos vistas, solo lectura)
document.querySelectorAll('.sup-toggle').forEach((tog) => {
  const paneles = {
    kanban: document.querySelector('[data-vista-panel="kanban"]'),
    flujo:  document.querySelector('[data-vista-panel="flujo"]'),
  };
  tog.querySelectorAll('[data-sup-vista]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const v = btn.dataset.supVista;
      tog.querySelectorAll('[data-sup-vista]').forEach((b) => b.classList.toggle('active', b === btn));
      Object.keys(paneles).forEach((k) => { if (paneles[k]) paneles[k].hidden = k !== v; });
      if (v === 'flujo' && typeof dibujarFlujo === 'function') dibujarFlujo();
    });
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

// "Más íconos": el selector de proyecto enseña primero los de Ajustes y
// despliega el resto del set aquí mismo, sin salir del formulario.
document.querySelectorAll('.icon-mas').forEach((btn) => {
  const picker = btn.previousElementSibling;
  if (!picker || !picker.classList.contains('icon-picker')) return;
  const txt = btn.querySelector('.icon-mas-txt');
  btn.addEventListener('click', () => {
    const abierto = btn.classList.toggle('abierto');
    picker.querySelectorAll('.icono-extra').forEach((l) => { l.hidden = !abierto; });
    if (txt) txt.textContent = abierto ? btn.dataset.menos : btn.dataset.mas;
  });
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
    // closest() desde el <path> del SVG: el clic casi nunca cae en el <button>.
    const btn = e.target.closest('.ig-btn');
    if (!btn) return;
    btn.classList.toggle('sel');
    sincronizar();
  });
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
  // El texto de "sin archivo" lo pone cada pantalla: este control lo usan el
  // respaldo (.json) y la carga del equipo (.xlsx / .csv).
  txt.innerHTML = f
    ? '<i class="fa-solid fa-file-circle-check"></i> ' + f.name
    : '<i class="fa-solid fa-file-arrow-up"></i> ' + (label.dataset.vacio || 'Elegir archivo .json');
});

/* =========================================================
   MecaTip — tooltip estilo Apple SOLO para botones de ícono
   (controles sin texto visible). Convierte su title en un
   tooltip propio y quita el nativo para que no salga doble.
   Funciona por delegación: cubre lo que se crea después.
   ========================================================= */
(() => {
  let tip = null, actual = null, timer = 0;
  const SEL = 'button, a, label, [role="button"], .accion-btn';

  const iconoSolo = (el) => el.textContent.replace(/\s+/g, '') === '';
  const textoDe = (el) => el.getAttribute('data-tip') || el.getAttribute('title') || '';

  const crear = () => {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.className = 'meca-tip';
    document.body.appendChild(tip);
    return tip;
  };

  const colocar = (el) => {
    const r = el.getBoundingClientRect();
    const t = tip.getBoundingClientRect();
    const sep = 9;
    let arriba = true;
    let top = r.top - t.height - sep;
    if (top < 6) { top = r.bottom + sep; arriba = false; }   // no cabe arriba → abajo
    let left = r.left + r.width / 2 - t.width / 2;
    left = Math.max(6, Math.min(left, innerWidth - t.width - 6));
    tip.style.left = Math.round(left) + 'px';
    tip.style.top = Math.round(top) + 'px';
    tip.classList.toggle('abajo', !arriba);
    // posición de la flechita respecto al centro del botón
    const cx = r.left + r.width / 2 - left;
    tip.style.setProperty('--tip-x', Math.max(12, Math.min(cx, t.width - 12)) + 'px');
  };

  const mostrar = (el) => {
    const texto = textoDe(el);
    if (!texto) return;
    // pasa el title nativo a data-tip para que no aparezca el tooltip del navegador
    if (el.hasAttribute('title')) { el.setAttribute('data-tip', el.getAttribute('title')); el.removeAttribute('title'); }
    crear().textContent = texto;
    tip.classList.remove('visible');
    colocar(el);
    requestAnimationFrame(() => { colocar(el); tip.classList.add('visible'); });
  };

  const ocultar = () => { if (tip) tip.classList.remove('visible'); actual = null; clearTimeout(timer); };

  const candidato = (target) => {
    const el = target.closest?.(SEL);
    if (!el) return null;
    // Un data-tip explícito muestra el tooltip. Pero si el botón lleva su
    // etiqueta (.tab-txt) VISIBLE, no hace falta: el tooltip solo sale cuando el
    // texto está oculto (p. ej. pestañas en pantallas pequeñas).
    if (el.hasAttribute('data-tip')) {
      const etq = el.querySelector('.tab-txt');
      if (etq && etq.offsetParent !== null) return null;
      return el;
    }
    if (el.hasAttribute('title') && iconoSolo(el)) return el;
    return null;
  };

  document.addEventListener('pointerover', (e) => {
    const el = candidato(e.target);
    if (!el || el === actual) return;
    // quita el title de una vez para matar el tooltip nativo aunque no se muestre el nuestro
    if (el.hasAttribute('title')) { el.setAttribute('data-tip', el.getAttribute('title')); el.removeAttribute('title'); }
    actual = el;
    clearTimeout(timer);
    timer = setTimeout(() => mostrar(el), 340);
  });
  document.addEventListener('pointerout', (e) => {
    if (actual && (e.target.closest?.(SEL) === actual)) ocultar();
  });
  document.addEventListener('focusin', (e) => {
    const el = candidato(e.target);
    if (el) { actual = el; mostrar(el); }
  });
  document.addEventListener('focusout', ocultar);
  document.addEventListener('click', ocultar);      // al accionar, se cierra
  addEventListener('scroll', ocultar, true);
})();

/* Menú flotante en móvil: las tres rayitas abren/cierran el menú */
(() => {
  const burger = document.getElementById('sidebar-burger');
  const sidebar = document.querySelector('.sidebar');
  const menu = document.getElementById('sidebar-menu');
  if (!burger || !sidebar || !menu) return;

  const abrir = () => { sidebar.classList.add('menu-abierto'); burger.setAttribute('aria-expanded', 'true'); };
  const cerrar = () => { sidebar.classList.remove('menu-abierto'); burger.setAttribute('aria-expanded', 'false'); };

  burger.addEventListener('click', (e) => {
    e.stopPropagation();
    sidebar.classList.contains('menu-abierto') ? cerrar() : abrir();
  });
  // Cerrar al tocar fuera, al navegar o con Escape
  document.addEventListener('click', (e) => {
    if (!sidebar.classList.contains('menu-abierto')) return;
    if (!menu.contains(e.target) && e.target !== burger) cerrar();
  });
  menu.addEventListener('click', (e) => { if (e.target.closest('a')) cerrar(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
  addEventListener('resize', () => { if (innerWidth > 900) cerrar(); });
})();

/* Equipo: rechazar una solicitud de acceso (rellena el modal con su nombre) */
(() => {
  const dlg = document.getElementById('dlg-rechazar');
  if (!dlg) return;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-rechazar]');
    if (!btn) return;
    dlg.querySelector('#rc-id').value = btn.dataset.rechazar;
    dlg.querySelector('#rc-nombre').textContent = btn.dataset.nombre || '';
    dlg.showModal();
  });
})();

/* Requerimientos: el selector de personas de los dos asistentes (el de alta,
   'nr', y el de asignar, 'dv'). Cada uno filtra SOLO su propia lista, lleva su
   contador y mantiene un espejo con los nombres marcados para que el paso de
   Revisión los liste: el asistente resume campos, y un montón de casillas no
   lo es. */
(() => {
  const sincronizar = (picker) => {
    const lista = document.querySelector(`[data-picker="${picker}"]`);
    if (!lista) return;
    const marcados = [...lista.querySelectorAll('input:checked')]
      .map(c => c.closest('.dv-persona')?.dataset.nombre || '');
    const n = document.querySelector(`[data-picker-n="${picker}"]`);
    if (n) n.textContent = marcados.length;
    const espejo = document.querySelector(`[data-picker-resumen="${picker}"]`);
    if (espejo) espejo.value = marcados.join(', ');
  };

  ['nr', 'dv', 're'].forEach(picker => {
    const lista = document.querySelector(`[data-picker="${picker}"]`);
    if (!lista) return;
    lista.addEventListener('change', () => sincronizar(picker));

    const rolSel = document.querySelector(`.js-${picker}-rol`);
    const buscar = document.querySelector(`.js-${picker}-buscar`);
    const vacio  = document.querySelector(`[data-picker-vacio="${picker}"]`);
    // Filtro combinado: por rol Y por nombre. Una fila se oculta (.filtrado) si
    // no cumple ambos.
    const aplicar = () => {
      const rol = rolSel ? rolSel.value : '';
      const q = (buscar ? buscar.value : '').trim().toLowerCase();
      let visibles = 0;
      lista.querySelectorAll('.dv-persona').forEach(fila => {
        const okRol = !rol || fila.dataset.rol === rol;
        const okQ = !q || (fila.dataset.nombre || '').toLowerCase().includes(q);
        const oculto = !(okRol && okQ);
        fila.classList.toggle('filtrado', oculto);
        if (!oculto) visibles++;
      });
      if (vacio) vacio.hidden = visibles > 0;
    };
    rolSel?.addEventListener('change', aplicar);
    buscar?.addEventListener('input', aplicar);
    sincronizar(picker);
  });

  /* El asistente de asignar se rellena con el requerimiento pulsado: id,
     título, quién lo tiene ahora y su plazo actual. Las fechas van rellenas
     porque asignar y mover el plazo son la misma operación: si salieran
     vacías, guardar borraría el plazo que ya había. */
  const dlg = document.getElementById('dlg-req-derivar');
  if (!dlg) return;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-derivar]');
    if (!btn) return;
    dlg.querySelector('#dv-id').value = btn.dataset.derivar;
    dlg.querySelector('#dv-titulo').textContent = btn.dataset.titulo || '';
    // setFecha (y no .value) porque MecaDate sustituye el input por su propio
    // selector: sin avisarle, la fecha se guardaría pero no se vería.
    setFecha(dlg.querySelector('[data-req-fecha="dv-inicio"]'), btn.dataset.inicio);
    setFecha(dlg.querySelector('[data-req-fecha="dv-fin"]'), btn.dataset.fin);
    // Deja marcados a los que ya lo tienen (data-actual = "3,7")
    const actuales = (btn.dataset.actual || '').split(',').filter(Boolean);
    dlg.querySelectorAll('input[name="asignados[]"]').forEach(c => {
      c.checked = actuales.includes(c.value);
    });
    sincronizar('dv');
    actualizarDuracion(dlg.querySelector('form'));
    dlg.showModal();
  });
})();

/* Requerimientos: la ficha completa se abre al pulsar una fila. La lista solo
   muestra lo justo; aquí se ve todo y están las acciones. */
(() => {
  const dlg = document.getElementById('dlg-req-ficha');
  if (!dlg) return;
  const $ = (id) => dlg.querySelector('#' + id);

  document.addEventListener('click', (e) => {
    const fila = e.target.closest('.req-fila');
    if (!fila) return;
    const r = JSON.parse(fila.dataset.req);

    $('fq-titulo').textContent = r.titulo;
    // El detalle es HTML ya saneado en el servidor: se inyecta tal cual para
    // que se vean las tablas y el formato. Vacío → nota en gris.
    const detEl = $('fq-detalle');
    if (r.detalle) { detEl.innerHTML = r.detalle; detEl.classList.remove('vacio'); }
    else { detEl.textContent = 'Sin detalle.'; detEl.classList.add('vacio'); }
    // Documentos: los mismos chips que en el detalle de la tarea, con el ojo
    // para lo que el navegador sabe pintar y la flecha para lo que hay que
    // bajarse. El bloque se esconde si no hay ninguno.
    const docs = Array.isArray(r.adjuntos) ? r.adjuntos : [];
    const bloqueDocs = $('fq-docs-bloque');
    if (bloqueDocs) {
      bloqueDocs.hidden = docs.length === 0;
      $('fq-docs').innerHTML = docs.map((a) => {
        const ver = previsualizable(a.ext);
        return '<a class="adj-chip' + (ver ? '' : ' adj-bajar') + '" href="' + esc(a.ruta) + '"' +
          (ver ? '' : ' download') + ' target="_blank" rel="noopener"' +
          ' title="' + (ver ? 'Ver ' : 'Descargar ') + esc(a.nombre) + '">' +
          '<i class="fa-solid ' + iconoAdjunto(a.ext) + '"></i><span>' + esc(a.nombre) + '</span>' +
          '<i class="fa-solid ' + (ver ? 'fa-eye' : 'fa-download') + ' adj-chip-acc"></i></a>';
      }).join('');
    }
    $('fq-solicitante').textContent = r.solicitante || '—';
    $('fq-inicio').textContent = r.inicio || '—';
    $('fq-fin').textContent = r.fin || '—';
    $('fq-creado').textContent = r.creado || '—';

    $('fq-chips').innerHTML =
      `<span class="fq-chip fq-${r.estado}">${r.estadoTxt}</span>` +
      (r.prioridad ? `<span class="fq-chip">Prioridad ${r.prioridad}</span>` : '') +
      (r.vencido ? '<span class="fq-chip fq-vencido">Pasado de fecha</span>' : '');

    // Los nombres se pintan con textContent: vienen del equipo, pero un
    // nombre con "<" no tiene por qué romper la ficha.
    const personas = $('fq-personas');
    personas.textContent = '';
    if (r.personas.length) {
      r.personas.forEach(p => {
        const li = document.createElement('li');
        const b = document.createElement('b');
        b.textContent = p.nombre;
        const s = document.createElement('small');
        s.textContent = p.rol || '';
        li.append(b, s);
        personas.append(li);
      });
    } else {
      const li = document.createElement('li');
      li.className = 'vacio';
      li.textContent = 'Todavía no está asignado a nadie.';
      personas.append(li);
    }

    ['fq-id-borrar', 'fq-id-estado', 'fq-id-terminar'].forEach(id => { const el = $(id); if (el) el.value = r.id; });
    // Cerrarlo solo tiene sentido mientras siga abierto
    const resolver = $('fq-resolver');
    if (resolver) resolver.hidden = r.cerrado;

    // Observación de cierre (cómo se entregó): se muestra si existe
    const cierre = $('fq-cierre');
    if (cierre) {
      if (r.notaCierre) {
        $('fq-nota-cierre').textContent = r.notaCierre;
        const meta = [r.cerradoPor && ('por ' + r.cerradoPor), r.cerradoEn].filter(Boolean).join(' · ');
        $('fq-cierre-meta').textContent = meta;
        cierre.hidden = false;
      } else {
        cierre.hidden = true;
      }
    }
    // Formulario de "marcar terminado" (bandeja del responsable): solo mientras
    // siga abierto; si ya está cerrado, se muestra el aviso en su lugar.
    const fTerm = $('fq-terminar');
    if (fTerm) fTerm.hidden = r.cerrado;
    const yaCerr = $('fq-ya-cerrado');
    if (yaCerr) yaCerr.hidden = !r.cerrado;

    // "Asignar" reutiliza el modal de siempre, con lo que ya tiene marcado
    // y con su plazo actual en las fechas
    const derivar = $('fq-derivar');
    if (derivar) {
      derivar.dataset.derivar = r.id;
      derivar.dataset.titulo = r.titulo;
      derivar.dataset.actual = r.asignados;
      derivar.dataset.inicio = r.inicio || '';
      derivar.dataset.fin = r.fin || '';
      derivar.onclick = () => { dlg.close(); };
    }

    // "Editar": abre EL MISMO asistente que "Nuevo requerimiento", relleno con
    // este requerimiento (contenido + responsables + plazo actuales).
    const editar = $('fq-editar');
    if (editar) {
      editar.onclick = () => {
        const em = document.getElementById('dlg-req-editar');
        if (!em) return;
        em.querySelector('#re-id').value = r.id;
        em.querySelector('#re-titulo').value = r.titulo || '';
        em.querySelector('#re-solicitante').value = r.solicitante || '';
        setSelect(em.querySelector('.js-re-prioridad'), r.prioridadKey || 'media');
        setSelect(em.querySelector('.js-re-inst'), r.instituciones || []);
        window.MecaRT.set('re-detalle', r.detalle || '');
        // Plazo actual (setFecha, no .value: MecaDate sustituye el input)
        setFecha(em.querySelector('[data-req-fecha="re-inicio"]'), r.inicio || '');
        setFecha(em.querySelector('[data-req-fecha="re-fin"]'), r.fin || '');
        // Deja marcados a los responsables actuales (r.asignados = "3,7")
        const actuales = (r.asignados || '').split(',').filter(Boolean);
        em.querySelectorAll('input[name="asignados[]"]').forEach(c => { c.checked = actuales.includes(c.value); });
        const lista = em.querySelector('[data-picker="re"]');
        if (lista) lista.dispatchEvent(new Event('change'));
        const fEm = em.querySelector('form');
        if (typeof actualizarDuracion === 'function') actualizarDuracion(fEm);
        dlg.close();
        em.showModal();
      };
    }
    dlg.showModal();
  });
})();

/* Horario de reuniones fijas: el mismo formulario sirve para añadir y para
   editar. Al pulsar el lápiz de una fila se rellena con sus datos y cambia la
   acción; "Cancelar" lo devuelve a modo alta. Tener dos formularios para lo
   mismo solo daría dos sitios donde arreglar cada cosa. */
(() => {
  const form = document.getElementById('form-rfija');
  if (!form) return;
  const $ = (id) => document.getElementById(id);

  const modoAlta = () => {
    form.classList.remove('editando');
    $('rf-accion').value = 'rfija_crear';
    $('rf-id').value = '';
    $('rf-titulo-form').textContent = 'Añadir al horario';
    $('rf-guardar').textContent = 'Añadir';
    $('rf-cancelar').hidden = true;
    form.reset();
  };

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-rfija-editar]');
    if (!btn) return;
    const r = JSON.parse(btn.dataset.rfijaEditar);
    form.classList.add('editando');
    $('rf-accion').value = 'rfija_editar';
    $('rf-id').value = r.id;
    $('rf-titulo').value = r.titulo;
    $('rf-hora').value = r.hora;
    $('rf-titulo-form').textContent = 'Cambiando «' + r.titulo + '»';
    $('rf-guardar').textContent = 'Guardar';
    $('rf-cancelar').hidden = false;
    // El select de proyecto es el personalizado del panel: se le avisa
    const sel = form.querySelector('select[name="proyecto_id"]');
    if (sel) {
      sel.value = String(r.pid);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    form.querySelectorAll('#rf-dias input').forEach((c) => {
      c.checked = r.dias.includes(parseInt(c.value, 10));
    });
    // Desplegar el formulario, que arranca cerrado
    document.getElementById('det-rfija')?.setAttribute('open', '');
    form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });

  $('rf-cancelar')?.addEventListener('click', modoAlta);
})();

/* =========================================================
   Editor de texto enriquecido (data-editor-rico). Un contenteditable con
   barra mínima y un <textarea> oculto que lleva el HTML en el formulario.
   Sirve para PEGAR contenido con formato y TABLAS (p. ej. un correo). Al
   pegar se limpia a una lista blanca para que la edición se vea limpia; el
   servidor vuelve a sanear siempre antes de guardar.
   ========================================================= */
(() => {
  const OK = new Set(['P', 'BR', 'HR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'STRIKE',
    'SUB', 'SUP', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'LI', 'BLOCKQUOTE',
    'A', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH', 'COL', 'COLGROUP',
    'CODE', 'PRE', 'SPAN', 'DIV']);
  const ATTRS = { A: ['href', 'title'], TD: ['colspan', 'rowspan'], TH: ['colspan', 'rowspan'], COL: ['span'], COLGROUP: ['span'] };
  const FUERA = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT',
    'TEXTAREA', 'BUTTON', 'SELECT', 'META', 'LINK', 'BASE', 'NOSCRIPT', 'SVG', 'MATH', 'TITLE', 'O:P']);

  // Recorta un HTML pegado a la lista blanca (mismo criterio que el servidor).
  function limpiarPegado(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    const paso = (nodo) => {
      [...nodo.childNodes].forEach((n) => {
        if (n.nodeType === 8) { n.remove(); return; }          // comentario
        if (n.nodeType !== 1) return;                          // texto: se queda
        const tag = n.nodeName.toUpperCase();
        if (FUERA.has(tag)) { n.remove(); return; }
        paso(n);
        if (!OK.has(tag)) {                                    // desconocida: desenvolver
          const p = n.parentNode;
          while (n.firstChild) p.insertBefore(n.firstChild, n);
          p.removeChild(n);
          return;
        }
        const keep = ATTRS[tag] || [];
        [...n.attributes].forEach((a) => {
          if (!keep.includes(a.name.toLowerCase())) n.removeAttribute(a.name);
        });
      });
    };
    paso(tpl.content);
    return tpl.innerHTML;
  }

  const vacio = (area) => area.textContent.trim() === '' && !area.querySelector('table, img, hr');

  function init(rt) {
    const area = rt.querySelector('.rt-area');
    const fuente = rt.querySelector('.rt-fuente');
    if (!area || !fuente) return;

    const sync = () => {
      const vac = vacio(area);
      fuente.value = vac ? '' : area.innerHTML;
      rt.classList.toggle('rt-vacio', vac);
    };

    area.addEventListener('input', sync);
    area.addEventListener('blur', sync);

    // Pegado: se inserta el HTML ya limpio (conserva tablas y formato básico).
    area.addEventListener('paste', (e) => {
      const cb = e.clipboardData;
      if (!cb) return;
      const html = cb.getData('text/html');
      if (html) {
        e.preventDefault();
        document.execCommand('insertHTML', false, limpiarPegado(html));
        sync();
      }
      // Sin HTML en el portapapeles: se deja el pegado de texto normal.
    });

    // Barra de formato. mousedown + preventDefault para no perder la selección.
    rt.querySelectorAll('.rt-b[data-cmd]').forEach((b) => {
      b.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const cmd = b.dataset.cmd;
        area.focus();
        if (cmd === 'createLink') {
          const url = prompt('URL del enlace (https://…):');
          if (url) document.execCommand('createLink', false, url);
        } else if (cmd === 'formatBlock') {
          // Alternar: si ya es ese bloque, se vuelve a párrafo.
          const val = (b.dataset.val || 'p').toUpperCase();
          const actual = (document.queryCommandValue('formatBlock') || '').toUpperCase();
          document.execCommand('formatBlock', false, actual === val ? 'P' : val);
        } else {
          document.execCommand(cmd, false, null);
        }
        sync();
      });
    });

    const form = rt.closest('form');
    if (form) form.addEventListener('submit', sync);
    sync();
  }

  document.querySelectorAll('[data-editor-rico]').forEach(init);

  // API para rellenar un editor por JS (formularios de edición que se llenan
  // en el navegador): MecaRT.set('id-del-contenedor', htmlSaneado).
  window.MecaRT = {
    set(id, html) {
      const rt = document.getElementById(id);
      if (!rt) return;
      const area = rt.querySelector('.rt-area');
      const fuente = rt.querySelector('.rt-fuente');
      if (!area || !fuente) return;
      area.innerHTML = html || '';
      fuente.value = html || '';
      rt.classList.toggle('rt-vacio', vacio(area));
    },
  };
})();

/* =========================================================
   Panel de requerimientos (ApexCharts 6). Los datos llegan en window.REQ_DASH
   desde req_dashboard.php; la librería se carga antes que este archivo.

   Criterios que se aplican igual en todos los gráficos:
   - Colores propios de datos, no los tokens de interfaz. Los del CSS sirven
     para texto y bordes, pero puestos uno al lado del otro dentro de un
     gráfico no se distinguen. Los de aquí están medidos contra la superficie
     real de cada tema (#e5eaf3 claro, #262c3a oscuro) y siguen separándose
     con daltonismo.
   - El color rojo/naranja de una institución identifica a la institución, no
     dice "va mal": por eso solo se usa donde la institución ES el dato (el
     reparto). Cumplido/pendiente va en verde contra gris.
   - Ejes de enteros calculados desde el máximo. Con 3 requerimientos, Apex
     repartía 6 marcas y al redondear salían etiquetas repetidas (0,0,1,1).
   - Marcas finas, punta redondeada, rejilla continua de 1px y 2px de hueco
     del color del fondo entre porciones (hueco, nunca un borde dibujado).
   - Se redibujan al cambiar de tema (evento meca:tema).
   ========================================================= */
(() => {
  const D = window.REQ_DASH;
  if (!D || typeof ApexCharts === 'undefined' || !document.getElementById('rd-inst')) return;

  const PALETAS = {
    claro: {
      sup: '#e5eaf3',
      hecho: '#129251',
      pendiente: '#8B9CB8',
      tinta: ['#ffffff', '#16233a'],          // texto sobre hecho / sobre pendiente
      serie: '#129251',
      situacion: ['#3DB878', '#129251', '#0B6234'],   // sin asignar → en curso → cerrados
      prioridad: { Alta: '#8C4711', Media: '#BE6A18', Baja: '#DD9436' },
      tintaPrio: ['#ffffff', '#ffffff', '#3a2408'],   // Alta / Media / Baja
      estado: { bien: '#0E8E4E', ojo: '#BE6A18', mal: '#B23A2E' },
      pista: '#cfd8e6',                       // canal vacío del medidor
    },
    oscuro: {
      sup: '#2E3A57',
      hecho: '#28AC69',
      pendiente: '#7E8FAC',
      tinta: ['#08301c', '#101827'],
      serie: '#28AC69',
      situacion: ['#7FE3AE', '#35C078', '#178A4E'],
      prioridad: { Alta: '#B0641A', Media: '#DE8A2E', Baja: '#F5B860' },
      tintaPrio: ['#ffffff', '#2a1a06', '#2a1a06'],
      estado: { bien: '#28AC69', ojo: '#DE8A2E', mal: '#E06A5C' },
      pista: '#333c4e',
    },
  };

  const vivos = [];

  const dibujar = () => {
    // Al repintar por cambio de tema hay que soltar los anteriores: si no,
    // Apex deja el SVG viejo debajo del nuevo.
    while (vivos.length) { try { vivos.pop().destroy(); } catch (e) { /* ya no estaba */ } }

    const oscuro = document.documentElement.classList.contains('dark');
    const P = oscuro ? PALETAS.oscuro : PALETAS.claro;
    const css = getComputedStyle(document.documentElement);
    const v = (n, d) => (css.getPropertyValue(n).trim() || d);
    const cText  = v('--c-text', oscuro ? '#e9edf6' : '#1e2430');
    const cMuted = v('--c-text-muted', oscuro ? '#b6c0d2' : '#6b7688');
    const cGrid  = oscuro ? 'rgba(255,255,255,.08)' : 'rgba(30,55,100,.10)';

    const base = {
      chart: {
        fontFamily: 'inherit', foreColor: cMuted, background: 'transparent',
        toolbar: { show: false },
        // Sin "animateGradually": si no, las barras crecen en fila india y
        // durante medio segundo unas miden menos de lo que valen.
        animations: { enabled: true, speed: 420, animateGradually: { enabled: false } },
        parentHeightOffset: 0,
      },
      grid: { borderColor: cGrid, strokeDashArray: 0, padding: { top: 0, right: 14, bottom: 0, left: 6 } },
      dataLabels: { enabled: false },
      tooltip: { theme: oscuro ? 'dark' : 'light' },
      legend: {
        position: 'bottom', horizontalAlign: 'center', fontSize: '12.5px', fontWeight: 600,
        labels: { colors: cText }, markers: { width: 9, height: 9, radius: 9 },
        itemMargin: { horizontal: 9, vertical: 3 },
      },
      noData: { text: 'Sin datos todavía', style: { color: cMuted, fontSize: '13px' } },
      states: { hover: { filter: { type: 'lighten', value: 0.08 } } },
    };

    // Eje de enteros: fija el tope y el número de marcas para que cada una
    // caiga justo en un entero y no se repitan las etiquetas.
    const ejeEntero = (max) => {
      const alto = Math.max(1, Math.ceil(max));
      const marcas = alto <= 5 ? alto : 5;
      return {
        min: 0, max: Math.ceil(alto / marcas) * marcas, tickAmount: marcas,
        forceNiceScale: false,
        labels: { formatter: (n) => String(Math.round(n)) },
      };
    };

    const req = (n) => n + (n === 1 ? ' requerimiento' : ' requerimientos');
    // Altura a partir del número de barras, para que cada una salga de ~22px
    // en vez de estirarse hasta llenar la tarjeta cuando hay dos o tres.
    const altoBarras = (n, extra) => Math.max(170, n * 48 + (extra || 70));
    const vacio = (id, msg) => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<p class="rd-vacio">' + msg + '</p>';
    };

    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    /* Cuando solo hay UNA categoría no hay nada que comparar: una dona de una
       porción es un aro y una barra de una categoría es una raya de lado a
       lado. En ese caso el número es el gráfico, así que se escribe y ya.
       Cuando entren más datos, cada uno vuelve solo a su forma. */
    const cifra = (id, valor, etiqueta, color) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.innerHTML =
        '<div class="rd-cifra">' +
          '<b>' + esc(valor) + '</b>' +
          '<span>' +
            (color ? '<i class="rd-punto" style="background:' + esc(color) + '"></i>' : '') +
            esc(etiqueta) +
          '</span>' +
        '</div>';
    };
    const pintar = (id, opts) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.innerHTML = '';
      try {
        const c = new ApexCharts(el, opts);
        c.render();
        vivos.push(c);
      } catch (e) {
        el.innerHTML = '<p class="rd-vacio">No se pudo dibujar el gráfico.</p>';
      }
    };

    /* 1) Cumplidos por institución. Barras apiladas: lo cumplido y lo que
       falta, en horizontal porque los nombres de institución son largos. La
       barra entera mide el total, así que ya no hace falta la etiqueta "1/3"
       encima de una barra que medía otra cosa. */
    const inst = D.inst || [];
    if (inst.length === 1) {
      cifra('rd-inst', inst[0].cumplidos + '/' + inst[0].total, 'cumplidos en ' + inst[0].nombre, inst[0].color);
    } else if (inst.length) {
      pintar('rd-inst', {
        ...base,
        chart: { ...base.chart, type: 'bar', stacked: true, height: altoBarras(inst.length, 104) },
        series: [
          { name: 'Cumplidos',  data: inst.map((i) => i.cumplidos) },
          { name: 'Pendientes', data: inst.map((i) => Math.max(0, i.total - i.cumplidos)) },
        ],
        colors: [P.hecho, P.pendiente],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '42%' } },
        stroke: { show: true, width: 2, colors: [P.sup] },
        xaxis: { categories: inst.map((i) => i.nombre), ...ejeEntero(Math.max(...inst.map((i) => i.total))) },
        yaxis: { labels: { style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        dataLabels: {
          enabled: true,
          // Solo dentro del trozo que tenga sitio; si no, lo cuenta el eje.
          formatter: (val) => (val >= 1 ? val : ''),
          style: { fontSize: '12px', fontWeight: 700, colors: P.tinta },
          dropShadow: { enabled: false },
        },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-inst', 'Sin instituciones asignadas todavía.');
    }

    /* 2) Reparto por institución. Único gráfico donde manda el color propio
       de cada institución, porque aquí el dato ES la institución. */
    if (inst.length === 1) {
      cifra('rd-inst-dona', inst[0].total, 'todos de ' + inst[0].nombre, inst[0].color);
    } else if (inst.length) {
      pintar('rd-inst-dona', {
        ...base,
        chart: { ...base.chart, type: 'donut', height: 260 },
        series: inst.map((i) => i.total),
        labels: inst.map((i) => i.nombre),
        colors: inst.map((i) => i.color || P.serie),
        stroke: { width: 2, colors: [P.sup] },
        plotOptions: { pie: { donut: { size: '68%', labels: {
          show: true, name: { fontSize: '13px', color: cMuted },
          value: { fontSize: '24px', fontWeight: 700, color: cText },
          total: { show: true, label: 'Total', color: cMuted, formatter: () => inst.reduce((a, i) => a + i.total, 0) },
        } } } },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-inst-dona', 'Sin instituciones asignadas.');
    }

    /* 3) Situación de la carga. Las tres situaciones están ordenadas (sin
       asignar → en curso → cerrado), así que van en una rampa de un solo
       tono: el orden se lee por lo oscuro, no por el tono. */
    const sit = (D.sit || []).filter((s) => s[1] > 0);
    const ordenSit = ['Sin asignar', 'En curso', 'Cerrados'];
    if (sit.length === 1) {
      cifra('rd-sit', sit[0][1], 'todos en "' + sit[0][0] + '"',
            P.situacion[Math.max(0, ordenSit.indexOf(sit[0][0]))]);
    } else if (sit.length) {
      const orden = ordenSit;
      pintar('rd-sit', {
        ...base,
        chart: { ...base.chart, type: 'donut', height: 260 },
        series: sit.map((s) => s[1]),
        labels: sit.map((s) => s[0]),
        colors: sit.map((s) => P.situacion[Math.max(0, orden.indexOf(s[0]))]),
        stroke: { width: 2, colors: [P.sup] },
        plotOptions: { pie: { donut: { size: '68%', labels: {
          show: true, name: { fontSize: '13px', color: cMuted },
          value: { fontSize: '24px', fontWeight: 700, color: cText },
          total: { show: true, label: 'Total', color: cMuted, formatter: () => sit.reduce((a, s) => a + s[1], 0) },
        } } } },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-sit', 'Sin requerimientos.');
    }

    /* 4) Por prioridad. Era un radial de tres anillos que no se podía
       comparar: tres barras sobre la misma línea se leen de un vistazo. */
    const prio = (D.prio || []).filter((p) => p[1] > 0);
    if (prio.length === 1) {
      cifra('rd-prio', prio[0][1], 'todos de prioridad ' + prio[0][0].toLowerCase(),
            P.prioridad[prio[0][0]] || P.serie);
    } else if (prio.length) {
      pintar('rd-prio', {
        ...base,
        chart: { ...base.chart, type: 'bar', height: altoBarras(prio.length) },
        series: [{ name: 'Requerimientos', data: prio.map((p) => p[1]) }],
        colors: prio.map((p) => P.prioridad[p[0]] || P.serie),
        plotOptions: { bar: { horizontal: true, distributed: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '46%' } },
        xaxis: { categories: prio.map((p) => p[0]), ...ejeEntero(Math.max(...prio.map((p) => p[1]))) },
        yaxis: { labels: { style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-prio', 'Sin prioridades registradas.');
    }

    /* 5) Recibidos por mes. Un área necesita recorrido: con uno o dos meses
       no hay tendencia que dibujar y quedaba un punto suelto en medio de una
       tarjeta vacía, así que hasta el tercer mes se cuentan en columnas. */
    const meses = D.meses || [];
    const hayTendencia = meses.length >= 3;
    if (meses.length === 1) {
      cifra('rd-meses', meses[0][1], 'recibidos en ' + meses[0][0], P.serie);
    } else if (meses.length) {
      pintar('rd-meses', {
        ...base,
        chart: { ...base.chart, type: hayTendencia ? 'area' : 'bar', height: 280, zoom: { enabled: false } },
        series: [{ name: 'Recibidos', data: meses.map((m) => m[1]) }],
        colors: [P.serie],
        plotOptions: { bar: {
          columnWidth: meses.length === 1 ? '56px' : '38%',
          borderRadius: 4, borderRadiusApplication: 'end',
          dataLabels: { position: 'top' },     // el número va sobre la columna
        } },
        dataLabels: hayTendencia ? { enabled: false } : {
          enabled: true, offsetY: -20,
          style: { fontSize: '12.5px', fontWeight: 700, colors: [cText] },
          background: { enabled: false }, dropShadow: { enabled: false },
        },
        stroke: hayTendencia ? { curve: 'smooth', width: 2, lineCap: 'round' } : { width: 0 },
        fill: { type: 'solid', opacity: hayTendencia ? 0.1 : 1 },
        markers: { size: 0, strokeColors: P.sup, strokeWidth: 2, hover: { size: 6 } },
        xaxis: {
          categories: meses.map((m) => m[0]),
          // 'on' pone cada mes justo debajo de su punto; por defecto Apex los
          // reparte entre marcas y el primero y el último quedan descolgados.
          tickPlacement: 'on',
          axisBorder: { show: false }, axisTicks: { show: false },
          labels: { style: { fontSize: '12px' }, hideOverlappingLabels: true },
        },
        yaxis: ejeEntero(Math.max(...meses.map((m) => m[1]))),
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-meses', 'Sin histórico todavía.');
    }

    /* 6) Quién los cumplió. Una sola serie, un solo color: pintar cada
       persona de un color distinto no añadía información. */
    const quien = D.quien || [];
    if (quien.length === 1) {
      cifra('rd-quien', quien[0][1], 'cumplidos por ' + quien[0][0], P.serie);
    } else if (quien.length) {
      pintar('rd-quien', {
        ...base,
        chart: { ...base.chart, type: 'bar', height: altoBarras(quien.length) },
        series: [{ name: 'Cumplidos', data: quien.map((q) => q[1]) }],
        colors: [P.serie],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '46%' } },
        xaxis: { categories: quien.map((q) => q[0]), ...ejeEntero(Math.max(...quien.map((q) => q[1]))) },
        yaxis: { labels: { maxWidth: 170, style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-quien', 'Nadie ha resuelto requerimientos todavía.');
    }

    /* 7) Prioridad por institución. Era un mapa de calor donde las filas en
       cero salían en blanco y parecía roto. Apilado por prioridad se ve el
       total de cada institución y su mezcla, con la misma rampa que el
       gráfico de prioridad. */
    const heat = (D.heat || []).filter((s) => (s.data || []).length);
    const hayHeat = heat.length && heat.some((s) => s.data.some((d) => d.y > 0));
    if (hayHeat && heat[0].data.length === 1) {
      // Una sola institución: cruzarla contra la prioridad no cruza nada.
      const mezcla = heat.filter((s) => s.data[0].y > 0)
                         .map((s) => s.data[0].y + ' ' + s.name.toLowerCase());
      cifra('rd-heat', heat.reduce((a, s) => a + s.data[0].y, 0),
            heat[0].data[0].x + ' · ' + mezcla.join(', '));
    } else if (hayHeat) {
      const cats = heat[0].data.map((d) => d.x);
      const totales = cats.map((_, i) => heat.reduce((a, s) => a + (s.data[i] ? s.data[i].y : 0), 0));
      pintar('rd-heat', {
        ...base,
        chart: { ...base.chart, type: 'bar', stacked: true, height: altoBarras(cats.length, 104) },
        series: heat.map((s) => ({ name: s.name, data: s.data.map((d) => d.y) })),
        colors: heat.map((s) => P.prioridad[s.name] || P.serie),
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '42%' } },
        stroke: { show: true, width: 2, colors: [P.sup] },
        xaxis: { categories: cats, ...ejeEntero(Math.max(...totales)) },
        yaxis: { labels: { style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        dataLabels: {
          enabled: true,
          formatter: (val) => (val >= 1 ? val : ''),
          // El naranja claro de "Baja" no aguanta texto blanco: cada
          // prioridad lleva la tinta que contrasta con su relleno.
          style: { fontSize: '12px', fontWeight: 700, colors: P.tintaPrio },
          dropShadow: { enabled: false },
        },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-heat', 'Sin datos por institución y prioridad.');
    }

    /* 8) Carga abierta por persona. Era un treemap: con dos personas eran dos
       bloques enormes de colores que parecían un semáforo. En barras se
       compara de verdad quién lleva más. */
    const carga = D.carga || [];
    if (carga.length === 1) {
      cifra('rd-carga', carga[0][1], 'abiertos, todos de ' + carga[0][0], P.pendiente);
    } else if (carga.length) {
      pintar('rd-carga', {
        ...base,
        chart: { ...base.chart, type: 'bar', height: altoBarras(carga.length) },
        series: [{ name: 'Abiertos', data: carga.map((c) => c[1]) }],
        colors: [P.pendiente],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '46%' } },
        xaxis: { categories: carga.map((c) => c[0]), ...ejeEntero(Math.max(...carga.map((c) => c[1]))) },
        yaxis: { labels: { maxWidth: 170, style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-carga', 'Nadie tiene requerimientos abiertos.');
    }

    /* 9) Cumplimiento global. El número grande del panel: medidor limpio, sin
       degradado, y el color lo pone el tramo en el que cae. */
    if (typeof D.pct === 'number') {
      const col = D.pct >= 66 ? P.estado.bien : (D.pct >= 33 ? P.estado.ojo : P.estado.mal);
      pintar('rd-pct', {
        ...base,
        chart: { ...base.chart, type: 'radialBar', height: 270 },
        series: [D.pct],
        labels: ['Cumplimiento'],
        colors: [col],
        fill: { type: 'solid' },
        // A 0% la punta redondeada deja igualmente su media caña dibujada, y
        // se ve una pastilla suelta al inicio del canal que no significa nada.
        stroke: { lineCap: D.pct > 0 ? 'round' : 'butt' },
        plotOptions: {
          radialBar: {
            startAngle: -135, endAngle: 135,
            // Aro fino: el protagonista es el número del centro, no la rosca.
            hollow: { size: '76%' },
            track: { background: P.pista, strokeWidth: '100%', margin: 0 },
            dataLabels: {
              name: { color: cMuted, fontSize: '13px', fontWeight: 600, offsetY: 26 },
              value: { color: cText, fontSize: '38px', fontWeight: 700, offsetY: -6, formatter: (n) => Math.round(n) + '%' },
            },
          },
        },
      });
    }
  };

  dibujar();
  document.addEventListener('meca:tema', dibujar);
})();

/* =========================================================
   Buscador de tabla reutilizable (data-tabla-buscar). Filtra las filas del
   <tbody> de su tarjeta por el texto tecleado, comparando contra data-buscar
   (o el texto de la fila). Marca las filas que no coinciden con .fila-oculta
   y muestra el aviso [data-buscar-vacio] si no queda ninguna.
   ========================================================= */
document.querySelectorAll('[data-tabla-buscar]').forEach((input) => {
  const card = input.closest('.tabla-card') || document;
  const tbody = card.querySelector('table tbody');
  if (!tbody) return;
  const vacio = card.querySelector('[data-buscar-vacio]');
  const contador = card.querySelector('[data-buscar-count]');
  // Sin tildes y en minúsculas por los dos lados: nadie escribe "planificación"
  // con tilde en un buscador, y sin esto "planificacion" no encontraba nada.
  // La ñ también se descompone, así que "ordonez" encuentra a "Ordoñez".
  const normalizar = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const filtrar = () => {
    const q = normalizar(input.value.trim());
    let n = 0;
    tbody.querySelectorAll('tr').forEach((tr) => {
      if (tr.hasAttribute('data-no-buscar')) return;
      const hay = normalizar(tr.dataset.buscar || tr.textContent || '');
      const ok = !q || hay.includes(q);
      tr.classList.toggle('fila-oculta', !ok);
      if (ok) n++;
    });
    if (vacio) vacio.hidden = n > 0;
    if (contador) contador.textContent = n;
    // La tabla puede estar paginada: se le avisa para que reparta las filas
    // que SI coinciden, no las de la pagina en la que estabas.
    tbody.dispatchEvent(new CustomEvent('tabla-filtrada'));
  };
  input.addEventListener('input', filtrar);
});

/* =========================================================
   Observaciones en hilo: el botón "Responder" abre el cuadro del propio hilo
   y la respuesta se manda por AJAX, así la conversación no recarga la página.
   ========================================================= */
document.addEventListener('click', (e) => {
  const abrir = e.target.closest('.obs-responder');
  if (abrir) {
    const caja = abrir.closest('.obs-item')?.querySelector('.obs-responder-caja');
    if (!caja) return;
    caja.hidden = !caja.hidden;
    if (!caja.hidden) { MC.sonidoAbrir(); caja.querySelector('textarea')?.focus(); }
    return;
  }
  const cancelar = e.target.closest('.obs-responder-cancelar');
  if (cancelar) {
    const caja = cancelar.closest('.obs-responder-caja');
    if (caja) { caja.hidden = true; caja.querySelector('textarea').value = ''; }
  }
});

document.addEventListener('submit', async (e) => {
  const form = e.target.closest('.obs-responder-caja');
  if (!form) return;
  e.preventDefault();
  const txt = form.querySelector('textarea');
  if (!txt.value.trim()) { MC.sonidoError(); MC.toast('Escribe la respuesta.', 'error'); return; }
  const btn = form.querySelector('button[type="submit"], .btn-primary');
  btn.disabled = true;
  btn.classList.add('btn-enviando');   // el avión despega mientras va
  try {
    const fd = new FormData(form);
    fd.set('ajax', '1');
    const res = await fetch('actions.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    const data = await res.json();
    if (!data.ok) { MC.sonidoError(); MC.toast(data.error || 'No se pudo responder.', 'error'); return; }
    // La respuesta se mete ANTES del cuadro, al final de las que ya había.
    data.items.forEach((html) => {
      form.insertAdjacentHTML('beforebegin', html);
      MC.estrenar(form.previousElementSibling);
    });
    txt.value = '';
    form.hidden = true;
    MC.sonidoEnviar();
    MC.toast('Respuesta publicada', 'success', 1600);
  } catch {
    MC.sonidoError();
    MC.toast('Error de red al responder.', 'error');
  } finally {
    btn.disabled = false;
    btn.classList.remove('btn-enviando');
  }
});

/* Deploys: el botón "Cambios subidos" se marca en cuanto se pulsa. El envío es
   un POST normal y la página recarga en un parpadeo; sin esa señal el
   encargado no sabe si registró o si le falló el clic, y vuelve a pulsar.
   No se deshabilita el botón: un botón deshabilitado en pleno submit no manda
   su valor. Se marca el formulario y el segundo envío se descarta. */
document.addEventListener('submit', (e) => {
  const form = e.target;
  const btn = form.querySelector?.('[data-dep-btn]');
  if (!btn) return;
  if (form.dataset.depEnviado) { e.preventDefault(); return; }
  form.dataset.depEnviado = '1';
  btn.classList.add('dep-enviando');
});


/* Ajustes → Despliegues: los campos de alias siguen a "Proyectos a los que
   afecta cada subida". Salen todos en el HTML y aquí se deja ver solo lo
   marcado, al vuelo: si hubiera que guardar para verlos, marcar un proyecto y
   no encontrar su casilla parece que la pantalla está rota.

   Sin nada marcado valen todos, igual que en el resto del módulo.

   Los ocultos NO se deshabilitan: siguen enviando su alias, así que desmarcar
   un proyecto un rato no borra lo que ya se había escrito. */
document.querySelectorAll('[data-alias-de]').forEach((caja) => {
  const sel = document.querySelector('select[name="' + caja.dataset.aliasDe + '"]');
  if (!sel) return;
  const sincronizar = () => {
    const marcados = new Set([...sel.selectedOptions].map((o) => o.value));
    caja.querySelectorAll('[data-proy]').forEach((fila) => {
      fila.hidden = marcados.size > 0 && !marcados.has(fila.dataset.proy);
    });
  };
  sel.addEventListener('change', sincronizar);
  sincronizar();
});
