<?php
/**
 * Requerimientos sueltos. Modulo EXCLUSIVO del administrador.
 *
 * Llegan peticiones que no caen en ningun proyecto. El administrador las
 * registra y decide quien las hace: un requerimiento puede ir a varias
 * personas a la vez, y al asignarlo se le pone fecha de inicio y de entrega.
 *
 * Para repartir sin cargarle todo al mismo, cada persona aparece en el
 * selector con lo que YA tiene encima (tareas de proyecto abiertas +
 * requerimientos sin cerrar), ordenadas de la mas libre a la mas cargada.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/req_ui.php';
Auth::requiereAdmin();

// Con "ver como" activo el panel se mira con los ojos de otra persona, y esa
// persona NO tiene este modulo: se sale de ahi antes de entrar.
if ($verComo = verComo()) {
    redirigir('index.php',
        'Estás viendo el panel como ' . explode(' ', trim($verComo['nombre']))[0]
        . '. Los requerimientos solo se gestionan desde tu propia vista.', 'info');
}

$reqRepo    = new RequerimientoRepo();
$miembros   = new MiembroRepo();
$tareasRepo = new TareaRepo();

// Catálogo de instituciones: un requerimiento puede ser para una o varias.
$instRepo         = new InstitucionRepo();
$instMapa         = $instRepo->mapa();          // [id => institución] para pintar
$institucionesCat = $instRepo->todas();         // ordenadas por nombre, para el selector
$opcionesInst     = [];
foreach ($institucionesCat as $i) { $opcionesInst[(int)$i['id']] = $i['nombre']; }
reqUiInst($instMapa);   // para que las filas pinten el logo de cada institución

$mapa        = $miembros->mapa();
$prioridades = Catalogo::prioridades();
$estados     = RequerimientoRepo::ESTADOS;
$hoy         = date('Y-m-d');

$requerimientos = $reqRepo->todos();

/* ---------- Filtros (por URL, como en el resto del panel) ---------- */

$situaciones = [
    'sin-asignar' => ['Sin asignar', 'fa-inbox',        'sit-sin'],
    'en-curso'    => ['En curso',    'fa-user-check',   'sit-curso'],
    'tarde'       => ['Pasados de fecha', 'fa-triangle-exclamation', 'sit-tarde'],
    'cerrados'    => ['Cerrados',    'fa-circle-check', 'sit-cerrado'],
];

$fSit     = isset($situaciones[$_GET['sit'] ?? '']) ? (string)$_GET['sit'] : '';
$fQ       = trim((string)($_GET['q'] ?? ''));
$fPersona = max(0, (int)($_GET['persona'] ?? 0));
$fInst    = max(0, (int)($_GET['inst'] ?? 0));
$fOrden   = in_array($_GET['orden'] ?? '', ['entrega', 'prioridad', 'nuevos'], true) ? (string)$_GET['orden'] : 'entrega';
$hayFiltro = $fSit !== '' || $fQ !== '' || $fPersona > 0 || $fInst > 0;

// Los contadores de las pastillas van SIEMPRE sobre el total: son el mapa de
// la pantalla, y si se recalcularan sobre lo ya filtrado se quedarian en 0 y
// no habria forma de volver.
$conteo = ['sin-asignar' => 0, 'en-curso' => 0, 'tarde' => 0, 'cerrados' => 0];
foreach ($requerimientos as $r) {
    $conteo[situacionDe($r)]++;
    if (RequerimientoRepo::vencido($r)) $conteo['tarde']++;
}

$lista = array_values(array_filter($requerimientos, function ($r) use ($fSit, $fQ, $fPersona, $fInst) {
    if ($fInst > 0 && !in_array($fInst, RequerimientoRepo::institucionesDe($r), true)) return false;
    // "Pasados de fecha" es un corte transversal, no una situacion mas: puede
    // haber vencidos sin asignar y vencidos en curso.
    if ($fSit === 'tarde') {
        if (!RequerimientoRepo::vencido($r)) return false;
    } elseif ($fSit !== '' && situacionDe($r) !== $fSit) {
        return false;
    }
    if ($fPersona > 0 && !RequerimientoRepo::tieneAsignado($r, $fPersona)) return false;
    if ($fQ !== '') {
        $paja = $r['titulo'] . ' ' . HtmlRico::texto($r['detalle'] ?? '') . ' ' . ($r['solicitante'] ?? '');
        if (mb_stripos($paja, $fQ) === false) return false;
    }
    return true;
}));

