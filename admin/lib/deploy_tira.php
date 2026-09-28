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

// A qué proyectos afecta la subida se elige AL REGISTRAR, en el modal, con
// todo lo configurado ya marcado: quien sube el lote entero confirma y ya,
// y quien sube un solo microservicio desmarca el resto. Antes se registraba
// en todos a ciegas, y con varios proyectos en el mismo servidor eso daba por
// subido lo que nadie había tocado.
//
// Cada uno se nombra por su ALIAS ("ms-academico"): el nombre del proyecto en
// el panel ("Equipo Delta") no es el que ve quien lo sube.
$dtAfectados = $dtPuede
    ? proyectosDeploys((new ProyectoRepo())->todos())
    : [];
// Cuántas tareas espera cada proyecto, para decirlo dentro del modal. Se
// cuentan sobre $dtAfectados y no sobre $dtFilas: en el dashboard $dtFilas
// trae solo los proyectos de quien mira, y un configurado que no fuera suyo
// habría salido con un "0 tareas" que es mentira.
$dtPendPorProy = [];
foreach (resumenDeploys($dtAfectados) as $dtPid => $dtF) {
    $dtPendPorProy[(int)$dtPid] = count($dtF['pendientes']);
}
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
      <button type="button" class="dep-btn" aria-haspopup="dialog"
              onclick="document.getElementById('dlg-dep-registrar').showModal()"
              title="Elige qué subiste y queda registrada la hora de ahora en <?= e($dtCfg['entorno']) ?>">
        <?= UI::icono('Upload') ?> <span>Registrar deploy</span>
      </button>
      <?php endif; ?>
      <?php if ($dtVolver !== 'deploys'): ?>
      <a class="btn-outline btn-meca btn-sm btn-neutro" href="deploys.php">
        <?= UI::icono('Upload') ?> Despliegues
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>


<?php if ($dtPuede): ?>
<!-- Qué se subió. Marcado por defecto todo lo configurado: el caso normal
     sigue siendo un clic ("Registrar"), y quien subió solo una cosa desmarca
     el resto. Cada línea dice su alias y cuántas tareas se llevaría. -->
<dialog id="dlg-dep-registrar" class="dlg-meca dlg-dep-reg">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="deploy_registrar">
    <input type="hidden" name="volver" value="<?= e($dtVolver) ?>">
    <!-- Marca que la lista viene de aquí: sin esto, desmarcar todo se
         confundiría con "no se preguntó" y se registraría en todos. -->
    <input type="hidden" name="elegidos" value="1">
    <header>
      <h3 class="font-display">
        <?= UI::icono('Upload') ?> ¿Qué subiste a <?= e($dtCfg['entorno']) ?>?
      </h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="dlg-cuerpo">
      <?php if (!$dtAfectados): ?>
      <p class="cs-vacio">No hay proyectos configurados para despliegues. Elígelos en Ajustes → Despliegues.</p>
      <?php else: ?>
      <p class="campo-ayuda">
        Se marca la hora de ahora y la subida se lleva las tareas ya completadas que ningún
        despliegue anterior había subido. Desmarca lo que no hayas subido.
      </p>
      <div class="cs-lista">
        <?php foreach ($dtAfectados as $pDep): $pidDep = (int)$pDep['id'];
              $aliasDep = aliasDeploy($pidDep);
              $nPendDep = $dtPendPorProy[$pidDep] ?? 0; ?>
        <label class="cs-item dep-reg-item">
          <input type="checkbox" name="proyectos[]" value="<?= $pidDep ?>" checked>
          <?= UI::icono($pDep['icono'] ?? 'FolderOpen') ?>
          <span class="dep-reg-txt">
            <b class="truncate"><?= e($aliasDep ?: $pDep['nombre']) ?></b>
            <?php if ($aliasDep): ?><small class="truncate"><?= e($pDep['nombre']) ?></small><?php endif; ?>
          </span>
          <span class="dep-reg-n<?= $nPendDep ? '' : ' dep-reg-n-cero' ?>">
            <?= $nPendDep ?> tarea<?= $nPendDep === 1 ? '' : 's' ?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cancelar</button>
      <?php if ($dtAfectados): ?>
      <button class="btn-primary btn-meca btn-agregar" data-dep-btn>
        <?= UI::icono('Upload') ?> Registrar subida
      </button>
      <?php endif; ?>
    </footer>
  </form>
</dialog>
<?php endif; ?>
