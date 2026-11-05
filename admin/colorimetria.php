<?php
/**
 * Colorimetría de gestión de proyectos (lib/Colorimetria.php), según la guía de
 * la PMO: la salud de cada proyecto del panel y de cada equipo de Releases, con
 * un solo tono por estado. Pestañas:
 *   ?v=proyectos (por defecto) · ?v=equipos · ?v=config (administrador) · ?v=guia
 *   ?v=medir&t=proyecto|equipo&r=<id o equipo>&p=AAAA-MM   anotar los valores del mes
 * Cada fila se abre con el detalle por indicador (gráficos de bala con las
 * mismas zonas de la configuración).
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Colorimetria.php';
if (is_file(__DIR__ . '/lib/Releases.php')) require_once __DIR__ . '/lib/Releases.php';
Auth::requiereLogin();
if (!Colorimetria::puedeVer()) redirigir('index.php', 'La colorimetría la ven el administrador, los Scrum Master y los supervisores.', 'error');

$v = (string)($_GET['v'] ?? (Auth::esAdmin() ? 'config' : 'proyectos'));
if (!in_array($v, ['proyectos', 'equipos', 'config', 'guia', 'medir'], true)) $v = 'proyectos';
if ($v === 'config' && !Auth::esAdmin()) $v = 'proyectos';
$conf = Colorimetria::conf();
$C = Colorimetria::class;
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mesTxt = fn(string $per) => $per === '' ? '' : $meses[(int)substr($per, 5, 2) - 1] . ' de ' . substr($per, 0, 4);

/* ---------- Piezas ---------- */
$pill = fn(string $k, ?string $txt = null) => '<span class="col-pill col-s-' . e($k) . '"><svg class="col-ic" aria-hidden="true"><use href="#col-i-' . e($k === 'nm' ? 'none' : $k) . '"/></svg><span>' . e($txt ?? $C::nombre($k)) . '</span></span>';
$pos = fn(array $i, float $x) => max(0, min(100, ($x - $i['min']) / (($i['max'] - $i['min']) ?: 1) * 100));
$bullet = function (array $i, ?float $val, string $st) use ($C, $pos): string {
    $e = !empty($i['int']) ? 0.5 : 0;
    $zonas = $i['dir'] === 'up'
        ? [['crit', $i['min'], $i['a']], ['warn', $i['a'], $i['g']], ['ok', $i['g'], $i['max']]]
        : [['ok', $i['min'], $i['g'] + $e], ['warn', $i['g'] + $e, $i['a'] + $e], ['crit', $i['a'] + $e, $i['max']]];
    $h = '<span class="col-bala" role="img" aria-label="' . e($i['n'] . ': ' . $C::fmt($i, $val) . ', meta ' . $C::meta($i)) . '">';
    foreach ($zonas as [$k, $de, $a]) {
        $l = $pos($i, (float)$de);
        $h .= '<i class="col-bala__zona col-s-' . ($st === 'nm' ? 'nm' : $k) . '" style="left:' . round($l, 2) . '%;width:' . round(max(0, $pos($i, (float)$a) - $l), 2) . '%"></i>';
    }
    if ($val !== null) $h .= '<i class="col-bala__val" style="width:' . round($pos($i, $val), 2) . '%"></i>';
    return $h . '</span>';
};
$celda = function (array $fila, string $id) use ($C, $pill): string {
    $r = $fila['ev']['filas'][$id] ?? null;
    if (!$r) return $pill('nm');
    return $pill($r['st'], $r['v'] === null ? 'Sin dato' : $C::fmt($r['i'], $r['v']));
};
$barra = function (?int $av, ?int $plan): string {
    if ($av === null) return '<span class="col-av"><span class="col-av__pista"></span><b>Sin dato</b></span>';
    return '<span class="col-av"><span class="col-av__pista"><i style="width:' . max(0, min(100, $av)) . '%"></i>'
        . ($plan !== null ? '<em style="left:' . max(0, min(100, $plan)) . '%" title="Esperado a hoy: ' . $plan . ' %"></em>' : '') . '</span><b class="mc-num">' . $av . ' %</b></span>';
};
$puntajeCelda = function (array $ev) use ($C): string {
    if ($ev['puntaje'] === null) return '<span class="col-av"><span class="col-av__pista"></span><b>Sin dato</b></span>';
    return '<span class="col-av col-s-' . e($ev['salud']) . '"><span class="col-av__pista"><i class="is-estado" style="width:' . round(max(0, min(100, $ev['puntaje']))) . '%"></i></span><b class="mc-num">' . $C::num($ev['puntaje']) . '</b></span>';
};
$detalle = function (array $fila) use ($C, $conf, $pill, $bullet, $mesTxt): string {
    $ev = $fila['ev'];
    $sk = $ev['puntaje'] === null ? 'nm' : $ev['salud'];
    $h = '<div class="col-det"><div class="col-det__cab col-s-' . e($sk) . '">';
    $h .= '<span class="col-det__pts"><small>Puntaje</small><b class="mc-num">' . ($ev['puntaje'] === null ? 'Sin dato' : $C::num($ev['puntaje'])) . '</b>' . ($ev['puntaje'] === null ? '' : '<em>de 100</em>') . '</span>';
    if ($ev['puntaje'] !== null) {
        $h .= '<span class="col-det__barra" role="img" aria-label="Puntaje ' . $C::num($ev['puntaje']) . ' de 100"><i style="width:' . round(max(0, min(100, $ev['puntaje']))) . '%"></i>'
            . '<em style="left:' . $conf['bandas']['warn'] . '%"></em><em style="left:' . $conf['bandas']['ok'] . '%"></em></span>' . $pill($ev['salud']);
    }
    $h .= '<span class="col-det__cuentas">';
    foreach (['ok', 'warn', 'crit', 'nm'] as $k) if ($ev['cuenta'][$k]) $h .= '<span class="col-s-' . $k . '"><i class="col-punto"></i><b class="mc-num">' . $ev['cuenta'][$k] . '</b> ' . e($C::nombre($k)) . '</span>';
    $h .= '</span>';
    if ($ev['claves']) $h .= $pill('crit', ($ev['escalo'] ? 'Escaló por clave en ' : 'Clave en ') . $C::nombre('crit') . ': ' . implode(', ', array_map(fn($r) => $r['i']['n'], $ev['claves'])));
    if ($fila['periodo'] !== '') $h .= '<span class="col-det__nota">Lo anotado es de ' . e($mesTxt($fila['periodo'])) . '</span>';
    $h .= '</div><div class="col-ind"><div class="col-ind__cols"><span>Indicador</span><span>Marco</span><span>Real</span><span>Meta</span><span>Zonas</span><span>Peso</span><span>Estado</span></div>';
    foreach ($ev['dims'] as $d) {
        $h .= '<div class="col-ind__dim"><b>' . e($d['n']) . '</b>' . ($d['puntaje'] === null ? $pill('nm') : $pill($d['st'])) . '</div>';
        foreach ($d['filas'] as $id => $r) {
            $i = $r['i'];
            $src = $fila['fuente'][$id] ?? '';
            $h .= '<div class="col-ind__fila"><span class="col-ind__nom">' . e($i['n']) . (!empty($i['clave']) ? '<span class="col-tag col-tag--clave">Clave</span>' : '') . '</span>'
                . '<span><span class="col-tag' . ($i['m'] === 'Scrum' ? ' col-tag--scrum' : '') . '">' . $i['m'] . '</span></span>'
                . '<span class="col-ind__real"><b class="mc-num">' . e($C::fmt($i, $r['v'])) . '</b>'
                . ($src === 'auto' ? '<small title="' . e(Colorimetria::COMO[$id][$fila['tipo'] === 'proyecto' ? 'p' : 'e'] ?? '') . '">del panel</small>' : ($src === 'manual' ? '<small>anotado</small>' : '')) . '</span>'
                . '<span class="mc-num">' . e($C::meta($i)) . '</span>' . $bullet($i, $r['v'], $r['st'])
                . '<span class="mc-num">' . $C::num((float)$i['w']) . '</span><span>' . $pill($r['st']) . '</span></div>';
        }
    }
    return $h . '</div></div>';
};

