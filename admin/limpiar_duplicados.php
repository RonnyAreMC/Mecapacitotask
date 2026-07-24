<?php
/**
 * Borra tareas duplicadas (mismo proyecto + mismo título), dejando UNA.
 * Solo por terminal. Por defecto muestra qué haría; borra con --aplicar.
 *
 *   php admin/limpiar_duplicados.php                 (vista previa, no borra)
 *   php admin/limpiar_duplicados.php --aplicar        (borra de verdad)
 *   php admin/limpiar_duplicados.php --aplicar CONTABILIDAD   (solo ese proyecto)
 *
 * De cada grupo de repetidas conserva la más reciente (la del último import)
 * y elimina las anteriores.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}
require_once __DIR__ . '/lib/bootstrap.php';

$aplicar = in_array('--aplicar', $argv, true);
$args    = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$filtroProyecto = $args[0] ?? '';

$proyectos = [];
foreach ((new ProyectoRepo())->todos() as $p) {
    $proyectos[(int)$p['id']] = $p['nombre'];
}

$norm = fn(string $s) => preg_replace('/\s+/', ' ', mb_strtolower(trim($s), 'UTF-8'));

// Agrupar por proyecto + título normalizado
$tareas = (new TareaRepo())->todas();
$grupos = [];
foreach ($tareas as $t) {
    $pid = (int)($t['proyecto_id'] ?? 0);
    if ($filtroProyecto !== '' && $norm($proyectos[$pid] ?? '') !== $norm($filtroProyecto)) {
        continue;
    }
    $clave = $pid . '|' . $norm($t['titulo'] ?? '');
    $grupos[$clave][] = $t;
}

$aBorrar = [];
foreach ($grupos as $g) {
    if (count($g) < 2) continue;
    // ordenar por id: conservamos el mayor (último creado), borramos el resto
    usort($g, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $conservar = array_pop($g);
    foreach ($g as $viejo) {
        $aBorrar[] = $viejo;
    }
    printf("• %-32s [%s]  %d copias → conservo #%d, borro %s\n",
        mb_substr($conservar['titulo'], 0, 32),
        $proyectos[(int)$conservar['proyecto_id']] ?? '?',
        count($g) + 1,
        (int)$conservar['id'],
        implode(', ', array_map(fn($v) => '#' . $v['id'], $g)));
}

if (!$aBorrar) {
    exit("\nNo encontré tareas duplicadas" . ($filtroProyecto ? " en «$filtroProyecto»" : '') . ". Todo limpio.\n");
}

echo "\n" . count($aBorrar) . " tarea(s) duplicada(s).\n";
if (!$aplicar) {
    echo "Esto es solo una vista previa. Para borrarlas de verdad, corre:\n";
    echo "  php admin/limpiar_duplicados.php --aplicar" . ($filtroProyecto ? " $filtroProyecto" : '') . "\n";
    exit(0);
}

$repo = new TareaRepo();
$n = 0;
foreach ($aBorrar as $t) {
    if ($repo->eliminar((int)$t['id'])) $n++;
}
echo "✔ $n tarea(s) duplicada(s) eliminada(s).\n";
