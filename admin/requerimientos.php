<?php
/**
 * Requerimientos sueltos.
 *
 * Llegan peticiones que no caen en ningún proyecto. El administrador las
 * registra y decide a quién derivarlas, viendo de un vistazo cuánto tiene
 * encima cada persona (tareas abiertas + requerimientos ya derivados).
 *
 * El resto del equipo ve la misma pantalla en modo bandeja: los suyos arriba
 * y los demás abajo, para saber qué hay dando vueltas aunque no les toque.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$reqRepo    = new RequerimientoRepo();
$miembros   = new MiembroRepo();
$tareasRepo = new TareaRepo();

// Con "ver como" activo, el administrador ve exactamente lo que vería esa
// persona: su bandeja, sin el resumen ni los botones de derivar. Si no, cada
// quien ve lo suyo y el admin ve el módulo completo.
$verComo  = verComo();
$soyAdmin = esAdmin() && !$verComo;
$yoId     = $verComo ? (int)$verComo['id'] : (int)(Auth::usuario()['id'] ?? 0);
$mapa     = $miembros->mapa();

$requerimientos = $reqRepo->todos();
$mios  = array_values(array_filter($requerimientos, fn($r) => RequerimientoRepo::tieneAsignado($r, $yoId)));
$otros = array_values(array_filter($requerimientos, fn($r) => !RequerimientoRepo::tieneAsignado($r, $yoId)));

/**
 * Carga de cada persona: tareas abiertas + requerimientos abiertos. Es lo que
 * el administrador necesita para repartir sin cargarle todo al mismo.
 */
$carga = [];
if ($soyAdmin) {
    $finales = Catalogo::estadosFinales();
    $abiertas = [];
    foreach ($tareasRepo->todas() as $t) {
        if (in_array($t['estado'] ?? '', $finales, true)) continue;
        foreach (TareaRepo::asignadosDe($t) as $mid) {
            $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
        }
    }
    foreach ($miembros->todos() as $m) {
        $mid = (int)$m['id'];
        $tareas = (int)($abiertas[$mid] ?? 0);
        $reqs   = $reqRepo->abiertosDe($mid);
        $carga[] = [
            'miembro' => $m,
            'rol'     => trim($m['rol'] ?? '') ?: 'Sin rol',
            'tareas'  => $tareas,
            'reqs'    => $reqs,
            'total'   => $tareas + $reqs,
        ];
    }
    // Del más libre al más cargado: la decisión salta a la vista
    usort($carga, fn($a, $b) => $a['total'] <=> $b['total'] ?: strcasecmp($a['miembro']['nombre'], $b['miembro']['nombre']));
}
$topCarga = $carga ? max(array_column($carga, 'total')) : 0;

// Roles presentes, con cuánta gente hay en cada uno: son los filtros de la
// carga. Se sacan del equipo real y no del catálogo, para no ofrecer filtros
// que no seleccionan a nadie.
$rolesCarga = [];
foreach ($carga as $c) {
    $rolesCarga[$c['rol']] = ($rolesCarga[$c['rol']] ?? 0) + 1;
}
ksort($rolesCarga, SORT_NATURAL | SORT_FLAG_CASE);

// Opciones del filtro por rol, compartidas por los dos selectores de personas
$opcionesRol = ['' => 'Todos (' . count($carga) . ')'];
foreach ($rolesCarga as $rolNombre => $cuantos) {
    $opcionesRol[$rolNombre] = $rolNombre . ' (' . $cuantos . ')';
}

$prioridades = Catalogo::prioridades();
$estados     = RequerimientoRepo::ESTADOS;

/**
 * Selector de personas con su carga: la misma pieza en el asistente de
 * "nuevo requerimiento" y en el de derivar uno que ya existe. El color dice
 * quién está libre sin tener que ir a comprobarlo a otra pantalla.
 *
 * $prefijo distingue los dos usos ('nr' y 'dv') para que el JS y el filtro
 * de rol de cada modal no se pisen.
 */
