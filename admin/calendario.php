<?php
/**
 * Calendario general: los feriados nacionales del Ecuador (lib/Feriados.php).
 * Esos días no son hábiles en todo el panel: no hay daily, no corren los
 * plazos ni el % esperado de Releases, las reuniones que se repiten no caen
 * ese día y no salen los avisos automáticos. Todos lo ven; el administrador
 * agrega, quita o trae los de un año desde la base pública.
 */
require_once __DIR__ . '/lib/bootstrap.php';
Auth::requiereLogin();

$esAdmin = Auth::esAdmin();
$hoy     = date('Y-m-d');
$anio    = max(2020, min(2100, (int)($_GET['a'] ?? date('Y'))));
$meses   = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dias    = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

$mapa  = Feriados::mapa();
$todos = Feriados::lista();
$delAnio = array_values(array_filter($todos, fn($f) => str_starts_with((string)$f['fecha'], $anio . '-')));
// Sin nada guardado todavía (antes de actualizar), lo que trae el código
if (!$todos) {
    foreach ($mapa as $fe => $no) if (str_starts_with($fe, $anio . '-')) $delAnio[] = ['id' => 0, 'fecha' => $fe, 'nombre' => $no, 'origen' => 'oficial', 'activo' => true];
}
$activos = array_values(array_filter($delAnio, fn($f) => !empty($f['activo'])));
$entreSemana = count(array_filter($activos, fn($f) => (int)date('N', strtotime($f['fecha'])) <= 5));
$proximo = null;
foreach ($mapa as $fe => $no) if ($fe > $hoy) { $proximo = [$fe, $no]; break; }
$hoyFeriado = Feriados::nombre($hoy);
$fechaLarga = fn(string $f) => $dias[(int)date('w', strtotime($f))] . ' ' . (int)date('j', strtotime($f)) . ' de ' . $meses[(int)date('n', strtotime($f)) - 1];

$acciones = '';
if ($esAdmin) {
    $acciones = '<form method="post" action="actions.php" class="fcal-traer"><input type="hidden" name="accion" value="feriado_traer"><input type="hidden" name="anio" value="' . $anio . '">'
        . '<button class="mc-btn mc-btn--secondary mc-btn--md" data-ds-tip="Agrega los feriados nacionales de ' . $anio . ' que falten (calendario oficial o la base pública Nager.Date)">'
        . UI::iconoDS('descargar', 18) . '<span class="mc-btn__label">Traer los de ' . $anio . '</span></button></form>'
        . '<button type="button" class="mc-btn mc-btn--success mc-btn--md" data-abrir-dlg="dlg-feriado">' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Agregar feriado</span></button>';
}