// Orden. Por entrega es el util de verdad (lo que vence antes, arriba) y los
// que no tienen fecha caen al final: sin plazo no hay urgencia que ordenar.
$pesoPrio = array_flip(array_keys($prioridades));   // baja=0, media=1, alta=2
usort($lista, function ($a, $b) use ($fOrden, $pesoPrio) {
    if ($fOrden === 'prioridad') {
        $pa = $pesoPrio[$a['prioridad'] ?? ''] ?? -1;
        $pb = $pesoPrio[$b['prioridad'] ?? ''] ?? -1;
        if ($pa !== $pb) return $pb <=> $pa;
    } elseif ($fOrden === 'entrega') {
        $fa = RequerimientoRepo::fechaFin($a) ?: '9999-99-99';
        $fb = RequerimientoRepo::fechaFin($b) ?: '9999-99-99';
        if ($fa !== $fb) return strcmp($fa, $fb);
    }
    return strcmp($b['creado'] ?? '', $a['creado'] ?? '');
});

// Agrupado por situacion salvo cuando se filtra por "pasados de fecha", que
// mezcla situaciones a proposito.
$secciones = ['sin-asignar' => [], 'en-curso' => [], 'cerrados' => []];
foreach ($lista as $r) {
    $secciones[situacionDe($r)][] = $r;
}

/**
 * Carga de cada persona: tareas abiertas + requerimientos abiertos. Es el dato
 * con el que se decide a quien darle el siguiente.
 */
