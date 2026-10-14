<?php
/**
 * Reportería: el resumen ejecutivo del portafolio, con los datos de hoy, en
 * PowerPoint, Word o PDF. Ver lib/Reporte.php.
 *
 * ?documento        la vista del documento sola (la carga el iframe de aquí)
 * ?documento&imprimir   lo mismo, y abre el cuadro de impresión → PDF
 *
 * Solo administrador: lleva el estado de todos los proyectos.
 */
require_once __DIR__ . '/lib/bootstrap.php';
// Fuera de bootstrap a propósito: si al subir por FTP falta alguno de estos
// archivos, falla solo Reportería y no el panel entero.
require_once __DIR__ . '/lib/Reporte.php';
Auth::requiereAdmin();

$cfgRep = Reporte::config();

if (isset($_GET['documento'])) {
    $rep = Reporte::desdePanel($cfgRep);
    $imprimir = isset($_GET['imprimir']);
    require __DIR__ . '/lib/reporte_documento.php';
    exit;
}

$rep = Reporte::desdePanel($cfgRep);
// La configuración muestra TODOS los proyectos con tareas, también los que
// están fuera del reporte, para poder volver a meterlos.
$repTodos = $cfgRep['excluir'] ? Reporte::desdePanel(['excluir' => []] + $cfgRep) : $rep;
$todos = $repTodos['proyectos'];
usort($todos, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
$R = $rep['resumen'];
$tab = ($_GET['tab'] ?? '') === 'config' ? 'config' : 'vista';

UI::inicio('Reportería', 'reporteria');
UI::cabecera(
    'Reporte<span class="text-secondary">ría</span>',
    'El resumen ejecutivo del portafolio con los datos de hoy: se arma solo con las tareas del panel.',
    '<button class="btn-primary btn-meca" form="frm-rep-pptx">' . UI::icono('ChalkboardTeacher') . ' PowerPoint</button>'
  . '<button class="btn-outline btn-meca" form="frm-rep-docx">' . UI::icono('DocSuccess') . ' Word</button>'
  . '<a class="btn-outline btn-meca" href="reporteria.php?documento=1&amp;imprimir=1" target="_blank" rel="noopener">'
  . UI::icono('DocumentCloud') . ' PDF</a>'
);
?>
<style>
  .rep-resumen { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
  .rep-kpi { padding: 14px 16px; }
  .rep-kpi b { display: block; font-size: 1.7rem; line-height: 1.1; color: #150089; }
  html.dark .rep-kpi b { color: #fff; }
  .rep-kpi span { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: #16a34a; font-weight: 700; }
  .rep-tabs { display: flex; gap: 6px; margin: 4px 0 14px; flex-wrap: wrap; }
  .rep-tabs button { border: 1px solid rgba(127,140,170,.35); background: transparent; border-radius: 999px;
                     padding: 7px 16px; font: inherit; font-weight: 600; cursor: pointer; color: inherit; }
  .rep-tabs button[aria-selected="true"] { background: var(--c-tab-activo, #000080); border-color: transparent; color: #fff; }
  .rep-panel[hidden] { display: none !important; }
  .rep-preview { width: 100%; height: 1200px; border: 0; border-radius: 12px; background: var(--n-bg, #e9ebf0); display: block; }
  .rep-config { display: grid; gap: 16px; }
  .rep-proy { border: 1px solid rgba(127,140,170,.35); border-radius: 10px; margin: 0 0 10px; }
  .rep-proy > summary { cursor: pointer; padding: 10px 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-weight: 600; }
  .rep-proy > summary small { font-weight: 500; opacity: .75; }
  .rep-proy .rep-otras { margin-left: auto; font-size: .78rem; padding: 2px 9px; border-radius: 999px; background: #fdecec; color: #b4232f; }
  .rep-proy .rep-ok { margin-left: auto; font-size: .78rem; padding: 2px 9px; border-radius: 999px; background: #e3f8ec; color: #0d7340; }
  .rep-proy-cuerpo { padding: 0 14px 14px; display: grid; gap: 10px; }
  .rep-proy textarea { font-family: ui-monospace, Consolas, monospace; font-size: .82rem; min-height: 120px; }
  .rep-cuentas { display: flex; flex-wrap: wrap; gap: 6px; font-size: .8rem; }
  .rep-cuentas span { padding: 3px 9px; border-radius: 999px; background: rgba(127,140,170,.16); }
  .rep-cuentas span.cero { opacity: .55; }
  .rep-sueltas { font-size: .8rem; margin: 0; padding-left: 18px; max-height: 160px; overflow: auto; }
  .rep-sec { border: 1px solid rgba(127,140,170,.35); border-radius: 10px; padding: 12px 14px; display: grid; gap: 10px; }
  .rep-sec-cab { display: flex; justify-content: space-between; gap: 10px; align-items: center; flex-wrap: wrap; }
  .rep-sec textarea { font-size: .85rem; }
  .rep-incluir { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 4px 14px; }
  .rep-guardar { position: sticky; bottom: 0; padding: 12px 0; display: flex; gap: 10px; flex-wrap: wrap;
                 background: linear-gradient(transparent, var(--n-bg, #e5eaf3) 35%); }
</style>

<div class="rep-resumen">
  <div class="card-base rep-kpi"><b><?= (int)$R['proyectos'] ?></b><span>Proyectos</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['entregables'] ?></b><span>Entregables</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['completados'] ?></b><span>Completados</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['en_curso'] ?></b><span>En curso</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['cumplimiento'] ?>%</b><span>Cumplimiento</span></div>
</div>

<div class="rep-tabs" role="tablist">
  <button type="button" role="tab" data-tab="vista" aria-selected="<?= $tab === 'vista' ? 'true' : 'false' ?>">Vista previa</button>
  <button type="button" role="tab" data-tab="config" aria-selected="<?= $tab === 'config' ? 'true' : 'false' ?>">Configuración</button>
</div>

<section class="rep-panel" data-panel="vista" <?= $tab === 'vista' ? '' : 'hidden' ?>>
  <iframe class="rep-preview" id="rep-preview" src="reporteria.php?documento=1" title="Vista previa del reporte" loading="lazy"></iframe>
</section>

<section class="rep-panel" data-panel="config" <?= $tab === 'config' ? '' : 'hidden' ?>>
<form method="post" action="actions.php" class="rep-config" id="frm-rep-config">
  <input type="hidden" name="accion" value="reporte_guardar">

  <section class="card-base ajuste-card">
    <h2 class="font-display"><?= UI::icono('TrendingUp') ?> Portada y criterio</h2>
    <div class="campo-doble">
      <label class="campo">
        <span>Título</span>
        <input class="input-meca" name="titulo" maxlength="60" value="<?= e($cfgRep['titulo']) ?>">
      </label>
      <label class="campo">
        <span>Pie de la portada</span>
        <input class="input-meca" name="pie" maxlength="80" value="<?= e($cfgRep['pie']) ?>">
      </label>
    </div>
    <label class="campo">
      <span>Subtítulo</span>
      <input class="input-meca" name="subtitulo" maxlength="120" value="<?= e($cfgRep['subtitulo']) ?>">
    </label>
    <label class="chk-linea">
      <input type="checkbox" name="descontar_observadas" value="1" <?= $cfgRep['descontar_observadas'] ? 'checked' : '' ?>>
      <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
      Una tarea terminada con observaciones abiertas cuenta como <b>en curso</b>, no como completada
    </label>
    <p class="ajuste-ayuda">
      Es el mismo criterio del avance del panel (el control de calidad), así el reporte y el panel dan el mismo
      porcentaje. Si lo apagas, cuenta el estado de la tarea aunque tenga observaciones pendientes.
    </p>
    <span class="campo"><span>Proyectos en el reporte</span></span>
    <div class="rep-incluir">
      <?php foreach ($todos as $p): $fuera = in_array((int)$p['id'], $cfgRep['excluir'], true); ?>
      <label class="chk-linea">
        <input type="hidden" name="listados[]" value="<?= (int)$p['id'] ?>">
        <input type="checkbox" name="incluir[]" value="<?= (int)$p['id'] ?>" <?= $fuera ? '' : 'checked' ?>>
        <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
        <?= e($p['nombre']) ?> <small>(<?= (int)$p['entregables'] ?>)</small>
      </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card-base ajuste-card">
    <h2 class="font-display"><?= UI::icono('MenuBoard') ?> Líneas de trabajo por proyecto</h2>
    <p class="ajuste-ayuda">
      Una línea por renglón: <code>Nombre de la línea | clave, clave, clave</code>. Cada tarea va a la
      <b>primera</b> línea con alguna clave en su título, así que pon arriba las más específicas.
      Una clave tiene que empezar una palabra (<code>reporte</code> encuentra «reportes» y «reportería»); con
      <code>^</code> delante, el título tiene que empezar así (<code>^docencia</code>). Lo que no cae en ninguna va a
      «Otras actividades»: debajo se listan esas tareas para que sepas qué clave falta.
    </p>
    <?php foreach ($todos as $p):
        $texto = implode("\n", array_map(fn($l) => $l['nombre'] . ' | ' . $l['claves'], $p['lineas_conf'])); ?>
    <details class="rep-proy" <?= $p['otras'] > 0 ? 'open' : '' ?>>
      <summary>
        <?= e($p['nombre']) ?> <small><?= (int)$p['entregables'] ?> entregables · <?= count($p['lineas_conf']) ?> líneas</small>
        <?php if (!$p['lineas_conf']): ?>
          <span class="rep-otras">sin líneas</span>
        <?php elseif ($p['otras'] > 0): ?>
          <span class="rep-otras"><?= (int)$p['otras'] ?> sin clasificar</span>
        <?php else: ?>
          <span class="rep-ok">todo clasificado</span>
        <?php endif; ?>
      </summary>
      <div class="rep-proy-cuerpo">
        <textarea class="input-meca" name="lineas[<?= (int)$p['id'] ?>]" rows="<?= max(4, count($p['lineas_conf']) + 1) ?>"
                  spellcheck="false"><?= e($texto) ?></textarea>
        <?php if ($p['lineas_conf']): ?>
        <div class="rep-cuentas">
          <?php foreach ($p['lineas_conf'] as $l): ?>
            <span class="<?= $l['entregables'] ? '' : 'cero' ?>"><?= e($l['nombre']) ?>: <b><?= (int)$l['entregables'] ?></b></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($p['otras_titulos']): ?>
        <div>
          <small><b>Sin clasificar</b> (van a «Otras actividades»):</small>
          <ul class="rep-sueltas">
            <?php foreach ($p['otras_titulos'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </details>
    <?php endforeach; ?>
  </section>

  <section class="card-base ajuste-card">
    <h2 class="font-display"><?= UI::icono('DocumentFilter') ?> Secciones adicionales</h2>
    <p class="ajuste-ayuda">
      Lo que no sale de las tareas: el detalle de un release, una capacitación, un hito. Cada sección tiene su
      diapositiva y su bloque en el Word. En las filas, una fila por renglón con las celdas separadas por
      <code>|</code>; en las cifras, <code>valor | etiqueta</code> (hasta 5).
    </p>
    <div id="rep-secciones" style="display:grid;gap:12px">
      <?php foreach ($cfgRep['secciones'] as $i => $sRaw): $s = Reporte::seccionLimpia($sRaw); ?>
        <?php include __DIR__ . '/lib/reporte_seccion_form.php'; ?>
      <?php endforeach; ?>
    </div>
    <div><button type="button" class="btn-outline btn-meca" id="rep-sec-nueva"><i class="fa-solid fa-plus"></i> Agregar sección</button></div>
  </section>

  <section class="card-base ajuste-card">
    <h2 class="font-display"><?= UI::icono('ClipboardExport') ?> Conclusiones</h2>
    <p class="ajuste-ayuda">
      Las de las cifras (cumplimiento, proyectos cerrados, dónde se concentra el esfuerzo, el más rezagado,
      vencidos) se escriben solas en cada descarga. Aquí van las que quieras agregar, una por renglón.
    </p>
    <textarea class="input-meca" name="conclusiones_extra" rows="3"><?= e(implode("\n", $cfgRep['conclusiones_extra'])) ?></textarea>
  </section>

  <div class="rep-guardar">
    <button class="btn-primary btn-meca"><i class="fa-solid fa-floppy-disk"></i> Guardar configuración</button>
    <button type="submit" class="btn-outline btn-meca" form="frm-rep-restaurar" data-no-spin
            onclick="return confirm('¿Volver a la configuración de fábrica? Se pierden las líneas y secciones que hayas cambiado.')">
      Restaurar de fábrica
    </button>
  </div>
</form>
</section>

<form id="frm-rep-pptx" method="post" action="actions.php" data-descarga><input type="hidden" name="accion" value="reporte_pptx"></form>
<form id="frm-rep-docx" method="post" action="actions.php" data-descarga><input type="hidden" name="accion" value="reporte_docx"></form>
<form id="frm-rep-restaurar" method="post" action="actions.php"><input type="hidden" name="accion" value="reporte_restaurar"></form>

<template id="tpl-rep-sec">
  <?php $i = '__i__'; $s = Reporte::seccionLimpia(['id' => '', 'titulo' => '', 'ubicacion' => 'final']); $s['titulo'] = ''; ?>
  <?php include __DIR__ . '/lib/reporte_seccion_form.php'; ?>
</template>

<script>
(() => {
  const tabs = document.querySelectorAll('.rep-tabs [data-tab]');
  tabs.forEach((b) => b.addEventListener('click', () => {
    tabs.forEach((x) => x.setAttribute('aria-selected', x === b ? 'true' : 'false'));
    document.querySelectorAll('.rep-panel').forEach((p) => { p.hidden = p.dataset.panel !== b.dataset.tab; });
    history.replaceState(null, '', b.dataset.tab === 'config' ? '?tab=config' : location.pathname);
  }));

  // El iframe crece con su contenido (lo avisa la propia vista del documento).
  const ifr = document.getElementById('rep-preview');
  window.addEventListener('message', (e) => {
    if (e.origin === location.origin && e.data && e.data.reporteAlto && ifr) ifr.style.height = (e.data.reporteAlto + 24) + 'px';
  });

  // Secciones nuevas a partir de la plantilla.
  let n = document.querySelectorAll('#rep-secciones .rep-sec').length + 1000;
  document.getElementById('rep-sec-nueva').addEventListener('click', () => {
    const html = document.getElementById('tpl-rep-sec').innerHTML.replaceAll('__i__', String(n++));
    const caja = document.getElementById('rep-secciones');
    caja.insertAdjacentHTML('beforeend', html);
    caja.lastElementChild.querySelector('input[name$="[titulo]"]').focus();
  });
})();
</script>
<?php UI::fin(); ?>
