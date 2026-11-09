<?php
/**
 * Una historia de usuario en su pantalla completa (la abre la pestaña «Sprints»
 * del proyecto): ?p=ID del proyecto (&id=ID de la historia; sin id, una nueva,
 * con &sprint=S23 si se creó desde un sprint). La arman su PO, su Scrum Master
 * y el administrador; los demás del proyecto la ven. El formulario está en
 * lib/backlog/backlog_ui.php (blFormHistoria) y se guarda con bl_historia_guardar.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/releases/Releases.php';
require_once __DIR__ . '/lib/backlog/Backlog.php';
require_once __DIR__ . '/lib/releases/releases_ui.php';
require_once __DIR__ . '/lib/backlog/backlog_ui.php';
// El diagrama vivo de la historia (solo para ver), si la pizarra está en el servidor
foreach (['whiteboard/Pizarra', 'whiteboard/PizarraHU'] as $libPz) if (is_file(__DIR__ . '/lib/' . $libPz . '.php')) require_once __DIR__ . '/lib/' . $libPz . '.php';

$pid = (int)($_GET['p'] ?? 0);
$proyecto = (new ProyectoRepo())->buscar($pid);
if (!$proyecto || !puedeVerProyecto($pid)) redirigir('index.php', 'Ese proyecto no está disponible.', 'error');
Backlog::usarProyecto($pid);   // su calendario: el general o el de su planificación aparte
$id = (int)($_GET['id'] ?? 0);
$historia = $id > 0 ? Backlog::historia($id) : null;
if ($id > 0 && (!$historia || (int)$historia['proyecto_id'] !== $pid)) redirigir('proyecto.php?id=' . $pid . '#vista-sprints', 'Esa historia ya no existe.', 'error');
$puede = Backlog::puede($pid);
if (!$historia && !$puede) redirigir('proyecto.php?id=' . $pid . '#vista-sprints', 'Las historias las crean el PO del proyecto, su Scrum Master o el administrador.', 'error');
$sprint = (string)($_GET['sprint'] ?? '');
if ($sprint !== '' && !isset(Backlog::sprints()[$sprint])) $sprint = '';

UI::inicio(($historia ? $historia['codigo'] . ' · ' : 'Nueva historia · ') . $proyecto['nombre'], 'proyecto-' . $pid);
?>
<div class="ds bl-hu-pag">
  <nav class="mc-crumbs rl-migas" aria-label="Estás en">
    <a class="mc-crumbs__link" href="proyecto.php?id=<?= $pid ?>"><?= e((string)$proyecto['nombre']) ?></a><span class="mc-crumbs__sep">/</span>
    <a class="mc-crumbs__link" href="proyecto.php?id=<?= $pid ?>#vista-sprints">Sprints</a><span class="mc-crumbs__sep">/</span>
    <span class="mc-crumbs__cur"><?= $historia ? e($historia['codigo']) : 'Nueva historia' ?></span>
  </nav>
  <?= blFormHistoria(['proyecto' => $proyecto, 'miembros' => (new MiembroRepo())->mapa(), 'tareas' => (new TareaRepo())->delProyecto($pid),
                      'puede' => $puede, 'historia' => $historia, 'sprint' => $sprint]) ?>
</div>
<script src="<?= asset('assets/backlog.js') ?>" defer></script>
<?php UI::fin(); ?>
