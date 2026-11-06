<?php
/**
 * Importar historias de usuario al backlog del proyecto desde su documento de
 * planificación (?p=ID del proyecto), en pantalla completa. Lo abre el botón
 * «Importar historias» de la pestaña Sprints; lo usan el PO, el Scrum Master
 * y el administrador (Backlog::puede).
 *
 *  1. Se sube el Word (o PDF, Markdown, texto). El Word con el formato de
 *     InnoTech se lee aquí mismo, sin IA (BacklogImport::leer): exacto y gratis.
 *     Lo demás, o si se pide, lo lee la IA (IAFunciones::historiasDocumento).
 *  2. Lo leído queda en la sesión y se revisa por épica: cuáles entran, si son
 *     nuevas o ya estaban (por su código) y a qué sprint va cada una.
 *  3. Se importa con bl_importar (actions.php → BacklogImport::aplicar).
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Releases.php';
require_once __DIR__ . '/lib/Backlog.php';
require_once __DIR__ . '/lib/BacklogImport.php';
require_once __DIR__ . '/lib/releases_ui.php';
require_once __DIR__ . '/lib/backlog_ui.php';

$pid = (int)($_GET['p'] ?? 0);
$proyecto = (new ProyectoRepo())->buscar($pid);
if (!$proyecto || !puedeVerProyecto($pid)) redirigir('index.php', 'Ese proyecto no está disponible.', 'error');
if (!Backlog::puede($pid)) redirigir('proyecto.php?id=' . $pid . '#vista-sprints', 'Las historias las importan el PO del proyecto, su Scrum Master o el administrador.', 'error');
$aqui = 'historias_importar.php?p=' . $pid;

// ¿Hay IA para leer lo que no trae el formato?
$conIA = false;
if (is_file(__DIR__ . '/lib/IA.php') && is_file(__DIR__ . '/lib/IAFunciones.php')) {
    require_once __DIR__ . '/lib/IA.php';
    require_once __DIR__ . '/lib/IAFunciones.php';
    $conIA = IA::lista() && !empty(IA::config()['asignaciones']['bl_historias']['activa']);
}

/* ---------- 1. Subir y leer ---------- */
if (!empty($_GET['otro'])) {
    unset($_SESSION['bl_imp'][$pid]);
    redirigir($aqui);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['archivo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        redirigir($aqui, ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'El archivo supera el límite del servidor (' . ini_get('upload_max_filesize') . ').' : 'No llegó el archivo: elígelo otra vez.', 'error');
    }
    $nombre = mb_substr(basename((string)$f['name']), 0, 160);
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (!in_array($ext, ['docx', 'pdf', 'md', 'txt'], true)) redirigir($aqui, 'Sube el documento en Word (.docx), PDF, Markdown o texto.', 'error');
    $conLaIA = ($_POST['lectura'] ?? '') === 'ia';
    try {
        $datos = null;
        $lectura = 'directa';
        $iaMeta = [];
        if ($ext === 'docx' && !$conLaIA) {
            $datos = BacklogImport::leer(BacklogImport::bloquesDocx($f['tmp_name']));
            if (!$datos['historias']) $datos = null;   // no trae el formato: que lo lea la IA
        }
        if ($datos === null) {
            if (!$conIA) throw new RuntimeException($ext === 'docx'
                ? 'Ese Word no trae el formato de historias de InnoTech y la IA no está configurada para leerlo (consola de IA → «Historias de usuario desde un documento»).'
                : 'Para leer un PDF o un texto hace falta la IA (consola de IA → «Historias de usuario desde un documento»).');
            @set_time_limit(300);
            $r = IAFunciones::historiasDocumento($proyecto, IA::leerArchivo($nombre, $f['tmp_name']), $nombre);
            $datos = ['meta' => $r['meta'], 'epicas' => $r['epicas'], 'historias' => $r['historias']];
            $iaMeta = ['modelo' => (string)($r['modelo'] ?? ''), 'costo' => (float)($r['costo'] ?? 0), 'tokens' => (int)($r['tokens'] ?? 0)];
            $lectura = 'ia';
        }
    } catch (Throwable $e) {
        redirigir($aqui, $e->getMessage(), 'error');
    }
    $_SESSION['bl_imp'][$pid] = ['nombre' => $nombre, 'lectura' => $lectura, 'ia' => $iaMeta, 'datos' => $datos, 'fecha' => date('Y-m-d H:i')];
    redirigir($aqui, 'Leí ' . count($datos['historias']) . ' historia(s) de «' . $nombre . '». Revisa cuáles entran y a qué sprint van.');
}

