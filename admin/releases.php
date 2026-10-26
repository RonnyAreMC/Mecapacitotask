<?php
/**
 * Releases: la planificación estratégica del proyecto (R0…R4) en el panel.
 *
 * Reemplaza los dos Excel de la gerencia («Cronograma General» y «Revisiones»):
 * qué ítems tiene cada release y de qué equipo son, cómo van frente a lo que
 * el plan espera hoy, las revisiones semanales por equipo y los hitos.
 * El modelo está en lib/Releases.php; las acciones (rel_*) en actions.php; el
 * JavaScript en assets/releases.js y el estilo al final de assets/ds.css.
 *
 * Vistas (?v=):
 *   tablero      indicadores, hoja de ruta, matriz equipos × releases, alertas
 *   release      ?r=R2 (&e, &estado, &salud, &q): Gantt por equipo o lista
 *   equipo       ?e=Alpha: SM, PO, avance por release, cobertura de tareas, IA
 *   item         ?id=N (con &parcial=1 sale solo el detalle, para el panel lateral)
 *   revisiones   ?s=AAAA-MM-DD (&e): agenda de la semana y cumplimiento
 *   revision     ?id=N: registrar lo revisado fila por fila
 *   hitos        línea de tiempo de los hitos
 *   ajustes      ?t=equipos|releases|avisos|importar (solo editores)
 *
 * Las fechas del release NO limitan las tareas que se crean desde aquí: el plan
 * tuvo reajustes; solo se avisa.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Releases.php';
if (!Releases::puedeVer()) redirigir('index.php', 'No tienes acceso a Releases.', 'error');

$VISTAS = ['tablero', 'release', 'equipo', 'item', 'revisiones', 'revision', 'hitos', 'ajustes'];
$v = (string)($_GET['v'] ?? 'tablero');
if (!in_array($v, $VISTAS, true)) $v = 'tablero';
$editor = Releases::puedeEditar();
if ($v === 'ajustes' && !$editor) redirigir('releases.php', 'Los ajustes de Releases los cambian solo sus editores.', 'error');
// Quien solo mira (no es editor ni SM de un equipo) ve el release y los hitos, nada más
$veTodo = Releases::veTodo();
if (!$veTodo && !in_array($v, Releases::VISTAS_LECTOR, true)) $v = 'release';

$hoy      = date('Y-m-d');
$yo       = Auth::usuario();
$yoId     = (int)($yo['id'] ?? 0);
$conf     = Releases::conf();
$rels     = Releases::releases();
$eqs      = Releases::equipos();
$miembros = (new MiembroRepo())->mapa();
$proyMapa = [];
foreach ((new ProyectoRepo())->todos() as $p) $proyMapa[(int)$p['id']] = $p;
$todos    = Releases::items();
$porId    = [];
$porCodigo = [];
foreach ($todos as $it) { $porId[(int)$it['id']] = $it; $porCodigo[$it['codigo']] = $it; }
// El Scrum Master de un equipo marca cómo va lo suyo; los editores, todo
$smDe = fn(string $eq): bool => $editor || ($yoId > 0 && (int)($eqs[$eq]['sm_id'] ?? 0) === $yoId);
$mapaT = null;
$tareas = function () use (&$mapaT): array {
    if ($mapaT === null) { $mapaT = []; foreach ((new TareaRepo())->todas() as $t) $mapaT[(int)$t['id']] = $t; }
    return $mapaT;
};
$estadosTarea = Catalogo::estadosTarea();
$finales = Catalogo::estadosFinales();

// Las ayudas de pintado (rlChip, rlBarra, rlAnillo…) y la vista de un release viven en
// lib/releases_ui.php: las usa también la pestaña «Release» del proyecto.
require_once __DIR__ . '/lib/releases_ui.php';

/* ============================== Datos de cada vista ============================== */

// El release que va ahora (para la pestaña «Releases») y el equipo de cabecera
$relActual = '';
foreach ($rels as $c => $r) if (($r['estado'] ?? '') === 'en_curso') { $relActual = $c; break; }
if ($relActual === '') foreach ($rels as $c => $r) if (($r['inicio'] ?? '') <= $hoy && ($r['fin'] ?? '') >= $hoy) { $relActual = $c; break; }
if ($relActual === '') $relActual = (string)(array_key_first($rels) ?? '');
$eqDefault = (string)(Releases::equipoDeSm($yoId)['nombre'] ?? '');
if ($eqDefault === '') foreach ($eqs as $n => $e) if ($n !== 'Todos') { $eqDefault = $n; break; }
if ($eqDefault === '') $eqDefault = (string)(array_key_first($eqs) ?? '');

/** Las revisiones donde apareció un ítem: [[rev, fila]] */
$apariciones = function (array $it): array {
    $out = [];
    foreach (Releases::revisiones() as $r) {
        foreach ((array)$r['filas'] as $f) {
            if ((int)($f['item_id'] ?? 0) === (int)$it['id'] || ($f['codigo'] ?? '') === $it['codigo']) $out[] = ['r' => $r, 'f' => $f];
        }
    }
    return $out;
};

/** Datos del ítem para el JS (crear tarea, vincular). */
$datosItemJs = function (array $it) use ($eqs, $proyMapa): array {
    $pids = Releases::proyectosEquipo($it['equipo']);
    if (!$pids) foreach ($eqs as $e) $pids = array_merge($pids, Releases::proyectosEquipo($e['nombre']));
    $pids = array_values(array_unique($pids));
    $proys = [];
    foreach ($pids as $pid) if (isset($proyMapa[$pid])) $proys[] = ['id' => $pid, 'nombre' => $proyMapa[$pid]['nombre'], 'equipo' => true];
    foreach ($proyMapa as $pid => $p) if (!in_array($pid, $pids, true) && ($p['estado'] ?? 'activo') === 'activo') $proys[] = ['id' => $pid, 'nombre' => $p['nombre'], 'equipo' => false];
    return ['id' => (int)$it['id'], 'codigo' => $it['codigo'], 'titulo' => $it['titulo'], 'equipo' => $it['equipo'],
            'inicio' => $it['inicio'], 'fin' => $it['fin'], 'proyectos' => $proys, 'miembros' => $eqs[$it['equipo']]['miembros'] ?? []];
};

/**
 * El detalle de un ítem: en su página y en el panel lateral (&parcial=1).
 * Editar estado, avance y fechas; tareas vinculadas; historial y revisiones.
 */
$pintarItem = function (array $it, bool $panel) use ($eqs, $rels, $miembros, $editor, $smDe, $tareas, $estadosTarea, $finales, $proyMapa, $apariciones, $datosItemJs, $hoy, $porCodigo): string {
    $marca = $smDe($it['equipo']);
    $mapa = $tareas();
    $vinculadas = array_values(array_filter(array_map(fn($id) => $mapa[(int)$id] ?? null, $it['tareas'])));
    $hechas = count(array_filter($vinculadas, fn($t) => in_array($t['estado'] ?? '', $finales, true)));
    // Para vincular: las tareas del equipo (o de todos los equipos si es «Todos») que aún no están
    $delEquipo = Releases::tareasEquipo($it['equipo']);
    if (!$delEquipo) foreach ($eqs as $e) if ($e['nombre'] !== $it['equipo']) $delEquipo = array_merge($delEquipo, Releases::tareasEquipo($e['nombre']));
    $ya = array_flip($it['tareas']);
    $paraVincular = array_values(array_filter($delEquipo, fn($t) => !isset($ya[(int)$t['id']])));
    usort($paraVincular, fn($a, $b) => [in_array($a['estado'] ?? '', $finales, true), -(int)$a['id']] <=> [in_array($b['estado'] ?? '', $finales, true), -(int)$b['id']]);
    $aps = $apariciones($it);
    $relColor = rlColorRel($rels, $it['release']);
    $hist = array_reverse((array)($it['historial'] ?? []));
    $origCambio = ($it['inicio_original'] ?? '') !== '' && (($it['inicio_original'] ?? '') !== $it['inicio'] || ($it['fin_original'] ?? '') !== $it['fin']);
    $deps = rlDependencias((string)($it['dependencias'] ?? ''), $porCodigo, $it['equipo']);
    $dt = (array)($it['tareas_resumen'] ?? []) + ['total' => 0, 'hechas' => 0, 'en_curso' => 0, 'pct' => 0];
    $fuente = (string)($it['fuente'] ?? ($dt['total'] ? 'tareas' : 'manual'));
    $base = (int)($it['avance_base'] ?? $it['avance']);
    $porIA = array_flip(array_map('intval', (array)($it['tareas_ia'] ?? [])));
    $motivoBloqueo = '';
    foreach (array_reverse((array)($it['historial'] ?? [])) as $h) if (str_contains((string)$h['texto'], '→ Bloqueado')) { $motivoBloqueo = (string)($h['motivo'] ?? ''); break; }
    $detalle = array_filter([
        'Alcance' => $it['alcance'] ?? '', 'Qué debe demostrarse' => $it['demostrar'] ?? '', 'Entregable de cierre' => $it['entregable'] ?? '',
        'Criterio de aceptación' => $it['criterio'] ?? '', 'Proceso BPM' => $it['proceso'] ?? '', 'Actividades' => $it['actividades'] ?? '',
        'Fases' => $it['fases'] ?? '', 'Responsable' => $it['responsable'] ?? '',
    ], fn($x) => trim((string)$x) !== '');
    $nombre = fn(int $id) => (string)($miembros[$id]['nombre'] ?? ($id ? 'Alguien' : 'El sistema'));
    ob_start(); ?>
<div class="rl-item<?= $panel ? ' rl-item--panel' : '' ?>" data-rl-item-raiz="<?= (int)$it['id'] ?>" data-rl-item-datos="<?= e(json_encode($datosItemJs($it), JSON_UNESCAPED_UNICODE)) ?>">
  <header class="rl-item__cab" style="--rc:<?= e($relColor) ?>">
    <div class="rl-item__chips">
      <?= rlCode($it['codigo'], $relColor, 'rl-code--lg') ?>
      <a class="rl-tag" href="<?= e(rlUrl(['v' => 'release', 'r' => $it['release']])) ?>"><?= rlIco('ruta', 14) ?> <?= e($it['release']) ?> · <?= e($rels[$it['release']]['nombre'] ?? '') ?></a>
      <a class="rl-tag" href="<?= e(rlUrl(['v' => 'equipo', 'e' => $it['equipo']])) ?>"><?= rlEqChip($eqs, $it['equipo']) ?></a>
    </div>
    <h2 class="rl-item__tit font-display"><?= e($it['titulo']) ?></h2>
    <div class="rl-item__estado">
      <?= rlEstado($it['estado']) ?> <?= rlSalud($it['salud']) ?>
      <span class="rl-item__dias<?= in_array($it['salud'], ['vencido', 'atrasado'], true) ? ' is-mal' : '' ?>"><?= rlIco('reloj', 15) ?> <?= e(rlDias($it['dias_restantes'], $it['estado'])) ?></span>
      <?php if ($panel): ?><a class="rl-item__abrir" href="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>"><?= rlIco('expandir', 15) ?> Abrir en su página</a><?php endif; ?>
    </div>
  </header>

  <div class="rl-item__grid">
    <div class="rl-item__main">
      <section class="rl-bloque rl-avance rl-s--<?= e($it['salud']) ?>">
        <div class="rl-avance__nums">
          <div><span class="rl-avance__big mc-num font-display"><?= (int)$it['avance'] ?><small>%</small></span>
            <span class="rl-avance__lbl"><?= $fuente === 'tareas' ? rlIco('completar', 14) . ' Según sus tareas' : rlIco('editar', 14) . ($dt['total'] ? ' Puesto a mano' : ' Del Excel o la última revisión') ?></span></div>
          <div><span class="rl-avance__med mc-num"><?= (int)$it['esperado'] ?> %</span><span class="rl-avance__lbl">Esperado hoy</span></div>
          <div><span class="rl-avance__med"><?= e(rlF($it['inicio'])) ?> → <?= e(rlF($it['fin'], true)) ?></span><span class="rl-avance__lbl"><?= $origCambio ? 'Plan original: ' . e(rlF($it['inicio_original'])) . ' → ' . e(rlF($it['fin_original'], true)) : 'Fechas del plan' ?></span></div>
        </div>
        <?= rlBarra((int)$it['avance'], (int)$it['esperado'], $it['salud'], 'rl-barra--lg') ?>
        <?php if ($dt['total']): $porHacer = max(0, $dt['total'] - $dt['hechas'] - $dt['en_curso']); ?>
        <div class="rl-avance__tareas">
          <span class="rl-pila rl-pila--tareas" aria-hidden="true"><?php if ($dt['hechas']): ?><i class="is-hecha" style="flex:<?= (int)$dt['hechas'] ?>"></i><?php endif; ?><?php if ($dt['en_curso']): ?><i class="is-curso" style="flex:<?= (int)$dt['en_curso'] ?>"></i><?php endif; ?><?php if ($porHacer): ?><i class="is-pend" style="flex:<?= $porHacer ?>"></i><?php endif; ?></span>
          <p><b><?= (int)$dt['hechas'] ?> de <?= (int)$dt['total'] ?></b> completada<?= $dt['total'] === 1 ? '' : 's' ?><?= $dt['en_curso'] ? ' · ' . (int)$dt['en_curso'] . ' en curso' : '' ?><?= $porHacer ? ' · ' . $porHacer . ' por hacer' : '' ?>
            <span class="rl-avance__ayuda">Las tareas suman su avance: completada 100 %, en revisión 80 %, en progreso 50 %. <?php if ($fuente === 'tareas' && $base > (int)$dt['pct']): ?>Se mantiene lo verificado (<?= $base ?> %) hasta que las tareas lo pasen.<?php endif; ?></span></p>
        </div>
        <?php else: ?>
        <p class="rl-avance__ayuda"><?= rlIco('info', 15) ?> Sin tareas vinculadas: el avance es el del Excel o el de la última revisión. Cuando el equipo tenga tareas, vincúlalas o pide a la IA que las asocie y el avance saldrá solo del tablero.</p>
        <?php endif; ?>
      </section>

      <?php if ($it['estado'] === 'bloqueado'): ?>
      <?= UI::alertaDS('danger', 'Este ítem está bloqueado', $motivoBloqueo !== '' ? '«' . e($motivoBloqueo) . '»' : 'Mientras siga bloqueado, sus tareas no mueven el estado.') ?>
      <?php endif; ?>

      <?php if ($marca): ?>
      <section class="rl-bloque rl-sm">
        <div class="rl-bloque__cab">
          <h3 class="rl-bloque__tit"><?= rlIco('notas', 18) ?> Notas y bloqueos</h3>
          <div class="rl-bloque__acc">
          <?php if ($it['estado'] === 'bloqueado'): ?>
            <button type="button" class="mc-btn mc-btn--success mc-btn--sm" data-rl-bloqueo-abrir="desbloquear"><?= rlIco('desbloqueado', 16) ?><span class="mc-btn__label">Desbloquear</span></button>
          <?php else: ?>
            <button type="button" class="mc-btn mc-btn--secondary mc-btn--sm rl-btn-peligro" data-rl-bloqueo-abrir="bloquear"><?= rlIco('candado', 16) ?><span class="mc-btn__label">Bloquear</span></button>
          <?php endif; ?>
          </div>
        </div>
        <form class="rl-bloqueo" method="post" action="actions.php" data-rl-item-form data-rl-bloqueo hidden>
          <input type="hidden" name="accion" value="rel_item_guardar"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>" data-siempre>
          <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>">
          <input type="hidden" name="estado" value="<?= $it['estado'] === 'bloqueado' ? 'empezado' : 'bloqueado' ?>" data-siempre>
          <?= rlCampo($it['estado'] === 'bloqueado' ? '¿Qué se destrabó?' : '¿Qué lo bloquea?', rlInput('name="motivo" maxlength="400" required placeholder="' . ($it['estado'] === 'bloqueado' ? 'Por ejemplo: UBE ya definió el flujo' : 'Por ejemplo: esperando la definición de UBE') . '"'), 'Queda en el historial y se avisa al equipo.') ?>
          <div class="rl-bloqueo__pie">
            <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rl-bloqueo-cerrar>Cancelar</button>
            <button type="submit" class="mc-btn mc-btn--sm <?= $it['estado'] === 'bloqueado' ? 'mc-btn--success' : 'mc-btn--danger' ?>"><span class="mc-btn__label"><?= $it['estado'] === 'bloqueado' ? 'Desbloquear' : 'Bloquear el ítem' ?></span></button>
          </div>
        </form>
        <form method="post" action="actions.php" data-rl-item-form class="rl-nota">
          <input type="hidden" name="accion" value="rel_item_guardar"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>" data-siempre>
          <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>">
          <div class="mc-input mc-input--multiline"><textarea class="mc-input__control rl-textarea" name="nota" rows="2" data-inicial="<?= e((string)($it['nota'] ?? '')) ?>" placeholder="Lo que hay que saber de este ítem: dependencias, acuerdos, riesgos…" aria-label="Nota del ítem"><?= e((string)($it['nota'] ?? '')) ?></textarea></div>
          <div class="rl-nota__pie"><button class="mc-btn mc-btn--secondary mc-btn--sm" type="submit"><?= rlIco('check', 16) ?><span class="mc-btn__label">Guardar nota</span></button></div>
        </form>
      </section>
      <?php elseif (($it['nota'] ?? '') !== ''): ?>
      <section class="rl-bloque"><h3 class="rl-bloque__tit"><?= rlIco('notas', 18) ?> Nota</h3><p class="rl-nota__txt"><?= nl2br(e((string)$it['nota'])) ?></p></section>
      <?php endif; ?>

      <?php if ($editor): $aMano = ($it['avance_fuente'] ?? 'tareas') === 'manual'; ?>
      <details class="rl-bloque rl-manual">
        <summary><?= rlIco('configuracion', 18) ?><span><b>Ajuste manual</b><small>Para editores: estado, avance, fechas, equipo y release.</small></span><?= rlIco('chevron-abajo', 16) ?></summary>
        <form class="rl-editar" method="post" action="actions.php" data-rl-item-form>
          <input type="hidden" name="accion" value="rel_item_guardar">
          <input type="hidden" name="id" value="<?= (int)$it['id'] ?>" data-siempre>
          <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>">
          <span class="mc-field__label">Estado</span>
          <div class="rl-seg rl-seg--estado" role="radiogroup" aria-label="Estado del ítem">
            <?php foreach (Releases::ESTADOS as $k => [$lbl, $tono, $tip]): ?>
            <label class="rl-seg__op rl-t--<?= e($tono) ?>">
              <input type="radio" name="estado" value="<?= e($k) ?>" data-tipico="<?= $tip === null ? '' : (int)$tip ?>"<?= $it['estado'] === $k ? ' checked data-inicial="1"' : '' ?>>
              <span><i aria-hidden="true"></i><?= e($lbl) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <?= rlSwitch('avance_fuente', $aMano, 'Avance a mano', 'Apagado, el avance sale de las tareas. El % que pongas queda como lo verificado: las tareas pueden subirlo, no bajarlo.', 'manual') ?>
          <div class="rl-rango">
            <label class="mc-field__label" for="rl-av-<?= (int)$it['id'] ?>">Avance</label>
            <input type="range" min="0" max="100" step="5" id="rl-av-<?= (int)$it['id'] ?>" name="avance" value="<?= (int)$it['avance'] ?>" data-inicial="<?= (int)$it['avance'] ?>"
                   data-rl-rango style="--v:<?= (int)$it['avance'] ?>%; --e:<?= (int)$it['esperado'] ?>%">
            <output class="rl-rango__val mc-num" data-rl-rango-val><?= (int)$it['avance'] ?> %</output>
          </div>
          <div class="rl-form-grid rl-form-grid--2">
            <?= rlCampo('Inicio', '<input class="input-meca" type="date" name="inicio" value="' . e($it['inicio']) . '" data-inicial="' . e($it['inicio']) . '">') ?>
            <?= rlCampo('Fin', '<input class="input-meca" type="date" name="fin" value="' . e($it['fin']) . '" data-inicial="' . e($it['fin']) . '">') ?>
            <?= rlCampo('Equipo', UI::selectDS('equipo', array_combine(array_keys($eqs), array_keys($eqs)), $it['equipo'], ['attrs' => 'data-inicial="' . e($it['equipo']) . '"'])) ?>
            <?= rlCampo('Release', UI::selectDS('release', array_map(fn($r) => $r['codigo'] . ' · ' . $r['nombre'], $rels), $it['release'], ['attrs' => 'data-inicial="' . e($it['release']) . '"'])) ?>
          </div>
          <div class="rl-editar__pie">
            <?= rlCampo('Motivo del cambio', rlInput('name="motivo" maxlength="400" placeholder="Por ejemplo: reajuste v8 aprobado por Gerencia"'), 'Queda en el historial y va en el aviso.') ?>
            <button class="mc-btn mc-btn--primary" type="submit"><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar ajuste</span></button>
          </div>
        </form>
      </details>
      <?php endif; ?>

      <?php if ($deps['partes']): ?>
      <section class="rl-bloque">
        <h3 class="rl-bloque__tit"><?= rlIco('relacionar', 18) ?> Depende de / se integra con</h3>
        <div class="rl-deps">
          <?php foreach ($deps['partes'] as $d): ?>
          <?php if ($d['item']): ?>
          <a class="rl-dep<?= $d['otro'] ? ' is-otro' : '' ?>" href="<?= e(rlUrl(['v' => 'item', 'id' => (int)$d['item']['id']])) ?>" data-rl-item="<?= (int)$d['item']['id'] ?>"<?= rlTipItem($d['item']) ?>>
            <?= rlCode($d['item']['codigo'], rlColorRel($rels, $d['item']['release'])) ?><span><?= e($d['resto'] !== '' ? $d['resto'] : $d['item']['titulo']) ?></span>
            <?= $d['otro'] ? rlEqChip($eqs, $d['item']['equipo']) : '' ?><?= rlSalud($d['item']['salud']) ?>
          </a>
          <?php else: ?>
          <span class="rl-dep"><?= rlIco('relacionar', 14) ?><span><?= e($d['texto']) ?></span></span>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($detalle || ($it['microservicio'] ?? '') !== '' || ($it['sprints'] ?? '') !== ''): ?>
      <section class="rl-bloque">
        <h3 class="rl-bloque__tit"><?= rlIco('documento', 18) ?> Qué es</h3>
        <div class="rl-datos-chips">
          <?php if (($it['microservicio'] ?? '') !== ''): ?><span class="rl-dato"><?= rlIco('microservicios', 15) ?> <?= e($it['microservicio']) ?></span><?php endif; ?>
          <?php if (($it['sprints'] ?? '') !== ''): ?><span class="rl-dato"><?= rlIco('racha', 15) ?> Sprints <?= e($it['sprints']) ?></span><?php endif; ?>
          <?php if (!empty($it['verificar'])): ?><span class="rl-dato rl-dato--warn"><?= rlIco('alerta', 15) ?> Por verificar</span><?php endif; ?>
        </div>
        <dl class="rl-dl">
          <?php foreach ($detalle as $lbl => $txt): ?>
          <div><dt><?= e($lbl) ?></dt><dd><?= nl2br(e((string)$txt)) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </section>
      <?php endif; ?>

      <section class="rl-bloque" data-rl-tareas-bloque data-rl-tareas="<?= e(json_encode($it['tareas'])) ?>">
        <div class="rl-bloque__cab">
          <h3 class="rl-bloque__tit"><?= rlIco('completar', 18) ?> Tareas que lo cubren <span class="rl-n"><?= count($vinculadas) ?></span></h3>
          <?php if ($marca): ?>
          <div class="rl-bloque__acc">
            <button type="button" class="mc-btn mc-btn--secondary mc-btn--sm" data-rl-vincular-abrir aria-expanded="false"><?= rlIco('relacionar', 16) ?><span class="mc-btn__label">Vincular tareas</span></button>
            <button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-rl-crear-tarea><?= rlIco('mas', 16) ?><span class="mc-btn__label">Crear tarea</span></button>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($vinculadas): ?>
        <ul class="rl-tareas">
          <?php foreach ($vinculadas as $t):
              $fin = in_array($t['estado'] ?? '', $finales, true);
              $fueraFecha = !$fin && ($t['fecha_limite'] ?? '') !== '' && $it['fin'] !== '' && $t['fecha_limite'] > $it['fin'];
              $asig = array_values(array_filter(array_map(fn($id) => $miembros[$id] ?? null, TareaRepo::asignadosDe($t)))); ?>
          <li class="rl-tarea rl-te--<?= e(preg_replace('/[^a-z_]/', '', (string)($t['estado'] ?? ''))) ?><?= $fin ? ' is-hecha' : '' ?>">
            <span class="rl-tarea__check" aria-hidden="true"><?= $fin ? UI::checkDibujado(12) : '' ?></span>
            <span class="rl-tarea__txt">
              <a href="proyecto.php?id=<?= (int)$t['proyecto_id'] ?>&amp;tarea=<?= (int)$t['id'] ?>"><b>#<?= (int)$t['id'] ?></b> <?= e($t['titulo']) ?></a>
              <small><span class="rl-tarea__estado"><?= e($estadosTarea[$t['estado'] ?? ''][0] ?? ($t['estado'] ?? '')) ?></span><?php if (isset($porIA[(int)$t['id']])): ?> <span class="rl-chip-ia" data-ds-tip="La vinculó la IA. Si no corresponde, quítala: no la volverá a poner."><?= rlIco('ia', 12) ?>IA</span><?php endif; ?> · <?= e($proyMapa[(int)$t['proyecto_id']]['nombre'] ?? 'Proyecto') ?>
                <?php if (($t['fecha_limite'] ?? '') !== ''): ?> · límite <?= e(rlF($t['fecha_limite'])) ?><?php endif; ?>
                <?php if ($fueraFecha): ?> <span class="rl-aviso-fecha" data-ds-tip="Termina después del fin del ítem (<?= e(rlF($it['fin'])) ?>). No es un error: solo un aviso."><?= rlIco('alerta', 13) ?> después del fin del ítem</span><?php endif; ?>
              </small>
            </span>
            <span class="rl-tarea__gente"><?= $asig ? UI::avatarStack($asig, 3, 24) : '' ?></span>
            <?php if ($marca): ?>
            <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm rl-btn-quitar" data-rl-desvincular="<?= (int)$t['id'] ?>" aria-label="Quitar la tarea #<?= (int)$t['id'] ?> de este ítem"><?= rlIco('cerrar', 14) ?><span class="mc-btn__label">Quitar</span></button>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="rl-vacio-txt"><?= rlIco('info', 16) ?> Ninguna tarea del panel cubre este ítem todavía. <?= $marca ? 'Vincula las que ya existen o crea una nueva: las fechas del release no la limitan.' : '' ?></p>
        <?php endif; ?>
        <?php if ($marca): ?>
        <div class="rl-vincular" data-rl-vincular hidden>
          <div class="mc-input mc-input--sm"><?= UI::iconoDS('buscar', 16, 'mc-input__icon') ?>
            <input class="mc-input__control" type="search" placeholder="Buscar entre las tareas del equipo…" data-rl-vincular-q aria-label="Buscar tareas"></div>
          <div class="rl-vincular__lista">
            <?php foreach ($paraVincular as $t): $fin = in_array($t['estado'] ?? '', $finales, true); ?>
            <label class="rl-vincular__op" data-buscar="<?= e(mb_strtolower('#' . $t['id'] . ' ' . $t['titulo'] . ' ' . ($proyMapa[(int)$t['proyecto_id']]['nombre'] ?? ''))) ?>">
              <input type="checkbox" value="<?= (int)$t['id'] ?>">
              <span class="rl-multi__caja" aria-hidden="true"><?= UI::checkDibujado(11) ?></span>
              <span class="rl-multi__txt"><b>#<?= (int)$t['id'] ?> <?= e($t['titulo']) ?></b>
                <small><?= e($estadosTarea[$t['estado'] ?? ''][0] ?? '') ?> · <?= e($proyMapa[(int)$t['proyecto_id']]['nombre'] ?? '') ?><?= ($t['fecha_limite'] ?? '') !== '' ? ' · límite ' . e(rlF($t['fecha_limite'])) : '' ?><?= $fin ? ' · ya completada' : '' ?></small></span>
            </label>
            <?php endforeach; ?>
            <?php if (!$paraVincular): ?><p class="rl-multi__vacio">No hay más tareas en los proyectos del equipo. Revisa en Ajustes qué proyectos tiene el equipo.</p><?php endif; ?>
          </div>
          <div class="rl-vincular__pie">
            <span data-rl-vincular-n>0 elegidas</span>
            <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rl-vincular-cerrar>Cancelar</button>
            <button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-rl-vincular-ok disabled><?= rlIco('relacionar', 16) ?><span class="mc-btn__label">Vincular</span></button>
          </div>
        </div>
        <?php endif; ?>
      </section>
    </div>

    <aside class="rl-item__lado">
      <section class="rl-bloque">
        <h3 class="rl-bloque__tit"><?= rlIco('agenda', 18) ?> En las revisiones <span class="rl-n"><?= count($aps) ?></span></h3>
        <?php if ($aps): ?>
        <ul class="rl-aps">
          <?php foreach ($aps as ['r' => $r, 'f' => $f]): $pasada = $r['fecha'] < $hoy; ?>
          <li class="rl-ap rl-r--<?= e($f['estado'] ?? 'pendiente') ?>">
            <a href="<?= e(rlUrl(['v' => 'revision', 'id' => (int)$r['id']])) ?>" class="rl-ap__fecha"><b class="mc-num"><?= e(date('d', strtotime($r['fecha']))) ?></b><small><?= e(RL_MES[(int)date('n', strtotime($r['fecha'])) - 1]) ?></small></a>
            <div class="rl-ap__txt">
              <span class="rl-ap__tipo"><?= e($f['tipo'] ?: 'Revisión') ?></span>
              <span class="rl-ap__res"><?= ($f['estado'] ?? 'pendiente') !== 'pendiente' ? rlResultado($f['estado']) : rlChip($pasada ? 'Sin registrar' : 'Programada', 'gris') ?>
                <?php if ($f['pct'] !== null): ?><b class="mc-num"><?= (int)$f['pct'] ?> % real</b><?php endif; ?>
                <?php if ($f['esperado'] !== null): ?><small>esperado <?= (int)$f['esperado'] ?> %</small><?php endif; ?></span>
              <?php if (trim((string)($f['obs'] ?? '')) !== ''): ?><p><?= e($f['obs']) ?></p><?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="rl-vacio-txt">No aparece en ninguna revisión.</p>
        <?php endif; ?>
      </section>

      <section class="rl-bloque">
        <h3 class="rl-bloque__tit"><?= rlIco('reloj', 18) ?> Historial</h3>
        <ol class="rl-hist">
          <?php foreach ($hist as $h):
              $q = (int)($h['quien'] ?? 0);
              $txtH = (string)$h['texto'];
              $esIA = $q === 0 && str_starts_with($txtH, 'La IA');
              $quienH = $q > 0 ? $nombre($q) : ($esIA ? 'La IA' : (str_starts_with($txtH, 'Según sus tareas') ? 'Según el tablero' : 'El sistema')); ?>
          <li class="<?= $q === 0 ? ($esIA ? 'is-ia' : 'is-sistema') : '' ?>">
            <span class="rl-hist__punto" aria-hidden="true"><?= $q === 0 ? rlIco($esIA ? 'ia' : 'automatizacion', 12) : '' ?></span>
            <div>
              <p class="rl-hist__txt"><?= e($txtH) ?></p>
              <?php if (trim((string)($h['motivo'] ?? '')) !== ''): ?><p class="rl-hist__motivo">«<?= e((string)$h['motivo']) ?>»</p><?php endif; ?>
              <small><?= $q > 0 && isset($miembros[$q]) ? UI::avatar($miembros[$q], 16) . ' ' : '' ?><?= e($quienH) ?> · <?= e(rlFL(substr((string)$h['fecha'], 0, 10), false)) ?><?= strlen((string)$h['fecha']) > 10 ? ', ' . e(substr((string)$h['fecha'], 11, 5)) : '' ?></small>
            </div>
          </li>
          <?php endforeach; ?>
          <?php if (!$hist): ?><li><div><p class="rl-hist__txt">Sin cambios registrados.</p></div></li><?php endif; ?>
        </ol>
      </section>
      <?php if ($editor && !$panel): ?>
      <form method="post" action="actions.php" class="rl-borrar-item" data-confirmar="Se borra el ítem con su historial. Las tareas del panel no se tocan." data-confirmar-titulo="¿Borrar <?= e($it['codigo']) ?>?" data-confirmar-ok="Sí, borrar">
        <input type="hidden" name="accion" value="rel_item_borrar"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
        <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'release', 'r' => $it['release']])) ?>">
        <button class="mc-btn mc-btn--ghost mc-btn--sm rl-btn-peligro" type="submit"><?= rlIco('eliminar', 16) ?><span class="mc-btn__label">Borrar este ítem</span></button>
      </form>
      <?php endif; ?>
    </aside>
  </div>
