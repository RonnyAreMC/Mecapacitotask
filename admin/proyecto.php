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
// Un colaborador de solo lectura solo abre los proyectos en los que participa,
// aunque escriba el id a mano en la URL.
exigirProyecto($id);

$miembros = $miembrosRepo->mapa();
$tareas   = $tareasRepo->delProyecto($id);
$resumen  = $tareasRepo->resumen($id);
$avance   = $tareasRepo->avance($id);
$completadas = $tareasRepo->completadas($id);
$finales  = Catalogo::estadosFinales();
$color    = ProyectoRepo::colorBase($proyecto);

// Filtros ("ver como" global manda sobre el filtro de la pagina)
$verComo   = verComo();
$fEstado   = $_GET['estado'] ?? '';
$fAsignado = $verComo ? (int)$verComo['id'] : (int)($_GET['asignado'] ?? 0);
// URL de esta misma vista con sus filtros. Los formularios que redirigen
// (borrar o editar una tarea) la mandan para volver aquí en vez de a la lista
// sin filtrar: si no, tras borrar una hay que volver a filtrar cada vez.
// El filtro de "ver como" no va en la URL (vive en la sesión), así que se omite.
$urlConFiltros = 'proyecto.php?id=' . $id
    . ($fEstado !== '' ? '&estado=' . urlencode($fEstado) : '')
    . (!$verComo && $fAsignado ? '&asignado=' . $fAsignado : '');

// Completadas viejas (más de una semana): salen del tablero por defecto, pero
// siguen guardadas (métricas y avance las cuentan igual). ?arch=1 las muestra;
// y si filtras justo por un estado final, también aparecen.
$verArchivadas = !empty($_GET['arch']) || in_array($fEstado, $finales, true);
$limiteArch    = date('Y-m-d', strtotime('-7 days'));
$esArchivada   = fn($t) => in_array($t['estado'] ?? '', $finales, true)
    && (string)($t['completada_en'] ?? '') !== '' && (string)($t['completada_en'] ?? '') < $limiteArch;
$nArchivadas   = count(array_filter($tareas, $esArchivada));
if (!$verArchivadas) {
    $tareas = array_values(array_filter($tareas, fn($t) => !$esArchivada($t)));
}

$visibles = array_filter($tareas, function ($t) use ($fEstado, $fAsignado) {
    if ($fEstado !== '' && $t['estado'] !== $fEstado) return false;
    if ($fAsignado && !TareaRepo::tieneAsignado($t, $fAsignado)) return false;
    return true;
});

// Equipo del proyecto: si esta definido, los selectores de tareas y reuniones
// solo ofrecen a esas personas (null = proyecto abierto a todo el equipo).
$equipoProyecto = ProyectoRepo::miembrosDe($proyecto);
if ($equipoProyecto !== null) {
    // El proyecto tiene su equipo definido: solo esas personas se asignan.
    $delProyecto = array_filter($miembros, fn($m) => in_array((int)$m['id'], $equipoProyecto, true));
} else {
    // Proyecto abierto: se ofrece SOLO a quien ya participa aquí (tiene tarea,
    // está invitado a una reunión o escribió una observación). Si aún no hay
    // nadie (proyecto nuevo), se ofrece a todo el equipo para poder empezar.
    $participan = [];
    foreach ($tareas as $t) {
        foreach (TareaRepo::asignadosDe($t) as $mid) $participan[(int)$mid] = true;
    }
    foreach ((new JsonStore('reuniones'))->where('proyecto_id', $id) as $r) {
        foreach (array_map('intval', (array)($r['invitados'] ?? [])) as $mid) $participan[$mid] = true;
    }
    foreach ((new JsonStore('observaciones'))->where('proyecto_id', $id) as $o) {
        $participan[(int)($o['autor_id'] ?? 0)] = true;
    }
    $delProyecto = $participan
        ? array_filter($miembros, fn($m) => isset($participan[(int)$m['id']]))
        : $miembros;
}

// Quien ya tiene tareas aqui pero salio del equipo: se sigue ofreciendo para
// no perder la asignacion existente al editar la tarea.
foreach ($tareas as $t) {
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        if (isset($miembros[$mid]) && !isset($delProyecto[$mid])) {
            $delProyecto[$mid] = $miembros[$mid];
        }
    }
}
uasort($delProyecto, fn($a, $b) => strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? ''));

// Responsables de tareas: SOLO desarrolladores (los analistas no ejecutan
// tareas de código, así que no se ofrecen aquí). El filtro y las reuniones sí
// los incluyen (más abajo), porque en eso sí participan.
$opcionesAsignar = [];
foreach ($delProyecto as $m) {
    if (MiembroRepo::equipoDe($m) === 'analistas') continue;
    $opcionesAsignar[$m['id']] = $m['nombre'] . ' (@' . $m['git_user'] . ')';
}

$opcionesMiembros = [0 => '— Sin asignar —'];
$opcionesFiltro   = [0 => 'Todo el equipo'];
$opcionesInvitados = [];
foreach ($delProyecto as $m) {
    $opcionesMiembros[$m['id']]  = $m['nombre'] . ' (@' . $m['git_user'] . ')';
    $opcionesFiltro[$m['id']]    = $m['nombre'];
    $opcionesInvitados[$m['id']] = $m['nombre'] . (!empty($m['email']) ? ' · ' . $m['email'] : '');
}

// Todo el equipo, para el selector de participantes del proyecto
$opcionesEquipo = [];
foreach ($miembros as $m) {
    $opcionesEquipo[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
}

// Dependencias: opciones (todas las tareas del proyecto) y mapa por id.
$tareasPorId = [];
$opcionesDependencia = [0 => '— Ninguna —'];   // (lo usa también el compositor de observaciones)
$opcionesDeps = [];                            // para el selector MÚLTIPLE de dependencias (sin "Ninguna")
foreach ($tareas as $t) {
    $tareasPorId[(int)$t['id']] = $t;
    $opcionesDependencia[(int)$t['id']] = mb_strimwidth($t['titulo'], 0, 46, '…');
    $opcionesDeps[(int)$t['id']]        = mb_strimwidth($t['titulo'], 0, 46, '…');
}
$nivelesFlujo = $tareasRepo->niveles($tareas);
$hayDependencias = (bool)array_filter($tareas, fn($t) => TareaRepo::dependenciasDe($t) !== []);

// Repos del proyecto. Los datos de GitHub (commits, ramas) se cargan de forma
// diferida al abrir la vista Métricas, así la página no espera a la API al abrir.
$reposProyecto = ProyectoRepo::repos($proyecto);

// Observaciones (revision / QA)
$obsRepo         = new ObservacionRepo();
$observaciones   = $obsRepo->delProyecto($id);
$obsPorTarea     = $obsRepo->pendientesPorTarea($id);   // [tarea_id => n pendientes]
$obsResumen      = $obsRepo->resumen($id);
$obsPendientes   = $obsResumen['pendientes'];
$equiposCat      = Catalogo::equipos();

// Datos de solo lectura de una tarea para el modal de detalle (cualquiera puede
// abrirlo, incluidos los programadores). Se calcula una vez y se pega como
// atributo data-ver-tarea en cada tarjeta/fila/nodo.
$estadosCat = Catalogo::estadosTarea();
$prioCat    = Catalogo::prioridades();
$verTareaAttr = function (array $t) use ($miembros, $tareasPorId, $finales, $obsPorTarea, $estadosCat, $prioCat, $proyecto): string {
    $nombres = [];
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        if (isset($miembros[$mid])) $nombres[] = $miembros[$mid]['nombre'];
    }
    $depId = (int)($t['depende_de'] ?? 0);
    $dep = $depId && isset($tareasPorId[$depId]) ? $tareasPorId[$depId] : null;
    return e(json_encode([
        'titulo'       => $t['titulo'] ?? '',
        'descripcion'  => $t['descripcion'] ?? '',
        'proyecto'     => $proyecto['nombre'] ?? '',
        'estado_badge' => UI::badgeEstadoTarea($t['estado'] ?? ''),
        'prio_badge'   => UI::badgePrioridad($t['prioridad'] ?? ''),
        'asignados'    => $nombres,
        'fecha_inicio' => $t['fecha_inicio'] ?? '',
        'fecha_limite' => $t['fecha_limite'] ?? '',
        'dep'          => $dep['titulo'] ?? '',
        'dep_lista'    => $dep ? in_array($dep['estado'] ?? '', $finales, true) : false,
        'obs'          => $obsPorTarea[(int)$t['id']] ?? 0,
        'adjuntos'     => TareaRepo::adjuntosDe($t),
        'creado'       => $t['creado'] ?? '',
    ], JSON_UNESCAPED_UNICODE));
};
$listoEntrega    = $avance === 100 && $obsPendientes === 0 && array_sum($resumen) > 0;

// Para el modal "Avisar al equipo": quién tiene tareas PENDIENTES aquí y
// cuáles. El administrador elige a quién le llega y con qué tareas dentro.
$avisoPersonas = [];
foreach ($tareas as $t) {
    if (in_array($t['estado'] ?? '', $finales, true)) continue;
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        if (!isset($miembros[$mid])) continue;
        $avisoPersonas[$mid]['miembro'] = $miembros[$mid];
        $avisoPersonas[$mid]['tareas'][] = $t;
    }
}
// Las más urgentes primero; las que no tienen fecha, al final
foreach ($avisoPersonas as &$_per) {
    usort($_per['tareas'], fn($a, $b) => (($a['fecha_limite'] ?? '') ?: '9999') <=> (($b['fecha_limite'] ?? '') ?: '9999'));
}
unset($_per);

// Intercambios de tareas
$interRepo      = new IntercambioRepo();
$intercambios   = $interRepo->delProyecto($id);
$interResumen   = $interRepo->resumen($id);
$interPendientes = $interResumen['pendiente'];
$miId           = (int)(Auth::usuario()['id'] ?? 0);

// Mis tareas aqui (las que puedo ofrecer) y las del resto (las que puedo pedir)
$misTareasAqui = array_values(array_filter($tareas, fn($t) => TareaRepo::tieneAsignado($t, $miId)));
$tareasDeOtros = array_values(array_filter($tareas, fn($t) => TareaRepo::asignadosDe($t)
    && !TareaRepo::tieneAsignado($t, $miId)));

