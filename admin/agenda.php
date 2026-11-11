<?php
/**
 * La agenda de reuniones (agenda.php, «General › Agenda» en el menú): en una
 * sola pantalla, todas las reuniones que la persona puede ver, cada una con su
 * chip de proyecto o «General».
 *
 *   · Las generales (proyecto_id = 0): la «de todos» o la de varios equipos,
 *     sin ser de un proyecto. Las ve quien está invitado o la creó (el admin,
 *     todas; con «ver como», las de esa persona).
 *   · Las de los proyectos que ve (con «ver como», solo donde esa persona va).
 *   · Próximas | Historial y el buscador, como la suite de cada proyecto
 *     (reuniones.php): el pintado es el mismo (lib/meetings/reuniones_ui.php).
 *   · «Nueva reunión» (arriba, solo el ícono) abre el asistente en un modal:
 *     de qué es (General o uno de los proyectos que lleva), plataforma, día y
 *     hora, invitados (por equipo, con «Todo el equipo» y «Todos») y revisar.
 *     Valida antes de crear (tema, hora que no pasó, duración, al menos un
 *     invitado, Zoom libre, enlace válido); actions.php (reunion_crear) lo
 *     vuelve a validar todo y vuelve aquí con un aviso.
 *   · La general se edita aquí (?editar_reunion=ID desde su detalle, reunion.php).
 *   · ?nueva=1&tema=…&invitados=1,2,3[&p=ID] abre el asistente ya lleno (lo
 *     usan otras pantallas, p. ej. un requerimiento, para convocar a su gente).
 *
 * Quién crea: las generales, el administrador y el Scrum Master
 * (puedeReunionesGenerales); las de un proyecto, quien lleva sus reuniones
 * (puedeReunionesDelProyecto). Estilos: assets/agenda.css (y los de la suite en
 * admin.css). El selector de invitados y lo propio del asistente: assets/invitados.js.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/meetings/reuniones_ui.php';

if (esSupervisor()) {
    redirigir('index.php', 'Las reuniones no son parte de tu vista de supervisión.', 'error');
}

$ahora    = time();
$yo       = verComo() ?: Auth::usuario();
$yoId     = (int)($yo['id'] ?? 0);
$viendo   = verComo() !== null;               // «ver como»: solo lo de esa persona
$veTodas  = Auth::esAdmin() && !$viendo;      // el admin ve todas las generales
$miembros = (new MiembroRepo())->mapa();

/* ---------- Lo que se ve: las generales suyas y las de sus proyectos ---------- */
$proys = [];
foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) $proys[(int)$p['id']] = $p;
$general = Reuniones::proyectoGeneral();
$proysVer = $proys + [0 => $general];         // ReunionesUI::proximas/pasadas buscan el proyecto de cada una
$esSuya = fn(array $r) => (int)($r['creador_id'] ?? 0) === $yoId || in_array($yoId, array_map('intval', (array)($r['invitados'] ?? [])), true);
$todas = (new ReunionRepo())->todas();
$lista = array_values(array_filter($todas, function (array $r) use ($proys, $veTodas, $viendo, $esSuya): bool {
    $pid = (int)($r['proyecto_id'] ?? 0);
    if ($pid <= 0) return $veTodas || $esSuya($r);
    return isset($proys[$pid]) && (!$viendo || $esSuya($r));
}));

