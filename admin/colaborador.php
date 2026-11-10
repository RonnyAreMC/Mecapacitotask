<?php
/**
 * Ficha del colaborador: datos, cuentas (git/correo) y todas sus tareas.
 * Pantalla propia a la que se llega desde la tabla de equipo.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';

$miembrosRepo = new MiembroRepo();
$tareasRepo   = new TareaRepo();

$id = (int)($_GET['id'] ?? 0);
$m  = $miembrosRepo->buscar($id);
if (!$m) {
    redirigir('equipo.php', 'Ese colaborador no existe.', 'error');
}
// La ficha lista las tareas de la persona en todos los proyectos: quien no es
// administrador solo puede abrir la suya.
if (!esAdmin() && $id !== (int)(Auth::usuario()['id'] ?? 0)) {
    redirigir('equipo.php?e=' . MiembroRepo::equipoDe($m), 'Solo puedes abrir tu propia ficha.', 'error');
}

$eq = MiembroRepo::equipoDe($m);
[$eqLabel, $eqIcono] = Catalogo::equipos()[$eq];
$c1 = Catalogo::colorDe($m['color'] ?? 0);
$finales = Catalogo::estadosFinales();

$nombresProyecto = [];
$colorProyecto   = [];
foreach ((new ProyectoRepo())->todos() as $p) {
    $nombresProyecto[(int)$p['id']] = $p['nombre'];
    $colorProyecto[(int)$p['id']]   = ProyectoRepo::colorBase($p);
}

// Todas las tareas del colaborador (abiertas primero)
$susTareas = array_values(array_filter($tareasRepo->todas(), fn($t) => TareaRepo::tieneAsignado($t, $id)));
usort($susTareas, function ($a, $b) use ($finales) {
    $fa = in_array($a['estado'] ?? '', $finales, true) ? 1 : 0;
    $fb = in_array($b['estado'] ?? '', $finales, true) ? 1 : 0;
    return $fa <=> $fb;
});
$abiertas = count(array_filter($susTareas, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
$totales  = count($susTareas);
$hechas   = $totales - $abiertas;

/* ---------- Métricas personales (KPIs + gráficos) ---------- */
$hoy     = date('Y-m-d');
$limite7 = date('Y-m-d', strtotime('+7 days'));
$atrasadas = $porVencer = $aTiempo = $tarde = 0;
$porEstado    = array_fill_keys(array_keys(Catalogo::estadosTarea()), 0);
$porPrioridad = array_fill_keys(array_keys(Catalogo::prioridades()), 0);
$porProyecto  = [];
foreach ($susTareas as $t) {
    $est  = $t['estado'] ?? 'pendiente';
    $prio = $t['prioridad'] ?? 'media';
    $pid  = (int)$t['proyecto_id'];
    $porEstado[$est]     = ($porEstado[$est] ?? 0) + 1;
    $porPrioridad[$prio] = ($porPrioridad[$prio] ?? 0) + 1;
    $porProyecto[$pid]   = ($porProyecto[$pid] ?? 0) + 1;
    $esFinal = in_array($est, $finales, true);
    $lim  = $t['fecha_limite'] ?? '';
    $comp = $t['completada_en'] ?? '';
    if (!$esFinal && $lim !== '') {
        if ($lim < $hoy)        $atrasadas++;
        elseif ($lim <= $limite7) $porVencer++;
    }
    if ($esFinal && $lim !== '' && $comp !== '') {
        $comp <= $lim ? $aTiempo++ : $tarde++;
    }
}
/* ---------- Requerimientos sueltos de esta persona ----------
   No son tareas de proyecto y no salían por ningún lado en su ficha, así que
   la carga real de alguien con muchos requerimientos se veía a cero. */
