<?php
/**
 * Despliegues: cuándo se subieron los cambios al servidor de pruebas.
 *
 * El equipo terminaba tareas y el Product Owner no sabía cuándo podía entrar a
 * probarlas. Aquí el encargado pulsa "Cambios subidos" —un botón, nada más: la
 * fecha y la hora las pone el servidor— y queda el registro con las tareas que
 * ese despliegue se llevó.
 *
 * Quién entra aquí lo decide el administrador en Ajustes → Despliegues.
 */
require_once __DIR__ . '/lib/bootstrap.php';
exigirDeploys();

$cfgDep    = configDeploys();
$puedeDep  = puedeDesplegar();
$miembros  = (new MiembroRepo())->mapa();
// Solo los proyectos que el administrador puso a seguir (y que esta persona
// puede ver). Vacío en Ajustes = todos.
$proyectos = proyectosDeploys((new ProyectoRepo())->todos());
$mapaProy  = [];
foreach ($proyectos as $p) {
    $mapaProy[(int)$p['id']] = $p;
}

$deploysRepo = new DeployRepo();
$tareasRepo  = new TareaRepo();

// Filtro por proyecto (?p=N). El historial de un proyecto suelto es lo que se
// mira cuando alguien pregunta "¿y lo de SIGE cuándo subió?".
$fProy = (int)($_GET['p'] ?? 0);
if ($fProy && !isset($mapaProy[$fProy])) $fProy = 0;

$filas = resumenDeploys($proyectos);

// Historial: solo de los proyectos que esta persona puede ver
$historial = [];
foreach ($deploysRepo->todos() as $d) {
    $pids = DeployRepo::proyectosDe($d);
    // Se ve la subida si tocó algún proyecto de los suyos
    if (!array_intersect($pids, array_keys($mapaProy))) continue;
    if ($fProy && !in_array($fProy, $pids, true)) continue;
    $historial[] = $d;
}

// Títulos de las tareas que subió cada despliegue (para la lista desplegable)
$tituloTarea = [];
foreach ($tareasRepo->todas() as $t) {
    $tituloTarea[(int)$t['id']] = $t;
}

UI::inicio('Despliegues', 'deploys');
UI::cabecera(
    'Despliegues <span class="text-secondary">' . e($cfgDep['entorno']) . '</span>',
    $puedeDep
        ? 'Cuando subas los cambios, pulsa <b>Cambios subidos</b>: la fecha y la hora se guardan solas.'
        : 'Aquí ves a qué hora quedaron los últimos cambios en el servidor de pruebas.',
    $fProy ? '<a class="btn-outline btn-meca btn-neutro" href="deploys.php">'
             . UI::icono('Close') . ' Ver todos los proyectos</a>' : ''
);

$dtFilas    = $filas;
$dtMiembros = $miembros;
$dtVolver   = 'deploys';
require __DIR__ . '/lib/deploy_tira.php';

// El horario de subidas (lib/Cronograma.php): a qué hora sube cada proyecto, la cuenta
// regresiva a la próxima y los builds de GitLab que la esperan
$crono = false;
if (is_file(__DIR__ . '/lib/Cronograma.php')) {
    require_once __DIR__ . '/lib/Cronograma.php';
    $crono = Cronograma::activo();
}
if ($crono):
    $agenda = [];
    foreach (Cronograma::agenda() as $fA) {
        $fA['proyectos'] = array_values(array_filter($fA['proyectos'], fn($x) => isset($mapaProy[$x['id']])));
        if ($fA['proyectos']) $agenda[] = $fA;
    }
    $colaV = array_values(array_filter(Cronograma::enCola(), fn($b) => isset($mapaProy[(int)$b['proyecto_id']])));
    $primera = $agenda[0] ?? null;
    $nombreP = fn(array $x) => $x['alias'] !== '' ? $x['alias'] : $x['nombre'];
