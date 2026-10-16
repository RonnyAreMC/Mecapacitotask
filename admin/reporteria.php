<?php
/**
 * Reportería: el resumen ejecutivo del portafolio, con los datos de hoy, en
 * PowerPoint, Word o PDF. Solo lo que sale del panel; no se edita nada aquí.
 * Ver lib/Reporte.php.
 *
 * ?documento            la vista del documento sola (la carga el iframe de aquí)
 * ?documento&imprimir   lo mismo, y abre el cuadro de impresión → PDF
 * ?p[]=3&p[]=8&req=0    solo esos proyectos / sin requerimientos sueltos
 *                       (lo elige el botón Configurar; no se guarda)
 *
 * Solo administrador: lleva el estado de todos los proyectos.
 */
require_once __DIR__ . '/lib/bootstrap.php';
// Fuera de bootstrap a propósito: si al subir por FTP falta alguno de estos
// archivos, falla solo Reportería y no el panel entero.
require_once __DIR__ . '/lib/Reporte.php';
Auth::requiereAdmin();

[$sel, $conReq] = Reporte::seleccion($_GET);
$rep = Reporte::desdePanel($sel, $conReq);

if (isset($_GET['documento'])) {
    $imprimir = isset($_GET['imprimir']);
    require __DIR__ . '/lib/reporte_documento.php';
    exit;
}

