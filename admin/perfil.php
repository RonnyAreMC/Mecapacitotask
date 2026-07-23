<?php
/**
 * Mi perfil: cada quien edita SUS datos (nombre, rol, cuentas, foto y
 * contrasena) sin pasar por un administrador.
 *
 * Lo que NO se toca desde aqui, a proposito: el nivel de acceso y el
 * equipo. Los decide un administrador desde Equipo, si no cualquiera se
 * daria permisos de admin editando su propia ficha.
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

// Mis tareas, para el resumen de la cabecera
$finales  = Catalogo::estadosFinales();
$misTareas = array_filter((new TareaRepo())->todas(), fn($t) => TareaRepo::tieneAsignado($t, $miId));
$abiertas  = count(array_filter($misTareas, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
$misProyectos = [];
foreach ($misTareas as $t) {
    $misProyectos[(int)$t['proyecto_id']] = true;
}

$pasos = [
    ['Datos',     'Cómo te ves en el panel',   'Tu nombre y el rol que ocupas en el equipo.'],
    ['Cuentas',   'Git y correo',              'Con cualquiera de los dos puedes entrar al panel.'],
    ['Aspecto',   'Foto y color',              'Tu avatar en tableros, tareas y reuniones.'],
    ['Seguridad', 'Tu contraseña',             'Para cambiarla hay que escribir la actual.'],
    ['Revisión',  'Revisa antes de guardar',   'Un vistazo a lo que se va a guardar.'],
];

UI::inicio('Mi perfil', 'perfil');
?>

<header class="colab-hero card-base" style="--av-c1:<?= $c1 ?>">
  <div class="colab-hero-main">
    <div class="mc-avatar-zone">
      <span class="mc-ring-anim"></span>
      <div class="mc-avatar-ring"><?= UI::avatar($yo, 104) ?></div>
    </div>
    <div class="colab-id">
      <h1 class="font-display"><?= e($yo['nombre']) ?></h1>
      <p class="mc-rol" style="--av-c1:<?= $c1 ?>">
        <i class="fa-solid <?= e($eqIcono) ?>"></i> <?= e($yo['rol']) ?: 'Sin rol' ?> · <?= e($eqLabel) ?>
      </p>
      <div class="colab-cuentas">
        <span class="cuenta-chip cuenta-txt">
          <i class="fa-solid fa-shield-halved"></i>
          <?= e(Auth::ROLES[Auth::rol()] ?? 'Solo lectura') ?>
        </span>
        <?php if (!empty($yo['email'])): ?>
        <span class="cuenta-chip">
          <a href="mailto:<?= e($yo['email']) ?>"><i class="fa-solid fa-envelope"></i> <?= e($yo['email']) ?></a>
          <button type="button" class="btn-copiar mini" data-copiar="<?= e($yo['email']) ?>" title="Copiar correo"><i class="fa-regular fa-copy"></i></button>
        </span>
        <?php endif; ?>
        <span class="cuenta-chip <?= $tieneClave ? 'cuenta-txt' : 'cuenta-off' ?>">
          <i class="fa-solid <?= $tieneClave ? 'fa-lock' : 'fa-lock-open' ?>"></i>
          <?= $tieneClave ? 'Contraseña puesta' : 'Todavía sin contraseña' ?>
        </span>
      </div>
    </div>
  </div>
</header>

<section class="stats-grid">
  <?= UI::stat('fa-list-check', '#F7931E', (string)$abiertas, 'Tareas abiertas') ?>
  <?= UI::stat('fa-layer-group', '#2B76F7', (string)count($misTareas), 'Tareas asignadas') ?>
  <?= UI::stat('fa-folder-open', $c1, (string)count($misProyectos), 'Proyectos') ?>
</section>

<section class="card-base perfil-card">
  <form method="post" action="actions.php" class="wz wz-inline form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="perfil_guardar">

    <aside class="wz-riel">
      <div class="persona-preview pp-riel">
        <label class="pp-avatar" title="Cambiar foto">
          <input type="file" name="foto" class="pp-file" accept="image/png,image/jpeg,image/webp,image/gif">
          <span class="avatar pp-avatar-circle" style="--sz:88px;--av-c1:<?= $c1 ?>">
            <img class="pp-img" alt="" <?= empty($yo['foto']) ? 'hidden' : 'src="' . e($yo['foto']) . '"' ?>>
            <span class="pp-iniciales" <?= empty($yo['foto']) ? '' : 'hidden' ?>><?= e(MiembroRepo::iniciales($yo)) ?></span>
          </span>
          <span class="pp-cam"><i class="fa-solid fa-camera"></i></span>
        </label>
        <div class="pp-info">
          <b class="pp-nombre font-display">—</b>
          <span class="pp-rol"><i class="fa-solid fa-code"></i> <span class="pp-rol-texto">Rol</span></span>
          <span class="pp-git"><i class="fa-brands fa-github"></i> @<span class="pp-git-user">usuario</span></span>
        </div>
      </div>

      <div class="wz-pasos">
        <?php foreach ($pasos as $i => [$corto, $tituloPaso, $ayuda]): ?>
        <button type="button" class="wz-paso" data-titulo="<?= e($tituloPaso) ?>" data-ayuda="<?= e($ayuda) ?>">
          <span class="wz-num"><?= $i + 1 ?></span>
          <span class="wz-txt"><b><?= e($corto) ?></b><small><?= e($ayuda) ?></small></span>
        </button>
        <?php endforeach; ?>
      </div>
    </aside>

    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
      </header>

      <!-- 1. Datos -->
      <section class="wz-panel">
        <label class="campo"><span>Nombre *</span>
          <input class="input-meca" name="nombre" required maxlength="60" value="<?= e($yo['nombre']) ?>">
        </label>
        <label class="campo"><span>Rol</span>
          <input class="input-meca" name="rol" maxlength="40" list="lista-roles" value="<?= e($yo['rol'] ?? '') ?>" placeholder="Frontend Dev, Backend Dev...">
        </label>
        <p class="campo-ayuda">
          <i class="fa-solid fa-circle-info"></i>
          Tu <b>equipo</b> (<?= e($eqLabel) ?>) y tu <b>nivel de acceso</b> los cambia un administrador desde Equipo.
        </p>
      </section>

      <!-- 2. Cuentas -->
      <section class="wz-panel">
        <label class="campo">
          <span>Usuario de Git</span>
          <div class="input-prefijo">
            <i class="fa-brands fa-github"></i>
            <input class="input-meca" name="git_user" maxlength="40" value="<?= e($yo['git_user'] ?? '') ?>" placeholder="usuario-github">
          </div>
          <small class="campo-ayuda">Sirve para entrar al panel y para reconocer tus commits.</small>
        </label>
        <label class="campo">
          <span>Correo</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-envelope"></i>
            <input class="input-meca" type="email" name="email" maxlength="80" value="<?= e($yo['email'] ?? '') ?>" placeholder="nombre@mecapacito.com">
          </div>
          <small class="campo-ayuda">
            Ahí llegan los avisos de tus tareas<?= $googleOn ? ' y con él entras con «Continuar con Google»' : '' ?>.
            No puede repetirse con el de otra persona.
          </small>
        </label>
      </section>

      <!-- 3. Aspecto -->
      <section class="wz-panel">
        <div class="campo" data-sin-resumen>
          <span>Foto</span>
          <p class="campo-ayuda"><i class="fa-solid fa-camera"></i> Toca el avatar de la izquierda para cambiarla. JPG, PNG, WebP o GIF.</p>
        </div>
        <div class="campo" data-sin-resumen>
          <span>Color del avatar</span>
          <?= UI::colorPicker($yo['color'] ?? 0) ?>
          <small class="campo-ayuda">Se usa cuando no tienes foto y para el borde de tu avatar.</small>
        </div>
      </section>

      <!-- 4. Seguridad -->
      <section class="wz-panel">
        <?php if ($tieneClave): ?>
        <label class="campo" data-sin-resumen>
          <span>Contraseña actual</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-lock"></i>
            <input class="input-meca" type="password" name="clave_actual" autocomplete="current-password" placeholder="La que usas hoy">
          </div>
          <small class="campo-ayuda">Solo hace falta si vas a cambiar la contraseña.</small>
        </label>
        <?php else: ?>
        <p class="campo-ayuda perfil-aviso">
          <i class="fa-solid fa-triangle-exclamation"></i>
          Todavía no tienes contraseña: entras con Google. Si pones una, podrás entrar también con tu correo o tu usuario de Git.
        </p>
        <?php endif; ?>

        <div class="campo-doble">
          <label class="campo" data-sin-resumen>
            <span>Contraseña nueva</span>
            <div class="input-prefijo">
              <i class="fa-solid fa-key"></i>
              <input class="input-meca" type="password" name="clave_nueva" minlength="6" autocomplete="new-password" placeholder="mínimo 6 caracteres">
            </div>
          </label>
          <label class="campo" data-sin-resumen>
            <span>Repetir la nueva</span>
            <div class="input-prefijo">
              <i class="fa-solid fa-key"></i>
              <input class="input-meca" type="password" name="clave_repetir" minlength="6" autocomplete="new-password" placeholder="otra vez">
            </div>
          </label>
        </div>
        <p class="campo-ayuda">Deja las dos vacías si no quieres cambiarla.</p>
      </section>

      <!-- 5. Revisión -->
      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
        </div>
      </div>
    </div>
  </form>
</section>

<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?><option value="<?= e($rol) ?>"></option><?php endforeach; ?>
</datalist>

<?php UI::fin(); ?>
