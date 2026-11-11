/* =========================================================
   Invitados de las reuniones (ReunionesUI::selectorInvitados, en
   lib/meetings/reuniones_ui.php) y lo propio del asistente de la agenda
   (agenda.php). Estilos: assets/agenda.css.

   - La gente va agrupada por equipo; quien está en dos equipos sale en los
     dos, con la misma casilla (invitados[]): marcar una marca las demás.
   - «Todo el equipo» (en cada grupo) marca o quita a todo ese grupo;
     «Todos», a todo el panel; «Quitar a todos» deja la lista vacía.
   - El buscador filtra por nombre, rol, correo o equipo (sin tildes) y abre
     los grupos donde hay alguien; si nadie coincide, lo dice.
   - Cada grupo se pliega; el contador dice cuántas personas van y el
     resumen del asistente (data-resumen) dice quiénes.
   - En la agenda, el grupo «Del proyecto» es el del proyecto elegido en
     «¿De qué es?» (select proyecto_id); en «General» no hay.
   - Al editar (botón .js-editar-reunion), marca a los ya invitados.
   - Guardas del asistente (radios ocultos y required, que MecaWizard valida):
     al menos un invitado, la hora que no pasó y la repetición con días y fin.
   - <dialog data-abrir-al-cargar>: se abre solo al cargar (agenda.php?nueva=1).
   ========================================================= */
