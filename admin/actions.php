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
$accionesPublicas   = ['auth_login'];
// Los intercambios los pide y responde la propia gente, no un administrador:
// cada accion comprueba por dentro que la tarea sea suya.
$accionesDeCualquiera = [
    'auth_logout', 'obs_crear', 'perfil_guardar',
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
    return $avisados ? ' 📧 ' . $avisados . ' persona(s) avisada(s) por correo.' : '';
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
 * Si la tarea quedo asignada a alguien nuevo, le envia el correo.
 * Devuelve [sufijo para el mensaje flash, tipo de toast].
 */
function notificarSiAsignada(array $tarea, int $asignadoNuevo, int $asignadoAntes, ProyectoRepo $proyectos, MiembroRepo $miembros): array
{
    if ($asignadoNuevo === 0 || $asignadoNuevo === $asignadoAntes) {
        return ['', 'success'];
    }
    $m = $miembros->buscar($asignadoNuevo);
    $p = $proyectos->buscar((int)($tarea['proyecto_id'] ?? 0));
    if (!$m || !$p) {
        return ['', 'success'];
    }
    $resultado = Mailer::notificarAsignacion($tarea, $m, $p);
    if ($resultado === true) {
        return [' 📧 ' . $m['nombre'] . ' fue notificado por correo.', 'success'];
    }
    if (is_string($resultado)) {
        return [' Pero el correo a ' . $m['nombre'] . ' falló: ' . $resultado, 'error'];
    }
    return ['', 'success']; // sin correo registrado o notificaciones apagadas
}

switch ($accion) {

    /* ---------- Acceso ---------- */

    case 'auth_login':
        if (Auth::login($_POST['usuario'] ?? '', $_POST['clave'] ?? '')) {
            redirigir('index.php', '¡Bienvenido, ' . (Auth::usuario()['nombre'] ?? '') . '!');
        }
        redirigir('login.php', 'Usuario o contraseña incorrectos.', 'error');

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
            'repo'          => trim($_POST['repo'] ?? ''),
            'repo_frontend' => trim($_POST['repo_frontend'] ?? ''),
            'estado'        => $_POST['estado'] ?? 'activo',
            'icono'         => $_POST['icono'] ?? 'fa-rocket',
            'color'         => Catalogo::colorEntrada($_POST),
            'fecha_inicio'  => ProyectoRepo::fecha($_POST['fecha_inicio'] ?? ''),
            'miembros'      => ProyectoRepo::miembrosEntrada($_POST['miembros'] ?? []),
        ]);
        $pAhora = $proyectos->buscar($id);
        $avisoEquipo = avisarNuevosDelProyecto($equipoAntes, (array)($pAhora['miembros'] ?? []), $pAhora, $miembros);
        redirigir('proyecto.php?id=' . $id, 'Proyecto actualizado.' . $avisoEquipo);

    case 'proyecto_estado':
        $id = (int)($_POST['id'] ?? 0);
        $proyectos->actualizar($id, ['estado' => $_POST['estado'] ?? 'activo']);
        redirigir($_POST['volver'] ?? 'index.php', 'Estado del proyecto actualizado.');

    case 'proyecto_eliminar':
        $id = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($id);
        $proyectos->eliminar($id);
        redirigir('index.php', 'Proyecto «' . ($p['nombre'] ?? '') . '» eliminado junto con sus tareas.');

    /* ---------- Tareas ---------- */

    case 'tarea_crear':
        $pid = (int)($_POST['proyecto_id'] ?? 0);
        if (trim($_POST['titulo'] ?? '') === '') {
            redirigir('proyecto.php?id=' . $pid, 'El título de la tarea es obligatorio.', 'error');
        }
        fechasTarea($_POST, 'proyecto.php?id=' . $pid);   // corta si el inicio va después del límite
        $t = $tareas->crear($_POST);
        $dep = $tareas->dependenciaValida((int)$t['id'], (int)($_POST['depende_de'] ?? 0), $pid);
        if ($dep !== (int)$t['depende_de']) {
            $tareas->actualizar((int)$t['id'], ['depende_de' => $dep]);
        }
        [$msg, $tipo] = notificarSiAsignada($t, (int)$t['asignado_id'], 0, $proyectos, $miembros);
        chequearEntrega($pid, $proyectos, $tareas);
        redirigir('proyecto.php?id=' . $pid, 'Tarea creada.' . $msg, $tipo);

    case 'tarea_estado':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if ($t) {
            $tareas->actualizar((int)$t['id'], ['estado' => $_POST['estado'] ?? 'pendiente']);
            chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
            redirigir('proyecto.php?id=' . $t['proyecto_id'], 'Estado actualizado.');
        }
        redirigir('index.php', 'Tarea no encontrada.', 'error');

    case 'tarea_editar':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if (!$t) {
            redirigir('index.php', 'Tarea no encontrada.', 'error');
        }
        $asignadoAntes = (int)($t['asignado_id'] ?? 0);
        [$fIni, $fLim] = fechasTarea($_POST, 'proyecto.php?id=' . $t['proyecto_id']);
        $tareas->actualizar((int)$t['id'], [
            'titulo'       => trim($_POST['titulo'] ?? ''),
            'descripcion'  => trim($_POST['descripcion'] ?? ''),
            'prioridad'    => $_POST['prioridad'] ?? 'media',
            'estado'       => $_POST['estado'] ?? 'pendiente',
            'asignado_id'  => (int)($_POST['asignado_id'] ?? 0),
            'fecha_inicio' => $fIni,
            'fecha_limite' => $fLim,
            'depende_de'   => $tareas->dependenciaValida((int)$t['id'], (int)($_POST['depende_de'] ?? 0), (int)$t['proyecto_id']),
        ]);
        $tActual = $tareas->buscar((int)$t['id']);
        [$msg, $tipo] = notificarSiAsignada($tActual, (int)$tActual['asignado_id'], $asignadoAntes, $proyectos, $miembros);
        chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
        redirigir('proyecto.php?id=' . $t['proyecto_id'], 'Tarea actualizada.' . $msg, $tipo);

    case 'tarea_eliminar':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if ($t) {
            $tareas->eliminar((int)$t['id']);
            chequearEntrega((int)$t['proyecto_id'], $proyectos, $tareas);
            redirigir('proyecto.php?id=' . $t['proyecto_id'], 'Tarea eliminada.');
        }
        redirigir('index.php', 'Tarea no encontrada.', 'error');

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
        if (!esAdmin() && (int)$tMia['asignado_id'] !== $miId) {
            redirigir($volver, 'Solo puedes ofrecer una tarea que sea tuya.', 'error');
        }
        $paraId = (int)$tSuya['asignado_id'];
        if ($paraId === 0) {
            redirigir($volver, 'Esa tarea no tiene responsable: no hay con quién intercambiar.', 'error');
        }
        if ($paraId === (int)$tMia['asignado_id']) {
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
            'de_id'       => (int)$tMia['asignado_id'],
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
            if ($r === true)          $aviso = ' 📧 ' . $mPara['nombre'] . ' fue avisado por correo.';
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
            $tareas->actualizar((int)$tA['id'], ['asignado_id' => (int)$x['para_id']]);
            $tareas->actualizar((int)$tB['id'], ['asignado_id' => (int)$x['de_id']]);
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

    case 'miembro_acceso':
        // Interruptor de administrador (equipo de analistas)
        $m = $miembros->buscar((int)($_POST['id'] ?? 0));
        $volver = $_POST['volver'] ?? 'equipo.php';
        if (!$m) {
            redirigir($volver, 'Colaborador no encontrado.', 'error');
        }
        $eraAdmin = ($m['acceso'] ?? 'lector') === 'admin';
        if ($eraAdmin) {
            // Nunca dejar el panel sin ningun administrador
            $otros = array_filter($miembros->todos(), fn($x) =>
                (int)$x['id'] !== (int)$m['id'] && ($x['acceso'] ?? '') === 'admin');
            if (!$otros) {
                redirigir($volver, 'No puedes quitar al único administrador del panel.', 'error');
            }
            if ((int)$m['id'] === (int)(Auth::usuario()['id'] ?? 0)) {
                redirigir($volver, 'No puedes quitarte a ti mismo el acceso de administrador.', 'error');
            }
        }
        $miembros->actualizar((int)$m['id'], ['acceso' => $eraAdmin ? 'lector' : 'admin']);
        if ($eraAdmin) {
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

        Config::guardar([
            'titulo'           => trim($_POST['titulo'] ?? '') ?: $def['titulo'],
            'subtitulo'        => trim($_POST['subtitulo'] ?? '') ?: $def['subtitulo'],
            'github_token'     => $secreto($_POST['github_token'] ?? '', $prev['github_token'] ?? ''),
            'google_login'     => [
                'activo'              => !empty($_POST['google_login']['activo']),
                'vincular_por_nombre' => !empty($_POST['google_login']['vincular_por_nombre']),
                'client_id'     => trim($_POST['google_login']['client_id'] ?? ''),
                'client_secret' => $secreto($_POST['google_login']['client_secret'] ?? '', $prev['google_login']['client_secret'] ?? ''),
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
            redirigir('ajustes.php', 'Primero guarda la configuración de correo (activo, usuario y contraseña).', 'error');
        }
        $r = Mailer::enviar($para, '✅ Prueba de correo — Panel Mecapacito',
            '<p style="font-family:Arial;font-size:15px;">¡Funciona! 🎉 El panel Mecapacito ya puede enviar notificaciones por correo.</p>');
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
        if (!Zoom::listo()) {
            redirigir($volver, 'Zoom no está configurado. Ve a Ajustes → Zoom.', 'error');
        }
        $topic = trim($_POST['topic'] ?? '');
        $inicio = trim($_POST['inicio'] ?? '');   // datetime-local: Y-m-dTH:i
        $inicio = str_replace('T', ' ', $inicio);
        if ($topic === '' || $inicio === '') {
            redirigir($volver, 'Indica el tema y la fecha/hora de la reunión.', 'error');
        }
        $creada = Zoom::crearReunion([
            'topic'    => $topic,
            'inicio'   => $inicio,
            'duracion' => (int)($_POST['duracion'] ?? 60),
        ]);
        if (isset($creada['error'])) {
            redirigir($volver, $creada['error'], 'error');
        }
        $invitados = array_values(array_map('intval', (array)($_POST['invitados'] ?? [])));
        $reu = $reuniones->crear([
            'proyecto_id' => $pid,
            'zoom_id'     => (string)($creada['id'] ?? ''),
            'topic'       => $topic,
            'inicio'      => $inicio,
            'duracion'    => (int)($_POST['duracion'] ?? 60),
            'join_url'    => $creada['join_url'] ?? '',
            'start_url'   => $creada['start_url'] ?? '',
            'password'    => $creada['password'] ?? '',
            'invitados'   => $invitados,
        ]);
        // Notifica por correo a los invitados con correo registrado
        $avisados = 0;
        if (Mailer::listo()) {
            foreach ($invitados as $mid) {
                $m = $miembros->buscar($mid);
                if ($m && !empty($m['email'])) {
                    $html = '<p style="font-family:Arial;font-size:15px">Hola <b>' . e($m['nombre']) . '</b>, te invitaron a una reunión:</p>'
                        . '<p style="font-family:Arial;font-size:15px"><b>' . e($topic) . '</b><br>' . e($inicio) . '</p>'
                        . '<p><a href="' . e($reu['join_url']) . '" style="background:#2D8CFF;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-family:Arial">Entrar a la reunión</a></p>';
                    if (Mailer::enviar($m['email'], '📹 Reunión: ' . $topic, $html) === true) $avisados++;
                }
            }
        }
        redirigir($volver, 'Reunión creada en Zoom.' . ($avisados ? ' 📧 ' . $avisados . ' invitado(s) notificado(s).' : ''));

    case 'reunion_grabaciones':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if (!$reu) {
            redirigir('index.php', 'Reunión no encontrada.', 'error');
        }
        $volver = 'proyecto.php?id=' . $reu['proyecto_id'] . '#vista-reuniones';
        $g = Zoom::grabaciones($reu['zoom_id']);
        if ($g['estado'] === 'ok') {
            $reuniones->actualizar((int)$reu['id'], ['grabaciones' => $g['archivos'], 'share_url' => $g['share_url'] ?? '']);
            redirigir($volver, count($g['archivos']) . ' archivo(s) de grabación disponibles.');
        }
        redirigir($volver, $g['msg'] ?? 'Sin grabación disponible.', $g['estado'] === 'vacio' ? 'info' : 'error');

    case 'reunion_eliminar':
        $reuniones = new ReunionRepo();
        $reu = $reuniones->buscar((int)($_POST['id'] ?? 0));
        if ($reu) {
            if (!empty($reu['zoom_id']) && Zoom::listo()) Zoom::eliminarReunion($reu['zoom_id']);
            $reuniones->eliminar((int)$reu['id']);
            redirigir('proyecto.php?id=' . $reu['proyecto_id'] . '#vista-reuniones', 'Reunión eliminada.');
        }
        redirigir('index.php', 'Reunión no encontrada.', 'error');

    default:
        redirigir('index.php', 'Acción no reconocida.', 'error');
}
