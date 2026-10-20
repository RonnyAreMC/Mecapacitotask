<?php
/**
 * Consola de IA: claves de OpenAI y Gemini, modelos con sus precios, qué modelo
 * usa cada función (con uno de respaldo) y cuánto se está gastando.
 *
 * Por pestañas y con los componentes del design system (.mc-*, assets/ds.css):
 * Resumen · Claves · Modelos y precios · Funciones · Consumo. Las cuatro
 * primeras son un solo formulario: cambiar de pestaña no pierde lo escrito.
 *
 * Solo administrador: aquí están las claves. Las funciones de IA las usan el
 * administrador y los Scrum Masters desde cada pantalla (ver lib/IA.php).
 */
require_once __DIR__ . '/lib/bootstrap.php';
// Fuera de bootstrap a propósito: si al subir por FTP falta, falla solo esto.
require_once __DIR__ . '/lib/IA.php';
if (is_file(__DIR__ . '/lib/Tutoriales.php')) require_once __DIR__ . '/lib/Tutoriales.php';
Auth::requiereAdmin();

$cfg   = IA::config();
$mes   = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
$usos  = (new JsonStore('ia_uso'))->where('mes', $mes);
usort($usos, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
$miembros = (new MiembroRepo())->mapa();

$gasto = 0.0; $nOk = 0; $nErr = 0; $porTarea = []; $porPersona = [];
foreach ($usos as $u) {
    $c = (float)($u['costo'] ?? 0);
    $gasto += $c;
    ($u['estado'] ?? '') === 'ok' ? $nOk++ : $nErr++;
    $t = (string)($u['tarea'] ?? '');
    $porTarea[$t]['usos'] = ($porTarea[$t]['usos'] ?? 0) + 1;
    $porTarea[$t]['tokens'] = ($porTarea[$t]['tokens'] ?? 0) + (int)($u['entrada'] ?? 0) + (int)($u['salida'] ?? 0);
    $porTarea[$t]['costo'] = ($porTarea[$t]['costo'] ?? 0) + $c;
    $quien = (string)($miembros[(int)($u['miembro_id'] ?? 0)]['nombre'] ?? '—');
    $porPersona[$quien]['usos'] = ($porPersona[$quien]['usos'] ?? 0) + 1;
    $porPersona[$quien]['costo'] = ($porPersona[$quien]['costo'] ?? 0) + $c;
}
uasort($porTarea, fn($a, $b) => $b['costo'] <=> $a['costo']);
uasort($porPersona, fn($a, $b) => $b['costo'] <=> $a['costo']);
$tope = (float)$cfg['tope_mensual'];
$pctTope = $tope > 0 ? min(100, (int)round($gasto * 100 / $tope)) : 0;
$usd = fn(float $v) => 'US$ ' . number_format($v, $v > 0 && $v < 1 ? 4 : 2);
$lista = IA::lista();
$nClaves = array_sum(array_map('count', $cfg['claves']));
$nFunc = count(array_filter($cfg['asignaciones'], fn($a) => $a['activa']));

// Modelos para las listas: agrupados por proveedor, con el precio debajo
$precio = fn(array $m) => 'US$ ' . rtrim(rtrim(number_format((float)$m['entrada'], 2), '0'), '.') . ' entrada · '
    . rtrim(rtrim(number_format((float)$m['salida'], 2), '0'), '.') . ' salida (por millón)' . ($m['activo'] ? '' : ' · inactivo');
$opcModelos = [];
$opcProveedor = [];
foreach ($cfg['modelos'] as $m) {
    $prov = IA::PROVEEDORES[$m['proveedor']]['nombre'];
    $opcModelos[$prov][$m['proveedor'] . ':' . $m['codigo']] = [$m['nombre'], $precio($m)];
    $opcProveedor[$m['proveedor']][$m['proveedor'] . ':' . $m['codigo']] = [$m['nombre'], $precio($m)];
}
$opcProv = array_map(fn($p) => $p['nombre'], IA::PROVEEDORES);
$grupos = [];
foreach (IA::TAREAS as $k => $t) $grupos[$t[1]][$k] = $t;

$tabs = [
    'resumen'   => ['Resumen', 'dashboard', null],
    'claves'    => ['Claves', 'candado', $nClaves],
    'modelos'   => ['Modelos y precios', 'precios', count($cfg['modelos'])],
    'funciones' => ['Funciones', 'ia', $nFunc],
    'consumo'   => ['Consumo', 'reportes', count($usos)],
];

/** Interruptor del design system con su casilla real para el formulario. */
$switch = function (string $name, bool $on, string $etiqueta, string $desc = '', string $tam = 'md'): string {
    $id = 'sw-' . substr(md5($name), 0, 8);
    return '<div class="mc-switch mc-switch--' . $tam . ($on ? ' is-on' : '') . '" data-ds-switch>'
        . '<input type="checkbox" class="ds-switch-casilla" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . ' tabindex="-1" aria-hidden="true">'
        . '<button type="button" role="switch" id="' . $id . '" aria-checked="' . ($on ? 'true' : 'false') . '" class="mc-switch__track"'
        . ($etiqueta === '' ? ' aria-label="Activo"' : '') . '><span class="mc-switch__thumb"></span></button>'
        . ($etiqueta !== '' ? '<span class="mc-switch__text"><label class="mc-switch__label" for="' . $id . '">' . e($etiqueta) . '</label>'
            . ($desc !== '' ? '<span class="mc-switch__desc">' . e($desc) . '</span>' : '') . '</span>' : '')
        . '</div>';
};
$campo = function (string $etiqueta, string $control, string $ayuda = ''): string {
    return '<div class="mc-field"><label class="mc-field__label">' . e($etiqueta) . '</label>' . $control
        . ($ayuda !== '' ? '<div class="mc-field__foot"><span class="mc-field__hint">' . $ayuda . '</span></div>' : '') . '</div>';
};
$input = fn(string $attrs, string $tam = 'md') => '<div class="mc-input mc-input--' . $tam . '"><input class="mc-input__control" ' . $attrs . '></div>';
// Lista desplegable del design system (UI::selectDS + assets/ds.js)
$select = fn(string $name, array $opciones, string $sel, array $op = []) => UI::selectDS($name, $opciones, $sel, $op);
$quitar = fn(string $name) => UI::checkDS($name, false, 'Quitar', '', 'ds-check-peligro');
$estado = fn(bool $ok, string $txt) => '<span class="mc-status mc-status--pill mc-status--' . ($ok ? 'success' : 'danger') . '"><span class="mc-status__content">'
    . UI::iconoDS($ok ? 'check' : 'alerta', 14) . '<span class="mc-status__label">' . e($txt) . '</span></span></span>';

UI::inicio('Consola de IA', 'ia');
UI::cabecera(
    'Consola de <span class="text-secondary">IA</span>',
    'Claves, modelos, precios y consumo de las funciones de IA del panel (GPT y Gemini). Las usan el administrador y los Scrum Masters.',
    '<button class="mc-btn mc-btn--primary mc-btn--md" form="frm-ia" data-ds-guardar>' . UI::iconoDS('check', 18) . '<span class="mc-btn__label">Guardar cambios</span></button>'
);
?>
<div class="ds" id="ia-consola">

  <div class="mc-tabs mc-tabs--line" role="tablist" aria-label="Secciones de la consola" data-ds-tabs>
    <?php foreach ($tabs as $id => [$etq, $ico, $n]): ?>
    <button type="button" class="mc-tab<?= $id === 'resumen' ? ' is-active' : '' ?>" role="tab" id="tab-<?= $id ?>"
            aria-selected="<?= $id === 'resumen' ? 'true' : 'false' ?>" aria-controls="panel-<?= $id ?>" data-tab="<?= $id ?>"
            <?= $id === 'resumen' ? '' : 'tabindex="-1"' ?>>
      <?= UI::iconoDS($ico, 18) ?><span class="mc-tab__label"><?= e($etq) ?></span>
      <?php if ($n !== null): ?><span class="mc-tab__count"><?= (int)$n ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <span class="mc-tabs__ink"></span>
  </div>

  <form id="frm-ia" method="post" action="actions.php" autocomplete="off">
    <input type="hidden" name="accion" value="ia_guardar">
    <input type="hidden" name="tab" value="resumen" data-ds-tab-actual>

    <!-- ============ Resumen ============ -->
    <section class="ds-panel" id="panel-resumen" role="tabpanel" aria-labelledby="tab-resumen" data-panel="resumen">
      <div class="ds-kpis">
        <div class="mc-kpi mc-kpi--azul">
          <div class="mc-kpi__head"><span class="mc-tile mc-tile--azul"><?= UI::iconoDS('precios', 22) ?></span><p class="mc-kpi__label">Gasto de <?= e($mes) ?></p></div>
          <div class="mc-kpi__body"><p class="mc-kpi__value mc-num"><?= $usd($gasto) ?></p>
            <div class="mc-kpi__meta-row"><span class="mc-kpi__hint"><?= $tope > 0 ? $pctTope . ' % del tope' : 'Sin tope' ?></span></div></div>
          <?php if ($tope > 0): ?><div class="mc-kpi__progress"><div class="mc-kpi__bar" role="progressbar" aria-valuenow="<?= $pctTope ?>" aria-valuemin="0" aria-valuemax="100"><span class="mc-kpi__bar-fill" style="--p:<?= $pctTope / 100 ?>"></span></div></div><?php endif; ?>
        </div>
        <div class="mc-kpi mc-kpi--petroleo">
          <div class="mc-kpi__head"><span class="mc-tile mc-tile--petroleo"><?= UI::iconoDS('bandera', 22) ?></span><p class="mc-kpi__label">Tope mensual</p></div>
          <div class="mc-kpi__body"><p class="mc-kpi__value mc-num"><?= $tope > 0 ? $usd($tope) : 'Sin tope' ?></p>
            <div class="mc-kpi__meta-row"><span class="mc-kpi__hint">Se corta al llegar</span></div></div>
        </div>
        <div class="mc-kpi mc-kpi--verde">
          <div class="mc-kpi__head"><span class="mc-tile mc-tile--verde"><?= UI::iconoDS('check-doble', 22) ?></span><p class="mc-kpi__label">Usos del mes</p></div>
          <div class="mc-kpi__body"><p class="mc-kpi__value mc-num"><?= $nOk ?></p>
            <div class="mc-kpi__meta-row"><span class="mc-kpi__hint">Respuestas entregadas</span></div></div>
        </div>
        <div class="mc-kpi mc-kpi--rojo">
          <div class="mc-kpi__head"><span class="mc-tile mc-tile--rojo"><?= UI::iconoDS('alerta', 22) ?></span><p class="mc-kpi__label">Con error</p></div>
          <div class="mc-kpi__body"><p class="mc-kpi__value mc-num"><?= $nErr ?></p>
            <div class="mc-kpi__meta-row"><span class="mc-kpi__hint">Sin respuesta de la IA</span></div></div>
        </div>
        <div class="mc-kpi mc-kpi--<?= $lista ? 'verde' : 'naranja' ?>">
          <div class="mc-kpi__head"><span class="mc-tile mc-tile--<?= $lista ? 'verde' : 'naranja' ?>"><?= UI::iconoDS('ia', 22) ?></span><p class="mc-kpi__label">Estado</p></div>
          <div class="mc-kpi__body"><p class="mc-kpi__value"><?= $lista ? 'Activa' : 'Inactiva' ?></p>
            <div class="mc-kpi__meta-row"><span class="mc-kpi__hint"><?= $lista ? 'Lista para usarse' : ($cfg['activa'] ? 'Falta una clave' : 'Apagada') ?></span></div></div>
        </div>
      </div>

      <section class="mc-card">
        <header class="mc-card__head"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('configuracion', 18) ?></span>
          <div class="mc-card__titles"><h3 class="mc-card__title">Estado y tope de gasto</h3><p class="mc-card__sub">Apagar la IA esconde todos sus botones del panel.</p></div></header>
        <div class="mc-card__body ds-grid">
          <?= $switch('ia[activa]', (bool)$cfg['activa'], 'IA activa en el panel', 'Administradores y Scrum Masters ven los botones de IA en sus pantallas.') ?>
          <div style="max-width:320px">
            <?= $campo('Tope de gasto por mes (US$)', $input('name="ia[tope_mensual]" inputmode="decimal" value="' . e((string)$tope) . '"'),
                'Con 0 no hay tope. Al llegar, la IA deja de responder hasta el mes siguiente.') ?>
          </div>
        </div>
      </section>
    </section>

    <!-- ============ Claves ============ -->
    <section class="ds-panel" id="panel-claves" role="tabpanel" aria-labelledby="tab-claves" data-panel="claves" hidden>
      <?= UI::alertaDS('info', 'Las claves se guardan cifradas y no se vuelven a mostrar', 'Si un proveedor tiene varias, se usan en orden: cuando una se queda sin cupo, se pasa a la siguiente.') ?>
      <div class="ds-grid-2">
        <?php foreach (IA::PROVEEDORES as $prov => $pv): ?>
        <section class="mc-card" data-ia-prov="<?= $prov ?>">
          <header class="mc-card__head"><span class="mc-tile mc-tile--sm mc-tile--<?= $prov === 'openai' ? 'verde' : 'azul' ?>"><?= UI::iconoDS('candado', 18) ?></span>
            <div class="mc-card__titles"><h3 class="mc-card__title"><?= e($pv['nombre']) ?></h3>
              <p class="mc-card__sub"><?= count($cfg['claves'][$prov]) ?: 'Sin' ?> clave<?= count($cfg['claves'][$prov]) === 1 ? '' : 's' ?> · se sacan en <a href="<?= e($pv['consola']) ?>" target="_blank" rel="noopener"><?= e(parse_url($pv['consola'], PHP_URL_HOST)) ?></a></p></div></header>
          <div class="mc-card__body ds-grid">
            <?php foreach ($cfg['claves'][$prov] as $k): $base = 'ia[claves][' . $prov . '][' . e($k['id']) . ']'; ?>
            <div class="ds-fila">
              <div style="flex:1;min-width:160px"><?= $input('name="' . $base . '[etiqueta]" value="' . e($k['etiqueta']) . '" maxlength="40" aria-label="Nombre de la clave"', 'sm') ?></div>
              <code class="mc-code-inline">••••<?= e($k['final']) ?></code>
              <?= $switch($base . '[activa]', (bool)$k['activa'], 'Activa', '', 'sm') ?>
              <button type="submit" form="frm-quitar-<?= e($prov . '-' . $k['id']) ?>" class="mc-btn mc-btn--ghost mc-btn--sm ia-quitar"><?= UI::iconoDS('eliminar', 16) ?><span class="mc-btn__label">Quitar</span></button>
            </div>
            <?php endforeach; ?>
            <hr class="ds-sep">
            <div class="ds-grid-2" style="gap:var(--space-3)">
              <?= $campo('Nombre (opcional)', $input('name="ia[nueva][' . $prov . '][etiqueta]" maxlength="40" placeholder="Principal"', 'sm')) ?>
              <?= $campo('Clave nueva', $input('type="password" name="ia[nueva][' . $prov . '][valor]" placeholder="Pegar la clave…" autocomplete="new-password"', 'sm')) ?>
            </div>
            <div class="ds-fila">
              <div style="min-width:220px;flex:1"><?= $select('', $opcProveedor[$prov] ?? [], '', ['aria' => 'Modelo para probar', 'attrs' => 'data-ia-probar-modelo']) ?></div>
              <button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" data-ia-probar><?= UI::iconoDS('enlace', 16) ?><span class="mc-btn__label">Probar conexión</span></button>
            </div>
            <div class="ds-grid" style="gap:var(--space-2)" data-ia-probar-res></div>
            <?= UI::alertaDS('tip', '', 'Prueba las claves ya guardadas: una recién pegada, guárdala antes.', true, 'ds-sin-margen') ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ============ Modelos y precios ============ -->
    <section class="ds-panel" id="panel-modelos" role="tabpanel" aria-labelledby="tab-modelos" data-panel="modelos" hidden>
      <div class="mc-table">
        <div class="mc-table__toolbar">
          <div class="mc-table__heading"><h3 class="mc-table__title">Modelos y precios</h3>
            <p class="mc-table__desc">Dólares por <b>millón de tokens</b> (entrada = lo que se le manda; salida = lo que responde). Son precios de referencia: revísalos en cada proveedor, porque cambian. El código es el nombre exacto del modelo en la API.</p></div>
          <div class="mc-table__tools"><button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" id="ia-modelo-nuevo"><?= UI::iconoDS('mas', 16) ?><span class="mc-btn__label">Agregar modelo</span></button></div>
        </div>
        <div class="mc-table__scroll">
          <table class="mc-table__table" id="ia-modelos">
            <thead><tr><th scope="col">Proveedor</th><th scope="col">Código del modelo</th><th scope="col">Nombre</th><th scope="col" class="is-right">Entrada</th><th scope="col" class="is-right">Salida</th><th scope="col">Activo</th><th scope="col">Quitar</th></tr></thead>
            <tbody>
              <?php foreach ($cfg['modelos'] as $i => $m): $b = 'ia[modelos][' . $i . ']'; ?>
              <tr>
                <td><div class="mc-table__cell"><?= $select($b . '[proveedor]', $opcProv, $m['proveedor'], ['aria' => 'Proveedor']) ?></div></td>
                <td><div class="mc-table__cell"><?= $input('name="' . $b . '[codigo]" value="' . e($m['codigo']) . '" maxlength="80"', 'sm') ?></div></td>
                <td><div class="mc-table__cell"><?= $input('name="' . $b . '[nombre]" value="' . e($m['nombre']) . '" maxlength="60"', 'sm') ?></div></td>
                <td class="is-right"><div class="mc-table__cell" style="max-width:110px"><?= $input('name="' . $b . '[entrada]" inputmode="decimal" value="' . e((string)$m['entrada']) . '"', 'sm') ?></div></td>
                <td class="is-right"><div class="mc-table__cell" style="max-width:110px"><?= $input('name="' . $b . '[salida]" inputmode="decimal" value="' . e((string)$m['salida']) . '"', 'sm') ?></div></td>
                <td><div class="mc-table__cell"><?= $switch($b . '[activo]', (bool)$m['activo'], '', '', 'sm') ?></div></td>
                <td><div class="mc-table__cell"><?= $quitar($b . '[quitar]') ?></div></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ============ Funciones ============ -->
    <section class="ds-panel" id="panel-funciones" role="tabpanel" aria-labelledby="tab-funciones" data-panel="funciones" hidden>
      <div class="mc-table">
        <div class="mc-table__toolbar">
          <div class="mc-table__heading"><h3 class="mc-table__title">Funciones de IA</h3>
            <p class="mc-table__desc">Qué modelo usa cada función. Si el principal falla (sin cupo, caído…), responde el de respaldo. Una función apagada desaparece de su pantalla.</p></div>
        </div>
        <div class="mc-table__scroll">
          <table class="mc-table__table">
            <thead><tr><th scope="col">Función</th><th scope="col">Modelo principal</th><th scope="col">Respaldo</th><th scope="col">Activa</th></tr></thead>
            <tbody>
              <?php foreach ($grupos as $grupo => $tareasG): ?>
              <tr><td colspan="4"><div class="mc-table__cell"><span class="mc-status mc-status--pill mc-status--petroleo"><span class="mc-status__content"><span class="mc-status__label"><?= e($grupo) ?></span></span></span></div></td></tr>
              <?php foreach ($tareasG as $k => [$etq, , $para]): $a = $cfg['asignaciones'][$k]; ?>
              <tr>
                <td class="is-primary"><div class="mc-table__cell"><div><b><?= e($etq) ?></b><small style="display:block;color:var(--ink-muted)"><?= e($para) ?></small></div></div></td>
                <td><div class="mc-table__cell" style="min-width:220px"><?= $select('ia[asig][' . $k . '][principal]', $opcModelos, $a['principal'], ['grupos' => true, 'aria' => 'Modelo principal de ' . $etq]) ?></div></td>
                <td><div class="mc-table__cell" style="min-width:220px"><?= $select('ia[asig][' . $k . '][respaldo]', $opcModelos, $a['respaldo'], ['grupos' => true, 'vacio' => 'Sin respaldo', 'aria' => 'Respaldo de ' . $etq]) ?></div></td>
                <td><div class="mc-table__cell"><?= $switch('ia[asig][' . $k . '][activa]', (bool)$a['activa'], '', '', 'sm') ?></div></td>
              </tr>
              <?php endforeach; endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </form>
  <?php foreach (IA::PROVEEDORES as $prov => $pv): foreach ($cfg['claves'][$prov] as $k): ?>
  <form id="frm-quitar-<?= e($prov . '-' . $k['id']) ?>" method="post" action="actions.php" hidden
        data-confirmar="La clave «<?= e($k['etiqueta']) ?>» (••••<?= e($k['final']) ?>) se borra del panel ahora mismo. La IA sigue con las que queden."
        data-confirmar-titulo="¿Quitar esta clave de <?= e($pv['nombre']) ?>?" data-confirmar-ok="Sí, quitar">
    <input type="hidden" name="accion" value="ia_clave_quitar">
    <input type="hidden" name="proveedor" value="<?= e($prov) ?>">
    <input type="hidden" name="id" value="<?= e($k['id']) ?>">
  </form>
  <?php endforeach; endforeach; ?>

  <!-- ============ Consumo (fuera del formulario) ============ -->
  <section class="ds-panel" id="panel-consumo" role="tabpanel" aria-labelledby="tab-consumo" data-panel="consumo" hidden>
    <form method="get" action="ia.php#consumo" class="ds-fila" style="margin-bottom:var(--space-4)">
      <div style="width:200px"><?= $input('type="month" name="mes" value="' . e($mes) . '" aria-label="Mes"', 'sm') ?></div>
      <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm"><?= UI::iconoDS('filtro', 16) ?><span class="mc-btn__label">Ver mes</span></button>
    </form>
    <?php if (!$usos): ?>
      <?= UI::vacioDS('reportes-vacio', 'Sin usos de IA en ' . $mes, 'Cuando alguien use una función de IA, aquí aparecen el modelo, los tokens y el costo. Prueba con otro mes.') ?>
    <?php else: ?>
    <div class="ds-grid-2" style="margin-bottom:var(--space-5)">
      <div class="mc-table"><div class="mc-table__toolbar"><div class="mc-table__heading"><h3 class="mc-table__title">Por función</h3></div></div>
        <div class="mc-table__scroll"><table class="mc-table__table">
          <thead><tr><th scope="col">Función</th><th scope="col" class="is-right">Usos</th><th scope="col" class="is-right">Tokens</th><th scope="col" class="is-right">Costo</th></tr></thead>
          <tbody><?php foreach ($porTarea as $t => $x): ?>
            <tr><td class="is-primary"><div class="mc-table__cell"><?= e(IA::TAREAS[$t][0] ?? $t) ?></div></td>
              <td class="is-right"><div class="mc-table__cell mc-num"><?= (int)$x['usos'] ?></div></td>
              <td class="is-right"><div class="mc-table__cell mc-num"><?= number_format((int)$x['tokens']) ?></div></td>
              <td class="is-right"><div class="mc-table__cell mc-num"><?= $usd((float)$x['costo']) ?></div></td></tr>
          <?php endforeach; ?></tbody></table></div></div>
      <div class="mc-table"><div class="mc-table__toolbar"><div class="mc-table__heading"><h3 class="mc-table__title">Por persona</h3></div></div>
        <div class="mc-table__scroll"><table class="mc-table__table">
          <thead><tr><th scope="col">Persona</th><th scope="col" class="is-right">Usos</th><th scope="col" class="is-right">Costo</th></tr></thead>
          <tbody><?php foreach ($porPersona as $n => $x): ?>
            <tr><td class="is-primary"><div class="mc-table__cell"><?= e($n) ?></div></td>
              <td class="is-right"><div class="mc-table__cell mc-num"><?= (int)$x['usos'] ?></div></td>
              <td class="is-right"><div class="mc-table__cell mc-num"><?= $usd((float)$x['costo']) ?></div></td></tr>
          <?php endforeach; ?></tbody></table></div></div>
    </div>
    <div class="mc-table">
      <div class="mc-table__toolbar"><div class="mc-table__heading"><h3 class="mc-table__title">Últimos usos</h3><p class="mc-table__desc"><?= count($usos) ?> en <?= e($mes) ?></p></div></div>
      <div class="mc-table__scroll"><table class="mc-table__table">
        <thead><tr><th scope="col">Fecha</th><th scope="col">Función</th><th scope="col">Persona</th><th scope="col">Modelo</th><th scope="col" class="is-right">Tokens (entrada / salida)</th><th scope="col" class="is-right">Costo</th><th scope="col">Estado</th></tr></thead>
        <tbody><?php foreach (array_slice($usos, 0, 40) as $u): $ok = ($u['estado'] ?? '') === 'ok'; ?>
          <tr>
            <td><div class="mc-table__cell mc-num"><?= e(substr((string)$u['fecha'], 0, 16)) ?></div></td>
            <td class="is-primary"><div class="mc-table__cell"><?= e(IA::TAREAS[$u['tarea'] ?? ''][0] ?? (string)($u['tarea'] ?? '')) ?></div></td>
            <td><div class="mc-table__cell"><?= e((string)($miembros[(int)($u['miembro_id'] ?? 0)]['nombre'] ?? '—')) ?></div></td>
            <td><div class="mc-table__cell"><?= e((string)($u['modelo'] ?? '')) ?><?= !empty($u['respaldo']) ? ' <small style="color:var(--ink-muted)">(respaldo)</small>' : '' ?></div></td>
            <td class="is-right"><div class="mc-table__cell mc-num"><?= number_format((int)($u['entrada'] ?? 0)) ?> / <?= number_format((int)($u['salida'] ?? 0)) ?></div></td>
            <td class="is-right"><div class="mc-table__cell mc-num"><?= $usd((float)($u['costo'] ?? 0)) ?></div></td>
            <td><div class="mc-table__cell"><?= $estado($ok, $ok ? 'OK' : 'Error') ?>
              <?php if (!$ok): ?><br><small style="color:var(--danger-ink)"><?= e(mb_substr((string)($u['error'] ?? ''), 0, 140)) ?></small><?php endif; ?></div></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </section>
</div>

<template id="tpl-ia-modelo">
  <tr>
    <td><div class="mc-table__cell"><?= $select('ia[modelos][__i__][proveedor]', $opcProv, 'openai', ['aria' => 'Proveedor']) ?></div></td>
    <td><div class="mc-table__cell"><?= $input('name="ia[modelos][__i__][codigo]" maxlength="80" placeholder="p. ej. gpt-4.1-mini"', 'sm') ?></div></td>
    <td><div class="mc-table__cell"><?= $input('name="ia[modelos][__i__][nombre]" maxlength="60"', 'sm') ?></div></td>
    <td class="is-right"><div class="mc-table__cell" style="max-width:110px"><?= $input('name="ia[modelos][__i__][entrada]" inputmode="decimal" value="0"', 'sm') ?></div></td>
    <td class="is-right"><div class="mc-table__cell" style="max-width:110px"><?= $input('name="ia[modelos][__i__][salida]" inputmode="decimal" value="0"', 'sm') ?></div></td>
    <td><div class="mc-table__cell"><?= $switch('ia[modelos][__i__][activo]', true, '', '', 'sm') ?></div></td>
    <td><div class="mc-table__cell"><?= $quitar('ia[modelos][__i__][quitar]') ?></div></td>
  </tr>
</template>

<script>
(() => {
  const raiz = document.getElementById('ia-consola');
  const tabs = [...raiz.querySelectorAll('[data-ds-tabs] .mc-tab')];
  const ink = raiz.querySelector('.mc-tabs__ink');
  const actual = document.querySelector('[data-ds-tab-actual]');

  // Pestañas del design system: subrayado que se desliza, ← → Inicio Fin.
  function ir(id, foco) {
    const t = tabs.find((x) => x.dataset.tab === id) || tabs[0];
    tabs.forEach((x) => {
      const on = x === t;
      x.classList.toggle('is-active', on);
      x.setAttribute('aria-selected', on ? 'true' : 'false');
      x.tabIndex = on ? 0 : -1;
    });
    raiz.querySelectorAll('.ds-panel').forEach((p) => { p.hidden = p.dataset.panel !== t.dataset.tab; });
    if (ink) { ink.style.width = t.offsetWidth + 'px'; ink.style.transform = 'translateX(' + t.offsetLeft + 'px)'; }
    if (actual) actual.value = t.dataset.tab;
    history.replaceState(null, '', location.pathname + location.search + '#' + t.dataset.tab);
    if (foco) t.focus();
  }
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => ir(t.dataset.tab));
    t.addEventListener('keydown', (e) => {
      const n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
      if (n === undefined) return;
      e.preventDefault();
      ir(tabs[(n + tabs.length) % tabs.length].dataset.tab, true);
    });
  });
  ir((location.hash || '#resumen').slice(1));
  addEventListener('resize', () => ir(actual ? actual.value : 'resumen'));

  // Interruptor: alterna la píldora y su casilla real del formulario.
  raiz.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ds-switch] .mc-switch__track');
    if (!b) return;
    const sw = b.closest('[data-ds-switch]');
    const on = !sw.classList.contains('is-on');
    sw.classList.toggle('is-on', on);
    b.setAttribute('aria-checked', on ? 'true' : 'false');
    sw.querySelector('.ds-switch-casilla').checked = on;
  });

  let n = <?= count($cfg['modelos']) + 100 ?>;
  document.getElementById('ia-modelo-nuevo').addEventListener('click', () => {
    const html = document.getElementById('tpl-ia-modelo').innerHTML.replaceAll('__i__', String(n++));
    document.querySelector('#ia-modelos tbody').insertAdjacentHTML('beforeend', html);
  });

  // Probar conexión: una llamada mínima con cada clave activa del proveedor.
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  // Resultado de la prueba: mensaje en línea del sistema (éxito o error), en una línea
  const ICO_OK = <?= json_encode(UI::iconoDS('exito', 20, 'mc-alert__icon')) ?>;
  const ICO_ERR = <?= json_encode(UI::iconoDS('error', 20, 'mc-alert__icon')) ?>;
  const aviso = (ok, titulo, desc) => '<div class="mc-alert mc-alert--compact mc-alert--' + (ok ? 'success' : 'danger') + ' ds-sin-margen" role="' + (ok ? 'status' : 'alert') + '">'
    + (ok ? ICO_OK : ICO_ERR) + '<div class="mc-alert__content"><p class="mc-alert__title">' + esc(titulo) + '</p>'
    + (desc ? '<div class="mc-alert__desc">' + esc(desc) + '</div>' : '') + '</div></div>';
  raiz.querySelectorAll('[data-ia-probar]').forEach((b) => b.addEventListener('click', async () => {
    const card = b.closest('[data-ia-prov]');
    const res = card.querySelector('[data-ia-probar-res]');
    const modelo = card.querySelector('[data-ia-probar-modelo]').value;
    if (!modelo) { res.innerHTML = aviso(false, 'Este proveedor no tiene modelos en el catálogo', 'Agrega uno en «Modelos y precios».'); return; }
    res.innerHTML = '<span class="ds-fila" style="color:var(--ink-muted)"><span class="mc-spinner"></span> Probando…</span>';
    b.disabled = true;
    try {
      const fd = new FormData(); fd.append('accion', 'ia_probar'); fd.append('modelo', modelo);
      const r = await (await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
      res.innerHTML = !r.ok ? aviso(false, 'No se pudo probar', r.error)
        : r.resultados.map((x) => aviso(x.ok, x.clave + (x.ok ? ' responde' : ' no responde'), x.ok ? x.ms + ' ms' : x.error)).join('');
    } catch (e) {
      res.innerHTML = aviso(false, 'No se pudo probar', e.message);
    }
    b.disabled = false;
  }));
})();
</script>
<?php UI::fin(); ?>