/* ---------- Datos de la vista ---------- */
$lista = match ($v) { 'proyectos' => $C::proyectos(), 'equipos' => $C::equipos(), default => [] };

$acciones = '';
if (in_array($v, ['proyectos', 'equipos'], true)) {
    $acciones = '<a class="mc-btn mc-btn--secondary mc-btn--md" href="colorimetria.php?v=guia">' . UI::iconoDS('documento', 18) . '<span class="mc-btn__label">Guía</span></a>';
}

UI::inicio('Colorimetría', 'colorimetria');
UI::cabecera('Colorimetría', 'Los tonos de cada estado que usa todo el panel (avances de proyectos, Releases, revisiones), sus umbrales y los valores del mes de la PMO. Lo que el panel sabe se calcula solo.', $acciones);
?>
<style><?= Colorimetria::css() ?></style>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="col-i-ok" viewBox="0 0 16 16"><circle fill="currentColor" cx="8" cy="8" r="7"/><path d="M4.8 8.2l2.2 2.2 4.2-4.6" style="stroke:var(--col-bg)" stroke-width="1.9" fill="none" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="col-i-warn" viewBox="0 0 16 16"><path fill="currentColor" d="M8 1.6l6.8 11.8H1.2z"/><path d="M8 6v3.4M8 11.4v.1" style="stroke:var(--col-bg)" stroke-width="1.9" fill="none" stroke-linecap="round"/></symbol>
  <symbol id="col-i-crit" viewBox="0 0 16 16"><rect fill="currentColor" x="1.5" y="1.5" width="13" height="13" rx="3.5"/><path d="M5.4 5.4l5.2 5.2M10.6 5.4l-5.2 5.2" style="stroke:var(--col-bg)" stroke-width="1.9" fill="none" stroke-linecap="round"/></symbol>
  <symbol id="col-i-done" viewBox="0 0 16 16"><path d="M4 14.5V2M4 3h8.2l-2.1 3 2.1 3H4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="col-i-none" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5.2 8h5.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
  <symbol id="col-i-hold" viewBox="0 0 16 16"><path d="M5.6 3v10M10.4 3v10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
