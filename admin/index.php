<?php
/**
 * Dashboard: resumen general y grid de proyectos.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$proyectosRepo = new ProyectoRepo();
$miembrosRepo  = new MiembroRepo();
$tareasRepo    = new TareaRepo();

$proyectos = $proyectosRepo->todos();
$miembros  = $miembrosRepo->mapa();
$todasTareas = $tareasRepo->todas();

// Alcance del usuario: un colaborador de solo lectura ve unicamente
// los proyectos en los que participa (y las tareas de esos proyectos).
$alcance = alcanceProyectos();
if ($alcance !== null) {
    $proyectos   = soloProyectosVisibles($proyectos);
    $todasTareas = array_values(array_filter($todasTareas, fn($t) => isset($alcance[(int)$t['proyecto_id']])));
}

// "Ver como": solo los proyectos y tareas de esa persona
$verComo = verComo();
if ($verComo) {
    $vcId = (int)$verComo['id'];
    $todasTareas = array_values(array_filter($todasTareas, fn($t) => TareaRepo::tieneAsignado($t, $vcId)));
    $pidsVc = array_flip(array_map(fn($t) => (int)$t['proyecto_id'], $todasTareas));
    $proyectos = array_values(array_filter($proyectos, fn($p) => isset($pidsVc[(int)$p['id']])));
}

// Equipo completo, para elegir participantes al crear un proyecto
$opcionesEquipo = [];
foreach ($miembrosRepo->todos() as $m) {
    $opcionesEquipo[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
}

$finales     = Catalogo::estadosFinales();
$primerEstadoProyecto = array_key_first(Catalogo::estadosProyecto());
$activos    = count(array_filter($proyectos, fn($p) => ($p['estado'] ?? '') === $primerEstadoProyecto));
$abiertas   = count(array_filter($todasTareas, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
$hechas     = count($todasTareas) - $abiertas;

UI::inicio('Dashboard', 'dashboard');
UI::cabecera(
    'Proyectos <span class="text-secondary">' . e(Config::get('titulo')) . '</span>',
    $verComo
        ? 'Viendo solo los proyectos y tareas de <b>' . e($verComo['nombre']) . '</b>.'
        : ($alcance !== null
            ? 'Estos son los proyectos en los que participas.'
            : 'Gestiona los proyectos del equipo de programación: tareas, estados y colaboradores.'),
    '<button class="btn-primary btn-meca solo-admin" onclick="document.getElementById(\'dlg-nuevo\').showModal()">
       <i class="fa-solid fa-plus"></i> Nuevo proyecto
     </button>'
);
?>

<!-- Fondo: un grafo de ramas, como el de un repositorio. Es decorativo, va
     detrás de todo y se apaga si el sistema pide menos animación. -->
<svg class="fondo-ramas" viewBox="0 0 1200 620" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
  <!-- Carriles de punta a punta y ramas que siempre salen de uno y entran en
       otro: así ninguna se corta en el aire. El movimiento lo da un pulso que
       recorre cada trazo (los <use>), no un punteado. -->
  <g class="fr-lineas">
    <path id="c1" pathLength="1" d="M-60 60 H1260"/>
    <path id="c2" pathLength="1" d="M-60 230 H1260"/>
    <path id="c3" pathLength="1" d="M-60 400 H1260"/>
    <path id="c4" pathLength="1" d="M-60 560 H1260"/>
    <path id="r1" pathLength="1" d="M120 60 C210 60 190 230 280 230"/>
    <path id="r2" pathLength="1" d="M340 230 C430 230 410 60 500 60"/>
    <path id="r3" pathLength="1" d="M560 60 C650 60 630 230 720 230"/>
    <path id="r4" pathLength="1" d="M180 400 C270 400 250 230 340 230"/>
    <path id="r5" pathLength="1" d="M420 400 C510 400 490 560 580 560"/>
    <path id="r6" pathLength="1" d="M640 560 C730 560 710 400 800 400"/>
    <path id="r7" pathLength="1" d="M860 230 C950 230 930 400 1020 400"/>
    <path id="r8" pathLength="1" d="M900 400 C990 400 970 230 1060 230"/>
    <path id="r9" pathLength="1" d="M1080 60 C1170 60 1150 230 1240 230"/>
    <path id="r10" pathLength="1" d="M40 560 C130 560 110 400 200 400"/>
  </g>
  <g class="fr-pulsos">
    <use href="#c1"/><use href="#c2"/><use href="#c3"/>
    <use href="#c4"/><use href="#r1"/><use href="#r2"/>
    <use href="#r3"/><use href="#r4"/><use href="#r5"/>
    <use href="#r6"/><use href="#r7"/><use href="#r8"/>
    <use href="#r9"/><use href="#r10"/>
  </g>
  <g class="fr-nodos">
    <circle cx="120" cy="60" r="9"/><circle cx="280" cy="230" r="7"/><circle cx="340" cy="230" r="7"/>
    <circle cx="500" cy="60" r="9"/><circle cx="560" cy="60" r="7"/><circle cx="720" cy="230" r="7"/>
    <circle cx="180" cy="400" r="9"/><circle cx="420" cy="400" r="7"/><circle cx="580" cy="560" r="7"/>
    <circle cx="640" cy="560" r="9"/><circle cx="800" cy="400" r="7"/><circle cx="860" cy="230" r="7"/>
    <circle cx="1020" cy="400" r="9"/><circle cx="1060" cy="230" r="7"/><circle cx="1080" cy="60" r="7"/>
    <circle cx="200" cy="400" r="9"/><circle cx="900" cy="400" r="7"/><circle cx="40" cy="560" r="7"/>
  </g>
</svg>

<section class="stats-grid">
  <?= UI::stat('fa-folder-open', '#1A4B99', (string)count($proyectos), 'Proyectos') ?>
  <?= UI::stat('fa-bolt', '#2B76F7', (string)$activos, 'Activos') ?>
  <?= UI::stat('fa-list-check', '#F7931E', (string)$abiertas, 'Tareas abiertas') ?>
  <?= UI::stat('fa-circle-check', '#2BB673', (string)$hechas, 'Tareas completadas') ?>
</section>

<?php if (empty($proyectos)): ?>
  <?php if ($verComo): ?>
    <?= UI::vacio('fa-mug-hot', 'Sin proyectos para ' . $verComo['nombre'], 'No tiene tareas asignadas en ningún proyecto. Quita el filtro "Ver como" para ver todo.') ?>
  <?php elseif ($alcance !== null): ?>
    <?= UI::vacio('fa-mug-hot', 'Todavía no participas en ningún proyecto', 'Cuando te asignen una tarea o te inviten a una reunión, el proyecto aparecerá aquí.') ?>
  <?php else: ?>
    <?= UI::vacio('fa-folder-plus', 'Aún no hay proyectos', 'Crea el primer proyecto del equipo con el botón "Nuevo proyecto".') ?>
  <?php endif; ?>
<?php else: ?>
<section class="proyectos-admin-grid">
  <?php foreach ($proyectos as $p):
      $resumen = $tareasRepo->resumen((int)$p['id']);
      $total   = array_sum($resumen);
      $avance  = $tareasRepo->avance((int)$p['id']);
      $color   = ProyectoRepo::colorBase($p);

      // Miembros con tareas en este proyecto
      $equipo = [];
      foreach ($tareasRepo->delProyecto((int)$p['id']) as $t) {
          foreach (TareaRepo::asignadosDe($t) as $mid) {
              if (isset($miembros[$mid])) $equipo[$mid] = $miembros[$mid];
          }
      }
  ?>
  <article class="proyecto-admin-card card-base" style="--pc:<?= $color ?>">
    <div class="pac-head">
      <div class="pac-icon"><i class="fa-solid <?= e($p['icono']) ?>"></i></div>
      <?= UI::badgeEstadoProyecto($p['estado']) ?>
    </div>
    <div class="pac-body">
      <h2 class="font-display"><a href="proyecto.php?id=<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?></a></h2>
      <p class="pac-desc"><?= e($p['descripcion']) ?: 'Sin descripción.' ?></p>

      <div class="pac-progress">
        <?= UI::progreso($avance, $color) ?>
      </div>

      <div class="pac-estados">
        <?php foreach (Catalogo::estadosTarea() as $k => [$label, $icono]): ?>
          <span class="pac-mini estado-<?= $k ?>" title="<?= e($label) ?>">
            <i class="fa-solid <?= $icono ?>"></i> <?= (int)$resumen[$k] ?>
          </span>
        <?php endforeach; ?>
      </div>

      <div class="pac-foot">
        <?= UI::avatarStack(array_values($equipo)) ?>
        <div class="pac-links">
          <?php foreach (ProyectoRepo::repos($p) as $repo): ?>
          <a href="<?= e($repo['url']) ?>" target="_blank" rel="noopener" class="pac-repo" title="Repositorio <?= e($repo['label']) ?>">
            <i class="fa-solid <?= e($repo['icono']) ?>"></i>
          </a>
          <?php endforeach; ?>
          <a href="proyecto.php?id=<?= (int)$p['id'] ?>" class="btn-outline btn-meca btn-sm">
            Ver tablero <i class="fa-solid fa-arrow-right"></i>
          </a>
        </div>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- Modal: nuevo proyecto (asistente por pasos) -->
<dialog id="dlg-nuevo" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="proyecto_crear">
    <?= UI::wizardRiel('fa-folder-plus', 'Nuevo proyecto', 'Se creará en el panel del equipo', UI::PASOS_PROYECTO) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo">
          <span>Nombre del proyecto *</span>
          <input class="input-meca" name="nombre" required maxlength="80" placeholder="Ej. App de delivery">
        </label>
        <label class="campo">
          <span>Descripción</span>
          <textarea class="input-meca" name="descripcion" rows="3" placeholder="¿De qué trata el proyecto?"></textarea>
        </label>
        <label class="campo">
          <span>Fecha de inicio</span>
          <input class="input-meca" type="date" name="fecha_inicio">
          <small class="campo-ayuda">Cuándo arranca el proyecto. Puedes dejarlo vacío.</small>
        </label>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Participantes del proyecto</span>
          <?= UI::select('miembros', $opcionesEquipo, [], false, '', true) ?>
          <small class="campo-ayuda">Al asignar tareas solo aparecerán estas personas. Si no eliges a nadie, el proyecto queda abierto a todo el equipo.</small>
        </label>
      </section>

      <section class="wz-panel">
        <div class="campo" data-sin-resumen>
          <span><i class="fa-solid fa-code-branch"></i> Repositorios</span>
          <?= UI::reposEditor() ?>
        </div>
        <label class="campo">
          <span>Estado</span>
          <?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosProyecto()), 'activo') ?>
        </label>
      </section>

      <section class="wz-panel">
        <div class="campo" data-sin-resumen>
          <span>Ícono</span>
          <div class="icon-picker">
            <?php foreach (Catalogo::iconosProyecto() as $i => $ic): ?>
            <label>
              <input type="radio" name="icono" value="<?= $ic ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <i class="fa-solid <?= $ic ?>"></i>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="campo" data-sin-resumen>
          <span>Color</span>
          <?= UI::colorPicker(0) ?>
        </div>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-check"></i> Crear proyecto</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php UI::fin(); ?>
