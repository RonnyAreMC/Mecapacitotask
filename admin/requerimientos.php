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
// Requerimientos desde el correo (lib/ReqCorreo.php): si falta algo, solo no aparece
foreach (['Correo', 'ReqCorreo'] as $modRc) {
    if (is_file(__DIR__ . '/lib/' . $modRc . '.php')) require_once __DIR__ . '/lib/' . $modRc . '.php';
}
if (is_file(__DIR__ . '/lib/correo_vista.php')) require_once __DIR__ . '/lib/correo_vista.php';
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

// ¿Viene a revisar una propuesta armada con IA desde su correo? (solo las propias)
$yoReq     = (int)(Auth::usuario()['id'] ?? 0);
$conCorreo = class_exists('ReqCorreo') && class_exists('Correo');
$prop      = null;
if ($conCorreo && isset($_GET['propuesta'])) {
    $prop = ReqCorreo::fila((int)$_GET['propuesta']);
    if ($prop && (int)$prop['miembro_id'] === $yoReq && ($prop['estado'] ?? '') === 'registrado' && !empty($prop['req_id'])) {
        header('Location: requerimientos.php?ver=' . (int)$prop['req_id']);
        exit;
    }
    if (!$prop || (int)$prop['miembro_id'] !== $yoReq || ($prop['estado'] ?? '') !== 'propuesta') $prop = null;
}
$pd         = $prop ? (array)$prop['datos'] : [];
$propuestas = $conCorreo ? ReqCorreo::propuestas($yoReq) : [];

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
 *
 * Y, a igualdad de carga, CUANDO entrego algo por ultima vez. Ordenar solo por
 * lo abierto repartia mal: quien acababa de cerrar diez cosas marcaba 0 igual
 * que quien no recibe trabajo desde hace un mes, y entre dos ceros mandaba el
 * alfabeto — o sea que Aaron Bayas se llevaba siempre el siguiente. Con la
 * fecha de la ultima entrega, arriba queda quien lleva mas tiempo sin que le
 * toque nada.
 */
$finales  = Catalogo::estadosFinales();
$abiertas = [];
$ultima   = [];      // [miembro_id => 'Y-m-d' de lo ultimo que entrego]
$apuntar  = function (int $mid, string $fecha) use (&$ultima) {
    $fecha = substr(trim($fecha), 0, 10);
    if ($mid <= 0 || $fecha === '') return;
    if (!isset($ultima[$mid]) || $fecha > $ultima[$mid]) $ultima[$mid] = $fecha;
};
foreach ($tareasRepo->todas() as $t) {
    $cerrada = in_array($t['estado'] ?? '', $finales, true);
    foreach (TareaRepo::asignadosDe($t) as $mid) {
        if ($cerrada) $apuntar($mid, (string)($t['completada_en'] ?? ''));
        else          $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
    }
}
foreach ($reqRepo->todos() as $rq) {
    if (!RequerimientoRepo::cerrado($rq)) continue;
    $cuando = (string)($rq['cerrado_en'] ?? '');
    // Lo cuenta quien lo marco terminado; si lo cerro el administrador desde
    // el modulo no queda 'cerrado_por', y entonces cuenta para sus responsables.
    $deQuien = (int)($rq['cerrado_por'] ?? 0)
        ? [(int)$rq['cerrado_por']]
        : RequerimientoRepo::asignadosDe($rq);
    foreach ($deQuien as $mid) $apuntar($mid, $cuando);
}