$opcionesMisTareas = [];
foreach ($misTareasAqui as $t) {
    $opcionesMisTareas[(int)$t['id']] = mb_strimwidth($t['titulo'], 0, 46, '…');
}
$opcionesOtrasTareas = [];
foreach ($tareasDeOtros as $t) {
    $nombres = array_map(fn($mid) => $miembros[$mid]['nombre'] ?? '?', TareaRepo::asignadosDe($t));
    $duenio = implode(', ', $nombres) ?: '?';
    $opcionesOtrasTareas[(int)$t['id']] = mb_strimwidth($t['titulo'], 0, 34, '…') . ' · ' . $duenio;
}
$puedeIntercambiar = $opcionesMisTareas !== [] && $opcionesOtrasTareas !== [];

// Reuniones (Zoom)
$reunionesRepo = new ReunionRepo();
$reuniones     = $reunionesRepo->delProyecto($id);
$zoomListo     = Zoom::listo();
$meetListo     = GoogleCalendar::listo();          // reuniones por Google Meet
$reunionesOn   = $zoomListo || $meetListo;         // hay al menos una plataforma
// Parametrizacion del admin (Ajustes -> Reuniones)
$platElegir    = Reuniones::puedeElegir();         // ¿se ofrece el selector?
$platDefecto   = Reuniones::plataformaDefecto($proyecto);   // el proyecto puede tener la suya
$durDefecto    = Reuniones::duracionDefecto();
$durOpciones   = Reuniones::duraciones();

// Calendario del proyecto (fechas límite de tareas + reuniones)
$mesCal = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesCal)) $mesCal = date('Y-m');
$calIni    = strtotime($mesCal . '-01');
$calDias   = (int)date('t', $calIni);
$calOffset = (int)date('w', $calIni);   // 0 = domingo
$calPrev   = date('Y-m', strtotime($mesCal . '-01 -1 month'));
$calNext   = date('Y-m', strtotime($mesCal . '-01 +1 month'));
$mesesEs   = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
$calTitulo = $mesesEs[(int)date('n', $calIni)] . ' ' . date('Y', $calIni);
$hoyIso    = date('Y-m-d');

// Las tareas se pintan como una barra desde su fecha de inicio hasta la de
// fin: 'inicio' arranca la barra (con el título), 'medio'/'fin' la continúan.
$eventosCal = [];   // 'Y-m-d' => [ ['tipo'=>, 'dato'=>, 'pos'=>], ... ]
$mesIni = $mesCal . '-01';
$mesFin = sprintf('%s-%02d', $mesCal, $calDias);
// Ordenar por inicio para que las barras se apilen parejas entre días
$tareasCal = $tareas;
usort($tareasCal, fn($a, $b) =>
    strcmp($a['fecha_inicio'] ?: ($a['fecha_limite'] ?? ''), $b['fecha_inicio'] ?: ($b['fecha_limite'] ?? '')));
foreach ($tareasCal as $t) {
    $ini = $t['fecha_inicio'] ?? '';
    $fin = $t['fecha_limite'] ?? '';
    if ($ini === '' && $fin === '') continue;
    if ($ini === '') $ini = $fin;          // solo fin: un día
    if ($fin === '') $fin = $ini;          // solo inicio: un día
    if ($ini > $fin) { [$ini, $fin] = [$fin, $ini]; }
    $desde = max($ini, $mesIni);
    $hasta = min($fin, $mesFin);
    if ($desde > $hasta) continue;         // el tramo no cae en este mes
    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $pos = ($ini === $fin) ? 'solo' : ($d === $ini ? 'inicio' : ($d === $fin ? 'fin' : 'medio'));
        $eventosCal[$d][] = ['tipo' => 'tarea', 'dato' => $t, 'pos' => $pos, 'ini' => $ini, 'fin' => $fin];
    }
}
foreach ($reuniones as $r) {
    $dia = substr($r['inicio'] ?? '', 0, 10);
    if ($dia === '') continue;
    if (!Reuniones::esRecurrente($r)) {
        $eventosCal[$dia][] = ['tipo' => 'reunion', 'dato' => $r, 'pos' => 'solo'];
        continue;
    }
    // Una reunión que se repite cae en cada día marcado: se pinta en todos los
    // que toquen dentro del mes que se está viendo, no solo el primero.
    $dias  = Reuniones::diasValidos($r['dias']);
    $desde = max($dia, $mesIni);
    $hasta = min((string)$r['hasta'], $mesFin);
    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (in_array((int)date('N', strtotime($d)), $dias, true)) {
            $eventosCal[$d][] = ['tipo' => 'reunion', 'dato' => $r, 'pos' => 'solo'];
        }
    }
}

UI::inicio($proyecto['nombre'], 'proyecto-' . $id);
?>

<!-- Cabecera del proyecto -->
<header class="proyecto-hero" style="--pc:<?= $color ?>">
  <div class="ph-barra" title="Avance del proyecto: <?= $avance ?>%"><span style="width:<?= $avance ?>%"></span></div>
  <i class="fa-solid <?= e($proyecto['icono']) ?> ph-watermark"></i>
  <div class="ph-top">
    <a href="index.php" class="ph-back"><i class="fa-solid fa-arrow-left"></i> Proyectos</a>
    <div class="ph-actions">
      <?php foreach (ProyectoRepo::repos($proyecto) as $repo): ?>
      <a class="btn-meca btn-sm <?= e(Repos::clase($repo['url'])) ?>" href="<?= e($repo['url']) ?>" target="_blank" rel="noopener"
         title="Repositorio <?= e($repo['label']) ?> en <?= e(Repos::etiqueta($repo['url'])) ?>">
        <i class="<?= e(Repos::icono($repo['url'])) ?>"></i> <i class="fa-solid <?= e($repo['icono']) ?>"></i> <?= e($repo['label']) ?>
      </a>
      <?php endforeach; ?>
      <button class="btn-ghost btn-meca btn-sm solo-admin" onclick="document.getElementById('dlg-editar-proyecto').showModal()">
        <i class="fa-solid fa-pen"></i> Editar
      </button>
      <form method="post" action="actions.php" class="solo-admin"
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
        <?php if ($listoEntrega): ?>
          <span class="badge-entrega listo"><i class="fa-solid fa-circle-check"></i> Listo para entrega</span>
        <?php elseif ($obsPendientes > 0): ?>
          <a class="badge-entrega alerta" href="#vista-observaciones"><i class="fa-solid fa-triangle-exclamation"></i>
            <?= $obsPendientes ?> observación<?= $obsPendientes === 1 ? '' : 'es' ?> pendiente<?= $obsPendientes === 1 ? '' : 's' ?></a>
        <?php endif; ?>
        <?php if (!empty($proyecto['fecha_inicio'])): ?>
        <span class="ph-fecha"><i class="fa-regular fa-flag"></i> Inicia <?= e($proyecto['fecha_inicio']) ?></span>
        <?php endif; ?>
        <?php if ($equipoProyecto !== null): ?>
        <span class="ph-fecha"><i class="fa-solid fa-user-group"></i> <?= count($equipoProyecto) ?> participante<?= count($equipoProyecto) === 1 ? '' : 's' ?></span>
        <?php endif; ?>
        <span class="ph-fecha"><i class="fa-regular fa-calendar"></i> Creado <?= e($proyecto['creado'] ?? '') ?></span>
      </div>
      <h1 class="font-display"><?= e($proyecto['nombre']) ?></h1>
      <p><?= e($proyecto['descripcion']) ?></p>
    </div>
  </div>

  <!-- Avance abajo a la derecha: barra semaforo (rojo/amarillo/verde) -->
  <?php $nivelAvance = $avance >= 67 ? 'verde' : ($avance >= 34 ? 'amarillo' : 'rojo'); ?>
  <div class="ph-avance-abajo" title="<?= $completadas ?> de <?= array_sum($resumen) ?> tareas completadas">
    <small><?= $completadas ?>/<?= array_sum($resumen) ?> tareas</small>
    <div class="barra-semaforo sem-<?= $nivelAvance ?>"><span style="width:<?= $avance ?>%"></span></div>
    <b class="pam-num sem-txt-<?= $nivelAvance ?>"><?= $avance ?>%</b>
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

<!-- Cambio de vista + selector de persona -->
<?php
// Tareas abiertas por miembro dentro de este proyecto (para el selector y metricas)
$abiertasProyecto = [];
foreach ($tareas as $t) {
    if (!in_array($t['estado'] ?? '', $finales, true)) {
        foreach (TareaRepo::asignadosDe($t) as $mid) {
            $abiertasProyecto[$mid] = ($abiertasProyecto[$mid] ?? 0) + 1;
        }
    }
}
?>
<div class="vista-fila">
  <div class="vista-toggle">
    <button type="button" class="tab-btn" data-vista="calendario" data-tip="Calendario"><i class="fa-solid fa-calendar-days"></i> <span class="tab-txt">Calendario</span></button>
    <button type="button" class="tab-btn" data-vista="tareas" data-tip="Tareas"><i class="fa-solid fa-list-check"></i> <span class="tab-txt">Tareas</span>
      <span class="tab-badge tab-badge-neutro"><?= count($tareas) ?></span>
    </button>
    <button type="button" class="tab-btn" data-vista="observaciones" data-tip="Observaciones"><i class="fa-solid fa-comment-dots"></i> <span class="tab-txt">Observaciones</span>
      <?php if ($obsPendientes > 0): ?><span class="tab-badge"><?= $obsPendientes ?></span><?php endif; ?>
    </button>
    <button type="button" class="tab-btn" data-vista="intercambios" data-tip="Intercambios"><i class="fa-solid fa-right-left"></i> <span class="tab-txt">Intercambios</span>
      <?php if ($interPendientes > 0): ?><span class="tab-badge"><?= $interPendientes ?></span><?php endif; ?>
    </button>
    <button type="button" class="tab-btn" data-vista="reuniones" data-tip="Reuniones"><i class="fa-solid fa-video"></i> <span class="tab-txt">Reuniones</span>
      <?php if (count($reuniones)): ?><span class="tab-badge tab-badge-zoom"><?= count($reuniones) ?></span><?php endif; ?>
    </button>
    <button type="button" class="tab-btn" data-vista="metricas" data-tip="Métricas"><i class="fa-solid fa-chart-simple"></i> <span class="tab-txt">Métricas</span></button>
  </div>

  <!-- Subvistas de "Tareas": la misma lista vista como tabla, kanban o flujo -->
  <div class="subvista-toggle" hidden>
    <span class="subvista-tit">Ver como</span>
    <button type="button" class="subvista-btn active" data-subvista="tabla" data-tip="Tabla"><i class="fa-solid fa-table-list"></i> <span class="tab-txt">Tabla</span></button>
    <button type="button" class="subvista-btn" data-subvista="kanban" data-tip="Kanban"><i class="fa-solid fa-table-columns"></i> <span class="tab-txt">Kanban</span></button>
    <button type="button" class="subvista-btn" data-subvista="flujo" data-tip="Flujo"><i class="fa-solid fa-diagram-project"></i> <span class="tab-txt">Flujo</span></button>
  </div>
