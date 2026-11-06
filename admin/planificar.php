<?php
/**
 * Planificación: importar tareas en lote desde un JSON.
 * Solo para administradores. El admin pega (o sube) el JSON, puede validarlo
 * sin crear nada, y al importar se crean todas las tareas de una.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
Auth::requiereGestor();   // admin (todo) o Scrum Master (sus proyectos)

// El Scrum Master solo planifica en los proyectos donde participa.
$alcancePlan = Auth::esAdmin() ? null : array_keys(alcanceProyectos() ?? []);
// La lista de proyectos que ve (para el ejemplo y las notas) también se limita.
$proyectos = soloProyectosVisibles((new ProyectoRepo())->todos());
$miembros  = (new MiembroRepo())->todos();

$reporte = null;
$notasIA = '';
$jsonPegado = '';
$modo = '';
$actualizar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modo = $_POST['modo'] ?? 'validar';   // 'validar' | 'importar'
    $actualizar = !empty($_POST['actualizar']);
    $jsonPegado = (string)($_POST['json'] ?? '');
    // Lo que dijo la IA al generar (assets/ia.js): sigue a la vista después de validar
    $notasIA = mb_substr(trim(strip_tags((string)($_POST['ia_notas'] ?? ''))), 0, 1500);

    // Si subieron un archivo, ese manda sobre el textarea
    if (!empty($_FILES['archivo']['tmp_name']) && ($_FILES['archivo']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $jsonPegado = (string)file_get_contents($_FILES['archivo']['tmp_name']);
    }

    $datos = json_decode(trim($jsonPegado), true);
    // El ítem del release que se eligió en cada fila al validar (item[n] => código; '' = sin ítem)
    if (is_array($datos) && isset($_POST['item']) && is_array($_POST['item'])) {
        $conEnvoltura = isset($datos['tareas']) && is_array($datos['tareas']);
        $lista = $conEnvoltura ? $datos['tareas'] : $datos;
        if (array_is_list($lista)) {
            foreach ($_POST['item'] as $n => $cod) {
                $i = (int)$n - 1;
                if (!isset($lista[$i]) || !is_array($lista[$i])) continue;
                // Solo si la fila sigue siendo la misma que se validó
                if (trim((string)($lista[$i]['titulo'] ?? '')) !== trim((string)($_POST['item_titulo'][$n] ?? ''))) continue;
                $cod = strtoupper(trim((string)$cod));
                // Solo si se cambió a mano: el selector que quedó de la validación anterior no
                // pisa el item_release que trae el JSON nuevo (pasaba al pegar otro JSON y validar)
                if ($cod === strtoupper(trim((string)($_POST['item_json'][$n] ?? '')))) continue;
                unset($lista[$i]['items_release'], $lista[$i]['release_item'], $lista[$i]['item']);
                if ($cod === '') unset($lista[$i]['item_release']); else $lista[$i]['item_release'] = $cod;
            }
            if ($conEnvoltura) $datos['tareas'] = $lista; else $datos = $lista;
            // El editor se queda con lo elegido, para que se vea en el JSON
            $jsonPegado = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    if (!is_array($datos)) {
        $reporte = ['ok' => false, 'creadas' => 0, 'filas' => [],
                    'errores' => ['El texto no es un JSON válido. Revisa que no falten comas o comillas. (' . json_last_error_msg() . ')']];
    } else {
        $imp = new ImportadorTareas(new ProyectoRepo(), new MiembroRepo(), new TareaRepo());
        $reporte = $imp->procesar($datos, $modo !== 'importar', $actualizar, $alcancePlan);
    }
}

// Ejemplo con los nombres reales del equipo/proyectos para que copie y edite
$ejProy = $proyectos[0]['nombre'] ?? 'SIGE';
$ejP2   = $proyectos[1]['nombre'] ?? $ejProy;
$ejM1   = $miembros[0]['nombre'] ?? 'Kevin';
$ejM2   = $miembros[1]['nombre'] ?? 'Dulce Villacis';
$hoy    = date('Y-m-d');
$mas7   = date('Y-m-d', strtotime('+7 day'));
$mas14  = date('Y-m-d', strtotime('+14 day'));
$ejItem = '';
if (is_file(__DIR__ . '/lib/releases/Releases.php')) {
    require_once __DIR__ . '/lib/releases/Releases.php';
    foreach ((new JsonStore('rel_items'))->all() as $itE) if (($itE['estado'] ?? '') !== 'cerrado') { $ejItem = (string)$itE['codigo']; break; }
}
$ejItemLinea = $ejItem !== '' ? ",\n      \"item_release\": \"$ejItem\"" : '';
$ejemplo = <<<JSON
{
  "tareas": [
    {
      "proyecto": "$ejProy",
      "titulo": "Diseñar el login con Google",
      "descripcion": "Pantalla partida y botón oficial.",
      "prioridad": "alta",
      "fecha_inicio": "$hoy",
      "fecha_limite": "$mas7",
      "asignados": ["$ejM1"],
      "ref": "login"$ejItemLinea
    },
    {
      "proyecto": "$ejProy",
      "titulo": "Conectar el login al backend",
      "prioridad": "media",
      "fecha_inicio": "$mas7",
      "fecha_limite": "$mas14",
      "asignados": ["$ejM2", "$ejM1"],
      "depende_de": "login"
    },
    {
      "proyecto": "$ejP2",
      "titulo": "Reporte de cierre mensual",
      "prioridad": "baja",
      "fecha_limite": "$mas14"
    }
  ]
}
JSON;

UI::inicio('Planificar', 'planificar');
// «Generar tareas con IA» solo para quien puede usar la IA aquí (admin o Scrum
// Master, con la función activa en la Consola de IA): desde una descripción
// (plan_descripcion) o, sin descripción, desde el documento del plan (plan_documento).
$conIADesc = UI::ia('plan_descripcion');
$conIADoc  = UI::ia('plan_documento');
$conIAPlan = $conIADesc || $conIADoc;
UI::cabecera(
    'Planificar <span class="text-secondary">tareas</span>',
    'Describe el trabajo y la IA arma las tareas, o sube un JSON con la planificación: se crean todas de una.',
    $conIAPlan
        ? '<button type="button" class="btn-primary btn-meca" onclick="document.getElementById(\'dlg-ia-plan\').showModal()">'
          . UI::icono('ds-ia', 'ia-estrellas') . ' Generar tareas con IA</button>'
        : ''
);
?>

<div class="plan-grid">

  <!-- Editor -->
  <section class="card-base plan-card">
    <form method="post" action="planificar.php" enctype="multipart/form-data" class="plan-form" id="plan-form">
      <div class="plan-toolbar">
        <h2 class="font-display"><i class="fa-solid fa-file-code text-secondary"></i> JSON de tareas</h2>
        <div class="plan-acc">
          <label class="btn-outline btn-meca btn-azul btn-sm plan-file">
            <i class="fa-solid fa-file-arrow-up"></i> Subir .json
            <input type="file" name="archivo" accept="application/json,.json" hidden>
          </label>
          <button type="button" class="btn-ghost btn-meca btn-sm" id="plan-ejemplo">
            <?= UI::icono('ds-ia', 'ia-estrellas') ?> Ejemplo
          </button>
        </div>
      </div>
      <?php if ($conIAPlan): ?>
      <!-- Lo rellena assets/ia.js al generar con IA: cuántas tareas propuso y sus notas
           (y, al validar, las vuelve a mostrar desde ia_notas) -->
      <div id="ia-plan-notas" class="ia-plan-notas" aria-live="polite"><?php if ($notasIA !== ''): ?>
        <div class="mc-alert mc-alert--tip" role="status"><div class="mc-alert__content">
          <p class="mc-alert__title"><?= UI::icono('ds-ia', 'ia-estrellas') ?> Lo que armó la IA</p>
          <div class="mc-alert__desc"><?= nl2br(e($notasIA)) ?></div></div></div>
      <?php endif; ?></div>
      <input type="hidden" name="ia_notas" id="plan-ia-notas" value="<?= e($notasIA) ?>">
      <?php endif; ?>
      <textarea name="json" id="plan-json" class="input-meca plan-text" spellcheck="false"
        placeholder='Pega aquí el JSON…'><?= e($jsonPegado) ?></textarea>
      <input type="hidden" name="modo" id="plan-modo" value="validar">
      <label class="plan-check">
        <input type="checkbox" name="actualizar" value="1" <?= $actualizar ? 'checked' : '' ?>>
        <span>Actualizar las que ya existan (mismo proyecto y título) en vez de duplicarlas</span>
      </label>
      <div class="plan-botones">
        <button class="btn-outline btn-meca btn-azul" value="validar" onclick="document.getElementById('plan-modo').value='validar'">
          <i class="fa-solid fa-eye"></i> Validar sin crear
        </button>
        <button class="btn-primary btn-meca" value="importar" onclick="document.getElementById('plan-modo').value='importar'">
          <i class="fa-solid fa-cloud-arrow-up"></i> Importar tareas
        </button>
      </div>
    </form>

    <template id="plan-tpl-ejemplo"><?= e($ejemplo) ?></template>
  </section>

  <!-- Resultado / ayuda -->
  <section class="card-base plan-card">
    <?php if ($reporte === null): ?>
      <h2 class="font-display"><i class="fa-solid fa-circle-info text-secondary"></i> Cómo funciona</h2>
      <ul class="plan-ayuda">
        <li><b>proyecto</b> y <b>asignados</b> van <b>por nombre</b>, no por número. Da igual el orden del equipo.</li>
        <li>Lo único obligatorio es <b>titulo</b> y <b>proyecto</b>. El resto es opcional.</li>
        <li><b>prioridad</b>: baja · media · alta. <b>fechas</b>: AAAA-MM-DD.</li>
        <li>Para encadenar, ponle <b>ref</b> a una tarea y <b>depende_de</b> con esa ref en otra.</li>
        <li>Para amarrarla al release, <b>item_release</b> con el código del ítem (<b>R2-B04</b>) o una lista de códigos. Su avance sale de sus tareas.</li>
        <?php if ($conIADesc): ?><li>Con <b>Generar tareas con IA</b> describes el trabajo y eliges cuántas tareas, el responsable, la prioridad, el ítem y las fechas (o lo dejas a la IA); el JSON queda aquí ya validado.</li><?php endif; ?>
        <li>Primero <b>Validar</b> para ver qué se va a crear; si algo falla, no se crea nada.</li>
      </ul>
      <p class="plan-nota">Proyectos disponibles:
        <?php foreach ($proyectos as $p): ?><span class="plan-chip"><?= e($p['nombre']) ?></span><?php endforeach; ?>
      </p>
      <p class="plan-nota">Equipo:
        <?php foreach ($miembros as $m): ?><span class="plan-chip"><?= e($m['nombre']) ?></span><?php endforeach; ?>
      </p>
    <?php else: ?>
      <?php if (!empty($reporte['errores'])): ?>
        <h2 class="font-display"><i class="fa-solid fa-circle-exclamation" style="color:var(--c-danger)"></i>
          No se creó nada</h2>
        <p class="plan-nota">Corrige esto y vuelve a intentar (es todo o nada):</p>
        <ul class="plan-errores">
          <?php foreach ($reporte['errores'] as $e): ?><li><i class="fa-solid fa-xmark"></i> <?= e($e) ?></li><?php endforeach; ?>
        </ul>
      <?php elseif (($reporte['creadas'] ?? 0) + ($reporte['actualizadas'] ?? 0) > 0): ?>
        <?php $partes = [];
          if ($reporte['creadas'] > 0)      $partes[] = $reporte['creadas'] . ' creada' . ($reporte['creadas'] === 1 ? '' : 's');
          if ($reporte['actualizadas'] > 0) $partes[] = $reporte['actualizadas'] . ' actualizada' . ($reporte['actualizadas'] === 1 ? '' : 's'); ?>
        <?php if (!empty($reporte['amarradas'])) $partes[] = $reporte['amarradas'] . ' amarrada' . ($reporte['amarradas'] === 1 ? '' : 's') . ' al release'; ?>
        <h2 class="font-display"><i class="fa-solid fa-circle-check" style="color:var(--c-success)"></i>
          <?= e(implode(' · ', $partes)) ?></h2>
      <?php else: ?>
        <?php
          $nCrear = 0; $nAct = 0;
          foreach ($reporte['filas'] as $f) { (($f['accion'] ?? 'crear') === 'actualizar') ? $nAct++ : $nCrear++; }
        ?>
        <h2 class="font-display"><i class="fa-solid fa-circle-check" style="color:var(--c-success)"></i>
          Todo en orden</h2>
        <p class="plan-nota">
          <?php if ($nAct > 0): ?>Se actualizarán <b><?= $nAct ?></b><?php endif; ?>
          <?php if ($nAct > 0 && $nCrear > 0): ?> y <?php endif; ?>
          <?php if ($nCrear > 0 || $nAct === 0): ?>Se crearán <b><?= $nCrear ?></b><?php endif; ?>
          tarea(s). Pulsa <b>Importar</b> cuando quieras.
        </p>
      <?php endif; ?>

      <?php if (!empty($reporte['filas'])):
        // Ítem del release de cada fila: al validar se elige aquí (va con el formulario al importar)
        $conItems = class_exists('Releases') && (new JsonStore('rel_items'))->all();
        $yaImporto = ($reporte['creadas'] ?? 0) + ($reporte['actualizadas'] ?? 0) > 0;
        $opcionesItem = function (int $pid, string $sel) {
            static $cache = [];
            if (!isset($cache[$pid])) {
                $suyos = array_column(Releases::itemsDeProyecto($pid), 'codigo');
                $grupos = [];
                foreach (Releases::items() as $it) {
                    $g = in_array($it['codigo'], $suyos, true) ? 'Del equipo de este proyecto' : 'Equipo ' . $it['equipo'];
                    $grupos[$g][] = $it;
                }
                uksort($grupos, fn($a, $b) => [$a !== 'Del equipo de este proyecto', $a] <=> [$b !== 'Del equipo de este proyecto', $b]);
                $cache[$pid] = $grupos;
            }
            $h = '<option value="">— Sin ítem del release —</option>';
            foreach ($cache[$pid] as $g => $its) {
                $h .= '<optgroup label="' . e($g) . '">';
                foreach ($its as $it) $h .= '<option value="' . e($it['codigo']) . '"' . ($it['codigo'] === $sel ? ' selected' : '') . '>'
                    . e($it['codigo'] . ' · ' . mb_strimwidth((string)$it['titulo'], 0, 64, '…') . ($g !== 'Del equipo de este proyecto' ? ' · ' . $it['equipo'] : '') . ($it['estado'] === 'cerrado' ? ' (terminado)' : '')) . '</option>';
                $h .= '</optgroup>';
            }
            return $h;
        };
      ?>
      <?php if ($conItems && !$yaImporto && empty($reporte['errores'])): ?>
      <p class="plan-item-ayuda"><i class="fa-solid fa-route"></i> Elige en cada tarea a qué <b>ítem del release</b> se amarra (o ponlo en el JSON con <b>item_release</b>). Se guarda al pulsar <b>Importar</b>.</p>
      <?php endif; ?>
      <div class="plan-lista">
        <?php foreach ($reporte['filas'] as $f): $conSelect = $conItems && !$yaImporto && empty($reporte['errores']) && count($f['items'] ?? []) <= 1; ?>
        <div class="plan-fila <?= $f['error'] ? 'mala' : (empty($f['avisos']) ? 'ok' : 'aviso') ?>">
          <div class="plan-fila-top">
            <i class="fa-solid <?= $f['error'] ? 'fa-circle-xmark' : (empty($f['avisos']) ? 'fa-circle-check' : 'fa-circle-exclamation') ?>"></i>
            <b><?= e($f['titulo']) ?></b>
            <span class="plan-tag"><?= e($f['proyecto']) ?></span>
            <?php if (($f['accion'] ?? 'crear') === 'actualizar'): ?><span class="plan-tag-act"><i class="fa-solid fa-rotate"></i> Actualiza</span><?php endif; ?>
            <?php if ($f['asignados']): ?><span class="plan-tag-p"><i class="fa-solid fa-user"></i> <?= e(implode(', ', $f['asignados'])) ?></span><?php endif; ?>
            <?php if (!empty($f['items']) && !$conSelect): ?><span class="plan-tag-p"><i class="fa-solid fa-route"></i> <?= e(implode(', ', $f['items'])) ?></span><?php endif; ?>
          </div>
          <?php if ($conSelect): ?>
          <label class="plan-item"><i class="fa-solid fa-route" aria-hidden="true"></i><span class="mc-sr-only">Ítem del release</span>
            <input type="hidden" name="item_titulo[<?= (int)$f['n'] ?>]" value="<?= e($f['titulo']) ?>" form="plan-form">
            <input type="hidden" name="item_json[<?= (int)$f['n'] ?>]" value="<?= e((string)($f['items'][0] ?? '')) ?>" form="plan-form">
            <select name="item[<?= (int)$f['n'] ?>]" form="plan-form" class="select-meca" aria-label="Ítem del release de «<?= e($f['titulo']) ?>»">
              <?= $opcionesItem((int)($f['proyecto_id'] ?? 0), (string)($f['items'][0] ?? '')) ?>
            </select>
          </label>
          <?php endif; ?>
          <?php foreach ($f['avisos'] as $a): ?><small class="plan-aviso">↳ <?= e($a) ?></small><?php endforeach; ?>
          <?php if ($f['error']): ?><small class="plan-mal">↳ <?= e($f['error']) ?></small><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (($reporte['creadas'] ?? 0) + ($reporte['actualizadas'] ?? 0) > 0): ?>
        <a href="index.php" class="btn-primary btn-meca" style="margin-top:16px"><i class="fa-solid fa-table-columns"></i> Ver el tablero</a>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php if ($conIAPlan):
    // Solo los proyectos que esta persona gestiona (el Scrum Master, los suyos).
    $proyIA = array_values(array_filter($proyectos, fn($p) => puedeGestionar((int)$p['id'])));
    // Para elegir a mano: la gente y los ítems del release de cada proyecto (assets/ia.js
    // cambia las listas al elegir otro proyecto)
    if (is_file(__DIR__ . '/lib/releases/Releases.php')) require_once __DIR__ . '/lib/releases/Releases.php';
    $mapaM = [];
    foreach ($miembros as $m) $mapaM[(int)$m['id']] = $m;
    $datosIA = [];
    foreach ($proyIA as $p) {
        $idsP = ProyectoRepo::miembrosDe($p);
        $idsP = $idsP === null ? array_keys($mapaM) : array_merge($idsP, [ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0), ProyectoRepo::scrumDe($p)]);
        $genteP = [];
        foreach (array_unique(array_map('intval', $idsP)) as $mid) {
            if (isset($mapaM[$mid])) $genteP[] = ['id' => $mid, 'n' => (string)$mapaM[$mid]['nombre'], 'r' => (string)($mapaM[$mid]['rol'] ?? '')];
        }
        usort($genteP, fn($a, $b) => strcmp($a['n'], $b['n']));
        $itemsP = [];
        if (class_exists('Releases')) foreach (Releases::itemsDeProyecto((int)$p['id']) as $it) {
            if (($it['estado'] ?? '') !== 'cerrado') $itemsP[] = ['c' => (string)$it['codigo'], 't' => mb_strimwidth((string)$it['titulo'], 0, 70, '…')];
        }
        $datosIA[(int)$p['id']] = ['gente' => $genteP, 'items' => $itemsP];
    }
    $p0 = $proyIA[0] ?? null;
    $d0 = $p0 ? $datosIA[(int)$p0['id']] : ['gente' => [], 'items' => []];
    $optsGente = ['' => ['Que lo elija la IA', 'Por el rol y la carga de cada uno']];
    foreach ($d0['gente'] as $g) $optsGente[(string)$g['id']] = [$g['n'], $g['r']];
    $optsItems = ['' => ['Que lo elija la IA', 'Por el tema de cada tarea']];
    foreach ($d0['items'] as $x) $optsItems[$x['c']] = [$x['c'], $x['t']];
    $optsCant = ['' => ['Las que hagan falta', 'La IA divide el trabajo si es grande']];
    foreach (range(1, 10) as $nC) $optsCant[(string)$nC] = $nC === 1 ? '1 tarea' : $nC . ' tareas';
    $optsPrio = ['' => ['Según la urgencia', 'La decide la IA']] + array_map(fn($v) => $v[0], Catalogo::prioridades()); ?>
<!-- Modal: generar las tareas con IA. Lo envía assets/ia.js (ia_plan_descripcion o, solo con
     el documento, ia_plan_documento) y deja el JSON en el editor ya validado; no crea nada. -->
<dialog id="dlg-ia-plan" class="dlg-meca dlg-ia">
  <form id="ia-plan-form" class="dlg-form" enctype="multipart/form-data" data-con-descripcion="<?= $conIADesc ? '1' : '' ?>" data-con-documento="<?= $conIADoc ? '1' : '' ?>">
    <header>
      <h3 class="font-display"><?= UI::iconoDS('ia', 20) ?> Generar tareas con IA<small><?= $conIADesc
          ? 'Describe el trabajo y elige lo que ya tengas claro; lo demás lo decide la IA. Nada se crea hasta que pulses «Importar tareas».'
          : 'Sube el plan (Excel, PDF, Word o CSV): la IA lo desglosa en tareas con su orden y las deja en el editor. Nada se crea hasta que valides e importes.' ?></small></h3>
      <button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>

    <div class="ia-cuerpo">
      <div class="campo"><span>Proyecto</span>
        <?= UI::selectDS('proyecto_id', array_column(array_map(fn($p) => ['k' => (string)(int)$p['id'], 'v' => (string)$p['nombre']], $proyIA), 'v', 'k'), $p0 ? (string)(int)$p0['id'] : '',
            ['tam' => 'md', 'aria' => 'Proyecto', 'attrs' => 'required data-ia-plan-proy']) ?></div>
      <label class="campo"><span><?= $conIADesc ? '¿Qué hay que hacer?' : 'Indicaciones (opcional)' ?></span>
        <span class="mc-input mc-input--multiline"><textarea class="mc-input__control" name="descripcion" rows="<?= $conIADesc ? 5 : 3 ?>" maxlength="8000"
          placeholder="<?= $conIADesc ? 'Ej.: Agregar el filtro por carrera al reporte de matrículas y probarlo con los datos de octubre.' : 'Ej.: solo la hoja «Estudiante»; agrupa por módulo…' ?>"></textarea></span>
      </label>
      <?php if ($conIADesc): ?>
      <div class="ia-plan-grid">
        <div class="campo"><span>¿Cuántas tareas?</span><?= UI::selectDS('cantidad', $optsCant, '', ['tam' => 'md', 'aria' => 'Cuántas tareas']) ?></div>
        <div class="campo"><span>Responsable</span><?= UI::selectDS('responsable', $optsGente, '', ['tam' => 'md', 'aria' => 'Responsable', 'buscar' => true, 'attrs' => 'data-ia-plan-gente']) ?></div>
        <div class="campo ia-plan-prio"><span>Prioridad</span><?= UI::selectDS('prioridad', $optsPrio, '', ['tam' => 'md', 'aria' => 'Prioridad']) ?></div>
        <div class="campo" data-ia-plan-item-campo<?= $d0['items'] ? '' : ' hidden' ?>><span>Ítem del release</span><?= UI::selectDS('item_release', $optsItems, '', ['tam' => 'md', 'aria' => 'Ítem del release', 'buscar' => true, 'attrs' => 'data-ia-plan-items']) ?></div>
        <div class="ia-plan-fechas">
          <label class="campo"><span>Empieza <small>(opcional)</small></span><input class="input-meca" type="date" name="fecha_inicio"></label>
          <label class="campo"><span>Para cuándo <small>(opcional)</small></span><input class="input-meca" type="date" name="fecha_limite"></label>
        </div>
      </div>
      <?php if ($conIADoc): ?>
      <details class="ia-plan-doc"><summary><?= UI::iconoDS('documento', 16) ?> Agregar un documento de apoyo <small>(opcional)</small></summary>
        <?= UI::archivo(['name' => 'archivo', 'variante' => 'zona', 'accept' => '.xlsx,.docx,.pdf,.csv,.txt,.md,application/pdf',
                         'ayuda' => 'Solo el documento, sin descripción: la IA desglosa el plan entero. Excel, PDF, Word o CSV · máx 15 MB', 'maxMB' => 15]) ?>
      </details>
      <?php endif; ?>
      <?php else: ?>
      <div class="campo"><span>Documento del plan</span>
        <?= UI::archivo(['name' => 'archivo', 'variante' => 'zona', 'accept' => '.xlsx,.docx,.pdf,.csv,.txt,.md,application/pdf', 'ayuda' => 'Excel, PDF, Word o CSV · máx 15 MB', 'maxMB' => 15]) ?>
      </div>
      <?php endif; ?>
    </div>

    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><?= UI::iconoDS('ia', 18) ?> Generar tareas</button>
    </footer>
  </form>
  <script type="application/json" id="ia-plan-datos"><?= json_encode($datosIA, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</dialog>
<?php endif; ?>

<script>
  document.getElementById('plan-ejemplo')?.addEventListener('click', () => {
    const ta = document.getElementById('plan-json');
    if (ta.value.trim() && !confirm('¿Reemplazar lo que hay con el ejemplo?')) return;
    ta.value = document.getElementById('plan-tpl-ejemplo').content.textContent;
  });
  // Si suben archivo, avisar en el textarea que se usará ese
  document.querySelector('.plan-file input')?.addEventListener('change', (e) => {
    const f = e.target.files[0];
    if (f) document.getElementById('plan-json').placeholder = 'Se importará el archivo: ' + f.name;
  });
</script>

<?php UI::fin(); ?>
