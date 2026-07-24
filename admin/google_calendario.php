<?php
/**
 * Manda al usuario (ya logueado) a conceder el permiso de Google Calendar.
 * Es un permiso aparte del acceso, para no bloquear el login del equipo con
 * un scope "sensible". Al volver, oauth_google.php guarda su refresh token.
 */
require_once __DIR__ . '/lib/bootstrap.php';

Auth::requiereLogin();

if (!GoogleCalendar::listo()) {
    redirigir('perfil.php', 'La sincronización con Google Calendar no está activada en Ajustes.', 'error');
}

header('Location: ' . GoogleLogin::urlCalendario());
exit;