</div>

<div data-vista-panel="tabla">
<!-- Tabla de tareas -->
<section class="card-base tabla-card">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Tareas
      <span class="tabla-count"><?= count($visibles) ?></span>
    </h2>
    <div class="tabla-filtros">
      <?php if (!$verComo): ?>
      <form method="get" class="inline-form">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php if ($fEstado): ?><input type="hidden" name="estado" value="<?= e($fEstado) ?>"><?php endif; ?>
        <?= UI::select('asignado', $opcionesFiltro, (string)$fAsignado, true, 'select-sm') ?>
      </form>
      <?php endif; ?>
      <?php if ($fEstado || (!$verComo && $fAsignado)): ?>
      <a href="?id=<?= $id ?>" class="filtro-clear"><i class="fa-solid fa-filter-circle-xmark"></i> Limpiar</a>
      <?php endif; ?>
      <?php if ($nArchivadas > 0 && !in_array($fEstado, $finales, true)): ?>
        <?php if ($verArchivadas): ?>
        <a href="?id=<?= $id ?>" class="filtro-clear"><i class="fa-solid fa-eye-slash"></i> Ocultar archivadas</a>
        <?php else: ?>
        <a href="?id=<?= $id ?>&arch=1" class="filtro-clear" title="Completadas hace más de una semana (siguen guardadas)"><i class="fa-solid fa-box-archive"></i> Ver archivadas (<?= $nArchivadas ?>)</a>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" action="actions.php" class="inline-form" data-descarga>
        <input type="hidden" name="accion" value="proyecto_tareas_json">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="accion-btn accion-claude" data-tip="Descarga las tareas de este proyecto en JSON (con sus #id) para pasárselas a Claude">
          <img src="assets/claude.svg" alt="" width="16" height="16"> Descargar
        </button>
      </form>
      <?php if ($avisoPersonas): ?>
      <!-- Un correo por persona con las tareas que el admin elija en el modal -->
      <button type="button" class="accion-btn solo-admin" onclick="document.getElementById('dlg-avisar').showModal()"
              data-tip="Elige a quién avisar y con qué tareas; se manda un correo por persona">
        <i class="fa-solid fa-paper-plane"></i> Avisar al equipo
      </button>
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
            $asigIds = TareaRepo::asignadosDe($t);
            $asigLista = array_values(array_filter(array_map(fn($mid) => $miembros[$mid] ?? null, $asigIds)));
            $esFinal = in_array($t['estado'] ?? '', $finales, true);
            $vencida = !empty($t['fecha_limite']) && $t['fecha_limite'] < date('Y-m-d') && !$esFinal;
        ?>
        <tr class="fila-tarea <?= $esFinal ? 'fila-hecha' : '' ?>" data-ver-tarea='<?= $verTareaAttr($t) ?>'>
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
              <?php $nObs = $obsPorTarea[(int)$t['id']] ?? 0; if ($nObs > 0): ?>
              <a class="dep-tag obs-tag" href="#vista-observaciones" title="Tiene observaciones pendientes de revisión">
                <i class="fa-solid fa-comment-dots"></i> <?= $nObs ?> observación<?= $nObs === 1 ? '' : 'es' ?>
              </a>
              <?php endif; ?>
              <?php $nAdj = count(TareaRepo::adjuntosDe($t)); if ($nAdj > 0): ?>
              <small class="dep-tag adj-tag" title="Ábrelos desde el detalle de la tarea">
                <i class="fa-solid fa-paperclip"></i> <?= $nAdj ?> documento<?= $nAdj === 1 ? '' : 's' ?>
              </small>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div class="celda-persona">
              <?= UI::avatarsAsignados($t, $miembros, 34) ?>
              <?php if (count($asigLista) === 1): ?>
              <div class="cp-info">
                <span><?= e($asigLista[0]['nombre']) ?></span>
                <small><i class="fa-brands fa-github"></i> <?= e($asigLista[0]['git_user']) ?></small>
              </div>
              <?php elseif (count($asigLista) > 1): ?>
              <div class="cp-info">
                <span><?= e($asigLista[0]['nombre']) ?> +<?= count($asigLista) - 1 ?></span>
                <small><?= count($asigLista) ?> responsables</small>
              </div>
              <?php else: ?>
              <span class="cp-nadie">Sin asignar</span>
              <?php endif; ?>
            </div>
          </td>
          <td><?= UI::badgePrioridad($t['prioridad']) ?></td>
          <td><?= UI::selectEstadoTarea($t) ?></td>
          <td>
            <?php $arranque = TareaRepo::arranque($t); ?>
            <?php if ($arranque === 'programada'): ?>
              <span class="celda-fecha fecha-programada" title="Todavía no arranca: empieza el <?= e($t['fecha_inicio']) ?>">
                <i class="fa-regular fa-hourglass-half"></i> en <?= TareaRepo::diasParaArrancar($t) ?> d
              </span>
            <?php endif; ?>
            <?php if (!empty($t['fecha_limite'])): ?>
              <span class="celda-fecha <?= $vencida ? 'fecha-vencida' : '' ?>">
                <i class="fa-regular fa-calendar"></i> <?= e($t['fecha_limite']) ?>
                <?php if ($vencida): ?><i class="fa-solid fa-triangle-exclamation" title="Vencida"></i><?php endif; ?>
              </span>
            <?php elseif ($arranque === ''): ?>
              <span class="celda-fecha celda-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="celda-acciones">
            <button class="accion-btn solo-admin" title="Editar"
              data-editar-tarea='<?= e(json_encode([
                  'id' => (int)$t['id'],
                  'titulo' => $t['titulo'],
                  'descripcion' => $t['descripcion'] ?? '',
                  'prioridad' => $t['prioridad'],
                  'estado' => $t['estado'],
                  'asignados' => TareaRepo::asignadosDe($t),
                  'fecha_inicio' => $t['fecha_inicio'] ?? '',
                  'fecha_limite' => $t['fecha_limite'] ?? '',
                  'depende_de' => (int)($t['depende_de'] ?? 0), 'dependencias' => TareaRepo::dependenciasDe($t),
                  'adjuntos' => TareaRepo::adjuntosDe($t),
              ], JSON_UNESCAPED_UNICODE)) ?>'>
              <i class="fa-solid fa-pen"></i>
            </button>
            <form method="post" action="actions.php" class="inline-form solo-admin"
                  data-confirmar="Se eliminará la tarea «<?= e($t['titulo']) ?>»."
                  data-confirmar-titulo="¿Eliminar tarea?" data-confirmar-ok="Sí, eliminar">
              <input type="hidden" name="accion" value="tarea_eliminar">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="volver" value="<?= e($urlConFiltros) ?>">
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

<!-- Vista Kanban: columnas por estado, arrastra para cambiar -->
<div data-vista-panel="kanban" hidden>
  <section class="card-base tabla-card">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-table-columns text-secondary"></i> Kanban</h2>
      <span class="ajuste-ayuda"><i class="fa-solid fa-hand"></i> Arrastra una tarjeta a otra columna para cambiar su estado.</span>
    </div>
    <div class="kanban" style="--pc:<?= $color ?>">
      <?php foreach (Catalogo::estadosTarea() as $k => [$label, $icono]): ?>
      <div class="kb-col">
        <div class="kb-head estado-<?= $k ?>">
          <i class="fa-solid <?= $icono ?>"></i> <?= e($label) ?>
          <span class="kb-count"><?= (int)$resumen[$k] ?></span>
        </div>
        <div class="kb-cards" data-estado-drop="<?= e($k) ?>">
          <?php foreach ($tareas as $t): if (($t['estado'] ?? '') !== $k) continue; ?>
          <?php $puedoMover = esAdmin() || TareaRepo::tieneAsignado($t, $miId); ?>
          <div class="kb-card <?= $puedoMover ? '' : 'kb-fija' ?>" draggable="<?= $puedoMover ? 'true' : 'false' ?>" data-tarea="<?= (int)$t['id'] ?>" data-ver-tarea='<?= $verTareaAttr($t) ?>'>
            <b><?= e($t['titulo']) ?></b>
            <div class="kb-meta">
              <?= UI::avatarsAsignados($t, $miembros, 22) ?>
              <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
              <?php if (!empty($t['fecha_limite'])): ?>
              <small><i class="fa-regular fa-calendar"></i> <?= e($t['fecha_limite']) ?></small>
              <?php endif; ?>
              <?php $nDeps = count(TareaRepo::dependenciasDe($t)); if ($nDeps): ?>
              <small title="Depende de <?= $nDeps ?> tarea<?= $nDeps === 1 ? '' : 's' ?>"><i class="fa-solid fa-link"></i><?= $nDeps > 1 ? ' ' . $nDeps : '' ?></small>
              <?php endif; ?>
              <?php $nAdj = count(TareaRepo::adjuntosDe($t)); if ($nAdj > 0): ?>
              <small title="<?= $nAdj ?> documento<?= $nAdj === 1 ? '' : 's' ?> de respaldo"><i class="fa-solid fa-paperclip"></i> <?= $nAdj ?></small>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <form id="frm-kanban" method="post" action="actions.php" hidden>
    <input type="hidden" name="accion" value="tarea_estado">
    <input type="hidden" name="id" id="kb-id">
    <input type="hidden" name="estado" id="kb-estado">
  </form>
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
        // Alinear cada tarea cerca de su dependencia (lineas mas rectas)
        $posAnterior = [];
        foreach ($columnas as $nivel => $lista) {
            if ($nivel > 0 && $posAnterior) {
                usort($lista, fn($a, $b) =>
                    ($posAnterior[TareaRepo::dependenciasDe($a)[0] ?? 0] ?? 99) <=> ($posAnterior[TareaRepo::dependenciasDe($b)[0] ?? 0] ?? 99));
                $columnas[$nivel] = $lista;
            }
            $posAnterior = [];
            foreach ($lista as $i => $t) {
                $posAnterior[(int)$t['id']] = $i;
            }
        }
        foreach ($columnas as $nivel => $lista): ?>
        <div class="flujo-col">
          <h4><?= $nivel === 0 ? 'Inicio' : 'Fase ' . ($nivel + 1) ?></h4>
          <?php foreach ($lista as $t):
              $esFinalF = in_array($t['estado'] ?? '', $finales, true);
          ?>
          <div class="flujo-nodo <?= $esFinalF ? 'nodo-hecho' : '' ?> <?= $fAsignado && !TareaRepo::tieneAsignado($t, $fAsignado) ? 'nodo-ajeno' : '' ?>"
               id="fn-<?= (int)$t['id'] ?>" data-deps="<?= e(implode(',', TareaRepo::dependenciasDe($t))) ?>" data-ver-tarea='<?= $verTareaAttr($t) ?>'>
            <b><?= e($t['titulo']) ?></b>
            <div class="fn-meta">
              <?= UI::avatarsAsignados($t, $miembros, 24) ?>
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