/* ---------- La pestaña, lo buscado y la página del historial ---------- */
$ver = ($_GET['ver'] ?? '') === 'historial' ? 'historial' : 'proximas';
$q   = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$qN  = ReunionesUI::normal($q);
$pag = max(1, min(50, (int)($_GET['pag'] ?? 1)));
$url = function (array $cambios = []) use ($ver, $q): string {
    $qs = array_filter(array_merge(['ver' => $ver === 'historial' ? 'historial' : '', 'q' => $q], $cambios), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return 'agenda.php' . ($qs ? '?' . http_build_query($qs) : '');
};
$coincide = fn(array $v) => $qN === '' || str_contains(ReunionesUI::textoBuscar($v, $miembros), $qN);

$proximas = array_values(array_filter(ReunionesUI::proximas($lista, $proysVer, $ahora), $coincide));
$ctx = ['ahora' => $ahora, 'miembros' => $miembros, 'proyecto' => true, 'h' => 'h2', 'desde' => $ver === 'historial' ? 'agenda_hist' : 'agenda'];
$pasadas = [];
$hayMas = false;
if ($ver === 'historial') {
    $pasadas = array_values(array_filter(ReunionesUI::pasadas($lista, $proysVer, $ahora), $coincide));
    $tope = 40 * $pag;
    $hayMas = count($pasadas) > $tope;
    $pasadas = array_slice($pasadas, 0, $tope);
    $ctx['rastros'] = ReunionesUI::rastros(array_map(fn($v) => $v['r'], $pasadas));
}

/* ---------- Quién crea qué ---------- */
$puedeGeneral = puedeReunionesGenerales();
$gestiona = array_filter($proys, fn($p) => puedeReunionesDelProyecto((int)$p['id']));
uasort($gestiona, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));
$puedeCrear = $puedeGeneral || $gestiona;

// ¿Se abre el asistente ya lleno? (?nueva=1&tema=…&invitados=1,2,3[&p=ID])
$abrirNueva = $puedeCrear && ($_GET['nueva'] ?? '') === '1';
$temaPre = $abrirNueva ? trim(mb_substr((string)($_GET['tema'] ?? ''), 0, 120)) : '';
$invPre = [];
if ($abrirNueva) {
    foreach (preg_split('/[\s,]+/', (string)($_GET['invitados'] ?? '')) ?: [] as $mid) {
        if ((int)$mid > 0 && isset($miembros[(int)$mid])) $invPre[] = (int)$mid;
    }
}
$pidPre = $abrirNueva ? (int)($_GET['p'] ?? 0) : 0;
$pidDefecto = isset($gestiona[$pidPre]) ? $pidPre : ($puedeGeneral ? 0 : (int)array_key_first($gestiona));
$elegirDonde = ($puedeGeneral ? 1 : 0) + count($gestiona) > 1;

// Editar una general (desde su detalle: reunion.php → ?editar_reunion=ID&volver=…)
$editarId = (int)($_GET['editar_reunion'] ?? 0);
$editarR = $editarId ? (new ReunionRepo())->buscar($editarId) : null;
if ($editarR && (!Reuniones::esGeneral($editarR) || !puedeEditarReunion($editarR))) $editarR = null;
$puedeEditarAlguna = $puedeGeneral;   // el asistente de editar va en la página para quien edita generales
$ruVolver = (string)($_GET['volver'] ?? '');
$ruVolver = $editarR && preg_match('#^(reunion|agenda)\.php(\?[\w=&%.,+-]*)?$#', $ruVolver) ? $ruVolver : 'agenda.php';

/* ---------- El encabezado ---------- */
$acciones = '';
if ($puedeCrear) {
    $acciones .= ReunionesUI::botonCab('ds-mas', 'Nueva reunión: general o de un proyecto', '', '',
        'aria-haspopup="dialog" onclick="document.getElementById(\'dlg-nueva-reunion\').showModal()"');
}
$vivas = array_values(array_filter($proximas, fn($v) => $v['estado'] === 'vivo'));
$deHoy = array_values(array_filter($proximas, fn($v) => $v['dia'] === date('Y-m-d', $ahora)));
if ($q !== '') {
    $frase = 'Las reuniones que coinciden con lo que buscas.';
} elseif ($vivas) {
    $frase = count($vivas) === 1 ? 'Ahora mismo está en curso «' . e(ReunionesUI::tema($vivas[0]['r'])) . '».' : 'Ahora mismo hay ' . count($vivas) . ' reuniones en curso.';
} elseif ($deHoy) {
    $frase = 'Hoy ' . (count($deHoy) === 1 ? 'queda 1 reunión' : 'quedan ' . count($deHoy) . ' reuniones') . '; la próxima, a las ' . e(ReunionesUI::hora($deHoy[0]['ts'])) . '.';
} elseif ($proximas) {
    $frase = 'Hoy no queda ninguna. La próxima: ' . e(mb_strtolower(ReunionesUI::cuando($proximas[0]['ts'], $ahora))) . '.';
} else {
    $frase = 'Las reuniones generales y las de tus proyectos, por día.';
}

