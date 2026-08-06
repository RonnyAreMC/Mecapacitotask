<?php
/**
 * Punto unico de entrada para todas las acciones POST del panel.
 * Cada accion valida, ejecuta sobre el repositorio y redirige con flash.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('index.php');
}

// Si el envio supero post_max_size, PHP descarta TODO el formulario en silencio
// (por eso "no guarda ni el nombre"). Avisamos claramente en vez de ignorarlo.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    redirigir(
        paginaOrigen(),
        'El envío supera el límite del servidor (' . ini_get('post_max_size') . '), probablemente por una foto muy pesada. No se guardó nada.',
        'error'
    );
}

$accion = $_POST['accion'] ?? '';

/* ---------- Control de acceso por acción ----------
   públicas      : sin sesión (login y primer acceso)
   cualquiera    : con sesión iniciada (salir, anotar observaciones)
   resto         : solo administrador                                     */
$accionesPublicas   = ['auth_login', 'auth_identificar'];
// Los intercambios los pide y responde la propia gente, no un administrador:
// cada accion comprueba por dentro que la tarea sea suya.
$accionesDeCualquiera = [
    'auth_logout', 'obs_crear', 'perfil_guardar', 'mis_tareas_json', 'proyecto_tareas_json',
    'reunion_grabaciones', 'reunion_transcripcion', 'tarea_estado', 'tarea_crear',
    'intercambio_crear', 'intercambio_responder', 'intercambio_cancelar',
];

if (!in_array($accion, $accionesPublicas, true)) {
    if (in_array($accion, $accionesDeCualquiera, true)) {
        Auth::requiereLogin();
    } else {
        Auth::requiereAdmin();
    }
}

$proyectos = new ProyectoRepo();
$miembros  = new MiembroRepo();
$tareas    = new TareaRepo();

/**
 * Revisa si el proyecto acaba de completarse (100% y con tareas) y, si es
 * la primera vez, avisa al administrador. Si baja de 100%, reinicia el flag.
 */
function chequearEntrega(int $proyectoId, ProyectoRepo $proyectos, TareaRepo $tareas): void
{
    $p = $proyectos->buscar($proyectoId);
    if (!$p) return;
    $total = array_sum($tareas->resumen($proyectoId));
    $completo = $total > 0 && $tareas->avance($proyectoId) === 100;
    $yaAvisado = !empty($p['entrega_notificada']);

    if ($completo && !$yaAvisado) {
        $proyectos->actualizar($proyectoId, ['entrega_notificada' => 1]);
        Mailer::notificarProyectoCompleto($p, $total);
    } elseif (!$completo && $yaAvisado) {
        $proyectos->actualizar($proyectoId, ['entrega_notificada' => 0]);
    }
}

/**
 * Avisa por correo a quienes acaban de entrar al equipo del proyecto.
 * Solo a los nuevos: comparar antes/despues evita reenviar a los que ya
 * estaban cada vez que se guarda el proyecto.
 * Devuelve el sufijo para el mensaje flash.
 */
function avisarNuevosDelProyecto(array $antes, array $despues, array $proyecto, MiembroRepo $miembros): string
{
    $nuevos = array_diff($despues, $antes);
    if (!$nuevos || !Mailer::listo()) {
        return '';
    }
    $avisados = 0;
    foreach ($nuevos as $mid) {
        $m = $miembros->buscar((int)$mid);
        if ($m && Mailer::notificarEquipoProyecto($m, $proyecto) === true) {
            $avisados++;
        }
    }
    return $avisados ? ' ' . $avisados . ' persona(s) avisada(s) por correo.' : '';
}

/**
 * Sincroniza los eventos de Google Calendar de una tarea: crea/actualiza el
 * evento de cada responsable que tenga conectado su Google, y borra el de
 * quien ya no es responsable. Guarda el mapa idMiembro=>idEvento en la tarea.
 */
function sincronizarCalendario(array $tarea, ProyectoRepo $proyectos, MiembroRepo $miembros, TareaRepo $tareas): void
{
    if (!GoogleCalendar::listo()) return;
    $p = $proyectos->buscar((int)($tarea['proyecto_id'] ?? 0));
    if (!$p) return;

    $eventos   = is_array($tarea['gcal_eventos'] ?? null) ? $tarea['gcal_eventos'] : [];
    $asignados = TareaRepo::asignadosDe($tarea);
    $nuevos = [];

    foreach ($asignados as $mid) {
        $m = $miembros->buscar((int)$mid);
        if (!$m || empty($m['gcal_refresh'])) continue;
        $id = GoogleCalendar::upsert($m, $tarea, $p, (string)($eventos[$mid] ?? ''));
        if ($id) $nuevos[$mid] = $id;
    }
    // Eventos de quienes dejaron de ser responsables
    foreach ($eventos as $mid => $eid) {
        if (in_array((int)$mid, $asignados, true)) continue;
        $m = $miembros->buscar((int)$mid);
        if ($m) GoogleCalendar::borrar($m, (string)$eid);
    }
    $tareas->actualizar((int)$tarea['id'], ['gcal_eventos' => $nuevos]);
}

/**
 * Deja la reunion en el calendario propio de cada invitado y devuelve a cuantos
 * les llego. Es imprescindible en Zoom (Google no sabe que esa reunion existe);
 * en Meet solo se usa para quien no tiene correo, porque el resto ya recibe la
 * invitacion del evento y tenerla dos veces seria ruido.
 *
 * Guarda el mapa idMiembro => idEvento en la reunion para poder actualizar o
 * borrar despues, y limpia las copias de quien dejo de estar invitado.
 */
function agendarReunionEnCalendarios(array $reu, array $proyecto, MiembroRepo $miembros, ReunionRepo $reuniones): int
{
    $copias    = (array)($reu['gcal_copias'] ?? []);
    $invitados = array_map('intval', (array)($reu['invitados'] ?? []));
    $esMeet    = ($reu['plataforma'] ?? 'zoom') === 'meet';
    $vigentes  = [];
    $agendados = 0;

    if (Reuniones::agendaEnCalendarios()) {
        foreach ($invitados as $mid) {
            $m = $miembros->buscar($mid);
            if (!$m || empty($m['gcal_refresh'])) continue;         // sin su Google conectado
            if ($esMeet && !empty($m['email'])) continue;           // ya va como invitado del evento
            $ev = GoogleCalendar::agendarReunion($m, $reu, $proyecto, (string)($copias[$mid] ?? ''));
            if ($ev !== null) { $vigentes[$mid] = $ev; $agendados++; }
        }
    }
    // Copias que sobran: se desinvito a alguien, cambio la plataforma o se apago
    // la opcion en Ajustes.
    foreach ($copias as $mid => $ev) {
        if (isset($vigentes[(int)$mid])) continue;
        $m = $miembros->buscar((int)$mid);
        if ($m) GoogleCalendar::borrar($m, (string)$ev);
    }
    $reuniones->actualizar((int)$reu['id'], ['gcal_copias' => $vigentes]);
    return $agendados;
}

/** Quita del calendario de cada invitado las copias de una reunion borrada. */
function borrarCopiasReunion(array $reu, MiembroRepo $miembros): void
{
    foreach ((array)($reu['gcal_copias'] ?? []) as $mid => $ev) {
        $m = $miembros->buscar((int)$mid);
        if ($m) GoogleCalendar::borrar($m, (string)$ev);
    }
}

/**
 * Lee del formulario la repeticion semanal de una reunion y la valida.
 * Devuelve [recurrente, dias, hasta, inicio] con el inicio ya movido al primer
 * dia que cumple la regla. Redirige con un mensaje claro si algo no cuadra.
 */
function repeticionReunion(array $post, string $inicio, string $plataforma, string $volver): array
{
    if (empty($post['recurrente'])) return [false, [], '', $inicio];

    $dias  = Reuniones::diasValidos($post['dias'] ?? []);
    $hasta = trim((string)($post['hasta'] ?? ''));
    if (!$dias) {
        redirigir($volver, 'Marca al menos un día de la semana para repetir la reunión.', 'error');
    }
    if ($hasta === '') {
        redirigir($volver, 'Indica hasta qué fecha se repite la reunión.', 'error');
    }
    if ($hasta < substr($inicio, 0, 10)) {
        redirigir($volver, 'La fecha final de la repetición («' . $hasta . '») es anterior al primer día de la reunión.', 'error');
    }
    // Si el primer dia elegido no es de los marcados, la serie arrancaria fuera
    // de la regla: se adelanta al siguiente que si lo sea.
    $inicio = Reuniones::primerInicio($inicio, $dias);

    $veces = Reuniones::ocurrencias($inicio, $dias, $hasta);
    if ($plataforma === 'zoom' && $veces > 60) {
        redirigir($volver, 'Zoom admite como máximo 60 repeticiones por reunión y esta serie tendría ' . $veces
            . '. Acorta el rango de fechas o crea la reunión con Google Meet.', 'error');
    }
    return [true, $dias, $hasta, $inicio];
}

/**
 * Comprueba el par inicio/limite de una tarea. Devuelve [inicio, limite]
 * ya normalizados, o redirige con un error si el inicio queda despues.
 */
function fechasTarea(array $post, string $volver): array
{
    $inicio = ProyectoRepo::fecha($post['fecha_inicio'] ?? '');
    $limite = ProyectoRepo::fecha($post['fecha_limite'] ?? '');
    if ($inicio !== '' && $limite !== '' && $inicio > $limite) {
        redirigir($volver, 'La fecha de inicio (' . $inicio . ') no puede ser posterior a la fecha límite (' . $limite . ').', 'error');
    }
    return [$inicio, $limite];
}

/**
 * Avisa por correo a cada responsable NUEVO de la tarea (los que no estaban
 * antes). Devuelve [sufijo para el mensaje flash, tipo de toast].
 */
function notificarSiAsignada(array $tarea, array $asignadosNuevos, array $asignadosAntes, ProyectoRepo $proyectos, MiembroRepo $miembros): array
{
    $nuevos = array_diff($asignadosNuevos, $asignadosAntes);
    $p = $proyectos->buscar((int)($tarea['proyecto_id'] ?? 0));
    if (!$nuevos || !$p) {
        return ['', 'success'];
    }
    $avisados = [];
    foreach ($nuevos as $mid) {
        $m = $miembros->buscar((int)$mid);
        if ($m && Mailer::notificarAsignacion($tarea, $m, $p) === true) {
            $avisados[] = explode(' ', $m['nombre'])[0];
        }
    }
    return $avisados
        ? [' ' . implode(', ', $avisados) . ' ' . (count($avisados) === 1 ? 'fue notificado' : 'fueron notificados') . ' por correo.', 'success']
        : ['', 'success'];
}