<!-- Vista Calendario: fechas límite de tareas + reuniones -->
<div data-vista-panel="calendario" hidden>
  <section class="card-base tabla-card" style="--pc:<?= $color ?>">
    <div class="cal-head">
      <h2 class="font-display"><i class="fa-solid fa-calendar-days text-secondary"></i> <?= e($calTitulo) ?></h2>
      <div class="cal-nav">
        <?php if (esAdmin() && GoogleCalendar::listo()): ?>
        <form method="post" action="actions.php" class="cal-sync">
          <input type="hidden" name="accion" value="sincronizar_calendario">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="accion-btn" title="Enviar estas tareas al Google Calendar de cada responsable">
            <i class="fa-brands fa-google"></i> Sincronizar
          </button>
        </form>
        <?php endif; ?>
        <a class="accion-btn" href="?id=<?= $id ?>&mes=<?= $calPrev ?>#vista-calendario" title="Mes anterior"><i class="fa-solid fa-chevron-left"></i></a>
        <a class="accion-btn" href="?id=<?= $id ?>&mes=<?= date('Y-m') ?>#vista-calendario">Hoy</a>
        <a class="accion-btn" href="?id=<?= $id ?>&mes=<?= $calNext ?>#vista-calendario" title="Mes siguiente"><i class="fa-solid fa-chevron-right"></i></a>
      </div>
    </div>
    <div class="cal-scroll">
      <?php
      // Pinta un evento del calendario (tarea con su barra, o reunión). Se usa
      // en la celda y en el desplegable "+N" para no duplicar el HTML.
      $pintarEv = function (array $ev) use ($color, $finales, $hoyIso, $id, $verTareaAttr) {
          if ($ev['tipo'] === 'tarea') {
              $t = $ev['dato']; $pos = $ev['pos'];
              $venc = ($ev['fin'] < $hoyIso) && !in_array($t['estado'] ?? '', $finales, true);
              $datos = e(json_encode([
                  'id' => (int)$t['id'], 'titulo' => $t['titulo'], 'descripcion' => $t['descripcion'] ?? '',
                  'prioridad' => $t['prioridad'], 'estado' => $t['estado'], 'asignados' => TareaRepo::asignadosDe($t),
                  'fecha_inicio' => $t['fecha_inicio'] ?? '',
                  'fecha_limite' => $t['fecha_limite'] ?? '', 'depende_de' => (int)($t['depende_de'] ?? 0), 'dependencias' => TareaRepo::dependenciasDe($t),
                  'adjuntos' => TareaRepo::adjuntosDe($t),
              ], JSON_UNESCAPED_UNICODE));
              $rango = $ev['ini'] === $ev['fin'] ? $ev['ini'] : ($ev['ini'] . ' → ' . $ev['fin']);
              $attr = esAdmin() ? "data-editar-tarea='$datos'" : "data-ver-tarea='" . $verTareaAttr($t) . "'";
              if ($pos === 'inicio' || $pos === 'solo') {
                  echo '<button type="button" class="cal-ev cal-ev-tarea cal-' . $pos . ' ' . ($venc ? 'cal-venc' : '') . '"'
                     . ' style="--tc:' . $color . '" title="' . e($t['titulo']) . ' · ' . e($rango) . '" ' . $attr . '>'
                     . '<span class="prio-dot prio-' . e($t['prioridad'] ?? 'media') . '"></span>'
                     . e(mb_strimwidth($t['titulo'], 0, 22, '…')) . '</button>';
              } else {
                  echo '<button type="button" class="cal-ev cal-ev-linea cal-' . $pos . ' ' . ($venc ? 'cal-venc' : '') . '"'
                     . ' style="--tc:' . $color . '" title="' . e($t['titulo']) . ' · hasta ' . e($ev['fin']) . '" ' . $attr . '>'
                     . ($pos === 'fin' ? '<i class="fa-solid fa-flag-checkered"></i>' : '') . '</button>';
              }
          } else {
              $r = $ev['dato'];
              echo '<a class="cal-ev cal-ev-reunion" href="?id=' . $id . '#vista-reuniones" title="' . e($r['topic']) . '">'
                 . '<i class="fa-solid fa-video"></i> ' . e(substr($r['inicio'], 11, 5)) . ' '
                 . e(mb_strimwidth($r['topic'], 0, 16, '…')) . '</a>';
          }
      };
      ?>
      <div class="cal-dows">
        <?php foreach (['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'] as $d): ?><span class="cal-dow"><?= $d ?></span><?php endforeach; ?>
      </div>
      <div class="cal-grid">
        <?php
        // Celdas vacías antes del día 1
        for ($i = 0; $i < $calOffset; $i++) echo '<div class="cal-cell vacia"></div>';
        for ($d = 1; $d <= $calDias; $d++):
            $iso = sprintf('%s-%02d', $mesCal, $d);
            $evs = $eventosCal[$iso] ?? [];
        ?>
        <div class="cal-cell <?= $iso === $hoyIso ? 'hoy' : '' ?>">
          <span class="cal-num"><?= $d ?></span>
          <?php
          // Para no romper el estilo con días llenos: se muestran pocas y el
          // resto se guarda tras un chip "+N" que se abre al pulsarlo.
          $CAL_MAX = 4;
          $nEv = count($evs);
          $vis = $nEv > $CAL_MAX ? $CAL_MAX - 1 : $nEv;
          foreach (array_slice($evs, 0, $vis) as $ev) $pintarEv($ev);
          if ($nEv > $CAL_MAX): $resto = $nEv - $vis; ?>
          <button type="button" class="cal-mas" data-cal-mas title="Ver <?= $resto ?> más de este día">+<?= $resto > 99 ? '99+' : $resto ?></button>
          <div class="cal-mas-lista" hidden><?php foreach (array_slice($evs, $vis) as $ev) $pintarEv($ev); ?></div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <div class="cal-leyenda">
      <span><span class="prio-dot prio-alta"></span> Fecha límite de tarea</span>
      <span><i class="fa-solid fa-video" style="color:#2D8CFF"></i> Reunión</span>
      <span class="cal-venc-ley"><i class="fa-solid fa-triangle-exclamation"></i> Vencida</span>
    </div>
  </section>
</div>

<!-- Vista Intercambios de tareas -->
<div data-vista-panel="intercambios" hidden>
  <section class="card-base tabla-card" style="--pc:<?= $color ?>">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-right-left text-secondary"></i> Intercambios
        <span class="tabla-count"><?= count($intercambios) ?></span>
      </h2>
      <?php if ($puedeIntercambiar): ?>
      <button class="btn-primary btn-meca btn-sm" onclick="document.getElementById('dlg-intercambio').showModal()">
        <i class="fa-solid fa-right-left"></i> Proponer intercambio
      </button>
      <?php endif; ?>
    </div>

    <p class="ajuste-ayuda inter-intro">
      <i class="fa-solid fa-circle-info"></i>
      Si no puedes avanzar con una tarea —por salud, carga de trabajo o porque bloquea a alguien—
      ofrécela a cambio de otra. <b>No cambia nada hasta que la otra persona acepta.</b>
    </p>

    <?php if (empty($intercambios)): ?>
      <?= UI::vacio('fa-right-left', 'Sin intercambios',
            $puedeIntercambiar
              ? 'Nadie ha propuesto todavía. Usa «Proponer intercambio» si necesitas soltar una tarea.'
              : 'Para proponer un intercambio necesitas al menos una tarea tuya y otra de un compañero.') ?>
    <?php else: ?>
    <div class="inter-lista">
      <?php foreach ($intercambios as $x):
          $tA = $tareasPorId[(int)$x['tarea_de']]   ?? null;
          $tB = $tareasPorId[(int)$x['tarea_para']] ?? null;
          $mA = $miembros[(int)$x['de_id']]   ?? null;
          $mB = $miembros[(int)$x['para_id']] ?? null;
          $estado = $x['estado'] ?? 'pendiente';
          $mio    = (int)$x['de_id'] === $miId;
          $paraMi = (int)$x['para_id'] === $miId;
          [$mLabel, $mIcono] = Catalogo::MOTIVOS_INTERCAMBIO[$x['motivo']] ?? ['—', 'fa-circle-question'];
      ?>
      <article class="inter-item inter-<?= e($estado) ?>">
        <header class="inter-cab">
          <span class="inter-estado est-<?= e($estado) ?>">
            <i class="fa-solid <?= $estado === 'pendiente' ? 'fa-hourglass-half'
                : ($estado === 'aceptado' ? 'fa-circle-check'
                : ($estado === 'rechazado' ? 'fa-circle-xmark' : 'fa-ban')) ?>"></i>
            <?= ucfirst(e($estado)) ?>
          </span>
          <span class="inter-motivo" title="Motivo"><i class="fa-solid <?= e($mIcono) ?>"></i> <?= e($mLabel) ?></span>
          <span class="inter-fecha"><?= e($x['creado'] ?? '') ?></span>
        </header>

        <div class="inter-cuerpo">
          <div class="inter-lado">
            <?= UI::avatar($mA, 30, true) ?>
            <div>
              <small><?= e($mA['nombre'] ?? '?') ?> ofrece</small>
              <b><?= e($tA['titulo'] ?? 'tarea eliminada') ?></b>
            </div>
          </div>
          <i class="fa-solid fa-right-left inter-flecha"></i>
          <div class="inter-lado">
            <?= UI::avatar($mB, 30, true) ?>
            <div>
              <small><?= e($mB['nombre'] ?? '?') ?> daría</small>
              <b><?= e($tB['titulo'] ?? 'tarea eliminada') ?></b>
            </div>
          </div>
        </div>

        <?php if (!empty($x['nota'])): ?>
        <p class="inter-nota"><i class="fa-solid fa-quote-left"></i> <?= e($x['nota']) ?></p>
        <?php endif; ?>
        <?php if (!empty($x['respuesta'])): ?>
        <p class="inter-nota inter-respuesta"><i class="fa-solid fa-reply"></i> <?= e($x['respuesta']) ?></p>
        <?php endif; ?>

        <?php if ($estado === 'pendiente'): ?>
        <footer class="inter-acciones">
          <?php if ($paraMi || esAdmin()): ?>
          <form method="post" action="actions.php" class="inter-form">
            <input type="hidden" name="accion" value="intercambio_responder">
            <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <input class="input-meca input-sm" name="nota" maxlength="160" placeholder="Comentario (opcional)">
            <button class="btn-primary btn-meca btn-sm" name="respuesta" value="aceptar">
              <i class="fa-solid fa-check"></i> Aceptar
            </button>
            <button class="btn-outline btn-meca btn-sm" name="respuesta" value="rechazar">
              <i class="fa-solid fa-xmark"></i> Rechazar
            </button>
          </form>
          <?php elseif ($mio): ?>
          <form method="post" action="actions.php" class="inter-form"
                data-confirmar="Se retirará tu propuesta de intercambio." data-confirmar-titulo="¿Retirar propuesta?" data-confirmar-ok="Sí, retirar">
            <input type="hidden" name="accion" value="intercambio_cancelar">
            <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <button class="btn-outline btn-meca btn-sm"><i class="fa-solid fa-rotate-left"></i> Retirar propuesta</button>
          </form>
          <?php else: ?>
          <span class="ajuste-ayuda">Esperando la respuesta de <?= e($mB['nombre'] ?? '') ?>.</span>
          <?php endif; ?>
        </footer>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($puedeIntercambiar): ?>
