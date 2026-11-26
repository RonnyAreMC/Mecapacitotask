<?php
/**
 * Un documento para firmar. A la izquierda el PDF (la primera hoja; al
 * tocarla se abre el visor en un modal); a la derecha su estado con lo que te
 * toca hacer, el cuadro de firmantes y los archivos. El historial, con las
 * observaciones, va en su modal. Firmar (gráfica o electrónica con .p12),
 * observar, editar y borrar también se hacen en su modal.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/firmas/firmas_ui.php';

$yo = Auth::usuario();
if (!$yo) redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
$uid = (int)$yo['id'];
$doc = Firmas::buscar((int)($_GET['id'] ?? 0));
if (!$doc || !Firmas::puedeVer($doc, $yo)) redirigir('firmas.php', 'No encontramos ese documento, o no participas en él.', 'error');

$mapa = (new MiembroRepo())->mapa();
$id = (int)$doc['id'];
$estado = (string)($doc['estado'] ?? 'borrador');
$turno = Firmas::turno($doc);
$miTurno = Firmas::esMiTurno($doc, $uid);
$gestiona = Firmas::puedeGestionar($doc, $yo);
$n = count($doc['firmantes'] ?? []);
$hayFirmado = is_file(Firmas::rutaFirmado($doc)) && $estado !== 'borrador';
$tengoFirma = Firmas::tieneFirma($uid);
$quien = fn(int $u) => Firmas::nombre($u);
$cual = $hayFirmado ? 'firmado' : 'original';
$verPdf = 'firma_archivo.php?doc=' . $id . '&pdf=' . $cual;
$nombrePdf = preg_replace('/\.pdf$/i', '', (string)$doc['titulo']) . ($hayFirmado ? ($estado === 'firmado' ? ' - firmado' : ' - hoja de firmas') : '') . '.pdf';
$pags = (int)($doc['paginas'] ?? 0) + ($hayFirmado ? 1 : 0);
$creador = $mapa[(int)$doc['creado_por']] ?? ['nombre' => '—'];
// El informe semanal: lo arma el Hub como borrador y la persona lo corrige y lo firma primero («Firmar y enviar»)
$esInforme = ($doc['tipo'] ?? '') === 'informe';
$primeroYo = $estado === 'borrador' && $gestiona && (int)($doc['firmantes'][0]['uid'] ?? 0) === $uid;
if ($primeroYo) { $miTurno = true; $turno = 0; }
$dlgEditar = $esInforme ? 'dlg-informe' : 'dlg-doc-editar';
$ico = fn(string $n, int $px = 18) => UI::iconoDS($n, $px);

$acc = '<a class="mc-iconbtn mc-iconbtn--tinte" href="firmas.php" aria-label="Volver a Firmas" data-ds-tip="Volver">' . $ico('chevron-izq', 20) . '</a>'
    . '<button type="button" class="mc-iconbtn mc-iconbtn--tinte" data-fd-abrir="dlg-historial" aria-label="Historial y observaciones" data-ds-tip="Historial">' . $ico('reloj', 20) . '</button>';
if ($gestiona && $estado !== 'firmado') {
    $acc .= '<button type="button" class="mc-iconbtn mc-iconbtn--tinte" data-fd-abrir="' . $dlgEditar . '" aria-label="' . ($esInforme ? 'Corregir el informe' : 'Editar el documento') . '" data-ds-tip="' . ($esInforme ? 'Corregir' : 'Editar') . '">' . $ico('editar-cuadro', 20) . '</button>';
}
if ($gestiona && ($estado !== 'firmado' || Auth::esAdmin())) {
    $acc .= '<button type="submit" form="f-eliminar" class="mc-iconbtn mc-iconbtn--tinte mc-iconbtn--danger" aria-label="Eliminar el documento" data-ds-tip="Eliminar">' . $ico('eliminar-bote', 20) . '</button>';
}

UI::inicio($doc['titulo'], 'firmas');
// Arriba el nombre del documento (el del informe, sin la persona: va de autor) y una línea con el autor
$partesTit = explode(' · ', (string)$doc['titulo']);
$tituloVer = $esInforme && count($partesTit) >= 3 ? $partesTit[0] . ' · ' . end($partesTit) : (string)$doc['titulo'];
$autor = Firmas::nombre((int)($doc['persona'] ?? $doc['creado_por'] ?? 0));
UI::cabecera(e($tituloVer),
    '<span class="fd-autor" title="' . e($autor) . '">Autor: <b>' . e(mb_strtoupper($autor)) . '</b> · ' . e(Firmas::fecha((string)($doc['creado'] ?? ''), false)) . '</span>',
    '<div class="fd-cab-acc">' . $acc . '</div>');
?>
<div class="ds fd-ficha">
  <!-- El documento: la primera hoja; al tocarla se abre el visor -->
  <section class="mc-card fd-card fd-doccard" aria-label="Documento">
    <div class="fd-doccard__cab">
      <span class="mc-tile mc-tile--sm mc-tile--rojo"><?= $ico('pdf') ?></span>
      <span class="fd-doccard__txt"><b class="truncate" title="<?= e($nombrePdf) ?>"><?= e($nombrePdf) ?></b>
        <small><?= $pags ?> <?= $pags === 1 ? 'página' : 'páginas' ?><?= $hayFirmado ? ' · la última es la hoja de firmas' : '' ?></small></span>
      <a class="mc-iconbtn mc-iconbtn--tinte" href="<?= e($verPdf) ?>&amp;bajar=1" aria-label="Descargar el PDF" data-ds-tip="Descargar PDF"><?= UI::icono('adj-pdf', 'fd-ico-pdf') ?></a>
    </div>
    <button type="button" class="fd-miniatura" data-fd-ver aria-label="Ver el documento completo">
      <canvas data-fd-miniatura data-pdf="<?= e($verPdf) ?>" aria-hidden="true"></canvas>
      <span class="fd-miniatura__carga" data-fd-miniatura-carga><?= $ico('pdf', 40) ?></span>
      <span class="fd-miniatura__ver"><?= $ico('ver-documento') ?> Ver documento</span>
    </button>
  </section>

  <div class="fd-lado">
    <!-- Estado y lo que te toca hacer -->
    <section class="mc-card fd-card fd-estado">
      <div class="fd-estado__cab"><?= FirmasUI::estado($doc, false) ?></div>
      <?php /* Quién firma ya está abajo, en «Firmas de responsabilidad»: aquí no se repite */ ?>
      <?php if ($estado === 'borrador' && $esInforme && $primeroYo): ?>
        <p class="fd-estado__txt">Tu informe de la semana <?= e((string)($doc['contenido']['semana'] ?? '')) ?> está listo. Revísalo y fírmalo; si algo falta, corrígelo con el lápiz de arriba.</p>
        <div class="fd-estado__acc">
          <button type="button" class="mc-btn mc-btn--primary mc-btn--md fd-ancho" data-fd-abrir="dlg-firmar"><?= $ico('firma') ?><span class="mc-btn__label">Firmar y enviar</span></button>
        </div>
      <?php elseif ($estado === 'borrador'): ?>
        <p class="fd-estado__txt">Todavía no se envía. Cuando esté listo, envíalo y le avisamos a quien firma primero.</p>
        <?php if ($gestiona): ?>
        <form method="post" action="actions.php" class="fd-estado__acc"><input type="hidden" name="accion" value="firma_doc_enviar"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="mc-btn mc-btn--primary mc-btn--md fd-ancho"><?= $ico('enviar') ?><span class="mc-btn__label">Enviar a firmar</span></button></form>
        <?php endif; ?>
      <?php elseif ($estado === 'en_firma' && $turno !== null): $ft = $doc['firmantes'][$turno]; ?>
        <?php if ($miTurno): ?>
        <p class="fd-estado__txt"><b>Te toca firmar.</b> Revisa el documento y, si está bien, fírmalo. Si algo no cuadra, obsérvalo y vuelve a quien lo elaboró.</p>
        <div class="fd-estado__acc fd-estado__acc--dos">
          <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-fd-abrir="dlg-observar"><?= $ico('mensajes') ?><span class="mc-btn__label">Observar</span></button>
          <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-fd-abrir="dlg-firmar"><?= $ico('firma') ?><span class="mc-btn__label">Firmar</span></button>
        </div>
        <?php else: ?>
        <p class="fd-estado__txt">Está en firma. Ya le avisamos a quien le toca.</p>
        <?php endif; ?>
      <?php elseif ($estado === 'rechazado'): ?>
        <p class="fd-estado__txt">Tiene observaciones y vuelve a quien lo elaboró para corregirlo.</p>
        <button type="button" class="fd-enlace" data-fd-abrir="dlg-historial">Ver las observaciones</button>
        <?php if ($gestiona): ?>
        <p class="fd-estado__txt">Corrígelo con el lápiz de arriba y vuelve a enviarlo.</p>
        <form method="post" action="actions.php" class="fd-estado__acc"><input type="hidden" name="accion" value="firma_doc_enviar"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="mc-btn mc-btn--primary mc-btn--md fd-ancho"><?= $ico('enviar') ?><span class="mc-btn__label">Volver a enviar</span></button></form>
        <?php endif; ?>
      <?php elseif ($estado === 'firmado'): ?>
        <p class="fd-estado__txt">Lo firmaron todos el <?= e(Firmas::fecha((string)($doc['firmado_en'] ?? ''))) ?>. El PDF firmado tiene la hoja «Firmas de responsabilidad» al final.</p>
        <div class="fd-estado__acc"><a class="mc-btn mc-btn--primary mc-btn--md fd-ancho" href="firma_archivo.php?doc=<?= $id ?>&amp;pdf=firmado&amp;bajar=1"><?= $ico('descargar') ?><span class="mc-btn__label">Descargar el PDF firmado</span></a></div>
      <?php endif; ?>
    </section>

    <!-- El cuadro de firmas -->
    <section class="mc-card fd-card">
      <h2 class="fd-card__tit">Firmas de responsabilidad</h2>
      <ol class="fd-cuadro">
        <?php foreach ($doc['firmantes'] ?? [] as $i => $f): [$txt, $tono] = FirmasUI::estadoFirmante($doc, (int)$i); $m = $mapa[(int)$f['uid']] ?? null; ?>
        <li class="fd-firmante<?= $turno === (int)$i ? ' is-turno' : '' ?>">
          <span class="fd-firmante__n" aria-hidden="true"><?= $i + 1 ?></span>
          <?= UI::avatar($m, 36) ?>
          <span class="fd-firmante__txt">
            <small class="fd-firmante__rol"><?= e($f['rol']) ?></small>
            <b class="truncate" title="<?= e($m['nombre'] ?? '—') ?>"><?= e($m['nombre'] ?? '—') ?></b>
            <?php if (($f['cargo'] ?? '') !== ''): ?><small class="fd-firmante__cargo"><?= e($f['cargo']) ?></small><?php endif; ?>
            <span class="mc-status mc-status--sm mc-status--<?= $tono ?>"><span class="mc-status__dot"></span><?= e($txt) ?></span>
            <?php if (($f['tipo'] ?? '') === 'electronica' && ($f['estado'] ?? '') === 'firmado'): ?>
            <small class="fd-firmante__cert" title="<?= e(($f['cert'] ?? '') . (($f['emisor'] ?? '') !== '' ? ' · ' . $f['emisor'] : '')) ?>"><?= $ico('certificado', 14) ?> <?= e((string)($f['cert'] ?? '')) ?></small>
            <?php endif; ?>
          </span>
          <?php if (($f['estado'] ?? '') === 'firmado' && Firmas::rutaFirmaUsada($doc, (int)$i) !== ''): ?>
          <img class="fd-firmante__firma" src="firma_archivo.php?doc=<?= $id ?>&amp;firma=<?= (int)$i ?>&amp;r=<?= (int)($doc['ronda'] ?? 1) ?>" alt="Firma de <?= e($m['nombre'] ?? '') ?>" loading="lazy">
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>

    <!-- Archivos e integridad -->
    <section class="mc-card fd-card">
      <h2 class="fd-card__tit">Archivos</h2>
      <ul class="fd-archivos">
        <?php if ($hayFirmado): ?>
        <li><span class="mc-tile mc-tile--sm mc-tile--rojo"><?= $ico('pdf') ?></span>
          <span class="fd-archivos__txt"><b><?= $estado === 'firmado' ? 'PDF firmado' : 'PDF con la hoja de firmas' ?></b>
          <small><?= $estado === 'firmado' ? 'El original con la hoja «Firmas de responsabilidad» al final.' : 'Cómo va: la hoja muestra quién falta.' ?></small></span>
          <a class="mc-iconbtn mc-iconbtn--outline mc-iconbtn--sm" href="firma_archivo.php?doc=<?= $id ?>&amp;pdf=firmado&amp;bajar=1" aria-label="Descargar el PDF con la hoja de firmas" data-ds-tip="Descargar"><?= $ico('descargar', 16) ?></a></li>
        <?php endif; ?>
        <li><span class="mc-tile mc-tile--sm mc-tile--neutro fd-tile-gris"><?= $ico('pdf') ?></span>
          <span class="fd-archivos__txt"><b>PDF original</b><small class="truncate" title="<?= e((string)($doc['archivo'] ?? '')) ?>"><?= e((string)($doc['archivo'] ?? '')) ?></small></span>
          <a class="mc-iconbtn mc-iconbtn--outline mc-iconbtn--sm" href="firma_archivo.php?doc=<?= $id ?>&amp;pdf=original&amp;bajar=1" aria-label="Descargar el PDF original" data-ds-tip="Descargar"><?= $ico('descargar', 16) ?></a></li>
      </ul>
      <dl class="fd-hash">
        <dt>SHA-256 del original</dt><dd><code title="<?= e((string)($doc['hash_original'] ?? '')) ?>"><?= e((string)($doc['hash_original'] ?? '')) ?></code></dd>
        <?php if (($doc['hash_final'] ?? '') !== ''): ?>
        <dt>SHA-256 del firmado</dt><dd><code title="<?= e((string)$doc['hash_final']) ?>"><?= e((string)$doc['hash_final']) ?></code></dd>
        <?php endif; ?>
      </dl>
      <p class="fd-aclara">La firma gráfica registra quién firmó, cuándo y desde qué conexión. La firma electrónica con certificado va dentro del PDF y se valida con FirmaEC o Adobe Acrobat.</p>
    </section>
  </div>