$hoyCarga = new DateTimeImmutable('today');
$carga = [];
foreach ($miembros->todos() as $m) {
    $mid    = (int)$m['id'];
    $tareas = (int)($abiertas[$mid] ?? 0);
    $reqs   = $reqRepo->abiertosDe($mid);
    $suUltima = $ultima[$mid] ?? '';
    $carga[] = [
        'miembro' => $m,
        'rol'     => trim($m['rol'] ?? '') ?: 'Sin rol',
        'tareas'  => $tareas,
        'reqs'    => $reqs,
        'total'   => $tareas + $reqs,
        'ultima'  => $suUltima,
        // Dias desde la ultima entrega; null si no consta ninguna.
        'dias'    => $suUltima !== ''
            ? (int)$hoyCarga->diff(new DateTimeImmutable($suUltima))->days
            : null,
    ];
}
// Del mas libre al mas cargado y, a igual carga, de quien lleva mas tiempo sin
// entregar a quien acaba de hacerlo. La cadena vacia ordena antes que cualquier
// fecha, asi que quien no tiene ninguna entrega registrada sale primero.
usort($carga, fn($a, $b) => $a['total'] <=> $b['total']
    ?: strcmp($a['ultima'], $b['ultima'])
    ?: strcasecmp($a['miembro']['nombre'], $b['miembro']['nombre']));

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
function panelResponsables(array $carga, array $opcionesRol, string $prefijo, array $marcados = []): void
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

        <div class="dv-controles">
          <label class="dv-buscar">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" class="input-meca js-<?= e($prefijo) ?>-buscar" placeholder="Buscar por nombre…" autocomplete="off">
          </label>
          <label class="carga-filtro dv-filtro">
            <span>Filtrar por rol</span>
            <?= UI::select($prefijo . '_rol', $opcionesRol, '', false, 'js-' . $prefijo . '-rol') ?>
          </label>
        </div>

        <p class="dv-sinresultados" data-picker-vacio="<?= e($prefijo) ?>" hidden>Nadie coincide con la búsqueda.</p>

        <div class="dv-lista" data-picker="<?= e($prefijo) ?>">
          <?php foreach ($carga as $c):
              $m = $c['miembro'];
              $pct = $tope > 0 ? round($c['total'] / $tope * 100) : 0;
              $nivel = $pct >= 66 ? 'alta' : ($pct >= 33 ? 'media' : 'baja');
          ?>
          <label class="dv-persona carga-<?= $nivel ?>" data-id="<?= (int)$m['id'] ?>"
                 data-rol="<?= e($c['rol']) ?>" data-nombre="<?= e($m['nombre']) ?>">
            <input type="checkbox" name="asignados[]" value="<?= (int)$m['id'] ?>"<?= in_array((int)$m['id'], $marcados, true) ? ' checked' : '' ?>>
            <span class="dv-check"><i class="fa-solid fa-check"></i></span>
            <?= UI::avatar($m, 34) ?>
            <span class="dv-datos">
              <strong class="truncate"><?= e($m['nombre']) ?></strong>
              <small class="truncate"><?= e($c['rol']) ?></small>
            </span>
            <span class="dv-medida">
              <span class="dv-barra"><span style="width:<?= max($pct, $c['total'] > 0 ? 8 : 0) ?>%"></span></span>
              <small><?= $c['tareas'] ?> tareas · <?= $c['reqs'] ?> req.</small>
              <?php
              // Por que esta donde esta: entre varios con la misma carga, arriba
              // van los que llevan mas tiempo sin entregar. Sin esto, el orden
              // parece arbitrario cuando todos marcan 0.
              $etUlt = $c['dias'] === null
                  ? 'sin entregas registradas'
                  : match (true) {
                      $c['dias'] === 0 => 'entregó hoy',
                      $c['dias'] === 1 => 'entregó ayer',
                      $c['dias'] < 30  => 'entregó hace ' . $c['dias'] . ' días',
                      default          => 'sin entregar hace ' . intdiv($c['dias'], 30) . ' mes'
                                          . (intdiv($c['dias'], 30) === 1 ? '' : 'es'),
                  };
              ?>
              <small class="dv-ultima<?= $c['dias'] !== null && $c['dias'] < 7 ? ' dv-ultima-reciente' : '' ?>"
                     <?= $c['ultima'] !== '' ? 'title="Última entrega: ' . e($c['ultima']) . '"' : '' ?>>
                <?= e($etUlt) ?>
              </small>
            </span>
            <span class="dv-carga" title="<?= $c['total'] ?> pendientes en total: <?= $c['tareas'] ?> tareas de proyecto y <?= $c['reqs'] ?> requerimientos"><?= $c['total'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <small class="campo-ayuda">
          <b data-picker-n="<?= e($prefijo) ?>">0</b> seleccionados · el número de cada quien es lo que ya tiene
          abierto (tareas de proyecto + requerimientos). Arriba van los más libres y, entre los que
          empatan, quien lleva más tiempo sin que le toque nada. Sin marcar a nadie, queda «sin asignar».
        </small>
      </div>
    </section>
    <?php
}

/**
 * Paso "Plazo": las dos fechas del encargo, con los mismos atajos y el mismo
 * aviso de duración que el asistente de tareas.
 */