$imp = $_SESSION['bl_imp'][$pid] ?? null;
if (!is_array($imp) || empty($imp['datos']['historias'])) $imp = null;

UI::inicio('Importar historias · ' . $proyecto['nombre'], 'proyecto-' . $pid);
$urlSprints = 'proyecto.php?id=' . $pid . '#vista-sprints';
?>
<div class="ds bl-hu-pag bl-imp">
  <nav class="mc-crumbs rl-migas" aria-label="Estás en">
    <a class="mc-crumbs__link" href="proyecto.php?id=<?= $pid ?>"><?= e((string)$proyecto['nombre']) ?></a><span class="mc-crumbs__sep">/</span>
    <a class="mc-crumbs__link" href="<?= e($urlSprints) ?>">Sprints</a><span class="mc-crumbs__sep">/</span>
    <span class="mc-crumbs__cur">Importar historias</span>
  </nav>
<?php if (!$imp): /* ======================= Subir el documento ======================= */ ?>
  <form class="bl-hu" method="post" action="<?= e($aqui) ?>" enctype="multipart/form-data" data-bl-imp-subir>
    <header class="bl-hu__cab">
      <a class="rs-boton rs-boton--icono" href="<?= e($urlSprints) ?>" aria-label="Volver a los sprints" data-ds-tip="Volver a los sprints"><?= UI::icono('ds-chevron-izq') ?></a>
      <div class="bl-hu__tit"><span class="bl-dlg__cod">Importar historias</span><span class="bl-hu__proy"><?= e((string)$proyecto['nombre']) ?> · desde el documento de planificación</span></div>
      <button type="submit" class="rs-boton rs-boton--primario" data-bl-imp-leer disabled><?= rlIco('importar', 18) ?><span>Leer documento</span></button>
    </header>
    <div class="bl-hu__grid">
      <div class="bl-hu__col">
        <section class="rl-pan">
          <header class="rl-pan__cab"><h3>El documento</h3></header>
          <div class="rl-pan__cuerpo bl-imp__cuerpo">
            <label class="bl-imp__drop" data-bl-imp-drop>
              <input type="file" name="archivo" accept=".docx,.pdf,.md,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required data-bl-imp-archivo>
              <span class="bl-doc" data-bl-doc data-tipo="" aria-hidden="true"><svg class="bl-doc__hoja" viewBox="0 0 48 60"><path class="bl-doc__papel" d="M7 2h25l12 12v41a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3z"/><path class="bl-doc__dobles" d="M32 2v9a3 3 0 0 0 3 3h9"/><g class="bl-doc__lineas"><rect x="10" y="21" width="22" height="3" rx="1.5"/><rect x="10" y="28" width="28" height="3" rx="1.5"/><rect x="10" y="35" width="18" height="3" rx="1.5"/><rect x="10" y="42" width="25" height="3" rx="1.5"/></g></svg><span class="bl-doc__tipo" data-bl-doc-tipo>DOC</span><span class="bl-doc__scan"></span></span>
              <b data-bl-imp-nombre>Arrastra aquí el Word o el PDF, o haz clic para elegirlo</b>
              <small data-bl-imp-meta>Word (.docx), PDF, Markdown o texto · hasta 15 MB</small>
            </label>
            <?php /* Mientras se lee: el documento analizándose y los pasos (los arma el script de abajo) */ ?>
            <div class="bl-lee" data-bl-lee hidden>
              <div class="bl-lee__escena" aria-hidden="true">
                <span class="bl-doc bl-doc--grande" data-bl-lee-doc data-tipo="" aria-hidden="true"><svg class="bl-doc__hoja" viewBox="0 0 48 60"><path class="bl-doc__papel" d="M7 2h25l12 12v41a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3z"/><path class="bl-doc__dobles" d="M32 2v9a3 3 0 0 0 3 3h9"/><g class="bl-doc__lineas"><rect x="10" y="21" width="22" height="3" rx="1.5"/><rect x="10" y="28" width="28" height="3" rx="1.5"/><rect x="10" y="35" width="18" height="3" rx="1.5"/><rect x="10" y="42" width="25" height="3" rx="1.5"/></g></svg><span class="bl-doc__tipo" data-bl-lee-tipo>DOC</span><span class="bl-doc__scan"></span></span>
                <span class="bl-lee__ficha" style="--i:0">Épica</span><span class="bl-lee__ficha" style="--i:1">HU</span><span class="bl-lee__ficha" style="--i:2">Dado</span><span class="bl-lee__ficha" style="--i:3">Entonces</span>
                <span class="bl-lee__chispa" style="--i:0"></span><span class="bl-lee__chispa" style="--i:1"></span><span class="bl-lee__chispa" style="--i:2"></span>
              </div>
              <div class="bl-lee__txt">
                <b class="bl-lee__tit" data-bl-lee-tit aria-live="polite">Analizando el documento…</b>
                <small class="bl-lee__arch" data-bl-lee-arch></small>
                <ol class="bl-lee__pasos" data-bl-lee-pasos></ol>
                <span class="bl-lee__barra" aria-hidden="true"><i></i></span>
              </div>
            </div>
            <div class="bl-imp__ops" role="radiogroup" aria-label="Cómo leerlo">
              <label class="bl-imp-op"><input type="radio" name="lectura" value="directa" checked>
                <span><b>Formato InnoTech</b><small>Se lee aquí mismo, exacto y sin costo. Si el Word no trae el formato, lo lee la IA<?= $conIA ? '' : ' (no está configurada)' ?>.</small></span></label>
              <label class="bl-imp-op<?= $conIA ? '' : ' is-off' ?>"><input type="radio" name="lectura" value="ia"<?= $conIA ? '' : ' disabled' ?>>
                <span><b><?= UI::icono('ds-ia', 'ia-estrellas') ?> Con IA</b><small><?= $conIA ? 'Para un PDF o un documento con otro formato. Unos US$ 0,05 por documento de 20 páginas.' : 'La IA no está configurada en la consola.' ?></small></span></label>
            </div>
          </div>
        </section>
      </div>
      <div class="bl-hu__col">
        <section class="rl-pan">
          <header class="rl-pan__cab"><h3>Qué saca del documento</h3></header>
          <div class="rl-pan__cuerpo bl-imp__cuerpo">
            <ul class="bl-imp__lista">
              <li><b>Épicas</b> con su código (R2-D01…) y se enlazan solas al ítem del release del proyecto.</li>
              <li><b>Cada historia</b> con su código, título, Como · Quiero · Para, puntos, prioridad MoSCoW y el sprint que dice el documento.</li>
              <li><b>Reglas de negocio</b> y lo que dice el documento, en el detalle.</li>
              <li><b>Criterios de aceptación</b> como escenarios Dado · Cuando · Entonces.</li>
            </ul>
            <p class="bl-hu__ayuda">Antes de importar ves todo, eliges cuáles entran y a qué sprint va cada una. Si importas el mismo documento otra vez, las historias se ponen al día por su código y conservan su sprint, su estado, su responsable y sus tareas.</p>
          </div>
        </section>
      </div>
    </div>
  </form>
