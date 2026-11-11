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
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/requirements/req_ui.php';
// Requerimientos desde el correo (lib/requirements/ReqCorreo.php): si falta algo, solo no aparece
foreach (['mail/Correo', 'requirements/ReqCorreo', 'requirements/ReqSeguimiento'] as $modRc) {
    if (is_file(__DIR__ . '/lib/' . $modRc . '.php')) require_once __DIR__ . '/lib/' . $modRc . '.php';
}
if (is_file(__DIR__ . '/lib/mail/correo_vista.php')) require_once __DIR__ . '/lib/mail/correo_vista.php';
// Los enlaces de siempre (campana, correos) abren ahora la pantalla del requerimiento
if (isset($_GET['ver'])) {
    header('Location: requerimiento.php?id=' . (int)$_GET['ver']);
    exit;
}
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
        header('Location: requerimiento.php?id=' . (int)$prop['req_id']);
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

// Carga de cada persona (tareas + requerimientos abiertos) y los roles para filtrar: req_ui.php
[$carga, $opcionesRol] = cargaParaAsignar($miembros, $reqRepo, $tareasRepo);

/* ---------- Reparto entre compañeros (equidad) ----------
   Cuánto se le asignó a cada persona en el periodo, separado en resueltos, en
   curso y vencidos, y el promedio por programador como referencia. Arriba de
   la lista, para repartir mirando esto. Periodo: ?rep=30|90|todo (90 por defecto). */
$periodosRep = ['30' => '30 días', '90' => '90 días', 'todo' => 'Todo'];
$fRep  = isset($periodosRep[$_GET['rep'] ?? '']) ? (string)$_GET['rep'] : '90';
$desdeRep = $fRep === 'todo' ? '' : date('Y-m-d', strtotime('-' . (int)$fRep . ' days'));
$porPersona = reqRepartoPorPersona($requerimientos, $desdeRep);
$filasRep = [];
$devsRep = 0; $asignadoDevs = 0;
foreach ($carga as $c) {
    $mid = (int)$c['miembro']['id'];
    $d = $porPersona[$mid] ?? ['total' => 0, 'abiertos' => 0, 'vencidos' => 0, 'resueltos' => 0];
    if ($c['grupo'] === 0) { $devsRep++; $asignadoDevs += $d['total']; }
    if ($d['total'] === 0) continue;
    $partes = preg_split('/\s+/', trim($c['miembro']['nombre']));
    $filasRep[] = [
        'nombre'  => implode(' ', array_slice($partes, 0, 2)),
        'completo'=> $c['miembro']['nombre'],
        'equipo'  => $c['equipo'],
        'resueltos' => $d['resueltos'], 'curso' => $d['abiertos'], 'vencidos' => $d['vencidos'], 'total' => $d['total'],
    ];
}
usort($filasRep, fn($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['nombre'], $b['nombre']));
$asignadosRep = 0;   // requerimientos (no asignaciones) repartidos en el periodo
foreach ($requerimientos as $r) {
    $cuando = substr((string)(($r['asignado_en'] ?? '') ?: ($r['creado'] ?? '')), 0, 10);
    if (RequerimientoRepo::asignadosDe($r) && ($desdeRep === '' || $cuando === '' || $cuando >= $desdeRep)) $asignadosRep++;
}
$promedioDevs = $devsRep > 0 ? round($asignadoDevs / $devsRep, 1) : 0;
$topeRep = 12;   // en la lista principal, los 12 con más; el resto en el Panel
$siguientes = array_slice(array_values(array_filter($carga, fn($c) => in_array($c['reco'][0], ['recomendado', 'disponible'], true) && $c['grupo'] === ($carga[0]['grupo'] ?? 0))), 0, 4);
$repData = [
    'filas'    => array_slice($filasRep, 0, $topeRep),
    'promedio' => $promedioDevs,
];

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
        'rep'     => $_GET['rep'] ?? '',
    ], $cambios), fn($v) => (string)$v !== '' && (string)$v !== '0');
    return 'requerimientos.php' . ($qs ? '?' . http_build_query($qs) : '');
}