/* ---------- Lo del asistente ---------- */
$platElegir  = Reuniones::puedeElegir();
$platDefecto = Reuniones::plataformaDefecto(null) ?: 'enlace';
$disp = Reuniones::disponibles();
// Las tarjetas de plataforma: todas las listas si se puede elegir; si no, la del panel y el enlace propio
$plats = $platElegir ? array_keys($disp) : array_values(array_unique([$platDefecto, 'enlace']));
$platInfo = [
    'zoom'   => ['ds-video', 'Zoom', 'Se graba y la grabación llega al panel'],
    'meet'   => ['ds-global', 'Google Meet', 'Se crea en tu Google Calendar'],
    'enlace' => ['ds-enlace', 'Enlace propio', 'Tu propio enlace; no se graba'],
];
$durOpciones = Reuniones::duraciones();
$durDefecto  = Reuniones::duracionDefecto();
$inicioDefecto = date('Y-m-d\TH:i', (int)(ceil(($ahora + 3600) / 900) * 900));
$enCalendario = Reuniones::agendaEnCalendarios();
$PASOS = [
    ['Reunión',      'De qué es la reunión', 'El tema, si es general o de un proyecto, y dónde se hace', 'ds-reuniones'],
    ['Día y hora',   'Cuándo es',            'Las horas en gris ya las tiene Zoom',                     'ds-reloj'],
    ['Invitados',    'Quiénes van',          'Personas sueltas, un equipo entero o todos',               'ds-usuarios'],
    ['Confirmación', 'Revisar reunión',      'Comprueba los datos antes de crearla',                     'ds-check'],
];
$PASOS_EDITAR = [
    ['Reunión',      'De qué es la reunión', 'El tema que ven los invitados',      'ds-reuniones'],
    ['Día y hora',   'Cuándo es',            'Las horas en gris ya las tiene Zoom', 'ds-reloj'],
    ['Invitados',    'Quiénes van',          'A los nuevos les llega el enlace',    'ds-usuarios'],
    ['Confirmación', 'Revisar cambios',      'Comprueba los datos antes de guardarlos', 'ds-check'],
];
// «Del proyecto» en los invitados: uno por proyecto que lleva (se ve el del proyecto elegido)
$invProyectos = [];
if ($gestiona) {
    $particip = ReunionesUI::participantes();
    foreach ($gestiona as $pid => $p) $invProyectos[$pid] = ['p' => $p, 'ids' => ReunionesUI::delProyecto($p, $miembros, $particip)];
}
// La hora: el selector de siempre y, al final, la guarda de «la hora no pasó» (assets/invitados.js)
$conGuarda = fn(string $hp) => substr($hp, 0, -6)
    . '<input type="radio" class="inv-req" name="_guarda_hora" value="1" data-ag-futuro required tabindex="-1" aria-hidden="true" checked data-error="Elige un día y una hora que todavía no pasaron.">'
    . '</div>';