?>
<section class="card-base dep-crono">
  <div class="dep-crono-cab">
    <span class="dep-crono-ico" aria-hidden="true"><?= UI::icono('Clock') ?></span>
    <div class="dep-crono-tit">
      <h2 class="font-display">Horario de subidas</h2>
      <p>Los cambios suben <?= e(Cronograma::dias()) ?> a la hora de cada proyecto. Los builds que terminan bien en GitLab esperan esa hora y suben solos.</p>
    </div>
    <?php if (esAdmin()): ?>
    <a class="btn-outline btn-meca btn-sm btn-neutro" href="ajustes.php#tab-deploys"><?= UI::icono('Settings') ?> Configurar</a>
    <?php endif; ?>
  </div>

  <?php if ($primera): ?>
  <div class="dep-cuenta" data-cuenta-regresiva data-hasta="<?= (int)$primera['proxima'] ?>" data-ahora="<?= time() ?>">
    <small>Próxima subida</small>
    <b class="dep-cuenta__reloj font-display" data-cuenta-reloj>&nbsp;</b>
    <span class="dep-cuenta__cuando"><?= e(ucfirst(Cronograma::texto((int)$primera['proxima']))) ?> ·
      <?= e(implode(', ', array_map($nombreP, $primera['proyectos']))) ?>
      <?php if ($primera['cola']): ?> · <b><?= (int)$primera['cola'] ?> build<?= $primera['cola'] === 1 ? '' : 's' ?></b> en cola<?php endif; ?>
    </span>
  </div>
  <?php endif; ?>

  <ul class="dep-crono-lista">
    <?php foreach ($agenda as $fA): ?>
    <li class="dep-crono-fila">
      <span class="dep-crono-hora font-display"><?= e(Cronograma::horaTexto($fA['hora'])) ?></span>
      <span class="dep-crono-proys">
        <?php foreach ($fA['proyectos'] as $x): ?>
        <span class="dep-crono-proy" style="--pc:<?= e(ProyectoRepo::colorBase($x['p'])) ?>" title="<?= e($x['nombre']) ?>">
          <?= e($nombreP($x)) ?><?php if ($x['cola']): ?> <b><?= (int)$x['cola'] ?></b><?php endif; ?>
        </span>
        <?php endforeach; ?>
      </span>
      <span class="dep-crono-cuando"><?= e(Cronograma::texto((int)$fA['proxima'])) ?> ·
        <b data-cuenta-regresiva data-corto data-hasta="<?= (int)$fA['proxima'] ?>" data-ahora="<?= time() ?>">&nbsp;</b></span>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($colaV): ?>
  <details class="dep-crono-builds" open>
    <summary>En cola para subir <span class="tabla-count"><?= count($colaV) ?></span></summary>
    <ul>
      <?php foreach ($colaV as $b): $pB = $mapaProy[(int)$b['proyecto_id']]; ?>
      <li>
        <span class="dep-crono-proy" style="--pc:<?= e(ProyectoRepo::colorBase($pB)) ?>"><?= e(aliasDeploy((int)$b['proyecto_id']) ?: $pB['nombre']) ?></span>
        <span class="dep-build-txt"><b><?= e($b['titulo'] !== '' ? $b['titulo'] : substr((string)$b['sha'], 0, 8)) ?></b>
          <small><?= e($b['rama']) ?><?= $b['autor'] !== '' ? ' · ' . e($b['autor']) : '' ?> · build terminado <?= e(Cronograma::cuandoFue((string)$b['terminado'])) ?></small></span>
        <span class="dep-build-sube">sube <?= e(Cronograma::texto(Cronograma::proxima((int)$b['proyecto_id']))) ?></span>
        <?php if ($b['web_url'] !== ''): ?><a class="dep-build-ver" href="<?= e($b['web_url']) ?>" target="_blank" rel="noopener">#<?= (int)($b['iid'] ?: $b['pipeline_id']) ?></a><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php elseif (!Cronograma::conGitLab()): ?>
  <p class="dep-crono-nota"><?= UI::iconoDS('info', 16) ?> Los builds de GitLab todavía no se toman solos:
    <?= esAdmin() ? 'pon el token de GitLab en <a href="ajustes.php#tab-acceso">Ajustes → Acceso y respaldo</a> y el repositorio en cada proyecto.' : 'falta configurarlo en Ajustes.' ?></p>
  <?php else: ?>
  <p class="dep-crono-nota"><?= UI::iconoDS('info', 16) ?> No hay builds esperando: cuando uno termine bien en GitLab, aparece aquí con la hora a la que sube.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card-base tabla-card dep-hist">
  <div class="tabla-toolbar">
    <h2 class="font-display"><?= UI::icono('Clock') ?> Historial
      <span class="tabla-count"><?= count($historial) ?></span>
    </h2>
    <?php if (count($proyectos) > 1): ?>
    <form method="get" class="inline-form">
      <?php $opcProy = [0 => 'Todos los proyectos'];
            foreach ($proyectos as $p) $opcProy[(int)$p['id']] = $p['nombre']; ?>
      <?= UI::select('p', $opcProy, (string)$fProy, true, 'select-sm') ?>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$historial): ?>
    <?= UI::vacio('fa-cloud-arrow-up', 'Sin despliegues registrados',
          $puedeDep
            ? 'Cuando subas los cambios, pulsa "Cambios subidos" arriba y aquí queda la hora.'
            : 'Todavía nadie ha marcado una subida al servidor de pruebas.') ?>
  <?php else: ?>
  <div class="dep-lista">
    <?php
    $diaPrevio = '';
    foreach ($historial as $d):
        $dia   = substr((string)$d['fecha'], 0, 10);
        $autor = $miembros[(int)$d['autor_id']] ?? null;
        $tids  = DeployRepo::tareasDe($d);
        // Una subida puede tocar varios proyectos: aquí sí se dicen cuáles,
        // que es la pantalla donde se viene a mirar el detalle.
        $proysD = array_values(array_filter(array_map(
            fn($pid) => $mapaProy[$pid] ?? null, DeployRepo::proyectosDe($d)
        )));
        $proy  = $proysD[0] ?? null;
        if (!$proy) continue;
        if ($dia !== $diaPrevio):
            $diaPrevio = $dia;
            $rotulo = match ($dia) {
                date('Y-m-d')                          => 'Hoy',
                date('Y-m-d', strtotime('-1 day'))     => 'Ayer',
                default                                => $dia,
            };
    ?>
    <div class="dep-dia"><?= e($rotulo) ?><small><?= e($dia) ?></small></div>
    <?php endif; ?>

    <article class="dep-item" style="--pc:<?= e(ProyectoRepo::colorBase($proy)) ?>">
      <div class="dep-item-hora">
        <b><?= e(substr($d['fecha'], 11, 5)) ?></b>
        <small><?= e(DeployRepo::haceCuanto($d['fecha'])) ?></small>
      </div>
      <div class="dep-item-cuerpo">
        <div class="dep-item-cab">
          <?php foreach ($proysD as $pr): ?>
          <a class="dep-item-proy" href="proyecto.php?id=<?= (int)$pr['id'] ?>"
             style="--pc:<?= e(ProyectoRepo::colorBase($pr)) ?>">
            <?= UI::icono($pr['icono'] ?? 'FolderOpen') ?> <?= e($pr['nombre']) ?>
          </a>
          <?php endforeach; ?>
          <?php if ($autor): ?>
          <span class="dep-item-autor"><?= UI::avatar($autor, 22, true) ?> <?= e($autor['nombre']) ?></span>
          <?php endif; ?>
          <span class="dep-item-n"><?= count($tids) ?> tarea<?= count($tids) === 1 ? '' : 's' ?></span>
          <?php if (($d['origen'] ?? '') === 'cronograma'): ?>
          <span class="dep-item-auto" title="La registró el horario de subidas con los builds de GitLab"><?= UI::icono('Clock') ?> Automática · horario</span>
          <?php endif; ?>
        </div>
        <?php if (trim((string)($d['nota'] ?? '')) !== ''): ?>
        <p class="dep-item-nota"><?= nl2br(e($d['nota'])) ?></p>
        <?php endif; ?>
        <?php $bsD = $crono || class_exists('Cronograma') ? Cronograma::deDeploy($d) : []; if ($bsD): ?>
        <details class="dep-item-tareas">
          <summary>Builds de GitLab (<?= count($bsD) ?>)</summary>
          <ul>
            <?php foreach ($bsD as $b): ?>
            <li>
              <b><?= e(aliasDeploy((int)$b['proyecto_id']) ?: $b['repo']) ?></b> · <?= e($b['rama']) ?> ·
              <?= e($b['titulo'] !== '' ? $b['titulo'] : substr((string)$b['sha'], 0, 8)) ?>
              <small><?= $b['autor'] !== '' ? e($b['autor']) . ' · ' : '' ?>terminó <?= e(Cronograma::cuandoFue((string)$b['terminado'])) ?>
                <?php if ($b['web_url'] !== ''): ?> · <a href="<?= e($b['web_url']) ?>" target="_blank" rel="noopener">build #<?= (int)($b['iid'] ?: $b['pipeline_id']) ?></a><?php endif; ?></small>
            </li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>
        <?php if ($tids): ?>
        <details class="dep-item-tareas">
          <summary>Qué subió</summary>
          <ul>
            <?php foreach ($tids as $tid): $t = $tituloTarea[$tid] ?? null; ?>
            <li>
              <b>#<?= $tid ?></b>
              <?= $t ? e($t['titulo']) : '<i>tarea eliminada</i>' ?>
              <?php if ($t && !empty($t['completada_en'])): ?>
              <small>completada el <?= e($t['completada_en']) ?></small>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>
      </div>
      <?php if (esAdmin()): ?>
      <form method="post" action="actions.php" class="inline-form dep-item-borrar"
            data-confirmar="Se borra el registro y sus <?= count($tids) ?> tarea(s) vuelven a quedar como «sin subir»."
            data-confirmar-titulo="¿Eliminar el despliegue?" data-confirmar-ok="Sí, eliminar">
        <input type="hidden" name="accion" value="deploy_eliminar">
        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <button class="accion-btn accion-peligro" title="Eliminar el registro"><i class="fa-solid fa-trash"></i></button>
      </form>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php if ($puedeDep): ?>
