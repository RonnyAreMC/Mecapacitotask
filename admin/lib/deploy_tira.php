<?php
/**
 * Tira de despliegues: "¿ya están los cambios en el servidor de pruebas?".
 *
 * Lo que ve quien entra: a qué hora se subió el último cambio (en grande) y
 * cuándo suben los demás, con la cuenta regresiva. Nada de a qué proyecto — la
 * persona ya está en los suyos. El detalle (qué proyectos tocó cada subida y
 * con qué tareas) vive en el módulo; aquí se llega con los botones de ícono.
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
<?php
// Lo que importa, en grande: a qué hora subió el último cambio y cuándo suben
// los demás (la próxima hora límite, con su cuenta regresiva). Lo demás son
// botones de ícono con su ayuda al pasar el mouse.
if (is_file(__DIR__ . '/Cronograma.php')) require_once __DIR__ . '/Cronograma.php';
$dtTs = $dtUltimo ? (strtotime((string)$dtUltimo['fecha']) ?: 0) : 0;
$dtHora = fn(int $t) => class_exists('Cronograma') ? Cronograma::hora($t) : date('g:i a', $t);
$dtDia  = fn(int $t) => class_exists('Cronograma') ? Cronograma::diaCorto($t) : date('d/m', $t);
// La próxima hora límite de los proyectos de quien mira (en Despliegues no: allí está el horario completo)
$dtProx = 0;
if ($dtVolver !== 'deploys' && class_exists('Cronograma') && Cronograma::activo()) {
    foreach (array_keys($dtFilas) as $dtPid) {
        $dtV = Cronograma::proxima((int)$dtPid);
        if ($dtV > 0 && ($dtProx === 0 || $dtV < $dtProx)) $dtProx = $dtV;
    }
}
?>
<?php ob_start(); ?>
  <div class="dep-tira-main<?= $dtUltimo ? '' : ' dep-tira-vacia' ?>">
    <div class="dep-dato">
      <span class="dep-pulso<?= $dtHoy ? ' dep-pulso-hoy' : '' ?>" aria-hidden="true"><?= UI::icono('Upload') ?></span>
      <div class="dep-dato__txt">
        <small>Último cambio en <?= e($dtCfg['entorno']) ?></small>
        <?php if ($dtTs): ?>
        <b class="dep-hora"><?= e($dtHora($dtTs)) ?></b>
        <span class="dep-sub"><?= e(time() - $dtTs > 6 * 86400 ? date('d/m/Y', $dtTs) : $dtDia($dtTs)) ?><?php if ($dtAutor): ?> · <?= e(explode(' ', trim($dtAutor['nombre']))[0]) ?><?php endif; ?></span>
        <?php else: ?>
        <b class="dep-hora dep-hora--vacia">Sin cambios todavía</b>
        <span class="dep-sub">La hora sale aquí cuando alguien suba</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($dtProx): ?>
    <div class="dep-dato dep-dato--prox">
      <span class="dep-pulso dep-pulso--prox" aria-hidden="true"><?= UI::icono('Clock') ?></span>
      <div class="dep-dato__txt">
        <small>Los demás cambios suben</small>
        <b class="dep-hora"><?= e(class_exists('Cronograma') ? Cronograma::diaLargo($dtProx) : $dtDia($dtProx)) ?> · <?= e($dtHora($dtProx)) ?></b>
        <span class="dep-sub"><b data-cuenta-regresiva data-corto data-hasta="<?= $dtProx ?>" data-ahora="<?= time() ?>">&nbsp;</b><?php if ($dtPendTotal): ?> · <?= $dtPendTotal ?> tarea<?= $dtPendTotal === 1 ? '' : 's' ?> por subir<?php endif; ?></span>
      </div>
    </div>
    <?php endif; ?>
    <div class="dep-acc">
      <?php if ($dtPuede): ?>
      <button type="button" class="dep-ico dep-ico--subir" aria-haspopup="dialog" aria-label="Registrar deploy"
              data-ds-tip="Registrar deploy: marca qué subiste a <?= e($dtCfg['entorno']) ?>"
              onclick="document.getElementById('dlg-dep-registrar').showModal()"><?= UI::icono('Upload') ?></button>
      <?php endif; ?>
      <?php if ($dtVolver !== 'deploys'): ?>
      <a class="dep-ico" href="deploys.php" aria-label="Despliegues" data-ds-tip="Todos los despliegues y el horario"><?= UI::icono('RotateLeft') ?></a>
      <?php endif; ?>
      <?php if ($dtCfg['url'] !== ''): ?>
      <a class="dep-ico" href="<?= e($dtCfg['url']) ?>" target="_blank" rel="noopener" aria-label="Abrir <?= e($dtCfg['entorno']) ?>" data-ds-tip="Abrir <?= e($dtCfg['entorno']) ?>"><?= UI::icono('Export01') ?></a>
      <?php endif; ?>
    </div>
  </div>
<?php
$dtMain = ob_get_clean();
// En el dashboard es una diapositiva más del resumen (lib/resumen_tira.php);
// en Despliegues va sola, como siempre.
if (!empty($dtCarrusel)) {
    $rtSlides[] = ['clave' => 'deploys', 'nombre' => 'Despliegues', 'main' => $dtMain];
} else {
    echo '<section class="dep-tira card-base">' . $dtMain . '</section>';
}
?>


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
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><?= UI::icono('ds-cerrar') ?></button>
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
      <?php /* Plegado: el caso normal sigue siendo un clic. */ ?>
      <details class="dep-reg-notas">
        <summary>Notas de la versión (opcional)</summary>
        <textarea class="input-meca" name="nota" rows="5" maxlength="1500" placeholder="Qué cambió en esta subida…"></textarea>
        <?php if (UI::ia('deploy_notas')): ?>
        <div class="ia-acciones"><?= UI::botonIA('Redactar con IA', ['ia-deploy' => 1], 'Notas a partir de las tareas que se lleva la subida') ?></div>
        <?php endif; ?>
      </details>
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