</div>
    <?php
    return (string)ob_get_clean();
};

// El panel lateral pide solo el detalle del ítem
if ($v === 'item' && isset($_GET['parcial'])) {
    header('Cache-Control: no-store');
    $it = $porId[(int)($_GET['id'] ?? 0)] ?? null;
    echo $it ? $pintarItem($it, true) : UI::vacioDS('nexo:pensando', 'Ese ítem ya no existe', 'Puede que lo hayan borrado.', '', 'sm', false);
    exit;
}

/* ============================== Cabecera y pestañas ============================== */

// Los resúmenes con IA son para quien gestiona (editores y SM); quien solo mira no los ve
$botonIA = fn(string $texto, array $data) => !$veTodo ? '' : '<button type="button" class="mc-btn mc-btn--secondary rl-btn-ia"' . implode('', array_map(fn($k, $x) => ' data-' . $k . '="' . e((string)$x) . '"', array_keys($data), $data)) . '>'
    . rlIco('ia', 18) . '<span class="mc-btn__label">' . e($texto) . '</span></button>';
$acciones = '';
$sub = 'La planificación estratégica en un solo lugar: avance por release y por equipo, revisiones semanales y alertas de fechas.';
switch ($v) {
    case 'tablero':
        $acciones = '<button type="button" class="mc-btn mc-btn--ghost" data-rl-personalizar aria-expanded="false">' . rlIco('configuracion', 18) . '<span class="mc-btn__label">Personalizar</span></button>'
            . $botonIA('Resumen con IA', ['rl-ia-general' => '1']);
        break;
    case 'release':
        $acciones = $botonIA('Resumen con IA', ['rl-ia-general' => '1', 'release' => (string)($_GET['r'] ?? $relActual), 'equipo' => (string)($_GET['e'] ?? '')])
            . ($editor ? '<button type="button" class="mc-btn mc-btn--primary" data-rl-abrir="rl-dlg-item">' . rlIco('mas', 18) . '<span class="mc-btn__label">Nuevo ítem</span></button>' : '');
        break;
    case 'equipo':
        $acciones = $botonIA('Resumen del equipo', ['rl-ia-general' => '1', 'equipo' => (string)($_GET['e'] ?? $eqDefault)]);
        break;
    case 'revisiones':
        if ($editor || Releases::equipoDeSm($yoId)) $acciones = '<button type="button" class="mc-btn mc-btn--primary" data-rl-abrir="rl-dlg-revision">' . rlIco('mas', 18) . '<span class="mc-btn__label">Nueva revisión</span></button>';
        break;
    case 'hitos':
        if ($editor) $acciones = '<button type="button" class="mc-btn mc-btn--primary" data-rl-hito-nuevo>' . rlIco('mas', 18) . '<span class="mc-btn__label">Nuevo hito</span></button>';
        break;
}

$tabs = ['tablero' => ['Tablero', 'dashboard', rlUrl([])],
         'release' => ['Releases', 'ruta', rlUrl(['v' => 'release', 'r' => $relActual])],
         'equipo' => ['Equipos', 'usuarios', rlUrl(['v' => 'equipo', 'e' => $eqDefault])],
         'revisiones' => ['Revisiones', 'agenda', rlUrl(['v' => 'revisiones'])],
         'hitos' => ['Hitos', 'bandera', rlUrl(['v' => 'hitos'])]];
if ($editor) $tabs['ajustes'] = ['Ajustes', 'configuracion', rlUrl(['v' => 'ajustes'])];
if (!$veTodo) $tabs = array_intersect_key($tabs, array_flip(Releases::VISTAS_LECTOR));
$tabActiva = ['item' => 'release', 'revision' => 'revisiones'][$v] ?? $v;

UI::inicio('Releases', 'releases');
$ilus = !$todos ? 'releases-importar' : (['tablero' => 'releases-tablero', 'release' => 'releases-release', 'item' => 'releases-release', 'equipo' => 'releases-equipos',
    'revisiones' => 'releases-revisiones', 'revision' => 'releases-revisiones', 'hitos' => 'releases-hitos', 'ajustes' => 'releases-importar'][$v] ?? 'releases-tablero');
UI::cabecera('Releases <span class="text-secondary">· ' . e($conf['programa']) . '</span>', $sub, $acciones, $ilus);
?>
<div class="ds rl" data-rl data-vista="<?= e($v) ?>">
  <nav class="mc-tabs mc-tabs--line rl-tabs" aria-label="Secciones de Releases">
    <?php foreach ($tabs as $k => [$lbl, $ico, $url]): ?>
    <a class="mc-tab<?= $k === $tabActiva ? ' is-active' : '' ?>" href="<?= e($url) ?>"<?= $k === $tabActiva ? ' aria-current="page"' : '' ?>><?= rlIco($ico, 18) ?><span><?= e($lbl) ?></span></a>
    <?php endforeach; ?>
  </nav>

<?php if (!$todos && $v !== 'ajustes'): ?>
  <?= UI::vacioDS('nexo:pensando', 'Todavía no hay un plan cargado',
        'Importa los dos Excel de la planificación (el cronograma general y el calendario de revisiones) y aquí aparecen los releases, los equipos, los ítems y las revisiones.',
        $editor ? '<a class="mc-btn mc-btn--primary" href="' . e(rlUrl(['v' => 'ajustes', 't' => 'importar'])) . '">' . rlIco('importar', 18) . '<span class="mc-btn__label">Importar los Excel</span></a>'
                : '<span class="rl-vacio-txt">Pídele al administrador que los importe.</span>') ?>
<?php elseif ($v === 'tablero'):
/* =========================================================================
   TABLERO
   ========================================================================= */
    $tab = Releases::tablero();
    $T = $tab['total'];
    $delta = $T['pct'] - $T['esperado'];
    $golive = $conf['go_live'];
    $diasGo = $golive !== '' ? (int)floor((strtotime($golive) - strtotime($hoy)) / 86400) : null;
    $hitos = Releases::hitos();
    $hitoGo = null;
    foreach ($hitos as $h) if ($h['fecha'] === $golive) { $hitoGo = $h; break; }
    $saludT = rlSaludGrupo($T, $hoy);
?>
  <section class="rl-kpis" aria-label="Indicadores">
    <article class="mc-kpi mc-kpi--azul" style="--i:0">
      <div class="mc-kpi__head"><span class="mc-tile mc-tile--azul mc-tile--sm"><?= rlIco('progreso', 20) ?></span><p class="mc-kpi__label">Avance general</p></div>
      <p class="mc-kpi__value mc-num"><?= (int)$T['pct'] ?><span class="mc-kpi__unit">%</span></p>
      <?= rlBarra((int)$T['pct'], (int)$T['esperado'], $saludT, 'rl-barra--kpi') ?>
      <div class="mc-kpi__meta-row">
        <span class="mc-kpi__delta mc-kpi__delta--<?= $delta >= 0 ? 'good' : 'bad' ?>"><?= $delta >= 0 ? '+' : '−' ?><?= abs($delta) ?> pts</span>
        <span class="mc-kpi__hint">esperado hoy <?= (int)$T['esperado'] ?> %</span>
      </div>
    </article>
    <article class="mc-kpi mc-kpi--verde" style="--i:1">
      <div class="mc-kpi__head"><span class="mc-tile mc-tile--verde mc-tile--sm"><?= rlIco('exito', 20) ?></span><p class="mc-kpi__label">Terminados</p></div>
      <p class="mc-kpi__value mc-num"><?= (int)$T['cerrados'] ?><span class="mc-kpi__unit">de <?= (int)$T['total'] ?></span></p>
      <div class="mc-kpi__progress"><div class="mc-kpi__bar"><span class="mc-kpi__bar-fill" style="--p:<?= $T['total'] ? round($T['cerrados'] / $T['total'], 3) : 0 ?>"></span></div>
        <span class="mc-kpi__bar-text"><?= (int)$T['en_curso'] ?> en curso · <?= (int)$T['por_iniciar'] ?> por iniciar</span></div>
    </article>
    <a class="mc-kpi mc-kpi--rojo mc-kpi--clickable" style="--i:2" href="#rl-alertas">
      <div class="mc-kpi__head"><span class="mc-tile mc-tile--rojo mc-tile--sm"><?= rlIco('alerta', 20) ?></span><p class="mc-kpi__label">Atrasados</p></div>
      <p class="mc-kpi__value mc-num"><?= (int)$T['atrasados'] ?></p>
      <span class="mc-kpi__hint">vencidos, por debajo del 70 % de lo esperado o bloqueados</span>
    </a>
    <a class="mc-kpi mc-kpi--naranja mc-kpi--clickable" style="--i:3" href="#rl-alertas">
      <div class="mc-kpi__head"><span class="mc-tile mc-tile--naranja mc-tile--sm"><?= rlIco('reloj', 20) ?></span><p class="mc-kpi__label">En riesgo</p></div>
      <p class="mc-kpi__value mc-num"><?= (int)$T['riesgo'] ?></p>
      <span class="mc-kpi__hint">un poco por debajo de lo que el plan espera hoy</span>
    </a>
    <article class="mc-kpi mc-kpi--featured rl-kpi-go" style="--i:4"<?= $hitoGo ? ' title="' . e($hitoGo['titulo']) . '"' : '' ?>>
      <?= UI::nexo($diasGo !== null && $diasGo <= 21 ? 'corriendo' : 'con-laptop', 'rl-kpi-go__nexo') ?>
      <div class="mc-kpi__head"><span class="mc-tile mc-tile--sm"><?= rlIco('bandera', 20) ?></span><p class="mc-kpi__label">Go-Live</p></div>
      <?php if ($diasGo === null): ?>
      <p class="mc-kpi__value">—</p><span class="mc-kpi__hint">Pon la fecha en Ajustes → Avisos.</span>
      <?php elseif ($diasGo >= 0): ?>
      <p class="mc-kpi__value mc-num"><?= $diasGo ?><span class="mc-kpi__unit"><?= $diasGo === 1 ? 'día' : 'días' ?></span></p>
      <span class="mc-kpi__hint rl-kpi-go__fecha"><?= (int)date('j', strtotime($golive)) ?> <?= e(RL_MES[(int)date('n', strtotime($golive)) - 1]) ?> <?= date('Y', strtotime($golive)) ?></span>
      <?php else: ?>
      <p class="mc-kpi__value">En vivo</p><span class="mc-kpi__hint rl-kpi-go__fecha">desde el <?= e(rlF($golive, true)) ?></span>
      <?php endif; ?>
    </article>
  </section>

  <div class="rl-personalizar mc-pop" data-rl-personalizar-pop hidden role="dialog" aria-label="Qué se ve en el tablero">
    <p class="rl-personalizar__t">Qué se ve en el tablero</p>
    <?php foreach (['ruta' => 'Hoja de ruta', 'matriz' => 'Equipos × releases', 'revisiones' => 'Próximas revisiones', 'alertas' => 'Alertas', 'hitos' => 'Próximos hitos'] as $k => $lbl): ?>
    <?= UI::checkDS('rl-sec-' . $k, true, $lbl, '', 'rl-personalizar__op') ?>
    <?php endforeach; ?>
    <p class="rl-personalizar__nota">Se guarda en este navegador.</p>
  </div>

  <div class="rl-ia-slot" data-rl-ia-slot hidden></div>

