<?php
/**
 * WhatsApp: manda los mensajes de la cola y pone en ella los recordatorios de
 * las reuniones que están por empezar. Pensado para correr CADA MINUTO con un
 * cron de cPanel:
 *   * * * * *  /usr/local/bin/php ~/RUTA-DEL-PANEL/admin/cron_whatsapp.php
 *
 * De paso llama a la pasarela, y así cPanel no la apaga por estar quieta (si
 * la apaga, el primer mensaje tarda unos segundos en salir mientras se
 * reconecta). Solo se ejecuta desde la terminal (el cron), nunca desde la web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde el cron.');
}
require_once __DIR__ . '/lib/bootstrap.php';
cronCallado('whatsapp');   // sin correos de «Cron Daemon»: lo que escribe va a data/cron_whatsapp.log
if (!is_file(__DIR__ . '/lib/Whatsapp.php')) exit("Falta lib/Whatsapp.php\n");
require_once __DIR__ . '/lib/Whatsapp.php';
@set_time_limit(58);

// Una sola vuelta a la vez (si una tarda más de un minuto, la siguiente espera su turno)
$candado = fopen(__DIR__ . '/data/cron_whatsapp.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) exit("Ya hay una vuelta en curso.\n");

$estado = ['t' => time(), 'activo' => Whatsapp::activo(), 'servicio' => null,
           'recordatorios' => 0, 'enviados' => 0, 'fallidos' => 0, 'error' => ''];
if ($estado['activo']) {
    $estado['servicio'] = Whatsapp::salud();
    $estado['recordatorios'] = Whatsapp::recordatorios();
    if ($estado['servicio']) {
        $estado = array_merge($estado, Whatsapp::procesarCola(45));
    } else {
        $estado['error'] = 'La pasarela no contesta';
    }
}
// Para que Ajustes y actualizar.php muestren que el cron está corriendo
@file_put_contents(__DIR__ . '/data/cron_whatsapp.json', json_encode($estado, JSON_UNESCAPED_UNICODE));
echo date('Y-m-d H:i:s') . ' · ' . ($estado['activo']
    ? 'enviados: ' . $estado['enviados'] . ' · recordatorios nuevos: ' . $estado['recordatorios'] . ($estado['error'] !== '' ? ' · ' . $estado['error'] : '')
    : 'WhatsApp apagado (todo va por correo)') . "\n";