$susReqs = array_values(array_filter(
    (new RequerimientoRepo())->todos(),
    fn($r) => RequerimientoRepo::tieneAsignado($r, $id)
));
$reqTotal    = count($susReqs);
$reqCerrados = count(array_filter($susReqs, fn($r) => RequerimientoRepo::cerrado($r)));
$reqAbiertos = $reqTotal - $reqCerrados;
$reqVencidos = count(array_filter($susReqs, fn($r) => RequerimientoRepo::vencido($r)));
$reqCumpl    = $reqTotal ? (int)round($reqCerrados * 100 / $reqTotal) : null;

// Reparto por institución, con el color propio de cada una
$instRepo   = new InstitucionRepo();
$instMapaC  = $instRepo->mapa();
$reqPorInst = [];
foreach ($susReqs as $r) {
    foreach (RequerimientoRepo::institucionesDe($r) as $iid) {
        if (!isset($instMapaC[$iid])) continue;
        $reqPorInst[$iid] = ($reqPorInst[$iid] ?? 0) + 1;
    }
}
arsort($reqPorInst);

$avance       = $totales ? (int)round($hechas * 100 / $totales) : 0;
$conPuntualidad = $aTiempo + $tarde;
$puntualidad  = $conPuntualidad ? (int)round($aTiempo * 100 / $conPuntualidad) : null;

// Colores de los catálogos (para que los gráficos usen la misma paleta)
$colEstado = $colPrio = [];
foreach ((array)Config::get('estados_tarea') as $k => $v) $colEstado[$k] = $v['color'] ?? '#2B76F7';
foreach ((array)Config::get('prioridades')  as $k => $v) $colPrio[$k]   = $v['color'] ?? '#94a3b8';

/* Donut SVG a partir de segmentos [ [valor, color], ... ] */
$donut = function (array $segs, string $centro, string $sub): string {
    $total = array_sum(array_map(fn($s) => $s[0], $segs));
    $r = 54; $circ = 2 * M_PI * $r; $off = 0;
    $svg = '<svg viewBox="0 0 140 140" class="mc-donut" role="img">';
    $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" class="mc-donut-track" stroke-width="12"/>';
    if ($total > 0) {
        foreach ($segs as [$v, $col]) {
            if ($v <= 0) continue;
            $len = $circ * $v / $total;
            $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . e($col) . '"'
                  . ' stroke-width="12" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '"'
                  . ' stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 70 70)"/>';
            $off += $len;
        }
    }
    $svg .= '<text x="70" y="68" class="mc-donut-num">' . e($centro) . '</text>';
    $svg .= '<text x="70" y="88" class="mc-donut-sub">' . e($sub) . '</text></svg>';
    return $svg;
};
/* Barras horizontales a partir de [ [label, valor, color], ... ] */
$barras = function (array $items): string {
    $vals = array_map(fn($i) => $i[1], $items);
    $max = $vals ? max(1, max($vals)) : 1;
    $h = '<div class="mc-bars">';
    foreach ($items as [$lab, $val, $col]) {
        $pct = (int)round($val * 100 / $max);
        $h .= '<div class="mc-bar-row"><span class="mc-bar-lab">' . e($lab) . '</span>'
            . '<span class="mc-bar-track"><span class="mc-bar-fill" style="width:' . $pct . '%;background:' . e($col) . '"></span></span>'
            . '<span class="mc-bar-val">' . (int)$val . '</span></div>';
    }
    return $h . '</div>';
};

// Proyectos en los que participa
$susProyectos = [];
foreach ($susTareas as $t) {
    $susProyectos[(int)$t['proyecto_id']] = true;
}

UI::inicio('Ficha · ' . $m['nombre'], 'equipo-' . $eq);
?>

<?php
/* ---------- La ficha, al estilo del panel: tarjetas blancas sin bordes ----------
   Arriba quién es y sus cuentas, con las acciones solo con ícono y «Regresar» a
   la derecha. Debajo, cinco indicadores que se leen solos (cada uno dice qué
   significa su número) y cuatro gráficos. Prefijo kp-. */
