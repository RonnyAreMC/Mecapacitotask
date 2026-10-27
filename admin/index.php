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
$opcionesAnalistas = [0 => '— Sin PO —'];
// Quienes tienen rol de Scrum Master: el del proyecto es el único (además del
// administrador) que pone su horario de reuniones.
$opcionesScrum = [0 => '— Sin Scrum Master —'];
foreach ($miembrosRepo->todos() as $m) {
    $opcionesEquipo[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
    if (MiembroRepo::equipoDe($m) === 'analistas') {
        $opcionesAnalistas[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
    }
    // El administrador también puede llevar un tablero: manda sobre todo.
    if (MiembroRepo::tieneAcceso($m, 'scrum') || MiembroRepo::tieneAcceso($m, 'admin')) {
        $opcionesScrum[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
    }
}


UI::inicio('Dashboard', 'dashboard');
UI::cabecera(
    'Proyectos <span class="text-secondary">' . e(Config::get('titulo')) . '</span>',
    $verComo
        ? 'Viendo solo los proyectos y tareas de <b>' . e($verComo['nombre']) . '</b>.'
        : ($alcance !== null
            ? 'Estos son los proyectos en los que participas.'
            : 'Gestiona los proyectos del equipo de programación: tareas, estados y colaboradores.'),
    '<button class="btn-outline btn-meca btn-azul" onclick="document.getElementById(\'dlg-dailies\').showModal()"
             title="A qué hora se junta cada equipo">
       <i class="fa-solid fa-mug-hot"></i> Dailies
     </button>
     <button class="btn-primary btn-meca btn-agregar solo-admin" onclick="document.getElementById(\'dlg-nuevo\').showModal()">
       <i class="fa-solid fa-plus"></i> Nuevo proyecto
     </button>',
    'nexo:saludando'   // en lugar de los indicadores: Nexo saluda, flotando suave
);

/* ---------- Horario de reuniones fijas ("dailies") ----------
   VER lo puede todo el mundo: un cuadro de horarios se comparte, y por eso se
   listan todos los proyectos y no solo los de cada quien. AÑADIR es otra cosa:
   solo el Scrum Master de ESE proyecto (y el administrador).

   El horario no sale del proyecto: se escribe aquí, porque un equipo tiene
   varias fijas (la daily, el refinamiento…) y porque lo que se consulta es la
   agenda del día, no el listado por proyecto. */
$hoyIso    = (int)date('N');
$ahora     = date('H:i');
$fijasRepo = new ReunionFijaRepo();
$mapaProy  = [];
foreach ($proyectosRepo->todos() as $p) {
    $mapaProy[(int)$p['id']] = $p;
}

$fijas = [];
foreach ($fijasRepo->todas() as $r) {
    $pid = (int)$r['proyecto_id'];
    if (!isset($mapaProy[$pid])) continue;   // proyecto borrado: la fija ya no dice nada
    $dias = ReunionFijaRepo::diasDe($r);
    $hoy  = in_array($hoyIso, $dias, true);
    $fijas[] = [
        'r'        => $r,
        'proyecto' => $mapaProy[$pid],
        'dias'     => $dias,
        'hoy'      => $hoy,
        'pasada'   => $hoy && ($r['hora'] ?? '') < $ahora,
        'mia'      => puedeHorarioDelProyecto($pid),
    ];
}

// La siguiente de hoy: la primera que toca y todavía no ha pasado
$siguiente = null;
foreach ($fijas as $f) {
    if ($f['hoy'] && !$f['pasada']) { $siguiente = $f; break; }
}

// Proyectos donde ESTA persona puede poner horario (para el formulario):
// los que lleva como Scrum Master o como Product Owner.
$misProyectosScrum = [];
foreach ($mapaProy as $pid => $p) {
    if (puedeHorarioDelProyecto($pid)) {
        $misProyectosScrum[$pid] = $p['nombre'];
    }
}
?>

<?php
/* El resumen de tareas de cada tarjeta de abajo. Los indicadores de arriba
   (proyectos, tareas, terminadas…) se quitaron: arriba saluda Nexo. */
$finalesDash = Catalogo::estadosFinales();
$resumenes = [];
foreach ($proyectos as $p) $resumenes[(int)$p['id']] = $tareasRepo->resumen((int)$p['id']);
?>

<?php
/* Arriba, lo que nadie sabía sin preguntar por chat: a
   qué hora se subieron los últimos cambios al servidor de pruebas. */
// Solo los proyectos en los que participo: aquí salían los ocho del panel, y
// a quien no está en SIGE no le importa cuándo subió SIGE. El registro
// completo sigue en el módulo.
if (puedeVerDeploys()) {
    $dtFilas = resumenDeploys(misProyectosDeploys($proyectos));
    if ($dtFilas) {
        $dtMiembros = $miembros;
        $dtVolver   = 'index';
        require __DIR__ . '/lib/deploy_tira.php';
    }
} elseif (configDeploys()['activo'] && is_file(__DIR__ . '/lib/Cronograma.php')) {
    // Quien no entra a Despliegues (los dev) igual ve hasta cuándo puede subir los cambios de
    // sus proyectos, con la cuenta regresiva (lib/Cronograma.php): para eso es el horario
    require_once __DIR__ . '/lib/Cronograma.php';
    if (Cronograma::activo()) {
        $proxSub = 0;
        $quienesSub = [];
        foreach (misProyectosDeploys($proyectos) as $pSub) {
            $vSub = Cronograma::proxima((int)$pSub['id']);
            if ($vSub <= 0) continue;
            if ($proxSub === 0 || $vSub < $proxSub) { $proxSub = $vSub; $quienesSub = []; }
            if ($vSub === $proxSub) $quienesSub[] = aliasDeploy((int)$pSub['id']) ?: $pSub['nombre'];
        }
        if ($proxSub): ?>
<section class="dep-tira card-base dep-tira-crono">
  <div class="dep-tira-main">
    <span class="dep-pulso" aria-hidden="true"><?= UI::icono('Clock') ?></span>
    <div class="dep-tira-txt">
      <small>Hora límite para subir tus cambios</small>
      <b class="font-display"><?= e(ucfirst(Cronograma::texto($proxSub))) ?></b>
      <span class="dep-sub"><?= e(implode(', ', $quienesSub)) ?> · <?= e(Cronograma::dias()) ?>, hasta esa hora</span>
    </div>
    <div class="dep-tira-fin">
      <span class="dep-prox"><?= UI::icono('Clock') ?> <b data-cuenta-regresiva data-corto data-hasta="<?= $proxSub ?>" data-ahora="<?= time() ?>">&nbsp;</b></span>
    </div>
  </div>
</section>
        <?php endif;
    }
}
?>

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
  <?php $iCard = 0; foreach ($proyectos as $p):
      $resumen = $resumenes[(int)$p['id']] ?? $tareasRepo->resumen((int)$p['id']);
      $total   = array_sum($resumen);
      $avance  = $tareasRepo->avance((int)$p['id']);
      $hechas  = $tareasRepo->completadas((int)$p['id']);
      $portada = UI::portadaDe($p);
      $color   = ProyectoRepo::colorBase($p);

      // Miembros con tareas en este proyecto
      $equipo = [];
      foreach ($tareasRepo->delProyecto((int)$p['id']) as $t) {
          foreach (TareaRepo::asignadosDe($t) as $mid) {
              if (isset($miembros[$mid])) $equipo[$mid] = $miembros[$mid];
          }
      }
  ?>
  <article class="proyecto-admin-card card-base<?= $portada ? '' : ' sin-portada' ?>" style="--pc:<?= $color ?>;--i:<?= $iCard++ ?>">
    <a class="pac-portada" href="proyecto.php?id=<?= (int)$p['id'] ?>" tabindex="-1" aria-hidden="true">
      <?php if ($portada): ?>
      <img src="<?= e(asset('iconos/ilustraciones/' . $portada . '.svg')) ?>" alt="" width="520" height="420" loading="lazy" decoding="async">
      <?php else: ?>
      <span class="pac-portada-icono"><?= UI::icono($p['icono'] ?? 'FolderOpen') ?></span>
      <?php endif; ?>
      <?= UI::badgeEstadoProyecto($p['estado']) ?>
      <span class="pac-icon"><?= UI::icono($p['icono'] ?? 'FolderOpen') ?></span>
    </a>
    <div class="pac-body">
      <h2 class="font-display"><a href="proyecto.php?id=<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?></a></h2>
      <p class="pac-desc"><?= e($p['descripcion']) ?: 'Sin descripción.' ?></p>

      <!-- Solo lo que se pregunta: cuántas tareas hay y cuántas ya están
           terminadas (el detalle por estado está en el tablero). -->
      <p class="pac-resumen">
        <span><b><?= $total ?></b> <?= $total === 1 ? 'tarea' : 'tareas' ?></span>
        <span class="pac-hechas"><b><?= $hechas ?></b> <?= $hechas === 1 ? 'terminada' : 'terminadas' ?></span>
      </p>
      <div class="pac-progress">
        <?= UI::progreso($avance, $color) ?>
      </div>

      <div class="pac-foot">
        <?= UI::avatarStack(array_values($equipo)) ?>
        <div class="pac-links">
          <?php if (!esSupervisor()): /* el supervisor no ve los repositorios */ ?>
          <?php foreach (ProyectoRepo::repos($p) as $repo): ?>
          <a href="<?= e($repo['url']) ?>" target="_blank" rel="noopener" class="pac-repo" title="Repositorio <?= e($repo['label']) ?>">
            <i class="fa-solid <?= e($repo['icono']) ?>"></i>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
          <a href="proyecto.php?id=<?= (int)$p['id'] ?>" class="btn-outline btn-meca btn-azul btn-sm">
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
          <span>Nombre del proyecto</span>
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
        <label class="campo">
          <span><i class="fa-solid fa-user-tie"></i> Product Owner</span>
          <?= UI::select('po', $opcionesAnalistas, 0) ?>
          <small class="campo-ayuda">El PO del proyecto (se elige entre los analistas). Junto con el Scrum Master, crea y edita las tareas y pone el horario de reuniones.</small>
        </label>
        <label class="campo">
          <span><i class="fa-solid fa-user-shield"></i> Product Owner de relevo</span>
          <?= UI::select('po_relevo', [0 => '— Sin PO de relevo —'] + array_diff_key($opcionesAnalistas, [0 => true]), 0) ?>
          <small class="campo-ayuda">Cubre al PO principal cuando no está (vacaciones, permisos): tiene sus mismos permisos en el proyecto.</small>
        </label>
        <label class="campo">
          <span><i class="fa-solid fa-user-gear"></i> Scrum Master</span>
          <?= UI::select('scrum', $opcionesScrum, 0) ?>
          <small class="campo-ayuda">Quién lleva este proyecto. Junto con el Product Owner, es quien pone su horario de reuniones diarias.</small>
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
          <span>Portada</span>
          <?= UI::selectorPortada() ?>
        </div>
        <div class="campo" data-sin-resumen>
          <span>Ícono</span>
          <?= UI::selectorIcono() ?>
        </div>
        <div class="campo" data-sin-resumen>
          <span>Color</span>
          <?= UI::colorPicker(0) ?>
        </div>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Crear proyecto</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/lib/modal_dailies.php'; ?>

<?php UI::fin(); ?>