<?php else: /* ======================= Revisar e importar ======================= */
    $d = $imp['datos'];
    $hus = $d['historias'];
    $hoy = date('Y-m-d');
    $sps = Backlog::sprints();
    $act = Backlog::actual($hoy);
    $epPro = [];
    foreach (Backlog::epicas($pid) as $it) $epPro[(string)$it['codigo']] = $it;
    $ya = [];
    foreach (Backlog::historias(['proyecto_id' => $pid]) as $h) if ($h['codigo'] !== '') $ya[$h['codigo']] = $h;
    $conf = Backlog::conf();
    $vel = Backlog::velocidad($pid);
    $capDef = (int)($conf['capacidad'] ?? 0) ?: ((int)($vel['promedio'] ?? 0) ?: 20);
    $cand = BacklogImport::candidatos($pid, $hus);
    // Lo que el navegador necesita para mostrar a qué sprint va cada una (y repartir en vivo)
    $js = ['actual' => $act, 'candidatos' => array_values($cand), 'historias' => []];
    $grupos = [];
    $totSp = $nEsc = $nNuevas = 0;
    foreach ($hus as $i => $h) {
        $sp = BacklogImport::sprintInicial((string)$h['sprint']);
        $doc = $sp !== '' && isset($sps[$sp]) ? ($sps[$sp]['fin'] < $hoy ? $act : $sp) : '';
        $previa = $h['codigo'] !== '' ? ($ya[$h['codigo']] ?? null) : null;
        $js['historias'][] = ['i' => $i, 'pts' => max(1, Backlog::puntos(BacklogImport::estimacion((string)$h['sp']))), 'prio' => (string)$h['prioridad'],
                              'doc' => $doc, 'ya' => $previa ? (string)$previa['sprint'] : null];
        $grupos[$h['epica']][] = $i;
        $totSp += (int)$h['sp'];
        $nEsc += count($h['escenarios']);
        if (!$previa) $nNuevas++;
    }
    $enlazadas = count(array_filter(array_keys($grupos), fn($c) => $c !== '' && isset($epPro[$c])));
    $kpi = fn(string $lbl, string $val, string $k, string $pie = '') => '<div class="rl-kpi' . ($k === 'azul' ? ' rl-kpi--azul' : ' col-s-' . $k) . '"><small>' . e($lbl) . '</small><b class="mc-num">' . $val . '</b>' . ($pie !== '' ? '<em>' . $pie . '</em>' : '') . '</div>';
    $meta = array_slice(array_filter((array)$d['meta'], fn($v) => trim((string)$v) !== ''), 0, 8, true);
    $prioTxt = ['Must' => 'Must · alta', 'Should' => 'Should · media', 'Could' => 'Could · baja', "Won't" => "Won't · baja"];