<?php
    // ---------- Hoja de ruta ----------
    $ini = $fin = '';
    foreach ($rels as $r) {
        if (($r['inicio'] ?? '') !== '' && ($ini === '' || $r['inicio'] < $ini)) $ini = $r['inicio'];
        if (($r['fin'] ?? '') !== '' && $r['fin'] > $fin) $fin = $r['fin'];
    }
    if ($golive !== '' && $golive > $fin) $fin = $golive;
    if ($ini !== '' && $fin !== ''):
        $ts0 = strtotime(date('Y-m-01', strtotime($ini)));
        $ts1 = strtotime(date('Y-m-t', strtotime($fin)) . ' +1 day');
        $pos = fn(string $f) => round((strtotime($f) - $ts0) / max(1, $ts1 - $ts0) * 100, 3);
        $meses = [];
        for ($t = $ts0; $t < $ts1; $t = strtotime('+1 month', $t)) $meses[] = $t;
        // Los hitos en tres carriles, para que los que caen juntos no se pisen
        $carril = [-99, -99, -99];
        $hitosPos = [];
        foreach ($hitos as $h) {
            $x = $pos($h['fecha']);
            if ($x < 0 || $x > 100) continue;
            $c = 0;
            while ($c < 2 && $x - $carril[$c] < 1.6) $c++;
            $carril[$c] = $x;
            $hitosPos[] = [$h, $x, $c];
        }
?>
  <section class="mc-card rl-card" data-rl-sec="ruta">
    <header class="rl-card__cab">
      <div><h2 class="rl-card__tit"><?= rlIco('ruta', 20) ?> Hoja de ruta</h2>
        <p class="rl-card__sub">Cada release con su ventana y su avance. La raya fina es lo que el plan espera hoy; la punteada, el plan anterior.</p></div>
      <ul class="rl-leyenda" aria-label="Leyenda">
        <li><i class="rl-ley rl-ley--fill"></i>Avance real</li>
        <li><i class="rl-ley rl-ley--esp"></i>Esperado hoy</li>
        <li><i class="rl-ley rl-ley--ant"></i>Plan anterior</li>
        <li><i class="rl-ley rl-ley--hito"></i>Hito</li>
      </ul>
    </header>
    <div class="rl-ruta-scroll">
      <div class="rl-ruta">
        <div class="rl-ruta__eje" aria-hidden="true">
          <?php foreach ($meses as $i => $t): $x = $pos(date('Y-m-d', $t)); $w = $pos(date('Y-m-d', strtotime('+1 month', $t))) - $x; ?>
          <span class="rl-ruta__mes<?= $i % 2 ? ' is-par' : '' ?>" style="left:<?= $x ?>%;width:<?= $w ?>%"><?= e(RL_MES[(int)date('n', $t) - 1]) ?><?= (int)date('n', $t) === 1 || $i === 0 ? ' <b>' . date('Y', $t) . '</b>' : '' ?></span>
          <?php endforeach; ?>
        </div>
        <div class="rl-ruta__cuerpo">
          <div class="rl-ruta__fondo" aria-hidden="true">
            <?php foreach ($meses as $i => $t): ?><span style="left:<?= $pos(date('Y-m-d', $t)) ?>%"></span><?php endforeach; ?>
            <?php $xh = $pos($hoy); if ($xh >= 0 && $xh <= 100): ?><i class="rl-hoy" style="left:<?= $xh ?>%"><b>Hoy</b></i><?php endif; ?>
          </div>
          <div class="rl-ruta__hitos">
            <?php foreach ($hitosPos as [$h, $x, $c]): $esGo = $h['fecha'] === $golive; ?>
            <a class="rl-rombo<?= !empty($h['hecho']) ? ' is-hecho' : '' ?><?= $esGo ? ' is-go' : '' ?>" href="<?= e(rlUrl(['v' => 'hitos'])) ?>#hito-<?= (int)$h['id'] ?>" style="left:<?= $x ?>%;--c:<?= $c ?>;--hc:<?= e(rlColorRel($rels, (string)($h['release'] ?? ''))) ?>"
               <?= rlTip(['h' => $h['titulo'], 'f' => rlFL($h['fecha'], true, true), 'r' => (string)($h['release'] ?? ''), 'x' => (string)($h['descripcion'] ?? ''), 'ok' => !empty($h['hecho'])]) ?> aria-label="<?= e($h['titulo'] . ' · ' . rlF($h['fecha'], true)) ?>"></a>
            <?php endforeach; ?>
          </div>
          <?php foreach ($tab['releases'] as $cod => $x):
              $r = $x['release'];
              if (($r['inicio'] ?? '') === '' || ($r['fin'] ?? '') === '') continue;
              $l = $pos($r['inicio']);
              $w = max(1.2, $pos(date('Y-m-d', strtotime($r['fin'] . ' +1 day'))) - $l);
              $ant = ($r['fin_anterior'] ?? '') !== '' && $r['fin_anterior'] !== $r['fin'];
              $wa = $ant ? min(100 - $l, max(1.2, $pos(date('Y-m-d', strtotime($r['fin_anterior'] . ' +1 day'))) - $l)) : 0;
              $antSale = $ant && $r['fin_anterior'] > date('Y-m-d', $ts1);
              $sal = rlSaludGrupo($x, $hoy); ?>
          <a class="rl-ruta__fila" href="<?= e(rlUrl(['v' => 'release', 'r' => $cod])) ?>" style="--rc:<?= e($r['color']) ?>"
             <?= rlTip(['c' => $cod, 't' => $r['nombre'] ?? '', 'f' => rlF($r['inicio'], true) . ' → ' . rlF($r['fin'], true), 'a' => (int)$x['pct'], 'e' => (int)$x['esperado'],
                 'sa' => Releases::SALUD[$sal][0] ?? 'Sin ítems', 's' => $sal, 'n' => (int)$x['total'], 'k' => (int)$x['cerrados'], 'o' => $ant ? 'Fin anterior: ' . rlF($r['fin_anterior'], true) : '']) ?>>
            <span class="rl-ruta__lbl"><?= rlCode($cod, $r['color']) ?><span class="rl-ruta__nom"><?= e($r['nombre'] ?? '') ?></span></span>
            <span class="rl-ruta__pista">
              <?php if ($ant && $wa > $w): ?><span class="rl-ruta__ant<?= $antSale ? ' is-sale' : '' ?>" style="left:<?= $l ?>%;width:<?= $wa ?>%"></span><?php endif; ?>
              <span class="rl-ruta__barra<?= $r['estado'] === 'cerrado' ? ' is-cerrado' : '' ?>" style="left:<?= $l ?>%;width:<?= $w ?>%">
                <span class="rl-ruta__fill" style="width:<?= (int)$x['pct'] ?>%"></span>
                <?php if ($x['esperado'] > 0 && $x['esperado'] < 100): ?><span class="rl-ruta__esp" style="left:<?= (int)$x['esperado'] ?>%"></span><?php endif; ?>
                <span class="rl-ruta__pct mc-num"><?= (int)$x['pct'] ?> %</span>
              </span>
            </span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>

    <section class="mc-card rl-card" data-rl-sec="matriz">
      <header class="rl-card__cab">
        <div><h2 class="rl-card__tit"><?= rlIco('usuarios', 20) ?> Equipos × releases</h2>
          <p class="rl-card__sub">El % real de cada equipo en cada release, con la marca de lo esperado. Pulsa una celda para ver sus ítems.</p></div>
      </header>
      <div class="rl-matriz-scroll">
        <table class="rl-matriz">
          <thead><tr><th scope="col">Equipo</th>
            <?php foreach ($rels as $cod => $r): ?><th scope="col"><?= rlCode($cod, $r['color']) ?></th><?php endforeach; ?>
            <th scope="col" class="rl-matriz__tot">Total</th><th scope="col" class="rl-matriz__dl" data-ds-tip="Días con la daily hecha y su gente conectada, desde el <?= e(rlF($conf['dailies']['desde'], true)) ?>">Dailies</th></tr></thead>
          <tbody>
          <?php foreach ($tab['equipos'] as $nom => $x): if (!$x['total']) continue; $sm = $miembros[(int)($x['equipo']['sm_id'] ?? 0)] ?? null; $dlx = Releases::resumenDailies($nom); ?>
            <tr>
              <th scope="row"><a class="rl-matriz__eq" href="<?= e(rlUrl(['v' => 'equipo', 'e' => $nom])) ?>" style="--ec:<?= e(rlColorEq($eqs, $nom)) ?>">
                <span class="rl-matriz__dot"></span><span><b><?= e($nom) ?></b><small><?= e($sm['nombre'] ?? ($x['equipo']['sm_nombre'] ?? '')) ?></small></span></a></th>
              <?php foreach ($rels as $cod => $r): $c = $tab['releases'][$cod]['equipos'][$nom] ?? null; ?>
              <td><?php if ($c): $s = rlSaludGrupo($c, $hoy); ?>
                <a class="rl-celda rl-s--<?= e($s) ?>" href="<?= e(rlUrl(['v' => 'release', 'r' => $cod, 'e' => $nom])) ?>"
                   <?= rlTip(['c' => $cod . ' · ' . $nom, 'a' => (int)$c['pct'], 'e' => (int)$c['esperado'], 'sa' => Releases::SALUD[$s][0] ?? '', 's' => $s, 'n' => (int)$c['total'], 'k' => (int)$c['cerrados'], 'z' => (int)$c['atrasados'], 'y' => (int)$c['riesgo']]) ?>>
                  <b class="mc-num"><?= (int)$c['pct'] ?>%</b><?= rlBarra((int)$c['pct'], (int)$c['esperado'], $s) ?><small><?= (int)$c['cerrados'] ?>/<?= (int)$c['total'] ?></small></a>
              <?php else: ?><span class="rl-celda rl-celda--vacia" aria-label="Sin ítems">—</span><?php endif; ?></td>
              <?php endforeach; ?>
              <?php $s = rlSaludGrupo($x, $hoy); ?>
              <td class="rl-matriz__tot"><a class="rl-celda rl-celda--tot rl-s--<?= e($s) ?>" href="<?= e(rlUrl(['v' => 'equipo', 'e' => $nom])) ?>">
                <b class="mc-num"><?= (int)$x['pct'] ?>%</b><?= rlBarra((int)$x['pct'], (int)$x['esperado'], $s) ?><small><?= (int)$x['cerrados'] ?>/<?= (int)$x['total'] ?></small></a></td>
              <td class="rl-matriz__dl"><?php if ($dlx['pct'] !== null): ?>
                <a class="rl-celda rl-celda--dl" href="<?= e(rlUrl(['v' => 'equipo', 'e' => $nom])) ?>#dailies" data-ds-tip="<?= (int)$dlx['cuenta']['cumplida'] ?> de <?= (int)$dlx['cuentan'] ?> día(s) cumplidos<?= $dlx['asistencia'] !== null ? ' · asistencia ' . (int)$dlx['asistencia'] . ' %' : '' ?>">
                  <b class="mc-num"><?= (int)$dlx['pct'] ?>%</b><span class="rl-dbarra" aria-hidden="true"><i style="--p:<?= (int)$dlx['pct'] ?>%"></i></span>
                  <?php if ($dlx['cuenta']['sin_registrar'] === $dlx['cuentan']): ?><small class="is-mal">Sin daily en el panel</small><?php else: ?><small><?= (int)$dlx['cuenta']['cumplida'] ?>/<?= (int)$dlx['cuentan'] ?> días</small><?php endif; ?></a>
              <?php else: ?><span class="rl-celda rl-celda--vacia" data-ds-tip="<?= !$dlx['aplica'] ? 'Sin gente ni proyectos: no tiene daily' : ($conf['dailies']['desde'] > $hoy ? 'Se mide desde el ' . e(rlF($conf['dailies']['desde'], true)) : 'Aún no hay días medidos') ?>">—</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th scope="row">Todos</th>
            <?php foreach ($tab['releases'] as $cod => $x): $s = rlSaludGrupo($x, $hoy); ?>
            <td><?php if ($x['total']): ?><a class="rl-celda rl-celda--tot rl-s--<?= e($s) ?>" href="<?= e(rlUrl(['v' => 'release', 'r' => $cod])) ?>"><b class="mc-num"><?= (int)$x['pct'] ?>%</b><?= rlBarra((int)$x['pct'], (int)$x['esperado'], $s) ?><small><?= (int)$x['cerrados'] ?>/<?= (int)$x['total'] ?></small></a><?php else: ?><span class="rl-celda rl-celda--vacia">—</span><?php endif; ?></td>
            <?php endforeach; ?>
            <td class="rl-matriz__tot"><span class="rl-celda rl-celda--tot rl-s--<?= e($saludT) ?>"><b class="mc-num"><?= (int)$T['pct'] ?>%</b><?= rlBarra((int)$T['pct'], (int)$T['esperado'], $saludT) ?><small><?= (int)$T['cerrados'] ?>/<?= (int)$T['total'] ?></small></span></td>
            <td class="rl-matriz__dl"></td>
          </tr></tfoot>
        </table>
      </div>
      <ul class="rl-leyenda rl-leyenda--pie">
        <?php foreach (['al_dia' => 'Al día', 'riesgo' => 'En riesgo', 'atrasado' => 'Atrasado', 'futuro' => 'Aún no inicia', 'cerrado' => 'Terminado'] as $k => $lbl): ?>
        <li><i class="rl-ley rl-ley--s rl-s--<?= $k ?>"></i><?= e($lbl) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>

<?php
    // ---------- Próximas revisiones: esta semana y la que viene (van a la columna de la derecha, abajo) ----------
    ob_start();
    $lunes = date('Y-m-d', strtotime($hoy . ' -' . ((int)date('N', strtotime($hoy)) - 1) . ' days'));
    $proximas = Releases::revisiones(['desde' => $lunes, 'hasta' => date('Y-m-d', strtotime($lunes . ' +13 days'))]);
    $porSemana = [];
    foreach ($proximas as $r) $porSemana[$r['fecha'] < date('Y-m-d', strtotime($lunes . ' +7 days')) ? 'Esta semana' : 'La próxima semana'][] = $r;
?>
    <section class="mc-card rl-card" data-rl-sec="revisiones">
      <header class="rl-card__cab">
        <div><h2 class="rl-card__tit"><?= rlIco('agenda', 20) ?> Próximas revisiones</h2>
          <p class="rl-card__sub">Esta semana y la que viene.</p></div>
        <a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?= e(rlUrl(['v' => 'revisiones'])) ?>"><span class="mc-btn__label">Agenda</span><?= rlIco('chevron-der', 16) ?></a>
      </header>
      <?php if (!$porSemana): ?>
      <p class="rl-vacio-txt"><?= rlIco('info', 16) ?> No hay revisiones en estas dos semanas.</p>
      <?php endif; ?>
      <?php foreach ($porSemana as $lbl => $lista): ?>
      <h3 class="rl-subtit"><?= e($lbl) ?></h3>
      <ul class="rl-sesiones">
        <?php foreach ($lista as $r): $hecha = ($r['estado'] ?? '') === 'realizada'; $esHoy = $r['fecha'] === $hoy; ?>
        <li class="rl-sesion<?= $hecha ? ' is-hecha' : '' ?><?= $esHoy ? ' is-hoy' : '' ?>" style="--ec:<?= e(rlColorEq($eqs, $r['equipo'])) ?>">
          <span class="rl-sesion__fecha"><b class="mc-num"><?= (int)date('j', strtotime($r['fecha'])) ?></b><small><?= e(mb_substr(RL_DIA[(int)date('w', strtotime($r['fecha']))], 0, 3)) ?></small></span>
          <span class="rl-sesion__txt">
            <b><?= rlEqChip($eqs, $r['equipo']) ?> <span class="rl-sesion__hora"><?= e((string)$r['hora']) ?></span></b>
            <small><?= count($r['filas']) ?> ítem<?= count($r['filas']) === 1 ? '' : 's' ?><?= ($r['titulo'] ?? '') !== '' ? ' · ' . e($r['titulo']) : '' ?></small>
          </span>
          <?php if ($hecha): ?>
          <a class="mc-btn mc-btn--soft mc-btn--t-verde mc-btn--sm" href="<?= e(rlUrl(['v' => 'revision', 'id' => (int)$r['id']])) ?>"><?= rlIco('check', 16) ?><span class="mc-btn__label"><?= $r['cumplimiento']['pct'] !== null ? (int)$r['cumplimiento']['pct'] . ' %' : 'Ver' ?></span></a>
          <?php else: ?>
          <a class="mc-btn mc-btn--<?= $esHoy ? 'primary' : 'secondary' ?> mc-btn--sm" href="<?= e(rlUrl(['v' => 'revision', 'id' => (int)$r['id']])) ?>"><?= rlIco('editar', 16) ?><span class="mc-btn__label"><?= $smDe($r['equipo']) ? 'Registrar' : 'Ver' ?></span></a>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endforeach; ?>
    </section>
<?php $htmlRevisiones = ob_get_clean(); ?>

<?php
    // ---------- Alertas ----------
    $domingo = date('Y-m-d', strtotime($lunes . ' +6 days'));
    $abiertos = array_filter($todos, fn($it) => $it['estado'] !== 'cerrado');
    $grupos = [
        'atrasados' => ['Atrasados o vencidos', 'rojo', 'alerta', array_filter($abiertos, fn($it) => in_array($it['salud'], ['atrasado', 'vencido', 'bloqueado'], true))],
        'riesgo'    => ['En riesgo', 'amarillo', 'reloj', array_filter($abiertos, fn($it) => $it['salud'] === 'riesgo')],
        'semana'    => ['Vencen esta semana', 'azul', 'agenda', array_filter($abiertos, fn($it) => ($it['fin'] ?? '') >= $hoy && ($it['fin'] ?? '') <= $domingo)],
    ];
    foreach ($grupos as &$g) usort($g[3], fn($a, $b) => [$a['fin'], $a['codigo']] <=> [$b['fin'], $b['codigo']]);
    unset($g);
    $proxHitos = array_slice(array_values(array_filter($hitos, fn($h) => empty($h['hecho']) && $h['fecha'] >= $hoy)), 0, 5);
