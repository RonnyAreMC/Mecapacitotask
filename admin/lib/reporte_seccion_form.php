<?php
/**
 * Una sección adicional del reporte en el formulario de Reportería.
 * Espera $i (índice o '__i__' en la plantilla), $s (Reporte::seccionLimpia)
 * y $todos (proyectos del reporte, para "dentro del proyecto...").
 */
$nom   = fn(string $campo) => 'sec[' . $i . '][' . $campo . ']';
$celdas = fn(array $filas) => implode("\n", array_map(fn($f) => implode(' | ', (array)$f), $filas));
// La de la semilla nombra el proyecto; la guardada, su id.
$ubicSel = $s['ubicacion'];
if ($s['ubicacion'] === 'proyecto') {
    $pidSel = $s['proyecto_id'];
    if (!$pidSel) {
        foreach ($todos as $p) {
            if (Reporte::clave($p['nombre']) === Reporte::clave($s['proyecto'])) { $pidSel = (int)$p['id']; break; }
        }
    }
    $ubicSel = $pidSel ? 'p:' . $pidSel : 'final';
}
?>
<div class="rep-sec">
  <input type="hidden" name="<?= $nom('id') ?>" value="<?= e($s['id']) ?>">
  <div class="rep-sec-cab">
    <b><?= $s['titulo'] !== '' ? e($s['titulo']) : 'Sección nueva' ?></b>
    <label class="chk-linea" style="margin:0">
      <input type="checkbox" name="<?= $nom('quitar') ?>" value="1">
      <span class="chk-caja"><i class="fa-solid fa-check"></i></span> Quitar
    </label>
  </div>
  <div class="campo-doble">
    <label class="campo"><span>Título</span>
      <input class="input-meca" name="<?= $nom('titulo') ?>" maxlength="120" value="<?= e($s['titulo']) ?>"></label>
    <label class="campo"><span>Antetítulo (opcional)</span>
      <input class="input-meca" name="<?= $nom('kicker') ?>" maxlength="80" value="<?= e($s['kicker']) ?>"
             placeholder="Ej.: Requerimiento #19 · cerrado"></label>
  </div>
  <label class="campo"><span>Dónde va</span>
    <select class="input-meca" name="<?= $nom('donde') ?>">
      <?php foreach ($todos as $p): ?>
        <option value="p:<?= (int)$p['id'] ?>" <?= $ubicSel === 'p:' . $p['id'] ? 'selected' : '' ?>>Dentro del proyecto <?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="requerimientos" <?= $ubicSel === 'requerimientos' ? 'selected' : '' ?>>Después de los requerimientos sueltos</option>
      <option value="final" <?= $ubicSel === 'final' ? 'selected' : '' ?>>Al final, antes de las conclusiones</option>
    </select>
  </label>
  <label class="campo"><span>Introducción</span>
    <textarea class="input-meca" name="<?= $nom('intro') ?>" rows="2"><?= e($s['intro']) ?></textarea></label>
  <div class="campo-doble">
    <label class="campo"><span>Cifras destacadas (valor | etiqueta)</span>
      <textarea class="input-meca" name="<?= $nom('kpis') ?>" rows="3" placeholder="Ej.: 100 | Docentes"><?= e($celdas($s['kpis'])) ?></textarea></label>
    <label class="campo"><span>Columnas de la tabla</span>
      <input class="input-meca" name="<?= $nom('columnas') ?>" value="<?= e(implode(' | ', $s['columnas'])) ?>" placeholder="Aspecto | Resultado"></label>
  </div>
  <label class="campo"><span>Filas de la tabla (una por renglón, celdas con |)</span>
    <textarea class="input-meca" name="<?= $nom('filas') ?>" rows="<?= max(3, min(12, count($s['filas']) + 1)) ?>"><?= e($celdas($s['filas'])) ?></textarea></label>
  <label class="campo"><span>Nota al pie (opcional)</span>
    <textarea class="input-meca" name="<?= $nom('nota') ?>" rows="2"><?= e($s['nota']) ?></textarea></label>
</div>