</div>

<!-- Visor del PDF -->
<dialog class="ds-modal fd-dlg fd-visor-dlg" id="dlg-ver" aria-labelledby="dlg-ver-t" data-fd-visor data-pdf="<?= e($verPdf) ?>">
  <header class="ds-modal__head">
    <span class="mc-tile mc-tile--sm mc-tile--rojo"><?= $ico('pdf') ?></span>
    <div><h2 class="ds-modal__title fd-una-linea" id="dlg-ver-t" title="<?= e($nombrePdf) ?>"><?= e($nombrePdf) ?></h2>
      <p class="ds-modal__sub"><?= e(Firmas::rotulo($doc)) ?></p></div>
    <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= $ico('cerrar') ?></button>
  </header>
  <div class="fd-vw">
    <div class="fd-vw__barra" role="toolbar" aria-label="Controles del documento">
      <span class="fd-vw__pagina"><input type="number" min="1" value="1" inputmode="numeric" aria-label="Ir a la página" data-vw-pag><span>/ <b data-vw-total>–</b></span></span>
      <span class="fd-vw__sep" aria-hidden="true"></span>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-vw-menos aria-label="Alejar"><?= $ico('menos', 16) ?></button>
      <span class="fd-vw__zoom" data-vw-zoom>100%</span>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-vw-mas aria-label="Acercar"><?= $ico('mas', 16) ?></button>
      <span class="fd-vw__sep" aria-hidden="true"></span>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-vw-ajustar aria-label="Ajustar al ancho"><?= $ico('expandir', 16) ?></button>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-vw-girar aria-label="Girar"><?= $ico('reintentar', 16) ?></button>
    </div>
    <a class="mc-iconbtn mc-iconbtn--outline fd-vw__bajar" href="<?= e($verPdf) ?>&amp;bajar=1" aria-label="Descargar el PDF" data-ds-tip="Descargar"><?= $ico('descargar') ?></a>
    <div class="fd-vw__hojas" data-vw-hojas tabindex="0" aria-label="Páginas del documento">
      <p class="fd-vw__estado" data-vw-estado>Cargando el documento…</p>
    </div>
  </div>
