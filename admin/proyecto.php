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

// Dependencias: opciones (todas las tareas del proyecto) y mapa por id
$tareasPorId = [];
$opcionesDependencia = [0 => '— Ninguna —'];
foreach ($tareas as $t) {
    $tareasPorId[(int)$t['id']] = $t;
    $opcionesDependencia[(int)$t['id']] = mb_strimwidth($t['titulo'], 0, 46, '…');
}
$nivelesFlujo = $tareasRepo->niveles($tareas);
$hayDependencias = (bool)array_filter($tareas, fn($t) => (int)($t['depende_de'] ?? 0) > 0);

// Actividad del repositorio en GitHub (con cache)
$actividadRepo = GitHub::actividad($proyecto['repo'] ?? '');

UI::inicio($proyecto['nombre'], 'proyecto-' . $id);
?>

<!-- Cabecera del proyecto -->
<header class="proyecto-hero" style="--pc:<?= $color ?>">
  <div class="ph-barra" title="Avance del proyecto: <?= $avance ?>%"><span style="width:<?= $avance ?>%"></span></div>
  <i class="fa-solid <?= e($proyecto['icono']) ?> ph-watermark"></i>
  <div class="ph-top">
    <a href="index.php" class="ph-back"><i class="fa-solid fa-arrow-left"></i> Proyectos</a>
    <div class="ph-actions">
      <?php if (!empty($proyecto['repo'])): ?>
      <a class="btn-meca btn-sm btn-github" href="<?= e($proyecto['repo']) ?>" target="_blank" rel="noopener">
        <i class="fa-brands fa-github"></i> Repositorio
      </a>
      <?php endif; ?>
      <button class="btn-ghost btn-meca btn-sm" onclick="document.getElementById('dlg-editar-proyecto').showModal()">
        <i class="fa-solid fa-pen"></i> Editar
      </button>
      <form method="post" action="actions.php"
            data-confirmar="Se eliminará el proyecto «<?= e($proyecto['nombre']) ?>» y TODAS sus tareas. Esta acción no se puede deshacer."
            data-confirmar-titulo="¿Eliminar proyecto?" data-confirmar-ok="Sí, eliminar">
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

<!-- Resumen por estado (mini kanban): icono a un lado, datos al otro -->
<section class="estados-resumen">
  <?php foreach (Catalogo::estadosTarea() as $k => [$label, $icono]): ?>
  <a class="estado-tile estado-<?= $k ?> <?= $fEstado === $k ? 'tile-activo' : '' ?>"
     href="?id=<?= $id ?>&estado=<?= $fEstado === $k ? '' : $k ?><?= $fAsignado ? '&asignado=' . $fAsignado : '' ?>">
    <span class="et-icono"><i class="fa-solid <?= $icono ?>"></i></span>
    <span class="et-datos">
      <b class="font-display"><?= (int)$resumen[$k] ?></b>
      <small><?= e($label) ?></small>
    </span>
  </a>
  <?php endforeach; ?>
</section>

<!-- Cambio de vista: tabla / flujo de dependencias -->
<div class="vista-toggle">
  <button type="button" class="tab-btn active" data-vista="tabla"><i class="fa-solid fa-table-list"></i> Tabla</button>
  <button type="button" class="tab-btn" data-vista="flujo"><i class="fa-solid fa-diagram-project"></i> Flujo</button>
</div>

<div data-vista-panel="tabla">
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
  <?php endif; ?>
  <?php if (!empty($visibles)): ?>
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
              <?php
              $depId = (int)($t['depende_de'] ?? 0);
              if ($depId && isset($tareasPorId[$depId])):
                  $depTarea = $tareasPorId[$depId];
                  $depLista = in_array($depTarea['estado'] ?? '', $finales, true);
              ?>
              <small class="dep-tag <?= $depLista ? 'dep-ok' : 'dep-bloqueada' ?>"
                     title="<?= $depLista ? 'Su dependencia ya está completada' : 'Esperando a que se complete la dependencia' ?>">
                <i class="fa-solid <?= $depLista ? 'fa-link' : 'fa-lock' ?>"></i>
                <?= $depLista ? 'Depende de' : 'Espera a' ?>: <?= e(mb_strimwidth($depTarea['titulo'], 0, 34, '…')) ?>
              </small>
              <?php endif; ?>
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
                  'depende_de' => (int)($t['depende_de'] ?? 0),
              ], JSON_UNESCAPED_UNICODE)) ?>'>
              <i class="fa-solid fa-pen"></i>
            </button>
            <form method="post" action="actions.php" class="inline-form"
                  data-confirmar="Se eliminará la tarea «<?= e($t['titulo']) ?>»."
                  data-confirmar-titulo="¿Eliminar tarea?" data-confirmar-ok="Sí, eliminar">
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
</div>