?>
  <div class="rl-grid rl-grid--2-1">
    <section class="mc-card rl-card" id="rl-alertas" data-rl-sec="alertas">
      <header class="rl-card__cab">
        <div><h2 class="rl-card__tit"><?= rlIco('alerta', 20) ?> Alertas</h2>
          <p class="rl-card__sub">Lo que pide atención hoy. Pulsa un ítem para ver su detalle y marcar cómo va.</p></div>
      </header>
      <div class="rl-alertas">
      <?php foreach ($grupos as $k => [$lbl, $tono, $ico, $lista]): $lista = array_values($lista); ?>
        <div class="rl-alerta rl-t--<?= e($tono) ?>">
          <h3 class="rl-alerta__tit"><span class="mc-tile mc-tile--<?= $tono === 'amarillo' ? 'naranja' : $tono ?> mc-tile--sm"><?= rlIco($ico, 18) ?></span><?= e($lbl) ?><span class="rl-n"><?= count($lista) ?></span></h3>
          <?php if (!$lista): ?><p class="rl-vacio-txt"><?= rlIco('exito', 16) ?> Nada por aquí.</p><?php endif; ?>
          <div class="rl-filas">
            <?php foreach (array_slice($lista, 0, 5) as $it) echo rlFilaItem($it, $eqs, $rels, $it['estado'] === 'bloqueado' ? 'Bloqueado · fin ' . rlF($it['fin']) : ''); ?>
          </div>
          <?php if (count($lista) > 5): ?>
          <details class="rl-mas"><summary>Ver los otros <?= count($lista) - 5 ?></summary>
            <div class="rl-filas"><?php foreach (array_slice($lista, 5) as $it) echo rlFilaItem($it, $eqs, $rels); ?></div>
          </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </section>

    <div class="rl-col">
    <?= $htmlRevisiones ?>
    <section class="mc-card rl-card" data-rl-sec="hitos">
      <header class="rl-card__cab">
        <div><h2 class="rl-card__tit"><?= rlIco('bandera', 20) ?> Próximos hitos</h2></div>
        <a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?= e(rlUrl(['v' => 'hitos'])) ?>"><span class="mc-btn__label">Todos</span><?= rlIco('chevron-der', 16) ?></a>
      </header>
      <ol class="rl-hitos-mini">
        <?php foreach ($proxHitos as $h): $d = (int)floor((strtotime($h['fecha']) - strtotime($hoy)) / 86400); ?>
        <li style="--hc:<?= e(rlColorRel($rels, (string)($h['release'] ?? ''))) ?>">
          <span class="rl-rombo rl-rombo--mini" aria-hidden="true"></span>
          <div><b><?= e($h['titulo']) ?></b><small><?= e(rlFL($h['fecha'], false)) ?> · <?= $d === 0 ? 'hoy' : ($d === 1 ? 'mañana' : 'en ' . $d . ' días') ?><?= ($h['release'] ?? '') !== '' ? ' · ' . e($h['release']) : '' ?></small></div>
        </li>
        <?php endforeach; ?>
        <?php if (!$proxHitos): ?><li><div><small>No quedan hitos pendientes.</small></div></li><?php endif; ?>
      </ol>
    </section>
    </div>
  </div>

<?php elseif ($v === 'release'):
/* =========================================================================
   RELEASE (lib/releases_ui.php · rlVistaRelease)
   ========================================================================= */
    $rcV = (string)($_GET['r'] ?? $relActual);
    if (!isset($rels[$rcV])) $rcV = $relActual;
    $fEqV = (string)($_GET['e'] ?? '');
    rlVistaRelease([
        'rels' => $rels, 'eqs' => $eqs, 'todos' => $todos, 'miembros' => $miembros, 'hoy' => $hoy, 'rc' => $rcV,
        'equipo' => isset($eqs[$fEqV]) ? $fEqV : '',
        'estado' => isset(Releases::ESTADOS[$_GET['estado'] ?? '']) ? (string)$_GET['estado'] : '',
        'salud' => isset(Releases::SALUD[$_GET['salud'] ?? '']) ? (string)$_GET['salud'] : '',
        'q' => trim((string)($_GET['q'] ?? '')),
        'url' => fn(array $p) => rlUrl(['v' => 'release'] + $p),
        'buscar' => ['releases.php', fn(array $b) => ['v' => 'release', 'r' => $b['r'], 'e' => $b['e'], 'estado' => $b['estado'], 'salud' => $b['salud']], 'q'],
        'clicable' => $veTodo,
    ]);
?>

<?php elseif ($v === 'equipo'):
/* =========================================================================
   EQUIPO
   ========================================================================= */
    $en = (string)($_GET['e'] ?? $eqDefault);
    if (!isset($eqs[$en])) $en = $eqDefault;
    $E = $eqs[$en];
    $delEq = array_values(array_filter($todos, fn($it) => $it['equipo'] === $en));
    $resEq = Releases::resumen($delEq);
    $salE = rlSaludGrupo($resEq, $hoy);
    $sm = $miembros[(int)($E['sm_id'] ?? 0)] ?? null;
    $po = $miembros[(int)($E['po_id'] ?? 0)] ?? null;
    $pids = Releases::proyectosEquipo($en);
    $tareasEq = Releases::tareasEquipo($en);
    $vinc = Releases::vinculos();
    $mapa = $tareas();
    $sinItem = array_values(array_filter($tareasEq, fn($t) => empty($vinc[(int)$t['id']]) && !in_array($t['estado'] ?? '', $finales, true)));
    $abiertosEq = array_values(array_filter($delEq, fn($it) => $it['estado'] !== 'cerrado'));
    $porRel = [];
    foreach ($delEq as $it) $porRel[$it['release']][] = $it;
    $analisis = Releases::analisis($en);
    $puedeIA = $smDe($en);
    $persona = function (?array $m, string $nombre, string $rol) {
        $txt = $m ? $m['nombre'] : ($nombre !== '' ? $nombre : 'Sin asignar');
        $ini = implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($txt)) ?: [], 0, 2)));
        return '<div class="rl-persona">' . ($m ? UI::avatar($m, 44) : '<span class="avatar rl-avatar-ext" style="--sz:44px"><span>' . e(mb_strtoupper($ini ?: '?')) . '</span></span>')
            . '<span><small>' . e($rol) . '</small><b>' . e($txt) . '</b>' . (!$m && $nombre !== '' ? '<em>No está en el panel</em>' : '') . '</span></div>';
    };
    // Lo que el JS necesita para pintar el análisis de IA y vincular
    $jsItems = [];
    foreach ($delEq as $it) $jsItems[$it['codigo']] = ['id' => (int)$it['id'], 'titulo' => $it['titulo'], 'inicio' => $it['inicio'], 'fin' => $it['fin'], 'tareas' => $it['tareas'],
        'tareas_ia' => $it['tareas_ia'] ?? [], 'quitadas' => array_map('intval', (array)($it['tareas_quitadas'] ?? [])), 'release' => $it['release'], 'avance' => (int)$it['avance']];
    $jsTareas = [];
    foreach ($tareasEq as $t) $jsTareas[(int)$t['id']] = ['titulo' => $t['titulo'], 'estado' => $estadosTarea[$t['estado'] ?? ''][0] ?? '', 'limite' => $t['fecha_limite'] ?? '', 'proyecto' => $proyMapa[(int)$t['proyecto_id']]['nombre'] ?? ''];
?>
  <nav class="rl-relnav rl-relnav--eq" aria-label="Elegir el equipo">
    <?php foreach ($eqs as $nom => $e): $rq = Releases::resumen(array_filter($todos, fn($it) => $it['equipo'] === $nom)); if (!$rq['total'] && $nom !== $en) continue; ?>
    <a class="rl-relpill<?= $nom === $en ? ' is-active' : '' ?>" href="<?= e(rlUrl(['v' => 'equipo', 'e' => $nom])) ?>" style="--rc:<?= e(rlColorEq($eqs, $nom)) ?>;--p:<?= (int)$rq['pct'] ?>%"<?= $nom === $en ? ' aria-current="page"' : '' ?>>
      <b><?= e($nom) ?></b><small class="mc-num"><?= (int)$rq['pct'] ?> %</small><i aria-hidden="true"></i>
    </a>
    <?php endforeach; ?>
  </nav>

  <section class="mc-card rl-hero rl-hero--eq" style="--rc:<?= e(rlColorEq($eqs, $en)) ?>">
    <div class="rl-hero__main">
      <div class="rl-hero__fila">
        <span class="rl-eq-tile" aria-hidden="true"><?= e(mb_substr($en, 0, 1)) ?></span>
        <div>
          <h2 class="rl-hero__tit font-display">Equipo <?= e($en) ?></h2>
          <?php if (($E['dominio'] ?? '') !== ''): ?><p class="rl-hero__desc"><?= e($E['dominio']) ?></p><?php endif; ?>
        </div>
      </div>
      <div class="rl-personas">
        <?= $persona($sm, (string)($E['sm_nombre'] ?? ''), 'Scrum Master') ?>
        <?= $persona($po, (string)($E['po_nombre'] ?? ''), 'Product Owner') ?>
        <?php if (($E['dia_revision'] ?? '') !== '' || ($E['hora_revision'] ?? '') !== ''): ?>
        <div class="rl-persona"><span class="mc-tile mc-tile--sm mc-tile--cian"><?= rlIco('agenda', 20) ?></span><span><small>Revisión semanal</small><b><?= e(ucfirst((string)($E['dia_revision'] ?? ''))) ?> <?= e((string)($E['hora_revision'] ?? '')) ?></b></span></div>
        <?php endif; ?>
      </div>
      <div class="rl-hero__proys">
        <span class="rl-filtros__rot">Proyectos del panel</span>
        <?php foreach ($pids as $pid): if (!isset($proyMapa[$pid])) continue; $p = $proyMapa[$pid]; ?>
        <a class="dep-card__proy" href="proyecto.php?id=<?= (int)$pid ?>" style="--pc:<?= e(ProyectoRepo::colorBase($p)) ?>"><?= UI::icono($p['icono'] ?? 'FolderOpen') ?><span><?= e($p['nombre']) ?></span></a>
        <?php endforeach; ?>
        <?php if (!$pids): ?><span class="rl-vacio-txt"><?= rlIco('info', 15) ?> Sin proyectos vinculados<?= $editor ? ': elígelos en <a href="' . e(rlUrl(['v' => 'ajustes', 't' => 'equipos'])) . '#eq-' . (int)$E['id'] . '">Ajustes</a>.' : '.' ?></span><?php endif; ?>
        <?php if ($editor && $pids): ?><a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?= e(rlUrl(['v' => 'ajustes', 't' => 'equipos'])) ?>#eq-<?= (int)$E['id'] ?>"><?= rlIco('editar', 15) ?><span class="mc-btn__label">Editar equipo</span></a><?php endif; ?>
      </div>
    </div>
    <div class="rl-hero__lado">
      <?= rlAnillo((int)$resEq['pct'], (int)$resEq['esperado'], $salE === 'vacio' ? 'futuro' : $salE, 112) ?>
      <p class="rl-hero__esp">esperado hoy <b class="mc-num"><?= (int)$resEq['esperado'] ?> %</b></p>
      <dl class="rl-hero__nums">
        <div><dt>Ítems</dt><dd class="mc-num"><?= (int)$resEq['total'] ?></dd></div>
        <div><dt>Terminados</dt><dd class="mc-num"><?= (int)$resEq['cerrados'] ?></dd></div>
        <div class="is-mal"><dt>Atrasados</dt><dd class="mc-num"><?= (int)$resEq['atrasados'] ?></dd></div>
        <div class="is-riesgo"><dt>En riesgo</dt><dd class="mc-num"><?= (int)$resEq['riesgo'] ?></dd></div>
      </dl>
    </div>
  </section>

  <section class="rl-anillos" aria-label="Avance por release">
    <?php foreach ($rels as $cod => $r): if (empty($porRel[$cod])) continue; $rq = Releases::resumen($porRel[$cod]); $s = rlSaludGrupo($rq, $hoy); ?>
    <a class="rl-anillo-card" href="<?= e(rlUrl(['v' => 'release', 'r' => $cod, 'e' => $en])) ?>" style="--rc:<?= e($r['color']) ?>">
      <?= rlAnillo((int)$rq['pct'], (int)$rq['esperado'], $s === 'vacio' ? 'futuro' : $s, 64) ?>
      <span><?= rlCode($cod, $r['color']) ?><b><?= (int)$rq['cerrados'] ?> de <?= (int)$rq['total'] ?> terminados</b><small><?= $s === 'futuro' ? 'Inicia el ' . e(rlF($rq['inicio'], true)) : 'esperado ' . (int)$rq['esperado'] . ' %' ?></small></span>
    </a>
    <?php endforeach; ?>
  </section>

  <?php
    // Reuniones diarias: lo medido desde la fecha de Ajustes (Zoom o lo que registró el SM)
    $dl = Releases::resumenDailies($en);
    $puedeDl = $smDe($en);
    $diasDl = array_slice($dl['dias'], -20, null, true);
    $reuDl = $dl['reuniones'];
    $hoyDl = Releases::dailyDelDia($reuDl, $hoy);
    $candDl = $puedeDl ? Releases::dailyCandidatas($en) : [];
    $nomDl = fn(int $id) => (string)($miembros[$id]['nombre'] ?? 'Alguien');
    $pasadosDl = Releases::puedeEditar();   // el SM registra solo la de hoy; un día pasado, un editor
    $jsDl = ['equipo' => $en, 'desde' => $dl['desde'], 'hoy' => $hoy, 'pasados' => $pasadosDl, 'dias' => [], 'esperados' => array_map(fn($id) => ['id' => $id, 'nombre' => $nomDl($id)], $dl['esperados'])];
    foreach ($dl['dias'] as $f => $x) $jsDl['dias'][$f] = ['estado' => $x['estado'], 'fuente' => (string)($x['fuente'] ?? ''), 'presentes' => array_map('intval', (array)($x['presentes'] ?? [])),
        'nota' => (string)($x['nota'] ?? ''), 'modalidad' => (string)($x['modalidad'] ?? ''), 'zoom' => !empty($x['minutos']) || ($x['fuente'] ?? '') === 'zoom'];
  ?>
  <?php if ($dl['aplica']): ?>
  <section class="mc-card rl-card rl-dailies" id="dailies" data-rl-dailies>
    <header class="rl-card__cab">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('video', 20) ?></span>
      <div><h2 class="rl-card__tit">Reuniones diarias</h2>
        <p class="rl-card__sub">Desde el <?= e(rlFL($dl['desde'], false)) ?>: cada día hábil, la daily registrada en el panel y quién se conectó (Zoom lo dice solo). Cuenta como cumplida con el <?= (int)$conf['dailies']['umbral'] ?> % del equipo conectado.</p></div>
      <?php if ($puedeDl): ?>
      <div class="rl-card__acc">
        <?php if ($reuDl && Zoom::listo()): ?><button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rl-daily-zoom="<?= e($en) ?>"><?= rlIco('reintentar', 16) ?><span class="mc-btn__label">Traer de Zoom</span></button><?php endif; ?>
        <button type="button" class="mc-btn mc-btn--secondary mc-btn--sm" data-rl-daily-reg="<?= e(min($hoy, max($dl['desde'], $hoy))) ?>"<?= $dl['desde'] > $hoy ? ' disabled' : '' ?>><?= rlIco('check', 16) ?><span class="mc-btn__label">Registrar daily</span></button>
      </div>
      <?php endif; ?>
    </header>

    <div class="rl-dailies__reu">
      <?php if ($reuDl): $r0 = $hoyDl ?? $reuDl[0]; ?>
      <span class="rl-dailies__reu-ico"><?= rlIco('agenda', 16) ?></span>
      <span><b><?= e((string)$r0['topic']) ?></b> · <?= e(!empty($r0['recurrente']) ? Reuniones::resumen($r0) : rlFL(substr((string)$r0['inicio'], 0, 10), false) . ' ' . substr((string)$r0['inicio'], 11, 5)) ?>
        · <?= e(Reuniones::etiquetaPlataforma((string)($r0['plataforma'] ?? 'zoom'))) ?><?= count($reuDl) > 1 ? ' <small>(y ' . (count($reuDl) - 1) . ' más)</small>' : '' ?></span>
      <?php else: ?>
      <span class="rl-dailies__reu-ico is-falta"><?= rlIco('alerta', 16) ?></span>
      <span><b>El equipo no tiene su daily registrada en el panel.</b> Créala en <?= $pids && isset($proyMapa[$pids[0]]) ? '<a href="proyecto.php?id=' . (int)$pids[0] . '#vista-reuniones">' . e($proyMapa[$pids[0]]['nombre']) . ' → Reuniones</a>' : 'el proyecto del equipo → Reuniones' ?>, repetida de lunes a viernes y con «Daily» en el nombre<?= $candDl ? ', o elige cuál es' : '' ?>. Mientras tanto, cada día cuenta como «Sin registrar».</span>
      <?php endif; ?>
      <?php if ($candDl): ?>
      <label class="rl-dailies__elegir"><span class="sr-only">Cuál es la daily del equipo</span>
        <?= UI::selectDS('reunion_id', ['0' => 'La que se llame «Daily…»'] + array_column(array_map(fn($c) => ['id' => (string)$c['id'], 't' => $c['texto']], $candDl), 't', 'id'), (string)(int)($E['daily_reunion'] ?? 0), ['aria' => 'Cuál es la daily del equipo']) ?>
      </label>
      <?php endif; ?>
    </div>

    <?php if ($dl['desde'] > $hoy): ?>
    <p class="rl-vacio-txt"><?= rlIco('reloj', 16) ?> Se empieza a medir el <?= e(rlFL($dl['desde'])) ?>.</p>
    <?php else: ?>
    <div class="rl-dailies__cuerpo">
      <dl class="rl-dailies__nums">
        <div class="rl-dnum"><dt>Cumplimiento</dt><dd class="mc-num"><?= $dl['pct'] === null ? '—' : (int)$dl['pct'] . '<small>%</small>' ?></dd>
          <span class="rl-dbarra" aria-hidden="true"><i style="--p:<?= (int)($dl['pct'] ?? 0) ?>%"></i></span>
          <small><?= $dl['cuentan'] ? (int)$dl['cuenta']['cumplida'] . ' de ' . (int)$dl['cuentan'] . ' día(s) hábiles' : 'Aún no hay días medidos' ?></small></div>
        <div class="rl-dnum"><dt>Asistencia</dt><dd class="mc-num"><?= $dl['asistencia'] === null ? '—' : (int)$dl['asistencia'] . '<small>%</small>' ?></dd>
          <span class="rl-dbarra" aria-hidden="true"><i style="--p:<?= (int)($dl['asistencia'] ?? 0) ?>%"></i></span>
          <small>Promedio del equipo conectado</small></div>
        <div class="rl-dnum<?= ($dl['cuenta']['sin_registrar'] + $dl['cuenta']['no_hecha']) ? ' is-mal' : '' ?>"><dt>Sin daily</dt><dd class="mc-num"><?= (int)($dl['cuenta']['sin_registrar'] + $dl['cuenta']['no_hecha']) ?></dd>
          <small><?= (int)$dl['cuenta']['sin_registrar'] ?> sin registrar · <?= (int)$dl['cuenta']['no_hecha'] ?> sin conectarse</small></div>
      </dl>

      <div class="rl-dailies__dias">
        <p class="rl-dlg__rot">Día por día<?= count($dl['dias']) > count($diasDl) ? ' <small>(los últimos ' . count($diasDl) . ')</small>' : '' ?></p>
        <ol class="rl-ddias">
          <?php foreach ($diasDl as $f => $x): [$lbl] = Releases::DAILY[$x['estado']] ?? Releases::DAILY['pendiente'];
            $det = $lbl . (($x['asistencia'] ?? null) !== null ? ' · ' . (int)$x['asistencia'] . ' % conectado' : '') . (($x['fuente'] ?? '') === 'manual' ? ' · registrada por el SM' : (($x['fuente'] ?? '') === 'zoom' ? ' · según Zoom' : ''))
              . (($x['nota'] ?? '') !== '' ? ' · ' . $x['nota'] : '') . (($x['msg'] ?? '') !== '' ? ' · ' . $x['msg'] : '');
            $tag = $puedeDl ? 'button type="button" data-rl-daily-reg="' . e($f) . '"' : 'span tabindex="0"'; ?>
          <li><<?= $tag ?> class="rl-ddia rl-d--<?= e($x['estado']) ?><?= $f === $hoy ? ' is-hoy' : '' ?>" data-ds-tip="<?= e(rlFL($f, true) . ': ' . $det) ?>" aria-label="<?= e(rlFL($f, true) . ': ' . $det) ?>">
            <small><?= e(mb_substr(RL_DIA[(int)date('w', strtotime($f))], 0, 3)) ?></small><b class="mc-num"><?= (int)date('j', strtotime($f)) ?></b>
            <i class="mc-num"><?= ($x['asistencia'] ?? null) !== null ? (int)$x['asistencia'] . '%' : match ($x['estado']) { 'no_aplica' => 'N/A', 'pendiente' => '·', 'no_hecha', 'sin_registrar' => '✕', default => '—' } ?></i>
          </<?= $puedeDl ? 'button' : 'span' ?>></li>
          <?php endforeach; ?>
          <?php if (!$diasDl): ?><li class="rl-vacio-txt">Todavía no hay días hábiles.</li><?php endif; ?>
        </ol>
        <ul class="rl-leyenda rl-leyenda--pie">
          <?php foreach (['cumplida', 'incompleta', 'no_hecha', 'sin_registrar', 'sin_zoom', 'no_aplica'] as $k): ?>
          <li><i class="rl-ley rl-d--<?= $k ?>"></i><?= e(Releases::DAILY[$k][0]) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <?php $conDatos = array_filter($dl['personas'], fn($p) => $p['de'] > 0); ?>
      <div class="rl-dailies__gente">
        <p class="rl-dlg__rot">Quién se conecta</p>
        <?php if ($conDatos): uasort($conDatos, fn($a, $b) => [$a['va'] / $a['de'], 0] <=> [$b['va'] / $b['de'], 0]); ?>
        <ul class="rl-dgente">
          <?php foreach ($conDatos as $mid => $p): $m = $miembros[$mid] ?? null; $pc = (int)round($p['va'] * 100 / $p['de']); ?>
          <li><?= $m ? UI::avatar($m, 28) : '' ?><span class="rl-dgente__n"><?= e($nomDl((int)$mid)) ?></span>
            <span class="rl-dbarra" aria-hidden="true"><i style="--p:<?= $pc ?>%"></i></span><b class="mc-num"><?= (int)$p['va'] ?>/<?= (int)$p['de'] ?></b></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="rl-vacio-txt"><?= rlIco('info', 16) ?> <?= $dl['esperados'] ? 'Aquí sale cada persona cuando haya dailies medidas.' : 'Elige los miembros del equipo en Ajustes → Equipos para medir a cada persona.' ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <script type="application/json" data-rl-dailies-datos><?= json_encode($jsDl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </section>
  <?php endif; ?>

  <?php if ($puedeDl && $dl['aplica'] && $dl['desde'] <= $hoy):
    $opsDl = [];
    foreach ($dl['esperados'] as $mid) $opsDl[$mid] = [$nomDl($mid), (int)$mid === (int)($E['sm_id'] ?? 0) ? 'Scrum Master' : (string)($miembros[$mid]['rol'] ?? '')]; ?>
  <dialog id="rl-dlg-daily" class="dlg-meca rl-dlg">
    <form class="dlg-form" method="post" action="actions.php" data-rl-form="rel_daily_registrar" data-rl-tras="recargar">
      <input type="hidden" name="accion" value="rel_daily_registrar"><input type="hidden" name="equipo" value="<?= e($en) ?>">
      <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'equipo', 'e' => $en])) ?>">
      <header><h3 class="font-display"><i class="fa-solid fa-mug-hot"></i> Registrar la daily<small>Equipo <?= e($en) ?></small></h3><button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
      <?= rlCampo('Día', '<input class="input-meca" type="date" name="fecha" min="' . e($pasadosDl ? $dl['desde'] : $hoy) . '" max="' . e($hoy) . '" value="' . e($hoy) . '" required data-rl-dl-fecha>') ?>
      <p class="rl-dlg__nota" data-rl-dl-zoom hidden><?= rlIco('info', 15) ?> <span>Zoom ya midió este día. Lo que guardes aquí manda sobre lo de Zoom.</span></p>
      <div class="mc-field"><span class="mc-field__label">¿Cómo fue?</span>
        <div class="rl-seg" role="radiogroup" aria-label="Cómo fue la daily">
          <label class="rl-seg__op rl-t--azul"><input type="radio" name="estado" value="hecha" checked><span><?= rlIco('check', 15) ?>Se hizo</span></label>
          <label class="rl-seg__op rl-t--rojo"><input type="radio" name="estado" value="no_hecha"><span><?= rlIco('cerrar', 15) ?>No se hizo</span></label>
          <label class="rl-seg__op rl-t--gris"><input type="radio" name="estado" value="no_aplica"><span><?= rlIco('pausa', 15) ?>No aplica</span></label>
          <label class="rl-seg__op rl-t--gris" data-rl-dl-auto hidden><input type="radio" name="estado" value="auto"><span><?= rlIco('reintentar', 15) ?>Que lo diga Zoom</span></label>
        </div>
      </div>
      <div class="rl-dl-hecha" data-rl-dl-hecha>
        <div class="rl-seg rl-seg--modo" role="radiogroup" aria-label="Modalidad">
          <label class="rl-seg__op rl-t--petroleo"><input type="radio" name="modalidad" value="presencial" checked><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Presencial</span></label>
          <label class="rl-seg__op rl-t--azul"><input type="radio" name="modalidad" value="virtual"><span><?= rlIco('video', 15) ?>Virtual</span></label>
        </div>
        <?= rlCampo('Quiénes estuvieron', rlMulti('presentes[]', $opsDl, $dl['esperados'], 'Buscar persona…')) ?>
      </div>
      <?= rlCampo('Nota', '<textarea class="input-meca" name="nota" rows="2" maxlength="600" placeholder="Bloqueos, quién avisó que no venía… Si no aplica, el motivo."></textarea>') ?>
      <footer><button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button><button class="btn-primary btn-meca" type="submit"><i class="fa-solid fa-check"></i> Guardar</button></footer>
    </form>
  </dialog>
  <?php endif; ?>

  <div class="rl-ia-slot" data-rl-ia-slot hidden></div>

  <section class="mc-card rl-card rl-iaeq" data-rl-iaeq data-equipo="<?= e($en) ?>">
    <header class="rl-card__cab">
      <img class="ia-masc ia-masc--mini nexo" src="<?= e(asset('iconos/nexo/idea.webp')) ?>" alt="" width="52" height="52">
      <div><h2 class="rl-card__tit">Tareas del equipo e ítems</h2>
        <p class="rl-card__sub">La IA lee las tareas de los proyectos del equipo y las asocia sola a cada ítem; el avance sale de ahí. Avisa de las fechas que no cuadran y propone tareas para lo que falta. Lo que alguien quite a mano no lo vuelve a poner.
          <?php if ($conf['ia_auto'] ?? false): ?><span class="rl-chip-ia rl-chip-ia--txt"><?= rlIco('reloj', 12) ?>Cada día asocia sola las tareas nuevas</span><?php endif; ?></p></div>
      <?php if ($puedeIA): ?>
      <button type="button" class="mc-btn mc-btn--primary" data-rl-ia-equipo="<?= e($en) ?>"><?= rlIco('ia', 18) ?><span class="mc-btn__label">Asociar tareas con IA</span></button>
      <?php endif; ?>
    </header>
    <div class="rl-iaeq__cuerpo" data-rl-analisis>
      <?php if (!$analisis): ?><p class="rl-vacio-txt"><?= rlIco('info', 16) ?> La IA todavía no ha asociado las tareas de este equipo.</p><?php endif; ?>
    </div>
    <script type="application/json" data-rl-analisis-datos><?= json_encode(['analisis' => $analisis ? ['id' => (int)$analisis['id'], 'fecha' => $analisis['fecha'], 'quien' => $miembros[(int)($analisis['quien'] ?? 0)]['nombre'] ?? '', 'resultado' => $analisis['resultado'], 'aplicadas' => $analisis['aplicadas'] ?? []] : null,
        'items' => $jsItems, 'tareas' => $jsTareas, 'puede' => $puedeIA, 'equipo' => $en,
        'proyectos' => $datosItemJs(['id' => 0, 'codigo' => '', 'titulo' => '', 'equipo' => $en, 'inicio' => '', 'fin' => ''])['proyectos'], 'miembrosEq' => $E['miembros']],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </section>

  <div class="rl-grid rl-grid--2-1">
    <section class="mc-card rl-card">
      <header class="rl-card__cab"><div><h2 class="rl-card__tit"><?= rlIco('ruta', 20) ?> Ítems del equipo</h2><p class="rl-card__sub">Por release. Pulsa uno para marcar cómo va.</p></div></header>
      <?php foreach ($rels as $cod => $r): if (empty($porRel[$cod])) continue; $rq = Releases::resumen($porRel[$cod]); $abierto = $rq['cerrados'] < $rq['total']; ?>
      <details class="rl-grupo-rel"<?= $abierto ? ' open' : '' ?> style="--rc:<?= e($r['color']) ?>">
        <summary><?= rlCode($cod, $r['color']) ?><b><?= e($r['nombre'] ?? '') ?></b><span class="mc-num"><?= (int)$rq['cerrados'] ?>/<?= (int)$rq['total'] ?></span><?= rlIco('chevron-abajo', 16) ?></summary>
        <div class="rl-filas"><?php foreach ($porRel[$cod] as $it) {
            $depEq = rlDependencias((string)($it['dependencias'] ?? ''), $porCodigo, $en)['equipos'];
            $chips = implode('', array_map(fn($n) => '<span class="rl-depchip" style="--ec:' . e(rlColorEq($eqs, $n)) . '" title="Depende de algo que entrega ' . e($n) . '">' . rlIco('relacionar', 12) . 'lo entrega ' . e($n) . '</span>', $depEq));
            echo rlFilaItem($it, $eqs, $rels, Releases::ESTADOS[$it['estado']][0] . ' · ' . rlF($it['inicio']) . ' → ' . rlF($it['fin']), $chips);
        } ?></div>
      </details>
      <?php endforeach; ?>
    </section>

    <section class="mc-card rl-card">
      <header class="rl-card__cab"><div><h2 class="rl-card__tit"><?= rlIco('completar', 20) ?> Cobertura de tareas</h2><p class="rl-card__sub">Cuántas tareas del panel tiene cada ítem abierto.</p></div></header>
      <ul class="rl-cobertura">
        <?php foreach ($abiertosEq as $it):
            $ts = array_values(array_filter(array_map(fn($id) => $mapa[(int)$id] ?? null, $it['tareas'])));
            $h = count(array_filter($ts, fn($t) => in_array($t['estado'] ?? '', $finales, true)));
            $n = count($ts);
            $empieza = ($it['inicio'] ?? '') !== '' && $it['inicio'] <= date('Y-m-d', strtotime($hoy . ' +14 days')); ?>
        <li class="<?= !$n && $empieza ? 'is-falta' : '' ?>">
          <a href="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>" data-rl-item="<?= (int)$it['id'] ?>"<?= rlTipItem($it) ?>>
            <?= rlCode($it['codigo'], rlColorRel($rels, $it['release'])) ?>
            <span class="rl-cobertura__txt"><b><?= e($it['titulo']) ?></b>
              <small><?= $n ? $h . ' de ' . $n . ' completada' . ($n === 1 ? '' : 's') . ' · avance ' . (int)$it['avance'] . ' %' : ($empieza ? 'Sin tareas y ya empieza' : 'Sin tareas todavía') ?>
                <?php $nIa = count(array_intersect((array)($it['tareas_ia'] ?? []), $it['tareas'])); if ($nIa): ?><span class="rl-chip-ia"><?= rlIco('ia', 12) ?><?= $nIa ?> por la IA</span><?php endif; ?></small></span>
            <span class="rl-cobertura__n<?= !$n ? ' is-cero' : '' ?> mc-num"><?= $n ?></span>
          </a>
          <?php if ($n): ?><span class="rl-cobertura__barra" style="--p:<?= round($h / $n * 100) ?>%"></span><?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$abiertosEq): ?><li><p class="rl-vacio-txt"><?= rlIco('exito', 16) ?> Todos los ítems del equipo están terminados.</p></li><?php endif; ?>
      </ul>
      <h3 class="rl-subtit">Tareas abiertas sin ítem <span class="rl-n"><?= count($sinItem) ?></span></h3>
      <?php if (!$sinItem): ?>
      <p class="rl-vacio-txt"><?= rlIco('exito', 16) ?> <?= $tareasEq ? 'Cada tarea abierta del equipo está en algún ítem.' : 'No hay tareas en los proyectos del equipo.' ?></p>
      <?php else: ?>
      <ul class="rl-sinitem">
        <?php foreach (array_slice($sinItem, 0, 12) as $t): ?>
        <li>
          <span class="rl-sinitem__txt"><b>#<?= (int)$t['id'] ?> <?= e($t['titulo']) ?></b><small><?= e($estadosTarea[$t['estado'] ?? ''][0] ?? '') ?><?= ($t['fecha_limite'] ?? '') !== '' ? ' · límite ' . e(rlF($t['fecha_limite'])) : '' ?></small></span>
          <?php if ($smDe($en) && $abiertosEq): ?>
          <form class="rl-sinitem__form" data-rl-vincular-una="<?= (int)$t['id'] ?>">
            <?= UI::selectDS('item', array_column(array_map(fn($it) => ['k' => (string)$it['id'], 'v' => $it['codigo'] . ' · ' . mb_strimwidth($it['titulo'], 0, 46, '…')], $abiertosEq), 'v', 'k'), '', ['vacio' => 'Vincular a…', 'aria' => 'Elegir el ítem para la tarea #' . (int)$t['id']]) ?>
            <button type="submit" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--primary" aria-label="Vincular la tarea #<?= (int)$t['id'] ?>" data-ds-tip="Vincular"><?= rlIco('relacionar', 16) ?></button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($sinItem) > 12): ?><p class="rl-vacio-txt">Y <?= count($sinItem) - 12 ?> más.</p><?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

