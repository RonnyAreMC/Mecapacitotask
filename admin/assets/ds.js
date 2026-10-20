/* =========================================================
   Instalar las apps (PWA): window.MCInstalar
   Guarda el pedido de instalar que hace el navegador (beforeinstallprompt)
   para que un botón propio instale con un clic. Ese evento llega una sola
   vez y temprano: por eso este bloque va primero en ds.js. Lo usan
   Notificaciones → Tus dispositivos (el panel) y correo.php (la app del
   correo). Cada página solo puede instalar la app de SU manifest.

   MCInstalar.disponible   true si el navegador ya ofreció instalar con un
                           clic (Chrome, Edge, Android). Safari y Firefox: nunca.
   MCInstalar.instalar()   muestra el cuadro del navegador. Llamarla directo
                           desde un clic. Promesa con 'accepted' (instaló),
                           'dismissed' (dijo que no) o null (no había pedido).
                           El pedido sirve una sola vez: luego disponible = false.
   MCInstalar.plataforma   'ios' (iPhone y iPad), 'android', 'windows', 'mac' u 'otro'.
   MCInstalar.navegador    'safari', 'chrome', 'edge', 'firefox', 'samsung' u 'otro'.
   MCInstalar.instalada    true si la página está abierta como app (ventana
                           propia: display-mode standalone, o el ícono de
                           inicio en iPhone).
   MCInstalar.recienInstalada  true si se instaló en esta visita (appinstalled).
   MCInstalar.unClic       true si este navegador sabe instalar con un clic,
                           aunque todavía no lo haya ofrecido.

   Evento: document → 'mcinstalar:cambio' cada vez que algo cambia.
   detail = { motivo, disponible, instalada }; motivo: 'disponible' (llegó
   el pedido), 'respuesta' (contestó el cuadro), 'instalada' (appinstalled)
   o 'ventana' (pasó a abrirse como app, o al revés).
   ========================================================= */
(() => {
  if (window.MCInstalar) return;
  const ua = navigator.userAgent || '';
  const esIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const plataforma = esIOS ? 'ios' : /Android/.test(ua) ? 'android' : /Windows/.test(ua) ? 'windows'
    : /Macintosh|Mac OS X/.test(ua) ? 'mac' : 'otro';
  const navegador = /Edg(A|iOS)?\//.test(ua) ? 'edge' : /SamsungBrowser/.test(ua) ? 'samsung' : /Firefox\/|FxiOS/.test(ua) ? 'firefox'
    : /OPR\/|Opera/.test(ua) ? 'otro' : /Chrome\/|CriOS|Chromium/.test(ua) ? 'chrome' : /Safari\//.test(ua) ? 'safari' : 'otro';
  // «fullscreen» no cuenta: también lo da el navegador normal con F11
  const modos = ['standalone', 'minimal-ui', 'window-controls-overlay'].map((m) => (window.matchMedia ? matchMedia('(display-mode: ' + m + ')') : null));
  const enVentana = () => modos.some((q) => q && q.matches) || navigator.standalone === true;
  let pedido = null;
  let recien = false;
  const avisar = (motivo) => document.dispatchEvent(new CustomEvent('mcinstalar:cambio', { detail: { motivo, disponible: pedido !== null, instalada: enVentana() } }));
  window.addEventListener('beforeinstallprompt', (ev) => {
    ev.preventDefault();   // sin la barrita automática de Chrome: se instala desde el botón propio
    pedido = ev;
    avisar('disponible');
  });
  window.addEventListener('appinstalled', () => { pedido = null; recien = true; avisar('instalada'); });
  // Chrome de computadora pasa la pestaña a la ventana de la app al instalar
  modos.forEach((q) => { if (q && q.addEventListener) q.addEventListener('change', () => avisar('ventana')); });
  window.MCInstalar = {
    get disponible() { return pedido !== null; },
    get instalada() { return enVentana(); },
    get recienInstalada() { return recien; },
    plataforma,
    navegador,
    unClic: 'BeforeInstallPromptEvent' in window || 'onbeforeinstallprompt' in window,
    /** Muestra el cuadro del navegador (prompt() va antes del primer await: sigue dentro del clic). */
    async instalar() {
      const p = pedido;
      if (!p) return null;
      pedido = null;       // el mismo pedido no se puede mostrar dos veces
      let r = null;
      try {
        await p.prompt();
        r = ((await p.userChoice) || {}).outcome || null;
      } catch (e) { r = null; }
      avisar('respuesta');
      return r;
    },
  };
})();

/* =========================================================
   Componentes del design system (SIGE) que necesitan JS.

   - Lista desplegable: <div class="mc-select" data-ds-select> con su
     <select class="ds-select-nativo"> adentro (lo imprime UI::selectDS).
     El <select> real sigue en el formulario y es la fuente de verdad: la
     lista se arma de sus <option>/<optgroup> cada vez que se abre, y al
     elegir se le cambia el valor y se dispara «change». Un <select
     data-ds-select> suelto también se convierte solo.
     Opciones: data-desc (segunda línea), data-ds-buscar (buscador
     siempre; si no, aparece con más de 7 opciones).
   - Casilla: <label class="mc-check"> alterna is-checked y dibuja el check.
   ========================================================= */