</svg>
<div class="ds col-pagina">
  <nav class="tabs-meca col-tabs" aria-label="Vistas">
    <?php if (Auth::esAdmin()): ?><a class="tab-btn<?= $v === 'config' ? ' active' : '' ?>" href="colorimetria.php?v=config"><?= UI::icono('ds-configuracion') ?> <span class="tab-txt">Configuración</span></a><?php endif; ?>
    <a class="tab-btn<?= $v === 'proyectos' || ($v === 'medir' && ($_GET['t'] ?? '') !== 'equipo') ? ' active' : '' ?>" href="colorimetria.php?v=proyectos"><?= UI::icono('ds-carpeta') ?> <span class="tab-txt">Valores de proyectos</span></a>
    <?php if (class_exists('Releases')): ?><a class="tab-btn<?= $v === 'equipos' || ($v === 'medir' && ($_GET['t'] ?? '') === 'equipo') ? ' active' : '' ?>" href="colorimetria.php?v=equipos"><?= UI::icono('ds-usuarios') ?> <span class="tab-txt">Valores de equipos</span></a><?php endif; ?>
    <a class="tab-btn<?= $v === 'guia' ? ' active' : '' ?>" href="colorimetria.php?v=guia"><?= UI::icono('ds-documento') ?> <span class="tab-txt">Guía</span></a>
  </nav>

<?php if ($v === 'proyectos' || $v === 'equipos'):
    $esEq = $v === 'equipos';
    $conCliente = (bool)array_filter($lista, fn($f) => $f['cliente'] !== '');   // sin clientes anotados, la columna no ocupa sitio
    $cuenta = [];
    foreach ($lista as $f) $cuenta[$f['estado']] = ($cuenta[$f['estado']] ?? 0) + 1;
?>
  <div class="col-filtros" role="group" aria-label="Filtrar por estado" data-col-filtros>
    <button type="button" class="col-filtro" data-k="" aria-pressed="true"><b class="mc-num"><?= count($lista) ?></b><span>Todos</span></button>
    <?php foreach (['crit', 'warn', 'ok', 'nm', 'hold', 'done', 'none'] as $k): if (empty($cuenta[$k])) continue; ?>
    <button type="button" class="col-filtro col-s-<?= $k ?>" data-k="<?= $k ?>" aria-pressed="false"><b class="mc-num"><?= (int)$cuenta[$k] ?></b><span><i class="col-punto"></i><?= e($C::nombre($k)) ?></span></button>
    <?php endforeach; ?>
  </div>
  <?php if (!$lista): ?>
  <?= UI::vacioDS('nexo:pensando', $esEq ? 'No hay equipos con ítems' : 'No hay proyectos que mostrar', $esEq ? 'Carga el plan en Releases → Ajustes para ver la salud de cada equipo.' : 'Cuando haya proyectos, aquí sale la salud de cada uno.', '') ?>
  <?php else: ?>
  <div class="col-tabla-wrap">
    <table class="col-tabla" data-col-tabla>
      <thead><tr>
        <th scope="col"><?= $esEq ? 'Equipo' : 'Proyecto' ?></th><?php if ($conCliente): ?><th scope="col"><?= $esEq ? 'Dominio' : 'Cliente' ?></th><?php endif; ?><th scope="col">Avance</th>
        <th scope="col">Cronograma</th>
        <?php if ($esEq): ?><th scope="col">Release</th><th scope="col">Predictibilidad</th><th scope="col">Impedimentos</th>
        <?php else: ?><th scope="col">Costo</th><th scope="col">Margen</th><th scope="col">Riesgos</th><?php endif; ?>
        <th scope="col">Puntaje</th><th scope="col">Estado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($lista as $n => $f): $det = 'col-d-' . $n; ?>
        <tr class="col-fila" data-estado="<?= e($f['estado']) ?>">
          <td><span class="col-cod"><button type="button" class="mc-iconbtn mc-iconbtn--sm col-abrir" aria-expanded="false" aria-controls="<?= $det ?>" aria-label="Indicadores de <?= e($f['nombre']) ?>" data-col-abrir><?= UI::iconoDS('chevron-abajo', 16) ?></button>
            <span class="col-nom"><?= $esEq ? '<b>' . e($f['nombre']) . '</b>' : '<a href="proyecto.php?id=' . (int)$f['ref'] . '">' . e($f['nombre']) . '</a>' ?><code><?= e($f['codigo']) ?></code></span></span></td>
          <?php if ($conCliente): ?><td class="col-cli"><?= $f['cliente'] !== '' ? '<span title="' . e($f['cliente']) . '">' . e($f['cliente']) . '</span>' : '<span class="col-vacio">—</span>' ?></td><?php endif; ?>
          <td><?= $barra($f['auto']['avance'] ?? null, $f['auto']['plan'] ?? null) ?></td>
          <td><?= $celda($f, 'spi') ?></td>
          <?php if ($esEq): ?><td><?= $celda($f, 'hist') ?><?= ($f['auto']['release'] ?? '') !== '' ? '<small class="col-sub">' . e($f['auto']['release']) . '</small>' : '' ?></td><td><?= $celda($f, 'pred') ?></td><td><?= $celda($f, 'imped') ?></td>
          <?php else: ?><td><?= $celda($f, 'cpi') ?></td><td><?= $celda($f, 'margen') ?></td><td><?= $celda($f, 'riesgos') ?></td><?php endif; ?>
          <td><?= $puntajeCelda($f['ev']) ?></td>
          <td><span class="col-estado"><?= $pill($f['estado']) ?><?php if ($C::puedeMedir($f['tipo'], $f['ref'])): ?><a class="mc-iconbtn mc-iconbtn--sm" href="colorimetria.php?v=medir&amp;t=<?= e($f['tipo']) ?>&amp;r=<?= e(rawurlencode($f['ref'])) ?>" aria-label="Anotar los valores del mes de <?= e($f['nombre']) ?>" data-ds-tip="Anotar los valores del mes"><?= UI::iconoDS('editar', 16) ?></a><?php endif; ?></span></td>
        </tr>
        <tr class="col-fila-det" id="<?= $det ?>" data-estado="<?= e($f['estado']) ?>" hidden><td colspan="<?= $conCliente ? 10 : 9 ?>"><?= $detalle($f) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="col-pie">Ordenado de lo más grave a lo más tranquilo. <b>del panel</b>: lo calcula el panel con sus tareas<?= $esEq ? ', ítems y revisiones' : ', observaciones e ítems de Releases' ?>. <b>anotado</b>: lo que se anotó en el mes. Un indicador sin dato no cuenta en el puntaje.</p>
  <?php endif; ?>