<?php elseif ($v === 'item'):
/* =========================================================================
   ÍTEM (página)
   ========================================================================= */
    $it = $porId[(int)($_GET['id'] ?? 0)] ?? null;
    if (!$it):
        echo UI::vacioDS('nexo:pensando', 'Ese ítem no existe', 'Puede que lo hayan borrado o que el enlace esté mal.', '<a class="mc-btn mc-btn--secondary" href="releases.php"><span class="mc-btn__label">Volver al tablero</span></a>');
    else: ?>
  <nav class="mc-crumbs rl-migas" aria-label="Estás en">
    <a class="mc-crumbs__link" href="<?= e(rlUrl(['v' => 'release', 'r' => $it['release']])) ?>"><?= e($it['release']) ?></a><span class="mc-crumbs__sep">/</span>
    <a class="mc-crumbs__link" href="<?= e(rlUrl(['v' => 'release', 'r' => $it['release'], 'e' => $it['equipo']])) ?>"><?= e($it['equipo']) ?></a><span class="mc-crumbs__sep">/</span>
    <span class="mc-crumbs__cur"><?= e($it['codigo']) ?></span>
  </nav>
  <section class="mc-card rl-card"><?= $pintarItem($it, false) ?></section>
<?php endif; ?>

<?php elseif ($v === 'revisiones'):
/* =========================================================================
   REVISIONES (agenda semanal)
   ========================================================================= */
    $s = Releases::fecha($_GET['s'] ?? '') ?: $hoy;
    $lunes = date('Y-m-d', strtotime($s . ' -' . ((int)date('N', strtotime($s)) - 1) . ' days'));
    $viernes = date('Y-m-d', strtotime($lunes . ' +4 days'));
    $domingo = date('Y-m-d', strtotime($lunes . ' +6 days'));
    $fEq = (string)($_GET['e'] ?? '');
    $todasRev = Releases::revisiones();
    $eqsRev = array_keys($eqs);
    foreach ($todasRev as $r) if (!in_array($r['equipo'], $eqsRev, true)) $eqsRev[] = $r['equipo'];
    if ($fEq !== '' && !in_array($fEq, $eqsRev, true)) $fEq = '';
    $semana = array_values(array_filter($todasRev, fn($r) => $r['fecha'] >= $lunes && $r['fecha'] <= $domingo && ($fEq === '' || $r['equipo'] === $fEq)));
    $porDia = [];
    foreach ($semana as $r) $porDia[min($r['fecha'], $viernes)][] = $r;
    $sig = null;
    foreach ($todasRev as $r) if ($r['fecha'] > $domingo && ($fEq === '' || $r['equipo'] === $fEq)) { $sig = $r['fecha']; break; }
    $lunesHoy = date('Y-m-d', strtotime($hoy . ' -' . ((int)date('N', strtotime($hoy)) - 1) . ' days'));
    $tituloSemana = (int)date('j', strtotime($lunes)) . ($lunes !== '' && date('n', strtotime($lunes)) !== date('n', strtotime($viernes)) ? ' de ' . RL_MESL[(int)date('n', strtotime($lunes)) - 1] : '')
        . ' al ' . (int)date('j', strtotime($viernes)) . ' de ' . RL_MESL[(int)date('n', strtotime($viernes)) - 1] . ' de ' . date('Y', strtotime($viernes));
    // Cumplimiento por equipo (como la hoja «Cumplimiento»): todas las sesiones del calendario
    $cumple = [];
    foreach ($eqsRev as $nom) {
        $rs = array_filter($todasRev, fn($r) => $r['equipo'] === $nom);
        if ($rs) $cumple[$nom] = Releases::cumplimiento($rs) + ['sesiones' => count($rs), 'hechas' => count(array_filter($rs, fn($r) => ($r['estado'] ?? '') === 'realizada'))];
    }
    $cumpleT = Releases::cumplimiento($todasRev);
