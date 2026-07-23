<?php
/**
 * Recordatorios de tareas próximas a vencer.
 * Pensado para ejecutarse UNA VEZ AL DÍA con un cron de cPanel:
 *   /usr/local/bin/php ~/mchub.mecapacito.com/admin/cron_recordatorios.php
 *
 * Envía un correo al asignado por cada tarea (no completada) cuya fecha
 * límite cae exactamente dentro de los "días antes" configurados en Ajustes.
 * Sin duplicados: cada tarea recuerda el día en que ya avisó.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (!Mailer::listo()) { echo "Correo no configurado.\n"; exit; }
$conf = Mailer::config();
if (empty($conf['avisar_recordatorio'])) { echo "Recordatorios desactivados.\n"; exit; }

$dias      = max(0, (int)($conf['dias_recordatorio'] ?? 3));
$finales   = Catalogo::estadosFinales();
$tareas    = new TareaRepo();
$miembros  = new MiembroRepo();
$proyectos = new ProyectoRepo();
$hoy       = new DateTime('today');
$enviados  = 0;

foreach ($tareas->todas() as $t) {
    if (empty($t['fecha_limite'])) continue;
    if (in_array($t['estado'] ?? '', $finales, true)) continue;        // ya entregada
    $responsables = TareaRepo::asignadosDe($t);
    if (!$responsables) continue;

    $limite = DateTime::createFromFormat('Y-m-d', $t['fecha_limite']);
    if (!$limite) continue;
    $restan = (int)$hoy->diff($limite)->format('%r%a');               // días (negativo si venció)
    if ($restan < 0 || $restan > $dias) continue;                     // solo dentro de la ventana

    // Un aviso por tarea al día
    if (($t['recordado_en'] ?? '') === $hoy->format('Y-m-d')) continue;

    $p = $proyectos->buscar((int)$t['proyecto_id']);
    if (!$p) continue;

    // Recordar a cada responsable con correo
    $algunEnvio = false;
    foreach ($responsables as $mid) {
        $m = $miembros->buscar((int)$mid);
        if (!$m || empty($m['email'])) continue;
        if (Mailer::recordatorioTarea($t, $m, $p, $restan) === true) {
            $algunEnvio = true;
            $enviados++;
        }
    }
    if ($algunEnvio) {
        $tareas->actualizar((int)$t['id'], ['recordado_en' => $hoy->format('Y-m-d')]);
    }
}

echo "Recordatorios enviados: $enviados\n";
