<?php
/**
 * Planificación: importar tareas en lote desde un JSON.
 * Solo para administradores. El admin pega (o sube) el JSON, puede validarlo
 * sin crear nada, y al importar se crean todas las tareas de una.
 */
require_once __DIR__ . '/lib/bootstrap.php';
Auth::requiereAdmin();

$proyectos = (new ProyectoRepo())->todos();
$miembros  = (new MiembroRepo())->todos();

$reporte = null;
$jsonPegado = '';
$modo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modo = $_POST['modo'] ?? 'validar';   // 'validar' | 'importar'
    $jsonPegado = (string)($_POST['json'] ?? '');

    // Si subieron un archivo, ese manda sobre el textarea
    if (!empty($_FILES['archivo']['tmp_name']) && ($_FILES['archivo']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $jsonPegado = (string)file_get_contents($_FILES['archivo']['tmp_name']);
    }

    $datos = json_decode(trim($jsonPegado), true);
    if (!is_array($datos)) {
        $reporte = ['ok' => false, 'creadas' => 0, 'filas' => [],
                    'errores' => ['El texto no es un JSON válido. Revisa que no falten comas o comillas. (' . json_last_error_msg() . ')']];
    } else {
        $imp = new ImportadorTareas(new ProyectoRepo(), new MiembroRepo(), new TareaRepo());
        $reporte = $imp->procesar($datos, $modo !== 'importar');
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
      "ref": "login"
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
UI::cabecera(
    'Planificar <span class="text-secondary">tareas</span>',
    'Sube un JSON con la planificación y se crean todas las tareas de una. Ideal para cargar el sprint completo.',
    ''
);
?>

<div class="plan-grid">

  <!-- Editor -->
  <section class="card-base plan-card">
    <form method="post" action="planificar.php" enctype="multipart/form-data" class="plan-form">
      <div class="plan-toolbar">
        <h2 class="font-display"><i class="fa-solid fa-file-code text-secondary"></i> JSON de tareas</h2>
        <div class="plan-acc">
          <label class="btn-outline btn-meca btn-sm plan-file">
            <i class="fa-solid fa-file-arrow-up"></i> Subir .json
            <input type="file" name="archivo" accept="application/json,.json" hidden>
          </label>
          <button type="button" class="btn-ghost btn-meca btn-sm" id="plan-ejemplo">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Ejemplo
          </button>
        </div>
      </div>
      <textarea name="json" id="plan-json" class="input-meca plan-text" spellcheck="false"
        placeholder='Pega aquí el JSON…'><?= e($jsonPegado) ?></textarea>
      <input type="hidden" name="modo" id="plan-modo" value="validar">
      <div class="plan-botones">
        <button class="btn-outline btn-meca" value="validar" onclick="document.getElementById('plan-modo').value='validar'">
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
      <?php elseif ($reporte['creadas'] > 0): ?>
        <h2 class="font-display"><i class="fa-solid fa-circle-check" style="color:var(--c-success)"></i>
          <?= (int)$reporte['creadas'] ?> tarea<?= $reporte['creadas'] === 1 ? '' : 's' ?> creada<?= $reporte['creadas'] === 1 ? '' : 's' ?></h2>
      <?php else: ?>
        <h2 class="font-display"><i class="fa-solid fa-circle-check" style="color:var(--c-success)"></i>
          Todo en orden</h2>
        <p class="plan-nota">Se crearán <?= count($reporte['filas']) ?> tareas. Pulsa <b>Importar</b> cuando quieras.</p>
      <?php endif; ?>

      <?php if (!empty($reporte['filas'])): ?>
      <div class="plan-lista">
        <?php foreach ($reporte['filas'] as $f): ?>
        <div class="plan-fila <?= $f['error'] ? 'mala' : (empty($f['avisos']) ? 'ok' : 'aviso') ?>">
          <div class="plan-fila-top">
            <i class="fa-solid <?= $f['error'] ? 'fa-circle-xmark' : (empty($f['avisos']) ? 'fa-circle-check' : 'fa-circle-exclamation') ?>"></i>
            <b><?= e($f['titulo']) ?></b>
            <span class="plan-tag"><?= e($f['proyecto']) ?></span>
            <?php if ($f['asignados']): ?><span class="plan-tag-p"><i class="fa-solid fa-user"></i> <?= e(implode(', ', $f['asignados'])) ?></span><?php endif; ?>
          </div>
          <?php foreach ($f['avisos'] as $a): ?><small class="plan-aviso">↳ <?= e($a) ?></small><?php endforeach; ?>
          <?php if ($f['error']): ?><small class="plan-mal">↳ <?= e($f['error']) ?></small><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($reporte['creadas'] > 0): ?>
        <a href="index.php" class="btn-primary btn-meca" style="margin-top:16px"><i class="fa-solid fa-table-columns"></i> Ver el tablero</a>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

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