<!-- Modal: proponer intercambio -->
<dialog id="dlg-intercambio" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="intercambio_crear">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <?= UI::wizardRiel('fa-right-left', 'Proponer intercambio', 'En ' . $proyecto['nombre'], UI::PASOS_INTERCAMBIO) ?>
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
          <span>La tarea que sueltas *</span>
          <?= UI::select('tarea_de', $opcionesMisTareas, (string)array_key_first($opcionesMisTareas)) ?>
          <small class="campo-ayuda">Solo aparecen tus tareas de este proyecto.</small>
        </label>
        <label class="campo">
          <span>La tarea que tomarías *</span>
          <?= UI::select('tarea_para', $opcionesOtrasTareas, (string)array_key_first($opcionesOtrasTareas)) ?>
          <small class="campo-ayuda">La propuesta le llegará a quien la tenga asignada.</small>
        </label>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Motivo *</span>
          <?= UI::select('motivo', array_map(fn($v) => $v[0], Catalogo::MOTIVOS_INTERCAMBIO), 'carga') ?>
        </label>
        <label class="campo">
          <span>Cuéntale por qué</span>
          <textarea class="input-meca" name="nota" rows="3" maxlength="400"
                    placeholder="Ej. Estoy con reposo hasta el viernes y tu tarea no depende de nadie."></textarea>
          <small class="campo-ayuda">Se incluye en el correo que recibirá.</small>
        </label>
      </section>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
        <p class="campo-ayuda"><i class="fa-solid fa-circle-info"></i>
          Al enviar no se cambia nada todavía: las tareas solo se cruzan si la otra persona acepta.</p>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-paper-plane"></i> Enviar propuesta</button>
        </div>
      </div>
    </div>
  </form>
</dialog>
<?php endif; ?>

