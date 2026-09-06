<?php
/**
 * Semilla del equipo por terminal: carga colaboradores desde una hoja.
 *
 *   Apellidos ; Nombres ; Correo ; Proyecto (o Equipo)
 *
 * Es el mismo motor que usa el botón «Cargar equipo» de la pantalla de Equipo
 * (lib/ImportadorEquipo.php): acepta .xlsx y .csv, no duplica fichas y, si la
 * última columna trae el nombre de un proyecto que existe, deja a esa gente
 * vinculada a él.
 *
 * Uso:
 *   php admin/seed_equipo.php                       lee admin/data/equipo.csv
 *   php admin/seed_equipo.php ruta/al/archivo.xlsx  lee ese archivo
 *   php admin/seed_equipo.php equipo.csv --seco     enseña qué haría, sin escribir
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}

$argumentos = array_slice($argv, 1);
$seco    = in_array('--seco', $argumentos, true) || in_array('--dry-run', $argumentos, true);
$archivo = '';
foreach ($argumentos as $a) {
    if (!str_starts_with($a, '--')) { $archivo = $a; break; }
}
if ($archivo === '') {
    $archivo = __DIR__ . '/data/equipo.csv';
}
if (!is_file($archivo)) {
    exit("No encuentro el archivo: $archivo\n"
       . "Crea admin/data/equipo.csv (o pásame un .xlsx) con las columnas:\n"
       . "Apellidos, Nombres, Correo y Proyecto.\n");
}

try {
    $filas = ImportadorEquipo::leer($archivo, basename($archivo));
} catch (Throwable $e) {
    exit('No se pudo leer: ' . $e->getMessage() . "\n");
}

$r = ImportadorEquipo::aplicar($filas, $seco);

echo 'Semilla del equipo · ' . basename($archivo) . ($seco ? "  (SIMULACIÓN, no escribe)\n" : "\n");
echo str_repeat('-', 92), "\n";
foreach ($r['detalle'] as $d) {
    $marca = match ($d['accion']) { 'nuevo' => '+', 'actualiza' => '~', default => '=' };
    printf("  %s  %-34s %-32s %-14s %s\n",
        $marca,
        mb_substr($d['nombre'], 0, 34),
        $d['correo'] ?: '—',
        mb_substr((string)($d['proyecto'] ?: $d['equipo']), 0, 14),
        $d['nota']);
}
echo str_repeat('-', 92), "\n";
printf("nuevos: %d · actualizados: %d · sin cambios: %d\n", $r['nuevos'], $r['actualizados'], $r['iguales']);
foreach ($r['proyectos'] as $nombre => $cuantos) {
    // Sin llaves, PHP se come el «» como parte del nombre de la variable
    echo '  → ' . $cuantos . ' vinculados al proyecto «' . $nombre . "»\n";
}
foreach ($r['desconocidos'] as $nombreProy => $cuantos) {
    // Que el proyecto no exista no frena la carga: entran igual
    echo '  · «' . $nombreProy . '» no es un proyecto del panel: esas ' . $cuantos
       . " personas entran como colaboradoras y las asignas después\n";
}
foreach ($r['avisos'] as $a) {
    echo "  ! $a\n";
}
if ($r['sinProyecto']) {
    // Juntas en una línea: con 20 filas, un aviso por cada una tapaba el resto
    echo '  · ' . $r['sinProyecto'] . " quedan solo como colaboradores, sin proyecto\n";
}

echo $seco
    ? "\nSimulación: no se escribió nada. Quita --seco para aplicarlo.\n"
    : "\nListo. Nadie entra al panel todavía: el acceso se da desde Equipo\n"
      . "(o entran con Google, que los reconoce por su correo).\n";
