<?php
/**
 * Pasa ítems de Releases a otro equipo, por terminal: el cambio queda en el
 * historial de cada ítem con su motivo (y se avisa como cualquier cambio de
 * equipo) y los ítems salen de las revisiones que el equipo de antes todavía
 * no hace, si ahí seguían sin revisar.
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
$codigos = array_values(array_unique(array_map('strtoupper', $libres)));
if ($destino === null || !$codigos) exit("Uso: php admin/mover_items.php <equipo> <código> [<código>…] --motivo=\"…\" [--por=nombre] [--seco]\n");

// El equipo: el que se llama así o, si no, el único que lo contiene
$equipos = array_keys(Releases::equipos());
$buscado = Releases::normal($destino);
$iguales = array_values(array_filter($equipos, fn($n) => Releases::normal($n) === $buscado));
$parecidos = $iguales ?: array_values(array_filter($equipos, fn($n) => str_contains(Releases::normal($n), $buscado)));
if (count($parecidos) !== 1) {
    exit(($parecidos ? "«{$destino}» puede ser varios equipos" : "No hay un equipo «{$destino}»") . '. Los equipos son: ' . implode(', ', $equipos)
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

$hoy = date('Y-m-d');
$n = 0;
echo ($seco ? '[en seco] ' : '') . 'A ' . $equipo . ($motivo !== '' ? " · motivo: $motivo" : '') . "\n";
foreach ($codigos as $cod) {
    $it = Releases::itemPorCodigo($cod);
    if (!$it) { echo "  $cod: no existe\n"; continue; }
    if ($it['equipo'] === $equipo) { echo "  $cod: ya es de $equipo\n"; continue; }
    $antes = $it['equipo'];
    // Las revisiones que el equipo de antes aún no hace: el ítem sale si ahí seguía sin revisar
    $sesiones = [];
    foreach (Releases::revisiones(['equipo' => $antes, 'desde' => $hoy]) as $r) {
        if (($r['estado'] ?? 'programada') === 'realizada') continue;
        $quitar = array_keys(array_filter((array)$r['filas'], fn($f) => strtoupper((string)$f['codigo']) === $cod && ($f['estado'] ?? 'pendiente') === 'pendiente'));
        if ($quitar) $sesiones[(int)$r['id']] = [$r['fecha'], $quitar];
    }
    if (!$seco) {
        Releases::guardarItem((int)$it['id'], ['equipo' => $equipo, 'motivo' => $motivo], $quien);
        foreach ($sesiones as $id => [, $quitar]) Releases::guardarRevision($id, ['quitar' => $quitar], $quien);
    }
    $n++;
    echo "  $cod · {$it['titulo']}: $antes → $equipo"
        . ($sesiones ? ' · sale de ' . count($sesiones) . ' revisión(es) de ' . $antes . ' (' . implode(', ', array_map(fn($s) => date('d/m', strtotime($s[0])), $sesiones)) . ')' : '') . "\n";
}
echo $seco ? "Nada cambió (en seco): $n ítem(s) pasarían a $equipo.\n" : "Listo: $n ítem(s) pasaron a $equipo.\n";