</dialog>

<!-- Historial y observaciones -->
<?php
$accEv = [
    'creado' => ['Documento creado', 'azul'], 'enviado' => ['Enviado a firmar', 'azul'], 'reenviado' => ['Enviado de nuevo', 'azul'],
    'notificado' => ['Aviso enviado', 'azul'], 'firmado' => ['Firmado', 'verde'], 'completado' => ['Firmado por todos', 'verde'],
    'rechazado' => ['Observado', 'rojo'], 'descargado' => ['Descargado', 'azul'], 'editado' => ['Editado', 'azul'], 'reiniciado' => ['Flujo reiniciado', 'naranja'],
];
$eventos = $doc['eventos'] ?? [];
?>
<dialog class="ds-modal fd-dlg fd-hist-dlg" id="dlg-historial" aria-labelledby="dlg-hist-t">
  <header class="ds-modal__head">
    <span class="mc-tile mc-tile--sm mc-tile--azul"><?= $ico('reloj') ?></span>
    <div><h2 class="ds-modal__title" id="dlg-hist-t">Historial y observaciones</h2>
      <p class="ds-modal__sub">Quién hizo qué, cuándo y desde qué conexión.</p></div>
    <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= $ico('cerrar') ?></button>
  </header>
  <div class="ds-modal__body fd-hist" data-fd-hist data-por-pagina="8">
    <div class="mc-table fd-hist__tabla">
      <div class="mc-table__scroll">
        <table class="mc-table__table">
          <thead><tr><th>Fecha</th><th>Hora</th><th>Acción</th><th>Realizado por</th><th class="fd-hist__th-linea"><span class="mc-sr-only">Línea de tiempo</span></th></tr></thead>
          <tbody>
          <?php foreach ($eventos as $k => $ev):
              $t = strtotime((string)($ev['t'] ?? '')) ?: 0;
              [$etq, $tn] = $accEv[$ev['accion'] ?? ''] ?? ['Movimiento', 'azul'];
              $u = (int)($ev['uid'] ?? 0);
              $m = $mapa[$u] ?? null;
              $usuario = $u === 0 ? '@sistema' : (($m['git_user'] ?? '') !== '' ? '@' . ltrim((string)$m['git_user'], '@') : (string)($m['email'] ?? ''));
          ?>
          <tr data-fd-hist-fila>
            <td class="fd-hist__fecha"><?= $t ? date('Y-m-d', $t) : '—' ?></td>
            <td class="fd-hist__hora"><?= $t ? date('H:i:s', $t) : '—' ?></td>
            <td class="fd-hist__accion">
              <b><?= e($etq) ?></b>
              <small><?= e(ucfirst((string)($ev['texto'] ?? ''))) ?><?= ($ev['ip'] ?? '') !== '' && $u !== 0 ? ' · IP ' . e((string)$ev['ip']) : '' ?></small>
              <?php if (($ev['motivo'] ?? '') !== ''): ?><blockquote class="fd-hist__obs"><?= nl2br(e((string)$ev['motivo'])) ?></blockquote><?php endif; ?>
            </td>
            <td class="fd-hist__quien"><div class="fd-quien">
              <?php if ($u === 0): ?><span class="avatar fd-quien__hub" style="--sz:30px"><img src="<?= e(faviconPanel()) ?>" alt=""></span><?php else: ?><?= UI::avatar($m, 30) ?><?php endif; ?>
              <span class="fd-quien__txt"><b class="truncate"><?= e($quien($u)) ?></b><small class="truncate"><?= e($usuario) ?></small></span></div></td>
            <td class="fd-hist__linea<?= $k === 0 ? ' is-primero' : '' ?><?= $k === count($eventos) - 1 ? ' is-ultimo' : '' ?>">
              <span class="fd-hist__punto fd-hist__punto--<?= $tn ?>"><?= $ico($tn === 'rojo' ? 'alerta' : 'check', 14) ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mc-table__foot fd-hist__pie">
        <span class="fd-hist__cuenta" data-fd-hist-cuenta>Mostrando <b>1 - <?= min(8, count($eventos)) ?></b> de <b><?= count($eventos) ?></b> registros</span>
        <span class="fd-hist__pag">
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-hist-ir="primera" aria-label="Primera página"><?= $ico('chevron-izq', 16) ?><?= $ico('chevron-izq', 16) ?></button>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-hist-ir="antes" aria-label="Página anterior"><?= $ico('chevron-izq', 16) ?></button>
          <span>Página <b data-fd-hist-pag>1</b> de <b data-fd-hist-total>1</b></span>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-hist-ir="despues" aria-label="Página siguiente"><?= $ico('chevron-der', 16) ?></button>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-hist-ir="ultima" aria-label="Última página"><?= $ico('chevron-der', 16) ?><?= $ico('chevron-der', 16) ?></button>
        </span>
      </div>
    </div>
  </div>