<!-- Vista Reuniones (Zoom) -->
<div data-vista-panel="reuniones" hidden>
  <section class="card-base tabla-card" style="--pc:<?= $color ?>">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-video text-secondary"></i> Reuniones
        <span class="tabla-count"><?= count($reuniones) ?></span>
      </h2>
      <?php if ($reunionesOn): ?>
      <button class="btn-primary btn-meca solo-gestor" onclick="document.getElementById('dlg-nueva-reunion').showModal()">
        <i class="fa-solid fa-plus"></i> Nueva reunión
      </button>
      <?php else: ?>
      <a class="btn-outline btn-meca btn-sm" href="ajustes.php#tab-reuniones"><i class="fa-solid fa-gear"></i> Configurar reuniones</a>
      <?php endif; ?>
    </div>

    <?php if (!$reunionesOn): ?>
      <div class="obs-intro"><i class="fa-solid fa-video"></i>
        <span>Ve a <a href="ajustes.php#tab-reuniones">Ajustes → Reuniones</a> y configura Zoom —o activa Google
        Calendar— para crear reuniones desde aquí: sueltas o repetidas (por ejemplo, todos los días de lunes a
        viernes), con sus invitados y sus grabaciones.</span>
      </div>
    <?php endif; ?>
    <?php if (empty($reuniones)): ?>
      <?php if ($reunionesOn): ?><?= UI::vacio('fa-video', 'Sin reuniones', 'Crea la primera reunión (Zoom o Google Meet) de este proyecto con el botón de arriba.') ?><?php endif; ?>
    <?php else: ?>
    <div class="reu-lista">
      <?php foreach ($reuniones as $r):
          $repite = Reuniones::esRecurrente($r);
          // Una reunión que se repite no está "finalizada" hasta que pasa su último día
          $fin = $repite
              ? (int)strtotime($r['hasta'] . ' 23:59:59')
              : (int)strtotime($r['inicio'] ?? 'now') + ((int)$r['duracion'] * 60);
          $pasada = $fin < time();
          $invita = array_filter(array_map(fn($mid) => $miembros[$mid] ?? null, $r['invitados'] ?? []));
          $esMeet = ($r['plataforma'] ?? 'zoom') === 'meet';
      ?>
      <article class="reu-item">
        <div class="reu-icono <?= $pasada ? 'reu-pasada' : 'reu-proxima' ?> <?= $esMeet ? 'reu-meet' : 'reu-zoom' ?>">
          <i class="fa-solid <?= $esMeet ? 'fa-video' : 'fa-video' ?>"></i></div>
        <div class="reu-info">
          <b><?= e($r['topic']) ?></b>
          <span class="reu-meta">
            <span class="reu-plat <?= $esMeet ? 'plat-meet' : 'plat-zoom' ?>"><?= $esMeet ? 'Google Meet' : 'Zoom' ?></span>
            <i class="fa-regular fa-calendar"></i> <?= e($r['inicio']) ?> · <?= (int)$r['duracion'] ?> min
            <?php if ($repite): ?>
            <span class="reu-repite" title="<?= e(Reuniones::resumen($r)) ?>">
              <i class="fa-solid fa-repeat"></i> <?= e(Reuniones::etiqueta((array)$r['dias'])) ?> · hasta <?= e($r['hasta']) ?>
            </span>
            <?php endif; ?>
            <span class="reu-estado <?= $pasada ? 'e-pasada' : 'e-proxima' ?>"><?= $pasada ? 'Finalizada' : 'Próxima' ?></span>
          </span>
          <?php if ($invita): ?>
          <span class="reu-invitados"><?= UI::avatarStack(array_values($invita), 6, 26) ?>
            <small><?= count($invita) ?> invitado<?= count($invita) === 1 ? '' : 's' ?></small></span>
          <?php endif; ?>
        </div>
        <div class="reu-acciones">
          <?php if (!$pasada): ?>
          <a class="btn-meca btn-sm btn-zoom" href="<?= e($r['join_url']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-right-to-bracket"></i> Entrar</a>
          <?php if (!empty($r['start_url'])): ?>
          <a class="accion-btn" href="<?= e($r['start_url']) ?>" target="_blank" rel="noopener" title="Iniciar como anfitrión"><i class="fa-solid fa-crown"></i></a>
          <?php endif; ?>
          <?php endif; ?>
          <?php if (!$esMeet): /* grabación y transcripción son de Zoom */ ?>
          <?php if (!empty($r['grabaciones'])): ?>
            <?php foreach ($r['grabaciones'] as $g): if (!empty($g['play'])): ?>
            <a class="accion-btn accion-grab" href="<?= e($g['play']) ?>" target="_blank" rel="noopener" title="Ver grabación (<?= e($g['tipo']) ?>)"><i class="fa-solid fa-circle-play"></i> Grabación</a>
            <?php break; endif; endforeach; ?>
            <?php if (!empty($r['grab_password'])): ?>
            <span class="chip-copiar grab-codigo" title="Código de la grabación (por si Zoom lo pide)">
              <code><i class="fa-solid fa-key"></i> <?= e($r['grab_password']) ?></code>
              <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e($r['grab_password']) ?>" title="Copiar código"><i class="fa-regular fa-copy"></i></button>
            </span>
            <?php endif; ?>
          <?php elseif ($pasada): ?>
          <form method="post" action="actions.php" class="inline-form">
            <input type="hidden" name="accion" value="reunion_grabaciones">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="accion-btn" title="Buscar grabación en Zoom"><i class="fa-solid fa-cloud-arrow-down"></i> Grabación</button>
          </form>
          <?php endif; ?>
          <?php if ($pasada): ?>
          <!-- Transcripción para Claude: la acción la busca en vivo en Zoom -->
          <form method="post" action="actions.php" class="inline-form" data-descarga>
            <input type="hidden" name="accion" value="reunion_transcripcion">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="accion-btn accion-claude" data-tip="Lleva esto a Claude Code para que tenga el contexto de la reunión">
              <img src="assets/claude.svg" alt="" width="16" height="16"> Transcripción
            </button>
          </form>
          <?php endif; ?>
          <?php endif; /* !$esMeet */ ?>
          <?php $reuData = htmlspecialchars(json_encode([
              'id'        => (int)$r['id'],
              'topic'     => (string)($r['topic'] ?? ''),
              'inicio'    => str_replace(' ', 'T', (string)($r['inicio'] ?? '')),
              'duracion'  => (int)$r['duracion'],
              'invitados' => array_map('intval', $r['invitados'] ?? []),
              'recurrente'=> $repite,
              'dias'      => array_map('intval', (array)($r['dias'] ?? [])),
              'hasta'     => (string)($r['hasta'] ?? ''),
          ], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>
          <button type="button" class="accion-btn solo-gestor js-editar-reunion" data-editar-reunion='<?= $reuData ?>' title="Editar / invitar a más gente"><i class="fa-solid fa-pen"></i></button>
          <form method="post" action="actions.php" class="inline-form solo-gestor"
                data-confirmar="Se eliminará la reunión «<?= e($r['topic']) ?>» del panel y de <?= $esMeet ? 'Google Calendar' : 'Zoom' ?>."
                data-confirmar-titulo="¿Eliminar reunión?" data-confirmar-ok="Sí, eliminar">
            <input type="hidden" name="accion" value="reunion_eliminar">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<!-- Vista Observaciones: revisión (analistas / programadores) -->
<div data-vista-panel="observaciones" hidden>
  <section class="card-base tabla-card obs-card" style="--pc:<?= $color ?>">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-comment-dots text-secondary"></i> Observaciones
        <span class="tabla-count"><?= $obsResumen['total'] ?></span>
      </h2>
      <div class="tabla-filtros">
        <div class="obs-filtros" id="obs-filtros">
          <button type="button" class="chip-filtro active" data-filtro="todas">Todas</button>
          <button type="button" class="chip-filtro" data-filtro="pendiente">Pendientes <?php if ($obsPendientes): ?>· <?= $obsPendientes ?><?php endif; ?></button>
          <button type="button" class="chip-filtro" data-filtro="resuelta">Resueltas</button>
        </div>
        <button type="button" class="btn-outline btn-meca btn-sm" id="obs-add-nota" title="Abrir otro cuadro para anotar en paralelo">
          <i class="fa-solid fa-plus"></i> Otra nota
        </button>
      </div>
    </div>

    <?php
    /** Compositor rápido de observación (reutilizable: inicial + template). */
    function composerObs(int $id, array $opcionesFiltro, int $fAsignado, array $opcionesDependencia, array $opcionesReunion): void { ?>
    <form class="obs-composer" method="post" action="actions.php" enctype="multipart/form-data">
      <button type="button" class="oc-cerrar" title="Quitar esta nota"><i class="fa-solid fa-xmark"></i></button>
      <input type="hidden" name="accion" value="obs_crear">
      <input type="hidden" name="proyecto_id" value="<?= $id ?>">
      <div class="oc-top">
        <?= UI::select('autor_id', $opcionesFiltro, (string)$fAsignado, false, 'oc-select') ?>
        <select name="tarea_id[]" class="select-meca oc-select" multiple data-ph="General de la entrega — o elige tareas">
          <?php foreach (array_slice($opcionesDependencia, 1, null, true) as $tid => $lbl): ?>
          <option value="<?= (int)$tid ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($opcionesReunion): ?>
        <?= UI::select('reunion_id', [0 => 'Sin reunión'] + $opcionesReunion, '0', false, 'oc-select') ?>
        <?php endif; ?>
      </div>
      <div class="oc-campo">
        <textarea name="texto" class="oc-texto" rows="2"
          placeholder="Escribe una observación… pega capturas con Ctrl+V o arrástralas aquí."></textarea>
        <div class="oc-previews"></div>
      </div>
      <div class="oc-pie">
        <label class="oc-adjuntar" title="Adjuntar imágenes, PDF o Word">
          <input type="file" class="oc-file" name="adjuntos[]" multiple hidden
                 accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,.doc,.docx">
          <i class="fa-solid fa-paperclip"></i> Adjuntar
        </label>
        <span class="oc-hint"><i class="fa-regular fa-clipboard"></i> Ctrl+V pega imágenes · Ctrl+Enter guarda</span>
        <button type="submit" class="btn-primary btn-meca btn-sm"><i class="fa-solid fa-comment-medical"></i> Anotar</button>
      </div>
    </form>
    <?php } ?>

    <!-- Compositores (hasta 3 en paralelo para anotar en reuniones) -->
    <?php $opcionesReunion = $reunionesRepo->opciones($id); ?>
    <div class="obs-composers" id="obs-composers" data-max="3">
      <?php composerObs($id, $opcionesFiltro, (int)$fAsignado, $opcionesDependencia, $opcionesReunion); ?>
    </div>
    <template id="tpl-composer"><?php composerObs($id, $opcionesFiltro, (int)$fAsignado, $opcionesDependencia, $opcionesReunion); ?></template>

    <?php require_once __DIR__ . '/lib/obs_item.php'; ?>
    <div class="obs-lista" id="obs-lista">
      <?php if (empty($observaciones)): ?>
        <?= UI::vacio('fa-clipboard-check', 'Sin observaciones', 'Aún no hay observaciones de revisión. Anota la primera con el compositor de arriba.') ?>
      <?php else: foreach ($observaciones as $o) echo obsItemHtml($o); endif; ?>
    </div>
  </section>
</div>

<!-- Vista Métricas: actividad del repo + carga por persona -->
<div data-vista-panel="metricas" hidden>

<?php
// Un solo gráfico de aportes: commits de TODOS los repos del proyecto,
// filtrables por persona, por rama y por rango de fechas.
// Los commits y las ramas se traen por AJAX (aportes.php) al abrir esta vista,
// para no frenar la carga de la página con llamadas a la API del proveedor.
$commitsProyecto = [];

// Icono del bloque: la marca del proveedor cuando todos los repos son del
// mismo, y uno neutro si el proyecto mezcla GitHub con GitLab.
$provsProyecto = array_unique(array_map(fn($rp) => Repos::proveedor($rp['url']), $reposProyecto));
$iconoAportes  = count($provsProyecto) === 1
    ? Repos::icono($reposProyecto[array_key_first($reposProyecto)]['url'])
    : 'fa-solid fa-code-branch';

$comMiembros = [];
foreach ($miembros as $m) {
    if (empty($m['git_user'])) continue;
    $comMiembros[] = [
        'id' => (int)$m['id'], 'git' => strtolower($m['git_user']), 'n' => $m['nombre'],
        'c' => Catalogo::colorDe($m['color'] ?? 0), 'ini' => MiembroRepo::iniciales($m), 'foto' => $m['foto'] ?? '',
    ];
}
$comTareas = [];
foreach ($tareas as $t) {
    $comTareas[(int)$t['id']] = mb_strimwidth($t['titulo'], 0, 50, '…');
}
$comReposLabels = array_map(fn($rp) => $rp['label'], $reposProyecto);
$comData = json_encode([
    'commits'  => $commitsProyecto,
    'miembros' => $comMiembros,
    'tareas'   => $comTareas,
    'repos'    => $comReposLabels,
], JSON_UNESCAPED_UNICODE);
?>
<?php if (!empty($comMiembros) && $reposProyecto): ?>
<section class="card-base tabla-card met-aportes" style="--pc:<?= $color ?>" data-aportes data-aportes-lazy="1" data-proyecto="<?= $id ?>">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="<?= e($iconoAportes) ?>"></i> Aportes del equipo
      <span class="ap-total"></span>
      <span class="ap-cargando" hidden><i class="fa-solid fa-circle-notch fa-spin"></i></span>
    </h2>
    <div class="tabla-filtros ap-filtros">
      <select class="select-meca select-sm ap-persona">
        <option value="0">Todo el equipo</option>
        <?php foreach ($comMiembros as $cm): ?>
        <option value="<?= $cm['id'] ?>"><?= e($cm['n']) ?></option>
        <?php endforeach; ?>
      </select>
      <!-- La lista de ramas la rellena el JS tras traerlas por AJAX -->
      <select class="select-meca select-sm ap-rama" hidden>
        <option value="">Rama por defecto</option>
      </select>
      <div class="subvista-toggle ap-rango">
        <button type="button" class="subvista-btn" data-dias="30">30 d</button>
        <button type="button" class="subvista-btn" data-dias="90">90 d</button>
        <button type="button" class="subvista-btn active" data-dias="182">6 m</button>
        <button type="button" class="subvista-btn" data-dias="365">1 año</button>
      </div>
      <button type="button" class="btn-outline btn-meca btn-sm ap-ver-commits"><i class="fa-solid fa-list"></i> Ver commits</button>
    </div>
  </div>
  <div class="metricas-cuerpo">
    <!-- Skeleton mientras se traen los commits de GitHub -->
    <div class="ap-skeleton sk-fade">
      <div class="ap-sk-lb">
        <span class="sk sk-fila"></span><span class="sk sk-fila"></span><span class="sk sk-fila"></span>
      </div>
      <div class="ap-sk-mapas">
        <span class="sk sk-mapa"></span><span class="sk sk-mapa"></span>
      </div>
    </div>
    <div class="ap-leaderboard" hidden></div>
    <div class="ap-mapas" hidden></div>
    <p class="ap-vacio actividad-msj" hidden><i class="fa-solid fa-mug-hot"></i> Sin commits con esos filtros.</p>
  </div>
  <script type="application/json" data-aportes-data><?= $comData ?></script>

  <!-- Commits a pantalla completa, paginados de 25 en 25 -->
  <dialog class="dlg-meca dlg-commits ap-dialogo">
    <div class="dlg-form">
      <header>
        <h3 class="font-display"><i class="<?= e($iconoAportes) ?> text-secondary"></i> Commits <span class="apc-sub"></span></h3>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>
      <ol class="apc-lista"></ol>
      <footer class="apc-pie">
        <button type="button" class="btn-outline btn-meca btn-sm apc-prev"><i class="fa-solid fa-arrow-left"></i> Anterior</button>
        <span class="apc-pag"></span>
        <button type="button" class="btn-outline btn-meca btn-sm apc-next">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
      </footer>
    </div>
  </dialog>
</section>
<?php endif; ?>

<div class="met-2col">
<section class="card-base tabla-card" style="--pc:<?= $color ?>">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-clipboard-check text-secondary"></i> Observaciones de revisión</h2>
    <span class="ajuste-ayuda"><?= $obsResumen['pendientes'] ?> pendientes · <?= $obsResumen['resueltas'] ?> resueltas</span>
  </div>
  <div class="metricas-cuerpo">
    <?php if ($obsResumen['total'] === 0): ?>
      <p class="actividad-msj"><i class="fa-solid fa-clipboard-check"></i> Sin observaciones registradas todavía.</p>
    <?php else: foreach ($equiposCat as $ek => [$eLabel, $eIcono]):
        $d = $obsResumen['porEquipo'][$ek] ?? ['pendientes' => 0, 'resueltas' => 0];
        $tot = $d['pendientes'] + $d['resueltas'];
        if ($tot === 0) continue;
    ?>
    <div class="obs-metrica">
      <span class="om-equipo"><i class="fa-solid <?= e($eIcono) ?>"></i> <?= e($eLabel) ?></span>
      <div class="om-barra">
        <span class="om-pend" style="flex:<?= $d['pendientes'] ?>"></span>
        <span class="om-res"  style="flex:<?= $d['resueltas'] ?>"></span>
      </div>
      <span class="om-nums"><b class="om-num-pend"><?= $d['pendientes'] ?></b> pend · <b class="om-num-res"><?= $d['resueltas'] ?></b> resueltas</span>
    </div>
    <?php endforeach; endif; ?>
  </div>
</section>

<section class="card-base tabla-card" style="--pc:<?= $color ?>">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-chart-simple text-secondary"></i> Carga del equipo</h2>
    <span class="ajuste-ayuda">Tareas abiertas por persona en este proyecto.</span>
  </div>
  <div class="metricas-cuerpo">
    <?php
    $maxCarga = max(1, ...array_values($abiertasProyecto ?: [0]));
    $conCarga = array_filter($miembros, fn($m) => isset($abiertasProyecto[(int)$m['id']]));
    ?>
    <?php if (empty($conCarga)): ?>
      <p class="actividad-msj"><i class="fa-solid fa-mug-hot"></i> Nadie tiene tareas abiertas en este proyecto.</p>
    <?php else: foreach ($conCarga as $m):
        $n = $abiertasProyecto[(int)$m['id']];
    ?>
    <div class="carga-fila">
      <?= UI::avatar($m, 34) ?>
      <span class="carga-nombre"><?= e($m['nombre']) ?></span>
      <div class="carga-barra"><span style="width:<?= (int)($n * 100 / $maxCarga) ?>%"></span></div>
      <b class="carga-num"><?= $n ?></b>
    </div>
    <?php endforeach; endif; ?>
  </div>
</section>
</div>


</div><!-- /metricas -->

<?php if ($reunionesOn): ?>
<!-- Modal: nueva reunión (Zoom o Google Meet) -->
<dialog id="dlg-nueva-reunion" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="reunion_crear">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-video text-secondary"></i> Nueva reunión</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <label class="campo"><span>Tema de la reunión *</span>
      <input class="input-meca" name="topic" required maxlength="120" placeholder="Ej. Revisión de avances — <?= e($proyecto['nombre']) ?>">
    </label>
    <div class="campo-doble">
      <label class="campo"><span>Fecha y hora *</span>
        <input class="input-meca" type="datetime-local" name="inicio" required value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
      </label>
      <label class="campo"><span>Duración (min)</span>
        <?= UI::select('duracion', $durOpciones, (string)$durDefecto) ?>
      </label>
    </div>

    <!-- Repeticion semanal: una sola reunion con un enlace fijo -->
    <div class="campo repetir-caja">
      <label class="chk-linea" title="Se crea una sola reunión con el mismo enlace para todos los días.<?= $zoomListo ? ' En Zoom, máximo 60 repeticiones por serie.' : '' ?>">
        <input type="checkbox" name="recurrente" value="1" class="js-repetir">
        <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
        Repetir todas las semanas
      </label>
      <div class="repetir-detalle" hidden>
        <div class="dias-semana">
          <?php foreach (Reuniones::DIAS as $dn => [$corto, $largo]): ?>
          <label class="dia-chip" title="<?= e($largo) ?>">
            <input type="checkbox" name="dias[]" value="<?= $dn ?>" <?= $dn <= 5 ? 'checked' : '' ?>>
            <span><?= e($corto) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <label class="repetir-hasta"><span>hasta</span>
          <input class="input-meca" type="date" name="hasta" value="<?= date('Y-m-d', strtotime('+1 month')) ?>">
        </label>
      </div>
    </div>

    <?php if ($platElegir): ?>
    <div class="campo"><span>Plataforma</span>
      <input type="hidden" name="plataforma" value="<?= e($platDefecto) ?>">
      <div class="subvista-toggle nr-plat">
        <button type="button" class="subvista-btn <?= $platDefecto === 'zoom' ? 'active' : '' ?>" data-plat="zoom"><i class="fa-solid fa-video"></i> Zoom</button>
        <button type="button" class="subvista-btn <?= $platDefecto === 'meet' ? 'active' : '' ?>" data-plat="meet"><i class="fa-brands fa-google"></i> Google Meet</button>
      </div>
      <small class="campo-ayuda nr-meet-hint" <?= $platDefecto === 'meet' ? '' : 'hidden' ?>>La reunión de Meet se crea en TU Google Calendar (necesitas tenerlo conectado en Mi perfil).</small>
    </div>
    <?php else:
      // Sin selector conviene decir de dónde sale la plataforma, o parece impuesta sin motivo
      $platOrigen = ProyectoRepo::plataformaEntrada($proyecto['plataforma'] ?? '') !== ''
          ? 'la elegida para este proyecto'
          : (count(Reuniones::disponibles()) < 2 ? 'la única configurada' : 'la del panel');
    ?>
    <input type="hidden" name="plataforma" value="<?= e($platDefecto) ?>">
    <div class="campo"><span>Plataforma</span>
      <small class="campo-ayuda"><b><?= e(Reuniones::etiquetaPlataforma($platDefecto)) ?></b><?= $platDefecto === 'meet' ? ', en tu calendario' : '' ?>
        — <?= $platOrigen ?>. Se cambia en <?= $platOrigen === 'la elegida para este proyecto' ? 'Editar proyecto' : 'Ajustes → Reuniones' ?>.</small>
    </div>
    <?php endif; ?>

    <label class="campo"><span>Invitar (registra a las personas del equipo)</span>
      <?= UI::select('invitados', $opcionesInvitados, [], false, '', true) ?>
      <small class="campo-ayuda">Se les enviará el enlace por correo si tienen uno registrado<?= Reuniones::agendaEnCalendarios() ? ', y se les agenda en su Google Calendar' : '' ?>.</small>
    </label>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-video"></i> Crear reunión</button>
    </footer>
  </form>
</dialog>

<!-- Modal: editar reunión / invitar a más gente -->
<dialog id="dlg-editar-reunion" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="reunion_editar">
    <input type="hidden" name="id" id="er-id" value="">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-pen text-secondary"></i> Editar reunión</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <label class="campo"><span>Tema de la reunión *</span>
      <input class="input-meca" name="topic" id="er-topic" required maxlength="120">
    </label>
    <div class="campo-doble">
      <label class="campo"><span>Fecha y hora *</span>
        <input class="input-meca" type="datetime-local" name="inicio" id="er-inicio" required>
      </label>
      <label class="campo"><span>Duración (min)</span>
        <?= UI::select('duracion', $durOpciones, (string)$durDefecto, false, 'js-er-duracion') ?>
      </label>
    </div>

    <div class="campo repetir-caja">
      <label class="chk-linea" title="Al guardar se actualiza toda la serie, no un día suelto.">
        <input type="checkbox" name="recurrente" value="1" class="js-repetir js-er-recurrente">
        <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
        Repetir todas las semanas
      </label>
      <div class="repetir-detalle" hidden>
        <div class="dias-semana js-er-dias">
          <?php foreach (Reuniones::DIAS as $dn => [$corto, $largo]): ?>
          <label class="dia-chip" title="<?= e($largo) ?>">
            <input type="checkbox" name="dias[]" value="<?= $dn ?>">
            <span><?= e($corto) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <label class="repetir-hasta"><span>hasta</span>
          <input class="input-meca js-er-hasta" type="date" name="hasta">
        </label>
      </div>
    </div>

    <label class="campo"><span>Invitar (añade a más personas del equipo)</span>
      <?= UI::select('invitados', $opcionesInvitados, [], false, 'js-er-invitados', true) ?>
      <small class="campo-ayuda">A los invitados nuevos se les enviará el enlace por correo.</small>
    </label>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<!-- Modal: nueva tarea (asistente por pasos) -->
<dialog id="dlg-nueva-tarea" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="tarea_crear">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <?= UI::wizardRiel('fa-circle-plus', 'Nueva tarea', 'En ' . $proyecto['nombre'], UI::PASOS_TAREA) ?>
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
          <span>Título *</span>
          <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Implementar login con Google">
        </label>
        <label class="campo">
          <span>Descripción</span>
          <textarea class="input-meca" name="descripcion" rows="3" placeholder="Detalles, criterios de aceptación..."></textarea>
        </label>
        <div class="campo-doble">
          <label class="campo"><span>Prioridad</span><?= UI::select('prioridad', array_map(fn($v) => $v[0], Catalogo::prioridades()), 'media') ?></label>
          <label class="campo"><span>Estado inicial</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()), 'pendiente') ?></label>
        </div>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Responsables</span>
          <?= UI::select('asignados', $opcionesAsignar, [], false, '', true) ?>
          <small class="campo-ayuda">Puedes elegir varias personas. <?= UI::ayudaEquipoProyecto($equipoProyecto) ?></small>
        </label>
        <label class="campo">
          <span>Depende de (opcional)</span>
          <?= UI::select('dependencias', $opcionesDeps, [], false, '', true) ?>
          <small class="campo-ayuda">Puedes elegir varias. La tarea queda "en espera" hasta que TODAS se completen.</small>
        </label>
        <?= UI::adjuntosTarea() ?>
      </section>

      <section class="wz-panel">
        <div class="campo-doble">
          <label class="campo"><span>Fecha de inicio</span>
            <input class="input-meca" type="date" name="fecha_inicio">
          </label>
          <label class="campo"><span>Fecha límite</span>
            <input class="input-meca" type="date" name="fecha_limite">
          </label>
        </div>
        <?= UI::atajosFecha() ?>
      </section>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-check"></i> Crear tarea</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<!-- Modal: editar tarea (se rellena por JS) -->
