<?php
/**
 * Tablero general de equipos (administrador): los equipos de Releases lado a lado
 * en tres frentes, y arriba el que va más bajo de todos.
 *
 *   1. Reuniones diarias: % de días en que la daily SE HIZO (aunque faltara alguien),
 *      desde la fecha de Releases → Ajustes → Dailies (Releases::resumenDailies); lo
 *      justificado no cuenta. La asistencia (contra los invitados) va aparte.
 *   2. Avance de releases: el avance real de sus ítems frente al esperado a hoy, y
 *      cuántos van atrasados (Releases::tablero).
 *   3. Revisiones semanales: % de lo revisado que se cumplió (Releases::cumplimiento).
 *
 * El índice de cada equipo es el promedio de los tres (el avance cuenta como «real
 * sobre esperado», tope 100). El que tiene el índice más bajo sale destacado, con lo
 * que más lo baja. La colorimetría del equipo se muestra al lado, como referencia.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/releases/Releases.php';
if (is_file(__DIR__ . '/lib/releases/Colorimetria.php')) require_once __DIR__ . '/lib/releases/Colorimetria.php';
Auth::requiereAdmin();

$hoy = date('Y-m-d');
$tab = Releases::tablero($hoy);
$confDl = Releases::conf()['dailies'];
$FRENTES = [
    'dailies'    => ['Reuniones diarias', 'Días en que se hizo la daily'],
    'avance'     => ['Avance de releases', 'Avance real frente al esperado'],
    'revisiones' => ['Revisiones semanales', 'Lo revisado que se cumplió'],
];

/* ---------- Las cifras de cada equipo ---------- */
$filas = [];
foreach (Releases::equipos() as $nom => $e) {
    if ($nom === 'Todos') continue;
    $dl = Releases::resumenDailies($nom);
    $av = $tab['equipos'][$nom] ?? null;
    $cu = Releases::cumplimiento(Releases::revisiones(['equipo' => $nom, 'hasta' => $hoy]));
    $col = class_exists('Colorimetria') ? Colorimetria::delEquipo($nom) : null;
    // Avance: real sobre esperado (si todavía no se esperaba nada, va al día)
    $avIdx = $av && $av['total'] ? ($av['esperado'] > 0 ? min(100, (int)round($av['pct'] * 100 / $av['esperado'])) : 100) : null;
    // Dailies: el mismo cumplimiento que Releases (se hizo / no se hizo; lo justificado no cuenta)
    $dl += ['hechas' => (int)($dl['cuenta']['cumplida'] ?? 0), 'base' => (int)$dl['cuentan'], 'sin_verificar' => (int)($dl['cuenta']['sin_zoom'] ?? 0),
            'justificadas' => (int)($dl['cuenta']['justificada'] ?? 0)];
    $comp = ['dailies' => $dl['aplica'] ? $dl['pct'] : null, 'avance' => $avIdx, 'revisiones' => $cu['pct']];
    $con = array_filter($comp, fn($v) => $v !== null);
    if (!$con && !($av['total'] ?? 0)) continue;   // sin nada que medir todavía
    $filas[$nom] = [
        'nombre' => $nom, 'color' => (string)($e['color'] ?? '#475569'), 'comp' => $comp,
        'indice' => $con ? (int)round(array_sum($con) / count($con)) : null,
        'peor' => $con ? (string)array_search(min($con), $con, true) : '',
        'dl' => $dl, 'av' => $av, 'cu' => $cu,
        'col' => $col ? ['estado' => (string)($col['estado'] ?? 'nm'), 'puntaje' => $col['ev']['puntaje'] ?? null] : null,
    ];
}
// Del más bajo al más alto (los que no tienen índice, al final)
uasort($filas, fn($a, $b) => [$a['indice'] === null, $a['indice']] <=> [$b['indice'] === null, $b['indice']]);
$conIndice = array_values(array_filter($filas, fn($f) => $f['indice'] !== null));
$masBajo = $conIndice[0] ?? null;
$masAlto = $conIndice ? end($conIndice) : null;
// El más bajo de cada frente
$bajoEn = [];
foreach (array_keys($FRENTES) as $k) {
    $min = null;
    foreach ($filas as $nom => $f) if ($f['comp'][$k] !== null && ($min === null || $f['comp'][$k] < $filas[$min]['comp'][$k])) $min = $nom;
    $bajoEn[$k] = $min;
}

$tono = fn(?int $v): string => $v === null ? 'nm' : ($v >= 80 ? 'ok' : ($v >= 60 ? 'warn' : 'crit'));
$fecha = fn(string $f): string => $f !== '' ? date('d/m/Y', strtotime($f)) : '';
$urlEq = fn(string $nom): string => 'releases.php?v=equipo&e=' . rawurlencode($nom);