$tonoPct = fn(?int $p): string => $p === null ? 'neutro' : ($p >= 70 ? 'ok' : ($p >= 40 ? 'aviso' : 'mal'));
$nProy   = count($susProyectos);
$pctDe   = fn(int $n, int $de): int => $de > 0 ? (int)round($n * 100 / $de) : 0;
// Colores de los estados: los de la pastilla de estado del tablero (UI::selectEstadoTarea)
$colEstadoDS = ['pendiente' => '#0B7EA8', 'progreso' => '#2563EB', 'revision' => '#C26F0E', 'hecho' => '#059669'];
$colPrioDS   = ['baja' => '#64748B', 'media' => '#D97706', 'alta' => '#EA580C'];
$tile = fn(string $ico, string $tono, string $valor, string $lab, string $sub = '', string $extra = ''): string =>
    '<article class="kp-tile kp--' . $tono . '">'
    . '<div class="kp-tile__top"><span class="kp-tile__lab">' . e($lab) . '</span><span class="kp-tile__ico" aria-hidden="true">' . UI::icono($ico) . '</span></div>'
    . '<b class="kp-tile__num">' . e($valor) . '</b>'
    . ($sub !== '' ? '<small class="kp-tile__sub">' . $sub . '</small>' : '') . $extra . '</article>';
/* Barras: [ [etiqueta, valor, color, enlace?], ... ] */
$kpBarras = function (array $items): string {
    $max = max(1, ...array_map(fn($i) => (int)$i[1], $items ?: [[0, 0]]));
    $h = '<ul class="kp-barras">';
    foreach ($items as $it) {
        [$lab, $val, $col] = $it;
        $url = $it[3] ?? '';
        $nombre = $url !== '' ? '<a href="' . e($url) . '" title="' . e($lab) . '">' . e($lab) . '</a>' : '<span title="' . e($lab) . '">' . e($lab) . '</span>';
        $h .= '<li style="--kc:' . e($col) . '"><span class="kp-barras__lab"><i class="kp-dot" aria-hidden="true"></i>' . $nombre . '</span>'
            . '<span class="kp-barras__pista"><span style="width:' . max((int)$val > 0 ? 4 : 0, (int)round($val * 100 / $max)) . '%"></span></span>'
            . '<b class="kp-barras__n">' . (int)$val . '</b></li>';
    }
    return $h . '</ul>';
};
$copiar = fn(string $v, string $tip) => '<button type="button" class="btn-copiar kp-cuenta__copiar" data-copiar="' . e($v) . '" aria-label="' . e($tip) . '" data-ds-tip="' . e($tip) . '"><i class="fa-regular fa-copy"></i></button>';
?>