</dialog>

<?php if ($gestiona && ($estado !== 'firmado' || Auth::esAdmin())): ?>
<form id="f-eliminar" method="post" action="actions.php" hidden
      data-confirmar="Se borran el PDF, las firmas y el historial de «<?= e($doc['titulo']) ?>». No se puede deshacer." data-confirmar-titulo="¿Eliminar el documento?" data-confirmar-ok="Sí, eliminar">
  <input type="hidden" name="accion" value="firma_doc_eliminar"><input type="hidden" name="id" value="<?= $id ?>">
</form>
<?php endif; ?>

<?php if ($gestiona && $estado !== 'firmado') echo FirmasUI::modalDocumento($doc, $yo); ?>
<?php if ($esInforme && $gestiona && $estado !== 'firmado') echo FirmasUI::modalInforme($doc); ?>

<?php if ($miTurno):
    $ft = $doc['firmantes'][$turno];
    $cert = Firmas::certGuardado($uid);
    $modo = $cert ? 'electronica' : 'grafica';
    // La fila de quien lo elaboró (si está en el cuadro), para el cargo en la carta
    $filaCreador = null;
    foreach ($doc['firmantes'] as $f) if ((int)$f['uid'] === (int)$doc['creado_por']) { $filaCreador = $f; break; }
    $cargoCreador = (string)($filaCreador['cargo'] ?? $creador['rol'] ?? '');
    $plantilla = 'Se solicita revisar y corregir el documento «' . $doc['titulo'] . '»'
        . ($cargoCreador !== '' ? ', en las actividades que están a su cargo como ' . $cargoCreador : '') . ".\n\n"
        . "Se encontraron las siguientes observaciones:\n\n1. \n\n"
        . "Estos ajustes son necesarios para continuar con la aprobación del documento, por lo que agradecemos su pronta atención.\n\n"
        . 'Quedamos a su disposición para cualquier consulta.';
