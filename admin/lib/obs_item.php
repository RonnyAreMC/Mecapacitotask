<?php
/**
 * Render de una tarjeta de observación. Reutilizado por proyecto.php
 * (listado inicial) y por la acción AJAX obs_crear (nuevas al vuelo).
 */
require_once __DIR__ . '/Models.php';

function obsItemHtml(array $o): string
{
    static $miembros = null, $equipos = null, $tareas = null;
    if ($miembros === null) $miembros = (new MiembroRepo())->mapa();
    if ($equipos === null)  $equipos  = Catalogo::equipos();
    if ($tareas === null)   $tareas   = new TareaRepo();

    $autor   = $miembros[(int)($o['autor_id'] ?? 0)] ?? null;
    $c1      = $autor ? Catalogo::colorDe($autor['color'] ?? 0) : '#64748b';
    $eqLabel = $equipos[$o['equipo'] ?? '']['0'] ?? 'Equipo';
    $eqIcono = $equipos[$o['equipo'] ?? '']['1'] ?? 'fa-user';
    $pend    = ($o['estado'] ?? 'pendiente') === 'pendiente';
    $tRef    = (int)($o['tarea_id'] ?? 0) ? $tareas->buscar((int)$o['tarea_id']) : null;

    ob_start();
    ?>
    <article class="obs-item <?= $pend ? 'obs-pend' : 'obs-res' ?>" data-estado="<?= $pend ? 'pendiente' : 'resuelta' ?>" style="--av-c1:<?= $c1 ?>">
      <div class="obs-cabecera">
        <?= UI::avatar($autor, 40) ?>
        <div class="obs-autor">
          <b><?= e($autor['nombre'] ?? 'Alguien') ?></b>
          <span class="obs-meta">
            <span class="obs-equipo"><i class="fa-solid <?= e($eqIcono) ?>"></i> <?= e($eqLabel) ?></span>
            · <?= e($o['creado'] ?? '') ?>
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

      <?php if (!empty($o['texto'])): ?><p class="obs-texto"><?= nl2br(e($o['texto'])) ?></p><?php endif; ?>

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
      </div>
    </article>
    <?php
    return ob_get_clean();
}