?>
  <section class="rl-semana-nav">
    <div class="rl-semana-nav__ir">
      <a class="mc-iconbtn mc-iconbtn--outline" href="<?= e(rlUrl(['v' => 'revisiones', 's' => date('Y-m-d', strtotime($lunes . ' -7 days')), 'e' => $fEq])) ?>" aria-label="Semana anterior" data-ds-tip="Semana anterior"><?= rlIco('chevron-izq', 18) ?></a>
      <div class="rl-semana-nav__tit"><?php $nSem = (int)round((strtotime($lunes) - strtotime($lunesHoy)) / 604800); ?><small><?= match (true) { $nSem === 0 => 'Esta semana', $nSem === 1 => 'La próxima semana', $nSem === -1 => 'La semana pasada', $nSem > 1 => 'Dentro de ' . $nSem . ' semanas', default => 'Hace ' . abs($nSem) . ' semanas' } ?></small><b class="font-display">Del <?= e($tituloSemana) ?></b></div>
      <a class="mc-iconbtn mc-iconbtn--outline" href="<?= e(rlUrl(['v' => 'revisiones', 's' => date('Y-m-d', strtotime($lunes . ' +7 days')), 'e' => $fEq])) ?>" aria-label="Semana siguiente" data-ds-tip="Semana siguiente"><?= rlIco('chevron-der', 18) ?></a>
      <?php if ($lunes !== $lunesHoy): ?><a class="mc-btn mc-btn--secondary mc-btn--sm" href="<?= e(rlUrl(['v' => 'revisiones', 'e' => $fEq])) ?>"><span class="mc-btn__label">Hoy</span></a><?php endif; ?>
    </div>
    <div class="rl-chips">
      <a class="rl-chip<?= $fEq === '' ? ' is-on' : '' ?>" href="<?= e(rlUrl(['v' => 'revisiones', 's' => $lunes !== $lunesHoy ? $lunes : ''])) ?>">Todos</a>
      <?php foreach ($eqsRev as $nom): if (!isset($cumple[$nom])) continue; ?>
      <a class="rl-chip<?= $fEq === $nom ? ' is-on' : '' ?>" style="--ec:<?= e(rlColorEq($eqs, $nom)) ?>" href="<?= e(rlUrl(['v' => 'revisiones', 's' => $lunes !== $lunesHoy ? $lunes : '', 'e' => $fEq === $nom ? '' : $nom])) ?>"><i class="rl-chip__dot"></i><?= e($nom) ?></a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php $hayMovibles = (bool)array_filter($semana, fn($r) => $smDe($r['equipo'])); ?>
  <?php if ($hayMovibles): ?><p class="rl-agenda__ayuda"><?= rlIco('info', 15) ?> Arrastra una revisión a otro día de la semana para moverla, o pulsa <?= rlIco('editar', 14) ?> para cambiarle la fecha, la hora o el foco. Si tiene su reunión de Zoom, se mueve con ella.</p><?php endif; ?>
  <section class="rl-agenda<?= !$semana ? ' is-vacia' : '' ?>" aria-label="Revisiones de la semana"<?= $hayMovibles ? ' data-rl-agenda' : '' ?>>
    <?php for ($i = 0; $i < 5; $i++): $d = date('Y-m-d', strtotime($lunes . " +$i days")); $lista = $porDia[$d] ?? []; ?>
    <div class="rl-dia<?= $d === $hoy ? ' is-hoy' : '' ?><?= $d < $hoy ? ' is-pasado' : '' ?>" data-rl-dia="<?= e($d) ?>">
      <header class="rl-dia__cab"><small><?= e(RL_DIA[(int)date('w', strtotime($d))]) ?></small><b class="mc-num"><?= (int)date('j', strtotime($d)) ?></b><?= $d === $hoy ? '<span class="rl-dia__hoy">Hoy</span>' : '' ?></header>
      <div class="rl-dia__lista">
        <?php foreach ($lista as $r): $hecha = ($r['estado'] ?? '') === 'realizada'; $cancelada = ($r['estado'] ?? '') === 'cancelada'; $cu = $r['cumplimiento']; $mueve = $smDe($r['equipo']); ?>
        <div class="rl-ses<?= $hecha ? ' is-hecha' : '' ?><?= $cancelada ? ' is-cancelada' : '' ?><?= $mueve ? ' is-movible' : '' ?>" style="--ec:<?= e(rlColorEq($eqs, $r['equipo'])) ?>"<?= $mueve ? ' draggable="true" data-rl-ses="' . (int)$r['id'] . '" data-fecha="' . e($r['fecha']) . '"' : '' ?>>
          <span class="rl-ses__top"><b><a class="rl-ses__ir" href="<?= e(rlUrl(['v' => 'revision', 'id' => (int)$r['id']])) ?>" draggable="false"><?= e($r['equipo']) ?></a></b><span class="rl-ses__hora mc-num"><?= e((string)$r['hora']) ?></span></span>
          <?php if (($r['titulo'] ?? '') !== ''): ?><span class="rl-ses__foco"><?= e($r['titulo']) ?></span><?php endif; ?>
          <span class="rl-ses__pie">
            <?= $hecha ? rlChip('Realizada', 'verde') : ($cancelada ? rlChip('Cancelada', 'gris') : rlChip($r['fecha'] < $hoy ? 'Sin registrar' : 'Programada', $r['fecha'] < $hoy ? 'amarillo' : 'gris')) ?>
            <span class="rl-ses__n"><?= rlIco('notas', 14) ?> <?= count($r['filas']) ?></span>
            <?php if (($r['modalidad'] ?? '') === 'virtual'): ?><span class="rl-ses__modo" title="Virtual<?= !empty($r['join_url']) ? ' · con reunión' : '' ?>"><?= rlIco('video', 14) ?></span>
            <?php else: ?><span class="rl-ses__modo" title="Presencial<?= ($r['lugar'] ?? '') !== '' ? ' · ' . e($r['lugar']) : '' ?>"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span><?php endif; ?>
            <?php if ($cu['pct'] !== null): ?><span class="rl-ses__pct mc-num"><?= (int)$cu['pct'] ?> %</span><?php endif; ?>
            <?php if ($mueve): ?>
            <button type="button" class="rl-ses__editar" aria-label="Editar la revisión de <?= e($r['equipo']) ?>" data-ds-tip="Editar"
              data-rl-editar-rev="<?= e(json_encode(['id' => (int)$r['id'], 'equipo' => $r['equipo'], 'fecha' => $r['fecha'], 'hora' => (string)($r['hora'] ?? ''), 'titulo' => (string)($r['titulo'] ?? ''),
                'responsable' => (string)($r['responsable'] ?? ''), 'estado' => (string)($r['estado'] ?? 'programada'), 'reunion' => !empty($r['reunion_id'])], JSON_UNESCAPED_UNICODE)) ?>"><?= rlIco('editar', 15) ?></button>
            <?php endif; ?>
          </span>
          <?php if ($cu['revisados']): ?>
          <span class="rl-pila" aria-hidden="true">
            <?php foreach (['cumplido', 'parcial', 'no_cumplido', 'reprogramado', 'no_aplica', 'pendiente'] as $k): if (!$cu[$k]) continue; ?><i class="rl-r--<?= $k ?>" style="flex:<?= (int)$cu[$k] ?>"></i><?php endforeach; ?>
          </span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$lista): ?><span class="rl-dia__nada">—</span><?php endif; ?>
      </div>
    </div>
    <?php endfor; ?>
    <?php if (!$semana): ?>
    <div class="rl-agenda__vacia">
      <?= UI::nexo('descansando', 'rl-agenda__nexo') ?>
      <p><b>No hay revisiones esta semana.</b><?php if ($sig): ?> La próxima es el <?= e(rlFL($sig)) ?>.<?php endif; ?></p>
      <?php if ($sig): ?><a class="mc-btn mc-btn--secondary mc-btn--sm" href="<?= e(rlUrl(['v' => 'revisiones', 's' => $sig, 'e' => $fEq])) ?>"><span class="mc-btn__label">Ir a esa semana</span><?= rlIco('chevron-der', 16) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="mc-card rl-card">
    <header class="rl-card__cab"><div><h2 class="rl-card__tit"><?= rlIco('reportes', 20) ?> Cumplimiento por equipo</h2>
      <p class="rl-card__sub">Todo el calendario de revisiones, como la hoja «Cumplimiento». El % es cumplidos sobre cumplidos, parciales y no cumplidos.</p></div></header>
    <div class="rl-matriz-scroll">
      <table class="rl-cumple">
        <thead><tr><th scope="col">Equipo</th><th scope="col">Sesiones</th><th scope="col">Programados</th><th scope="col">Revisados</th>
          <th scope="col"><span class="rl-pt rl-r--cumplido"></span>Cumplido</th><th scope="col"><span class="rl-pt rl-r--parcial"></span>Parcial</th>
          <th scope="col"><span class="rl-pt rl-r--no_cumplido"></span>No cumplido</th><th scope="col"><span class="rl-pt rl-r--reprogramado"></span>Reprogramado</th>
          <th scope="col" class="rl-cumple__pct">Cumplimiento</th></tr></thead>
        <tbody>
        <?php foreach ($cumple as $nom => $c): ?>
          <tr>
            <th scope="row"><a href="<?= e(rlUrl(['v' => 'revisiones', 'e' => $nom, 's' => $lunes !== $lunesHoy ? $lunes : ''])) ?>"><?= rlEqChip($eqs, $nom) ?></a></th>
            <td class="mc-num"><?= (int)$c['hechas'] ?>/<?= (int)$c['sesiones'] ?></td><td class="mc-num"><?= (int)$c['programados'] ?></td><td class="mc-num"><?= (int)$c['revisados'] ?></td>
            <td class="mc-num"><?= (int)$c['cumplido'] ?></td><td class="mc-num"><?= (int)$c['parcial'] ?></td><td class="mc-num"><?= (int)$c['no_cumplido'] ?></td><td class="mc-num"><?= (int)$c['reprogramado'] ?></td>
            <td class="rl-cumple__pct"><span class="rl-pila rl-pila--fila"><?php foreach (['cumplido', 'parcial', 'no_cumplido', 'reprogramado', 'no_aplica', 'pendiente'] as $k): if (!$c[$k]) continue; ?><i class="rl-r--<?= $k ?>" style="flex:<?= (int)$c[$k] ?>"></i><?php endforeach; ?></span>
              <b class="mc-num"><?= $c['pct'] === null ? '—' : (int)$c['pct'] . ' %' ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th scope="row">Total</th><td class="mc-num"><?= count(array_filter($todasRev, fn($r) => ($r['estado'] ?? '') === 'realizada')) ?>/<?= count($todasRev) ?></td>
          <td class="mc-num"><?= (int)$cumpleT['programados'] ?></td><td class="mc-num"><?= (int)$cumpleT['revisados'] ?></td><td class="mc-num"><?= (int)$cumpleT['cumplido'] ?></td>
          <td class="mc-num"><?= (int)$cumpleT['parcial'] ?></td><td class="mc-num"><?= (int)$cumpleT['no_cumplido'] ?></td><td class="mc-num"><?= (int)$cumpleT['reprogramado'] ?></td>
          <td class="rl-cumple__pct"><b class="mc-num"><?= $cumpleT['pct'] === null ? '—' : (int)$cumpleT['pct'] . ' %' ?></b></td></tr></tfoot>
      </table>
    </div>
  </section>

<?php elseif ($v === 'revision'):
/* =========================================================================
   REVISIÓN (registrar)
   ========================================================================= */
    $rev = Releases::revision((int)($_GET['id'] ?? 0));
    if (!$rev):
        echo UI::vacioDS('nexo:pensando', 'Esa revisión no existe', 'Puede que la hayan borrado.', '<a class="mc-btn mc-btn--secondary" href="' . e(rlUrl(['v' => 'revisiones'])) . '"><span class="mc-btn__label">Ver la agenda</span></a>');
    else:
        $puedeReg = $smDe($rev['equipo']);
        $hecha = ($rev['estado'] ?? '') === 'realizada';
        $cu = $rev['cumplimiento'];
        $guia = $conf['guia'];
        $enSesion = array_flip(array_map(fn($f) => (string)$f['codigo'], (array)$rev['filas']));
        $paraAgregar = array_filter($todos, fn($it) => ($it['equipo'] === $rev['equipo'] || !isset($eqs[$rev['equipo']])) && !isset($enSesion[$it['codigo']]) && $it['estado'] !== 'cerrado');
        $lunesR = date('Y-m-d', strtotime($rev['fecha'] . ' -' . ((int)date('N', strtotime($rev['fecha'])) - 1) . ' days'));
        $registro = $hecha ? ($miembros[(int)($rev['registrada_por'] ?? 0)]['nombre'] ?? '') : '';
        // Las sesiones importadas no traen modalidad: cuentan como presenciales hasta que la elijan
        $virtual = ($rev['modalidad'] ?? '') === 'virtual';
?>
  <nav class="mc-crumbs rl-migas" aria-label="Estás en">
    <a class="mc-crumbs__link" href="<?= e(rlUrl(['v' => 'revisiones', 's' => $lunesR])) ?>">Revisiones</a><span class="mc-crumbs__sep">/</span>
    <a class="mc-crumbs__link" href="<?= e(rlUrl(['v' => 'revisiones', 's' => $lunesR])) ?>">Semana del <?= e(rlF($lunesR)) ?></a><span class="mc-crumbs__sep">/</span>
    <span class="mc-crumbs__cur"><?= e($rev['equipo']) ?> · <?= e(rlF($rev['fecha'])) ?></span>
  </nav>

  <section class="mc-card rl-hero rl-hero--rev" style="--rc:<?= e(rlColorEq($eqs, $rev['equipo'])) ?>">
    <div class="rl-hero__main">
      <div class="rl-hero__fila">
        <span class="rl-eq-tile" aria-hidden="true"><?= e(mb_substr($rev['equipo'], 0, 1)) ?></span>
        <div>
          <p class="rl-hero__kicker"><?= e(ucfirst(rlFL($rev['fecha'], true, true))) ?> · <?= e((string)$rev['hora']) ?><?= ($rev['sprint'] ?? '') !== '' ? ' · ' . e($rev['sprint']) : '' ?></p>
          <h2 class="rl-hero__tit font-display">Revisión <?= e($rev['equipo']) ?></h2>
        </div>
        <?= $hecha ? rlChip('Realizada', 'verde') : rlChip($rev['fecha'] < $hoy ? 'Sin registrar' : 'Programada', $rev['fecha'] < $hoy ? 'amarillo' : 'gris') ?>
      </div>
      <?php if (($rev['titulo'] ?? '') !== ''): ?><p class="rl-hero__desc"><b>Foco:</b> <?= e($rev['titulo']) ?></p><?php endif; ?>
      <p class="rl-hero__meta">
        <?php if (($rev['responsable'] ?? '') !== ''): ?><span><?= rlIco('perfil', 16) ?> <?= e($rev['responsable']) ?></span><?php endif; ?>
        <span><?= rlIco('notas', 16) ?> <?= count($rev['filas']) ?> ítem(s)</span>
        <?php if ($hecha): ?><span><?= rlIco('check', 16) ?> Registrada<?= $registro !== '' ? ' por ' . e($registro) : '' ?><?= !empty($rev['registrada']) ? ' el ' . e(rlF(substr((string)$rev['registrada'], 0, 10))) : '' ?></span><?php endif; ?>
        <?php if ($virtual): ?><span><?= rlIco('video', 16) ?> Virtual<?= ($rev['plataforma'] ?? '') !== '' ? ' · ' . e(['zoom' => 'Zoom', 'meet' => 'Google Meet', 'enlace' => 'enlace propio'][$rev['plataforma']] ?? $rev['plataforma']) : '' ?></span>
        <?php else: ?><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Presencial<?= ($rev['lugar'] ?? '') !== '' ? ' · ' . e($rev['lugar']) : '' ?></span><?php endif; ?>
      </p>
      <?php if ($virtual || $puedeReg): ?>
      <div class="rl-hero__acc">
        <?php if ($virtual && ($rev['join_url'] ?? '') !== ''): ?>
        <a class="mc-btn mc-btn--primary mc-btn--sm" href="<?= e($rev['join_url']) ?>" target="_blank" rel="noopener"><?= rlIco('video', 16) ?><span class="mc-btn__label">Entrar a la reunión</span></a>
        <?php if ((int)($rev['reunion_proyecto'] ?? 0) > 0): ?><a class="mc-btn mc-btn--ghost mc-btn--sm" href="proyecto.php?id=<?= (int)$rev['reunion_proyecto'] ?>#vista-reuniones"><span class="mc-btn__label">Ver en Reuniones del proyecto</span><?= rlIco('chevron-der', 16) ?></a><?php endif; ?>
        <?php elseif ($virtual && $puedeReg): ?>
        <button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-rl-crear-reunion="<?= (int)$rev['id'] ?>"><?= rlIco('video', 16) ?><span class="mc-btn__label">Crear la reunión de Zoom</span></button>
        <?php endif; ?>
        <?php if ($puedeReg): ?><button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rl-abrir="rl-dlg-modalidad"><?= rlIco('editar', 16) ?><span class="mc-btn__label">Cambiar modalidad</span></button><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="rl-hero__lado rl-hero__lado--rev" data-rl-rev-cumple>
      <span class="rl-pila rl-pila--grande" aria-hidden="true">
        <?php foreach (['cumplido', 'parcial', 'no_cumplido', 'reprogramado', 'no_aplica', 'pendiente'] as $k): if (!$cu[$k]) continue; ?><i class="rl-r--<?= $k ?>" style="flex:<?= (int)$cu[$k] ?>"></i><?php endforeach; ?>
      </span>
      <p class="rl-hero__pct"><b class="mc-num font-display"><?= $cu['pct'] === null ? '—' : (int)$cu['pct'] . ' %' ?></b><small>cumplimiento · <?= (int)$cu['revisados'] ?> de <?= (int)$cu['programados'] ?> revisados</small></p>
    </div>
  </section>

  <div class="rl-ia-slot" data-rl-resumen>
    <?php if (!empty($rev['resumen_ia'])): ?><script type="application/json" data-rl-resumen-datos><?= json_encode($rev['resumen_ia'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
  </div>

  <div class="rl-rev">
    <form class="rl-rev__main" method="post" action="actions.php" data-rl-rev-form>
      <input type="hidden" name="accion" value="rel_revision_guardar">
      <input type="hidden" name="id" value="<?= (int)$rev['id'] ?>">
      <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'revision', 'id' => (int)$rev['id']])) ?>">
      <div class="rl-rev__filas">
      <?php
        // Acordeón: abre la primera fila sin revisar (o la primera); las demás, plegadas
        $abiertaRl = 0;
        foreach ((array)$rev['filas'] as $iA => $fA) { if (($fA['estado'] ?? 'pendiente') === 'pendiente') { $abiertaRl = $iA; break; } }
        foreach ((array)$rev['filas'] as $i => $f):
          $it = ($f['item_id'] ?? 0) ? ($porId[(int)$f['item_id']] ?? null) : ($porCodigo[$f['codigo']] ?? null);
          $tipoCorto = preg_replace('/\s*\(.*$/', '', (string)$f['tipo']) ?: 'Revisión'; ?>
        <?php
          // Lo que hay que mirar de este ítem y lo que ya se sabe de él (lo esperado, cómo va, sus tareas)
          $estadoF  = isset(Releases::RESULTADOS[$f['estado'] ?? '']) ? $f['estado'] : 'pendiente';
          $esperado = $f['esperado'] ?? ($it['esperado'] ?? null);
          $segunTar = ($it && $it['avance_tareas'] !== null) ? (int)$it['avance_tareas'] : null;
          $resTar   = $it['tareas_resumen'] ?? ['total' => 0, 'hechas' => 0];
          $esCierre = stripos((string)$f['tipo'], 'cierre') !== false;
          $integra  = array_values(array_filter(array_map('trim', preg_split('/\s*·\s*/u', (string)($f['integraciones'] ?? '')) ?: [])));
          $revisar  = array_filter([
              ['documento', 'Debe estar entregado', (string)($f['entregado'] ?? '')],
              ['bandera', 'Foco de la semana', (string)($f['foco'] ?? '')],
              ['check', 'Evidencia', (string)($f['evidencia'] ?? '')],
          ], fn($x) => trim($x[2]) !== '');
          $pctF = $f['pct'] === null ? '' : (int)$f['pct'];
        ?>
        <article class="rl-rfila rl-r--<?= e($estadoF) ?><?= $i === $abiertaRl ? ' is-abierta' : '' ?>" data-rl-rfila data-esperado="<?= $esperado === null ? '' : (int)$esperado ?>"<?= $esCierre ? ' data-cierre' : '' ?>>
          <header class="rl-rfila__cab" data-rl-acordeon>
            <?= rlCode((string)$f['codigo'], rlColorRel($rels, (string)$f['codigo'])) ?>
            <div class="rl-rfila__tit">
              <?php if ($it): ?><a href="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>" data-rl-item="<?= (int)$it['id'] ?>"><?= e($f['titulo'] ?: $it['titulo']) ?></a><?php else: ?><b><?= e($f['titulo'] ?: $f['codigo']) ?></b><?php endif; ?>
              <span class="rl-rfila__meta">
                <span class="rl-tipo rl-tipo--<?= e(strtolower(preg_replace('/[^a-z]/i', '', Releases::normal($tipoCorto)))) ?>"><?= e((string)$f['tipo']) ?></span>
                <?php if ($esperado !== null): ?><span class="rl-rfila__dato">Esperado <b class="mc-num"><?= (int)$esperado ?> %</b></span><?php endif; ?>
                <?php if ($it): ?><span class="rl-rfila__dato">Hoy va en <b class="mc-num"><?= (int)$it['avance'] ?> %</b></span><?php endif; ?>
                <?php if ($resTar['total']): ?><span class="rl-rfila__dato"><?= rlIco('completar', 14) ?> <b class="mc-num"><?= (int)$resTar['hechas'] ?>/<?= (int)$resTar['total'] ?></b> tareas</span><?php endif; ?>
              </span>
            </div>
            <span class="rl-rfila__resumen" data-rl-resumen-fila>
              <span class="rl-rfila__res"><i aria-hidden="true"></i><b data-rl-res-txt><?= e(Releases::RESULTADOS[$estadoF][0]) ?></b></span>
              <b class="mc-num rl-rfila__respct" data-rl-res-pct><?= $pctF === '' ? '' : $pctF . ' %' ?></b>
            </span>
            <span class="rl-rfila__acc">
              <?php if ($puedeReg): ?><button type="button" class="mc-iconbtn mc-iconbtn--sm" data-rl-quitar="<?= (int)$i ?>" aria-label="Quitar de la sesión" data-ds-tip="Quitar de la sesión"><?= rlIco('eliminar', 15) ?></button><?php endif; ?>
              <button type="button" class="mc-iconbtn mc-iconbtn--sm rl-rfila__abrir" data-rl-abrir-fila aria-expanded="<?= $i === $abiertaRl ? 'true' : 'false' ?>" aria-controls="rl-rf-<?= (int)$i ?>" aria-label="Abrir o cerrar <?= e((string)$f['codigo']) ?>"><?= rlIco('chevron-abajo', 18) ?></button>
            </span>
          </header>
          <div class="rl-rfila__cuerpo" id="rl-rf-<?= (int)$i ?>">
            <?php if ($revisar || $integra): ?>
            <div class="rl-rfila__revisar">
              <p class="rl-rfila__rot">Qué revisar</p>
              <ul class="rl-rfila__lista">
                <?php foreach ($revisar as [$ico, $lbl, $txt]): ?>
                <li><span class="rl-rfila__ico" aria-hidden="true"><?= rlIco($ico, 14) ?></span>
                  <div><span class="rl-rfila__lbl"><?= e($lbl) ?></span><p class="rl-rfila__txt" data-rl-recorte><?= e($txt) ?></p></div></li>
                <?php endforeach; ?>
                <?php if ($integra): ?>
                <li><span class="rl-rfila__ico" aria-hidden="true"><?= rlIco('relacionar', 14) ?></span>
                  <div><span class="rl-rfila__lbl">Integraciones a verificar</span>
                    <span class="rl-rfila__chips"><?php foreach ($integra as $x): ?><span class="rl-chipi"><?= e($x) ?></span><?php endforeach; ?></span></div></li>
                <?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>
            <div class="rl-rfila__resultado">
              <p class="rl-rfila__rot">Resultado</p>
              <div class="rl-res" role="radiogroup" aria-label="Resultado de <?= e((string)$f['codigo']) ?>">
                <div class="rl-res__main">
                  <?php foreach (['cumplido' => 'check', 'parcial' => 'menos', 'no_cumplido' => 'cerrar'] as $k => $ico): ?>
                  <label class="rl-res__op rl-r--<?= $k ?>"><input type="radio" name="filas[<?= (int)$i ?>][estado]" value="<?= $k ?>"<?= $estadoF === $k ? ' checked' : '' ?><?= $puedeReg ? '' : ' disabled' ?>>
                    <span><span class="rl-res__ico" aria-hidden="true"><?= rlIco($ico, 16) ?></span><?= e(Releases::RESULTADOS[$k][0]) ?></span></label>
                  <?php endforeach; ?>
                </div>
                <div class="rl-res__mas">
                  <?php foreach (['reprogramado', 'no_aplica', 'pendiente'] as $k): ?>
                  <label class="rl-res__op rl-r--<?= $k ?>"><input type="radio" name="filas[<?= (int)$i ?>][estado]" value="<?= $k ?>"<?= $estadoF === $k ? ' checked' : '' ?><?= $puedeReg ? '' : ' disabled' ?>>
                    <span><i aria-hidden="true"></i><?= e(Releases::RESULTADOS[$k][0]) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="rl-rfila__pct">
                <div class="rl-rfila__pctcab">
                  <label class="rl-rfila__lbl" for="rl-pct-<?= (int)$i ?>">% real</label>
                  <?php if ($puedeReg && ($esperado !== null || $segunTar !== null)): ?>
                  <span class="rl-atajos">
                    <?php if ($esperado !== null): ?><button type="button" class="rl-atajo" data-rl-pct="<?= (int)$esperado ?>">= esperado <?= (int)$esperado ?> %</button><?php endif; ?>
                    <?php if ($segunTar !== null): ?><button type="button" class="rl-atajo" data-rl-pct="<?= $segunTar ?>">según tareas <?= $segunTar ?> %</button><?php endif; ?>
                  </span>
                  <?php endif; ?>
                </div>
                <div class="rl-rango"<?= $pctF === '' ? ' data-vacio' : '' ?> style="--espn:<?= $esperado === null ? '-1' : round((int)$esperado / 100, 2) ?>">
                  <span class="rl-rango__pista">
                    <input type="range" min="0" max="100" step="5" value="<?= $pctF === '' ? 0 : $pctF ?>" data-rl-rango aria-label="% real de <?= e((string)$f['codigo']) ?>"<?= $puedeReg ? '' : ' disabled' ?>>
                    <?php if ($esperado !== null): ?><i class="rl-rango__esp" aria-hidden="true" data-ds-tip="Esperado <?= (int)$esperado ?> %"></i><?php endif; ?>
                  </span>
                  <div class="mc-input mc-input--sm rl-rango__num"><input class="mc-input__control mc-num" id="rl-pct-<?= (int)$i ?>" type="number" min="0" max="100" step="1" inputmode="numeric" name="filas[<?= (int)$i ?>][pct]" value="<?= $pctF ?>" placeholder="—"<?= $puedeReg ? '' : ' disabled' ?>><span class="mc-input__affix">%</span></div>
                </div>
              </div>
              <div class="rl-rfila__obs">
                <label class="rl-rfila__lbl" for="rl-obs-<?= (int)$i ?>">Observaciones</label>
                <div class="mc-input mc-input--multiline mc-input--sm"><textarea class="mc-input__control rl-textarea" id="rl-obs-<?= (int)$i ?>" name="filas[<?= (int)$i ?>][obs]" rows="2" data-rl-crece placeholder="• Qué se vio y qué falta…"<?= $puedeReg ? '' : ' disabled' ?>><?= e((string)($f['obs'] ?? '')) ?></textarea></div>
                <?php if ($puedeReg): ?>
                <div class="rl-frases" aria-label="Frases rápidas">
                  <?php foreach (['Falta evidencia', 'Falta de requerimientos', 'Falta integrar', 'Bloqueado por UBE'] as $fr): ?>
                  <button type="button" class="rl-frase" data-rl-frase="<?= e($fr) ?>"><?= rlIco('mas', 12) ?> <?= e($fr) ?></button>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$rev['filas']): ?><p class="rl-vacio-txt"><?= rlIco('info', 16) ?> Esta sesión todavía no tiene ítems. Agrégalos abajo.</p><?php endif; ?>
      </div>

      <?php if ($puedeReg && $paraAgregar): ?>
      <div class="rl-agregar">
        <span class="rl-filtros__rot">Agregar ítem a la sesión</span>
        <?= UI::selectDS('agregar_codigo', array_combine(array_column($paraAgregar, 'codigo'), array_map(fn($it) => $it['codigo'] . ' · ' . $it['titulo'], $paraAgregar)), '', ['vacio' => 'Elige el ítem…', 'buscar' => true, 'aria' => 'Ítem para agregar']) ?>
        <button type="button" class="mc-btn mc-btn--secondary mc-btn--sm" data-rl-agregar><?= rlIco('mas', 16) ?><span class="mc-btn__label">Agregar</span></button>
      </div>
      <?php endif; ?>

      <section class="rl-bloque rl-rev__acuerdos">
        <h3 class="rl-bloque__tit"><?= rlIco('notas', 18) ?> Acuerdos y compromisos</h3>
        <?= rlTexto('acuerdos', (string)($rev['acuerdos'] ?? ''), 4, 'Quién hace qué y para cuándo…') ?>
        <h3 class="rl-bloque__tit"><?= rlIco('usuarios', 18) ?> Asistentes</h3>
        <?php
          $ops = [];
          $equipoM = array_flip($eqs[$rev['equipo']]['miembros'] ?? []);
          $orden = $miembros;
          uasort($orden, fn($a, $b) => [!isset($equipoM[(int)$a['id']]), $a['nombre']] <=> [!isset($equipoM[(int)$b['id']]), $b['nombre']]);
          foreach ($orden as $m) $ops[(int)$m['id']] = [$m['nombre'], (string)($m['rol'] ?? '')];
          echo rlMulti('asistentes[]', $ops, (array)($rev['asistentes'] ?? []), 'Buscar persona…', !$puedeReg);
        ?>
      </section>

      <?php if ($puedeReg): ?>
      <div class="rl-rev__pie">
        <?php if ($editor): ?>
        <button type="button" class="mc-btn mc-btn--ghost rl-btn-peligro" data-rl-rev-borrar="<?= (int)$rev['id'] ?>"><?= rlIco('eliminar', 18) ?><span class="mc-btn__label">Borrar</span></button>
        <?php endif; ?>
        <span class="rl-rev__aviso" data-rl-rev-sucio hidden><?= rlIco('info', 16) ?> Hay cambios sin guardar</span>
        <button type="submit" class="mc-btn mc-btn--secondary"><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar</span></button>
        <?php if (!$hecha): ?>
        <button type="button" class="mc-btn mc-btn--success" data-rl-registrar><?= rlIco('exito', 18) ?><span class="mc-btn__label">Registrar revisión</span></button>
        <?php endif; ?>
      </div>
      <p class="rl-rev__nota"><?= rlIco('info', 15) ?> Al registrarla, el % real pasa al avance de cada ítem, un «Cumplido» en un cierre termina el ítem y se avisa al equipo.</p>
      <?php endif; ?>
    </form>

    <?php if ($puedeReg): ?>
    <dialog id="rl-dlg-modalidad" class="dlg-meca rl-dlg">
      <form class="dlg-form" method="post" action="actions.php" data-rl-form="rel_revision_guardar" data-rl-tras="recargar">
        <input type="hidden" name="accion" value="rel_revision_guardar"><input type="hidden" name="id" value="<?= (int)$rev['id'] ?>">
        <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'revision', 'id' => (int)$rev['id']])) ?>">
        <header><h3 class="font-display"><i class="fa-solid fa-video"></i> Modalidad de la revisión<small><?= e($rev['equipo']) ?> · <?= e(rlFL($rev['fecha'])) ?> · <?= e((string)$rev['hora']) ?></small></h3>
          <button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
        <?= rlModalidad($virtual ? 'virtual' : 'presencial', (string)($rev['lugar'] ?? ''), (string)$rev['equipo'], (string)$rev['fecha'], !empty($rev['reunion_id'])) ?>
        <footer><button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button><button class="btn-primary btn-meca" type="submit"><i class="fa-solid fa-check"></i> Guardar</button></footer>
      </form>
    </dialog>
    <?php endif; ?>
    <aside class="rl-rev__guia">
      <?php if ($puedeReg): ?>
      <button type="button" class="mc-btn mc-btn--secondary mc-btn--block rl-btn-ia" data-rl-ia-revision="<?= (int)$rev['id'] ?>"><?= rlIco('ia', 18) ?><span class="mc-btn__label"><?= !empty($rev['resumen_ia']) ? 'Resumir otra vez con IA' : 'Resumen con IA' ?></span></button>
      <?php endif; ?>
      <?php
        $secs = [
            'agenda' => ['Agenda de la sesión', 'reloj', (array)($guia['agenda'] ?? []), false],
            'dod' => ['Definición de Terminado (DoD)', 'exito', (array)($guia['dod'] ?? []), true],
            'dor' => ['Definición de Listo (DoR)', 'check', (array)($guia['dor'] ?? []), true],
            'estados' => ['Cómo marcar cada ítem', 'info', (array)($guia['estados'] ?? []), false],
        ];
        foreach ($secs as $k => [$tit, $ico, $lista, $check]): if (!$lista) continue; ?>
      <details class="rl-guia" data-rl-guia="<?= e($k) ?>"<?= $k === 'agenda' || $k === 'dod' ? ' open' : '' ?>>
        <summary><?= rlIco($ico, 18) ?><b><?= e($tit) ?></b><?= rlIco('chevron-abajo', 16) ?></summary>
        <?php if ($check): ?>
        <ul class="rl-guia__check">
          <?php foreach ($lista as $j => $x): ?>
          <li><label><input type="checkbox" data-rl-guia-check="<?= e($k . '-' . $j) ?>"><span class="rl-multi__caja" aria-hidden="true"><?= UI::checkDibujado(11) ?></span><span><?= e(is_array($x) ? ($x['texto'] ?? '') : (string)$x) ?></span></label></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <dl class="rl-guia__dl">
          <?php foreach ($lista as $x): ?><div><dt><?= e(is_array($x) ? ($x['titulo'] ?? '') : '') ?></dt><dd><?= e(is_array($x) ? ($x['texto'] ?? '') : (string)$x) ?></dd></div><?php endforeach; ?>
        </dl>
        <?php endif; ?>
      </details>
      <?php endforeach; ?>
      <?php if (!array_filter(array_column($secs, 2))): ?>
      <p class="rl-vacio-txt"><?= rlIco('info', 16) ?> La guía (DoD, DoR y la agenda) llega al importar el Excel de revisiones.</p>
      <?php endif; ?>
    </aside>
  </div>