$repetir = function (bool $edicion) use ($ahora): string {
    $h = '<div class="campo repetir-caja" data-resumen="No se repite"><span hidden>Repetición</span>'
        . '<label class="chk-linea" title="' . ($edicion ? 'Al guardar se actualiza toda la serie, no un día suelto.' : 'Se crea una sola reunión con el mismo enlace para todos los días.') . '">'
        . '<input type="checkbox" name="recurrente" value="1" class="js-repetir' . ($edicion ? ' js-er-recurrente' : '') . '">'
        . '<span class="chk-caja">' . UI::checkDibujado(12) . '</span> Repetir todas las semanas</label>'
        . '<div class="repetir-detalle" hidden><div class="dias-semana' . ($edicion ? ' js-er-dias' : '') . '">';
    foreach (Reuniones::DIAS as $dn => [$corto, $largo]) {
        $h .= '<label class="dia-chip" title="' . e($largo) . '"><input type="checkbox" name="dias[]" value="' . $dn . '"' . (!$edicion && $dn <= 5 ? ' checked' : '') . '><span>' . e($corto) . '</span></label>';
    }
    return $h . '</div><label class="repetir-hasta"><span>hasta</span>'
        . '<input class="input-meca' . ($edicion ? ' js-er-hasta' : '') . '" type="date" name="hasta" value="' . ($edicion ? '' : date('Y-m-d', strtotime('+1 month', $ahora))) . '"></label></div>'
        . '<input type="radio" class="inv-req" name="_guarda_repite" value="1" data-ag-repite required tabindex="-1" aria-hidden="true" checked data-error="Marca al menos un día y una fecha final que no sea anterior al primer día.">'
        . '</div>';
};
$pie = fn(string $ok, string $icono) => '<div class="wz-pie"><span class="wz-contador"></span><div class="wz-acciones">'
    . '<button type="button" class="btn-outline btn-meca btn-neutro wz-atras">' . UI::icono('ds-chevron-izq') . ' Atrás</button>'
    . '<button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente ' . UI::icono('ds-chevron-der') . '</button>'
    . '<button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar">' . UI::icono($icono) . ' ' . e($ok) . '</button>'
    . '</div></div>';
$cabPaso = '<header><div><h4 class="wz-titulo-paso"></h4><p class="wz-ayuda-paso"></p></div>'
    . '<button type="button" class="dlg-close" onclick="this.closest(\'dialog\').close()" aria-label="Cerrar">' . UI::icono('ds-cerrar') . '</button></header>';

UI::inicio('Agenda de reuniones', 'agenda');
ReunionesUI::cabecera('', '', 'Agenda de reuniones', '<span class="ru-cab__frase">' . $frase . '</span>', $acciones);
?>

<div class="ru ag" data-ru>
  <div class="ru-barra">
    <nav class="ru-tabs" data-ru-tabs data-activa="<?= $ver ?>" aria-label="Agenda de reuniones">
      <span class="ru-tabs__pieza" aria-hidden="true"></span>
      <a class="ru-tabs__op" data-op="proximas" href="<?= e($url(['ver' => '', 'pag' => ''])) ?>"<?= $ver === 'proximas' ? ' aria-current="page"' : '' ?>>
        <?= UI::icono('Calendar') ?><span>Próximas</span><?php if ($proximas): ?><small class="ru-tabs__n"><?= count($proximas) ?></small><?php endif; ?>
      </a>
      <a class="ru-tabs__op" data-op="historial" href="<?= e($url(['ver' => 'historial', 'pag' => ''])) ?>"<?= $ver === 'historial' ? ' aria-current="page"' : '' ?>>
        <?= UI::icono('ds-reloj') ?><span>Historial</span>
      </a>
    </nav>
    <form class="ru-buscar" method="get" action="agenda.php" role="search">
      <?php if ($ver === 'historial'): ?><input type="hidden" name="ver" value="historial"><?php endif; ?>
      <label class="ru-buscar__caja">
        <?= UI::icono('ds-buscar') ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por tema, proyecto o persona" autocomplete="off" data-ru-buscar aria-label="Buscar en la agenda de reuniones">
      </label>
    </form>
  </div>

  <?php if ($q !== ''): ?>
  <p class="ru-filtro">Buscando «<b><?= e($q) ?></b>» · <a href="<?= e($url(['q' => '', 'pag' => ''])) ?>">Quitar la búsqueda</a></p>
  <?php endif; ?>

  <?php if ($ver === 'proximas'): ?>
    <?php $html = ReunionesUI::proximasHtml($proximas, $ctx); ?>
    <?php if ($html !== ''): ?>
      <div class="ru-lista"><?= $html ?></div>
    <?php elseif ($q !== ''): ?>
      <?= ReunionesUI::vacio('ds-buscar', 'Ninguna próxima coincide', 'Prueba con otra palabra o mira en el historial.') ?>
    <?php elseif (!$lista): ?>
      <?= UI::vacioDS('entrada-reunion', 'Todavía no hay reuniones en tu agenda',
          e($puedeCrear ? 'Crea la primera con el botón + de arriba: una general, para todos o para varios equipos, o la de uno de tus proyectos.' : 'Cuando te inviten a una reunión, sale aquí en su día, con el botón para entrar.')) ?>
    <?php else: ?>
      <?= ReunionesUI::vacio('CalendarCheck', 'No hay reuniones por venir', $puedeCrear ? 'Crea una con el botón + de arriba: sale aquí, en su día, con el botón para entrar.' : 'Cuando te inviten a una, sale aquí en su día.') ?>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($pasadas): ?>
      <div class="ru-lista"><?= ReunionesUI::dias($pasadas, $ctx, true) ?></div>
      <?php if ($hayMas): ?>
      <p class="ru-mas"><a class="rs-entrar rs-entrar--suave" href="<?= e($url(['pag' => $pag + 1])) ?>"><?= UI::icono('ds-chevron-abajo') ?> Ver las anteriores</a></p>
      <?php endif; ?>
    <?php elseif ($q !== ''): ?>
      <?= ReunionesUI::vacio('ds-buscar', 'Nada en el historial coincide', 'Prueba con otra palabra.') ?>
    <?php else: ?>
      <?= ReunionesUI::vacio('ds-reloj', 'Todavía no hay reuniones realizadas', 'Cuando termine una, queda aquí con su grabación, sus observaciones y quién entró.') ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php /* Lo que dice el buscador al vuelo cuando nada de lo que se ve coincide (admin.js) */ ?>
  <div data-ru-sin hidden><?= ReunionesUI::vacio('ds-buscar', 'Nada de lo que se ve coincide', 'Pulsa Enter para buscar también en lo que no se cargó.') ?></div>
