<?php
/**
 * Ficha del colaborador: datos, cuentas (git/correo) y todas sus tareas.
 * Pantalla propia a la que se llega desde la tabla de equipo.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$miembrosRepo = new MiembroRepo();
$tareasRepo   = new TareaRepo();

$id = (int)($_GET['id'] ?? 0);
$m  = $miembrosRepo->buscar($id);
if (!$m) {
    redirigir('equipo.php', 'Ese colaborador no existe.', 'error');
}
// La ficha lista las tareas de la persona en todos los proyectos: quien no es
// administrador solo puede abrir la suya.
if (!esAdmin() && $id !== (int)(Auth::usuario()['id'] ?? 0)) {
    redirigir('equipo.php?e=' . MiembroRepo::equipoDe($m), 'Solo puedes abrir tu propia ficha.', 'error');
}

$eq = MiembroRepo::equipoDe($m);
[$eqLabel, $eqIcono] = Catalogo::equipos()[$eq];
$c1 = Catalogo::colorDe($m['color'] ?? 0);
$finales = Catalogo::estadosFinales();

$nombresProyecto = [];
$colorProyecto   = [];
foreach ((new ProyectoRepo())->todos() as $p) {
    $nombresProyecto[(int)$p['id']] = $p['nombre'];
    $colorProyecto[(int)$p['id']]   = ProyectoRepo::colorBase($p);
}

// Todas las tareas del colaborador (abiertas primero)
$susTareas = array_values(array_filter($tareasRepo->todas(), fn($t) => (int)($t['asignado_id'] ?? 0) === $id));
usort($susTareas, function ($a, $b) use ($finales) {
    $fa = in_array($a['estado'] ?? '', $finales, true) ? 1 : 0;
    $fb = in_array($b['estado'] ?? '', $finales, true) ? 1 : 0;
    return $fa <=> $fb;
});
$abiertas = count(array_filter($susTareas, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
$totales  = count($susTareas);
$hechas   = $totales - $abiertas;

// Proyectos en los que participa
$susProyectos = [];
foreach ($susTareas as $t) {
    $susProyectos[(int)$t['proyecto_id']] = true;
}

UI::inicio('Ficha · ' . $m['nombre'], 'equipo-' . $eq);
?>

<header class="colab-hero card-base" style="--av-c1:<?= $c1 ?>">
  <a href="equipo.php?e=<?= e($eq) ?>" class="colab-back"><i class="fa-solid fa-arrow-left"></i> <?= e($eqLabel) ?></a>

  <div class="colab-hero-main">
    <div class="mc-avatar-zone">
      <span class="mc-ring-anim"></span>
      <div class="mc-avatar-ring"><?= UI::avatar($m, 104) ?></div>
    </div>
    <div class="colab-id">
      <h1 class="font-display"><?= e($m['nombre']) ?></h1>
      <p class="mc-rol" style="--av-c1:<?= $c1 ?>"><i class="fa-solid <?= e($eqIcono) ?>"></i> <?= e($m['rol']) ?> · <?= e($eqLabel) ?></p>
      <div class="colab-cuentas">
        <?php if (!empty($m['git_user'])): ?>
        <span class="cuenta-chip">
          <a href="https://github.com/<?= e($m['git_user']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> @<?= e($m['git_user']) ?></a>
          <button type="button" class="btn-copiar mini" data-copiar="<?= e($m['git_user']) ?>" title="Copiar usuario de Git"><i class="fa-regular fa-copy"></i></button>
        </span>
        <?php endif; ?>
        <?php if (!empty($m['email'])): ?>
        <span class="cuenta-chip">
          <a href="mailto:<?= e($m['email']) ?>"><i class="fa-solid fa-envelope"></i> <?= e($m['email']) ?></a>
          <button type="button" class="btn-copiar mini" data-copiar="<?= e($m['email']) ?>" title="Copiar correo"><i class="fa-regular fa-copy"></i></button>
        </span>
        <?php else: ?>
        <span class="cuenta-chip cuenta-off"><i class="fa-solid fa-envelope-circle-check"></i> Sin correo registrado</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="colab-acciones">
      <button class="btn-outline btn-meca btn-sm solo-admin" title="Editar"
        data-editar-miembro='<?= e(json_encode([
            'id' => $id, 'nombre' => $m['nombre'], 'rol' => $m['rol'],
            'git_user' => $m['git_user'], 'email' => $m['email'] ?? '',
            'color' => $m['color'] ?? 0, 'foto' => $m['foto'] ?? '', 'equipo' => $eq,
        ], JSON_UNESCAPED_UNICODE)) ?>'>
        <i class="fa-solid fa-pen"></i> Editar
      </button>
      <form method="post" action="actions.php" class="inline-form solo-admin"
            data-confirmar="<?= e($m['nombre']) ?> saldrá del equipo y sus tareas quedarán sin asignar."
            data-confirmar-titulo="¿Retirar del equipo?" data-confirmar-ok="Sí, retirar">
        <input type="hidden" name="accion" value="miembro_eliminar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-outline btn-meca btn-sm btn-peligro"><i class="fa-solid fa-user-minus"></i></button>
      </form>
    </div>
  </div>
</header>

<section class="stats-grid">
  <?= UI::stat('fa-list-check', '#F7931E', (string)$abiertas, 'Tareas abiertas') ?>
  <?= UI::stat('fa-circle-check', '#2BB673', (string)$hechas, 'Completadas') ?>
  <?= UI::stat('fa-layer-group', '#2B76F7', (string)$totales, 'Asignadas') ?>
  <?= UI::stat('fa-folder-open', $c1, (string)count($susProyectos), 'Proyectos') ?>
</section>

<section class="card-base tabla-card">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Sus tareas
      <span class="tabla-count"><?= $totales ?></span>
    </h2>
  </div>
  <?php if (empty($susTareas)): ?>
    <?= UI::vacio('fa-mug-hot', 'Sin tareas asignadas', $m['nombre'] . ' no tiene tareas en ningún proyecto todavía.') ?>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="tabla-meca">
      <thead>
        <tr>
          <th>Tarea</th>
          <th>Proyecto</th>
          <th>Prioridad</th>
          <th>Estado</th>
          <th>Fecha límite</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($susTareas as $t):
            $pid = (int)$t['proyecto_id'];
            $esFinal = in_array($t['estado'] ?? '', $finales, true);
        ?>
        <tr class="<?= $esFinal ? 'fila-hecha' : '' ?>">
          <td class="celda-tarea">
            <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
            <div><b><?= e($t['titulo']) ?></b></div>
          </td>
          <td>
            <a class="chip-proyecto" href="proyecto.php?id=<?= $pid ?>" style="--pc:<?= e($colorProyecto[$pid] ?? '#2B76F7') ?>">
              <i class="fa-regular fa-folder"></i> <?= e($nombresProyecto[$pid] ?? '—') ?>
            </a>
          </td>
          <td><?= UI::badgePrioridad($t['prioridad'] ?? 'media') ?></td>
          <td><?= UI::badgeEstadoTarea($t['estado'] ?? '') ?></td>
          <td>
            <?php if (!empty($t['fecha_limite'])): ?>
              <span class="celda-fecha"><i class="fa-regular fa-calendar"></i> <?= e($t['fecha_limite']) ?></span>
            <?php else: ?><span class="celda-fecha celda-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Modal editar colaborador (reutiliza el form de equipo via JS global) -->
<dialog id="dlg-editar-miembro" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="miembro_editar">
    <input type="hidden" name="id" id="em-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-pen text-secondary"></i> Editar colaborador</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <?php
    // Reutiliza el mismo componente de campos del equipo
    require_once __DIR__ . '/lib/campos_persona.php';
    camposPersona(true, $eq, Catalogo::equipos());
    ?>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </footer>
  </form>
</dialog>
<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?><option value="<?= e($rol) ?>"></option><?php endforeach; ?>
</datalist>

<?php UI::fin(); ?>