?>
<!-- Firmar: con la firma gráfica o con el certificado (.p12 / .pfx) -->
<dialog class="ds-modal fd-dlg fd-dlg--md" id="dlg-firmar" aria-labelledby="dlg-firmar-t">
  <form method="post" action="actions.php" enctype="multipart/form-data" class="ds" data-fd-firmar data-tiene-firma="<?= $tengoFirma ? '1' : '0' ?>" novalidate>
    <input type="hidden" name="accion" value="firma_doc_firmar"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="modo" value="<?= $modo ?>" data-fd-modo>
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= $ico('firma') ?></span>
      <?php /* Arriba, solo quién emite el documento y la fecha (el título largo ya está en la página) */ ?>
      <div><h2 class="ds-modal__title" id="dlg-firmar-t">Emite: <?= e(Firmas::nombre((int)($doc['persona'] ?? $doc['creado_por'] ?? 0))) ?></h2>
        <p class="ds-modal__sub"><?= e(Firmas::fecha((string)($doc['creado'] ?? ''), false)) ?></p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= $ico('cerrar') ?></button>
    </header>
    <div class="ds-modal__body">
      <div class="mc-tabs mc-tabs--segmented mc-tabs--fitted fd-modos" role="tablist" aria-label="Cómo firmar">
        <button type="button" class="mc-tab<?= $modo === 'grafica' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $modo === 'grafica' ? 'true' : 'false' ?>" data-fd-tab="grafica"><?= $ico('firma', 16) ?><span class="mc-tab__label">Firma gráfica</span></button>
        <button type="button" class="mc-tab<?= $modo === 'electronica' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $modo === 'electronica' ? 'true' : 'false' ?>" data-fd-tab="electronica"><?= $ico('certificado', 16) ?><span class="mc-tab__label">Firma electrónica</span></button>
      </div>

      <div class="fd-modo" data-fd-panel="grafica"<?= $modo === 'grafica' ? '' : ' hidden' ?>>
        <?php if ($tengoFirma): ?>
        <div class="fd-previa" aria-label="Así queda tu fila en el cuadro de firmas">
          <div class="fd-previa__izq"><b><?= e(rtrim($ft['rol'], ':')) ?>:</b><span><?= e($yo['nombre']) ?></span><?php if ($ft['cargo'] !== ''): ?><small><?= e($ft['cargo']) ?></small><?php endif; ?></div>
          <div class="fd-previa__der">
            <img src="firma_archivo.php?mia=1&amp;v=<?= (int)@filemtime(Firmas::rutaMiFirma($uid)) ?>" alt="Tu firma">
            <span class="fd-previa__linea"></span>
            <small>Firmado el <?= date('d/m/Y') ?> a las <?= date('H:i') ?></small>
          </div>
        </div>
        <p class="fd-nota">Así queda tu fila en la hoja «Firmas de responsabilidad». Se registran tu nombre, la fecha y la IP (<?= e(Firmas::ip()) ?>).
          <button type="button" class="fd-enlace" data-fd-abrir="dlg-mi-firma">Cambiar mi firma</button></p>
        <?php if (!empty($yo['pass_hash'])): ?>
        <div class="mc-field"><label class="mc-field__label" for="fd-clave">Tu contraseña del Hub</label>
          <div class="mc-input"><input class="mc-input__control" id="fd-clave" type="password" name="clave" autocomplete="current-password" placeholder="La misma con la que entras" data-fd-req="grafica"></div>
          <span class="mc-field__hint">La pedimos para confirmar que eres tú quien firma.</span></div>
        <?php else: ?>
        <?= UI::checkDS('confirmo', false, 'Revisé el documento y lo firmo', 'Entras con Google, así que confirmamos con esta casilla.') ?>
        <?php endif; ?>
        <?php else: ?>
        <div class="mc-alert mc-alert--tip" role="status"><?= UI::iconoDS('firma', 20, 'mc-alert__icon') ?>
          <div class="mc-alert__content"><p class="mc-alert__title">Primero guarda tu firma gráfica</p>
            <p class="mc-alert__desc">La dibujas o subes una foto una sola vez. Si tienes firma electrónica, usa la otra pestaña.</p></div>
          <button type="button" class="mc-btn mc-btn--secondary mc-btn--sm" data-fd-abrir="dlg-mi-firma"><span class="mc-btn__label">Guardar mi firma</span></button></div>
        <?php endif; ?>
      </div>

      <div class="fd-modo" data-fd-panel="electronica"<?= $modo === 'electronica' ? '' : ' hidden' ?>>
        <div class="mc-field"><span class="mc-field__label">Archivo de firma electrónica (.p12)</span>
          <div class="mc-input is-readonly fd-cert" data-fd-cert>
            <?= $ico('certificado', 18) ?><span class="fd-cert__nombre truncate" data-fd-cert-nombre><?= $cert ? e($cert['archivo']) : 'Ningún archivo cargado' ?></span>
            <?php if ($cert): ?><span class="mc-status mc-status--sm mc-status--success fd-cert__rec"><span class="mc-status__dot"></span>Recordado</span><?php endif; ?>
          </div>
          <input type="file" name="p12" accept=".p12" class="mc-sr-only" data-fd-cert-archivo aria-label="Archivo de firma electrónica">
          <input type="hidden" name="recordar" value="" data-fd-recordar-val></div>
        <div class="fd-cert-acc">
          <button type="button" class="mc-btn mc-btn--sm fd-btn fd-btn--verde" data-fd-cert-cargar><?= $ico('importar', 16) ?><span class="mc-btn__label">Cargar</span></button>
          <button type="button" class="mc-btn mc-btn--sm fd-btn fd-btn--ambar" data-fd-cert-recordar aria-pressed="false" disabled title="<?= $cert ? 'Ya está recordado hasta que cierres sesión' : 'Primero carga tu archivo .p12' ?>"><?= $ico('candado', 16) ?><span class="mc-btn__label">Recordar</span></button>
          <button type="submit" form="f-olvidar-cert" class="mc-btn mc-btn--sm fd-btn fd-btn--rojo"<?= $cert ? '' : ' disabled' ?>><?= $ico('eliminar-bote', 16) ?><span class="mc-btn__label">Olvidar</span></button>
        </div>
        <div class="mc-field"><label class="mc-field__label" for="fd-clave-cert">Contraseña</label>
          <div class="mc-input"><input class="mc-input__control" id="fd-clave-cert" type="password" name="clave_cert" autocomplete="off" placeholder="La de tu firma electrónica" data-fd-req="electronica"></div>
          <span class="mc-field__hint">La contraseña no se guarda. «Recordar» deja tu archivo cifrado hasta que cierres sesión.</span></div>
      </div>
      <p class="fd-error" data-fd-firmar-error role="alert" hidden></p>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Regresar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><span class="mc-btn__label"><?= $primeroYo ? 'Firmar y enviar' : 'Firmar' ?></span></button>
    </footer>
  </form>
  <form id="f-olvidar-cert" method="post" action="actions.php" hidden
        data-confirmar="Se borra tu archivo de firma electrónica de esta sesión. La próxima vez tendrás que cargarlo de nuevo." data-confirmar-titulo="¿Olvidar tu firma electrónica?" data-confirmar-ok="Sí, olvidar">
    <input type="hidden" name="accion" value="firma_cert_olvidar"><input type="hidden" name="volver" value="firma.php?id=<?= $id ?>">
  </form>
