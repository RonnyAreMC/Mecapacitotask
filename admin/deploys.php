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
        ? 'Los deploys que terminan bien en GitLab se registran solos. Si subes algo a mano, pulsa <b>Registrar deploy</b>: la fecha y la hora se guardan solas.'
        : 'Aquí ves a qué hora quedaron los últimos cambios en el servidor de pruebas.',
    $fProy ? '<a class="btn-outline btn-meca btn-neutro" href="deploys.php">'
             . UI::icono('Close') . ' Ver todos los proyectos</a>' : ''
);

$dtFilas    = $filas;
$dtMiembros = $miembros;
$dtVolver   = 'deploys';
require __DIR__ . '/lib/deploy_tira.php';

// El horario de subidas (lib/Cronograma.php): la hora límite de cada proyecto con su cuenta
// regresiva, y el último deploy que se vio en GitLab (esos se registran solos, abajo)
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
    $primera = $agenda[0] ?? null;
    $nombreP = fn(array $x) => $x['alias'] !== '' ? $x['alias'] : $x['nombre'];
    $ultGl = Cronograma::ultimoDeGitLab(array_keys($mapaProy));
    $sinRespuesta = esAdmin() ? (array)(Cronograma::estado()['errores'] ?? []) : [];
?>
<section class="card-base dep-crono">
  <div class="dep-crono-cab">
    <span class="dep-crono-ico" aria-hidden="true"><?= UI::icono('Clock') ?></span>
    <div class="dep-crono-tit">
      <h2 class="font-display">Horario de subidas</h2>
      <p>Hasta la hora de cada proyecto (<?= e(Cronograma::dias()) ?>) hay que dejar subidos los cambios. Los deploys que terminan bien en GitLab se registran solos, con su hora.</p>
    </div>
    <?php if (esAdmin()): ?>
    <a class="btn-outline btn-meca btn-sm btn-neutro" href="ajustes.php#tab-deploys"><?= UI::icono('Settings') ?> Configurar</a>
    <?php endif; ?>
  </div>

  <?php if ($primera): ?>
  <div class="dep-cuenta" data-cuenta-regresiva data-hasta="<?= (int)$primera['proxima'] ?>" data-ahora="<?= time() ?>">
    <?php /* Nexo: con tiempo de sobra trabaja tranquilo; en las últimas tres horas, corre */ ?>
    <?= UI::nexo((int)$primera['proxima'] - time() > 3 * 3600 ? 'con-laptop' : 'corriendo', 'dep-cuenta__nexo') ?>
    <small>Hora límite para subir los cambios</small>
    <b class="dep-cuenta__reloj font-display" data-cuenta-reloj>&nbsp;</b>
    <span class="dep-cuenta__cuando"><?= e(ucfirst(Cronograma::texto((int)$primera['proxima']))) ?> ·
      <?= e(implode(', ', array_map($nombreP, $primera['proyectos']))) ?>
    </span>
  </div>
  <?php endif; ?>

  <ul class="dep-crono-lista">
    <?php foreach ($agenda as $fA): ?>
    <li class="dep-crono-fila">
      <span class="dep-crono-hora font-display"><?= e(Cronograma::horaTexto($fA['hora'])) ?></span>
      <span class="dep-crono-proys">
        <?php foreach ($fA['proyectos'] as $x): ?>
        <span class="dep-crono-proy" style="--pc:<?= e(ProyectoRepo::colorBase($x['p'])) ?>" title="<?= e($x['nombre']) ?>"><?= e($nombreP($x)) ?></span>
        <?php endforeach; ?>
      </span>
      <span class="dep-crono-cuando"><?= e(Cronograma::texto((int)$fA['proxima'])) ?> ·
        <b data-cuenta-regresiva data-corto data-hasta="<?= (int)$fA['proxima'] ?>" data-ahora="<?= time() ?>">&nbsp;</b></span>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if (!Cronograma::conGitLab()): ?>
  <p class="dep-crono-nota"><?= UI::iconoDS('info', 16) ?> Los deploys de GitLab todavía no se registran solos:
    <?= esAdmin() ? 'pon el token de GitLab en <a href="ajustes.php#tab-acceso">Ajustes → Acceso y respaldo</a> y el repositorio en cada proyecto.' : 'falta configurarlo en Ajustes.' ?></p>
  <?php elseif ($ultGl): ?>
  <p class="dep-crono-nota"><?= UI::icono('Upload') ?> <span>Último deploy en GitLab:
    <b>#<?= (int)($ultGl['iid'] ?: $ultGl['pipeline_id']) ?></b> en <b><?= e($ultGl['rama']) ?></b>, terminó <?= e(Cronograma::cuandoFue((string)$ultGl['terminado'])) ?><?= $ultGl['lanzo'] !== '' ? ' · lo lanzó ' . e($ultGl['lanzo']) : '' ?><?php if ($ultGl['web_url'] !== ''): ?> · <a href="<?= e($ultGl['web_url']) ?>" target="_blank" rel="noopener">ver pipeline</a><?php endif; ?></span></p>
  <?php else: ?>
  <p class="dep-crono-nota"><?= UI::iconoDS('info', 16) ?> Todavía no se ha visto ningún deploy en GitLab: cuando un pipeline con deploy termine bien, queda registrado solo aquí abajo.</p>
  <?php endif; ?>
  <?php if ($sinRespuesta): ?>
  <p class="dep-crono-nota dep-crono-nota--alerta"><?= UI::iconoDS('info', 16) ?> GitLab no respondió por <?= e(implode(', ', $sinRespuesta)) ?>: revisa el token y el repositorio del proyecto.</p>
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
  <?php
    // Tarjetitas por día: cada subida en su tarjeta (hora, proyectos, quién y cuántas tareas);
    // el detalle —tareas, builds y la nota entera— en su ventana, para que la rejilla no se deforme.
    $porDia = [];
    foreach ($historial as $d) {
        $proysD = array_values(array_filter(array_map(fn($pid) => $mapaProy[$pid] ?? null, DeployRepo::proyectosDe($d))));
        if (!$proysD) continue;
        $porDia[substr((string)$d['fecha'], 0, 10)][] = ['d' => $d, 'proys' => $proysD];
    }
    $diasSem = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fechaLarga = function (string $dia) use ($diasSem, $meses): string {
        $t = strtotime($dia);
        return $diasSem[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t) - 1]
            . (date('Y', $t) !== date('Y') ? ' de ' . date('Y', $t) : '');
    };
    $horaCorta = fn(string $f) => ($t = strtotime($f)) ? date('g:i', $t) . ' ' . date('a', $t) : substr($f, 11, 5);
    $nombreCorto = function (array $m): string {
        $p = preg_split('/\s+/', trim((string)$m['nombre'])) ?: [];
        return trim(($p[0] ?? '') . ' ' . (count($p) >= 3 ? ($p[2] ?? '') : ($p[1] ?? '')));
    };
    $dialogos = [];
  ?>
  <div class="dep-lista dep-lista--cards">
    <?php foreach ($porDia as $dia => $items):
        $rotulo = match ($dia) {
            date('Y-m-d')                      => 'Hoy',
            date('Y-m-d', strtotime('-1 day')) => 'Ayer',
            default                            => ucfirst($fechaLarga($dia)),
        };
        $n = count($items); ?>
    <div class="dep-dia2">
      <b class="font-display"><?= e($rotulo) ?></b>
      <?php if ($rotulo === 'Hoy' || $rotulo === 'Ayer'): ?><small><?= e($fechaLarga($dia)) ?></small><?php endif; ?>
      <span class="dep-dia2__n"><?= $n ?> subida<?= $n === 1 ? '' : 's' ?></span>
    </div>
    <div class="dep-cards">
      <?php foreach ($items as ['d' => $d, 'proys' => $proysD]):
          $did   = (int)$d['id'];
          $autor = $miembros[(int)$d['autor_id']] ?? null;
          $tids  = DeployRepo::tareasDe($d);
          $nT    = count($tids);
          $auto  = in_array($d['origen'] ?? '', ['gitlab', 'cronograma'], true);
          $bsD   = class_exists('Cronograma') ? Cronograma::deDeploy($d) : [];
          $nota  = trim((string)($d['nota'] ?? ''));
          $conDetalle = $nT || $bsD || $nota !== '';
          if ($conDetalle) $dialogos[] = ['d' => $d, 'proys' => $proysD, 'autor' => $autor, 'tids' => $tids, 'builds' => $bsD, 'auto' => $auto]; ?>
      <article class="dep-card" style="--pc:<?= e(ProyectoRepo::colorBase($proysD[0])) ?>">
        <div class="dep-card__top">
          <span class="dep-card__hora font-display"><?= e($horaCorta((string)$d['fecha'])) ?></span>
          <span class="dep-card__hace"><?= e(DeployRepo::haceCuanto($d['fecha'])) ?></span>
          <?php if (esAdmin()): ?>
          <form method="post" action="actions.php" class="inline-form dep-card__borrar"
                data-confirmar="Se borra el registro y sus <?= $nT ?> tarea(s) vuelven a quedar como «sin subir»."
                data-confirmar-titulo="¿Eliminar el despliegue?" data-confirmar-ok="Sí, eliminar">
            <input type="hidden" name="accion" value="deploy_eliminar">
            <input type="hidden" name="id" value="<?= $did ?>">
            <button class="dep-card__x" title="Eliminar el registro" aria-label="Eliminar el registro"><i class="fa-solid fa-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
        <div class="dep-card__proys">
          <?php foreach (array_slice($proysD, 0, 3) as $pr): $alias = aliasDeploy((int)$pr['id']); ?>
          <a class="dep-card__proy" href="proyecto.php?id=<?= (int)$pr['id'] ?>" style="--pc:<?= e(ProyectoRepo::colorBase($pr)) ?>" title="<?= e($pr['nombre']) ?>">
            <?= UI::icono($pr['icono'] ?? 'FolderOpen') ?><span><?= e($alias ?: $pr['nombre']) ?></span>
          </a>
          <?php endforeach; ?>
          <?php if (count($proysD) > 3): ?><span class="dep-card__mas">+<?= count($proysD) - 3 ?></span><?php endif; ?>
        </div>
        <?php if ($nota !== ''): ?>
        <p class="dep-card__nota"><?= e($nota) ?></p>
        <?php endif; ?>
        <div class="dep-card__pie">
          <?php if ($auto && !$autor): ?>
          <span class="dep-item-auto" title="Se registró sola con el deploy de GitLab"><?= UI::icono('Upload') ?> <?= ($d['origen'] ?? '') === 'gitlab' ? 'GitLab' : 'Automática' ?></span>
          <?php elseif ($autor): ?>
          <span class="dep-card__autor"><?= UI::avatar($autor, 24, true) ?><span><?= e($nombreCorto($autor)) ?></span><?php if ($auto): ?><span class="dep-item-auto" title="Se registró sola con el deploy de GitLab que lanzó"><?= UI::icono('Upload') ?> GitLab</span><?php endif; ?></span>
          <?php else: ?>
          <span class="dep-card__autor dep-card__autor--nadie">Sin autor</span>
          <?php endif; ?>
          <?php if ($conDetalle): ?>
          <button type="button" class="dep-card__ver" aria-haspopup="dialog" onclick="document.getElementById('dlg-dep-<?= $did ?>').showModal()">
            <?= $nT ? $nT . ' tarea' . ($nT === 1 ? '' : 's') : ($bsD ? 'Ver pipeline' : 'Ver nota') ?>
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
          </button>
          <?php else: ?>
          <span class="dep-card__cero">Sin tareas nuevas</span>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php /* El detalle de cada subida: la nota entera, los builds de GitLab y las tareas que se llevó. */ ?>
