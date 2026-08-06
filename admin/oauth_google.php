<?php
/**
 * Retorno de "Iniciar sesión con Google".
 *
 * Entra si el correo de Google ya está registrado en un colaborador. Si no,
 * intenta reconocerlo por nombre y apellido contra el equipo (por ejemplo, la
 * cuenta "Jaione Cherres" con el colaborador Jaione Cherres) y, si hay una
 * única coincidencia y esa persona todavía no tiene correo, le asocia el suyo.
 * Nunca se crean colaboradores nuevos.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (!GoogleLogin::listo()) {
    redirigir('login.php', 'El acceso con Google no está configurado.', 'error');
}

// ¿De qué flujo volvemos: calendario, cuenta de envío, crear cuenta o entrar?
$esCalendario = !empty($_SESSION['oauth_calendario']);
$esCorreo     = !empty($_SESSION['oauth_correo']);
$esRegistro   = !empty($_SESSION['oauth_registro']);
unset($_SESSION['oauth_calendario'], $_SESSION['oauth_correo'], $_SESSION['oauth_registro']);
$volver = match (true) {
    $esCalendario => 'perfil.php',
    $esCorreo     => 'ajustes.php#tab-correo',
    $esRegistro   => 'registro.php',
    default       => 'login.php',
};

// --- Conectar la cuenta que envía los correos (solo el administrador) ---
if ($esCorreo) {
    Auth::requiereAdmin();
    if (!empty($_GET['error'])) {
        redirigir('ajustes.php', 'Cancelaste la conexión de la cuenta de envío.', 'info');
    }
    $res = Mailer::guardarConexion($_GET['code'] ?? '', $_GET['state'] ?? '');
    if (is_string($res)) {
        redirigir('ajustes.php', $res, 'error');
    }
    redirigir('ajustes.php',
        'Cuenta de envío conectada: ' . $res['email'] . '. Los correos del panel saldrán desde ahí; pruébalo con «Probar envío».');
}

if (!empty($_GET['error'])) {
    redirigir($volver, $esCalendario ? 'No concediste el permiso de calendario.' : 'Cancelaste el acceso con Google.', 'info');
}

$r = GoogleLogin::procesar($_GET['code'] ?? '', $_GET['state'] ?? '');
if (!$r['ok']) {
    redirigir($volver, $r['error'], 'error');
}

// --- Conectar calendario: el usuario ya está dentro, solo guardamos su token ---
if ($esCalendario) {
    $actual = Auth::usuario();
    if (!$actual) {
        redirigir('login.php', 'Tu sesión expiró. Entra de nuevo y vuelve a conectar tu calendario.', 'error');
    }
    if (empty($r['refresh_token'])) {
        // Google no reenvía el refresh token si ya lo habías concedido antes.
        // Hay que revocar el acceso en myaccount.google.com y reconectar, o
        // aceptar de nuevo la pantalla de permiso (forzamos prompt=consent).
        redirigir('perfil.php',
            'Google no devolvió el permiso esta vez. Si ya habías conectado antes, entra a myaccount.google.com/permissions, quita el acceso a esta app y vuelve a pulsar "Conectar mi calendario".',
            'error');
    }
    // Se guarda el token en la cuenta con la que estás dentro del panel. Si
    // usaste otra cuenta de Google, tus tareas irán a ESE calendario (tu elección).
    (new MiembroRepo())->actualizar((int)$actual['id'], ['gcal_refresh' => $r['refresh_token']]);
    $aviso = 'Conectaste tu Google Calendar (' . $r['email'] . '). Tus tareas con fecha se enviarán ahí.';
    if (strcasecmp($actual['email'] ?? '', $r['email']) !== 0) {
        $aviso .= ' Nota: es una cuenta distinta a la de tu ficha (' . ($actual['email'] ?: 'sin correo') . ').';
    }
    redirigir('perfil.php', $aviso, 'success');
}

$repo     = new MiembroRepo();
$equipo   = $repo->todos();
$correo   = $r['email'];
$nombreG  = trim($r['nombre'] ?? '');
$vinculado = false;

// 1) Correo ya registrado
$miembro = null;
foreach ($equipo as $m) {
    if (strcasecmp($m['email'] ?? '', $correo) === 0) { $miembro = $m; break; }
}

// 2) Reconocer por nombre y apellido (si está permitido en Ajustes)
if (!$miembro && !empty(GoogleLogin::conf()['vincular_por_nombre'])) {
    $candidatos = GoogleLogin::coincidenciasPorNombre($equipo, $nombreG, $correo);

    if (count($candidatos) === 1) {
        $c = $candidatos[0];
        if (!empty($c['email'])) {
            // Ya tiene otro correo: no se pisa en silencio.
            redirigir('login.php',
                'Tu cuenta se parece a ' . $c['nombre'] . ', pero esa persona ya tiene otro correo registrado. Pídele al administrador que lo actualice.',
                'error');
        }
        $repo->actualizar((int)$c['id'], ['email' => $correo]);
        $miembro   = $repo->buscar((int)$c['id']);
        $vinculado = true;
    } elseif (count($candidatos) > 1) {
        redirigir('login.php',
            'Hay más de un colaborador que coincide con "' . $nombreG . '". Pídele al administrador que registre tu correo.',
            'error');
    }
}

// 3a) Venía de "Crear cuenta": no se le conoce de nada, así que deja una
//     solicitud con el nombre y el correo que Google acaba de verificar. No
//     entra a ninguna parte hasta que un administrador la apruebe.
if (!$miembro && $esRegistro) {
    $solicitudes = new SolicitudRepo();

    if (!Auth::registroAbierto()) {
        redirigir('login.php', 'El registro de cuentas nuevas está cerrado.', 'error');
    }
    if (!Auth::dominioPermitido($correo)) {
        redirigir('login.php',
            'Solo se aceptan cuentas de: @' . implode(', @', Auth::dominiosPermitidos()) . '. Entra con tu correo institucional.',
            'error');
    }
    if ($solicitudes->porEmail($correo)) {
        redirigir('login.php', 'Ya tienes una solicitud con ' . $correo . ' esperando aprobación. Te avisaremos en cuanto la revisen.', 'info');
    }

    $solicitud = $solicitudes->crear(['nombre' => $nombreG ?: strtok($correo, '@'), 'email' => $correo]);

    $avisados = 0;
    if (Auth::registro()['avisar']) {
        foreach (Auth::correosAdmin() as $correoAdmin) {
            if (Mailer::solicitudNueva($solicitud, $correoAdmin) === true) $avisados++;
        }
    }
    redirigir('login.php',
        '¡Listo, ' . explode(' ', $solicitud['nombre'])[0] . '! Tu solicitud quedó registrada con ' . $correo
        . ($avisados > 0 ? ' y ya avisamos al administrador.' : '. Un administrador la revisará.')
        . ' Cuando la aprueben, entra con el botón de Google.');
}

// 3b) No lo reconoció: le preguntamos "¿quién eres?" y que se elija a sí mismo
//    de entre las fichas que todavía no tienen correo (y que no son admin).
//    Guardamos el correo YA verificado por Google en sesión para vincularlo
//    cuando confirme (no se puede repetir GoogleLogin::procesar, el code es de un solo uso).
if (!$miembro) {
    $sinVincular = array_filter($equipo, fn($m) => empty($m['email']) && ($m['acceso'] ?? '') !== 'admin');
    if ($sinVincular) {
        $_SESSION['identificar'] = [
            'email'   => $correo,
            'nombre'  => $nombreG,
            'refresh' => $r['refresh_token'] ?? '',
        ];
        redirigir('login.php', 'No reconocimos tu correo. Dinos quién eres para vincularlo a tu ficha.', 'info');
    }
    redirigir('login.php',
        'El correo ' . $correo . ' no está registrado en el equipo'
            . ($nombreG !== '' ? ' y tampoco encontré a nadie llamado "' . $nombreG . '"' : '') . '. '
            . (Auth::registroAbierto()
                ? 'Puedes pedir acceso desde «Crear una cuenta».'
                : 'Pídele al administrador que lo agregue.'),
        'error');
}

// Guarda el refresh token para poder crear eventos en su Google Calendar.
// Solo llega la primera vez que la persona concede el permiso de calendario.
$avisoCal = '';
if (!empty($r['refresh_token'])) {
    $repo->actualizar((int)$miembro['id'], ['gcal_refresh' => $r['refresh_token']]);
    $avisoCal = ' Tus tareas con fecha se enviarán a tu Google Calendar.';
}

Auth::iniciarSesion((int)$miembro['id']);
redirigir(
    'index.php',
    '¡Bienvenido, ' . explode(' ', $miembro['nombre'])[0] . '!'
        . ($vinculado ? ' Vinculé tu cuenta de Google (' . $correo . ') a tu ficha del equipo.' : '')
        . $avisoCal
);