<?php elseif ($v === 'medir'):
    $t = ($_GET['t'] ?? '') === 'equipo' ? 'equipo' : 'proyecto';
    $ref = (string)($_GET['r'] ?? '');
    $todas = $t === 'equipo' ? $C::equipos() : $C::proyectos();
    $fila = null;
    foreach ($todas as $x) if ($x['ref'] === $ref) $fila = $x;
    if (!$fila || !$C::puedeMedir($t, $ref)):
        echo UI::vacioDS('nexo:pensando', 'No puedes anotar aquí', 'Ese ' . ($t === 'equipo' ? 'equipo' : 'proyecto') . ' no existe o no lo gestionas.', '<a class="mc-btn mc-btn--secondary" href="colorimetria.php"><span class="mc-btn__label">Volver</span></a>');
    else:
        $per = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['p'] ?? '')) ? (string)$_GET['p'] : $C::periodo();
        [$man, $perMan] = $C::manuales($t, $ref, $per);
        $deEste = $perMan === $per;
        $volver = $t === 'equipo' ? 'colorimetria.php?v=equipos' : 'colorimetria.php';
?>
  <form method="post" action="actions.php" class="col-medir">
    <input type="hidden" name="accion" value="col_medir"><input type="hidden" name="t" value="<?= e($t) ?>"><input type="hidden" name="r" value="<?= e($ref) ?>">
    <section class="mc-card col-medir__cab">
      <div>
        <p class="col-medir__kicker"><?= e($fila['codigo']) ?></p>
        <h2 class="font-display"><?= e($fila['nombre']) ?></h2>
        <p class="col-medir__sub">Hoy: <?= $pill($fila['estado']) ?><?= $fila['ev']['puntaje'] !== null ? ' · puntaje <b class="mc-num">' . $C::num($fila['ev']['puntaje']) . '</b> de 100' : '' ?></p>
      </div>
      <div class="col-medir__mes">
        <label class="mc-field__label" for="col-per">Mes</label>
        <input class="input-meca" type="month" id="col-per" name="periodo" value="<?= e($per) ?>" max="<?= e($C::periodo()) ?>" required onchange="location.href='colorimetria.php?v=medir&t=<?= e($t) ?>&r=<?= e(rawurlencode($ref)) ?>&p='+this.value">
        <?php if ($perMan !== '' && !$deEste): ?><small>Se muestran los valores de <?= e($mesTxt($perMan)) ?>: al guardar quedan como los de <?= e($mesTxt($per)) ?>.</small><?php endif; ?>
      </div>
    </section>
    <section class="mc-card col-medir__ficha">
      <div class="mc-field"><label class="mc-field__label" for="col-cod">Código</label><div class="mc-input"><input class="mc-input__control" id="col-cod" name="codigo" maxlength="40" value="<?= e($fila['ficha']['codigo']) ?>" placeholder="<?= e($fila['codigo']) ?>"></div></div>
      <div class="mc-field"><label class="mc-field__label" for="col-cli"><?= $t === 'equipo' ? 'Dominio' : 'Cliente' ?></label><div class="mc-input"><input class="mc-input__control" id="col-cli" name="cliente" maxlength="80" value="<?= e($fila['ficha']['cliente']) ?>" placeholder="<?= $t === 'equipo' ? e(mb_strimwidth($fila['cliente'], 0, 40, '…')) : 'UBE, ITB…' ?>"></div></div>
      <div class="mc-field"><label class="mc-field__label">Fase</label><?= UI::selectDS('fase', Colorimetria::FASES, $fila['ficha']['fase'], ['aria' => 'Fase']) ?></div>
    </section>
    <section class="mc-card col-medir__ind">
      <p class="col-medir__ayuda"><?= UI::iconoDS('info', 15) ?> Escribe solo lo que tienes del mes (con coma decimal: 0,93). Lo que dejes vacío y el panel calcula, se calcula solo; lo vacío que no calcula queda «Sin dato» y no cuenta.</p>
      <?php foreach (Colorimetria::DIMS as $dim): ?>
      <h3 class="col-medir__dim"><?= e($dim) ?></h3>
      <?php foreach (Colorimetria::IND as $id => $_): $i = $C::ind($id); if ($i['dim'] !== $dim || !$i['act']) continue;
          $calcula = str_contains((string)($i['auto'] ?? ''), $t === 'proyecto' ? 'p' : 'e');
          $autoV = $fila['auto'][$id] ?? null;
          $val = array_key_exists($id, $man) ? $C::num((float)$man[$id], (int)$i['d']) : ''; ?>
      <div class="col-medir__fila">
        <label for="col-v-<?= $id ?>"><b><?= e($i['n']) ?><?= !empty($i['clave']) ? '<span class="col-tag col-tag--clave">Clave</span>' : '' ?></b>
          <small>Meta <?= e($C::meta($i)) ?><?= $calcula ? ' · ' . e(Colorimetria::COMO[$id][$t === 'proyecto' ? 'p' : 'e'] ?? '') : '' ?></small></label>
        <div class="mc-input mc-input--sm col-medir__in"><input class="mc-input__control mc-num" id="col-v-<?= $id ?>" name="vals[<?= $id ?>]" inputmode="decimal" value="<?= e($val) ?>"
          placeholder="<?= $calcula ? ($autoV !== null ? 'Panel: ' . e($C::fmt($i, (float)$autoV)) : 'Panel: sin dato') : '—' ?>"><?= $i['u'] !== '' ? '<span class="mc-input__affix">' . e($i['u']) . '</span>' : '' ?></div>
      </div>
      <?php endforeach; endforeach; ?>
      <div class="mc-field"><label class="mc-field__label" for="col-nota">Nota del mes <small>(opcional)</small></label>
        <div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="col-nota" name="nota" rows="2" maxlength="1000" placeholder="De dónde salen los valores, qué pasó en el mes…"><?= e((string)(($deEste ? $C::manuales($t, $ref, $per)[2]['nota'] ?? '' : ''))) ?></textarea></div></div>
    </section>
    <div class="col-medir__pie">
      <a class="mc-btn mc-btn--ghost mc-btn--md" href="<?= e($volver) ?>"><span class="mc-btn__label">Cancelar</span></a>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar los valores de <?= e($mesTxt($per)) ?></span></button>
    </div>
  </form>
