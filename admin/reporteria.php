<?php
/**
 * Reportería: el resumen ejecutivo del portafolio, con los datos de hoy, en
 * PowerPoint, Word o PDF. Solo lo que sale del panel; no se edita nada aquí.
 * Ver lib/Reporte.php.
 *
 * ?documento            la vista del documento sola (la carga el iframe de aquí)
 * ?documento&imprimir   lo mismo, y abre el cuadro de impresión → PDF
 *
 * Solo administrador: lleva el estado de todos los proyectos.
 */
require_once __DIR__ . '/lib/bootstrap.php';
// Fuera de bootstrap a propósito: si al subir por FTP falta alguno de estos
// archivos, falla solo Reportería y no el panel entero.
require_once __DIR__ . '/lib/Reporte.php';
Auth::requiereAdmin();

$rep = Reporte::desdePanel();

if (isset($_GET['documento'])) {
    $imprimir = isset($_GET['imprimir']);
    require __DIR__ . '/lib/reporte_documento.php';
    exit;
}

$R = $rep['resumen'];

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
  .rep-preview { width: 100%; height: 1200px; border: 0; border-radius: 12px; background: var(--n-bg, #e9ebf0); display: block; }
</style>

<div class="rep-resumen">
  <div class="card-base rep-kpi"><b><?= (int)$R['proyectos'] ?></b><span>Proyectos</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['entregables'] ?></b><span>Entregables</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['completados'] ?></b><span>Completados</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['en_curso'] ?></b><span>En curso</span></div>
  <div class="card-base rep-kpi"><b><?= (int)$R['cumplimiento'] ?>%</b><span>Cumplimiento</span></div>
</div>

<iframe class="rep-preview" id="rep-preview" src="reporteria.php?documento=1" title="Vista previa del reporte" loading="lazy"></iframe>

<form id="frm-rep-pptx" method="post" action="actions.php" data-descarga><input type="hidden" name="accion" value="reporte_pptx"></form>
<form id="frm-rep-docx" method="post" action="actions.php" data-descarga><input type="hidden" name="accion" value="reporte_docx"></form>

<script>
  // El iframe toma el alto de la hoja (lo avisa la propia vista del documento).
  window.addEventListener('message', (e) => {
    const ifr = document.getElementById('rep-preview');
    const alto = e.origin === location.origin && e.data ? Number(e.data.reporteAlto) : 0;
    if (!ifr || !(alto > 0)) return;
    if (Math.abs(ifr.offsetHeight - alto) > 2) ifr.style.height = alto + 'px';
  });
</script>
<?php UI::fin(); ?>