<header class="page-head ru-cab kp-cab" style="--av-c1:<?= $c1 ?>">
  <div class="ru-cab__main">
    <span class="kp-cab__foto"><?= UI::avatar($m, 72) ?></span>
    <div class="ru-cab__txt">
      <p class="kp-cab__rol"><?= UI::iconoEquipo($eq, $eqIcono, 'ico-equipo--chip') ?> <?= e(trim((string)($m['rol'] ?? '')) ?: 'Sin rol') ?> · <?= e(MiembroRepo::etiquetaEquipos($m)) ?></p>
      <h1 class="page-title font-display"><?= e($m['nombre']) ?></h1>
      <div class="kp-cuentas">
        <?php if (!empty($m['git_user'])): ?>
        <span class="kp-cuenta"><a href="https://github.com/<?= e($m['git_user']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> @<?= e($m['git_user']) ?></a><?= $copiar((string)$m['git_user'], 'Copiar usuario de Git') ?></span>
        <?php endif; ?>
        <?php if (!empty($m['email'])): ?>
        <span class="kp-cuenta"><a href="mailto:<?= e($m['email']) ?>"><i class="fa-regular fa-envelope"></i> <?= e($m['email']) ?></a><?= $copiar((string)$m['email'], 'Copiar correo') ?></span>
        <?php else: ?>
        <span class="kp-cuenta kp-cuenta--off"><i class="fa-regular fa-envelope"></i> Sin correo registrado</span>
        <?php endif; ?>
        <?php if (!empty($m['telefono'])): ?>
        <span class="kp-cuenta"><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string)$m['telefono'])) ?>"><i class="fa-solid fa-phone"></i> <?= e($m['telefono']) ?></a><?= $copiar((string)$m['telefono'], 'Copiar teléfono') ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="page-actions ru-cab__acc">
    <?php if (esAdmin()): ?>
    <button type="button" class="rs-boton rs-boton--icono solo-admin" aria-label="Editar" data-ds-tip="Editar"
      data-editar-miembro='<?= e(json_encode([
          'id' => $id, 'nombre' => $m['nombre'], 'rol' => $m['rol'] ?? '',
          'git_user' => $m['git_user'] ?? '', 'git_emails' => $m['git_emails'] ?? '', 'email' => $m['email'] ?? '', 'telefono' => $m['telefono'] ?? '',
          'acceso' => $m['acceso'] ?? 'lector',
          'color' => $m['color'] ?? 0, 'foto' => $m['foto'] ?? '', 'equipo' => $eq, 'equipos' => MiembroRepo::equiposDe($m),
      ], JSON_UNESCAPED_UNICODE)) ?>'><?= UI::icono('ds-editar') ?></button>
    <form method="post" action="actions.php" class="inline-form solo-admin"
          data-confirmar="<?= e($m['nombre']) ?> saldrá del equipo y sus tareas quedarán sin asignar."
          data-confirmar-titulo="¿Retirar del equipo?" data-confirmar-ok="Sí, retirar">
      <input type="hidden" name="accion" value="miembro_eliminar">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button class="rs-boton rs-boton--icono kp-peligro" aria-label="Retirar del equipo" data-ds-tip="Retirar del equipo"><i class="fa-solid fa-user-minus"></i></button>
    </form>
    <span class="ru-cab__sep" aria-hidden="true"></span>
    <?php endif; ?>
    <a class="rs-boton rs-boton--icono" href="equipo.php?e=<?= e($eq) ?>" aria-label="Regresar" data-ds-tip="Regresar a <?= e($eqLabel) ?>">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg></a>
  </div>
</header>

<section class="kp-tiles" aria-label="Indicadores personales">
  <?= $tile('ds-lista-espera', 'info', (string)$totales, 'Asignadas',
        $totales ? 'en ' . $nProy . ' proyecto' . ($nProy === 1 ? '' : 's') : 'todavía ninguna') ?>
  <?= $tile('ds-check', 'ok', (string)$hechas, 'Completadas',
        $totales ? '<b>' . $avance . ' %</b> de lo asignado' : '—',
        '<span class="kp-tile__barra" aria-hidden="true"><span style="width:' . $avance . '%"></span></span>') ?>
  <?= $tile('ds-progreso', 'info', (string)$abiertas, 'Abiertas',
        $porVencer ? e($porVencer . ' vence' . ($porVencer === 1 ? '' : 'n') . ' en los próximos 7 días') : 'ninguna vence esta semana') ?>
  <?= $tile('ds-alerta', $atrasadas ? 'mal' : 'ok', (string)$atrasadas, 'Atrasadas',
        $atrasadas ? 'pasaron su fecha límite' : 'todo al día') ?>
  <?= $tile('ds-reloj', $tonoPct($puntualidad), $puntualidad === null ? '—' : $puntualidad . ' %', 'Puntualidad',
        $conPuntualidad ? e($aTiempo . ' de ' . $conPuntualidad . ' entregadas a tiempo') : 'sin tareas cerradas con fecha límite') ?>
</section>

