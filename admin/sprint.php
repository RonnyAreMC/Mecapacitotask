<?php
/**
 * El sprint de un proyecto en su pantalla completa (se abre desde la cabecera de
 * cada sprint en la pestaña «Sprints» del proyecto y desde su tablero):
 * ?p=ID del proyecto &s=S22 (sin s, el sprint en curso) &v=plan|retro|dor.
 *
 *  - Planificación: el objetivo del sprint, la capacidad de cada persona frente a
 *    lo que tiene asignado, las historias del sprint con su «Lista» (DoR), las del
 *    backlog para meter y lo que quedó abierto de las retrospectivas anteriores.
 *  - Retrospectiva: si se cumplió el objetivo, qué salió bien, qué salió mal y
 *    las acciones (cada una puede volverse tarea del proyecto).
 *  - Lista para sprint: la Definición de «Lista» del proyecto.
 *
 * Lo arman su PO, su Scrum Master y el administrador (Backlog::puede); los demás
 * del proyecto lo ven. La pantalla está en lib/backlog/sprint_ui.php (blSprintPagina);
 * se guarda con bl_sprint_plan, bl_sprint_retro, bl_sprint_accion y bl_dor_conf.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/releases/Releases.php';
require_once __DIR__ . '/lib/backlog/Backlog.php';
require_once __DIR__ . '/lib/releases/releases_ui.php';
require_once __DIR__ . '/lib/backlog/backlog_ui.php';
require_once __DIR__ . '/lib/backlog/sprint_ui.php';

$pid = (int)($_GET['p'] ?? 0);
$proyecto = (new ProyectoRepo())->buscar($pid);
if (!$proyecto || !puedeVerProyecto($pid) || Auth::esSupervisor()) redirigir('index.php', 'Ese proyecto no está disponible.', 'error');
Backlog::usarProyecto($pid);   // su calendario: el general o el de su planificación aparte
Backlog::arrastrar();   // lo no terminado de un sprint que ya pasó, al sprint en curso (como en la pestaña)
$sprint = (string)($_GET['s'] ?? '');
if (!isset(Backlog::sprints()[$sprint])) $sprint = Backlog::actual();
if ($sprint === '') redirigir('proyecto.php?id=' . $pid . '#vista-sprints', 'Todavía no hay sprints en el calendario.', 'error');
$vista = (string)($_GET['v'] ?? 'plan');

UI::inicio($sprint . ' · ' . $proyecto['nombre'], 'proyecto-' . $pid);
?>
<div class="ds bls-pag">
  <nav class="mc-crumbs rl-migas" aria-label="Estás en">
    <a class="mc-crumbs__link" href="proyecto.php?id=<?= $pid ?>"><?= e((string)$proyecto['nombre']) ?></a><span class="mc-crumbs__sep">/</span>
    <a class="mc-crumbs__link" href="proyecto.php?id=<?= $pid ?>#vista-sprints">Sprints</a><span class="mc-crumbs__sep">/</span>
    <span class="mc-crumbs__cur"><?= e($sprint) ?></span>
  </nav>
  <?= blSprintPagina(['proyecto' => $proyecto, 'miembros' => (new MiembroRepo())->mapa(), 'tareas' => (new TareaRepo())->delProyecto($pid),
                      'puede' => Backlog::puede($pid), 'sprint' => $sprint, 'vista' => $vista]) ?>
</div>
<script src="<?= asset('assets/sprint.js') ?>" defer></script>
<?php UI::fin(); ?>