function panelPlazo(string $prefijo, string $inicioPorDefecto = '', string $finPorDefecto = ''): void
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
                 data-req-fecha="<?= e($prefijo) ?>-fin" value="<?= e($finPorDefecto) ?>">
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
    '<a class="btn-outline btn-meca btn-azul btn-icono" href="req_dashboard.php" title="Panel con gráficos de cumplimiento">
       <i class="fa-solid fa-chart-column"></i> <span class="tab-txt">Panel</span>
     </a>
     <a class="btn-outline btn-meca btn-azul btn-icono" href="instituciones.php" title="Catálogo de instituciones">
       <i class="fa-solid fa-building-columns"></i> <span class="tab-txt">Instituciones</span>
     </a>
     ' . ($conCorreo ? '<button type="button" class="btn-outline btn-meca btn-azul btn-icono" onclick="document.getElementById(\'dlg-req-correo\').showModal()" title="La IA convierte tus correos en requerimientos">
       <i class="fa-solid fa-envelope-open-text"></i> <span class="tab-txt">Desde el correo</span>'
       . ($propuestas ? ' <span class="tab-badge">' . count($propuestas) . '</span>' : '') . '
     </button>' : '') . '
     <button class="btn-primary btn-meca btn-agregar" onclick="document.getElementById(\'dlg-req-nuevo\').showModal()">
       <i class="fa-solid fa-plus"></i> Nuevo requerimiento
     </button>'
);
?>

<!-- Situación de un vistazo. No son adornos: cada pastilla FILTRA la lista de
     abajo, así que la pantalla contesta "¿qué tengo que hacer?" y no solo
     "¿cuántos hay?". "Pasados de fecha" cruza las otras tres a propósito. -->
<?php if ($propuestas): ?>
<section class="ds req-propuestas" aria-labelledby="req-prop-t">
  <header class="req-propuestas__cab">
    <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('ia', 18) ?></span>
    <div><h3 id="req-prop-t">Desde tu correo: por revisar</h3>
      <p>La IA las armó con tus correos. Revisa cada una y regístrala, o descártala.</p></div>
  </header>
  <ul class="req-propuestas__lista">
    <?php foreach ($propuestas as $pp):
        $dp = (array)$pp['datos'];
        $respPp = array_values(array_filter(array_map(fn($id) => $mapa[(int)$id]['nombre'] ?? null, (array)($dp['responsables'] ?? []))));
    ?>
    <li class="req-propuesta">
      <div class="req-propuesta__txt">
        <b><?= e((string)($dp['titulo'] ?? $pp['asunto'])) ?></b>
        <small>De <?= e(trim((string)(($pp['de']['nombre'] ?? '') ?: ($pp['de']['email'] ?? '')))) ?> · «<?= e((string)$pp['asunto']) ?>»
          · <?= e(ReqCorreo::MAGNITUDES[$dp['magnitud'] ?? 'mediana'][0] ?? '') ?>
          · <?= $respPp ? 'para ' . e(implode(', ', $respPp)) : 'sin responsable' ?></small>
      </div>
      <a class="mc-btn mc-btn--primary mc-btn--sm" href="requerimientos.php?propuesta=<?= (int)$pp['id'] ?>"><?= UI::iconoDS('ojo', 16) ?><span class="mc-btn__label">Revisar</span></a>
      <form method="post" action="actions.php" data-confirmar="El correo sigue en tu buzón; solo se quita esta propuesta." data-confirmar-titulo="¿Descartar la propuesta?" data-confirmar-ok="Sí, descartar">
        <input type="hidden" name="accion" value="req_correo_descartar"><input type="hidden" name="id" value="<?= (int)$pp['id'] ?>">
        <button class="mc-btn mc-btn--ghost mc-btn--sm"><span class="mc-btn__label">Descartar</span></button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

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
    <span class="et-icono"><?= UI::icono($icono) ?></span>
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
      <button class="btn-outline btn-meca btn-azul req-buscar-btn"><i class="fa-solid fa-filter"></i> Filtrar</button>
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
        bloqueRequerimientos('Cerrados', 'fa-circle-check', $secciones['cerrados'], $mapa, $prioridades, $estados,
            '', 'req-bloque-hecho');
      ?>
    <?php endif; ?>
  </div>

</div>
<?php fichaRequerimiento(true); ?>

<!-- Modal: nuevo requerimiento. Asistente por pasos, el mismo componente
     (.dlg-wizard + form.wz, que mueve MecaWizard) que "Nueva tarea": riel de
     pasos a la izquierda, un panel visible cada vez y revisión al final. -->