<?php foreach ($dialogos ?? [] as $x): $d = $x['d']; $did = (int)$d['id']; ?>
<dialog id="dlg-dep-<?= $did ?>" class="dlg-meca dlg-espera dlg-dep-det">
  <div class="dlg-form">
    <header>
      <h3 class="font-display"><?= UI::icono('Upload') ?> Subida de las <?= e($horaCorta((string)$d['fecha'])) ?>
        <small><?= e($fechaLarga(substr((string)$d['fecha'], 0, 10))) ?></small></h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="dlg-cuerpo">
      <div class="dep-card__proys">
        <?php foreach ($x['proys'] as $pr): $alias = aliasDeploy((int)$pr['id']); ?>
        <a class="dep-card__proy" href="proyecto.php?id=<?= (int)$pr['id'] ?>" style="--pc:<?= e(ProyectoRepo::colorBase($pr)) ?>">
          <?= UI::icono($pr['icono'] ?? 'FolderOpen') ?><span><?= e($pr['nombre']) ?><?= $alias ? ' · ' . e($alias) : '' ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <p class="campo-ayuda">
        <?= $x['auto'] ? 'Se registró sola con el deploy de GitLab' . ($x['autor'] ? ' que lanzó ' . e($x['autor']['nombre']) : '') . '.' : ($x['autor'] ? 'La registró ' . e($x['autor']['nombre']) . '.' : '') ?>
        <?= e((string)($d['entorno'] ?? '')) !== '' ? ' En ' . e((string)$d['entorno']) . '.' : '' ?>
      </p>
      <?php if (trim((string)($d['nota'] ?? '')) !== ''): ?>
      <p class="dep-det-nota"><?= nl2br(e((string)$d['nota'])) ?></p>
      <?php endif; ?>
      <?php if ($x['builds']): ?>
      <h4 class="dep-det-tit">Pipeline de GitLab</h4>
      <ul class="dep-espera-lista">
        <?php foreach ($x['builds'] as $b): ?>
        <li>
          <b><?= e($b['repo']) ?> · <?= e($b['rama']) ?></b>
          <span><?= e($b['titulo'] !== '' ? $b['titulo'] : substr((string)$b['sha'], 0, 8)) ?><?= $b['autor'] !== '' ? ' · ' . e($b['autor']) : '' ?><?= !empty($b['jobs']) ? ' · ' . e(implode(', ', (array)$b['jobs'])) : '' ?></span>
          <small><?php if ($b['web_url'] !== ''): ?><a href="<?= e($b['web_url']) ?>" target="_blank" rel="noopener">pipeline #<?= (int)($b['iid'] ?: $b['pipeline_id']) ?></a><?php endif; ?></small>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($x['tids']): ?>
      <h4 class="dep-det-tit">Tareas que subió (<?= count($x['tids']) ?>)</h4>
      <ul class="dep-espera-lista">
        <?php foreach ($x['tids'] as $tid): $t = $tituloTarea[$tid] ?? null; ?>
        <li>
          <b>#<?= (int)$tid ?></b>
          <span><?= $t ? e($t['titulo']) : '<i>tarea eliminada</i>' ?></span>
          <?php if ($t && !empty($t['completada_en'])): ?><small><?= e($t['completada_en']) ?></small><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cerrar</button>
    </footer>
  </div>
</dialog>
<?php endforeach; ?>

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