</dialog>

<!-- Observar: la carta que le llega, a la izquierda, y el motivo -->
<dialog class="ds-modal fd-dlg fd-obs-dlg" id="dlg-observar" aria-labelledby="dlg-obs-t">
  <form method="post" action="actions.php" class="ds fd-obs" data-fd-observar>
    <input type="hidden" name="accion" value="firma_doc_rechazar"><input type="hidden" name="id" value="<?= $id ?>">
    <article class="fd-carta" aria-label="Así le llega">
      <img class="fd-carta__logo" src="<?= e(faviconPanel()) ?>" alt="">
      <p class="fd-carta__saludo">Estimado/a</p>
      <p class="fd-carta__nombre"><?= e($creador['nombre']) ?></p>
      <hr>
      <div class="fd-carta__cuerpo" data-fd-carta><?= e($plantilla) ?></div>
      <p class="fd-carta__firma">Atentamente,<br><b><?= e($yo['nombre']) ?></b><?= $ft['cargo'] !== '' ? '<br>' . e($ft['cargo']) : '' ?></p>
    </article>
    <div class="fd-obs__lado">
      <header class="ds-modal__head">
        <span class="mc-tile mc-tile--sm mc-tile--naranja"><?= $ico('mensajes') ?></span>
        <div><h2 class="ds-modal__title" id="dlg-obs-t">¿Observar el documento?</h2>
          <p class="ds-modal__sub">Vuelve a quien lo elaboró para que lo corrija. Cuando lo reenvíe, todos firman de nuevo.</p></div>
        <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= $ico('cerrar') ?></button>
      </header>
      <div class="ds-modal__body">
        <div class="mc-field"><span class="mc-field__label">Se observará «<?= e($doc['titulo']) ?>» y se notificará a:</span>
          <div class="fd-persona"><?= UI::avatar($creador, 36) ?><span class="fd-persona__txt"><b class="truncate"><?= e($creador['nombre']) ?></b><small class="truncate"><?= e($cargoCreador) ?></small></span></div></div>
        <div class="mc-field"><label class="mc-field__label" for="fd-motivo">Motivo</label>
          <div class="mc-input mc-input--multiline"><textarea class="mc-input__control fd-motivo-txt" id="fd-motivo" name="motivo" rows="12" maxlength="4000" required data-fd-plantilla="<?= e($plantilla) ?>"><?= e($plantilla) ?></textarea></div>
          <span class="mc-field__hint">Le llega en la campana y por correo, como en la vista previa.</span></div>
        <p class="fd-error" data-fd-obs-error role="alert" hidden></p>
      </div>
      <footer class="ds-modal__foot">
        <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Regresar</span></button>
        <button class="mc-btn mc-btn--primary mc-btn--md"><span class="mc-btn__label">Observar</span></button>
      </footer>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($miTurno || !$tengoFirma) echo FirmasUI::modalMiFirma($yo, 'firma.php?id=' . $id); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" defer></script>
<script src="<?= asset('assets/firmas.js') ?>" defer></script>
<?php UI::fin(); ?>