$finales  = Catalogo::estadosFinales();
$abiertas = [];
foreach ($tareasRepo->todas() as $t) {
    if (in_array($t['estado'] ?? '', $finales, true)) continue;
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
    }
}
$carga = [];
foreach ($miembros->todos() as $m) {
    $mid    = (int)$m['id'];
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
// Del mas libre al mas cargado: la decision salta a la vista
usort($carga, fn($a, $b) => $a['total'] <=> $b['total'] ?: strcasecmp($a['miembro']['nombre'], $b['miembro']['nombre']));

// Roles presentes, con cuanta gente hay en cada uno: son los filtros del
// selector. Se sacan del equipo real y no del catalogo, para no ofrecer
// filtros que no seleccionan a nadie.
$rolesCarga = [];
foreach ($carga as $c) {
    $rolesCarga[$c['rol']] = ($rolesCarga[$c['rol']] ?? 0) + 1;
}
ksort($rolesCarga, SORT_NATURAL | SORT_FLAG_CASE);

$opcionesRol = ['' => 'Todos (' . count($carga) . ')'];
foreach ($rolesCarga as $rolNombre => $cuantos) {
    $opcionesRol[$rolNombre] = $rolNombre . ' (' . $cuantos . ')';
}

// Filtro "de quién": solo gente que tiene alguno, para no ofrecer filtros que
// no devuelven nada.
$opcionesPersona = ['0' => 'Cualquiera'];
foreach ($carga as $c) {
    $mid = (int)$c['miembro']['id'];
    $n = 0;
    foreach ($requerimientos as $r) {
        if (RequerimientoRepo::tieneAsignado($r, $mid)) $n++;
    }
    if ($n > 0) $opcionesPersona[(string)$mid] = $c['miembro']['nombre'] . ' (' . $n . ')';
}

/** La URL de esta pantalla cambiando un solo filtro y dejando los demas. */
function urlFiltro(array $cambios): string
{
    $qs = array_filter(array_merge([
        'sit'     => $_GET['sit'] ?? '',
        'q'       => $_GET['q'] ?? '',
        'persona' => $_GET['persona'] ?? '',
        'inst'    => $_GET['inst'] ?? '',
        'orden'   => $_GET['orden'] ?? '',
    ], $cambios), fn($v) => (string)$v !== '' && (string)$v !== '0');
    return 'requerimientos.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * Paso "Responsables": el selector de personas con su carga delante. La misma
 * pieza en "nuevo requerimiento" y en "asignar" uno que ya existe, porque es
 * la misma decisión. El numero de cada quien es lo que ya tiene abierto y el
 * color dice quien esta libre, sin ir a comprobarlo a otra pantalla.
 *
 * $prefijo distingue los dos usos ('nr' y 'dv') para que el filtro de rol de
 * cada asistente no toque la lista del otro.
 */
function panelResponsables(array $carga, array $opcionesRol, string $prefijo): void
{
    $tope = $carga ? max(array_column($carga, 'total')) : 0;
    ?>
    <section class="wz-panel">
      <div class="campo">
        <span>Responsables</span>
        <!-- Espejo de lo marcado, solo para que el paso de Revisión lo liste:
             el asistente resume campos, y una lista de casillas no lo es.
             Sin name, así que no se envía; los ids van en asignados[]. -->
        <input type="hidden" data-md="1" data-picker-resumen="<?= e($prefijo) ?>">

        <label class="carga-filtro dv-filtro">
          <span>Filtrar por rol</span>
          <?= UI::select($prefijo . '_rol', $opcionesRol, '', false, 'js-' . $prefijo . '-rol') ?>
        </label>

        <div class="dv-lista" data-picker="<?= e($prefijo) ?>">
          <?php foreach ($carga as $c):
              $m = $c['miembro'];
              $pct = $tope > 0 ? round($c['total'] / $tope * 100) : 0;
              $nivel = $pct >= 66 ? 'alta' : ($pct >= 33 ? 'media' : 'baja');
          ?>
          <label class="dv-persona carga-<?= $nivel ?>" data-id="<?= (int)$m['id'] ?>"
                 data-rol="<?= e($c['rol']) ?>" data-nombre="<?= e($m['nombre']) ?>">
            <input type="checkbox" name="asignados[]" value="<?= (int)$m['id'] ?>">
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
            <span class="dv-carga" title="<?= $c['total'] ?> pendientes en total: <?= $c['tareas'] ?> tareas de proyecto y <?= $c['reqs'] ?> requerimientos"><?= $c['total'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <small class="campo-ayuda">
          <b data-picker-n="<?= e($prefijo) ?>">0</b> seleccionados · el número de cada quien es lo que ya tiene
          abierto (tareas de proyecto + requerimientos). Sin marcar a nadie, queda «sin asignar».
        </small>
      </div>
    </section>
    <?php
}

/**
 * Paso "Plazo": las dos fechas del encargo, con los mismos atajos y el mismo
 * aviso de duración que el asistente de tareas.
 */
function panelPlazo(string $prefijo, string $inicioPorDefecto = ''): void
{
    ?>
    <section class="wz-panel">
      <div class="campo-doble">
        <label class="campo"><span>Fecha de inicio</span>
          <input class="input-meca" type="date" name="fecha_inicio"
                 data-req-fecha="<?= e($prefijo) ?>-inicio" value="<?= e($inicioPorDefecto) ?>">
        </label>
        <label class="campo"><span>Fecha de entrega</span>
          <input class="input-meca" type="date" name="fecha_fin"
                 data-req-fecha="<?= e($prefijo) ?>-fin">
        </label>
      </div>
      <?= UI::atajosFecha() ?>
    </section>
    <?php
}

UI::inicio('Requerimientos', 'requerimientos');
UI::cabecera(
    'Requerimientos <span class="text-secondary">sueltos</span>',
    'Peticiones que no pertenecen a ningún proyecto. Repártelas con su plazo, mirando cuánto tiene encima cada persona.',
    '<a class="btn-outline btn-meca" href="req_dashboard.php" title="Panel con gráficos de cumplimiento">
       <i class="fa-solid fa-chart-column"></i> Panel
     </a>
     <a class="btn-outline btn-meca" href="instituciones.php" title="Catálogo de instituciones">
       <i class="fa-solid fa-building-columns"></i> Instituciones
     </a>
     <button class="btn-primary btn-meca" onclick="document.getElementById(\'dlg-req-nuevo\').showModal()">
       <i class="fa-solid fa-plus"></i> Nuevo requerimiento
     </button>'
);
?>

<!-- Situación de un vistazo. No son adornos: cada pastilla FILTRA la lista de
     abajo, así que la pantalla contesta "¿qué tengo que hacer?" y no solo
     "¿cuántos hay?". "Pasados de fecha" cruza las otras tres a propósito. -->
<section class="req-situacion">
  <?php foreach ($situaciones as $k => [$label, $icono, $clase]):
      $activo = $fSit === $k;
      $ayudas = [
          'sin-asignar' => 'Abiertos que todavía no tienen responsable',
          'en-curso'    => 'Abiertos y ya repartidos',
          'tarde'       => 'Abiertos a los que se les pasó la fecha de entrega',
          'cerrados'    => 'Resueltos o descartados',
      ];
  ?>
  <a class="estado-tile <?= e($clase) ?><?= $activo ? ' tile-activo' : '' ?>"
     href="<?= e(urlFiltro(['sit' => $activo ? '' : $k])) ?>"
     title="<?= e($ayudas[$k]) ?><?= $activo ? ' · Pulsa otra vez para quitar el filtro' : '' ?>">
    <span class="et-icono"><i class="fa-solid <?= e($icono) ?>"></i></span>
    <span class="et-datos">
      <b class="font-display"><?= (int)$conteo[$k] ?></b>
      <small><?= e($label) ?></small>
    </span>
  </a>
  <?php endforeach; ?>
</section>

<?php
// Métrica: cuántos requerimientos toca cada institución (y cuántos ya resueltos)
$porInstitucion = [];
foreach ($requerimientos as $r) {
    $resuelto = (($r['estado'] ?? '') === 'hecho');
    foreach (RequerimientoRepo::institucionesDe($r) as $iid) {
        if (!isset($instMapa[$iid])) continue;
        if (!isset($porInstitucion[$iid])) $porInstitucion[$iid] = ['total' => 0, 'hechos' => 0];
        $porInstitucion[$iid]['total']++;
        if ($resuelto) $porInstitucion[$iid]['hechos']++;
    }
}
uasort($porInstitucion, fn($a, $b) => $b['total'] <=> $a['total']);
?>
<?php if ($porInstitucion): ?>
<section class="inst-metrica card-base">
  <div class="inst-metrica-tit">
    <span><i class="fa-solid fa-building-columns text-secondary"></i> Requerimientos por institución</span>
    <a href="instituciones.php" class="inst-metrica-link">Catálogo <i class="fa-solid fa-arrow-right"></i></a>
  </div>
  <div class="inst-metrica-lista">
    <?php foreach ($porInstitucion as $iid => $c): $ii = $instMapa[$iid]; $act = ($fInst === (int)$iid); ?>
    <a class="inst-metrica-item<?= $act ? ' activo' : '' ?>" href="<?= e(urlFiltro(['inst' => $act ? 0 : (int)$iid])) ?>" title="<?= e($ii['nombre']) ?>: <?= $c['total'] ?> requerimiento(s), <?= $c['hechos'] ?> resuelto(s)<?= $act ? ' · Pulsa para quitar el filtro' : '' ?>">
      <span class="inst-metrica-logo">
        <?php if (!empty($ii['imagen'])): ?><img src="<?= e($ii['imagen']) ?>" alt=""><?php else: ?><i class="fa-solid fa-building-columns"></i><?php endif; ?>
      </span>
      <span class="inst-metrica-datos">
        <b class="truncate"><?= e($ii['nombre']) ?></b>
        <small><?= $c['hechos'] ?>/<?= $c['total'] ?> resueltos</small>
      </span>
      <b class="inst-metrica-num"><?= $c['total'] ?></b>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="req-tablero">
  <div class="req-principal">

    <!-- Buscador y orden. El filtro por persona contesta "¿qué le he
         mandado a fulano?", que antes obligaba a abrirlos uno a uno. -->
    <form method="get" class="card-base req-toolbar">
      <?php if ($fSit !== ''): ?><input type="hidden" name="sit" value="<?= e($fSit) ?>"><?php endif; ?>
      <label class="req-buscar">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input-meca" type="search" name="q" value="<?= e($fQ) ?>"
               placeholder="Buscar por título, detalle o quién lo pide…">
      </label>
      <label class="req-filtro">
        <span>Responsable</span>
        <?= UI::select('persona', $opcionesPersona, (string)$fPersona, true, 'select-sm') ?>
      </label>
      <label class="req-filtro">
        <span>Ordenar por</span>
        <?= UI::select('orden', [
              'entrega'   => 'Lo que vence antes',
              'prioridad' => 'Prioridad',
              'nuevos'    => 'Los más recientes',
            ], $fOrden, true, 'select-sm') ?>
      </label>
      <button class="btn-outline btn-meca req-buscar-btn"><i class="fa-solid fa-filter"></i> Filtrar</button>
      <?php if ($hayFiltro): ?>
      <a class="req-limpiar" href="requerimientos.php"><i class="fa-solid fa-xmark"></i> Quitar filtros</a>
      <?php endif; ?>
    </form>

    <?php if (!$requerimientos): ?>
      <?= UI::vacio('fa-inbox', 'No hay requerimientos sueltos',
            'Cuando llegue una petición que no encaje en ningún proyecto, regístrala con «Nuevo requerimiento» y asígnala a quien la vaya a hacer.') ?>

    <?php elseif (!$lista): ?>
      <?= UI::vacio('fa-filter-circle-xmark', 'Nada con esos filtros',
            'Ningún requerimiento cumple lo que has pedido. Quita algún filtro para volver a verlos todos.') ?>

    <?php elseif ($fSit === 'tarde'): ?>
      <!-- Este filtro mezcla situaciones a propósito (los hay sin asignar y
           en curso), así que va en una sola lista. -->
      <?php bloqueRequerimientos('Pasados de fecha', 'fa-triangle-exclamation', $lista, $mapa, $prioridades, $estados,
            'lo que ya debería estar entregado') ?>

    <?php else: ?>
      <?php
        bloqueRequerimientos('Sin asignar', 'fa-inbox', $secciones['sin-asignar'], $mapa, $prioridades, $estados,
            'esperan a que decidas quién lo hace');
        bloqueRequerimientos('En curso', 'fa-user-check', $secciones['en-curso'], $mapa, $prioridades, $estados);
        bloqueRequerimientos('Cerrados', 'fa-circle-check', $secciones['cerrados'], $mapa, $prioridades, $estados);
      ?>
    <?php endif; ?>
  </div>

  <!-- Carga del equipo. Antes solo se veía dentro del modal, en el momento de
       asignar; aquí está siempre delante, que es cuando se decide a quién
       darle lo siguiente. -->
  <aside class="req-lateral">
    <section class="card-base carga-panel">
      <div class="carga-panel-cab">
        <h2 class="font-display"><i class="fa-solid fa-scale-unbalanced text-secondary"></i> Carga del equipo</h2>
        <?php $libres = count(array_filter($carga, fn($c) => $c['total'] === 0)); ?>
        <p class="ajuste-ayuda">
          Tareas de proyecto abiertas + requerimientos sin cerrar.
          <?= $libres ? '<b>' . $libres . '</b> sin nada encima ahora mismo.' : 'Todo el mundo tiene algo encima.' ?>
        </p>
      </div>

      <?php $topCarga = $carga ? max(array_column($carga, 'total')) : 0; ?>
      <ul class="carga-panel-lista">
        <?php foreach ($carga as $c):
            $m   = $c['miembro'];
            $mid = (int)$m['id'];
            $pct = $topCarga > 0 ? round($c['total'] / $topCarga * 100) : 0;
            $nivel = $pct >= 66 ? 'alta' : ($pct >= 33 ? 'media' : 'baja');
            $suyos = $fPersona === $mid;
        ?>
        <li class="carga-p carga-<?= $nivel ?><?= $suyos ? ' carga-p-activa' : '' ?>">
          <!-- Pulsar a alguien filtra la lista con lo suyo: de "está cargado"
               a "esto es lo que tiene" sin cambiar de pantalla. -->
          <a href="<?= e(urlFiltro(['persona' => $suyos ? '' : $mid])) ?>"
             title="<?= $suyos ? 'Quitar el filtro' : 'Ver los requerimientos de ' . e($m['nombre']) ?>">
            <?= UI::avatar($m, 30) ?>
            <span class="carga-p-datos">
              <strong class="truncate"><?= e($m['nombre']) ?></strong>
              <small class="truncate"><?= $c['tareas'] ?> tareas · <?= $c['reqs'] ?> req.</small>
            </span>
            <span class="carga-p-barra"><span style="width:<?= max($pct, $c['total'] > 0 ? 8 : 0) ?>%"></span></span>
            <b class="carga-p-num"><?= $c['total'] ?></b>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </aside>
</div>
<?php fichaRequerimiento(true); ?>

<!-- Modal: nuevo requerimiento. Asistente por pasos, el mismo componente
     (.dlg-wizard + form.wz, que mueve MecaWizard) que "Nueva tarea": riel de
     pasos a la izquierda, un panel visible cada vez y revisión al final. -->
<dialog id="dlg-req-nuevo" class="dlg-meca dlg-wizard dlg-req-wz">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="req_crear">
    <?= UI::wizardRiel('fa-inbox', 'Nuevo requerimiento', 'Petición que no pertenece a ningún proyecto', UI::PASOS_REQUERIMIENTO) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo">
          <span>¿Qué piden? *</span>
          <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Reporte de matrículas para el rectorado">
        </label>
        <div class="campo">
          <span>Detalle</span>
          <?= UI::editorRico(['name' => 'detalle', 'placeholder' => 'Contexto, con quién hablar, qué se espera de entrega… (puedes pegar un correo con su tabla)']) ?>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>¿Quién lo pide?</span>
            <input class="input-meca" name="solicitante" maxlength="80" placeholder="Ej. Secretaría académica">
          </label>
          <label class="campo"><span>Prioridad</span>
            <?= UI::select('prioridad', array_map(fn($v) => $v[0], $prioridades), Catalogo::prioridadValida('')) ?>
          </label>
        </div>
        <label class="campo">
          <span><i class="fa-solid fa-building-columns"></i> Institución(es)</span>
          <?php if ($opcionesInst): ?>
          <?= UI::select('instituciones', $opcionesInst, [], false, '', true) ?>
          <small class="campo-ayuda">¿Para qué institución es? Puedes elegir <b>varias</b> si el mismo requerimiento atiende a más de una.</small>
          <?php else: ?>
          <small class="campo-ayuda">Aún no hay instituciones en el catálogo. <a href="instituciones.php">Créalas aquí</a> para poder asignarlas.</small>
          <?php endif; ?>
        </label>
      </section>

      <?php panelResponsables($carga, $opcionesRol, 'nr'); ?>
      <?php panelPlazo('nr', $hoy); ?>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-check"></i> Registrar</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<!-- Modal: asignar uno que ya existe. Mismo asistente, sin los datos de la
     petición: quién lo hace y con qué plazo, que es la misma decisión. -->
<dialog id="dlg-req-derivar" class="dlg-meca dlg-wizard dlg-req-wz">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="req_asignar">
    <input type="hidden" name="id" id="dv-id">
    <?= UI::wizardRiel('fa-user-plus', 'Asignar', 'A una o varias personas, con su plazo', UI::PASOS_ASIGNAR) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
          <p class="wz-sobre">«<b id="dv-titulo"></b>»</p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <?php panelResponsables($carga, $opcionesRol, 'dv'); ?>
      <?php panelPlazo('dv'); ?>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-paper-plane"></i> Asignar</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<!-- Modal: editar un requerimiento. Es EL MISMO asistente que "Nuevo
     requerimiento" (mismos pasos y componentes), pero apunta a req_editar y se
     rellena con el requerimiento pulsado. Así se puede corregir todo —qué
     piden, detalle, prioridad, instituciones, responsables y plazo— en el mismo
     sitio. Los responsables nuevos reciben aviso; a los que ya estaban no se
     les reenvía. -->
<dialog id="dlg-req-editar" class="dlg-meca dlg-wizard dlg-req-wz">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="req_editar">
    <input type="hidden" name="id" id="re-id">
    <?= UI::wizardRiel('fa-pen', 'Editar requerimiento', 'Corrige lo que haga falta', UI::PASOS_REQUERIMIENTO) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo">
          <span>¿Qué piden? *</span>
          <input class="input-meca" name="titulo" id="re-titulo" required maxlength="120" placeholder="Ej. Reporte de matrículas para el rectorado">
        </label>
        <div class="campo">
          <span>Detalle</span>
          <?= UI::editorRico(['name' => 'detalle', 'id' => 're-detalle', 'placeholder' => 'Contexto, con quién hablar, qué se espera… (puedes pegar un correo con su tabla)']) ?>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>¿Quién lo pide?</span>
            <input class="input-meca" name="solicitante" id="re-solicitante" maxlength="80" placeholder="Ej. Secretaría académica">
          </label>
          <label class="campo"><span>Prioridad</span>
            <?= UI::select('prioridad', array_map(fn($v) => $v[0], $prioridades), Catalogo::prioridadValida(''), false, 'js-re-prioridad') ?>
          </label>
        </div>
        <label class="campo">
          <span><i class="fa-solid fa-building-columns"></i> Institución(es)</span>
          <?php if ($opcionesInst): ?>
          <?= UI::select('instituciones', $opcionesInst, [], false, 'js-re-inst', true) ?>
          <small class="campo-ayuda">¿Para qué institución es? Puedes elegir <b>varias</b>.</small>
          <?php else: ?>
          <small class="campo-ayuda">Aún no hay instituciones en el catálogo. <a href="instituciones.php">Créalas aquí</a>.</small>
          <?php endif; ?>
        </label>
      </section>

      <?php panelResponsables($carga, $opcionesRol, 're'); ?>
      <?php panelPlazo('re'); ?>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php UI::fin(); ?>