?>
  <form class="bl-hu" method="post" action="actions.php" data-bl-imp data-imp="<?= e(json_encode($js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <input type="hidden" name="accion" value="bl_importar"><input type="hidden" name="proyecto_id" value="<?= $pid ?>">
    <header class="bl-hu__cab">
      <a class="rs-boton rs-boton--icono" href="<?= e($urlSprints) ?>" aria-label="Volver a los sprints" data-ds-tip="Volver a los sprints"><?= UI::icono('ds-chevron-izq') ?></a>
      <div class="bl-hu__tit"><span class="bl-dlg__cod">Importar historias</span>
        <span class="bl-hu__proy"><?= e($imp['nombre']) ?> · <?= $imp['lectura'] === 'ia'
            ? 'leído con IA' . ($imp['ia']['modelo'] ? ' (' . e($imp['ia']['modelo']) . ($imp['ia']['costo'] > 0 ? ' · US$ ' . number_format($imp['ia']['costo'], 3) : '') . ')' : '')
            : 'leído sin IA (formato InnoTech)' ?></span></div>
      <a class="rs-boton rs-boton--icono" href="<?= e($aqui . '&otro=1') ?>" aria-label="Subir otro documento" data-ds-tip="Subir otro documento"><?= rlIco('reintentar', 18) ?></a>
      <button type="submit" class="rs-boton rs-boton--primario" data-bl-imp-ir><?= UI::icono('ds-check') ?><span>Importar <b data-bl-imp-n><?= count($hus) ?></b></span></button>
    </header>

    <div class="rl-kpis bl-kpis">
      <?= $kpi('Historias', (string)count($hus), 'azul', $nNuevas . ' nueva(s) · ' . (count($hus) - $nNuevas) . ' ya estaban') ?>
      <?= $kpi('Puntos', (string)$totSp, 'azul', 'Lo que dice el documento') ?>
      <?= $kpi('Épicas', (string)count(array_filter(array_keys($grupos), fn($c) => $c !== '')), $enlazadas === count(array_filter(array_keys($grupos), fn($c) => $c !== '')) ? 'ok' : 'warn', $enlazadas . ' con su ítem en el proyecto') ?>
      <?= $kpi('Escenarios', (string)$nEsc, 'azul', 'Dado · Cuando · Entonces') ?>
      <?= $kpi('Elegidas', '<span data-bl-imp-n>' . count($hus) . '</span>', 'ok', '<span data-bl-imp-pts>' . $totSp . '</span> pts') ?>
    </div>

    <div class="bl-hu__grid bl-imp__grid">
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>¿A qué sprint va cada una?</h3></header>
        <div class="rl-pan__cuerpo bl-imp__cuerpo">
          <div class="bl-imp__ops" role="radiogroup" aria-label="Sprint">
            <label class="bl-imp-op"><input type="radio" name="modo" value="documento" checked data-bl-imp-modo>
              <span><b>Como dice el documento</b><small>Cada una a su sprint (S17, S18…). Si ese sprint ya pasó, va al de hoy (<?= e($act) ?>).</small></span></label>
            <label class="bl-imp-op"><input type="radio" name="modo" value="repartir" data-bl-imp-modo>
              <span><b>Repartir por capacidad</b><small>Primero las Must, luego Should y Could, en el orden del documento, desde el sprint de hoy hasta el último del release. Lo que no quepa queda en el backlog.</small>
                <span class="bl-imp__cap"><input class="mc-input__control" type="number" name="capacidad" min="1" max="300" value="<?= $capDef ?>" data-bl-imp-cap aria-label="Puntos por sprint"> puntos por sprint<?= !empty($vel['promedio']) ? ' · velocidad del equipo: ' . (int)$vel['promedio'] : '' ?></span></span></label>
            <label class="bl-imp-op"><input type="radio" name="modo" value="backlog" data-bl-imp-modo>
              <span><b>Todas al backlog</b><small>Sin sprint: las metes tú a cada sprint arrastrándolas.</small></span></label>
          </div>
          <p class="bl-hu__ayuda">Las que ya estaban en el backlog (mismo código) se ponen al día y se quedan en su sprint.</p>
        </div>
      </section>
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Así quedan los sprints</h3></header>
        <div class="rl-pan__cuerpo bl-imp__cuerpo">
          <ul class="bl-imp__sps" data-bl-imp-sps aria-live="polite"></ul>
          <?php if ($meta): ?>
          <dl class="bl-imp__meta"><?php foreach ($meta as $k => $v): ?><div><dt><?= e((string)$k) ?></dt><dd><?= e(mb_substr((string)$v, 0, 120)) ?></dd></div><?php endforeach; ?></dl>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <?php foreach ($grupos as $cod => $idx):
        $ep = $cod !== '' ? ($d['epicas'][$cod] ?? ['codigo' => $cod, 'titulo' => '']) : null;
        $it = $cod !== '' ? ($epPro[$cod] ?? null) : null;
        $ptsEp = array_sum(array_map(fn($i) => (int)$hus[$i]['sp'], $idx)); ?>
    <section class="rl-pan bl-imp__ep" data-bl-imp-ep>
      <header class="rl-pan__cab">
        <label class="bl-imp__todas" data-ds-tip="Marcar o quitar todas"><input type="checkbox" checked data-bl-imp-todas><span class="rl-multi__caja" aria-hidden="true"><?= UI::checkDibujado(11) ?></span></label>
        <h3><?= $ep ? e($cod) . ' · ' . e($ep['titulo'] !== '' ? $ep['titulo'] : ($it['titulo'] ?? '')) : 'Sin épica' ?>
          <span class="bl-hu__sub"><?= count($idx) ?> historia(s) · <?= $ptsEp ?> pts<?= $ep && ($ep['sprints'] ?? '') !== '' ? ' · ' . e($ep['sprints']) : '' ?><?= $ep && ($ep['microservicio'] ?? '') !== '' ? ' · ' . e($ep['microservicio']) : '' ?></span></h3>
        <?php if ($ep): ?><span class="bl-imp__enlace<?= $it ? ' is-ok' : '' ?>" data-ds-tip="<?= $it ? 'Se enlaza al ítem ' . e($cod) . ' del release ' . e((string)$it['release']) : 'Este proyecto no tiene el ítem ' . e($cod) . ' en sus releases: entran sin épica' ?>"><?= rlIco($it ? 'relacionar' : 'alerta', 16) ?><span><?= $it ? 'Enlazada' : 'Sin ítem' ?></span></span><?php endif; ?>
      </header>
      <div class="mc-table__scroll">
        <table class="mc-table__table bl-imp__tabla">
          <thead><tr><th class="bl-imp__c-chk"><span class="mc-sr-only">Importar</span></th><th>Historia</th><th class="is-center">SP</th><th>Prioridad</th><th>Documento</th><th>Va a</th><th class="is-center">Esc. · reglas</th><th>Queda</th></tr></thead>
          <tbody>
          <?php foreach ($idx as $i): $h = $hus[$i]; $previa = $h['codigo'] !== '' ? ($ya[$h['codigo']] ?? null) : null; ?>
            <tr data-bl-imp-fila="<?= $i ?>">
              <td class="bl-imp__c-chk"><label class="bl-imp__chk"><input type="checkbox" name="hu[]" value="<?= $i ?>" checked data-bl-imp-hu><span class="rl-multi__caja" aria-hidden="true"><?= UI::checkDibujado(11) ?></span><span class="mc-sr-only">Importar <?= e($h['codigo']) ?></span></label></td>
              <td class="bl-imp__c-hu">
                <details class="bl-imp__det">
                  <summary><span class="bl-dlg__cod"><?= e($h['codigo'] !== '' ? $h['codigo'] : 'Sin código') ?></span><b><?= e($h['titulo']) ?></b>
                    <?php if ($h['como'] !== ''): ?><small>Como <?= e($h['como']) ?></small><?php endif; ?></summary>
                  <div class="bl-imp__det-cuerpo">
                    <?php if ($h['quiero'] !== '' || $h['para'] !== ''): ?><p><b>Quiero</b> <?= e($h['quiero']) ?><?php if ($h['para'] !== ''): ?><br><b>Para</b> <?= e($h['para']) ?><?php endif; ?></p><?php endif; ?>
                    <?php if ($h['reglas']): ?><p class="bl-imp__rot">Reglas de negocio</p><ul><?php foreach ($h['reglas'] as $rg): ?><li><?= e($rg) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php foreach ($h['escenarios'] as $n => $es): ?>
                      <div class="bl-imp__esc"><p class="bl-imp__rot">Escenario <?= $n + 1 ?><?= $es['nombre'] !== '' ? ' · ' . e($es['nombre']) : '' ?></p>
                        <?php foreach (['dado' => 'Dado', 'cuando' => 'Cuando', 'entonces' => 'Entonces'] as $k => $lbl): if (trim((string)$es[$k]) === '') continue; ?>
                          <p><b><?= $lbl ?></b> <?= nl2br(e((string)$es[$k])) ?></p>
                        <?php endforeach; ?></div>
                    <?php endforeach; ?>
                  </div>
                </details>
              </td>
              <td class="is-center"><span class="bl-pts<?= $h['sp'] === '' ? ' is-falta' : '' ?>"><?= $h['sp'] !== '' ? e(BacklogImport::estimacion((string)$h['sp'])) : '—' ?></span></td>
              <td><span class="bl-imp__prio"><?= blPrio(BacklogImport::MOSCOW[$h['prioridad']] ?? 'media') ?><?= e($prioTxt[$h['prioridad']] ?? 'Sin prioridad') ?></span></td>
              <td><span class="bl-imp__doc"><?= $h['sprint'] !== '' ? e($h['sprint']) : '—' ?></span></td>
              <td><b class="bl-imp__va" data-bl-imp-va>—</b></td>
              <td class="is-center"><span class="bl-imp__n" data-ds-tip="<?= count($h['escenarios']) ?> escenario(s) · <?= count($h['reglas']) ?> regla(s)"><?= count($h['escenarios']) ?> · <?= count($h['reglas']) ?></span></td>
              <td><?= $previa ? '<span class="bl-est bl-t--azul" data-ds-tip="Ya está en el backlog: se pone al día y conserva su sprint, estado y responsable">Se actualiza</span>' : '<span class="bl-est bl-t--verde">Nueva</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endforeach; ?>
  </form>