(() => {
  const ICO = {
    chev: '<path d="M6 9.5l6 6 6-6"/>',
    buscar: '<circle cx="10.8" cy="10.8" r="6.8"/><path d="M15.8 15.8l4.7 4.7"/>',
  };
  const svg = (n, px, cls) => '<svg class="mc-icon' + (cls ? ' ' + cls : '') + '" width="' + px + '" height="' + px
    + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + ICO[n] + '</svg>';
  const check = (px, cls) => '<svg class="mc-draw-check' + (cls ? ' ' + cls : '') + '" width="' + px + '" height="' + px
    + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const plano = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  let nId = 0;

  /* ---------- Lista desplegable ---------- */

  /** Convierte un <select data-ds-select> suelto en la lista del sistema. */
  function convertir(sel) {
    if (sel.closest('.mc-select')) return;
    const caja = document.createElement('div');
    caja.className = 'mc-select';
    caja.setAttribute('data-ds-select', '');
    if (sel.hasAttribute('data-ds-buscar')) caja.setAttribute('data-ds-buscar', '');
    sel.before(caja);
    caja.appendChild(sel);
    sel.classList.add('ds-select-nativo');
    sel.removeAttribute('data-ds-select');
    caja.insertAdjacentHTML('beforeend', '<button type="button" class="mc-input mc-input--' + (sel.dataset.dsTam || 'md')
      + ' mc-select__trigger" role="combobox" aria-haspopup="listbox" aria-expanded="false"><span class="mc-select__value"></span>'
      + svg('chev', 18, 'mc-select__chev') + '</button>');
    pintar(caja);
  }

  /** El botón muestra la opción elegida del <select> real. */
  function pintar(caja) {
    const sel = caja.querySelector('select');
    const btn = caja.querySelector('.mc-select__trigger');
    if (!sel || !btn) return;
    const o = sel.options[sel.selectedIndex];
    const vacio = !o || o.value === '';
    const txt = o ? o.text : (sel.dataset.placeholder || 'Elegir…');
    btn.querySelector('.mc-select__value').innerHTML = '<span class="' + (vacio ? 'mc-select__placeholder' : 'mc-select__text') + '">' + esc(txt) + '</span>';
    btn.disabled = sel.disabled;
    btn.classList.toggle('is-disabled', sel.disabled);
    if (sel.getAttribute('aria-label') && !btn.getAttribute('aria-label')) btn.setAttribute('aria-label', sel.getAttribute('aria-label'));
  }

  let abierto = null;   // { caja, btn, sel, pop, lista, items, activo, buscador, glide }

  function abrir(caja) {
    if (abierto) cerrar(false);
    const sel = caja.querySelector('select');
    const btn = caja.querySelector('.mc-select__trigger');
    if (!sel || sel.disabled) return;
    const id = 'ds-lista-' + (++nId);
    const opciones = [...sel.options];
    const conBuscador = caja.hasAttribute('data-ds-buscar') || opciones.length > 7;

    let h = '';
    if (conBuscador) {
      h += '<div class="mc-select__search">' + svg('buscar', 18)
        + '<input class="mc-select__search-input" type="text" placeholder="' + esc(sel.dataset.buscar || 'Buscar…')
        + '" aria-label="Buscar" autocomplete="off" role="searchbox" aria-controls="' + id + '"></div>';
    }
    h += '<div class="mc-select__list" role="listbox" id="' + id + '"><span class="mc-glide mc-glide--instant" style="opacity:0"></span>';
    let grupo = null, g = -1;
    opciones.forEach((o, i) => {
      // Lo escondido (hidden en la opción o en su grupo) no se ofrece
      if (o.hidden || (o.parentElement && o.parentElement.tagName === 'OPTGROUP' && o.parentElement.hidden)) return;
      const og = o.parentElement && o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : '';
      if (og !== grupo) { grupo = og; g++; if (og) h += '<div class="mc-select__group" data-g="' + g + '">' + esc(og) + '</div>'; }
      const elegida = i === sel.selectedIndex;
      const desc = o.dataset.desc || '';
      h += '<div class="mc-option' + (elegida ? ' is-selected' : '') + (o.disabled ? ' is-disabled' : '') + '" role="option" id="' + id + '-' + i
        + '" aria-selected="' + elegida + '"' + (o.disabled ? ' aria-disabled="true"' : '') + ' data-i="' + i + '" data-g="' + g
        + '" data-buscar="' + esc(plano(o.text + ' ' + desc)) + '" style="--i:' + Math.min(i, 10) + '">'
        + '<span class="mc-option__text"><span class="mc-option__label">' + esc(o.text) + '</span>'
        + (desc ? '<span class="mc-option__desc">' + esc(desc) + '</span>' : '') + '</span>'
        + (elegida ? check(16, 'mc-option__check') : '') + '</div>';
    });
    h += '<div class="mc-select__empty" hidden>Sin resultados</div></div>';

    const pop = document.createElement('div');
    pop.className = 'mc-pop mc-select__pop ds-pop-flotante';
    pop.innerHTML = h;
    // Dentro de un modal abierto, la lista va en el modal (si no, quedaría debajo)
    (caja.closest('dialog[open]') || document.body).appendChild(pop);

    abierto = {
      caja, btn, sel, pop,
      lista: pop.querySelector('.mc-select__list'),
      items: [...pop.querySelectorAll('.mc-option')],
      glide: pop.querySelector('.mc-glide'),
      buscador: pop.querySelector('.mc-select__search-input'),
      activo: null,
    };
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-controls', id);
    btn.classList.add('is-open');
    colocar();
    const elegida = abierto.items.find((x) => x.classList.contains('is-selected')) || visibles()[0];
    if (elegida) activar(elegida, true);
    requestAnimationFrame(() => abierto && abierto.glide.classList.remove('mc-glide--instant'));
    if (abierto.buscador) abierto.buscador.focus();

    pop.addEventListener('mousemove', (e) => {
      const it = e.target.closest('.mc-option');
      if (it && !it.classList.contains('is-disabled') && it !== abierto.activo) activar(it, false);
    });
    pop.addEventListener('click', (e) => {
      const it = e.target.closest('.mc-option');
      if (it && !it.classList.contains('is-disabled')) elegir(+it.dataset.i);
    });
    if (abierto.buscador) {
      abierto.buscador.addEventListener('input', filtrar);
      abierto.buscador.addEventListener('keydown', teclas);
    }
  }

  function cerrar(foco) {
    if (!abierto) return;
    const { btn, pop } = abierto;
    btn.setAttribute('aria-expanded', 'false');
    btn.removeAttribute('aria-activedescendant');
    btn.classList.remove('is-open');
    pop.remove();
    abierto = null;
    if (foco) btn.focus();
  }

  /** La lista flota (fija) debajo del botón; si no cabe, encima. */
  function colocar() {
    if (!abierto) return;
    const { btn, pop } = abierto;
    const r = btn.getBoundingClientRect();
    const ancho = Math.min(Math.max(r.width, 300), 460, innerWidth - 24);
    pop.style.width = ancho + 'px';
    const izq = Math.max(12, Math.min(r.left, innerWidth - ancho - 12));
    const alto = pop.offsetHeight;
    const abajo = innerHeight - r.bottom - 12;
    const arriba = r.top - 12;
    const subir = alto + 6 > abajo && arriba > abajo;
    pop.classList.toggle('es-arriba', subir);
    pop.style.left = izq + 'px';
    pop.style.top = (subir ? Math.max(8, r.top - 6 - alto) : r.bottom + 6) + 'px';
  }

  const visibles = () => abierto.items.filter((x) => !x.hidden && !x.classList.contains('is-disabled'));

  function activar(it, desplazar) {
    const a = abierto;
    if (a.activo) a.activo.classList.remove('is-active');
    a.activo = it;
    if (!it) { a.glide.style.opacity = '0'; return; }
    it.classList.add('is-active');
    (a.buscador || a.btn).setAttribute('aria-activedescendant', it.id);
    a.glide.style.cssText = 'opacity:1;width:' + it.offsetWidth + 'px;height:' + it.offsetHeight
      + 'px;transform:translate(' + it.offsetLeft + 'px,' + it.offsetTop + 'px)';
    if (desplazar) it.scrollIntoView({ block: 'nearest' });
  }

  function filtrar() {
    const q = plano(abierto.buscador.value.trim());
    const grupos = new Set();
    abierto.items.forEach((x) => {
      x.hidden = q !== '' && !x.dataset.buscar.includes(q);
      if (!x.hidden) grupos.add(x.dataset.g);
    });
    abierto.pop.querySelectorAll('.mc-select__group').forEach((gr) => { gr.hidden = !grupos.has(gr.dataset.g); });
    const v = visibles();
    abierto.pop.querySelector('.mc-select__empty').hidden = v.length > 0;
    activar(v[0] || null, true);
  }

  function elegir(i) {
    const { caja, sel } = abierto;
    if (sel.selectedIndex !== i) {
      sel.selectedIndex = i;
      sel.dispatchEvent(new Event('input', { bubbles: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    pintar(caja);
    cerrar(true);
  }

  let tecleado = '', tTecleo = 0;
  function teclas(e) {
    if (!abierto) return;
    const v = visibles();
    const pos = v.indexOf(abierto.activo);
    const mover = (n) => { e.preventDefault(); if (v.length) activar(v[Math.max(0, Math.min(v.length - 1, n))], true); };
    switch (e.key) {
      case 'ArrowDown': return mover(pos + 1);
      case 'ArrowUp': return mover(pos < 0 ? v.length - 1 : pos - 1);
      case 'Home': if (!abierto.buscador) mover(0); return;
      case 'End': if (!abierto.buscador) mover(v.length - 1); return;
      case 'PageDown': return mover(pos + 6);
      case 'PageUp': return mover(pos - 6);
      case ' ':
        if (abierto.buscador) return;          // en el buscador, el espacio se escribe
      // falls through
      case 'Enter':
        e.preventDefault();
        if (abierto.activo) elegir(+abierto.activo.dataset.i);
        return;
      case 'Escape': e.preventDefault(); e.stopPropagation(); return cerrar(true);
      case 'Tab': return cerrar(false);
    }
    // Sin buscador: escribir salta a la opción que empieza así
    if (!abierto.buscador && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      clearTimeout(tTecleo);
      tecleado += plano(e.key);
      tTecleo = setTimeout(() => { tecleado = ''; }, 600);
      const it = v.find((x) => x.dataset.buscar.startsWith(tecleado));
      if (it) activar(it, true);
    }
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ds-select] > .mc-select__trigger');
    if (!btn) return;
    const caja = btn.parentElement;
    if (abierto && abierto.caja === caja) cerrar(false); else abrir(caja);
  });
  document.addEventListener('keydown', (e) => {
    const btn = e.target.closest && e.target.closest('[data-ds-select] > .mc-select__trigger');
    if (!btn) return;
    if (abierto && abierto.btn === btn) return teclas(e);
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); abrir(btn.parentElement); }
  });
  // El espacio ya abrió o eligió en keydown: que el botón no haga además su clic
  document.addEventListener('keyup', (e) => {
    if (e.key === ' ' && e.target.closest && e.target.closest('[data-ds-select] > .mc-select__trigger')) e.preventDefault();
  });
  // Clic fuera, rueda o cambio de tamaño: la lista se cierra o se recoloca
  document.addEventListener('mousedown', (e) => {
    if (abierto && !abierto.pop.contains(e.target) && !abierto.caja.contains(e.target)) cerrar(false);
  }, true);
  addEventListener('scroll', (e) => { if (abierto && !abierto.pop.contains(e.target)) colocar(); }, true);
  // (en el celular, abrir el teclado del buscador también cambia el tamaño: se recoloca, no se cierra)
  addEventListener('resize', colocar);
  // Si otro código cambia el <select> real (y avisa con change), el botón se entera
  document.addEventListener('change', (e) => {
    if (e.target.classList && e.target.classList.contains('ds-select-nativo')) pintar(e.target.closest('.mc-select'));
  });

  /* ---------- Ver / ocultar contraseña (UI::botonVerClave) ---------- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest && e.target.closest('[data-ver-clave]');
    if (!b) return;
    const campo = b.parentElement.querySelector('input');
    if (!campo) return;
    const ver = campo.type === 'password';
    campo.type = ver ? 'text' : 'password';
    b.classList.toggle('is-viendo', ver);
    b.setAttribute('aria-pressed', ver ? 'true' : 'false');
    b.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
  });

  /* ---------- Correo: el marco del mensaje toma la altura de su contenido ---------- */
  const ajustarMarco = (f) => {
    try {
      const d = f.contentDocument;
      if (!d || !d.documentElement) return;
      f.style.height = Math.max(240, d.documentElement.scrollHeight + 4) + 'px';
      d.querySelectorAll('img').forEach((im) => { if (!im.complete) im.addEventListener('load', () => ajustarMarco(f), { once: true }); });
    } catch (err) { /* sin acceso al contenido: queda la altura mínima */ }
  };
  document.querySelectorAll('[data-correo-marco]').forEach((f) => {
    f.addEventListener('load', () => ajustarMarco(f));
    if (f.contentDocument && f.contentDocument.readyState === 'complete') ajustarMarco(f);
  });
  if (document.querySelector('[data-correo-marco]')) {
    let t = null;
    window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(() => document.querySelectorAll('[data-correo-marco]').forEach(ajustarMarco), 150); });
  }

  /* ---------- Zona de adjuntos (data-adjuntos) ----------
     Suma lo que se suelta o se elige (sin repetir), lo lista con su tamaño y
     una × para quitarlo, y frena el envío si pasa del tope. */
  const tamBonito = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
  document.querySelectorAll('[data-adjuntos]').forEach((caja) => {
    const input = caja.querySelector('[data-adjuntos-input]');
    const zona = caja.querySelector('[data-adjuntos-zona]');
    const lista = caja.querySelector('[data-adjuntos-lista]');
    const total = caja.querySelector('[data-adjuntos-total]');
    const tope = parseInt(caja.dataset.tope, 10) || 0;
    if (!input || !zona || typeof DataTransfer === 'undefined') return;
    let archivos = [];
    const pintar = () => {
      const dt = new DataTransfer();
      archivos.forEach((f) => dt.items.add(f));
      input.files = dt.files;
      lista.innerHTML = '';
      archivos.forEach((f, i) => {
        const li = document.createElement('li');
        li.className = 'ds-adjuntos__item';
        li.innerHTML = '<svg class="mc-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8z"/><path d="M14 3.5V8h4.5"/></svg>'
          + '<span class="ds-adjuntos__nombre"></span><small></small>'
          + '<button type="button" class="mc-iconbtn mc-iconbtn--sm ds-adjuntos__quitar"><svg class="mc-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button>';
        li.querySelector('.ds-adjuntos__nombre').textContent = f.name;
        li.querySelector('small').textContent = tamBonito(f.size);
        const q = li.querySelector('button');
        q.setAttribute('aria-label', 'Quitar ' + f.name);
        q.addEventListener('click', () => { archivos.splice(i, 1); pintar(); input.focus(); });
        lista.appendChild(li);
      });
      const suma = archivos.reduce((s, f) => s + f.size, 0);
      const pasa = tope > 0 && suma > tope;
      total.hidden = !archivos.length;
      total.textContent = archivos.length + (archivos.length === 1 ? ' archivo' : ' archivos') + ' · ' + tamBonito(suma)
        + (pasa ? ' — pasa del límite de ' + tamBonito(tope) + '. Quita alguno para poder enviar.' : '');
      caja.classList.toggle('is-error', pasa);
      const form = caja.closest('form');
      const enviar = form && form.querySelector('[data-enviar]');
      if (enviar) enviar.disabled = pasa;
    };
    const sumar = (nuevos) => {
      [...nuevos].forEach((f) => {
        if (!archivos.some((a) => a.name === f.name && a.size === f.size && a.lastModified === f.lastModified)) archivos.push(f);
      });
      pintar();
    };
    input.addEventListener('change', () => sumar(input.files));
    ['dragenter', 'dragover'].forEach((ev) => zona.addEventListener(ev, (e) => { e.preventDefault(); zona.classList.add('is-sobre'); }));
    ['dragleave', 'dragend', 'drop'].forEach((ev) => zona.addEventListener(ev, (e) => { e.preventDefault(); zona.classList.remove('is-sobre'); }));
    zona.addEventListener('drop', (e) => { if (e.dataTransfer && e.dataTransfer.files.length) sumar(e.dataTransfer.files); });
  });

  /* ---------- Destinatarios del correo (data-destinatarios) ----------
     Cada dirección queda como una etiqueta. Mientras se escribe, sugiere del
     directorio del equipo y de las direcciones a las que la persona ya
     escribió (#correo-directorio). Enter, Tab, coma o punto y coma agregan;
     Retroceso con el campo vacío quita la última. El campo original
     (name="para", "cc", "cco") sigue mandando las direcciones separadas por comas. */
  const datosDir = (() => {
    const s = document.getElementById('correo-directorio');
    if (!s) return null;
    try { return JSON.parse(s.textContent); } catch (err) { return null; }
  })();
  if (datosDir) {
    const sinTildes = (v) => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const esCorreo = (v) => /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[a-z]{2,}$/i.test(v);
    const porCorreo = new Map();
    (datosDir.equipo || []).forEach((p) => porCorreo.set(p.e, Object.assign({}, p)));
    const recientes = [];
    (datosDir.recientes || []).forEach((r) => {
      let p = porCorreo.get(r.e);
      if (!p) { p = { e: r.e, n: r.n || '', d: 'Le escribiste antes' }; porCorreo.set(r.e, p); }
      p.reciente = true;
      recientes.push(p);
    });
    const iniciales = (p) => p.i || ((p.n || p.e).replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean).slice(0, 2)
      .map((w) => w[0]).join('').toUpperCase() || '@');
    const avatar = (p, clase, error) => {
      const s = document.createElement('span');
      s.className = clase;
      s.setAttribute('aria-hidden', 'true');
      if (error) s.textContent = '!';
      else if (p && p.f) { const im = new Image(); im.src = p.f; im.alt = ''; s.append(im); }
      else s.textContent = iniciales(p);
      if (p && p.c && !error) s.style.setProperty('--av', p.c);
      return s;
    };
    const resaltar = (texto, q) => {
      const frag = document.createDocumentFragment();
      const t = sinTildes(q.trim());
      const i = t ? sinTildes(texto).indexOf(t) : -1;
      if (i < 0) { frag.append(texto); return frag; }
      const b = document.createElement('b');
      b.textContent = texto.slice(i, i + t.length);
      frag.append(texto.slice(0, i), b, texto.slice(i + t.length));
      return frag;
    };
    const buscar = (q, fuera) => {
      const t = sinTildes(q.trim());
      if (!t) return recientes.filter((p) => !fuera.has(p.e)).slice(0, 6);
      const puntos = (p) => {
        const n = sinTildes(p.n);
        let s = 0;
        if (n.startsWith(t)) s = 5;
        else if (n.split(/\s+/).some((w) => w.startsWith(t)) || p.e.startsWith(t)) s = 4;
        else if (n.includes(t)) s = 2;
        else if (p.e.includes(t)) s = 1;
        return s ? s + (p.reciente ? 0.5 : 0) : 0;
      };
      return [...porCorreo.values()].filter((p) => !fuera.has(p.e)).map((p) => [puntos(p), p]).filter((x) => x[0] > 0)
        .sort((a, b) => b[0] - a[0] || (a[1].n || a[1].e).localeCompare(b[1].n || b[1].e, 'es')).slice(0, 8).map((x) => x[1]);
    };
    // «Ana <a@x.com>, b@y.com; c@z.com d@w.com» → ['a@x.com', 'b@y.com', 'c@z.com', 'd@w.com']
    const partir = (v) => String(v || '').split(/[,;\n]+/).flatMap((s) => {
      const m = s.match(/<([^<>]+)>/);
      if (m) return [m[1]];
      const pal = s.trim().split(/\s+/).filter(Boolean);
      return pal.length > 1 && pal.every((w) => w.includes('@')) ? pal : [s.trim()];
    }).map((x) => x.trim().replace(/^mailto:/i, '')).filter(Boolean);
    const svgX = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>';

    let nCampo = 0;
    document.querySelectorAll('[data-destinatarios]').forEach((orig) => {
      const caja = orig.closest('.mc-input');
      const campo = orig.closest('.mc-field');
      if (!caja || !campo) return;
      const k = ++nCampo;
      const ph = orig.placeholder || '';
      const elegidos = [];
      const escribir = document.createElement('input');
      escribir.type = 'text';
      escribir.className = 'mc-input__control ds-dest__input';
      escribir.id = orig.id;
      orig.removeAttribute('id');
      escribir.placeholder = ph;
      escribir.autocomplete = 'off';
      escribir.spellcheck = false;
      escribir.setAttribute('role', 'combobox');
      escribir.setAttribute('aria-autocomplete', 'list');
      escribir.setAttribute('aria-expanded', 'false');
      escribir.setAttribute('aria-controls', 'ds-dest-pop-' + k);
      const conFoco = document.activeElement === orig || orig.hasAttribute('autofocus');
      orig.type = 'hidden';
      orig.required = false;
      orig.removeAttribute('autofocus');
      caja.classList.add('ds-dest');
      caja.insertBefore(escribir, orig);
      campo.classList.add('ds-dest-campo');
      const pop = document.createElement('div');
      pop.className = 'mc-pop ds-dest__pop';
      pop.id = 'ds-dest-pop-' + k;
      pop.setAttribute('role', 'listbox');
      pop.hidden = true;
      campo.append(pop);
      const aviso = document.createElement('p');
      aviso.className = 'ds-dest__error';
      aviso.setAttribute('role', 'alert');
      aviso.hidden = true;
      caja.after(aviso);

      const limpiarError = () => { aviso.hidden = true; caja.classList.remove('is-error'); campo.classList.remove('is-error'); };
      const sincronizar = () => {
        orig.value = elegidos.map((x) => x.e).join(', ');
        escribir.placeholder = elegidos.length ? '' : ph;
        limpiarError();
      };
      const quitar = (e) => {
        const i = elegidos.findIndex((x) => x.e === e);
        if (i < 0) return;
        elegidos[i].chip.remove();
        elegidos.splice(i, 1);
        sincronizar();
      };
      const agregar = (texto, persona) => {
        const e = String(persona ? persona.e : texto).trim().replace(/^<|>$/g, '').toLowerCase();
        if (!e || elegidos.some((x) => x.e === e)) return;
        const p = persona || porCorreo.get(e) || null;
        const ok = esCorreo(e);
        const chip = document.createElement('span');
        chip.className = 'ds-dest__chip' + (ok ? '' : ' is-error');
        chip.dataset.dsTip = ok ? (p && p.n ? p.n + ' · ' + e : e) : 'Esta dirección no es válida: ' + e;
        chip.append(avatar(p || { e }, 'ds-dest__av', !ok));
        const t = document.createElement('span');
        t.className = 'ds-dest__txt';
        t.textContent = p && p.n ? p.n : e;
        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'ds-dest__x';
        x.tabIndex = -1;
        x.setAttribute('aria-label', 'Quitar ' + (p && p.n ? p.n : e));
        x.innerHTML = svgX;
        x.addEventListener('click', (ev) => { ev.stopPropagation(); quitar(e); escribir.focus(); });
        chip.append(t, x);
        caja.insertBefore(chip, escribir);
        elegidos.push({ e, chip });
        sincronizar();
      };
      const confirmarTexto = () => {
        const v = escribir.value;
        escribir.value = '';
        partir(v).forEach((x) => agregar(x));
        cerrar();
      };

      let opciones = [];
      let activa = -1;
      const marcar = (i) => {
        activa = i;
        [...pop.querySelectorAll('.ds-dest__opt')].forEach((o, j) => {
          o.classList.toggle('is-active', j === i);
          o.setAttribute('aria-selected', j === i ? 'true' : 'false');
          if (j === i) o.scrollIntoView({ block: 'nearest' });
        });
        if (i >= 0) escribir.setAttribute('aria-activedescendant', pop.id + '-' + i);
        else escribir.removeAttribute('aria-activedescendant');
      };
      const cerrar = () => { pop.hidden = true; escribir.setAttribute('aria-expanded', 'false'); marcar(-1); };
      const elegir = (i) => {
        const p = opciones[i];
        if (!p) return;
        escribir.value = '';
        agregar(p.e, p.nuevo ? null : p);
        cerrar();
        escribir.focus();
      };
      const abrir = () => {
        const q = escribir.value;
        const qt = q.trim().toLowerCase();
        const fuera = new Set(elegidos.map((x) => x.e));
        opciones = buscar(q, fuera);
        // Una dirección completa que no está en el directorio también se ofrece
        if (esCorreo(qt) && !fuera.has(qt) && !opciones.some((p) => p.e === qt)) opciones.push({ e: qt, n: '', d: 'Agregar esta dirección', nuevo: true });
        if (!opciones.length) { cerrar(); return; }
        pop.innerHTML = '';
        if (!qt) {
          const g = document.createElement('div');
          g.className = 'mc-select__group';
          g.textContent = 'Les escribiste hace poco';
          pop.append(g);
        }
        opciones.forEach((p, i) => {
          const o = document.createElement('div');
          o.className = 'mc-option ds-dest__opt';
          o.id = pop.id + '-' + i;
          o.setAttribute('role', 'option');
          o.append(avatar(p, 'ds-dest__av ds-dest__av--lg'));
          const tx = document.createElement('span');
          tx.className = 'mc-option__text';
          const l = document.createElement('span');
          l.className = 'mc-option__label';
          l.append(resaltar(p.n || p.e, q));
          const d = document.createElement('span');
          d.className = 'mc-option__desc';
          if (p.n) d.append(resaltar(p.e, q));
          if (p.d) d.append((p.n ? ' · ' : '') + p.d);
          tx.append(l, d);
          o.append(tx);
          o.addEventListener('mousedown', (ev) => ev.preventDefault());
          o.addEventListener('click', () => elegir(i));
          o.addEventListener('mousemove', () => { if (activa !== i) marcar(i); });
          pop.append(o);
        });
        pop.style.top = (caja.offsetTop + caja.offsetHeight + 6) + 'px';
        pop.hidden = false;
        escribir.setAttribute('aria-expanded', 'true');
        marcar(qt ? 0 : -1);
      };

      escribir.addEventListener('keydown', (ev) => {
        const abierto = !pop.hidden;
        if (ev.key === 'ArrowDown') { ev.preventDefault(); if (!abierto) abrir(); else marcar(Math.min(opciones.length - 1, activa + 1)); return; }
        if (ev.key === 'ArrowUp') { if (abierto) { ev.preventDefault(); marcar(Math.max(0, activa - 1)); } return; }
        if (ev.key === 'Escape') { if (abierto) { ev.preventDefault(); ev.stopPropagation(); cerrar(); } return; }
        if (ev.key === 'Enter' || ev.key === 'Tab' || ev.key === ',' || ev.key === ';') {
          if (abierto && activa >= 0) { ev.preventDefault(); elegir(activa); return; }
          if (escribir.value.trim()) { ev.preventDefault(); confirmarTexto(); return; }
          if (ev.key !== 'Tab') ev.preventDefault(); // Enter en «Para» no manda el correo a medias
          return;
        }
        if (ev.key === 'Backspace' && !escribir.value && elegidos.length) {
          ev.preventDefault();
          quitar(elegidos[elegidos.length - 1].e);
        }
      });
      escribir.addEventListener('input', () => {
        // Pegar una lista («a@x.com, b@y.com») la convierte en etiquetas
        if (/[,;\n]/.test(escribir.value)) {
          const partes = escribir.value.split(/[,;\n]+/);
          escribir.value = partes.pop();
          partes.forEach((s) => partir(s).forEach((x) => agregar(x)));
        }
        limpiarError();
        abrir();
      });
      let sinSugerir = false; // al marcar un error se enfoca sin tapar el aviso con la lista
      escribir.addEventListener('focus', () => { if (sinSugerir) { sinSugerir = false; return; } if (!escribir.value) abrir(); });
      escribir.addEventListener('blur', () => {
        setTimeout(() => {
          if (document.activeElement === escribir) return;
          if (escribir.value.trim()) confirmarTexto();
          cerrar();
        }, 150);
      });
      caja.addEventListener('mousedown', (ev) => {
        if (ev.target === caja || ev.target.classList.contains('ds-dest__chip') || ev.target.classList.contains('ds-dest__txt')) {
          ev.preventDefault();
          escribir.focus();
        }
      });

      partir(orig.value).forEach((x) => agregar(x));
      if (conFoco) escribir.focus();

      const form = orig.form;
      if (form) {
        form.addEventListener('submit', (ev) => {
          if (escribir.value.trim()) confirmarTexto();
          const malos = elegidos.filter((x) => !esCorreo(x.e));
          const falta = orig.dataset.destinatarios === 'para' && !elegidos.length;
          if (!malos.length && !falta) return;
          ev.preventDefault();
          aviso.textContent = falta ? 'Agrega al menos un destinatario.' : 'Corrige o quita las direcciones marcadas en rojo.';
          aviso.hidden = false;
          caja.classList.add('is-error');
          campo.classList.add('is-error');
          cerrar();
          if (document.activeElement !== escribir) { sinSugerir = true; escribir.focus(); }
        });
      }
    });
  }

  /* ---------- Notificaciones nativas (Web Push) ----------
     Pregunta arriba, en cada página, mientras la persona no acepte ni diga
     «No, gracias». Aceptar: el navegador pide permiso y el dispositivo queda
     anotado en el panel. Una vez activado queda así para siempre: al volver
     a entrar se comprueba sola, sin preguntar, que el panel lo tenga (la
     barra de arriba trae las huellas de sus dispositivos) y, si el navegador
     cambió la suscripción, la clave del panel no coincide o el servidor la
     dio por vencida, se renueva sola. Solo deja de recibir si la persona lo
     quita en Notificaciones → Tus dispositivos o bloquea los avisos.
     Solo avisos: no se descarga ni se instala nada. */
  // Los datos vienen en la barra de arriba o, en una página sin barra (la app del correo),
  // en cualquier elemento con data-push (Push::atributosPagina)
  const encPush = document.querySelector('#ds-topbar[data-push]') || document.querySelector('[data-push]');
  const clavePush = encPush && encPush.dataset.push;
  if (clavePush && 'serviceWorker' in navigator && window.isSecureContext) {
    const modal = encPush.querySelector('[data-push-modal]');
    const RECHAZO = 'meca-push-no';
    const ULTIMA = 'meca-push-ep';          // la última dirección de avisos que el panel anotó de este navegador
    const REVISADO = 'meca-push-revisado';  // para no insistir en cada página si el servidor dice que no
    const ua = navigator.userAgent;
    const esIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const instalada = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
    // En iPhone/iPad (y Safari de Mac «Agregar al Dock») cada app instalada tiene su propia suscripción:
    // se le dice al panel cuál es (panel o correo) para no mandar dos veces lo mismo. En Chrome, Edge
    // o Android el panel y el correo comparten una sola y esto va vacío.
    const appAislada = instalada && (esIOS || (/Macintosh/.test(ua) && /Safari\//.test(ua) && !/Chrome|Chromium|Edg|OPR|Firefox/.test(ua)))
      ? (/\/correo\.php$/.test(location.pathname) ? 'correo' : 'panel') : '';
    const soporta = 'PushManager' in window && 'Notification' in window;
    const leer = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
    const anotar = (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ } };
    const bytes = (b64) => {
      const s = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
      return Uint8Array.from(s, (c) => c.charCodeAt(0));
    };
    const lista = (s) => String(s || '').split(' ').filter(Boolean);
    const mios = lista(encPush.dataset.pushMios);          // dispositivos de esta persona que reciben bien
    const quitados = lista(encPush.dataset.pushQuitados);  // los que quitó a propósito
    const prefs = lista(encPush.dataset.pushPrefs);        // 'correo', 'avisos'
    // La misma huella que Push::huella() (sha256, 16 caracteres)
    const huella = async (ep) => {
      if (!ep || !window.crypto || !crypto.subtle) return '';
      const h = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(ep)));
      return Array.from(h.slice(0, 8), (b) => b.toString(16).padStart(2, '0')).join('');
    };
    // ¿La suscripción de este navegador se hizo con la clave actual del panel?
    const mismaClave = (sub) => {
      const k = sub.options && sub.options.applicationServerKey;
      if (!k) return true;                  // el navegador no la informa: se da por buena
      const a = new Uint8Array(k);
      const b = bytes(clavePush);
      return a.length === b.length && a.every((x, i) => x === b[i]);
    };
    // La clave va en la dirección del service worker: con ella renueva la suscripción él solo (sw.js)
    const registro = navigator.serviceWorker.register('sw.js?k=' + encodeURIComponent(clavePush)).catch(() => null);
    const estado = { recibe: false };       // ¿este navegador recibe las notificaciones del sistema?
    const guardar = async (sub, anterior) => {
      const fd = new FormData();
      fd.append('accion', 'push_suscribir');
      fd.append('sub', JSON.stringify(appAislada ? Object.assign(sub.toJSON(), { app: appAislada }) : sub));
      if (anterior) fd.append('anterior', anterior);
      const r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      const j = r.ok ? await r.json().catch(() => null) : null;
      if (!j || !j.ok) {
        const err = new Error((j && j.error) || 'No se pudo anotar este dispositivo.');
        err.servidor = true;
        throw err;
      }
      anotar(ULTIMA, sub.endpoint);
      return j;
    };
    /** Deja este navegador suscrito con la clave del panel y anotado. forzar: pide una suscripción nueva. */
    const suscribir = async (forzar) => {
      const reg = await registro;
      if (!reg) throw new Error('Este navegador no deja recibir avisos aquí.');
      await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      const anterior = (sub && sub.endpoint) || leer(ULTIMA) || '';
      if (sub && (forzar === true || !mismaClave(sub))) { await sub.unsubscribe().catch(() => {}); sub = null; }
      if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(clavePush) });
      const j = await guardar(sub, anterior !== sub.endpoint ? anterior : '');
      estado.recibe = true;
      return j;
    };
    /** Al entrar, si ya aceptó: comprueba sin preguntar que el panel tenga este navegador al día. */
    const sincronizar = async () => {
      const reg = await registro;
      if (!reg) return 'sin-sw';
      await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      const ep = sub ? sub.endpoint : (leer(ULTIMA) || '');
      const h = await huella(ep);
      if (h && quitados.includes(h)) return 'quitado';                 // lo quitó a propósito: no vuelve solo
      if (sub && mismaClave(sub) && mios.includes(h)) {                // todo en orden: ni un pedido al servidor
        estado.recibe = true;
        anotar(ULTIMA, ep);
        return 'activo';
      }
      // Navegador nuevo, suscripción renovada, otra clave o el último aviso falló: se anota otra vez
      // (si el servidor sigue diciendo que no, se reintenta cada media hora, no en cada página)
      let antes = {};
      try { antes = JSON.parse(leer(REVISADO) || '{}'); } catch (e) { /* vacío */ }
      if (antes.ep === ep && Date.now() - (antes.t || 0) < 30 * 60 * 1000) return 'revisado';
      anotar(REVISADO, JSON.stringify({ ep, t: Date.now() }));
      try { await suscribir(false); } catch (e) { if (e.servidor) await suscribir(true); else throw e; }
      return 'activo';
    };
    const sincronizado = (soporta && Notification.permission === 'granted') ? sincronizar().catch(() => 'error') : Promise.resolve('');
    const aviso = (t, tipo, desc) => { if (window.MC && MC.toast) MC.toast(t, tipo, 7000, desc ? { desc } : undefined); };
    /**
     * Activa los avisos en este dispositivo. Llamarla directo desde un clic (en iPhone el permiso
     * solo sale de un toque): lo primero que hace es pedir el permiso. Nunca lanza error.
     * Devuelve 'activo' | 'bloqueado' (dijo que no o está bloqueado) | 'sin-respuesta' (cerró la
     * pregunta) | 'sin-soporte' (p. ej. iPhone sin instalar) | 'error'.
     * opciones.silencioso: true para que no muestre sus propios avisos (MC.toast).
     */
    const activar = async (opciones) => {
      const decir = (opciones && opciones.silencioso) ? () => {} : aviso;
      if (!soporta) return 'sin-soporte';
      let permiso;
      try { permiso = await Notification.requestPermission(); } catch (e) { permiso = Notification.permission; }
      if (permiso === 'granted') {
        anotar(RECHAZO, null);
        anotar(REVISADO, null);
        try {
          // Pedido a propósito: si este navegador no está entre los que reciben bien (lo quitó, falló…), una suscripción nueva
          const reg = await registro;
          const sub = reg ? await reg.pushManager.getSubscription() : null;
          const forzar = !!sub && !mios.includes(await huella(sub.endpoint));
          const j = await suscribir(forzar).catch((e) => { if (e.servidor && !forzar) return suscribir(true); throw e; });
          decir('Listo: este dispositivo recibirá los avisos', 'success', j.equipo || '');
          return 'activo';
        } catch (e) { decir('No se pudo activar en este dispositivo', 'error', e.message); return 'error'; }
      }
      if (permiso === 'denied') {
        decir('Bloqueaste los avisos en el navegador', 'warning', 'Para activarlos, toca el candado junto a la dirección del panel y permite las notificaciones.');
        return 'bloqueado';
      }
      return 'sin-respuesta';
    };
    // recibe('correo' | 'avisos'): ¿a este navegador le llega la notificación del sistema de ese tipo?
    const recibe = (tipo) => estado.recibe && Notification.permission === 'granted' && (!tipo || prefs.includes(tipo));
    // listo: promesa con cómo quedó la revisión al entrar ('activo', 'quitado', 'revisado', 'error' o '' si no tiene permiso)
    window.MCPush = { activar, suscribir, soporta, esIOS, instalada, recibe, listo: sincronizado, olvidarRechazo: () => anotar(RECHAZO, null) };

    navigator.serviceWorker.addEventListener('message', (ev) => {
      const d = ev.data || {};
      // Llegó una notificación nativa: cualquier página puede escucharlo (document → 'meca:push')
      if (d.tipo === 'meca-push') document.dispatchEvent(new CustomEvent('meca:push', { detail: d.aviso || {} }));
      // Al tocar una notificación, si la pestaña no la controla el service worker, va ella sola
      if (d.tipo !== 'meca-ir' || typeof d.url !== 'string') return;
      try { const u = new URL(d.url, location.href); if (u.origin === location.origin) location.href = u.href; } catch (e) { /* dirección rara: no se va */ }
    });
    if (navigator.serviceWorker.startMessages) navigator.serviceWorker.startMessages();

    // En «Tus dispositivos», marca cuál es este y cuenta en qué está
    const filasPush = document.querySelectorAll('[data-push-endpoint]');
    if (filasPush.length && soporta) {
      registro.then((r) => (r ? r.pushManager.getSubscription() : null)).then((sub) => {
        if (!sub) return;
        filasPush.forEach((f) => {
          if (f.dataset.pushEndpoint !== sub.endpoint) return;
          f.classList.add('is-este');
          const marca = f.querySelector('.push-equipo__este');
          if (marca) marca.hidden = false;
        });
      }).catch(() => {});
    }
    const aqui = document.querySelector('[data-push-aqui]');
    if (aqui) {
      const pintarAqui = (tono, texto) => { aqui.dataset.tono = tono; aqui.textContent = texto; aqui.hidden = false; };
      if (!soporta) {
        pintarAqui('aviso', esIOS ? 'En iPhone y iPad los avisos llegan si abres el panel desde la pantalla de inicio: Compartir → Agregar a inicio, y entra desde ese ícono.'
          : 'Este navegador no permite notificaciones aquí. Prueba con Chrome, Edge, Firefox o Safari actualizados.');
      } else if (Notification.permission === 'denied') {
        pintarAqui('error', 'Bloqueaste las notificaciones del panel en este navegador. Toca el candado junto a la dirección, permite «Notificaciones» y recarga la página.');
      } else if (Notification.permission === 'default') {
        pintarAqui('aviso', 'Este dispositivo todavía no recibe avisos: pulsa «Activar en este dispositivo».');
      } else {
        sincronizado.then((r) => {
          if (r === 'quitado') pintarAqui('aviso', 'Quitaste este dispositivo: no le llegan avisos. Pulsa «Activar en este dispositivo» para volver a recibirlos.');
          else if (estado.recibe) pintarAqui('ok', 'Este dispositivo recibe los avisos como notificación del sistema, aunque el panel esté cerrado.');
          else pintarAqui('aviso', 'Este dispositivo tiene permiso, pero todavía no quedó anotado: pulsa «Activar en este dispositivo».');
        });
      }
    }

    if (modal) {
      const cerrar = () => { modal.classList.remove('is-visible'); setTimeout(() => { modal.hidden = true; }, 200); };
      // En iPhone, si ya activó el panel instalado en la pantalla de inicio, Safari normal no vuelve a insistir
      const iosSinInstalar = esIOS && !instalada && !soporta && encPush.dataset.pushIos !== '1';
      const preguntar = leer(RECHAZO) !== '1' && ((soporta && Notification.permission === 'default') || iosSinInstalar);
      if (iosSinInstalar) {
        modal.querySelector('[data-push-normal]').hidden = true;
        modal.querySelector('[data-push-ios]').hidden = false;
        modal.querySelector('[data-push-si] .mc-btn__label').textContent = 'Entendido';
      }
      modal.querySelector('[data-push-si]').addEventListener('click', () => { cerrar(); if (!iosSinInstalar) activar(); });
      modal.querySelector('[data-push-no]').addEventListener('click', () => { anotar(RECHAZO, '1'); cerrar(); });
      modal.querySelector('[data-push-cerrar]').addEventListener('click', cerrar);
      if (preguntar) {
        setTimeout(() => { modal.hidden = false; requestAnimationFrame(() => modal.classList.add('is-visible')); }, 900);
      }
    }
  }
  // «Activar en este dispositivo» desde Notificaciones → Tus dispositivos
  document.querySelectorAll('[data-push-activar]').forEach((b) => b.addEventListener('click', async () => {
    if (!window.MCPush) return;
    MCPush.olvidarRechazo();
    if (!MCPush.soporta) {
      if (window.MC && MC.toast) MC.toast(MCPush.esIOS ? 'Primero agrega el panel a tu pantalla de inicio' : 'Este navegador no permite avisos aquí', 'warning', 7000,
        { desc: MCPush.esIOS ? 'Toca Compartir → Agregar a inicio y abre el panel desde ese ícono.' : 'Prueba con Chrome, Edge, Firefox o Safari actualizados.' });
      return;
    }
    await MCPush.activar();
    setTimeout(() => location.reload(), 1200);
  }));

  /* ---------- Tutoriales: el reproductor en un modal (data-tutorial-ver) ----------
     El marco va con referrerpolicy «strict-origin-when-cross-origin»: sin eso
     YouTube muestra el error 153. Al cerrar se vacía (el video se detiene). */
  const analizarVideo = (url) => {
    let u;
    try { u = new URL(/^https?:\/\//i.test(url) ? url : 'https://' + url); } catch (err) { return { error: 'Ese enlace no es válido.' }; }
    const host = u.hostname.replace(/^(www|m)\./i, '').toLowerCase();
    const ruta = u.pathname;
    const q = u.searchParams;
    const yt = /^[A-Za-z0-9_-]{11}$/;
    if (['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'].includes(host)) {
      const id = q.get('v') || (ruta.match(/^\/(?:shorts|embed|live|v)\/([A-Za-z0-9_-]{11})/) || [])[1] || '';
      if (!id && q.get('list')) return { error: 'Es una lista de reproducción: abre el video que quieres y copia su enlace.' };
      return yt.test(id) ? { proveedor: 'youtube', id } : { error: 'Ese enlace de YouTube no lleva a un video.' };
    }
    if (host === 'youtu.be') {
      const id = ruta.split('/')[1] || '';
      return yt.test(id) ? { proveedor: 'youtube', id } : { error: 'Ese enlace corto de YouTube no es válido.' };
    }
    if (host === 'drive.google.com' || host === 'docs.google.com') {
      if (ruta.includes('/folders/')) return { error: 'Es una carpeta de Drive: abre el video y copia el enlace del archivo.' };
      const id = (ruta.match(/\/file\/d\/([A-Za-z0-9_-]{20,})/) || [])[1] || (/^[A-Za-z0-9_-]{20,}$/.test(q.get('id') || '') ? q.get('id') : '');
      return id ? { proveedor: 'drive', id } : { error: 'Ese enlace de Drive no lleva a un archivo.' };
    }
    return { error: 'Solo se aceptan enlaces de YouTube o de Google Drive.' };
  };
  window.DSTutorial = { analizar: analizarVideo };

  let dlgTut = null;
  const abrirTutorial = (b) => {
    if (!dlgTut) {
      dlgTut = document.createElement('dialog');
      dlgTut.className = 'ds-modal ds-tutorial';
      dlgTut.setAttribute('aria-labelledby', 'ds-tut-titulo');
      dlgTut.innerHTML = '<header class="ds-tutorial__cab"><span class="ds-tutorial__ico" aria-hidden="true">'
        + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5.5v13l10-6.5z"/></svg></span>'
        + '<h2 class="ds-modal__title" id="ds-tut-titulo" data-tut-titulo></h2>'
        + '<button type="button" class="mc-iconbtn mc-iconbtn--sm" data-tut-cerrar aria-label="Cerrar el video">'
        + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button></header>'
        + '<div class="ds-tutorial__marco"><iframe data-tut-marco title="Tutorial en video" loading="eager"'
        + ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen"'
        + ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>'
        + '<p class="ds-tutorial__pie"><span data-tut-ayuda></span> <a data-tut-abrir target="_blank" rel="noopener"></a></p>';
      document.body.appendChild(dlgTut);
      const vaciar = () => { dlgTut.querySelector('[data-tut-marco]').src = 'about:blank'; };
      dlgTut.querySelector('[data-tut-cerrar]').addEventListener('click', () => dlgTut.close());
      dlgTut.addEventListener('click', (e) => { if (e.target === dlgTut) dlgTut.close(); });
      dlgTut.addEventListener('close', vaciar);
    }
    const yt = b.dataset.proveedor === 'youtube';
    dlgTut.querySelector('[data-tut-titulo]').textContent = b.dataset.titulo || 'Tutorial';
    dlgTut.querySelector('[data-tut-ayuda]').textContent = yt
      ? '¿No carga? El dueño del video debe permitir insertarlo.'
      : '¿No carga? El archivo debe estar compartido con «Cualquier persona con el enlace».';
    const abrir = dlgTut.querySelector('[data-tut-abrir]');
    abrir.href = b.dataset.abrir || '#';
    abrir.textContent = yt ? 'Ábrelo en YouTube' : 'Ábrelo en Drive';
    dlgTut.querySelector('[data-tut-marco]').src = b.dataset.src;
    dlgTut.showModal();
  };
  document.addEventListener('click', (e) => {
    const b = e.target.closest && e.target.closest('[data-tutorial-ver]');
    if (!b) return;
    e.preventDefault();
    abrirTutorial(b);
  });

  /* ---------- Teléfono: solo números (soloNumerosTel) ---------- */
  document.addEventListener('input', (e) => {
    const t = e.target;
    if (!(t instanceof HTMLInputElement) || t.name !== 'telefono') return;
    const limpio = t.value.replace(/\D/g, '').slice(0, 15);
    if (limpio !== t.value) t.value = limpio;
  });

  /* ---------- Casilla ---------- */
  document.addEventListener('change', (e) => {
    const inp = e.target;
    if (!(inp instanceof HTMLInputElement) || inp.type !== 'checkbox') return;
    const l = inp.closest('.mc-check');
    if (!l) return;
    l.classList.toggle('is-checked', inp.checked);
    const caja = l.querySelector('.mc-check__box');
    if (caja) caja.innerHTML = inp.checked ? check(12) : '';
  });

  /** Convierte lo que haya en `raiz` (también lo que se agregue después). */
  function mejorar(raiz) {
    (raiz || document).querySelectorAll('select[data-ds-select]').forEach(convertir);
  }
  mejorar(document);
  new MutationObserver((ms) => {
    for (const m of ms) for (const n of m.addedNodes) {
      if (n.nodeType !== 1) continue;
      if (n.matches('select[data-ds-select]')) convertir(n); else if (n.querySelector('select[data-ds-select]')) mejorar(n);
    }
  }).observe(document.body, { childList: true, subtree: true });

  window.DS = Object.assign(window.DS || {}, { mejorar, refrescar: (sel) => pintar(sel.closest('.mc-select')) });
})();