<!-- Lo que entraría en el próximo despliegue de cada proyecto: así el
     encargado sabe qué está a punto de marcar como subido. -->
<section class="card-base tabla-card dep-espera">
  <div class="tabla-toolbar">
    <h2 class="font-display"><?= UI::icono('Task') ?> Esperando subida
      <span class="tabla-count"><?= array_sum(array_map(fn($f) => count($f['pendientes']), $filas)) ?></span>
    </h2>
    <span class="ajuste-ayuda">Tareas completadas que ningún despliegue se ha llevado todavía.</span>
  </div>
  <!-- Una tarjeta por proyecto con el número, y los títulos en un modal.
       Listadas aquí, las tareas hacían de esta sección un muro de varias
       pantallas y dejaban las columnas a alturas distintas. Lo que se viene a
       mirar es cuántas faltan; cuáles son, solo a veces. -->
  <div class="dep-espera-grid">
    <?php $hayEspera = false; foreach ($filas as $pid => $f):
        if (!$f['pendientes']) continue;
        $hayEspera = true;
        $nEsp = count($f['pendientes']);
        // Solo el alias: es el nombre con el que quien sube reconoce la cosa.
        // Con los dos, la tarjeta repetía "Equipo Delta / ms-talento_humano" y
        // la mitad que se lee no era la que sirve aquí. El nombre del panel
        // sigue en el modal y en el título del botón.
        $aliasEsp = aliasDeploy($pid); ?>
    <button type="button" class="dep-espera-card" aria-haspopup="dialog"
            style="--pc:<?= e(ProyectoRepo::colorBase($f['proyecto'])) ?>"
            title="<?= e($f['proyecto']['nombre']) ?>"
            onclick="document.getElementById('dlg-espera-<?= $pid ?>').showModal()">
      <span class="dee-ico"><?= UI::icono($f['proyecto']['icono'] ?? 'FolderOpen') ?></span>
      <span class="dee-txt">
        <b class="truncate<?= $aliasEsp ? ' dee-alias' : '' ?>"><?= e($aliasEsp ?: $f['proyecto']['nombre']) ?></b>
      </span>
      <span class="dee-n"><?= $nEsp ?></span>
    </button>
    <?php endforeach; ?>
    <?php if (!$hayEspera): ?>
    <p class="dep-vacio"><?= UI::icono('CircleCheck') ?> Todo lo completado ya está en <?= e($cfgDep['entorno']) ?>.</p>
    <?php endif; ?>
  </div>