// Los pasos del asistente (Responsables, Plazo, Observaciones) viven en req_ui.php:
// los usa también la pantalla de un requerimiento (requerimiento.php).

UI::inicio('Requerimientos', 'requerimientos');
UI::cabecera(
    'Requerimientos <span class="text-secondary">sueltos</span>',
    'Peticiones que no pertenecen a ningún proyecto. Repártelas con su plazo, mirando cuánto tiene encima cada persona.',
    '<a class="rs-boton rs-boton--icono" href="req_dashboard.php" aria-label="Panel" data-ds-tip="Panel: gráficos de cumplimiento y reparto">' . UI::icono('ds-reportes') . '</a>'
    . '<button type="button" class="rs-boton rs-boton--icono" data-rp-abrir aria-label="Reparto entre compañeros" data-ds-tip="Reparto: cuánto le tocó a cada uno">' . UI::icono('ds-usuarios') . '</button>'
    . (is_file(__DIR__ . '/agenda.php') && function_exists('puedeReunionesGenerales') && puedeReunionesGenerales()
        ? '<a class="rs-boton rs-boton--icono" href="agenda.php?nueva=1&amp;tema=' . rawurlencode('Requerimientos sueltos') . '" aria-label="Reunión" data-ds-tip="Agendar una reunión de requerimientos sueltos">' . UI::icono('ds-reuniones') . '</a>' : '')
    . '<a class="rs-boton rs-boton--icono" href="instituciones.php" aria-label="Instituciones" data-ds-tip="Catálogo de instituciones">' . UI::icono('Bank') . '</a>'
    . (class_exists('ReqSeguimiento') ? '<button type="button" class="rs-boton rs-boton--icono" onclick="document.getElementById(\'dlg-req-seg\').showModal()" aria-label="Seguimiento" data-ds-tip="Seguimiento: quién recibe los terminados y los atrasados">' . UI::icono('ds-auditoria') . '</button>' : '')
    . ($conCorreo ? '<button type="button" class="rs-boton rs-boton--icono" onclick="document.getElementById(\'dlg-req-correo\').showModal()" aria-label="Desde el correo" data-ds-tip="Desde el correo: la IA arma requerimientos con tus correos"'
        . ($propuestas ? ' data-n="' . count($propuestas) . '"' : '') . '>' . UI::icono('ds-correo') . '</button>' : '')
    . '<button type="button" class="rs-boton rs-boton--icono rs-boton--primario" onclick="document.getElementById(\'dlg-req-nuevo\').showModal()" aria-label="Nuevo requerimiento" data-ds-tip="Nuevo requerimiento">' . UI::icono('ds-mas') . '</button>'
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
          'cerrados'    => 'Resueltos o cancelados',
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
/* Avisos y estadísticas que van rotando arriba (req-reparto.js): lo que pide
   atención primero (sin asignar, vencidos, sobrecargados) y luego el reparto.
   El gráfico completo, en el modal «Reparto entre compañeros». */
$nombreCorto = fn(array $m) => explode(' ', trim((string)$m['nombre']))[0];
$listaNombres = function (array $nombres): string {
    $nombres = array_values($nombres);
    return count($nombres) > 1 ? implode(', ', array_slice($nombres, 0, -1)) . ' y ' . end($nombres) : (string)($nombres[0] ?? '');
};
$avisos = [];
$sugeridosTira = array_values(array_filter($carga, fn($c) => ($c['reco'][0] ?? '') === 'recomendado'));
if ($conteo['sin-asignar'] > 0) {
    $avisos[] = ['warn', 'ds-estado-alerta', $conteo['sin-asignar'] . ($conteo['sin-asignar'] === 1 ? ' requerimiento espera responsable' : ' requerimientos esperan responsable'),
        $sugeridosTira ? 'Los sugeridos para repartir: ' . $listaNombres(array_map(fn($c) => $nombreCorto($c['miembro']), $sugeridosTira)) . '.' : 'Repártelos mirando la carga de cada uno.',
        urlFiltro(['sit' => 'sin-asignar']), 'Ver'];
}
if ($conteo['tarde'] > 0) {
    $deQuien = [];
    foreach ($requerimientos as $r) {
        if (!RequerimientoRepo::vencido($r)) continue;
        foreach (RequerimientoRepo::asignadosDe($r) as $mid) if (isset($mapa[$mid])) $deQuien[$mid] = ($deQuien[$mid] ?? 0) + 1;
    }
    arsort($deQuien);
    $avisos[] = ['crit', 'ds-estado-critico', $conteo['tarde'] . ($conteo['tarde'] === 1 ? ' pasado de fecha' : ' pasados de fecha'),
        $deQuien ? 'Los tienen ' . $listaNombres(array_map(fn($mid, $n) => $nombreCorto($mapa[$mid]) . ' (' . $n . ')', array_keys(array_slice($deQuien, 0, 3, true)), array_slice($deQuien, 0, 3, true))) . '.' : 'Todavía sin responsable.',
        urlFiltro(['sit' => 'tarde']), 'Ver'];
}
$cargados = array_values(array_filter($carga, fn($c) => $c['nivel'] === 'alta'));
if ($cargados) {
    $avisos[] = ['warn', 'ds-estado-insignia-alerta', count($cargados) === 1 ? $cargados[0]['miembro']['nombre'] . ' tiene mucha carga' : count($cargados) . ' personas con mucha carga',
        $listaNombres(array_map(fn($c) => $nombreCorto($c['miembro']) . ' (' . $c['total'] . ' pendientes)', array_slice($cargados, 0, 3))) . ': mejor darle lo nuevo a otro.', '', ''];
}
if ($filasRep) {
    $ultimoRep = end($filasRep);
    $avisos[] = ['done', 'ds-estado-finalizado', $asignadosRep . ' repartidos en ' . ($fRep === 'todo' ? 'total' : 'los últimos ' . $periodosRep[$fRep]),
        'Promedio por programador: ' . number_format($promedioDevs, 1, ',', '.') . '. A quien más le tocó: ' . $filasRep[0]['nombre'] . ' (' . $filasRep[0]['total'] . ').', '', ''];
    $resueltosRep = array_sum(array_column($filasRep, 'resueltos'));
    if ($resueltosRep > 0) {
        $avisos[] = ['ok', 'ds-estado-cumple', $resueltosRep . ($resueltosRep === 1 ? ' resuelto' : ' resueltos') . ' en el periodo',
            'De ' . array_sum(array_column($filasRep, 'total')) . ' asignaciones; ' . array_sum(array_column($filasRep, 'curso')) . ' siguen en curso.', '', ''];
    }
}
if ($siguientes) {
    $avisos[] = ['ok', 'ds-estado-cumple', 'Los siguientes en la fila: ' . $listaNombres(array_map(fn($c) => $nombreCorto($c['miembro']), $siguientes)),
        'Son quienes menos tienen abierto y a quienes menos se les asignó en 90 días.', '', '', $siguientes];
}
if (!$avisos) {
    $avisos[] = ['ok', 'ds-estado-cumple', 'Todo en orden', 'Nada sin asignar, nada vencido y nadie sobrecargado.', '', ''];
}
?>
<section class="rp-tira" data-rp-tira aria-roledescription="carrusel" aria-label="Avisos y estadísticas de los requerimientos">
  <div class="rp-tira__pila" aria-live="polite">
    <?php foreach ($avisos as $n => $av): [$tono, $ico, $tit, $sub, $url, $acc] = $av; $gente = $av[6] ?? []; ?>
    <article class="rp-aviso rp-aviso--<?= e($tono) ?><?= $n === 0 ? ' is-activo' : '' ?>" data-rp-aviso="<?= $n ?>"<?= $n === 0 ? '' : ' aria-hidden="true"' ?>>
      <span class="rp-aviso__ic"><?= UI::icono($ico) ?></span>
      <div class="rp-aviso__txt">
        <b class="truncate"><?= e($tit) ?></b>
        <small class="truncate"><?= e($sub) ?></small>
      </div>
      <?php if ($gente): ?><span class="rp-aviso__gente" aria-hidden="true"><?php foreach ($gente as $c) echo UI::avatar($c['miembro'], 26); ?></span><?php endif; ?>
      <?php if ($url !== ''): ?><a class="rp-aviso__acc" href="<?= e($url) ?>"<?= $n === 0 ? '' : ' tabindex="-1"' ?>><?= e($acc) ?></a><?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="rp-tira__ctrl">
    <?php if (count($avisos) > 1): ?>
    <span class="rp-tira__puntos" role="tablist" aria-label="Avisos">
      <?php foreach ($avisos as $n => $av): ?><button type="button" role="tab" data-rp-ir="<?= $n ?>" aria-label="Aviso <?= $n + 1 ?> de <?= count($avisos) ?>"<?= $n === 0 ? ' aria-selected="true"' : '' ?>></button><?php endforeach; ?>
    </span>
    <?php endif; ?>
    <button type="button" class="rs-boton rs-boton--icono rp-tira__btn" data-rp-abrir aria-label="Ver el reparto entre compañeros" data-ds-tip="Reparto entre compañeros"><?= UI::icono('ds-reportes') ?></button>
  </div>
</section>

<!-- Modal: el reparto completo (gráfico y datos). Se dibuja al abrirlo. -->
<dialog class="ds-modal rp-dlg" id="dlg-reparto" aria-labelledby="rp-t">
  <header class="ds-modal__head">
    <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('usuarios', 18) ?></span>
    <div><h2 class="ds-modal__title" id="rp-t">Reparto entre compañeros</h2>
      <p class="ds-modal__sub">Cuántos requerimientos se le asignaron a cada uno, para repartir parejo.</p></div>
    <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
  </header>
  <div class="ds-modal__body">
    <nav class="rp-periodo" aria-label="Periodo del reparto">
      <?php foreach ($periodosRep as $k => $lab): $k = (string)$k;   // PHP vuelve número las claves «30» y «90» ?>
      <a href="<?= e(urlFiltro(['rep' => $k === '90' ? '' : $k])) ?>#reparto"<?= $fRep === $k ? ' aria-current="true"' : '' ?>><?= e($lab) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php if (!$filasRep): ?>
    <p class="rp-vacio">Nadie recibió requerimientos <?= $fRep === 'todo' ? 'todavía' : 'en los últimos ' . e($periodosRep[$fRep]) ?>.</p>
  <?php else: ?>
  <div class="rp-cuerpo">
    <div class="rp-grafico" id="rp-grafico" role="img"
         aria-label="Requerimientos asignados por persona: <?= e(implode(', ', array_map(fn($f) => $f['completo'] . ' ' . $f['total'], array_slice($filasRep, 0, $topeRep)))) ?>"></div>
    <aside class="rp-lado">
      <div class="rp-dato"><span>Repartidos en el periodo</span><b><?= number_format($asignadosRep, 0, ',', '.') ?></b></div>
      <div class="rp-dato"><span>Promedio por programador</span><b><?= number_format($promedioDevs, 1, ',', '.') ?></b></div>
      <div class="rp-dato"><span>A quien más le tocó</span><b class="truncate" title="<?= e($filasRep[0]['completo']) ?>"><?= e($filasRep[0]['nombre']) ?> · <?= (int)$filasRep[0]['total'] ?></b></div>
      <?php if ($siguientes): ?>
      <div class="rp-sig">
        <h3>Los siguientes en la fila</h3>
        <ol>
          <?php foreach ($siguientes as $c): $m = $c['miembro']; ?>
          <li title="<?= e($m['nombre']) ?>: <?= (int)$c['total'] ?> pendiente(s) · <?= (int)$c['recibidos'] ?> requerimiento(s) en 90 días">
            <?= UI::avatar($m, 24) ?><span class="rp-sig__n truncate"><?= e($m['nombre']) ?></span>
            <small><?= (int)$c['total'] ?> pend. · <?= (int)$c['recibidos'] ?> en 90 d</small>
          </li>
          <?php endforeach; ?>
        </ol>
      </div>
      <?php endif; ?>
    </aside>
  </div>
  <?php if (count($filasRep) > $topeRep): ?>
  <p class="rp-nota">Se ven los <?= $topeRep ?> con más. <a href="req_dashboard.php">Todos en el Panel</a>.</p>
  <?php endif; ?>
  <script>window.REQ_REPARTO = <?= json_encode($repData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <?php endif; ?>
  </div>
</dialog>

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
                  : 'Plazo que estimó la IA: ' . (int)($pd['dias'] ?? 0) . ' día' . ((int)($pd['dias'] ?? 0) === 1 ? '' : 's') . ' hábil' . ((int)($pd['dias'] ?? 0) === 1 ? '' : 'es') . ', entrega el <b>' . e((string)($pd['fecha_fin_calc'] ?? '')) . '</b>'
                    . (!empty($pd['dias_motivo']) ? ' — ' . e((string)$pd['dias_motivo']) : '') . '.' ?></li>
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
          <span>¿Qué piden?</span>
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
      <?php panelObservacion('nr'); ?>

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

<!-- Asignar y Editar están en la pantalla de cada requerimiento (requerimiento.php). -->
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
        <legend>Plazo de respaldo <small>(días hábiles; solo si la IA no puede estimarlo)</small></legend>
        <p class="mc-field__hint">La IA estima los días de cada pedido sabiendo que el equipo trabaja con Claude Code: un cambio de texto o de etiqueta es 1 día, una pantalla nueva 2 o 3.</p>
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

<?php if (class_exists('ReqSeguimiento')):
    $seg = ReqSeguimiento::conf();
    $opsSeg = [];
    foreach ($miembros->todos() as $mS) $opsSeg[(int)$mS['id']] = $mS['nombre'] . (trim((string)($mS['rol'] ?? '')) !== '' ? ' · ' . $mS['rol'] : '');
?>
<!-- Seguimiento: a quién le llegan los terminados y los que se pasan de fecha (Felipe) -->
<dialog class="ds-modal" id="dlg-req-seg" aria-labelledby="dlg-seg-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="req_seguimiento_conf">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><i class="fa-solid fa-binoculars"></i></span>
      <div><h2 class="ds-modal__title" id="dlg-seg-t">Seguimiento de requerimientos</h2>
        <p class="ds-modal__sub">Quien da seguimiento recibe en la campana y por correo los datos de cada uno: responsables y colaboradores, fechas, si llegó a tiempo y cómo lo dejaron.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body req-seg">
      <label class="campo"><span>Quién da seguimiento</span>
        <?= UI::select('personas', $opsSeg, $seg['personas'], false, '', true) ?>
        <small class="campo-ayuda">Puedes elegir a varias personas.</small></label>
      <?= UI::checkDS('terminado', $seg['terminado'], 'Cuando alguien termina uno', 'Quién lo terminó, cuándo, si fue a tiempo o con cuántos días de retraso, y cómo lo dejó.') ?>
      <?= UI::checkDS('vencido', $seg['vencido'], 'Cuando a uno se le pasa la fecha de entrega', 'Una vez al día, en un solo correo con todos los que se pasaron, sus responsables y los días de retraso.') ?>
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
});
</script>
<link rel="stylesheet" href="<?= asset('assets/req-reparto.css') ?>">
<?php if (!empty($repData['filas'])): ?><script src="<?= asset('assets/apexcharts.min.js') ?>" defer></script><?php endif; ?>
<script src="<?= asset('assets/req-reparto.js') ?>" defer></script>
<?php UI::fin(); ?>