<dialog id="dlg-req-nuevo" class="dlg-meca dlg-wizard dlg-req-wz">
  <form method="post" action="actions.php" class="dlg-form wz" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="req_crear">
    <?php if ($prop): ?><input type="hidden" name="propuesta" value="<?= (int)$prop['id'] ?>"><?php endif; ?>
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
        <?php if ($prop):
            $dePp = trim((string)(($prop['de']['nombre'] ?? '') ?: ($prop['de']['email'] ?? '')));
            $mg   = ReqCorreo::MAGNITUDES[$pd['magnitud'] ?? 'mediana'] ?? ReqCorreo::MAGNITUDES['mediana'];
        ?>
        <div class="req-desde-correo">
          <p class="req-desde-correo__cab"><i class="fa-solid fa-envelope-open-text"></i>
            Armado con IA desde el correo «<?= e((string)$prop['asunto']) ?>» de <?= e($dePp) ?> (<?= e((string)$prop['fecha']) ?>). Revísalo antes de registrar.</p>
          <ul class="req-desde-correo__notas">
            <li><b>Magnitud: <?= e($mg[0]) ?></b><?= !empty($pd['magnitud_motivo']) ? ' — ' . e((string)$pd['magnitud_motivo']) : '' ?>.
              <?= !empty($pd['fecha_del_correo'])
                  ? 'El correo pide entregarlo el <b>' . e((string)$pd['fecha_fin_calc']) . '</b>.'
                  : 'Entrega en ' . (int)($pd['dias'] ?? 0) . ' día' . ((int)($pd['dias'] ?? 0) === 1 ? '' : 's') . ' hábil' . ((int)($pd['dias'] ?? 0) === 1 ? '' : 'es') . ': <b>' . e((string)($pd['fecha_fin_calc'] ?? '')) . '</b>.' ?></li>
            <?php if (!empty($pd['institucion_texto']) && empty($pd['instituciones'])): ?>
            <li>Institución: «<?= e((string)$pd['institucion_texto']) ?>», que no está en el catálogo. <a href="instituciones.php">Agrégala</a> o elige otra abajo.</li>
            <?php endif; ?>
            <?php if (!empty($pd['responsables_texto']) && empty($pd['responsables'])): ?>
            <li>El correo se lo asigna a «<?= e((string)$pd['responsables_texto']) ?>», pero no aparece en el equipo del panel: elige el responsable en el paso siguiente.</li>
            <?php endif; ?>
            <?php foreach ((array)($pd['parecidos'] ?? []) as $par): ?>
            <li class="req-desde-correo__ojo">Puede que ya exista: #<?= (int)$par['id'] ?> <?= e((string)$par['titulo']) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if (!empty($prop['adjuntos'])): ?>
          <div class="req-desde-correo__adj">
            <span>Adjuntos del correo</span>
            <?php foreach ((array)$prop['adjuntos'] as $adj): ?>
            <label class="req-adj-correo<?= empty($adj['ok']) ? ' is-off' : '' ?>">
              <input type="checkbox" name="correo_adjuntos[]" value="<?= (int)$adj['i'] ?>"<?= !empty($adj['ok']) ? ' checked' : ' disabled' ?>>
              <i class="fa-solid fa-paperclip"></i> <?= e((string)$adj['nombre']) ?> <small><?= e(function_exists('correoTam') ? correoTam((int)$adj['tam']) : round($adj['tam'] / 1024) . ' KB') ?><?= empty($adj['ok']) ? ' · no se puede guardar (tipo no permitido o más de 10 MB)' : '' ?></small>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (UI::ia('req_desde_texto') && Auth::esAdmin()): ?>
        <div class="ia-acciones" style="margin:0 0 4px">
          <?= UI::botonIA('Rellenar desde un correo o WhatsApp', ['ia-req' => 1], 'Pega el mensaje y la IA propone los campos') ?>
        </div>
        <?php endif; ?>
        <label class="campo">
          <span>¿Qué piden? *</span>
          <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Reporte de matrículas para el rectorado" value="<?= e((string)($pd['titulo'] ?? '')) ?>">
        </label>
        <div class="campo">
          <span>Detalle</span>
          <?= UI::editorRico(['name' => 'detalle', 'valor' => HtmlRico::limpiar((string)($pd['detalle'] ?? '')), 'placeholder' => 'Contexto, con quién hablar, qué se espera de entrega… (puedes pegar un correo con su tabla)']) ?>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>¿Quién lo pide?</span>
            <input class="input-meca" name="solicitante" maxlength="80" placeholder="Ej. Secretaría académica" value="<?= e(mb_substr((string)($pd['solicitante'] ?? ''), 0, 80)) ?>">
          </label>
          <label class="campo"><span>Prioridad</span>
            <?= UI::select('prioridad', array_map(fn($v) => $v[0], $prioridades), Catalogo::prioridadValida($pd['prioridad'] ?? '')) ?>
          </label>
        </div>
        <label class="campo">
          <span><i class="fa-solid fa-building-columns"></i> Institución(es)</span>
          <?php if ($opcionesInst): ?>
          <?= UI::select('instituciones', $opcionesInst, array_map('intval', (array)($pd['instituciones'] ?? [])), false, '', true) ?>
          <small class="campo-ayuda">¿Para qué institución es? Puedes elegir <b>varias</b> si el mismo requerimiento atiende a más de una.</small>
          <?php else: ?>
          <small class="campo-ayuda">Aún no hay instituciones en el catálogo. <a href="instituciones.php">Créalas aquí</a> para poder asignarlas.</small>
          <?php endif; ?>
        </label>
        <div class="campo">
          <span>Documentos (opcional)</span>
          <?= UI::archivo([
                'name' => 'adjuntos[]', 'multiple' => true, 'maxMB' => 10,
                'accept' => 'image/png,image/jpeg,image/webp,image/gif,application/pdf,.doc,.docx',
                'ayuda'  => 'Capturas, PDF o Word · máx 10 MB cada uno',
              ]) ?>
          <small class="campo-ayuda">Lo que adjuntes se ve en la ficha del requerimiento, igual que en una tarea.</small>
        </div>
      </section>

      <?php panelResponsables($carga, $opcionesRol, 'nr', array_map('intval', (array)($pd['responsables'] ?? []))); ?>
      <?php panelPlazo('nr', (string)($pd['fecha_inicio'] ?? $hoy), (string)($pd['fecha_fin_calc'] ?? '')); ?>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Registrar</button>
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
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
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
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<?php if ($conCorreo):
    $rc        = ReqCorreo::reglas($yoReq);
    $plz       = ReqCorreo::plazos();
    $conectado = Correo::conectado($yoReq);
    $hist      = ReqCorreo::historial($yoReq, 8);