<?php endif; ?>

<?php elseif ($v === 'config'): ?>
  <form method="post" action="actions.php" class="col-conf">
    <input type="hidden" name="accion" value="col_conf">
    <section class="mc-card col-card">
      <h2 class="font-display">Estados y colores</h2>
      <p class="col-card__sub">Un solo tono por estado en todo el tablero. Los colores vienen de una paleta validada con contraste AA en tema claro y oscuro.</p>
      <div class="col-tabla-wrap"><table class="col-tabla col-tabla--conf">
        <thead><tr><th>Vista previa</th><th>Tipo</th><th>Nombre</th><th>Color</th></tr></thead><tbody>
        <?php foreach (Colorimetria::ESTADOS as $k => [$tipo]): ?>
        <tr><td><?= $pill($k) ?></td><td><?= e($tipo) ?></td>
          <td><div class="mc-input mc-input--sm"><input class="mc-input__control" name="estados[<?= $k ?>][nombre]" maxlength="24" value="<?= e($conf['estados'][$k]['nombre']) ?>" aria-label="Nombre de <?= e(Colorimetria::ESTADOS[$k][1]) ?>"></div></td>
          <td><span class="col-swatches" role="radiogroup" aria-label="Color de <?= e(Colorimetria::ESTADOS[$k][1]) ?>">
            <?php foreach (Colorimetria::PAL as $c => $p): ?>
            <label class="col-sw" title="<?= e(Colorimetria::PAL_NOM[$c]) ?>" style="--sw-l:<?= $p['l'][2] ?>;--sw-d:<?= $p['d'][2] ?>"><input type="radio" name="estados[<?= $k ?>][color]" value="<?= $c ?>"<?= $conf['estados'][$k]['color'] === $c ? ' checked' : '' ?>><i aria-hidden="true"></i><span class="mc-sr-only"><?= e(Colorimetria::PAL_NOM[$c]) ?></span></label>
            <?php endforeach; ?>
          </span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="mc-card col-card">
      <h2 class="font-display">Puntaje y escalamiento</h2>
      <div class="col-conf__grid">
        <?php foreach (['ok', 'warn', 'crit'] as $k): ?><div class="mc-field"><label class="mc-field__label" for="cp-<?= $k ?>">Puntos de <?= e($C::nombre($k)) ?></label><div class="mc-input mc-input--sm"><input class="mc-input__control mc-num" id="cp-<?= $k ?>" type="number" min="0" max="100" step="1" name="puntos[<?= $k ?>]" value="<?= e((string)$conf['puntos'][$k]) ?>"></div></div><?php endforeach; ?>
        <?php foreach (['ok', 'warn'] as $k): ?><div class="mc-field"><label class="mc-field__label" for="cb-<?= $k ?>">Puntaje desde para <?= e($C::nombre($k)) ?></label><div class="mc-input mc-input--sm"><input class="mc-input__control mc-num" id="cb-<?= $k ?>" type="number" min="0" max="100" step="1" name="bandas[<?= $k ?>]" value="<?= e((string)$conf['bandas'][$k]) ?>"></div></div><?php endforeach; ?>
        <div class="mc-field"><label class="mc-field__label" for="ce-min">Claves en <?= e($C::nombre('crit')) ?> para marcarlo <?= e($C::nombre('crit')) ?></label><div class="mc-input mc-input--sm"><input class="mc-input__control mc-num" id="ce-min" type="number" min="1" max="10" step="1" name="escala[min]" value="<?= (int)$conf['escala']['min'] ?>"></div></div>
      </div>
      <input type="hidden" name="escala[on]" value="0"><?= UI::checkDS('escala[on]', (bool)$conf['escala']['on'], 'Escalar por indicadores clave', 'Con esa cantidad de claves en ' . $C::nombre('crit') . ' el proyecto pasa a ' . $C::nombre('crit') . '; con una sola, un ' . $C::nombre('ok') . ' baja a ' . $C::nombre('warn') . '.') ?>
    </section>
    <section class="mc-card col-card">
      <h2 class="font-display">Indicadores, pesos y umbrales</h2>
      <p class="col-card__sub">Los umbrales son valores iniciales para calibrar con la PMO. Con coma decimal (0,95).</p>
      <div class="col-tabla-wrap"><table class="col-tabla col-tabla--conf">
        <thead><tr><th>Activo</th><th>Indicador</th><th>Marco</th><th>Sentido</th><th><?= e($C::nombre('ok')) ?></th><th><?= e($C::nombre('warn')) ?></th><th>Peso</th><th>Clave</th><th>Origen</th></tr></thead><tbody>
        <?php foreach (Colorimetria::DIMS as $dim): ?>
        <tr class="col-tabla__dim"><td colspan="9"><?= e($dim) ?></td></tr>
        <?php foreach (Colorimetria::IND as $id => $_): $i = $C::ind($id); if ($i['dim'] !== $dim) continue; $un = $i['u'] === '%' ? '%' : $i['u']; ?>
        <tr><td><input type="hidden" name="ind[<?= $id ?>][act]" value="0"><input type="checkbox" class="col-check" name="ind[<?= $id ?>][act]" value="1"<?= $i['act'] ? ' checked' : '' ?> aria-label="Activar <?= e($i['n']) ?>"></td>
          <td><b><?= e($i['n']) ?></b></td><td><span class="col-tag<?= $i['m'] === 'Scrum' ? ' col-tag--scrum' : '' ?>"><?= $i['m'] ?></span></td>
          <td class="col-nw"><?= $i['dir'] === 'up' ? 'Más es mejor' : 'Menos es mejor' ?></td>
          <?php foreach (['g', 'a'] as $k): ?><td><div class="mc-input mc-input--sm col-num"><input class="mc-input__control mc-num" name="ind[<?= $id ?>][<?= $k ?>]" inputmode="decimal" value="<?= e($C::num((float)$i[$k], max(0, (int)$i['d']))) ?>" aria-label="Umbral de <?= $k === 'g' ? e($C::nombre('ok')) : e($C::nombre('warn')) ?> de <?= e($i['n']) ?>"><?= $un !== '' ? '<span class="mc-input__affix">' . e($un) . '</span>' : '' ?></div></td><?php endforeach; ?>
          <td><div class="mc-input mc-input--sm col-num"><input class="mc-input__control mc-num" name="ind[<?= $id ?>][w]" inputmode="decimal" value="<?= e($C::num((float)$i['w'])) ?>" aria-label="Peso de <?= e($i['n']) ?>"></div></td>
          <td><input type="hidden" name="ind[<?= $id ?>][clave]" value="0"><input type="checkbox" class="col-check" name="ind[<?= $id ?>][clave]" value="1"<?= $i['clave'] ? ' checked' : '' ?> aria-label="Clave: <?= e($i['n']) ?>"></td>
          <td class="col-nw"><?= !empty($i['auto']) ? '<span class="col-origen">Del panel</span>' : '<span class="col-origen col-origen--man">Se anota</span>' ?></td></tr>
        <?php endforeach; endforeach; ?>
        </tbody></table></div>
      <p class="col-card__sub">Suma de pesos activos: <b class="mc-num"><?= $C::num(array_sum(array_map(fn($id) => $C::ind($id)['act'] ? (float)$C::ind($id)['w'] : 0, array_keys(Colorimetria::IND)))) ?></b>. El puntaje se normaliza solo, así que no tiene que sumar 100.</p>
    </section>
    <div class="col-medir__pie">
      <button type="submit" class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar la configuración</span></button>
    </div>
  </form>
  <form method="post" action="actions.php" class="col-reset" data-confirmar="Vuelven los umbrales, pesos, nombres y colores recomendados de la guía." data-confirmar-titulo="¿Restablecer la configuración?" data-confirmar-ok="Sí, restablecer">
    <input type="hidden" name="accion" value="col_conf_reset">
    <button class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::iconoDS('reintentar', 16) ?><span class="mc-btn__label">Restablecer los valores recomendados</span></button>
  </form>