(function () {
  'use strict';
  const CHECK = '<svg class="mc-draw-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  const RAYA = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg>';
  const normal = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const avisar = (el) => el && el.dispatchEvent(new Event('change', { bubbles: true }));

  function montar(raiz) {
    if (raiz.dataset.invListo) return;
    raiz.dataset.invListo = '1';
    const campo = raiz.closest('.campo');
    const form = raiz.closest('form');
    const buscar = raiz.querySelector('[data-inv-buscar]');
    const req = raiz.querySelector('[data-inv-req]');
    const btnTodos = raiz.querySelector('[data-inv-todos]');
    const btnQuitar = raiz.querySelector('[data-inv-quitar]');
    const cuenta = raiz.querySelector('[data-inv-cuenta]');
    const vacio = raiz.querySelector('[data-inv-vacio]');
    const grupos = [...raiz.querySelectorAll('[data-inv-grupo]')];
    const chks = [...raiz.querySelectorAll('input[name="invitados[]"]')];
    const nombres = new Map(chks.map((c) => [c.value, c.dataset.nombre || '']));
    const todosIds = [...nombres.keys()];
    const idsDe = (g) => [...new Set([...g.querySelectorAll('input[name="invitados[]"]')].map((c) => c.value))];

    const pintarCaja = (caja, estado) => { if (caja) caja.innerHTML = estado === 'si' ? CHECK : (estado === 'parte' ? RAYA : ''); };
    const elegidos = () => new Set(chks.filter((c) => c.checked).map((c) => c.value));
    const abrir = (g, si) => {
      const b = g.querySelector('[data-inv-abrir]');
      const l = g.querySelector('.inv-lista');
      if (!b || !l) return;
      b.setAttribute('aria-expanded', si ? 'true' : 'false');
      l.hidden = !si;
    };

    // Pinta todo a partir de las casillas: grupos, contador, «Todos», resumen y la guarda
    const actualizar = () => {
      const sel = elegidos();
      chks.forEach((c) => {
        const li = c.closest('.inv-p');
        if (li.classList.contains('is-on') !== c.checked) {
          li.classList.toggle('is-on', c.checked);
          pintarCaja(li.querySelector('.inv-caja'), c.checked ? 'si' : 'no');
        }
      });
      grupos.forEach((g) => {
        const ids = idsDe(g);
        const n = ids.filter((i) => sel.has(i)).length;
        const estado = n === 0 ? 'no' : (n === ids.length ? 'si' : 'parte');
        const nEl = g.querySelector('[data-inv-n]');
        if (nEl) nEl.textContent = n + ' de ' + ids.length;
        const b = g.querySelector('[data-inv-todo]');
        if (b && b.dataset.estado !== estado) {
          b.dataset.estado = estado;
          b.setAttribute('aria-pressed', estado === 'si' ? 'true' : (estado === 'parte' ? 'mixed' : 'false'));
          pintarCaja(b.querySelector('.inv-caja'), estado);
        }
      });
      const n = sel.size;
      if (cuenta) cuenta.textContent = n === 0 ? 'Nadie invitado todavía' : (n === 1 ? '1 persona invitada' : n + ' personas invitadas');
      if (btnQuitar) btnQuitar.hidden = n === 0;
      if (btnTodos) btnTodos.setAttribute('aria-pressed', n && n === todosIds.length ? 'true' : 'false');
      // Lo que dice la confirmación del asistente
      if (campo) {
        const lista = [...sel].map((i) => nombres.get(i)).filter(Boolean).sort((a, b) => a.localeCompare(b, 'es'));
        campo.dataset.resumen = !n ? '' : (n === todosIds.length ? 'Todos (' + n + ' personas)'
          : (n <= 4 ? lista.join(', ') : lista.slice(0, 3).join(', ') + ' y ' + (n - 3) + ' más') + (n > 1 ? ' · ' + n + ' personas' : ''));
      }
      if (req && req.checked !== n > 0) { req.checked = n > 0; avisar(req); }
    };

    const poner = (ids, si) => {
      const set = new Set(ids.map(String));
      chks.forEach((c) => { if (set.has(c.value)) c.checked = si; });
      actualizar();
    };

    raiz.addEventListener('change', (e) => {
      const c = e.target;
      if (!(c instanceof HTMLInputElement) || c.name !== 'invitados[]') return;
      // La misma persona en otro equipo, igual
      chks.forEach((x) => { if (x !== c && x.value === c.value) x.checked = c.checked; });
      actualizar();
    });

    raiz.addEventListener('click', (e) => {
      const todo = e.target.closest('[data-inv-todo]');
      if (todo) {
        const g = todo.closest('[data-inv-grupo]');
        poner(idsDe(g), todo.dataset.estado !== 'si');
        abrir(g, true);
        return;
      }
      const ab = e.target.closest('[data-inv-abrir]');
      if (ab) { abrir(ab.closest('[data-inv-grupo]'), ab.getAttribute('aria-expanded') !== 'true'); return; }
      if (e.target.closest('[data-inv-todos]')) { poner(todosIds, btnTodos.getAttribute('aria-pressed') !== 'true'); return; }
      if (e.target.closest('[data-inv-quitar]')) { poner(todosIds, false); buscar?.focus(); }
    });

    // El buscador: al vuelo, sin tildes; si el texto es el nombre de un equipo, sale el equipo entero
    const filtrar = () => {
      const t = normal(buscar.value.trim());
      let hay = 0;
      grupos.forEach((g) => {
        if (g.hasAttribute('data-inv-oculto')) { g.hidden = true; return; }
        const todoGrupo = t !== '' && normal(g.dataset.invTxt).includes(t);
        let n = 0;
        g.querySelectorAll('.inv-p').forEach((li) => {
          const ok = !t || todoGrupo || normal(li.dataset.invTxt).includes(t);
          li.hidden = !ok;
          if (ok) n++;
        });
        g.hidden = n === 0;
        if (t && n) abrir(g, true);
        hay += n;
      });
      if (vacio) vacio.hidden = hay > 0;
    };
    if (buscar) {
      buscar.addEventListener('input', filtrar);
      // Enter en el buscador no avanza el asistente
      buscar.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    }
    // El asistente lleva el foco a la guarda cuando falta alguien: mejor al buscador
    req?.addEventListener('focus', () => buscar?.focus());

    // La agenda: el grupo «Del proyecto» es el del proyecto elegido (en «General» no hay)
    const verProyecto = (pid) => {
      grupos.forEach((g) => {
        if (g.dataset.invDe === undefined) return;
        const si = g.dataset.invDe === String(pid);
        g.toggleAttribute('data-inv-oculto', !si);
        g.hidden = !si;
        if (si) abrir(g, true);
      });
      // Con un proyecto, los demás equipos plegados (salvo los que ya tienen a alguien); en «General», abiertos
      const sel = elegidos();
      const conProy = grupos.some((g) => g.dataset.invDe === String(pid));
      grupos.forEach((g) => {
        if (g.dataset.invDe !== undefined) return;
        abrir(g, !conProy || idsDe(g).some((i) => sel.has(i)));
      });
      if (buscar && buscar.value) filtrar();
    };
    const selP = form && form.querySelector('select[name="proyecto_id"]');
    if (selP) selP.addEventListener('change', () => {
      verProyecto(selP.value);
      // La confirmación dice «General» o el proyecto (MecaWizard no resume la opción de valor 0)
      const c = selP.closest('.campo');
      if (c) c.dataset.resumen = selP.selectedOptions[0]?.textContent.trim() || '';
    });

    raiz._inv = {
      // Marcar exactamente a estos (al editar) y abrir los grupos donde están
      elegir(ids) {
        const set = new Set((ids || []).map(String));
        chks.forEach((c) => { c.checked = set.has(c.value); });
        if (buscar) { buscar.value = ''; filtrar(); }
        actualizar();
        grupos.forEach((g) => { if (!g.hidden && idsDe(g).some((i) => set.has(i))) abrir(g, true); });
      },
    };
    actualizar();
  }

  document.querySelectorAll('[data-inv]').forEach(montar);

  // Editar una reunión: admin.js abre el asistente con sus datos; aquí, sus invitados
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.js-editar-reunion');
    if (!b) return;
    const raiz = document.querySelector('#dlg-editar-reunion [data-inv]');
    if (!raiz || !raiz._inv) return;
    let r = null;
    try { r = JSON.parse(b.dataset.editarReunion || '{}'); } catch (err) { return; }
    raiz._inv.elegir(r.invitados || []);
  });

  /* ---------- Guardas del asistente de la agenda (agenda.php) ---------- */
  document.querySelectorAll('form').forEach((form) => {
    const futuro = form.querySelector('[data-ag-futuro]');
    const repite = form.querySelector('[data-ag-repite]');
    if (!futuro && !repite) return;
    const inicio = form.querySelector('input[name="inicio"]');
    const fijar = (g, ok) => { if (g && g.checked !== ok) { g.checked = ok; avisar(g); } };
    const revisar = () => {
      const v = (inicio && inicio.value) || '';
      const m = v.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
      // Diez minutos de gracia, como actions.php (reunionNoPasada). Al editar, la hora que ya tenía vale
      const ts = m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).getTime() : 0;
      const original = form.dataset.agInicio || '';
      fijar(futuro, !!ts && (ts > Date.now() - 600000 || (original !== '' && v.slice(0, 16) === original.slice(0, 16))));
      const chk = form.querySelector('input[name="recurrente"]');
      if (repite) {
        const dias = form.querySelectorAll('input[name="dias[]"]:checked').length;
        const hasta = form.querySelector('input[name="hasta"]')?.value || '';
        fijar(repite, !chk || !chk.checked || (dias > 0 && hasta !== '' && (!m || hasta >= v.slice(0, 10))));
      }
    };
    form.addEventListener('change', (e) => { if (!e.target.matches?.('[data-ag-futuro], [data-ag-repite]')) revisar(); });
    form.addEventListener('input', (e) => { if (e.target.name === 'inicio') revisar(); });
    // Antes de que el asistente valide (Siguiente, un paso, Crear): la hora pudo pasar con el modal abierto
    form.addEventListener('click', (e) => { if (e.target.closest('.wz-siguiente, .wz-guardar, .wz-paso')) revisar(); }, true);
    [futuro, repite].forEach((g) => g?.addEventListener('focus', () => {
      const ir = g === futuro ? form.querySelector('.hp .md-trigger, .hp-op:not([aria-disabled])') : form.querySelector('.repetir-caja .dia-chip');
      ir?.focus?.();
    }));
    const dlg = form.closest('dialog');
    if (dlg) {
      new MutationObserver(() => {
        if (!dlg.open) return;
        // Al abrir para editar: la hora guardada (aunque ya haya empezado) no se juzga, como en actions.php
        setTimeout(() => { form.dataset.agInicio = form.querySelector('#er-id') ? (inicio?.value || '') : ''; revisar(); }, 0);
      }).observe(dlg, { attributes: true, attributeFilter: ['open'] });
    }
    revisar();
  });

  /* ---------- Abrir al cargar (agenda.php?nueva=1&tema=…&invitados=…) ---------- */
  const abrirAlCargar = () => {
    document.querySelectorAll('dialog[data-abrir-al-cargar]').forEach((d) => { if (!d.open) d.showModal(); });
    const params = new URLSearchParams(location.search);
    if (!params.has('nueva')) return;
    ['nueva', 'tema', 'invitados', 'p'].forEach((k) => params.delete(k));
    const qs = params.toString();
    history.replaceState(history.state, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', abrirAlCargar);
  else abrirAlCargar();
})();