// El reporte completo, para la lista del modal (y para saber de cuántos sale).
$todo = ($sel === null && $conReq) ? $rep : Reporte::desdePanel();
$lista = $todo['proyectos'];
usort($lista, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
$filtrado = $rep['parcial'] || !$conReq;

$qs  = Reporte::consulta($rep['parcial'] ? $sel : null, $conReq);
$amp = $qs !== '' ? '&' . $qs : '';
$R   = $rep['resumen'];

UI::inicio('Reportería', 'reporteria');
UI::cabecera(
    'Reporte<span class="text-secondary">ría</span>',
    'El resumen ejecutivo del portafolio con los datos de hoy: se arma solo con las tareas del panel.',
    '<button type="button" class="btn-outline btn-meca" onclick="document.getElementById(\'dlg-rep-conf\').showModal()">'
  . UI::icono('SettingsGear') . ' Configurar</button>'
  . '<button class="btn-primary btn-meca" form="frm-rep-pptx">' . UI::icono('ChalkboardTeacher') . ' PowerPoint</button>'
  . '<button class="btn-outline btn-meca" form="frm-rep-docx">' . UI::icono('DocSuccess') . ' Word</button>'
  . '<a class="btn-outline btn-meca" href="reporteria.php?documento=1&amp;imprimir=1' . e($amp) . '" target="_blank" rel="noopener">'
  . UI::icono('DocumentCloud') . ' PDF</a>'
);
?>
<style>
  .rep-resumen { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
  .rep-kpi { padding: 14px 16px; }
  .rep-kpi b { display: block; font-size: 1.7rem; line-height: 1.1; color: #150089; }
  html.dark .rep-kpi b { color: #fff; }
  .rep-kpi span { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: #16a34a; font-weight: 700; }
  .rep-preview { width: 100%; height: 1200px; border: 0; border-radius: 12px; background: var(--n-bg, #e9ebf0); display: block; }
  .rep-filtro { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: -4px 0 14px; padding: 10px 14px;
                border-radius: 12px; font-size: .88rem; background: color-mix(in srgb, var(--c-secondary) 12%, var(--n-bg)); }
  .rep-filtro a { margin-left: auto; font-weight: 700; }
  .dlg-rep { width: min(640px, calc(100vw - 40px)); }
  .rep-atajos { display: flex; gap: 8px; }
  .rep-atajos button { border: 0; background: none; padding: 2px 4px; font: inherit; font-size: .82rem; font-weight: 700;
                       color: var(--c-secondary); cursor: pointer; }
  .rep-lista { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 6px 14px;
               max-height: 46vh; overflow: auto; padding: 2px; }
  .rep-lista small { opacity: .7; }
  .rep-aviso { color: var(--c-danger, #b4232f); font-size: .85rem; margin: 0; }
  .rep-aviso[hidden] { display: none; }
  .rep-sep { border: 0; border-top: 1px solid rgba(127,140,170,.3); margin: 2px 0; }
</style>

<?php if ($filtrado): ?>
<div class="rep-filtro">
  <i class="fa-solid fa-filter"></i>
  <span>
    <?php if ($rep['parcial']): ?>
      Reporte de <b><?= count($rep['proyectos']) ?> de <?= count($lista) ?> proyectos</b>:
      <?= e(count($rep['proyectos']) <= 3 ? Reporte::lista(array_map(fn($p) => $p['nombre'], $rep['proyectos'])) : 'los seleccionados') ?>
    <?php else: ?>
      Todos los proyectos
    <?php endif; ?>
    <?= $conReq ? '' : ' · sin requerimientos sueltos' ?>
  </span>
  <a href="reporteria.php">Ver el reporte completo</a>
</div>
<?php endif; ?>

<div class="rep-resumen">
  <div class="card-base rep-kpi"><b><?= (int)$R['proyectos'] ?></b><span><?= $R['proyectos'] === 1 ? 'Proyecto' : 'Proyectos' ?></span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['entregables'] ?></b><span>Entregables</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['completados'] ?></b><span>Completados</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['en_curso'] ?></b><span>En curso</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['cumplimiento'] ?>%</b><span>Cumplimiento</span></div>
</div>

<iframe class="rep-preview" id="rep-preview" src="reporteria.php?documento=1<?= e($amp) ?>" title="Vista previa del reporte" loading="lazy"></iframe>

<?php /* Las descargas llevan la misma selección que se está viendo. */ ?>
<?php foreach (['pptx' => 'reporte_pptx', 'docx' => 'reporte_docx'] as $ext => $acc): ?>
<form id="frm-rep-<?= $ext ?>" method="post" action="actions.php" data-descarga>
  <input type="hidden" name="accion" value="<?= $acc ?>">
  <?php if ($rep['parcial']): foreach ($sel as $id): ?><input type="hidden" name="p[]" value="<?= (int)$id ?>"><?php endforeach; endif; ?>
  <input type="hidden" name="req" value="<?= $conReq ? '1' : '0' ?>">
</form>
<?php endforeach; ?>

<dialog id="dlg-rep-conf" class="dlg-meca dlg-rep">
  <form class="dlg-form" method="get" action="reporteria.php" id="frm-rep-conf">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-sliders"></i> ¿Qué entra en el reporte?</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="rep-atajos">
      <span class="campo-ayuda" style="margin-right:auto">Proyectos</span>
      <button type="button" data-marcar="1">Todos</button>
      <button type="button" data-marcar="0">Ninguno</button>
    </div>
    <div class="rep-lista">
      <?php foreach ($lista as $p): $marcado = $sel === null || in_array((int)$p['id'], $sel, true); ?>
      <label class="chk-linea">
        <input type="checkbox" name="p[]" value="<?= (int)$p['id'] ?>" <?= $marcado ? 'checked' : '' ?>>
        <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
        <span><?= e($p['nombre']) ?> <small>· <?= (int)$p['entregables'] ?> · <?= (int)$p['avance'] ?>%</small></span>
      </label>
      <?php endforeach; ?>
    </div>
    <hr class="rep-sep">
    <input type="hidden" name="req" value="0">
    <label class="chk-linea">
      <input type="checkbox" name="req" value="1" <?= $conReq ? 'checked' : '' ?>>
      <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
      <span>Requerimientos sueltos <small>· <?= count($todo['requerimientos']['abiertos']) ?> abiertos</small></span>
    </label>
    <p class="rep-aviso" id="rep-aviso" hidden>Elige al menos un proyecto.</p>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Aplicar</button>
    </footer>
  </form>
</dialog>

<script>
(() => {
  // El iframe toma el alto de la hoja (lo avisa la propia vista del documento).
  window.addEventListener('message', (e) => {
    const ifr = document.getElementById('rep-preview');
    const alto = e.origin === location.origin && e.data ? Number(e.data.reporteAlto) : 0;
    if (!ifr || !(alto > 0)) return;
    if (Math.abs(ifr.offsetHeight - alto) > 2) ifr.style.height = alto + 'px';
  });

  const frm = document.getElementById('frm-rep-conf');
  const casillas = [...frm.querySelectorAll('input[name="p[]"]')];
  frm.querySelectorAll('[data-marcar]').forEach((b) => b.addEventListener('click', () => {
    casillas.forEach((c) => { c.checked = b.dataset.marcar === '1'; });
  }));
  frm.addEventListener('submit', (e) => {
    const marcadas = casillas.filter((c) => c.checked).length;
    document.getElementById('rep-aviso').hidden = marcadas > 0;
    if (!marcadas) { e.preventDefault(); return; }
    // Todos marcados = el reporte completo: la dirección queda limpia.
    if (marcadas === casillas.length) casillas.forEach((c) => { c.disabled = true; });
    if (frm.querySelector('input[type="checkbox"][name="req"]').checked) {
      frm.querySelectorAll('input[name="req"]').forEach((c) => { c.disabled = true; });
    }
  });
})();
</script>
<?php UI::fin(); ?>
