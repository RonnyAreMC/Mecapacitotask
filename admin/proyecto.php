<?php
/**
 * Detalle de proyecto: cabecera con avance + tablero de tareas en tabla.
 * Filtros por estado y asignado, edicion en linea del estado.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$proyectosRepo = new ProyectoRepo();
$miembrosRepo  = new MiembroRepo();
$tareasRepo    = new TareaRepo();

$id = (int)($_GET['id'] ?? 0);
$proyecto = $proyectosRepo->buscar($id);
if (!$proyecto) {
    redirigir('index.php', 'Ese proyecto no existe.', 'error');
}

$miembros = $miembrosRepo->mapa();
$tareas   = $tareasRepo->delProyecto($id);
$resumen  = $tareasRepo->resumen($id);
$avance   = $tareasRepo->avance($id);
$completadas = $tareasRepo->completadas($id);
$finales  = Catalogo::estadosFinales();
$color    = ProyectoRepo::colorBase($proyecto);

// Filtros
$fEstado   = $_GET['estado'] ?? '';
$fAsignado = (int)($_GET['asignado'] ?? 0);
$visibles = array_filter($tareas, function ($t) use ($fEstado, $fAsignado) {
    if ($fEstado !== '' && $t['estado'] !== $fEstado) return false;
    if ($fAsignado && (int)$t['asignado_id'] !== $fAsignado) return false;
    return true;
});

$opcionesMiembros = [0 => '— Sin asignar —'];
$opcionesFiltro   = [0 => 'Todo el equipo'];
foreach ($miembros as $m) {
    $opcionesMiembros[$m['id']] = $m['nombre'] . ' (@' . $m['git_user'] . ')';
    $opcionesFiltro[$m['id']]   = $m['nombre'];
}

UI::inicio($proyecto['nombre'], 'proyecto-' . $id);
?>

<!-- Cabecera del proyecto -->
<header class="proyecto-hero" style="--pc:<?= $color ?>">
  <i class="fa-solid <?= e($proyecto['icono']) ?> ph-watermark"></i>
  <div class="ph-top">
    <a href="index.php" class="ph-back"><i class="fa-solid fa-arrow-left"></i> Proyectos</a>
    <div class="ph-actions">
      <?php if (!empty($proyecto['repo'])): ?>
      <a class="btn-ghost btn-meca btn-sm" href="<?= e($proyecto['repo']) ?>" target="_blank" rel="noopener">
        <i class="fa-brands fa-github"></i> Repositorio
      </a>
      <?php endif; ?>
      <button class="btn-ghost btn-meca btn-sm" onclick="document.getElementById('dlg-editar-proyecto').showModal()">
        <i class="fa-solid fa-pen"></i> Editar
      </button>
      <form method="post" action="actions.php" onsubmit="return confirm('¿Eliminar este proyecto y TODAS sus tareas?')">
        <input type="hidden" name="accion" value="proyecto_eliminar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-ghost btn-meca btn-sm btn-peligro"><i class="fa-solid fa-trash"></i></button>
      </form>
    </div>
  </div>

  <div class="ph-main">
    <div class="ph-icon"><i class="fa-solid <?= e($proyecto['icono']) ?>"></i></div>
    <div class="ph-info">
      <div class="ph-badges">
        <?= UI::badgeEstadoProyecto($proyecto['estado']) ?>
        <span class="ph-fecha"><i class="fa-regular fa-calendar"></i> Creado <?= e($proyecto['creado'] ?? '') ?></span>
      </div>
      <h1 class="font-display"><?= e($proyecto['nombre']) ?></h1>
      <p><?= e($proyecto['descripcion']) ?></p>
    </div>
    <div class="ph-avance">
      <div class="ph-avance-num font-display"><?= $avance ?>%</div>
      <small><?= $completadas ?> de <?= array_sum($resumen) ?> tareas</small>
      <div class="progress-wrap"><div class="progress-bar" style="width:<?= $avance ?>%;background:var(--pc)"></div></div>
    </div>
  </div>
</header>

<!-- Resumen por estado (mini kanban) -->
<section class="estados-resumen">
  <?php foreach (Catalogo::estadosTarea() as $k => [$label, $icono]): ?>
  <a class="estado-tile estado-<?= $k ?> <?= $fEstado === $k ? 'tile-activo' : '' ?>"
     href="?id=<?= $id ?>&estado=<?= $fEstado === $k ? '' : $k ?><?= $fAsignado ? '&asignado=' . $fAsignado : '' ?>">
    <i class="fa-solid <?= $icono ?>"></i>
    <b class="font-display"><?= (int)$resumen[$k] ?></b>
    <span><?= e($label) ?></span>
  </a>
  <?php endforeach; ?>
</section>

<!-- Tabla de tareas -->
<section class="card-base tabla-card">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Tareas
      <span class="tabla-count"><?= count($visibles) ?></span>
    </h2>
    <div class="tabla-filtros">
      <form method="get" class="inline-form">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php if ($fEstado): ?><input type="hidden" name="estado" value="<?= e($fEstado) ?>"><?php endif; ?>
        <?= UI::select('asignado', $opcionesFiltro, (string)$fAsignado, true, 'select-sm') ?>
      </form>
      <?php if ($fEstado || $fAsignado): ?>
      <a href="?id=<?= $id ?>" class="filtro-clear"><i class="fa-solid fa-filter-circle-xmark"></i> Limpiar</a>
      <?php endif; ?>
      <button class="btn-primary btn-meca" onclick="document.getElementById('dlg-nueva-tarea').showModal()">
        <i class="fa-solid fa-plus"></i> Nueva tarea
      </button>
    </div>
  </div>

  <?php if (empty($visibles)): ?>
    <?= UI::vacio('fa-clipboard-list', 'Sin tareas aquí', $fEstado || $fAsignado ? 'No hay tareas con esos filtros.' : 'Agrega la primera tarea de este proyecto.') ?>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="tabla-meca">
      <thead>
        <tr>
          <th>Tarea</th>
          <th>Asignado</th>
          <th>Prioridad</th>
          <th>Estado</th>
          <th>Fecha límite</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($visibles as $t):
            $m = $miembros[(int)$t['asignado_id']] ?? null;
            $esFinal = in_array($t['estado'] ?? '', $finales, true);
            $vencida = !empty($t['fecha_limite']) && $t['fecha_limite'] < date('Y-m-d') && !$esFinal;
        ?>
        <tr class="<?= $esFinal ? 'fila-hecha' : '' ?>">
          <td class="celda-tarea">
            <span class="prio-dot prio-<?= e($t['prioridad']) ?>"></span>
            <div>
              <b><?= e($t['titulo']) ?></b>
              <?php if (!empty($t['descripcion'])): ?><small><?= e($t['descripcion']) ?></small><?php endif; ?>
            </div>
          </td>
          <td>
            <div class="celda-persona">
              <?= UI::avatar($m, 34) ?>
              <?php if ($m): ?>
              <div class="cp-info">
                <span><?= e($m['nombre']) ?></span>
                <small><i class="fa-brands fa-github"></i> <?= e($m['git_user']) ?></small>
              </div>
              <?php else: ?>
              <span class="cp-nadie">Sin asignar</span>
              <?php endif; ?>
            </div>
          </td>
          <td><?= UI::badgePrioridad($t['prioridad']) ?></td>
          <td><?= UI::selectEstadoTarea($t) ?></td>
          <td>
            <?php if (!empty($t['fecha_limite'])): ?>
              <span class="celda-fecha <?= $vencida ? 'fecha-vencida' : '' ?>">
                <i class="fa-regular fa-calendar"></i> <?= e($t['fecha_limite']) ?>
                <?php if ($vencida): ?><i class="fa-solid fa-triangle-exclamation" title="Vencida"></i><?php endif; ?>
              </span>
            <?php else: ?>
              <span class="celda-fecha celda-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="celda-acciones">
            <button class="accion-btn" title="Editar"
              data-editar-tarea='<?= e(json_encode([
                  'id' => (int)$t['id'],
                  'titulo' => $t['titulo'],
                  'descripcion' => $t['descripcion'] ?? '',
                  'prioridad' => $t['prioridad'],
                  'estado' => $t['estado'],
                  'asignado_id' => (int)$t['asignado_id'],
                  'fecha_limite' => $t['fecha_limite'] ?? '',
              ], JSON_UNESCAPED_UNICODE)) ?>'>
              <i class="fa-solid fa-pen"></i>
            </button>
            <form method="post" action="actions.php" class="inline-form" onsubmit="return confirm('¿Eliminar esta tarea?')">
              <input type="hidden" name="accion" value="tarea_eliminar">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Modal: nueva tarea -->
<dialog id="dlg-nueva-tarea" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="tarea_crear">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-circle-plus text-secondary"></i> Nueva tarea</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <label class="campo">
      <span>Título *</span>
      <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Implementar login con Google">
    </label>
    <label class="campo">
      <span>Descripción</span>
      <textarea class="input-meca" name="descripcion" rows="2" placeholder="Detalles, criterios de aceptación..."></textarea>
    </label>
    <div class="campo-doble">
      <label class="campo"><span>Asignado a</span><?= UI::select('asignado_id', $opcionesMiembros, '0') ?></label>
      <label class="campo"><span>Prioridad</span><?= UI::select('prioridad', array_map(fn($v) => $v[0], Catalogo::prioridades()), 'media') ?></label>
    </div>
    <div class="campo-doble">
      <label class="campo"><span>Estado inicial</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()), 'pendiente') ?></label>
      <label class="campo"><span>Fecha límite</span><input class="input-meca" type="date" name="fecha_limite"></label>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Crear tarea</button>
    </footer>
  </form>
</dialog>

<!-- Modal: editar tarea (se rellena por JS) -->
<dialog id="dlg-editar-tarea" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="tarea_editar">
    <input type="hidden" name="id" id="et-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-pen text-secondary"></i> Editar tarea</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <label class="campo"><span>Título *</span><input class="input-meca" name="titulo" id="et-titulo" required maxlength="120"></label>
    <label class="campo"><span>Descripción</span><textarea class="input-meca" name="descripcion" id="et-descripcion" rows="2"></textarea></label>
    <div class="campo-doble">
      <label class="campo"><span>Asignado a</span><?= UI::select('asignado_id', $opcionesMiembros, '0', false, 'js-et-asignado') ?></label>
      <label class="campo"><span>Prioridad</span><?= UI::select('prioridad', array_map(fn($v) => $v[0], Catalogo::prioridades()), 'media', false, 'js-et-prioridad') ?></label>
    </div>
    <div class="campo-doble">
      <label class="campo"><span>Estado</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()), 'pendiente', false, 'js-et-estado') ?></label>
      <label class="campo"><span>Fecha límite</span><input class="input-meca" type="date" name="fecha_limite" id="et-fecha"></label>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </footer>
  </form>
</dialog>

<!-- Modal: editar proyecto -->
<dialog id="dlg-editar-proyecto" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="proyecto_editar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-folder-open text-secondary"></i> Editar proyecto</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <label class="campo"><span>Nombre *</span><input class="input-meca" name="nombre" required value="<?= e($proyecto['nombre']) ?>"></label>
    <label class="campo"><span>Descripción</span><textarea class="input-meca" name="descripcion" rows="2"><?= e($proyecto['descripcion']) ?></textarea></label>
    <label class="campo"><span>Repositorio</span><input class="input-meca" type="url" name="repo" value="<?= e($proyecto['repo'] ?? '') ?>"></label>
    <div class="campo-doble">
      <label class="campo"><span>Estado</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosProyecto()), $proyecto['estado']) ?></label>
      <div class="campo">
        <span>Ícono</span>
        <div class="icon-picker">
          <?php foreach (Catalogo::iconosProyecto() as $ic): ?>
          <label>
            <input type="radio" name="icono" value="<?= $ic ?>" <?= $proyecto['icono'] === $ic ? 'checked' : '' ?>>
            <i class="fa-solid <?= $ic ?>"></i>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="campo">
      <span>Color</span>
      <?= UI::colorPicker($proyecto['color'] ?? 0) ?>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar</button>
    </footer>
  </form>
</dialog>

<?php UI::fin(); ?>
