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
 *     nuevas o ya estaban (por su código o su título, BacklogImport::previas) y a
 *     qué sprint va cada una. Solo vienen marcadas las nuevas: subir otra vez el
 *     documento con dos historias más crea esas dos, no todas de nuevo.
 *  3. Se importa con bl_importar (actions.php → BacklogImport::aplicar).
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/releases/Releases.php';
require_once __DIR__ . '/lib/backlog/Backlog.php';
require_once __DIR__ . '/lib/backlog/BacklogImport.php';
require_once __DIR__ . '/lib/releases/releases_ui.php';
require_once __DIR__ . '/lib/backlog/backlog_ui.php';

$pid = (int)($_GET['p'] ?? 0);
Backlog::usarProyecto($pid);   // su calendario: el general o el de su planificación aparte
$proyecto = (new ProyectoRepo())->buscar($pid);
if (!$proyecto || !puedeVerProyecto($pid)) redirigir('index.php', 'Ese proyecto no está disponible.', 'error');
if (!Backlog::puede($pid)) redirigir('proyecto.php?id=' . $pid . '#vista-sprints', 'Las historias las importan el PO del proyecto, su Scrum Master o el administrador.', 'error');
$aqui = 'historias_importar.php?p=' . $pid;

// ¿Hay IA para leer lo que no trae el formato?
$conIA = false;
if (is_file(__DIR__ . '/lib/ai/IA.php') && is_file(__DIR__ . '/lib/ai/IAFunciones.php')) {
    require_once __DIR__ . '/lib/ai/IA.php';
    require_once __DIR__ . '/lib/ai/IAFunciones.php';
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
      <div class="bl-hu__tit"><h1 class="bl-hu__h">Importar historias</h1>
        <p class="bl-hu__proy"><span class="bl-hu__lbl">Proyecto:</span> <b><?= e((string)$proyecto['nombre']) ?></b> <span class="bl-hu__sep">·</span> desde el documento de planificación</p></div>
      <div class="bl-hu__acc">
        <button type="submit" class="rs-boton rs-boton--primario" data-bl-imp-leer disabled><?= rlIco('importar', 18) ?><span>Leer documento</span></button>
        <?= UI::volver($urlSprints, 'Volver a los sprints') ?>
      </div>
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
            <p class="bl-hu__ayuda">Antes de importar ves todo, eliges cuáles entran y a qué sprint va cada una. Si subes el documento otra vez, editado o con historias nuevas, el panel reconoce las que ya están (por su código o por lo que dicen): las que cambiaron se ponen al día, las nuevas se crean y las iguales no se tocan.</p>
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
    // Las que ya están en el backlog (por su código o por lo que dicen) y qué les cambió:
    // las nuevas y las que cambiaron vienen marcadas; las iguales, no
    $prev = BacklogImport::previas($pid, $hus);
    $todasPro = Backlog::historias(['proyecto_id' => $pid]);
    usort($todasPro, fn($a, $b) => strnatcasecmp((string)$a['codigo'], (string)$b['codigo']) ?: strcmp((string)$a['titulo'], (string)$b['titulo']));
    $opsPar = ['0' => 'Es nueva: se crea'];
    foreach ($todasPro as $e) $opsPar[(string)$e['id']] = mb_strimwidth(trim((string)$e['codigo'] . ' · ' . (string)$e['titulo'], ' ·'), 0, 90, '…');
    $conf = Backlog::conf();
    $vel = Backlog::velocidad($pid);
    $capDef = (int)($conf['capacidad'] ?? 0) ?: ((int)($vel['promedio'] ?? 0) ?: 20);
    $cand = BacklogImport::candidatos($pid, $hus);
    // Lo que el navegador necesita para mostrar a qué sprint va cada una (y repartir en vivo)
    $js = ['actual' => $act, 'candidatos' => array_values($cand), 'historias' => [],
           'existentes' => array_map(fn($e) => (string)$e['sprint'], array_column($todasPro, null, 'id'))];
    $grupos = [];
    $totSp = $nEsc = $nNuevas = $nCambio = 0;
    foreach ($hus as $i => $h) {
        $sp = BacklogImport::sprintInicial((string)$h['sprint']);
        $doc = $sp !== '' && isset($sps[$sp]) ? ($sps[$sp]['fin'] < $hoy ? $act : $sp) : '';
        $previa = $prev[$i]['h'] ?? null;
        $js['historias'][] = ['i' => $i, 'pts' => max(1, Backlog::puntos(BacklogImport::estimacion((string)$h['sp']))), 'prio' => (string)$h['prioridad'],
                              'doc' => $doc, 'ya' => $previa ? (string)$previa['sprint'] : null, 'estado' => $previa ? $prev[$i]['estado'] : 'nueva'];
        $grupos[$h['epica']][] = $i;
        $totSp += (int)$h['sp'];
        $nEsc += count($h['escenarios']);
        if (!$previa) $nNuevas++;
        elseif ($prev[$i]['estado'] === 'cambio') $nCambio++;
        if (!$previa || $prev[$i]['estado'] === 'cambio') $spNuevas = ($spNuevas ?? 0) + (int)$h['sp'];
    }
    $nIgual = count($hus) - $nNuevas - $nCambio;
    $nYa = $nCambio + $nIgual;
    $nMarcadas = $nNuevas + $nCambio;
    $spNuevas ??= 0;
    // Las iguales empiezan ocultas si hay algo que hacer: lo que hay que mirar es lo nuevo y lo que cambió
    $soloNuevas = $nIgual > 0 && $nMarcadas > 0;
    $enlazadas = count(array_filter(array_keys($grupos), fn($c) => $c !== '' && isset($epPro[$c])));
    $kpi = fn(string $lbl, string $val, string $k, string $pie = '') => '<div class="rl-kpi' . ($k === 'azul' ? ' rl-kpi--azul' : ' col-s-' . $k) . '"><small>' . e($lbl) . '</small><b class="mc-num">' . $val . '</b>' . ($pie !== '' ? '<em>' . $pie . '</em>' : '') . '</div>';
    $meta = array_slice(array_filter((array)$d['meta'], fn($v) => trim((string)$v) !== ''), 0, 8, true);
    $prioTxt = ['Must' => 'Must · alta', 'Should' => 'Should · media', 'Could' => 'Could · baja', "Won't" => "Won't · baja"];
?>
  <form class="bl-hu<?= $soloNuevas ? ' is-solo-nuevas' : '' ?>" method="post" action="actions.php" data-bl-imp data-imp="<?= e(json_encode($js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <input type="hidden" name="accion" value="bl_importar"><input type="hidden" name="proyecto_id" value="<?= $pid ?>">
    <header class="bl-hu__cab">
      <div class="bl-hu__tit"><h1 class="bl-hu__h">Importar historias</h1>
        <p class="bl-hu__proy"><?= e($imp['nombre']) ?> · <?= $imp['lectura'] === 'ia'
            ? 'leído con IA' . ($imp['ia']['modelo'] ? ' (' . e($imp['ia']['modelo']) . ($imp['ia']['costo'] > 0 ? ' · US$ ' . number_format($imp['ia']['costo'], 3) : '') . ')' : '')
            : 'leído sin IA (formato InnoTech)' ?></p></div>
      <div class="bl-hu__acc">
        <a class="rs-boton rs-boton--icono" href="<?= e($aqui . '&otro=1') ?>" aria-label="Subir otro documento" data-ds-tip="Subir otro documento"><?= rlIco('reintentar', 18) ?></a>
        <button type="submit" class="rs-boton rs-boton--primario" data-bl-imp-ir><?= UI::icono('ds-check') ?><span>Importar <b data-bl-imp-n><?= $nMarcadas ?></b></span></button>
        <?= UI::volver($urlSprints, 'Volver a los sprints') ?>
      </div>
    </header>

    <div class="rl-kpis bl-kpis">
      <?= $kpi('Historias', (string)count($hus), 'azul', $nNuevas . ' nueva(s) · ' . $nCambio . ' con cambios · ' . $nIgual . ' igual(es)') ?>
      <?= $kpi('Puntos', (string)$totSp, 'azul', 'Lo que dice el documento') ?>
      <?= $kpi('Épicas', (string)count(array_filter(array_keys($grupos), fn($c) => $c !== '')), $enlazadas === count(array_filter(array_keys($grupos), fn($c) => $c !== '')) ? 'ok' : 'warn', $enlazadas . ' con su ítem en el proyecto') ?>
      <?= $kpi('Escenarios', (string)$nEsc, 'azul', 'Dado · Cuando · Entonces') ?>
      <?= $kpi('Elegidas', '<span data-bl-imp-n>' . $nMarcadas . '</span>', 'ok', '<span data-bl-imp-pts>' . $spNuevas . '</span> pts') ?>
    </div>

    <?php if ($nYa > 0):
        $pl = fn(int $n, string $uno, string $varios) => $n . ' ' . ($n === 1 ? $uno : $varios);
        $partesAv = array_filter([
            $nNuevas ? '<b>' . $pl($nNuevas, 'nueva', 'nuevas') . '</b> (se crea' . ($nNuevas === 1 ? '' : 'n') . ')' : '',
            $nCambio ? '<b>' . $pl($nCambio, 'con cambios', 'con cambios') . '</b> (se pone' . ($nCambio === 1 ? '' : 'n') . ' al día y conserva' . ($nCambio === 1 ? '' : 'n') . ' su sprint, estado, responsable y tareas)' : '',
        ]); ?>
    <div class="bl-imp-ya<?= $nMarcadas ? '' : ' is-ninguna' ?>">
      <span class="bl-imp-ya__ico" aria-hidden="true"><?= UI::icono($nMarcadas ? 'ds-info' : 'ds-check') ?></span>
      <p><?= $nMarcadas
          ? 'Vienen marcadas ' . implode(' y ', $partesAv) . '.'
            . ($nIgual ? ' ' . $pl($nIgual, 'ya está igual', 'ya están iguales') . ' en el backlog: no se toca' . ($nIgual === 1 ? '' : 'n') . '.' : '')
            . ' Si una quedó emparejada con la que no era, ábrela y elige con cuál va.'
          : '<b>Este documento no trae nada nuevo ni cambios.</b> Las ' . $nIgual . ' historias ya están iguales en el backlog.' ?></p>
      <?php if ($nIgual && $nMarcadas): ?>
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-bl-imp-ver-ya aria-pressed="false"><span class="mc-btn__label" data-bl-imp-ver-ya-txt>Ver las <?= $nIgual ?> iguales</span></button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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
          <p class="bl-hu__ayuda">Las que ya están en el backlog y cambiaron se ponen al día y se quedan en su sprint; las iguales no vienen marcadas.</p>
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
        $ptsEp = array_sum(array_map(fn($i) => (int)$hus[$i]['sp'], $idx));
        $yaEp = count(array_filter($idx, fn($i) => ($prev[$i]['estado'] ?? '') === 'igual')); // las iguales: no vienen marcadas ?>
    <section class="rl-pan bl-imp__ep<?= $yaEp === count($idx) ? ' is-solo-ya' : '' ?>" data-bl-imp-ep>
      <header class="rl-pan__cab">
        <label class="bl-imp__todas" data-ds-tip="Marcar o quitar todas"><input type="checkbox"<?= $yaEp === 0 ? ' checked' : '' ?> data-bl-imp-todas><span class="rl-multi__caja" aria-hidden="true"><?= UI::checkDibujado(11) ?></span></label>
        <h3><?= $ep ? e($cod) . ' · ' . e($ep['titulo'] !== '' ? $ep['titulo'] : ($it['titulo'] ?? '')) : 'Sin épica' ?>
          <span class="bl-hu__sub"><?= count($idx) ?> historia(s) · <?= $ptsEp ?> pts<?= $ep && ($ep['sprints'] ?? '') !== '' ? ' · ' . e($ep['sprints']) : '' ?><?= $ep && ($ep['microservicio'] ?? '') !== '' ? ' · ' . e($ep['microservicio']) : '' ?></span></h3>
        <?php if ($ep): ?><span class="bl-imp__enlace<?= $it ? ' is-ok' : '' ?>" data-ds-tip="<?= $it ? 'Se enlaza al ítem ' . e($cod) . ' del release ' . e((string)$it['release']) : 'Este proyecto no tiene el ítem ' . e($cod) . ' en sus releases: entran sin épica' ?>"><?= rlIco($it ? 'relacionar' : 'alerta', 16) ?><span><?= $it ? 'Enlazada' : 'Sin ítem' ?></span></span><?php endif; ?>
      </header>
      <div class="mc-table__scroll">
        <table class="mc-table__table bl-imp__tabla">
          <thead><tr><th class="bl-imp__c-chk"><span class="mc-sr-only">Importar</span></th><th>Historia</th><th class="is-center">SP</th><th>Prioridad</th><th>Documento</th><th>Va a</th><th class="is-center">Esc. · reglas</th><th>Queda</th></tr></thead>
          <tbody>
          <?php foreach ($idx as $i): $h = $hus[$i]; $previa = $prev[$i]['h'] ?? null; $estPrev = $previa ? $prev[$i]['estado'] : 'nueva'; $cambiosPrev = $previa ? $prev[$i]['cambios'] : []; ?>
            <tr data-bl-imp-fila="<?= $i ?>"<?= $estPrev === 'igual' ? ' class="is-ya"' : ($estPrev === 'cambio' ? ' class="is-cambio"' : '') ?>>
              <td class="bl-imp__c-chk"><label class="bl-imp__chk"><input type="checkbox" name="hu[]" value="<?= $i ?>"<?= $estPrev === 'igual' ? '' : ' checked' ?> data-bl-imp-hu><span class="rl-multi__caja" aria-hidden="true"><?= UI::checkDibujado(11) ?></span><span class="mc-sr-only">Importar <?= e($h['codigo']) ?></span></label></td>
              <td class="bl-imp__c-hu">
                <details class="bl-imp__det">
                  <summary><span class="bl-dlg__cod"><?= e($h['codigo'] !== '' ? $h['codigo'] : 'Sin código') ?></span><b><?= e($h['titulo']) ?></b>
                    <?php if ($h['como'] !== ''): ?><small>Como <?= e($h['como']) ?></small><?php endif; ?>
                    <?php if ($estPrev === 'cambio'): ?><small class="bl-imp__antes">Cambia <?= e(implode(', ', $cambiosPrev)) ?><?= in_array('título', $cambiosPrev, true) ? ' · antes: «' . e(mb_strimwidth((string)$previa['titulo'], 0, 80, '…')) . '»' : '' ?></small><?php endif; ?></summary>
                  <div class="bl-imp__det-cuerpo">
                    <div class="bl-imp__par">
                      <label class="bl-imp__rot" for="bl-par-<?= $i ?>">¿Es una que ya está?</label>
                      <?= str_replace('<select ', '<select id="bl-par-' . $i . '" data-bl-imp-par="' . $i . '" ', UI::select('par[' . $i . ']', $opsPar, $previa ? (string)$previa['id'] : '0')) ?>
                      <small>El panel la emparejó <?= $previa ? (match ($prev[$i]['por']) { 'codigo' => 'por su código', 'titulo' => 'por su título', default => 'por lo que dice (' . (int)$prev[$i]['pct'] . ' % parecida)' }) . ' con <b>' . e((string)$previa['codigo']) . '</b>' : 'como nueva' ?>. Si no es esa, elige la correcta o «Es nueva».</small>
                    </div>
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
              <td><?php if ($previa):
                  $comoEs = trim((string)$previa['codigo'] . ' · ' . (string)$previa['titulo'], ' ·'); ?>
                <span class="bl-est <?= $estPrev === 'cambio' ? 'bl-t--azul' : 'bl-t--gris' ?>" data-bl-imp-queda data-ds-tip="<?= $estPrev === 'cambio'
                    ? 'Es «' . e(mb_strimwidth($comoEs, 0, 90, '…')) . '» con cambios en ' . e(implode(', ', $cambiosPrev)) . ': se pone al día con el documento y conserva su sprint, estado, responsable y tareas.'
                    : 'Ya está igual en el backlog: «' . e(mb_strimwidth($comoEs, 0, 90, '…')) . '». Márcala solo si quieres volver a escribirla con el documento.' ?>"><?= $estPrev === 'cambio' ? 'Se actualiza' : 'Igual' ?></span>
                <?php else: ?><span class="bl-est bl-t--verde" data-bl-imp-queda>Nueva</span><?php endif; ?></td>
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
      // Lo que le va a pasar: nueva se crea, con cambios (o marcada a mano) se pone al día, sin marcar no se toca
      var queda = fila.querySelector('[data-bl-imp-queda]');
      if (queda) {
        var esNueva = h.ya === null, txt = on ? (esNueva ? 'Nueva' : 'Se actualiza') : (esNueva ? 'No entra' : h.estado === 'igual' ? 'Igual' : 'No se toca');
        queda.textContent = txt;
        queda.classList.toggle('bl-t--verde', on && esNueva); queda.classList.toggle('bl-t--azul', on && !esNueva); queda.classList.toggle('bl-t--gris', !on);
      }
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
    // Otra pareja elegida a mano: «Es nueva» o una de las que ya están (se pone al día y conserva su sprint)
    if (t.matches('[data-bl-imp-par]')) {
      var h = porI[+t.getAttribute('data-bl-imp-par')], fila = f.querySelector('[data-bl-imp-fila="' + h.i + '"]');
      if (t.value === '0') { h.ya = null; h.estado = 'nueva'; } else { h.ya = d.existentes[t.value] !== undefined ? d.existentes[t.value] : ''; h.estado = 'cambio'; }
      fila.classList.remove('is-ya'); fila.classList.toggle('is-cambio', h.estado === 'cambio');
      fila.querySelector('[data-bl-imp-hu]').checked = true;
    }
    if (t.matches('[data-bl-imp-todas]')) t.closest('[data-bl-imp-ep]').querySelectorAll('[data-bl-imp-hu]').forEach(function (c) { c.checked = t.checked; });
    else if (t.matches('[data-bl-imp-hu]')) { var ep = t.closest('[data-bl-imp-ep]'), cs = ep.querySelectorAll('[data-bl-imp-hu]'), on = ep.querySelectorAll('[data-bl-imp-hu]:checked').length, td = ep.querySelector('[data-bl-imp-todas]'); td.checked = on === cs.length; td.indeterminate = on > 0 && on < cs.length; }
    calcular();
  });
  f.querySelector('[data-bl-imp-cap]').addEventListener('input', calcular);
  f.addEventListener('submit', function () { f.querySelector('[data-bl-imp-ir]').disabled = true; });
  // Cada épica con nuevas y repetidas: su casilla a medias
  f.querySelectorAll('[data-bl-imp-ep]').forEach(function (ep) {
    var cs = ep.querySelectorAll('[data-bl-imp-hu]'), on = ep.querySelectorAll('[data-bl-imp-hu]:checked').length, td = ep.querySelector('[data-bl-imp-todas]');
    td.checked = on === cs.length; td.indeterminate = on > 0 && on < cs.length;
  });
  // «Ver las que ya están»: empiezan ocultas cuando el documento trae nuevas
  var verYa = f.querySelector('[data-bl-imp-ver-ya]');
  if (verYa) verYa.addEventListener('click', function () {
    var ocultas = f.classList.toggle('is-solo-nuevas');
    verYa.setAttribute('aria-pressed', ocultas ? 'false' : 'true');
    var t = verYa.querySelector('[data-bl-imp-ver-ya-txt]');
    t.textContent = ocultas ? t.textContent.replace(/^Ocultar/, 'Ver') : t.textContent.replace(/^Ver/, 'Ocultar');
  });
  calcular();
})();
</script>
<?php UI::fin(); ?>
