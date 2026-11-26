<?php
/**
 * Firma de documentos: la lista. Arriba lo que te toca firmar; después lo que
 * creaste y todo en lo que participas (el administrador ve todos). Cada fila
 * abre el documento (firma.php). «Nuevo documento» y «Mi firma» se hacen en
 * su modal. La lógica está en lib/firmas/Firmas.php.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/firmas/firmas_ui.php';
require_once __DIR__ . '/lib/firmas/InformeSemanal.php';

$yo = Auth::usuario();
if (!$yo) redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
$uid = (int)$yo['id'];
$mapa = (new MiembroRepo())->mapa();

$docs = array_values(array_filter(Firmas::todos(), fn($d) => Firmas::puedeVer($d, $yo)));
usort($docs, fn($a, $b) => strcmp((string)($b['actualizado'] ?? $b['creado'] ?? ''), (string)($a['actualizado'] ?? $a['creado'] ?? '')) ?: (int)$b['id'] <=> (int)$a['id']);

// Informe semanal: si te toca hacerlo y todavía no está el de esta semana, se puede generar ya
$confInf = InformeSemanal::conf();
$semInf = InformeSemanal::semana();
$hagoInforme = in_array($uid, $confInf['personas'], true);
$miInforme = $hagoInforme ? InformeSemanal::deLaSemana($uid, $semInf['clave']) : null;
// Mis informes semanales: los que el Hub me armó, del más nuevo al más viejo
$misInformes = array_values(array_filter(Firmas::todos(), fn($d) => ($d['tipo'] ?? '') === 'informe' && (int)($d['persona'] ?? 0) === $uid));
usort($misInformes, fn($a, $b) => strcmp((string)($b['semana'] ?? ''), (string)($a['semana'] ?? '')) ?: (int)$b['id'] <=> (int)$a['id']);

$tabs = [
    'turno'    => ['Te toca firmar', 'firma', array_values(array_filter($docs, fn($d) => Firmas::esMiTurno($d, $uid)))],
    'creados'  => ['Creados por ti', 'enviar', array_values(array_filter($docs, fn($d) => (int)($d['creado_por'] ?? 0) === $uid))],
    'todos'    => [Auth::esAdmin() ? 'Todos' : 'Donde participas', 'documento', $docs],
];
if ($hagoInforme || $misInformes) $tabs['informes'] = ['Mis informes semanales', 'reportes', $misInformes];
$pedida = (string)($_GET['tab'] ?? '');
$activa = isset($tabs[$pedida]) ? $pedida : ($tabs['turno'][2] ? 'turno' : ($tabs['creados'][2] ? 'creados' : 'todos'));

$nuevoBtn = '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-fd-abrir="dlg-doc-nuevo">' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Nuevo documento</span></button>';

/** Una fila de la lista. En el celular se vuelve tarjeta (assets/firmas.css). */
/** Las fotos de los firmantes, con un anillo de color según cómo va cada uno. */
$avatares = function (array $d) use ($mapa): string {
    $avs = '';
    foreach ($d['firmantes'] ?? [] as $k => $f) {
        [$txt] = FirmasUI::estadoFirmante($d, (int)$k);
        $cls = match (true) {
            ($f['estado'] ?? '') === 'firmado'   => ' is-ok',
            ($f['estado'] ?? '') === 'rechazado' => ' is-no',
            Firmas::turno($d) === (int)$k        => ' is-turno',
            default                              => '',
        };
        $m = $mapa[(int)$f['uid']] ?? null;
        $avs .= '<span class="fd-av' . $cls . '" title="' . e($f['rol'] . ': ' . ($m['nombre'] ?? '—') . ' · ' . $txt) . '">' . UI::avatar($m, 28) . '</span>';
    }
    return '<div class="fd-avs" aria-label="Firmantes">' . $avs . '</div>';
};
$fila = function (array $d, int $i) use ($avatares, $uid): string {
    $url = 'firma.php?id=' . (int)$d['id'];
    $mio = Firmas::esMiTurno($d, $uid);
    $actual = (string)($d['actualizado'] ?? $d['creado'] ?? '');
    $pags = (int)($d['paginas'] ?? 0);
    return '<tr class="mc-table__row-in is-clickable' . ($mio ? ' fd-es-mio' : '') . '" style="--i:' . $i . '" tabindex="0" data-href="' . e($url) . '" data-buscar="' . e(mb_strtolower($d['titulo'] . ' ' . ($d['archivo'] ?? ''))) . '">'
        . '<td class="fd-c-doc"><div class="mc-table__cell fd-doc">'
        . '<span class="mc-tile mc-tile--sm mc-tile--' . ($mio ? 'naranja' : 'petroleo') . '">' . UI::iconoDS($mio ? 'firma' : 'documento', 18) . '</span>'
        . '<span class="fd-doc__txt"><a class="fd-doc__tit" href="' . e($url) . '" title="' . e($d['titulo']) . '">' . e($d['titulo']) . '</a>'
        . '<small class="fd-doc__sub" title="' . e((string)($d['archivo'] ?? '')) . '">' . e((string)($d['archivo'] ?? '')) . ($pags ? ' · ' . $pags . ($pags === 1 ? ' página' : ' páginas') : '') . '</small></span></div></td>'
        . '<td class="fd-c-estado">' . FirmasUI::estado($d) . '</td>'
        . '<td class="fd-c-firm">' . $avatares($d) . '</td>'
        . '<td class="fd-c-fecha"><span title="Actualizado: ' . e(Firmas::fecha($actual)) . '">' . e(Firmas::fecha($actual, false)) . '</span></td>'
        . '<td class="mc-table__actions-c fd-c-acc"><a class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--ghost" href="' . e($url) . '" aria-label="Ver el documento «' . e($d['titulo']) . '»" data-ds-tip="Ver documento">' . UI::iconoDS('ver-documento', 20) . '</a></td>'
        . '</tr>';
};

