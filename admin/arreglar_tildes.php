<?php
/**
 * Arregla las tildes rotas que ya quedaron guardadas («AcadÃ©mico», «dÃas»).
 *
 *   php admin/arreglar_tildes.php            arregla y dice cuántos cambió
 *   php admin/arreglar_tildes.php --seco     solo dice qué cambiaría
 *
 * Los correos del respaldo se guardan enteros y se vuelven a leer al abrirlos,
 * así que ya se ven bien solos. Lo que quedó escrito con la tilde rota es lo
 * que se sacó de ellos: el asunto, los nombres y el resumen de la lista del
 * respaldo, las propuestas de la IA, los requerimientos y las tareas que se
 * crearon desde un correo, y los avisos de la campana. Usa el mismo arreglo
 * que el correo (CorreoMime::sinMojibake): un texto bien escrito no cambia.
 * Se puede correr las veces que haga falta.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la terminal.');
}
require_once __DIR__ . '/lib/bootstrap.php';
if (!is_file(__DIR__ . '/lib/Correo.php')) exit("Falta lib/Correo.php\n");
require_once __DIR__ . '/lib/Correo.php';

$seco = in_array('--seco', $argv, true);
// Tabla => campos que no se tocan (identificadores, llaves, rutas)
$tablas = [
    'correo_copias'  => ['llave', 'message_id', 'carpeta', 'archivo'],
    'req_correo'     => ['message_id', 'carpeta'],
    'tareas_correo'  => ['message_id', 'carpeta'],
    'requerimientos' => ['adjuntos'],
    'tareas'         => ['adjuntos'],
    'notificaciones' => ['url'],
];

/** Arregla cada texto del valor (también dentro de listas), menos las claves de $fuera. */
$arreglar = function ($v, array $fuera, int &$n) use (&$arreglar) {
    if (is_string($v)) {
        if (!preg_match('/[\x{00C2}-\x{00F4}]/u', $v)) return $v;
        $nuevo = CorreoMime::sinMojibake($v);
        if ($nuevo !== $v) $n++;
        return $nuevo;
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) if (!in_array((string)$k, $fuera, true)) $v[$k] = $arreglar($x, $fuera, $n);
    }
    return $v;
};

$total = 0;
foreach ($tablas as $tabla => $fuera) {
    $repo = new JsonStore($tabla);
    $filas = 0;
    $textos = 0;
    $ejemplo = '';
    foreach ($repo->all() as $f) {
        $n = 0;
        $nueva = $arreglar($f, array_merge(['id'], $fuera), $n);
        if (!$n) continue;
        $filas++;
        $textos += $n;
        if ($ejemplo === '') {
            foreach ($f as $k => $v) if (is_string($v) && $v !== ($nueva[$k] ?? $v)) { $ejemplo = '«' . mb_strimwidth($v, 0, 60, '…') . '» → «' . mb_strimwidth((string)$nueva[$k], 0, 60, '…') . '»'; break; }
        }
        if (!$seco) {
            unset($nueva['id']);
            $repo->update((int)$f['id'], $nueva);
        }
    }
    $total += $filas;
    echo str_pad($tabla, 16) . ' ' . ($filas ? ($seco ? 'arreglaría ' : 'arreglados ') . $filas . ' registro(s), ' . $textos . ' texto(s)' . ($ejemplo !== '' ? '. Ej.: ' . $ejemplo : '') : 'nada que arreglar') . "\n";
}
echo $total ? ($seco ? "Para arreglarlos, córrelo sin --seco.\n" : "Listo.\n") : "Todo estaba bien.\n";