<dialog id="dlg-editar-tarea" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="tarea_editar">
    <input type="hidden" name="id" id="et-id">
    <input type="hidden" name="volver" value="<?= e($urlConFiltros) ?>">
    <?= UI::wizardRiel('fa-pen', 'Editar tarea', 'En ' . $proyecto['nombre'], UI::PASOS_TAREA) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo"><span>Título *</span><input class="input-meca" name="titulo" id="et-titulo" required maxlength="120"></label>
        <label class="campo"><span>Descripción</span><textarea class="input-meca" name="descripcion" id="et-descripcion" rows="3"></textarea></label>
        <div class="campo-doble">
          <label class="campo"><span>Prioridad</span><?= UI::select('prioridad', array_map(fn($v) => $v[0], Catalogo::prioridades()), 'media', false, 'js-et-prioridad') ?></label>
          <label class="campo"><span>Estado</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()), 'pendiente', false, 'js-et-estado') ?></label>
        </div>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Responsables</span>
          <?= UI::select('asignados', $opcionesAsignar, [], false, 'js-et-asignado', true) ?>
          <small class="campo-ayuda">Puedes elegir varias personas. <?= UI::ayudaEquipoProyecto($equipoProyecto) ?></small>
        </label>
        <label class="campo">
          <span>Depende de (opcional)</span>
          <?= UI::select('dependencias', $opcionesDeps, [], false, 'js-et-depende', true) ?>
          <small class="campo-ayuda">Puedes elegir varias. No puede depender de sí misma ni formar ciclos (se valida al guardar).</small>
        </label>
        <?= UI::adjuntosTarea() ?>
      </section>

      <section class="wz-panel">
        <div class="campo-doble">
          <label class="campo"><span>Fecha de inicio</span>
            <input class="input-meca" type="date" name="fecha_inicio" id="et-inicio">
          </label>
          <label class="campo"><span>Fecha límite</span>
            <input class="input-meca" type="date" name="fecha_limite" id="et-fecha">
          </label>
        </div>
        <?= UI::atajosFecha() ?>
      </section>

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
</dialog>

