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
$accionesPublicas   = ['auth_login', 'auth_identificar', 'solicitud_registrar'];
// Los intercambios los pide y responde la propia gente, no un administrador:
// cada accion comprueba por dentro que la tarea sea suya.
$accionesDeCualquiera = [
    'auth_logout', 'obs_crear', 'perfil_guardar', 'mis_tareas_json', 'proyecto_tareas_json',
    'reunion_grabaciones', 'reunion_transcripcion', 'tarea_estado', 'tarea_crear', 'tarea_editar',
    // Quien depende de una tarea de otro equipo puede recordarle por correo
    // (dentro se comprueba que participe en su proyecto y que la dep sea real).
    'dep_recordar',
    // El responsable de un requerimiento suelto puede marcarlo terminado desde
    // su bandeja (dentro se comprueba que sea suyo).
    'req_terminar',
    // El Scrum Master gestiona reuniones de SUS proyectos (cada acción verifica
    // puedeGestionar por dentro; un lector queda fuera igual).
    'reunion_crear', 'reunion_editar', 'reunion_eliminar',
    'intercambio_crear', 'intercambio_responder', 'intercambio_cancelar',
    // El horario de reuniones fijas lo escribe quien lleva cada proyecto (su
    // Scrum Master o su PO); dentro se comprueba con puedeHorarioDelProyecto().
    'rfija_crear', 'rfija_editar', 'rfija_eliminar',
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

function derivarRequerimiento(RequerimientoRepo $repo, int $reqId, array $ids, MiembroRepo $miembros, array $fechas = []): string
{
    $validos = [];
    foreach ($ids as $id) {
        if ($m = $miembros->buscar((int)$id)) $validos[(int)$m['id']] = $m;
    }
    $repo->asignar($reqId, array_keys($validos), $fechas);
    if (!$validos) {
        return '';
    }
    $req = $repo->buscar($reqId) ?? [];
    $avisados = 0;
    $fallo = '';
    foreach ($validos as $mid => $m) {
        // A cada quien se le dice con quien lo comparte: si no, dos personas
        // se ponen a hacer lo mismo sin saberlo.
        $otros = array_map(fn($o) => $o['nombre'], array_diff_key($validos, [$mid => true]));
        $r = Mailer::notificarRequerimiento($req, $m, array_values($otros));
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
 * UUID de la ocurrencia de $fecha (Y-m-d) de una reunión recurrente, leyendo
 * las instancias pasadas en Zoom. Devuelve '' y llena $error con un diagnóstico
 * útil (qué días SÍ tiene Zoom, o el error de la API) cuando no la encuentra.
 * Tolera ±1 día de desfase por zona horaria.
 */
function resolverUuidOcurrencia(array $reu, string $fecha, string &$error): string
{
    $error = '';
    $inst  = Zoom::instancias((string)($reu['zoom_id'] ?? ''));
    if (($inst['estado'] ?? '') !== 'ok') {
        $error = ($inst['msg'] ?? 'No se pudieron leer las ocurrencias en Zoom.')
               . ' — En la app Server-to-Server de Zoom añade el permiso «meeting:read:list_past_instances» (y «cloud_recording:read»); es el que deja ver los días anteriores de una reunión repetida.';
        return '';
    }
    $items = $inst['items'] ?? [];
    // Coincidencia exacta por fecha; si no, la instancia más cercana (±1 día).
    $mejor = null; $mejorDif = 2.0;
    foreach ($items as $it) {
        if (($it['fecha'] ?? '') === $fecha) return (string)$it['uuid'];
        $dif = abs((strtotime((string)($it['fecha'] ?? '')) - strtotime($fecha)) / 86400);
        if ($dif < $mejorDif) { $mejorDif = $dif; $mejor = $it; }
    }
    if ($mejor && $mejorDif <= 1.0) return (string)$mejor['uuid'];

    $dias = array_values(array_filter(array_map(fn($i) => (string)($i['fecha'] ?? ''), $items)));
    $error = $dias
        ? 'Zoom no tiene una grabación del ' . $fecha . '. Días con grabación en Zoom: ' . implode(', ', $dias) . '.'
        : 'Zoom todavía no reporta ninguna ocurrencia grabada de esta reunión (aparecen cuando termina de procesarlas, y solo si se grabó en la nube).';
    return '';
}

/**
 * ¿$fecha (Y-m-d) es el ÚLTIMO día ya pasado de la serie? Para ese, Zoom
 * devuelve la grabación en el endpoint normal de la reunión (sin UUID), así que
 * se puede recuperar aunque no haya permiso para listar instancias.
 */
function esUltimaOcurrencia(array $reu, string $fecha): bool
{
    $ocs = Reuniones::fechasOcurrencias((string)($reu['inicio'] ?? ''), (array)($reu['dias'] ?? []), (string)($reu['hasta'] ?? ''));
    $ultima = '';
    foreach ($ocs as $oc) {
        if (strtotime($oc) <= time()) $ultima = substr($oc, 0, 10);
    }
    return $ultima !== '' && $ultima === $fecha;
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
    if (!$nuevas || !Mailer::listo()) {
        return '';
    }
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
        foreach ([ProyectoRepo::poDe($p), ProyectoRepo::scrumDe($p)] as $mid) if ($mid > 0) $ids[(int)$mid] = true;
    }
    return array_keys($ids);
}

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
 * Campos para registrar CUÁNDO se completó una tarea. Se guarda al entrar a un
 * estado final y se limpia al salir. Con esa fecha, las completadas se ocultan
 * del tablero una semana después (pero siguen guardadas).
 */
function completadaEn(string $nuevo, string $antes): array
{
    $finales  = Catalogo::estadosFinales();
    $esFinal  = in_array($nuevo, $finales, true);
    $eraFinal = in_array($antes, $finales, true);
    if ($esFinal && !$eraFinal) return ['completada_en' => date('Y-m-d')];
    if (!$esFinal && $eraFinal) return ['completada_en' => ''];
    return [];
}

/**
 * Product Owner válido: el id solo cuenta si es un miembro del equipo de
 * ANALISTAS (el PO se elige entre los analistas). Si no, 0 (sin PO).
 */
function poAnalistaValido(int $id, MiembroRepo $miembros): int
{
    if ($id <= 0) return 0;
    $m = $miembros->buscar($id);
    return ($m && MiembroRepo::equipoDe($m) === 'analistas') ? $id : 0;
}

/**
 * Scrum Master válido para un proyecto: el id solo cuenta si esa persona tiene
 * el rol de Scrum Master en el panel. Si no, 0 (el proyecto se queda sin SM y
 * solo el administrador toca su horario).
 */
function scrumValido(int $id, MiembroRepo $miembros): int
{
    if ($id <= 0) return 0;
    $m = $miembros->buscar($id);
    return ($m && ($m['acceso'] ?? '') === 'scrum') ? $id : 0;
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
            $yoLogin = $miembros->buscar((int)($_SESSION['uid'] ?? 0)) ?? [];
            $nombre1 = explode(' ', (string)($yoLogin['nombre'] ?? ''))[0];
            // Primer ingreso de alguien recién aprobado: a Mi perfil a completar
            // sus datos (usuario de Git, correos, foto). Se limpia la marca.
            if (!empty($yoLogin['perfil_pendiente'])) {
                $miembros->actualizar((int)$yoLogin['id'], ['perfil_pendiente' => false]);
                redirigir('perfil.php', '¡Bienvenido, ' . $nombre1 . '! Completa tus datos (usuario de Git, correos y foto) para que se cuenten tus commits.');
            }
            redirigir('index.php', '¡Bienvenido, ' . $nombre1 . '!');
        }
        // Si se registró y todavía no lo aprueban, decírselo: si no, parece
        // que su contraseña está mal y la vuelve a pedir una y otra vez.
        if ((new SolicitudRepo())->porLogin($_POST['usuario'] ?? '')) {
            redirigir('login.php', 'Tu solicitud de acceso sigue pendiente. Te avisaremos por correo en cuanto un administrador la apruebe.', 'info');
        }
        redirigir('login.php', 'Usuario o contraseña incorrectos.', 'error');

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
            'color'         => Catalogo::colorEntrada($_POST),
            'fecha_inicio'  => ProyectoRepo::fecha($_POST['fecha_inicio'] ?? ''),
            'miembros'      => ProyectoRepo::miembrosEntrada($_POST['miembros'] ?? []),
            'po'            => poAnalistaValido((int)($_POST['po'] ?? 0), $miembros),
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
        // La descripción es texto enriquecido (se puede pegar tablas): se sanea
        // a lista blanca antes de guardar para que no entre HTML peligroso.
        $datosTarea['descripcion'] = HtmlRico::limpiar($_POST['descripcion'] ?? '');
        $datosTarea['adjuntos'] = guardarAdjuntos('adjuntos', 'tarea_', $rechazados);
        $t = $tareas->crear($datosTarea);
        $deps = $tareas->dependenciasValidas((int)$t['id'], TareaRepo::dependenciasEntrada($_POST), $pid);
        $tareas->actualizar((int)$t['id'], ['dependencias' => $deps, 'depende_de' => $deps[0] ?? 0]);
        $tCreada = $tareas->buscar((int)$t['id']);
        [$msg, $tipo] = notificarSiAsignada($t, TareaRepo::asignadosDe($t), [], $proyectos, $miembros);
        $msg .= notificarDepsExternas($tCreada, [], $deps, $proyectos, $miembros, $tareas);
        sincronizarCalendario($tCreada, $proyectos, $miembros, $tareas);
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
        $req = $reqRepo->crear($_POST + [
            'creado_por' => (int)(Auth::usuario()['id'] ?? 0),
            'adjuntos'   => guardarAdjuntos('adjuntos'),
        ]);
        // Se puede asignar de una vez, sin pasar dos veces por el formulario
        $avisoReq = derivarRequerimiento($reqRepo, (int)$req['id'], (array)($_POST['asignados'] ?? []), $miembros, $fechasReq);
        redirigir('requerimientos.php', $avisoReq === ''
            ? 'Requerimiento registrado. Queda sin asignar hasta que le pongas responsables.'
            : 'Requerimiento registrado y asignado a ' . $avisoReq);

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
        $avisadosEd = 0;
        foreach ($nuevosEd as $mid) {
            $otros = array_map(fn($o) => $o['nombre'], array_diff_key($validosEd, [$mid => true]));
            if (Mailer::notificarRequerimiento($reqActEd, $validosEd[$mid], array_values($otros)) === true) {
                $avisadosEd++;
            }
        }
        $sufijoEd = $avisadosEd
            ? ' Avisamos por correo a ' . $avisadosEd . ' responsable' . ($avisadosEd === 1 ? '' : 's') . ' nuevo' . ($avisadosEd === 1 ? '' : 's') . '.'
            : '';
        redirigir('requerimientos.php', '«' . trim($_POST['titulo']) . '» actualizado.' . $sufijoEd);

    case 'req_asignar':
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$req) {
            redirigir('requerimientos.php', 'Ese requerimiento ya no existe.', 'error');
        }
        $aviso = derivarRequerimiento($reqRepo, (int)$req['id'], (array)($_POST['asignados'] ?? []),
                                      $miembros, fechasRequerimiento($_POST));
        if ($aviso === '') {
            redirigir('requerimientos.php', '«' . $req['titulo'] . '» vuelve a «sin asignar».', 'info');
        }
        redirigir('requerimientos.php', '«' . $req['titulo'] . '» es de ' . $aviso);

    case 'req_estado':
        $reqRepo = new RequerimientoRepo();
        $req = $reqRepo->buscar((int)($_POST['id'] ?? 0));
        if (!$req) {
            redirigir('requerimientos.php', 'Ese requerimiento ya no existe.', 'error');
        }
        $estadoReq = RequerimientoRepo::estadoValido($_POST['estado'] ?? '');
        $reqRepo->actualizar((int)$req['id'], ['estado' => $estadoReq]);
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
        $avisoTerm  = notificarReqTerminado($reqTermAct, $miembros->buscar($yoTerm) ?? [], $miembros, $notaTerm);
        redirigir('bandeja.php', 'Marcaste «' . ($req['titulo'] ?? '') . '» como terminado.' . $avisoTerm);

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
        $datos = $_POST;
        $datos['foto'] = guardarFoto('foto');
        $m = $miembros->crear($datos);
        // Acceso al panel (rol + contraseña opcional)
        $accesoNuevo = Auth::accesoValido($_POST['acceso'] ?? '');
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
            'nombre'     => trim($_POST['nombre'] ?? ''),
            'rol'        => trim($_POST['rol'] ?? ''),
            'git_user'   => ltrim(trim($_POST['git_user'] ?? ''), '@'),
            'git_emails' => MiembroRepo::gitEmailsEntrada($_POST['git_emails'] ?? ''),
            'email'    => filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
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
        // Select de acceso en la tabla de equipo (admin / solo lectura)
        $m = $miembros->buscar((int)($_POST['id'] ?? 0));
        $volver = volverAqui('equipo.php');
        if (!$m) {
            redirigir($volver, 'Colaborador no encontrado.', 'error');
        }
        $nuevo   = Auth::accesoValido($_POST['acceso'] ?? '');
        $actual  = $m['acceso'] ?? 'lector';
        $eraAdmin = $actual === 'admin';
        if ($nuevo === $actual) {
            redirigir($volver);   // sin cambios
        }
        // Al dejar de ser admin (a scrum o a lector): nunca dejar el panel sin
        // administrador, ni quitarse uno mismo el acceso.
        if ($eraAdmin && $nuevo !== 'admin') {
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
        $etiqueta = Auth::ROLES[$nuevo] ?? 'Solo lectura';
        if ($nuevo === 'lector') {
            redirigir($volver, $m['nombre'] . ' vuelve a solo lectura.');
        }
        $falta = empty($m['pass_hash'])
            ? ' Todavía no tiene contraseña: pónsela al editar su ficha o que entre con Google.'
            : '';
        redirigir($volver, $m['nombre'] . ' ahora es ' . $etiqueta . '.' . $falta, $falta ? 'info' : 'success');

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
        if (!puedeReunionesDelProyecto($pid)) {
            redirigir('proyecto.php?id=' . $pid, 'Solo puedes editar reuniones de tus proyectos.', 'error');
        }
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

        // Grabación de UN día concreto (ocurrencia de una reunión recurrente):
        // se localiza la instancia de Zoom de ese día para leer SU grabación,
        // no la de toda la serie.
        $ocurrencia = trim((string)($_POST['ocurrencia'] ?? ''));
        $uuidOc = '';
        if ($ocurrencia !== '' && Reuniones::esRecurrente($reu)) {
            $errOc  = '';
            $uuidOc = resolverUuidOcurrencia($reu, $ocurrencia, $errOc);
            if ($uuidOc === '' && !esUltimaOcurrencia($reu, $ocurrencia)) {
                // No es el último día y no se pudo resolver su UUID: sin el UUID
                // no se puede pedir la grabación de un día anterior.
                redirigir($volver, $errOc, 'info');
            }
            // Si es el último día ya pasado, seguimos con $uuidOc='' y se lee el
            // endpoint normal de la reunión (Zoom devuelve ahí la última).
        }

        $g = Zoom::grabaciones($reu['zoom_id'], (string)($reu['password'] ?? ''), $uuidOc);
        if ($g['estado'] === 'ok') {
            if ($ocurrencia !== '') {
                // Se guarda en un mapa por día; la lista de reuniones lo lee para
                // pintar el "▶ Grabación" de ESE día.
                $mapaOc = is_array($reu['grab_ocurrencias'] ?? null) ? $reu['grab_ocurrencias'] : [];
                $mapaOc[$ocurrencia] = [
                    'archivos'      => $g['archivos'],
                    'share_url'     => $g['share_url'] ?? '',
                    'grab_password' => $g['password'] ?? '',
                ];
                $reuniones->actualizar((int)$reu['id'], ['grab_ocurrencias' => $mapaOc]);
            } else {
                $reuniones->actualizar((int)$reu['id'], [
                    'grabaciones'   => $g['archivos'],
                    'share_url'     => $g['share_url'] ?? '',
                    'grab_password' => $g['password'] ?? '',
                ]);
            }
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

        // Transcripción de UN día (ocurrencia de una serie): se resuelve su UUID.
        $ocurrenciaT = trim((string)($_POST['ocurrencia'] ?? ''));
        $uuidT = '';
        if ($ocurrenciaT !== '' && Reuniones::esRecurrente($reu)) {
            $errT  = '';
            $uuidT = resolverUuidOcurrencia($reu, $ocurrenciaT, $errT);
            if ($uuidT === '' && !esUltimaOcurrencia($reu, $ocurrenciaT)) {
                redirigir($volver, $errT, 'info');
            }
            // Último día pasado: se lee el endpoint normal (Zoom da ahí la última).
        }

        $tr = Zoom::transcripcion((string)$reu['zoom_id'], $uuidT);
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