<?php endif; ?>

<?php elseif ($v === 'hitos'):
/* =========================================================================
   HITOS
   ========================================================================= */
    $hitos = Releases::hitos();
    $porMes = [];
    foreach ($hitos as $h) $porMes[substr($h['fecha'], 0, 7)][] = $h;
    $hechos = count(array_filter($hitos, fn($h) => !empty($h['hecho'])));
    $hoyPuesto = false;
?>
  <section class="rl-hitos-cab">
    <div class="rl-hitos-cab__n"><b class="mc-num font-display"><?= $hechos ?></b><small>de <?= count($hitos) ?> hitos cumplidos</small></div>
    <span class="rl-pila rl-pila--grande" aria-hidden="true"><i class="rl-r--cumplido" style="flex:<?= max(0, $hechos) ?>"></i><i class="rl-r--pendiente" style="flex:<?= max(0, count($hitos) - $hechos) ?>"></i></span>
  </section>
  <?php if (!$hitos): ?>
  <?= UI::vacioDS('nexo:pensando', 'Sin hitos', 'Los hitos llegan con el Excel del cronograma, o agrégalos aquí.', '') ?>
  <?php endif; ?>
  <ol class="rl-tl">
    <?php foreach ($porMes as $ym => $lista): $tm = strtotime($ym . '-01'); ?>
    <li class="rl-tl__mes"><span><?= e(ucfirst(RL_MESL[(int)date('n', $tm) - 1])) ?> <?= date('Y', $tm) ?></span></li>
      <?php foreach ($lista as $h):
          if (!$hoyPuesto && $h['fecha'] >= $hoy) { $hoyPuesto = true; echo '<li class="rl-tl__hoy"><span>Hoy · ' . e(rlFL($hoy, true)) . '</span></li>'; }
          $atrasado = empty($h['hecho']) && $h['fecha'] < $hoy;
          $relesH = []; preg_match_all('/R\d+/', (string)($h['release'] ?? ''), $mm); $relesH = $mm[0]; ?>
    <li data-pasado="<?= $h['fecha'] < $hoy ? '1' : '0' ?>" class="rl-tl__hito<?= !empty($h['hecho']) ? ' is-hecho' : '' ?><?= $atrasado ? ' is-atrasado' : '' ?><?= $h['fecha'] === $conf['go_live'] ? ' is-go' : '' ?>" id="hito-<?= (int)$h['id'] ?>" style="--hc:<?= e(rlColorRel($rels, (string)($h['release'] ?? ''))) ?>">
      <span class="rl-tl__fecha"><b class="mc-num"><?= (int)date('j', strtotime($h['fecha'])) ?></b><small><?= e(RL_MES[(int)date('n', strtotime($h['fecha'])) - 1]) ?></small></span>
      <span class="rl-rombo rl-tl__rombo" aria-hidden="true"></span>
      <div class="rl-tl__card">
        <div class="rl-tl__top">
          <b class="rl-tl__tit"><?= e($h['titulo']) ?></b>
          <?php foreach ($relesH as $rr): ?><?= rlCode($rr, rlColorRel($rels, $rr)) ?><?php endforeach; ?>
          <?php if (($h['sprint'] ?? '') !== ''): ?><span class="rl-tag"><?= e($h['sprint']) ?></span><?php endif; ?>
          <?= !empty($h['hecho']) ? rlChip('Cumplido', 'verde') : ($atrasado ? rlChip('Pendiente', 'amarillo') : '') ?>
        </div>
        <?php if (($h['descripcion'] ?? '') !== ''): ?><p><?= e($h['descripcion']) ?></p><?php endif; ?>
        <div class="rl-tl__pie">
          <small><?= e(ucfirst(rlFL($h['fecha'], true, true))) ?><?= ($h['origen'] ?? '') !== '' ? ' · ' . e($h['origen']) : '' ?></small>
          <?php if ($editor): ?>
          <form class="rl-tl__hecho" data-rl-hito-hecho="<?= (int)$h['id'] ?>"><?= rlSwitch('hecho', !empty($h['hecho']), 'Hecho') ?></form>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-rl-hito-editar="<?= e(json_encode(['id' => (int)$h['id'], 'titulo' => $h['titulo'], 'fecha' => $h['fecha'], 'release' => $h['release'] ?? '', 'descripcion' => $h['descripcion'] ?? '', 'hecho' => !empty($h['hecho'])], JSON_UNESCAPED_UNICODE)) ?>" aria-label="Editar el hito" data-ds-tip="Editar"><?= rlIco('editar', 16) ?></button>
          <?php endif; ?>
        </div>
      </div>
    </li>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (!$hoyPuesto && $hitos): ?><li class="rl-tl__hoy"><span>Hoy · <?= e(rlFL($hoy, true)) ?></span></li><?php endif; ?>
  </ol>

<?php elseif ($v === 'ajustes'):
/* =========================================================================
   AJUSTES (solo editores)
   ========================================================================= */
    $t = (string)($_GET['t'] ?? 'equipos');
    if (!in_array($t, ['equipos', 'releases', 'avisos', 'importar'], true)) $t = 'equipos';
    $opsM = [];
    foreach ($miembros as $m) $opsM[(int)$m['id']] = [$m['nombre'], (string)($m['rol'] ?? '')];
    uasort($opsM, fn($a, $b) => strnatcasecmp($a[0], $b[0]));
    $selM = ['' => '— No está en el panel —'];
    foreach ($opsM as $id => [$n]) $selM[(string)$id] = $n;
    $opsP = [];
    foreach ($proyMapa as $pid => $p) $opsP[$pid] = [$p['nombre'], ($p['estado'] ?? 'activo') === 'activo' ? '' : 'no activo'];
    uasort($opsP, fn($a, $b) => strnatcasecmp($a[0], $b[0]));
    $dias = ['' => 'Sin día fijo', 'lunes' => 'Lunes', 'martes' => 'Martes', 'miércoles' => 'Miércoles', 'jueves' => 'Jueves', 'viernes' => 'Viernes'];
