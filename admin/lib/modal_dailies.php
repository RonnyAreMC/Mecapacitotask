<?php
/**
 * Modal del horario de reuniones fijas ("dailies"), del dashboard.
 *
 * VER lo puede todo el mundo. AÑADIR y cambiar, solo quien LLEVA ese proyecto
 * —su Scrum Master y su Product Owner— más el administrador: eso lo deciden
 * $f['mia'] y $misProyectosScrum, que llegan calculados desde index.php.
 *
 * Se lee como una agenda: primero lo de HOY (con la siguiente destacada) y
 * luego el resto de la semana. Antes iba todo en una sola lista y había que
 * mirar el chip de cada fila para saber si tocaba hoy.
 */
$hoyNombre = Reuniones::DIAS[$hoyIso][1] ?? '';
$deHoy  = array_values(array_filter($fijas, fn($f) => $f['hoy']));
$deOtro = array_values(array_filter($fijas, fn($f) => !$f['hoy']));

/** Cuánto falta para la siguiente, en cristiano. */
$faltaPara = function (string $hora) use ($ahora): string {
    $min = (int)round((strtotime($hora) - strtotime($ahora)) / 60);
    if ($min <= 0)  return 'ahora mismo';
    if ($min < 60)  return 'en ' . $min . ' min';
    $h = intdiv($min, 60);
    $m = $min % 60;
    return 'en ' . $h . ' h' . ($m ? ' ' . $m . ' min' : '');
};
?>
<dialog id="dlg-dailies" class="dlg-meca dlg-dailies">
  <div class="dlg-form">
    <header>
      <div>
        <h3 class="font-display"><i class="fa-solid fa-mug-hot text-secondary"></i> Reuniones diarias</h3>
        <p class="ajuste-ayuda">
          <?php if (!$fijas): ?>
            Cada Scrum Master apunta aquí a qué hora se junta su equipo.
          <?php elseif ($siguiente): ?>
            Ahora son las <b><?= e($ahora) ?></b>.
          <?php else: ?>
            Son las <b><?= e($ahora) ?></b>: por hoy no queda ninguna.
          <?php endif; ?>
        </p>
      </div>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <?php if ($siguiente): ?>
    <!-- Lo único que se pregunta uno al abrir esto: qué toca ahora -->
    <div class="daily-proxima">
      <span class="dp-hora"><?= e($siguiente['r']['hora']) ?></span>
      <span class="dp-txt">
        <strong><?= e($siguiente['r']['titulo']) ?> · <?= e($siguiente['proyecto']['nombre']) ?></strong>
        <small>La siguiente, <?= e($faltaPara($siguiente['r']['hora'])) ?></small>
      </span>
    </div>
    <?php endif; ?>

    <?php
    // Un bloque de la agenda. $titulo va vacío cuando no hay nada que separar.
    $bloque = function (string $titulo, array $lista) use ($siguiente) {
        if (!$lista) return;
        ?>
        <h4 class="daily-grupo"><?= e($titulo) ?></h4>
        <ul class="daily-lista">
          <?php foreach ($lista as $f):
              $r  = $f['r'];
              $p  = $f['proyecto'];
              $es = $siguiente && (int)$siguiente['r']['id'] === (int)$r['id'];
          ?>
          <li class="daily-fila<?= $f['pasada'] ? ' daily-pasada' : '' ?><?= $es ? ' daily-siguiente' : '' ?>">
            <span class="daily-hora-txt"><?= e($r['hora']) ?></span>
            <span class="daily-icono" style="background:<?= e(ProyectoRepo::colorBase($p)) ?>">
              <i class="fa-solid <?= e($p['icono'] ?? 'fa-rocket') ?>"></i>
            </span>
            <span class="daily-datos">
              <strong class="truncate"><?= e($r['titulo']) ?></strong>
              <small class="truncate"><?= e($p['nombre']) ?> · <?= e(Reuniones::diasTexto($f['dias'])) ?></small>
            </span>
            <?php if ($f['pasada']): ?><span class="daily-chip">Ya pasó</span><?php endif; ?>
            <?php if ($f['mia']): ?>
            <!-- Solo el Scrum Master de ESE proyecto (y el admin) puede tocarla -->
            <span class="daily-acciones">
              <button type="button" class="accion-btn" title="Cambiar hora o días"
                      data-rfija-editar="<?= e(json_encode([
                          'id'     => (int)$r['id'],
                          'pid'    => (int)$r['proyecto_id'],
                          'titulo' => (string)$r['titulo'],
                          'hora'   => (string)$r['hora'],
                          'dias'   => $f['dias'],
                      ], JSON_UNESCAPED_UNICODE)) ?>"><i class="fa-solid fa-pen"></i></button>
              <form method="post" action="actions.php" class="inline-form"
                    data-confirmar="Se quitará «<?= e($r['titulo']) ?>» del horario." data-confirmar-titulo="¿Quitar?" data-confirmar-ok="Sí, quitar">
                <input type="hidden" name="accion" value="rfija_eliminar">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="accion-btn accion-peligro" title="Quitar del horario"><i class="fa-solid fa-trash"></i></button>
              </form>
            </span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php
    };
    $bloque('Hoy, ' . mb_strtolower($hoyNombre), $deHoy);
    $bloque('El resto de la semana', $deOtro);
    ?>

    <?php if (!$fijas): ?>
      <p class="daily-vacio"><i class="fa-solid fa-calendar-day"></i> El horario está vacío.</p>
    <?php endif; ?>

    <?php if ($misProyectosScrum): ?>
    <!-- Plegado por defecto: la mayoría de las veces esto se abre para MIRAR.
         Alta y edición comparten formulario; al editar, el JS lo rellena, lo
         despliega y cambia la acción. -->
    <details class="daily-alta" id="det-rfija">
      <summary><i class="fa-solid fa-plus"></i> Añadir una reunión</summary>
      <form method="post" action="actions.php" id="form-rfija">
        <input type="hidden" name="accion" value="rfija_crear" id="rf-accion">
        <input type="hidden" name="id" value="" id="rf-id">
        <div class="daily-alta-campos">
          <label class="campo">
            <span>Proyecto</span>
            <?= UI::select('proyecto_id', $misProyectosScrum, (string)array_key_first($misProyectosScrum), false, 'js-rf-proyecto') ?>
          </label>
          <label class="campo">
            <span>Reunión</span>
            <input class="input-meca" name="titulo" id="rf-titulo" maxlength="60" placeholder="Daily">
          </label>
          <label class="campo">
            <span>Hora</span>
            <input class="input-meca daily-hora" type="time" name="hora" id="rf-hora" required>
          </label>
        </div>
        <div class="daily-dias" id="rf-dias">
          <?php foreach (Reuniones::DIAS as $iso => [$corto, $largo]): ?>
          <label class="daily-dia" title="<?= e($largo) ?>">
            <input type="checkbox" name="dias[]" value="<?= $iso ?>"<?= $iso <= 5 ? ' checked' : '' ?>>
            <span><?= e($corto) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="daily-alta-pie">
          <button type="button" class="btn-outline btn-meca" id="rf-cancelar" hidden>Cancelar</button>
          <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> <span id="rf-guardar">Añadir</span></button>
        </div>
      </form>
    </details>
    <?php else: ?>
      <p class="daily-nota">
        <i class="fa-solid fa-circle-info"></i>
        El horario lo pone quien lleva cada proyecto: su Scrum Master o su Product Owner.
        Si falta el tuyo, pídeselo a ellos o al administrador.
      </p>
    <?php endif; ?>
  </div>
</dialog>
