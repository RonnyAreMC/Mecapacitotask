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

// Supervisor: dentro del proyecto solo ve el Kanban y el detalle de las tareas.
$esSupervisor = esSupervisor();

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
// Solo se archiva lo terminado DE VERDAD (el mismo criterio de «Completadas»):
// una tarea en estado final con observaciones pendientes sigue en el tablero,
// porque falta corregirla; si no, quedaba oculta y fuera de las completadas.
$tareasObservadas = (new ObservacionRepo())->tareasConPendientes();
$esArchivada   = fn($t) => in_array($t['estado'] ?? '', $finales, true)
    && empty($tareasObservadas[(int)($t['id'] ?? 0)])
    && (string)($t['completada_en'] ?? '') !== '' && (string)($t['completada_en'] ?? '') < $limiteArch;
$nArchivadas   = count(array_filter($tareas, $esArchivada));
// Lista completa (con las archivadas): el cierre por dia mira hacia atras y
// necesita las completadas viejas cuando se pide un rango de fechas largo.
$tareasTodas   = $tareas;
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

// Quien LLEVA el proyecto entra siempre: su Product Owner y su Scrum Master.
// No son gente de fuera que se cuela, son de este proyecto por definicion, y
// sin esto no habia forma de asignarles el analisis o la documentacion de su
// propio proyecto sin meterlos antes en el equipo a mano.
//
// Y solo ellos: los demas tienen que participar aqui. Ofrecer a TODOS los
// analistas en TODOS los proyectos llenaba el selector de gente que no pinta
// nada en este tablero.
foreach ([ProyectoRepo::poDe($proyecto), (int)($proyecto['po_relevo'] ?? 0), ProyectoRepo::scrumDe($proyecto)] as $mid) {
    if ($mid > 0 && isset($miembros[$mid]) && !isset($delProyecto[$mid])) {
        $delProyecto[$mid] = $miembros[$mid];
    }
}
uasort($delProyecto, fn($a, $b) => strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? ''));

/**
 * Etiqueta de una persona en los selectores de tarea. El usuario de Git solo
 * se pone si lo tiene: los analistas suelen no tenerlo y salia un "(@)" suelto.
 */
$etiquetaMiembro = fn(array $m) => trim($m['nombre'])
    . (trim((string)($m['git_user'] ?? '')) !== '' ? ' (@' . $m['git_user'] . ')' : '');

// Responsables de tareas: TODO el que participe en el proyecto, analistas
// incluidos. No todas las tareas son de codigo (analisis, documentacion,
// pruebas, actas) y dejarlos fuera obligaba a asignarselas a un programador
// que no las iba a hacer.
$opcionesAsignar   = [];
$opcionesMiembros  = [0 => '— Sin asignar —'];
$opcionesFiltro    = [0 => 'Todo el equipo'];
$opcionesInvitados = [];
$opcionesDestino   = ['all' => 'Todo el proyecto'];   // destinatarios de una observación
foreach ($delProyecto as $m) {
    $opcionesAsignar[$m['id']]   = $etiquetaMiembro($m);
    $opcionesMiembros[$m['id']]  = $etiquetaMiembro($m);
    $opcionesFiltro[$m['id']]    = $m['nombre'];
    $opcionesInvitados[$m['id']] = $m['nombre'] . (!empty($m['email']) ? ' · ' . $m['email'] : '');
    $opcionesDestino[$m['id']]   = $m['nombre'];
}

