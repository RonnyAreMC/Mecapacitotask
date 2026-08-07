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
            <span>Usuario(s) de Git</span>
            <div class="input-prefijo">
              <i class="fa-brands fa-github"></i>
              <input class="input-meca" name="git_user" maxlength="200" placeholder="usuario-github, otro-usuario">
            </div>
            <small class="campo-ayuda">Su usuario de GitHub/GitLab. Si usa varios, sepáralos con coma.</small>
          </label>
          <div class="campo">
            <span>Correos de Git (para cruzar sus commits)</span>
            <div class="git-emails" data-git-emails>
              <div class="git-email-fila">
                <div class="input-prefijo">
                  <i class="fa-solid fa-envelope"></i>
                  <input class="input-meca" type="text" inputmode="email" name="git_emails[]" maxlength="80" placeholder="correo-github@ejemplo.com">
                </div>
                <button type="button" class="accion-btn accion-peligro git-email-quitar" title="Quitar"><i class="fa-solid fa-xmark"></i></button>
              </div>
            </div>
            <button type="button" class="btn-ghost btn-meca btn-sm git-email-agregar"><i class="fa-solid fa-plus"></i> Agregar otro correo</button>
            <small class="campo-ayuda">El <b>correo</b> con el que commitea en cada cuenta (GitHub, GitLab…). Agrega uno por cuenta: es lo más seguro para contarle sus commits, porque el usuario cambia entre máquinas.</small>
          </div>
          <label class="campo">
            <span>Equipo</span>
            <?= UI::select('equipo', $opcionesEquipo, $eqActual) ?>
          </label>
        </div>
        <label class="campo">
          <span>Correo (para notificarle sus tareas y para entrar al panel)</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-envelope"></i>
            <input class="input-meca" type="email" name="email" maxlength="80" placeholder="nombre@innotech-solutions.com.ec">
          </div>
        </label>
        <div class="campo-doble">
          <label class="campo">
            <span>Acceso al panel</span>
            <?= UI::select('acceso', Auth::ROLES, 'lector', false, 'js-acceso') ?>
            <small class="campo-ayuda">"Solo lectura" ve todo pero no edita nada.</small>
          </label>
          <label class="campo">
            <span>Contraseña <?= $esEdicion ? '(dejar vacío para no cambiarla)' : '(opcional)' ?></span>
            <div class="input-prefijo">
              <i class="fa-solid fa-lock"></i>
              <input class="input-meca" type="password" name="clave" minlength="6" autocomplete="new-password" placeholder="mínimo 6 caracteres">
            </div>
          </label>
        </div>
        <div class="campo">
          <span>Color del avatar</span>
          <?= UI::colorPicker($esEdicion ? null : 0) ?>
          <small class="campo-ayuda">El círculo punteado <i class="fa-solid fa-palette"></i> permite elegir cualquier color.</small>
        </div>
        <?php
    }
}
