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
 * También analiza con IA los correos que cumplen las reglas de «Requerimientos
 * desde tu correo» (lib/ReqCorreo.php).
 * Solo se ejecuta desde la terminal (el cron), nunca desde la web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde el cron.');
}
require_once __DIR__ . '/lib/bootstrap.php';
cronCallado('correo');   // sin correos de «Cron Daemon»: lo que escribe va a data/cron_correo.log
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

// 0) «Tu reunión empieza en 10 minutos» (lib/Reuniones.php): a los invitados, en la campana,
// como notificación nativa y por correo. Va primero porque no puede llegar tarde, y no depende
// de que el correo InnoTech esté encendido.
try {
    $nReu = Reuniones::recordarProximas(10);
    if ($nReu) echo date('Y-m-d H:i:s') . ' · recordatorios de reunión: ' . $nReu . "\n";
} catch (Throwable $e) {
    echo 'Recordatorios de reunión: ' . $e->getMessage() . "\n";
}
// Los recordatorios que cada quien puso en una nota de su pizarra (lib/Pizarra.php)
if (is_file(__DIR__ . '/lib/Pizarra.php')) {
    require_once __DIR__ . '/lib/Pizarra.php';
    try {
        $nNotas = Pizarra::avisarRecordatorios();
        if ($nNotas) echo date('Y-m-d H:i:s') . ' · recordatorios de notas: ' . $nNotas . "\n";
    } catch (Throwable $e) {
        echo 'Recordatorios de notas: ' . $e->getMessage() . "\n";
    }
}
// Los recordatorios de «Mis notas» (lib/Notas.php): en la campana, en sus dispositivos y por correo
if (is_file(__DIR__ . '/lib/Notas.php')) {
    require_once __DIR__ . '/lib/Notas.php';
    try {
        $nMisNotas = Notas::avisarRecordatorios();
        if ($nMisNotas) echo date('Y-m-d H:i:s') . ' · recordatorios de Mis notas: ' . $nMisNotas . "\n";
    } catch (Throwable $e) {
        echo 'Recordatorios de Mis notas: ' . $e->getMessage() . "\n";
    }
}

// El horario de subidas (lib/Cronograma.php): toma los builds de GitLab y, a la hora de cada proyecto,
// registra la subida y avisa a todos. Va al final de cada vuelta, y también si el correo está apagado.
$cronograma = function (): string {
    if (!is_file(__DIR__ . '/lib/Cronograma.php')) return '';
    require_once __DIR__ . '/lib/Cronograma.php';
    // Releases (lib/Releases.php): una vez al día, lo que vence pronto, lo atrasado, las revisiones de hoy y el resumen del lunes
    $rel = '';
    if (is_file(__DIR__ . '/lib/Releases.php')) {
        require_once __DIR__ . '/lib/Releases.php';
        try {
            $sRel = Releases::pasoRapido();   // los ítems al día con lo que el equipo marcó en sus tareas
            $nRel = Releases::paso();
            if ($sRel) $rel .= ' · ítems al día con sus tareas: ' . $sRel;
            if ($nRel) $rel .= ' · avisos de releases: ' . $nRel;
        } catch (Throwable $e) {
            echo 'Releases: ' . $e->getMessage() . "\n";
        }
    }
    // Requerimientos (lib/ReqSeguimiento.php): una vez al día, los que se pasaron de fecha, a quien da seguimiento
    if (is_file(__DIR__ . '/lib/ReqSeguimiento.php')) {
        require_once __DIR__ . '/lib/ReqSeguimiento.php';
        try {
            $nSeg = ReqSeguimiento::paso();
            if ($nSeg) $rel .= ' · avisos de requerimientos vencidos: ' . $nSeg;
        } catch (Throwable $e) {
            echo 'Seguimiento de requerimientos: ' . $e->getMessage() . "\n";
        }
    }
    try {
        $r = Cronograma::paso();
        return ' · builds nuevos: ' . $r['nuevos'] . ($r['deploys'] ? ' · subidas registradas: ' . implode(', ', $r['deploys']) : '') . $rel;
    } catch (Throwable $e) {
        echo 'Horario de subidas: ' . $e->getMessage() . "\n";   // al registro (data/cron_correo.log)
        return '';
    }
};

if (!Correo::activo()) exit("El correo InnoTech está apagado." . $cronograma() . "\n");

$inicio = time();
$avisos = 0;
$reqAnalizados = 0;

// 1) Primero los avisos nativos de los correos nuevos: es lo que no puede esperar
foreach ((Push::activo() ? Push::interesados('correo') : []) as $mid) {
    if (time() - $inicio > 30) break;                       // lo que falte, en la próxima vuelta (un minuto)
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

// 2) Requerimientos desde el correo (lib/ReqCorreo.php): los correos nuevos que
// cumplen las reglas de cada administrador se analizan con IA y quedan
// registrados (o listos para revisar). Pocos por vuelta: la IA tarda.
if (is_file(__DIR__ . '/lib/ReqCorreo.php') && is_file(__DIR__ . '/lib/IA.php') && is_file(__DIR__ . '/lib/IAFunciones.php')) {
    foreach (['ReqCorreo', 'IA', 'IAFunciones', 'Notificaciones'] as $m) {
        if (is_file(__DIR__ . '/lib/' . $m . '.php')) require_once __DIR__ . '/lib/' . $m . '.php';
    }
    foreach (ReqCorreo::cuentasActivas() as $mid) {
        if (time() - $inicio > 42) break;
        $_SESSION['uid'] = $mid;                              // el consumo de IA queda a su nombre
        try {
            $reqAnalizados += ReqCorreo::procesar($mid, 2);
        } catch (Throwable $e) {
            echo 'Requerimientos del correo de #' . $mid . ': ' . $e->getMessage() . "\n";
        }
    }
}

// 3) Copia en el panel (lib/CorreoCopias.php) de lo que llega a Recibidos, de todos los
// buzones sincronizados: así queda guardado aunque otro programa (Gmail por POP, un
// celular con POP) lo saque del servidor después.
$copiados = 0;
if (is_file(__DIR__ . '/lib/CorreoCopias.php')) {
    require_once __DIR__ . '/lib/CorreoCopias.php';
    foreach (array_keys(Correo::correosSincronizados()) as $mid) {
        if (time() - $inicio > 48) break;
        try {
            $copiados += CorreoCopias::copiarNuevos($mid, 10, $inicio + 50);
        } catch (Throwable $e) {
            // servidor caído o clave vencida: se reintenta en la próxima vuelta
        }
    }
}

// Para que Notificaciones → Tus dispositivos muestre que el cron está corriendo
@file_put_contents(__DIR__ . '/data/cron_correo.json', json_encode(['t' => time(), 'avisos' => $avisos, 'requerimientos' => $reqAnalizados, 'copiados' => $copiados]));
echo date('Y-m-d H:i:s') . " · avisos enviados: $avisos · correos analizados para requerimientos: $reqAnalizados · copias guardadas: $copiados" . $cronograma() . "\n";