UI::inicio('Tablero de equipos', 'releases');
UI::cabecera(
    'Tablero de <span class="text-secondary">equipos</span>',
    'Las reuniones diarias, el avance de los releases y las revisiones semanales de cada equipo, lado a lado. Arriba, el que va más bajo.',
    UI::volver('index.php', 'Volver al Dashboard')
        . '<a class="rs-boton rs-boton--icono" href="releases.php" aria-label="Releases" data-ds-tip="Abrir Releases">' . UI::icono('ds-dashboard') . '</a>',
    'releases-equipos'
);
?>
<div class="ds tg">
<?php if (!$filas): ?>
  <?= UI::vacioDS('releases-equipos', 'Todavía no hay equipos que medir', 'Arma los equipos y sus releases en Releases; cuando tengan ítems, dailies o revisiones, aparecen aquí.') ?>
<?php else: ?>

  <?php if ($masBajo): $k = $masBajo['peor']; ?>
  <!-- El que va más bajo de todos, con lo que más lo baja -->
  <section class="tg-alerta tg-t--<?= $tono($masBajo['indice']) ?>" aria-labelledby="tg-alerta-t">
    <span class="tg-alerta__ico" aria-hidden="true"><?= UI::iconoDS('alerta', 26) ?></span>
    <div class="tg-alerta__txt">
      <small>El equipo más bajo de todos</small>
      <h2 id="tg-alerta-t"><span class="tg-punto" style="--ec:<?= e($masBajo['color']) ?>"></span><?= e($masBajo['nombre']) ?></h2>
      <p>Lo que más lo baja: <b><?= e(mb_strtolower($FRENTES[$k][0])) ?></b> (<?= (int)$masBajo['comp'][$k] ?> %).
        <?= $masAlto && $masAlto['nombre'] !== $masBajo['nombre'] ? 'El más alto es <b>' . e($masAlto['nombre']) . '</b> con ' . (int)$masAlto['indice'] . ' / 100.' : '' ?></p>
      <a class="tg-alerta__ir" href="<?= e($urlEq($masBajo['nombre'])) ?>">Ver el equipo en Releases →</a>
    </div>
    <div class="tg-alerta__indice"><b class="mc-num"><?= (int)$masBajo['indice'] ?></b><small>de 100</small></div>
    <ul class="tg-alerta__frentes">
      <?php foreach ($FRENTES as $kf => [$tit]): $v = $masBajo['comp'][$kf]; ?>
      <li class="tg-t--<?= $tono($v) ?>"><small><?= e($tit) ?></small><b class="mc-num"><?= $v === null ? '—' : $v . ' %' ?></b></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <!-- Los tres frentes: una barra por equipo; el más bajo de cada uno, marcado -->
  <div class="tg-paneles">
    <?php foreach ($FRENTES as $k => [$tit, $sub]):
      $lista = array_filter($filas, fn($f) => $f['comp'][$k] !== null);
      uasort($lista, fn($a, $b) => $a['comp'][$k] <=> $b['comp'][$k]); ?>
    <section class="mc-card tg-panel" aria-labelledby="tg-<?= $k ?>">
      <header class="tg-panel__cab">
        <div><h2 id="tg-<?= $k ?>"><?= e($tit) ?></h2><p><?= e($sub) ?><?= $k === 'dailies' && ($confDl['desde'] ?? '') !== '' ? ' · desde el ' . e($fecha($confDl['desde'])) : '' ?></p></div>
        <?php if ($bajoEn[$k]): ?><span class="tg-chip tg-t--crit" data-ds-tip="El más bajo en <?= e(mb_strtolower($tit)) ?>">Más bajo: <?= e($bajoEn[$k]) ?></span><?php endif; ?>
      </header>
      <?php if (!$lista): ?>
      <p class="tg-vacio">Todavía no hay datos de <?= e(mb_strtolower($tit)) ?>.</p>
      <?php else: ?>
      <ul class="tg-barras">
        <?php foreach ($lista as $nom => $f): $v = (int)$f['comp'][$k]; $bajo = $nom === $bajoEn[$k]; ?>
        <li class="tg-barra tg-t--<?= $tono($v) ?><?= $bajo ? ' is-bajo' : '' ?>">
          <a class="tg-barra__eq" href="<?= e($urlEq($nom)) ?>"><span class="tg-punto" style="--ec:<?= e($f['color']) ?>"></span><?= e($nom) ?></a>
          <?php if ($k === 'avance'): $av = $f['av']; ?>
          <span class="tg-barra__pista" role="img" aria-label="<?= e($nom) ?>: <?= (int)$av['pct'] ?> % de avance, se esperaba <?= (int)$av['esperado'] ?> %">
            <span class="tg-barra__relleno" style="width:<?= max(2, min(100, (int)$av['pct'])) ?>%"></span>
            <span class="tg-barra__meta" style="left:<?= min(100, (int)$av['esperado']) ?>%" data-ds-tip="Se esperaba <?= (int)$av['esperado'] ?> % a hoy"></span>
          </span>
          <span class="tg-barra__val"><b class="mc-num"><?= (int)$av['pct'] ?> %</b></span>
          <small class="tg-barra__det"><?= $bajo ? '<span class="tg-chip tg-t--crit">Más bajo</span>' : '' ?>se esperaba <?= (int)$av['esperado'] ?> %<?= $av['atrasados'] ? ' · ' . (int)$av['atrasados'] . ' atrasado(s)' : '' ?></small>
          <?php else: ?>
          <span class="tg-barra__pista" role="img" aria-label="<?= e($nom) ?>: <?= $v ?> %"><span class="tg-barra__relleno" style="width:<?= max(2, $v) ?>%"></span></span>
          <span class="tg-barra__val"><b class="mc-num"><?= $v ?> %</b></span>
          <small class="tg-barra__det"><?= $bajo ? '<span class="tg-chip tg-t--crit">Más bajo</span>' : '' ?><?php
            if ($k === 'dailies') echo e('se hizo ' . $f['dl']['hechas'] . ' de ' . $f['dl']['base'] . ' días'
                . ($f['dl']['justificadas'] ? ' · ' . $f['dl']['justificadas'] . ' justificada(s)' : '')
                . ($f['dl']['asistencia'] !== null ? ' · asistencia ' . (int)$f['dl']['asistencia'] . ' %' : '')
                . ($f['dl']['sin_verificar'] ? ' · ' . $f['dl']['sin_verificar'] . ' sin verificar' : ''));
            else echo (int)$f['cu']['cumplido'] . ' de ' . (int)($f['cu']['cumplido'] + $f['cu']['parcial'] + $f['cu']['no_cumplido']) . ' cumplido(s)';
          ?></small>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>

  <!-- El ranking: de más bajo a más alto -->
  <section class="mc-card tg-ranking" aria-labelledby="tg-ranking-t">
    <header class="tg-panel__cab"><div><h2 id="tg-ranking-t">Ranking general</h2>
      <p>Índice = promedio de los días con daily, el avance real sobre el esperado (tope 100) y las revisiones cumplidas. Del más bajo al más alto.</p></div></header>
    <div class="tg-tabla-caja"><table class="tg-tabla">
      <thead><tr><th scope="col">#</th><th scope="col">Equipo</th><th scope="col">Índice</th><?php foreach ($FRENTES as [$tit]): ?><th scope="col"><?= e($tit) ?></th><?php endforeach; ?><th scope="col">Colorimetría</th><th scope="col">Lo que más lo baja</th></tr></thead>
      <tbody>
      <?php $n = 0; foreach ($filas as $nom => $f): $n++; ?>
        <tr class="<?= $masBajo && $nom === $masBajo['nombre'] ? 'is-bajo' : '' ?>">
          <td class="mc-num"><?= $f['indice'] === null ? '—' : $n ?></td>
          <th scope="row"><a href="<?= e($urlEq($nom)) ?>"><span class="tg-punto" style="--ec:<?= e($f['color']) ?>"></span><?= e($nom) ?></a></th>
          <td><span class="tg-indice tg-t--<?= $tono($f['indice']) ?>"><?= $f['indice'] === null ? '—' : (int)$f['indice'] ?></span></td>
          <?php foreach (array_keys($FRENTES) as $kf): $v = $f['comp'][$kf]; ?>
          <td class="mc-num tg-t--<?= $tono($v) ?> tg-celda"><?= $v === null ? '—' : $v . ' %' ?><?php if ($kf === 'dailies' && $v !== null): ?><small class="tg-celda__sub">se hizo <?= (int)$f['dl']['hechas'] ?> de <?= (int)$f['dl']['base'] ?><?= $f['dl']['asistencia'] !== null ? ' · asist. ' . (int)$f['dl']['asistencia'] . ' %' : '' ?></small><?php endif; ?></td>
          <?php endforeach; ?>
          <td><?= $f['col'] && class_exists('Colorimetria') ? Colorimetria::estado($f['col']['estado'], $f['col']['puntaje'] !== null ? Colorimetria::num((float)$f['col']['puntaje']) . ' / 100' : null) : '—' ?></td>
          <td><?= $f['peor'] !== '' ? e($FRENTES[$f['peor']][0]) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>
</div>
<?php UI::fin(); ?>