<?php if ($avisoPersonas): ?>
<!-- Modal: avisar al equipo. Se elige a quién le llega y con qué tareas dentro. -->
<dialog id="dlg-avisar" class="dlg-meca dlg-avisar">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="tareas_avisar">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <?php
      $avisoConCorreo = 0;
      foreach ($avisoPersonas as $per) if (!empty($per['miembro']['email'])) $avisoConCorreo++;
    ?>
    <header class="av-head">
      <div>
        <h3 class="font-display"><i class="fa-solid fa-paper-plane text-secondary"></i> Avisar al equipo</h3>
        <p>Un correo por persona con las tareas que marques. Nadie recibe un correo por cada tarea.</p>
      </div>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <?php if ($avisoConCorreo === 0): ?>
    <!-- Sin correos no hay nada que hacer: se dice antes de que lo descubra a base de clics -->
    <p class="av-alerta">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span>Nadie del equipo tiene correo registrado, así que todavía no se puede enviar.
        Añádelos en <a href="equipo.php">Equipo</a> y vuelve aquí.</span>
    </p>
    <?php endif; ?>

    <label class="campo">
      <span>Mensaje para el equipo (opcional)</span>
      <textarea class="input-meca" name="nota" rows="2" maxlength="400"
                placeholder="Ej. Arrancamos el sprint hoy; cualquier duda, en la daily de las 10:00."></textarea>
    </label>

    <div class="av-barra">
      <span class="av-barra-tit">Destinatarios</span>
      <button type="button" class="av-marcar" data-marcar="1">Marcar todo</button>
      <button type="button" class="av-marcar" data-marcar="0">Ninguno</button>
    </div>

    <div class="av-lista" data-avisar>
      <?php foreach ($avisoPersonas as $mid => $per):
        $m = $per['miembro'];
        $sinCorreo = empty($m['email']);
        $n = count($per['tareas']);
        // La fecha más próxima resume la urgencia sin desplegar la lista
        $proxima = '';
        foreach ($per['tareas'] as $t) {
            if (!empty($t['fecha_limite'])) { $proxima = $t['fecha_limite']; break; }
        }
      ?>
      <section class="av-persona <?= $sinCorreo ? 'av-sincorreo' : '' ?>">
        <div class="av-cab">
          <label class="av-marca">
            <input type="checkbox" class="av-todo" <?= $sinCorreo ? 'disabled' : 'checked' ?>>
            <span class="av-marca-caja"><i class="fa-solid fa-check"></i></span>
          </label>
          <?= UI::avatar($m, 34) ?>
          <span class="av-quien">
            <b><?= e($m['nombre']) ?></b>
            <small>
              <?php if ($sinCorreo): ?>
                <i class="fa-solid fa-circle-exclamation"></i> sin correo · <a href="equipo.php">añádelo en Equipo</a>
              <?php else: ?>
                <?= e($m['email']) ?>
              <?php endif; ?>
            </small>
          </span>
          <span class="av-meta">
            <span class="av-n"><?= $n ?> <?= $n === 1 ? 'tarea' : 'tareas' ?></span>
            <?php if ($proxima !== ''): ?><small>hasta <?= e($proxima) ?></small><?php endif; ?>
          </span>
          <button type="button" class="av-abrir" title="Ver y elegir sus tareas">
            <i class="fa-solid fa-chevron-down"></i>
          </button>
        </div>
        <ul class="av-tareas" hidden>
          <?php foreach ($per['tareas'] as $t): ?>
          <li>
            <label>
              <span class="av-marca">
                <input type="checkbox" name="avisar[<?= (int)$mid ?>][]" value="<?= (int)$t['id'] ?>"
                       <?= $sinCorreo ? 'disabled' : 'checked' ?>>
                <span class="av-marca-caja"><i class="fa-solid fa-check"></i></span>
              </span>
              <span class="av-t-titulo"><?= e($t['titulo']) ?></span>
              <small><?= e($t['fecha_limite'] ?? '') ?: 'sin fecha' ?></small>
            </label>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endforeach; ?>
    </div>

    <footer class="av-pie">
      <span class="av-resumen"></span>
      <div class="av-acciones">
        <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
        <button class="btn-primary btn-meca"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
      </div>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<!-- Modal: detalle de tarea (solo lectura, lo abre cualquiera) -->
<dialog id="dlg-detalle-tarea" class="dlg-meca dlg-detalle">
  <div class="dlg-form dt-body">
   <div class="dt-cols">
    <div class="dt-info">
    <header class="dt-head">
      <div class="dt-head-txt">
        <span class="dt-proyecto"></span>
        <h3 class="dt-titulo font-display"></h3>
      </div>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="dt-chips"></div>
    <span class="dt-restante" hidden></span>
    <p class="dt-desc"></p>
    <dl class="dt-datos">
      <div><dt><i class="fa-solid fa-user"></i> Responsables</dt><dd class="dt-asignados"></dd></div>
      <div><dt><i class="fa-regular fa-calendar"></i> Fechas</dt><dd class="dt-fechas"></dd></div>
      <div class="dt-fila-dep" hidden><dt><i class="fa-solid fa-link"></i> Dependencia</dt><dd class="dt-dep"></dd></div>
      <div class="dt-fila-obs" hidden><dt><i class="fa-solid fa-comment-dots"></i> Observaciones</dt><dd class="dt-obs"></dd></div>
      <div class="dt-fila-creada" hidden><dt><i class="fa-regular fa-clock"></i> Creada</dt><dd class="dt-creada"></dd></div>
      <div class="dt-fila-adj" hidden><dt><i class="fa-solid fa-paperclip"></i> Documentos</dt><dd class="dt-adjuntos"></dd></div>
    </dl>
    <footer class="dt-foot">
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cerrar</button>
    </footer>
    </div>

    <!-- Con documentos, el primero ya se ve aqui al abrir la tarea -->
    <aside class="dt-previa" hidden>
      <header class="dt-previa-cab">
        <span class="dt-previa-nom"><i class="fa-solid fa-paperclip"></i> <b></b></span>
      </header>
      <div class="dt-previa-cuerpo"></div>
    </aside>
   </div>
  </div>
</dialog>

<!-- Modal: editar proyecto (asistente por pasos) -->
<dialog id="dlg-editar-proyecto" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="proyecto_editar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?= UI::wizardRiel('fa-folder-open', 'Editar proyecto', $proyecto['nombre'], UI::PASOS_PROYECTO) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo"><span>Nombre *</span><input class="input-meca" name="nombre" required value="<?= e($proyecto['nombre']) ?>"></label>
        <label class="campo"><span>Descripción</span><textarea class="input-meca" name="descripcion" rows="3"><?= e($proyecto['descripcion']) ?></textarea></label>
        <label class="campo"><span>Fecha de inicio</span>
          <input class="input-meca" type="date" name="fecha_inicio" value="<?= e($proyecto['fecha_inicio'] ?? '') ?>">
          <small class="campo-ayuda">Cuándo arranca el proyecto. Se muestra en la cabecera del tablero.</small>
        </label>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Participantes del proyecto</span>
          <?= UI::select('miembros', $opcionesEquipo, $equipoProyecto ?? [], false, '', true) ?>
          <small class="campo-ayuda">Al asignar tareas solo aparecerán estas personas. Si no eliges a nadie, el proyecto queda abierto a todo el equipo.</small>
        </label>
      </section>

      <section class="wz-panel">
        <div class="campo" data-sin-resumen>
          <span><i class="fa-solid fa-code-branch"></i> Repositorios</span>
          <?= UI::reposEditor($proyecto) ?>
        </div>
        <label class="campo"><span>Estado</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosProyecto()), $proyecto['estado']) ?></label>
        <label class="campo"><span>Plataforma de reuniones</span>
          <?= UI::select('plataforma', [
                '' => 'La del panel (' . (Reuniones::etiquetaPlataforma(Reuniones::conf()['plataforma']) ?: 'sin definir') . ')',
                'zoom' => 'Zoom',
                'meet' => 'Google Meet',
              ], (string)($proyecto['plataforma'] ?? '')) ?>
          <small class="campo-ayuda">Este proyecto puede usar una distinta a la del resto. Si la que elijas no
            está configurada, se usa la que sí lo esté.</small>
        </label>
      </section>

      <section class="wz-panel">
        <div class="campo" data-sin-resumen>
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
        <div class="campo" data-sin-resumen>
          <span>Color</span>
          <?= UI::colorPicker($proyecto['color'] ?? 0) ?>
        </div>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php UI::fin(); ?>
