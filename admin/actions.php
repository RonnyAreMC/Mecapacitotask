<?php
/**
 * Punto unico de entrada para todas las acciones POST del panel.
 * Cada accion valida, ejecuta sobre el repositorio y redirige con flash.
 */
require_once __DIR__ . '/lib/bootstrap.php';
// Notificaciones (campana) y pedidos entre equipos. Fuera de bootstrap a
// propósito: si al subir por FTP falta alguno, solo se pierde eso.
foreach (['Notificaciones', 'Pedidos', 'Correo', 'Push', 'Tutoriales', 'Whatsapp', 'ReqCorreo', 'CorreoCopias', 'TareasCorreo', 'ReqSeguimiento'] as $moduloNuevo) {
    if (is_file(__DIR__ . '/lib/' . $moduloNuevo . '.php')) require_once __DIR__ . '/lib/' . $moduloNuevo . '.php';
}

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
// Recuperar la contraseña: sin sesión, con un código de 6 dígitos (WhatsApp o correo)
$accionesPublicas   = ['auth_login', 'auth_identificar', 'solicitud_registrar', 'auth_recuperar_pedir', 'auth_recuperar_cambiar'];
// Los intercambios los pide y responde la propia gente, no un administrador:
// cada accion comprueba por dentro que la tarea sea suya.
$accionesDeCualquiera = [
    'auth_logout', 'obs_crear', 'perfil_guardar', 'mis_tareas_json', 'proyecto_tareas_json', 'contexto_tareas_json',
    // El token de la API para Claude Code: cada quien el suyo (Mi perfil)
    'api_token_crear', 'api_token_quitar',
    'reunion_transcripcion', 'tarea_estado', 'tarea_crear', 'tarea_editar',
    // Quien depende de una tarea de otro equipo puede recordarle por correo
    // (dentro se comprueba que participe en su proyecto y que la dep sea real).
    'dep_recordar',
    // El responsable de un requerimiento suelto puede marcarlo terminado desde
    // su bandeja, y anotar observaciones en el que tenga asignado (dentro se
    // comprueba que sea suyo).
    'req_terminar', 'req_observar',
    // Quien tiene un requerimiento suma a un colaborador (solo eso: no cambia
    // fechas ni quita a nadie). Dentro se comprueba que lo tenga asignado.
    'req_colaborador',
    // Lo mismo en una tarea: quien la tiene suma a quien le ayuda (dentro se comprueba)
    'tarea_colaborador',
    // Quien sube los cambios al servidor de pruebas suele ser un colaborador
    // sin permisos de gestion: el encargado se comprueba con puedeDesplegar().
    'deploy_registrar',
    // El Scrum Master gestiona reuniones de SUS proyectos (cada acción verifica
    // puedeGestionar por dentro; un lector queda fuera igual).
    'reunion_crear', 'reunion_editar', 'reunion_eliminar', 'reunion_choque', 'reunion_agenda',
    'intercambio_crear', 'intercambio_responder', 'intercambio_cancelar',
    // El horario de reuniones fijas lo escribe quien lleva cada proyecto (su
    // Scrum Master o su PO); dentro se comprueba con puedeHorarioDelProyecto().
    'rfija_crear', 'rfija_editar', 'rfija_eliminar',
    // IA: administrador y Scrum Master. Cada acción comprueba por dentro el
    // perfil (IA::puedeUsar) y que el proyecto sea suyo (puedeGestionar).
    'ia_plan_documento', 'ia_acta', 'ia_acta_hay', 'ia_req_texto', 'ia_obs_redaccion', 'ia_obs_adjunto', 'ia_obs_resumen',
    'ia_tarea_descripcion', 'ia_tarea_desglose', 'ia_tarea_dependencias', 'ia_crear_tareas',
    'ia_deploy_notas', 'ia_daily',
    // Campana: cada quien marca las suyas. Pedidos entre equipos: los hace y
    // responde la propia gente (dentro se comprueba quién puede qué).
    'notif_leer_todas', 'pedido_crear', 'pedido_responder', 'pedido_estado',
    // Cada quien guarda su teléfono cuando el panel se lo pide al entrar
    'perfil_telefono',
    // La ventana del tutorial («Avisar a todos»): cada quien marca que ya lo vio o la cerró
    'tutorial_visto',
    // Correo InnoTech: cada persona maneja SOLO su buzón (dentro se usa la
    // sesión de quien entró). correo_conf queda para el administrador.
    'correo_conectar', 'correo_desconectar', 'correo_enviar', 'correo_marcar', 'correo_marcar_varios', 'correo_leidos', 'correo_eliminar',
    // Notificaciones nativas: cada persona anota y quita SUS dispositivos
    'push_suscribir', 'push_quitar', 'push_prefs', 'push_prueba',
    // Releases: los ven los editores y los Scrum Master; cada acción comprueba
    // por dentro quién puede qué (Releases::puedeVer, puedeEditar o SM del equipo).
    'rel_importar', 'rel_item_guardar', 'rel_item_tareas', 'rel_item_borrar', 'rel_tarea_crear', 'rel_equipo_guardar',
    'rel_release_guardar', 'rel_hito_guardar', 'rel_hito_borrar', 'rel_revision_guardar', 'rel_revision_borrar',
    'rel_ia_aplicar', 'rel_conf_guardar', 'rel_ia_revision', 'rel_ia_equipo', 'rel_ia_general', 'rel_sembrar', 'rel_reuniones_equipo',
    'rel_daily_registrar', 'rel_daily_zoom', 'rel_daily_reunion',
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
 * Aviso en la campana de la barra de arriba, aparte del correo: sale aunque el
 * correo no esté configurado. Nadie se avisa a sí mismo. Si lib/Notificaciones.php
 * no está en el servidor, no hace nada.
 */
function avisoPanel(array $para, string $tipo, string $titulo, string $texto = '', string $url = '', int $de = -1): void
{
    if (class_exists('Notif')) Notif::crear($para, $tipo, $titulo, $texto, $url, $de);
}

/**
 * Releases: crea la reunión virtual de una revisión en el proyecto del equipo,
 * igual que «Nueva reunión» (Zoom, Meet o enlace según Ajustes → Reuniones):
 * la agenda en el calendario de cada invitado y avisa por WhatsApp o correo.
 * Devuelve la reunión creada o el texto del error.
 */
function relCrearReunion(array $rev, int $pid, array $invitados, string $pedida, string $enlace, ProyectoRepo $proyectos, MiembroRepo $miembros): array|string
{
    $p = $proyectos->buscar($pid);
    if (!$p) return 'El equipo no tiene un proyecto del panel donde crear la reunión (Releases → Ajustes → Equipos).';
    if (!puedeReunionesDelProyecto($pid)) return 'Solo crean reuniones en «' . $p['nombre'] . '» su Scrum Master, su PO o el administrador.';
    $plataforma = Reuniones::resolverPlataforma($pedida, $p);
    if ($plataforma === '') return 'No hay ninguna plataforma de reuniones configurada (Ajustes → Reuniones).';
    [$hIni, $dur] = Releases::horario((string)($rev['hora'] ?? ''), (int)($rev['duracion'] ?? Reuniones::duracionDefecto()));
    if ((int)($rev['duracion'] ?? 0) > 0) $dur = (int)$rev['duracion'];
    $inicio = $rev['fecha'] . ' ' . $hIni;
    $topic = 'Revisión ' . $rev['equipo'] . ' · ' . date('d/m', strtotime($rev['fecha'])) . (($rev['titulo'] ?? '') !== '' ? ' · ' . mb_substr($rev['titulo'], 0, 120) : '');
    $reuniones = new ReunionRepo();
    $base = ['proyecto_id' => $pid, 'creador_id' => (int)(Auth::usuario()['id'] ?? 0), 'topic' => $topic, 'inicio' => $inicio,
             'duracion' => $dur, 'invitados' => $invitados, 'recurrente' => false, 'dias' => [], 'hasta' => ''];
    if ($plataforma === 'enlace') {
        if (!filter_var($enlace, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $enlace)) return 'Pega el enlace de la reunión (tiene que empezar por https://).';
        $reu = $reuniones->crear($base + ['plataforma' => 'enlace', 'join_url' => $enlace]);
    } elseif ($plataforma === 'meet') {
        $refresh = (string)(Auth::usuario()['gcal_refresh'] ?? '');
        if ($refresh === '') return 'Para crear reuniones de Meet, primero conecta tu Google en Mi perfil → «Conectar mi calendario».';
        $emails = [];
        foreach ($invitados as $mid) { $m = $miembros->buscar($mid); if ($m && !empty($m['email'])) $emails[] = $m['email']; }
        $creada = GoogleCalendar::crearMeet($refresh, ['topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur, 'invitados' => $emails, 'dias' => [], 'hasta' => '']);
        if (isset($creada['error'])) return $creada['error'] === 'sin_conexion' ? 'Conecta tu Google en Mi perfil para crear reuniones de Meet.' : (string)$creada['error'];
        $reu = $reuniones->crear($base + ['plataforma' => 'meet', 'gcal_event' => (string)($creada['event'] ?? ''), 'join_url' => (string)($creada['meet'] ?? '')]);
    } else {
        if (!Zoom::listo()) return 'Zoom no está configurado (Ajustes → Zoom).';
        if ($choque = Reuniones::choqueZoom($inicio, $dur)) return Reuniones::textoChoque($choque, $dur);
        $creada = Zoom::crearReunion(['topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur, 'dias' => [], 'hasta' => '']);
        if (isset($creada['error'])) return (string)$creada['error'];
        $reu = $reuniones->crear($base + ['plataforma' => 'zoom', 'zoom_id' => (string)($creada['id'] ?? ''), 'join_url' => $creada['join_url'] ?? '',
                                          'start_url' => $creada['start_url'] ?? '', 'password' => $creada['password'] ?? '']);
    }
    // Lo mismo que una reunión hecha a mano: calendarios, campana, WhatsApp o correo
    try {
        agendarReunionEnCalendarios($reuniones->buscar((int)$reu['id']), $p, $miembros, $reuniones);
        $volverR = 'releases.php?v=revision&id=' . (int)$rev['id'];
        avisoPanel($invitados, 'reunion', 'Te invitaron a una revisión', $topic . ' · ' . $inicio, $volverR);
        $paraCorreo = $invitados;
        if (class_exists('Whatsapp') && Whatsapp::activo()) {
            [, $sinTel] = Whatsapp::avisarReunion($reu, $invitados, $p, 'nueva', (int)(Auth::usuario()['id'] ?? 0));
            $paraCorreo = array_map(fn($m) => (int)$m['id'], $sinTel);
        }
        if (Mailer::listo()) foreach ($paraCorreo as $mid) { $m = $miembros->buscar($mid); if ($m) Mailer::notificarReunion($reu, $m, $p); }
    } catch (Throwable $e) { /* la reunión ya quedó creada */ }
    return $reu + ['proyecto_id' => $pid];
}

/**
 * La revisión cambió de día u hora: si tiene una reunión suelta del panel (la
 * que se creó para ella), se mueve en Zoom o Meet, en los calendarios y se avisa
 * a los invitados. Una reunión que se repite no se toca. Devuelve la coletilla.
 */
function relMoverReunion(array $antes, array $rev, ProyectoRepo $proyectos, MiembroRepo $miembros): string
{
    if (($antes['fecha'] ?? '') === ($rev['fecha'] ?? '') && ($antes['hora'] ?? '') === ($rev['hora'] ?? '')
        && (int)($antes['duracion'] ?? 0) === (int)($rev['duracion'] ?? 0)) return '';
    $repo = new ReunionRepo();
    $reu = (int)($rev['reunion_id'] ?? 0) > 0 ? $repo->buscar((int)$rev['reunion_id']) : null;
    if (!$reu) return '';
    if (!empty($reu['recurrente'])) return ' La reunión enlazada se repite cada semana: esa no se movió.';
    [$hIni, $dur] = Releases::horario((string)($rev['hora'] ?? ''), (int)($reu['duracion'] ?? 60));
    if ((int)($rev['duracion'] ?? 0) > 0) $dur = (int)$rev['duracion'];
    $inicio = $rev['fecha'] . ' ' . $hIni;
    if ($inicio === (string)($reu['inicio'] ?? '') && $dur === (int)($reu['duracion'] ?? 0)) return '';
    $topic = (string)($reu['topic'] ?? '');
    // El «· dd/mm» del tema de la revisión sigue a la fecha nueva
    if (($antes['fecha'] ?? '') !== '') $topic = str_replace('· ' . date('d/m', strtotime($antes['fecha'])), '· ' . date('d/m', strtotime($rev['fecha'])), $topic);
    if (($reu['plataforma'] ?? 'zoom') === 'meet' && !empty($reu['gcal_event'])) {
        $creador = $miembros->buscar((int)($reu['creador_id'] ?? 0));
        $emails = [];
        foreach ((array)($reu['invitados'] ?? []) as $mid) { $m = $miembros->buscar((int)$mid); if ($m && !empty($m['email'])) $emails[] = $m['email']; }
        $ok = GoogleCalendar::actualizarMeet((string)($creador['gcal_refresh'] ?? (Auth::usuario()['gcal_refresh'] ?? '')), (string)$reu['gcal_event'],
            ['topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur, 'dias' => [], 'hasta' => '', 'invitados' => $emails]);
        if ($ok !== true) return ' La revisión se movió, pero la reunión de Meet no: ' . $ok;
    } elseif (!empty($reu['zoom_id']) && Zoom::listo()) {
        if ($choque = Reuniones::choqueZoom($inicio, $dur, [], '', (int)$reu['id'])) return ' La revisión se movió, pero la reunión de Zoom no: ' . Reuniones::textoChoque($choque, $dur);
        $ok = Zoom::actualizarReunion((string)$reu['zoom_id'], ['topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur, 'dias' => [], 'hasta' => '']);
        if ($ok !== true) return ' La revisión se movió, pero la reunión de Zoom no: ' . $ok;
    }
    $repo->actualizar((int)$reu['id'], ['topic' => $topic, 'inicio' => $inicio, 'duracion' => $dur]);
    try {
        $p = $proyectos->buscar((int)$reu['proyecto_id']);
        agendarReunionEnCalendarios($repo->buscar((int)$reu['id']), (array)$p, $miembros, $repo);
        avisoPanel(array_map('intval', (array)($reu['invitados'] ?? [])), 'reunion', 'Cambió la revisión ' . $rev['equipo'],
            $topic . ' · ' . $inicio, 'releases.php?v=revision&id=' . (int)$rev['id']);
    } catch (Throwable $e) { /* la reunión ya quedó movida */ }
    return ' La reunión también se movió al ' . date('d/m H:i', strtotime($inicio)) . '.';
}

/**
 * Releases: el PO o el SM amarra la tarea a un ítem del release desde el
 * formulario de la tarea (campo rel_item; vacío = sin ítem). Solo se tocan los
 * ítems de los equipos que trabajan en ese proyecto. Devuelve la coletilla.
 */
function relAmarrar(int $tareaId, int $pid, array $post): string
{
    if (!array_key_exists('rel_item', $post) || !is_file(__DIR__ . '/lib/Releases.php')) return '';
    try {
        require_once __DIR__ . '/lib/Releases.php';
        $items = [];
        foreach (Releases::itemsDeProyecto($pid) as $it) $items[(int)$it['id']] = $it;
        $nuevo = (int)$post['rel_item'];
        if ($nuevo > 0 && !isset($items[$nuevo])) return ' Ese ítem del release no es de los equipos de este proyecto.';
        $txt = '';
        foreach ($items as $iid => $it) {
            $tiene = in_array($tareaId, array_map('intval', (array)($it['tareas'] ?? [])), true);
            if ($tiene && $iid !== $nuevo) {
                // Quitada a mano: la IA ya no la vuelve a poner en ese ítem
                Releases::vincularTareas($iid, array_values(array_diff(array_map('intval', (array)$it['tareas']), [$tareaId])));
                if (!$nuevo) $txt = ' Ya no está amarrada a ' . $it['codigo'] . '.';
            } elseif (!$tiene && $iid === $nuevo) {
                Releases::vincularTareas($iid, [$tareaId], -1, true);
                $txt = ' Amarrada a ' . $it['codigo'] . ' del release.';
            }
        }
        return $txt;
    } catch (Throwable $e) {
        return ' No se pudo amarrar al release: ' . $e->getMessage();
    }
}


/**
 * Teléfono: solo números y con forma de número real (MiembroRepo::telefonoValido:
 * celular o fijo de Ecuador, o de otro país con su código). '' si viene vacío
 * o no es válido.
 */
function telefonoEntrada(?string $v): string
{
    $v = trim((string)$v);
    return MiembroRepo::telefonoValido($v) ? $v : '';
}

/** Si escribieron un teléfono que no es válido, vuelve con el aviso (vacío sí se permite). */
function exigirTelefonoValido(?string $v, string $volver): void
{
    if (trim((string)$v) !== '' && telefonoEntrada($v) === '') {
        redirigir($volver, 'Ese teléfono no es válido. ' . MiembroRepo::TELEFONO_AYUDA, 'error');
    }
}

/** Ids de los administradores (para los avisos que también van a ellos). */
function idsAdmins(MiembroRepo $miembros): array
{
    return array_values(array_map(fn($m) => (int)$m['id'],
        array_filter($miembros->todos(), fn($m) => MiembroRepo::tieneAcceso($m, 'admin'))));
}

/**
 * Revisa si el proyecto acaba de completarse (100% y con tareas) y, si es
 * la primera vez, avisa al administrador. Si baja de 100%, reinicia el flag.
 */
/**
 * Asigna un requerimiento a una o varias personas, con las fechas del
 * encargo, y avisa por correo a cada una. Devuelve la coletilla para el flash
 * ('' si la lista quedó vacía, o sea que vuelve a «sin asignar»).
 */
/**
 * Fechas del encargo, ya normalizadas. Una entrega ANTES del inicio es un
 * error de dedo que no se ve hasta que alguien se queja del plazo, asi que se
 * corta aqui en vez de guardarlo.
 */
function fechasRequerimiento(array $post): array
{
    $ini = ProyectoRepo::fecha($post['fecha_inicio'] ?? '');
    $fin = ProyectoRepo::fecha($post['fecha_fin'] ?? '');
    if ($ini !== '' && $fin !== '' && $fin < $ini) {
        redirigir('requerimientos.php', 'La fecha de entrega no puede ser anterior a la de inicio.', 'error');
    }
    return ['fecha_inicio' => $ini, 'fecha_fin' => $fin];
}

function derivarRequerimiento(RequerimientoRepo $repo, int $reqId, array $ids, MiembroRepo $miembros, array $fechas = [], string $nota = ''): string
{
    $validos = [];
    foreach ($ids as $id) {
        if ($m = $miembros->buscar((int)$id)) $validos[(int)$m['id']] = $m;
    }
    $repo->asignar($reqId, array_keys($validos), $fechas);
    $req = $repo->buscar($reqId) ?? [];
    $repo->anotar($reqId, (int)(Auth::usuario()['id'] ?? 0), $validos
        ? 'Lo asignó a ' . implode(', ', array_map(fn($m) => $m['nombre'], $validos)) . (RequerimientoRepo::fechaFin($req) !== '' ? ' · entrega ' . date('d/m/Y', strtotime(RequerimientoRepo::fechaFin($req))) : '')
        : 'Lo dejó sin asignar.');
    if (!$validos) {
        return '';
    }
    avisoPanel(array_keys($validos), 'requerimiento', 'Te asignaron un requerimiento',
        '«' . ($req['titulo'] ?? '') . '»' . (!empty($req['fecha_fin']) ? ' · entrega ' . $req['fecha_fin'] : ''), 'bandeja.php');
    $avisados = 0;
    $fallo = '';
    foreach ($validos as $mid => $m) {
        // A cada quien se le dice con quien lo comparte: si no, dos personas
        // se ponen a hacer lo mismo sin saberlo.
        $otros = array_map(fn($o) => $o['nombre'], array_diff_key($validos, [$mid => true]));
        $r = Mailer::notificarRequerimiento($req, $m, array_values($otros), $nota);
        if ($r === true) $avisados++;
        elseif (is_string($r)) $fallo = $r;
    }
    $nombres = implode(', ', array_map(fn($m) => explode(' ', trim($m['nombre']))[0], $validos));
    $cuantos = count($validos);
    // Con varios destinatarios el aviso puede salir a medias: se dice cuántos
    $correo = match (true) {
        $avisados === $cuantos && $cuantos === 1 => ' Le avisamos por correo.',
        $avisados === $cuantos                   => ' Les avisamos por correo.',
        $avisados === 0 && $fallo === ''         => ' Avísales tú: el correo del panel no está configurado o no tienen correo registrado.',
        default => ' Avisados por correo: ' . $avisados . ' de ' . $cuantos
                 . ($fallo !== '' ? ' (' . $fallo . ')' : '') . '.',
    };
    return $nombres . '.' . $correo;
}

/**
 * La observación del paso «Observaciones» de los asistentes (Nuevo, Asignar,
 * Editar), ya saneada; '' si no se escribió nada.
 */
function reqObsAsistente(): string
{
    $html = HtmlRico::limpiar((string)($_POST['observacion'] ?? ''));
    return HtmlRico::vacio($html) ? '' : $html;
}

/**
 * La deja anotada en el requerimiento. $yaAvisados: los que ya la recibieron
 * dentro del correo de asignación; al resto de responsables (en Editar, los que
 * ya estaban) les llega como observación. Devuelve la coletilla.
 */
function reqObsGuardar(RequerimientoRepo $repo, int $reqId, string $html, MiembroRepo $miembros, array $yaAvisados): string
{
    if ($html === '') return '';
    $req = $repo->buscar($reqId);
    if (!$req) return '';
    $yo = (int)(Auth::usuario()['id'] ?? 0);
    $autor = $miembros->buscar($yo) ?? [];
    // Los que de verdad la recibieron en el correo de asignación (con correo y el envío configurado)
    $avisados = Mailer::listo() ? array_values(array_filter(array_map('intval', $yaAvisados), fn($id) => !empty($miembros->buscar($id)['email']))) : [];
    $yaAvisados = array_map('intval', $yaAvisados);
    foreach (RequerimientoRepo::asignadosDe($req) as $mid) {
        if ($mid === $yo || in_array($mid, $yaAvisados, true)) continue;
        $m = $miembros->buscar($mid);
        if (!$m) continue;
        avisoPanel([$mid], 'requerimiento', 'Observación en un requerimiento', '«' . ($req['titulo'] ?? '') . '» · ' . HtmlRico::texto($html, 100), 'bandeja.php');
        if (Mailer::observacionRequerimiento($req, $autor, $m, $html) === true) $avisados[] = $mid;
    }
    $repo->observar($reqId, $html, $yo, $avisados, 0, true);
    return ' Las observaciones quedaron anotadas.';
}

/**
 * La transcripción de una reunión de Zoom, leída de Drive (las grabaciones ya no
 * se piden a Zoom: lib/ZoomDrive.php las pasa solas). Con $fecha, la de ese día
 * de una reunión que se repite. ['estado' => 'ok'|'espera'|'vacio'|'error', 'texto', 'msg']:
 * mientras no llega a Drive, «Esperando, aún no se ha guardado un respaldo» y nada más.
 */
function transcripcionReunion(array $reu, string $fecha = ''): array
{
    if (($reu['plataforma'] ?? 'zoom') !== 'zoom' || empty($reu['zoom_id'])) {
        return ['estado' => 'vacio', 'texto' => '', 'msg' => 'Esta reunión no trae transcripción automática.'];
    }
    if (!class_exists('ZoomDrive') && is_file(__DIR__ . '/lib/ZoomDrive.php')) require_once __DIR__ . '/lib/ZoomDrive.php';
    if (!class_exists('ZoomDrive')) return ['estado' => 'error', 'texto' => '', 'msg' => 'Falta lib/ZoomDrive.php.'];
    $hora = substr((string)($reu['inicio'] ?? ''), 11, 5);
    $empieza = $fecha !== '' ? strtotime($fecha . ' ' . $hora) : (Reuniones::esRecurrente($reu) ? time() : strtotime((string)($reu['inicio'] ?? '')));
    $t = ZoomDrive::transcripcion((string)$reu['zoom_id'], $fecha, (int)$empieza + (int)($reu['duracion'] ?? 60) * 60);
    $t['msg'] = match ($t['estado']) {
        'espera' => 'Esperando, aún no se ha guardado un respaldo de la transcripción.',
        'vacio'  => 'Esta reunión no tiene transcripción.',
        default  => (string)($t['msg'] ?? ''),
    };
    return $t;
}

/**
 * Avisa por correo de que un requerimiento se terminó.
 *
 * Van dos avisos, cada uno por su lado:
 *  - a quien lo creó/asignó, que es el que espera la respuesta;
 *  - al correo del administrador de Ajustes → Correo, con el mismo
 *    interruptor que los proyectos completados, para que tenga la foto de
 *    todo lo que se cierra aunque el requerimiento no lo pidiera él.
 * Si son el mismo correo solo sale uno. Devuelve la coletilla para el flash.
 */
function notificarReqTerminado(array $req, array $quien, MiembroRepo $miembros, string $nota): string
{
    avisoPanel(array_merge([(int)($req['creado_por'] ?? 0)], idsAdmins($miembros)), 'requerimiento',
        'Terminaron un requerimiento', '«' . ($req['titulo'] ?? '') . '» · ' . ($quien['nombre'] ?? ''), 'requerimientos.php');
    $para    = '';
    $creador = $miembros->buscar((int)($req['creado_por'] ?? 0));
    if ($creador && !empty($creador['email'])) {
        $para = (string)$creador['email'];
    }
    if ($para === '') {
        $para = trim((string)(Mailer::config()['admin_email'] ?? ''));
    }

    $aQuienPidio = $para !== ''
        && Mailer::notificarRequerimientoHecho($req, $quien, $para, $nota) === true;
    $alAdmin = Mailer::avisarAdminRequerimientoHecho($req, $quien, $nota, $para) === true;
    // Quien da seguimiento (Felipe): lo terminado, con si llegó a tiempo (sin repetir a los de arriba)
    $alSeguimiento = class_exists('ReqSeguimiento')
        ? ReqSeguimiento::avisarTerminado($req, $quien, $nota, array_merge([$para], Mailer::listo() ? Mailer::destinatariosAdmin($para) : []))
        : 0;
    if ($alSeguimiento) return ' Avisamos por correo a quien lo asignó y a quien le da seguimiento.';

    if ($aQuienPidio && $alAdmin) return ' Avisamos por correo a quien lo asignó y al administrador.';
    if ($aQuienPidio)             return ' Le avisamos por correo a quien lo asignó.';
    if ($alAdmin)                 return ' Le avisamos por correo al administrador.';
    return '';
}

/**
 * Avisa al OTRO equipo cuando una tarea pasa a depender de una suya. Solo por
 * las dependencias NUEVAS (comparando antes/después) y solo las de otro equipo:
 * las del mismo tablero no generan correo. A cada dependencia externa se avisa a
 * sus responsables y al Scrum Master de su proyecto. Devuelve la coletilla flash.
 */
function notificarDepsExternas(array $tareaMia, array $depsAntes, array $depsDespues, ProyectoRepo $proyectos, MiembroRepo $miembros, TareaRepo $tareas): string
{
    $nuevas = array_diff(array_map('intval', $depsDespues), array_map('intval', $depsAntes));
    if (!$nuevas) {
        return '';
    }
    $conCorreo = Mailer::listo();
    $miProy = (int)($tareaMia['proyecto_id'] ?? 0);
    $pMio   = $proyectos->buscar($miProy);
    if (!$pMio) {
        return '';
    }
    $avisados = 0;
    foreach ($nuevas as $depId) {
        $dep = $tareas->buscar((int)$depId);
        if (!$dep || (int)$dep['proyecto_id'] === $miProy) continue;   // solo dependencias de otro equipo
        $pDep = $proyectos->buscar((int)$dep['proyecto_id']);
        if (!$pDep) continue;
        // Responsables de la dependencia + Scrum Master de su equipo.
        $ids = TareaRepo::asignadosDe($dep);
        $sm  = ProyectoRepo::scrumDe($pDep);
        if ($sm > 0) $ids[] = $sm;
        avisoPanel($ids, 'dependencia', 'Otro equipo depende de tu tarea',
            '«' . ($tareaMia['titulo'] ?? '') . '» (' . ($pMio['nombre'] ?? '') . ') espera «' . ($dep['titulo'] ?? '') . '»',
            'proyecto.php?id=' . (int)$dep['proyecto_id'] . '#vista-kanban');
        if (!$conCorreo) continue;
        foreach (array_values(array_unique($ids)) as $mid) {
            $m = $miembros->buscar((int)$mid);
            if ($m && Mailer::notificarDependencia($tareaMia, $pMio, $dep, $pDep, $m) === true) $avisados++;
        }
    }
    return $avisados ? ' Avisamos por correo al otro equipo (' . $avisados . ').' : '';
}

/**
 * Ids de todo el que participa en un proyecto: responsables de sus tareas, su
 * equipo definido y quien lo lleva (PO y Scrum Master). Para "avisar a todos".
 */
function participantesProyecto(int $pid, ProyectoRepo $proyectos, TareaRepo $tareas, MiembroRepo $miembros): array
{
    $ids = [];
    foreach ($tareas->delProyecto($pid) as $t) {
        foreach (TareaRepo::asignadosDe($t) as $mid) $ids[(int)$mid] = true;
    }
    if ($p = $proyectos->buscar($pid)) {
        foreach (ProyectoRepo::miembrosDe($p) ?? [] as $mid) $ids[(int)$mid] = true;
        foreach ([ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0), ProyectoRepo::scrumDe($p)] as $mid) if ($mid > 0) $ids[(int)$mid] = true;
    }
    return array_keys($ids);
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
    if ($nuevos) {
        avisoPanel($nuevos, 'proyecto', 'Te sumaron a un proyecto', (string)($proyecto['nombre'] ?? ''),
            'proyecto.php?id=' . (int)($proyecto['id'] ?? 0));
    }
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
 * Una reunión no se crea (ni se mueve) a una hora que ya pasó: así no se puede
 * registrar hoy la daily de ayer. Diez minutos de gracia por si se crea al empezar.
 */
function reunionNoPasada(string $inicio, string $volver): void
{
    $ts = strtotime($inicio);
    if ($ts !== false && $ts < time() - 600) {
        redirigir($volver, 'La reunión no puede empezar en una fecha u hora que ya pasó (' . date('d/m/Y H:i', $ts) . '). Elige desde ahora en adelante.', 'error');
    }
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
 * Product Owner válido: el id solo cuenta si es un miembro del equipo de
 * ANALISTAS (el PO se elige entre los analistas). Si no, 0 (sin PO).
 */
/** PO de relevo: un analista como el principal, y nunca la misma persona. */
function poRelevoValido(int $id, int $principal, MiembroRepo $miembros): int
{
    $id = poAnalistaValido($id, $miembros);
    return $id === $principal ? 0 : $id;
}

function poAnalistaValido(int $id, MiembroRepo $miembros): int
{
    if ($id <= 0) return 0;
    $m = $miembros->buscar($id);
    return ($m && MiembroRepo::equipoDe($m) === 'analistas') ? $id : 0;
}

/**
 * Scrum Master válido para un proyecto: hace falta llevar el perfil de Scrum
 * Master, o el de administrador — que manda sobre todo y por tanto también
 * puede llevar un tablero. Si no, 0 (el proyecto se queda sin SM y solo el
 * administrador toca su horario).
 *
 * Antes se exigía que el perfil ÚNICO fuera 'scrum', así que un administrador
 * no podía figurar como Scrum Master de su propio proyecto: para aparecer en
 * la lista tenía que dejar de ser administrador.
 */
function scrumValido(int $id, MiembroRepo $miembros): int
{
    if ($id <= 0) return 0;
    $m = $miembros->buscar($id);
    if (!$m) return 0;
    return (MiembroRepo::tieneAcceso($m, 'scrum') || MiembroRepo::tieneAcceso($m, 'admin')) ? $id : 0;
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
    avisoPanel($nuevos, 'tarea', 'Te asignaron una tarea',
        '«' . ($tarea['titulo'] ?? '') . '» · ' . ($p['nombre'] ?? '') . (!empty($tarea['fecha_limite']) ? ' · para el ' . $tarea['fecha_limite'] : ''),
        'proyecto.php?id=' . (int)$p['id'] . '#vista-kanban');
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
            $yoLogin = $miembros->buscar((int)($_SESSION['uid'] ?? 0)) ?? [];
            $nombre1 = explode(' ', (string)($yoLogin['nombre'] ?? ''))[0];
            // Primer ingreso de alguien recién aprobado: a Mi perfil a completar
            // sus datos (usuario de Git, correos, foto). Se limpia la marca.
            if (!empty($yoLogin['perfil_pendiente'])) {
                $miembros->actualizar((int)$yoLogin['id'], ['perfil_pendiente' => false]);
                redirigir('perfil.php', '¡Bienvenido, ' . $nombre1 . '! Completa tus datos (usuario de Git, correos y foto) para que se cuenten tus commits.');
            }
            redirigir(Auth::trasLogin(), '¡Bienvenido, ' . $nombre1 . '!');
        }
        // Si se registró y todavía no lo aprueban, decírselo: si no, parece
        // que su contraseña está mal y la vuelve a pedir una y otra vez.
        if ((new SolicitudRepo())->porLogin($_POST['usuario'] ?? '')) {
            redirigir('login.php', 'Tu solicitud de acceso sigue pendiente. Te avisaremos por correo en cuanto un administrador la apruebe.', 'info');
        }
        redirigir('login.php', 'Usuario o contraseña incorrectos.', 'error');

    /* ---------- Recuperar la contraseña con un código de 6 dígitos ----------
       El código llega por WhatsApp si está encendido y la persona tiene un
       teléfono válido; si no, por correo. Vence en 10 minutos, admite 5 intentos
       y se pueden pedir 5 por hora (uno por minuto). La respuesta es la misma
       exista o no la cuenta: así nadie averigua quién está registrado. */
    case 'auth_recuperar_pedir':
        $usuarioR = trim((string)($_POST['usuario'] ?? ''));
        if ($usuarioR === '') redirigir('login.php?recuperar=1', 'Escribe tu correo o tu usuario de Git.', 'error');
        $_SESSION['recup_pedidos'] = array_values(array_filter((array)($_SESSION['recup_pedidos'] ?? []), fn($t) => (int)$t > time() - 3600));
        if (count($_SESSION['recup_pedidos']) >= 8) {
            redirigir('login.php?recuperar=1', 'Pediste demasiados códigos. Espera un rato y vuelve a intentarlo.', 'error');
        }
        $_SESSION['recup_pedidos'][] = time();
        $mR = Auth::buscarPorUsuario($usuarioR);
        if ($mR) {
            $enviosR = array_values(array_filter((array)($mR['recup_envios'] ?? []), fn($t) => (int)$t > time() - 3600));
            // Menos de un minuto desde el último: sigue valiendo ese código
            if (count($enviosR) < 5 && time() - ($enviosR ? max($enviosR) : 0) >= 60) {
                $codigoR = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $enviosR[] = time();
                $miembros->actualizar((int)$mR['id'], [
                    'recup_hash'     => password_hash($codigoR, PASSWORD_DEFAULT),
                    'recup_vence'    => time() + 600,
                    'recup_intentos' => 0,
                    'recup_envios'   => $enviosR,
                ]);
                $porWaR = class_exists('Whatsapp') && Whatsapp::enviarCodigo($mR, $codigoR, 10);
                if (!$porWaR) Mailer::codigoRecuperacion($mR, $codigoR, 10);
            }
        } else {
            usleep(random_int(400000, 1200000));   // que no se note por el tiempo si la cuenta existe
        }
        $_SESSION['recup'] = ['mid' => $mR ? (int)$mR['id'] : 0, 't' => time()];
        $_SESSION['recup_fallos'] = 0;
        redirigir('login.php?recuperar=codigo', 'Si la cuenta existe, te llegó un código de 6 dígitos por WhatsApp o a tu correo. Vence en 10 minutos.', 'info');

    case 'auth_recuperar_cambiar':
        $recR = (array)($_SESSION['recup'] ?? []);
        if (!$recR || time() - (int)($recR['t'] ?? 0) > 1800) {
            unset($_SESSION['recup']);
            redirigir('login.php?recuperar=1', 'Pide un código para cambiar tu contraseña.', 'error');
        }
        $volverR = 'login.php?recuperar=codigo';
        $codigoIn = preg_replace('/\D/', '', (string)($_POST['codigo'] ?? ''));
        $claveN  = (string)($_POST['clave'] ?? '');
        $claveN2 = (string)($_POST['clave2'] ?? '');
        if (strlen($claveN) < 6) redirigir($volverR, 'La contraseña nueva debe tener al menos 6 caracteres.', 'error');
        if ($claveN !== $claveN2) redirigir($volverR, 'Las dos contraseñas no coinciden.', 'error');
        $mR = (int)($recR['mid'] ?? 0) > 0 ? $miembros->buscar((int)$recR['mid']) : null;
        $vigente = $mR && !empty($mR['recup_hash']) && (int)($mR['recup_vence'] ?? 0) >= time() && (int)($mR['recup_intentos'] ?? 0) < 5;
        if ($vigente && strlen($codigoIn) === 6 && password_verify($codigoIn, (string)$mR['recup_hash'])) {
            $miembros->actualizar((int)$mR['id'], ['pass_hash' => Auth::hash($claveN), 'recup_hash' => '', 'recup_vence' => 0, 'recup_intentos' => 0]);
            unset($_SESSION['recup'], $_SESSION['recup_pedidos'], $_SESSION['recup_fallos']);
            Auth::iniciarSesion((int)$mR['id']);
            redirigir(Auth::trasLogin(), 'Listo, cambiaste tu contraseña.');
        }
        if ($mR && !empty($mR['recup_hash'])) {
            $intR = (int)($mR['recup_intentos'] ?? 0) + 1;
            // Al quinto intento el código ya no sirve
            $miembros->actualizar((int)$mR['id'], $intR >= 5 ? ['recup_intentos' => $intR, 'recup_hash' => ''] : ['recup_intentos' => $intR]);
        }
        $_SESSION['recup_fallos'] = (int)($_SESSION['recup_fallos'] ?? 0) + 1;
        if ($_SESSION['recup_fallos'] >= 5) {
            unset($_SESSION['recup']);
            $_SESSION['recup_fallos'] = 0;
            redirigir('login.php?recuperar=1', 'Demasiados intentos con un código equivocado. Pide uno nuevo.', 'error');
        }
        redirigir($volverR, 'El código no es correcto o ya venció.', 'error');

    case 'solicitud_registrar':
        // Registro por CORREO: la persona deja su correo + datos + clave. No
        // entra: queda una solicitud que el administrador aprueba o rechaza.
        // El correo ES su usuario; luego podrá entrar con esa clave o con Google.
        $volver = 'registro.php';
        if (!(Auth::registro()['abierto'] && Auth::hayQuienApruebe())) {
            redirigir($volver, 'El registro de cuentas nuevas está cerrado ahora mismo.', 'error');
        }
        $nombreReg = trim($_POST['nombre'] ?? '');
        $emailReg  = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
        $claveReg  = (string)($_POST['clave'] ?? '');
        $clave2Reg = (string)($_POST['clave2'] ?? '');
        if ($nombreReg === '') {
            redirigir($volver, 'Escribe tu nombre y apellido.', 'error');
        }
        if ($emailReg === '') {
            redirigir($volver, 'Escribe un correo válido: ese será tu usuario para entrar.', 'error');
        }
        if (!Auth::dominioPermitido($emailReg)) {
            redirigir($volver, 'Solo se aceptan correos de: @' . implode(', @', Auth::dominiosPermitidos()) . '.', 'error');
        }
        if (strlen($claveReg) < 6) {
            redirigir($volver, 'La contraseña debe tener al menos 6 caracteres.', 'error');
        }
        if ($claveReg !== $clave2Reg) {
            redirigir($volver, 'Las dos contraseñas no coinciden.', 'error');
        }
        // El correo EXACTO no puede repetirse: ni de un colaborador ni de otra
        // solicitud. (Antes bastaba con algo "parecido"; ahora es el correo tal cual.)
        foreach ($miembros->todos() as $m) {
            if (strcasecmp((string)($m['email'] ?? ''), $emailReg) === 0) {
                redirigir($volver, 'Ese correo ya tiene una cuenta en el panel. Entra desde el login (o pídele al administrador que te ayude).', 'error');
            }
        }
        $solicitudesReg = new SolicitudRepo();
        if ($solicitudesReg->porEmail($emailReg)) {
            redirigir($volver, 'Ya hay una solicitud con ese correo esperando aprobación. Te avisaremos cuando la revisen.', 'info');
        }
        $solReg = $solicitudesReg->crear([
            'nombre'    => $nombreReg,
            'email'     => $emailReg,
            'pass_hash' => Auth::hash($claveReg),   // se guarda hasheada, nunca en claro
        ]);
        $avisadosReg = 0;
        if (Auth::registro()['avisar']) {
            foreach (Auth::correosAdmin() as $correoAdmin) {
                if (Mailer::solicitudNueva($solReg, $correoAdmin) === true) $avisadosReg++;
            }
        }
        redirigir('login.php',
            '¡Listo, ' . explode(' ', $nombreReg)[0] . '! Tu solicitud quedó registrada con ' . $emailReg
            . ($avisadosReg > 0 ? ' y ya avisamos al administrador.' : '. Un administrador la revisará.')
            . ' Cuando la aprueben, entra con tu correo y contraseña'
            . (GoogleLogin::listo() ? ' o con Google.' : '.'));

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
        $cambiosAprob = [
            'acceso' => Auth::accesoValido($_POST['acceso'] ?? ''),
            // Su primer ingreso lo lleva a Mi perfil para completar sus datos
            // (usuario de Git, correos, foto). Se limpia al entrar esa vez.
            'perfil_pendiente' => true,
        ];
        // Si se registró con correo y clave, se conserva su clave para que pueda
        // entrar con correo+contraseña (además de Google). Si se registró con
        // Google, no hay clave: entra con Google.
        if (!empty($s['pass_hash'])) {
            $cambiosAprob['pass_hash'] = $s['pass_hash'];
        }
        $miembros->actualizar((int)$nuevo['id'], $cambiosAprob);
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
        // Flujo RETIRADO: "reclamar" una ficha sin correo permitía asociar
        // cualquier correo de Google a una ficha ajena (entrar como otra persona).
        // El acceso es por el correo exacto; si no calza, se pide acceso o el
        // admin pone el correo en la ficha.
        unset($_SESSION['identificar']);
        redirigir('login.php', 'Entra con el correo que ya está registrado en tu ficha, o pide acceso desde «Crear una cuenta».', 'error');

    /* ---------- Campana ---------- */
    case 'notif_leer_todas':
        $nLeidas = class_exists('Notif') ? Notif::leerTodas((int)(Auth::usuario()['id'] ?? 0)) : 0;
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'leidas' => $nLeidas]);
            exit;
        }
        redirigir('notificaciones.php', $nLeidas ? 'Listo: todo marcado como leído.' : 'No tenías notificaciones sin leer.', 'info');

    /* ---------- Pedidos entre equipos ---------- */
    case 'pedido_crear':
        if (!class_exists('PedidoRepo')) redirigir(paginaOrigen(), 'Falta subir lib/Pedidos.php al servidor.', 'error');
        if (Auth::esSupervisor()) redirigir(paginaOrigen(), 'Un supervisor solo observa: no hace pedidos.', 'error');
        $yoPed = Auth::usuario();
        $equiposPed = Catalogo::equipos();
        $paraEquipo = (string)($_POST['para_equipo'] ?? '');
        if (!isset($equiposPed[$paraEquipo])) redirigir(paginaOrigen(), 'Elige a qué equipo va el pedido.', 'error');
        $tituloPed = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 140);
        if ($tituloPed === '') redirigir(paginaOrigen(), 'Escribe qué necesitas (el título del pedido).', 'error');
        // La persona es opcional, pero si viene debe ser de ese equipo
        $paraIdPed = (int)($_POST['para_id'] ?? 0);
        if ($paraIdPed > 0) {
            $mPed = $miembros->buscar($paraIdPed);
            if (!$mPed || MiembroRepo::equipoDe($mPed) !== $paraEquipo) $paraIdPed = 0;
        }
        $pidPed = (int)($_POST['proyecto_id'] ?? 0);
        if ($pidPed > 0 && (!$proyectos->buscar($pidPed) || !puedeVerProyecto($pidPed))) $pidPed = 0;
        $pedRepo = new PedidoRepo();
        $ped = $pedRepo->crear([
            'de_id'        => (int)$yoPed['id'],
            'de_equipo'    => MiembroRepo::equipoDe($yoPed),
            'para_equipo'  => $paraEquipo,
            'para_id'      => $paraIdPed,
            'proyecto_id'  => $pidPed,
            'titulo'       => $tituloPed,
            'detalle'      => mb_substr(str_replace("\r", '', trim((string)($_POST['detalle'] ?? ''))), 0, 4000),
            'prioridad'    => Catalogo::prioridadValida($_POST['prioridad'] ?? ''),
            'fecha_limite' => ProyectoRepo::fecha($_POST['fecha_limite'] ?? ''),
        ]);
        $paraPed = PedidoRepo::destinatarios($ped, $miembros);
        avisoPanel($paraPed, 'pedido', $paraIdPed ? $yoPed['nombre'] . ' te pidió algo' : $yoPed['nombre'] . ' le pidió algo a ' . $equiposPed[$paraEquipo][0],
            '«' . $tituloPed . '»' . ($ped['fecha_limite'] ? ' · para el ' . $ped['fecha_limite'] : ''), 'pedidos.php#p-' . (int)$ped['id']);
        $nCorreoPed = 0;
        if (method_exists('Mailer', 'notificarPedido') && Mailer::listo()) {
            foreach ($paraPed as $midPed) {
                $mPed = $miembros->buscar($midPed);
                if ($mPed && Mailer::notificarPedido($ped, $yoPed, $mPed, 'nuevo') === true) $nCorreoPed++;
            }
        }
        redirigir('pedidos.php#p-' . (int)$ped['id'], 'Pedido enviado a ' . ($paraIdPed ? ($miembros->buscar($paraIdPed)['nombre'] ?? '') : $equiposPed[$paraEquipo][0]) . '.'
            . ($nCorreoPed ? ' También le avisamos por correo (' . $nCorreoPed . ').' : ''));

    case 'pedido_responder':
    case 'pedido_estado':
        if (!class_exists('PedidoRepo')) redirigir(paginaOrigen(), 'Falta subir lib/Pedidos.php al servidor.', 'error');
        if (Auth::esSupervisor()) redirigir('pedidos.php', 'Un supervisor solo observa los pedidos.', 'error');
        $pedRepo = new PedidoRepo();
        $yoPed = Auth::usuario();
        $miPed = (int)$yoPed['id'];
        $ped = $pedRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$ped || !PedidoRepo::puedeVer($ped, $yoPed)) redirigir('pedidos.php', 'Ese pedido no existe o no es tuyo.', 'error');
        $volverPed = 'pedidos.php#p-' . (int)$ped['id'];
        $esQuienPide = (int)$ped['de_id'] === $miPed;
        $atiende = PedidoRepo::esParaMi($ped, $yoPed) || Auth::esAdmin();
        $notaPed = mb_substr(str_replace("\r", '', trim((string)($_POST['texto'] ?? ''))), 0, 2000);
        // El otro lado: si escribe quien pidió, el equipo (o quien lo tomó); si no, quien pidió
        $otroLado = $esQuienPide
            ? ((int)($ped['tomado_por'] ?? 0) > 0 ? [(int)$ped['tomado_por']] : PedidoRepo::destinatarios($ped, $miembros))
            : [(int)$ped['de_id']];

        if ($accion === 'pedido_responder') {
            if ($notaPed === '') redirigir($volverPed, 'Escribe la respuesta.', 'error');
            $pedRepo->responder((int)$ped['id'], $miPed, $notaPed);
            avisoPanel($otroLado, 'pedido', $yoPed['nombre'] . ' respondió en un pedido', '«' . $ped['titulo'] . '» · ' . $notaPed, $volverPed);
            if (method_exists('Mailer', 'notificarPedido') && Mailer::listo()) {
                foreach ($otroLado as $midPed) {
                    if ($mPed = $miembros->buscar($midPed)) Mailer::notificarPedido($pedRepo->buscar((int)$ped['id']) ?? $ped, $yoPed, $mPed, 'respuesta', $notaPed);
                }
            }
            redirigir($volverPed, 'Respuesta enviada.');
        }

        $op = (string)($_POST['op'] ?? '');
        $abiertoPed = PedidoRepo::abierto($ped);
        $cambio = match (true) {
            $op === 'tomar'    && $atiende && ($ped['estado'] ?? '') === 'abierto' => ['estado' => 'en_curso', 'tomado_por' => $miPed],
            $op === 'hecho'    && $atiende && $abiertoPed => ['estado' => 'hecho', 'tomado_por' => (int)($ped['tomado_por'] ?? 0) ?: $miPed, 'resuelto_en' => date('Y-m-d H:i')],
            $op === 'rechazar' && $atiende && $abiertoPed => ['estado' => 'rechazado', 'resuelto_en' => date('Y-m-d H:i')],
            $op === 'cancelar' && $esQuienPide && $abiertoPed => ['estado' => 'cancelado', 'resuelto_en' => date('Y-m-d H:i')],
            $op === 'reabrir'  && $esQuienPide && !$abiertoPed => ['estado' => 'abierto', 'tomado_por' => 0, 'resuelto_en' => ''],
            default => null,
        };
        if (!$cambio) redirigir($volverPed, 'Eso no se puede hacer con este pedido ahora.', 'error');
        $pedRepo->actualizar((int)$ped['id'], $cambio);
        if ($notaPed !== '') $pedRepo->responder((int)$ped['id'], $miPed, $notaPed);
        $rotulosPed = ['tomar' => 'tomó tu pedido', 'hecho' => 'terminó tu pedido', 'rechazar' => 'rechazó tu pedido',
                       'cancelar' => 'canceló su pedido', 'reabrir' => 'reabrió su pedido'];
        avisoPanel($otroLado, 'pedido', $yoPed['nombre'] . ' ' . $rotulosPed[$op],
            '«' . $ped['titulo'] . '»' . ($notaPed !== '' ? ' · ' . $notaPed : ''), $volverPed);
        if (method_exists('Mailer', 'notificarPedido') && Mailer::listo() && in_array($op, ['hecho', 'rechazar'], true)) {
            if ($mPed = $miembros->buscar((int)$ped['de_id'])) Mailer::notificarPedido($pedRepo->buscar((int)$ped['id']) ?? $ped, $yoPed, $mPed, $op, $notaPed);
        }
        $flashPed = ['tomar' => 'Lo tomaste: quien lo pidió ya sabe que estás en eso.', 'hecho' => 'Pedido marcado como hecho.',
                     'rechazar' => 'Pedido rechazado.', 'cancelar' => 'Pedido cancelado.', 'reabrir' => 'Pedido reabierto.'];
        redirigir($volverPed, $flashPed[$op]);

    case 'perfil_telefono':
        $yoTel = Auth::usuario();
        $tel = telefonoEntrada($_POST['telefono'] ?? '');
        if ($tel === '') redirigir(paginaOrigen(), 'Ese teléfono no es válido. ' . MiembroRepo::TELEFONO_AYUDA, 'error');
        $miembros->actualizar((int)$yoTel['id'], ['telefono' => $tel]);
        redirigir(paginaOrigen(), 'Listo, guardamos tu teléfono.');

    /* ---------- Tutoriales en video (lib/Tutoriales.php): solo administrador ---------- */
    case 'tutorial_visto':
        header('Content-Type: application/json; charset=utf-8');
        if (class_exists('Tutoriales')) {
            Tutoriales::marcarVisto((int)($_POST['id'] ?? 0), (int)(Auth::usuario()['id'] ?? 0), (string)($_POST['como'] ?? 'cerrado'));
        }
        exit('{"ok":true}');

    case 'tutorial_crear':
    case 'tutorial_quitar':
    case 'tutorial_avisar':
        if (!class_exists('Tutoriales')) redirigir('index.php', 'Los tutoriales no están disponibles en este servidor.', 'error');
        if ($accion === 'tutorial_avisar') {
            $ta = Tutoriales::avisar((int)($_POST['id'] ?? 0), !empty($_POST['avisar']));
            redirigir('tutoriales.php', !$ta ? 'Ese tutorial ya no estaba.'
                : (!empty($ta['avisar']) ? 'Listo: a cada persona le sale «' . $ta['titulo'] . '» al entrar al panel.' : 'Ya no se avisa de «' . $ta['titulo'] . '».'));
        }
        if ($accion === 'tutorial_quitar') {
            $tq = Tutoriales::quitar((int)($_POST['id'] ?? 0));
            redirigir('tutoriales.php', $tq ? 'Quitamos «' . $tq['titulo'] . '» del panel.' : 'Ese tutorial ya no estaba.');
        }
        $tc = Tutoriales::crear($_POST, (int)(Auth::usuario()['id'] ?? 0));
        if (is_string($tc)) redirigir('tutoriales.php', $tc, 'error');
        redirigir('tutoriales.php', 'Publicamos «' . $tc['titulo'] . '».' . (!empty($tc['avisar']) ? ' A cada persona le sale en una ventana al entrar al panel.' : ''));

    /* ---------- Notificaciones nativas (lib/Push.php) ---------- */
    case 'push_suscribir':
    case 'push_quitar':
    case 'push_prefs':
    case 'push_prueba':
        $yoP   = Auth::usuario();          // siempre quien entró, nunca el «ver como»
        $midP  = (int)$yoP['id'];
        $uaP   = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $volverP = 'notificaciones.php?tab=dispositivos';
        $listoP = class_exists('Push') && Push::activo();
        if ($accion === 'push_suscribir') {
            // Lo llama assets/ds.js (o el service worker): responde JSON
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            if (!$listoP) exit(json_encode(['ok' => false, 'error' => 'Los avisos nativos no están disponibles en este servidor.'], JSON_UNESCAPED_UNICODE));
            if (!empty($_POST['anterior'])) Push::quitarEndpoint($midP, (string)$_POST['anterior']);
            $errP = Push::suscribir($midP, (string)($_POST['sub'] ?? ''), $uaP);
            exit(json_encode($errP === '' ? ['ok' => true, 'equipo' => Push::equipo($uaP)] : ['ok' => false, 'error' => $errP], JSON_UNESCAPED_UNICODE));
        }
        if (!$listoP) redirigir($volverP, 'Los avisos nativos no están disponibles en este servidor.', 'error');
        if ($accion === 'push_quitar') {
            Push::quitar($midP, (int)($_POST['id'] ?? 0));
            redirigir($volverP, 'Ese dispositivo ya no recibirá avisos.');
        }
        if ($accion === 'push_prefs') {
            Push::guardarPrefs($midP, ['correo' => !empty($_POST['correo']), 'avisos' => !empty($_POST['avisos'])]);
            redirigir($volverP, 'Guardamos qué avisos te llegan.');
        }
        $nP = Push::enviar([$midP], [
            'titulo' => 'Así te llegan los avisos',
            'texto'  => 'Aviso de prueba de InnoTech Hub: si lo ves, este dispositivo está listo.',
            'url'    => $volverP,
            'tag'    => 'prueba',
        ], 'prueba');
        redirigir($volverP, $nP ? 'Enviamos un aviso de prueba a ' . $nP . ($nP === 1 ? ' dispositivo.' : ' dispositivos.')
                                : 'No llegó a ningún dispositivo: primero actívalo en el equipo donde quieras recibirlo.', $nP ? 'success' : 'error');

    /* ---------- Correo InnoTech (lib/Correo.php) ---------- */
    case 'correo_respuesta_ia':
        // La respuesta que armó la IA (assets/ia.js) va al formulario de responder, con la cita debajo
        if (!Auth::esAdmin()) redirigir('notificaciones.php', 'Responder con IA es solo para administradores.', 'error');
        $cRi = (string)($_POST['c'] ?? '');
        $uidRi = (int)($_POST['uid'] ?? 0);
        $_SESSION['correo_respuesta_ia'] = ['c' => $cRi, 'uid' => $uidRi, 'texto' => mb_substr(str_replace("\r\n", "\n", (string)($_POST['texto'] ?? '')), 0, 20000)];
        $qRi = http_build_query(['redactar' => 1, 'c' => $cRi, 'responder' => $uidRi]);
        redirigir(str_ends_with((string)parse_url(paginaOrigen(), PHP_URL_PATH), '/correo.php') ? 'correo.php?' . $qRi : 'notificaciones.php?tab=correo&' . $qRi);

    case 'correo_conectar':
    case 'correo_desconectar':
    case 'correo_enviar':
    case 'correo_marcar':
    case 'correo_marcar_varios':
    case 'correo_leidos':
    case 'correo_eliminar':
    case 'correo_conf':
        if (!class_exists('Correo')) redirigir('notificaciones.php', 'El correo dentro del panel no está instalado.', 'error');
        @set_time_limit(120);   // enviar con adjuntos puede tardar
        $yoC   = Auth::usuario();          // siempre quien entró, nunca el «ver como»
        $midC  = (int)$yoC['id'];
        $cC    = (string)($_POST['c'] ?? '');
        // Desde la app del correo (correo.php, instalada en el celular) se vuelve a ella, no al panel
        $enAppC = str_ends_with((string)parse_url(paginaOrigen(), PHP_URL_PATH), '/correo.php');
        $urlC  = fn(array $q = []) => $enAppC
            ? 'correo.php' . (($qC = array_filter($q, fn($v) => $v !== '' && $v !== 0)) ? '?' . http_build_query($qC) : '')
            : 'notificaciones.php?' . http_build_query(['tab' => 'correo'] + array_filter($q, fn($v) => $v !== '' && $v !== 0));
        if ($accion === 'correo_conf') {
            $hostOk = fn($h) => preg_match('/^[a-z0-9.-]{3,120}$/i', trim((string)$h)) ? strtolower(trim((string)$h)) : '';
            $imapH = $hostOk($_POST['imap_host'] ?? '');
            $smtpH = $hostOk($_POST['smtp_host'] ?? '');
            if ($imapH === '' || $smtpH === '') redirigir($urlC(), 'Revisa los servidores: solo letras, números, puntos y guiones.', 'error');
            $doms = implode(', ', array_filter(array_map(fn($d) => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) ? $d : '',
                preg_split('/[\s,;]+/', strtolower(str_replace('@', '', (string)($_POST['dominio'] ?? '')))))));
            Config::guardar(['correo_buzon' => [
                'activo'        => !empty($_POST['activo']),
                'imap_host'     => $imapH,
                'imap_puerto'   => max(1, min(65535, (int)($_POST['imap_puerto'] ?? 993))),
                'smtp_host'     => $smtpH,
                'smtp_puerto'   => max(1, min(65535, (int)($_POST['smtp_puerto'] ?? 465))),
                'dominio'       => $doms,
                'verificar_ssl' => !empty($_POST['verificar_ssl']),
            ]]);
            unset($_SESSION['correo_carpetas']);
            redirigir($urlC(), 'Guardamos el servidor de correo.');
        }
        if ($accion === 'correo_conectar') {
            $errC = Correo::sincronizar($midC, (string)($_POST['email'] ?? ''), (string)($_POST['clave'] ?? ''));
            if ($errC !== '') redirigir($urlC(), $errC, 'error');
            redirigir($urlC(), '¡Listo! Tu correo quedó sincronizado.');
        }
        if ($accion === 'correo_desconectar') {
            Correo::desconectar($midC);
            $nCp = class_exists('CorreoCopias') ? CorreoCopias::borrarTodo($midC) : 0;
            redirigir($urlC(), 'Desconectamos tu correo y borramos la contraseña guardada' . ($nCp ? ' y los ' . $nCp . ' correos que el panel guardó' : '') . '. Tu buzón sigue igual.');
        }
        if (!Correo::conectado($midC)) redirigir($urlC(), 'Primero sincroniza tu correo.', 'error');
        try {
            // «Marcar todos como leídos»: lo no leído de la carpeta, en el servidor y en lo guardado del panel
            if ($accion === 'correo_leidos') {
                $imapC  = Correo::abrir($midC);
                $carpC  = Correo::carpetaValida(Correo::carpetas($imapC, $midC), $cC);
                $imapC->abrir($carpC['nombre'], true);
                $sinLeerC = $imapC->buscar('', true);
                $imapC->marcarVarios($sinLeerC, '\\Seen');
                $nC = count($sinLeerC) + (class_exists('CorreoCopias') ? CorreoCopias::marcarCarpeta($midC, $carpC['nombre']) : 0);
                if ($carpC['tipo'] === 'inbox') Correo::anotarNoLeidos($midC, 0);
                $imapC->cerrar();
                redirigir($urlC(['c' => $carpC['nombre']]), $nC ? 'Listo: todo ' . $carpC['etiqueta'] . ' está leído.' : 'No había correos sin leer.', $nC ? 'success' : 'info');
            }
            // Los elegidos con su casilla: leídos o no leídos, todos de una
            if ($accion === 'correo_marcar_varios') {
                $volverVC = ['p' => max(0, (int)($_POST['p'] ?? 0)), 'q' => mb_substr(trim((string)($_POST['q'] ?? '')), 0, 100)];
                // Una casilla puede traer una conversación entera («12,15,18»)
                $uidsC = array_slice(array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', implode(',', array_map('strval', (array)($_POST['uids'] ?? []))))), fn($u) => $u > 0))), 0, 500);
                if (!$uidsC) redirigir($urlC(['c' => $cC] + $volverVC), 'Elige primero los correos con su casilla.', 'info');
                $leidoC = !empty($_POST['leido']);
                $imapC  = Correo::abrir($midC);
                $carpC  = Correo::carpetaValida(Correo::carpetas($imapC, $midC), $cC);
                $imapC->abrir($carpC['nombre'], true);
                $imapC->marcarVarios($uidsC, '\\Seen', $leidoC);
                if (class_exists('CorreoCopias')) foreach ($uidsC as $uC) CorreoCopias::marcar($midC, $carpC['nombre'], $uC, $leidoC);
                if ($carpC['tipo'] === 'inbox') {   // el número de la campana y del menú, al día
                    Correo::anotarNoLeidos($midC, class_exists('CorreoCopias') ? CorreoCopias::noLeidos($imapC, $midC, 'INBOX') : (int)$imapC->estado('INBOX')['no_leidos']);
                }
                $imapC->cerrar();
                $nVC = count($uidsC);
                redirigir($urlC(['c' => $carpC['nombre']] + $volverVC),
                    'Listo: ' . $nVC . ($nVC === 1 ? ' correo marcado como ' : ' correos marcados como ') . ($leidoC ? 'leído' : 'no leído') . ($nVC === 1 ? '.' : 's.'));
            }
            if ($accion === 'correo_marcar' || $accion === 'correo_eliminar') {
                $uidC   = (int)($_POST['uid'] ?? 0);
                $imapC  = Correo::abrir($midC);
                $carpsC = Correo::carpetas($imapC, $midC);
                $carpC  = Correo::carpetaValida($carpsC, $cC);
                $imapC->abrir($carpC['nombre'], true);
                $copiasC = class_exists('CorreoCopias');
                if ($accion === 'correo_marcar') {
                    $leidoC = !empty($_POST['leido']);
                    $imapC->marcar($uidC, '\\Seen', $leidoC);
                    if ($copiasC) CorreoCopias::marcar($midC, $carpC['nombre'], $uidC, $leidoC);
                    $imapC->cerrar();
                    redirigir($urlC(['c' => $carpC['nombre'], 'p' => max(0, (int)($_POST['p'] ?? 0)), 'q' => mb_substr(trim((string)($_POST['q'] ?? '')), 0, 100)]),
                        $leidoC ? 'Marcado como leído.' : 'Lo marcamos como no leído.');
                }
                // Lo que se borra en el panel se va también de lo guardado (si no, volvería a salir en Recibidos)
                $quitadoC = $copiasC && CorreoCopias::quitar($midC, $carpC['nombre'], $uidC);
                $papeleraC = Correo::carpetaDeTipo($carpsC, 'trash');
                if (!$imapC->traer([$uidC], 'UID')) {
                    // Ya no estaba en el servidor (solo en lo guardado del panel)
                    $imapC->cerrar();
                    redirigir($urlC(['c' => $carpC['nombre']]), $quitadoC ? 'Lo quitamos de Recibidos.' : 'Ese correo ya no estaba.');
                }
                if ($carpC['tipo'] === 'trash' || $papeleraC === null) {
                    $imapC->borrar($uidC);
                    $msgC = 'Correo borrado.';
                } else {
                    $imapC->mover($uidC, $papeleraC);
                    $msgC = 'Lo movimos a la papelera.';
                }
                $imapC->cerrar();
                redirigir($urlC(['c' => $carpC['nombre']]), $msgC);
            }

            // correo_enviar. Si algo falla, lo escrito vuelve al formulario.
            $_SESSION['correo_borrador'] = [
                'para' => (string)($_POST['para'] ?? ''), 'cc' => (string)($_POST['cc'] ?? ''), 'cco' => (string)($_POST['cco'] ?? ''),
                'asunto' => (string)($_POST['asunto'] ?? ''), 'texto' => (string)($_POST['texto'] ?? ''),
                'html' => HtmlRico::limpiar((string)($_POST['html'] ?? '')),
                'en_respuesta_a' => (string)($_POST['en_respuesta_a'] ?? ''), 'referencias' => (string)($_POST['referencias'] ?? ''),
                'reenviar_uid' => (int)($_POST['reenviar_uid'] ?? 0),
                'prioridad' => (int)($_POST['prioridad'] ?? 3), 'acuse' => !empty($_POST['acuse']), 'dsn' => !empty($_POST['dsn']),
                'guardar_en' => (string)($_POST['guardar_en'] ?? ''),
            ];
            $adjC = [];
            $totalC = 0;
            if (!empty($_FILES['adjuntos']['name']) && is_array($_FILES['adjuntos']['name'])) {
                foreach ($_FILES['adjuntos']['name'] as $k => $nomC) {
                    $errU = (int)($_FILES['adjuntos']['error'][$k] ?? UPLOAD_ERR_NO_FILE);
                    if ($errU === UPLOAD_ERR_NO_FILE) continue;
                    if ($errU !== UPLOAD_ERR_OK) {
                        throw new CorreoError('El adjunto «' . $nomC . '» no se pudo subir: quizá pesa más de lo que deja el servidor (' . ini_get('upload_max_filesize') . ').', 'uso');
                    }
                    $tmpC = (string)$_FILES['adjuntos']['tmp_name'][$k];
                    if (!is_uploaded_file($tmpC)) continue;
                    $totalC += (int)filesize($tmpC);
                    if ($totalC > Correo::TOPE_ADJUNTOS) throw new CorreoError('Los adjuntos pasan de 20 MB en total.', 'uso');
                    $tipoC = 'application/octet-stream';
                    if (class_exists('finfo')) $tipoC = (string)((new finfo(FILEINFO_MIME_TYPE))->file($tmpC) ?: $tipoC);
                    $adjC[] = ['nombre' => (string)$nomC, 'tipo' => $tipoC, 'datos' => (string)file_get_contents($tmpC)];
                }
            }
            // Reenviar: van también los adjuntos del correo original
            $reUid = (int)($_POST['reenviar_uid'] ?? 0);
            if ($reUid > 0) {
                $imapC = Correo::abrir($midC);
                $carpC = Correo::carpetaValida(Correo::carpetas($imapC, $midC), $cC);
                $imapC->abrir($carpC['nombre']);
                $origC = $imapC->crudo($reUid);
                $imapC->cerrar();
                // Si ya no está en el servidor, los adjuntos salen de lo que guardó el panel
                $adjsOrigC = $origC !== '' ? CorreoMime::analizar($origC)['adjuntos']
                    : (class_exists('CorreoCopias') ? CorreoCopias::adjuntos($midC, $carpC['nombre'], $reUid) : []);
                if ($adjsOrigC) {
                    foreach ($adjsOrigC as $a) {
                        if ($a['inline'] && $a['cid'] !== '') continue;
                        $totalC += (int)$a['tam'];
                        if ($totalC > Correo::TOPE_ADJUNTOS) throw new CorreoError('Con los adjuntos del original se pasa de 20 MB.', 'uso');
                        $adjC[] = ['nombre' => $a['nombre'], 'tipo' => $a['tipo'], 'datos' => $a['datos']];
                    }
                }
            }
            $envC = Correo::enviar($midC, trim((string)$yoC['nombre']), [
                'para' => $_POST['para'] ?? '', 'cc' => $_POST['cc'] ?? '', 'cco' => $_POST['cco'] ?? '',
                'asunto' => $_POST['asunto'] ?? '', 'texto' => $_POST['texto'] ?? '', 'html' => (string)($_POST['html'] ?? ''),
                'en_respuesta_a' => $_POST['en_respuesta_a'] ?? '', 'referencias' => $_POST['referencias'] ?? '',
                'adjuntos' => $adjC,
                // Opciones de envío (como en Roundcube): prioridad, confirmación de lectura, aviso de entrega y dónde guardar la copia
                'prioridad' => (int)($_POST['prioridad'] ?? 3), 'acuse' => !empty($_POST['acuse']), 'dsn' => !empty($_POST['dsn']),
                'guardar_en' => (string)($_POST['guardar_en'] ?? ''),
            ]);
            unset($_SESSION['correo_borrador']);
            $okC = 'Correo enviado.';
            if (is_array($envC) && ($envC['dsn'] ?? null) === true) $okC .= ' Te llegará un aviso cuando se entregue.';
            if (is_array($envC) && ($envC['dsn'] ?? null) === false) $okC .= ' Tu servidor de correo no admite avisos de entrega: salió sin ese aviso.';
            redirigir($urlC(['c' => $cC]), $okC);
        } catch (CorreoError $e) {
            if ($e->tipo === 'auth') {
                unset($_SESSION['correo_borrador']);
                redirigir($urlC(), $e->getMessage(), 'error');
            }
            // Si falló al enviar, de vuelta al formulario con lo escrito
            redirigir($accion === 'correo_enviar' ? $urlC(['redactar' => 1, 'c' => $cC]) : paginaOrigen(), $e->getMessage(), 'error');
        }

    case 'auth_logout':
        Auth::salir();
        redirigir('login.php', 'Sesión cerrada.');

    /* ---------- Proyectos ---------- */

    case 'proyecto_crear':
        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir('index.php', 'El nombre del proyecto es obligatorio.', 'error');
        }
        $_POST['po']    = poAnalistaValido((int)($_POST['po'] ?? 0), $miembros);
        $_POST['scrum'] = scrumValido((int)($_POST['scrum'] ?? 0), $miembros);
        $p = $proyectos->crear($_POST);
        // Portada ilustrada ('' = automática según el nombre) y PO de relevo
        $extraCrear = ['po_relevo' => poRelevoValido((int)($_POST['po_relevo'] ?? 0), (int)$_POST['po'], $miembros)];
        if (isset($_POST['portada'])) $extraCrear['portada'] = UI::portadaEntrada($_POST['portada']);
        $proyectos->actualizar((int)$p['id'], $extraCrear);
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

    /* ---------- Horario de reuniones fijas (las "dailies") ---------- */
    // Lo escribe el Scrum Master de cada proyecto (y el admin en todos). No
    // crea nada en Zoom ni en el calendario: es solo el cuadro de horarios.

    case 'rfija_crear':
        $pidFija = (int)($_POST['proyecto_id'] ?? 0);
        if (!puedeHorarioDelProyecto($pidFija)) {
            redirigir('index.php', 'Solo quien lleva ese proyecto (su Scrum Master o su Product Owner) pone su horario.', 'error');
        }
        if (ProyectoRepo::hora($_POST['hora'] ?? '') === '') {
            redirigir('index.php', 'Pon la hora de la reunión.', 'error');
        }
        (new ReunionFijaRepo())->crear($_POST + ['creador_id' => (int)(Auth::usuario()['id'] ?? 0)]);
        redirigir('index.php', 'Reunión añadida al horario.');

    case 'rfija_editar':
        $fijas = new ReunionFijaRepo();
        $rf = $fijas->buscar((int)($_POST['id'] ?? 0));
        if (!$rf) {
            redirigir('index.php', 'Esa reunión ya no existe.', 'error');
        }
        // Puede quien manda en el proyecto de ANTES y en el de después: si no,
        // se podría mover una reunión ajena a un proyecto propio, o al revés.
        if (!puedeHorarioDelProyecto((int)$rf['proyecto_id']) || !puedeHorarioDelProyecto((int)($_POST['proyecto_id'] ?? 0))) {
            redirigir('index.php', 'Esa reunión no es de un proyecto tuyo.', 'error');
        }
        if (ProyectoRepo::hora($_POST['hora'] ?? '') === '') {
            redirigir('index.php', 'Pon la hora de la reunión.', 'error');
        }
        $fijas->actualizar((int)$rf['id'], $_POST);
        redirigir('index.php', 'Horario actualizado.');

    case 'rfija_eliminar':
        $fijas = new ReunionFijaRepo();
        $rf = $fijas->buscar((int)($_POST['id'] ?? 0));
        if (!$rf) {
            redirigir('index.php', 'Esa reunión ya no existe.', 'error');
        }
        if (!puedeHorarioDelProyecto((int)$rf['proyecto_id'])) {
            redirigir('index.php', 'Esa reunión no es de un proyecto tuyo.', 'error');
        }
        $fijas->eliminar((int)$rf['id']);
        redirigir('index.php', 'Reunión quitada del horario.');

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
            'icono'         => $_POST['icono'] ?? 'FolderOpen',
            'portada'       => UI::portadaEntrada($_POST['portada'] ?? ''),
            'color'         => Catalogo::colorEntrada($_POST),
            'fecha_inicio'  => ProyectoRepo::fecha($_POST['fecha_inicio'] ?? ''),
            'miembros'      => ProyectoRepo::miembrosEntrada($_POST['miembros'] ?? []),
            'po'            => ($poEd = poAnalistaValido((int)($_POST['po'] ?? 0), $miembros)),
            'po_relevo'     => poRelevoValido((int)($_POST['po_relevo'] ?? 0), $poEd, $miembros),
            'scrum'         => scrumValido((int)($_POST['scrum'] ?? 0), $miembros),
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
        // El backlog lo arman el Product Owner y el Scrum Master del proyecto (y
        // el admin). Los demás participantes ejecutan, no crean tareas.
        if (!puedeGestionarTareas($pid)) {
            redirigir('proyecto.php?id=' . $pid, 'Solo el Product Owner o el Scrum Master pueden crear tareas.', 'error');
        }
        if (trim($_POST['titulo'] ?? '') === '') {
            redirigir('proyecto.php?id=' . $pid, 'El título de la tarea es obligatorio.', 'error');
        }
        fechasTarea($_POST, 'proyecto.php?id=' . $pid);   // corta si el inicio va después del límite
        // Documentos de respaldo: van con la tarea desde que se asigna
        $rechazados = [];
        $datosTarea = $_POST;
        $datosTarea['origen'] = '';   // a mano; el autor lo pone el modelo con la sesión
        // La descripción es texto enriquecido (se puede pegar tablas): se sanea
        // a lista blanca antes de guardar para que no entre HTML peligroso.
        $datosTarea['descripcion'] = HtmlRico::limpiar($_POST['descripcion'] ?? '');
        $datosTarea['adjuntos'] = guardarAdjuntos('adjuntos', 'tarea_', $rechazados);
        $t = $tareas->crear($datosTarea);
        $deps = $tareas->dependenciasValidas((int)$t['id'], TareaRepo::dependenciasEntrada($_POST), $pid);
        $tareas->actualizar((int)$t['id'], ['dependencias' => $deps, 'depende_de' => $deps[0] ?? 0]);
        $tCreada = $tareas->buscar((int)$t['id']);
        $msgRel = relAmarrar((int)$t['id'], $pid, $_POST);
        [$msg, $tipo] = notificarSiAsignada($t, TareaRepo::asignadosDe($t), [], $proyectos, $miembros);
        $msg .= notificarDepsExternas($tCreada, [], $deps, $proyectos, $miembros, $tareas);
        sincronizarCalendario($tCreada, $proyectos, $miembros, $tareas);
        chequearEntrega($pid, $proyectos, $tareas);
        [$msg, $tipo] = avisoAdjuntos($rechazados, $msg, $tipo);
        redirigir('proyecto.php?id=' . $pid, 'Tarea creada.' . $msgRel . $msg, $tipo);

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
        // El supervisor es solo vista: nunca cambia estados.
        if (Auth::esSupervisor()) {
            $respEstado(false, 'El supervisor solo observa el tablero.');
        }
        // Cada quien puede mover SUS tareas por el tablero; los demás, solo admin.
        if (!Auth::esAdmin() && !TareaRepo::tieneAsignado($t, (int)(Auth::usuario()['id'] ?? 0))) {
            $respEstado(false, 'Solo puedes cambiar el estado de tus tareas.');
        }
        $estadoNuevo = $_POST['estado'] ?? 'pendiente';
        $tareas->actualizar((int)$t['id'],
            ['estado' => $estadoNuevo] + completadaEn($estadoNuevo, $t['estado'] ?? 'pendiente'));
        // Al darla por terminada, quien lleva el proyecto se entera al momento
        $avisoFin = avisarTareaTerminada($t, $t['estado'] ?? 'pendiente', $estadoNuevo, (int)(Auth::usuario()['id'] ?? 0));
        relAlDia([(int)$t['id']]);
        chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
        // Contadores por estado del proyecto, para que el kanban, los tiles de
        // resumen y la barra de avance se actualicen sin recargar. El avance se
        // calcula aqui porque descuenta las tareas con observaciones pendientes:
        // el navegador no puede deducirlo del conteo.
        $pidEstado = (int)$t['proyecto_id'];
        $conteo    = $tareas->resumen($pidEstado);
        $respEstado(true, 'Estado actualizado.' . $avisoFin, [
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
        // Editar la tarea (título, asignados, fechas…) es del PO y el Scrum
        // Master del proyecto (y el admin). El resto solo mueve su estado.
        if (!puedeGestionarTareas((int)$t['proyecto_id'])) {
            redirigir('proyecto.php?id=' . (int)$t['proyecto_id'], 'Solo el Product Owner o el Scrum Master pueden editar tareas.', 'error');
        }
        $asignadosAntes = TareaRepo::asignadosDe($t);
        $depsAntes      = TareaRepo::dependenciasDe($t);
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
            'descripcion'  => HtmlRico::limpiar($_POST['descripcion'] ?? ''),
            'prioridad'    => $_POST['prioridad'] ?? 'media',
            'estado'       => $_POST['estado'] ?? 'pendiente',
            'fecha_inicio' => $fIni,
            'fecha_limite' => $fLim,
            'dependencias' => ($depsEd = $tareas->dependenciasValidas((int)$t['id'], TareaRepo::dependenciasEntrada($_POST), (int)$t['proyecto_id'])),
            'depende_de'   => $depsEd[0] ?? 0,
        ] + completadaEn($_POST['estado'] ?? 'pendiente', $t['estado'] ?? 'pendiente')
          + TareaRepo::camposAsignado($_POST));
        $tActual = $tareas->buscar((int)$t['id']);
        [$msg, $tipo] = notificarSiAsignada($tActual, TareaRepo::asignadosDe($tActual), $asignadosAntes, $proyectos, $miembros);
        $msg .= avisarTareaTerminada($tActual, $t['estado'] ?? 'pendiente', $_POST['estado'] ?? 'pendiente', (int)(Auth::usuario()['id'] ?? 0));
        $msg .= notificarDepsExternas($tActual, $depsAntes, $depsEd, $proyectos, $miembros, $tareas);
        sincronizarCalendario($tActual, $proyectos, $miembros, $tareas);
        chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
        [$msg, $tipo] = avisoAdjuntos($rechazados, $msg, $tipo);
        $msgRel = relAmarrar((int)$t['id'], (int)$t['proyecto_id'], $_POST);
        relAlDia([(int)$t['id']]);
        redirigir(volverAqui('proyecto.php?id=' . $t['proyecto_id']), 'Tarea actualizada.' . $msgRel . $msg, $tipo);

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
        if (Auth::esSupervisor()) $fallar('Un supervisor solo observa el tablero, no anota observaciones.');
        $adjuntos = guardarAdjuntos('adjuntos');
        if (HtmlRico::vacio($_POST['texto'] ?? '') && empty($adjuntos)) {
            $fallar('Escribe la observación o adjunta un archivo.');
        }
        // A quién va dirigida: una o varias personas del proyecto. Si no se
        // elige a nadie, queda como observación general del proyecto.
        // 'all' = a todo el equipo del proyecto; si no, los ids elegidos.
        $paraRaw = array_map('strval', (array)($_POST['autor_id'] ?? []));
        if (in_array('all', $paraRaw, true)) {
            $paraIds = participantesProyecto($pid, $proyectos, $tareas, $miembros);
        } else {
            $paraIds = array_values(array_unique(array_filter(
                array_map('intval', $paraRaw),
                fn($mid) => $mid > 0 && $miembros->buscar($mid) !== null
            )));
        }
        if (!$paraIds) $paraIds = [0];
        // Quien la escribe NO se elige: sale de la sesión.
        $creadorId = (int)(Auth::usuario()['id'] ?? 0);

        // Destinatarios de la observación: ids elegidos; 'all' = todo el
        // proyecto; vacío = nadie en concreto (solo queda registrada).
        $destRaw = array_map('strval', (array)($_POST['destinatarios'] ?? []));
        // El asistente de «Nueva observación» avisa a las mismas personas para las
        // que es: no se eligen dos veces (y si no es para nadie, no se avisa a nadie)
        if (!empty($_POST['avisar_a_para'])) $destRaw = $paraRaw;
        $aTodos  = in_array('all', $destRaw, true);
        $destIds = ObservacionRepo::destinatariosEntrada($destRaw);
        if ($aTodos) $destIds = participantesProyecto($pid, $proyectos, $tareas, $miembros);

        // Tareas destino (n a la vez): solo las del proyecto; ninguna = general
        $destinos = array_values(array_filter(
            array_map('intval', (array)($_POST['tarea_id'] ?? [])),
            function ($tid) use ($tareas, $pid) {
                $t = $tareas->buscar($tid);
                return $t && (int)$t['proyecto_id'] === $pid;
            }
        ));
        if (empty($destinos)) $destinos = [0];   // general

        // UNA observación aunque vaya dirigida a varias personas: la lista
        // entera va en 'para'. Antes se creaba una copia por persona y el mismo
        // texto salía repetido tantas veces como destinatarios tuviera.
        // 'autor_id' se queda con el primero, que es de quien salen el avatar y
        // el color de la tarjeta; los demás se leen de 'para'.
        // ¿Es una respuesta? Entonces hereda del hilo: misma tarea y mismo
        // destinatario. Solo se pide el texto.
        $padreId = (int)($_POST['padre_id'] ?? 0);
        $padre   = $padreId ? $obsRepo->buscar($padreId) : null;
        if ($padre && (int)$padre['proyecto_id'] !== $pid) $padre = null;   // no de otro proyecto
        if ($padre) {
            $destinos = [(int)($padre['tarea_id'] ?? 0)];
            $paraIds  = is_array($padre['para'] ?? null) && $padre['para']
                ? $padre['para']
                : array_filter([(int)($padre['autor_id'] ?? 0)]);
            if (!$paraIds) $paraIds = [0];
        }

        $primero = $paraIds[0];
        $paraUno = $primero ? $miembros->buscar($primero) : null;
        $equipo  = $paraUno ? MiembroRepo::equipoDe($paraUno) : '';

        $creadas = [];
        foreach ($destinos as $tid) {
            $creadas[] = $obsRepo->crear([
                'proyecto_id'   => $pid,
                'tarea_id'      => $tid,
                'reunion_id'    => (int)($_POST['reunion_id'] ?? 0),
                'autor_id'      => $primero,
                'para'          => $paraIds,
                'padre_id'      => $padre ? (int)$padre['id'] : 0,
                'creado_por'    => $creadorId,
                'equipo'        => $equipo,
                'texto'         => HtmlRico::limpiar($_POST['texto'] ?? ''),
                'destinatarios' => $destIds,
                'adjuntos'      => $adjuntos,
            ]);
        }

        // Campana: a quien va dirigida y a los avisados; si es respuesta, también
        // a quien abrió el hilo (menos al propio autor, eso lo hace avisoPanel).
        if (!empty($creadas)) {
            $campana = array_merge($destIds, array_filter($paraIds));
            if ($padre) $campana[] = (int)($padre['creado_por'] ?? 0);
            $pCampana = $proyectos->buscar($pid);
            avisoPanel($campana, 'observacion', $padre ? 'Respondieron una observación' : 'Nueva observación',
                HtmlRico::texto((string)($creadas[0]['texto'] ?? ''), 120) . ' · ' . ($pCampana['nombre'] ?? ''),
                'proyecto.php?id=' . $pid . '#vista-observaciones');
        }

        // Aviso por correo a los destinatarios (menos al propio autor).
        if ($destIds && Mailer::listo() && !empty($creadas)) {
            $pObs     = $proyectos->buscar($pid);
            $tareaRef = (count($destinos) === 1 && $destinos[0] > 0) ? $tareas->buscar($destinos[0]) : null;
            foreach (array_unique($destIds) as $mid) {
                if ((int)$mid === $creadorId) continue;   // no se avisa a sí mismo
                $m = $miembros->buscar((int)$mid);
                if ($m && !empty($m['email'])) {
                    Mailer::notificarObservacion($creadas[0], $miembros->buscar($creadorId) ?? [], $pObs ?? [], $tareaRef, $m['email']);
                }
            }
        }

        if ($esAjax) {
            require_once __DIR__ . '/lib/obs_item.php';
            $res = $obsRepo->resumen($pid);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'         => true,
                // Una respuesta se pinta como tal: sin acciones de hilo y con
                // su sangría. Si no, entraría con el aspecto de una raíz.
                'items'      => array_map(fn($o) => obsItemHtml($o, (bool)$padre), $creadas),
                'esRespuesta'=> (bool)$padre,
                'total'      => $res['total'],
                'pendientes' => $res['pendientes'],
            ]);
            exit;
        }
        redirigir($volver, count($creadas) > 1 ? count($creadas) . ' observaciones registradas.' : 'Observación registrada.');

    case 'dep_recordar':
        $pid    = (int)($_POST['proyecto_id'] ?? 0);
        $volver = 'proyecto.php?id=' . $pid;
        if (!$proyectos->buscar($pid) || !puedeVerProyecto($pid)) {
            redirigir($volver, 'No participas en ese proyecto.', 'error');
        }
        $tareaMia = $tareas->buscar((int)($_POST['tarea_id'] ?? 0));
        $depTarea = $tareas->buscar((int)($_POST['dep_tarea_id'] ?? 0));
        if (!$tareaMia || (int)$tareaMia['proyecto_id'] !== $pid) {
            redirigir($volver, 'Tarea no encontrada.', 'error');
        }
        if (!$depTarea || !in_array((int)$depTarea['id'], TareaRepo::dependenciasDe($tareaMia), true)) {
            redirigir($volver, 'Esa tarea no es una dependencia de la tuya.', 'error');
        }
        if ((int)$depTarea['proyecto_id'] === $pid) {
            redirigir($volver, 'El recordatorio es solo para dependencias de otro equipo.', 'error');
        }
        if (!Mailer::listo()) {
            redirigir($volver, 'El correo no está configurado. Revisa Ajustes → Correo.', 'error');
        }
        $pDep = $proyectos->buscar((int)$depTarea['proyecto_id']);
        $pMio = $proyectos->buscar($pid);
        // Destinatarios: responsables de la dependencia + Scrum Master de su equipo.
        $idsDep = TareaRepo::asignadosDe($depTarea);
        $smDep  = $pDep ? ProyectoRepo::scrumDe($pDep) : 0;
        if ($smDep > 0) $idsDep[] = $smDep;
        $idsDep = array_values(array_unique(array_filter($idsDep)));
        $quien  = Auth::usuario() ?? [];
        $notaDr = trim((string)($_POST['nota'] ?? ''));
        $avisados = 0;
        foreach ($idsDep as $mid) {
            $m = $miembros->buscar((int)$mid);
            if ($m && !empty($m['email'])
                && Mailer::recordatorioDependencia($tareaMia, $pMio ?? [], $depTarea, $pDep ?? [], $quien, $m['email'], $notaDr) === true) {
                $avisados++;
            }
        }
        // Queda registrado como observación en MI tablero, sobre mi tarea.
        (new ObservacionRepo())->crear([
            'proyecto_id'   => $pid,
            'tarea_id'      => (int)$tareaMia['id'],
            'autor_id'      => (int)($quien['id'] ?? 0),
            'equipo'        => $quien ? MiembroRepo::equipoDe($quien) : '',
            'texto'         => 'Recordatorio a ' . ($pDep['nombre'] ?? 'otro equipo') . ' por la dependencia «'
                             . ($depTarea['titulo'] ?? '') . '».' . ($notaDr !== '' ? ' ' . $notaDr : ''),
            'destinatarios' => $idsDep,
            'tipo'          => 'recordatorio',
        ]);
        redirigir($volver . '#vista-observaciones',
            $avisados ? 'Recordatorio enviado (' . $avisados . ') y guardado en observaciones.'
                      : 'Nadie del otro equipo tiene correo registrado. Igual quedó guardado en observaciones.',
            $avisados ? 'success' : 'info');

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
        avisoPanel([$paraId], 'intercambio', 'Te proponen intercambiar tareas',
            ($mDe['nombre'] ?? '') . ': «' . ($tMia['titulo'] ?? '') . '» por tu «' . ($tSuya['titulo'] ?? '') . '»',
            'proyecto.php?id=' . $pid . '#vista-intercambios');
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
            TareaRepo::$motivo = 'intercambio';   // en la historia de las dos tareas
            $tareas->reemplazarAsignado((int)$tA['id'], (int)$x['de_id'], (int)$x['para_id']);
            $tareas->reemplazarAsignado((int)$tB['id'], (int)$x['para_id'], (int)$x['de_id']);
            TareaRepo::$motivo = '';
        }

        $inter->actualizar((int)$x['id'], [
            'estado'      => $acepta ? 'aceptado' : 'rechazado',
            'respuesta'   => trim($_POST['nota'] ?? ''),
            'resuelto_en' => date('Y-m-d H:i'),
        ]);

        $quien = $miembros->buscar($miId);
        $dest  = $miembros->buscar((int)$x['de_id']);
        avisoPanel([(int)$x['de_id']], 'intercambio', $acepta ? 'Aceptaron tu intercambio de tareas' : 'Rechazaron tu intercambio de tareas',
            ($quien['nombre'] ?? '') . (trim($_POST['nota'] ?? '') !== '' ? ': ' . trim($_POST['nota']) : ''),
            'proyecto.php?id=' . (int)$x['proyecto_id'] . '#vista-intercambios');
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

    case 'api_token_crear':
    case 'api_token_quitar':
        // El token de la API (api.php) para Claude Code: siempre el de quien entró, nunca el del «ver como»
        require_once __DIR__ . '/lib/ApiToken.php';
        $yoTk = (int)(Auth::usuario()['id'] ?? 0);
        if ($yoTk <= 0) redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
        if ($accion === 'api_token_quitar') {
            ApiToken::quitar($yoTk);
            redirigir('perfil.php#claude-api', 'Listo: ese token ya no sirve. Claude Code dejará de entrar hasta que crees otro.');
        }
        // Se ve una sola vez: va por la sesión, no por la URL
        $_SESSION['api_token_nuevo'] = ApiToken::crear($yoTk);
        redirigir('perfil.php#claude-api', 'Token creado. Cópialo ahora: es la única vez que se ve.');

    case 'contexto_tareas_json':
        // El formato de tareas con los releases, los equipos y los ítems de todos
        // (lib/ContextoTareas.php), para dárselo al asistente de código. Mi perfil.
        if (!Auth::usuario()) {
            redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
        }
        require_once __DIR__ . '/lib/ContextoTareas.php';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="innotech-hub-tareas-y-releases.json"');
        header('Cache-Control: no-store');
        echo json_encode(ContextoTareas::generar(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;

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
            'nota'      => 'Mis tareas en InnoTech Hub. Cada commit referencia su tarea con el #id; con una palabra clave pegada (closes/fixes/cierra #id) el panel la avanza de estado solo (ver estándar del equipo).',
            'tareas'    => $mias,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(MiembroRepo::iniciales($yo) . '-' . $yo['nombre']));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-tareas-' . trim($slug, '-') . '.json"');
        header('Cache-Control: no-store');
        echo $salida;
        exit;

    case 'proyecto_tareas_json':
        // Exporta MIS tareas de un proyecto como JSON (con su #id y las tareas de
        // las que dependen), para pasárselo a mi Claude con contexto. Solo las
        // mías, no las de todo el equipo.
        $yoJson = Auth::usuario();
        if (!$yoJson) {
            redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
        }
        $pid = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($pid);
        if (!$p) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        if (!puedeVerProyecto($pid)) {
            redirigir('index.php', 'No participas en ese proyecto.', 'error');
        }
        $miIdJson = (int)$yoJson['id'];
        // Alcance: por defecto solo las mías. "equipo" saca las de todo el
        // proyecto y lo puede pedir quien lo gestiona — admin o Scrum de este
        // proyecto; a cualquier otro se le devuelven las suyas.
        $todoElEquipo = ($_POST['alcance'] ?? '') === 'equipo' && puedeGestionar($pid);
        $estCat  = Catalogo::estadosTarea();
        $priCat  = Catalogo::prioridades();
        $memNom  = [];
        foreach ($miembros->todos() as $m) { $memNom[(int)$m['id']] = $m['nombre']; }
        $lista   = $tareas->delProyecto($pid);
        $porId   = [];
        foreach ($lista as $t) { $porId[(int)$t['id']] = $t; }

        $fmtTarea = function ($t) use ($estCat, $priCat, $porId, $memNom, $p) {
            $resp = [];
            foreach (TareaRepo::asignadosDe($t) as $mid) {
                if (isset($memNom[$mid])) $resp[] = $memNom[$mid];
            }
            $depId = (int)($t['depende_de'] ?? 0);
            return [
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
        };

        // Las mías, o las del proyecto entero si se pidió el alcance de equipo.
        $out = [];
        $dentro = [];
        $depIds = [];
        foreach ($lista as $t) {
            if (!$todoElEquipo && !TareaRepo::tieneAsignado($t, $miIdJson)) continue;
            $dentro[(int)$t['id']] = true;
            $out[] = $fmtTarea($t);
            $d = (int)($t['depende_de'] ?? 0);
            if ($d && isset($porId[$d])) $depIds[$d] = true;
        }
        // Contexto: las tareas de las que dependen las incluidas. Con el alcance
        // de equipo ya están todas dentro, así que esta lista sale vacía sola.
        $deps = [];
        foreach (array_keys($depIds) as $d) {
            if (empty($dentro[$d])) $deps[] = $fmtTarea($porId[$d]);
        }

        $salida = json_encode([
            'proyecto'     => $p['nombre'],
            'persona'      => $todoElEquipo ? 'Todo el equipo' : $yoJson['nombre'],
            'total'        => count($out),
            'nota'         => ($todoElEquipo
                ? 'Todas las tareas del proyecto «' . $p['nombre'] . '» en InnoTech Hub, de todo el equipo.'
                : 'Mis tareas del proyecto «' . $p['nombre'] . '» en InnoTech Hub.')
                . ' Cada commit referencia su tarea con el #id: <tipo>(<área>): <descripción en presente> #<id>. Con una palabra clave pegada (closes/fixes/cierra #id) el panel avanza la tarea de estado solo (ver estándar del equipo). En "dependencias" van las tareas de las que dependen estas, como contexto.',
            'tareas'       => $out,
            'dependencias' => $deps,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(
            $p['nombre'] . ($todoElEquipo ? '' : '-' . MiembroRepo::iniciales($yoJson))
        ));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'
            . ($todoElEquipo ? 'tareas-equipo-' : 'mis-tareas-') . trim($slug, '-') . '.json"');
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
            'nombre'     => trim($_POST['nombre']),
            'rol'        => trim($_POST['rol'] ?? ''),
            'git_user'   => $gitNuevo,
            'git_emails' => MiembroRepo::gitEmailsEntrada($_POST['git_emails'] ?? ''),
            'email'      => $correoNuevo,
            'color'      => Catalogo::colorEntrada($_POST),
        ];
        if (array_key_exists('telefono', $_POST)) {
            $telNuevo = telefonoEntrada($_POST['telefono']);
            exigirTelefonoValido($_POST['telefono'], $volver);
            $cambios['telefono'] = $telNuevo;
        }

        // A dónde le llegan los avisos: se sigue entrando con el correo de la
        // cuenta, pero los avisos pueden ir a otro (o a los dos)
        if (array_key_exists('avisos_a', $_POST)) {
            $avisosA = (string)$_POST['avisos_a'];
            if (!isset(MiembroRepo::AVISOS_A[$avisosA])) $avisosA = 'cuenta';
            $correoAvisos = trim((string)($_POST['email_avisos'] ?? ''));
            if ($correoAvisos !== '' && !filter_var($correoAvisos, FILTER_VALIDATE_EMAIL)) {
                redirigir($volver, 'El otro correo para avisos no es válido.', 'error');
            }
            if ($avisosA !== 'cuenta' && $correoAvisos === '') {
                redirigir($volver, 'Escribe el otro correo para tus avisos, o elige «Mi correo».', 'error');
            }
            $cambios['email_avisos'] = $correoAvisos;
            $cambios['avisos_a'] = $avisosA;
        }

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

    /* ---------- Requerimientos sueltos (solo administrador) ---------- */
    // (asigna con fechas y avisa; devuelve la coletilla para el flash — '' si
    //  no se marcó a nadie)

    case 'req_crear':
        if (trim($_POST['titulo'] ?? '') === '') {
            redirigir('requerimientos.php', 'Escribe qué es lo que piden.', 'error');
        }
        $fechasReq = fechasRequerimiento($_POST);
        // El detalle es texto enriquecido (se puede pegar un correo con su
        // tabla): se sanea a lista blanca antes de guardar.
        $_POST['detalle'] = HtmlRico::limpiar($_POST['detalle'] ?? '');
        $reqRepo = new RequerimientoRepo();
        // ¿Viene de un correo analizado con IA? Solo la propuesta de quien la registra
        $propReq = null;
        if (!empty($_POST['propuesta']) && class_exists('ReqCorreo')) {
            $propReq = ReqCorreo::fila((int)$_POST['propuesta']);
            if (!$propReq || (int)$propReq['miembro_id'] !== (int)(Auth::usuario()['id'] ?? 0) || ($propReq['estado'] ?? '') !== 'propuesta') $propReq = null;
        }
        $adjCorreo = $rechCorreo = [];
        if ($propReq) {
            try {
                [$adjCorreo, $rechCorreo] = ReqCorreo::traerAdjuntos($propReq, (array)($_POST['correo_adjuntos'] ?? []));
            } catch (CorreoError $e) {
                $rechCorreo[] = 'los adjuntos del correo (' . $e->getMessage() . ')';
            }
        }
        $req = $reqRepo->crear($_POST + [
            'creado_por' => (int)(Auth::usuario()['id'] ?? 0),
            'adjuntos'   => array_merge(guardarAdjuntos('adjuntos'), $adjCorreo),
        ]);
        if ($propReq) {
            $reqRepo->actualizar((int)$req['id'], ['origen_correo' => ReqCorreo::origen($propReq)]);
            ReqCorreo::marcarRegistrado($propReq, $req);
        }
        $reqRepo->anotar((int)$req['id'], (int)(Auth::usuario()['id'] ?? 0), $propReq ? 'Lo registró desde un correo analizado con IA.' : 'Lo registró.');
        $notaCorreo = ($adjCorreo ? ' Se guardaron ' . count($adjCorreo) . ' adjunto' . (count($adjCorreo) === 1 ? '' : 's') . ' del correo.' : '')
            . ($rechCorreo ? ' No se pudo guardar: ' . implode(', ', $rechCorreo) . '.' : '');
        // Se puede asignar de una vez, sin pasar dos veces por el formulario (con sus observaciones en el correo)
        $obsReq = reqObsAsistente();
        $avisoReq = derivarRequerimiento($reqRepo, (int)$req['id'], (array)($_POST['asignados'] ?? []), $miembros, $fechasReq, $obsReq);
        $notaObs = reqObsGuardar($reqRepo, (int)$req['id'], $obsReq, $miembros, RequerimientoRepo::asignadosDe($reqRepo->buscar((int)$req['id']) ?? []));
        redirigir('requerimientos.php', ($avisoReq === ''
            ? 'Requerimiento registrado. Queda sin asignar hasta que le pongas responsables.'
            : 'Requerimiento registrado y asignado a ' . $avisoReq) . $notaObs . $notaCorreo);

    /* ---------- Requerimientos desde el correo (lib/ReqCorreo.php) ---------- */
    case 'req_correo_conf':
        if (!class_exists('ReqCorreo') || !class_exists('Correo')) redirigir('requerimientos.php', 'Falta el módulo del correo en el servidor.', 'error');
        $yoRc = (int)(Auth::usuario()['id'] ?? 0);
        ReqCorreo::guardarPlazos((array)($_POST['plazo'] ?? []));
        if (!Correo::conectado($yoRc)) redirigir('requerimientos.php', 'Guardamos los plazos. Para analizar tus correos, primero sincroniza tu correo InnoTech.', 'info');
        $rRc = ReqCorreo::guardarReglas($yoRc, $_POST, !empty($_POST['repasar']) ? 7 : 0);
        if ($rRc['activo'] && !$rRc['remitentes'] && !$rRc['destinos']) {
            redirigir('requerimientos.php', 'Guardado, pero falta decir de quién o hacia qué dirección vienen los correos a analizar.', 'info');
        }
        redirigir('requerimientos.php', $rRc['activo']
            ? 'Listo: cada minuto se revisan tus correos nuevos que cumplan las reglas.' . (!empty($_POST['repasar']) ? ' También los de la última semana.' : '')
            : 'Guardado. El análisis automático está apagado.');

    case 'req_correo_descartar':
        if (class_exists('ReqCorreo')) ReqCorreo::descartar((int)($_POST['id'] ?? 0), (int)(Auth::usuario()['id'] ?? 0));
        redirigir('requerimientos.php', 'Propuesta descartada.');

    case 'req_editar':
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$req) {
            redirigir('requerimientos.php', 'Ese requerimiento ya no existe.', 'error');
        }
        if (trim($_POST['titulo'] ?? '') === '') {
            redirigir('requerimientos.php', 'Escribe qué es lo que piden.', 'error');
        }
        $fechasReqEd = fechasRequerimiento($_POST);
        $antesResp   = RequerimientoRepo::asignadosDe($req);
        $antesFinEd  = RequerimientoRepo::fechaFin($req);

        // Contenido (el detalle es texto enriquecido: se sanea).
        $reqRepo->actualizar((int)$req['id'], [
            'titulo'        => trim($_POST['titulo'] ?? ''),
            'detalle'       => HtmlRico::limpiar($_POST['detalle'] ?? ''),
            'solicitante'   => trim($_POST['solicitante'] ?? ''),
            'prioridad'     => Catalogo::prioridadValida($_POST['prioridad'] ?? ''),
            'instituciones' => InstitucionRepo::idsEntrada($_POST['instituciones'] ?? []),
        ]);

        // Responsables + plazo. Se guardan igual que al asignar, pero el aviso
        // por correo va SOLO a quien se acaba de sumar: a los que ya estaban no
        // se les reenvía cada vez que se corrige una coma.
        $validosEd = [];
        foreach ((array)($_POST['asignados'] ?? []) as $aid) {
            if ($mEd = $miembros->buscar((int)$aid)) $validosEd[(int)$mEd['id']] = $mEd;
        }
        $reqRepo->asignar((int)$req['id'], array_keys($validosEd), $fechasReqEd);
        $reqActEd = $reqRepo->buscar((int)$req['id']) ?? [];
        $nuevosEd = array_diff(array_keys($validosEd), $antesResp);
        // Trazabilidad: quién entró, quién salió y si se movió la entrega
        $fuerasEd = array_diff($antesResp, array_keys($validosEd));
        $nomEd = fn(array $ids) => implode(', ', array_map(fn($i) => (string)($miembros->buscar((int)$i)['nombre'] ?? '#' . $i), $ids));
        $cambiosEd = [];
        if ($nuevosEd) $cambiosEd[] = 'sumó a ' . $nomEd($nuevosEd);
        if ($fuerasEd) $cambiosEd[] = 'quitó a ' . $nomEd($fuerasEd);
        if (RequerimientoRepo::fechaFin($reqActEd) !== $antesFinEd) $cambiosEd[] = 'movió la entrega ' . ($antesFinEd !== '' ? 'del ' . date('d/m', strtotime($antesFinEd)) . ' ' : '')
            . (RequerimientoRepo::fechaFin($reqActEd) !== '' ? 'al ' . date('d/m/Y', strtotime(RequerimientoRepo::fechaFin($reqActEd))) : 'a sin fecha');
        $reqRepo->anotar((int)$req['id'], (int)(Auth::usuario()['id'] ?? 0), $cambiosEd ? 'Editó: ' . implode('; ', $cambiosEd) . '.' : 'Editó los datos.');
        avisoPanel($nuevosEd, 'requerimiento', 'Te asignaron un requerimiento', '«' . trim($_POST['titulo'] ?? '') . '»', 'bandeja.php');
        $obsEd = reqObsAsistente();
        $avisadosEd = 0;
        foreach ($nuevosEd as $mid) {
            $otros = array_map(fn($o) => $o['nombre'], array_diff_key($validosEd, [$mid => true]));
            if (Mailer::notificarRequerimiento($reqActEd, $validosEd[$mid], array_values($otros), $obsEd) === true) {
                $avisadosEd++;
            }
        }
        // Los nuevos la recibieron en su correo de asignación; a los que ya estaban les llega como observación
        $notaEd = reqObsGuardar($reqRepo, (int)$req['id'], $obsEd, $miembros, array_values($nuevosEd));
        $sufijoEd = $avisadosEd
            ? ' Avisamos por correo a ' . $avisadosEd . ' responsable' . ($avisadosEd === 1 ? '' : 's') . ' nuevo' . ($avisadosEd === 1 ? '' : 's') . '.'
            : '';
        redirigir('requerimientos.php', '«' . trim($_POST['titulo']) . '» actualizado.' . $sufijoEd . $notaEd);

    case 'req_asignar':
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$req) {
            redirigir('requerimientos.php', 'Ese requerimiento ya no existe.', 'error');
        }
        $obsAs = reqObsAsistente();
        $aviso = derivarRequerimiento($reqRepo, (int)$req['id'], (array)($_POST['asignados'] ?? []),
                                      $miembros, fechasRequerimiento($_POST), $obsAs);
        // Todos los responsables la recibieron en el correo de asignación
        $notaAs = reqObsGuardar($reqRepo, (int)$req['id'], $obsAs, $miembros, RequerimientoRepo::asignadosDe($reqRepo->buscar((int)$req['id']) ?? []));
        if ($aviso === '') {
            redirigir('requerimientos.php', '«' . $req['titulo'] . '» vuelve a «sin asignar».' . $notaAs, 'info');
        }
        redirigir('requerimientos.php', '«' . $req['titulo'] . '» es de ' . $aviso . $notaAs);

    case 'req_estado':
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$req) {
            redirigir('requerimientos.php', 'Ese requerimiento ya no existe.', 'error');
        }
        $estadoReq = RequerimientoRepo::estadoValido($_POST['estado'] ?? '');
        $reqRepo->actualizar((int)$req['id'], ['estado' => $estadoReq]
            + ($estadoReq === 'hecho' && empty($req['cerrado_en']) ? ['cerrado_en' => date('Y-m-d H:i'), 'cerrado_por' => (int)(Auth::usuario()['id'] ?? 0)] : []));
        $reqRepo->anotar((int)$req['id'], (int)(Auth::usuario()['id'] ?? 0), 'Lo pasó a «' . RequerimientoRepo::ESTADOS[$estadoReq][0] . '».');
        redirigir('requerimientos.php', '«' . $req['titulo'] . '» → ' . RequerimientoRepo::ESTADOS[$estadoReq][0] . '.');

    case 'req_terminar':
        // Lo marca el RESPONSABLE desde su bandeja, con una observación de cómo
        // lo dejó (rama, commits…). Avisa por correo a quien lo asignó.
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$req) {
            redirigir('bandeja.php', 'Ese requerimiento ya no existe.', 'error');
        }
        $yoTerm = (int)(Auth::usuario()['id'] ?? 0);
        if (!RequerimientoRepo::tieneAsignado($req, $yoTerm)) {
            redirigir('bandeja.php', 'Solo quien lo tiene asignado puede marcarlo terminado.', 'error');
        }
        if (RequerimientoRepo::cerrado($req)) {
            redirigir('bandeja.php', 'Ese requerimiento ya estaba cerrado.', 'info');
        }
        $notaTerm = trim($_POST['nota'] ?? '');
        $reqRepo->actualizar((int)$req['id'], [
            'estado'      => 'hecho',
            'nota_cierre' => mb_substr($notaTerm, 0, 600),
            'cerrado_por' => $yoTerm,
            'cerrado_en'  => date('Y-m-d H:i'),
        ]);
        $reqTermAct = $reqRepo->buscar((int)$req['id']) ?? $req;
        $retTerm = class_exists('ReqSeguimiento') ? ReqSeguimiento::retraso($reqTermAct) : null;
        $reqRepo->anotar((int)$req['id'], $yoTerm, 'Lo marcó terminado' . ($retTerm ? ' con ' . $retTerm . ' día' . ($retTerm === 1 ? '' : 's') . ' de retraso' : ($retTerm === 0 ? ' a tiempo' : ''))
            . ($notaTerm !== '' ? ': ' . mb_substr($notaTerm, 0, 200) : '.'));
        $avisoTerm  = notificarReqTerminado($reqTermAct, $miembros->buscar($yoTerm) ?? [], $miembros, $notaTerm);
        redirigir('bandeja.php', 'Marcaste «' . ($req['titulo'] ?? '') . '» como terminado.' . $avisoTerm);

    case 'req_observar':
        // Dejar dicho algo sobre un requerimiento —"estimado, ¿qué pasó con
        // esto?"— y que le llegue por correo a la otra parte. Nace de los
        // vencidos: se veía que llevaba dos días pasado de fecha, pero para
        // preguntar había que salirse del panel a WhatsApp, y lo preguntado
        // no quedaba en ninguna parte.
        //
        // La observación se guarda SIEMPRE, aunque el correo no salga (sin
        // SMTP configurado, o sin dirección): perder lo escrito porque falló
        // el envío es peor que quedarse sin aviso.
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        $volverObs = volverAqui('requerimientos.php');
        if (!$req) {
            redirigir($volverObs, 'Ese requerimiento ya no existe.', 'error');
        }
        $yoObs = (int)(Auth::usuario()['id'] ?? 0);
        // La escribe quien lo gestiona o quien lo tiene asignado: es una
        // conversación entre esas dos partes. El supervisor solo observa.
        if (Auth::esSupervisor() || (!esAdmin() && !RequerimientoRepo::tieneAsignado($req, $yoObs))) {
            redirigir($volverObs, 'Solo quien gestiona el requerimiento o lo tiene asignado puede anotar observaciones.', 'error');
        }
        // Viene del editor con formato (viñetas, tablas): se sanea a lista blanca
        $textoObs = HtmlRico::limpiar((string)($_POST['texto'] ?? ''));
        if (HtmlRico::vacio($textoObs)) {
            redirigir($volverObs, 'Escribe la observación antes de enviarla.', 'error');
        }

        // ¿Abre hilo o responde a una? Un padre que ya no exista se ignora
        // dentro de observar() y la observación abre hilo.
        $padreObs = (int)($_POST['padre_id'] ?? 0);
        $laQueRespondeObs = $padreObs > 0
            ? RequerimientoRepo::observacionDe($req, $padreObs)
            : null;

        // A quién le llega:
        //
        //  - Si ABRE hilo, a los responsables y a nadie más. La observación es
        //    el tirón de orejas a quien no está cumpliendo; quien la registró
        //    no necesita copia de cada una.
        //  - Si RESPONDE, a quien escribió aquello que se responde. Así el hilo
        //    cierra el círculo: Felipe le escribe a Jaione, Jaione contesta y
        //    Felipe se entera — sin que Ronny, que solo la registró, reciba
        //    nada en todo el intercambio.
        //
        // Quien escribe queda siempre fuera, que si no se mandaría el correo a
        // sí mismo.
        $paraObs = [];
        $destinosObs = $laQueRespondeObs
            ? [$laQueRespondeObs['autor']]
            : RequerimientoRepo::asignadosDe($req);
        foreach ($destinosObs as $midObs) {
            if ($midObs <= 0 || $midObs === $yoObs || isset($paraObs[$midObs])) continue;
            if ($mObs = $miembros->buscar($midObs)) $paraObs[$midObs] = $mObs;
        }
        $autorObs = $miembros->buscar($yoObs) ?? [];
        // Campana: al administrador lo lleva al módulo; a los demás, a su bandeja
        $adminsObs = idsAdmins($miembros);
        foreach (array_keys($paraObs) as $midObs) {
            avisoPanel([$midObs], 'requerimiento', $laQueRespondeObs ? 'Respondieron tu observación' : 'Observación en un requerimiento',
                '«' . ($req['titulo'] ?? '') . '» · ' . HtmlRico::texto((string)$textoObs, 100),
                in_array($midObs, $adminsObs, true) ? 'requerimientos.php' : 'bandeja.php');
        }
        $avisadosObs = [];
        foreach ($paraObs as $midObs => $mObs) {
            if (Mailer::observacionRequerimiento($req, $autorObs, $mObs, $textoObs, $laQueRespondeObs) === true) {
                $avisadosObs[] = $midObs;
            }
        }
        $reqRepo->observar((int)$req['id'], $textoObs, $yoObs, $avisadosObs, $padreObs, true);

        $nAvObs = count($avisadosObs);
        $rotuloObs = $laQueRespondeObs ? 'Respuesta anotada en «' : 'Observación anotada en «';
        redirigir($volverObs, $rotuloObs . ($req['titulo'] ?? '') . '».'
            . ($nAvObs
                ? ' Avisamos por correo a ' . implode(', ', array_map(
                    fn($mid) => explode(' ', trim((string)($paraObs[$mid]['nombre'] ?? '')))[0], $avisadosObs)) . '.'
                : (!$paraObs
                    ? ($laQueRespondeObs
                        ? ' No salió correo: la escribiste tú o quien la escribió ya no está en el equipo.'
                        : ' Nadie la tiene asignada todavía, así que no salió ningún correo.')
                    : ' No salió el correo (revisa Ajustes → Correo), pero queda anotada.')));

    case 'req_colaborador':
        // Quien lo tiene asignado suma a alguien para que le ayude. Nada más:
        // las fechas, la prioridad y quitar gente siguen siendo del administrador.
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        $volverCol = volverAqui('bandeja.php');
        if (!$req) redirigir($volverCol, 'Ese requerimiento ya no existe.', 'error');
        $yoCol = (int)(Auth::usuario()['id'] ?? 0);
        if (Auth::esSupervisor() || (!esAdmin() && !RequerimientoRepo::tieneAsignado($req, $yoCol))) {
            redirigir($volverCol, 'Solo quien tiene el requerimiento puede sumar a un colaborador.', 'error');
        }
        if (RequerimientoRepo::cerrado($req)) redirigir($volverCol, 'Ese requerimiento ya está cerrado.', 'info');
        $colab = $miembros->buscar((int)($_POST['colaborador'] ?? 0));
        if (!$colab) redirigir($volverCol, 'Elige a la persona que te va a ayudar.', 'error');
        if (RequerimientoRepo::tieneAsignado($req, (int)$colab['id'])) redirigir($volverCol, $colab['nombre'] . ' ya está en este requerimiento.', 'info');
        $reqRepo->agregarColaborador((int)$req['id'], (int)$colab['id'], $yoCol);
        $yoNomCol = (string)(Auth::usuario()['nombre'] ?? 'Alguien');
        $reqRepo->anotar((int)$req['id'], $yoCol, 'Sumó a ' . $colab['nombre'] . ' como colaborador.');
        $reqCol = $reqRepo->buscar((int)$req['id']) ?? $req;
        // Al colaborador le llega como a cualquier responsable, con quién lo comparte
        avisoPanel([(int)$colab['id']], 'requerimiento', 'Te sumaron a un requerimiento', '«' . $req['titulo'] . '» · te sumó ' . $yoNomCol, 'bandeja.php');
        $otrosCol = array_values(array_filter(array_map(fn($i) => (string)($miembros->buscar($i)['nombre'] ?? ''), array_diff(RequerimientoRepo::asignadosDe($reqCol), [(int)$colab['id']]))));
        $correoCol = Mailer::notificarRequerimiento($reqCol, $colab, $otrosCol) === true;
        // Quien lo asignó, los administradores y quien da seguimiento se enteran en la campana
        $segCol = class_exists('ReqSeguimiento') ? ReqSeguimiento::conf()['personas'] : [];
        avisoPanel(array_values(array_diff(array_unique(array_merge([(int)($req['creado_por'] ?? 0)], idsAdmins($miembros), $segCol)), [$yoCol])), 'requerimiento',
            'Sumaron un colaborador', '«' . $req['titulo'] . '» · ' . $yoNomCol . ' sumó a ' . $colab['nombre'], 'requerimientos.php?ver=' . (int)$req['id']);
        redirigir($volverCol, $colab['nombre'] . ' ya está contigo en «' . $req['titulo'] . '».' . ($correoCol ? ' Le avisamos por correo.' : ''));

    case 'tarea_colaborador':
        // Quien tiene la tarea suma a alguien que le ayuda (o que ya le ayudó). Nada
        // más: fechas, prioridad y quitar gente siguen siendo de quien gestiona el proyecto.
        $tCol = $tareas->buscar((int)($_POST['id'] ?? 0));
        $volverTc = $tCol ? 'proyecto.php?id=' . (int)$tCol['proyecto_id'] . '&tarea=' . (int)$tCol['id'] : 'index.php';
        $finTc = function (bool $ok, string $msg, array $extra = []) use ($volverTc): never {
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['ok' => $ok, 'mensaje' => $msg] + $extra, JSON_UNESCAPED_UNICODE));
            }
            redirigir($volverTc, $msg, $ok ? 'success' : 'error');
        };
        if (!$tCol || !puedeVerProyecto((int)$tCol['proyecto_id'])) $finTc(false, 'Esa tarea ya no está disponible.');
        $yoTc = (int)(Auth::usuario()['id'] ?? 0);
        if (Auth::esSupervisor() || (!TareaRepo::tieneAsignado($tCol, $yoTc) && !puedeGestionar((int)$tCol['proyecto_id']))) {
            $finTc(false, 'Solo quien tiene la tarea (o quien gestiona el proyecto) puede sumar a alguien.');
        }
        $colTc = $miembros->buscar((int)($_POST['colaborador'] ?? 0));
        if (!$colTc) $finTc(false, 'Elige a la persona.');
        $pTc = $proyectos->buscar((int)$tCol['proyecto_id']) ?? [];
        // Solo gente del proyecto (si el proyecto no tiene lista, cualquiera del equipo)
        $genteTc = ProyectoRepo::miembrosDe($pTc);
        if ($genteTc !== null && !in_array((int)$colTc['id'], array_merge($genteTc, [(int)($pTc['scrum'] ?? 0)]), true)) {
            $finTc(false, $colTc['nombre'] . ' no está en este proyecto.');
        }
        if (TareaRepo::tieneAsignado($tCol, (int)$colTc['id'])) $finTc(false, $colTc['nombre'] . ' ya está en esta tarea.');
        $notaTc = mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 500);
        $tareas->agregarColaborador((int)$tCol['id'], (int)$colTc['id'], $yoTc, $notaTc);
        $tNuevaTc = $tareas->buscar((int)$tCol['id']) ?? $tCol;
        $yoNomTc = (string)(Auth::usuario()['nombre'] ?? 'Alguien');
        // A quien se suma le llega como cualquier tarea asignada, y en la campana con quién y para qué
        avisoPanel([(int)$colTc['id']], 'tarea', 'Te sumaron a una tarea', '#' . (int)$tCol['id'] . ' «' . $tCol['titulo'] . '» · te sumó ' . $yoNomTc
            . ($notaTc !== '' ? ': ' . mb_substr($notaTc, 0, 140) : ''), $volverTc);
        $correoTc = $pTc && Mailer::notificarAsignacion($tNuevaTc, $colTc, $pTc) === true;
        $nombresTc = fn(array $ids) => array_values(array_filter(array_map(fn($i) => (string)($miembros->buscar((int)$i)['nombre'] ?? ''), $ids)));
        $finTc(true, $colTc['nombre'] . ' ya está contigo en la tarea #' . (int)$tCol['id'] . '.' . ($correoTc ? ' Le avisamos por correo.' : ''), [
            'asignados'     => $nombresTc(TareaRepo::asignadosDe($tNuevaTc)),
            'asignados_ids' => TareaRepo::asignadosDe($tNuevaTc),
            'colaboradores' => array_keys(TareaRepo::colaboradoresDe($tNuevaTc)),
        ]);

    case 'req_seguimiento_conf':
        if (!class_exists('ReqSeguimiento')) redirigir('requerimientos.php', 'Falta lib/ReqSeguimiento.php en el servidor.', 'error');
        ReqSeguimiento::guardar($_POST);
        redirigir('requerimientos.php', 'Listo: el seguimiento de requerimientos quedó guardado.');

    case 'req_eliminar':
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        $reqRepo->eliminar((int)($_POST['id'] ?? 0));
        redirigir('requerimientos.php', 'Requerimiento «' . ($req['titulo'] ?? '') . '» eliminado.');

    /* ---------- Catálogo de instituciones (solo admin) ---------- */

    case 'institucion_crear':
        $nombreI = trim($_POST['nombre'] ?? '');
        if ($nombreI === '') {
            redirigir('instituciones.php', 'Ponle un nombre a la institución.', 'error');
        }
        (new InstitucionRepo())->crear([
            'nombre'    => $nombreI,
            'imagen'    => guardarFoto('imagen', 'inst_', 'imagen'),
            // El color del formulario (índice de paleta o "custom" + hex) lo
            // normaliza el propio repo con Catalogo::colorEntrada.
            'color'     => $_POST['color'] ?? 0,
            'color_hex' => $_POST['color_hex'] ?? '',
        ]);
        redirigir('instituciones.php', 'Institución «' . $nombreI . '» agregada al catálogo.');

    case 'institucion_editar':
        $instRepo = new InstitucionRepo();
        $inst = $instRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$inst) {
            redirigir('instituciones.php', 'Esa institución ya no existe.', 'error');
        }
        $cambiosInst = [
            'nombre' => trim($_POST['nombre'] ?? ''),
            'color'  => Catalogo::colorEntrada($_POST),
        ];
        $imgInst = guardarFoto('imagen', 'inst_', 'imagen');
        if ($imgInst !== '') {   // reemplaza la imagen y borra la anterior
            if (!empty($inst['imagen']) && is_file(__DIR__ . '/' . $inst['imagen'])) {
                @unlink(__DIR__ . '/' . $inst['imagen']);
            }
            $cambiosInst['imagen'] = $imgInst;
        }
        $instRepo->actualizar((int)$inst['id'], $cambiosInst);
        redirigir('instituciones.php', 'Institución actualizada.');

    case 'institucion_eliminar':
        (new InstitucionRepo())->eliminar((int)($_POST['id'] ?? 0));
        redirigir('instituciones.php', 'Institución quitada del catálogo.');

    case 'equipo_importar':
        // Sube el Excel (o CSV), lo lee y deja la PREVISUALIZACIÓN en sesión.
        // No escribe nada todavía: cargar 20 fichas a ciegas no se deshace.
        $volver = volverAqui('equipo.php');
        $err = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            redirigir($volver, 'Elige el archivo con la lista del equipo.', 'error');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            redirigir($volver, 'El archivo supera el límite del servidor (' . ini_get('upload_max_filesize') . ').', 'error');
        }
        if ($err !== UPLOAD_ERR_OK || empty($_FILES['archivo']['tmp_name'])) {
            redirigir($volver, 'No se pudo subir el archivo (código ' . $err . ').', 'error');
        }
        $nombreArchivo = (string)($_FILES['archivo']['name'] ?? '');
        $extArchivo = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
        if (!in_array($extArchivo, ImportadorEquipo::EXTENSIONES, true)) {
            redirigir($volver, 'Formato no admitido (.' . $extArchivo . '). Sube el Excel en .xlsx o guárdalo como CSV.', 'error');
        }
        try {
            $filasEquipo = ImportadorEquipo::leer($_FILES['archivo']['tmp_name'], $nombreArchivo);
        } catch (Throwable $e) {
            redirigir($volver, $e->getMessage(), 'error');
        }
        $_SESSION['import_equipo'] = ['archivo' => $nombreArchivo, 'filas' => $filasEquipo];
        redirigir($volver, 'Leí ' . count($filasEquipo) . ' filas de ' . $nombreArchivo . '. Revisa el resumen y confirma.', 'info');

    case 'equipo_importar_confirmar':
        $volver = volverAqui('equipo.php');
        $pend = $_SESSION['import_equipo'] ?? null;
        if (!$pend || empty($pend['filas'])) {
            redirigir($volver, 'Ya no hay ninguna carga pendiente. Vuelve a subir el archivo.', 'error');
        }
        $res = ImportadorEquipo::aplicar($pend['filas'], false);
        unset($_SESSION['import_equipo']);
        redirigir($volver, 'Equipo cargado: ' . $res['nuevos'] . ' nuevos, ' . $res['actualizados']
            . ' actualizados, ' . $res['iguales'] . ' sin cambios. Nadie tiene acceso al panel todavía.');

    case 'equipo_importar_cancelar':
        unset($_SESSION['import_equipo']);
        redirigir(volverAqui('equipo.php'), 'Carga descartada. No se tocó nada.', 'info');

    case 'miembro_crear':
        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir('equipo.php', 'El nombre del colaborador es obligatorio.', 'error');
        }
        exigirTelefonoValido($_POST['telefono'] ?? '', 'equipo.php');
        $datos = $_POST;
        $datos['foto'] = guardarFoto('foto');
        $m = $miembros->crear($datos);
        // Acceso al panel (rol + contraseña opcional)
        $accesoNuevo = Auth::accesoValido($_POST['acceso'] ?? '');
        $cambiosAcceso = ['acceso' => $accesoNuevo, 'telefono' => telefonoEntrada($_POST['telefono'] ?? '')];
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
        exigirTelefonoValido($_POST['telefono'] ?? '', paginaOrigen());
        $cambios = [
            'nombre'     => trim($_POST['nombre'] ?? ''),
            'rol'        => trim($_POST['rol'] ?? ''),
            'git_user'   => ltrim(trim($_POST['git_user'] ?? ''), '@'),
            'git_emails' => MiembroRepo::gitEmailsEntrada($_POST['git_emails'] ?? ''),
            'email'    => filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
            'telefono' => telefonoEntrada($_POST['telefono'] ?? ''),
            'color'    => Catalogo::colorEntrada($_POST),
            'equipo'   => MiembroRepo::equipoValido($_POST['equipo'] ?? ''),
            // OJO: editar los datos NO toca el 'acceso'. Se conserva el que ya
            // tenía; el acceso se cambia solo desde la columna «Acceso» de la
            // tabla (miembro_acceso_set). Antes, al no venir bien en el form, un
            // admin editado se degradaba a "solo lectura" sin querer.
        ];
        // Contraseña: solo se cambia si escribieron una nueva
        if (strlen((string)($_POST['clave'] ?? '')) >= 6) {
            $cambios['pass_hash'] = Auth::hash($_POST['clave']);
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

    case 'tareas_correo_crear':
        // Las tareas que el administrador revisó en el modal del correo (assets/ia.js).
        // Solo el administrador (no está en $accionesDeCualquiera). Se crean como
        // cualquier tarea: avisan al responsable y van a su calendario.
        header('Content-Type: application/json; charset=utf-8');
        $finTc = function (bool $ok, string $msg, array $extra = []): never {
            exit(json_encode(['ok' => $ok, 'error' => $ok ? '' : $msg, 'mensaje' => $msg] + $extra, JSON_UNESCAPED_UNICODE));
        };
        if (!class_exists('TareasCorreo')) $finTc(false, 'Falta lib/TareasCorreo.php en el servidor.');
        $pidTc = (int)($_POST['proyecto_id'] ?? 0);
        $pTc = $pidTc > 0 ? $proyectos->buscar($pidTc) : null;
        if (!$pTc) $finTc(false, 'Ese proyecto ya no existe.');
        $listaTc = json_decode((string)($_POST['tareas'] ?? '[]'), true);
        if (!is_array($listaTc) || !$listaTc) $finTc(false, 'Marca al menos una tarea.');
        $validosTc = array_column(TareasCorreo::gente($pTc), 'id');
        $origenTc = mb_substr(trim(strip_tags((string)($_POST['origen'] ?? ''))), 0, 300);
        if (is_file(__DIR__ . '/lib/IA.php')) require_once __DIR__ . '/lib/IA.php';
        $erroresTc = [];
        $listasTc = [];
        foreach (array_slice($listaTc, 0, 30) as $iTc => $tTc) {
            if (!is_array($tTc)) continue;
            $tituloTc = mb_substr(trim(strip_tags((string)($tTc['titulo'] ?? ''))), 0, 150);
            $nTc = $tituloTc !== '' ? '«' . $tituloTc . '»' : 'La tarea ' . ($iTc + 1);
            if ($tituloTc === '') { $erroresTc[] = $nTc . ' no tiene título.'; continue; }
            $respTc = array_values(array_intersect(TareaRepo::asignadosEntrada($tTc['responsables'] ?? []), $validosTc));
            if (!$respTc) { $erroresTc[] = $nTc . ': elige el responsable.'; continue; }
            $fiTc = ProyectoRepo::fecha((string)($tTc['fecha_inicio'] ?? ''));
            $flTc = ProyectoRepo::fecha((string)($tTc['fecha_limite'] ?? ''));
            if ($fiTc !== '' && $flTc !== '' && $flTc < $fiTc) { $erroresTc[] = $nTc . ': la fecha límite va antes del inicio.'; continue; }
            // La descripción llega como texto (con viñetas «- »): se pasa a HTML y se sanea
            $descTc = mb_substr(strip_tags(trim((string)($tTc['descripcion'] ?? ''))), 0, 6000);
            $descTc = class_exists('IA') ? IA::aHtml($descTc) : nl2br(e($descTc));
            if ($origenTc !== '') $descTc .= '<p><em>' . e($origenTc) . '</em></p>';
            $listasTc[] = [
                'ref' => trim((string)($tTc['ref'] ?? '')), 'dep' => trim((string)($tTc['depende_de'] ?? '')),
                'datos' => [
                    'proyecto_id' => $pidTc, 'titulo' => $tituloTc, 'descripcion' => HtmlRico::limpiar($descTc),
                    'prioridad' => Catalogo::prioridadValida((string)($tTc['prioridad'] ?? '')),
                    'fecha_inicio' => $fiTc, 'fecha_limite' => $flTc, 'asignados' => $respTc, 'origen' => 'correo',
                ],
            ];
        }
        if ($erroresTc) $finTc(false, implode(' ', array_slice($erroresTc, 0, 4)));
        if (!$listasTc) $finTc(false, 'Marca al menos una tarea.');
        $idsTc = [];
        $porRefTc = [];
        foreach ($listasTc as $lTc) {
            $tNueva = $tareas->crear($lTc['datos']);
            $idsTc[] = (int)$tNueva['id'];
            if ($lTc['ref'] !== '') $porRefTc[$lTc['ref']] = (int)$tNueva['id'];
        }
        // «Después de» dentro del mismo lote, o de una tarea que ya existía («#id»: la que no se creó por repetida)
        foreach ($listasTc as $kTc => $lTc) {
            $depTc = $porRefTc[$lTc['dep']] ?? (preg_match('/^#(\d+)$/', $lTc['dep'], $mDepTc) ? (int)$mDepTc[1] : 0);
            if ($depTc > 0 && $depTc !== $idsTc[$kTc]) {
                $depsTc = $tareas->dependenciasValidas($idsTc[$kTc], [$depTc], $pidTc);
                if ($depsTc) $tareas->actualizar($idsTc[$kTc], ['dependencias' => $depsTc, 'depende_de' => $depsTc[0]]);
            }
        }
        foreach ($idsTc as $idTc) {
            $tCreadaTc = $tareas->buscar($idTc);
            if (!$tCreadaTc) continue;
            notificarSiAsignada($tCreadaTc, TareaRepo::asignadosDe($tCreadaTc), [], $proyectos, $miembros);
            sincronizarCalendario($tCreadaTc, $proyectos, $miembros, $tareas);
        }
        chequearEntrega($pidTc, $proyectos, $tareas);
        TareasCorreo::anotar((int)(Auth::usuario()['id'] ?? 0), (string)($_POST['mensaje_id'] ?? ''), $pidTc, $idsTc);
        $nTcF = count($idsTc);
        $finTc(true, $nTcF . ' tarea' . ($nTcF === 1 ? '' : 's') . ' creada' . ($nTcF === 1 ? '' : 's') . ' en «' . $pTc['nombre'] . '».',
            ['creadas' => $nTcF, 'url' => 'proyecto.php?id=' . $pidTc]);

    case 'equipo_mover':
        // Organizar equipos (equipos.php): pasa a una o varias personas a otro
        // equipo. Llega por fetch al soltar una tarjeta (ajax=1, responde JSON)
        // o desde un formulario normal. Solo el administrador (no está en
        // $accionesDeCualquiera).
        $ajaxEq    = !empty($_POST['ajax']);
        $volverEq  = volverAqui('equipos.php');
        $equiposEq = Catalogo::equipos();
        $destEq    = (string)($_POST['equipo'] ?? '');
        $finEq = function (bool $ok, string $msg, array $extra = []) use ($ajaxEq, $volverEq): never {
            if ($ajaxEq) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['ok' => $ok, 'mensaje' => $msg] + $extra, JSON_UNESCAPED_UNICODE));
            }
            redirigir($volverEq, $msg, $ok ? 'success' : 'error');
        };
        if (!isset($equiposEq[$destEq])) $finEq(false, 'Ese equipo ya no existe: recarga la página.');
        $idsEq = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        if (!$idsEq) $finEq(false, 'Elige a quién mover.');
        $movidosEq = [];
        $sigueDePO = [];
        $proyectosEq = null;
        foreach ($idsEq as $idEq) {
            $mEq = $miembros->buscar($idEq);
            if (!$mEq) continue;
            $antesEq = MiembroRepo::equipoDe($mEq);
            if ($antesEq === $destEq) continue;
            $miembros->actualizar($idEq, ['equipo' => $destEq]);
            $movidosEq[] = ['id' => $idEq, 'nombre' => (string)$mEq['nombre'], 'antes' => $antesEq];
            // El PO se elige entre los analistas: quien sale de ahí sigue de PO
            // en sus proyectos hasta que alguien lo cambie (se avisa, no se toca)
            if ($antesEq === 'analistas') {
                $proyectosEq ??= $proyectos->todos();
                foreach ($proyectosEq as $pEq) {
                    if (esPOde($pEq, $idEq)) $sigueDePO[(string)$pEq['nombre']] = true;
                }
            }
        }
        $nombreDest = $equiposEq[$destEq][0];
        if (!$movidosEq) $finEq(true, 'Ya estaba en ' . $nombreDest . '.', ['movidos' => []]);
        $msgEq = count($movidosEq) === 1
            ? $movidosEq[0]['nombre'] . ' pasó a ' . $nombreDest . '.'
            : count($movidosEq) . ' personas pasaron a ' . $nombreDest . '.';
        $notaEq = $sigueDePO
            ? 'Sigue como PO de «' . implode('», «', array_keys($sigueDePO)) . '»: cámbialo en el proyecto si ya no corresponde.'
            : '';
        $finEq(true, $msgEq . ($notaEq !== '' && !$ajaxEq ? ' ' . $notaEq : ''), ['movidos' => $movidosEq, 'nota' => $notaEq, 'equipo' => $destEq, 'etiqueta' => $nombreDest]);

    case 'supervisor_proyectos':
        // El admin define qué proyectos ve un supervisor (solo esos, nada más).
        $id = (int)($_POST['id'] ?? 0);
        $m  = $miembros->buscar($id);
        if (!$m) {
            redirigir('equipo.php', 'Colaborador no encontrado.', 'error');
        }
        $existentes = array_map(fn($p) => (int)$p['id'], $proyectos->todos());
        $ids = array_values(array_intersect(
            ProyectoRepo::miembrosEntrada($_POST['proyectos'] ?? []),   // ids únicos y positivos
            $existentes
        ));
        $miembros->actualizar($id, ['proyectos_sup' => $ids]);
        redirigir('equipo.php?e=' . MiembroRepo::equipoDe($m),
            $ids ? ($m['nombre'] . ' verá ' . count($ids) . ' proyecto(s).')
                 : ($m['nombre'] . ' no verá ningún proyecto hasta que elijas alguno.'));

    case 'miembro_acceso_set':
        // Perfiles de una persona. Son VARIOS: lo normal es "administrador y
        // Scrum Master", y antes había que elegir uno — quien administraba el
        // panel no podía figurar como Scrum Master de su propio tablero.
        $m = $miembros->buscar((int)($_POST['id'] ?? 0));
        $volver = volverAqui('equipo.php');
        if (!$m) {
            redirigir($volver, 'Colaborador no encontrado.', 'error');
        }
        // 'accesos[]' es lo que manda; 'acceso' a secas viene de la pantalla
        // vieja y de cualquier enlace guardado.
        $nuevos = isset($_POST['accesos'])
            ? Auth::accesosValidos($_POST['accesos'])
            : [Auth::accesoValido($_POST['acceso'] ?? '')];
        $actuales = MiembroRepo::accesosDe($m);
        sort($nuevos); sort($actuales);
        if ($nuevos === $actuales) {
            redirigir($volver);   // sin cambios
        }
        $eraAdmin = in_array('admin', $actuales, true);
        $sigueAdmin = in_array('admin', $nuevos, true);
        // Al dejar de ser admin: nunca dejar el panel sin administrador, ni
        // quitarse uno mismo el acceso.
        if ($eraAdmin && !$sigueAdmin) {
            $otros = array_filter($miembros->todos(), fn($x) =>
                (int)$x['id'] !== (int)$m['id'] && MiembroRepo::tieneAcceso($x, 'admin'));
            if (!$otros) {
                redirigir($volver, 'No puedes quitar al único administrador del panel.', 'error');
            }
            if ((int)$m['id'] === (int)(Auth::usuario()['id'] ?? 0)) {
                redirigir($volver, 'No puedes quitarte a ti mismo el acceso de administrador.', 'error');
            }
        }
        // Se guardan los dos: 'accesos' con todos y 'acceso' con el de más
        // mando, que es lo que leen las pantallas que enseñan una sola etiqueta.
        $miembros->actualizar((int)$m['id'], [
            'accesos' => $nuevos,
            'acceso'  => MiembroRepo::accesoDominante($nuevos),
        ]);
        $mAct = $miembros->buscar((int)$m['id']) ?? $m;
        if ($nuevos === ['lector']) {
            redirigir($volver, $m['nombre'] . ' vuelve a solo lectura.');
        }
        $falta = empty($m['pass_hash'])
            ? ' Todavía no tiene contraseña: pónsela al editar su ficha o que entre con Google.'
            : '';
        redirigir($volver, $m['nombre'] . ' ahora es ' . MiembroRepo::accesosEnTexto($mAct) . '.' . $falta,
            $falta ? 'info' : 'success');

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
        // Ícono válido: una clase fa-* o uno del design system (iconos/*/<nombre>.svg).
        // Antes solo valía fa-*, y al guardar los del sistema se volvían el genérico.
        $fa = fn(string $v, string $fallback) =>
            preg_match('/^fa-[a-z0-9-]+$/', trim($v)) || (preg_match('/^[A-Za-z0-9_-]{1,60}$/', trim($v)) && UI::hayIcono(trim($v)))
                ? trim($v) : $fallback;

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

        // Deploys: a quien deja el administrador pulsar "cambios subidos" y
        // quien ve el modulo. Los ids se validan contra el equipo real.
        $depPost = (array)($_POST['deploys'] ?? []);
        $idsEquipo = array_map(fn($m) => (int)$m['id'], $miembros->todos());
        $idsValidos = fn($v) => array_values(array_intersect(
            array_values(array_unique(array_map('intval', (array)$v))), $idsEquipo
        ));
        $idsProy = array_map(fn($p) => (int)$p['id'], $proyectos->todos());
        $clavesEq = array_keys(Catalogo::equipos());
        $deploysCfg = [
            'activo'     => !empty($depPost['activo']),
            'entorno'    => trim($depPost['entorno'] ?? '') ?: $def['deploys']['entorno'],
            'url'        => preg_match('#^https?://\S+$#i', $uDep = trim((string)($depPost['url'] ?? ''))) ? $uDep : '',
            'encargados' => $idsValidos($depPost['encargados'] ?? []),
            'visores'    => $idsValidos($depPost['visores'] ?? []),
            'ver_po'     => !empty($depPost['ver_po']),
            'proyectos'  => array_values(array_intersect(
                array_values(array_unique(array_map('intval', (array)($depPost['proyectos'] ?? [])))), $idsProy
            )),
            'equipos'    => array_values(array_intersect(
                array_values(array_unique(array_map('strval', (array)($depPost['equipos'] ?? [])))), $clavesEq
            )),
            // Alias por proyecto. Se guarda solo el de los proyectos que
            // existen: si se borra un proyecto, su alias no se queda de okupa
            // en la config para siempre.
            'alias'      => array_intersect_key(
                aliasDeploys($depPost['alias'] ?? []), array_flip($idsProy)
            ),
            // Horario de subidas (lib/Cronograma.php): hora límite general, días, hora propia por
            // proyecto (vacía = la general), deploys de GitLab y aviso previo
            'cronograma' => (function () use ($depPost, $idsProy) {
                $cr = (array)($depPost['cronograma'] ?? []);
                $hora = trim((string)($cr['hora'] ?? ''));
                $horas = [];
                foreach ((array)($cr['horas'] ?? []) as $pidH => $h) {
                    $h = trim((string)$h);
                    if ($h !== '' && $h !== $hora && in_array((int)$pidH, $idsProy, true)) $horas[(int)$pidH] = $h;
                }
                return cronogramaDeploys([
                    'activo' => !empty($cr['activo']), 'hora' => $hora, 'dias' => (array)($cr['dias'] ?? []),
                    'horas' => $horas, 'gitlab' => !empty($cr['gitlab']), 'aviso' => (int)($cr['aviso'] ?? 30),
                ]);
            })(),
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
            // Otros correos que también reciben los avisos de completado/terminado.
            // Se aceptan separados por coma, punto y coma o salto de línea; se
            // guardan solo los válidos, sin repetir, unidos por ", ".
            'correos_aviso'       => implode(', ', array_values(array_unique(array_filter(
                array_map(
                    fn($e) => filter_var(trim($e), FILTER_VALIDATE_EMAIL) ?: '',
                    preg_split('/[\s,;]+/', (string)($correoPost['correos_aviso'] ?? ''))
                ),
                fn($e) => $e !== ''
            )))),
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

        // WhatsApp: el token, como los demás secretos, se conserva si el campo llega vacío
        $waPost = (array)($_POST['whatsapp'] ?? []);
        $waUrl  = rtrim(trim((string)($waPost['url'] ?? '')), '/');
        if ($waUrl !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[\w./-]*)?$#i', $waUrl)) {
            $waUrl = (string)($prev['whatsapp']['url'] ?? '');
        }
        $waCfg = [
            'activo'       => !empty($waPost['activo']),
            'url'          => $waUrl,
            'token'        => $secreto($waPost['token'] ?? '', $prev['whatsapp']['token'] ?? ''),
            'recordar_min' => in_array((int)($waPost['recordar_min'] ?? 15), [0, 10, 15, 30, 60], true) ? (int)($waPost['recordar_min'] ?? 15) : 15,
        ];

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
            'deploys'          => $deploysCfg,
            'whatsapp'         => $waCfg,
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
        $r = Mailer::enviarDirecto($para, 'Prueba de correo — ' . $marcaCorreo,
            '<p style="font-family:Arial;font-size:15px;">¡Funciona! El panel ' . e($marcaCorreo) . ' ya puede enviar notificaciones por correo.</p>');
        if ($r === true) {
            redirigir('ajustes.php', 'Correo de prueba enviado a ' . $para . '. ¡Revisa la bandeja!');
        }
        redirigir('ajustes.php', 'El envío falló: ' . $r, 'error');

    /* ---------- Panorama: todo el panel en un JSON ---------- */

    case 'panorama_json':
        // Todo el trabajo del panel en un archivo, ordenado POR PROYECTO: cada
        // uno con sus tareas, sus observaciones y sus subidas al servidor de
        // pruebas, más los requerimientos sueltos. Nace de tener que armar ese
        // contexto a mano —proyecto por proyecto— cada vez que hay que contar
        // en qué anda el equipo o pasárselo entero a Claude.
        //
        // NO lleva personas: ni responsables, ni quién escribió cada
        // observación, ni el equipo. Es un corte de QUÉ hay y en qué estado,
        // no de quién hace qué; así además el archivo no reparte los correos
        // del equipo por ahí.
        //
        // Solo para el administrador: no está en las listas de acciones
        // abiertas, así que actions.php ya exigió admin más arriba.
        $obsRepoPan  = new ObservacionRepo();
        $depRepoPan  = new DeployRepo();
        $reqRepoPan  = new RequerimientoRepo();
        $instRepoPan = new InstitucionRepo();

        $estCatPan  = Catalogo::estadosTarea();
        $priCatPan  = Catalogo::prioridades();
        $finalesPan = Catalogo::estadosFinales();
        $etiqueta   = fn(array $cat, string $k) => $cat[$k][0] ?? $k;
        $nomInst    = array_map(fn($i) => (string)$i['nombre'], $instRepoPan->mapa());

        // Todas las tareas indexadas: hace falta para nombrar una dependencia
        // que apunta a otro proyecto, que es el caso que de verdad importa.
        $tareaPorId = [];
        foreach ($tareas->todas() as $t) { $tareaPorId[(int)$t['id']] = $t; }
        $proyNombre = [];
        foreach ($proyectos->todos() as $p) { $proyNombre[(int)$p['id']] = (string)$p['nombre']; }

        $refTarea = function (int $id) use ($tareaPorId, $proyNombre) {
            $t = $tareaPorId[$id] ?? null;
            if (!$t) return '';
            return '#' . $id . ' · ' . ($t['titulo'] ?? '')
                 . ' (' . ($proyNombre[(int)$t['proyecto_id']] ?? '') . ')';
        };

        $fmtTareaPan = function (array $t) use ($estCatPan, $priCatPan, $etiqueta, $refTarea, $tareaPorId) {
            $propio   = (int)($t['proyecto_id'] ?? 0);
            $deps     = [];
            $externas = 0;
            foreach (TareaRepo::dependenciasDe($t) as $d) {
                $ref = $refTarea((int)$d);
                if ($ref === '') continue;
                $deps[] = $ref;
                $otra = $tareaPorId[(int)$d] ?? null;
                if ($otra && (int)$otra['proyecto_id'] !== $propio) $externas++;
            }
            return [
                'id'            => (int)$t['id'],
                'ref'           => '#' . (int)$t['id'],
                'titulo'        => (string)($t['titulo'] ?? ''),
                'descripcion'   => (string)($t['descripcion'] ?? ''),
                'estado'        => $etiqueta($estCatPan, (string)($t['estado'] ?? '')),
                'prioridad'     => $etiqueta($priCatPan, (string)($t['prioridad'] ?? '')),
                'fecha_inicio'  => (string)($t['fecha_inicio'] ?? ''),
                'fecha_limite'  => (string)($t['fecha_limite'] ?? ''),
                'completada_en' => (string)($t['completada_en'] ?? ''),
                'subida_en'     => (string)($t['desplegada_en'] ?? ''),
                'dependencias'  => $deps,
                'dependencias_de_otro_proyecto' => $externas,
                'adjuntos'      => count((array)($t['adjuntos'] ?? [])),
            ];
        };

        $fmtObsPan = function (array $o) use ($refTarea) {
            $tid = (int)($o['tarea_id'] ?? 0);
            return [
                'sobre'  => $tid ? $refTarea($tid) : 'General de la entrega',
                'texto'  => (string)($o['texto'] ?? ''),
                'estado' => (string)($o['estado'] ?? 'pendiente'),
                'creado' => (string)($o['creado'] ?? ''),
            ];
        };

        $aplicativos     = [];
        $totTareas       = 0;
        $porEstadoGlobal = [];
        $totObs          = 0;
        $totDeploys      = 0;
        $totSinSubir     = 0;

        foreach ($proyectos->todos() as $p) {
            $pid     = (int)$p['id'];
            $lista   = $tareas->delProyecto($pid);
            $resumen = $tareas->resumen($pid);

            $porEstado = [];
            foreach ($resumen as $k => $n) {
                $et = $etiqueta($estCatPan, (string)$k);
                $porEstado[$et] = (int)$n;
                $porEstadoGlobal[$et] = ($porEstadoGlobal[$et] ?? 0) + (int)$n;
            }
            // Terminadas que ningún despliegue se ha llevado todavía.
            $sinSubir = 0;
            foreach ($lista as $t) {
                if (in_array($t['estado'] ?? '', $finalesPan, true) && (int)($t['deploy_id'] ?? 0) === 0) $sinSubir++;
            }
            $totSinSubir += $sinSubir;

            $obsPlanas = [];
            foreach ($obsRepoPan->hilosDelProyecto($pid) as $h) {
                $fila = $fmtObsPan($h);
                $fila['respuestas'] = array_map($fmtObsPan, $h['respuestas'] ?? []);
                $obsPlanas[] = $fila;
                $totObs += 1 + count($h['respuestas'] ?? []);
            }

            $subidas = [];
            foreach ($depRepoPan->todos() as $d) {
                if (!in_array($pid, DeployRepo::proyectosDe($d), true)) continue;
                $subidas[] = [
                    'fecha'   => (string)($d['fecha'] ?? ''),
                    'entorno' => (string)($d['entorno'] ?? ''),
                    'nota'    => (string)($d['nota'] ?? ''),
                    'tareas'  => array_map(fn($x) => '#' . (int)$x, DeployRepo::tareasDe($d)),
                ];
            }
            $totDeploys += count($subidas);
            $totTareas  += count($lista);

            $aplicativos[] = [
                'proyecto'         => (string)$p['nombre'],
                'alias_despliegue' => aliasDeploy($pid),
                'estado'           => (string)($p['estado'] ?? ''),
                'descripcion'      => (string)($p['descripcion'] ?? ''),
                'repositorios'     => array_map(fn($r) => $r['url'], ProyectoRepo::repos($p)),
                'avance'           => $tareas->avance($pid),
                'resumen'          => [
                    'tareas'        => count($lista),
                    'por_estado'    => $porEstado,
                    // El avance NO es «completadas / total»: una tarea en estado
                    // final con una observación abierta no cuenta como terminada
                    // (es el control de calidad del panel). Por eso va también
                    // este número; si no, el avance y el recuento por estado
                    // parecen contradecirse al leer el archivo.
                    'completadas_netas' => $tareas->completadas($pid),
                    'sin_subir'     => $sinSubir,
                    'observaciones' => count($obsPlanas),
                    'despliegues'   => count($subidas),
                ],
                'tareas'           => array_map($fmtTareaPan, $lista),
                'observaciones'    => $obsPlanas,
                'despliegues'      => $subidas,
            ];
        }

        /* ---- Requerimientos sueltos: no pertenecen a ningún proyecto, así que
                van aparte y no dentro de los aplicativos. ---- */
        $reqsPan      = [];
        $reqPorEstado = [];
        $reqVencidos  = 0;
        foreach ($reqRepoPan->todos() as $r) {
            $et = RequerimientoRepo::ESTADOS[RequerimientoRepo::estadoValido($r['estado'] ?? '')][0];
            $reqPorEstado[$et] = ($reqPorEstado[$et] ?? 0) + 1;
            if (RequerimientoRepo::vencido($r)) $reqVencidos++;
            $hilosReq = [];
            foreach (RequerimientoRepo::hilosDe($r) as $h) {
                $hilosReq[] = [
                    'texto'      => !empty($h['html']) ? HtmlRico::texto($h['texto']) : $h['texto'],
                    'creado'     => $h['creado'],
                    'respuestas' => array_map(
                        fn($x) => ['texto' => !empty($x['html']) ? HtmlRico::texto($x['texto']) : $x['texto'], 'creado' => $x['creado']],
                        $h['respuestas']
                    ),
                ];
            }
            $reqsPan[] = [
                'id'            => (int)$r['id'],
                'ref'           => '#' . (int)$r['id'],
                'titulo'        => (string)($r['titulo'] ?? ''),
                'detalle'       => (string)($r['detalle'] ?? ''),
                'solicitante'   => (string)($r['solicitante'] ?? ''),
                'instituciones' => array_values(array_filter(array_map(
                    fn($i) => $nomInst[(int)$i] ?? '', RequerimientoRepo::institucionesDe($r)
                ))),
                'estado'        => $et,
                'prioridad'     => $etiqueta($priCatPan, (string)($r['prioridad'] ?? '')),
                'fecha_inicio'  => RequerimientoRepo::fechaInicio($r),
                'fecha_entrega' => RequerimientoRepo::fechaFin($r),
                'vencido'       => RequerimientoRepo::vencido($r),
                'nota_cierre'   => (string)($r['nota_cierre'] ?? ''),
                'cerrado_en'    => (string)($r['cerrado_en'] ?? ''),
                'observaciones' => $hilosReq,
            ];
        }

        $marcaPan  = Config::all();
        $salidaPan = json_encode([
            'panel'    => (string)($marcaPan['titulo'] ?? 'Panel'),
            'generado' => date('Y-m-d H:i'),
            'nota'     => 'Todo el trabajo del panel, por proyecto: cada aplicativo con TODAS sus '
                        . 'tareas —terminadas o no—, sus observaciones y sus subidas al servidor de '
                        . 'pruebas, más los requerimientos sueltos, que no pertenecen a ninguno. Cada '
                        . 'tarea se referencia con su #id. No lleva personas: es un corte de qué hay '
                        . 'y en qué estado. Ojo con «avance»: no es completadas/total, porque una '
                        . 'tarea terminada con una observación abierta no cuenta; para eso está '
                        . '«completadas_netas» en el resumen de cada aplicativo.',
            'resumen'  => [
                'aplicativos'       => count($aplicativos),
                'tareas'            => $totTareas,
                'tareas_por_estado' => $porEstadoGlobal,
                'sin_subir'         => $totSinSubir,
                'observaciones'     => $totObs,
                'despliegues'       => $totDeploys,
                'requerimientos'    => [
                    'total'      => count($reqsPan),
                    'por_estado' => $reqPorEstado,
                    'vencidos'   => $reqVencidos,
                ],
            ],
            'aplicativos'            => $aplicativos,
            'requerimientos_sueltos' => $reqsPan,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $nombrePan = 'panorama-'
                   . preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($marcaPan['titulo'] ?? 'panel')))
                   . '-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . trim($nombrePan, '-') . '"');
        header('Content-Length: ' . strlen($salidaPan));
        header('Cache-Control: no-store');
        echo $salidaPan;
        exit;

    case 'reporte_pptx':
    case 'reporte_docx':
        // Reportería: el resumen ejecutivo con los datos de este momento. Solo
        // administrador (no está en las listas de acciones abiertas).
        require_once __DIR__ . '/lib/Reporte.php';
        require_once __DIR__ . '/lib/ReportePptx.php';
        require_once __DIR__ . '/lib/ReporteDocx.php';
        // La misma selección que se ve en la página (Configurar): ?p[]=…&req=0
        [$selDesc, $reqDesc] = Reporte::seleccion($_POST);
        $repDesc = Reporte::desdePanel($selDesc, $reqDesc);
        $esPpt   = $accion === 'reporte_pptx';
        $binRep  = $esPpt ? ReportePptx::generar($repDesc) : ReporteDocx::generar($repDesc);
        header('Content-Type: ' . ($esPpt
            ? 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        header('Content-Disposition: attachment; filename="' . Reporte::nombreArchivo($repDesc, $esPpt ? 'pptx' : 'docx') . '"');
        header('Content-Length: ' . strlen($binRep));
        header('Cache-Control: no-store');
        echo $binRep;
        exit;

    /* ---------- IA: consola (solo administrador) ---------- */

    case 'ia_clave_quitar':
        // Quitar una clave al instante (botón «Quitar» de la consola, con confirmación)
        require_once __DIR__ . '/lib/IA.php';
        $provQ = (string)($_POST['proveedor'] ?? '');
        $idQ   = (string)($_POST['id'] ?? '');
        if (!isset(IA::PROVEEDORES[$provQ])) redirigir('ia.php#claves', 'Proveedor desconocido.', 'error');
        $cfgQ = IA::config();
        $quitadaQ = null;
        $cfgQ['claves'][$provQ] = array_values(array_filter((array)$cfgQ['claves'][$provQ], function ($k) use ($idQ, &$quitadaQ) {
            if ((string)$k['id'] !== $idQ) return true;
            $quitadaQ = $k;
            return false;
        }));
        if ($quitadaQ === null) redirigir('ia.php#claves', 'Esa clave ya no estaba.', 'error');
        IA::guardar($cfgQ);
        redirigir('ia.php#claves', 'Quitamos la clave «' . $quitadaQ['etiqueta'] . '» de ' . IA::PROVEEDORES[$provQ]['nombre'] . '.');

    case 'ia_guardar':
        require_once __DIR__ . '/lib/IA.php';
        $cfgIa  = IA::config();
        $postIa = (array)($_POST['ia'] ?? []);
        $num    = fn($v) => max(0, round((float)str_replace(',', '.', (string)$v), 4));

        // Claves: las guardadas se pueden renombrar, apagar o quitar; la nueva
        // se cifra. Nunca vuelven al HTML, así que un campo vacío no borra nada.
        $clavesIa = [];
        foreach (IA::PROVEEDORES as $provIa => $_) {
            $listaIa = [];
            foreach ($cfgIa['claves'][$provIa] as $k) {
                $kp = (array)($postIa['claves'][$provIa][$k['id']] ?? []);
                if (!empty($kp['quitar'])) continue;
                if ($kp) {
                    $k['etiqueta'] = mb_substr(trim((string)($kp['etiqueta'] ?? '')), 0, 40) ?: $k['etiqueta'];
                    $k['activa']   = !empty($kp['activa']);
                }
                $listaIa[] = $k;
            }
            $nuevaIa = trim((string)($postIa['nueva'][$provIa]['valor'] ?? ''));
            if ($nuevaIa !== '') {
                // Que sea una clave de verdad (no otro texto pegado por error) antes de guardarla
                $errClaveIa = method_exists('IA', 'validarClave') ? IA::validarClave($provIa, $nuevaIa) : '';
                if ($errClaveIa !== '') redirigir('ia.php#claves', $errClaveIa . ' No se guardó ningún cambio.', 'error');
                $listaIa[] = [
                    'id'       => bin2hex(random_bytes(4)),
                    'etiqueta' => mb_substr(trim((string)($postIa['nueva'][$provIa]['etiqueta'] ?? '')), 0, 40) ?: 'Clave ' . (count($listaIa) + 1),
                    'valor'    => IA::cifrar($nuevaIa),
                    'final'    => mb_substr($nuevaIa, -4),
                    'activa'   => true,
                ];
            }
            $clavesIa[$provIa] = $listaIa;
        }

        $modelosIa = [];
        foreach ((array)($postIa['modelos'] ?? []) as $m) {
            if (!is_array($m) || !empty($m['quitar'])) continue;
            $provIa = (string)($m['proveedor'] ?? '');
            $codIa  = (string)preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($m['codigo'] ?? ''));
            if (!isset(IA::PROVEEDORES[$provIa]) || $codIa === '') continue;
            $modelosIa[$provIa . ':' . $codIa] = [
                'proveedor' => $provIa, 'codigo' => $codIa,
                'nombre'    => mb_substr(trim((string)($m['nombre'] ?? '')), 0, 60) ?: $codIa,
                'entrada'   => $num($m['entrada'] ?? 0), 'salida' => $num($m['salida'] ?? 0),
                'activo'    => !empty($m['activo']),
            ];
        }
        if (!$modelosIa) redirigir('ia.php#modelos', 'Deja al menos un modelo en el catálogo.', 'error');

        $asigIa = [];
        foreach (IA::TAREAS as $tIa => $_) {
            $a   = (array)($postIa['asig'][$tIa] ?? []);
            $pri = (string)($a['principal'] ?? '');
            $res = (string)($a['respaldo'] ?? '');
            $asigIa[$tIa] = [
                'principal' => isset($modelosIa[$pri]) ? $pri : $cfgIa['asignaciones'][$tIa]['principal'],
                'respaldo'  => isset($modelosIa[$res]) && $res !== $pri ? $res : '',
                'activa'    => !empty($a['activa']),
            ];
        }

        IA::guardar([
            'activa'       => !empty($postIa['activa']),
            'tope_mensual' => $num($postIa['tope_mensual'] ?? 0),
            'claves'       => $clavesIa,
            'modelos'      => array_values($modelosIa),
            'asignaciones' => $asigIa,
            'base'         => $cfgIa['base'],
        ]);
        // Vuelve a la pestaña en la que se estaba.
        $tabIa = in_array($_POST['tab'] ?? '', ['resumen', 'claves', 'modelos', 'funciones'], true) ? $_POST['tab'] : 'resumen';
        redirigir('ia.php#' . $tabIa, 'Consola de IA guardada.');

    case 'ia_probar':
        require_once __DIR__ . '/lib/IA.php';
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode(['ok' => true, 'resultados' => IA::probar((string)($_POST['modelo'] ?? ''))], JSON_UNESCAPED_UNICODE);
        } catch (IAError $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;

    /* ---------- IA: funciones (administrador y Scrum Master) ----------
       Todas responden JSON con un BORRADOR: nada de esto guarda en el panel,
       salvo ia_crear_tareas, que crea las tareas que la persona marcó. */

    case 'ia_plan_documento':
    case 'ia_acta':
    case 'ia_acta_hay':
    case 'ia_req_texto':
    case 'rel_ia_revision':
    case 'rel_ia_equipo':
    case 'rel_ia_general':
    case 'ia_req_correo':
    case 'ia_responder_correo':
    case 'ia_tareas_correo':
    case 'ia_obs_redaccion':
    case 'ia_obs_adjunto':
    case 'ia_obs_resumen':
    case 'ia_tarea_descripcion':
    case 'ia_tarea_desglose':
    case 'ia_tarea_dependencias':
    case 'ia_crear_tareas':
    case 'ia_deploy_notas':
    case 'ia_daily':
        require_once __DIR__ . '/lib/IA.php';
        require_once __DIR__ . '/lib/IAFunciones.php';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $iaFin = function (array $d, int $st = 200): never {
            http_response_code($st);
            echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        };
        if (!IA::puedeUsar()) {
            $iaFin(['ok' => false, 'error' => Auth::esGestor()
                ? 'La IA todavía no está configurada. El administrador la activa en la Consola de IA.'
                : 'La IA es solo para administradores y Scrum Masters.'], 403);
        }
        $pidIa  = (int)($_POST['proyecto_id'] ?? 0);
        $proyIa = $pidIa > 0 ? $proyectos->buscar($pidIa) : null;
        $exigirProyIa = function () use ($proyIa, $pidIa, $iaFin) {
            if (!$proyIa || !puedeGestionar($pidIa)) $iaFin(['ok' => false, 'error' => 'No gestionas ese proyecto.'], 403);
        };
        // Archivos subidos de un campo (uno o varios) → listos para la IA.
        $archivosIa = function (string $campo, int $max = 6): array {
            $f = $_FILES[$campo] ?? null;
            if (!$f) return [];
            $nombres = (array)$f['name'];
            $out = ['archivos' => [], 'textos' => []];
            foreach ($nombres as $i => $nom) {
                if (count($out['archivos']) + count($out['textos']) >= $max) break;
                $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
                $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
                $l = IA::leerArchivo((string)$nom, $tmp);
                if (isset($l['archivo'])) $out['archivos'][] = $l['archivo'];
                else $out['textos'][] = '[' . $nom . ']' . "\n" . mb_substr($l['texto'], 0, 20000);
            }
            return $out;
        };

        // ¿Ya está en Drive la transcripción de esta reunión? (el acta solo ofrece
        // «Generar desde la transcripción» si la hay)
        if ($accion === 'ia_acta_hay') {
            $reuH = (new ReunionRepo())->buscar((int)($_POST['reunion_id'] ?? 0));
            if (!$reuH || !puedeGestionar((int)$reuH['proyecto_id'])) $iaFin(['ok' => false, 'error' => 'No gestionas ese proyecto.'], 403);
            $ocH = Reuniones::esRecurrente($reuH) ? trim((string)($_POST['ocurrencia'] ?? '')) : '';
            $trH = transcripcionReunion($reuH, $ocH);
            $iaFin(['ok' => true, 'hay' => $trH['estado'] === 'ok' && trim((string)$trH['texto']) !== '']);
        }

        try {
            switch ($accion) {
                case 'ia_plan_documento':
                    $exigirProyIa();
                    $f = $_FILES['archivo'] ?? null;
                    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new IAError('Sube el documento del plan (Excel, PDF, Word o CSV).');
                    $resIa = IAFunciones::planDocumento($proyIa, IA::leerArchivo((string)$f['name'], (string)$f['tmp_name']),
                        (string)$f['name'], (string)($_POST['instrucciones'] ?? ''));
                    break;

                case 'ia_acta':
                    $reuIa = (new ReunionRepo())->buscar((int)($_POST['reunion_id'] ?? 0));
                    if (!$reuIa) throw new IAError('Reunión no encontrada.');
                    $proyIa = $proyectos->buscar((int)$reuIa['proyecto_id']);
                    if (!$proyIa || !puedeGestionar((int)$reuIa['proyecto_id'])) $iaFin(['ok' => false, 'error' => 'No gestionas ese proyecto.'], 403);
                    $ocIa    = trim((string)($_POST['ocurrencia'] ?? ''));
                    $textoIa = trim((string)($_POST['texto'] ?? ''));
                    if ($textoIa === '') {
                        // La misma que «Transcripción»: la que ya está en Drive
                        $trIa = transcripcionReunion($reuIa, Reuniones::esRecurrente($reuIa) ? $ocIa : '');
                        if ($trIa['estado'] !== 'ok') throw new IAError($trIa['msg'] . ' También puedes pegar el texto de la reunión.');
                        $textoIa = (string)$trIa['texto'];
                    }
                    $partIa = [];
                    foreach ((array)($reuIa['invitados'] ?? []) as $midIa) {
                        $mIa = $miembros->buscar((int)$midIa);
                        if ($mIa) $partIa[] = (string)$mIa['nombre'];
                    }
                    $resIa = IAFunciones::actaReunion($proyIa, $reuIa, $ocIa !== '' ? $ocIa : substr((string)($reuIa['inicio'] ?? ''), 0, 10),
                        $partIa, $textoIa) + ['proyecto_id' => (int)$proyIa['id']];
                    break;

                case 'ia_req_texto':
                    // Los requerimientos sueltos son del administrador.
                    if (!Auth::esAdmin()) $iaFin(['ok' => false, 'error' => 'Los requerimientos sueltos los gestiona el administrador.'], 403);
                    $textoIa = trim((string)($_POST['texto'] ?? ''));
                    if (mb_strlen($textoIa) < 10) throw new IAError('Pega el mensaje con el pedido.');
                    $abiertosIa = [];
                    foreach ((new RequerimientoRepo())->todos() as $rIa) {
                        if (!RequerimientoRepo::cerrado($rIa)) $abiertosIa[] = ['id' => (int)$rIa['id'], 'titulo' => (string)($rIa['titulo'] ?? '')];
                    }
                    // El equipo con su carga y los responsables que ya marcó: la IA estima el plazo pensando en ellos
                    $genteIa = class_exists('ReqCorreo') ? ReqCorreo::gente() : [];
                    $resIa = IAFunciones::requerimiento($textoIa, array_slice($abiertosIa, 0, 200), $genteIa, (array)($_POST['responsables'] ?? []));
                    if (class_exists('ReqCorreo')) {
                        $plzIa = ReqCorreo::plazoDe($resIa + ['magnitud' => 'mediana']);
                        $resIa['fecha_inicio'] = $plzIa['fecha_inicio'];
                        $resIa['fecha_fin'] = $plzIa['fecha_fin_calc'];
                        $resIa['dias'] = $plzIa['dias'];
                        $resIa['fecha_del_mensaje'] = $plzIa['fecha_del_correo'];
                    }
                    break;

                case 'ia_req_correo':
                    // Solo el administrador, y solo desde su propio buzón
                    if (!Auth::esAdmin()) $iaFin(['ok' => false, 'error' => 'Los requerimientos sueltos los gestiona el administrador.'], 403);
                    if (!class_exists('ReqCorreo') || !class_exists('Correo')) throw new IAError('Falta el módulo del correo en el servidor.');
                    $yoRc = (int)(Auth::usuario()['id'] ?? 0);
                    try {
                        $imapRc = Correo::abrir($yoRc);
                        try {
                            $carpRc = Correo::carpetaValida(Correo::carpetas($imapRc, $yoRc), (string)($_POST['c'] ?? ''));
                            $filaRc = ReqCorreo::analizar($yoRc, (string)$carpRc['nombre'], (int)($_POST['uid'] ?? 0), 'manual', '', $imapRc,
                                mb_substr(trim((string)($_POST['instrucciones'] ?? '')), 0, 1500));
                        } finally {
                            $imapRc->cerrar();
                        }
                    } catch (CorreoError $e) {
                        throw new IAError($e->getMessage());
                    }
                    $resIa = ['url' => ($filaRc['estado'] ?? '') === 'registrado'
                        ? 'requerimientos.php?ver=' . (int)$filaRc['req_id']
                        : 'requerimientos.php?propuesta=' . (int)$filaRc['id']];
                    break;

                case 'ia_responder_correo':
                    // Leer, resumir y proponer la respuesta de un correo: solo el administrador, y solo cuando lo pide
                    if (!Auth::esAdmin()) $iaFin(['ok' => false, 'error' => 'Responder con IA es solo para administradores.'], 403);
                    if (!class_exists('TareasCorreo') || !class_exists('ReqCorreo') || !class_exists('Correo')) throw new IAError('Falta el módulo del correo en el servidor.');
                    try {
                        $msgRc = TareasCorreo::leer((int)(Auth::usuario()['id'] ?? 0), (string)($_POST['c'] ?? ''), (int)($_POST['uid'] ?? 0));
                    } catch (CorreoError $e) {
                        throw new IAError($e->getMessage());
                    }
                    $yoRc = Auth::usuario();
                    $resIa = IAFunciones::respuestaCorreo($msgRc, ['nombre' => trim((string)($yoRc['nombre'] ?? '')), 'cargo' => trim((string)($yoRc['rol'] ?? ''))],
                        mb_substr(trim((string)($_POST['instrucciones'] ?? '')), 0, 1500), (string)($_POST['tono'] ?? 'formal'));
                    break;

                case 'ia_tareas_correo':
                    // Tareas de un proyecto desde un correo: solo el administrador, desde su buzón
                    if (!Auth::esAdmin()) $iaFin(['ok' => false, 'error' => 'Crear tareas desde el correo es solo para administradores.'], 403);
                    if (!class_exists('TareasCorreo') || !class_exists('ReqCorreo') || !class_exists('Correo')) throw new IAError('Falta el módulo del correo en el servidor.');
                    if (!$proyIa) throw new IAError('Elige el proyecto.');
                    try {
                        $msgTc = TareasCorreo::leer((int)(Auth::usuario()['id'] ?? 0), (string)($_POST['c'] ?? ''), (int)($_POST['uid'] ?? 0));
                    } catch (CorreoError $e) {
                        throw new IAError($e->getMessage());
                    }
                    $genteTc = TareasCorreo::gente($proyIa);
                    $existTc = TareasCorreo::existentes((int)$proyIa['id']);
                    $resIa = IAFunciones::tareasCorreo($proyIa, $msgTc, $genteTc, trim((string)($_POST['instrucciones'] ?? '')), $existTc);
                    // ¿Ya existe en el proyecto? Lo que diga la IA o, si no, un título casi igual (sin gastar IA)
                    $porIdTc = array_column($existTc, null, 'id');
                    foreach ($resIa['tareas'] as &$tPropTc) {
                        $eTc = $porIdTc[$tPropTc['ya_existe']] ?? TareasCorreo::parecida($tPropTc['titulo'], $existTc);
                        $tPropTc['ya_existe'] = $eTc ? ['id' => $eTc['id'], 'titulo' => $eTc['titulo'], 'estado' => $eTc['estado_txt'], 'final' => $eTc['final']] : null;
                    }
                    unset($tPropTc);
                    $resIa += ['gente' => array_map(fn($g) => ['id' => $g['id'], 'nombre' => $g['nombre'], 'rol' => $g['rol']], $genteTc),
                           'proyecto' => $proyIa['nombre'], 'origen' => TareasCorreo::origen($msgTc), 'mensaje_id' => $msgTc['message_id'],
                           'prioridades' => array_map(fn($v) => $v[0], Catalogo::prioridades())];
                    break;

                case 'ia_obs_redaccion':
                case 'ia_obs_adjunto':
                    $exigirProyIa();
                    $ctxIa = [];
                    foreach (array_slice((array)($_POST['tareas'] ?? []), 0, 5) as $tidIa) {
                        $tIa = $tareas->buscar((int)$tidIa);
                        if ($tIa && (int)$tIa['proyecto_id'] === $pidIa) $ctxIa[] = '#' . $tIa['id'] . ' ' . $tIa['titulo'];
                    }
                    $ctxIa = 'Proyecto ' . $proyIa['nombre'] . ($ctxIa ? '; tareas: ' . implode('; ', $ctxIa) : '');
                    if ($accion === 'ia_obs_redaccion') {
                        $resIa = IAFunciones::obsRedaccion((string)($_POST['texto'] ?? ''), $ctxIa, $pidIa);
                    } else {
                        $adjIa = $archivosIa('adjuntos');
                        $borradorIa = HtmlRico::texto((string)($_POST['texto'] ?? ''));
                        if (!empty($adjIa['textos'])) $borradorIa .= "\n" . implode("\n", $adjIa['textos']);
                        $resIa = IAFunciones::obsAdjunto($adjIa['archivos'] ?? [], trim($borradorIa), $ctxIa, $pidIa);
                    }
                    break;

                case 'ia_obs_resumen':
                    $obsRepoIa = new ObservacionRepo();
                    $oIa = $obsRepoIa->buscar((int)($_POST['obs_id'] ?? 0));
                    if (!$oIa) throw new IAError('Observación no encontrada.');
                    $pidIa = (int)$oIa['proyecto_id'];
                    if (!puedeGestionar($pidIa)) $iaFin(['ok' => false, 'error' => 'No gestionas ese proyecto.'], 403);
                    $raizIa = (int)($oIa['padre_id'] ?? 0) ?: (int)$oIa['id'];
                    $hiloIa = null;
                    foreach ($obsRepoIa->hilosDelProyecto($pidIa) as $hIa) {
                        if ((int)$hIa['id'] === $raizIa) { $hiloIa = $hIa; break; }
                    }
                    if (!$hiloIa) throw new IAError('No se encontró el hilo de esa observación.');
                    $nombresIa = array_map(fn($m) => (string)$m['nombre'], $miembros->mapa());
                    $tIa = (int)($hiloIa['tarea_id'] ?? 0) ? $tareas->buscar((int)$hiloIa['tarea_id']) : null;
                    $resIa = IAFunciones::obsResumen($hiloIa, $nombresIa, $tIa ? '#' . $tIa['id'] . ' ' . $tIa['titulo'] : '', $pidIa);
                    break;

                case 'ia_tarea_descripcion':
                case 'ia_tarea_desglose':
                case 'ia_tarea_dependencias':
                    $exigirProyIa();
                    $tituloIa = (string)($_POST['titulo'] ?? '');
                    $descIa   = (string)($_POST['descripcion'] ?? '');
                    $resIa = match ($accion) {
                        'ia_tarea_descripcion'  => IAFunciones::tareaDescripcion($proyIa, $tituloIa, $descIa),
                        'ia_tarea_desglose'     => IAFunciones::tareaDesglose($proyIa, $tituloIa, $descIa),
                        default                 => IAFunciones::tareaDependencias($proyIa, $tituloIa, $descIa, (int)($_POST['tarea_id'] ?? 0)),
                    };
                    break;

                case 'ia_crear_tareas':
                    // Las que la persona dejó marcadas del acta o del desglose. Pasan
                    // por el importador de siempre (valida, limpia y resuelve depende_de).
                    $exigirProyIa();
                    $listaIa = json_decode((string)($_POST['tareas'] ?? '[]'), true);
                    if (!is_array($listaIa) || !$listaIa) throw new IAError('Marca al menos una tarea.');
                    $permitidosIa = ['titulo', 'descripcion', 'prioridad', 'fecha_inicio', 'fecha_limite', 'ref', 'depende_de'];
                    $loteIa = [];
                    foreach (array_slice($listaIa, 0, 60) as $tIa) {
                        if (!is_array($tIa)) continue;
                        $loteIa[] = ['proyecto' => $proyIa['nombre']] + array_intersect_key($tIa, array_flip($permitidosIa));
                    }
                    $impIa = new ImportadorTareas(new ProyectoRepo(), new MiembroRepo(), new TareaRepo());
                    $impIa->origen = 'ia';
                    $repIa = $impIa->procesar(['tareas' => $loteIa], false, false, Auth::esAdmin() ? null : array_keys(alcanceProyectos() ?? []));
                    if (empty($repIa['ok'])) throw new IAError('No se crearon: ' . implode(' ', array_slice((array)($repIa['errores'] ?? []), 0, 3)));
                    $iaFin(['ok' => true, 'creadas' => (int)($repIa['creadas'] ?? 0)]);

                case 'ia_deploy_notas':
                    $finalesIa = Catalogo::estadosFinales();
                    $porProyIa = [];
                    foreach (array_unique(array_map('intval', (array)($_POST['proyectos'] ?? []))) as $pidD) {
                        $pD = $proyectos->buscar($pidD);
                        if (!$pD || !puedeGestionar($pidD)) continue;
                        $nomD = aliasDeploy($pidD) ?: (string)$pD['nombre'];
                        foreach ($tareas->delProyecto($pidD) as $tIa) {
                            if (in_array($tIa['estado'] ?? '', $finalesIa, true) && (int)($tIa['deploy_id'] ?? 0) === 0) {
                                $porProyIa[$nomD][] = (string)$tIa['titulo'];
                            }
                        }
                    }
                    $resIa = IAFunciones::notasDespliegue($porProyIa);
                    break;

                case 'ia_daily':
                    $exigirProyIa();
                    $desdeIa = (string)($_POST['desde'] ?? '');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeIa)) $desdeIa = IAFunciones::desdeDaily();
                    $resIa = IAFunciones::daily($proyIa, $desdeIa);
                    break;

                case 'rel_ia_revision':
                case 'rel_ia_equipo':
                case 'rel_ia_general':
                    // Releases (lib/Releases.php): el resumen de una revisión, el cruce de
                    // las tareas de un equipo con sus ítems y el estado general.
                    require_once __DIR__ . '/lib/Releases.php';
                    if (!Releases::puedeVer()) $iaFin(['ok' => false, 'error' => 'No tienes acceso a Releases.'], 403);
                    $yoRl = (int)(Auth::usuario()['id'] ?? 0);
                    $smRl = fn(string $eq) => Releases::puedeEditar() || ($yoRl > 0 && (int)(Releases::equipo($eq)['sm_id'] ?? 0) === $yoRl);
                    if ($accion === 'rel_ia_revision') {
                        $revRl = Releases::revision((int)($_POST['id'] ?? 0));
                        if (!$revRl) throw new IAError('Esa revisión ya no existe.');
                        if (!$smRl((string)$revRl['equipo'])) $iaFin(['ok' => false, 'error' => 'Solo los editores o el Scrum Master del equipo.'], 403);
                        $mapaRl = $miembros->mapa();
                        $nomRl = array_values(array_filter(array_map(fn($id) => (string)($mapaRl[(int)$id]['nombre'] ?? ''), (array)($revRl['asistentes'] ?? []))));
                        $trRl = '';
                        $reuRl = (int)($revRl['reunion_id'] ?? 0) > 0 ? (new ReunionRepo())->buscar((int)$revRl['reunion_id']) : null;
                        if ($reuRl) {
                            try { $t = transcripcionReunion($reuRl); if ($t['estado'] === 'ok') $trRl = (string)$t['texto']; } catch (Throwable $e) { /* sin transcripción */ }
                        }
                        $datRl = IAFunciones::releaseRevision($revRl, $nomRl, $trRl);
                        $datRl['con_transcripcion'] = $trRl !== '';
                        Releases::guardarResumenRevision((int)$revRl['id'], $datRl);
                        $resIa = ['resumen' => $datRl];
                    } elseif ($accion === 'rel_ia_equipo') {
                        $eqRl = Releases::equipo((string)($_POST['equipo'] ?? ''));
                        if (!$eqRl) throw new IAError('Ese equipo no existe.');
                        if (!$smRl((string)$eqRl['nombre'])) $iaFin(['ok' => false, 'error' => 'Solo los editores o el Scrum Master del equipo.'], 403);
                        // La IA revisa las tareas del equipo y las asocia sola a sus ítems
                        $asRl = Releases::asociarIA((string)$eqRl['nombre']);
                        $anRl = $asRl['analisis'];
                        $resIa = ['analisis' => ['id' => (int)$anRl['id'], 'fecha' => $anRl['fecha'], 'resultado' => $anRl['resultado']],
                                  'vinculadas' => $asRl['vinculadas'], 'items' => $asRl['items'],
                                  'mensaje' => $asRl['vinculadas'] ? 'La IA asoció ' . $asRl['vinculadas'] . ' tarea(s) a ' . $asRl['items'] . ' ítem(s).' : 'La IA revisó las tareas: no había nada nuevo que asociar.'];
                    } else {
                        $relRl = (string)($_POST['release'] ?? '');
                        $eqGRl = (string)($_POST['equipo'] ?? '');
                        $resIa = ['resumen' => IAFunciones::releaseGeneral(Releases::tablero(), Releases::items(array_filter(['release' => $relRl, 'equipo' => $eqGRl])),
                            Releases::revisiones(['estado' => 'realizada']), $relRl, $eqGRl)];
                    }
                    break;
            }
            $iaFin(['ok' => true] + $resIa);
        } catch (IAError $e) {
            $iaFin(['ok' => false, 'error' => $e->getMessage()], 503);
        }



    /* ---------- Releases (lib/Releases.php · releases.php) ---------- */

    case 'rel_importar':
    case 'rel_sembrar':
    case 'rel_reuniones_equipo':
    case 'rel_item_guardar':
    case 'rel_item_tareas':
    case 'rel_item_borrar':
    case 'rel_tarea_crear':
    case 'rel_equipo_guardar':
    case 'rel_release_guardar':
    case 'rel_hito_guardar':
    case 'rel_hito_borrar':
    case 'rel_revision_guardar':
    case 'rel_revision_borrar':
    case 'rel_ia_aplicar':
    case 'rel_conf_guardar':
    case 'rel_daily_registrar':
    case 'rel_daily_zoom':
    case 'rel_daily_reunion':
        require_once __DIR__ . '/lib/Releases.php';
        $relAjax = !empty($_POST['ajax']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        // Con fetch, JSON; con un formulario normal, de vuelta a la vista con el aviso
        $relFin = function (bool $ok, string $msg = '', array $extra = []) use ($relAjax): never {
            if ($relAjax) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                if (!$ok) http_response_code(400);
                echo json_encode(['ok' => $ok, 'error' => $ok ? '' : $msg, 'mensaje' => $ok ? $msg : ''] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $volver = (string)($_POST['volver'] ?? '');
            if (!preg_match('#^releases\.php(\?[^\s<>"]*)?$#', $volver)) $volver = 'releases.php';
            redirigir($volver, $msg !== '' ? $msg : ($ok ? 'Listo.' : 'No se pudo.'), $ok ? 'success' : 'error');
        };
        if (!Releases::puedeVer()) $relFin(false, 'No tienes acceso a Releases.');
        $relYo = (int)(Auth::usuario()['id'] ?? 0);
        $relEditor = Releases::puedeEditar();
        $relSmDe = fn(string $eq) => $relEditor || ($relYo > 0 && (int)(Releases::equipo($eq)['sm_id'] ?? 0) === $relYo);
        $relSoloEditor = function () use ($relEditor, $relFin) {
            if (!$relEditor) $relFin(false, 'Esto lo cambian solo los editores de Releases (Ajustes del módulo).');
        };
        // Una tarea del panel, corta, para pintarla junto al ítem
        $relTarea = function (array $t) use ($proyectos, $miembros): array {
            static $mapaM = null, $mapaP = null, $est = null;
            $mapaM ??= $miembros->mapa();
            if ($mapaP === null) { $mapaP = []; foreach ($proyectos->todos() as $p) $mapaP[(int)$p['id']] = $p; }
            $est ??= Catalogo::estadosTarea();
            return ['id' => (int)$t['id'], 'titulo' => (string)$t['titulo'], 'estado' => (string)($t['estado'] ?? ''),
                    'estado_txt' => (string)($est[$t['estado'] ?? ''][0] ?? ($t['estado'] ?? '')), 'final' => in_array($t['estado'] ?? '', Catalogo::estadosFinales(), true),
                    'proyecto_id' => (int)$t['proyecto_id'], 'proyecto' => (string)($mapaP[(int)$t['proyecto_id']]['nombre'] ?? ''),
                    'fecha_inicio' => (string)($t['fecha_inicio'] ?? ''), 'fecha_limite' => (string)($t['fecha_limite'] ?? ''),
                    'asignados' => array_values(array_filter(array_map(fn($id) => (string)($mapaM[(int)$id]['nombre'] ?? ''), TareaRepo::asignadosDe($t))))];
        };
        try {
            switch ($accion) {
                case 'rel_importar':
                    $relSoloEditor();
                    $rutasRl = [];
                    foreach (['cronograma', 'revisiones'] as $campoRl) {
                        $fRl = $_FILES[$campoRl] ?? null;
                        if (!$fRl || ($fRl['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $rutasRl[$campoRl] = null; continue; }
                        if (($fRl['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$fRl['tmp_name'])) throw new RuntimeException('No llegó bien el archivo de ' . $campoRl . '. Prueba de nuevo.');
                        if (strtolower(pathinfo((string)$fRl['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Sube el Excel en formato .xlsx (' . $campoRl . ').');
                        $rutasRl[$campoRl] = (string)$fRl['tmp_name'];
                    }
                    if (!$rutasRl['cronograma'] && !$rutasRl['revisiones']) throw new RuntimeException('Elige al menos uno de los dos Excel.');
                    $modoRl = ($_POST['modo'] ?? '') === 'actualizar' ? 'actualizar' : 'agregar';
                    $secoRl = !empty($_POST['seco']);
                    $resRl = Releases::importar($rutasRl['cronograma'], $rutasRl['revisiones'], $modoRl, $secoRl);
                    $relFin(true, $secoRl
                        ? 'Así quedaría: ' . $resRl['items_nuevos'] . ' ítems nuevos, ' . $resRl['items_actualizados'] . ' que ya estaban y ' . $resRl['revisiones'] . ' revisiones.'
                        : 'Importado: ' . $resRl['items_nuevos'] . ' ítems nuevos, ' . $resRl['items_actualizados'] . ' actualizados, ' . $resRl['revisiones'] . ' revisiones y ' . $resRl['hitos'] . ' hitos.',
                        ['resultado' => $resRl]);

                case 'rel_sembrar':
                    // El plan que viene en el repo (admin/semillas/releases-*.json), sin subir los Excel
                    $relSoloEditor();
                    $modoRl = ($_POST['modo'] ?? '') === 'actualizar' ? 'actualizar' : 'agregar';
                    $secoRl = !empty($_POST['seco']);
                    $resRl = Releases::sembrar(null, $modoRl, $secoRl);
                    $relFin(true, ($secoRl ? 'Así quedaría: ' : 'Plan cargado: ') . $resRl['items_nuevos'] . ' ítems nuevos, '
                        . $resRl['items_actualizados'] . ($secoRl ? ' que ya estaban' : ' actualizados') . ', ' . $resRl['revisiones'] . ' revisiones y ' . $resRl['hitos'] . ' hitos.',
                        ['resultado' => $resRl, 'semilla' => basename((string)Releases::semilla())]);

                case 'rel_item_guardar':
                    $idRl = (int)($_POST['id'] ?? 0);
                    $datosRl = array_intersect_key($_POST, array_flip(['codigo', 'titulo', 'release', 'equipo', 'inicio', 'fin', 'estado', 'avance',
                        'microservicio', 'sprints', 'alcance', 'demostrar', 'nota', 'motivo', 'proceso', 'actividades', 'criterio', 'entregable', 'responsable', 'avance_fuente']));
                    if (!$relEditor) {
                        // El Scrum Master del equipo marca cómo va lo suyo (estado, %, nota); fechas y equipo, los editores
                        $antesRl = $idRl ? Releases::item($idRl) : null;
                        if (!$antesRl || !$relSmDe((string)$antesRl['equipo'])) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                        // El avance y el estado salen de las tareas que marca el equipo (la IA las asocia):
                        // el Scrum Master solo bloquea o desbloquea y deja notas
                        $datosRl = array_intersect_key($datosRl, array_flip(['estado', 'nota', 'motivo']));
                        if (isset($datosRl['estado'])) {
                            if ($datosRl['estado'] === 'bloqueado') { /* bloquear, sí */ }
                            elseif ($antesRl['estado'] === 'bloqueado') $datosRl['estado'] = 'empezado';   // al desbloquear, las tareas mandan
                            else unset($datosRl['estado']);
                        }
                    }
                    $itRl = Releases::guardarItem($idRl, $datosRl);
                    $relFin(true, $idRl ? 'Ítem actualizado.' : 'Ítem creado.', ['item' => $itRl]);

                case 'rel_item_tareas':
                    $itRl = Releases::item((int)($_POST['id'] ?? 0));
                    if (!$itRl) throw new RuntimeException('Ese ítem ya no existe.');
                    if (!$relSmDe((string)$itRl['equipo'])) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                    $itRl = Releases::vincularTareas((int)$itRl['id'], (array)($_POST['tareas'] ?? []), $relYo, !empty($_POST['sumar']));
                    $relFin(true, 'Tareas del ítem actualizadas.', ['item' => $itRl, 'tareas' => array_map($relTarea, Releases::tareasDe($itRl))]);

                case 'rel_item_borrar':
                    $relSoloEditor();
                    Releases::borrarItem((int)($_POST['id'] ?? 0));
                    $relFin(true, 'Ítem borrado.');

                case 'rel_tarea_crear':
                    // Una tarea nueva para cumplir un ítem. Las fechas del release NO la
                    // limitan (el plan tuvo reajustes): se crea con las que pongan.
                    $itRl = Releases::item((int)($_POST['item_id'] ?? 0));
                    if (!$itRl) throw new RuntimeException('Ese ítem ya no existe.');
                    if (!$relSmDe((string)$itRl['equipo'])) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                    $pidRl = (int)($_POST['proyecto_id'] ?? 0);
                    if (!$proyectos->buscar($pidRl)) throw new RuntimeException('Elige el proyecto donde va la tarea.');
                    if (!puedeGestionarTareas($pidRl)) $relFin(false, 'En ese proyecto solo crean tareas su PO, su Scrum Master o el administrador.');
                    $titRl = trim((string)($_POST['titulo'] ?? ''));
                    if ($titRl === '') throw new RuntimeException('Escribe el título de la tarea.');
                    $fiRl = Releases::fecha($_POST['fecha_inicio'] ?? '');
                    $flRl = Releases::fecha($_POST['fecha_limite'] ?? '');
                    if ($fiRl !== '' && $flRl !== '' && $flRl < $fiRl) $flRl = $fiRl;
                    $prioRl = (string)($_POST['prioridad'] ?? 'media');
                    if (!isset(Catalogo::prioridades()[$prioRl])) $prioRl = 'media';
                    $tRl = $tareas->crear(['proyecto_id' => $pidRl, 'titulo' => mb_substr($titRl, 0, 250),
                        'descripcion' => HtmlRico::limpiar(nl2br(e(trim((string)($_POST['descripcion'] ?? ''))))),
                        'prioridad' => $prioRl, 'fecha_inicio' => $fiRl, 'fecha_limite' => $flRl,
                        'asignados' => (array)($_POST['asignados'] ?? []), 'origen' => '']);
                    $msgRl = '';
                    try {
                        [$msgRl] = notificarSiAsignada($tRl, TareaRepo::asignadosDe($tRl), [], $proyectos, $miembros);
                        sincronizarCalendario($tareas->buscar((int)$tRl['id']), $proyectos, $miembros, $tareas);
                        chequearEntrega($pidRl, $proyectos, $tareas);
                    } catch (Throwable $e) { /* la tarea ya quedó creada */ }
                    $itRl = Releases::vincularTareas((int)$itRl['id'], [(int)$tRl['id']], $relYo, true);
                    $relFin(true, 'Tarea #' . (int)$tRl['id'] . ' creada y vinculada a ' . $itRl['codigo'] . '.' . $msgRl,
                        ['tarea' => $relTarea($tareas->buscar((int)$tRl['id'])), 'item' => $itRl]);

                case 'rel_equipo_guardar':
                    $relSoloEditor();
                    $eqRl = Releases::guardarEquipo((int)($_POST['id'] ?? 0), array_intersect_key($_POST, array_flip(['nombre', 'color', 'sm_id', 'po_id',
                        'sm_nombre', 'po_nombre', 'dominio', 'dia_revision', 'hora_revision', 'proyectos', 'miembros', 'orden'])));
                    $relFin(true, 'Equipo guardado.', ['equipo' => $eqRl]);

                case 'rel_release_guardar':
                    $relSoloEditor();
                    $rlRl = Releases::guardarRelease((int)($_POST['id'] ?? 0), array_intersect_key($_POST, array_flip(['codigo', 'nombre', 'inicio', 'fin', 'estado', 'color', 'descripcion', 'sprints', 'modulos'])));
                    $relFin(true, 'Release guardado.', ['release' => $rlRl]);

                case 'rel_hito_guardar':
                    $relSoloEditor();
                    $hiRl = Releases::guardarHito((int)($_POST['id'] ?? 0), array_intersect_key($_POST, array_flip(['titulo', 'fecha', 'release', 'descripcion', 'sprint', 'hecho'])));
                    $relFin(true, 'Hito guardado.', ['hito' => $hiRl]);

                case 'rel_hito_borrar':
                    $relSoloEditor();
                    (new JsonStore('rel_hitos'))->delete((int)($_POST['id'] ?? 0));
                    $relFin(true, 'Hito borrado.');

                case 'rel_revision_guardar':
                    $idRl = (int)($_POST['id'] ?? 0);
                    $revRl = $idRl ? Releases::revision($idRl) : null;
                    if ($idRl && !$revRl) throw new RuntimeException('Esa revisión ya no existe.');
                    $eqRevRl = (string)($revRl['equipo'] ?? ($_POST['equipo'] ?? ''));
                    if (!$relSmDe($eqRevRl)) $relFin(false, 'Las revisiones las registran los editores o el Scrum Master del equipo.');
                    if ($revRl && isset($_POST['equipo']) && $_POST['equipo'] !== $eqRevRl && !$relSmDe((string)$_POST['equipo'])) $relFin(false, 'No puedes pasar la revisión a un equipo que no llevas.');
                    $antesRl = $revRl;
                    $revRl = Releases::guardarRevision($idRl, array_intersect_key($_POST, array_flip(['fecha', 'hora', 'equipo', 'titulo', 'responsable',
                        'acuerdos', 'asistentes', 'filas', 'agregar', 'quitar', 'realizada', 'estado', 'sprint', 'lugar', 'modalidad', 'duracion'])));
                    // Se movió de día u hora (arrastrando en la agenda o al editarla): su reunión se mueve con ella
                    $msgReuRl = $antesRl ? relMoverReunion($antesRl, $revRl, $proyectos, $miembros) : '';
                    // Virtual: se enlaza a una reunión que ya existe o se crea la de Zoom en el proyecto del equipo
                    if (($revRl['modalidad'] ?? '') === 'virtual') {
                        $existeRl = (int)($_POST['reunion_existente'] ?? 0);
                        if ($existeRl > 0) {
                            $reuRl = (new ReunionRepo())->buscar($existeRl);
                            if (!$reuRl || !in_array((int)$reuRl['proyecto_id'], Releases::proyectosEquipo((string)$revRl['equipo']), true)) throw new RuntimeException('Esa reunión no es de los proyectos del equipo.');
                            $revRl = Releases::enlazarReunion((int)$revRl['id'], $reuRl);
                            $msgReuRl = ' Enlazada a la reunión «' . $reuRl['topic'] . '».';
                        } elseif (!empty($_POST['crear_reunion']) && empty($revRl['reunion_id'])) {
                            $pidReuRl = (int)($_POST['proyecto_id'] ?? 0) ?: (int)(Releases::proyectosEquipo((string)$revRl['equipo'])[0] ?? 0);
                            $invRl = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['invitados'] ?? Releases::invitadosSugeridos((string)$revRl['equipo']))))));
                            $reuRl = relCrearReunion($revRl, $pidReuRl, $invRl, (string)($_POST['plataforma'] ?? ''), trim((string)($_POST['join_url'] ?? '')), $proyectos, $miembros);
                            if (is_string($reuRl)) {
                                $relFin(false, 'La revisión quedó guardada, pero no se pudo crear la reunión: ' . $reuRl, ['revision' => $revRl]);
                            }
                            $revRl = Releases::enlazarReunion((int)$revRl['id'], $reuRl);
                            $msgReuRl = ' Reunión ' . ['zoom' => 'de Zoom', 'meet' => 'de Meet', 'enlace' => 'con tu enlace'][$reuRl['plataforma']] . ' creada y enviada a ' . count($invRl) . ' invitado(s).';
                        }
                    }
                    $relFin(true, (!empty($_POST['realizada']) ? 'Revisión registrada: los ítems quedaron al día y se avisó al equipo.' : 'Revisión guardada.') . $msgReuRl, ['revision' => $revRl]);

                case 'rel_reuniones_equipo':
                    // Para el formulario: las reuniones del equipo ese día, la plataforma y a quién invitar
                    $eqRl = (string)($_POST['equipo'] ?? '');
                    if (!Releases::equipo($eqRl)) throw new RuntimeException('Ese equipo no existe.');
                    if (!$relSmDe($eqRl)) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                    $mapaRl = $miembros->mapa();
                    $pidsRl = Releases::proyectosEquipo($eqRl);
                    $relFin(true, '', [
                        'reuniones' => Releases::reunionesEquipo($eqRl, Releases::fecha($_POST['fecha'] ?? '')),
                        'proyectos' => array_values(array_filter(array_map(fn($pid) => ($pr = $proyectos->buscar($pid)) ? ['id' => $pid, 'nombre' => $pr['nombre'], 'puede' => puedeReunionesDelProyecto($pid)] : null, $pidsRl))),
                        'plataforma' => $pidsRl ? Reuniones::resolverPlataforma('', $proyectos->buscar($pidsRl[0])) : '',
                        'zoom_listo' => Zoom::listo(),
                        'invitados' => array_values(array_filter(array_map(fn($id) => isset($mapaRl[$id]) ? ['id' => $id, 'nombre' => (string)$mapaRl[$id]['nombre']] : null, Releases::invitadosSugeridos($eqRl)))),
                    ]);

                case 'rel_daily_registrar':
                    $eqDl = (string)($_POST['equipo'] ?? '');
                    if (!$relSmDe($eqDl)) $relFin(false, 'Las dailies las registran los editores o el Scrum Master del equipo.');
                    Releases::registrarDaily($eqDl, (string)($_POST['fecha'] ?? ''), $_POST, $relYo, $relEditor);
                    $relFin(true, ($_POST['estado'] ?? '') === 'auto' ? 'Listo: ese día vuelve a medirse con Zoom.' : 'Daily registrada.');

                case 'rel_daily_zoom':
                    $eqDl = (string)($_POST['equipo'] ?? '');
                    if (!Releases::equipo($eqDl)) throw new RuntimeException('Ese equipo no existe.');
                    if (!$relSmDe($eqDl)) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                    // Con Zoom y con quienes entraron desde el panel (sin Zoom, solo lo segundo)
                    $rDl = Releases::sincronizarDailies($eqDl, 31);
                    if ($rDl['error'] !== '' && !$rDl['dias']) $relFin(false, $rDl['error']);
                    $relFin(true, ($rDl['dias'] ? 'Medido de nuevo: ' . $rDl['dias'] . ' día(s) al día.' : 'No había días nuevos que medir.')
                        . ($rDl['error'] !== '' ? ' Ojo: ' . $rDl['error'] : ''));

                case 'rel_daily_reunion':
                    $eqDl = (string)($_POST['equipo'] ?? '');
                    $EDl = Releases::equipo($eqDl);
                    if (!$EDl) throw new RuntimeException('Ese equipo no existe.');
                    if (!$relSmDe($eqDl)) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                    $ridDl = (int)($_POST['reunion_id'] ?? 0);
                    if ($ridDl > 0 && !in_array($ridDl, array_column(Releases::dailyCandidatas($eqDl), 'id'), true)) throw new RuntimeException('Esa reunión no es de los proyectos del equipo.');
                    Releases::guardarEquipo((int)$EDl['id'], ['daily_reunion' => $ridDl]);
                    $relFin(true, $ridDl ? 'Listo: esa es la daily del equipo.' : 'Listo: se toma la reunión que se llame «Daily…».');

                case 'rel_revision_borrar':
                    $relSoloEditor();
                    Releases::borrarRevision((int)($_POST['id'] ?? 0));
                    $relFin(true, 'Revisión borrada.');

                case 'rel_ia_aplicar':
                    $codRl = (string)($_POST['codigo'] ?? '');
                    $itRl = Releases::itemPorCodigo($codRl);
                    if (!$itRl) throw new RuntimeException('Ese ítem ya no existe.');
                    if (!$relSmDe((string)$itRl['equipo'])) $relFin(false, 'Solo los editores o el Scrum Master del equipo.');
                    $itRl = Releases::vincularTareas((int)$itRl['id'], (array)($_POST['tareas'] ?? []), $relYo, true);
                    if ((int)($_POST['analisis_id'] ?? 0) > 0) Releases::marcarAplicada((int)$_POST['analisis_id'], 'items:' . $codRl);
                    $relFin(true, 'Tareas vinculadas a ' . $itRl['codigo'] . '.', ['item' => $itRl, 'tareas' => array_map($relTarea, Releases::tareasDe($itRl))]);

                case 'rel_conf_guardar':
                    $relSoloEditor();
                    $avRl = (array)($_POST['avisos'] ?? []);
                    $avisosRl = [];
                    foreach (['panel', 'correo', 'cambio_fechas', 'cambio_estado', 'cambio_equipo', 'revision', 'revision_hoy', 'vence_pronto',
                              'atrasados', 'resumen_semanal', 'al_sm', 'al_equipo'] as $kRl) $avisosRl[$kRl] = !empty($avRl[$kRl]);
                    $avisosRl['vence_dias'] = (int)($avRl['vence_dias'] ?? 3);
                    $cambiosRl = ['avisos' => $avisosRl, 'ven_sm' => !empty($_POST['ven_sm']), 'ven_todos' => !empty($_POST['ven_todos'])];
                    // La IA asocia sola cada día las tareas nuevas de cada equipo (casilla con un hidden ia_auto=0 antes)
                    if (isset($_POST['ia_auto'])) $cambiosRl['ia_auto'] = (string)$_POST['ia_auto'] === '1';
                    if (isset($_POST['programa'])) $cambiosRl['programa'] = mb_substr(trim((string)$_POST['programa']), 0, 60);
                    if (isset($_POST['go_live'])) $cambiosRl['go_live'] = Releases::fecha($_POST['go_live']);
                    if (isset($_POST['dailies_desde']) || isset($_POST['dailies_umbral'])) {
                        $cambiosRl['dailies'] = ['desde' => Releases::fecha($_POST['dailies_desde'] ?? '') ?: Releases::conf()['dailies']['desde'],
                                                 'umbral' => min(100, max(10, (int)($_POST['dailies_umbral'] ?? 60)))];
                    }
                    // Solo el administrador decide quién edita (un editor no se nombra a sí mismo)
                    if (Auth::esAdmin() && (isset($_POST['editores']) || isset($_POST['editores_enviado']))) {
                        $cambiosRl['editores'] = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['editores'] ?? [])))));
                    }
                    if (isset($_POST['destinatarios']) || isset($_POST['destinatarios_enviado'])) {
                        $cambiosRl['destinatarios'] = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['destinatarios'] ?? [])))));
                    }
                    Releases::guardarConf($cambiosRl);
                    $relFin(true, 'Ajustes de Releases guardados.');
            }
        } catch (RuntimeException $e) {
            $relFin(false, $e->getMessage());
        }

    /* ---------- Respaldo de la configuracion ---------- */

    case 'config_exportar':
        $json = json_encode(Config::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nombre = 'innotech-config-' . date('Y-m-d') . '.json';
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

    /* ---------- WhatsApp (lib/Whatsapp.php): solo administrador ---------- */
    case 'whatsapp_estado':
        // Lo pide el modal «Ver estado y vincular» de Ajustes cada pocos segundos
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!class_exists('Whatsapp')) { echo json_encode(['ok' => false, 'error' => 'Falta subir lib/Whatsapp.php.']); exit; }
        $c = Whatsapp::conf();
        if ($c['url'] === '' || $c['token'] === '') {
            echo json_encode(['ok' => false, 'error' => 'Primero guarda la dirección de la pasarela y su token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $est = Whatsapp::estado();
        echo json_encode(is_string($est) ? ['ok' => false, 'error' => $est] : ['ok' => true] + $est + ['cola' => Whatsapp::resumenCola()], JSON_UNESCAPED_UNICODE);
        exit;

    case 'whatsapp_prueba':
        $volverW = 'ajustes.php#tab-reuniones';
        if (!class_exists('Whatsapp')) redirigir($volverW, 'Falta subir lib/Whatsapp.php al servidor.', 'error');
        $c = Whatsapp::conf();
        if ($c['url'] === '' || $c['token'] === '') redirigir($volverW, 'Primero guarda la dirección de la pasarela y su token.', 'error');
        $numW = Whatsapp::numeroDe(Auth::usuario() ?? []);
        if ($numW === '') redirigir($volverW, 'Tu ficha no tiene un teléfono válido: ponlo en Mi perfil para recibir la prueba.', 'error');
        $rW = Whatsapp::enviarAhora($numW, "✅ *Prueba de WhatsApp*\nSi te llegó este mensaje, el panel ya puede avisar las reuniones y mandar los códigos para recuperar la contraseña.");
        redirigir($volverW, $rW === true ? 'Listo: te mandamos un WhatsApp de prueba a tu número.' : $rW[1], $rW === true ? 'success' : 'error');

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
        if (!puedeReunionesDelProyecto($pid)) {
            redirigir('proyecto.php?id=' . $pid, 'Solo puedes crear reuniones en tus proyectos.', 'error');
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
        reunionNoPasada($inicio, $volver);
        $invitados = array_values(array_map('intval', (array)($_POST['invitados'] ?? [])));
        $p = $proyectos->buscar($pid);

        if ($plataforma === 'enlace') {
            // Enlace propio: no se llama a ninguna API, el enlace lo pone quien
            // crea la reunión. Se guarda el detalle, se agenda y se avisa igual;
            // lo único que no habrá es grabación.
            $suUrl = trim($_POST['join_url'] ?? '');
            if (!filter_var($suUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $suUrl)) {
                redirigir($volver, 'Pega el enlace de la reunión (tiene que empezar por https://).', 'error');
            }
            $reu = $reuniones->crear([
                'proyecto_id' => $pid,
                'plataforma'  => 'enlace',
                'creador_id'  => (int)(Auth::usuario()['id'] ?? 0),
                'topic'       => $topic,
                'inicio'      => $inicio,
                'duracion'    => $dur,
                'join_url'    => $suUrl,
                'invitados'   => $invitados,
                'recurrente'  => $recurrente,
                'dias'        => $dias,
                'hasta'       => $hasta,
            ]);
            $donde = 'Reunión creada con tu enlace. No quedará grabada.';
        } elseif ($plataforma === 'meet') {
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
            // Una sola cuenta de Zoom para todo el panel: a la hora de otra reunión, no
            if ($choque = Reuniones::choqueZoom($inicio, $dur, $recurrente ? $dias : [], $recurrente ? $hasta : '')) {
                redirigir($volver, Reuniones::textoChoque($choque, $dur, (bool)$recurrente), 'error');
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

        avisoPanel($invitados, 'reunion', 'Te invitaron a una reunión',
            ($reu['topic'] ?? 'Reunión') . ' · ' . ($reu['inicio'] ?? '') . ' · ' . ($p['nombre'] ?? ''), $volver);

        // Avisa a los invitados: con WhatsApp encendido, por WhatsApp (a la cola;
        // por correo a quien no tenga teléfono válido). Si no, por correo.
        $avisados = 0;
        $porWa = 0;
        $paraCorreo = $invitados;
        if (class_exists('Whatsapp') && Whatsapp::activo()) {
            [$porWa, $sinTelWa] = Whatsapp::avisarReunion($reu, $invitados, (array)$p, 'nueva', (int)(Auth::usuario()['id'] ?? 0));
            $paraCorreo = array_map(fn($m) => (int)$m['id'], $sinTelWa);
        }
        if (Mailer::listo()) {
            foreach ($paraCorreo as $mid) {
                $m = $miembros->buscar($mid);
                if ($m && Mailer::notificarReunion($reu, $m, $p) === true) $avisados++;
            }
        }
        redirigir($volver, $donde
            . ($porWa     ? ' ' . $porWa . ' aviso(s) por WhatsApp en camino.' : '')
            . ($avisados  ? ' ' . $avisados . ' invitado(s) notificado(s) por correo.' : '')
            . ($agendados ? ' Agendada en ' . $agendados . ' calendario(s).' : ''));

    case 'reunion_agenda':
        // El selector de hora: lo que Zoom ya tiene ese día, para pintar en gris los cuartos ocupados
        header('Content-Type: application/json; charset=utf-8');
        $reuA = (int)($_POST['id'] ?? 0) > 0 ? (new ReunionRepo())->buscar((int)$_POST['id']) : null;
        $pidA = $reuA ? (int)$reuA['proyecto_id'] : (int)($_POST['proyecto_id'] ?? 0);
        $platA = $reuA ? (string)($reuA['plataforma'] ?? 'zoom') : Reuniones::resolverPlataforma($_POST['plataforma'] ?? '', $proyectos->buscar($pidA));
        $fechaA = (string)($_POST['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaA) || $platA !== 'zoom' || $pidA <= 0 || !puedeReunionesDelProyecto($pidA)) {
            exit('{"ocupado":[]}');
        }
        exit(json_encode(['ocupado' => Reuniones::ocupadoZoom($fechaA, (int)($reuA['id'] ?? 0))], JSON_UNESCAPED_UNICODE));

    case 'reunion_choque':
        // Para el formulario de reunión: ¿Zoom está libre a esa hora? Se pregunta al
        // cambiar la fecha, la duración o la repetición, y si choca se bloquea «Crear».
        header('Content-Type: application/json; charset=utf-8');
        $reuC = (int)($_POST['id'] ?? 0) > 0 ? (new ReunionRepo())->buscar((int)$_POST['id']) : null;
        $pidC = $reuC ? (int)$reuC['proyecto_id'] : (int)($_POST['proyecto_id'] ?? 0);
        $platC = $reuC ? (string)($reuC['plataforma'] ?? 'zoom') : Reuniones::resolverPlataforma($_POST['plataforma'] ?? '', $proyectos->buscar($pidC));
        $inicioC = str_replace('T', ' ', trim((string)($_POST['inicio'] ?? '')));
        $durC = (int)($_POST['duracion'] ?? Reuniones::duracionDefecto());
        $serieC = !empty($_POST['recurrente']);
        $diasC = $serieC ? Reuniones::diasValidos($_POST['dias'] ?? []) : [];
        $hastaC = $serieC ? trim((string)($_POST['hasta'] ?? '')) : '';
        if ($serieC && $diasC) $inicioC = Reuniones::primerInicio($inicioC, $diasC);
        // Editando sin tocar el horario: lo que ya estaba no se vuelve a juzgar
        $mismoC = $reuC && $inicioC === (string)($reuC['inicio'] ?? '') && $durC === (int)($reuC['duracion'] ?? 0)
            && $serieC === !empty($reuC['recurrente']) && $hastaC === (string)($reuC['hasta'] ?? '')
            && $diasC === Reuniones::diasValidos($reuC['dias'] ?? []);
        $choqueC = !$mismoC && $platC === 'zoom' && $pidC > 0 && puedeReunionesDelProyecto($pidC) && strtotime($inicioC)
            ? Reuniones::choqueZoom($inicioC, $durC, $diasC, $hastaC, (int)($reuC['id'] ?? 0)) : null;
        exit(json_encode($choqueC ? ['libre' => false, 'texto' => Reuniones::textoChoque($choqueC, $durC, $serieC)] : ['libre' => true], JSON_UNESCAPED_UNICODE));

    case 'reunion_editar':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if (!$reu) {
            redirigir('index.php', 'Reunión no encontrada.', 'error');
        }
        $pid = (int)$reu['proyecto_id'];
        if (!puedeReunionesDelProyecto($pid)) {
            redirigir('proyecto.php?id=' . $pid, 'Solo puedes editar reuniones de tus proyectos.', 'error');
        }
        // Abierto desde el detalle (reunion.php): «volver» lo trae y se regresa ahí (volverAqui: solo páginas del panel)
        $volver = volverAqui('proyecto.php?id=' . $pid . '#vista-reuniones');
        $topic = trim($_POST['topic'] ?? '');
        $inicio = str_replace('T', ' ', trim($_POST['inicio'] ?? ''));
        $duracion = (int)($_POST['duracion'] ?? Reuniones::duracionDefecto());
        if ($topic === '' || $inicio === '') {
            redirigir($volver, 'Indica el tema y la fecha/hora de la reunión.', 'error');
        }
        $esMeet = ($reu['plataforma'] ?? 'zoom') === 'meet';
        [$recurrente, $dias, $hasta, $inicio] = repeticionReunion($_POST, $inicio, $esMeet ? 'meet' : 'zoom', $volver);
        // Cambiar el tema o los invitados de una que ya empezó, sí; moverla a una hora que ya pasó, no
        if (substr($inicio, 0, 16) !== substr((string)($reu['inicio'] ?? ''), 0, 16)) reunionNoPasada($inicio, $volver);
        $invitadosAntes = array_map('intval', (array)($reu['invitados'] ?? []));
        $invitados = array_values(array_map('intval', (array)($_POST['invitados'] ?? [])));
        // En Zoom, moverla a la hora de otra reunión no (sumar invitados a una que ya estaba, sí)
        $cambiaHorario = $inicio !== (string)($reu['inicio'] ?? '') || $duracion !== (int)($reu['duracion'] ?? 0)
            || (bool)$recurrente !== !empty($reu['recurrente']) || (string)$hasta !== (string)($reu['hasta'] ?? '')
            || Reuniones::diasValidos($dias) !== Reuniones::diasValidos($reu['dias'] ?? []);
        if (!$esMeet && ($reu['plataforma'] ?? 'zoom') === 'zoom' && $cambiaHorario
            && ($choque = Reuniones::choqueZoom($inicio, $duracion, $recurrente ? $dias : [], $recurrente ? $hasta : '', (int)$reu['id']))) {
            redirigir($volver, Reuniones::textoChoque($choque, $duracion, (bool)$recurrente), 'error');
        }

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
        // ¿Cambió algo que los invitados tengan que saber? (para avisarles por WhatsApp)
        $cambioReu = $topic !== (string)($reu['topic'] ?? '') || $inicio !== (string)($reu['inicio'] ?? '')
            || $duracion !== (int)($reu['duracion'] ?? 0) || (bool)$recurrente !== !empty($reu['recurrente'])
            || (string)$hasta !== (string)($reu['hasta'] ?? '')
            || Reuniones::diasValidos($dias) !== Reuniones::diasValidos($reu['dias'] ?? []);
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
        avisoPanel($nuevos, 'reunion', 'Te invitaron a una reunión',
            ($reuActual['topic'] ?? 'Reunión') . ' · ' . ($reuActual['inicio'] ?? '') . ' · ' . ($p['nombre'] ?? ''), $volver);
        // Con WhatsApp: los nuevos reciben la invitación y los que ya estaban, el cambio.
        // Por correo (WhatsApp apagado o sin teléfono válido), solo los nuevos, como siempre.
        $porWa = 0;
        $paraCorreo = $nuevos;
        if (class_exists('Whatsapp') && Whatsapp::activo() && $reuActual) {
            $yoReu = (int)(Auth::usuario()['id'] ?? 0);
            [$porWa, $sinTelWa] = Whatsapp::avisarReunion($reuActual, $nuevos, (array)$p, 'nueva', $yoReu);
            $paraCorreo = array_map(fn($m) => (int)$m['id'], $sinTelWa);
            if ($cambioReu) {
                [$nCambio] = Whatsapp::avisarReunion($reuActual, array_intersect($invitados, $invitadosAntes), (array)$p, 'cambio', $yoReu);
                $porWa += $nCambio;
            }
        }
        if ($paraCorreo && $p && Mailer::listo()) {
            foreach ($paraCorreo as $mid) {
                $m = $miembros->buscar((int)$mid);
                if ($m && Mailer::notificarReunion($reuActual, $m, $p) === true) $avisados++;
            }
        }
        redirigir($volver, 'Reunión actualizada.'
            . ($porWa ? ' ' . $porWa . ' aviso(s) por WhatsApp en camino.' : '')
            . ($avisados ? ' ' . $avisados . ' invitado(s) nuevo(s) notificado(s) por correo.' : ''));

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

        // Transcripción de UN día (ocurrencia de una serie) o de la reunión; de Drive
        $ocurrenciaT = Reuniones::esRecurrente($reu) ? trim((string)($_POST['ocurrencia'] ?? '')) : '';
        $tr = transcripcionReunion($reu, $ocurrenciaT);
        if ($tr['estado'] !== 'ok') {
            redirigir($volver, $tr['msg'], $tr['estado'] === 'error' ? 'error' : 'info');
        }
        $p = $proyectos->buscar((int)$reu['proyecto_id']);
        $nombres = [];
        foreach ((array)($reu['invitados'] ?? []) as $mid) {
            $m = $miembros->buscar((int)$mid);
            if ($m) $nombres[] = $m['nombre'];
        }
        $cab = '# Transcripción de reunión — ' . ($reu['topic'] ?? '') . "\n\n"
            . 'Proyecto: ' . ($p['nombre'] ?? '') . "\n"
            . 'Fecha: ' . ($ocurrenciaT !== '' ? $ocurrenciaT : ($reu['inicio'] ?? '')) . "\n"
            . ($nombres ? 'Participantes: ' . implode(', ', $nombres) . "\n" : '')
            . "\nContexto para Claude: esto es la transcripción automática (Zoom) de una reunión "
            . "del equipo de InnoTech Hub. Úsala para resumir lo hablado, decisiones y tareas pendientes. "
            . "Las tareas del panel se referencian con su #id.\n\n---\n\n";
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(($reu['topic'] ?? 'reunion') . ($ocurrenciaT !== '' ? '-' . $ocurrenciaT : '')));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="transcripcion-' . trim($slug, '-') . '.md"');
        header('Cache-Control: no-store');
        echo $cab . $tr['texto'];
        exit;

    case 'reunion_eliminar':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if ($reu && !puedeReunionesDelProyecto((int)$reu['proyecto_id'])) {
            redirigir('proyecto.php?id=' . (int)$reu['proyecto_id'], 'Solo puedes eliminar reuniones de tus proyectos.', 'error');
        }
        if ($reu) {
            // Con WhatsApp: si le quedaba alguna fecha por delante, los invitados se enteran
            // de que se canceló, y se quita de la cola lo que faltaba (el recordatorio)
            if (class_exists('Whatsapp') && Whatsapp::activo()) {
                Whatsapp::quitarDeReunion((int)$reu['id']);
                if (Whatsapp::quedaPorDelante($reu)) {
                    Whatsapp::avisarReunion($reu, (array)($reu['invitados'] ?? []), (array)$proyectos->buscar((int)$reu['proyecto_id']),
                        'cancelada', (int)(Auth::usuario()['id'] ?? 0));
                }
            }
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
            // Desde el detalle se vuelve a la suite del proyecto (o a su historial); volverAqui solo deja páginas del panel
            redirigir(volverAqui('proyecto.php?id=' . $reu['proyecto_id'] . '#vista-reuniones'), 'Reunión eliminada.');
        }
        redirigir('index.php', 'Reunión no encontrada.', 'error');

    /* ---------- Deploys: "los cambios ya están arriba" ---------- */

    case 'deploy_registrar':
        // Un solo botón: la fecha y la hora las pone el servidor. Lo único que
        // elige el encargado es a qué proyectos afecta esta subida — una misma
        // subida suele tocar varios— y, si quiere, una nota corta. Saturar a
        // quien sube los cambios es la forma de que deje de marcarlo.
        if (!puedeDesplegar()) {
            redirigir('index.php', 'No estás como encargado de los despliegues.', 'error');
        }
        $volverDep = ($_POST['volver'] ?? '') === 'index' ? 'index.php' : 'deploys.php';

        // A qué proyectos afecta. El encargado lo elige al registrar, en el
        // modal, marcado por defecto todo lo que el administrador configuró:
        // quien sube un solo microservicio desmarca el resto, y quien sube
        // todo confirma y ya. El campo 'elegidos' distingue "no marcó ninguno"
        // —que es un error y hay que decírselo— de una petición sin lista
        // (la tira vieja, o un enlace directo), donde valen los configurados.
        $cfgDep   = configDeploys();
        $eligio   = !empty($_POST['elegidos']);
        $pedidos  = (array)($_POST['proyectos'] ?? []);
        if (!$pedidos && $eligio) {
            redirigir($volverDep, 'Marca al menos un proyecto para registrar la subida.', 'error');
        }
        if (!$pedidos) {
            $pedidos = $cfgDep['proyectos']
                ?: array_map(fn($pp) => (int)$pp['id'], $proyectos->todos());
        }
        $pidsDep = [];
        foreach ($pedidos as $pidRaw) {
            $pidDep = (int)$pidRaw;
            if ($pidDep <= 0 || isset($pidsDep[$pidDep])) continue;
            if (!$proyectos->buscar($pidDep) || !proyectoConDeploys($pidDep)) continue;
            $pidsDep[$pidDep] = true;
        }
        $pidsDep = array_keys($pidsDep);
        if (!$pidsDep) {
            redirigir($volverDep, 'No hay proyectos configurados para despliegues. Elígelos en Ajustes → Despliegues.', 'error');
        }

        // Lo que sube este despliegue: las tareas ya completadas de esos
        // proyectos que ningún despliegue anterior se llevó. Una tarea
        // terminada anoche entra sola en la subida de esta mañana, que es como
        // trabaja el equipo.
        $finalesDep = Catalogo::estadosFinales();
        $pendientesDep = [];
        foreach ($pidsDep as $pidDep) {
            foreach ($tareas->delProyecto($pidDep) as $t) {
                if (!in_array($t['estado'] ?? '', $finalesDep, true)) continue;
                if ((int)($t['deploy_id'] ?? 0) > 0) continue;
                $pendientesDep[] = (int)$t['id'];
            }
        }

        $deploysRepo = new DeployRepo();
        $nuevoDep = $deploysRepo->crear([
            'proyectos' => $pidsDep,
            'autor_id'  => (int)(Auth::usuario()['id'] ?? 0),
            'entorno'   => $cfgDep['entorno'],
            // Hasta 1500: las notas de la versión (a mano o con IA) van por líneas.
            'nota'      => mb_substr(trim(str_replace("\r", '', (string)($_POST['nota'] ?? ''))), 0, 1500),
            'tareas'    => $pendientesDep,
        ]);
        foreach ($pendientesDep as $tid) {
            $tareas->actualizar($tid, [
                'deploy_id'     => (int)$nuevoDep['id'],
                'desplegada_en' => $nuevoDep['fecha'],
            ]);
        }
        // Se lleva los builds de GitLab en cola de esos proyectos y avisa a todos (lib/Cronograma.php)
        if (is_file(__DIR__ . '/lib/Cronograma.php')) {
            require_once __DIR__ . '/lib/Cronograma.php';
            try { Cronograma::alRegistrarAMano($nuevoDep); } catch (Throwable $e) { /* el registro ya quedó */ }
        }
        // El mensaje dice A QUÉ se registró, por su alias: "en 3 proyectos" no
        // le sirve de nada a quien acaba de subir ms-academico y quiere estar
        // seguro de que marcó ese y no otro.
        $nDep = count($pendientesDep);
        $comoSeLlaman = array_map(function ($pidDep) use ($proyectos) {
            $pDep = $proyectos->buscar($pidDep);
            return aliasDeploy($pidDep) ?: ($pDep['nombre'] ?? ('#' . $pidDep));
        }, $pidsDep);
        redirigir($volverDep, 'Subida registrada a las ' . substr($nuevoDep['fecha'], 11, 5)
            . ' en ' . implode(', ', $comoSeLlaman)
            . ($nDep ? ' con ' . $nDep . ' tarea' . ($nDep === 1 ? '' : 's') . '.' : '. No había tareas nuevas completadas.'));

    case 'deploy_eliminar':
        // Solo el administrador, y las tareas vuelven a quedar "sin subir":
        // si se borra el registro por error, el PO no puede quedarse creyendo
        // que algo está arriba cuando no lo está.
        $depRepo = new DeployRepo();
        $dep = $depRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$dep) {
            redirigir('deploys.php', 'Ese despliegue ya no existe.', 'error');
        }
        foreach (DeployRepo::tareasDe($dep) as $tid) {
            $t = $tareas->buscar($tid);
            if ($t && (int)($t['deploy_id'] ?? 0) === (int)$dep['id']) {
                $tareas->actualizar($tid, ['deploy_id' => 0, 'desplegada_en' => '']);
            }
        }
        $depRepo->eliminar((int)$dep['id']);
        redirigir('deploys.php', 'Despliegue eliminado.');

    default:
        redirigir('index.php', 'Acción no reconocida.', 'error');
}
