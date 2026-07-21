<?php
/**
 * Campos compartidos del formulario de persona (crear/editar) con
 * vista previa en vivo estilo card. Usado por equipo.php y colaborador.php.
 */
if (!function_exists('camposPersona')) {
    function camposPersona(bool $esEdicion, string $eqActual, array $equipos): void
    {
        $opcionesEquipo = array_map(fn($v) => $v[0], $equipos);
        ?>
        <div class="persona-preview">
          <label class="pp-avatar" title="<?= $esEdicion ? 'Cambiar foto' : 'Subir foto' ?>">
            <input type="file" name="foto" class="pp-file" accept="image/png,image/jpeg,image/webp,image/gif">
            <span class="avatar pp-avatar-circle" style="--sz:104px;--av-c1:<?= Catalogo::COLORES[0] ?>">
              <img class="pp-img" alt="" hidden>
              <span class="pp-iniciales">?</span>
            </span>
            <span class="pp-cam"><i class="fa-solid fa-camera"></i></span>
          </label>
          <div class="pp-info">
            <b class="pp-nombre font-display"><?= $esEdicion ? '—' : 'Nuevo colaborador' ?></b>
            <span class="pp-rol"><i class="fa-solid fa-code"></i> <span class="pp-rol-texto">Rol del equipo</span></span>
            <span class="pp-git"><i class="fa-brands fa-github"></i> @<span class="pp-git-user">usuario</span></span>
            <small class="campo-ayuda pp-ayuda"><i class="fa-solid fa-camera"></i> Toca el avatar para <?= $esEdicion ? 'cambiar la foto' : 'subir una foto' ?></small>
          </div>
        </div>

        <div class="campo-doble">
          <label class="campo"><span>Nombre *</span>
            <input class="input-meca" name="nombre" required maxlength="60" placeholder="Nombre y apellido">
          </label>
          <label class="campo"><span>Rol</span>
            <input class="input-meca" name="rol" maxlength="40" list="lista-roles" placeholder="Frontend Dev, Backend Dev...">
          </label>
        </div>
        <div class="campo-doble">
          <label class="campo">
            <span>Usuario de Git</span>
            <div class="input-prefijo">
              <i class="fa-brands fa-github"></i>
              <input class="input-meca" name="git_user" maxlength="40" placeholder="usuario-github">
            </div>
          </label>
          <label class="campo">
            <span>Equipo</span>
            <?= UI::select('equipo', $opcionesEquipo, $eqActual) ?>
          </label>
        </div>
        <label class="campo">
          <span>Correo (para notificarle sus tareas)</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-envelope"></i>
            <input class="input-meca" type="email" name="email" maxlength="80" placeholder="nombre@mecapacito.com">
          </div>
        </label>
        <div class="campo">
          <span>Color del avatar</span>
          <?= UI::colorPicker($esEdicion ? null : 0) ?>
          <small class="campo-ayuda">El círculo punteado <i class="fa-solid fa-palette"></i> permite elegir cualquier color.</small>
        </div>
        <?php
    }
}
