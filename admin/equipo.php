<?php
/**
 * Equipo: colaboradores con usuario de Git, foto y rol.
 * Soporta varios equipos (Programacion, Analistas, ...) via ?e=<clave>.
 * Los equipos son un catalogo parametrizable en Ajustes.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$miembrosRepo = new MiembroRepo();
$tareasRepo   = new TareaRepo();

$equipos = Catalogo::equipos();
$eq = MiembroRepo::equipoValido($_GET['e'] ?? '');
[$eqLabel, $eqIcono] = $equipos[$eq];

$equipo = array_values(array_filter(
    $miembrosRepo->todos(),
    fn($m) => MiembroRepo::equipoDe($m) === $eq
));

// Tareas por miembro: abiertas (no finales), total asignadas y detalle
$finales  = Catalogo::estadosFinales();
$abiertas = [];
$asignadas = [];
$tareasDe = [];
$nombresProyecto = [];
foreach ((new ProyectoRepo())->todos() as $p) {
    $nombresProyecto[(int)$p['id']] = $p['nombre'];
}
foreach ($tareasRepo->todas() as $t) {
    $mid = (int)($t['asignado_id'] ?? 0);
    if (!$mid) continue;
    $asignadas[$mid] = ($asignadas[$mid] ?? 0) + 1;
    if (!in_array($t['estado'] ?? '', $finales, true)) {
        $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
        $tareasDe[$mid][] = $t;
    }
}

UI::inicio('Equipo ' . $eqLabel, 'equipo-' . $eq);
UI::cabecera(
    'Equipo de <span class="text-secondary">' . e(mb_strtolower($eqLabel)) . '</span>',
    'Colaboradores del equipo, sus usuarios de Git y sus fotos.',
    '<button class="btn-primary btn-meca" onclick="document.getElementById(\'dlg-nuevo-miembro\').showModal()">
       <i class="fa-solid fa-user-plus"></i> Agregar colaborador
     </button>'
);
?>

<?php if (empty($equipo)): ?>
  <?= UI::vacio($eqIcono, 'El equipo de ' . mb_strtolower($eqLabel) . ' está vacío', 'Agrega al primer colaborador con el botón de arriba.') ?>
<?php else: ?>
<section class="equipo-master-detail" data-equipo="<?= e($eq) ?>">

  <!-- Lista compacta: selecciona a alguien para ver su card -->
  <div class="equipo-lista card-base">
    <div class="el-head">
      <i class="fa-solid <?= e($eqIcono) ?> text-secondary"></i>
      <span><?= count($equipo) ?> colaborador<?= count($equipo) === 1 ? '' : 'es' ?></span>
    </div>
    <?php foreach ($equipo as $idx => $m):
        $c1 = Catalogo::colorDe($m['color'] ?? 0);
        $mid = (int)$m['id'];
        $pendientes = $abiertas[$mid] ?? 0;
    ?>
    <button type="button" class="persona-row <?= $idx === 0 ? 'active' : '' ?>"
            data-persona="<?= $mid ?>" style="--av-c1:<?= $c1 ?>">
      <?= UI::avatar($m, 42) ?>
      <span class="pr-info">
        <b><?= e($m['nombre']) ?></b>
        <small><?= e($m['rol']) ?></small>
      </span>
      <span class="pr-chip" title="Tareas abiertas"><?= $pendientes ?></span>
      <i class="fa-solid fa-chevron-right pr-flecha"></i>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Detalle: la card del colaborador seleccionado -->
  <div class="equipo-detalle">
    <?php foreach ($equipo as $idx => $m):
        $c1 = Catalogo::colorDe($m['color'] ?? 0);
        $mid        = (int)$m['id'];
        $pendientes = $abiertas[$mid] ?? 0;
        $totales    = $asignadas[$mid] ?? 0;
        $misTareas  = $tareasDe[$mid] ?? [];
    ?>
    <article class="persona-card card-base" data-persona-card="<?= $mid ?>"
             style="--av-c1:<?= $c1 ?>" <?= $idx === 0 ? '' : 'hidden' ?>>
      <div class="pc-head">
        <div class="mc-avatar-zone">
          <span class="mc-ring-anim"></span>
          <div class="mc-avatar-ring"><?= UI::avatar($m, 92) ?></div>
        </div>
        <div class="pc-id">
          <h2 class="font-display"><?= e($m['nombre']) ?></h2>
          <p class="mc-rol"><i class="fa-solid <?= e($eqIcono) ?>"></i> <?= e($m['rol']) ?></p>
          <span class="pc-chips">
            <a class="mc-git" href="https://github.com/<?= e($m['git_user']) ?>" target="_blank" rel="noopener">
              <i class="fa-brands fa-github"></i> @<?= e($m['git_user']) ?: 'sin-usuario' ?>
            </a>
            <?php if (!empty($m['email'])): ?>
            <a class="mc-git" href="mailto:<?= e($m['email']) ?>" title="Enviar correo">
              <i class="fa-solid fa-envelope"></i> <?= e($m['email']) ?>
            </a>
            <?php else: ?>
            <span class="mc-git mc-git-off" title="Sin correo: no recibirá notificaciones">
              <i class="fa-solid fa-envelope-circle-check"></i> sin correo
            </span>
            <?php endif; ?>
          </span>
        </div>
        <div class="pc-stats">
          <span class="mc-stat">
            <b class="font-display"><?= $pendientes ?></b>
            <small>abierta<?= $pendientes === 1 ? '' : 's' ?></small>
          </span>
          <span class="mc-stat">
            <b class="font-display"><?= $totales ?></b>
            <small>asignada<?= $totales === 1 ? '' : 's' ?></small>
          </span>
        </div>
      </div>

      <div class="pc-tareas">
        <h3><i class="fa-solid fa-list-check"></i> Tareas abiertas</h3>
        <?php if (empty($misTareas)): ?>
          <p class="pc-sin-tareas"><i class="fa-solid fa-mug-hot"></i> Sin tareas abiertas. ¡Todo al día!</p>
        <?php else: ?>
        <ul>
          <?php foreach (array_slice($misTareas, 0, 4) as $t): ?>
          <li>
            <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
            <a href="proyecto.php?id=<?= (int)$t['proyecto_id'] ?>" class="pc-tarea-titulo"><?= e($t['titulo']) ?></a>
            <?= UI::badgeEstadoTarea($t['estado'] ?? '') ?>
            <small class="pc-tarea-proyecto"><i class="fa-regular fa-folder"></i> <?= e($nombresProyecto[(int)$t['proyecto_id']] ?? '—') ?></small>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($misTareas) > 4): ?>
          <small class="pc-mas-tareas">+ <?= count($misTareas) - 4 ?> más en sus proyectos</small>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <footer class="pc-acciones">
        <button class="accion-btn" title="Editar"
          data-editar-miembro='<?= e(json_encode([
              'id' => $mid,
              'nombre' => $m['nombre'],
              'rol' => $m['rol'],
              'git_user' => $m['git_user'],
              'email' => $m['email'] ?? '',
              'color' => $m['color'] ?? 0,
              'foto' => $m['foto'] ?? '',
              'equipo' => MiembroRepo::equipoDe($m),
          ], JSON_UNESCAPED_UNICODE)) ?>'>
          <i class="fa-solid fa-pen"></i> Editar
        </button>
        <form method="post" action="actions.php" class="inline-form"
              onsubmit="return confirm('¿Retirar a <?= e($m['nombre']) ?> del equipo? Sus tareas quedarán sin asignar.')">
          <input type="hidden" name="accion" value="miembro_eliminar">
          <input type="hidden" name="id" value="<?= $mid ?>">
          <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-user-minus"></i> Retirar</button>
        </form>
      </footer>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
/**
 * Campos compartidos del formulario de persona (crear/editar) con
 * vista previa en vivo estilo card: avatar clickeable para la foto,
 * nombre, rol y chip de GitHub que se actualizan al escribir.
 */
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
?>

<!-- Modal: nuevo colaborador -->
<dialog id="dlg-nuevo-miembro" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="miembro_crear">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-plus text-secondary"></i> Nuevo colaborador</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <?php camposPersona(false, $eq, $equipos); ?>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Agregar al equipo</button>
    </footer>
  </form>
</dialog>

<!-- Modal: editar colaborador (se rellena por JS) -->
<dialog id="dlg-editar-miembro" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="miembro_editar">
    <input type="hidden" name="id" id="em-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-pen text-secondary"></i> Editar colaborador</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <?php camposPersona(true, $eq, $equipos); ?>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </footer>
  </form>
</dialog>

<!-- Roles sugeridos (parametrizables en Ajustes) -->
<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?>
  <option value="<?= e($rol) ?>"></option>
  <?php endforeach; ?>
</datalist>

<?php UI::fin(); ?>
