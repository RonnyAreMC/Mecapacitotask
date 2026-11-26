<?php
/**
 * Parámetros de firmas (solo el administrador):
 *  - Coordinaciones: Desarrollo, Procesos… con sus personas y su cadena de
 *    revisores en orden. Los informes semanales y los documentos que crea
 *    alguien de esa coordinación proponen esos revisores.
 *  - Cargos: el cargo oficial con el que firma cada persona (el que sale en
 *    el cuadro de firmas), aparte de su rol en el Hub.
 *  - Informes semanales: cuándo se arman, de quiénes y a quién se avisa.
 * Todo se edita en su modal. La lógica está en lib/firmas/Firmas.php.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/firmas/firmas_ui.php';
require_once __DIR__ . '/lib/firmas/InformeSemanal.php';
Auth::requiereAdmin();

$miembros = (new MiembroRepo())->todos();
$mapa = (new MiembroRepo())->mapa();
$coords = Firmas::coordinaciones();
$conf = InformeSemanal::conf();
$nombre = fn(int $u) => (string)($mapa[$u]['nombre'] ?? '—');
$coordDe = [];
foreach ($coords as $c) foreach ($c['personas'] as $u) $coordDe[$u][] = $c['nombre'];
// Fotos en fila (las primeras y «+N»); el «+N» dice quiénes faltan al pasar el mouse
$pila = function (array $uids, int $max = 5) use ($mapa): string {
    $ms = array_values(array_filter(array_map(fn($u) => $mapa[$u] ?? null, $uids)));
    if (!$ms) return '';
    $html = UI::avatarStack($ms, $max, 32);
    if ($resto = array_slice($ms, $max)) {
        $html = str_replace('<span class="avatar avatar-more"', '<span class="avatar avatar-more" title="' . e(implode(', ', array_map(fn($m) => (string)$m['nombre'], $resto))) . '"', $html);
    }
    return '<span class="fp-pila">' . $html . '</span>';
};
$tab = in_array($_GET['tab'] ?? '', ['coordinaciones', 'cargos', 'informes'], true) ? $_GET['tab'] : 'coordinaciones';
// La imagen de cada coordinación, según su nombre (Desarrollo, Procesos, Diseño…)
$icoCoord = function (string $nombre): string {
    $n = mb_strtolower($nombre);
    return match (true) {
        str_contains($n, 'desarr') || str_contains($n, 'program') => 'programadores',
        str_contains($n, 'proces') || str_contains($n, 'anali')   => 'procesos',
        str_contains($n, 'dise')                                   => 'disenadores',
        str_contains($n, 'soporte') || str_contains($n, 'mesa')    => 'soporte',
        default                                                    => 'usuarios',
    };
};
$nuevaBtn = '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-fd-abrir="dlg-coord-nueva">' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Nueva coordinación</span></button>';

UI::inicio('Parámetros de firmas', 'firmas-param');
UI::cabecera('Parámetros de <span class="text-secondary">firmas</span>',
    'Las coordinaciones con sus revisores, los cargos que salen en el cuadro de firmas y los informes semanales.',
    '<div class="fd-cab-acc"><a class="mc-iconbtn mc-iconbtn--tinte" href="firmas.php" aria-label="Volver a Firmas" data-ds-tip="Volver">' . UI::iconoDS('chevron-izq', 20) . '</a>'
    . '<button type="button" class="mc-iconbtn mc-iconbtn--tinte" data-fd-abrir="dlg-coord-nueva" aria-label="Nueva coordinación" data-ds-tip="Nueva coordinación">' . UI::iconoDS('mas', 20) . '</button></div>');
?>
<div class="ds fd-pagina">
  <?php /* Las pestañas (assets/ds.js) abren la del #ancla: se pone la que eligió el servidor si no hay otra */ ?>
  <script>if (!location.hash) history.replaceState(null, '', location.pathname + location.search + '#<?= $tab ?>');</script>
  <div class="mc-tabs mc-tabs--line" role="tablist" aria-label="Parámetros" data-ds-tabs-auto>
    <?php foreach (['coordinaciones' => ['Coordinaciones', 'usuarios', count($coords)], 'cargos' => ['Cargos', 'insignia', 0], 'informes' => ['Informes semanales', 'reportes', 0]] as $tid => [$etq, $ico, $n]): $on = $tid === $tab; ?>
    <button type="button" class="mc-tab<?= $on ? ' is-active' : '' ?>" role="tab" id="tab-<?= $tid ?>" aria-selected="<?= $on ? 'true' : 'false' ?>" aria-controls="panel-<?= $tid ?>" data-tab="<?= $tid ?>"<?= $on ? '' : ' tabindex="-1"' ?>>
      <?= UI::iconoDS($ico, 18) ?><span class="mc-tab__label"><?= $etq ?></span><?php if ($n): ?><span class="mc-tab__count"><?= $n ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <span class="mc-tabs__ink"></span>
  </div>

  <!-- Coordinaciones -->
  <section class="ds-panel fd-panel" id="panel-coordinaciones" role="tabpanel" aria-labelledby="tab-coordinaciones" data-panel="coordinaciones"<?= $tab === 'coordinaciones' ? '' : ' hidden' ?>>
    <?php if (!$coords): ?>
      <?= UI::vacioDS('procesos', 'Todavía no hay coordinaciones', 'Crea una por área (Desarrollo, Procesos…) con sus personas y quiénes revisan lo que elaboran. Así cada informe y cada documento propone a sus revisores.', $nuevaBtn) ?>
    <?php else: ?>
    <div class="fp-coords">
      <?php foreach ($coords as $c): $nP = count($c['personas']); ?>
      <article class="mc-card fp-coord">
        <header class="fp-banda">
          <span class="fp-banda__img" aria-hidden="true"><?= UI::iconoDS($icoCoord($c['nombre']), 26) ?></span>
          <span class="fp-banda__tit"><b class="truncate"><?= e($c['nombre']) ?></b><small><?= $nP ?> <?= $nP === 1 ? 'persona' : 'personas' ?></small></span>
          <span class="fp-banda__acc">
            <button type="button" class="mc-iconbtn fp-banda__btn" data-fd-abrir="dlg-coord-<?= e($c['id']) ?>" aria-label="Editar «<?= e($c['nombre']) ?>»" data-ds-tip="Editar"><?= UI::iconoDS('editar-cuadro', 22) ?></button>
            <form method="post" action="actions.php" data-confirmar="Sus personas dejan de tener revisores propuestos. Los documentos que ya existen no cambian." data-confirmar-titulo="¿Eliminar «<?= e($c['nombre']) ?>»?" data-confirmar-ok="Sí, eliminar">
              <input type="hidden" name="accion" value="firma_coord_eliminar"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
              <button class="mc-iconbtn fp-banda__btn" aria-label="Eliminar «<?= e($c['nombre']) ?>»" data-ds-tip="Eliminar"><?= UI::iconoDS('eliminar-bote', 22) ?></button></form>
          </span>
        </header>
        <div class="fp-coord__cuerpo">
          <div class="fp-coord__bloque">
            <h3 class="fp-sub">Cadena de firmas</h3>
            <ol class="fp-cadena fp-cadena--fila">
              <li><span class="fd-fila__n">1</span><span class="fp-cadena__txt"><small>Elaborado por</small><b>Quien lo elabora</b></span></li>
              <?php foreach (Firmas::revisoresDe($c) as $i => $rv): ?>
              <li><span class="fd-fila__n"><?= $i + 2 ?></span><?= UI::avatar($mapa[$rv['uid']] ?? null, 32) ?>
                <span class="fp-cadena__txt"><small><?= e($rv['rol']) ?></small><b class="truncate" title="<?= e($nombre($rv['uid'])) ?>"><?= e($nombre($rv['uid'])) ?></b><em class="truncate" title="<?= e($rv['cargo']) ?>"><?= e($rv['cargo']) ?></em></span></li>
              <?php endforeach; ?>
              <?php if (!$c['revisores']): ?><li class="fp-cadena__vacia">Sin revisores: lo firma solo quien lo elabora.</li><?php endif; ?>
            </ol>
          </div>
          <div class="fp-coord__bloque fp-coord__bloque--gente">
            <h3 class="fp-sub">Personas</h3>
            <?php if ($c['personas']): ?>
            <div class="fp-gente"><?= $pila($c['personas'], 6) ?>
              <button type="button" class="mc-iconbtn mc-iconbtn--tinte" data-fd-abrir="dlg-coord-<?= e($c['id']) ?>" aria-label="Ver o cambiar las personas de «<?= e($c['nombre']) ?>»" data-ds-tip="Ver o cambiar"><?= UI::iconoDS('ver-cambiar', 20) ?></button></div>
            <?php else: ?><p class="fp-nota">Todavía nadie. Edítala para sumar personas.</p><?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- Cargos -->
  <section class="ds-panel fd-panel" id="panel-cargos" role="tabpanel" aria-labelledby="tab-cargos" data-panel="cargos"<?= $tab === 'cargos' ? '' : ' hidden' ?>>
    <div class="mc-table fp-tabla">
      <div class="mc-table__toolbar"><div class="mc-table__heading"><h2 class="mc-table__title">Cargos para firmar</h2>
        <p class="mc-table__desc">El que sale bajo el nombre de cada persona en el cuadro de firmas. Sin cargo propio, se usa su rol del Hub.</p></div></div>
      <div class="mc-table__scroll">
        <table class="mc-table__table">
          <thead><tr><th>Persona</th><th>Cargo para firmar</th><th>Rol en el Hub</th><th>Coordinación</th><th class="mc-table__actions-h"><span class="mc-sr-only">Editar</span></th></tr></thead>
          <tbody>
          <?php foreach ($miembros as $m): $u = (int)$m['id']; $cf = trim((string)($m['cargo_firma'] ?? '')); ?>
          <tr>
            <td data-label="Persona"><div class="mc-table__cell fd-quien"><?= UI::avatar($m, 30) ?><span class="fd-quien__txt"><b><?= e((string)$m['nombre']) ?></b><small><?= e((string)($m['email'] ?? '')) ?></small></span></div></td>
            <td data-label="Cargo para firmar"><?= $cf !== '' ? '<span class="fp-cargo">' . e($cf) . '</span>' : '<span class="fp-sin">— usa su rol</span>' ?></td>
            <td data-label="Rol en el Hub" class="fp-rol"><?= e((string)($m['rol'] ?? '—')) ?></td>
            <td data-label="Coordinación"><?= isset($coordDe[$u]) ? implode(' ', array_map(fn($n) => '<span class="mc-status mc-status--sm mc-status--azul">' . e($n) . '</span>', $coordDe[$u])) : '<span class="fp-sin">—</span>' ?></td>
            <td class="mc-table__actions-c"><button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fc-editar data-uid="<?= $u ?>" data-nombre="<?= e((string)$m['nombre']) ?>" data-cargo="<?= e($cf) ?>" data-rol="<?= e((string)($m['rol'] ?? '')) ?>" aria-label="Editar el cargo de <?= e((string)$m['nombre']) ?>" data-ds-tip="Editar"><?= UI::iconoDS('editar-cuadro', 20) ?></button></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Informes semanales -->
  <section class="ds-panel fd-panel" id="panel-informes" role="tabpanel" aria-labelledby="tab-informes" data-panel="informes"<?= $tab === 'informes' ? '' : ' hidden' ?>>
    <article class="mc-card fp-coord">
      <header class="fp-banda">
        <span class="fp-banda__img" aria-hidden="true"><?= UI::iconoDS('reportes', 26) ?></span>
        <span class="fp-banda__tit"><b>Informe semanal de actividades</b><small><?= $conf['activo'] ? 'Activo' : 'Apagado' ?></small></span>
        <span class="fp-banda__acc"><button type="button" class="mc-iconbtn fp-banda__btn" data-fd-abrir="dlg-informes" aria-label="Editar los informes semanales" data-ds-tip="Editar"><?= UI::iconoDS('editar-cuadro', 22) ?></button></span>
      </header>
      <dl class="fp-datos fp-coord__cuerpo">
        <dt>Cuándo se arman</dt><dd><?= e(InformeSemanal::DIAS[$conf['dia']]) ?> a las <?= e($conf['hora']) ?><?= $conf['activo'] ? '' : ' (apagado: solo a mano)' ?></dd>
        <dt>Quiénes hacen informe</dt><dd><?= $conf['personas'] ? '<span class="fp-gente">' . $pila($conf['personas'], 8) . '<span class="fp-cuenta">' . count($conf['personas']) . (count($conf['personas']) === 1 ? ' persona' : ' personas') . '</span></span>' : '<span class="fp-sin">Nadie todavía</span>' ?></dd>
        <dt>Quiénes lo firman</dt><dd>Los revisores de la coordinación de cada persona<?= $conf['revisores'] ? '; si no está en ninguna, ' . e(implode(' → ', array_map(fn($r) => $nombre($r['uid']), $conf['revisores']))) : '' ?></dd>
        <dt>Al quedar firmado se avisa a</dt><dd><?= $conf['avisar'] ? '<span class="fp-gente">' . $pila($conf['avisar'], 5) . '<span class="fp-cuenta">' . e(count($conf['avisar']) <= 2 ? implode(' y ', array_map($nombre, $conf['avisar'])) : count($conf['avisar']) . ' personas') . '</span></span>' : '<span class="fp-sin">Nadie</span>' ?></dd>
        <dt>Redacción</dt><dd><?= $conf['ia'] ? 'Con IA (la consola de IA del Hub)' : 'Sin IA: con los datos de la semana' ?></dd>
      </dl>
    </article>
  </section>
</div>

<?= FirmasUI::modalCoordinacion(null) ?>
<?php foreach ($coords as $c) echo FirmasUI::modalCoordinacion($c); ?>
<?= FirmasUI::modalCargo() ?>
<?= FirmasUI::modalInformes() ?>
<script src="<?= asset('assets/firmas.js') ?>" defer></script>
<?php UI::fin(); ?>
