<?php
/**
 * Avisos al instante de los correos InnoTech nuevos (notificación nativa).
 * Pensado para correr CADA MINUTO con un cron de cPanel:
 *   * * * * *  /usr/local/bin/php ~/RUTA-DEL-PANEL/admin/cron_correo.php
 *
 * Revisa solo los buzones sincronizados de quienes tienen algún dispositivo
 * con los avisos de correo encendidos. Por cada correo nuevo sin leer manda
 * «Correo de Fulano · Asunto» a sus dispositivos; si llegaron muchos juntos,
 * un solo aviso con el total. También deja al día el conteo de la campana.
 * Solo se ejecuta desde la terminal (el cron), nunca desde la web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde el cron.');
}
require_once __DIR__ . '/lib/bootstrap.php';
foreach (['Correo', 'Push'] as $m) {
    if (!is_file(__DIR__ . '/lib/' . $m . '.php')) exit("Falta lib/$m.php\n");
    require_once __DIR__ . '/lib/' . $m . '.php';
}
// Para el número del ícono (avisos + correos sin leer); si falta, solo los correos
if (is_file(__DIR__ . '/lib/Notificaciones.php')) require_once __DIR__ . '/lib/Notificaciones.php';
@set_time_limit(55);

// Una sola revisión a la vez (si una tarda más de un minuto, la siguiente espera su turno)
$candado = fopen(__DIR__ . '/data/cron_correo.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) exit("Ya hay una revisión en curso.\n");

if (!Correo::activo() || !Push::activo()) exit("Correo o avisos nativos apagados.\n");

$inicio = time();
$avisos = 0;
foreach (Push::interesados('correo') as $mid) {
    if (time() - $inicio > 45) break;                       // que no se pise con el próximo minuto
    if (!Correo::conectado($mid)) continue;
    try {
        $nuevos = Correo::revisarNuevos($mid);
    } catch (CorreoError $e) {
        continue;                                            // servidor caído o clave vencida: se reintenta luego
    }
    if (!$nuevos) continue;
    // El número del ícono: revisarNuevos ya dejó al día los correos sin leer
    $pend = class_exists('Notif') ? Notif::pendientes($mid) : (int)(Correo::noLeidos($mid) ?? 0);
    // Al tocarlo se abre la app del correo (correo.php); si aún no se subió, el correo dentro del panel
    $appCorreo = is_file(__DIR__ . '/correo.php');
    $urlUno = fn(int $uid) => $appCorreo ? 'correo.php?uid=' . $uid : 'notificaciones.php?tab=correo&uid=' . $uid;
    if (count($nuevos) > 3) {
        $avisos += Push::enviar([$mid], [
            'titulo' => count($nuevos) . ' correos nuevos',
            'texto'  => implode(' · ', array_map(fn($n) => $n['asunto'] !== '' ? $n['asunto'] : '(sin asunto)', array_slice($nuevos, -3))),
            'url'    => $appCorreo ? 'correo.php' : 'notificaciones.php?tab=correo',
            'tag'    => 'correo',
            'n'      => $pend,
        ], 'correo');
        continue;
    }
    foreach ($nuevos as $n) {
        $quien = $n['de']['nombre'] !== '' ? $n['de']['nombre'] : $n['de']['email'];
        $avisos += Push::enviar([$mid], [
            'titulo' => 'Correo de ' . ($quien !== '' ? $quien : 'alguien'),
            'texto'  => $n['asunto'] !== '' ? $n['asunto'] : '(sin asunto)',
            'url'    => $urlUno((int)$n['uid']),
            'tag'    => 'correo-' . (int)$n['uid'],
            'n'      => $pend,
        ], 'correo');
    }
}
// Para que Notificaciones → Tus dispositivos muestre que el cron está corriendo
@file_put_contents(__DIR__ . '/data/cron_correo.json', json_encode(['t' => time(), 'avisos' => $avisos]));
echo date('Y-m-d H:i:s') . " · avisos enviados: $avisos\n";