function pickerPersonas(array $carga, string $campo, string $prefijo): void
{
    $tope = $carga ? max(array_column($carga, 'total')) : 0;
    ?>
    <div class="dv-lista" data-picker="<?= e($prefijo) ?>">
      <?php foreach ($carga as $c):
          $m = $c['miembro'];
          $pct = $tope > 0 ? round($c['total'] / $tope * 100) : 0;
          $nivel = $pct >= 66 ? 'alta' : ($pct >= 33 ? 'media' : 'baja');
      ?>
      <label class="dv-persona carga-<?= $nivel ?>" data-id="<?= (int)$m['id'] ?>" data-rol="<?= e($c['rol']) ?>">
        <input type="checkbox" name="<?= e($campo) ?>" value="<?= (int)$m['id'] ?>">
        <span class="dv-check"><i class="fa-solid fa-check"></i></span>
        <?= UI::avatar($m, 34) ?>
        <span class="dv-datos">
          <strong class="truncate"><?= e($m['nombre']) ?></strong>
          <small class="truncate"><?= e($c['rol']) ?></small>
        </span>
        <span class="dv-medida">
          <span class="dv-barra"><span style="width:<?= max($pct, $c['total'] > 0 ? 8 : 0) ?>%"></span></span>
          <small><?= $c['tareas'] ?> tareas · <?= $c['reqs'] ?> req.</small>
        </span>
        <span class="dv-carga" title="<?= $c['tareas'] ?> tareas abiertas · <?= $c['reqs'] ?> requerimientos"><?= $c['total'] ?></span>
      </label>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Una fila de la lista. Deliberadamente escueta: estado, título, quién lo
 * pide, fechas y responsables. Todo lo demás (detalle largo, acciones) vive
 * en la ficha que se abre al pulsarla — antes cada tarjeta intentaba mostrarlo
 * todo a la vez y no se sabía dónde mirar.
 */
function filaRequerimiento(array $r, array $mapa, int $yoId, array $prioridades, array $estados): void
{
    $id     = (int)$r['id'];
    $estado = RequerimientoRepo::estadoValido($r['estado'] ?? '');
    [$etEstado, $icEstado] = $estados[$estado];
    $prio   = $r['prioridad'] ?? '';
    $ids    = RequerimientoRepo::asignadosDe($r);
    $gente  = array_values(array_filter(array_map(fn($i) => $mapa[$i] ?? null, $ids)));
    $esMio  = in_array($yoId, $ids, true);
    $ini    = trim((string)($r['fecha_inicio'] ?? ''));
    $fin    = RequerimientoRepo::fechaFin($r);

    // La ficha se rellena en el navegador con estos datos: así no hay que
    // repetir el detalle completo de cada requerimiento en el HTML.
    $datos = [
        'id'          => $id,
        'titulo'      => (string)$r['titulo'],
        'detalle'     => (string)($r['detalle'] ?? ''),
        'solicitante' => (string)($r['solicitante'] ?? ''),
        'estado'      => $estado,
        'estadoTxt'   => $etEstado,
        'prioridad'   => isset($prioridades[$prio]) ? $prioridades[$prio][0] : '',
        'inicio'      => $ini,
        'fin'         => $fin,
        'creado'      => (string)($r['creado'] ?? ''),
        'cerrado'     => RequerimientoRepo::cerrado($r),
        'mio'         => $esMio,
        'asignados'   => implode(',', $ids),
        'personas'    => array_map(fn($m) => ['nombre' => $m['nombre'], 'rol' => $m['rol'] ?? ''], $gente),
    ];
    ?>
    <button type="button" class="req-fila req-e-<?= e($estado) ?><?= $esMio ? ' req-fila-mia' : '' ?>"
            data-req="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>">
      <span class="req-f-estado" title="<?= e($etEstado) ?>"><i class="fa-solid <?= e($icEstado) ?>"></i></span>

      <span class="req-f-txt">
        <strong class="truncate"><?= e($r['titulo']) ?></strong>
        <small class="truncate">
          <?php if (!empty($r['solicitante'])): ?>Lo pide <?= e($r['solicitante']) ?> · <?php endif; ?>
          <?= e($etEstado) ?>
        </small>
      </span>

      <?php if (isset($prioridades[$prio])): ?>
      <span class="req-f-prio prioridad-<?= e($prio) ?>"><?= e($prioridades[$prio][0]) ?></span>
      <?php endif; ?>

      <span class="req-f-fecha"><?= $fin !== '' ? e($fin) : ($ini !== '' ? e($ini) : '—') ?></span>

      <span class="req-f-gente">
        <?= $gente ? UI::avatarStack($gente, 3, 26) : '<span class="req-libre"><i class="fa-solid fa-inbox"></i></span>' ?>
      </span>

      <i class="fa-solid fa-chevron-right req-f-mas"></i>
    </button>
    <?php
}

UI::inicio($soyAdmin ? 'Requerimientos' : 'Bandeja de entrada', 'requerimientos');
UI::cabecera(
    $soyAdmin
        ? 'Requerimientos <span class="text-secondary">sueltos</span>'
        : 'Bandeja de <span class="text-secondary">entrada</span>',
    $soyAdmin
        ? 'Peticiones que no pertenecen a ningún proyecto. Derívalas mirando cuánto tiene encima cada persona.'
        : ($verComo
            ? 'Lo que ve <b>' . e($verComo['nombre']) . '</b>: sus requerimientos arriba y el resto del equipo abajo.'
            : 'Peticiones sueltas del equipo. Arriba las tuyas; abajo, el resto, para que sepas qué hay en curso.'),
    $soyAdmin
        ? '<button class="btn-primary btn-meca" onclick="document.getElementById(\'dlg-req-nuevo\').showModal()">
             <i class="fa-solid fa-plus"></i> Nuevo requerimiento
           </button>'
        : ''
);
?>

<?php if ($soyAdmin): ?>
<!-- Resumen en gráficos: en qué estado están los requerimientos y a quién se
     los estamos mandando. La carga persona a persona vive ahora donde de
     verdad se usa: dentro del selector, al derivar. -->
<section class="card-base req-resumen">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-chart-pie text-secondary"></i> Resumen</h2>
    <span class="ajuste-ayuda"><?= count($requerimientos) ?> requerimiento(s) registrados</span>
  </div>

  <?php if (!$requerimientos): ?>
    <p class="ajuste-ayuda">Los gráficos aparecen en cuanto registres el primero.</p>
  <?php else: ?>
  <div class="req-graficos">

    <!-- 1. En qué estado están -->
    <div class="req-grafico">
      <h3>Estado</h3>
      <div class="mc-donut-wrap">
        <?php
          $porEstado = [];
          foreach ($requerimientos as $r) {
              $k = RequerimientoRepo::estadoValido($r['estado'] ?? '');
              $porEstado[$k] = ($porEstado[$k] ?? 0) + 1;
          }
          $colEstadoReq = [
              'pendiente'  => 'var(--c-warning)',
              'asignado'   => 'var(--c-secondary)',
              'hecho'      => 'var(--c-success)',
              'descartado' => 'color-mix(in srgb, var(--c-text-soft) 55%, transparent)',
          ];
          $segs = [];
          foreach ($estados as $k => [$et, $ic]) {
              $segs[] = [(int)($porEstado[$k] ?? 0), $colEstadoReq[$k]];
          }
          $abiertosTotal = ($porEstado['pendiente'] ?? 0) + ($porEstado['asignado'] ?? 0);
          echo UI::donut($segs, (string)$abiertosTotal, 'abiertos');
        ?>
        <ul class="mc-legend">
          <?php foreach ($estados as $k => [$et, $ic]): ?>
          <li>
            <span class="mc-dot" style="background:<?= $colEstadoReq[$k] ?>"></span>
            <?= e($et) ?> <b><?= (int)($porEstado[$k] ?? 0) ?></b>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- 2. A quién se los estamos mandando -->
    <div class="req-grafico">
      <h3>A quién se le asigna</h3>
      <?php
        $porPersona = [];
        foreach ($requerimientos as $r) {
            foreach (RequerimientoRepo::asignadosDe($r) as $mid) {
                $porPersona[$mid] = ($porPersona[$mid] ?? 0) + 1;
            }
        }
        arsort($porPersona);
        $filasP = [];
        foreach (array_slice($porPersona, 0, 8, true) as $mid => $n) {
            $m = $mapa[$mid] ?? null;
            if (!$m) continue;
            $filasP[] = [explode(' ', trim($m['nombre']))[0] . ' ' . (explode(' ', trim($m['nombre']))[1] ?? ''),
                         $n, Catalogo::colorDe($m['color'] ?? 0)];
        }
        $sinDerivar = count(array_filter($requerimientos, fn($r) => !RequerimientoRepo::asignadosDe($r)));
      ?>
      <?php if ($filasP): ?>
        <?= UI::barras($filasP) ?>
      <?php else: ?>
        <p class="ajuste-ayuda">Todavía no has derivado ninguno.</p>
      <?php endif; ?>
      <?php if ($sinDerivar): ?>
      <p class="req-pendiente-nota">
        <i class="fa-solid fa-inbox"></i> <b><?= $sinDerivar ?></b> sin derivar todavía
      </p>
      <?php endif; ?>
    </div>

    <!-- 3. Con qué urgencia llegan -->
    <div class="req-grafico">
      <h3>Prioridad</h3>
      <?php
        $colPrioReq = [];
        foreach ((array)Config::get('prioridades') as $k => $v) $colPrioReq[$k] = $v['color'] ?? '#94a3b8';
        $porPrio = [];
        foreach ($requerimientos as $r) {
            $k = $r['prioridad'] ?? '';
            if (isset($prioridades[$k])) $porPrio[$k] = ($porPrio[$k] ?? 0) + 1;
        }
        $filasPr = [];
        foreach ($prioridades as $k => [$et, $ic]) {
            $filasPr[] = [$et, (int)($porPrio[$k] ?? 0), $colPrioReq[$k] ?? '#94a3b8'];
        }
        echo UI::barras($filasPr);
      ?>
    </div>

  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$requerimientos): ?>
  <?= UI::vacio('fa-inbox', 'No hay requerimientos sueltos',
        $soyAdmin
          ? 'Cuando llegue una petición que no encaje en ningún proyecto, regístrala con «Nuevo requerimiento».'
          : 'Cuando el administrador registre alguno, aparecerá aquí.') ?>
