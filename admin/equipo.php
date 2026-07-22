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
    '<button class="btn-primary btn-meca solo-admin" onclick="document.getElementById(\'dlg-nuevo-miembro\').showModal()">
       <i class="fa-solid fa-user-plus"></i> Agregar colaborador
     </button>'
);
?>

<?php if (empty($equipo)): ?>
  <?= UI::vacio($eqIcono, 'El equipo de ' . mb_strtolower($eqLabel) . ' está vacío', 'Agrega al primer colaborador con el botón de arriba.') ?>
<?php else: ?>

  <!-- Tabla de colaboradores: vision general con git/correo copiables -->
  <div class="card-base tabla-card">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid <?= e($eqIcono) ?> text-secondary"></i> Colaboradores
        <span class="tabla-count"><?= count($equipo) ?></span>
      </h2>
      <span class="ajuste-ayuda"><i class="fa-regular fa-copy"></i> copia el usuario o correo · <i class="fa-solid fa-eye"></i> abre su ficha.</span>
    </div>
    <div class="tabla-scroll">
      <table class="tabla-meca tabla-equipo">
        <thead>
          <tr>
            <th>Colaborador</th>
            <th><i class="fa-brands fa-github"></i> Usuario de Git</th>
            <th><i class="fa-solid fa-envelope"></i> Correo</th>
            <th>Abiertas</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($equipo as $m):
              $c1 = Catalogo::colorDe($m['color'] ?? 0);
              $mid = (int)$m['id'];
              $pendientes = $abiertas[$mid] ?? 0;
              $ficha = 'colaborador.php?id=' . $mid;
          ?>
          <tr class="fila-colab" style="--av-c1:<?= $c1 ?>" onclick="if(!event.target.closest('.btn-copiar'))location.href='<?= $ficha ?>'">
            <td>
              <div class="celda-persona">
                <?= UI::avatar($m, 38) ?>
                <div class="cp-info">
                  <span><?= e($m['nombre']) ?></span>
                  <small><?= e($m['rol']) ?></small>
                </div>
              </div>
            </td>
            <td>
              <?php if (!empty($m['git_user'])): ?>
              <span class="chip-copiar">
                <code><i class="fa-brands fa-github"></i> @<?= e($m['git_user']) ?></code>
                <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e($m['git_user']) ?>" title="Copiar usuario de Git">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </span>
              <?php else: ?><span class="celda-muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($m['email'])): ?>
              <span class="chip-copiar">
                <code><i class="fa-solid fa-envelope"></i> <?= e($m['email']) ?></code>
                <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e($m['email']) ?>" title="Copiar correo">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </span>
              <?php else: ?><span class="celda-muted">—</span><?php endif; ?>
            </td>
            <td><span class="pr-chip" title="Tareas abiertas"><?= $pendientes ?></span></td>
            <td class="celda-acciones">
              <a class="accion-btn" href="<?= $ficha ?>" title="Ver ficha"><i class="fa-solid fa-eye"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/lib/campos_persona.php'; ?>

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