// Todo el equipo, para el selector de participantes del proyecto
$opcionesEquipo = [];
foreach ($miembros as $m) {
    $opcionesEquipo[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
}

// Analistas, para elegir el Product Owner del proyecto (el PO sale de ahí)
$opcionesAnalistas = [0 => '— Sin PO —'];
// Y quienes tienen rol de Scrum Master, para decir cuál manda AQUÍ: el rol es
// global, pero el horario de reuniones lo pone el SM de cada proyecto.
$opcionesScrum = [0 => '— Sin Scrum Master —'];
foreach ($miembros as $m) {
    if (MiembroRepo::equipoDe($m) === 'analistas') {
        $opcionesAnalistas[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
    }
    // El administrador también puede llevar un tablero: manda sobre todo.
    if (MiembroRepo::tieneAcceso($m, 'scrum') || MiembroRepo::tieneAcceso($m, 'admin')) {
        $opcionesScrum[$m['id']] = $m['nombre'] . ' · ' . $m['rol'];
    }
}
$scrumProyecto = ProyectoRepo::scrumDe($proyecto);
$poProyecto = ProyectoRepo::poDe($proyecto);
// Crear/editar tareas: solo el PO, el Scrum Master del proyecto y el admin.
$puedeTareas = puedeGestionarTareas($id);
// Admin o Scrum de este proyecto: manda en el tablero (p. ej. puede bajarse
// las tareas de todo el equipo, no solo las suyas).
$puedeGestionar = puedeGestionar($id);
// Reuniones: además del admin y el Scrum Master, el Product Owner del proyecto.
// No vale la clase .solo-gestor de siempre: esa se apaga por el ROL del panel, y
// un PO puede entrar como solo lectura y aun así mandar aquí.
$puedeReuniones = puedeReunionesDelProyecto($id);

// Dependencias: opciones (todas las tareas del proyecto) y mapa por id.
$tareasPorId = [];
$opcionesDependencia = [0 => '— Ninguna —'];   // (lo usa también el compositor de observaciones)
$opcionesDeps = [];                            // para el selector MÚLTIPLE de dependencias (sin "Ninguna")
foreach ($tareas as $t) {
    $tareasPorId[(int)$t['id']] = $t;
    $opcionesDependencia[(int)$t['id']] = mb_strimwidth($t['titulo'], 0, 46, '…');
    $opcionesDeps[(int)$t['id']]        = mb_strimwidth($t['titulo'], 0, 46, '…');
}
$hayDependencias = (bool)array_filter($tareas, fn($t) => TareaRepo::dependenciasDe($t) !== []);

// --- Dependencias entre equipos --------------------------------------------
// Mapa global de TODAS las tareas y proyectos: una dependencia puede apuntar a
// la tarea de otro equipo, y hay que resolver su id, icono y color.
$tareaGlobal = [];
foreach ($tareasRepo->todas() as $tg) $tareaGlobal[(int)$tg['id']] = $tg;
$proyGlobal = [];
foreach ($proyectosRepo->todos() as $pg) $proyGlobal[(int)$pg['id']] = $pg;

// El menú lateral aclara los proyectos de color oscuro; hacemos lo mismo aquí
// para que el chip del equipo se lea sobre fondo claro.
$colorEquipo = fn(?array $p) => $p
    ? (ProyectoRepo::colorBase($p) === '#2D3E50' ? '#40CFFF' : ProyectoRepo::colorBase($p))
    : '#64748b';

// "Próxima a vencer": faltan 3 días o menos (y no está en un estado final).
$urgenciaDe = function (array $t) use ($finales): string {
    $lim = (string)($t['fecha_limite'] ?? '');
    if ($lim === '' || in_array($t['estado'] ?? '', $finales, true)) return '';
    $dias = (int)floor((strtotime($lim . ' 12:00') - strtotime('today 12:00')) / 86400);
    if ($dias < 0)  return 'vencida';
    if ($dias <= 3) return 'proxima';
    return '';
};

// Info de una dependencia para chips y modal: equipo dueño, si es externa,
// estado, urgencia y a quién avisar (responsables + Scrum de su equipo).
$infoDep = function (int $depId) use ($id, $tareaGlobal, $proyGlobal, $finales, $urgenciaDe, $proyecto, $colorEquipo, $miembros): ?array {
    $d = $tareaGlobal[$depId] ?? null;
    if (!$d) return null;
    $pid     = (int)($d['proyecto_id'] ?? 0);
    $externa = $pid !== $id;
    $p       = $externa ? ($proyGlobal[$pid] ?? null) : $proyecto;
    $avisar  = [];
    if ($externa && $p) {
        $ids = TareaRepo::asignadosDe($d);
        $sm  = ProyectoRepo::scrumDe($p);
        if ($sm > 0) $ids[] = $sm;
        foreach (array_values(array_unique($ids)) as $mid) {
            if (isset($miembros[$mid])) $avisar[] = $miembros[$mid]['nombre'];
        }
    }
    return [
        'id'       => $depId,
        'titulo'   => (string)($d['titulo'] ?? ''),
        'externa'  => $externa,
        'equipo'   => $p['nombre'] ?? '',
        'color'    => $colorEquipo($p),
        'icono'    => $externa && $p ? UI::icono($p['icono'] ?? 'FolderOpen', 'dep-eq-ico') : '',
        'estado'   => UI::badgeEstadoTarea($d['estado'] ?? ''),
        'final'    => in_array($d['estado'] ?? '', $finales, true),
        'vence'    => (string)($d['fecha_limite'] ?? ''),
        'urgencia' => $urgenciaDe($d),
        'avisar'   => $avisar,
    ];
};

// Datos para el selector de dependencias (equipo → sus tareas). El proyecto
// actual va primero; luego el resto de equipos que tengan tareas.
$depDatos = [];
foreach (([$id => $proyecto] + $proyGlobal) as $pidD => $pD) {
    $tareasP = array_values(array_filter($tareaGlobal, fn($t) => (int)$t['proyecto_id'] === (int)$pidD));
    if (!$tareasP && (int)$pidD !== $id) continue;
    $depDatos[] = [
        'id'     => (int)$pidD,
        'nombre' => (string)($pD['nombre'] ?? ''),
        'color'  => $colorEquipo($pD),
        'icono'  => UI::icono($pD['icono'] ?? 'FolderOpen', 'dp-eq-ico'),
        'actual' => (int)$pidD === $id,
        'tareas' => array_map(fn($t) => [
            'id'     => (int)$t['id'],
            'titulo' => (string)($t['titulo'] ?? ''),
            'final'  => in_array($t['estado'] ?? '', $finales, true),
        ], $tareasP),
    ];
}

// Flujo de dependencias: solo entran las tareas que forman parte de alguna,
// o sea las que dependen de algo y aquellas de las que depende alguien. Las
// sueltas no dibujan ninguna flecha: solo alargaban la columna "Inicio" y
// escondían las que sí encadenan.
$enDependencia = [];
foreach ($tareas as $t) {
    $deps = TareaRepo::dependenciasDe($t);
    if (!$deps) continue;
    $enDependencia[(int)$t['id']] = true;              // la que depende
    foreach ($deps as $depId) $enDependencia[$depId] = true;   // y de la que depende
}

$tareasFlujo = [];
$enFlujo = [];
foreach ($tareas as $t) {
    if (!isset($enDependencia[(int)$t['id']])) continue;
    $tareasFlujo[] = $t;
    $enFlujo[(int)$t['id']] = true;
}
// Y como nodos externos de solo lectura, las tareas de OTROS equipos de las
// que dependen las de aquí.
foreach ($tareas as $t) {
    foreach (TareaRepo::dependenciasDe($t) as $depId) {
        if (isset($enFlujo[$depId])) continue;
        $d = $tareaGlobal[$depId] ?? null;
        if (!$d || (int)$d['proyecto_id'] === $id) continue;   // solo externas
        $enFlujo[$depId] = true;
        $tareasFlujo[] = $d;
    }
}
$nivelesFlujo = $tareasRepo->niveles($tareasFlujo);

// Repos del proyecto. Los datos de GitHub (commits, ramas) se cargan de forma
// diferida al abrir la vista Métricas, así la página no espera a la API al abrir.
$reposProyecto = ProyectoRepo::repos($proyecto);

// Observaciones (revision / QA)
$obsRepo         = new ObservacionRepo();
$observaciones   = $obsRepo->hilosDelProyecto($id);   // con sus respuestas dentro
$obsPorTarea     = $obsRepo->pendientesPorTarea($id);   // [tarea_id => n pendientes]
$obsResumen      = $obsRepo->resumen($id);
$obsPendientes   = $obsResumen['pendientes'];
$equiposCat      = Catalogo::equipos();

// Carga cruzada: a la gente se le meten requerimientos sueltos mientras tiene
// tareas del proyecto, y el SM/PO no lo ve — solo nota que la tarea se demora.
// Aquí se cruza, por persona, el rango de fechas de cada tarea con el de sus
// requerimientos abiertos, y lo que se solape se avisa en la tarea.
// Cuenta lo mismo el trabajo de OTROS equipos: alguien puede estar en dos o
// tres tableros a la vez y desde aquí solo se ve el suyo.
$cargaPorMiembro = [];
$apunta = function (int $mid, string $origen, string $titulo, string $ini, string $fin) use (&$cargaPorMiembro) {
    if ($ini === '' && $fin === '') return;                     // sin fechas no hay nada que cruzar
    $cargaPorMiembro[$mid][] = [
        'origen' => $origen,                                    // requerimiento suelto o nombre del equipo
        'titulo' => $titulo,
        'ini'    => $ini !== '' ? $ini : $fin,                  // si falta una punta, se usa la otra
        'fin'    => $fin !== '' ? $fin : $ini,
    ];
};

foreach ((new RequerimientoRepo())->todos() as $r) {
    if (RequerimientoRepo::cerrado($r)) continue;               // lo cerrado ya no estorba
    foreach (RequerimientoRepo::asignadosDe($r) as $mid) {
        $apunta($mid, 'Requerimiento suelto', (string)($r['titulo'] ?? ''),
                RequerimientoRepo::fechaInicio($r), RequerimientoRepo::fechaFin($r));
    }
}

// Tareas abiertas de esa persona en cualquier OTRO proyecto.
$nombreProy = [];
foreach ((new ProyectoRepo())->todos() as $p2) $nombreProy[(int)$p2['id']] = (string)$p2['nombre'];
foreach ($tareasRepo->todas() as $t2) {
    $pid2 = (int)($t2['proyecto_id'] ?? 0);
    if ($pid2 === $id) continue;                                // el tablero de aquí ya se ve
    if (in_array($t2['estado'] ?? '', $finales, true)) continue; // lo terminado no ocupa
    foreach (TareaRepo::asignadosDe($t2) as $mid) {
        $apunta($mid, $nombreProy[$pid2] ?? 'Otro equipo', (string)($t2['titulo'] ?? ''),
                (string)($t2['fecha_inicio'] ?? ''), (string)($t2['fecha_limite'] ?? ''));
    }
}

/** Texto del aviso de carga cruzada, para el title del tablero y de la tabla. */
function avisoCargaExtra(array $choques): string
{
    $partes = [];
    foreach ($choques as $c) {
        $partes[] = $c['persona'] . ' — ' . $c['origen'] . ': «' . $c['titulo'] . '» del ' . $c['ini'] . ' al ' . $c['fin'];
    }
    return 'Puede demorarse, tiene trabajo en paralelo: ' . implode('; ', $partes) . '.';
}

/** Trabajo de los responsables (otros equipos o requerimientos) que pisa las fechas de la tarea. */
$cargaExtra = function (array $t) use ($miembros, $cargaPorMiembro): array {
    $tIni = trim((string)($t['fecha_inicio'] ?? ''));
    $tFin = trim((string)($t['fecha_limite'] ?? ''));
    if ($tIni === '' && $tFin === '') return [];               // tarea sin fechas: nada que comparar
    if ($tIni === '') $tIni = $tFin;
    if ($tFin === '') $tFin = $tIni;

    $choques = [];
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        foreach ($cargaPorMiembro[$mid] ?? [] as $r) {
            // Dos rangos se pisan si cada uno empieza antes de que acabe el otro.
            if ($r['ini'] > $tFin || $r['fin'] < $tIni) continue;
            $choques[] = [
                'persona' => $miembros[$mid]['nombre'] ?? 'Alguien',
                'origen'  => $r['origen'],
                'titulo'  => $r['titulo'],
                'ini'     => $r['ini'],
                'fin'     => $r['fin'],
            ];
        }
    }
    return $choques;
};

// Datos de solo lectura de una tarea para el modal de detalle (cualquiera puede
// abrirlo, incluidos los programadores). Se calcula una vez y se pega como
// atributo data-ver-tarea en cada tarjeta/fila/nodo.
$estadosCat = Catalogo::estadosTarea();
$prioCat    = Catalogo::prioridades();
// Trazabilidad con Releases: el ítem del release (R2-B04…) de cada tarea, para quien ve el módulo
$relDeTarea = [];
if (is_file(__DIR__ . '/lib/Releases.php')) {
    require_once __DIR__ . '/lib/Releases.php';
    if (Releases::puedeVer()) $relDeTarea = Releases::vinculosDetalle();
}
// El PO o el SM amarra la tarea al ítem del release al crearla o editarla: los ítems
// de los equipos que trabajan en este proyecto, y a cuál está amarrada cada tarea
$relOpciones = [];
$relDeTareaAqui = [];
if (class_exists('Releases')) {
    $relEqs = [];
    foreach (Releases::itemsDeProyecto((int)$id) as $ri) {
        $relEqs[(string)$ri['equipo']] = true;
        $relOpciones[(string)$ri['id']] = [$ri, $ri['estado'] ?? ''];
        foreach ((array)($ri['tareas'] ?? []) as $tid) $relDeTareaAqui[(int)$tid] ??= (int)$ri['id'];
    }
    foreach ($relOpciones as $k => [$ri, $est]) {
        $relOpciones[$k] = $ri['codigo'] . ' · ' . mb_strimwidth((string)$ri['titulo'], 0, 70, '…')
            . (count($relEqs) > 1 ? ' · ' . $ri['equipo'] : '') . ($est === 'cerrado' ? ' (terminado)' : '');
    }
}
// El release del equipo, para verlo desde Tareas y agregarle tareas a cada ítem
$relPanel = null;
if ($relOpciones) {
    $idsRel = array_flip(array_map('intval', array_keys($relOpciones)));
    $itemsRel = array_values(array_filter(Releases::items(), fn($it) => isset($idsRel[(int)$it['id']])));
    $relsRel = Releases::releases();
    $porRel = [];
    foreach ($itemsRel as $it) $porRel[(string)$it['release']][] = $it;
    $hoyRel = date('Y-m-d');
    // Se abre en el release de hoy (o el próximo que empieza); si no, el último
    $actualRel = '';
    foreach ($relsRel as $cod => $r) if (isset($porRel[$cod]) && ($r['fin'] ?? '') >= $hoyRel) { $actualRel = (string)$cod; break; }
    if ($actualRel === '' && $porRel) $actualRel = (string)array_key_last($porRel);
    $relPanel = ['releases' => array_intersect_key($relsRel, $porRel), 'items' => $porRel, 'actual' => $actualRel,
                 'equipos' => array_values(array_unique(array_column($itemsRel, 'equipo')))];
}
$relCampo = function (string $clase = '') use ($relOpciones): string {
    if (!$relOpciones) return '';
    return '<label class="campo"><span>Ítem del release</span>'
        . UI::select('rel_item', ['' => '— Sin ítem del release —'] + $relOpciones, '', false, $clase)
        . '<small class="campo-ayuda">La historia o el frente del release al que suma esta tarea: su avance sale de sus tareas.</small></label>';
};
$relChips = function (array $t) use ($relDeTarea): string {
    $out = '';
    foreach ($relDeTarea[(int)$t['id']] ?? [] as $ri) {
        $out .= '<a class="dep-tag rel-tag" href="releases.php?v=item&amp;id=' . (int)$ri['id'] . '" title="Ítem del release: ' . e($ri['codigo'] . ' · ' . $ri['titulo']) . '">'
            . '<i class="fa-solid fa-route" aria-hidden="true"></i> ' . e($ri['codigo']) . '</a>';
    }
    return $out;
};

// Quien tiene la tarea (o quien gestiona el proyecto) puede sumar a alguien que le ayuda
$yoFicha = (int)(Auth::usuario()['id'] ?? 0);
$verTareaAttr = function (array $t) use ($miembros, $finales, $obsPorTarea, $estadosCat, $prioCat, $proyecto, $infoDep, $cargaExtra, $yoFicha, $puedeGestionar, $esSupervisor): string {
    $nombres = [];
    $responsables = [];
    $colabs = TareaRepo::colaboradoresDe($t);
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        if (!isset($miembros[$mid])) continue;
        $nombres[] = $miembros[$mid]['nombre'];
        $responsables[] = ['nombre' => $miembros[$mid]['nombre'], 'colabora' => isset($colabs[$mid])];
    }
    // Dependencias resueltas con equipo/color/urgencia (propias y de otros equipos).
    $deps = array_values(array_filter(array_map($infoDep, TareaRepo::dependenciasDe($t))));
    $ag = TareaRepo::agregadaPor($t, $miembros);
    return e(json_encode([
        'id'           => (int)$t['id'],
        'titulo'       => $t['titulo'] ?? '',
        'descripcion'  => $t['descripcion'] ?? '',
        'proyecto'     => $proyecto['nombre'] ?? '',
        'estado_badge' => UI::badgeEstadoTarea($t['estado'] ?? ''),
        'prio_badge'   => UI::badgePrioridad($t['prioridad'] ?? ''),
        'asignados'    => $nombres,
        'responsables' => $responsables,
        'asignados_ids'=> TareaRepo::asignadosDe($t),
        'puede_colab'  => !$esSupervisor && (TareaRepo::tieneAsignado($t, $yoFicha) || $puedeGestionar),
        'final'        => in_array($t['estado'] ?? '', $finales, true),   // terminada: sin «venció hace…»
        'fecha_inicio' => $t['fecha_inicio'] ?? '',
        'fecha_limite' => $t['fecha_limite'] ?? '',
        'deps'         => $deps,
        'obs'          => $obsPorTarea[(int)$t['id']] ?? 0,
        'adjuntos'     => TareaRepo::adjuntosDe($t),
        'creado'       => $t['creado'] ?? '',
        // Quién la anotó y cuándo
        'agrego'       => $ag['miembro'] ? [
            'nombre' => $ag['miembro']['nombre'] ?? '', 'avatar' => UI::avatar($ag['miembro'], 26), 'origen' => $ag['origen'],
        ] : null,
        'creado_txt'   => $ag['largo'],
        'carga_extra'  => $cargaExtra($t),
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

// Despliegues: si el módulo está activo, cada tarea completada dice si ya
// está en el servidor de pruebas o si sigue esperando la próxima subida.
$depCfgProy = configDeploys();
$depsOn     = $depCfgProy['activo'];
$depEntorno = $depCfgProy['entorno'];

// ---------------------------------------------------------------------------
// Filtro de fechas del Kanban.
//
// El tablero se abría con TODAS las tarjetas a la vez y no había forma de
// saber qué se movió hoy. Ahora arranca acotado a HOY: en las columnas solo
// quedan las tareas que se completaron hoy y las que se crearon hoy. El chip
// "Todo" devuelve el tablero entero.
//
// El rango se lee de ?desde=&hasta=; si vienen en la URL pero vacíos (el chip
// "Todo") no se filtra nada. Solo se cae a hoy cuando no vienen.
$dHoy   = date('Y-m-d');
$dAyer  = date('Y-m-d', strtotime('-1 day'));
$dPedido = isset($_GET['desde']) || isset($_GET['hasta']);
$dDesde = ProyectoRepo::fecha($_GET['desde'] ?? '');
$dHasta = ProyectoRepo::fecha($_GET['hasta'] ?? '');
if (!$dPedido) {                    // al entrar, solo lo de hoy
    $dDesde = $dHasta = $dHoy;
}
if ($dDesde !== '' && $dHasta === '') $dHasta = $dDesde;   // un solo día
if ($dHasta !== '' && $dDesde === '') $dDesde = $dHasta;
if ($dDesde !== '' && $dHasta < $dDesde) { [$dDesde, $dHasta] = [$dHasta, $dDesde]; }
$kbFiltro = $dDesde !== '';
$kbUnDia  = $kbFiltro && $dDesde === $dHasta;

/** Día (Y-m-d) en que se creó una tarea; 'creado' guarda fecha y hora. */
$creadaEl = fn(array $t) => substr((string)($t['creado'] ?? ''), 0, 10);

// Tareas que van al tablero. Sin filtro, las de siempre. Con filtro, las que
// se movieron en el rango: completadas dentro de él o creadas dentro de él.
// Se parte de $tareasTodas porque las completadas de hace más de una semana
// salen del tablero, y justo son las que pide un rango hacia atrás.
$kbTareas = $tareas;
if ($kbFiltro) {
    $kbTareas = array_values(array_filter($tareasTodas, function ($t) use ($dDesde, $dHasta, $creadaEl) {
        $fin = ProyectoRepo::fecha($t['completada_en'] ?? '');
        $ini = $creadaEl($t);
        return ($fin !== '' && $fin >= $dDesde && $fin <= $dHasta)
            || ($ini !== '' && $ini >= $dDesde && $ini <= $dHasta);
    }));
}
// El filtro de persona ("Ver como" incluido) manda también aquí
if ($fAsignado) {
    $kbTareas = array_values(array_filter($kbTareas, fn($t) => TareaRepo::tieneAsignado($t, $fAsignado)));
}
// Contadores de las columnas: los del rango, no los del proyecto entero
$kbResumen = array_fill_keys(array_keys(Catalogo::estadosTarea()), 0);
foreach ($kbTareas as $t) {
    $ek = $t['estado'] ?? '';
    if (isset($kbResumen[$ek])) $kbResumen[$ek]++;
}

/** Fecha corta para las etiquetas del tablero: "3 sep". */
$diaCorto = function (string $f): string {
    $ts = strtotime($f);
    if (!$ts) return $f;
    $meses = [1=>'ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return (int)date('j', $ts) . ' ' . $meses[(int)date('n', $ts)];
};

// Rangos rápidos y enlace que los aplica sin perder el filtro de persona.
// El #vista-kanban devuelve a esta misma pestaña tras recargar.
$urlDia = fn(string $desde, string $hasta) => '?id=' . $id
    . (!$verComo && $fAsignado ? '&asignado=' . $fAsignado : '')
    . '&desde=' . $desde . '&hasta=' . $hasta . '#vista-kanban';
$rangosDia = [
    'Hoy'            => [$dHoy, $dHoy],
    'Ayer'           => [$dAyer, $dAyer],
    'Últimos 7 días' => [date('Y-m-d', strtotime('-6 days')), $dHoy],
    'Todo'           => ['', ''],
];

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
// El calendario es PERSONAL: cada quien ve solo sus tareas/reuniones; el admin
// las ve todas (general). "Ver como X" muestra las de X. Además, se incluyen
// las tareas de las que DEPENDEN las mías (aunque sean de otro), para saber
// cuándo terminan y arranca la mía.
$calScope = $verComo ? (int)$verComo['id'] : (esAdmin() ? 0 : $miId);
$depSolo = [];   // ids de tareas ajenas que aparecen solo por ser dependencia
if ($calScope > 0) {
    $depIds = [];
    foreach ($tareas as $t) {
        if (TareaRepo::tieneAsignado($t, $calScope)) {
            foreach (TareaRepo::dependenciasDe($t) as $d) $depIds[(int)$d] = true;
        }
    }
    $tareasCal = array_values(array_filter($tareas, function ($t) use ($calScope, $depIds, &$depSolo) {
        $mia   = TareaRepo::tieneAsignado($t, $calScope);
        $esDep = isset($depIds[(int)$t['id']]);
        if ($esDep && !$mia) $depSolo[(int)$t['id']] = true;
        return $mia || $esDep;
    }));
} else {
    $tareasCal = $tareas;
}
// Ordenar por inicio para que las barras se apilen parejas entre días
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
    // Calendario personal: solo las reuniones donde participa (el admin, todas)
    if ($calScope > 0 && !in_array($calScope, array_map('intval', (array)($r['invitados'] ?? [])), true)) continue;
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
<header class="proyecto-hero ph-limpio" style="--pc:<?= $color ?>">
  <div class="ph-top">
    <a href="index.php" class="ph-back"><i class="fa-solid fa-arrow-left"></i> Proyectos</a>
    <div class="ph-actions">
      <?php if (!$esSupervisor): /* el supervisor no ve los repositorios */ ?>
      <?php foreach (ProyectoRepo::repos($proyecto) as $repo): ?>
      <a class="btn-meca btn-sm <?= e(Repos::clase($repo['url'])) ?>" href="<?= e($repo['url']) ?>" target="_blank" rel="noopener"
         title="Repositorio <?= e($repo['label']) ?> en <?= e(Repos::etiqueta($repo['url'])) ?>">
        <i class="<?= e(Repos::icono($repo['url'])) ?>"></i> <i class="fa-solid <?= e($repo['icono']) ?>"></i> <?= e($repo['label']) ?>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
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

  <?php
  // Cabecera limpia: el título, tres cifras y la ilustración del proyecto.
  // El detalle por estado está en el tablero de abajo.
  $totalTareas = array_sum($resumen);
  $portadaHero = UI::portadaDe($proyecto);
  ?>
  <div class="ph-main">
    <div class="ph-info">
      <h1 class="font-display" title="Creado <?= e($proyecto['creado'] ?? '') ?>"><?= e($proyecto['nombre']) ?></h1>
      <?php if (trim((string)($proyecto['descripcion'] ?? '')) !== ''): ?><p><?= e($proyecto['descripcion']) ?></p><?php endif; ?>
      <?php if (!$listoEntrega && $obsPendientes > 0): /* explica por qué el avance no llega al 100 % */ ?>
      <a class="badge-entrega alerta ph-obs" href="#vista-observaciones"><i class="fa-solid fa-triangle-exclamation"></i>
        <?= $obsPendientes ?> observación<?= $obsPendientes === 1 ? '' : 'es' ?> pendiente<?= $obsPendientes === 1 ? '' : 's' ?></a>
      <?php endif; ?>
      <dl class="ph-cifras">
        <div><dt>Tareas</dt><dd><?= $totalTareas ?></dd>
          <?php if ($nArchivadas > 0): /* el total incluye las archivadas; la pestaña «Tareas» no */ ?>
          <small class="ph-cifra-nota"><?= $totalTareas - $nArchivadas ?> en el tablero · <?= $nArchivadas ?> archivada<?= $nArchivadas === 1 ? '' : 's' ?></small>
          <?php endif; ?></div>
        <div class="ph-cifra-hechas"><dt>Completadas</dt><dd><?= $completadas ?></dd></div>
        <div class="ph-cifra-avance"><dt>Avance</dt><dd><?= $avance ?>%</dd>
          <span class="ph-cifra-barra" aria-hidden="true"><span style="width:<?= $avance ?>%"></span></span></div>
      </dl>
    </div>
    <div class="ph-arte" aria-hidden="true">
      <?php if ($portadaHero): ?>
      <img src="<?= e(asset('iconos/ilustraciones/' . $portadaHero . '.svg')) ?>" alt="" width="520" height="420" decoding="async">
      <?php else: ?>
      <span class="ph-arte-icono"><?= UI::icono($proyecto['icono'] ?? 'FolderOpen') ?></span>
      <?php endif; ?>
    </div>
  </div>
</header>

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
<?php
// «Visualización»: Tabla, Kanban o Flujo, en un menú circular que va en la barra
// de cada una (admin.js marca la actual). El supervisor solo ve el Kanban.
$radVistas = $esSupervisor ? '' : UI::menuRadial('Cómo ver las tareas', [
    ['texto' => 'Tabla',  'icono' => 'fa-table-list',      'attrs' => 'data-subvista="tabla"'],
    ['texto' => 'Kanban', 'icono' => 'fa-table-columns',   'attrs' => 'data-subvista="kanban"'],
    ['texto' => 'Flujo',  'icono' => 'fa-diagram-project', 'attrs' => 'data-subvista="flujo"'],
], 'Visualización', 'fa-layer-group');
?>
<?php if (!$esSupervisor): /* el supervisor solo ve el Kanban: sin pestañas ni tabla */ ?>
<div class="vista-fila">
  <div class="vista-toggle">
    <button type="button" class="tab-btn" data-vista="calendario" data-tip="Calendario"><i class="fa-solid fa-calendar-days"></i> <span class="tab-txt">Calendario</span></button>
    <button type="button" class="tab-btn" data-vista="tareas" data-tip="Tareas"><i class="fa-solid fa-list-check"></i> <span class="tab-txt">Tareas</span>
      <span class="tab-badge tab-badge-neutro"<?= $nArchivadas > 0 && !$verArchivadas ? ' title="En el tablero, sin las ' . $nArchivadas . ' archivadas (completadas hace más de una semana)"' : '' ?>><?= count($tareas) ?></span>
    </button>
    <button type="button" class="tab-btn" data-vista="observaciones" data-tip="Observaciones"><i class="fa-solid fa-comment-dots"></i> <span class="tab-txt">Observaciones</span>
      <?php if ($obsPendientes > 0): ?><span class="tab-badge"><?= $obsPendientes ?></span><?php endif; ?>
    </button>
    <?php if ($relPanel): ?>
    <button type="button" class="tab-btn" data-vista="release" data-tip="Release del equipo"><i class="fa-solid fa-route"></i> <span class="tab-txt">Release</span></button>
    <?php endif; ?>
    <?php /* Intercambios: la pestaña solo sale cuando hay solicitudes por responder; si no, se llega desde «Acciones» */ ?>
    <button type="button" class="tab-btn" data-vista="intercambios" data-tip="Intercambios"<?= $interPendientes > 0 ? '' : ' hidden' ?>><i class="fa-solid fa-right-left"></i> <span class="tab-txt">Intercambios</span>
      <?php if ($interPendientes > 0): ?><span class="tab-badge"><?= $interPendientes ?></span><?php endif; ?>
    </button>
    <button type="button" class="tab-btn" data-vista="reuniones" data-tip="Reuniones"><i class="fa-solid fa-video"></i> <span class="tab-txt">Reuniones</span>
      <?php if (count($reuniones)): ?><span class="tab-badge tab-badge-zoom"><?= count($reuniones) ?></span><?php endif; ?>
    </button>
    <button type="button" class="tab-btn" data-vista="metricas" data-tip="Métricas"><i class="fa-solid fa-chart-simple"></i> <span class="tab-txt">Métricas</span></button>
  </div>
</div>

<div data-vista-panel="tabla">
<!-- Tabla de tareas -->
<section class="card-base tabla-card">
  <?php /* Sin título: «Tareas» y su número ya están en la pestaña. A la izquierda el
           buscador; a la derecha el filtro, Acciones y Visualización */ ?>
  <div class="tabla-toolbar tabla-toolbar--tareas">
    <label class="tabla-buscar">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="input-meca" type="search" data-tabla-buscar placeholder="Buscar por título, responsable o estado…" autocomplete="off">
    </label>
    <div class="tabla-filtros">
      <?php if ($verComo): ?>
      <!-- "Ver como" filtra la lista a las tareas de esa persona, y el aviso
           vivía solo en la barra lateral: se asignaba una tarea a otro, no
           aparecía, y parecía que la asignación no había funcionado. El aviso
           va donde se mira, y se puede quitar desde aquí. -->
      <a class="filtro-vercomo" href="?id=<?= $id ?>&amp;ver_como=0"
         title="Volver a tu vista y ver todas las tareas">
        <i class="fa-solid fa-eye"></i>
        <!-- El texto va en un solo hijo: si no, el gap del flex mete aire
             entre el nombre y los dos puntos. -->
        <span>Viendo como <b><?= e(explode(' ', trim($verComo['nombre']))[0]) ?></b>: solo sus tareas</span>
        <i class="fa-solid fa-xmark"></i>
      </a>
      <?php else: ?>
      <form method="get" class="inline-form">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php if ($fEstado): ?><input type="hidden" name="estado" value="<?= e($fEstado) ?>"><?php endif; ?>
        <?= UI::select('asignado', $opcionesFiltro, (string)$fAsignado, true, 'select-sm') ?>
      </form>
      <?php endif; ?>
      <?php if ($fEstado || (!$verComo && $fAsignado)): ?>
      <a href="?id=<?= $id ?>" class="filtro-clear"><i class="fa-solid fa-filter-circle-xmark"></i> Limpiar</a>
      <?php endif; ?>
      <?php
        // Lo que antes eran cuatro botones va en un solo menú circular (UI::menuRadial):
        // el release del equipo, las archivadas, las descargas para Claude y avisar
        $accRapidas = [];
        if ($puedeIntercambiar) $accRapidas[] = ['texto' => 'Proponer intercambio', 'icono' => 'fa-right-left', 'attrs' => 'data-rad-dlg="dlg-intercambio"'];
        $accRapidas[] = ['texto' => 'Intercambios' . ($intercambios ? ' (' . count($intercambios) . ')' : ''), 'icono' => 'fa-clock-rotate-left', 'attrs' => 'data-rad-vista="intercambios"'];
        if ($nArchivadas > 0 && !in_array($fEstado, $finales, true)) {
            $accRapidas[] = $verArchivadas
                ? ['texto' => 'Ocultar archivadas', 'icono' => 'fa-eye-slash', 'tipo' => 'a', 'attrs' => 'href="?id=' . $id . '"']
                : ['texto' => 'Ver archivadas (' . $nArchivadas . ')', 'icono' => 'fa-box-archive', 'tipo' => 'a', 'attrs' => 'href="?id=' . $id . '&amp;arch=1"'];
        }
        $accRapidas[] = ['texto' => 'Mis tareas para Claude', 'icono' => '<img src="assets/claude.svg" alt="" width="20" height="20">', 'tipo' => 'submit',
                         'attrs' => 'form="form-tareas-json" name="alcance" value="mias" title="Tus tareas de este proyecto, con sus #id y dependencias, en JSON"'];
        if ($puedeGestionar) $accRapidas[] = ['texto' => 'Tareas del equipo (JSON)', 'icono' => 'fa-users', 'tipo' => 'submit',
                         'attrs' => 'form="form-tareas-json" name="alcance" value="equipo" title="Las tareas de todo el equipo en este proyecto, en JSON"'];
        if ($avisoPersonas && Auth::esAdmin()) $accRapidas[] = ['texto' => 'Avisar al equipo', 'icono' => 'fa-paper-plane', 'attrs' => 'data-rad-dlg="dlg-avisar"'];
      ?>
      <form method="post" action="actions.php" class="inline-form" id="form-tareas-json" data-descarga hidden>
        <input type="hidden" name="accion" value="proyecto_tareas_json">
        <input type="hidden" name="id" value="<?= $id ?>">
      </form>
      <?= UI::menuRadial('Acciones rápidas', $accRapidas) ?>
      <?= $radVistas ?>
      <?php if ($puedeTareas): ?>
      <button class="btn-primary btn-meca btn-agregar" onclick="document.getElementById('dlg-nueva-tarea').showModal()">
        <i class="fa-solid fa-plus"></i> Nueva tarea
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($visibles)): ?>
    <?= UI::vacio('fa-clipboard-list', 'Sin tareas aquí', match (true) {
          // Decir CUÁL es el filtro: "no hay tareas con esos filtros" a secas
          // deja pensando que la tarea que acabas de crear no se guardó.
          (bool)$verComo => 'Estás viendo el panel como ' . explode(' ', trim($verComo['nombre']))[0]
                            . ', y aquí solo salen las tareas suyas. Quita «Viendo como» para verlas todas.',
          $fEstado || $fAsignado => 'No hay tareas con esos filtros.',
          default => 'Agrega la primera tarea de este proyecto.',
        }) ?>
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
        <?php
        // Lo que busca el buscador de la tabla. Va explicito porque, sin esto,
        // el JS cae al texto de la fila — y ahi dentro esta el <select> de
        // Estado con SUS CUATRO OPCIONES, asi que buscar "completada" o
        // "progreso" devolvia TODAS las tareas. Aqui va el estado de verdad.
        $buscarFila = mb_strtolower(trim(
            '#' . (int)$t['id'] . ' ' . ($t['titulo'] ?? '')
            . ' ' . HtmlRico::texto($t['descripcion'] ?? '', 200)
            . ' ' . implode(' ', array_map(fn($m) => ($m['nombre'] ?? '') . ' ' . ($m['git_user'] ?? ''), $asigLista))
            . ' ' . ($estadosCat[$t['estado'] ?? '']['0'] ?? ($t['estado'] ?? ''))
            . ' ' . ($prioCat[$t['prioridad'] ?? '']['0'] ?? '')
        ));
        ?>
        <tr class="fila-tarea <?= $esFinal ? 'fila-hecha' : '' ?>" data-ver-tarea='<?= $verTareaAttr($t) ?>'
            data-buscar="<?= e($buscarFila) ?>">
          <td class="celda-tarea">
            <span class="prio-dot prio-<?= e($t['prioridad']) ?>"></span>
            <div>
              <b><button type="button" class="tarea-id btn-copiar" data-copiar="#<?= (int)$t['id'] ?>" title="Copiar #<?= (int)$t['id'] ?> para tus commits">#<?= (int)$t['id'] ?></button> <?= e($t['titulo']) ?><?php $choques = $cargaExtra($t); if ($choques): ?>
                <span class="kb-carga" title="<?= e(avisoCargaExtra($choques)) ?>"><?= UI::icono('TriangleWarning') ?> <?= count($choques) ?></span>
              <?php endif; ?></b>
              <?php $descPrev = HtmlRico::texto($t['descripcion'] ?? '', 140); if ($descPrev !== ''): ?><small><?= e($descPrev) ?></small><?php endif; ?>
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
              <?php if ($depsOn && $esFinal): ?>
                <?php if ((int)($t['deploy_id'] ?? 0) > 0): ?>
                <small class="dep-tag deploy-tag deploy-si" title="Ya está en <?= e($depEntorno) ?>">
                  <i class="fa-solid fa-cloud-arrow-up"></i> Subida el <?= e($t['desplegada_en'] ?? '') ?>
                </small>
                <?php else: ?>
                <small class="dep-tag deploy-tag deploy-no" title="Completada, pero aún no se ha subido a <?= e($depEntorno) ?>">
                  <i class="fa-regular fa-clock"></i> Sin subir
                </small>
                <?php endif; ?>
              <?php endif; ?>
              <?= $relChips($t) ?>
              <?php $ag = TareaRepo::agregadaPor($t, $miembros); if ($ag['miembro'] || $ag['corto'] !== ''): ?>
              <small class="tarea-autor" title="<?= e(($ag['miembro'] ? 'La agregó ' . $ag['miembro']['nombre'] . ' el ' : 'Agregada el ') . $ag['largo'] . ($ag['origen'] ? ' · ' . $ag['origen'] : '')) ?>">
                <?php if ($ag['miembro']): ?>
                  <?= UI::avatar($ag['miembro'], 16) ?>
                  <span class="tarea-autor__quien"><?= e(explode(' ', trim($ag['miembro']['nombre']))[0]) ?></span>
                  <span aria-hidden="true">·</span>
                <?php else: ?>
                  <i class="fa-regular fa-clock"></i> Agregada
                <?php endif; ?>
                <span><?= e($ag['corto']) ?></span>
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
            <?php if ($puedeTareas): ?>
            <button class="accion-btn" title="Editar"
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
            <?php endif; ?>
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
        <tr data-buscar-vacio data-no-buscar hidden>
          <td colspan="6" class="tabla-buscar-vacio">Ninguna tarea coincide con la búsqueda.</td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
</div>

<?php endif; /* fin: tab bar + tabla (ocultos al supervisor) */ ?>

<?php if ($esSupervisor): ?>
<div class="sup-toggle">
  <span class="sup-toggle-tit"><i class="fa-solid fa-eye"></i> <?= e($proyecto['nombre']) ?></span>
  <button type="button" class="sup-tab active" data-sup-vista="kanban"><i class="fa-solid fa-table-columns"></i> Kanban</button>
  <button type="button" class="sup-tab" data-sup-vista="flujo"><i class="fa-solid fa-diagram-project"></i> Flujo</button>
</div>
<?php endif; ?>
<!-- Vista Kanban: columnas por estado, arrastra para cambiar -->
<div data-vista-panel="kanban"<?= $esSupervisor ? '' : ' hidden' ?>>

  <section class="card-base tabla-card">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-table-columns text-secondary"></i> Kanban
        <?php if ($kbFiltro): ?><span class="tabla-count"><?= count($kbTareas) ?></span><?php endif; ?>
      </h2>
      <!-- Filtro de fechas del tablero: con "Hoy" las columnas solo traen lo
           que se completó o se creó hoy. -->
      <div class="dia-filtros">
        <?php foreach ($rangosDia as $rot => [$rd, $rh]): ?>
        <a class="dia-chip<?= ($dDesde === $rd && $dHasta === $rh) ? ' activo' : '' ?>" href="<?= e($urlDia($rd, $rh)) ?>"><?= e($rot) ?></a>
        <?php endforeach; ?>
        <form method="get" class="dia-rango">
          <input type="hidden" name="id" value="<?= $id ?>">
          <?php if (!$verComo && $fAsignado): ?><input type="hidden" name="asignado" value="<?= $fAsignado ?>"><?php endif; ?>
          <input class="input-meca input-dia" type="date" name="desde" value="<?= e($dDesde) ?>" max="<?= e($dHoy) ?>" aria-label="Desde">
          <span>a</span>
          <input class="input-meca input-dia" type="date" name="hasta" value="<?= e($dHasta) ?>" max="<?= e($dHoy) ?>" aria-label="Hasta">
          <button class="btn-outline btn-meca btn-sm btn-neutro" title="Ver ese rango"><i class="fa-solid fa-filter"></i></button>
        </form>
        <?= $radVistas ?>
      </div>
    </div>
    <?php if ($kbFiltro): ?>
    <p class="dia-aviso">
      <i class="fa-solid fa-calendar-day"></i>
      Solo <?= $kbUnDia ? ($dDesde === $dHoy ? 'lo de hoy' : 'lo del ' . e($dDesde)) : 'lo del ' . e($dDesde) . ' al ' . e($dHasta) ?>:
      tareas completadas o creadas en esas fechas.
      <a href="<?= e($urlDia('', '')) ?>">Ver el tablero completo</a>
    </p>
    <?php else: ?>
    <p class="dia-aviso dia-aviso-suave"><i class="fa-solid fa-hand"></i> Arrastra una tarjeta a otra columna para cambiar su estado.</p>
    <?php endif; ?>
    <?php
      // Lista de estados para el menú de "mover rápido" de cada tarjeta.
      $kbEstados = [];
      foreach (Catalogo::estadosTarea() as $ek => $ev) {
          // 'svg' ya renderizado: el menú de mover lo dibuja el JS, que no
          // puede llamar a UI::icono.
          $kbEstados[] = ['k' => $ek, 'label' => $ev[0], 'svg' => UI::icono($ev[1])];
      }
    ?>
    <div class="kanban" style="--pc:<?= $color ?>" data-estados='<?= e(json_encode($kbEstados, JSON_UNESCAPED_UNICODE)) ?>'<?= $kbFiltro ? ' data-filtrado="1"' : '' ?>>
      <?php foreach (Catalogo::estadosTarea() as $k => [$label, $icono]): ?>
      <div class="kb-col">
        <div class="kb-head estado-<?= $k ?>">
          <?= UI::icono($icono) ?> <?= e($label) ?>
          <span class="kb-count"><?= (int)$kbResumen[$k] ?></span>
        </div>
        <div class="kb-cards" data-estado-drop="<?= e($k) ?>">
          <?php foreach ($kbTareas as $t): if (($t['estado'] ?? '') !== $k) continue; ?>
          <?php $puedoMover = esAdmin() || TareaRepo::tieneAsignado($t, $miId); ?>
          <div class="kb-card <?= $puedoMover ? '' : 'kb-fija' ?>" draggable="<?= $puedoMover ? 'true' : 'false' ?>" data-tarea="<?= (int)$t['id'] ?>" data-ver-tarea='<?= $verTareaAttr($t) ?>'>
            <?php if ($puedoMover): ?>
            <button type="button" class="kb-mover" title="Mover a otra columna" aria-label="Mover a otra columna"><i class="fa-solid fa-ellipsis-vertical"></i></button>
            <?php endif; ?>
            <b><?= e($t['titulo']) ?></b>
            <?php $choques = $cargaExtra($t); if ($choques): ?>
            <span class="kb-carga" title="<?= e(avisoCargaExtra($choques)) ?>">
              <?= UI::icono('TriangleWarning') ?> <?= count($choques) ?>
            </span>
            <?php endif; ?>
            <div class="kb-meta">
              <?= UI::avatarsAsignados($t, $miembros, 22) ?>
              <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
              <?php if ($kbFiltro):
                  // Por qué esta tarjeta entra en el rango: se completó o se creó.
                  $fFin = ProyectoRepo::fecha($t['completada_en'] ?? '');
                  $enFin = $fFin !== '' && $fFin >= $dDesde && $fFin <= $dHasta; ?>
                <?php if ($enFin): ?>
                <small class="kb-dia kb-dia-fin" title="Marcada como completada el <?= e($fFin) ?>">
                  <i class="fa-solid fa-check"></i> <?= e($diaCorto($fFin)) ?>
                </small>
                <?php else: ?>
                <small class="kb-dia" title="Creada el <?= e($creadaEl($t)) ?>">
                  <i class="fa-solid fa-plus"></i> <?= e($diaCorto($creadaEl($t))) ?>
                </small>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($t['fecha_limite'])): ?>
              <small><i class="fa-regular fa-calendar"></i> <?= e($t['fecha_limite']) ?></small>
              <?php endif; ?>
              <?php
                // Dependencias: las de OTRO equipo salen con su icono y color;
                // las del propio tablero, con el eslabón de siempre.
                $propias = 0;
                foreach (TareaRepo::dependenciasDe($t) as $depId):
                    $di = $infoDep($depId); if (!$di) continue;
                    if (!$di['externa']) { $propias++; continue; } ?>
              <small class="kb-dep-eq<?= $di['urgencia'] ? ' dep-' . $di['urgencia'] : '' ?><?= $di['final'] ? ' dep-lista' : '' ?>" style="--dc:<?= e($di['color']) ?>"
                     title="Depende de «<?= e($di['titulo']) ?>» · equipo <?= e($di['equipo']) ?><?= $di['urgencia'] === 'vencida' ? ' · vencida' : ($di['urgencia'] === 'proxima' ? ' · próxima a vencer' : '') ?>">
                <?= $di['icono'] ?><?php if ($di['final']): ?><i class="fa-solid fa-check dep-ok"></i><?php endif; ?>
              </small>
              <?php endforeach; if ($propias): ?>
              <small title="Depende de <?= $propias ?> tarea<?= $propias === 1 ? '' : 's' ?> de este tablero"><i class="fa-solid fa-link"></i><?= $propias > 1 ? ' ' . $propias : '' ?></small>
              <?php endif; ?>
              <?php $nAdj = count(TareaRepo::adjuntosDe($t)); if ($nAdj > 0): ?>
              <small title="<?= $nAdj ?> documento<?= $nAdj === 1 ? '' : 's' ?> de respaldo"><i class="fa-solid fa-paperclip"></i> <?= $nAdj ?></small>
              <?php endif; ?>
              <?php if ($depsOn && in_array($t['estado'] ?? '', $finales, true)):
                  // Completada: lo siguiente que preguntan es si ya está arriba.
                  $depT = (int)($t['deploy_id'] ?? 0); ?>
                <?php if ($depT > 0): ?>
                <small class="kb-deploy kb-deploy-si" title="Subida a <?= e($depEntorno) ?> el <?= e($t['desplegada_en'] ?? '') ?>">
                  <?= UI::icono('Upload') ?> <?= e(substr((string)($t['desplegada_en'] ?? ''), 5, 11)) ?>
                </small>
                <?php else: ?>
                <small class="kb-deploy kb-deploy-no" title="Completada, pero todavía no se ha subido a <?= e($depEntorno) ?>">
                  <?= UI::icono('Clock') ?> sin subir
                </small>
                <?php endif; ?>
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

<!-- Vista de flujo: tareas conectadas por dependencias (el supervisor también la ve) -->
<div data-vista-panel="flujo" hidden>
  <section class="card-base tabla-card flujo-card">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid fa-diagram-project text-secondary"></i> Flujo de dependencias</h2>
      <?php if (!$hayDependencias): ?>
      <span class="ajuste-ayuda">Asigna "Depende de" al crear o editar una tarea para encadenarlas aquí.</span>
      <?php endif; ?>
      <?= $radVistas ?>
    </div>
    <?php if (empty($tareas)): ?>
      <?= UI::vacio('fa-diagram-project', 'Sin tareas', 'Crea tareas para ver su flujo.') ?>
    <?php elseif (!$hayDependencias): ?>
      <?php /* Sin dependencias no hay nada que encadenar: antes salía el lienzo
               con todas las tareas en una columna y ni una flecha. */ ?>
      <?= UI::vacio('fa-diagram-project', 'Todavía no hay dependencias',
            'Marca "Depende de" al crear o editar una tarea y aquí verás cómo se encadenan.') ?>
    <?php else: ?>
    <div class="flujo-wrap" id="flujo-wrap" style="--pc:<?= $color ?>">
      <svg class="flujo-lineas" id="flujo-lineas"></svg>
      <div class="flujo-cols">
        <?php
        $columnas = [];
        foreach ($tareasFlujo as $t) {
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
              $esExt    = (int)$t['proyecto_id'] !== $id;   // nodo de OTRO equipo
              $pExt     = $esExt ? ($proyGlobal[(int)$t['proyecto_id']] ?? null) : null;
          ?>
          <?php if ($esExt): ?>
          <a class="flujo-nodo nodo-externo <?= $esFinalF ? 'nodo-hecho' : '' ?>" style="--ec:<?= e($colorEquipo($pExt)) ?>"
             id="fn-<?= (int)$t['id'] ?>" data-deps="<?= e(implode(',', TareaRepo::dependenciasDe($t))) ?>"
             href="proyecto.php?id=<?= (int)$t['proyecto_id'] ?>#fn-<?= (int)$t['id'] ?>"
             title="Tarea del equipo <?= e($pExt['nombre'] ?? '') ?> — abrir su tablero">
            <span class="fn-equipo"><?= $pExt ? UI::icono($pExt['icono'] ?? 'FolderOpen', 'fn-eq-ico') : '' ?> <?= e($pExt['nombre'] ?? 'Otro equipo') ?></span>
            <b><?= e($t['titulo']) ?></b>
            <div class="fn-meta">
              <?= UI::avatarsAsignados($t, $miembros, 24) ?>
              <?= UI::badgeEstadoTarea($t['estado'] ?? '') ?>
              <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
            </div>
          </a>
          <?php else: ?>
          <div class="flujo-nodo <?= $esFinalF ? 'nodo-hecho' : '' ?> <?= $fAsignado && !TareaRepo::tieneAsignado($t, $fAsignado) ? 'nodo-ajeno' : '' ?>"
               id="fn-<?= (int)$t['id'] ?>" data-deps="<?= e(implode(',', TareaRepo::dependenciasDe($t))) ?>" data-ver-tarea='<?= $verTareaAttr($t) ?>'>
            <b><?= e($t['titulo']) ?></b>
            <div class="fn-meta">
              <?= UI::avatarsAsignados($t, $miembros, 24) ?>
              <?= UI::badgeEstadoTarea($t['estado'] ?? '') ?>
              <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
            </div>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php
// ---------------------------------------------------------------------------
// Tareas en el celular: tarjetas pequeñas agrupadas por estado.
//
// En el celular (hasta 900 px) la tabla, el Kanban y el Flujo no caben: se
// ocultan y en su lugar salen estas tarjetas, con las mismas tareas que la
// tabla (mismos filtros, sin las archivadas). Tocar una abre el mismo detalle
// de solo lectura que la tabla: el botón lleva data-ver-tarea y admin.js lo
// abre con el clic general. En la computadora no se ven (ds.css, bloque
// «Proyecto en el celular»). Al supervisor también le salen, en lugar de su
// Kanban, por eso van fuera del bloque que se le oculta.
$pmGrupos = [];
foreach ($estadosCat as $k => [$label, $icono]) {
    $pmGrupos[$k] = ['label' => $label, 'icono' => $icono, 'tareas' => []];
}
foreach ($visibles as $t) {
    $k = (string)($t['estado'] ?? '');
    // Un estado que ya no está en el catálogo: se agrupa aparte en vez de perderla
    if (!isset($pmGrupos[$k])) {
        $pmGrupos[$k] = ['label' => $k !== '' ? ucfirst($k) : 'Sin estado', 'icono' => 'fa-circle-question', 'tareas' => []];
    }
    $pmGrupos[$k]['tareas'][] = $t;
}
// Dentro de cada estado, lo que vence antes va arriba; lo que no tiene fecha, al final.
foreach ($pmGrupos as &$pmG) {
    usort($pmG['tareas'], fn($a, $b) =>
        [($a['fecha_limite'] ?? '') ?: '9999-99-99', (int)$a['id']] <=> [($b['fecha_limite'] ?? '') ?: '9999-99-99', (int)$b['id']]);
}
unset($pmG);
// Datos del asistente de editar (los mismos que manda el lápiz de la tabla)
$pmEditarAttr = fn(array $t) => e(json_encode([
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
], JSON_UNESCAPED_UNICODE));
?>
<!-- Tareas en el celular: tarjetas pequeñas; al tocar una se abre su detalle -->
<div class="pm-tareas" data-vista-panel="tarjetas"<?= $esSupervisor ? '' : ' hidden' ?>>
  <div class="pm-tareas__cab">
    <h2 class="pm-tareas__tit font-display">Tareas <span class="pm-tareas__n" data-pm-cuenta><?= count($visibles) ?></span></h2>
    <?php if ($puedeTareas): ?>
    <button type="button" class="btn-primary btn-meca btn-agregar btn-sm" onclick="document.getElementById('dlg-nueva-tarea').showModal()">
      <i class="fa-solid fa-plus"></i> Nueva tarea
    </button>
    <?php endif; ?>
  </div>

  <?php if ($verComo): ?>
  <a class="filtro-vercomo pm-tareas__aviso" href="?id=<?= $id ?>&amp;ver_como=0" title="Volver a tu vista y ver todas las tareas">
    <i class="fa-solid fa-eye"></i>
    <span>Viendo como <b><?= e(explode(' ', trim($verComo['nombre']))[0]) ?></b>: solo sus tareas</span>
    <i class="fa-solid fa-xmark"></i>
  </a>
  <?php elseif ($fEstado || $fAsignado): ?>
  <a href="?id=<?= $id ?>" class="filtro-clear pm-tareas__aviso"><i class="fa-solid fa-filter-circle-xmark"></i> Quitar el filtro y ver todas</a>
  <?php endif; ?>

  <?php if (count($visibles) > 5): ?>
  <label class="pm-buscar">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <input class="input-meca" type="search" data-pm-buscar placeholder="Buscar por título, responsable o estado…" autocomplete="off" aria-label="Buscar tareas">
  </label>
  <?php endif; ?>

  <?php if (empty($visibles)): ?>
    <?= UI::vacio('fa-clipboard-list', 'Sin tareas aquí', match (true) {
          (bool)$verComo => 'Estás viendo el panel como ' . explode(' ', trim($verComo['nombre']))[0]
                            . ', y aquí solo salen las tareas suyas. Quita «Viendo como» para verlas todas.',
          $fEstado || $fAsignado => 'No hay tareas con esos filtros.',
          default => 'Agrega la primera tarea de este proyecto.',
        }) ?>
  <?php else: ?>
  <?php foreach ($pmGrupos as $k => $g): if (!$g['tareas']) continue; ?>
  <section class="pm-grupo" data-pm-grupo>
    <h3 class="pm-grupo__tit">
      <span class="pm-grupo__ico estado-<?= e($k) ?>"><?= UI::icono($g['icono']) ?></span>
      <?= e($g['label']) ?> <span class="pm-grupo__n" data-pm-n><?= count($g['tareas']) ?></span>
    </h3>
    <?php foreach ($g['tareas'] as $t):
        $pmFinal = in_array($t['estado'] ?? '', $finales, true);
        $pmUrg   = $urgenciaDe($t);   // 'vencida', 'proxima' o ''
        $pmGente = array_values(array_filter(array_map(fn($mid) => $miembros[$mid] ?? null, TareaRepo::asignadosDe($t))));
        $pmObs   = $obsPorTarea[(int)$t['id']] ?? 0;
        $pmPrio  = (string)($t['prioridad'] ?? '');
        $pmLim   = (string)($t['fecha_limite'] ?? '');
        // Quién la lleva, en corto: el primer nombre, y «+N» si son varios
        $pmNombre = $pmGente
            ? explode(' ', trim($pmGente[0]['nombre'] ?? ''))[0] . (count($pmGente) > 1 ? ' +' . (count($pmGente) - 1) : '')
            : 'Sin asignar';
        // Lo que mira el buscador (igual que la tabla: el estado de verdad, no las opciones de un select)
        $pmBuscar = mb_strtolower(trim(
            '#' . (int)$t['id'] . ' ' . ($t['titulo'] ?? '')
            . ' ' . HtmlRico::texto($t['descripcion'] ?? '', 200)
            . ' ' . implode(' ', array_map(fn($m) => ($m['nombre'] ?? '') . ' ' . ($m['git_user'] ?? ''), $pmGente))
            . ' ' . $g['label']
            . ' ' . ($prioCat[$pmPrio][0] ?? '')
        ));
    ?>
    <article class="pm-tarea<?= $pmFinal ? ' is-hecha' : '' ?>" data-pm-tarea data-buscar="<?= e($pmBuscar) ?>">
      <div class="pm-tarea__top">
        <button type="button" class="pm-tarea__abrir" data-ver-tarea='<?= $verTareaAttr($t) ?>'>
          <span class="pm-tarea__titulo"><span class="pm-tarea__id">#<?= (int)$t['id'] ?></span> <?= e($t['titulo']) ?></span>
        </button>
        <?php if ($puedeTareas): ?>
        <button type="button" class="pm-tarea__editar" title="Editar" aria-label="Editar «<?= e($t['titulo']) ?>»"
                data-editar-tarea='<?= $pmEditarAttr($t) ?>'><i class="fa-solid fa-pen"></i></button>
        <?php endif; ?>
      </div>
      <div class="pm-tarea__meta">
        <?php /* Píldora con el color del estado. El ícono del catálogo (un SVG de
                 unos 3 KB) va solo en el encabezado del grupo, no en cada tarjeta. */ ?>
        <span class="pm-pill estado-<?= e($k) ?>"><span class="pm-pill__punto" aria-hidden="true"></span> <?= e($g['label']) ?></span>
        <?php if (isset($prioCat[$pmPrio])): ?>
        <span class="pm-pill pm-prio"><span class="prio-dot prio-<?= e($pmPrio) ?>"></span> <?= e($prioCat[$pmPrio][0]) ?></span>
        <?php endif; ?>
        <?php if ($pmLim !== ''): ?>
        <span class="pm-fecha<?= $pmUrg ? ' is-' . $pmUrg : '' ?>"
              title="Fecha límite: <?= e($pmLim) ?><?= $pmUrg === 'vencida' ? ' · vencida' : ($pmUrg === 'proxima' ? ' · vence pronto' : '') ?>">
          <i class="fa-regular fa-calendar"></i> <?= e($diaCorto($pmLim)) ?><?= substr($pmLim, 0, 4) !== date('Y') ? ' ' . e(substr($pmLim, 0, 4)) : '' ?>
        </span>
        <?php endif; ?>
        <?php if ($pmObs > 0): ?>
        <span class="pm-obs" title="<?= $pmObs ?> observación<?= $pmObs === 1 ? '' : 'es' ?> pendiente<?= $pmObs === 1 ? '' : 's' ?>">
          <i class="fa-solid fa-comment-dots"></i> <?= $pmObs ?>
        </span>
        <?php endif; ?>
        <?= $relChips($t) ?>
        <span class="pm-gente">
          <?= UI::avatarsAsignados($t, $miembros, 22) ?>
          <span class="pm-gente__nom"><?= e($pmNombre) ?></span>
        </span>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
  <p class="pm-vacio" data-pm-vacio hidden>Ninguna tarea coincide con la búsqueda.</p>
  <?php endif; ?>

  <?php if ($nArchivadas > 0 && !$verArchivadas): ?>
  <a class="pm-archivadas" href="?id=<?= $id ?>&arch=1"><i class="fa-solid fa-box-archive"></i> Ver <?= $nArchivadas ?> archivada<?= $nArchivadas === 1 ? '' : 's' ?> (completadas hace más de una semana)</a>
  <?php endif; ?>
</div>

<?php if (!$esSupervisor): /* calendario, intercambios, reuniones, observaciones y métricas: ocultos al supervisor */ ?>
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
      // $puedeTareas faltaba en el use: dentro del cierre valia null, asi que
      // el calendario abria SIEMPRE el detalle de solo lectura — ni el admin
      // podia editar una tarea desde ahi — y ademas PHP avisaba por cada
      // evento pintado, reventando la rejilla del mes.
      $pintarEv = function (array $ev) use ($color, $finales, $hoyIso, $id, $verTareaAttr, $depSolo, $puedeTareas) {
          if ($ev['tipo'] === 'tarea') {
              $t = $ev['dato']; $pos = $ev['pos'];
              $esDep = isset($depSolo[(int)$t['id']]);   // ajena, incluida por ser dependencia de una mía
              $clsDep = $esDep ? ' cal-ev-dep' : '';
              $notaDep = $esDep ? ' · (de la que depende la tuya)' : '';
              $venc = ($ev['fin'] < $hoyIso) && !in_array($t['estado'] ?? '', $finales, true);
              $datos = e(json_encode([
                  'id' => (int)$t['id'], 'titulo' => $t['titulo'], 'descripcion' => $t['descripcion'] ?? '',
                  'prioridad' => $t['prioridad'], 'estado' => $t['estado'], 'asignados' => TareaRepo::asignadosDe($t),
                  'fecha_inicio' => $t['fecha_inicio'] ?? '',
                  'fecha_limite' => $t['fecha_limite'] ?? '', 'depende_de' => (int)($t['depende_de'] ?? 0), 'dependencias' => TareaRepo::dependenciasDe($t),
                  'adjuntos' => TareaRepo::adjuntosDe($t),
              ], JSON_UNESCAPED_UNICODE));
              $rango = $ev['ini'] === $ev['fin'] ? $ev['ini'] : ($ev['ini'] . ' → ' . $ev['fin']);
              $attr = $puedeTareas ? "data-editar-tarea='$datos'" : "data-ver-tarea='" . $verTareaAttr($t) . "'";
              if ($pos === 'inicio' || $pos === 'solo') {
                  echo '<button type="button" class="cal-ev cal-ev-tarea cal-' . $pos . $clsDep . ' ' . ($venc ? 'cal-venc' : '') . '"'
                     . ' style="--tc:' . $color . '" title="' . e($t['titulo']) . ' · ' . e($rango) . e($notaDep) . '" ' . $attr . '>'
                     . '<span class="prio-dot prio-' . e($t['prioridad'] ?? 'media') . '"></span>'
                     . e(mb_strimwidth($t['titulo'], 0, 22, '…')) . '</button>';
              } else {
                  echo '<button type="button" class="cal-ev cal-ev-linea cal-' . $pos . $clsDep . ' ' . ($venc ? 'cal-venc' : '') . '"'
                     . ' style="--tc:' . $color . '" title="' . e($t['titulo']) . ' · hasta ' . e($ev['fin']) . e($notaDep) . '" ' . $attr . '>'
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

<?php if ($relPanel):
    // La pestaña «Release» es la vista de Releases (lib/releases_ui.php), fija en el
    // equipo de este proyecto. Los filtros van con prefijo rl_ (aquí «estado» ya filtra tareas).
    require_once __DIR__ . '/lib/releases_ui.php';
    $eqRel = (string)($relPanel['equipos'][0] ?? '');
    $relsRel = Releases::releases();
    // rl_r puede traer varios releases, separados por comas
    $rcRel = array_values(array_filter(explode(',', (string)($_GET['rl_r'] ?? '')), fn($x) => isset($relPanel['releases'][$x]))) ?: [$relPanel['actual']];
    $urlRel = fn(array $p) => 'proyecto.php?' . http_build_query(array_filter(['id' => $id, 'rl_r' => $p['r'] ?? '', 'rl_estado' => $p['estado'] ?? '',
        'rl_salud' => $p['salud'] ?? '', 'rl_q' => $p['q'] ?? ''], fn($x) => $x !== '' && $x !== null)) . '#vista-release';
?>
<!-- Vista Release: la de Releases, fija en el equipo de este proyecto. Al pulsar un
     ítem se abre su panel (assets/releases.js), donde el SM le agrega tareas. -->
<div data-vista-panel="release" hidden>
  <div class="ds rl rl--incrustado" data-rl data-vista="release">
    <?php rlVistaRelease([
        'rels' => $relsRel, 'eqs' => Releases::equipos(), 'todos' => Releases::items(), 'miembros' => $miembros, 'hoy' => date('Y-m-d'), 'rc' => $rcRel,
        'equipo' => $eqRel, 'fijo' => true, 'clicable' => Releases::veTodo(),
        'estado' => isset(Releases::ESTADOS[$_GET['rl_estado'] ?? '']) ? (string)$_GET['rl_estado'] : '',
        'salud' => isset(Releases::SALUD[$_GET['rl_salud'] ?? '']) ? (string)$_GET['rl_salud'] : '',
        'q' => trim((string)($_GET['rl_q'] ?? '')),
        'url' => $urlRel,
        'buscar' => ['proyecto.php', fn(array $b) => ['id' => $id, 'rl_r' => $b['r'], 'rl_estado' => $b['estado'], 'rl_salud' => $b['salud']], 'rl_q'],
    ]); ?>
  </div>
</div>
<?php endif; ?>
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
            <button class="btn-outline btn-meca btn-sm btn-rojo" name="respuesta" value="rechazar">
              <i class="fa-solid fa-xmark"></i> Rechazar
            </button>
          </form>
          <?php elseif ($mio): ?>
          <form method="post" action="actions.php" class="inter-form"
                data-confirmar="Se retirará tu propuesta de intercambio." data-confirmar-titulo="¿Retirar propuesta?" data-confirmar-ok="Sí, retirar">
            <input type="hidden" name="accion" value="intercambio_cancelar">
            <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <button class="btn-outline btn-meca btn-rojo btn-sm"><i class="fa-solid fa-rotate-left"></i> Retirar propuesta</button>
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
          <span>La tarea que sueltas</span>
          <?= UI::select('tarea_de', $opcionesMisTareas, (string)array_key_first($opcionesMisTareas)) ?>
          <small class="campo-ayuda">Solo aparecen tus tareas de este proyecto.</small>
        </label>
        <label class="campo">
          <span>La tarea que tomarías</span>
          <?= UI::select('tarea_para', $opcionesOtrasTareas, (string)array_key_first($opcionesOtrasTareas)) ?>
          <small class="campo-ayuda">La propuesta le llegará a quien la tenga asignada.</small>
        </label>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Motivo</span>
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
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-paper-plane"></i> Enviar propuesta</button>
        </div>
      </div>
    </div>
  </form>
</dialog>
<?php endif; ?>

<!-- Vista Reuniones: las próximas por día y lo último que pasó, con solo
     botones de ícono (el mismo pintado de la suite reuniones.php, en
     lib/reuniones_ui.php). Todo lo de cada una —la grabación, la
     transcripción, el acta con IA, editar y eliminar— está en su detalle
     (reunion.php); el historial entero, en reuniones.php?ver=historial. -->
<div data-vista-panel="reuniones" hidden>
  <?php
    require_once __DIR__ . '/lib/reuniones_ui.php';
    $ruAhora    = time();
    $ruProximas = ReunionesUI::proximas($reuniones, [$id => $proyecto], $ruAhora);
    $ruPasadas  = array_slice(ReunionesUI::pasadas($reuniones, [$id => $proyecto], $ruAhora), 0, 5);
    $ruCtx      = ['ahora' => $ruAhora, 'miembros' => $miembros, 'proyecto' => false,
                   'rastros' => ReunionesUI::rastros(array_map(fn($v) => $v['r'], $ruPasadas))];
  ?>
  <section class="card-base tabla-card ru-proy" style="--pc:<?= $color ?>">
    <div class="tabla-toolbar">
      <h2 class="font-display"><?= UI::icono('ds-video', 'text-secondary') ?> Reuniones
        <?php if ($ruProximas): ?><span class="tabla-count" title="Próximas"><?= count($ruProximas) ?></span><?php endif; ?>
      </h2>
      <div class="ru-acc ru-proy__acc">
        <?php if (UI::ia('daily_resumen') && puedeGestionar($id)):
            // Desde el último día hábil: el lunes mira desde el viernes.
            $wIa = (int)date('w');
            $desdeIa = date('Y-m-d', strtotime($wIa === 1 ? '-3 day' : ($wIa === 0 ? '-2 day' : '-1 day'))); ?>
        <?= ReunionesUI::ico('ds-ia', 'Resumen para el daily con IA: qué hizo cada persona (commits y tareas) y en qué sigue', '', 'ru-ico--ia', 'data-ia-daily="' . $id . '" data-desde="' . e($desdeIa) . '"') ?>
        <?php endif; ?>
        <?= ReunionesUI::ico('ds-reloj', 'Historial de las reuniones de este proyecto', 'reuniones.php?ver=historial&p=' . $id) ?>
        <?php if ($reunionesOn && $puedeReuniones): ?>
        <?= ReunionesUI::ico('ds-mas', 'Nueva reunión', '', 'ru-ico--nueva', 'aria-haspopup="dialog" onclick="document.getElementById(\'dlg-nueva-reunion\').showModal()"') ?>
        <?php elseif (!$reunionesOn): ?>
        <?= ReunionesUI::ico('ds-configuracion', 'Configurar reuniones (Zoom o Google Meet)', 'ajustes.php#tab-reuniones') ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$reunionesOn): ?>
      <div class="obs-intro"><?= UI::icono('ds-video') ?>
        <span>Ve a <a href="ajustes.php#tab-reuniones">Ajustes → Reuniones</a> y configura Zoom —o activa Google
        Calendar— para crear reuniones desde aquí: sueltas o repetidas (por ejemplo, todos los días de lunes a
        viernes), con sus invitados y sus grabaciones.</span>
      </div>
    <?php endif; ?>

    <div class="ru ru--proy">
      <?php $ruHtml = ReunionesUI::proximasHtml($ruProximas, $ruCtx); ?>
      <?php if ($ruHtml !== ''): ?>
      <div class="ru-lista"><?= $ruHtml ?></div>
      <?php elseif ($reunionesOn || $reuniones): ?>
      <?= ReunionesUI::vacio('CalendarCheck', 'No hay reuniones por venir', $reunionesOn && $puedeReuniones ? 'Crea una con el botón + de arriba: suelta o repetida, con sus invitados.' : 'Cuando agenden una en este proyecto, sale aquí en su día.') ?>
      <?php endif; ?>

      <?php if ($ruPasadas): ?>
      <div class="ru-ultimas">
        <header class="ru-ultimas__cab">
          <h3 class="ru-ultimas__t">Lo último</h3>
          <a class="rs-card__ver" href="reuniones.php?ver=historial&amp;p=<?= $id ?>">Ver todo el historial</a>
        </header>
        <div class="ru-lista"><?= ReunionesUI::dias($ruPasadas, $ruCtx, true) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <?php
    // Desde el detalle (reunion.php), «Editar» llega con ?editar_reunion=ID: este botón
    // escondido lleva los datos de esa reunión y admin.js lo pulsa para abrir el asistente.
    $ruEditar = (int)($_GET['editar_reunion'] ?? 0);
    $ruEditarR = $ruEditar && $puedeReuniones && $reunionesOn ? $reunionesRepo->buscar($ruEditar) : null;
    if ($ruEditarR && (int)$ruEditarR['proyecto_id'] === $id): ?>
    <button type="button" class="js-editar-reunion" data-editar-reunion='<?= ReunionesUI::datosEditar($ruEditarR) ?>' data-ru-abrir hidden></button>
    <?php endif; ?>
  </section>
</div>

<!-- Vista Observaciones: revisión (analistas / programadores) -->
<div data-vista-panel="observaciones" hidden>
  <section class="card-base tabla-card obs-card" style="--pc:<?= $color ?>">
    <div class="tabla-toolbar">
      <h2 class="font-display"><?= UI::icono('ds-mensajes', 'text-secondary') ?> Observaciones
        <span class="tabla-count"><?= $obsResumen['total'] ?></span>
      </h2>
      <div class="tabla-filtros">
        <div class="obs-filtros" id="obs-filtros">
          <button type="button" class="chip-filtro active" data-filtro="todas">Todas</button>
          <button type="button" class="chip-filtro" data-filtro="pendiente">Pendientes <?php if ($obsPendientes): ?>· <?= $obsPendientes ?><?php endif; ?></button>
          <button type="button" class="chip-filtro" data-filtro="resuelta">Resueltas</button>
        </div>
        <?php if (!$esSupervisor): ?>
        <button type="button" class="btn-primary btn-meca btn-agregar btn-sm" onclick="document.getElementById('dlg-obs-nueva').showModal()">
          <?= UI::icono('ds-mas') ?> Nueva observación
        </button>
        <?php endif; ?>
      </div>
    </div>

    <?php
    /* Observación nueva: un asistente de cuatro pasos (sobre qué, para quién, el texto y el
       resumen). Las tareas y las personas son tarjetas: el título se lee entero y se elige con
       un clic. Lo manda admin.js (initComposer) por AJAX a obs_crear, que no cambió. */
    $opcionesReunion = $reunionesRepo->opciones($id);
    if (!$esSupervisor):
      $obEstados = (array)Config::get('estados_tarea');
      $obFinales = Catalogo::estadosFinales();
      // Las abiertas primero y, entre ellas, la que vence antes
      $obTareas = array_values($tareas);
      usort($obTareas, fn($a, $b) => [in_array($a['estado'] ?? '', $obFinales, true), ($a['fecha_limite'] ?? '') ?: '9999']
                                 <=> [in_array($b['estado'] ?? '', $obFinales, true), ($b['fecha_limite'] ?? '') ?: '9999']);
    ?>
    <dialog id="dlg-obs-nueva" class="dlg-meca dlg-wizard dlg-obs-nueva">
      <form class="dlg-form wz obs-composer" method="post" action="actions.php" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="obs_crear">
        <input type="hidden" name="proyecto_id" value="<?= $id ?>">
        <?= UI::wizardRiel('ds-mensajes', 'Nueva observación', 'En ' . $proyecto['nombre'], UI::PASOS_OBSERVACION) ?>
        <div class="wz-cuerpo">
          <header>
            <div>
              <h4 class="wz-titulo-paso"></h4>
              <p class="wz-ayuda-paso"></p>
            </div>
            <button type="button" class="dlg-close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><?= UI::icono('ds-cerrar') ?></button>
          </header>

          <section class="wz-panel">
            <div class="campo ob-campo" data-ob-tareas data-resumen="General de la entrega"><span>Sobre</span>
              <?php if (count($obTareas) > 6): ?>
              <label class="ob-buscar"><?= UI::icono('ds-buscar') ?><input type="search" placeholder="Buscar una tarea…" data-ob-buscar aria-label="Buscar una tarea"></label>
              <?php endif; ?>
              <div class="ob-cards">
                <label class="ob-card ob-card--general">
                  <input type="checkbox" data-ob-general checked>
                  <span class="ob-card__ic"><?= UI::icono('ds-carpeta') ?></span>
                  <span class="ob-card__txt"><b class="ob-card__tit">General de la entrega</b><small>No es de una tarea en concreto</small></span>
                </label>
                <?php foreach ($obTareas as $t): $ek = (string)($t['estado'] ?? 'pendiente'); ?>
                <label class="ob-card<?= in_array($ek, $obFinales, true) ? ' ob-card--hecha' : '' ?>" data-ob-texto="<?= e(mb_strtolower((string)$t['titulo'])) ?>">
                  <input type="checkbox" name="tarea_id[]" value="<?= (int)$t['id'] ?>" data-ob-titulo="<?= e($t['titulo']) ?>">
                  <span class="ob-card__txt">
                    <span class="ob-estado" style="--ec:<?= e($obEstados[$ek]['color'] ?? '#64748B') ?>"><?= e($obEstados[$ek]['label'] ?? ucfirst($ek)) ?></span>
                    <b class="ob-card__tit"><?= e($t['titulo']) ?></b>
                    <span class="ob-card__pie"><?= UI::avatarsAsignados($t, $miembros, 22, 3) ?><?php if (!empty($t['fecha_limite'])): ?><small><?= UI::icono('Calendar') ?> <?= e(date('d/m', strtotime((string)$t['fecha_limite']))) ?></small><?php endif; ?></span>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php if ($opcionesReunion): ?>
            <label class="campo" data-ob-reunion data-resumen="Sin reunión"><span>De la reunión</span><?= UI::select('reunion_id', [0 => 'Sin reunión'] + $opcionesReunion, '0') ?></label>
            <?php endif; ?>
          </section>

          <section class="wz-panel">
            <?php /* El aviso (campana y correo) les llega a estas mismas personas: no se eligen dos veces */ ?>
            <input type="hidden" name="avisar_a_para" value="1">
            <div class="campo ob-campo" data-ob-para data-resumen="Nadie en concreto: es del proyecto"><span>Para</span>
              <small class="campo-ayuda">Les llega el aviso a la campana y por correo. Si no eliges a nadie, queda como observación del proyecto, sin avisar.</small>
              <div class="ob-cards ob-cards--gente">
                <label class="ob-card ob-persona ob-card--todos">
                  <input type="checkbox" name="autor_id[]" value="all" data-ob-todos data-ob-nombre="Todo el equipo">
                  <span class="ob-card__ic"><?= UI::icono('Users') ?></span>
                  <span class="ob-card__txt"><b class="ob-card__tit">Todo el equipo</b><small><?= count($delProyecto) ?> personas en el proyecto</small></span>
                </label>
                <?php foreach ($delProyecto as $m): ?>
                <label class="ob-card ob-persona">
                  <input type="checkbox" name="autor_id[]" value="<?= (int)$m['id'] ?>" data-ob-nombre="<?= e($m['nombre']) ?>">
                  <?= UI::avatar($m, 34) ?>
                  <span class="ob-card__txt"><b class="ob-card__tit"><?= e($m['nombre']) ?></b><small><?= e((string)($m['rol'] ?? '')) ?></small></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="wz-panel">
            <div class="campo ob-campo"><span>Observación</span>
              <?= UI::editorRico([
                    'name' => 'texto',
                    'placeholder' => 'Escribe la observación… pega capturas con Ctrl+V o arrástralas aquí.',
                  ]) ?>
              <?php if (UI::ia('obs_redaccion') || UI::ia('obs_adjunto')): ?>
              <div class="ia-acciones">
                <?php if (UI::ia('obs_redaccion')): ?><?= UI::botonIA('Mejorar redacción', ['ia-obs' => 'redaccion'], 'Reescribe el borrador de forma clara y profesional') ?><?php endif; ?>
                <?php if (UI::ia('obs_adjunto')): ?><?= UI::botonIA('Describir adjunto', ['ia-obs' => 'adjunto'], 'Redacta la observación mirando la captura o el PDF adjunto') ?><?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <div class="campo ob-campo" data-ob-adjuntos data-resumen="Ninguno"><span>Adjuntos</span>
              <?php /* La misma zona del correo (lib/correo_vista.php); los archivos los lleva
                       initComposer, que también suma las capturas pegadas con Ctrl+V */ ?>
              <div class="ds-adjuntos ob-adj">
                <input class="mc-sr-only oc-file" id="ob-adj-<?= $id ?>" type="file" name="adjuntos[]" multiple
                       accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,.doc,.docx">
                <label class="ds-adjuntos__zona" for="ob-adj-<?= $id ?>">
                  <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('importar', 18) ?></span>
                  <span class="ds-adjuntos__txt"><b>Arrastra los archivos aquí o <u>elígelos</u></b><small>Imágenes, PDF o Word · también puedes pegar capturas con Ctrl+V</small></span>
                </label>
                <ul class="ds-adjuntos__lista oc-previews"></ul>
              </div>
            </div>
            <template class="ob-iconos-adj"><?php foreach (['pdf', 'word', 'excel', 'ppt', 'imagen', 'otro'] as $c) echo '<span data-clase="' . $c . '">' . UI::icono('adj-' . $c) . '</span>'; ?></template>
          </section>

          <section class="wz-panel">
            <dl class="wz-resumen"></dl>
          </section>

          <div class="wz-pie">
            <span class="wz-contador"></span>
            <div class="wz-acciones">
              <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><?= UI::icono('ds-chevron-izq') ?> Atrás</button>
              <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <?= UI::icono('ds-chevron-der') ?></button>
              <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><?= UI::icono('ds-mensajes') ?> Anotar</button>
            </div>
          </div>
        </div>
      </form>
    </dialog>
    <?php endif; ?>

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
// Aportes del equipo: solo los participantes de ESTE proyecto (no toda la
// empresa) y SOLO desarrolladores (los analistas no aportan al git).
foreach ($delProyecto as $m) {
    if (MiembroRepo::equipoDe($m) === 'analistas') continue;
    // Una persona puede tener VARIOS usuarios de Git (otra máquina, otro nombre)
    // y/o validar por correo. Se juntan todas sus identidades para cruzar los
    // commits: usuarios (git_user), correo y su parte local (el login de GitLab).
    $ids = [];
    foreach (preg_split('/[,;]+/', (string)($m['git_user'] ?? '')) as $u) {
        $u = mb_strtolower(preg_replace('/\s+/', ' ', trim($u, " \t@")), 'UTF-8');
        if ($u !== '') $ids[] = $u;
    }
    foreach (MiembroRepo::gitEmailsDe($m) as $mail) {   // correos de Git + correo de acceso
        $ids[] = $mail;
        $ids[] = (string)strtok($mail, '@');            // parte local (el login de GitLab suele serlo)
    }
    if (!$ids) continue;   // sin ninguna identidad de Git no se pueden cruzar sus commits
    $comMiembros[] = [
        'id' => (int)$m['id'], 'gits' => array_values(array_unique($ids)), 'n' => $m['nombre'],
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
      <?php if (count($comReposLabels) > 1): ?>
      <!-- Con varios repos: elige cuál ver (filtra commits, mapa y ramas) -->
      <select class="select-meca select-sm ap-repo">
        <option value="">Todos los repos</option>
        <?php foreach ($comReposLabels as $rl): ?>
        <option value="<?= e($rl) ?>"><?= e($rl) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
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
      <button type="button" class="btn-outline btn-meca btn-sm btn-azul ap-ver-commits"><i class="fa-solid fa-list"></i> Ver commits</button>
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
        <button type="button" class="btn-outline btn-meca btn-neutro btn-sm apc-prev"><i class="fa-solid fa-arrow-left"></i> Anterior</button>
        <span class="apc-pag"></span>
        <button type="button" class="btn-outline btn-meca btn-neutro btn-sm apc-next">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
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
      <span class="om-equipo"><?= UI::iconoEquipo($ek, $eIcono, 'ico-equipo--chip') ?> <?= e($eLabel) ?></span>
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
<?php endif; /* fin de las vistas ocultas al supervisor */ ?>

<?php if ($reunionesOn): ?>
<!-- Modal: nueva reunión, por pasos (Zoom, Meet o enlace propio). La hora se
     elige en cuartos y lo que Zoom ya tiene ocupado sale en gris. -->
<dialog id="dlg-nueva-reunion" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="reunion_crear">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <?= UI::wizardRiel('fa-video', 'Nueva reunión', 'En ' . $proyecto['nombre'], UI::PASOS_REUNION) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo"><span>Tema</span>
          <input class="input-meca" name="topic" required maxlength="120" placeholder="Ej. Revisión de avances — <?= e($proyecto['nombre']) ?>" data-error="Escribe el tema de la reunión.">
        </label>
        <?php if ($platElegir): ?>
        <div class="campo" data-resumen="<?= e(Reuniones::etiquetaPlataforma($platDefecto)) ?>"><span>Plataforma</span>
          <input type="hidden" name="plataforma" value="<?= e($platDefecto) ?>">
          <div class="subvista-toggle nr-plat">
            <button type="button" class="subvista-btn <?= $platDefecto === 'zoom' ? 'active' : '' ?>" data-plat="zoom"><i class="fa-solid fa-video"></i> <b>Zoom</b><small>Se graba y la grabación llega al panel</small></button>
            <button type="button" class="subvista-btn <?= $platDefecto === 'meet' ? 'active' : '' ?>" data-plat="meet"><i class="fa-brands fa-google"></i> <b>Google Meet</b><small>Se crea en tu Google Calendar</small></button>
            <button type="button" class="subvista-btn <?= $platDefecto === 'enlace' ? 'active' : '' ?>" data-plat="enlace"><i class="fa-solid fa-link"></i> <b>Enlace propio</b><small>Tu propio enlace; no se graba</small></button>
          </div>
          <small class="campo-ayuda nr-meet-hint" <?= $platDefecto === 'meet' ? '' : 'hidden' ?>>La reunión de Meet se crea en TU Google Calendar (necesitas tenerlo conectado en Mi perfil).</small>
        </div>
        <!-- Enlace propio: para cuando la sala de Zoom está ocupada y hay que
             hacer la revisión igual. El panel guarda el detalle y avisa a los
             invitados, pero la reunión NO queda grabada. -->
        <label class="campo nr-enlace" <?= $platDefecto === 'enlace' ? '' : 'hidden' ?>>
          <span>Enlace de la reunión</span>
          <input class="input-meca" type="url" name="join_url" inputmode="url"
                 placeholder="https://us05web.zoom.us/j/… o el enlace que uses">
          <small class="campo-ayuda">
            <i class="fa-solid fa-circle-info"></i>
            Con tu propio enlace el panel guarda el detalle, lo agenda y avisa a los invitados,
            pero <b>la reunión no queda grabada</b>: la grabación solo llega desde Zoom.
          </small>
        </label>
        <?php else:
          // Sin selector conviene decir de dónde sale la plataforma, o parece impuesta sin motivo
          $platOrigen = ProyectoRepo::plataformaEntrada($proyecto['plataforma'] ?? '') !== ''
              ? 'la elegida para este proyecto'
              : (count(Reuniones::disponibles()) < 2 ? 'la única configurada' : 'la del panel');
        ?>
        <div class="campo" data-resumen="<?= e(Reuniones::etiquetaPlataforma($platDefecto)) ?>"><span>Plataforma</span>
          <input type="hidden" name="plataforma" value="<?= e($platDefecto) ?>">
          <div class="subvista-toggle nr-plat">
            <button type="button" class="subvista-btn active" data-plat="<?= e($platDefecto) ?>"><i class="<?= $platDefecto === 'meet' ? 'fa-brands fa-google' : 'fa-solid fa-video' ?>"></i> <b><?= e(Reuniones::etiquetaPlataforma($platDefecto)) ?></b><small><?= $platDefecto === 'meet' ? 'Se crea en tu Google Calendar' : 'Se graba y la grabación llega al panel' ?></small></button>
            <button type="button" class="subvista-btn" data-plat="enlace"><i class="fa-solid fa-link"></i> <b>Enlace propio</b><small>Tu propio enlace; no se graba</small></button>
          </div>
          <small class="campo-ayuda"><?= $platOrigen ?>. Se cambia en <?= $platOrigen === 'la elegida para este proyecto' ? 'Editar proyecto' : 'Ajustes → Reuniones' ?>.</small>
        </div>
        <!-- El enlace propio se ofrece aunque el panel fuerce una plataforma: es la
             salida cuando la sala está ocupada. -->
        <label class="campo nr-enlace" hidden>
          <span>Enlace de la reunión</span>
          <input class="input-meca" type="url" name="join_url" inputmode="url"
                 placeholder="https://us05web.zoom.us/j/… o el enlace que uses">
          <small class="campo-ayuda">
            <i class="fa-solid fa-circle-info"></i>
            Con tu propio enlace el panel guarda el detalle, lo agenda y avisa a los invitados,
            pero <b>la reunión no queda grabada</b>: la grabación solo llega desde Zoom.
          </small>
        </label>
        <?php endif; ?>
      </section>

      <section class="wz-panel">
        <?= UI::selectorHora(date('Y-m-d\TH:i', (int)(ceil((time() + 3600) / 900) * 900)), '', UI::select('duracion', $durOpciones, (string)$durDefecto)) ?>
        <!-- Repetición semanal: una sola reunión con un enlace fijo -->
        <div class="campo repetir-caja" data-resumen="No se repite">
          <span hidden>Repetición</span>
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
      </section>

      <section class="wz-panel">
        <label class="campo"><span>Invitados</span>
          <?= UI::select('invitados', $opcionesInvitados, [], false, '', true) ?>
          <small class="campo-ayuda">Les llega el enlace por correo si tienen uno registrado<?= Reuniones::agendaEnCalendarios() ? ', y la reunión queda en su Google Calendar' : '' ?>.</small>
        </label>
      </section>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-video"></i> Crear reunión</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<!-- Modal: editar reunión / invitar a más gente, por pasos -->
<dialog id="dlg-editar-reunion" class="dlg-meca dlg-wizard">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="reunion_editar">
    <input type="hidden" name="id" id="er-id" value="">
    <?= UI::wizardRiel('fa-pen', 'Editar reunión', 'En ' . $proyecto['nombre'], UI::PASOS_EDITAR_REUNION) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo"><span>Tema</span>
          <input class="input-meca" name="topic" id="er-topic" required maxlength="120" data-error="Escribe el tema de la reunión.">
        </label>
      </section>

      <section class="wz-panel">
        <?= UI::selectorHora('', 'er-inicio', UI::select('duracion', $durOpciones, (string)$durDefecto, false, 'js-er-duracion')) ?>
        <div class="campo repetir-caja" data-resumen="No se repite">
          <span hidden>Repetición</span>
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
      </section>

      <section class="wz-panel">
        <label class="campo"><span>Invitados</span>
          <?= UI::select('invitados', $opcionesInvitados, [], false, 'js-er-invitados', true) ?>
          <small class="campo-ayuda">A los invitados nuevos les llega el enlace por correo.</small>
        </label>
      </section>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
        </div>
      </div>
    </div>
  </form>
</dialog>
<?php endif; ?>

<!-- Datos para el selector de dependencias (equipo → sus tareas), compartido
     por los asistentes de crear y editar tarea. -->
<script id="dep-datos" type="application/json"><?= json_encode($depDatos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?></script>
<?php
/**
 * Selector de dependencias por pasos: primero "¿tiene dependencias?"; si sí,
 * se elige el equipo y sus tareas. Lo rellena y controla admin.js (dep-picker).
 */
function depPicker(): void { ?>
  <div class="campo dp-campo" data-dep-picker data-sin-resumen>
    <span>Dependencias</span>
    <div class="dp-toggle" role="group" aria-label="¿Depende de otra tarea?">
      <button type="button" class="dp-op active" data-dep="no"><i class="fa-solid fa-ban"></i> Sin dependencias</button>
      <button type="button" class="dp-op" data-dep="si"><i class="fa-solid fa-link"></i> Depende de otra(s) tarea(s)</button>
      <?php if (UI::ia('tarea_dependencias')): ?>
      <?= UI::botonIA('Sugerir con IA', ['ia-tarea' => 'dependencias', 'proyecto' => (int)($GLOBALS['id'] ?? 0)], 'Marca de qué tareas del proyecto depende esta') ?>
      <?php endif; ?>
    </div>
    <div class="dp-body" hidden>
      <div class="dp-fila">
        <select class="select-meca dp-equipo" aria-label="Equipo"></select>
        <input type="search" class="input-meca dp-buscar" placeholder="Buscar una tarea…" autocomplete="off">
      </div>
      <div class="dp-opciones" role="listbox"></div>
      <div class="dp-chips" aria-live="polite"></div>
      <div class="dp-hidden" hidden></div>
      <small class="campo-ayuda">Elige el equipo y sus tareas. La tarea queda «en espera» hasta que TODAS se completen. Si eliges una de <b>otro equipo</b>, se le avisa por correo.</small>
    </div>
  </div>
<?php } ?>

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
          <span>Título</span>
          <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Implementar login con Google" data-error="Escribe un título para la tarea.">
        </label>
        <div class="campo">
          <span>Descripción</span>
          <?= UI::editorRico(['name' => 'descripcion', 'placeholder' => 'Detalles, criterios de aceptación… (puedes pegar tablas y texto con formato)']) ?>
          <?php if (UI::ia('tarea_descripcion') || UI::ia('tarea_desglose')): ?>
          <div class="ia-acciones">
            <?php if (UI::ia('tarea_descripcion')): ?><?= UI::botonIA('Redactar descripción', ['ia-tarea' => 'descripcion', 'proyecto' => $id]) ?><?php endif; ?>
            <?php if (UI::ia('tarea_desglose')): ?><?= UI::botonIA('Partir en subtareas', ['ia-tarea' => 'desglose', 'proyecto' => $id], 'Propone subtareas encadenadas para crearlas de una') ?><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>Prioridad</span><?= UI::select('prioridad', array_map(fn($v) => $v[0], Catalogo::prioridades()), 'media') ?></label>
          <label class="campo"><span>Estado inicial</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()), 'pendiente') ?></label>
        </div>
        <?= $relCampo() ?>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Responsables</span>
          <?= UI::select('asignados', $opcionesAsignar, [], false, '', true) ?>
          <small class="campo-ayuda">Puedes elegir varias personas. <?= UI::ayudaEquipoProyecto($equipoProyecto) ?></small>
        </label>
        <?php depPicker(); ?>
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
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Crear tarea</button>
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
    <?= UI::wizardRiel('fa-pen', 'Editar tarea', 'En ' . $proyecto['nombre'], UI::PASOS_EDITAR_TAREA) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo"><span>Título</span><input class="input-meca" name="titulo" id="et-titulo" required maxlength="120" data-error="Escribe un título para la tarea."></label>
        <div class="campo"><span>Descripción</span><?= UI::editorRico(['name' => 'descripcion', 'id' => 'et-descripcion', 'placeholder' => 'Detalles, criterios de aceptación… (puedes pegar tablas)']) ?>
          <?php if (UI::ia('tarea_descripcion') || UI::ia('tarea_desglose')): ?>
          <div class="ia-acciones">
            <?php if (UI::ia('tarea_descripcion')): ?><?= UI::botonIA('Redactar descripción', ['ia-tarea' => 'descripcion', 'proyecto' => $id]) ?><?php endif; ?>
            <?php if (UI::ia('tarea_desglose')): ?><?= UI::botonIA('Partir en subtareas', ['ia-tarea' => 'desglose', 'proyecto' => $id], 'Propone subtareas encadenadas para crearlas de una') ?><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>Prioridad</span><?= UI::select('prioridad', array_map(fn($v) => $v[0], Catalogo::prioridades()), 'media', false, 'js-et-prioridad') ?></label>
          <label class="campo"><span>Estado</span><?= UI::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()), 'pendiente', false, 'js-et-estado') ?></label>
        </div>
        <?= $relCampo('js-et-rel') ?>
        <?php if ($relOpciones): ?><script type="application/json" id="rel-tareas-item"><?= json_encode((object)$relDeTareaAqui) ?></script><?php endif; ?>
      </section>

      <section class="wz-panel">
        <label class="campo">
          <span>Responsables</span>
          <?= UI::select('asignados', $opcionesAsignar, [], false, 'js-et-asignado', true) ?>
          <small class="campo-ayuda">Puedes elegir varias personas. <?= UI::ayudaEquipoProyecto($equipoProyecto) ?></small>
        </label>
        <?php depPicker(); ?>
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
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
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
        <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cancelar</button>
        <button class="btn-primary btn-meca"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
      </div>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<!-- Modal: detalle de tarea (solo lectura, lo abre cualquiera), por pasos: el detalle y su
     trazabilidad (historia, impedimentos, release y commits; los trae tarea_traza.php al abrirla) -->
<dialog id="dlg-detalle-tarea" class="dlg-meca dlg-wizard dlg-detalle-wz">
  <form class="dlg-form wz" onsubmit="return false">
    <?= UI::wizardRiel('fa-list-check', 'Detalle de la tarea', 'Todo lo que pasó con ella', UI::PASOS_DETALLE_TAREA) ?>
    <div class="wz-cuerpo">
      <header class="dt-head">
        <div class="dt-head-txt">
          <span class="dt-proyecto"></span>
          <h3 class="dt-titulo font-display"></h3>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel dt-panel-detalle">
   <div class="dt-cols">
    <div class="dt-info">
    <div class="dt-chips"></div>
    <span class="dt-restante" hidden></span>
    <div class="dt-carga" hidden></div>
    <div class="dt-desc rt-render"></div>
    <dl class="dt-datos">
      <div><dt><i class="fa-solid fa-user"></i> Responsables</dt><dd class="dt-asignados"></dd></div>
      <div class="dt-fila-colab" hidden><dt><i class="fa-solid fa-user-plus"></i> Ayuda</dt><dd>
        <?php /* Sin <form>: la ficha ya es un formulario (el asistente). Lo envía admin.js */ ?>
        <button type="button" class="dt-colab-btn" data-dt-colab-abrir><i class="fa-solid fa-user-plus"></i> Pedir ayuda o sumar a quien te ayudó</button>
        <div class="dt-colab-caja" data-dt-colab hidden>
          <label class="campo"><span>¿A quién?</span><select class="input-meca" data-dt-colab-quien></select></label>
          <label class="campo"><span>Para qué (opcional)</span><textarea class="input-meca" rows="2" maxlength="500" data-dt-colab-nota placeholder="Ej. me ayudó con las pruebas del formulario"></textarea></label>
          <div class="dt-colab-pie">
            <button type="button" class="btn-outline btn-meca btn-neutro btn-sm" data-dt-colab-cerrar>Cancelar</button>
            <button type="button" class="btn-primary btn-meca btn-sm" data-dt-colab-sumar><i class="fa-solid fa-user-plus"></i> Sumar</button>
          </div>
        </div>
      </dd></div>
      <div><dt><i class="fa-regular fa-calendar"></i> Fechas</dt><dd class="dt-fechas"></dd></div>
      <div class="dt-fila-dep" hidden><dt><i class="fa-solid fa-link"></i> Depende de</dt><dd class="dt-dep"></dd></div>
      <div class="dt-fila-obs" hidden><dt><i class="fa-solid fa-comment-dots"></i> Observaciones</dt><dd class="dt-obs"></dd></div>
      <div class="dt-fila-creada" hidden><dt><i class="fa-solid fa-user-pen"></i> Agregada por</dt><dd class="dt-creada"></dd></div>
      <div class="dt-fila-adj" hidden><dt><i class="fa-solid fa-paperclip"></i> Documentos</dt><dd class="dt-adjuntos"></dd></div>
    </dl>
    </div>

    <!-- Con documentos, el primero ya se ve aqui al abrir la tarea -->
    <aside class="dt-previa" hidden>
      <header class="dt-previa-cab">
        <span class="dt-previa-nom"><i class="fa-solid fa-paperclip"></i> <b></b></span>
      </header>
      <div class="dt-previa-cuerpo"></div>
    </aside>
   </div>
      </section>
      <section class="wz-panel"><div class="dt-traza" data-dt-traza="historia"></div></section>
      <section class="wz-panel"><div class="dt-traza" data-dt-traza="impedimentos"></div></section>
      <section class="wz-panel"><div class="dt-traza" data-dt-traza="release"></div></section>
      <section class="wz-panel"><div class="dt-traza" data-dt-traza="commits"></div></section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="button" class="btn-outline btn-meca btn-neutro wz-guardar" onclick="this.closest('dialog').close()">Cerrar</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php
// Para «Pedir ayuda»: la gente del proyecto (si no tiene lista, todo el equipo)
$genteFicha = [];
foreach (array_unique(array_merge(ProyectoRepo::miembrosDe($proyecto) ?? array_keys($miembros), [(int)($proyecto['scrum'] ?? 0)])) as $midF) {
    if (isset($miembros[$midF])) $genteFicha[] = ['id' => (int)$midF, 'nombre' => (string)$miembros[$midF]['nombre']];
}
usort($genteFicha, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));
// Enlace directo a una tarea (proyecto.php?id=…&tarea=…, p. ej. desde Releases): se abre su
// ficha al entrar, aunque esté archivada y no salga en el tablero
$tAbrir = (int)($_GET['tarea'] ?? 0) > 0 ? (new TareaRepo())->buscar((int)$_GET['tarea']) : null;
?>
<script type="application/json" id="dt-gente"><?= json_encode($genteFicha, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php if ($tAbrir && (int)$tAbrir['proyecto_id'] === $id): ?>
<div hidden id="tarea-abrir" data-ver-tarea="<?= $verTareaAttr($tAbrir) ?>"></div>
<?php endif; ?>

<!-- Modal: recordar una dependencia de otro equipo (lo abre el detalle de tarea) -->
<dialog id="dlg-dep-recordar" class="dlg-meca dlg-recordar">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="dep_recordar">
    <input type="hidden" name="proyecto_id" value="<?= $id ?>">
    <input type="hidden" name="tarea_id" id="dr-tarea">
    <input type="hidden" name="dep_tarea_id" id="dr-dep">
    <header class="dr-head">
      <div>
        <h3 class="font-display"><i class="fa-solid fa-bell text-secondary"></i> Enviar recordatorio</h3>
        <p class="dr-contexto"></p>
      </div>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <p class="dr-para"></p>
    <label class="campo">
      <span>Mensaje (opcional)</span>
      <textarea class="input-meca" name="nota" rows="3" maxlength="500"
                placeholder="Ej. Necesito esto para avanzar con mi tarea. ¿Para cuándo lo tendrías?"></textarea>
    </label>
    <footer class="dr-pie">
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cancelar</button>
      <button class="btn-primary btn-meca"><i class="fa-solid fa-paper-plane"></i> Enviar recordatorio</button>
    </footer>
  </form>
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
        <label class="campo"><span>Nombre</span><input class="input-meca" name="nombre" required value="<?= e($proyecto['nombre']) ?>"></label>
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
        <label class="campo">
          <span><i class="fa-solid fa-user-tie"></i> Product Owner</span>
          <?= UI::select('po', $opcionesAnalistas, $poProyecto) ?>
          <small class="campo-ayuda">El PO del proyecto (se elige entre los analistas). Junto con el Scrum Master, crea y edita las tareas y pone el horario de reuniones.</small>
        </label>
        <label class="campo">
          <span><i class="fa-solid fa-user-shield"></i> Product Owner de relevo</span>
          <?= UI::select('po_relevo', [0 => '— Sin PO de relevo —'] + array_diff_key($opcionesAnalistas, [0 => true]), (int)($proyecto['po_relevo'] ?? 0)) ?>
          <small class="campo-ayuda">Cubre al PO principal cuando no está (vacaciones, permisos): tiene sus mismos permisos en el proyecto.</small>
        </label>
        <label class="campo">
          <span><i class="fa-solid fa-user-gear"></i> Scrum Master</span>
          <?= UI::select('scrum', $opcionesScrum, $scrumProyecto) ?>
          <small class="campo-ayuda">Quién lleva ESTE proyecto. Junto con el Product Owner, es quien pone su horario de reuniones diarias.</small>
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
          <span>Portada</span>
          <?= UI::selectorPortada($proyecto['portada'] ?? '') ?>
        </div>
        <div class="campo" data-sin-resumen>
          <span>Ícono</span>
          <?= UI::selectorIcono($proyecto['icono'] ?? null) ?>
        </div>
        <div class="campo" data-sin-resumen>
          <span>Color</span>
          <?= UI::colorPicker($proyecto['color'] ?? 0) ?>
        </div>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php if ($relPanel) echo rlRecursosJs('release', Releases::puedeEditar(), $miembros); /* el panel del ítem y el Gantt (assets/releases.js) */ ?>
<?php UI::fin(); ?>
