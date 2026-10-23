<?php
/**
 * Cron: avanza la cola «Grabaciones de Zoom → Drive» (lib/ZoomDrive.php) aunque
 * nadie tenga abierta la página. Programarlo en cPanel → Cron Jobs cada 5 minutos:
 *
 *   /usr/local/bin/php /home/…/admin/cron_zoom_drive.php >/dev/null 2>&1
 *
 * Trabaja hasta 4 minutos y medio por vuelta; con un candado, nunca corren dos a
 * la vez (ni con la página). Si no hay nada en cola, termina al instante.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la terminal o el cron.');
}
require_once __DIR__ . '/lib/bootstrap.php';
cronCallado('zoom_drive');   // sin correos de «Cron Daemon»: lo que escribe va a data/cron_zoom_drive.log
foreach (['Correo', 'ZoomDrive'] as $m) {
    if (is_file(__DIR__ . '/lib/' . $m . '.php')) require_once __DIR__ . '/lib/' . $m . '.php';
}
if (!class_exists('ZoomDrive')) exit("Falta lib/ZoomDrive.php\n");

@file_put_contents(__DIR__ . '/data/cron_zoom_drive.json', json_encode(['t' => time()]));
if (!ZoomDrive::listo()) exit("Zoom → Drive todavía no está conectado.\n");

$r = ZoomDrive::paso(270);
echo !empty($r['ocupado']) ? "Otra vuelta sigue trabajando.\n"
    : sprintf("Cola: %d archivo(s) · en Drive: %d · liberado de Zoom: %s%s\n",
        $r['cola'], $r['drive'], ZoomDrive::bytes((int)$r['liberado_bytes']), !empty($r['error']) ? ' · ' . $r['error'] : '');
