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

      const contador = (n) => {
        badge.hidden = !n;
        badge.textContent = n > 99 ? '99+' : String(n);
        sub.textContent = n ? n + ' sin leer' : 'Todo leído';
        todas.hidden = !n;
        btn.setAttribute('aria-label', 'Notificaciones' + (n ? ' (' + n + ' sin leer)' : ''));
      };
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
            const nuevas = (j.items || []).filter((n) => n.id > ultimo);
            if (nuevas.length) {
              nuevas.slice().reverse().forEach((n) => lista.prepend(fila(n)));
              while (lista.children.length > 12) lista.lastElementChild.remove();
              ultimo = Math.max(ultimo, ...nuevas.map((n) => n.id));
              vacio.hidden = true;
              notif.classList.remove('is-ringing');
              void notif.offsetWidth;            // reinicia la animación de la campana
              notif.classList.add('is-ringing');
              const n = nuevas[0];
              if (window.MC && MC.toast) {
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