?>
  <nav class="mc-tabs mc-tabs--segmented rl-subtabs" aria-label="Ajustes de Releases">
    <?php foreach (['equipos' => ['Equipos', 'usuarios'], 'releases' => ['Releases', 'ruta'], 'avisos' => ['Avisos y acceso', 'notificaciones'], 'importar' => ['Importar Excel', 'importar']] as $k => [$lbl, $ico]): ?>
    <a class="mc-tab<?= $t === $k ? ' is-active' : '' ?>" href="<?= e(rlUrl(['v' => 'ajustes', 't' => $k])) ?>"<?= $t === $k ? ' aria-current="page"' : '' ?>><?= rlIco($ico, 16) ?><span><?= e($lbl) ?></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($t === 'equipos'): ?>
  <p class="ds-ayuda">El Scrum Master y el PO se eligen entre la gente del panel; si alguien no tiene cuenta, escribe su nombre. Los proyectos son donde están las tareas del equipo: la IA y la cobertura las leen de ahí.</p>
  <div class="rl-ajustes-grid">
    <?php foreach ($eqs as $nom => $E): ?>
    <form class="mc-card rl-card rl-eqform" id="eq-<?= (int)$E['id'] ?>" method="post" action="actions.php" data-rl-form="rel_equipo_guardar" style="--ec:<?= e(rlColorEq($eqs, $nom)) ?>">
      <input type="hidden" name="accion" value="rel_equipo_guardar"><input type="hidden" name="id" value="<?= (int)$E['id'] ?>">
      <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'ajustes', 't' => 'equipos'])) ?>">
      <input type="hidden" name="proyectos[]" value=""><input type="hidden" name="miembros[]" value="">
      <header class="rl-card__cab"><span class="rl-eq-tile rl-eq-tile--sm" aria-hidden="true"><?= e(mb_substr($nom, 0, 1)) ?></span><div><h2 class="rl-card__tit"><?= e($nom) ?></h2></div>
        <label class="rl-color" data-ds-tip="Color del equipo"><input type="color" name="color" value="<?= e(rlColorEq($eqs, $nom)) ?>" aria-label="Color del equipo"></label></header>
      <div class="rl-form-grid rl-form-grid--eq">
        <?= rlCampo('Nombre', rlInput('name="nombre" maxlength="40" value="' . e($nom) . '" required')) ?>
        <?= rlCampo('Día de revisión', UI::selectDS('dia_revision', $dias, (string)($E['dia_revision'] ?? ''))) ?>
        <?= rlCampo('Hora', rlInput('name="hora_revision" maxlength="20" placeholder="09:00–10:00" value="' . e((string)($E['hora_revision'] ?? '')) . '"')) ?>
      </div>
      <div class="rl-form-grid rl-form-grid--2">
        <?= rlCampo('Scrum Master', UI::selectDS('sm_id', $selM, (string)((int)($E['sm_id'] ?? 0) ?: ''), ['buscar' => true]) . rlInput('name="sm_nombre" maxlength="120" placeholder="Nombre si no está en el panel" value="' . e((string)($E['sm_nombre'] ?? '')) . '"', 'sm')) ?>
        <?= rlCampo('Product Owner', UI::selectDS('po_id', $selM, (string)((int)($E['po_id'] ?? 0) ?: ''), ['buscar' => true]) . rlInput('name="po_nombre" maxlength="120" placeholder="Nombre si no está en el panel" value="' . e((string)($E['po_nombre'] ?? '')) . '"', 'sm')) ?>
      </div>
      <?= rlCampo('Dominio', rlTexto('dominio', (string)($E['dominio'] ?? ''), 2, 'De qué se encarga el equipo')) ?>
      <div class="rl-form-grid">
        <?= rlCampo('Proyectos del panel', rlMulti('proyectos[]', $opsP, $E['proyectos'], 'Buscar proyecto…')) ?>
        <?= rlCampo('Miembros', rlMulti('miembros[]', $opsM, $E['miembros'], 'Buscar persona…'), 'Reciben los avisos del equipo si lo activas en Avisos.') ?>
      </div>
      <footer class="rl-card__pie"><button class="mc-btn mc-btn--primary" type="submit"><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar <?= e($nom) ?></span></button></footer>
    </form>
    <?php endforeach; ?>
    <form class="mc-card rl-card rl-eqform rl-eqform--nuevo" method="post" action="actions.php" data-rl-form="rel_equipo_guardar" data-rl-tras="recargar">
      <input type="hidden" name="accion" value="rel_equipo_guardar"><input type="hidden" name="id" value="0">
      <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'ajustes', 't' => 'equipos'])) ?>">
      <header class="rl-card__cab"><span class="mc-tile mc-tile--sm"><?= rlIco('mas', 20) ?></span><div><h2 class="rl-card__tit">Nuevo equipo</h2><p class="rl-card__sub">Luego completas su SM, PO y proyectos.</p></div></header>
      <div class="rl-form-grid rl-form-grid--2">
        <?= rlCampo('Nombre', rlInput('name="nombre" maxlength="40" placeholder="Épsilon" required')) ?>
        <?= rlCampo('Color', '<label class="rl-color rl-color--campo"><input type="color" name="color" value="#0EA5A4" aria-label="Color"></label>') ?>
      </div>
      <footer class="rl-card__pie"><button class="mc-btn mc-btn--success" type="submit"><?= rlIco('mas', 18) ?><span class="mc-btn__label">Crear equipo</span></button></footer>
    </form>
  </div>

  <?php elseif ($t === 'releases'): ?>
  <p class="ds-ayuda">Las fechas del release se usan para la hoja de ruta y para avisar; nunca limitan las fechas de las tareas.</p>
  <div class="rl-ajustes-grid">
    <?php foreach ($rels as $cod => $r): ?>
    <form class="mc-card rl-card rl-relform" method="post" action="actions.php" data-rl-form="rel_release_guardar" style="--rc:<?= e($r['color']) ?>">
      <input type="hidden" name="accion" value="rel_release_guardar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'ajustes', 't' => 'releases'])) ?>">
      <header class="rl-card__cab"><?= rlCode($cod, $r['color'], 'rl-code--lg') ?><div><h2 class="rl-card__tit"><?= e($r['nombre'] ?? '') ?></h2><p class="rl-card__sub"><?= e($r['sprints'] ?? '') ?></p></div>
        <label class="rl-color" data-ds-tip="Color del release"><input type="color" name="color" value="<?= e($r['color']) ?>" aria-label="Color del release"></label></header>
      <?= rlCampo('Nombre', rlInput('name="nombre" maxlength="160" value="' . e((string)($r['nombre'] ?? '')) . '"')) ?>
      <div class="rl-form-grid rl-form-grid--3">
        <?= rlCampo('Inicio', '<input class="input-meca" type="date" name="inicio" value="' . e((string)($r['inicio'] ?? '')) . '">') ?>
        <?= rlCampo('Fin', '<input class="input-meca" type="date" name="fin" value="' . e((string)($r['fin'] ?? '')) . '">') ?>
        <?= rlCampo('Estado', UI::selectDS('estado', ['por_iniciar' => 'Por iniciar', 'en_curso' => 'En curso', 'cerrado' => 'Cerrado'], (string)($r['estado'] ?? 'por_iniciar'))) ?>
      </div>
      <?= rlCampo('Descripción', rlTexto('descripcion', (string)($r['descripcion'] ?? ''), 2, $r['modulos'] ?? '')) ?>
      <footer class="rl-card__pie"><button class="mc-btn mc-btn--primary" type="submit"><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar <?= e($cod) ?></span></button></footer>
    </form>
    <?php endforeach; ?>
  </div>

  <?php elseif ($t === 'avisos'): $av = $conf['avisos']; ?>
  <form class="rl-avisos" method="post" action="actions.php" data-rl-form="rel_conf_guardar">
    <input type="hidden" name="accion" value="rel_conf_guardar"><input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'ajustes', 't' => 'avisos'])) ?>">
    <input type="hidden" name="destinatarios_enviado" value="1"><?php if (Auth::esAdmin()): ?><input type="hidden" name="editores_enviado" value="1"><?php endif; ?>
    <div class="rl-ajustes-grid">
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm"><?= rlIco('bandera', 20) ?></span><div><h2 class="rl-card__tit">El programa</h2></div></header>
        <div class="rl-form-grid rl-form-grid--2">
          <?= rlCampo('Nombre del programa', rlInput('name="programa" maxlength="60" value="' . e($conf['programa']) . '"')) ?>
          <?= rlCampo('Fecha del Go-Live', '<input class="input-meca" type="date" name="go_live" value="' . e($conf['go_live']) . '">', 'Para la cuenta regresiva del tablero.') ?>
        </div>
      </section>
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('video', 20) ?></span><div><h2 class="rl-card__tit">Reuniones diarias</h2>
          <p class="rl-card__sub">Cada día hábil, cada equipo tiene su daily registrada en el panel (una reunión de sus proyectos con «Daily» en el nombre) y su gente se conecta. Zoom dice quién entró.</p></div></header>
        <div class="rl-form-grid rl-form-grid--2">
          <?= rlCampo('Se miden desde', '<input class="input-meca" type="date" name="dailies_desde" value="' . e($conf['dailies']['desde']) . '">') ?>
          <?= rlCampo('Cuenta como cumplida con', UI::selectDS('dailies_umbral', ['50' => 'La mitad del equipo', '60' => 'El 60 % del equipo', '75' => 'El 75 % del equipo', '80' => 'El 80 % del equipo', '100' => 'Todo el equipo'], (string)$conf['dailies']['umbral'])) ?>
        </div>
      </section>
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--cian"><?= rlIco('notificaciones', 20) ?></span><div><h2 class="rl-card__tit">Por dónde avisar</h2></div></header>
        <div class="rl-switches">
          <?= rlSwitch('avisos[panel]', $av['panel'], 'En el panel', 'La campana de notificaciones.') ?>
          <?= rlSwitch('avisos[correo]', $av['correo'], 'Por correo', 'Con la cuenta de envío del panel.') ?>
        </div>
      </section>
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--naranja"><?= rlIco('alerta', 20) ?></span><div><h2 class="rl-card__tit">De qué avisar</h2></div></header>
        <div class="rl-switches">
          <?= rlSwitch('avisos[cambio_fechas]', $av['cambio_fechas'], 'Cambio de fechas de un ítem') ?>
          <?= rlSwitch('avisos[cambio_estado]', $av['cambio_estado'], 'Cambio de estado de un ítem') ?>
          <?= rlSwitch('avisos[cambio_equipo]', $av['cambio_equipo'], 'Un ítem cambia de equipo o de release') ?>
          <?= rlSwitch('avisos[revision]', $av['revision'], 'Revisión registrada', 'Con el resultado y los compromisos.') ?>
          <?= rlSwitch('avisos[revision_hoy]', $av['revision_hoy'], 'Revisión hoy', 'Al SM, con lo que debe traer.') ?>
          <div class="rl-switch-con">
            <?= rlSwitch('avisos[vence_pronto]', $av['vence_pronto'], 'Ítems que vencen pronto') ?>
            <?= UI::selectDS('avisos[vence_dias]', ['1' => '1 día antes', '2' => '2 días antes', '3' => '3 días antes', '5' => '5 días antes', '7' => '7 días antes'], (string)$av['vence_dias'], ['aria' => 'Con cuántos días de anticipación']) ?>
          </div>
          <?= rlSwitch('avisos[atrasados]', $av['atrasados'], 'Ítems atrasados', 'Los lunes, y el día después de que venzan.') ?>
          <?= rlSwitch('avisos[resumen_semanal]', $av['resumen_semanal'], 'Resumen de la semana', 'Los lunes: el avance de cada equipo.') ?>
        </div>
      </section>
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--verde"><?= rlIco('usuarios', 20) ?></span><div><h2 class="rl-card__tit">A quién</h2><p class="rl-card__sub">Los editores reciben todo. Si no hay nadie, a los administradores.</p></div></header>
        <div class="rl-switches">
          <?= rlSwitch('avisos[al_sm]', $av['al_sm'], 'Al Scrum Master del equipo') ?>
          <?= rlSwitch('avisos[al_equipo]', $av['al_equipo'], 'A los miembros del equipo') ?>
        </div>
        <?= rlCampo('Además, siempre a', rlMulti('destinatarios[]', $opsM, $conf['destinatarios'], 'Buscar persona…')) ?>
      </section>
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('ia', 20) ?></span><div><h2 class="rl-card__tit">La IA</h2><p class="rl-card__sub">El avance de cada ítem sale de sus tareas; la IA es la que las asocia.</p></div></header>
        <div class="rl-switches">
          <input type="hidden" name="ia_auto" value="0">
          <?= rlSwitch('ia_auto', (bool)($conf['ia_auto'] ?? true), 'La IA asocia sola cada día las tareas nuevas de cada equipo', 'Solo si el equipo tiene tareas nuevas o cambiadas. Lo que alguien quite a mano no lo vuelve a poner.') ?>
        </div>
      </section>
      <section class="mc-card rl-card">
        <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--petroleo"><?= rlIco('candado', 20) ?></span><div><h2 class="rl-card__tit">Quién edita y quién ve</h2><p class="rl-card__sub">El administrador siempre edita.</p></div></header>
        <?= rlCampo('Editores', rlMulti('editores[]', $opsM, $conf['editores'], 'Buscar persona…', !Auth::esAdmin()), Auth::esAdmin() ? 'Cambian fechas, equipos, releases y estos ajustes.' : 'Solo el administrador elige a los editores.') ?>
        <div class="rl-switches">
          <?= rlSwitch('ven_sm', $conf['ven_sm'], 'Los Scrum Master lo ven', 'Y registran las revisiones de su equipo.') ?>
          <?= rlSwitch('ven_todos', $conf['ven_todos'], 'Todo el equipo lo ve', 'Solo para mirar.') ?>
        </div>
      </section>
    </div>
    <div class="rl-guardar-fijo"><button class="mc-btn mc-btn--primary mc-btn--lg" type="submit"><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar los ajustes</span></button></div>
  </form>

  <?php else: $imp = $conf['importado']; ?>
  <form class="mc-card rl-card rl-importar" method="post" action="actions.php" enctype="multipart/form-data" data-rl-importar>
    <input type="hidden" name="accion" value="rel_importar"><input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'ajustes', 't' => 'importar'])) ?>">
    <header class="rl-card__cab"><span class="mc-tile mc-tile--sm"><?= rlIco('importar', 20) ?></span>
      <div><h2 class="rl-card__tit">Importar los Excel de la planificación</h2>
        <p class="rl-card__sub">Sube uno o los dos. Primero se previsualiza: nada se guarda hasta que pulses «Importar».
          <?php if ($imp): ?>Última vez: <?= e(rlFL(substr((string)($imp['fecha'] ?? ''), 0, 10), false, true)) ?><?= !empty($imp['quien']) && isset($miembros[(int)$imp['quien']]) ? ' por ' . e($miembros[(int)$imp['quien']]['nombre']) : '' ?> (<?= e(($imp['modo'] ?? '') === 'actualizar' ? 'el Excel mandó' : 'solo lo nuevo') ?>).<?php endif; ?></p></div></header>
    <div class="rl-archivos">
      <?php foreach (['cronograma' => ['Cronograma general', 'Releases, ítems, equipos con SM y PO, hitos.'], 'revisiones' => ['Calendario de revisiones', 'Sesiones por equipo, feriados y la guía (DoD, DoR).']] as $k => [$lbl, $desc]): ?>
      <label class="rl-archivo" data-rl-archivo>
        <input type="file" name="<?= $k ?>" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        <span class="mc-tile mc-tile--verde"><?= rlIco('documento', 22) ?></span>
        <span class="rl-archivo__txt"><b><?= e($lbl) ?></b><small data-rl-archivo-nombre><?= e($desc) ?></small></span>
        <span class="mc-btn mc-btn--secondary mc-btn--sm"><span class="mc-btn__label">Elegir .xlsx</span></span>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="rl-modos" role="radiogroup" aria-label="Cómo importar">
      <label class="rl-modo-op"><input type="radio" name="modo" value="agregar" checked><span><b>Solo lo nuevo</b><small>Agrega los ítems y sesiones que faltan y completa lo vacío. No pisa lo que ya se cambió en el panel.</small></span></label>
      <label class="rl-modo-op"><input type="radio" name="modo" value="actualizar"><span><b>El Excel manda</b><small>Las fechas, el estado y el avance del Excel reemplazan lo del panel. Úsalo tras un reajuste del plan.</small></span></label>
    </div>
    <footer class="rl-card__pie">
      <button class="mc-btn mc-btn--secondary" type="button" data-rl-importar-ver><?= rlIco('ver', 18) ?><span class="mc-btn__label">Previsualizar</span></button>
      <button class="mc-btn mc-btn--primary" type="button" data-rl-importar-ok disabled><?= rlIco('importar', 18) ?><span class="mc-btn__label">Importar</span></button>
    </footer>
    <div class="rl-importar__res" data-rl-importar-res hidden></div>
  </form>
  <?php if (method_exists('Releases', 'semilla') && ($semilla = Releases::semilla())):
      $verSem = preg_match('/(v\d+(?:\.\d+)*)/i', basename($semilla), $mv) ? $mv[1] : basename($semilla, '.json'); ?>
  <section class="mc-card rl-card rl-semilla" data-rl-semilla>
    <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--cian"><?= rlIco('datos', 20) ?></span>
      <div><h2 class="rl-card__tit">Plan incluido en el panel (<?= e($verSem) ?>)</h2>
        <p class="rl-card__sub">El cronograma ya viene preparado con el reparto más reciente. Sirve para cargar o completar el plan sin subir los Excel. Usa el mismo modo de arriba.</p></div></header>
    <footer class="rl-card__pie">
      <button class="mc-btn mc-btn--ghost" type="button" data-rl-sembrar="ver"><?= rlIco('ver', 18) ?><span class="mc-btn__label">Ver qué cambiaría</span></button>
      <button class="mc-btn mc-btn--secondary" type="button" data-rl-sembrar="ok"><?= rlIco('datos', 18) ?><span class="mc-btn__label">Cargar el plan incluido (<?= e($verSem) ?>)</span></button>
    </footer>
    <div class="rl-importar__res" data-rl-semilla-res hidden></div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php /* ---------- Ventanas ---------- */ ?>
<?php if ($v === 'release' && $editor && $todos): $rcN = isset($rels[$_GET['r'] ?? '']) ? (string)$_GET['r'] : $relActual; ?>
<dialog id="rl-dlg-item" class="dlg-meca rl-dlg">
  <form class="dlg-form" method="post" action="actions.php" data-rl-form="rel_item_guardar" data-rl-tras="recargar">
    <input type="hidden" name="accion" value="rel_item_guardar"><input type="hidden" name="id" value="0"><input type="hidden" name="release" value="<?= e($rcN) ?>">
    <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'release', 'r' => $rcN])) ?>">
    <header><h3 class="font-display"><i class="fa-solid fa-plus"></i> Nuevo ítem en <?= e($rcN) ?></h3><button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
    <div class="rl-form-grid rl-form-grid--2">
      <?= rlCampo('Código', rlInput('name="codigo" maxlength="40" placeholder="' . e($rcN) . '-A09" required')) ?>
      <?= rlCampo('Equipo', UI::selectDS('equipo', array_combine(array_keys($eqs), array_keys($eqs)), (string)array_key_first($eqs))) ?>
    </div>
    <?= rlCampo('Título', rlInput('name="titulo" maxlength="200" placeholder="Módulo — qué entrega" required')) ?>
    <div class="rl-form-grid rl-form-grid--2">
      <?= rlCampo('Inicio', '<input class="input-meca" type="date" name="inicio">') ?>
      <?= rlCampo('Fin', '<input class="input-meca" type="date" name="fin">') ?>
      <?= rlCampo('Microservicio', rlInput('name="microservicio" maxlength="80" placeholder="ms-…"')) ?>
      <?= rlCampo('Sprints', rlInput('name="sprints" maxlength="30" placeholder="S24–S26"')) ?>
    </div>
    <?= rlCampo('Alcance', rlTexto('alcance', '', 2)) ?>
    <?= rlCampo('Qué debe demostrarse', rlTexto('demostrar', '', 2)) ?>
    <footer><button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button><button class="btn-primary btn-meca btn-agregar" type="submit"><i class="fa-solid fa-plus"></i> Crear ítem</button></footer>
  </form>
</dialog>
<?php endif; ?>

<?php if ($v === 'revisiones'): $misEq = $editor ? array_keys($eqs) : array_values(array_filter(array_keys($eqs), $smDe)); if ($misEq): ?>
<dialog id="rl-dlg-revision" class="dlg-meca rl-dlg rl-dlg--ancho">
  <form class="dlg-form" method="post" action="actions.php" data-rl-form="rel_revision_guardar" data-rl-tras="ir-revision">
    <input type="hidden" name="accion" value="rel_revision_guardar"><input type="hidden" name="id" value="0">
    <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'revisiones'])) ?>">
    <header><h3 class="font-display"><i class="fa-solid fa-calendar-plus"></i> Nueva revisión</h3><button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
    <div class="rl-dlg__dos">
      <div class="rl-dlg__col">
        <p class="rl-dlg__rot">La sesión</p>
        <div class="rl-form-grid rl-form-grid--2">
          <?= rlCampo('Equipo', UI::selectDS('equipo', array_combine($misEq, $misEq), $misEq[0])) ?>
          <?= rlCampo('Fecha', '<input class="input-meca" type="date" name="fecha" value="' . e($hoy) . '" required>') ?>
          <?= rlCampo('Hora', rlInput('name="hora" maxlength="20" placeholder="09:00–10:00"')) ?>
          <?= rlCampo('Responsable', rlInput('name="responsable" maxlength="200" placeholder="Quién la dirige"')) ?>
        </div>
        <?= rlCampo('Foco de la sesión', rlInput('name="titulo" maxlength="300" placeholder="Cierre de R2-04 · avance de R2-05…"')) ?>
        <p class="campo-ayuda">Al crearla se abre para agregarle los ítems y registrar lo revisado.</p>
      </div>
      <div class="rl-dlg__col">
        <p class="rl-dlg__rot">Dónde se hace</p>
        <?= rlModalidad() ?>
      </div>
    </div>
    <footer><button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button><button class="btn-primary btn-meca btn-agregar" type="submit"><i class="fa-solid fa-plus"></i> Crear revisión</button></footer>
  </form>
</dialog>
<dialog id="rl-dlg-editar-rev" class="dlg-meca rl-dlg">
  <form class="dlg-form" method="post" action="actions.php" data-rl-form="rel_revision_guardar" data-rl-tras="recargar">
    <input type="hidden" name="accion" value="rel_revision_guardar"><input type="hidden" name="id" value="0">
    <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'revisiones'])) ?>">
    <header><h3 class="font-display"><i class="fa-solid fa-pen"></i> Editar revisión<small data-rl-er-eq></small></h3><button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
    <div class="rl-form-grid rl-form-grid--2">
      <?= rlCampo('Fecha', '<input class="input-meca" type="date" name="fecha" required>') ?>
      <?= rlCampo('Hora', rlInput('name="hora" maxlength="20" placeholder="09:00–10:00"')) ?>
      <?= rlCampo('Responsable', rlInput('name="responsable" maxlength="200" placeholder="Quién la dirige"')) ?>
      <?= rlCampo('Estado', UI::selectDS('estado', ['programada' => 'Programada', 'cancelada' => 'Cancelada'], 'programada'), '', 'rl-er-estado') ?>
    </div>
    <?= rlCampo('Foco de la sesión', rlInput('name="titulo" maxlength="300" placeholder="Cierre de R2-04 · avance de R2-05…"')) ?>
    <p class="campo-ayuda" data-rl-er-reu hidden><?= rlIco('video', 14) ?> Tiene su reunión: si cambias el día o la hora, se mueve también y se avisa a los invitados.</p>
    <footer>
      <a class="btn-ghost btn-meca rl-dlg__borrar" data-rl-er-abrir href="#"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir la revisión</a>
      <button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button><button class="btn-primary btn-meca" type="submit"><i class="fa-solid fa-check"></i> Guardar</button>
    </footer>
  </form>
</dialog>
<?php endif; endif; ?>

<?php if ($v === 'hitos' && $editor): ?>
<dialog id="rl-dlg-hito" class="dlg-meca rl-dlg">
  <form class="dlg-form" method="post" action="actions.php" data-rl-form="rel_hito_guardar" data-rl-tras="recargar">
    <input type="hidden" name="accion" value="rel_hito_guardar"><input type="hidden" name="id" value="0">
    <input type="hidden" name="volver" value="<?= e(rlUrl(['v' => 'hitos'])) ?>">
    <header><h3 class="font-display"><i class="fa-solid fa-flag"></i> <span data-rl-hito-titulo>Nuevo hito</span></h3><button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
    <?= rlCampo('Hito', rlInput('name="titulo" maxlength="200" placeholder="Release 2 Go/No-Go" required')) ?>
    <div class="rl-form-grid rl-form-grid--2">
      <?= rlCampo('Fecha', '<input class="input-meca" type="date" name="fecha" required>') ?>
      <?= rlCampo('Release', rlInput('name="release" maxlength="20" placeholder="R2 o R3/R4" list="rl-rels"')) ?>
    </div>
    <datalist id="rl-rels"><?php foreach ($rels as $cod => $r): ?><option value="<?= e($cod) ?>"><?php endforeach; ?></datalist>
    <?= rlCampo('Descripción', rlTexto('descripcion', '', 3)) ?>
    <input type="hidden" name="hecho" value="0">
    <?= UI::checkDS('hecho', false, 'Ya se cumplió') ?>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-rojo rl-dlg__borrar" data-rl-hito-borrar hidden><i class="fa-solid fa-trash"></i> Borrar</button>
      <button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button>
      <button class="btn-primary btn-meca" type="submit"><i class="fa-solid fa-check"></i> Guardar</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<?= rlRecursosJs($v, $editor, $miembros) ?>
<?php UI::fin(); ?>
