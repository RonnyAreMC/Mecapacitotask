<?php
/**
 * Ficha del colaborador: datos, cuentas (git/correo) y todas sus tareas.
 * Pantalla propia a la que se llega desde la tabla de equipo.
 */
require_once __DIR__ . '/lib/bootstrap.php';

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
    $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" class="mc-donut-track" stroke-width="15"/>';
    if ($total > 0) {
        foreach ($segs as [$v, $col]) {
            if ($v <= 0) continue;
            $len = $circ * $v / $total;
            $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . e($col) . '"'
                  . ' stroke-width="15" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '"'
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

<header class="colab-hero card-base" style="--av-c1:<?= $c1 ?>">
  <a href="equipo.php?e=<?= e($eq) ?>" class="colab-back"><i class="fa-solid fa-arrow-left"></i> <?= e($eqLabel) ?></a>

  <div class="colab-hero-main">
    <div class="mc-avatar-zone">
      <span class="mc-ring-anim"></span>
      <div class="mc-avatar-ring"><?= UI::avatar($m, 104) ?></div>
    </div>
    <div class="colab-id">
      <h1 class="font-display"><?= e($m['nombre']) ?></h1>
      <p class="mc-rol" style="--av-c1:<?= $c1 ?>"><i class="fa-solid <?= e($eqIcono) ?>"></i> <?= e($m['rol']) ?> · <?= e($eqLabel) ?></p>
      <div class="colab-cuentas">
        <?php if (!empty($m['git_user'])): ?>
        <span class="cuenta-chip">
          <a href="https://github.com/<?= e($m['git_user']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> @<?= e($m['git_user']) ?></a>
          <button type="button" class="btn-copiar mini" data-copiar="<?= e($m['git_user']) ?>" title="Copiar usuario de Git"><i class="fa-regular fa-copy"></i></button>
        </span>
        <?php endif; ?>
        <?php if (!empty($m['email'])): ?>
        <span class="cuenta-chip">
          <a href="mailto:<?= e($m['email']) ?>"><i class="fa-solid fa-envelope"></i> <?= e($m['email']) ?></a>
          <button type="button" class="btn-copiar mini" data-copiar="<?= e($m['email']) ?>" title="Copiar correo"><i class="fa-regular fa-copy"></i></button>
        </span>
        <?php else: ?>
        <span class="cuenta-chip cuenta-off"><i class="fa-solid fa-envelope-circle-check"></i> Sin correo registrado</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="colab-acciones">
      <button class="btn-outline btn-meca btn-sm btn-azul solo-admin" title="Editar"
        data-editar-miembro='<?= e(json_encode([
            'id' => $id, 'nombre' => $m['nombre'], 'rol' => $m['rol'],
            'git_user' => $m['git_user'], 'git_emails' => $m['git_emails'] ?? '', 'email' => $m['email'] ?? '',
            'acceso' => $m['acceso'] ?? 'lector',
            'color' => $m['color'] ?? 0, 'foto' => $m['foto'] ?? '', 'equipo' => $eq,
        ], JSON_UNESCAPED_UNICODE)) ?>'>
        <i class="fa-solid fa-pen"></i> Editar
      </button>
      <form method="post" action="actions.php" class="inline-form solo-admin"
            data-confirmar="<?= e($m['nombre']) ?> saldrá del equipo y sus tareas quedarán sin asignar."
            data-confirmar-titulo="¿Retirar del equipo?" data-confirmar-ok="Sí, retirar">
        <input type="hidden" name="accion" value="miembro_eliminar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-outline btn-meca btn-rojo btn-sm btn-peligro"><i class="fa-solid fa-user-minus"></i></button>
      </form>
    </div>
  </div>
</header>

<section class="stats-grid stats-grid-6">
  <?= UI::stat('fa-layer-group', '#2B76F7', (string)$totales, 'Asignadas') ?>
  <?= UI::stat('fa-circle-check', '#2BB673', (string)$hechas, 'Completadas') ?>
  <?= UI::stat('fa-list-check', '#F7931E', (string)$abiertas, 'Abiertas') ?>
  <?= UI::stat('fa-triangle-exclamation', '#E63946', (string)$atrasadas, 'Atrasadas') ?>
  <?= UI::stat('fa-gauge-high', '#0EA5E9', $avance . '%', 'Avance') ?>
  <?= UI::stat('fa-clock', '#16A34A', ($puntualidad === null ? '—' : $puntualidad . '%'), 'Puntualidad') ?>
</section>

