<?php
/**
 * Render de una tarjeta de observación. Reutilizado por proyecto.php
 * (listado inicial) y por la acción AJAX obs_crear (nuevas al vuelo).
 */
require_once __DIR__ . '/Models.php';

function obsItemHtml(array $o, bool $esRespuesta = false): string
{
    static $miembros = null, $equipos = null, $tareas = null, $reuniones = null;
    if ($miembros === null)  $miembros  = (new MiembroRepo())->mapa();
    if ($equipos === null)   $equipos   = Catalogo::equipos();
    if ($tareas === null)    $tareas    = new TareaRepo();
    if ($reuniones === null) $reuniones = new ReunionRepo();

    // 'autor_id' es la persona a la que va dirigida (es lo que se elige en el
    // compositor); quien la escribió va en 'creado_por' y se muestra al pie.
    $autor   = $miembros[(int)($o['autor_id'] ?? 0)] ?? null;
    $creador = $miembros[(int)($o['creado_por'] ?? 0)] ?? null;
    // A quién va dirigida: la lista entera. Las de antes de 'para' solo tienen
    // 'autor_id', así que se cae a esa para no dejarlas sin nombre.
    $paraIds = is_array($o['para'] ?? null) && $o['para'] ? $o['para'] : array_filter([(int)($o['autor_id'] ?? 0)]);
    $paraNombres = [];
    foreach ($paraIds as $pid2) {
        if (isset($miembros[(int)$pid2])) $paraNombres[] = $miembros[(int)$pid2]['nombre'];
    }
    $c1      = $autor ? Catalogo::colorDe($autor['color'] ?? 0) : '#64748b';
    $eqLabel = $equipos[$o['equipo'] ?? '']['0'] ?? 'Equipo';
    $eqIcono = $equipos[$o['equipo'] ?? '']['1'] ?? 'fa-user';
    $pend    = ($o['estado'] ?? 'pendiente') === 'pendiente';
    $tRef    = (int)($o['tarea_id'] ?? 0) ? $tareas->buscar((int)$o['tarea_id']) : null;
    $rRef    = (int)($o['reunion_id'] ?? 0) ? $reuniones->buscar((int)$o['reunion_id']) : null;
    $esRecord = ($o['tipo'] ?? 'nota') === 'recordatorio';
    // Nombres de los destinatarios (a quién se dirigió / avisó).
    $destNombres = [];
    foreach (ObservacionRepo::destinatariosDe($o) as $mid) {
        if (isset($miembros[$mid])) $destNombres[] = $miembros[$mid]['nombre'];
    }

    ob_start();
    ?>
    <article class="obs-item <?= $esRespuesta ? 'obs-respuesta' : '' ?> <?= $pend ? 'obs-pend' : 'obs-res' ?><?= $esRecord ? ' obs-record' : '' ?>" data-estado="<?= $pend ? 'pendiente' : 'resuelta' ?>" style="--av-c1:<?= $c1 ?>">
      <div class="obs-cabecera">
        <?= UI::avatar($autor, 40) ?>
        <div class="obs-autor">
          <b><?= e($paraNombres ? implode(', ', $paraNombres) : ($autor['nombre'] ?? 'Alguien')) ?></b>
          <span class="obs-meta">
            <?php if ($esRecord): ?><span class="obs-tipo-record"><i class="fa-solid fa-bell"></i> Recordatorio</span> · <?php endif; ?>
            <span class="obs-equipo"><i class="fa-solid <?= e($eqIcono) ?>"></i> <?= e($eqLabel) ?></span>
            · <?= e($o['creado'] ?? '') ?>
            <?php if ($creador): ?>
            · <span class="obs-creador" title="Quién anotó esta observación">
                <i class="fa-solid fa-pen"></i> la anotó <?= e($creador['nombre']) ?>
              </span>
            <?php endif; ?>
          </span>
        </div>
        <span class="obs-destino">
          <?php if ($tRef): ?>
            <i class="fa-regular fa-square-check"></i> <?= e(mb_strimwidth($tRef['titulo'], 0, 40, '…')) ?>
          <?php else: ?>
            <i class="fa-solid fa-layer-group"></i> General de la entrega
          <?php endif; ?>
        </span>
        <span class="obs-estado <?= $pend ? 'e-pend' : 'e-res' ?>">
          <i class="fa-solid <?= $pend ? 'fa-circle-dot' : 'fa-circle-check' ?>"></i>
          <?= $pend ? 'Pendiente' : 'Resuelta' ?>
        </span>
      </div>

      <?php if ($rRef): ?>
      <a class="obs-reunion" href="proyecto.php?id=<?= (int)$rRef['proyecto_id'] ?>#vista-reuniones" title="De la reunión">
        <i class="fa-solid fa-video"></i> <?= e(mb_strimwidth($rRef['topic'], 0, 40, '…')) ?>
      </a>
      <?php endif; ?>

      <?php if ($destNombres): ?>
      <p class="obs-para"><i class="fa-solid fa-bell"></i> Para: <?= e(implode(', ', $destNombres)) ?></p>
      <?php endif; ?>

      <?php if (!HtmlRico::vacio($o['texto'] ?? '')): ?><div class="obs-texto rt-render"><?= $o['texto'] ?></div><?php endif; ?>

      <?php if (!empty($o['adjuntos'])): ?>
      <div class="obs-adjuntos">
        <?php foreach ($o['adjuntos'] as $a): if (($a['tipo'] ?? '') === 'img'): ?>
        <a class="obs-img" href="<?= e($a['ruta']) ?>" target="_blank" rel="noopener" title="<?= e($a['nombre']) ?>">
          <img src="<?= e($a['ruta']) ?>" alt="<?= e($a['nombre']) ?>" loading="lazy">
        </a>
        <?php else: ?>
        <a class="obs-doc" href="<?= e($a['ruta']) ?>" target="_blank" rel="noopener" download>
          <i class="fa-solid <?= ($a['ext'] ?? '') === 'pdf' ? 'fa-file-pdf' : 'fa-file-word' ?>"></i>
          <span><?= e($a['nombre']) ?></span>
          <i class="fa-solid fa-download"></i>
        </a>
        <?php endif; endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="obs-acciones">
        <?php if (!$esRespuesta): ?>
        <button type="button" class="accion-btn obs-responder" data-obs="<?= (int)$o['id'] ?>">
          <i class="fa-solid fa-reply"></i> Responder
        </button>
        <?php endif; ?>
        <span class="obs-acc-admin solo-admin">
        <form method="post" action="actions.php" class="inline-form">
          <input type="hidden" name="accion" value="obs_estado">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="accion-btn <?= $pend ? '' : 'accion-hecho' ?>">
            <i class="fa-solid <?= $pend ? 'fa-check' : 'fa-rotate-left' ?>"></i>
            <?= $pend ? 'Marcar resuelta' : 'Reabrir' ?>
          </button>
        </form>
        <form method="post" action="actions.php" class="inline-form"
              data-confirmar="Se eliminará esta observación y sus adjuntos."
              data-confirmar-titulo="¿Eliminar observación?" data-confirmar-ok="Sí, eliminar">
          <input type="hidden" name="accion" value="obs_eliminar">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
        </form>
        </span>
      </div>

      <?php if (!$esRespuesta): ?>
      <!-- El hilo: las respuestas cuelgan con su sangría y su línea, y al final
           el cuadro para contestar (lo abre el botón de arriba). -->
      <div class="obs-hilo">
        <?php foreach ($o['respuestas'] ?? [] as $r) echo obsItemHtml($r, true); ?>
        <form class="obs-responder-caja" method="post" action="actions.php" hidden>
          <input type="hidden" name="accion" value="obs_crear">
          <input type="hidden" name="proyecto_id" value="<?= (int)$o['proyecto_id'] ?>">
          <input type="hidden" name="padre_id" value="<?= (int)$o['id'] ?>">
          <textarea name="texto" rows="2" class="input-meca" placeholder="Responder en este hilo…"></textarea>
          <div class="obs-responder-pie">
            <button type="button" class="btn-outline btn-meca btn-sm btn-neutro obs-responder-cancelar">Cancelar</button>
            <button class="btn-primary btn-meca btn-sm btn-agregar"><i class="fa-solid fa-paper-plane"></i> Responder</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </article>
    <?php
    return ob_get_clean();
}