<?php endif; ?>
</div>
<script>
/* Importar historias: el archivo elegido (o soltado), «Leyendo…» al enviar y,
   al revisar, a qué sprint va cada una según el modo (y repartir en vivo). */
(function () {
  var subir = document.querySelector('[data-bl-imp-subir]');
  if (subir) {
    var inp = subir.querySelector('[data-bl-imp-archivo]'), drop = subir.querySelector('[data-bl-imp-drop]'),
        nom = subir.querySelector('[data-bl-imp-nombre]'), meta = subir.querySelector('[data-bl-imp-meta]'), btn = subir.querySelector('[data-bl-imp-leer]'),
        doc = subir.querySelector('[data-bl-doc]'), lee = subir.querySelector('[data-bl-lee]');
    // El ícono según el archivo: PDF, Word, Markdown o texto
    var TIPOS = { pdf: ['PDF', 'PDF'], docx: ['DOCX', 'Word'], md: ['MD', 'Markdown'], txt: ['TXT', 'Texto'] };
    var tipoDe = function (n) { var x = String(n || '').split('.').pop().toLowerCase(); return TIPOS[x] ? x : ''; };
    var peso = function (b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
    var ponerTipo = function (el, t) { el.setAttribute('data-tipo', t); el.querySelector('.bl-doc__tipo').textContent = t ? TIPOS[t][0] : 'DOC'; };
    var pintar = function () {
      var f = inp.files && inp.files[0], t = f ? tipoDe(f.name) : '';
      nom.textContent = f ? f.name : 'Arrastra aquí el Word o el PDF, o haz clic para elegirlo';
      meta.textContent = f ? (t ? TIPOS[t][1] : 'Documento') + ' · ' + peso(f.size) + (t === 'pdf' || t === 'md' || t === 'txt' ? ' · lo lee la IA' : '') : 'Word (.docx), PDF, Markdown o texto · hasta 15 MB';
      ponerTipo(doc, t);
      drop.classList.toggle('is-lleno', !!f); btn.disabled = !f;
    };
    // Mientras el servidor lee: el documento analizándose y los pasos, uno tras otro
    var PASOS = {
      directa: [['Abrir el Word', 350], ['Encontrar las épicas', 450], ['Leer las historias de usuario', 600], ['Sacar reglas y criterios Dado · Cuando · Entonces', 700], ['Armar la vista previa', 0]],
      ia: [['Preparar el documento', 1800], ['Enviarlo a la IA', 4000], ['La IA lee el documento', 14000], ['Sacar las historias y sus criterios', 12000], ['Revisar lo que devolvió', 8000], ['Armar la vista previa', 0]]
    };
    var analizar = function () {
      var f = inp.files && inp.files[0], t = f ? tipoDe(f.name) : '';
      var conIA = (subir.querySelector('[name=lectura]:checked') || {}).value === 'ia' || (t !== '' && t !== 'docx');
      var pasos = PASOS[conIA ? 'ia' : 'directa'];
      ponerTipo(subir.querySelector('[data-bl-lee-doc]'), t);
      lee.classList.toggle('is-ia', conIA);
      subir.querySelector('[data-bl-lee-arch]').textContent = f ? f.name + ' · ' + peso(f.size) : '';
      var ol = subir.querySelector('[data-bl-lee-pasos]'), tit = subir.querySelector('[data-bl-lee-tit]');
      ol.innerHTML = pasos.map(function (p) { return '<li><i aria-hidden="true"></i><span>' + p[0] + '</span></li>'; }).join('');
      var lis = ol.querySelectorAll('li'), k = 0;
      var avanzar = function () {
        lis.forEach(function (li, i) { li.className = i < k ? 'is-hecho' : i === k ? 'is-actual' : ''; });
        tit.textContent = (conIA ? 'La IA está analizando' : 'Analizando') + ' el documento… ' + pasos[k][0].toLowerCase();
        if (pasos[k][1] && k < pasos.length - 1) setTimeout(function () { k++; avanzar(); }, pasos[k][1]);
      };
      avanzar();
      drop.hidden = true;
      subir.querySelector('.bl-imp__ops').classList.add('is-leyendo');
      lee.hidden = false;
    };
    inp.addEventListener('change', pintar);
    ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-encima'); }); });
    ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-encima'); }); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; pintar(); } });
    subir.addEventListener('submit', function () { btn.disabled = true; analizar(); });
    return;
  }
  var f = document.querySelector('[data-bl-imp]');
  if (!f) return;
  var d = JSON.parse(f.getAttribute('data-imp')), rango = { Must: 0, Should: 1, Could: 2 };
  var porI = {}; d.historias.forEach(function (h) { porI[h.i] = h; });
  function elegidas() { return Array.prototype.filter.call(f.querySelectorAll('[data-bl-imp-hu]'), function (c) { return c.checked; }).map(function (c) { return porI[+c.value]; }); }
  function calcular() {
    var modo = (f.querySelector('[data-bl-imp-modo]:checked') || {}).value || 'documento',
        cap = Math.max(1, parseInt(f.querySelector('[data-bl-imp-cap]').value, 10) || 1), sel = elegidas(), va = {};
    if (modo === 'repartir') {
      var nuevas = sel.filter(function (h) { return h.ya === null; }).sort(function (a, b) { return ((rango[a.prio] ?? 2) - (rango[b.prio] ?? 2)) || a.i - b.i; });
      var k = 0, usados = 0;
      nuevas.forEach(function (h) {
        while (k < d.candidatos.length && usados > 0 && usados + h.pts > cap) { k++; usados = 0; }
        if (k >= d.candidatos.length) { va[h.i] = ''; return; }
        va[h.i] = d.candidatos[k]; usados += h.pts;
      });
    }
    var sps = {}, pts = 0;
    d.historias.forEach(function (h) {
      var fila = f.querySelector('[data-bl-imp-fila="' + h.i + '"]'), on = fila.querySelector('[data-bl-imp-hu]').checked,
          s = h.ya !== null ? h.ya : (modo === 'backlog' ? '' : modo === 'repartir' ? (va[h.i] || '') : h.doc);
      fila.classList.toggle('is-fuera', !on);
      fila.querySelector('[data-bl-imp-va]').textContent = !on ? '—' : (s || 'Backlog') + (h.ya !== null ? ' (se queda)' : '');
      if (!on) return;
      pts += h.pts;
      var c = s || 'Backlog'; sps[c] = sps[c] || { n: 0, pts: 0 }; sps[c].n++; sps[c].pts += h.pts;
    });
    f.querySelectorAll('[data-bl-imp-n]').forEach(function (el) { el.textContent = sel.length; });
    f.querySelectorAll('[data-bl-imp-pts]').forEach(function (el) { el.textContent = pts; });
    f.querySelector('[data-bl-imp-ir]').disabled = !sel.length;
    var ul = f.querySelector('[data-bl-imp-sps]'), claves = Object.keys(sps).sort(function (a, b) { return a === 'Backlog' ? 1 : b === 'Backlog' ? -1 : a.localeCompare(b); });
    var max = Math.max.apply(null, claves.map(function (c) { return sps[c].pts; }).concat([modo === 'repartir' ? cap : 1]));
    ul.innerHTML = claves.length ? claves.map(function (c) {
      var p = sps[c].pts, sobre = modo === 'repartir' && c !== 'Backlog' && p > cap;
      return '<li><b>' + c + (c === d.actual ? ' <small>hoy</small>' : '') + '</b><span class="bl-imp__barra' + (sobre ? ' is-sobre' : '') + '"><i style="width:' + Math.max(4, Math.round(p * 100 / max)) + '%"></i></span><span class="mc-num">' + sps[c].n + ' HU · ' + p + ' pts</span></li>';
    }).join('') : '<li class="bl-imp__vacio">Marca al menos una historia.</li>';
  }
  f.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-bl-imp-todas]')) t.closest('[data-bl-imp-ep]').querySelectorAll('[data-bl-imp-hu]').forEach(function (c) { c.checked = t.checked; });
    else if (t.matches('[data-bl-imp-hu]')) { var ep = t.closest('[data-bl-imp-ep]'), cs = ep.querySelectorAll('[data-bl-imp-hu]'), on = ep.querySelectorAll('[data-bl-imp-hu]:checked').length, td = ep.querySelector('[data-bl-imp-todas]'); td.checked = on === cs.length; td.indeterminate = on > 0 && on < cs.length; }
    calcular();
  });
  f.querySelector('[data-bl-imp-cap]').addEventListener('input', calcular);
  f.addEventListener('submit', function () { f.querySelector('[data-bl-imp-ir]').disabled = true; });
  calcular();
})();
</script>
<?php UI::fin(); ?>
