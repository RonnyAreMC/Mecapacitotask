/* =========================================================
   Pantalla de entrada (login y crear cuenta).
   - Las ilustraciones de la izquierda se turnan cada pocos segundos
     (con un fundido suave; sin movimiento si el sistema lo pide).
     Los puntos eligen una. Al pasar el cursor se detiene.
   - El ojo de los campos de contraseña la muestra u oculta.
   ========================================================= */
(() => {
  const car = document.querySelector('[data-ent-carrusel]');
  if (car) {
    const escenas = [...car.querySelectorAll('.ent-escena')];
    const puntos = [...document.querySelectorAll('[data-ent-punto]')];
    const quieto = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    let actual = 0;
    let reloj = null;

    const ir = (n) => {
      actual = (n + escenas.length) % escenas.length;
      escenas.forEach((e, i) => {
        e.classList.toggle('is-activa', i === actual);
        if (i === actual) e.removeAttribute('aria-hidden'); else e.setAttribute('aria-hidden', 'true');
      });
      puntos.forEach((p, i) => {
        p.classList.toggle('is-activo', i === actual);
        if (i === actual) p.setAttribute('aria-current', 'true'); else p.removeAttribute('aria-current');
      });
    };
    const parar = () => { clearInterval(reloj); reloj = null; };
    const seguir = () => {
      parar();
      if (quieto || escenas.length < 2) return;
      reloj = setInterval(() => { if (!document.hidden) ir(actual + 1); }, 6500);
    };

    puntos.forEach((p, i) => p.addEventListener('click', () => { ir(i); seguir(); }));
    car.addEventListener('mouseenter', parar);
    car.addEventListener('mouseleave', seguir);
    seguir();
  }

  document.querySelectorAll('[data-ver-clave]').forEach((b) => {
    b.addEventListener('click', () => {
      const campo = b.parentElement.querySelector('input');
      if (!campo) return;
      const ver = campo.type === 'password';
      campo.type = ver ? 'text' : 'password';
      b.classList.toggle('is-viendo', ver);
      b.setAttribute('aria-pressed', ver ? 'true' : 'false');
      b.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
      campo.focus();
    });
  });
})();