</div>

<?php if ($puedeCrear): ?>
<!-- Modal: nueva reunión, por pasos. General (sin proyecto) o de un proyecto que lleva. -->
<dialog id="dlg-nueva-reunion" class="dlg-meca dlg-wizard ag-dlg"<?= $abrirNueva ? ' data-abrir-al-cargar' : '' ?>>
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="reunion_crear">
    <input type="hidden" name="volver" value="agenda.php">
    <?php if (!$elegirDonde): ?><input type="hidden" name="proyecto_id" value="<?= $pidDefecto ?>"><?php endif; ?>
    <?= UI::wizardRiel('ds-reuniones', 'Nueva reunión', $elegirDonde ? 'En la agenda' : ($pidDefecto ? 'En ' . (string)$gestiona[$pidDefecto]['nombre'] : 'Reunión general'), $PASOS) ?>
    <div class="wz-cuerpo">
      <?= $cabPaso ?>

      <section class="wz-panel">
        <label class="campo"><span>Tema</span>
          <input class="input-meca" name="topic" required maxlength="120" value="<?= e($temaPre) ?>" placeholder="Ej. Reunión de todos los equipos" data-error="Escribe el tema de la reunión.">
        </label>
        <?php if ($elegirDonde):
            $opDonde = [];
            if ($puedeGeneral) $opDonde['0'] = ['General', 'De todos o de varios equipos; no es de un proyecto'];
            foreach ($gestiona as $pid => $p) $opDonde[(string)$pid] = [(string)$p['nombre'], 'Queda en la pestaña Reuniones del proyecto'];
        ?>
        <div class="campo ag-donde" data-resumen="<?= e($pidDefecto ? (string)$gestiona[$pidDefecto]['nombre'] : 'General') ?>"><span>¿De qué es?</span>
          <?= UI::selectDS('proyecto_id', $opDonde, (string)$pidDefecto, ['tam' => 'md', 'aria' => 'De qué es la reunión', 'buscar' => count($opDonde) > 8]) ?>
          <small class="campo-ayuda">Una general solo la ven sus invitados. La de un proyecto, además, todo el proyecto.</small>
        </div>
        <?php endif; ?>
        <div class="campo" data-resumen="<?= e(Reuniones::etiquetaPlataforma(in_array($platDefecto, $plats, true) ? $platDefecto : 'enlace')) ?>"><span>Plataforma</span>
          <input type="hidden" name="plataforma" value="<?= e(in_array($platDefecto, $plats, true) ? $platDefecto : 'enlace') ?>">
          <div class="subvista-toggle nr-plat">
            <?php foreach ($plats as $k): [$ic, $nom, $desc] = $platInfo[$k]; ?>
            <button type="button" class="subvista-btn <?= $k === $platDefecto ? 'active' : '' ?>" data-plat="<?= e($k) ?>"><span class="nr-plat__ic" aria-hidden="true"><?= UI::icono($ic) ?></span> <b><?= e($nom) ?></b><small><?= e($desc) ?></small></button>
            <?php endforeach; ?>
          </div>
          <small class="campo-ayuda nr-meet-hint" <?= $platDefecto === 'meet' ? '' : 'hidden' ?>>La reunión de Meet se crea en TU Google Calendar (necesitas tenerlo conectado en Mi perfil).</small>
        </div>
        <label class="campo nr-enlace" <?= $platDefecto === 'enlace' ? '' : 'hidden' ?>>
          <span>Enlace de la reunión</span>
          <input class="input-meca" type="url" name="join_url" inputmode="url" pattern="https?://.+" maxlength="500"<?= $platDefecto === 'enlace' ? ' required' : '' ?>
                 placeholder="https://us05web.zoom.us/j/… o el enlace que uses" data-error="Pega el enlace completo de la reunión: empieza por https://">
          <small class="campo-ayuda">El panel guarda el detalle, la agenda y avisa a los invitados, pero la reunión no queda grabada: la grabación solo llega desde Zoom.</small>
        </label>
      </section>

      <section class="wz-panel">
        <?= $conGuarda(UI::selectorHora($inicioDefecto, '', UI::select('duracion', $durOpciones, (string)$durDefecto))) ?>
        <?= $repetir(false) ?>
      </section>

      <section class="wz-panel wz-panel--inv">
        <?= ReunionesUI::selectorInvitados($miembros, $invPre, ['proyectos' => $invProyectos, 'proyecto' => $pidDefecto, 'requerido' => true,
            'ayuda' => 'Les llega el enlace por correo' . ($enCalendario ? ' y queda en su Google Calendar' : '') . '.']) ?>
      </section>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <?= $pie('Crear reunión', 'ds-check') ?>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($puedeEditarAlguna): ?>
