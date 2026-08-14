<?php
/**
 * Catálogo de instituciones (solo administrador).
 *
 * Las instituciones a las que se asisten los requerimientos sueltos. Cada una
 * con su nombre y, opcionalmente, un logo. Un requerimiento puede ser para una
 * o varias, y de ahí salen las métricas por institución.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (!esAdmin()) {
    redirigir('index.php', 'Solo el administrador gestiona el catálogo de instituciones.', 'error');
}

$instRepo      = new InstitucionRepo();
$instituciones = $instRepo->todas();

// Cuántos requerimientos toca cada institución (para mostrarlo y de métrica)
$reqPorInst = [];
$totalReq   = 0;
foreach ((new RequerimientoRepo())->todos() as $r) {
    $suyas = RequerimientoRepo::institucionesDe($r);
    if ($suyas) $totalReq++;
    foreach ($suyas as $iid) {
        $reqPorInst[$iid] = ($reqPorInst[$iid] ?? 0) + 1;
    }
}

$aceptaImg = 'image/png,image/jpeg,image/webp,image/gif';

UI::inicio('Instituciones', 'instituciones');
UI::cabecera(
    'Catálogo de <span class="text-secondary">instituciones</span>',
    'Las instituciones que atienden los requerimientos sueltos. Cada una con su logo; con eso salen las métricas por institución.',
    '<a class="btn-outline btn-meca btn-azul" href="requerimientos.php"><i class="fa-solid fa-inbox"></i> Bandeja</a>
     <button class="btn-primary btn-meca" onclick="document.getElementById(\'dlg-inst-nueva\').showModal()">
       <i class="fa-solid fa-plus"></i> Nueva institución
     </button>'
);
?>

<?php if (!$instituciones): ?>
  <?= UI::vacio('fa-building-columns', 'Sin instituciones', 'Agrega la primera con «Nueva institución». Luego podrás asignarla a los requerimientos.') ?>
<?php else: ?>
<section class="inst-grid">
  <?php foreach ($instituciones as $i): $n = $reqPorInst[(int)$i['id']] ?? 0; ?>
  <article class="inst-card card-base">
    <div class="inst-logo">
      <?php if (!empty($i['imagen'])): ?>
        <img src="<?= e($i['imagen']) ?>" alt="">
      <?php else: ?>
        <i class="fa-solid fa-building-columns"></i>
      <?php endif; ?>
    </div>
    <div class="inst-datos">
      <b class="truncate"><?= e($i['nombre']) ?></b>
      <small><?= $n ?> requerimiento<?= $n === 1 ? '' : 's' ?></small>
    </div>
    <div class="inst-acciones">
      <button type="button" class="accion-btn" title="Editar"
        data-editar-inst='<?= e(json_encode(['id' => (int)$i['id'], 'nombre' => $i['nombre'] ?? '', 'imagen' => $i['imagen'] ?? '', 'color' => $i['color'] ?? 0], JSON_UNESCAPED_UNICODE)) ?>'>
        <i class="fa-solid fa-pen"></i>
      </button>
      <form method="post" action="actions.php" class="inline-form"
            data-confirmar="Se quitará «<?= e($i['nombre']) ?>» del catálogo. Los requerimientos ya registrados no se borran, solo dejan de mostrarla."
            data-confirmar-titulo="¿Quitar institución?" data-confirmar-ok="Sí, quitar">
        <input type="hidden" name="accion" value="institucion_eliminar">
        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
        <button class="accion-btn accion-peligro" title="Quitar"><i class="fa-solid fa-trash"></i></button>
      </form>
    </div>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- Modal: nueva institución -->
<dialog id="dlg-inst-nueva" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="institucion_crear">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-building-columns text-secondary"></i> Nueva institución</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="dlg-cuerpo">
      <label class="campo"><span>Nombre</span>
        <input class="input-meca" name="nombre" required maxlength="80" placeholder="Ej. Universidad Estatal de …">
      </label>
      <div class="campo">
        <span>Logo (opcional)</span>
        <?= UI::archivo(['name' => 'imagen', 'variante' => 'zona', 'accept' => $aceptaImg, 'ayuda' => 'PNG/JPG · máx 5 MB', 'maxMB' => 5]) ?>
        <small class="campo-ayuda">Se muestra en cada requerimiento de esta institución.</small>
      </div>
      <div class="campo">
        <span><i class="fa-solid fa-palette"></i> Color</span>
        <?= UI::colorPicker(count($instituciones) % count(Catalogo::COLORES)) ?>
        <small class="campo-ayuda">Identifica a la institución en los gráficos del panel.</small>
      </div>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cancelar</button>
      <button class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-check"></i> Agregar</button>
    </footer>
  </form>
</dialog>

<!-- Modal: editar institución (se rellena por JS) -->
<dialog id="dlg-inst-editar" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="institucion_editar">
    <input type="hidden" name="id" id="ie-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-pen text-secondary"></i> Editar institución</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="dlg-cuerpo">
      <label class="campo"><span>Nombre</span>
        <input class="input-meca" name="nombre" id="ie-nombre" required maxlength="80">
      </label>
      <div class="campo"><span>Logo actual</span>
        <div class="inst-logo inst-logo-prev"><img id="ie-img" alt="" hidden><i id="ie-noimg" class="fa-solid fa-building-columns"></i></div>
      </div>
      <div class="campo">
        <span>Cambiar logo (opcional)</span>
        <?= UI::archivo(['name' => 'imagen', 'variante' => 'zona', 'accept' => $aceptaImg, 'ayuda' => 'PNG/JPG · máx 5 MB', 'maxMB' => 5]) ?>
      </div>
      <div class="campo">
        <span><i class="fa-solid fa-palette"></i> Color</span>
        <?= UI::colorPicker(null) ?>
        <small class="campo-ayuda">Identifica a la institución en los gráficos del panel.</small>
      </div>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cancelar</button>
      <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar</button>
    </footer>
  </form>
</dialog>

<script>
document.querySelectorAll('[data-editar-inst]').forEach((btn) => {
  btn.addEventListener('click', () => {
    let d; try { d = JSON.parse(btn.dataset.editarInst); } catch (_) { return; }
    const dlg = document.getElementById('dlg-inst-editar');
    dlg.querySelector('#ie-id').value = d.id;
    dlg.querySelector('#ie-nombre').value = d.nombre || '';
    const img = dlg.querySelector('#ie-img'), noimg = dlg.querySelector('#ie-noimg');
    if (d.imagen) { img.src = d.imagen; img.hidden = false; noimg.hidden = true; }
    else { img.hidden = true; noimg.hidden = false; }
    // Color guardado (índice de la paleta o hex custom) → marcar en el picker
    const cp = dlg.querySelector('.color-picker');
    if (cp) {
      if (String(d.color).startsWith('#')) {
        const rc = cp.querySelector('input[value="custom"]'); if (rc) rc.checked = true;
        const ci = cp.querySelector('input[type="color"]'); if (ci) ci.value = d.color;
      } else {
        const radio = cp.querySelector('input[value="' + d.color + '"]');
        if (radio) { radio.checked = true; const mas = radio.closest('details'); if (mas) mas.open = true; }
      }
      cp.querySelector('input:checked')?.dispatchEvent(new Event('change', { bubbles: true }));
    }
    dlg.showModal();
  });
});
</script>

<?php UI::fin(); ?>
