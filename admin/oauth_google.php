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

// ¿Es el retorno de "conectar mi calendario" o el de iniciar sesión?
$esCalendario = !empty($_SESSION['oauth_calendario']);
unset($_SESSION['oauth_calendario']);
$volver = $esCalendario ? 'perfil.php' : 'login.php';

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

if (!$miembro) {
    redirigir('login.php',
        'El correo ' . $correo . ' no está registrado en el equipo' . ($nombreG !== '' ? ' y tampoco encontré a nadie llamado "' . $nombreG . '"' : '') . '. Pídele al administrador que lo agregue.',
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