<section class="mc-metricas">

  <!-- Distribución por estado (donut) -->
  <div class="card-base mc-chart">
    <h3 class="mc-chart-tit"><i class="fa-solid fa-chart-pie"></i> Distribución por estado</h3>
    <?php if ($totales === 0): ?>
      <p class="mc-chart-vacio">Sin tareas todavía.</p>
    <?php else:
      $segsEstado = [];
      foreach (Catalogo::estadosTarea() as $k => [$lab, $ic]) {
          $segsEstado[] = [$porEstado[$k] ?? 0, $colEstado[$k] ?? '#2B76F7'];
      }
    ?>
    <div class="mc-donut-wrap">
      <?= $donut($segsEstado, (string)$totales, 'tareas') ?>
      <ul class="mc-legend">
        <?php foreach (Catalogo::estadosTarea() as $k => [$lab, $ic]): ?>
        <li><span class="mc-dot" style="background:<?= e($colEstado[$k] ?? '#2B76F7') ?>"></span>
          <?= e($lab) ?> <b><?= (int)($porEstado[$k] ?? 0) ?></b></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <!-- Responsabilidad de tiempos (puntualidad) -->
  <div class="card-base mc-chart">
    <h3 class="mc-chart-tit"><i class="fa-solid fa-clock"></i> Responsabilidad de tiempos</h3>
    <div class="mc-punt">
      <div class="mc-punt-num" style="color:<?= $puntualidad === null ? '#94a3b8' : ($puntualidad >= 70 ? '#16A34A' : ($puntualidad >= 40 ? '#C26F0E' : '#E63946')) ?>">
        <?= $puntualidad === null ? '—' : $puntualidad . '%' ?>
      </div>
      <p class="mc-punt-sub"><?= $conPuntualidad > 0 ? 'entregadas a tiempo' : 'aún no hay tareas cerradas con fecha límite' ?></p>
    </div>
    <?php if ($conPuntualidad > 0): ?>
    <div class="mc-split">
      <span class="mc-split-a" style="width:<?= (int)round($aTiempo * 100 / $conPuntualidad) ?>%"></span>
      <span class="mc-split-b" style="width:<?= (int)round($tarde * 100 / $conPuntualidad) ?>%"></span>
    </div>
    <?php endif; ?>
    <ul class="mc-punt-list">
      <li><span class="mc-dot" style="background:#16A34A"></span> A tiempo <b><?= $aTiempo ?></b></li>
      <li><span class="mc-dot" style="background:#E63946"></span> Tarde <b><?= $tarde ?></b></li>
      <li><span class="mc-dot" style="background:#F7931E"></span> Atrasadas (abiertas) <b><?= $atrasadas ?></b></li>
      <li><span class="mc-dot" style="background:#0EA5E9"></span> Por vencer (7 días) <b><?= $porVencer ?></b></li>
    </ul>
  </div>

  <!-- Carga por prioridad -->
  <div class="card-base mc-chart">
    <h3 class="mc-chart-tit"><i class="fa-solid fa-signal"></i> Carga por prioridad</h3>
    <?php if ($totales === 0): ?>
      <p class="mc-chart-vacio">Sin tareas todavía.</p>
    <?php else:
      $itemsPrio = [];
      foreach (Catalogo::prioridades() as $k => [$lab, $ic]) {
          $itemsPrio[] = [$lab, $porPrioridad[$k] ?? 0, $colPrio[$k] ?? '#94a3b8'];
      }
      echo $barras($itemsPrio);
    endif; ?>
  </div>

  <!-- Tareas por proyecto -->
  <div class="card-base mc-chart">
    <h3 class="mc-chart-tit"><i class="fa-solid fa-folder-tree"></i> Tareas por proyecto</h3>
    <?php if (empty($porProyecto)): ?>
      <p class="mc-chart-vacio">Sin tareas todavía.</p>
    <?php else:
      arsort($porProyecto);
      $itemsProy = [];
      foreach ($porProyecto as $pid => $n) {
          $itemsProy[] = [$nombresProyecto[$pid] ?? '—', $n, $colorProyecto[$pid] ?? '#2B76F7'];
      }
      echo $barras($itemsProy);
    endif; ?>
  </div>

</section>

<?php if ($reqTotal): /* solo si tiene: si no, es una tarjeta vacía de más */ ?>
<!-- Requerimientos sueltos: no son tareas de proyecto, así que van aparte y
     con su propio corte. Sin esto, alguien cargado de requerimientos aparecía
     libre en su ficha. -->
<section class="mc-reqs">
  <div class="card-base mc-chart">
    <h2 class="font-display"><?= UI::icono('Requerimientos', 'text-secondary') ?> Requerimientos sueltos
      <span class="tabla-count"><?= $reqTotal ?></span>
    </h2>
    <div class="stats-grid stats-grid-4">
      <?= UI::stat('fa-inbox',        '#2B76F7', (string)$reqTotal,    'Asignados') ?>
      <?= UI::stat('fa-circle-check', '#2BB673', (string)$reqCerrados, 'Resueltos') ?>
      <?= UI::stat('fa-hourglass-half', '#C26F0E', (string)$reqAbiertos, 'Abiertos') ?>
      <?= UI::stat('fa-triangle-exclamation', '#C66B2D', (string)$reqVencidos, 'Vencidos') ?>
    </div>
    <?php if ($reqCumpl !== null): ?>
    <div class="mc-punt">
      <p class="mc-punt-num"><?= $reqCumpl ?>%</p>
      <p class="mc-punt-sub">de los que le tocaron ya están cerrados</p>
      <span class="mc-split">
        <span class="mc-split-a" style="width:<?= $reqCumpl ?>%"></span>
        <span class="mc-split-b" style="width:<?= 100 - $reqCumpl ?>%"></span>
      </span>
    </div>
    <?php endif; ?>
    <?php if ($reqPorInst): ?>
    <h3 class="mc-chart-tit">Por institución</h3>
    <?php
      $itemsInst = [];
      foreach ($reqPorInst as $iid => $n2) {
          $itemsInst[] = [$instMapaC[$iid]['nombre'], $n2, InstitucionRepo::colorBase($instMapaC[$iid])];
      }
      echo $barras($itemsInst);
    ?>
    <?php endif; ?>
  </div>
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
    require_once __DIR__ . '/lib/campos_persona.php';
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