/**
 * Una fila de «Mis informes semanales»: la semana, cómo va la firma, las
 * cifras que salieron en el informe y quiénes lo firman.
 */
$filaInforme = function (array $d, int $i) use ($avatares, $semInf): string {
    $url = 'firma.php?id=' . (int)$d['id'];
    $c = (array)($d['contenido'] ?? []);
    $k = (array)($c['kpi'] ?? []);
    $t = strtotime(str_replace('-', '', (string)($d['semana'] ?? '')));
    $sem = InformeSemanal::semana($t ?: strtotime((string)($d['creado'] ?? 'now')));
    $esta = ($d['semana'] ?? '') === $semInf['clave'];
    $num = fn(string $q) => isset($k[$q]) ? number_format((int)$k[$q], 0, ',', '.') : '—';
    $reu = !empty($k['reu_conv']) ? (int)$k['reu_asis'] . ' de ' . (int)$k['reu_conv'] : '—';
    $firmado = ($d['estado'] ?? '') === 'firmado';
    $pdf = 'firma_archivo.php?doc=' . (int)$d['id'] . '&pdf=' . ($firmado ? 'firmado' : 'original') . '&bajar=1';
    $sub = ($esta ? 'Esta semana · ' : '') . (string)($c['codigo'] ?? '');
    return '<tr class="mc-table__row-in is-clickable" style="--i:' . $i . '" tabindex="0" data-href="' . e($url) . '">'
        . '<td class="fi-c-sem"><div class="mc-table__cell fd-doc">'
        . '<span class="mc-tile mc-tile--sm mc-tile--' . ($esta ? 'azul' : 'petroleo') . '">' . UI::iconoDS('reportes', 18) . '</span>'
        . '<span class="fd-doc__txt"><a class="fd-doc__tit" href="' . e($url) . '" title="Semana ' . e($sem['texto']) . '">' . e($sem['corto']) . '</a>'
        . '<small class="fd-doc__sub">' . e(trim($sub, ' ·')) . '</small></span></div></td>'
        . '<td class="fi-c-estado">' . FirmasUI::estado($d) . '</td>'
        . '<td class="fi-c-num" data-label="Commits">' . $num('commits') . '</td>'
        . '<td class="fi-c-num" data-label="Tareas">' . $num('tareas') . '</td>'
        . '<td class="fi-c-num" data-label="Requerimientos">' . $num('reqs') . '</td>'
        . '<td class="fi-c-num" data-label="Reuniones"' . ($reu !== '—' ? ' title="Entraste a ' . e($reu) . ' reuniones"' : '') . '>' . $reu . '</td>'
        . '<td class="fi-c-firm">' . $avatares($d) . '</td>'
        . '<td class="mc-table__actions-c fi-c-acc"><div class="mc-table__actions">'
        . '<a class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--ghost" href="' . e($pdf) . '" aria-label="Descargar el PDF del informe" data-ds-tip="Descargar PDF">' . UI::icono('adj-pdf', 'fd-ico-pdf') . '</a>'
        . '<a class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--ghost" href="' . e($url) . '" aria-label="Ver el informe de la semana ' . e($sem['texto']) . '" data-ds-tip="Ver documento">' . UI::iconoDS('ver-documento', 20) . '</a>'
        . '</div></td></tr>';
};
/** El botón que arma ya el informe de esta semana (tarda unos segundos: lee los repositorios). */
$generarBtn = '<form method="post" action="actions.php" data-fd-espera="Armando tu informe…"><input type="hidden" name="accion" value="informe_generar_mio">'
    . '<button class="mc-btn mc-btn--secondary mc-btn--sm">' . UI::iconoDS('reportes', 16) . '<span class="mc-btn__label">Generarlo ahora</span></button></form>';