<section class="kp-grid">

  <article class="kp-card">
    <h2 class="kp-card__tit">Tareas por estado</h2>
    <?php if ($totales === 0): ?>
      <p class="kp-vacio">Sin tareas todavía.</p>
    <?php else:
      $segsEstado = [];
      foreach (Catalogo::estadosTarea() as $k => [$lab, $ic]) $segsEstado[] = [$porEstado[$k] ?? 0, $colEstadoDS[$k] ?? ($colEstado[$k] ?? '#2B76F7')];
    ?>
    <div class="kp-dona">
      <?= $donut($segsEstado, (string)$totales, 'tareas') ?>
      <ul class="kp-leyenda">
        <?php foreach (Catalogo::estadosTarea() as $k => [$lab, $ic]): $nE = (int)($porEstado[$k] ?? 0); ?>
        <li<?= $nE ? '' : ' class="is-cero"' ?>><i class="kp-dot" style="--kc:<?= e($colEstadoDS[$k] ?? ($colEstado[$k] ?? '#2B76F7')) ?>"></i>
          <span><?= e($lab) ?></span><b><?= $nE ?></b><small><?= $pctDe($nE, $totales) ?> %</small></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </article>

  <article class="kp-card">
    <h2 class="kp-card__tit">Entrega a tiempo</h2>
    <div class="kp-punt kp--<?= $tonoPct($puntualidad) ?>">
      <b class="kp-punt__num"><?= $puntualidad === null ? '—' : $puntualidad . ' %' ?></b>
      <span class="kp-punt__txt"><?= $conPuntualidad > 0 ? 'de las tareas cerradas con fecha límite se entregaron a tiempo' : 'Aún no hay tareas cerradas con fecha límite.' ?></span>
    </div>
    <?php if ($conPuntualidad > 0): ?>
    <div class="kp-split" aria-hidden="true">
      <?php if ($aTiempo): ?><span class="kp-split__a" style="flex:<?= $aTiempo ?>"></span><?php endif; ?>
      <?php if ($tarde): ?><span class="kp-split__b" style="flex:<?= $tarde ?>"></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <ul class="kp-leyenda kp-leyenda--2">
      <li><i class="kp-dot" style="--kc:#059669"></i><span>A tiempo</span><b><?= $aTiempo ?></b></li>
      <li><i class="kp-dot" style="--kc:#DC2626"></i><span>Tarde</span><b><?= $tarde ?></b></li>
      <li><i class="kp-dot" style="--kc:#EA580C"></i><span>Atrasadas (abiertas)</span><b><?= $atrasadas ?></b></li>
      <li><i class="kp-dot" style="--kc:#0EA5E9"></i><span>Vencen en 7 días</span><b><?= $porVencer ?></b></li>
    </ul>
  </article>

  <article class="kp-card">
    <h2 class="kp-card__tit">Carga por prioridad</h2>
    <?php if ($totales === 0): ?>
      <p class="kp-vacio">Sin tareas todavía.</p>
    <?php else:
      $itemsPrio = [];
      foreach (Catalogo::prioridades() as $k => [$lab, $ic]) $itemsPrio[] = [$lab, $porPrioridad[$k] ?? 0, $colPrioDS[$k] ?? ($colPrio[$k] ?? '#94A3B8')];
      echo $kpBarras($itemsPrio);
    endif; ?>
  </article>

  <article class="kp-card">
    <h2 class="kp-card__tit">Tareas por proyecto</h2>
    <?php if (empty($porProyecto)): ?>
      <p class="kp-vacio">Sin tareas todavía.</p>
    <?php else:
      arsort($porProyecto);
      $itemsProy = [];
      foreach ($porProyecto as $pid => $n) $itemsProy[] = [$nombresProyecto[$pid] ?? '—', $n, $colorProyecto[$pid] ?? '#2B76F7', 'proyecto.php?id=' . (int)$pid];
      echo $kpBarras($itemsProy);
    endif; ?>
  </article>

</section>

<?php if ($reqTotal): /* solo si tiene: si no, es una tarjeta vacía de más */ ?>
<!-- Requerimientos sueltos: no son tareas de proyecto, así que van aparte y
     con su propio corte. Sin esto, alguien cargado de requerimientos aparecía
     libre en su ficha. -->