<?php else: ?>

  <?php if ($mios): ?>
  <section class="req-bloque">
    <h2 class="req-titulo"><i class="fa-solid fa-user-check text-secondary"></i> <?= $verComo ? 'Para ' . e(explode(' ', $verComo['nombre'])[0]) : 'Para ti' ?>
      <span class="tabla-count"><?= count($mios) ?></span>
    </h2>
    <div class="req-lista">
      <?php foreach ($mios as $r) filaRequerimiento($r, $mapa, $yoId, $prioridades, $estados); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($otros): ?>
  <section class="req-bloque">
    <h2 class="req-titulo">
      <i class="fa-solid fa-inbox text-secondary"></i>
      <?= $mios ? 'Los demás' : 'Requerimientos' ?>
      <span class="tabla-count"><?= count($otros) ?></span>
    </h2>
    <div class="req-lista">
      <?php foreach ($otros as $r) filaRequerimiento($r, $mapa, $yoId, $prioridades, $estados); ?>
    </div>
  </section>
  <?php endif; ?>

<?php endif; ?>

<!-- Ficha del requerimiento: se abre al pulsar una fila y ahí está TODO -->
<dialog id="dlg-req-ficha" class="dlg-meca dlg-ficha">
  <div class="dlg-form">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-inbox text-secondary"></i> <span id="fq-titulo"></span></h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <div class="fq-chips" id="fq-chips"></div>

    <div class="fq-bloque">
      <h4>Detalle</h4>
      <p id="fq-detalle"></p>
    </div>

    <div class="fq-datos">
      <div><span>Lo pide</span><b id="fq-solicitante"></b></div>
      <div><span>Inicio</span><b id="fq-inicio"></b></div>
      <div><span>Entrega</span><b id="fq-fin"></b></div>
      <div><span>Registrado</span><b id="fq-creado"></b></div>
    </div>

    <div class="fq-bloque">
      <h4>Responsables</h4>
      <ul class="fq-personas" id="fq-personas"></ul>
    </div>

    <footer>
      <?php if ($soyAdmin): ?>
      <form method="post" action="actions.php" class="inline-form" id="fq-borrar"
            data-confirmar="Se borrará este requerimiento." data-confirmar-titulo="¿Eliminar?" data-confirmar-ok="Sí, eliminar">
        <input type="hidden" name="accion" value="req_eliminar">
        <input type="hidden" name="id" id="fq-id-borrar">
        <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
      </form>
      <button type="button" class="btn-outline btn-meca" id="fq-derivar"><i class="fa-solid fa-share-from-square"></i> Derivar</button>
      <?php endif; ?>
      <form method="post" action="actions.php" class="inline-form" id="fq-resolver">
        <input type="hidden" name="accion" value="req_estado">
        <input type="hidden" name="id" id="fq-id-estado">
        <input type="hidden" name="estado" value="hecho">
        <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Marcar resuelto</button>
      </form>
    </footer>
  </div>