?>
<!-- Requerimientos desde el correo: reglas, qué hacer y plazos por magnitud -->
<dialog class="ds-modal req-correo-dlg" id="dlg-req-correo" aria-labelledby="dlg-rc-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="req_correo_conf">
    <?php if ($conectado && function_exists('correoDirectorio')): ?>
    <script type="application/json" id="correo-directorio"><?= json_encode(correoDirectorio($yoReq, strtolower((string)(Correo::cuenta($yoReq)['email'] ?? ''))), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('ia', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-rc-t">Requerimientos desde tu correo</h2>
        <p class="ds-modal__sub">La IA lee los correos que cumplen tus reglas y arma el requerimiento: qué se pide, la institución, el responsable, el plazo y los adjuntos.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <?php if (!$conectado): ?>
        <?= UI::alertaDS('warning', 'Primero sincroniza tu correo InnoTech', 'Ve a <a href="notificaciones.php?tab=correo">Notificaciones → Correo InnoTech</a> e inicia sesión con tu correo. Mientras tanto puedes ajustar los plazos.', true) ?>
      <?php else: ?>
        <?= UI::checkDS('activo', $rc['activo'], 'Analizar mis correos nuevos automáticamente', 'Se revisan cada minuto. Solo los que cumplan alguna de estas reglas; el correo no se marca como leído.') ?>
        <div class="mc-field"><label class="mc-field__label" for="rc-remitentes">Cuando me escriba</label>
          <div class="mc-input"><input class="mc-input__control" id="rc-remitentes" name="remitentes" value="<?= e(implode(', ', $rc['remitentes'])) ?>" placeholder="Nombre o correo (ej. quien reparte los pedidos)" autocomplete="off" data-destinatarios="remitentes"></div></div>
        <div class="mc-field"><label class="mc-field__label" for="rc-destinos">O cuando vaya dirigido a</label>
          <div class="mc-input"><input class="mc-input__control" id="rc-destinos" name="destinos" value="<?= e(implode(', ', $rc['destinos'])) ?>" placeholder="Ej. requerimiento@innotech-solutions.com.ec" autocomplete="off" data-destinatarios="destinos"></div>
          <div class="mc-field__foot"><span class="mc-field__hint">Cuenta si esa dirección va en «Para» o en copia.</span></div></div>
        <?= UI::checkDS('solo_cc', $rc['solo_cc'], 'Solo si voy en copia (CC)', 'Para cuando otra persona reparte el pedido y te copia a ti.') ?>
        <div class="mc-field"><span class="mc-field__label">Qué hacer con cada pedido</span>
          <?= UI::selectDS('modo', ['auto' => 'Registrarlo y asignarlo solo (me aviso en la campana)', 'revisar' => 'Dejarlo listo para que yo lo revise'], $rc['modo'], ['aria' => 'Qué hacer con cada pedido']) ?></div>
        <?= UI::checkDS('repasar', false, 'Revisar también los correos de la última semana', 'Se hace una vez, al guardar.') ?>
      <?php endif; ?>

      <fieldset class="req-plazos">
        <legend>Plazo según la magnitud <small>(días hábiles; máximo 5, una semana)</small></legend>
        <?php foreach (ReqCorreo::MAGNITUDES as $k => [$etq, $desc]): ?>
        <label class="req-plazo">
          <span><b><?= e($etq) ?></b><small><?= e($desc) ?></small></span>
          <span class="mc-input mc-input--sm req-plazo__n"><input class="mc-input__control" type="number" name="plazo[<?= e($k) ?>]" min="1" max="<?= ReqCorreo::TOPE_DIAS ?>" value="<?= (int)$plz[$k] ?>" required></span>
          <small>días</small>
        </label>
        <?php endforeach; ?>
        <p class="mc-field__hint">Si el correo da una fecha, se usa esa. Si llega un fin de semana, se cuenta desde el lunes.</p>
      </fieldset>

      <?php if ($hist): ?>
      <div class="req-hist">
        <span class="mc-field__label">Últimos correos analizados</span>
        <ul>
          <?php foreach ($hist as $hf):
              [$etqH, $tonoH] = ReqCorreo::ESTADOS[$hf['estado'] ?? 'error'] ?? ['—', 'neutral'];
              $urlH = ($hf['estado'] ?? '') === 'registrado' ? 'requerimientos.php?ver=' . (int)$hf['req_id']
                    : (($hf['estado'] ?? '') === 'propuesta' ? 'requerimientos.php?propuesta=' . (int)$hf['id'] : '');
          ?>
          <li>
            <span class="req-hist__txt"><b><?= e((string)($hf['datos']['titulo'] ?? '') ?: (string)$hf['asunto']) ?></b>
              <small><?= e((string)$hf['fecha']) ?> · <?= ($hf['origen'] ?? '') === 'auto' ? 'automático' : 'a mano' ?><?= ($hf['estado'] ?? '') === 'error' ? ' · ' . e((string)$hf['error']) : '' ?></small></span>
            <?php if ($urlH !== ''): ?><a href="<?= e($urlH) ?>" class="mc-status mc-status--pill mc-status--<?= e($tonoH) ?>"><span class="mc-status__content"><span class="mc-status__label"><?= e($etqH) ?></span></span></a>
            <?php else: ?><span class="mc-status mc-status--pill mc-status--<?= e($tonoH) ?>"><span class="mc-status__content"><span class="mc-status__label"><?= e($etqH) ?></span></span></span><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar</span></button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  <?php if ($prop): ?>
  // Propuesta del correo: el asistente se abre ya relleno para revisarlo
  const dlgNuevo = document.getElementById('dlg-req-nuevo');
  if (dlgNuevo) {
    dlgNuevo.showModal();
    dlgNuevo.querySelectorAll('input[name="asignados[]"]:checked, input[name="fecha_fin"], input[name="fecha_inicio"]').forEach((c) => c.dispatchEvent(new Event('change', { bubbles: true })));
  }
  <?php endif; ?>
  <?php if (isset($_GET['ver'])): ?>
  // Enlace directo a un requerimiento (desde el correo o la campana): abre su ficha
  const fila = document.querySelector('[data-req-id="<?= (int)$_GET['ver'] ?>"]');
  if (fila) { fila.scrollIntoView({ block: 'center' }); fila.click(); }
  <?php endif; ?>
});
</script>
<?php UI::fin(); ?>
