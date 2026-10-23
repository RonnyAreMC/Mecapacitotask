<?php
/**
 * Semilla de Releases por terminal: carga el plan (releases, equipos, ítems,
 * hitos y revisiones) desde la semilla del repo o desde los dos Excel.
 *
 *   php admin/seed_releases.php                      la semilla de admin/semillas/ (la más nueva)
 *   php admin/seed_releases.php --actualizar         lo de la semilla manda sobre lo que ya está (fechas, estado, equipo)
 *   php admin/seed_releases.php --seco               dice qué haría, sin escribir
 *   php admin/seed_releases.php cronograma.xlsx revisiones.xlsx   desde los Excel (sin --actualizar solo agrega lo nuevo y lo vacío)
 *
 * Sin --actualizar no pisa lo que ya se cambió en el panel: se puede repetir sin riesgo.
 */
require_once __DIR__ . '/lib/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}
require_once __DIR__ . '/lib/Releases.php';

$args  = array_slice($argv, 1);
$modo  = in_array('--actualizar', $args, true) ? 'actualizar' : 'agregar';
$seco  = in_array('--seco', $args, true);
$xlsx  = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

try {
    if ($xlsx) {
        $crono = $revis = null;
        foreach ($xlsx as $f) {
            if (!is_file($f)) exit("No encuentro el archivo: $f\n");
            stripos(basename($f), 'revision') !== false ? $revis = $f : $crono = $f;
        }
        $r = Releases::importar($crono, $revis, $modo, $seco);
        $origen = implode(' + ', array_map('basename', array_filter([$crono, $revis])));
    } else {
        $r = Releases::sembrar(null, $modo, $seco);
        $origen = basename((string)Releases::semilla());
    }
} catch (Throwable $e) {
    exit('No se pudo: ' . $e->getMessage() . "\n");
}
printf("Releases · %s%s (%s)\n", $origen, $seco ? '  (SIMULACIÓN, no escribe)' : '', $modo === 'actualizar' ? 'lo de la semilla manda' : 'solo lo nuevo y lo vacío');
printf("  releases: %d · equipos: %d · ítems nuevos: %d · actualizados: %d · hitos nuevos: %d · revisiones nuevas: %d (%d filas) · feriados: %d\n",
    $r['releases'], $r['equipos'], $r['items_nuevos'], $r['items_actualizados'], $r['hitos'], $r['revisiones'], $r['filas_revision'], $r['feriados']);
if (!$seco) {
    foreach (Releases::equipos() as $n => $e) {
        $items = Releases::items(['equipo' => $n]);
        printf("  %-6s SM %-16s PO %-18s %3d ítems · proyectos del panel: %s\n", $n, $e['sm_nombre'] ?? '—', $e['po_nombre'] ?? '—', count($items),
            $e['proyectos'] ? implode(', ', $e['proyectos']) : 'ninguno (se eligen en Releases → Ajustes)');
    }
}