<!-- Vista de flujo: tareas conectadas por dependencias -->
<div data-vista-panel="flujo" hidden>
  <section class="card-base tabla-card flujo-card">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-diagram-project text-secondary"></i> Flujo de dependencias</h2>
      <?php if (!$hayDependencias): ?>
      <span class="ajuste-ayuda">Asigna "Depende de" al crear o editar una tarea para encadenarlas aquí.</span>
      <?php endif; ?>
    </div>
    <?php if (empty($tareas)): ?>
      <?= UI::vacio('fa-diagram-project', 'Sin tareas', 'Crea tareas para ver su flujo.') ?>
    <?php else: ?>
    <div class="flujo-wrap" id="flujo-wrap" style="--pc:<?= $color ?>">
      <svg class="flujo-lineas" id="flujo-lineas"></svg>
      <div class="flujo-cols">
        <?php
        $columnas = [];
        foreach ($tareas as $t) {
            $columnas[$nivelesFlujo[(int)$t['id']] ?? 0][] = $t;
        }
        ksort($columnas);
        foreach ($columnas as $nivel => $lista): ?>
        <div class="flujo-col">
          <h4><?= $nivel === 0 ? 'Inicio' : 'Fase ' . ($nivel + 1) ?></h4>
          <?php foreach ($lista as $t):
              $m = $miembros[(int)$t['asignado_id']] ?? null;
              $esFinalF = in_array($t['estado'] ?? '', $finales, true);
          ?>
          <div class="flujo-nodo <?= $esFinalF ? 'nodo-hecho' : '' ?>" id="fn-<?= (int)$t['id'] ?>"
               data-dep="<?= (int)($t['depende_de'] ?? 0) ?>">
            <b><?= e($t['titulo']) ?></b>
            <div class="fn-meta">
              <?= UI::avatar($m, 24) ?>
              <?= UI::badgeEstadoTarea($t['estado'] ?? '') ?>
              <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($actividadRepo['estado'] !== 'sin_repo'): ?>
<!-- Actividad del repositorio (GitHub) -->
<section class="card-base tabla-card actividad-card" style="--pc:<?= $color ?>">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-brands fa-github"></i> Actividad del repositorio</h2>
    <div class="tabla-filtros">
      <?php if ($actividadRepo['estado'] === 'ok'): ?>
      <span class="ajuste-ayuda"><b><?= (int)$actividadRepo['total'] ?></b> commits en el último año</span>
      <?php endif; ?>
      <a class="btn-meca btn-sm btn-github" href="<?= e($actividadRepo['url']) ?>" target="_blank" rel="noopener">
        <i class="fa-brands fa-github"></i> Abrir en GitHub
      </a>
    </div>
  </div>
  <div class="actividad-cuerpo">
    <?php if ($actividadRepo['estado'] === 'ok'):
        // Ultimas 26 semanas, celdas por dia con intensidad 0-4
        $semanas = array_slice($actividadRepo['semanas'], -26);
        $max = 1;
        foreach ($semanas as $s) { foreach ($s['days'] as $d) $max = max($max, $d); }
    ?>
    <div class="hm-grid" title="Commits por día (últimas 26 semanas)">
      <?php foreach ($semanas as $s): foreach ($s['days'] as $di => $d):
          $nivel = $d === 0 ? 0 : (int)ceil($d / $max * 4);
          $fecha = date('d M Y', $s['week'] + $di * 86400);
      ?><span class="hm-celda hm-<?= $nivel ?>" title="<?= $d ?> commit<?= $d === 1 ? '' : 's' ?> · <?= $fecha ?>"></span><?php
      endforeach; endforeach; ?>
    </div>
    <div class="hm-leyenda">
      <small>Menos</small>
      <span class="hm-celda hm-0"></span><span class="hm-celda hm-1"></span><span class="hm-celda hm-2"></span><span class="hm-celda hm-3"></span><span class="hm-celda hm-4"></span>
      <small>Más</small>
    </div>
    <?php elseif ($actividadRepo['estado'] === 'pendiente'): ?>
    <p class="actividad-msj"><i class="fa-solid fa-hourglass-half"></i> GitHub está calculando las estadísticas del repo — recarga en unos segundos.</p>
    <?php else: ?>
    <p class="actividad-msj"><i class="fa-solid fa-circle-info"></i> No se pudo leer la actividad. Si el repo es privado, agrega un token de GitHub en Ajustes → Identidad.</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

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
    <label class="campo">
      <span>Depende de (opcional)</span>
      <?= UI::select('depende_de', $opcionesDependencia, '0') ?>
      <small class="campo-ayuda">La tarea quedará "en espera" hasta que su dependencia se complete.</small>
    </label>
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
    <label class="campo">
      <span>Depende de (opcional)</span>
      <?= UI::select('depende_de', $opcionesDependencia, '0', false, 'js-et-depende') ?>
      <small class="campo-ayuda">No puede depender de sí misma ni formar ciclos (se valida al guardar).</small>
    </label>
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