<?php elseif ($v === 'guia'):
    // Contraste WCAG del texto sobre el fondo de cada píldora
    $lum = function (string $hex): float {
        $c = array_map(fn($x) => hexdec($x) / 255, str_split(ltrim($hex, '#'), 2));
        $c = array_map(fn($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $contraste = function (string $a, string $b) use ($lum): float { [$x, $y] = [$lum($a), $lum($b)]; return (max($x, $y) + 0.05) / (min($x, $y) + 0.05); };
    $sw = fn(string $hex) => '<span class="col-gsw"><i style="background:' . e($hex) . '"></i>' . e($hex) . '</span>';
?>
  <section class="mc-card col-card">
    <h2 class="font-display">Estados y colores</h2>
    <div class="col-tabla-wrap"><table class="col-tabla col-tabla--conf">
      <thead><tr><th>Estado</th><th>Cuándo se usa</th><th>Tema claro</th><th>Tema oscuro</th><th>Contraste del texto</th></tr></thead><tbody>
      <?php foreach (Colorimetria::ESTADOS as $k => [$tipo, , , $uso]): $p = Colorimetria::PAL[$conf['estados'][$k]['color']]; $cl = $contraste($p['l'][1], $p['l'][0]); $cd = $contraste($p['d'][1], $p['d'][0]); ?>
      <tr><td><?= $pill($k) ?><small class="col-sub"><?= e($tipo) ?></small></td><td><?= e($uso) ?></td>
        <td><?= $sw($p['l'][0]) ?><?= $sw($p['l'][1]) ?><?= $sw($p['l'][2]) ?></td><td><?= $sw($p['d'][0]) ?><?= $sw($p['d'][1]) ?><?= $sw($p['d'][2]) ?></td>
        <td class="col-nw mc-num"><?= $C::num($cl, 1) ?> : 1 / <?= $C::num($cd, 1) ?> : 1 <?= min($cl, $cd) >= 4.5 ? '· AA' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p>Cada estado lleva color, ícono y texto. El color nunca va solo.</p>
  </section>
  <div class="col-dos">
    <section class="mc-card col-card">
      <h2 class="font-display">Cómo se calcula cada estado</h2>
      <ul>
        <li>Cada indicador queda en <b><?= e($C::nombre('ok')) ?></b> si alcanza su meta, en <b><?= e($C::nombre('warn')) ?></b> si está entre la meta y el umbral de alerta, y en <b><?= e($C::nombre('crit')) ?></b> si no llega al umbral de alerta. Sin dato: <b><?= e($C::nombre('nm')) ?></b>, y no cuenta.</li>
        <li>El puntaje es el promedio ponderado por peso: <?= e($C::nombre('ok')) ?> vale <?= $C::num($conf['puntos']['ok']) ?>, <?= e($C::nombre('warn')) ?> <?= $C::num($conf['puntos']['warn']) ?> y <?= e($C::nombre('crit')) ?> <?= $C::num($conf['puntos']['crit']) ?> puntos.</li>
        <li>Desde <?= $C::num($conf['bandas']['ok']) ?> puntos el proyecto está en <?= e($C::nombre('ok')) ?>; desde <?= $C::num($conf['bandas']['warn']) ?>, en <?= e($C::nombre('warn')) ?>; por debajo, en <?= e($C::nombre('crit')) ?>.</li>
        <?php if ($conf['escala']['on']): ?><li>Escalamiento: con <?= (int)$conf['escala']['min'] ?> o más indicadores clave en <?= e($C::nombre('crit')) ?>, el proyecto pasa a <?= e($C::nombre('crit')) ?>; con uno solo, un <?= e($C::nombre('ok')) ?> baja a <?= e($C::nombre('warn')) ?>.</li><?php endif; ?>
        <li>Los proyectos <?= e($C::nombre('none')) ?>, <?= e($C::nombre('hold')) ?> o <?= e($C::nombre('done')) ?> muestran su fase en vez de la salud. La fase sale del estado del proyecto en el panel (o la que se elija al anotar).</li>
      </ul>
    </section>
    <section class="mc-card col-card">
      <h2 class="font-display">Origen de los indicadores</h2>
      <ul>
        <li><b>PMO:</b> cronograma y costo con valor ganado (SPI y CPI), desviación de presupuesto, margen, alcance, hitos, riesgos, satisfacción y aceptación de entregables.</li>
        <li><b>Scrum:</b> predictibilidad del sprint, avance del release, tiempo de ciclo, calidad (defectos, cobertura de pruebas, Definición de Hecho), impedimentos, salud del equipo y variación de velocidad.</li>
        <li><b>Del panel</b> (se calculan solos): <?= e(implode('; ', array_map(fn($id) => Colorimetria::IND[$id]['n'] . ': ' . mb_strtolower(rtrim(Colorimetria::COMO[$id]['p'], '.')), array_keys(Colorimetria::COMO)))) ?>.</li>
        <li><b>Se anotan</b> cada mes: <?= e(implode(', ', array_map(fn($i) => $i['n'], array_filter(Colorimetria::IND, fn($i) => empty($i['auto']))))) ?>. Un valor anotado manda sobre el calculado.</li>
        <li>Los umbrales de SPI y CPI (0,95 y 0,85) son los de uso habitual en valor ganado. Todos los umbrales son valores iniciales para calibrar con la PMO.</li>
      </ul>
    </section>
  </div>
  <section class="mc-card col-card">
    <h2 class="font-display">Reglas de uso</h2>
    <ul>
      <li>Un solo tono por estado en todo el tablero.</li>
      <li>Los colores se eligen de una paleta validada con contraste AA en tema claro y oscuro.</li>
      <li>Fondo suave y texto oscuro en las píldoras. El color pleno se reserva para barras, puntos y zonas de los gráficos (sin franjas de color en las filas).</li>
      <li>Las zonas de los gráficos de bala se dibujan con los mismos umbrales de la configuración.</li>
      <li>Valores en formato Ecuador: coma decimal y punto de miles.</li>
    </ul>
  </section>
  <section class="mc-card col-card">
    <h2 class="font-display">Tokens de color</h2>
    <pre class="col-codigo"><?= e(str_replace([';', '{', '}'], [";\n  ", "{\n  ", "\n}\n"], Colorimetria::css())) ?></pre>
  </section>
  <section class="mc-card col-card">
    <h2 class="font-display">Configuración actual en JSON</h2>
    <pre class="col-codigo"><?= e(json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
  </section>
<?php endif; ?>
</div>
<script>
// Abrir el detalle de una fila y filtrar por estado (sin recargar)
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-col-abrir]');
  if (b) {
    const det = document.getElementById(b.getAttribute('aria-controls'));
    const abrir = b.getAttribute('aria-expanded') !== 'true';
    b.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (det) det.hidden = !abrir;
    b.closest('tr').classList.toggle('is-abierta', abrir);
    return;
  }
  const f = e.target.closest('[data-col-filtros] .col-filtro');
  if (!f) return;
  const k = f.dataset.k;
  document.querySelectorAll('[data-col-filtros] .col-filtro').forEach((x) => x.setAttribute('aria-pressed', x === f ? 'true' : 'false'));
  document.querySelectorAll('[data-col-tabla] tbody tr').forEach((tr) => {
    const ver = !k || tr.dataset.estado === k;
    if (tr.classList.contains('col-fila-det')) { if (!ver) tr.hidden = true; else tr.hidden = !tr.previousElementSibling.classList.contains('is-abierta'); }
    else tr.style.display = ver ? '' : 'none';
  });
});
</script>
<?php UI::fin(); ?>