<section class="kp-card kp-reqs">
  <header class="kp-card__cab">
    <h2 class="kp-card__tit">Requerimientos sueltos <span class="tabla-count"><?= $reqTotal ?></span></h2>
    <?php if ($reqCumpl !== null): ?><span class="kp-reqs__cumpl"><b><?= $reqCumpl ?> %</b> ya cerrados</span><?php endif; ?>
  </header>
  <div class="kp-minis">
    <div><span>Asignados</span><b><?= $reqTotal ?></b></div>
    <div><span>Resueltos</span><b class="kp-t--ok"><?= $reqCerrados ?></b></div>
    <div><span>Abiertos</span><b><?= $reqAbiertos ?></b></div>
    <div><span>Vencidos</span><b class="<?= $reqVencidos ? 'kp-t--mal' : '' ?>"><?= $reqVencidos ?></b></div>
  </div>
  <?php if ($reqCumpl !== null): ?>
  <div class="kp-split" aria-hidden="true">
    <?php if ($reqCerrados): ?><span class="kp-split__a" style="flex:<?= $reqCerrados ?>"></span><?php endif; ?>
    <?php if ($reqAbiertos): ?><span class="kp-split__c" style="flex:<?= $reqAbiertos ?>"></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($reqPorInst): ?>
  <h3 class="kp-card__sub">Por institución</h3>
  <?php
    $itemsInst = [];
    foreach ($reqPorInst as $iid => $n2) $itemsInst[] = [$instMapaC[$iid]['nombre'], $n2, InstitucionRepo::colorBase($instMapaC[$iid])];
    echo $kpBarras($itemsInst);
  ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card-base tabla-card">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Sus tareas
      <span class="tabla-count"><?= $totales ?></span>
    </h2>
  </div>
  <?php if (empty($susTareas)): ?>
    <?= UI::vacio('fa-mug-hot', 'Sin tareas asignadas', $m['nombre'] . ' no tiene tareas en ningún proyecto todavía.') ?>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="tabla-meca">
      <thead>
        <tr>
          <th>Tarea</th>
          <th>Proyecto</th>
          <th>Prioridad</th>
          <th>Estado</th>
          <th>Fecha límite</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($susTareas as $t):
            $pid = (int)$t['proyecto_id'];
            $esFinal = in_array($t['estado'] ?? '', $finales, true);
        ?>
        <tr class="<?= $esFinal ? 'fila-hecha' : '' ?>">
          <td class="celda-tarea">
            <span class="prio-dot prio-<?= e($t['prioridad'] ?? 'media') ?>"></span>
            <div><b><?= e($t['titulo']) ?></b></div>
          </td>
          <td>
            <a class="chip-proyecto" href="proyecto.php?id=<?= $pid ?>" style="--pc:<?= e($colorProyecto[$pid] ?? '#2B76F7') ?>">
              <i class="fa-regular fa-folder"></i> <?= e($nombresProyecto[$pid] ?? '—') ?>
            </a>
          </td>
          <td><?= UI::badgePrioridad($t['prioridad'] ?? 'media') ?></td>
          <td><?= UI::badgeEstadoTarea($t['estado'] ?? '') ?></td>
          <td>
            <?php if (!empty($t['fecha_limite'])): ?>
              <span class="celda-fecha"><i class="fa-regular fa-calendar"></i> <?= e($t['fecha_limite']) ?></span>
            <?php else: ?><span class="celda-fecha celda-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Modal editar colaborador (reutiliza el form de equipo via JS global) -->
<dialog id="dlg-editar-miembro" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="miembro_editar">
    <input type="hidden" name="id" id="em-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-pen text-secondary"></i> Editar colaborador</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <?php
    // Reutiliza el mismo componente de campos del equipo
    require_once __DIR__ . '/lib/team/campos_persona.php';
    camposPersona(true, $eq, Catalogo::equipos());
    ?>
    <footer>
      <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </footer>
  </form>
</dialog>
<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?><option value="<?= e($rol) ?>"></option><?php endforeach; ?>
</datalist>

<?php UI::fin(); ?>