</section>

<?php /* El detalle de cada tarjeta: los títulos que entrarían en la próxima subida. */ ?>
<?php foreach ($filas as $pid => $f): if (!$f['pendientes']) continue; $nEsp = count($f['pendientes']); ?>
<dialog id="dlg-espera-<?= $pid ?>" class="dlg-meca dlg-espera">
  <div class="dlg-form">
    <header>
      <h3 class="font-display">
        <?= UI::icono($f['proyecto']['icono'] ?? 'FolderOpen') ?> <?= e($f['proyecto']['nombre']) ?>
      </h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="dlg-cuerpo">
      <p class="campo-ayuda">
        <?= $nEsp ?> tarea<?= $nEsp === 1 ? '' : 's' ?> completada<?= $nEsp === 1 ? '' : 's' ?>
        que ningún despliegue se ha llevado todavía.
      </p>
      <ul class="dep-espera-lista">
        <?php foreach ($f['pendientes'] as $t): ?>
        <li>
          <b>#<?= (int)$t['id'] ?></b>
          <span><?= e($t['titulo']) ?></span>
          <?php if (!empty($t['completada_en'])): ?><small><?= e($t['completada_en']) ?></small><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cerrar</button>
    </footer>
  </div>
</dialog>
<?php endforeach; ?>
<?php endif; ?>

<?php UI::fin(); ?>
