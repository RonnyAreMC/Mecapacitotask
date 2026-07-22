<?php
/**
 * Retorno de "Iniciar sesión con Google".
 * Solo entra si el correo de Google coincide con un colaborador del panel.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (!GoogleLogin::listo()) {
    redirigir('login.php', 'El acceso con Google no está configurado.', 'error');
}
if (!empty($_GET['error'])) {
    redirigir('login.php', 'Cancelaste el acceso con Google.', 'info');
}

$r = GoogleLogin::procesar($_GET['code'] ?? '', $_GET['state'] ?? '');
if (!$r['ok']) {
    redirigir('login.php', $r['error'], 'error');
}

// Buscar al colaborador por correo (no se crean usuarios nuevos)
$miembro = null;
foreach ((new MiembroRepo())->todos() as $m) {
    if (strcasecmp($m['email'] ?? '', $r['email']) === 0) { $miembro = $m; break; }
}
if (!$miembro) {
    redirigir('login.php', 'El correo ' . $r['email'] . ' no está registrado en el equipo. Pídele al administrador que lo agregue.', 'error');
}

Auth::iniciarSesion((int)$miembro['id']);
redirigir('index.php', '¡Bienvenido, ' . explode(' ', $miembro['nombre'])[0] . '!');