/* =========================================================
   Barra de arriba (UI::barraSuperior): campana de notificaciones, cuenta,
   tema claro/oscuro y el modal «Pedir algo a otro equipo». También las
   pestañas de las páginas nuevas (data-ds-tabs-auto).
   ========================================================= */
(() => {
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  /** Un desplegable de la barra: abre/cierra con su botón, Esc o clic fuera. */
  function desplegable(raiz, btnSel, panelSel, alAbrir) {
    const btn = raiz.querySelector(btnSel);
    const panel = raiz.querySelector(panelSel);
    if (!btn || !panel) return null;
    const cerrar = () => {
      if (panel.hidden) return;
      panel.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('is-active');
    };
    const abrir = () => {
      document.dispatchEvent(new CustomEvent('ds:cerrar-barra', { detail: panel }));
      panel.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('is-active');
      if (alAbrir) alAbrir();
    };
    btn.addEventListener('click', () => (panel.hidden ? abrir() : cerrar()));
    document.addEventListener('ds:cerrar-barra', (e) => { if (e.detail !== panel) cerrar(); });
    document.addEventListener('mousedown', (e) => { if (!raiz.contains(e.target)) cerrar(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { cerrar(); btn.focus(); } });
    return { abrir, cerrar };
  }

  const barra = document.getElementById('ds-topbar');
  if (barra) {
    /* ---------- Tema: el ícono dice a qué se cambia ---------- */
    const tema = document.getElementById('theme-toggle');
    const pintarTema = () => {
      const oscuro = document.documentElement.classList.contains('dark');
      if (!tema) return;
      tema.setAttribute('aria-label', oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
      tema.dataset.dsTip = oscuro ? 'Modo claro' : 'Modo oscuro';   // lo muestra el tooltip del sistema
      tema.removeAttribute('title');
    };
    pintarTema();
    document.addEventListener('meca:tema', pintarTema);

    /* ---------- Cuenta ---------- */
    const cuenta = barra.querySelector('[data-cuenta]');
    if (cuenta) desplegable(cuenta, '[data-cuenta-btn]', '[data-cuenta-menu]');

    /* ---------- Campana ---------- */
    const notif = barra.querySelector('[data-notif]');
    if (notif) {
      const lista = notif.querySelector('[data-notif-lista]');
      const badge = notif.querySelector('[data-notif-n]');
      const sub = notif.querySelector('[data-notif-sub]');
      const todas = notif.querySelector('[data-notif-todas]');
      const vacio = notif.querySelector('[data-notif-vacio]');
      const btn = notif.querySelector('[data-notif-btn]');
      let iconos = {};
      try { iconos = JSON.parse(document.getElementById('ds-notif-iconos').textContent || '{}'); } catch (e) { /* sin íconos */ }
      let ultimo = parseInt(notif.dataset.ultimo, 10) || 0;

      desplegable(notif, '[data-notif-btn]', '[data-notif-panel]');

      // ¿A este navegador ya le llega la notificación del sistema? Entonces no se repite dentro de la página
      const conSistema = (tipo) => !!(window.MCPush && MCPush.recibe && MCPush.recibe(tipo));
      const sonar = () => {
        notif.classList.remove('is-ringing');
        void notif.offsetWidth;            // reinicia la animación de la campana
        notif.classList.add('is-ringing');
      };

      // El número rojo de la campana: avisos sin leer + correos InnoTech sin leer.
      // En la app instalada (PWA) también va en el ícono (setAppBadge).
      let nAvisos = parseInt(notif.dataset.sin, 10) || 0;
      let nCorreos = 0;
      const pintarTotal = () => {
        const total = nAvisos + nCorreos;
        badge.hidden = !total;
        badge.textContent = total > 99 ? '99+' : String(total);
        const correos = nCorreos === 1 ? '1 correo' : nCorreos + ' correos';
        sub.textContent = nAvisos && nCorreos ? nAvisos + ' sin leer · ' + correos
          : nAvisos ? nAvisos + ' sin leer' : nCorreos ? correos + ' sin leer' : 'Todo leído';
        todas.hidden = !nAvisos;            // «Marcar todo leído» es solo para los avisos
        btn.setAttribute('aria-label', 'Notificaciones' + (total ? ' (' + total + ' sin leer)' : ''));
        notif.classList.toggle('has-pendientes', total > 0);
        if ('setAppBadge' in navigator) {
          try { Promise.resolve(total ? navigator.setAppBadge(total) : navigator.clearAppBadge()).catch(() => {}); } catch (e) { /* sin número en el ícono */ }
        }
      };

      // Correo InnoTech: la fila de la campana con lo que no se ha leído
      const correoFila = notif.querySelector('[data-correo-fila]');
      const correoN = correoFila && correoFila.querySelector('[data-correo-n]');
      let correoAntes = correoN && !correoN.hidden ? (parseInt(correoN.textContent, 10) || 0) : 0;
      nCorreos = correoAntes;
      const correoContador = (n) => {
        if (!correoFila || correoFila.hasAttribute('data-correo-sin') || typeof n !== 'number') return;
        const sEl = correoFila.querySelector('[data-correo-sub]');
        if (correoN) { correoN.hidden = !n; correoN.textContent = n > 999 ? '999+' : String(n); }
        if (sEl) sEl.textContent = n ? n + ' sin leer' : 'Estás al día';
        if (n > correoAntes) {
          sonar();
          if (!conSistema('correo') && window.MC && MC.toast) {
            const d = n - correoAntes;
            MC.toast(d === 1 ? 'Te llegó un correo nuevo' : d + ' correos nuevos', 'info', 6000, { desc: 'Míralos en Notificaciones → Correo InnoTech.' });
          }
        }
        correoAntes = n;
        nCorreos = n;
        pintarTotal();
      };

      const contador = (n) => {
        nAvisos = n;
        pintarTotal();
      };
      pintarTotal();
      const fila = (n) => {
        const [ico, tono] = iconos[n.tipo] || ['', 'azul'];
        const li = document.createElement('li');
        li.innerHTML = '<a class="mc-notif__item' + (n.leida ? '' : ' is-unread') + '" href="' + esc(n.abrir) + '" data-id="' + n.id + '">'
          + '<span class="mc-tile mc-tile--sm mc-tile--' + esc(tono) + '">' + ico + '</span>'
          + '<span class="mc-notif__body"><span class="mc-notif__ititle">' + esc(n.titulo) + '</span>'
          + (n.texto ? '<span class="mc-notif__text">' + esc(n.texto) + '</span>' : '')
          + '<span class="mc-notif__time">' + esc(n.rotulo) + ' · ' + esc(n.hace) + '</span></span>'
          + (n.leida ? '' : '<span class="mc-notif__dot" aria-label="Sin leer"></span>') + '</a>';
        return li;
      };

      // Cada 45 s (y al volver a la pestaña) pregunta si llegó algo nuevo
      let pidiendo = false;
      async function revisar() {
        if (document.hidden || pidiendo) return;
        pidiendo = true;
        try {
          const r = await fetch('notificaciones.php?json=1&desde=' + ultimo, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
          const j = r.ok ? await r.json() : null;
          if (j && j.ok) {
            contador(j.sinLeer);
            if ('correo' in j) correoContador(j.correo);
            // Teléfono que falta o está mal escrito (aunque la sesión ya estuviera
            // abierta): se abre la ventana para corregirlo y no se cierra con Esc
            if (j.telefono && !document.getElementById('dlg-telefono')) {
              document.body.insertAdjacentHTML('beforeend', j.telefono);
              const dTel = document.getElementById('dlg-telefono');
              if (dTel && dTel.showModal) {
                dTel.addEventListener('cancel', (ev) => ev.preventDefault());
                dTel.showModal();
                setTimeout(() => dTel.querySelector('[name="telefono"]')?.focus(), 60);
              }
            }
            const nuevas = (j.items || []).filter((n) => n.id > ultimo);
            if (nuevas.length) {
              nuevas.slice().reverse().forEach((n) => lista.prepend(fila(n)));
              while (lista.children.length > 12) lista.lastElementChild.remove();
              ultimo = Math.max(ultimo, ...nuevas.map((n) => n.id));
              vacio.hidden = true;
              sonar();
              const n = nuevas[0];
              // Si este dispositivo recibe la notificación del sistema, esa basta: la campana suena y se marca
              if (!conSistema('avisos') && window.MC && MC.toast) {
                MC.toast(nuevas.length > 1 ? nuevas.length + ' notificaciones nuevas' : n.titulo, 'info', 6000,
                  { desc: nuevas.length > 1 ? n.titulo : n.texto });
              }
            }
          }
        } catch (e) { /* sin red o sesión vencida: se reintenta en la próxima vuelta */ }
        pidiendo = false;
      }
      setInterval(revisar, 45000);
      document.addEventListener('visibilitychange', () => { if (!document.hidden) revisar(); });
      // Llegó una notificación nativa (sw.js avisa a las pestañas abiertas): la campana se pone al día ya
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (ev) => { if (ev.data && ev.data.tipo === 'meca-push') revisar(); });
        if (navigator.serviceWorker.startMessages) navigator.serviceWorker.startMessages();
      }
      notif.addEventListener('animationend', () => notif.classList.remove('is-ringing'));

      todas.addEventListener('click', async () => {
        todas.disabled = true;
        try {
          const fd = new FormData();
          fd.append('accion', 'notif_leer_todas');
          await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
          lista.querySelectorAll('.is-unread').forEach((a) => a.classList.remove('is-unread'));
          lista.querySelectorAll('.mc-notif__dot').forEach((d) => d.remove());
          contador(0);
        } catch (e) {
          if (window.MC && MC.toast) MC.toast('No se pudo marcar como leído', 'error');
        }
        todas.disabled = false;
      });
    }
  }

  /* ---------- Modal «Pedir algo a otro equipo» ---------- */
  const dlg = document.getElementById('dlg-pedido');
  if (dlg) {
    const equipo = dlg.querySelector('[data-pedido-equipo]');
    const persona = dlg.querySelector('[data-pedido-persona]');
    // La lista de personas muestra solo las del equipo elegido
    const filtrar = () => {
      if (!equipo || !persona) return;
      const nombreEq = equipo.options[equipo.selectedIndex] ? equipo.options[equipo.selectedIndex].text : '';
      persona.querySelectorAll('optgroup').forEach((g) => { g.hidden = g.label !== nombreEq; });
      const elegida = persona.options[persona.selectedIndex];
      if (elegida && elegida.parentElement.tagName === 'OPTGROUP' && elegida.parentElement.hidden) {
        persona.value = '';
        persona.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
    if (equipo) equipo.addEventListener('change', filtrar);
    filtrar();
    document.addEventListener('click', (e) => {
      const abrir = e.target.closest('[data-pedido-nuevo]');
      if (abrir) {
        document.dispatchEvent(new CustomEvent('ds:cerrar-barra', { detail: null }));
        dlg.showModal();
        setTimeout(() => { const t = dlg.querySelector('[name="titulo"]'); if (t) t.focus(); }, 30);
        return;
      }
      if (e.target.closest('#dlg-pedido [data-cerrar]')) dlg.close();
    });
    // Clic en el fondo oscuro: cierra
    dlg.addEventListener('mousedown', (e) => { if (e.target === dlg) dlg.close(); });
    // Desde una notificación o un enlace: pedidos.php?nuevo=1 abre el modal
    if (/[?&]nuevo=1\b/.test(location.search)) dlg.showModal();
  }

  /* ---------- Pestañas de las páginas nuevas (data-ds-tabs-auto) ---------- */
  document.querySelectorAll('[data-ds-tabs-auto]').forEach((cont) => {
    const raiz = cont.closest('.ds') || document;
    const tabs = [...cont.querySelectorAll('.mc-tab')];
    const ink = cont.querySelector('.mc-tabs__ink');
    const paneles = [...raiz.querySelectorAll('.ds-panel[data-panel]')];
    function ir(id, foco) {
      const t = tabs.find((x) => x.dataset.tab === id) || tabs[0];
      if (!t) return;
      tabs.forEach((x) => {
        const on = x === t;
        x.classList.toggle('is-active', on);
        x.setAttribute('aria-selected', on ? 'true' : 'false');
        x.tabIndex = on ? 0 : -1;
      });
      paneles.forEach((p) => { p.hidden = p.dataset.panel !== t.dataset.tab; });
      if (ink) { ink.style.width = t.offsetWidth + 'px'; ink.style.transform = 'translateX(' + t.offsetLeft + 'px)'; }
      if (foco) t.focus();
      cont.dataset.actual = t.dataset.tab;
    }
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => {
        ir(t.dataset.tab);
        history.replaceState(null, '', location.pathname + location.search + '#' + t.dataset.tab);
      });
      t.addEventListener('keydown', (e) => {
        const n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (n === undefined) return;
        e.preventDefault();
        ir(tabs[(n + tabs.length) % tabs.length].dataset.tab, true);
      });
    });
    // #recibidos abre esa pestaña; #p-12 abre la que tenga ese elemento y lo marca
    const h = decodeURIComponent(location.hash.slice(1));
    const destino = h && document.getElementById(h);
    const panelDestino = destino && destino.closest('.ds-panel[data-panel]');
    ir(panelDestino ? panelDestino.dataset.panel : (tabs.some((t) => t.dataset.tab === h) ? h : tabs[0] && tabs[0].dataset.tab));
    if (destino && panelDestino) {
      destino.classList.add('is-destacado');
      setTimeout(() => destino.scrollIntoView({ block: 'center' }), 60);
    }
    addEventListener('resize', () => ir(cont.dataset.actual));
  });
})();

