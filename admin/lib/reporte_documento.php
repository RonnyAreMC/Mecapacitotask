<?php
/**
 * El reporte como página HTML con el formato del Word: es la vista previa de
 * Reportería (dentro de un iframe, para que el CSS del panel no la toque) y,
 * con ?imprimir, el PDF: el navegador lo guarda con "Guardar como PDF".
 *
 * Al imprimir, el logo y la banda institucional se repiten en cada hoja: van
 * con position:fixed (Chrome, Edge y Firefox los pintan en todas las páginas)
 * y el thead/tfoot vacíos de .rep-hoja reservan su hueco en cada una.
 *
 * Espera $rep (Reporte::construir) y $imprimir (bool).
 */
$R  = $rep['resumen'];
$rq = $rep['requerimientos'];
$n  = 0;

$tabla = function (array $cabeceras, array $filas): void {
    $larga = [];
    foreach ($cabeceras as $i => $_) {
        $larga[$i] = $i === 0;
        foreach ($filas as $f) {
            if (mb_strlen((string)($f[$i] ?? '')) > 24) { $larga[$i] = true; break; }
        }
    }
    echo '<table class="tab"><thead><tr>';
    foreach ($cabeceras as $i => $c) {
        echo '<th class="' . ($larga[$i] ? 'izq' : '') . '">' . e(mb_strtoupper((string)$c)) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($filas as $f) {
        $total = mb_strtoupper((string)($f[0] ?? '')) === 'TOTAL';
        echo '<tr' . ($total ? ' class="total"' : '') . '>';
        foreach ($cabeceras as $i => $_) {
            echo '<td class="' . ($larga[$i] ? 'izq' : '') . '">' . e((string)($f[$i] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
};

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($rep['titulo']) ?> · <?= e(date('Y-m-d')) ?></title>
<style>
  :root { --navy:#150089; --celeste:#349AFE; --verde:#21D866; --gris:#57575B; --texto:#202020; --cab:#EAF2FF; --alt:#F7F7F9; }
  * { box-sizing: border-box; }
  html { background: #e9ebf0; }
  body { margin: 0; font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif; color: var(--texto); font-size: 10.5pt; line-height: 1.32;
         -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .papel { width: 210mm; max-width: 100%; margin: 18px auto; background: #fff; box-shadow: 0 2px 14px rgba(20,20,60,.12);
           padding: 12mm 20mm 10mm 30mm; }
  .cab-logo { display: block; width: 66mm; max-width: 60%; margin: 0 0 12mm; }
  .pie-banda { display: block; width: 100%; margin-top: 14mm; }
  .espacio { height: 0; }
  table.rep-hoja { width: 100%; border-collapse: collapse; }
  table.rep-hoja > thead td, table.rep-hoja > tfoot td, table.rep-hoja > tbody > tr > td { padding: 0; }

  .portada { margin: 6mm 0 2mm; }
  .portada .tit { font-size: 28pt; font-weight: 700; color: var(--navy); margin: 0 0 1mm; line-height: 1.1; }
  .portada .sub { font-size: 13pt; font-weight: 700; color: var(--navy); margin: 0 0 3mm; }
  .portada .fecha { font-size: 10pt; color: var(--gris); margin: 0 0 1mm; }
  .portada .cifras { font-size: 13pt; font-weight: 700; color: var(--verde); margin: 1.5mm 0 0; }

  h2 { font-size: 14pt; color: var(--navy); text-transform: uppercase; margin: 6mm 0 2mm; padding-bottom: 1mm;
       border-bottom: 1.5pt solid var(--navy); break-after: avoid; page-break-after: avoid; }
  h3 { font-size: 11pt; color: var(--navy); text-transform: uppercase; margin: 4mm 0 1mm; break-after: avoid; page-break-after: avoid; }
  h4 { font-size: 10.5pt; color: var(--navy); margin: 4.5mm 0 .8mm; break-after: avoid; page-break-after: avoid; }
  p { margin: 1.4mm 0 1mm; text-align: justify; }
  .gris { color: var(--gris); }
  .chico { font-size: 9.5pt; }
  ul.concl { margin: 1mm 0; padding: 0; list-style: none; }
  ul.concl li { margin: .8mm 0; padding-left: 5mm; text-indent: -4mm; }
  ul.concl li::before { content: "•"; display: inline-block; width: 4mm; text-indent: 0; }

  table.tab { width: 100%; border-collapse: collapse; margin: 1mm 0 1.5mm; font-size: 9pt; }
  table.tab th, table.tab td { border: .5pt solid #4a4a4a; padding: .7mm 1.8mm; text-align: center; vertical-align: middle; }
  table.tab th { background: var(--cab); color: var(--navy); font-weight: 700; }
  table.tab .izq { text-align: left; }
  table.tab tbody tr:nth-child(even) td { background: var(--alt); }
  table.tab tr.total td { font-weight: 700; color: var(--navy); }
  table.tab tr { break-inside: avoid; page-break-inside: avoid; }

  .barra-imprimir { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; justify-content: center; align-items: center;
                    padding: 10px; background: var(--navy); color: #fff; font-size: 13px; }
  .barra-imprimir button { font: inherit; font-weight: 700; border: 0; border-radius: 6px; padding: 7px 16px;
                           background: var(--verde); color: var(--navy); cursor: pointer; }

  @media screen and (max-width: 760px) {
    .papel { padding: 8mm 6mm; margin: 0; box-shadow: none; overflow-x: auto; }
    table.tab { font-size: 8pt; }
    table.tab th, table.tab td { padding: .5mm 1mm; }
  }
  @media print {
    @page { size: A4; margin: 0; }
    html, body { background: #fff; }
    .barra-imprimir { display: none; }
    .papel { width: auto; margin: 0; padding: 0 20mm 0 30mm; box-shadow: none; }
    .cab-logo { position: fixed; top: 12.5mm; left: 30mm; width: 66mm; margin: 0; }
    .pie-banda { position: fixed; bottom: 9mm; left: 15mm; width: 180mm; margin: 0; }
    /* El hueco del logo y de la banda, repetido en cada hoja */
    table.rep-hoja > thead .espacio { height: 32mm; }
    table.rep-hoja > tfoot .espacio { height: 30mm; }
  }
</style>
</head>
<body>
<?php if ($imprimir): ?>
<div class="barra-imprimir">
  En el cuadro de impresión elige <b>«Guardar como PDF»</b>.
  <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>
<?php endif; ?>
<div class="papel">
  <img class="cab-logo" src="<?= Reporte::logoDataUri('color') ?>" alt="InnoTech Solutions">
  <table class="rep-hoja">
    <thead><tr><td><div class="espacio"></div></td></tr></thead>
    <tfoot><tr><td><div class="espacio"></div></td></tr></tfoot>
    <tbody><tr><td>

  <div class="portada">
    <p class="tit"><?= e(mb_strtoupper($rep['titulo'])) ?></p>
    <p class="sub"><?= e(mb_strtoupper($rep['subtitulo'])) ?></p>
    <p class="fecha">Fecha de corte: <?= e($rep['generado']) ?></p>
    <p class="cifras"><?= (int)$R['proyectos'] ?> proyectos &nbsp;·&nbsp; <?= (int)$R['entregables'] ?> entregables &nbsp;·&nbsp; <?= (int)$R['cumplimiento'] ?>% de cumplimiento</p>
  </div>

  <h2><?= ++$n ?>. Resumen ejecutivo</h2>
  <p>El portafolio comprende <?= (int)$R['proyectos'] ?> proyectos en desarrollo con <?= (int)$R['entregables'] ?> entregables registrados.
     El nivel de cumplimiento global es del <?= (int)$R['cumplimiento'] ?>%: <?= (int)$R['completados'] ?> completados,
     <?= (int)$R['en_curso'] ?> en ejecución o validación y <?= (int)$R['por_hacer'] ?> pendientes de iniciar.</p>
  <?php if ($R['observadas'] > 0): ?>
  <p>De los entregables en ejecución, <?= (int)$R['observadas'] ?> ya están terminados pero tienen observaciones abiertas:
     cuentan como en curso hasta que se resuelvan.</p>
  <?php endif; ?>
  <p>Se gestionan además <?= (int)$rq['total'] ?> requerimientos sueltos, de los cuales <?= count($rq['abiertos']) ?> siguen abiertos
     y <?= (int)$rq['vencidos'] ?> están vencidos.</p>

  <h2><?= ++$n ?>. Proyectos y volumen de trabajo</h2>
  <?php
  $filas = array_map(fn($p) => [$p['nombre'], $p['avance'] . '%', $p['entregables'], $p['completados'], $p['en_curso'], $p['por_hacer']], $rep['proyectos']);
  $filas[] = ['TOTAL', $R['cumplimiento'] . '%', $R['entregables'], $R['completados'], $R['en_curso'], $R['por_hacer']];
  $tabla(['Proyecto', 'Avance', 'Entregables', 'Completados', 'En curso', 'Por hacer'], $filas);
  ?>

  <h2><?= ++$n ?>. Líneas de trabajo por proyecto</h2>
  <p class="gris" style="font-size:10pt">Qué se está construyendo en cada proyecto, agrupado por área funcional, con el número de entregables que agrupa cada línea.</p>
  <?php foreach ($rep['proyectos'] as $p): ?>
    <h3><?= e(mb_strtoupper($p['nombre'])) ?> &nbsp;·&nbsp; <?= (int)$p['entregables'] ?> entregables</h3>
    <?php
    $tabla(['Línea de trabajo', 'Entregables', 'Completados', 'En curso', 'Por hacer'],
        array_map(fn($l) => [$l['nombre'], $l['entregables'], $l['completados'], $l['en_curso'], $l['por_hacer']], $p['lineas']));
    ?>
  <?php endforeach; ?>

  <h2><?= ++$n ?>. Requerimientos sueltos</h2>
  <p>Peticiones que no pertenecen a ningún proyecto: <?= (int)$rq['total'] ?> en total, <?= count($rq['abiertos']) ?> abiertas y <?= (int)$rq['vencidos'] ?> vencidas.</p>
  <?php
  if ($rq['abiertos']) {
      $tabla(['Requerimiento abierto', 'Entrega', 'Situación'],
          array_map(fn($q) => [$q['titulo'], $q['entrega'] ?: '—', $q['vencido'] ? 'Vencido' : 'En plazo'], $rq['abiertos']));
  }
  ?>

  <h2><?= ++$n ?>. Conclusiones</h2>
  <ul class="concl">
    <?php foreach ($rep['conclusiones'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
  </ul>

    </td></tr></tbody>
  </table>
  <?php $pie = Reporte::pieDataUri(); if ($pie): ?>
  <img class="pie-banda" src="<?= $pie ?>" alt="">
  <?php endif; ?>
</div>
<?php if ($imprimir): ?>
<script>
  // Se abre el cuadro de impresión solo, cuando ya cargaron los logos.
  window.addEventListener('load', () => setTimeout(() => window.print(), 350));
</script>
<?php else: ?>
<script>
  // Vista previa dentro del iframe de Reportería: avisa el alto de la HOJA
  // para que el iframe crezca y no haya doble barra de desplazamiento.
  // No sirve documentElement.scrollHeight: nunca es menor que la ventana, así
  // que en cuanto el iframe crecía, informaba el alto del propio iframe, este
  // volvía a crecer y la página se alargaba sin fin.
  (() => {
    const papel = document.querySelector('.papel');
    const avisar = () => parent.postMessage(
      { reporteAlto: Math.ceil(papel.getBoundingClientRect().bottom + window.scrollY + 18) }, location.origin);
    window.addEventListener('load', avisar);
    // Solo cuando cambia la hoja (p. ej. el ancho), no cuando cambia el iframe.
    if (window.ResizeObserver) new ResizeObserver(avisar).observe(papel);
  })();
</script>
<?php endif; ?>
</body>
</html>
