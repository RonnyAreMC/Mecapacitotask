<?php
/**
 * Configuración de la colorimetría (lib/Colorimetria.php), solo para el
 * administrador: los nombres y colores de cada estado, los puntos y bandas del
 * puntaje, el escalamiento y los umbrales y pesos de cada indicador.
 *
 * La colorimetría no es un módulo aparte: se aplica en todo el panel (avances
 * de proyectos, Releases, revisiones). La salud de cada proyecto y sus valores
 * del mes se ven y se anotan en su propia página (el estado bajo «Avance»), y
 * los de cada equipo, en su página de Releases.
 */
require_once __DIR__ . '/lib/bootstrap.php';
Auth::requiereLogin();
if (!Auth::esAdmin()) redirigir('index.php', 'La configuración de la colorimetría la cambia el administrador.', 'error');

$conf = Colorimetria::conf();
$C = Colorimetria::class;

UI::inicio('Colorimetría', 'colorimetria');
UI::cabecera('Colorimetría', 'Los tonos de cada estado que usa todo el panel, cómo se calcula el puntaje y los umbrales de cada indicador. La salud y los valores del mes se ven en la página de cada proyecto y de cada equipo.');
?>
<div class="ds col-pagina">
  <form method="post" action="actions.php" class="col-conf">
    <input type="hidden" name="accion" value="col_conf">
    <section class="mc-card col-card">
      <h2 class="font-display">Estados y colores</h2>
      <p class="col-card__sub">Un solo tono por estado en todo el tablero. Los colores vienen de una paleta validada con contraste AA en tema claro y oscuro.</p>
      <div class="col-tabla-wrap"><table class="col-tabla col-tabla--conf">
        <thead><tr><th>Vista previa</th><th>Tipo</th><th>Nombre</th><th>Color</th></tr></thead><tbody>
        <?php foreach (Colorimetria::ESTADOS as $k => [$tipo]): ?>
        <tr><td><?= Colorimetria::estado($k) ?></td><td><?= e($tipo) ?></td>
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
</div>
<?php UI::fin(); ?>