/* =========================================================
   Tooltip del sistema (en vez del del navegador) para el menú lateral y la
   barra de arriba. Toma el title de cada elemento y lo guarda en
   data-ds-tip (así el navegador ya no pinta el suyo). Sale:
   - en el menú contraído, siempre (solo se ven los íconos);
   - en lo demás, si el elemento no muestra texto o si su texto se corta.
   Con el mouse tras un respiro corto, y con el teclado al enfocar.
   ========================================================= */
(() => {
  const tip = document.createElement('div');
  tip.className = 'ds-tip';
  tip.id = 'ds-tip';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);

  document.querySelectorAll('.sidebar [title], #ds-topbar [title], [data-ds-tip-auto][title]').forEach((el) => {
    const txt = el.getAttribute('title');
    if (!txt) return;
    el.dataset.dsTip = txt;
    if (!el.hasAttribute('aria-label') && !el.textContent.trim()) el.setAttribute('aria-label', txt);
    el.removeAttribute('title');
  });

  const contraido = () => document.documentElement.classList.contains('sb-collapsed') && innerWidth > 900;
  function debeMostrar(el) {
    if (el.closest('.sidebar') && contraido() && !el.closest('.nav-grupo-items')) return true;
    if (!(el.innerText || '').trim()) return true;
    const corto = el.querySelector('.truncate');
    return !!corto && corto.scrollWidth > corto.clientWidth + 1;
  }

  let actual = null, pendiente = null, reloj = 0;
  function mostrar(el) {
    pendiente = null;
    if (!el.isConnected || !el.dataset.dsTip || !debeMostrar(el)) return;
    actual = el;
    const derecha = !!el.closest('.sidebar') && innerWidth > 900;
    tip.textContent = el.dataset.dsTip;
    tip.className = 'ds-tip ' + (derecha ? 'a-la-derecha' : 'abajo');
    const r = el.getBoundingClientRect();
    const w = tip.offsetWidth, h = tip.offsetHeight;
    let x = derecha ? r.right + 10 : r.left + r.width / 2 - w / 2;
    let y = derecha ? r.top + r.height / 2 - h / 2 : r.bottom + 8;
    x = Math.max(8, Math.min(x, innerWidth - w - 8));
    y = Math.max(8, Math.min(y, innerHeight - h - 8));
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
    el.setAttribute('aria-describedby', 'ds-tip');
    requestAnimationFrame(() => { if (actual === el) tip.classList.add('is-visible'); });
  }
  function ocultar() {
    clearTimeout(reloj);
    pendiente = null;
    if (actual) actual.removeAttribute('aria-describedby');
    actual = null;
    tip.classList.remove('is-visible');
  }

  document.addEventListener('pointerover', (e) => {
    if (e.pointerType && e.pointerType !== 'mouse') return;
    const el = e.target.closest && e.target.closest('[data-ds-tip]');
    if (el && (el === actual || el === pendiente)) return;
    ocultar();
    if (el) { pendiente = el; reloj = setTimeout(() => mostrar(el), 280); }
  });
  document.addEventListener('focusin', (e) => {
    const el = e.target.closest && e.target.closest('[data-ds-tip]');
    if (el && el.matches(':focus-visible')) { ocultar(); mostrar(el); }
  });
  document.addEventListener('focusout', ocultar);
  ['pointerdown', 'scroll', 'resize'].forEach((ev) => addEventListener(ev, ocultar, true));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') ocultar(); });
})();

/* Elegir la portada de un proyecto: la opción marcada se resalta */
document.addEventListener('change', (e) => {
  const r = e.target;
  if (!(r instanceof HTMLInputElement) || r.type !== 'radio' || !r.closest('.ds-portadas')) return;
  r.closest('.ds-portadas').querySelectorAll('.ds-portada-op').forEach((l) => l.classList.toggle('is-checked', l.contains(r) && r.checked));
});

/* Filas que abren algo (requerimientos): también con Enter o espacio */
document.addEventListener('keydown', (e) => {
  const tr = e.target;
  if ((e.key === 'Enter' || e.key === ' ') && tr instanceof HTMLElement && tr.matches('tr[data-req]')) { e.preventDefault(); tr.click(); }
});
/* Botón × de los modales del sistema */
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-cerrar-dlg]');
  if (b && b.closest('dialog')) b.closest('dialog').close();
});
