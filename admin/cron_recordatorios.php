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

/* ---------- Parte de requerimientos sueltos atrasados ----------

   Al responsable ya se le puede escribir a mano una observación; lo que
   faltaba era que el administrador se enterara SIN tener que entrar a mirar.
   Va agrupado por persona y en un solo correo: con quince atrasados, quince
   correos no los lee nadie.

   Se manda una vez al día: cada requerimiento se marca con la fecha en la que
   entró en el parte, igual que las tareas con 'recordado_en'. Si el cron corre
   dos veces, la segunda no encuentra ninguno sin marcar y no manda nada. */
$reqRepo  = new RequerimientoRepo();
$atrasados = [];
$sinAvisar = [];
foreach ($reqRepo->todos() as $r) {
    if (!RequerimientoRepo::vencido($r)) continue;
    $atrasados[] = $r;
    if (($r['atraso_avisado_en'] ?? '') !== $hoy->format('Y-m-d')) $sinAvisar[] = (int)$r['id'];
}

if (!$atrasados) {
    echo "Requerimientos atrasados: 0\n";
} elseif (!$sinAvisar) {
    echo "Requerimientos atrasados: " . count($atrasados) . " (ya avisados hoy)\n";
} else {
    // Agrupado por responsable. Los que no tiene nadie van juntos al final:
    // son los que peor están y no aparecerían en ninguna otra parte.
    $porPersona = [];
    $huerfanos  = [];
    foreach ($atrasados as $r) {
        $fin  = RequerimientoRepo::fechaFin($r);
        $dias = (int)$hoy->diff(new DateTime($fin))->format('%a');
        $item = ['titulo' => (string)($r['titulo'] ?? ''), 'fin' => $fin, 'dias' => $dias];
        $suyos = RequerimientoRepo::asignadosDe($r);
        if (!$suyos) { $huerfanos[] = $item; continue; }
        foreach ($suyos as $mid) {
            $m = $miembros->buscar((int)$mid);
            if (!$m) continue;
            $porPersona[(int)$mid]['nombre'] = (string)$m['nombre'];
            $porPersona[(int)$mid]['items'][] = $item;
        }
    }
    // Quien más acumula, primero
    uasort($porPersona, fn($a, $b) => count($b['items']) <=> count($a['items']));
    $lista = array_values($porPersona);
    if ($huerfanos) $lista[] = ['nombre' => 'Sin responsable', 'items' => $huerfanos];

    $avisados = 0;
    foreach (Auth::correosAdmin() as $correoAdmin) {
        if (Mailer::atrasosRequerimientos($lista, $correoAdmin) === true) $avisados++;
    }
    if ($avisados) {
        foreach ($sinAvisar as $rid) {
            $reqRepo->actualizar($rid, ['atraso_avisado_en' => $hoy->format('Y-m-d')]);
        }
    }
    echo "Requerimientos atrasados: " . count($atrasados)
       . " · parte enviado a $avisados administrador" . ($avisados === 1 ? '' : 'es') . "\n";
}
