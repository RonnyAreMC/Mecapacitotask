<?php
/**
 * APIs de la empresa: las claves de los servicios que usan los proyectos
 * (OpenAI, Google Maps, Zoom…), en un solo lugar.
 *
 *   · El administrador las registra con «+ Nueva API»: el proveedor (con su
 *     logo), para qué se usa, en qué proyectos (ninguno = para todos), la clave,
 *     la URL, la documentación y notas de cómo usarla.
 *   · Cada desarrollador ve las de SUS proyectos y copia la clave con un clic
 *     (la trae actions.php → apis_copiar, que revisa que pueda verla y lo anota).
 *     En la página la clave va enmascarada; nunca viaja en el HTML.
 *   · Arriba, el aviso de cuidarlas. El supervisor (cliente) no entra.
 *
 * Lógica: lib/apis/Apis.php · estilos: ds.css («APIs») · JS: assets/apis.js.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
if (is_file(__DIR__ . '/lib/apis/Apis.php')) require_once __DIR__ . '/lib/apis/Apis.php';
if (!Auth::usuario()) redirigir('login.php');
if (!class_exists('Apis')) redirigir('index.php', 'Falta lib/apis/Apis.php en el servidor.', 'error');
if (Auth::esSupervisor()) redirigir('index.php', 'Las APIs son para el equipo de desarrollo.', 'info');

$esAdm     = esAdmin();
$lista     = Apis::visibles();
$miembros  = (new MiembroRepo())->mapa();
$alcance   = alcanceProyectos();
$proyectos = [];
foreach ((new ProyectoRepo())->todos() as $p) $proyectos[(int)$p['id']] = $p;
$ahora     = time();

// Los filtros: un chip por proyecto que tiene APIs (de los que ve) y «Para todos»
$nPorProy = [];
$nTodos = 0;
foreach ($lista as $a) {
    $ids = Apis::proyectosDe($a);
    if (!$ids) $nTodos++;
    foreach ($ids as $pid) {
        if (isset($proyectos[$pid]) && ($alcance === null || isset($alcance[$pid]))) $nPorProy[$pid] = ($nPorProy[$pid] ?? 0) + 1;
    }
}
uksort($nPorProy, fn($a, $b) => strcasecmp((string)$proyectos[$a]['nombre'], (string)$proyectos[$b]['nombre']));

$cuando = function (string $f) use ($ahora): string {
    $ts = strtotime($f) ?: $ahora;
    $min = (int)floor(($ahora - $ts) / 60);
    if ($min < 1) return 'ahora';
    if ($min < 60) return 'hace ' . $min . ' min';
    if ($min < 1440) return 'hace ' . intdiv($min, 60) . ' h';
    return date('d/m/Y', $ts);
};
$logo = function (array $prov, int $px = 28): string {
    return $prov['logo'] !== '' && is_file(__DIR__ . '/' . $prov['logo'])
        ? '<img src="' . e(asset($prov['logo'])) . '" alt="" width="' . $px . '" height="' . $px . '" decoding="async">'
        : UI::iconoDS('api', $px);
};

UI::inicio('APIs', 'apis');
UI::cabecera(
    '<span class="text-secondary">APIs</span> de los proyectos',
    'Las claves de los servicios que usa cada proyecto, en un solo lugar: búscala, cópiala con un clic y úsala con cuidado.',
    $esAdm ? '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-api-nueva>' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Nueva API</span></button>' : '',
    'nexo:con-laptop'
);
?>
<div class="ds api" data-api-pagina>

  <?= UI::alertaDS('warning', 'Cuiden estas APIs: son de la empresa',
      '<ul class="api-cuidado">'
      . '<li>No las compartas fuera del equipo ni las mandes por chat o correo.</li>'
      . '<li>No las pongas en el código ni las subas a GitHub o GitLab: van en el <code>.env</code> (y el <code>.env</code> en el <code>.gitignore</code>).</li>'
      . '<li>Muchas cuestan dinero por uso: úsalas solo para lo que dice cada una.</li>'
      . '<li>Si crees que una se filtró, avísale al administrador para cambiarla. Cada vez que alguien copia una clave queda registrado.</li>'
      . '</ul>') ?>

  <?php if ($lista): ?>
  <div class="api-filtros">
    <label class="mc-input mc-input--md api-buscar"><?= UI::iconoDS('buscar', 18, 'mc-input__icon') ?>
      <input class="mc-input__control" type="search" placeholder="Buscar por nombre, proveedor o para qué sirve" data-api-buscar aria-label="Buscar APIs" autocomplete="off"></label>
    <nav class="vt-chips api-chips" aria-label="Filtrar por proyecto">
      <button type="button" class="vt-chip is-on" data-api-filtro="">Todas <small><?= count($lista) ?></small></button>
      <?php foreach ($nPorProy as $pid => $n): ?>
      <button type="button" class="vt-chip" data-api-filtro="<?= (int)$pid ?>" style="--pc:<?= e(ProyectoRepo::colorBase($proyectos[$pid])) ?>">
        <span class="ru-pill__punto" aria-hidden="true"></span><?= e((string)$proyectos[$pid]['nombre']) ?> <small><?= $n ?></small></button>
      <?php endforeach; ?>
      <?php if ($nTodos && $nPorProy): ?>
      <button type="button" class="vt-chip" data-api-filtro="todos">Para todos <small><?= $nTodos ?></small></button>
      <?php endif; ?>
    </nav>
  </div>

  <div class="api-grid" data-api-grid>
    <?php foreach ($lista as $a):
        $aid  = (int)$a['id'];
        $prov = Apis::proveedor($a);
        $ids  = Apis::proyectosDe($a);
        $susP = array_values(array_filter(array_map(fn($pid) => $proyectos[$pid] ?? null, $ids)));
        $amb  = isset(Apis::AMBIENTES[$a['ambiente'] ?? '']) ? (string)$a['ambiente'] : 'produccion';
        $tieneClave = (string)($a['clave'] ?? '') !== '';
        $buscar = mb_strtolower(implode(' ', [$a['nombre'], $prov['nombre'], $a['uso'] ?? '', implode(' ', array_map(fn($p) => (string)$p['nombre'], $susP))]));
        $copias = $esAdm ? Apis::copiasDe($aid) : null;
    ?>
    <article class="api-card" id="api-<?= $aid ?>" data-api-card data-proys="<?= $ids ? e(implode(',', $ids)) : 'todos' ?>" data-buscar="<?= e($buscar) ?>" style="--marca:<?= e($prov['color']) ?>">
      <header class="api-card__cab">
        <span class="api-logo" aria-hidden="true"><?= $logo($prov, 30) ?></span>
        <div class="api-card__t">
          <h2 title="<?= e((string)$a['nombre']) ?>"><?= e((string)$a['nombre']) ?></h2>
          <small><?= e($prov['nombre']) ?><?= trim((string)($a['uso'] ?? '')) !== '' ? ' · ' . e((string)$a['uso']) : '' ?></small>
        </div>
        <span class="api-amb api-amb--<?= e($amb) ?>"><?= e(Apis::AMBIENTES[$amb]) ?></span>
      </header>

      <div class="api-card__proys">
        <?php if ($susP): foreach ($susP as $p): ?>
        <span class="eq-chip" style="--pc:<?= e(ProyectoRepo::colorBase($p)) ?>"><?= e((string)$p['nombre']) ?></span>
        <?php endforeach; else: ?>
        <span class="eq-chip api-todos"><?= UI::iconoDS('global', 14) ?> Todos los proyectos</span>
        <?php endif; ?>
      </div>

      <?php if ($tieneClave): ?>
      <div class="api-dato">
        <span class="api-dato__rot">Clave</span>
        <code class="api-dato__val" title="Se copia completa; aquí va oculta"><?= e((string)($a['pista'] ?? '••••••••')) ?></code>
        <button type="button" class="mc-btn mc-btn--primary mc-btn--sm api-copiar" data-api-copiar="<?= $aid ?>" data-que="clave">
          <?= UI::iconoDS('copiar', 16) ?><span class="mc-btn__label">Copiar</span></button>
      </div>
      <?php endif; ?>
      <?php if (trim((string)($a['url'] ?? '')) !== ''): ?>
      <div class="api-dato api-dato--url">
        <span class="api-dato__rot">URL</span>
        <code class="api-dato__val" title="<?= e((string)$a['url']) ?>"><?= e((string)$a['url']) ?></code>
        <button type="button" class="mc-iconbtn mc-iconbtn--sm btn-copiar" data-copiar="<?= e((string)$a['url']) ?>" aria-label="Copiar la URL" data-ds-tip="Copiar la URL"><?= UI::iconoDS('copiar', 16) ?></button>
      </div>
      <?php endif; ?>
      <?php if (!$tieneClave && trim((string)($a['url'] ?? '')) === ''): ?>
      <p class="api-card__nada">Todavía no tiene clave ni URL.</p>
      <?php endif; ?>

      <?php if (trim((string)($a['notas'] ?? '')) !== ''): ?>
      <details class="api-notas">
        <summary><?= UI::iconoDS('info', 16) ?> Cómo usarla</summary>
        <p><?= nl2br(e((string)$a['notas'])) ?></p>
      </details>
      <?php endif; ?>

      <footer class="api-card__pie">
        <?php if (trim((string)($a['docs'] ?? '')) !== ''): ?>
        <a class="api-docs" href="<?= e((string)$a['docs']) ?>" target="_blank" rel="noopener"><?= UI::iconoDS('documento', 16) ?> Documentación</a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if ($esAdm): ?>
        <span class="api-card__adm">
          <?php if ($copias['total']): $ult = $copias['ultimas'][0]; ?>
          <span class="api-copias" data-ds-tip="<?= e(implode(' · ', array_map(fn($c) => trim((string)($miembros[(int)$c['quien']]['nombre'] ?? 'Alguien')) . ', ' . $cuando((string)$c['fecha']), $copias['ultimas']))) ?>">
            <?= UI::iconoDS('copiar', 14) ?> <?= $copias['total'] === 1 ? '1 copia' : $copias['total'] . ' copias' ?> · <?= e(explode(' ', trim((string)($miembros[(int)$ult['quien']]['nombre'] ?? 'Alguien')))[0]) ?>, <?= e($cuando((string)$ult['fecha'])) ?></span>
          <?php endif; ?>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" aria-label="Editar" data-ds-tip="Editar"
                  data-api-editar="<?= e(json_encode([
                      'id' => $aid, 'nombre' => (string)$a['nombre'], 'proveedor' => $prov['clave'], 'proveedor_otro' => (string)($a['proveedor_otro'] ?? ''),
                      'uso' => (string)($a['uso'] ?? ''), 'ambiente' => $amb, 'proyectos' => $ids, 'url' => (string)($a['url'] ?? ''),
                      'docs' => (string)($a['docs'] ?? ''), 'notas' => (string)($a['notas'] ?? ''), 'pista' => (string)($a['pista'] ?? ''),
                  ], JSON_UNESCAPED_UNICODE)) ?>"><?= UI::iconoDS('editar', 16) ?></button>
          <form method="post" action="actions.php" class="inline-form" data-confirmar="Se quita «<?= e((string)$a['nombre']) ?>» de APIs, con su clave guardada. En el proveedor no se toca nada."
                data-confirmar-titulo="¿Quitar esta API?" data-confirmar-ok="Sí, quitar">
            <input type="hidden" name="accion" value="apis_quitar"><input type="hidden" name="id" value="<?= $aid ?>">
            <button class="mc-iconbtn mc-iconbtn--sm" aria-label="Quitar" data-ds-tip="Quitar"><?= UI::iconoDS('eliminar', 16) ?></button>
          </form>
        </span>
        <?php endif; ?>
      </footer>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="api-sin" data-api-sin hidden>Ninguna API coincide. Prueba con otra palabra u otro proyecto.</p>
  <?php else: ?>
    <?= UI::vacioDS('nexo:pensando', 'Todavía no hay APIs', $esAdm
        ? 'Registra la primera con «Nueva API»: el proveedor, en qué proyectos se usa y su clave.'
        : 'Cuando el administrador registre las APIs de tus proyectos, salen aquí para copiarlas.') ?>
  <?php endif; ?>
</div>

<?php if ($esAdm):
    $opcProy = array_map(fn($p) => (string)$p['nombre'], $proyectos);
    asort($opcProy, SORT_NATURAL | SORT_FLAG_CASE); ?>
<dialog class="ds-modal api-dlg" id="dlg-api" aria-labelledby="dlg-api-t">
  <form method="post" action="actions.php" class="ds" autocomplete="off">
    <input type="hidden" name="accion" value="apis_guardar">
    <input type="hidden" name="id" value="">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('api', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-api-t" data-api-dlg-t>Nueva API</h2>
        <p class="ds-modal__sub">La ven los desarrolladores de los proyectos que elijas. La clave se guarda cifrada.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <div class="campo"><span>Proveedor</span>
        <div class="api-prov">
          <label class="mc-input mc-input--sm api-prov__buscar"><?= UI::iconoDS('buscar', 16, 'mc-input__icon') ?>
            <input class="mc-input__control" type="search" placeholder="Buscar proveedor…" data-api-prov-buscar aria-label="Buscar proveedor"></label>
          <div class="api-prov__grid" role="radiogroup" aria-label="Proveedor">
            <label class="api-prov__op" data-nombre="otra otro propia" title="Otra (la escribes abajo)">
              <input type="radio" name="proveedor" value="otra" class="mc-sr-only" checked>
              <?= UI::iconoDS('api', 26) ?><span>Otra</span></label>
            <?php foreach (Apis::proveedores() as $k => [$nom, $col]): ?>
            <label class="api-prov__op" data-nombre="<?= e(mb_strtolower($nom . ' ' . $k)) ?>" title="<?= e($nom) ?>">
              <input type="radio" name="proveedor" value="<?= e($k) ?>" class="mc-sr-only">
              <img src="<?= e(asset('assets/apis/' . $k . '.svg')) ?>" alt="" width="26" height="26" loading="lazy"><span><?= e($nom) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div></div>
      <label class="campo" data-api-otro><span>¿Cuál proveedor?</span>
        <input class="input-meca" name="proveedor_otro" maxlength="40" placeholder="Ej. SRI, Banco Pichincha, API propia"></label>
      <div class="campo-doble">
        <label class="campo"><span>Nombre</span>
          <input class="input-meca" name="nombre" required maxlength="80" placeholder="Ej. OpenAI para las actas"></label>
        <label class="campo"><span>Para qué se usa <small>(opcional)</small></span>
          <input class="input-meca" name="uso" maxlength="140" placeholder="Ej. Resúmenes con IA, mapas, pagos"></label>
      </div>
      <label class="campo"><span>Proyectos <small>(si no eliges ninguno, es para todos)</small></span>
        <?= str_replace('<select ', '<select data-ph="Todos los proyectos" aria-label="Proyectos" data-api-proys ', UI::select('proyectos', $opcProy, [], false, '', true)) ?></label>
      <div class="campo-doble">
        <label class="campo"><span>Ambiente</span>
          <?= UI::selectDS('ambiente', Apis::AMBIENTES, 'produccion', ['aria' => 'Ambiente']) ?></label>
        <span aria-hidden="true"></span>
      </div>
      <label class="campo"><span>Clave o token</span>
        <div class="api-clave">
          <input class="input-meca" type="password" name="clave" maxlength="4000" autocomplete="new-password" spellcheck="false" placeholder="Pega la clave (ej. sk-…)" data-api-clave>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-api-ver aria-label="Ver u ocultar la clave" data-ds-tip="Ver u ocultar"><?= UI::iconoDS('ver', 16) ?></button>
        </div>
        <small class="campo-ayuda" data-api-clave-ayuda>Se guarda cifrada. En la página se ve oculta y se copia completa.</small></label>
      <div data-api-quitar-clave hidden><?= UI::checkDS('quitar_clave', false, 'Quitar la clave guardada', 'Queda sin clave (por ejemplo, si se dio de baja en el proveedor).') ?></div>
      <div class="campo-doble">
        <label class="campo"><span>URL base <small>(opcional)</small></span>
          <input class="input-meca" type="url" name="url" maxlength="500" placeholder="https://api.openai.com/v1"></label>
        <label class="campo"><span>Documentación <small>(opcional)</small></span>
          <input class="input-meca" type="url" name="docs" maxlength="500" placeholder="https://platform.openai.com/docs"></label>
      </div>
      <label class="campo"><span>Cómo usarla <small>(opcional)</small></span>
        <textarea class="input-meca" name="notas" rows="3" maxlength="3000" placeholder="Ej. Variable OPENAI_API_KEY en el .env. Modelo gpt-4o-mini. Límite: 50 USD al mes."></textarea></label>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar</span></button>
    </footer>
  </form>
</dialog>
<?php endif; ?>
<script src="<?= asset('assets/apis.js') ?>" defer></script>
<?php UI::fin(); ?>
