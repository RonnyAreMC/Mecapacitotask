<?php
/**
 * Migra los datos de los .json viejos a SQLite. Se ejecuta UNA vez por terminal
 * despues de actualizar (git pull). Es idempotente: si ya se migro, no repite.
 *
 *   php admin/migrar_sqlite.php
 *
 * En realidad la migracion tambien ocurre sola la primera vez que se abre el
 * panel (JsonStore importa el .json al crear cada tabla). Este script sirve
 * para hacerlo de forma controlada y ver el resultado antes de que entren los
 * usuarios, y para confirmar que el servidor tiene SQLite.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}

require_once __DIR__ . '/lib/Storage.php';

if (!JsonStore::disponible()) {
    fwrite(STDERR, "ERROR: este PHP no tiene el driver de SQLite (pdo_sqlite).\n");
    fwrite(STDERR, "En cPanel: MultiPHP INI Editor / Select PHP Version -> Extensions -> activa 'pdo_sqlite' (y 'sqlite3').\n");
    exit(1);
}

$dir = __DIR__ . '/data';
$colecciones = [];
foreach (glob($dir . '/*.json') ?: [] as $ruta) {
    $nombre = basename($ruta, '.json');
    // Solo las colecciones de JsonStore: no config ni los caches
    if ($nombre === 'config' || str_starts_with($nombre, 'cache_')) continue;
    $datos = json_decode((string)file_get_contents($ruta), true);
    if (is_array($datos) && isset($datos['items'])) {
        $colecciones[] = $nombre;
    }
}

echo "Migrando a SQLite (data/panel.sqlite)...\n";
if (!$colecciones) {
    echo "  No hay .json que migrar (¿instalación nueva o ya migrada?).\n";
}
foreach ($colecciones as $coleccion) {
    // Construir el store crea la tabla e importa el .json si aún no existía.
    $store = new JsonStore($coleccion);
    $n = count($store->all());
    $migrado = file_exists($dir . '/' . $coleccion . '.json.importado');
    printf("  %-15s %4d registros %s\n", $coleccion, $n, $migrado ? '(importado)' : '(ya estaba)');
}

echo "\nListo. Los .json viejos quedaron como *.json.importado (respaldo).\n";
echo "El panel ya usa SQLite. Puedes borrar los *.importado cuando confirmes que todo va bien.\n";