<!-- Modal: editar una reunión general o invitar a más gente (desde su detalle, reunion.php) -->
<dialog id="dlg-editar-reunion" class="dlg-meca dlg-wizard ag-dlg">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="reunion_editar">
    <input type="hidden" name="id" id="er-id" value="">
    <input type="hidden" name="volver" value="<?= e($ruVolver) ?>">
    <?= UI::wizardRiel('ds-editar', 'Editar reunión', 'Reunión general', $PASOS_EDITAR) ?>
    <div class="wz-cuerpo">
      <?= $cabPaso ?>
      <section class="wz-panel">
        <label class="campo"><span>Tema</span>
          <input class="input-meca" name="topic" id="er-topic" required maxlength="120" data-error="Escribe el tema de la reunión.">
        </label>
      </section>
      <section class="wz-panel">
        <?= $conGuarda(UI::selectorHora('', 'er-inicio', UI::select('duracion', $durOpciones, (string)$durDefecto, false, 'js-er-duracion'))) ?>
        <?= $repetir(true) ?>
      </section>
      <section class="wz-panel wz-panel--inv">
        <?php /* Los ya invitados los marca assets/invitados.js con los datos del botón .js-editar-reunion */ ?>
        <?= ReunionesUI::selectorInvitados($miembros, [], ['requerido' => true, 'ayuda' => 'A los invitados nuevos les llega el enlace por correo.']) ?>
      </section>
      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>
      <?= $pie('Guardar cambios', 'ds-check') ?>
    </div>
  </form>
</dialog>
<?php if ($editarR): /* desde el detalle: admin.js lo pulsa al cargar (data-ru-abrir) */ ?>
<button type="button" class="js-editar-reunion" data-editar-reunion='<?= ReunionesUI::datosEditar($editarR) ?>' data-ru-abrir hidden></button>
<?php endif; ?>
<?php endif; ?>

<?php UI::fin(); ?>
