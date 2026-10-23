<?php
/**
 * Horario de subidas (lib/Cronograma.php), solo: toma los builds de GitLab y,
 * a la hora de cada proyecto, registra la subida y avisa a todos.
 *
 * Normalmente NO hace falta: el cron de cada minuto (cron_correo.php) ya lo
 * corre al final de cada vuelta. Sirve para probarlo a mano o si se quiere un
 * cron aparte:
 *   * * * * *  /usr/local/bin/php ~/RUTA-DEL-PANEL/admin/cron_deploys.php
 * Solo desde la terminal, nunca desde la web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde el cron.');
}
require_once __DIR__ . '/lib/bootstrap.php';
cronCallado('deploys');   // sin correos de «Cron Daemon»: lo que escribe va a data/cron_deploys.log
require_once __DIR__ . '/lib/Cronograma.php';
if (is_file(__DIR__ . '/lib/Notificaciones.php')) require_once __DIR__ . '/lib/Notificaciones.php';
@set_time_limit(55);

if (!Cronograma::activo()) exit("El horario de subidas está apagado (Ajustes → Despliegues).\n");
$r = Cronograma::paso();
echo date('Y-m-d H:i:s') . ' · builds nuevos: ' . $r['nuevos'] . ' · avisos previos: ' . $r['avisos']
    . ($r['deploys'] ? ' · subidas registradas: ' . implode(', ', $r['deploys']) : '') . "\n";
