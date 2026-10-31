<?php
/**
 * Pasa ítems de Releases a otro equipo, por terminal (Releases::moverItems):
 * el cambio queda en el historial de cada ítem con su motivo (y se avisa como
 * cualquier cambio de equipo) y los ítems salen de las revisiones que el
 * equipo de antes todavía no hace, si ahí seguían sin revisar.
 *
 *   php admin/mover_items.php Académico R1-A04 R2-A01 --motivo="Por orden de Felipe" [--por=Ronny] [--seco]
 *
 * El equipo se busca por nombre, sin importar tildes ni mayúsculas (basta una
 * parte si es una sola). --por: quién hace el cambio (nombre o correo de un
 * miembro). --seco: dice qué haría, sin escribir.
 */
require_once __DIR__ . '/lib/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}
require_once __DIR__ . '/lib/Releases.php';

$opc = [];
$libres = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $opc[$m[1]] = $m[2] ?? true;
    else $libres[] = $a;
}
$seco = !empty($opc['seco']);
$motivo = trim((string)($opc['motivo'] ?? ''));
$destino = array_shift($libres);
if ($destino === null || !$libres) exit("Uso: php admin/mover_items.php <equipo> <código> [<código>…] --motivo=\"…\" [--por=nombre] [--seco]\n");

$parecidos = Releases::buscarEquipo($destino);
if (count($parecidos) !== 1) {
    exit(($parecidos ? "«{$destino}» puede ser varios equipos" : "No hay un equipo «{$destino}»") . '. Los equipos son: ' . implode(', ', array_keys(Releases::equipos()))
        . ".\nSi es nuevo, créalo antes en Releases → Ajustes → Equipos.\n");
}
$equipo = $parecidos[0];

$quien = 0;
if (($opc['por'] ?? '') !== '') {
    $por = Releases::normal((string)$opc['por']);
    foreach ((new MiembroRepo())->todos() as $m) {
        if (Releases::normal((string)($m['email'] ?? '')) === $por || str_contains(Releases::normal((string)($m['nombre'] ?? '')), $por)) { $quien = (int)$m['id']; break; }
    }
    if (!$quien) echo "Ojo: no encontré a «{$opc['por']}» entre los miembros; el cambio queda sin autor.\n";
}

echo ($seco ? '[en seco] ' : '') . 'A ' . $equipo . ($motivo !== '' ? " · motivo: $motivo" : '') . "\n";
try {
    $res = Releases::moverItems($equipo, $libres, $motivo, $quien, $seco);
} catch (Throwable $e) {
    exit('No se pudo: ' . $e->getMessage() . "\n");
}
foreach ($res as $cod => $r) {
    echo '  ' . $cod . ($r['titulo'] !== '' ? ' · ' . $r['titulo'] : '') . ': '
        . ($r['hecho'] ? $r['antes'] . ' → ' . $equipo . ($r['sesiones'] ? ' · sale de ' . count($r['sesiones']) . ' revisión(es) de ' . $r['antes']
            . ' (' . implode(', ', array_map(fn($f) => date('d/m', strtotime($f)), $r['sesiones'])) . ')' : '') : $r['nota']) . "\n";
}
$n = count(array_filter($res, fn($r) => $r['hecho']));
echo $seco ? "Nada cambió (en seco): $n ítem(s) pasarían a $equipo.\n" : "Listo: $n ítem(s) pasaron a $equipo.\n";