UI::inicio('Calendario', 'calendario');
UI::cabecera('Calendario', 'Los feriados nacionales del Ecuador. Esos días no se cuentan: no hay daily, no corren los plazos y no salen avisos automáticos.', $acciones);
?>
<div class="ds fcal-pagina">
  <section class="fcal-cab">
    <div class="fcal-anio">
      <a class="mc-iconbtn mc-iconbtn--outline" href="calendario.php?a=<?= $anio - 1 ?>" aria-label="Año anterior" data-ds-tip="<?= $anio - 1 ?>"><?= UI::iconoDS('chevron-izq', 18) ?></a>
      <b class="font-display mc-num"><?= $anio ?></b>
      <a class="mc-iconbtn mc-iconbtn--outline" href="calendario.php?a=<?= $anio + 1 ?>" aria-label="Año siguiente" data-ds-tip="<?= $anio + 1 ?>"><?= UI::iconoDS('chevron-der', 18) ?></a>
      <?php if ($anio !== (int)date('Y')): ?><a class="mc-btn mc-btn--ghost mc-btn--sm" href="calendario.php"><span class="mc-btn__label">Este año</span></a><?php endif; ?>
    </div>
    <p class="fcal-resumen"><b class="mc-num"><?= count($activos) ?></b> feriado<?= count($activos) === 1 ? '' : 's' ?> en <?= $anio ?> · <b class="mc-num"><?= $entreSemana ?></b> caen entre semana y no se cuentan como días hábiles</p>
    <?php if ($hoyFeriado !== ''): ?>
    <p class="fcal-hoy is-feriado"><?= UI::iconoDS('agenda', 16) ?> <span>Hoy es feriado: <b><?= e($hoyFeriado) ?></b>. No hay daily ni avisos automáticos.</span></p>
    <?php elseif ($proximo): $faltan = (int)round((strtotime($proximo[0]) - strtotime($hoy)) / 86400); ?>
    <p class="fcal-hoy"><?= UI::iconoDS('agenda', 16) ?> <span>El próximo: <b><?= e($fechaLarga($proximo[0])) ?></b> · <?= e($proximo[1]) ?> · <?= $faltan === 1 ? 'mañana' : 'en ' . $faltan . ' días' ?></span></p>
    <?php endif; ?>
  </section>

  <div class="fcal-cuerpo">
    <div class="fcal-meses">
      <?php for ($m = 1; $m <= 12; $m++):
        $primero = mktime(0, 0, 0, $m, 1, $anio);
        $vacios = (int)date('N', $primero) - 1;
        $nDias = (int)date('t', $primero); ?>
      <section class="fcal-mes<?= date('Y-m') === sprintf('%04d-%02d', $anio, $m) ? ' is-actual' : '' ?>" aria-label="<?= e(ucfirst($meses[$m - 1])) ?>">
        <h3><?= e(ucfirst($meses[$m - 1])) ?></h3>
        <div class="fcal-grid" role="grid">
          <?php foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $dw): ?><span class="fcal-dow" aria-hidden="true"><?= $dw ?></span><?php endforeach; ?>
          <?php for ($i = 0; $i < $vacios; $i++): ?><span class="fcal-d is-vacio"></span><?php endfor; ?>
          <?php for ($d = 1; $d <= $nDias; $d++):
            $fe = sprintf('%04d-%02d-%02d', $anio, $m, $d);
            $fer = $mapa[$fe] ?? '';
            $finde = (int)date('N', strtotime($fe)) >= 6; ?>
          <span class="fcal-d<?= $finde ? ' is-finde' : '' ?><?= $fer !== '' ? ' is-feriado' : '' ?><?= $fe === $hoy ? ' is-hoy' : '' ?><?= $fe < $hoy ? ' is-pasado' : '' ?>"<?= $fer !== '' ? ' data-ds-tip="' . e($fer) . '" aria-label="' . $d . ': ' . e($fer) . '"' : '' ?>><?= $d ?></span>
          <?php endfor; ?>
        </div>
      </section>
      <?php endfor; ?>
    </div>

    <aside class="mc-card fcal-lista" aria-label="Feriados de <?= $anio ?>">
      <h2 class="font-display">Feriados de <?= $anio ?></h2>
      <?php if (!$delAnio): ?>
      <p class="fcal-vacio"><?= $esAdmin ? 'Todavía no hay feriados de ' . $anio . '. Pulsa «Traer los de ' . $anio . '» para cargarlos.' : 'Todavía no se cargaron los feriados de ' . $anio . '.' ?></p>
      <?php else: ?>
      <ol class="fcal-fers">
        <?php foreach ($delAnio as $f): $on = !empty($f['activo']); $t = strtotime($f['fecha']); ?>
        <li class="fcal-fer<?= $on ? '' : ' is-off' ?><?= $f['fecha'] < $hoy ? ' is-pasado' : '' ?><?= $f['fecha'] === $hoy ? ' is-hoy' : '' ?>">
          <span class="fcal-fer__dia"><b class="mc-num"><?= (int)date('j', $t) ?></b><small><?= e(mb_substr($meses[(int)date('n', $t) - 1], 0, 3)) ?></small></span>
          <span class="fcal-fer__txt"><b><?= e((string)$f['nombre']) ?></b>
            <small><?= e(ucfirst($dias[(int)date('w', $t)])) ?><?= (int)date('N', $t) >= 6 ? ' (fin de semana)' : '' ?> · <?= e(Feriados::ORIGENES[$f['origen'] ?? ''] ?? 'Feriado') ?><?= $on ? '' : ' · <em>se cuenta como hábil</em>' ?></small></span>
          <?php if ($esAdmin && (int)$f['id'] > 0): ?>
          <form method="post" action="actions.php"<?= $on ? ' data-confirmar="Ese día vuelve a contarse como hábil: hay daily y salen los avisos." data-confirmar-titulo="¿Quitar «' . e((string)$f['nombre']) . '»?" data-confirmar-ok="Sí, quitar"' : '' ?>>
            <input type="hidden" name="accion" value="<?= $on ? 'feriado_quitar' : 'feriado_agregar' ?>"><input type="hidden" name="anio" value="<?= $anio ?>">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="fecha" value="<?= e((string)$f['fecha']) ?>"><input type="hidden" name="nombre" value="<?= e((string)$f['nombre']) ?>">
            <button class="mc-iconbtn mc-iconbtn--sm" aria-label="<?= $on ? 'Quitar' : 'Volver a contarlo como feriado' ?>" data-ds-tip="<?= $on ? 'Quitar: ese día se trabaja' : 'Volver a contarlo como feriado' ?>"><?= UI::iconoDS($on ? 'eliminar' : 'reintentar', 16) ?></button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <p class="fcal-fuente">Fuente: el calendario oficial del Ecuador con sus traslados y la base pública <a href="https://date.nager.at/PublicHoliday/Ecuador/<?= $anio ?>" target="_blank" rel="noopener">Nager.Date</a>. Si el Gobierno mueve un feriado por decreto, se corrige aquí.</p>
    </aside>
  </div>
</div>

<?php if ($esAdmin): ?>
<dialog class="ds-modal" id="dlg-feriado" aria-labelledby="dlg-fer-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="feriado_agregar"><input type="hidden" name="anio" value="<?= $anio ?>">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('agenda', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-fer-t">Agregar feriado</h2>
        <p class="ds-modal__sub">Ese día no se cuenta como hábil en todo el panel: sin daily, sin plazos ni avisos automáticos.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body ds-grid" style="gap:var(--space-4)">
      <div class="mc-field"><label class="mc-field__label" for="fer-fecha">Fecha</label>
        <input class="input-meca" id="fer-fecha" type="date" name="fecha" required></div>
      <div class="mc-field"><label class="mc-field__label" for="fer-nombre">Nombre</label>
        <div class="mc-input"><input class="mc-input__control" id="fer-nombre" name="nombre" required maxlength="120" placeholder="Ej.: Feriado por decreto"></div></div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--success mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Agregar</span></button>
    </footer>
  </form>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-abrir-dlg]').forEach((b) => b.addEventListener('click', () => document.getElementById(b.dataset.abrirDlg)?.showModal()));
});
</script>
<?php endif; ?>
<?php UI::fin(); ?>
