<?php
/**
 * Tira de despliegues: "¿ya están los cambios en el servidor de pruebas?".
 *
 * Lo que ve quien entra es una línea: a qué hora se subió, hace cuánto, quién
 * y cuántas tareas. Nada de a qué proyecto — la persona ya está en los suyos y
 * el listado de proyectos aquí solo llenaba la pantalla. El detalle (qué
 * proyectos tocó cada subida y con qué tareas) vive en el módulo.
 *
 * Quien sube los cambios ve además el botón: lo pulsa, marca a qué proyectos
 * afecta esa subida y listo. La fecha y la hora las pone el servidor.
 *
 * Espera:
 *   $dtFilas    resumenDeploys(...) — proyecto, último deploy y pendientes
 *   $dtMiembros mapa de miembros (para el autor)
 *   $dtVolver   'index' | 'deploys' — a dónde vuelve el botón de registrar
 */
$dtCfg    = configDeploys();
$dtPuede  = puedeDesplegar();
$dtUltimo = null;      // el deploy más reciente de los proyectos de esta persona
foreach ($dtFilas as $f) {
    if (!empty($f['ultimo'])) { $dtUltimo = $f['ultimo']; break; }
}
$dtPendTotal = array_sum(array_map(fn($f) => count($f['pendientes']), $dtFilas));
$dtAutor = $dtUltimo ? ($dtMiembros[(int)$dtUltimo['autor_id']] ?? null) : null;
$dtHoy   = $dtUltimo && substr((string)$dtUltimo['fecha'], 0, 10) === date('Y-m-d');

// A qué proyectos afecta la subida NO se pregunta aquí: lo dejó dicho el
// administrador en Ajustes → Despliegues. Quien sube los cambios pulsa una vez
// y se acabó; preguntárselo cada vez es la forma de que deje de marcarlo.
// Solo se nombran en el tooltip, para que el clic no sea a ciegas.
$dtAfectados = $dtPuede
    ? array_map(fn($p) => $p['nombre'], proyectosDeploys((new ProyectoRepo())->todos()))
    : [];
?>
<section class="dep-tira card-base<?= $dtUltimo ? '' : ' dep-tira-vacia' ?>">
  <div class="dep-tira-main">
    <span class="dep-pulso<?= $dtHoy ? ' dep-pulso-hoy' : '' ?>" aria-hidden="true">
      <?= UI::icono('Upload') ?>
    </span>
    <div class="dep-tira-txt">
      <small><?= e($dtCfg['entorno']) ?></small>
      <?php if ($dtUltimo): ?>
      <b class="font-display">Cambios subidos a las <?= e(substr($dtUltimo['fecha'], 11, 5)) ?></b>
      <span class="dep-sub">
        <span class="dep-hace"><?= e(DeployRepo::haceCuanto($dtUltimo['fecha'])) ?></span>
        <?php if ($dtAutor): ?> · por <?= e(explode(' ', trim($dtAutor['nombre']))[0]) ?><?php endif; ?>
        <?php $nT = count(DeployRepo::tareasDe($dtUltimo)); if ($nT): ?>
        · <?= $nT ?> tarea<?= $nT === 1 ? '' : 's' ?>
        <?php endif; ?>
        <?php if (trim((string)($dtUltimo['nota'] ?? '')) !== ''): ?>
        · <?= e($dtUltimo['nota']) ?>
        <?php endif; ?>
      </span>
      <?php endif; ?>
      <?php if (!$dtUltimo): ?>
      <b class="font-display">Todavía no se ha registrado ningún cambio</b>
      <span class="dep-sub">Cuando alguien suba al servidor, la hora aparece aquí.</span>
      <?php endif; ?>
    </div>
    <div class="dep-tira-fin">
      <?php if ($dtPendTotal): ?>
      <span class="dep-pend" title="Tareas completadas que ningún despliegue ha subido todavía">
        <?= UI::icono('Clock') ?> <b><?= $dtPendTotal ?></b> sin subir
      </span>
      <?php else: ?>
      <span class="dep-aldia" title="Todo lo completado ya está arriba">
        <?= UI::icono('CircleCheck') ?> Todo subido
      </span>
      <?php endif; ?>
      <?php if ($dtPuede): ?>
      <form method="post" action="actions.php" class="inline-form dep-form">
        <input type="hidden" name="accion" value="deploy_registrar">
        <input type="hidden" name="volver" value="<?= e($dtVolver) ?>">
        <button class="dep-btn" data-dep-btn
                title="Queda registrada la hora de ahora en <?= e($dtCfg['entorno']) ?><?= $dtAfectados ? ' · ' . e(implode(', ', $dtAfectados)) : '' ?>">
          <?= UI::icono('Upload') ?> <span>Registrar deploy</span>
        </button>
      </form>
      <?php endif; ?>
      <?php if ($dtVolver !== 'deploys'): ?>
      <a class="btn-outline btn-meca btn-sm btn-neutro" href="deploys.php">
        <?= UI::icono('Upload') ?> Despliegues
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>