switch ($accion) {

    /* ---------- Acceso ---------- */

    case 'auth_login':
        if (Auth::login($_POST['usuario'] ?? '', $_POST['clave'] ?? '')) {
            redirigir('index.php', '¡Bienvenido, ' . (Auth::usuario()['nombre'] ?? '') . '!');
        }
        // Si se registró y todavía no lo aprueban, decírselo: si no, parece
        // que su contraseña está mal y la vuelve a pedir una y otra vez.
        if ((new SolicitudRepo())->porLogin($_POST['usuario'] ?? '')) {
            redirigir('login.php', 'Tu solicitud de acceso sigue pendiente. Te avisaremos por correo en cuanto un administrador la apruebe.', 'info');
        }
        redirigir('login.php', 'Usuario o contraseña incorrectos.', 'error');

    case 'solicitud_aprobar':
        // Convierte la solicitud en colaborador de verdad. Entrará con la misma
        // cuenta de Google con la que se registró: no lleva contraseña.
        $solicitudes = new SolicitudRepo();
        $s = $solicitudes->buscar((int)($_POST['id'] ?? 0));
        $volver = volverAqui('equipo.php');
        if (!$s) {
            redirigir($volver, 'Esa solicitud ya no existe.', 'error');
        }
        // Entre la solicitud y la aprobación pudieron dar de alta a esa persona
        foreach ($miembros->todos() as $m) {
            if (strcasecmp($m['email'] ?? '', $s['email'] ?? '') === 0) {
                $solicitudes->eliminar((int)$s['id']);
                redirigir($volver, 'Ese correo ya es de ' . $m['nombre'] . '. Descarté la solicitud.', 'info');
            }
        }
        // El equipo y el rol los pone el administrador aquí, no quien se registró
        $equipoNuevoMiembro = MiembroRepo::equipoValido($_POST['equipo'] ?? '');
        $rolesValidos = (array)Config::get('roles');
        $rolNuevo = trim((string)($_POST['rol'] ?? ''));
        if (!in_array($rolNuevo, $rolesValidos, true)) {
            $rolNuevo = (string)($rolesValidos[0] ?? 'Developer');
        }
        // Sin usuario de Git: cada quien lo pone luego en Mi perfil
        $nuevo = $miembros->crear([
            'nombre'   => $s['nombre'],
            'rol'      => $rolNuevo,
            'email'    => $s['email'],
            'equipo'   => $equipoNuevoMiembro,
            // Color de la paleta, rotando para que no salgan todos iguales
            'color'    => count($miembros->todos()) % count(Catalogo::COLORES),
        ]);
        $miembros->actualizar((int)$nuevo['id'], [
            'acceso' => ($_POST['acceso'] ?? '') === 'admin' ? 'admin' : 'lector',
        ]);
        $solicitudes->eliminar((int)$s['id']);
        // Si el correo del panel no está configurado, la persona no se entera
        // de que ya puede entrar: hay que decírselo al admin, no callarlo.
        $envio = Mailer::solicitudAprobada($miembros->buscar((int)$nuevo['id']));
        $avisoAprob = $envio === true
            ? ' Le avisamos por correo.'
            : ' Avísale tú: no salió el correo (' . (is_string($envio) ? $envio : 'el correo del panel no está configurado') . ').';
        redirigir('equipo.php?e=' . $equipoNuevoMiembro,
            $nuevo['nombre'] . ' ya forma parte del equipo y puede entrar con su cuenta de Google.' . $avisoAprob,
            $envio === true ? 'success' : 'info');

    case 'solicitud_rechazar':
        $solicitudes = new SolicitudRepo();
        $s = $solicitudes->buscar((int)($_POST['id'] ?? 0));
        $volver = volverAqui('equipo.php');
        if (!$s) {
            redirigir($volver, 'Esa solicitud ya no existe.', 'error');
        }
        $motivoRech = trim((string)($_POST['motivo'] ?? ''));
        $avisoRech = Mailer::solicitudRechazada($s, $motivoRech) === true ? ' Le avisamos por correo.' : '';
        $solicitudes->eliminar((int)$s['id']);
        redirigir($volver, 'Rechazaste la solicitud de ' . ($s['nombre'] ?? '') . '.' . $avisoRech, 'info');

    case 'auth_identificar':
        // Confirma "¿quién eres?": vincula el correo de Google (ya verificado y
        // guardado en sesión) a la ficha que la persona eligió, y la deja dentro.
        $pend = $_SESSION['identificar'] ?? null;
        if (!$pend || empty($pend['email'])) {
            redirigir('login.php', 'La sesión de identificación expiró. Entra de nuevo con Google.', 'error');
        }
        $repo = new MiembroRepo();
        $elegido = $repo->buscar((int)($_POST['miembro'] ?? 0));
        // Solo fichas sin correo y que no sean admin (no se puede reclamar al admin).
        if (!$elegido || !empty($elegido['email']) || ($elegido['acceso'] ?? '') === 'admin') {
            redirigir('login.php', 'Esa ficha no está disponible para vincular.', 'error');
        }
        // Que ese correo no lo tenga ya otra persona.
        foreach ($repo->todos() as $m) {
            if (strcasecmp($m['email'] ?? '', $pend['email']) === 0) {
                unset($_SESSION['identificar']);
                redirigir('login.php', 'Ese correo ya está vinculado a otra ficha. Avisa al administrador.', 'error');
            }
        }
        $cambios = ['email' => $pend['email']];
        if (!empty($pend['refresh'])) $cambios['gcal_refresh'] = $pend['refresh'];
        $repo->actualizar((int)$elegido['id'], $cambios);
        unset($_SESSION['identificar']);
        Auth::iniciarSesion((int)$elegido['id']);
        redirigir('index.php', '¡Bienvenido, ' . explode(' ', $elegido['nombre'])[0] . '! Vinculé tu cuenta de Google (' . $pend['email'] . ') a tu ficha.');

    case 'auth_logout':
        Auth::salir();
        redirigir('login.php', 'Sesión cerrada.');

    /* ---------- Proyectos ---------- */

    case 'proyecto_crear':
        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir('index.php', 'El nombre del proyecto es obligatorio.', 'error');
        }
        $p = $proyectos->crear($_POST);
        $avisoEquipo = avisarNuevosDelProyecto([], (array)($p['miembros'] ?? []), $p, $miembros);
        redirigir('proyecto.php?id=' . $p['id'], 'Proyecto «' . $p['nombre'] . '» creado.' . $avisoEquipo);

    case 'proyecto_equipo':
        // Guarda solo la lista de participantes (desde el tablero)
        $id = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($id);
        if (!$p) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        $equipoAntes = (array)($p['miembros'] ?? []);
        $equipoNuevo = ProyectoRepo::miembrosEntrada($_POST['miembros'] ?? []);
        $proyectos->actualizar($id, ['miembros' => $equipoNuevo]);
        $avisoEquipo = avisarNuevosDelProyecto($equipoAntes, $equipoNuevo, $proyectos->buscar($id), $miembros);
        redirigir('proyecto.php?id=' . $id, ($equipoNuevo
            ? 'Equipo del proyecto actualizado: ' . count($equipoNuevo) . ' persona(s).'
            : 'El proyecto queda abierto a todo el equipo.') . $avisoEquipo);

    case 'proyecto_editar':
        $id = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($id);
        if (!$p) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        $equipoAntes = (array)($p['miembros'] ?? []);
        $proyectos->actualizar($id, [
            'nombre'        => trim($_POST['nombre'] ?? ''),
            'descripcion'   => trim($_POST['descripcion'] ?? ''),
            'repos'         => ProyectoRepo::reposEntrada($_POST['repos'] ?? []),
            // La lista nueva manda: se limpian los campos sueltos de antes
            // para no dejar dos fuentes de verdad.
            'repo'          => '',
            'repo_frontend' => '',
            'estado'        => $_POST['estado'] ?? 'activo',
            'icono'         => $_POST['icono'] ?? 'fa-rocket',
            'color'         => Catalogo::colorEntrada($_POST),
            'fecha_inicio'  => ProyectoRepo::fecha($_POST['fecha_inicio'] ?? ''),
            'miembros'      => ProyectoRepo::miembrosEntrada($_POST['miembros'] ?? []),
            'plataforma'    => ProyectoRepo::plataformaEntrada($_POST['plataforma'] ?? ''),
        ]);
        $pAhora = $proyectos->buscar($id);
        $avisoEquipo = avisarNuevosDelProyecto($equipoAntes, (array)($pAhora['miembros'] ?? []), $pAhora, $miembros);
        redirigir('proyecto.php?id=' . $id, 'Proyecto actualizado.' . $avisoEquipo);

    case 'proyecto_estado':
        $id = (int)($_POST['id'] ?? 0);
        $proyectos->actualizar($id, ['estado' => $_POST['estado'] ?? 'activo']);
        redirigir(volverAqui('index.php'), 'Estado del proyecto actualizado.');

    case 'proyecto_eliminar':
        $id = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($id);
        $proyectos->eliminar($id);
        redirigir('index.php', 'Proyecto «' . ($p['nombre'] ?? '') . '» eliminado junto con sus tareas.');

    /* ---------- Tareas ---------- */

    case 'tarea_crear':
        $pid = (int)($_POST['proyecto_id'] ?? 0);
        // Cualquier participante del proyecto puede registrar tareas; nadie
        // ajeno (aunque mande el id del proyecto a mano).
        if (!puedeVerProyecto($pid)) {
            redirigir('index.php', 'No participas en ese proyecto.', 'error');
        }
        if (trim($_POST['titulo'] ?? '') === '') {
            redirigir('proyecto.php?id=' . $pid, 'El título de la tarea es obligatorio.', 'error');
        }
        fechasTarea($_POST, 'proyecto.php?id=' . $pid);   // corta si el inicio va después del límite
        // Documentos de respaldo: van con la tarea desde que se asigna
        $rechazados = [];
        $datosTarea = $_POST;
        $datosTarea['adjuntos'] = guardarAdjuntos('adjuntos', 'tarea_', $rechazados);
        $t = $tareas->crear($datosTarea);
        $dep = $tareas->dependenciaValida((int)$t['id'], (int)($_POST['depende_de'] ?? 0), $pid);
        if ($dep !== (int)$t['depende_de']) {
            $tareas->actualizar((int)$t['id'], ['depende_de' => $dep]);
        }
        [$msg, $tipo] = notificarSiAsignada($t, TareaRepo::asignadosDe($t), [], $proyectos, $miembros);
        sincronizarCalendario($tareas->buscar((int)$t['id']), $proyectos, $miembros, $tareas);
        chequearEntrega($pid, $proyectos, $tareas);
        [$msg, $tipo] = avisoAdjuntos($rechazados, $msg, $tipo);
        redirigir('proyecto.php?id=' . $pid, 'Tarea creada.' . $msg, $tipo);

    case 'tarea_estado':
        // Si viene por AJAX (kanban/tabla), respondemos JSON y NO redirigimos:
        // así la página no se recarga y no hay "brinco".
        $ajaxEstado = !empty($_POST['ajax']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        $respEstado = function (bool $ok, string $msg = '', array $extra = []) use ($ajaxEstado) {
            if ($ajaxEstado) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $ok, 'error' => $ok ? '' : $msg] + $extra);
                exit;
            }
            redirigir($ok ? paginaOrigen() : 'index.php', $msg, $ok ? 'success' : 'error');
        };
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if (!$t) {
            $respEstado(false, 'Tarea no encontrada.');
        }
        // Cada quien puede mover SUS tareas por el tablero; los demás, solo admin.
        if (!Auth::esAdmin() && !TareaRepo::tieneAsignado($t, (int)(Auth::usuario()['id'] ?? 0))) {
            $respEstado(false, 'Solo puedes cambiar el estado de tus tareas.');
        }
        $tareas->actualizar((int)$t['id'], ['estado' => $_POST['estado'] ?? 'pendiente']);
        chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
        // Contadores por estado del proyecto, para que el kanban, los tiles de
        // resumen y la barra de avance se actualicen sin recargar. El avance se
        // calcula aqui porque descuenta las tareas con observaciones pendientes:
        // el navegador no puede deducirlo del conteo.
        $pidEstado = (int)$t['proyecto_id'];
        $conteo    = $tareas->resumen($pidEstado);
        $respEstado(true, 'Estado actualizado.', [
            'conteo'      => $conteo,
            'avance'      => $tareas->avance($pidEstado),
            'completadas' => $tareas->completadas($pidEstado),
            'total'       => array_sum($conteo),
        ]);

    case 'tarea_editar':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if (!$t) {
            redirigir('index.php', 'Tarea no encontrada.', 'error');
        }
        $asignadosAntes = TareaRepo::asignadosDe($t);
        [$fIni, $fLim] = fechasTarea($_POST, 'proyecto.php?id=' . $t['proyecto_id']);

        // Adjuntos: se quitan los marcados (y se borran del disco), se suman
        // los nuevos y el resto se queda como estaba.
        $previos = TareaRepo::adjuntosDe($t);
        $quitar  = array_map('strval', (array)($_POST['quitar_adjunto'] ?? []));
        $fuera   = array_values(array_filter($previos, fn($a) => in_array((string)($a['ruta'] ?? ''), $quitar, true)));
        $quedan  = array_values(array_filter($previos, fn($a) => !in_array((string)($a['ruta'] ?? ''), $quitar, true)));
        $rechazados = [];
        $nuevos  = guardarAdjuntos('adjuntos', 'tarea_', $rechazados);
        borrarAdjuntos($fuera);

        $tareas->actualizar((int)$t['id'], [
            'adjuntos'     => array_merge($quedan, $nuevos),
            'titulo'       => trim($_POST['titulo'] ?? ''),
            'descripcion'  => trim($_POST['descripcion'] ?? ''),
            'prioridad'    => $_POST['prioridad'] ?? 'media',
            'estado'       => $_POST['estado'] ?? 'pendiente',
            'fecha_inicio' => $fIni,
            'fecha_limite' => $fLim,
            'depende_de'   => $tareas->dependenciaValida((int)$t['id'], (int)($_POST['depende_de'] ?? 0), (int)$t['proyecto_id']),
        ] + TareaRepo::camposAsignado($_POST));
        $tActual = $tareas->buscar((int)$t['id']);
        [$msg, $tipo] = notificarSiAsignada($tActual, TareaRepo::asignadosDe($tActual), $asignadosAntes, $proyectos, $miembros);
        sincronizarCalendario($tActual, $proyectos, $miembros, $tareas);
        chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
        [$msg, $tipo] = avisoAdjuntos($rechazados, $msg, $tipo);
        redirigir(volverAqui('proyecto.php?id=' . $t['proyecto_id']), 'Tarea actualizada.' . $msg, $tipo);

    case 'tareas_avisar':
        // Un correo POR PERSONA con todas sus tareas del proyecto, no uno por
        // tarea: tras cargar una planificación en lote, avisar tarea a tarea
        // sería una lluvia de correos por la misma noticia.
        $pid = (int)($_POST['proyecto_id'] ?? 0);
        $p = $proyectos->buscar($pid);
        $volver = 'proyecto.php?id=' . $pid;
        if (!$p) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        if (!Mailer::listo()) {
            redirigir($volver, 'El correo no está configurado. Revisa Ajustes → Correo.', 'error');
        }

        // Lo que eligió el administrador en el modal: [miembro => [tarea, …]].
        // Se vuelve a validar aquí: cada tarea debe ser de este proyecto y
        // estar realmente a nombre de esa persona.
        $seleccion = (array)($_POST['avisar'] ?? []);
        $porPersona = [];
        foreach ($seleccion as $mid => $ids) {
            $mid = (int)$mid;
            foreach ((array)$ids as $tid) {
                $t = $tareas->buscar((int)$tid);
                if (!$t || (int)$t['proyecto_id'] !== $pid) continue;
                if (!TareaRepo::tieneAsignado($t, $mid)) continue;
                $porPersona[$mid][] = $t;
            }
        }
        if (!$porPersona) {
            redirigir($volver, 'No marcaste ninguna tarea, así que no se envió nada.', 'error');
        }
        $nota = mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 400);

        $enviados = [];
        $sinCorreo = [];
        $fallos = [];
        foreach ($porPersona as $mid => $suyas) {
            $m = $miembros->buscar((int)$mid);
            if (!$m) continue;
            if (empty($m['email'])) {
                $sinCorreo[] = explode(' ', $m['nombre'])[0];
                continue;
            }
            // Las más urgentes primero; las que no tienen fecha, al final
            usort($suyas, fn($a, $b) => (($a['fecha_limite'] ?? '') ?: '9999') <=> (($b['fecha_limite'] ?? '') ?: '9999'));
            $r = Mailer::resumenTareas($m, $p, $suyas, $nota);
            if ($r === true) {
                $enviados[] = explode(' ', $m['nombre'])[0] . ' (' . count($suyas) . ')';
            } else {
                $fallos[] = explode(' ', $m['nombre'])[0];
            }
        }

        $msg = $enviados
            ? 'Resumen enviado a ' . count($enviados) . ' persona(s): ' . implode(', ', $enviados) . '.'
            : 'No se envió ningún correo.';
        if ($sinCorreo) $msg .= ' Sin correo registrado: ' . implode(', ', $sinCorreo) . '.';
        if ($fallos)    $msg .= ' Falló el envío a: ' . implode(', ', $fallos) . '.';
        redirigir($volver, $msg, $enviados && !$fallos ? 'success' : 'error');

    case 'tarea_eliminar':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if ($t) {
            // Borra los eventos de Google Calendar de la tarea, si los tenía
            if (GoogleCalendar::listo() && is_array($t['gcal_eventos'] ?? null)) {
                foreach ($t['gcal_eventos'] as $mid => $eid) {
                    $m = $miembros->buscar((int)$mid);
                    if ($m) GoogleCalendar::borrar($m, (string)$eid);
                }
            }
            borrarAdjuntos(TareaRepo::adjuntosDe($t));   // no dejar archivos huérfanos
            $tareas->eliminar((int)$t['id']);
            chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
            // Vuelve a la vista tal como estaba (con sus filtros), para poder
            // seguir borrando sin tener que volver a filtrar cada vez.
            redirigir(volverAqui('proyecto.php?id=' . $t['proyecto_id']), 'Tarea eliminada.');
        }
        redirigir('index.php', 'Tarea no encontrada.', 'error');

    case 'sincronizar_calendario':
        // Empuja al Google Calendar de cada responsable TODAS las tareas del
        // proyecto (útil para las que ya existían antes de conectar Google).
        $id = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($id);
        if (!$p) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        if (!GoogleCalendar::listo()) {
            redirigir('proyecto.php?id=' . $id, 'Google Calendar no está configurado en Ajustes.', 'error');
        }
        $lista = $tareas->delProyecto($id);
        $conFecha = 0;              // tareas con fecha (candidatas a evento)
        $creados = 0;               // eventos realmente creados/actualizados en Google
        $sinConectar = [];          // responsables sin Google conectado
        $errores = [];              // otros motivos de fallo (dedup por texto)
        $sinResponsable = 0;        // tareas con fecha pero sin nadie asignado
        foreach ($lista as $t) {
            if (empty($t['fecha_inicio']) && empty($t['fecha_limite'])) continue;
            $conFecha++;
            $tarea = $tareas->buscar((int)$t['id']);
            $eventos = is_array($tarea['gcal_eventos'] ?? null) ? $tarea['gcal_eventos'] : [];
            $asignados = TareaRepo::asignadosDe($tarea);
            if (!$asignados) { $sinResponsable++; continue; }
            $nuevos = [];
            foreach ($asignados as $mid) {
                $m = $miembros->buscar((int)$mid);
                if (!$m) continue;
                $eid = GoogleCalendar::upsert($m, $tarea, $p, (string)($eventos[$mid] ?? ''));
                if ($eid) {
                    $nuevos[$mid] = $eid;
                    $creados++;
                } elseif (GoogleCalendar::$ultimoError === 'sin_conexion') {
                    $sinConectar[explode(' ', $m['nombre'])[0]] = true;
                } elseif (GoogleCalendar::$ultimoError) {
                    $errores[GoogleCalendar::$ultimoError] = true;
                }
            }
            // Conservar el id de eventos que no se pudieron re-crear; borrar los de quien ya no está
            foreach ($eventos as $mid => $eid) {
                if (isset($nuevos[$mid])) continue;
                if (in_array((int)$mid, $asignados, true)) { $nuevos[$mid] = $eid; continue; }
                $m = $miembros->buscar((int)$mid);
                if ($m) GoogleCalendar::borrar($m, (string)$eid);
            }
            $tareas->actualizar((int)$tarea['id'], ['gcal_eventos' => $nuevos]);
        }

        // Mensaje honesto: se cuenta lo que de verdad llegó a Google
        if ($creados > 0) {
            $aviso = 'Se enviaron ' . $creados . ' evento(s) al Google Calendar de los responsables.';
            $tipo = 'success';
        } elseif ($conFecha === 0) {
            $aviso = 'No hay tareas con fecha para sincronizar.';
            $tipo = 'info';
        } else {
            $aviso = 'No se creó ningún evento.';
            $tipo = 'error';
        }
        if ($sinConectar) {
            $aviso .= ' Falta que conecten su calendario en Mi perfil → "Conectar mi calendario": '
                . implode(', ', array_keys($sinConectar)) . '.';
        }
        if ($sinResponsable) {
            $aviso .= ' ' . $sinResponsable . ' tarea(s) con fecha no tienen responsable.';
        }
        if ($errores) {
            $aviso .= ' Detalle: ' . implode(' · ', array_keys($errores));
        }
        redirigir('proyecto.php?id=' . $id, $aviso, $tipo);

    /* ---------- Observaciones (revisión / QA) ---------- */

    case 'obs_crear':
        $obsRepo = new ObservacionRepo();
        $pid = (int)($_POST['proyecto_id'] ?? 0);
        $esAjax = !empty($_POST['ajax']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        $volver = 'proyecto.php?id=' . $pid . '#vista-observaciones';
        $fallar = function (string $msg) use ($esAjax, $volver) {
            if ($esAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); exit; }
            redirigir($volver, $msg, 'error');
        };

        if (!$proyectos->buscar($pid)) $fallar('Proyecto no encontrado.');
        // Solo se anota en proyectos propios (un lector no puede escribir
        // en un tablero ajeno mandando el id a mano).
        if (!puedeVerProyecto($pid)) $fallar('No participas en ese proyecto.');
        $adjuntos = guardarAdjuntos('adjuntos');
        if (trim($_POST['texto'] ?? '') === '' && empty($adjuntos)) {
            $fallar('Escribe la observación o adjunta un archivo.');
        }
        $autor  = $miembros->buscar((int)($_POST['autor_id'] ?? 0));
        $equipo = $autor ? MiembroRepo::equipoDe($autor) : '';

        // Tareas destino (n a la vez): solo las del proyecto; ninguna = general
        $destinos = array_values(array_filter(
            array_map('intval', (array)($_POST['tarea_id'] ?? [])),
            function ($tid) use ($tareas, $pid) {
                $t = $tareas->buscar($tid);
                return $t && (int)$t['proyecto_id'] === $pid;
            }
        ));
        if (empty($destinos)) $destinos = [0];   // general

        $creadas = [];
        foreach ($destinos as $tid) {
            $creadas[] = $obsRepo->crear([
                'proyecto_id' => $pid,
                'tarea_id'    => $tid,
                'reunion_id'  => (int)($_POST['reunion_id'] ?? 0),
                'autor_id'    => (int)($_POST['autor_id'] ?? 0),
                'equipo'      => $equipo,
                'texto'       => $_POST['texto'] ?? '',
                'adjuntos'    => $adjuntos,
            ]);
        }

        if ($esAjax) {
            require_once __DIR__ . '/lib/obs_item.php';
            $res = $obsRepo->resumen($pid);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'         => true,
                'items'      => array_map('obsItemHtml', $creadas),
                'total'      => $res['total'],
                'pendientes' => $res['pendientes'],
            ]);
            exit;
        }
        redirigir($volver, count($creadas) > 1 ? count($creadas) . ' observaciones registradas.' : 'Observación registrada.');

    case 'obs_estado':
        $obsRepo = new ObservacionRepo();
        $o = $obsRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$o) {
            redirigir('index.php', 'Observación no encontrada.', 'error');
        }
        $nuevo = ($o['estado'] ?? 'pendiente') === 'pendiente' ? 'resuelta' : 'pendiente';
        $obsRepo->actualizar((int)$o['id'], [
            'estado'      => $nuevo,
            'resuelto_en' => $nuevo === 'resuelta' ? date('Y-m-d H:i') : '',
        ]);
        chequearEntrega((int)$o['proyecto_id'], $proyectos, $tareas);
        redirigir('proyecto.php?id=' . $o['proyecto_id'] . '#vista-observaciones',
                  $nuevo === 'resuelta' ? 'Observación marcada como resuelta.' : 'Observación reabierta.');

    case 'obs_eliminar':
        $obsRepo = new ObservacionRepo();
        $o = $obsRepo->buscar((int)($_POST['id'] ?? 0));
        if ($o) {
            $obsRepo->eliminar((int)$o['id']);
            redirigir('proyecto.php?id=' . $o['proyecto_id'] . '#vista-observaciones', 'Observación eliminada.');
        }
        redirigir('index.php', 'Observación no encontrada.', 'error');

    /* ---------- Intercambio de tareas ---------- */

    case 'intercambio_crear':
        $inter = new IntercambioRepo();
        $yo    = Auth::usuario();
        $miId  = (int)($yo['id'] ?? 0);
        $pid   = (int)($_POST['proyecto_id'] ?? 0);
        $volver = 'proyecto.php?id=' . $pid . '#vista-intercambios';

        if (!$proyectos->buscar($pid) || !puedeVerProyecto($pid)) {
            redirigir('index.php', 'Ese proyecto no es tuyo.', 'error');
        }
        $tMia  = $tareas->buscar((int)($_POST['tarea_de'] ?? 0));
        $tSuya = $tareas->buscar((int)($_POST['tarea_para'] ?? 0));
        if (!$tMia || !$tSuya) {
            redirigir($volver, 'Elige las dos tareas del intercambio.', 'error');
        }
        if ((int)$tMia['proyecto_id'] !== $pid || (int)$tSuya['proyecto_id'] !== $pid) {
            redirigir($volver, 'Las dos tareas tienen que ser de este proyecto.', 'error');
        }
        // Solo se ofrece lo propio: un admin puede mover tareas sin pedir permiso
        $mios = TareaRepo::asignadosDe($tMia);
        if (!esAdmin() && !in_array($miId, $mios, true)) {
            redirigir($volver, 'Solo puedes ofrecer una tarea que sea tuya.', 'error');
        }
        // Quién sale de tu tarea (tú; o el primer responsable si un admin la mueve)
        $deId = in_array($miId, $mios, true) ? $miId : ($mios[0] ?? 0);
        if ($deId === 0) {
            redirigir($volver, 'Esa tarea no tiene un responsable que ofrecer.', 'error');
        }
        // Quién recibe: el (primer) responsable de la otra tarea
        $paraId = TareaRepo::asignadosDe($tSuya)[0] ?? 0;
        if ($paraId === 0) {
            redirigir($volver, 'Esa tarea no tiene responsable: no hay con quién intercambiar.', 'error');
        }
        if ($paraId === $deId) {
            redirigir($volver, 'Las dos tareas ya son de la misma persona.', 'error');
        }
        if ($inter->tareaComprometida((int)$tMia['id'], (int)$tSuya['id'])) {
            redirigir($volver, 'Una de esas tareas ya está en una propuesta pendiente. Resuélvela primero.', 'error');
        }
        if (!isset(Catalogo::MOTIVOS_INTERCAMBIO[$_POST['motivo'] ?? ''])) {
            redirigir($volver, 'Elige el motivo del intercambio.', 'error');
        }

        $nuevo = $inter->crear([
            'proyecto_id' => $pid,
            'de_id'       => $deId,
            'para_id'     => $paraId,
            'tarea_de'    => (int)$tMia['id'],
            'tarea_para'  => (int)$tSuya['id'],
            'motivo'      => $_POST['motivo'],
            'nota'        => $_POST['nota'] ?? '',
        ]);

        $mDe   = $miembros->buscar((int)$nuevo['de_id']);
        $mPara = $miembros->buscar($paraId);
        $aviso = '';
        if ($mDe && $mPara) {
            $r = Mailer::notificarIntercambio($nuevo, $mDe, $mPara, $tMia, $tSuya, $proyectos->buscar($pid));
            if ($r === true)          $aviso = ' ' . $mPara['nombre'] . ' fue avisado por correo.';
            elseif (is_string($r))    $aviso = ' Pero el correo falló: ' . $r;
        }
        redirigir($volver, 'Propuesta enviada a ' . ($mPara['nombre'] ?? '') . '.' . $aviso);

    case 'intercambio_responder':
        $inter = new IntercambioRepo();
        $yo    = Auth::usuario();
        $miId  = (int)($yo['id'] ?? 0);
        $x     = $inter->buscar((int)($_POST['id'] ?? 0));
        if (!$x) {
            redirigir('index.php', 'Esa propuesta no existe.', 'error');
        }
        $volver = 'proyecto.php?id=' . $x['proyecto_id'] . '#vista-intercambios';
        if (($x['estado'] ?? '') !== 'pendiente') {
            redirigir($volver, 'Esa propuesta ya estaba resuelta.', 'error');
        }
        // Responde a quien va dirigida (o un administrador)
        if (!esAdmin() && (int)$x['para_id'] !== $miId) {
            redirigir($volver, 'Esa propuesta no va dirigida a ti.', 'error');
        }
        $acepta = ($_POST['respuesta'] ?? '') === 'aceptar';

        if ($acepta) {
            // Cruzar responsables. Se releen por si algo cambio entretanto.
            $tA = $tareas->buscar((int)$x['tarea_de']);
            $tB = $tareas->buscar((int)$x['tarea_para']);
            if (!$tA || !$tB) {
                $inter->actualizar((int)$x['id'], ['estado' => 'cancelado', 'resuelto_en' => date('Y-m-d H:i'),
                                                   'respuesta' => 'Una de las tareas ya no existe.']);
                redirigir($volver, 'Una de las tareas ya no existe: la propuesta se canceló.', 'error');
            }
            // Cruzar responsables sin pisar a los demás co-responsables
            $tareas->reemplazarAsignado((int)$tA['id'], (int)$x['de_id'], (int)$x['para_id']);
            $tareas->reemplazarAsignado((int)$tB['id'], (int)$x['para_id'], (int)$x['de_id']);
        }

        $inter->actualizar((int)$x['id'], [
            'estado'      => $acepta ? 'aceptado' : 'rechazado',
            'respuesta'   => trim($_POST['nota'] ?? ''),
            'resuelto_en' => date('Y-m-d H:i'),
        ]);

        $quien = $miembros->buscar($miId);
        $dest  = $miembros->buscar((int)$x['de_id']);
        if ($quien && $dest) {
            Mailer::notificarRespuestaIntercambio(
                $inter->buscar((int)$x['id']), $quien, $dest, $proyectos->buscar((int)$x['proyecto_id']), $acepta);
        }
        redirigir($volver, $acepta
            ? 'Intercambio aceptado: las tareas ya cambiaron de responsable.'
            : 'Propuesta rechazada. No se cambió nada.');

    case 'intercambio_cancelar':
        $inter = new IntercambioRepo();
        $miId  = (int)(Auth::usuario()['id'] ?? 0);
        $x     = $inter->buscar((int)($_POST['id'] ?? 0));
        if (!$x) {
            redirigir('index.php', 'Esa propuesta no existe.', 'error');
        }
        $volver = 'proyecto.php?id=' . $x['proyecto_id'] . '#vista-intercambios';
        if (!esAdmin() && (int)$x['de_id'] !== $miId) {
            redirigir($volver, 'Solo quien propuso el intercambio puede retirarlo.', 'error');
        }
        if (($x['estado'] ?? '') !== 'pendiente') {
            redirigir($volver, 'Esa propuesta ya estaba resuelta.', 'error');
        }
        $inter->actualizar((int)$x['id'], ['estado' => 'cancelado', 'resuelto_en' => date('Y-m-d H:i')]);
        redirigir($volver, 'Propuesta retirada.');

    /* ---------- Mi perfil (cada quien edita lo suyo) ---------- */

    case 'mis_tareas_json':
        // Exporta MIS tareas como JSON, para pasárselo a Claude junto con el
        // estándar (cada commit referencia la tarea con su #id).
        $yo = Auth::usuario();
        if (!$yo) {
            redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
        }
        $miId2   = (int)$yo['id'];
        $estCat  = Catalogo::estadosTarea();
        $priCat  = Catalogo::prioridades();
        $proyMap = [];
        foreach ($proyectos->todos() as $p) { $proyMap[(int)$p['id']] = $p['nombre']; }
        $todasT  = $tareas->todas();
        $porId   = [];
        foreach ($todasT as $t) { $porId[(int)$t['id']] = $t; }

        $mias = [];
        foreach ($todasT as $t) {
            if (!TareaRepo::tieneAsignado($t, $miId2)) continue;
            $depId = (int)($t['depende_de'] ?? 0);
            $mias[] = [
                'id'           => (int)$t['id'],
                'ref'          => '#' . (int)$t['id'],
                'proyecto'     => $proyMap[(int)$t['proyecto_id']] ?? '',
                'titulo'       => $t['titulo'] ?? '',
                'descripcion'  => $t['descripcion'] ?? '',
                'estado'       => $estCat[$t['estado'] ?? ''][0] ?? ($t['estado'] ?? ''),
                'prioridad'    => $priCat[$t['prioridad'] ?? ''][0] ?? ($t['prioridad'] ?? ''),
                'fecha_inicio' => $t['fecha_inicio'] ?? '',
                'fecha_limite' => $t['fecha_limite'] ?? '',
                'depende_de'   => $depId && isset($porId[$depId]) ? ('#' . $depId . ' · ' . $porId[$depId]['titulo']) : '',
            ];
        }
        // Ordenar: primero las abiertas, por fecha límite
        $finalesX = Catalogo::estadosFinales();
        usort($mias, function ($a, $b) use ($finalesX, $estCat) {
            return [$a['fecha_limite'] ?: '9999', $a['id']] <=> [$b['fecha_limite'] ?: '9999', $b['id']];
        });

        $salida = json_encode([
            'persona'   => $yo['nombre'],
            'total'     => count($mias),
            'nota'      => 'Mis tareas en MChub. Cada commit referencia su tarea con el #id (ver estándar del equipo).',
            'tareas'    => $mias,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(MiembroRepo::iniciales($yo) . '-' . $yo['nombre']));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-tareas-' . trim($slug, '-') . '.json"');
        header('Cache-Control: no-store');
        echo $salida;
        exit;

    case 'proyecto_tareas_json':
        // Exporta TODAS las tareas de un proyecto como JSON (con su #id), para
        // que cualquier participante se lo pase a su Claude con contexto.
        $pid = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($pid);
        if (!$p) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        if (!puedeVerProyecto($pid)) {
            redirigir('index.php', 'No participas en ese proyecto.', 'error');
        }
        $estCat  = Catalogo::estadosTarea();
        $priCat  = Catalogo::prioridades();
        $memNom  = [];
        foreach ($miembros->todos() as $m) { $memNom[(int)$m['id']] = $m['nombre']; }
        $lista   = $tareas->delProyecto($pid);
        $porId   = [];
        foreach ($lista as $t) { $porId[(int)$t['id']] = $t; }

        $out = [];
        foreach ($lista as $t) {
            $resp = [];
            foreach (TareaRepo::asignadosDe($t) as $mid) {
                if (isset($memNom[$mid])) $resp[] = $memNom[$mid];
            }
            $depId = (int)($t['depende_de'] ?? 0);
            $out[] = [
                'id'           => (int)$t['id'],
                'ref'          => '#' . (int)$t['id'],
                'proyecto'     => $p['nombre'],
                'titulo'       => $t['titulo'] ?? '',
                'descripcion'  => $t['descripcion'] ?? '',
                'estado'       => $estCat[$t['estado'] ?? ''][0] ?? ($t['estado'] ?? ''),
                'prioridad'    => $priCat[$t['prioridad'] ?? ''][0] ?? ($t['prioridad'] ?? ''),
                'fecha_inicio' => $t['fecha_inicio'] ?? '',
                'fecha_limite' => $t['fecha_limite'] ?? '',
                'responsables' => $resp,
                'depende_de'   => $depId && isset($porId[$depId]) ? ('#' . $depId . ' · ' . ($porId[$depId]['titulo'] ?? '')) : '',
            ];
        }
        $salida = json_encode([
            'proyecto' => $p['nombre'],
            'total'    => count($out),
            'nota'     => 'Tareas del proyecto «' . $p['nombre'] . '» en MChub. Cada commit referencia su tarea con el #id: <tipo>(<área>): <descripción en presente> #<id> (ver estándar del equipo).',
            'tareas'   => $out,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($p['nombre']));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="tareas-' . trim($slug, '-') . '.json"');
        header('Cache-Control: no-store');
        echo $salida;
        exit;

    case 'perfil_guardar':
        // El id NUNCA sale del POST: siempre es el de la sesion. Asi nadie
        // edita la ficha de otro mandando otro id, ni se sube el rol solo.
        $yo = Auth::usuario();
        if (!$yo) {
            redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
        }
        $miId = (int)$yo['id'];
        $volver = 'perfil.php';

        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir($volver, 'El nombre no puede quedar vacío.', 'error');
        }

        // Correo y usuario de Git sirven para entrar: no pueden repetirse
        $correoNuevo = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
        $gitNuevo    = ltrim(trim($_POST['git_user'] ?? ''), '@');

        // Sin ninguno de los dos no habria con que iniciar sesion nunca mas:
        // el usuario se dejaria fuera del panel el mismo.
        if ($correoNuevo === '' && $gitNuevo === '') {
            redirigir($volver, 'Deja al menos el correo o el usuario de Git: son las dos formas de entrar al panel.', 'error');
        }

        foreach ($miembros->todos() as $otro) {
            if ((int)$otro['id'] === $miId) continue;
            if ($correoNuevo !== '' && strcasecmp($otro['email'] ?? '', $correoNuevo) === 0) {
                redirigir($volver, 'Ese correo ya lo usa otra persona del equipo.', 'error');
            }
            if ($gitNuevo !== '' && strcasecmp($otro['git_user'] ?? '', $gitNuevo) === 0) {
                redirigir($volver, 'Ese usuario de Git ya lo usa otra persona del equipo.', 'error');
            }
        }

        $cambios = [
            'nombre'   => trim($_POST['nombre']),
            'rol'      => trim($_POST['rol'] ?? ''),
            'git_user' => $gitNuevo,
            'email'    => $correoNuevo,
            'color'    => Catalogo::colorEntrada($_POST),
        ];

        // Contrasena: solo si la piden, y comprobando siempre la actual
        $claveNueva = (string)($_POST['clave_nueva'] ?? '');
        if ($claveNueva !== '') {
            if (strlen($claveNueva) < 6) {
                redirigir($volver, 'La contraseña nueva debe tener al menos 6 caracteres.', 'error');
            }
            if ($claveNueva !== (string)($_POST['clave_repetir'] ?? '')) {
                redirigir($volver, 'Las contraseñas nuevas no coinciden.', 'error');
            }
            $actual = (string)($_POST['clave_actual'] ?? '');
            if (!empty($yo['pass_hash'])) {
                if (!password_verify($actual, $yo['pass_hash'])) {
                    redirigir($volver, 'La contraseña actual no es correcta.', 'error');
                }
            }
            $cambios['pass_hash'] = Auth::hash($claveNueva);
        }

        $foto = guardarFoto('foto');
        if ($foto !== '') {
            if (!empty($yo['foto']) && file_exists(__DIR__ . '/' . $yo['foto'])) {
                @unlink(__DIR__ . '/' . $yo['foto']);
            }
            $cambios['foto'] = $foto;
        }

        $miembros->actualizar($miId, $cambios);
        redirigir($volver, isset($cambios['pass_hash'])
            ? 'Perfil actualizado y contraseña cambiada.'
            : 'Perfil actualizado.');

    /* ---------- Miembros ---------- */

    case 'miembro_crear':
        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir('equipo.php', 'El nombre del colaborador es obligatorio.', 'error');
        }
        $datos = $_POST;
        $datos['foto'] = guardarFoto('foto');
        $m = $miembros->crear($datos);
        // Acceso al panel (rol + contraseña opcional)
        $accesoNuevo = ($_POST['acceso'] ?? '') === 'admin' ? 'admin' : 'lector';
        $cambiosAcceso = ['acceso' => $accesoNuevo];
        if (strlen((string)($_POST['clave'] ?? '')) >= 6) {
            $cambiosAcceso['pass_hash'] = Auth::hash($_POST['clave']);
        }
        $miembros->actualizar((int)$m['id'], $cambiosAcceso);
        redirigir('equipo.php?e=' . $m['equipo'], '¡' . $m['nombre'] . ' se unió al equipo!');

    case 'miembro_editar':
        $id = (int)($_POST['id'] ?? 0);
        $m = $miembros->buscar($id);
        if (!$m) {
            redirigir('equipo.php', 'Colaborador no encontrado.', 'error');
        }
        $cambios = [
            'nombre'   => trim($_POST['nombre'] ?? ''),
            'rol'      => trim($_POST['rol'] ?? ''),
            'git_user' => ltrim(trim($_POST['git_user'] ?? ''), '@'),
            'email'    => filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
            'color'    => Catalogo::colorEntrada($_POST),
            'equipo'   => MiembroRepo::equipoValido($_POST['equipo'] ?? ''),
            'acceso'   => ($_POST['acceso'] ?? '') === 'admin' ? 'admin' : 'lector',
        ];
        // Contraseña: solo se cambia si escribieron una nueva
        if (strlen((string)($_POST['clave'] ?? '')) >= 6) {
            $cambios['pass_hash'] = Auth::hash($_POST['clave']);
        }
        // No permitir quedarse sin ningún administrador
        if ($cambios['acceso'] !== 'admin' && ($m['acceso'] ?? '') === 'admin') {
            $otrosAdmins = array_filter($miembros->todos(), fn($x) =>
                (int)$x['id'] !== $id && ($x['acceso'] ?? '') === 'admin' && !empty($x['pass_hash']));
            if (!$otrosAdmins) {
                redirigir('equipo.php', 'No puedes quitar el último administrador del panel.', 'error');
            }
        }
        $foto = guardarFoto('foto');
        if ($foto !== '') {
            if (!empty($m['foto']) && file_exists(__DIR__ . '/' . $m['foto'])) {
                @unlink(__DIR__ . '/' . $m['foto']);
            }
            $cambios['foto'] = $foto;
        }
        $miembros->actualizar($id, $cambios);
        redirigir('equipo.php?e=' . $cambios['equipo'], 'Colaborador actualizado.');

    case 'miembro_acceso_set':
        // Select de acceso en la tabla de equipo (admin / solo lectura)
        $m = $miembros->buscar((int)($_POST['id'] ?? 0));
        $volver = volverAqui('equipo.php');
        if (!$m) {
            redirigir($volver, 'Colaborador no encontrado.', 'error');
        }
        $nuevo = ($_POST['acceso'] ?? '') === 'admin' ? 'admin' : 'lector';
        $eraAdmin = ($m['acceso'] ?? 'lector') === 'admin';
        if ($eraAdmin === ($nuevo === 'admin')) {
            redirigir($volver);   // sin cambios
        }
        if ($eraAdmin && $nuevo === 'lector') {
            // Nunca dejar el panel sin ningun administrador, ni quitarse uno mismo
            $otros = array_filter($miembros->todos(), fn($x) =>
                (int)$x['id'] !== (int)$m['id'] && ($x['acceso'] ?? '') === 'admin');
            if (!$otros) {
                redirigir($volver, 'No puedes quitar al único administrador del panel.', 'error');
            }
            if ((int)$m['id'] === (int)(Auth::usuario()['id'] ?? 0)) {
                redirigir($volver, 'No puedes quitarte a ti mismo el acceso de administrador.', 'error');
            }
        }
        $miembros->actualizar((int)$m['id'], ['acceso' => $nuevo]);
        if ($nuevo === 'lector') {
            redirigir($volver, $m['nombre'] . ' vuelve a solo lectura.');
        }
        $falta = empty($m['pass_hash'])
            ? ' Todavía no tiene contraseña: pónsela al editar su ficha o que entre con Google.'
            : '';
        redirigir($volver, $m['nombre'] . ' ahora es administrador.' . $falta, $falta ? 'info' : 'success');

    case 'miembro_eliminar':
        $id = (int)($_POST['id'] ?? 0);
        $m = $miembros->buscar($id);
        $miembros->eliminar($id);
        redirigir('equipo.php', ($m['nombre'] ?? 'Colaborador') . ' fue retirado del equipo.');

    /* ---------- Ajustes (parametrizacion) ---------- */

    case 'config_guardar':
        $def = Config::defaults();
        $prev = Config::all();
        // Los secretos no se imprimen en el HTML: si el campo llega vacio,
        // se conserva el que ya estaba guardado.
        $secreto = function (?string $nuevo, $anterior): string {
            $nuevo = trim((string)$nuevo);
            return $nuevo !== '' ? $nuevo : (string)($anterior ?? '');
        };
        $hex = fn(string $v, string $fallback) =>
            preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $fallback;
        $fa = fn(string $v, string $fallback) =>
            preg_match('/^fa-[a-z0-9-]+$/', trim($v)) ? trim($v) : $fallback;

        // Clave interna a partir de la etiqueta (para entradas nuevas)
        $slug = function (string $label): string {
            $s = strtolower(trim($label));
            $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
            $s = preg_replace('/[^a-z0-9]+/', '-', $s);
            return trim($s, '-') ?: uniqid('item');
        };

        /** Lee un catalogo de filas del POST: [['key','icono','label','color'?,'final'?], ...] */
        $leerCatalogo = function (string $campo, string $iconoDef, bool $conColor, bool $conFinal) use ($hex, $fa, $slug): array {
            $out = [];
            foreach ((array)($_POST[$campo] ?? []) as $fila) {
                if (!is_array($fila)) continue;
                $label = trim($fila['label'] ?? '');
                if ($label === '') continue;
                $key = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($fila['key'] ?? '')));
                if ($key === '') $key = $slug($label);
                while (isset($out[$key])) $key .= '-2';
                $item = ['label' => $label, 'icono' => $fa($fila['icono'] ?? '', $iconoDef)];
                if ($conColor) $item['color'] = $hex($fila['color'] ?? '', '#2B76F7');
                if ($conFinal) $item['final'] = !empty($fila['final']);
                $out[$key] = $item;
            }
            return $out;
        };

        $estados = $leerCatalogo('et', 'fa-circle-dot', true, true) ?: $def['estados_tarea'];
        // Siempre debe existir al menos un estado "final" para calcular el avance
        if (!array_filter($estados, fn($v) => !empty($v['final']))) {
            $estados[array_key_last($estados)]['final'] = true;
        }
        $prioridades     = $leerCatalogo('pr', 'fa-equals', true, false) ?: $def['prioridades'];
        $estadosProyecto = $leerCatalogo('ep', 'fa-flag', false, false) ?: $def['estados_proyecto'];
        $equiposCat      = $leerCatalogo('eqs', 'fa-users', false, false) ?: $def['equipos'];

        $lineas = fn(string $texto) => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $texto))));
        $iconos = array_values(array_filter($lineas($_POST['iconos'] ?? ''), fn($i) => preg_match('/^fa-[a-z0-9-]+$/', $i)));

        $zoomPost = (array)($_POST['zoom'] ?? []);
        $zoom = [
            'activo'        => !empty($zoomPost['activo']),
            'account_id'    => trim($zoomPost['account_id'] ?? ''),
            'client_id'     => trim($zoomPost['client_id'] ?? ''),
            'client_secret' => $secreto($zoomPost['client_secret'] ?? '', $prev['zoom']['client_secret'] ?? ''),
            'zona'          => trim($zoomPost['zona'] ?? '') ?: 'America/Guayaquil',
        ];

        $reuPost = (array)($_POST['reuniones'] ?? []);
        $reunionesCfg = [
            'plataforma'      => ($reuPost['plataforma'] ?? '') === 'meet' ? 'meet' : 'zoom',
            'permitir_elegir' => !empty($reuPost['permitir_elegir']),
            'duracion'        => isset(Reuniones::duraciones()[(int)($reuPost['duracion'] ?? 0)])
                                    ? (int)$reuPost['duracion'] : $def['reuniones']['duracion'],
            'zona'            => trim($reuPost['zona'] ?? ''),
            'agendar'         => !empty($reuPost['agendar']),
        ];

        $correoPost = (array)($_POST['correo'] ?? []);
        $correo = [
            'activo'    => !empty($correoPost['activo']),
            'modo'      => in_array($correoPost['modo'] ?? '', ['smtp', 'gmail_api'], true) ? $correoPost['modo'] : 'smtp',
            'host'      => trim($correoPost['host'] ?? '') ?: $def['correo']['host'],
            'puerto'    => (int)($correoPost['puerto'] ?? 0) ?: $def['correo']['puerto'],
            'usuario'   => trim($correoPost['usuario'] ?? ''),
            'clave'     => $secreto($correoPost['clave'] ?? '', $prev['correo']['clave'] ?? ''),
            'remitente' => trim($correoPost['remitente'] ?? '') ?: $def['correo']['remitente'],
            'url_panel' => trim($correoPost['url_panel'] ?? ''),
            'client_id'     => trim($correoPost['client_id'] ?? ''),
            'client_secret' => $secreto($correoPost['client_secret'] ?? '', $prev['correo']['client_secret'] ?? ''),
            'refresh_token' => $secreto($correoPost['refresh_token'] ?? '', $prev['correo']['refresh_token'] ?? ''),
            'avisar_asignacion'   => !empty($correoPost['avisar_asignacion']),
            'avisar_proyecto'     => !empty($correoPost['avisar_proyecto']),
            'avisar_intercambio'  => !empty($correoPost['avisar_intercambio']),
            'avisar_recordatorio' => !empty($correoPost['avisar_recordatorio']),
            'dias_recordatorio'   => max(0, min(30, (int)($correoPost['dias_recordatorio'] ?? 3))),
            'avisar_completado'   => !empty($correoPost['avisar_completado']),
            'admin_email'         => filter_var(trim($correoPost['admin_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
        ];

        // Roles: filas del catalogo (rl[]) o, por compatibilidad, textarea 'roles'
        $roles = array_values(array_filter(array_map('trim', (array)($_POST['rl'] ?? []))));
        if (!$roles) {
            $roles = $lineas($_POST['roles'] ?? '');
        }

        // Logo del panel: sube uno nuevo, quítalo (vuelve al de siempre) o
        // conserva el que había si no tocaron el campo.
        $logoPrev = (string)($prev['logo'] ?? '');
        if (!empty($_POST['logo_quitar'])) {
            $logo = '';
        } else {
            $subido = guardarFoto('logo', 'marca_', 'imagen del logo');
            $logo = $subido !== '' ? $subido : $logoPrev;
        }

        Config::guardar([
            'titulo'           => trim($_POST['titulo'] ?? '') ?: $def['titulo'],
            'subtitulo'        => trim($_POST['subtitulo'] ?? '') ?: $def['subtitulo'],
            'logo'             => $logo,
            'github_token'     => $secreto($_POST['github_token'] ?? '', $prev['github_token'] ?? ''),
            'gitlab_token'     => $secreto($_POST['gitlab_token'] ?? '', $prev['gitlab_token'] ?? ''),
            'gitlab_host'      => trim($_POST['gitlab_host'] ?? ''),
            'google_login'     => [
                'activo'              => !empty($_POST['google_login']['activo']),
                'vincular_por_nombre' => !empty($_POST['google_login']['vincular_por_nombre']),
                'calendario'          => !empty($_POST['google_login']['calendario']),
                'client_id'     => trim($_POST['google_login']['client_id'] ?? ''),
                'client_secret' => $secreto($_POST['google_login']['client_secret'] ?? '', $prev['google_login']['client_secret'] ?? ''),
            ],
            'registro'         => [
                'abierto'  => !empty($_POST['registro']['abierto']),
                // Solo dominios con forma de dominio; lo demás se descarta
                'dominios' => implode(', ', array_filter(
                    array_map(fn($d) => strtolower(ltrim(trim($d), '@')), preg_split('/[\s,;]+/', (string)($_POST['registro']['dominios'] ?? ''))),
                    fn($d) => $d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)
                )),
                'avisar'   => !empty($_POST['registro']['avisar']),
            ],
            'color_secundario' => $hex($_POST['color_secundario'] ?? '', $def['color_secundario']),
            'color_acento'     => $hex($_POST['color_acento'] ?? '', $def['color_acento']),
            'estados_tarea'    => $estados,
            'prioridades'      => $prioridades,
            'estados_proyecto' => $estadosProyecto,
            'equipos'          => $equiposCat,
            'iconos'           => $iconos ?: $def['iconos'],
            'roles'            => $roles ?: $def['roles'],
            'correo'           => $correo,
            'zoom'             => $zoom,
            'reuniones'        => $reunionesCfg,
        ]);

        // Remapear datos existentes: si se elimino un estado/prioridad en uso,
        // las tareas y proyectos afectados pasan a la primera opcion del catalogo.
        $ek = array_keys($estados);
        $pk = array_keys($prioridades);
        $epk = array_keys($estadosProyecto);
        $storeTareas = new JsonStore('tareas');
        foreach ($storeTareas->all() as $t) {
            $cambios = [];
            if (!in_array($t['estado'] ?? '', $ek, true))    $cambios['estado'] = $ek[0];
            if (!in_array($t['prioridad'] ?? '', $pk, true)) $cambios['prioridad'] = $pk[0];
            if ($cambios) $storeTareas->update((int)$t['id'], $cambios);
        }
        $storeProyectos = new JsonStore('proyectos');
        foreach ($storeProyectos->all() as $p) {
            if (!in_array($p['estado'] ?? '', $epk, true)) {
                $storeProyectos->update((int)$p['id'], ['estado' => $epk[0]]);
            }
        }
        $eqk = array_keys($equiposCat);
        $storeMiembros = new JsonStore('miembros');
        foreach ($storeMiembros->all() as $m) {
            if (!in_array($m['equipo'] ?? '', $eqk, true)) {
                $storeMiembros->update((int)$m['id'], ['equipo' => $eqk[0]]);
            }
        }
        redirigir('ajustes.php', 'Ajustes guardados. ¡El panel ya usa tu configuración!');

    case 'config_reset':
        Config::restaurar();
        redirigir('ajustes.php', 'Ajustes restaurados a los valores por defecto.');

    case 'correo_prueba':
        $para = filter_var(trim($_POST['para'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$para) {
            redirigir('ajustes.php', 'Escribe un correo de destino válido para la prueba.', 'error');
        }
        if (!Mailer::listo()) {
            // Decir QUÉ falta: "revisa la configuración" no ayuda a nadie.
            $cCorreo = Mailer::config();
            $falta = match (true) {
                empty($cCorreo['activo']) => 'marca «Activar envío de correos»',
                ($cCorreo['modo'] ?? '') === 'gmail_api' && trim($cCorreo['refresh_token'] ?? '') === ''
                    => 'falta conectar la cuenta de envío con Google (el botón está aquí abajo, en «API de Gmail»)',
                ($cCorreo['modo'] ?? '') === 'gmail_api'
                    => 'faltan el Client ID y el Client Secret de Google Cloud',
                trim($cCorreo['usuario'] ?? '') === '' => 'falta el correo remitente',
                default => 'falta la contraseña de aplicación del SMTP',
            };
            redirigir('ajustes.php', 'Todavía no se puede enviar: ' . $falta . '.', 'error');
        }
        $marcaCorreo = Config::get('titulo');
        $r = Mailer::enviar($para, 'Prueba de correo — ' . $marcaCorreo,
            '<p style="font-family:Arial;font-size:15px;">¡Funciona! El panel ' . e($marcaCorreo) . ' ya puede enviar notificaciones por correo.</p>');
        if ($r === true) {
            redirigir('ajustes.php', 'Correo de prueba enviado a ' . $para . '. ¡Revisa la bandeja!');
        }
        redirigir('ajustes.php', 'El envío falló: ' . $r, 'error');

    /* ---------- Respaldo de la configuracion ---------- */

    case 'config_exportar':
        $json = json_encode(Config::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nombre = 'mchub-config-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-store');
        echo $json;
        exit;

    case 'config_importar':
        $err = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            redirigir('ajustes.php', 'Elige el archivo .json que quieres importar.', 'error');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            redirigir('ajustes.php', 'El archivo supera el límite del servidor (' . ini_get('upload_max_filesize') . ').', 'error');
        }
        if ($err !== UPLOAD_ERR_OK || empty($_FILES['archivo']['tmp_name'])) {
            redirigir('ajustes.php', 'No se pudo subir el archivo (código ' . $err . ').', 'error');
        }
        $contenido = (string)file_get_contents($_FILES['archivo']['tmp_name']);
        $datos = json_decode($contenido, true);
        if (!is_array($datos) || json_last_error() !== JSON_ERROR_NONE) {
            redirigir('ajustes.php', 'Ese archivo no es un JSON válido de configuración.', 'error');
        }
        $aplicadas = Config::importar($datos);
        if (!$aplicadas) {
            redirigir('ajustes.php', 'El archivo no traía ninguna clave de configuración reconocida.', 'error');
        }
        redirigir('ajustes.php', 'Configuración importada: ' . count($aplicadas) . ' bloque(s) actualizado(s) (' . implode(', ', $aplicadas) . ').');

    case 'zoom_prueba':
        if (!Zoom::listo()) {
            redirigir('ajustes.php', 'Primero activa Zoom y guarda Account ID, Client ID y Client Secret.', 'error');
        }
        $r = Zoom::probar();
        redirigir('ajustes.php', $r === true ? '¡Conexión con Zoom exitosa! Ya puedes crear reuniones.' : 'Zoom: ' . $r, $r === true ? 'success' : 'error');

    /* ---------- Reuniones (Zoom) ---------- */

    case 'reunion_crear':
        $reuniones = new ReunionRepo();
        $pid = (int)($_POST['proyecto_id'] ?? 0);
        if (!$proyectos->buscar($pid)) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        $volver = 'proyecto.php?id=' . $pid . '#vista-reuniones';
        // La plataforma la decide el proyecto (si tiene una propia) o Ajustes;
        // lo que pida el formulario solo cuenta si el admin dejo elegir.
        $plataforma = Reuniones::resolverPlataforma($_POST['plataforma'] ?? '', $proyectos->buscar($pid));
        if ($plataforma === '') {
            redirigir($volver, 'No hay ninguna plataforma de reuniones configurada. Ve a Ajustes → Reuniones.', 'error');
        }
        $topic  = trim($_POST['topic'] ?? '');
        $inicio = str_replace('T', ' ', trim($_POST['inicio'] ?? ''));   // datetime-local
        $dur    = (int)($_POST['duracion'] ?? Reuniones::duracionDefecto());
        if ($topic === '' || $inicio === '') {
            redirigir($volver, 'Indica el tema y la fecha/hora de la reunión.', 'error');
        }
        [$recurrente, $dias, $hasta, $inicio] = repeticionReunion($_POST, $inicio, $plataforma, $volver);
        $invitados = array_values(array_map('intval', (array)($_POST['invitados'] ?? [])));
        $p = $proyectos->buscar($pid);

        if ($plataforma === 'meet') {
            // Meet: se crea en el calendario del creador (necesita su Google)
            $yo = Auth::usuario();
            $refresh = (string)($yo['gcal_refresh'] ?? '');
            if ($refresh === '') {
                redirigir($volver, 'Para crear reuniones de Meet, primero conecta tu Google en Mi perfil → "Conectar mi calendario".', 'error');
            }
            $emails = [];
            foreach ($invitados as $mid) {
                $m = $miembros->buscar($mid);
                if ($m && !empty($m['email'])) $emails[] = $m['email'];
            }
            $creada = GoogleCalendar::crearMeet($refresh, [
                'topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur, 'invitados' => $emails,
                'dias'  => $dias,  'hasta'  => $hasta,
            ]);
            if (isset($creada['error'])) {
                $msg = $creada['error'] === 'sin_conexion'
                    ? 'Conecta tu Google en Mi perfil para crear reuniones de Meet.'
                    : $creada['error'];
                redirigir($volver, $msg, 'error');
            }
            $reu = $reuniones->crear([
                'proyecto_id' => $pid,
                'plataforma'  => 'meet',
                'gcal_event'  => (string)($creada['event'] ?? ''),
                'creador_id'  => (int)($yo['id'] ?? 0),
                'topic'       => $topic,
                'inicio'      => $inicio,
                'duracion'    => $dur,
                'join_url'    => (string)($creada['meet'] ?? ''),
                'invitados'   => $invitados,
                'recurrente'  => $recurrente,
                'dias'        => $dias,
                'hasta'       => $hasta,
            ]);
            $donde = 'Reunión de Google Meet creada.';
        } else {
            // Zoom (Server-to-Server)
            if (!Zoom::listo()) {
                redirigir($volver, 'Zoom no está configurado. Ve a Ajustes → Zoom (o crea la reunión con Meet).', 'error');
            }
            $creada = Zoom::crearReunion([
                'topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur,
                'dias'  => $dias,  'hasta'  => $hasta,
            ]);
            if (isset($creada['error'])) {
                redirigir($volver, $creada['error'], 'error');
            }
            $reu = $reuniones->crear([
                'proyecto_id' => $pid,
                'plataforma'  => 'zoom',
                'zoom_id'     => (string)($creada['id'] ?? ''),
                'creador_id'  => (int)(Auth::usuario()['id'] ?? 0),
                'topic'       => $topic,
                'inicio'      => $inicio,
                'duracion'    => $dur,
                'join_url'    => $creada['join_url'] ?? '',
                'start_url'   => $creada['start_url'] ?? '',
                'password'    => $creada['password'] ?? '',
                'invitados'   => $invitados,
                'recurrente'  => $recurrente,
                'dias'        => $dias,
                'hasta'       => $hasta,
            ]);
            $donde = 'Reunión creada en Zoom.';
        }
        if ($recurrente) {
            $donde .= ' Se repite ' . mb_strtolower(Reuniones::etiqueta($dias)) . ' hasta el ' . $hasta . '.';
        }

        // La deja en el calendario de cada invitado (imprescindible en Zoom)
        $agendados = agendarReunionEnCalendarios($reuniones->buscar((int)$reu['id']), (array)$p, $miembros, $reuniones);

        // Notifica por correo a los invitados con correo registrado
        $avisados = 0;
        if (Mailer::listo()) {
            foreach ($invitados as $mid) {
                $m = $miembros->buscar($mid);
                if ($m && Mailer::notificarReunion($reu, $m, $p) === true) $avisados++;
            }
        }
        redirigir($volver, $donde
            . ($avisados  ? ' ' . $avisados . ' invitado(s) notificado(s).' : '')
            . ($agendados ? ' Agendada en ' . $agendados . ' calendario(s).' : ''));

    case 'reunion_editar':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if (!$reu) {
            redirigir('index.php', 'Reunión no encontrada.', 'error');
        }
        $pid = (int)$reu['proyecto_id'];
        $volver = 'proyecto.php?id=' . $pid . '#vista-reuniones';
        $topic = trim($_POST['topic'] ?? '');
        $inicio = str_replace('T', ' ', trim($_POST['inicio'] ?? ''));
        $duracion = (int)($_POST['duracion'] ?? Reuniones::duracionDefecto());
        if ($topic === '' || $inicio === '') {
            redirigir($volver, 'Indica el tema y la fecha/hora de la reunión.', 'error');
        }
        $esMeet = ($reu['plataforma'] ?? 'zoom') === 'meet';
        [$recurrente, $dias, $hasta, $inicio] = repeticionReunion($_POST, $inicio, $esMeet ? 'meet' : 'zoom', $volver);
        $invitadosAntes = array_map('intval', (array)($reu['invitados'] ?? []));
        $invitados = array_values(array_map('intval', (array)($_POST['invitados'] ?? [])));

        // Actualiza en la plataforma correspondiente
        if ($esMeet && !empty($reu['gcal_event'])) {
            $creador = $miembros->buscar((int)($reu['creador_id'] ?? 0));
            $refresh = (string)($creador['gcal_refresh'] ?? (Auth::usuario()['gcal_refresh'] ?? ''));
            $emails = [];
            foreach ($invitados as $mid) {
                $m = $miembros->buscar($mid);
                if ($m && !empty($m['email'])) $emails[] = $m['email'];
            }
            $ok = GoogleCalendar::actualizarMeet($refresh, (string)$reu['gcal_event'], [
                'topic' => $topic, 'inicio' => $inicio, 'duracion' => $duracion,
                'dias'  => $dias,  'hasta'  => $hasta, 'invitados' => $emails,
            ]);
            if ($ok !== true) {
                redirigir($volver, $ok, 'error');
            }
        } elseif (!empty($reu['zoom_id']) && Zoom::listo()) {
            $ok = Zoom::actualizarReunion((string)$reu['zoom_id'], [
                'topic' => $topic, 'inicio' => $inicio, 'duracion' => $duracion,
                'dias'  => $dias,  'hasta'  => $hasta,
            ]);
            if ($ok !== true) {
                redirigir($volver, $ok, 'error');
            }
        }
        $reuniones->actualizar((int)$reu['id'], [
            'topic'      => $topic,
            'inicio'     => $inicio,
            'duracion'   => $duracion,
            'invitados'  => $invitados,
            'recurrente' => $recurrente,
            'dias'       => $dias,
            'hasta'      => $hasta,
        ]);
        // Reagenda las copias: la hora, la repeticion o los invitados pudieron cambiar
        $pReu = $proyectos->buscar($pid);
        agendarReunionEnCalendarios($reuniones->buscar((int)$reu['id']), (array)$pReu, $miembros, $reuniones);
        // Avisa solo a los invitados NUEVOS
        $nuevos = array_diff($invitados, $invitadosAntes);
        $avisados = 0;
        $p = $proyectos->buscar($pid);
        $reuActual = $reuniones->buscar((int)$reu['id']);
        if ($nuevos && $p && Mailer::listo()) {
            foreach ($nuevos as $mid) {
                $m = $miembros->buscar((int)$mid);
                if ($m && Mailer::notificarReunion($reuActual, $m, $p) === true) $avisados++;
            }
        }
        redirigir($volver, 'Reunión actualizada.' . ($avisados ? ' ' . $avisados . ' invitado(s) nuevo(s) notificado(s).' : ''));

    case 'reunion_grabaciones':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if (!$reu) {
            redirigir('index.php', 'Reunión no encontrada.', 'error');
        }
        // Cualquier participante del proyecto puede traer/ver las grabaciones,
        // no solo un administrador (pero no gente ajena al proyecto).
        if (!puedeVerProyecto((int)$reu['proyecto_id'])) {
            redirigir('index.php', 'No participas en ese proyecto.', 'error');
        }
        $volver = 'proyecto.php?id=' . $reu['proyecto_id'] . '#vista-reuniones';
        $g = Zoom::grabaciones($reu['zoom_id'], (string)($reu['password'] ?? ''));
        if ($g['estado'] === 'ok') {
            $reuniones->actualizar((int)$reu['id'], [
                'grabaciones'   => $g['archivos'],
                'share_url'     => $g['share_url'] ?? '',
                'grab_password' => $g['password'] ?? '',
            ]);
            $msg = count($g['archivos']) . ' archivo(s) de grabación disponibles.';
            if (!empty($g['abierto'])) {
                $msg .= ' Abre sin pedir código.';
            } else {
                // No se pudo quitar el código por API: mostramos el motivo real
                // (para saber si es scope o política de la cuenta) y el código.
                $msg .= ' No se pudo quitar el código automáticamente';
                $msg .= !empty($g['abrir_error']) ? ' (' . $g['abrir_error'] . ').' : '.';
                if (!empty($g['password'])) $msg .= ' Usa el código del botón de al lado, o desactívalo en Zoom → Configuración → Grabación.';
            }
            redirigir($volver, $msg, !empty($g['abierto']) ? 'success' : 'info');
        }
        redirigir($volver, $g['msg'] ?? 'Sin grabación disponible.', $g['estado'] === 'vacio' ? 'info' : 'error');

    case 'reunion_transcripcion':
        // Descarga la transcripción de la reunión como .md con contexto, para
        // pasársela a Claude (resúmenes, tareas, decisiones).
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if (!$reu) {
            redirigir('index.php', 'Reunión no encontrada.', 'error');
        }
        if (!puedeVerProyecto((int)$reu['proyecto_id'])) {
            redirigir('index.php', 'No participas en ese proyecto.', 'error');
        }
        $volver = 'proyecto.php?id=' . $reu['proyecto_id'] . '#vista-reuniones';
        $tr = Zoom::transcripcion((string)$reu['zoom_id']);
        if ($tr['estado'] !== 'ok') {
            redirigir($volver, $tr['msg'] ?? 'Sin transcripción.', $tr['estado'] === 'vacio' ? 'info' : 'error');
        }
        $p = $proyectos->buscar((int)$reu['proyecto_id']);
        $nombres = [];
        foreach ((array)($reu['invitados'] ?? []) as $mid) {
            $m = $miembros->buscar((int)$mid);
            if ($m) $nombres[] = $m['nombre'];
        }
        $cab = '# Transcripción de reunión — ' . ($reu['topic'] ?? '') . "\n\n"
            . 'Proyecto: ' . ($p['nombre'] ?? '') . "\n"
            . 'Fecha: ' . ($reu['inicio'] ?? '') . "\n"
            . ($nombres ? 'Participantes: ' . implode(', ', $nombres) . "\n" : '')
            . "\nContexto para Claude: esto es la transcripción automática (Zoom) de una reunión "
            . "del equipo de MChub. Úsala para resumir lo hablado, decisiones y tareas pendientes. "
            . "Las tareas del panel se referencian con su #id.\n\n---\n\n";
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($reu['topic'] ?? 'reunion'));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="transcripcion-' . trim($slug, '-') . '.md"');
        header('Cache-Control: no-store');
        echo $cab . $tr['texto'];
        exit;

    case 'reunion_eliminar':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if ($reu) {
            // Las copias en los calendarios de los invitados se van con ella
            borrarCopiasReunion($reu, $miembros);
            if (($reu['plataforma'] ?? 'zoom') === 'meet' && !empty($reu['gcal_event'])) {
                $creador = $miembros->buscar((int)($reu['creador_id'] ?? 0));
                $refresh = (string)($creador['gcal_refresh'] ?? (Auth::usuario()['gcal_refresh'] ?? ''));
                GoogleCalendar::borrarEvento($refresh, (string)$reu['gcal_event']);
            } elseif (!empty($reu['zoom_id']) && Zoom::listo()) {
                Zoom::eliminarReunion($reu['zoom_id']);
            }
            $reuniones->eliminar((int)$reu['id']);
            redirigir('proyecto.php?id=' . $reu['proyecto_id'] . '#vista-reuniones', 'Reunión eliminada.');
        }
        redirigir('index.php', 'Reunión no encontrada.', 'error');

    default:
        redirigir('index.php', 'Acción no reconocida.', 'error');
}
