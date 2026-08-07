<?php
/**
 * Mi perfil: cada quien edita SUS datos con edición inline (lápiz por campo).
 * Un solo formulario; la barra de guardar aparece cuando hay cambios.
 *
 * Lo que NO se toca aquí, a propósito: el nivel de acceso y el equipo. Los
 * decide un administrador desde Equipo (si no, cualquiera se daría permisos).
 */
require_once __DIR__ . '/lib/bootstrap.php';

$yo = Auth::usuario();
if (!$yo) {
    redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
}

$miId       = (int)$yo['id'];
$eq         = MiembroRepo::equipoDe($yo);
$equipos    = Catalogo::equipos();
[$eqLabel, $eqIcono] = $equipos[$eq];
$c1         = Catalogo::colorDe($yo['color'] ?? 0);
$tieneClave = !empty($yo['pass_hash']);
$googleOn   = GoogleLogin::listo();

$finales   = Catalogo::estadosFinales();
$misTareas = array_filter((new TareaRepo())->todas(), fn($t) => TareaRepo::tieneAsignado($t, $miId));
$abiertas  = count(array_filter($misTareas, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
$misProyectos = [];
foreach ($misTareas as $t) { $misProyectos[(int)$t['proyecto_id']] = true; }

UI::inicio('Mi perfil', 'perfil');
?>

<!-- Cabecera -->
<header class="pf-hero card-base" style="--av-c1:<?= $c1 ?>">
  <label class="pf-hero-foto" title="Cambiar foto">
    <input type="file" name="foto" class="pf-file" form="perfil-form" accept="image/png,image/jpeg,image/webp,image/gif">
    <span class="avatar pf-hero-avatar" style="--sz:96px;--av-c1:<?= $c1 ?>">
      <img class="pf-img" alt="" <?= empty($yo['foto']) ? 'hidden' : 'src="' . e($yo['foto']) . '"' ?>>
      <span class="pf-iniciales" <?= empty($yo['foto']) ? '' : 'hidden' ?>><?= e(MiembroRepo::iniciales($yo)) ?></span>
    </span>
    <span class="pf-cam"><i class="fa-solid fa-camera"></i></span>
  </label>
  <div class="pf-hero-id">
    <h1 class="font-display js-hero-nombre"><?= e($yo['nombre']) ?></h1>
    <p class="pf-hero-rol" style="--av-c1:<?= $c1 ?>">
      <i class="fa-solid <?= e($eqIcono) ?>"></i> <span class="js-hero-rol"><?= e($yo['rol']) ?: 'Sin rol' ?></span> · <?= e($eqLabel) ?>
    </p>
    <div class="pf-hero-chips">
      <span class="pf-chip"><i class="fa-solid fa-shield-halved"></i> <?= e(Auth::ROLES[Auth::rol()] ?? 'Solo lectura') ?></span>
      <span class="pf-chip <?= $tieneClave ? '' : 'off' ?>"><i class="fa-solid <?= $tieneClave ? 'fa-lock' : 'fa-lock-open' ?>"></i> <?= $tieneClave ? 'Con contraseña' : 'Sin contraseña' ?></span>
    </div>
  </div>
  <div class="pf-hero-stats">
    <span><b><?= $abiertas ?></b> abiertas</span>
    <span><b><?= count($misTareas) ?></b> asignadas</span>
    <span><b><?= count($misProyectos) ?></b> proyectos</span>
  </div>
</header>

<?php if (GoogleCalendar::listo()): $calConectado = !empty($yo['gcal_refresh']); ?>
<!-- Google Calendar -->
<section class="card-base gcal-card">
  <div class="gcal-info">
    <span class="gcal-icono-svg"><img src="assets/calendar.svg" alt="Calendar" width="46" height="46"></span>
    <div>
      <h2 class="font-display">Google Calendar</h2>
      <?php if ($calConectado): ?>
        <p class="gcal-estado ok"><i class="fa-solid fa-circle-check"></i> Conectado. Tus tareas con fecha se envían a tu calendario.</p>
      <?php else: ?>
        <p class="gcal-estado"><i class="fa-solid fa-circle-info"></i> Conecta tu calendario para recibir ahí tus tareas con fecha.</p>
      <?php endif; ?>
    </div>
  </div>
  <a href="google_calendario.php" class="btn-meca <?= $calConectado ? 'btn-outline' : 'btn-primary' ?>">
    <i class="fa-brands fa-google"></i> <?= $calConectado ? 'Volver a conectar' : 'Conectar mi calendario' ?>
  </a>
</section>
<?php endif; ?>

<!-- Trabajar con Claude: descarga tus tareas para pasárselas -->
<section class="card-base pf-claude">
  <div class="pf-claude-info">
    <span class="pf-claude-ic"><img src="assets/claude.svg" alt="Claude" width="30" height="30"></span>
    <div>
      <h2 class="font-display">Trabaja con tu Claude</h2>
      <p>Descarga tus tareas en JSON y pásaselas a tu Claude: sabrá el <b>#id</b> de cada una para que tus commits se enlacen solos.</p>
    </div>
  </div>
  <div class="pf-claude-acc">
    <form method="post" action="actions.php" class="inline-form" data-descarga>
      <input type="hidden" name="accion" value="mis_tareas_json">
      <button class="btn-primary btn-meca"><i class="fa-solid fa-file-arrow-down"></i> Descargar mis tareas (JSON)</button>
    </form>
    <a href="docs.php" class="btn-outline btn-meca"><i class="fa-solid fa-book-open"></i> Ver el estándar</a>
  </div>
</section>

<form method="post" action="actions.php" id="perfil-form" class="pf-form" enctype="multipart/form-data">
  <input type="hidden" name="accion" value="perfil_guardar">

  <div class="pf-grid">
    <!-- Datos -->
    <section class="card-base pf-card">
      <h2 class="pf-card-tit"><i class="fa-solid fa-id-badge text-secondary"></i> Tus datos</h2>

      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-user"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Nombre</span>
          <span class="pf-valor js-valor"><?= e($yo['nombre']) ?></span>
          <input class="input-meca pf-input" name="nombre" required maxlength="60" value="<?= e($yo['nombre']) ?>" data-hero="nombre" hidden>
        </div>
        <button type="button" class="pf-lapiz" title="Editar nombre"><i class="fa-solid fa-pen"></i></button>
      </div>

      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-briefcase"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Rol</span>
          <span class="pf-valor js-valor"><?= e($yo['rol']) ?: '<i class="pf-vacio">Sin definir</i>' ?></span>
          <input class="input-meca pf-input" name="rol" maxlength="40" list="lista-roles" value="<?= e($yo['rol'] ?? '') ?>" placeholder="Frontend Dev, Backend Dev…" data-hero="rol" hidden>
        </div>
        <button type="button" class="pf-lapiz" title="Editar rol"><i class="fa-solid fa-pen"></i></button>
      </div>

      <p class="pf-nota"><i class="fa-solid fa-circle-info"></i> Tu <b>equipo</b> (<?= e($eqLabel) ?>) y tu <b>acceso</b> los cambia un administrador desde Equipo.</p>
    </section>

    <!-- Cuentas -->
    <section class="card-base pf-card">
      <h2 class="pf-card-tit"><i class="fa-solid fa-at text-secondary"></i> Cuentas para entrar</h2>

      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-brands fa-github"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Usuario de Git</span>
          <span class="pf-valor js-valor"><?= !empty($yo['git_user']) ? '@' . e($yo['git_user']) : '<i class="pf-vacio">Sin definir</i>' ?></span>
          <input class="input-meca pf-input" name="git_user" maxlength="40" value="<?= e($yo['git_user'] ?? '') ?>" placeholder="usuario-github" hidden>
        </div>
        <button type="button" class="pf-lapiz" title="Editar usuario de Git"><i class="fa-solid fa-pen"></i></button>
      </div>

      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-envelope"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Correo</span>
          <span class="pf-valor js-valor"><?= !empty($yo['email']) ? e($yo['email']) : '<i class="pf-vacio">Sin definir</i>' ?></span>
          <input class="input-meca pf-input" type="email" name="email" maxlength="80" value="<?= e($yo['email'] ?? '') ?>" placeholder="nombre@innotech-solutions.com.ec" hidden>
        </div>
        <button type="button" class="pf-lapiz" title="Editar correo"><i class="fa-solid fa-pen"></i></button>
      </div>

      <p class="pf-nota"><i class="fa-solid fa-circle-info"></i> Con cualquiera de los dos entras al panel<?= $googleOn ? '; con el correo, también con «Continuar con Google»' : '' ?>.</p>
    </section>

    <!-- Aspecto -->
    <section class="card-base pf-card">
      <h2 class="pf-card-tit"><i class="fa-solid fa-palette text-secondary"></i> Aspecto</h2>
      <div class="pf-fila pf-fila-color">
        <div class="pf-ic"><i class="fa-solid fa-droplet"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Color del avatar</span>
          <?= UI::colorPicker($yo['color'] ?? 0) ?>
        </div>
      </div>
      <p class="pf-nota"><i class="fa-solid fa-camera"></i> Tu foto se cambia tocando el avatar de arriba. JPG, PNG, WebP o GIF.</p>
    </section>

    <!-- Seguridad -->
    <section class="card-base pf-card">
      <h2 class="pf-card-tit"><i class="fa-solid fa-lock text-secondary"></i> Seguridad</h2>

      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid <?= $tieneClave ? 'fa-lock' : 'fa-lock-open' ?>"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Contraseña</span>
          <span class="pf-valor"><?= $tieneClave ? '••••••••' : '<i class="pf-vacio">Sin contraseña — entras con Google</i>' ?></span>
        </div>
        <button type="button" class="pf-lapiz js-clave-toggle" title="Cambiar contraseña"><i class="fa-solid fa-pen"></i></button>
      </div>

      <div class="pf-clave" hidden>
        <?php if ($tieneClave): ?>
        <label class="campo"><span>Contraseña actual</span>
          <div class="input-prefijo"><i class="fa-solid fa-lock"></i>
            <input class="input-meca" type="password" name="clave_actual" autocomplete="current-password" placeholder="La que usas hoy"></div>
        </label>
        <?php endif; ?>
        <div class="campo-doble">
          <label class="campo"><span>Nueva</span>
            <div class="input-prefijo"><i class="fa-solid fa-key"></i>
              <input class="input-meca" type="password" name="clave_nueva" minlength="6" autocomplete="new-password" placeholder="mínimo 6"></div>
          </label>
          <label class="campo"><span>Repetir</span>
            <div class="input-prefijo"><i class="fa-solid fa-key"></i>
              <input class="input-meca" type="password" name="clave_repetir" minlength="6" autocomplete="new-password" placeholder="otra vez"></div>
          </label>
        </div>
      </div>
    </section>
  </div>

  <!-- Barra de guardar: aparece al haber cambios -->
  <div class="pf-guardar" id="pf-guardar" hidden>
    <span class="pf-guardar-txt"><i class="fa-solid fa-circle-dot"></i> Tienes cambios sin guardar</span>
    <div class="pf-guardar-acc">
      <button type="button" class="btn-outline btn-meca" id="pf-descartar">Descartar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </div>
  </div>
</form>

<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?><option value="<?= e($rol) ?>"></option><?php endforeach; ?>
</datalist>

<script>
(() => {
  const form = document.getElementById('perfil-form');
  const barra = document.getElementById('pf-guardar');
  if (!form) return;

  // Edición inline: el lápiz revela el input de esa fila
  form.querySelectorAll('.pf-lapiz:not(.js-clave-toggle)').forEach((lapiz) => {
    const fila = lapiz.closest('.pf-fila');
    const input = fila.querySelector('.pf-input');
    const valor = fila.querySelector('.pf-valor');
    if (!input) return;
    const abrir = () => {
      fila.classList.add('editando');
      input.hidden = false; valor.hidden = true;
      input.focus(); input.select?.();
    };
    const cerrar = () => {
      fila.classList.remove('editando');
      input.hidden = true; valor.hidden = false;
    };
    lapiz.addEventListener('click', () => fila.classList.contains('editando') ? cerrar() : abrir());
    input.addEventListener('blur', cerrar);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); cerrar(); }
      if (e.key === 'Escape') { cerrar(); }
    });
    // Reflejar en vivo en la fila y en la cabecera
    input.addEventListener('input', () => {
      const v = input.value.trim();
      const pref = input.name === 'git_user' && v ? '@' : '';
      valor.innerHTML = v ? pref + v.replace(/[<>&]/g, '') : '<i class="pf-vacio">Sin definir</i>';
      const hero = input.dataset.hero;
      if (hero === 'nombre') document.querySelector('.js-hero-nombre').textContent = v || '—';
      if (hero === 'rol') document.querySelector('.js-hero-rol').textContent = v || 'Sin rol';
    });
  });

  // Cambiar contraseña: revela el bloque
  const claveToggle = form.querySelector('.js-clave-toggle');
  const claveBox = form.querySelector('.pf-clave');
  claveToggle?.addEventListener('click', () => {
    claveBox.hidden = !claveBox.hidden;
    claveToggle.classList.toggle('activo', !claveBox.hidden);
    if (!claveBox.hidden) claveBox.querySelector('input')?.focus();
  });

  // Barra de guardar cuando algo cambia
  const mostrar = () => barra.hidden = false;
  form.addEventListener('input', mostrar);
  form.addEventListener('change', mostrar);
  document.getElementById('pf-descartar')?.addEventListener('click', () => location.reload());
})();
</script>

<?php UI::fin(); ?>