$cuandoInf = 'Se arma solo el ' . mb_strtolower(InformeSemanal::DIAS[$confInf['dia']]) . ' a las ' . $confInf['hora'] . ($confInf['activo'] ? '' : ' (cuando se active)');

UI::inicio('Firmas', 'firmas');
UI::cabecera(
    'Firma de <span class="text-secondary">documentos</span>',
    'Sube un PDF, elige quién firma y en qué orden. A cada uno le llega el aviso cuando le toca y, al final, queda el PDF con la hoja «Firmas de responsabilidad».',
    '<div class="fd-cab-acc">'
    . (Auth::esAdmin() ? '<a class="mc-iconbtn mc-iconbtn--tinte" href="firmas_param.php" aria-label="Parámetros de firmas: coordinaciones, cargos e informes" data-ds-tip="Parámetros">' . UI::iconoDS('configuracion', 20) . '</a>' : '')
    . '<button type="button" class="mc-iconbtn mc-iconbtn--tinte" data-fd-abrir="dlg-mi-firma" aria-label="Mi firma" data-ds-tip="Mi firma">' . UI::iconoDS('firma', 20) . '</button>'
    . '<button type="button" class="mc-iconbtn mc-iconbtn--tinte" data-fd-abrir="dlg-doc-nuevo" aria-label="Nuevo documento" data-ds-tip="Nuevo documento">' . UI::iconoDS('mas', 20) . '</button></div>'
);
?>
<div class="ds fd-pagina">
  <?php if (!Firmas::tieneFirma($uid) && !Firmas::certGuardado($uid)): ?>
  <div class="mc-alert mc-alert--tip fd-sinfirma" role="status"><?= UI::iconoDS('firma', 20, 'mc-alert__icon') ?>
    <div class="mc-alert__content"><p class="mc-alert__title">Todavía no guardas tu firma</p>
      <p class="mc-alert__desc">La necesitas para firmar los documentos que te lleguen: tu firma gráfica (dibujada o una foto) o tu firma electrónica (.p12).</p></div>
    <button type="button" class="mc-btn mc-btn--secondary mc-btn--sm" data-fd-abrir="dlg-mi-firma"><span class="mc-btn__label">Guardar mi firma</span></button>
  </div>
  <?php endif; ?>

  <?php if ($miInforme && ($miInforme['estado'] ?? '') === 'borrador'): ?>
  <div class="mc-alert mc-alert--info fd-sinfirma" role="status"><?= UI::iconoDS('reportes', 20, 'mc-alert__icon') ?>
    <div class="mc-alert__content"><p class="mc-alert__title">Tu informe semanal está listo</p>
      <p class="mc-alert__desc">Revísalo, corrige lo que haga falta y fírmalo para que siga su camino.</p></div>
    <a class="mc-btn mc-btn--secondary mc-btn--sm" href="firma.php?id=<?= (int)$miInforme['id'] ?>"><span class="mc-btn__label">Abrirlo</span></a>
  </div>
  <?php endif; ?>

  <?php /* Las pestañas (assets/ds.js) abren la del #ancla: se pone la que eligió el servidor si no hay otra */ ?>
  <script>if (!location.hash) history.replaceState(null, '', location.pathname + location.search + '#<?= $activa ?>');</script>
  <div class="mc-tabs mc-tabs--line" role="tablist" aria-label="Documentos" data-ds-tabs-auto>
    <?php foreach ($tabs as $tid => [$etq, $ico, $lista]): $on = $tid === $activa; ?>
    <button type="button" class="mc-tab<?= $on ? ' is-active' : '' ?>" role="tab" id="tab-<?= $tid ?>" aria-selected="<?= $on ? 'true' : 'false' ?>"
            aria-controls="panel-<?= $tid ?>" data-tab="<?= $tid ?>"<?= $on ? '' : ' tabindex="-1"' ?>>
      <?= UI::iconoDS($ico, 18) ?><span class="mc-tab__label"><?= e($etq) ?></span><?php if ($lista): ?><span class="mc-tab__count"><?= count($lista) ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <span class="mc-tabs__ink"></span>
  </div>

  <?php foreach ($tabs as $tid => [$etq, , $lista]): ?>
  <section class="ds-panel fd-panel" id="panel-<?= $tid ?>" role="tabpanel" aria-labelledby="tab-<?= $tid ?>" data-panel="<?= $tid ?>"<?= $tid === $activa ? '' : ' hidden' ?>>
    <?php if ($tid === 'informes'): ?>
      <?php if (!$lista): ?>
        <?= UI::vacioDS('reportes-vacio', 'Todavía no tienes informes semanales', $cuandoInf . ' con tus commits, tareas, requerimientos y reuniones de la semana. Si lo necesitas antes, genéralo ahora.', $hagoInforme ? $generarBtn : '') ?>
      <?php else: ?>
        <?php if ($hagoInforme && !$miInforme): ?>
        <div class="mc-alert mc-alert--info fd-sinfirma fi-semana" role="status"><?= UI::iconoDS('reportes', 20, 'mc-alert__icon') ?>
          <div class="mc-alert__content"><p class="mc-alert__title">Tu informe de la semana <?= e($semInf['texto']) ?></p>
            <p class="mc-alert__desc"><?= e($cuandoInf) ?>. Si lo necesitas antes, genéralo ahora con lo que registraste en el Hub.</p></div>
          <?= $generarBtn ?>
        </div>
        <?php endif; ?>
        <div class="mc-table fd-inf">
          <div class="mc-table__scroll">
            <table class="mc-table__table">
              <thead><tr><th>Semana</th><th>Estado</th><th class="fi-c-num">Commits</th><th class="fi-c-num">Tareas</th><th class="fi-c-num">Requerimientos</th><th class="fi-c-num">Reuniones</th><th>Firman</th><th class="mc-table__actions-h"><span class="mc-sr-only">Acciones</span></th></tr></thead>
              <tbody><?php foreach ($lista as $i => $d) echo $filaInforme($d, min($i, 12)); ?></tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    <?php elseif (!$lista): ?>
      <?= match ($tid) {
          'turno'   => UI::vacioDS('nexo:descansando', 'Nada por firmar', 'Cuando te toque firmar un documento, aparece aquí y te llega el aviso en la campana y por correo.'),
          'creados' => UI::vacioDS('reportes-vacio', 'Todavía no creas documentos', 'Con «Nuevo documento» subes un PDF y eliges quién lo firma: Elaborado por, Revisado por, Aprobado por…', $nuevoBtn),
          default   => UI::vacioDS('reportes-vacio', 'Todavía no hay documentos', 'Los documentos que crees o que te toque firmar aparecen aquí con su estado.', $nuevoBtn),
      } ?>
    <?php else: ?>
    <div class="mc-table fd-tabla">
      <?php if (count($lista) > 6): ?>
      <div class="mc-table__toolbar">
        <label class="mc-table__search"><?= UI::iconoDS('buscar', 18) ?>
          <input type="search" placeholder="Buscar por título o archivo" aria-label="Buscar documentos" data-fd-buscar></label>
      </div>
      <?php endif; ?>
      <div class="mc-table__scroll">
        <table class="mc-table__table">
          <thead><tr><th>Documento</th><th>Estado</th><th>Firmantes</th><th>Actualizado</th><th class="mc-table__actions-h"><span class="mc-sr-only">Abrir</span></th></tr></thead>
          <tbody>
            <?php foreach ($lista as $i => $d) echo $fila($d, min($i, 12)); ?>
            <tr class="fd-sin-resultados" hidden><td colspan="5"><?= UI::vacioDS('sin-resultados-cursos', 'Nada con ese nombre', 'Prueba con otra palabra del título o del archivo.', '', 'sm', false) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>

<?= FirmasUI::modalDocumento(null, $yo) ?>
<?= FirmasUI::modalMiFirma($yo, 'firmas.php') ?>
<script src="<?= asset('assets/firmas.js') ?>" defer></script>
<?php UI::fin(); ?>