</dialog>

<?php if ($soyAdmin): ?>
<!-- Modal: nuevo requerimiento. Mismo componente que el resto de modales del
     panel (.dlg-meca + .dlg-form): sin alturas propias ni scrolls a medida,
     que es de donde salían los huecos en blanco. -->
<dialog id="dlg-req-nuevo" class="dlg-meca dlg-req-form">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="req_crear">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-inbox text-secondary"></i> Nuevo requerimiento</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <label class="campo"><span>¿Qué piden? *</span>
      <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Reporte de matrículas para el rectorado">
    </label>
    <label class="campo"><span>Detalle</span>
      <textarea class="input-meca" name="detalle" rows="3" placeholder="Contexto, con quién hablar, qué se espera de entrega…"></textarea>
    </label>

    <div class="campo-doble">
      <label class="campo"><span>¿Quién lo pide?</span>
        <input class="input-meca" name="solicitante" maxlength="80" placeholder="Ej. Secretaría académica">
      </label>
      <label class="campo"><span>Prioridad</span>
        <?= UI::select('prioridad', array_map(fn($v) => $v[0], $prioridades), Catalogo::prioridadValida('')) ?>
      </label>
    </div>
    <div class="campo-doble">
      <label class="campo"><span>Fecha de inicio</span>
        <input class="input-meca" type="date" name="fecha_inicio">
      </label>
      <label class="campo"><span>Fecha de entrega</span>
        <input class="input-meca" type="date" name="fecha_fin">
      </label>
    </div>

    <div class="campo" data-sin-resumen>
      <span>Derivar a <small>(uno o varios; puedes dejarlo para después)</small></span>
      <label class="carga-filtro dv-filtro">
        <span>Rol</span>
        <?= UI::select('nr_rol', $opcionesRol, '', false, 'js-nr-rol') ?>
      </label>
      <?php pickerPersonas($carga, 'asignados[]', 'nr'); ?>
      <small class="campo-ayuda"><span data-nr-n>0</span> seleccionados · si no marcas a nadie, queda en la bandeja.</small>
    </div>

    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Registrar</button>
    </footer>
  </form>
</dialog>

<!-- Modal: derivar uno que ya existe (mismo selector con carga y color) -->
<dialog id="dlg-req-derivar" class="dlg-meca dlg-derivar">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="req_asignar">
    <input type="hidden" name="id" id="dv-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-share-from-square text-secondary"></i> Derivar</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <p class="ajuste-ayuda">«<b id="dv-titulo"></b>». Marca a quién o a quiénes se lo mandas — el número es lo que ya tiene abierto.</p>

    <label class="carga-filtro dv-filtro">
      <span>Rol</span>
      <?= UI::select('dv_rol', $opcionesRol, '', false, 'js-dv-rol') ?>
    </label>

    <?php pickerPersonas($carga, 'asignados[]', 'dv'); ?>

    <footer>
      <!-- Sin marcar a nadie, "Derivar" lo devuelve a la bandeja: es la misma
           acción con la lista vacía, sin una opción aparte que confunda. -->
      <span class="dv-pie ajuste-ayuda"><span id="dv-n">0</span> seleccionados · sin marcar a nadie, vuelve a la bandeja</span>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-paper-plane"></i> Derivar</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<?php UI::fin(); ?>