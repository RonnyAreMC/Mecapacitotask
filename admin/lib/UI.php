<?php
/**
 * UI - componentes visuales reutilizables del panel.
 * Todos son metodos estaticos que devuelven/imprimen HTML.
 */
require_once __DIR__ . '/Models.php';

class UI
{
    /* ---------- Layout ---------- */


    /**
     * Fondo decorativo del tablero: un grafo de ramas.
     *
     * Se pinta FUERA de <main> a propósito: ese contenedor lleva una animación
     * con transform y, por dentro, un position:fixed se mide contra él y no
     * contra la pantalla (el fondo acababa donde acababa el contenido).
     *
     * Los colores no van quemados: salen del color de cada proyecto y de los
     * acentos de Ajustes, así el fondo sigue a la marca.
     */
    public static function fondoRamas(): void
    {
        $colores = array_values(array_unique(array_map(
            fn($p) => ProyectoRepo::colorBase($p),
            soloProyectosVisibles((new ProyectoRepo())->todos())
        )));
        foreach ([Config::get('color_secundario'), Config::get('color_acento')] as $extra) {
            if (is_string($extra) && $extra !== '' && !in_array($extra, $colores, true)) {
                $colores[] = $extra;
            }
        }
        for ($i = 0; count($colores) < 6; $i++) {
            $c = Catalogo::colorDe($i * 3);
            if (!in_array($c, $colores, true)) $colores[] = $c;
        }
        $color = fn(int $i) => $colores[$i % count($colores)];

        // Poco y espaciado: dos carriles largos y tres ramas que los unen.
        // Antes eran 14 trazos cruzándose y el fondo cansaba la vista.
        $trazos = [
            ['c1', 'M-60 210 H1260'],
            ['c2', 'M-60 470 H1260'],
            ['r1', 'M210 210 C330 210 300 470 420 470'],
            ['r2', 'M660 470 C780 470 750 210 870 210'],
            ['r3', 'M980 210 C1100 210 1070 470 1190 470'],
        ];
        $nodos = [[210,210],[420,470],[660,470],[870,210],[980,210],[1190,470]];
        ?>
<svg class="fondo-ramas" viewBox="0 0 1200 620" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
  <g class="fr-lineas">
    <?php foreach ($trazos as $i => [$id, $d]): ?>
    <path id="<?= $id ?>" pathLength="1" d="<?= $d ?>" style="color:<?= e($color($i)) ?>"/>
    <?php endforeach; ?>
  </g>
  <!-- El pulso solo recorre los dos carriles largos: con uno por rama, el
       conjunto era demasiado movimiento a la vez. -->
  <g class="fr-pulsos">
    <use href="#c1" style="color:<?= e($color(0)) ?>"/>
    <use href="#c2" style="color:<?= e($color(1)) ?>"/>
  </g>
  <g class="fr-nodos">
    <?php foreach ($nodos as $i => [$x, $y]): ?>
    <circle cx="<?= $x ?>" cy="<?= $y ?>" r="6" style="color:<?= e($color($i)) ?>"/>
    <?php endforeach; ?>
  </g>
</svg>
        <?php
    }

    public static function inicio(string $titulo, string $activo = ''): void
    {
        // El sidebar solo lista los proyectos que el usuario puede abrir:
        // un colaborador de solo lectura ve unicamente en los que participa.
        $proyectos = soloProyectosVisibles((new ProyectoRepo())->todos());
        $marca = Config::all();
        $verComo = verComo();

        // Con "ver como" activo, el sidebar solo lista sus proyectos
        if ($verComo) {
            $vcId = (int)$verComo['id'];
            $pids = [];
            foreach ((new TareaRepo())->todas() as $t) {
                if ((int)($t['asignado_id'] ?? 0) === $vcId) $pids[(int)$t['proyecto_id']] = true;
            }
            $proyectos = array_values(array_filter($proyectos, fn($p) => isset($pids[(int)$p['id']])));
        }
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($titulo) ?> · <?= e(Config::get('titulo')) ?></title>
<link rel="icon" type="<?= logoMime(faviconPanel()) ?>" href="<?= e(faviconPanel()) ?>">
<script>
(function () {
  var params = new URLSearchParams(location.search);
  var q = params.get('theme');
  if (q === 'dark' || q === 'light') localStorage.setItem('meca-theme', q);
  var sb = params.get('sidebar');
  if (sb === 'min' || sb === 'full') localStorage.setItem('meca-sidebar', sb);
  if (localStorage.getItem('meca-theme') === 'dark') document.documentElement.classList.add('dark');
  if (localStorage.getItem('meca-sidebar') === 'min') document.documentElement.classList.add('sb-collapsed');
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('../assets/mecapacito.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/admin.css') ?>">
<?php self::estilosConfig(); ?>
</head>
<body class="admin-body" data-limite-subida="<?= limiteSubidaBytes() ?>" data-rol="<?= e(Auth::rol()) ?>">
<aside class="sidebar">
  <div class="sidebar-top">
    <a href="index.php" class="sidebar-brand sidebar-brand-icono">
      <img class="sidebar-logo" src="<?= e(faviconPanel()) ?>" alt="<?= e($marca['titulo']) ?>">
      <div>
        <strong><?= e($marca['titulo']) ?></strong>
        <span><?= e($marca['subtitulo']) ?></span>
      </div>
    </a>
    <button type="button" id="sidebar-toggle" class="sidebar-toggle" title="Ocultar / mostrar menú">
      <?= UI::icono('PanelLateral') ?>
    </button>
    <!-- Solo en móvil: abre el menú flotante -->
    <button type="button" id="sidebar-burger" class="sidebar-burger" aria-label="Menú" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>

  <div class="sidebar-menu" id="sidebar-menu">

  <!-- Filtro global "Ver como" (solo administradores) -->
  <?php if (puedeVerComo()): ?>
  <div class="ver-como <?= $verComo ? 'activo' : '' ?>">
    <button type="button" class="vc-abrir" onclick="document.getElementById('dlg-ver-como').showModal()"
            title="<?= $verComo ? 'Viendo solo lo de ' . e($verComo['nombre']) : 'Filtrar todo el panel por una persona' ?>">
      <?php if ($verComo): ?>
        <?= self::avatar($verComo, 26) ?>
        <span class="truncate">Viendo a <b><?= e(explode(' ', $verComo['nombre'])[0]) ?></b></span>
      <?php else: ?>
        <?= UI::icono('Eye') ?> <span class="truncate">Ver como...</span>
      <?php endif; ?>
    </button>
    <?php if ($verComo): ?>
    <a class="vc-quitar" href="<?= e(urlConVerComo(0)) ?>" title="Volver a ver todo"><?= UI::icono('Close') ?></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="sidebar-nav">
    <span class="sidebar-label">General</span>
    <a href="index.php" class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
      <?= UI::icono('Dashboard') ?> <span class="truncate">Dashboard</span>
    </a>

    <?php
    // Plegada, la barra no lista los proyectos uno a uno (crecia sin fin y
    // obligaba a hacer scroll): muestra un solo boton que abre un panel
    // flotante con todos. Desplegada se ven en linea, como siempre.
    $enProyecto = str_starts_with($activo, 'proyecto-');
    ?>
    <div class="nav-grupo <?= $enProyecto ? 'con-activo' : '' ?>">
      <span class="sidebar-label">Proyectos</span>
      <button type="button" class="sidebar-link nav-grupo-btn <?= $enProyecto ? 'active' : '' ?>"
              aria-expanded="false" title="Proyectos">
        <?= UI::icono('FolderOpen') ?>
        <span class="truncate">Proyectos</span>
        <?php if ($proyectos): ?><span class="nav-grupo-n"><?= count($proyectos) ?></span><?php endif; ?>
      </button>

      <div class="nav-grupo-items">
        <div class="nav-grupo-cab"><?= UI::icono('FolderOpen') ?> Proyectos</div>
        <?php foreach ($proyectos as $p): ?>
        <a href="proyecto.php?id=<?= (int)$p['id'] ?>"
           class="sidebar-link sidebar-link-proyecto <?= $activo === 'proyecto-' . $p['id'] ? 'active' : '' ?>"
           title="<?= e($p['nombre']) ?>"
           style="--pc:<?= ProyectoRepo::colorBase($p) === '#2D3E50' ? '#40CFFF' : e(ProyectoRepo::colorBase($p)) ?>">
          <?= UI::icono($p['icono'] ?? 'FolderOpen') ?>
          <span class="truncate"><?= e($p['nombre']) ?></span>
        </a>
        <?php endforeach; ?>
        <?php if (!$proyectos): ?>
        <p class="nav-grupo-vacio">Todavía no hay proyectos.</p>
        <?php endif; ?>

        <a href="index.php#nuevo" class="sidebar-link sidebar-link-new solo-admin" onclick="sessionStorage.setItem('abrirNuevo','1')" title="Nuevo proyecto">
          <?= UI::icono('Plus') ?> <span class="truncate">Nuevo proyecto</span>
        </a>
      </div>
    </div>

    <?php
    // Requerimientos sueltos. El administrador tiene el modulo entero, donde
    // los reparte, y su contador es lo que le falta por asignar. El resto del
    // equipo tiene su bandeja, y el contador es lo que tiene encima. Con "ver
    // como" activo se muestra la bandeja de esa persona, que es lo que ella
    // vería.
    $reqVerComo = verComo();
    $reqAdmin   = Auth::esAdmin() && !$reqVerComo;
    $reqRepoNav = new RequerimientoRepo();
    if ($reqAdmin) {
        $reqHref  = 'requerimientos.php';
        $reqClave = 'requerimientos';
        $reqTexto = 'Requerimientos';
        $reqTitle = 'Requerimientos sueltos';
        $nReq     = $reqRepoNav->sinAsignar();
        $reqTitleN = $nReq . ' sin asignar todavía';
    } else {
        $reqHref  = 'bandeja.php';
        $reqClave = 'bandeja';
        $reqTexto = 'Bandeja de entrada';
        $reqTitle = 'Requerimientos que te asignaron';
        $nReq     = $reqRepoNav->abiertosDe((int)($reqVerComo['id'] ?? Auth::usuario()['id'] ?? 0));
        $reqTitleN = $nReq . ' sin cerrar';
    }
    ?>
    <a href="<?= $reqHref ?>" class="sidebar-link <?= $activo === $reqClave ? 'active' : '' ?>"
       title="<?= e($reqTitle) ?>">
      <?= UI::icono('Requerimientos') ?>
      <span class="truncate"><?= e($reqTexto) ?></span>
      <?php if ($nReq): ?><span class="nav-badge nav-badge-fin" title="<?= e($reqTitleN) ?>"><?= $nReq ?></span><?php endif; ?>
    </a>

    <?php
    // Solicitudes de acceso sin resolver. No cuelgan de un equipo concreto
    // (el admin decide cual al aprobar), asi que el contador va en el titulo
    // de la seccion y no en un equipo cualquiera.
    $nSol = Auth::esAdmin() ? (new SolicitudRepo())->cuantas() : 0;
    ?>
    <span class="sidebar-label">Equipos
      <?php if ($nSol): ?><span class="nav-badge" title="<?= $nSol ?> solicitud(es) de acceso por revisar"><?= $nSol ?></span><?php endif; ?>
    </span>
    <?php foreach (Catalogo::equipos() as $ek => [$eLabel, $eIcono]): ?>
    <a href="equipo.php?e=<?= e($ek) ?>" class="sidebar-link <?= $activo === 'equipo-' . $ek ? 'active' : '' ?>" title="<?= e($eLabel) ?>">
      <?= UI::icono($eIcono) ?> <span class="truncate"><?= e($eLabel) ?></span>
    </a>
    <?php endforeach; ?>

    <?php if (Auth::esGestor()): ?>
    <span class="sidebar-label">Configuración</span>
    <?php endif; ?>
    <?php if (Auth::esGestor()): /* Planificar: admin y Scrum Master */ ?>
    <a href="planificar.php" class="sidebar-link <?= $activo === 'planificar' ? 'active' : '' ?>" title="Planificar tareas">
      <?= UI::icono('Task') ?> <span class="truncate">Planificar</span>
    </a>
    <?php endif; ?>
    <?php if (Auth::esAdmin()): /* Ajustes: solo administrador */ ?>
    <a href="ajustes.php" class="sidebar-link <?= $activo === 'ajustes' ? 'active' : '' ?>" title="Ajustes">
      <?= UI::icono('SettingsGear') ?> <span class="truncate">Ajustes</span>
    </a>
    <?php endif; ?>
  </nav>

  <?php $yo = Auth::usuario(); if ($yo): ?>
  <!-- Cuenta: abajo solo el avatar; perfil y salir viven en el desplegable -->
  <div class="nav-grupo cuenta-menu">
    <button type="button" class="sesion-chip nav-grupo-btn <?= $activo === 'perfil' ? 'en-perfil' : '' ?>"
            aria-expanded="false" title="Tu cuenta">
      <?= self::avatar($yo, 30) ?>
      <span class="sesion-info truncate">
        <b><?= e(explode(' ', $yo['nombre'])[0]) ?></b>
        <small><?= e(Auth::ROLES[Auth::rol()] ?? 'Solo lectura') ?></small>
      </span>
      <i class="fa-solid fa-chevron-up sesion-flecha"></i>
    </button>

    <div class="nav-grupo-items cuenta-panel">
      <div class="cuenta-cab">
        <?= self::avatar($yo, 40) ?>
        <span class="cuenta-cab-txt">
          <b class="truncate"><?= e($yo['nombre']) ?></b>
          <small><?= e(Auth::ROLES[Auth::rol()] ?? 'Solo lectura') ?></small>
        </span>
      </div>
      <a href="perfil.php" class="sidebar-link cuenta-perfil <?= $activo === 'perfil' ? 'active' : '' ?>">
        <?= UI::icono('SecurityUser') ?> <span class="truncate">Mi perfil</span>
      </a>
      <a href="docs.php" class="sidebar-link cuenta-docs <?= $activo === 'docs' ? 'active' : '' ?>">
        <?= UI::icono('Book') ?> <span class="truncate">Documentación</span>
      </a>
      <form method="post" action="actions.php" class="cuenta-form">
        <input type="hidden" name="accion" value="auth_logout">
        <button class="sidebar-link cuenta-salir">
          <?= UI::icono('Door') ?> <span class="truncate">Cerrar sesión</span>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="sidebar-foot">
    <button type="button" id="theme-toggle" class="theme-toggle" title="Cambiar tema">
      <i class="fa-solid fa-sun tt-sol"></i>
      <span class="tt-knob"></span>
      <i class="fa-solid fa-moon tt-luna"></i>
    </button>
    <span><?= UI::icono('Laptop') ?> Equipo dev</span>
  </div>
  </div><!-- /.sidebar-menu -->
</aside>

<main class="admin-main">
<?php self::flashes(); ?>
        <?php
    }

    public static function fin(): void
    {
        // El modal lista a todo el equipo con sus cargas: solo para el admin.
        if (puedeVerComo()) {
            self::dialogVerComo();
        }
        ?>
</main>
<script src="<?= asset('assets/admin.js') ?>"></script>
</body>
</html>
        <?php
    }

    /** Modal global para elegir "ver como" (disponible en todas las paginas). */
    private static function dialogVerComo(): void
    {
        $miembros = (new MiembroRepo())->todos();
        $equipos  = Catalogo::equipos();
        $finales  = Catalogo::estadosFinales();
        $actual   = verComo();

        $abiertas = [];
        foreach ((new TareaRepo())->todas() as $t) {
            if (!in_array($t['estado'] ?? '', $finales, true)) {
                $mid = (int)($t['asignado_id'] ?? 0);
                if ($mid) $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
            }
        }
        ?>
<dialog id="dlg-ver-como" class="dlg-meca dlg-ver-como">
  <div class="dlg-form">
    <header>
      <h3 class="font-display"><i class="fa-regular fa-eye text-secondary"></i> ¿Como quién quieres ver el panel?</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <p class="ajuste-ayuda">Todo el panel se filtra a los proyectos y tareas de esa persona. Puedes cambiar o salir cuando quieras.</p>
    <div class="vc-grid">
      <a class="vc-card <?= !$actual ? 'active' : '' ?>" href="<?= e(urlConVerComo(0)) ?>">
        <span class="avatar avatar-empty" style="--sz:62px"><i class="fa-solid fa-users"></i></span>
        <b>Todo el equipo</b>
        <small>Sin filtro</small>
      </a>
      <?php foreach ($miembros as $m):
          $mid = (int)$m['id'];
          $c1 = Catalogo::colorDe($m['color'] ?? 0);
          $eqLabel = $equipos[MiembroRepo::equipoDe($m)][0] ?? '';
      ?>
      <a class="vc-card <?= $actual && (int)$actual['id'] === $mid ? 'active' : '' ?>"
         style="--av-c1:<?= $c1 ?>" href="<?= e(urlConVerComo($mid)) ?>">
        <span class="vc-chip" title="Tareas abiertas"><?= $abiertas[$mid] ?? 0 ?></span>
        <?= self::avatar($m, 62) ?>
        <b><?= e($m['nombre']) ?></b>
        <small><?= e($m['rol']) ?></small>
        <span class="vc-equipo"><?= e($eqLabel) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</dialog>
        <?php
    }

    /** Cabecera de pagina con titulo, subtitulo y acciones (HTML opcional). */
    public static function cabecera(string $titulo, string $subtitulo = '', string $acciones = ''): void
    {
        ?>
<header class="page-head">
  <div>
    <h1 class="page-title font-display"><?= $titulo ?></h1>
    <?php if ($subtitulo): ?><p class="page-sub"><?= $subtitulo ?></p><?php endif; ?>
  </div>
  <?php if ($acciones): ?><div class="page-actions"><?= $acciones ?></div><?php endif; ?>
</header>
        <?php
    }

    /**
     * CSS generado desde Config: colores de marca y de estados/prioridades.
     * Solo emite lo que difiere de los defaults, para conservar las
     * variantes claro/oscuro afinadas del CSS base.
     */
    public static function estilosConfig(): void
    {
        $cfg = Config::all();
        $def = Config::defaults();
        $css = '';

        if (strcasecmp($cfg['color_secundario'], $def['color_secundario']) !== 0) {
            $c = $cfg['color_secundario'];
            $css .= ':root{--c-secondary:' . $c . ';--c-secondary-rgb:' . Config::hexARgb($c) . ';}';
        }
        if (strcasecmp($cfg['color_acento'], $def['color_acento']) !== 0) {
            $c = $cfg['color_acento'];
            $css .= ':root{--c-accent:' . $c . ';--c-accent-rgb:' . Config::hexARgb($c) . ';}';
        }
        // Estados de tarea: los personalizados siempre emiten su color; los de
        // fabrica solo si cambiaron (para conservar las variantes del modo oscuro).
        // Los COLORES_JUBILADOS son defaults de antes: quien nunca tocó el color
        // los tiene guardados y, al cambiar el default, pasarían por
        // personalizados y se quedarían con el color viejo pisando el nuevo con
        // !important. Cuentan como default para que el panel se actualice solo.
        foreach ($cfg['estados_tarea'] as $k => $v) {
            $color = $v['color'] ?? '#64748b';
            $esDefault = isset($def['estados_tarea'][$k])
                && (strcasecmp($color, $def['estados_tarea'][$k]['color']) === 0
                    || in_array(strtolower($color), self::COLORES_JUBILADOS[$k] ?? [], true));
            if (!$esDefault) {
                $css .= '.estado-' . $k . ',.select-pill.estado-' . $k . '{color:' . $color . ' !important;}';
            }
        }
        foreach ($cfg['prioridades'] as $k => $v) {
            $color = $v['color'] ?? '#64748b';
            $esDefault = isset($def['prioridades'][$k]) && strcasecmp($color, $def['prioridades'][$k]['color']) === 0;
            if (!$esDefault) {
                $css .= '.badge-prio.prio-' . $k . '{color:' . $color . ' !important;}';
                $css .= '.prio-dot.prio-' . $k . '{background:' . $color . ' !important;}';
            }
        }
        // Estados de proyecto personalizados: color de acento por defecto
        foreach (array_keys($cfg['estados_proyecto']) as $k) {
            if (!isset($def['estados_proyecto'][$k])) {
                $css .= '.pestado-' . $k . '{color:var(--c-secondary);}';
            }
        }
        if ($css !== '') {
            echo '<style>' . $css . '</style>' . "\n";
        }
    }

    /* ---------- Mensajes flash ---------- */

    /**
     * Contenedor de toasts MC. Siempre se imprime (vacio o con los flash
     * de sesion); MC.toast() de admin.js agrega toasts al mismo lugar.
     */
    public static function flashes(): void
    {
        $iconos = ['success' => 'fa-circle-check', 'error' => 'fa-circle-xmark', 'info' => 'fa-circle-info'];
        echo '<div class="mc-toasts" id="mc-toasts">';
        foreach ($_SESSION['flash'] ?? [] as [$tipo, $msg]) {
            $tipo = isset($iconos[$tipo]) ? $tipo : 'info';
            echo '<div class="mc-toast mc-' . $tipo . '" data-duracion="' . ($tipo === 'error' ? 8000 : 4500) . '">'
               . '<i class="fa-solid ' . $iconos[$tipo] . '"></i>'
               . '<div class="mc-toast-txt">' . e($msg) . '</div>'
               . '<button type="button" class="mc-toast-x" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>'
               . '<span class="mc-toast-barra"></span>'
               . '</div>';
        }
        unset($_SESSION['flash']);
        echo '</div>';
    }

    /* ---------- Avatares ---------- */

    /**
     * Avatar de miembro: foto si tiene, iniciales con gradiente si no.
     * $extra: html extra dentro del wrapper (ej. tooltip).
     */
    public static function avatar(?array $m, int $size = 40, bool $tooltip = false): string
    {
        if (!$m) {
            return '<span class="avatar avatar-empty" style="--sz:' . $size . 'px" title="Sin asignar">
                      <i class="fa-solid fa-user-slash"></i></span>';
        }
        $c1 = Catalogo::colorDe($m['color'] ?? 0);
        $title = $tooltip ? ' title="' . e($m['nombre']) . ' · @' . e($m['git_user']) . '"' : '';
        $inner = !empty($m['foto'])
            ? '<img src="' . e($m['foto']) . '" alt="' . e($m['nombre']) . '">'
            : '<span>' . e(MiembroRepo::iniciales($m)) . '</span>';
        return '<span class="avatar" style="--sz:' . $size . 'px;--av-c1:' . $c1 . '"' . $title . '>'
             . $inner . '</span>';
    }

    /**
     * Avatares de los responsables de una tarea. Sin responsables muestra el
     * avatar "sin asignar"; con uno, su avatar; con varios, la pila solapada.
     */
    public static function avatarsAsignados(array $tarea, array $miembros, int $size = 30, int $max = 3): string
    {
        $lista = [];
        foreach (TareaRepo::asignadosDe($tarea) as $mid) {
            if (isset($miembros[$mid])) $lista[] = $miembros[$mid];
        }
        if (!$lista)              return self::avatar(null, $size);
        if (count($lista) === 1)  return self::avatar($lista[0], $size, true);
        return self::avatarStack($lista, $max, $size);
    }

    /** Pila de avatares solapados (equipo de un proyecto). */
    public static function avatarStack(array $miembros, int $max = 4, int $size = 34): string
    {
        $html = '<span class="avatar-stack">';
        foreach (array_slice($miembros, 0, $max) as $m) {
            $html .= self::avatar($m, $size, true);
        }
        $resto = count($miembros) - $max;
        if ($resto > 0) {
            $html .= '<span class="avatar avatar-more" style="--sz:' . $size . 'px">+' . $resto . '</span>';
        }
        $html .= '</span>';
        return $html;
    }

    /* ---------- Badges ---------- */

    public static function badgeEstadoTarea(string $estado): string
    {
        [$label, $icono] = Catalogo::estadosTarea()[$estado] ?? ['?', 'fa-question'];
        return '<span class="badge-estado estado-' . e($estado) . '"><i class="fa-solid ' . $icono . '"></i> ' . e($label) . '</span>';
    }

    public static function badgePrioridad(string $prio): string
    {
        [$label, $icono] = Catalogo::prioridades()[$prio] ?? ['?', 'fa-question'];
        return '<span class="badge-prio prio-' . e($prio) . '"><i class="fa-solid ' . $icono . '"></i> ' . e($label) . '</span>';
    }

    public static function badgeEstadoProyecto(string $estado): string
    {
        [$label, $icono] = Catalogo::estadosProyecto()[$estado] ?? ['?', 'fa-question'];
        return '<span class="badge-estado pestado-' . e($estado) . '"><i class="fa-solid ' . $icono . '"></i> ' . e($label) . '</span>';
    }

    /* ---------- Formularios ---------- */

    /**
     * Select estilizado. $opciones: clave => etiqueta (o [etiqueta, icono]).
     * $auto: true => envia el formulario al cambiar (edicion en linea).
     * $valor: con $multiple es una lista de claves; si no, una sola clave.
     */
    public static function select(string $name, array $opciones, string|int|array|null $valor = '', bool $auto = false, string $clase = '', bool $multiple = false): string
    {
        $valor ??= '';
        // requestSubmit() SI dispara el evento 'submit' (a diferencia de submit()),
        // que es lo que deja al JS interceptar y guardar por AJAX sin recargar.
        $attrs = $auto ? ' onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"' : '';
        $seleccion = $multiple ? array_map('strval', (array)$valor) : [(string)$valor];
        $nombre = $multiple ? $name . '[]' : $name;
        $html = '<select name="' . e($nombre) . '" class="select-meca ' . e($clase) . '"'
              . ($multiple ? ' multiple' : '') . $attrs . '>';
        foreach ($opciones as $k => $v) {
            $label = is_array($v) ? $v[0] : $v;
            $sel = in_array((string)$k, $seleccion, true) ? ' selected' : '';
            $html .= '<option value="' . e((string)$k) . '"' . $sel . '>' . e($label) . '</option>';
        }
        return $html . '</select>';
    }

    /** Select de estado de tarea que se auto-guarda y se colorea segun el estado. */
    public static function selectEstadoTarea(array $tarea): string
    {
        // Solo el admin o la persona asignada pueden cambiar el estado; para el
        // resto se muestra una insignia estatica (no un select que rebotaria).
        $puede = Auth::esAdmin()
            || TareaRepo::tieneAsignado($tarea, (int)(Auth::usuario()['id'] ?? 0));
        if (!$puede) {
            return self::badgeEstadoTarea($tarea['estado'] ?? '');
        }
        $html = '<form method="post" action="actions.php" class="inline-form">';
        $html .= '<input type="hidden" name="accion" value="tarea_estado">';
        $html .= '<input type="hidden" name="id" value="' . (int)$tarea['id'] . '">';
        $html .= self::select('estado', array_map(fn($v) => $v[0], Catalogo::estadosTarea()),
                              $tarea['estado'], true, 'select-pill estado-' . e($tarea['estado']));
        return $html . '</form>';
    }

    /**
     * Selector de color reutilizable (campos `color` + `color_hex`).
     * Muestra la fila principal (colores de marca + personalizado) y el
     * resto de la paleta en una seccion desplegable para no aglomerar.
     *
     * $valor: indice de Catalogo::COLORES, '#hex' custom, o null para
     * no marcar nada (formularios que rellena JS, como editar persona).
     */
    public static function colorPicker(int|string|null $valor = 0): string
    {
        $colores  = Catalogo::COLORES;
        $esCustom = is_string($valor) && str_starts_with($valor, '#');
        $marca    = array_slice($colores, 0, 6, true);
        $resto    = array_slice($colores, 6, null, true);
        $restoAbierto = !$esCustom && $valor !== null && (int)$valor >= 6;

        $swatch = function (int $i, string $c) use ($valor, $esCustom): string {
            $checked = !$esCustom && $valor !== null && (int)$valor === $i ? ' checked' : '';
            return '<label title="' . e($c) . '">
                      <input type="radio" name="color" value="' . $i . '" data-hex="' . e($c) . '"' . $checked . '>
                      <span style="background:' . e($c) . '"></span>
                    </label>';
        };

        $html = '<div class="color-picker cp-meca">';
        $html .= '<div class="cp-fila-principal">';
        foreach ($marca as $i => $c) {
            $html .= $swatch($i, $c);
        }
        $html .= '<span class="cp-sep"></span>
                  <label class="cp-custom" title="Color personalizado">
                    <input type="radio" name="color" value="custom"' . ($esCustom ? ' checked' : '') . '>
                    <input type="color" name="color_hex" value="' . e($esCustom ? $valor : '#2B76F7') . '">
                  </label>';
        $html .= '</div>';
        $html .= '<details class="cp-mas"' . ($restoAbierto ? ' open' : '') . '>
                    <summary><i class="fa-solid fa-chevron-down"></i> Más colores</summary>
                    <div class="cp-grid">';
        foreach ($resto as $i => $c) {
            $html .= $swatch($i, $c);
        }
        $html .= '</div></details></div>';
        return $html;
    }

    /* ---------- Asistente por pasos (modales apaisados) ---------- */

    /**
     * Pasos de los modales. Cada fila es [etiqueta corta del riel,
     * titulo del panel, texto de ayuda].
     */
    public const PASOS_TAREA = [
        ['Detalle',    'Qué hay que hacer',       'Ponle un título claro y, si quieres, los criterios de aceptación.'],
        ['Asignación', 'Quién la saca adelante',  'Elige a la persona responsable y si espera a otra tarea.'],
        ['Fechas',     'Cuándo empieza y acaba',  'Puedes programarla para que arranque más adelante.'],
        ['Revisión',   'Revisa antes de guardar', 'Un vistazo rápido a todo lo que se va a guardar.'],
    ];

    public const PASOS_INTERCAMBIO = [
        ['Tareas',   'Qué se intercambia',      'La tuya que sueltas y la que tomarías a cambio.'],
        ['Motivo',   'Por qué lo pides',        'Va en el correo, así la otra persona entiende el contexto.'],
        ['Revisión', 'Revisa antes de enviar',  'Nada cambia hasta que la otra persona acepte.'],
    ];

    /**
     * Los dos asistentes de requerimientos comparten el orden de "Nueva
     * tarea" (qué → quién → cuándo → revisar) para que el administrador no
     * tenga que aprender dos flujos distintos.
     */
    public const PASOS_REQUERIMIENTO = [
        ['Petición',     'Qué están pidiendo',      'El título es lo que verás en la lista; el detalle viaja en el correo.'],
        ['Responsables', 'Quién lo saca adelante',  'El número de cada persona es lo que ya tiene abierto: elige a quien esté más libre.'],
        ['Plazo',        'Cuándo empieza y acaba',  'Desde cuándo se puede empezar y para cuándo lo esperan.'],
        ['Revisión',     'Revisa antes de guardar', 'Un vistazo rápido a todo lo que se va a guardar.'],
    ];

    public const PASOS_ASIGNAR = [
        ['Responsables', 'Quién lo saca adelante',  'Puede ir a varias personas. El número es lo que ya tienen abierto.'],
        ['Plazo',        'Cuándo empieza y acaba',  'Se guarda con el requerimiento; puedes moverlo al reasignar.'],
        ['Revisión',     'Revisa antes de guardar', 'Un vistazo a quién se lo mandas y con qué plazo.'],
    ];

    public const PASOS_PROYECTO = [
        ['Identidad',  'De qué va el proyecto',   'Nombre, descripción y cuándo arranca.'],
        ['Equipo',     'Quién participa',         'Solo esta gente aparecerá al asignar tareas del proyecto.'],
        ['Repos',      'Código y estado',         'Enlaza los repositorios para ver la actividad de commits.'],
        ['Aspecto',    'Ícono y color',           'Cómo se distingue el proyecto en el panel.'],
    ];

    /** Riel lateral con la marca del modal y la lista de pasos. */
    public static function wizardRiel(string $icono, string $titulo, string $sub, array $pasos): string
    {
        $html = '<aside class="wz-riel">'
              . '<div class="wz-marca">'
              . '<span class="wz-icono"><i class="fa-solid ' . e($icono) . '"></i></span>'
              . '<h3 class="font-display">' . e($titulo) . '</h3>'
              . '<p>' . e($sub) . '</p>'
              . '</div><div class="wz-pasos">';
        foreach ($pasos as $i => [$corto, $tituloPaso, $ayuda]) {
            $html .= '<button type="button" class="wz-paso" data-titulo="' . e($tituloPaso) . '" data-ayuda="' . e($ayuda) . '">'
                   . '<span class="wz-num">' . ($i + 1) . '</span>'
                   . '<span class="wz-txt"><b>' . e($corto) . '</b><small>' . e($ayuda) . '</small></span>'
                   . '</button>';
        }
        return $html . '</div></aside>';
    }

    /**
     * Editor de repositorios: una fila por repo (tipo, nombre, url) con
     * botones para agregar y quitar. admin.js lo maneja (repos-editor).
     * Un proyecto puede tener varios: dos instituciones, back + front, etc.
     */
    public static function reposEditor(array $proyecto = []): string
    {
        $repos = ProyectoRepo::repos($proyecto);   // ya resuelve nuevos y viejos
        $tipos = ProyectoRepo::TIPOS_REPO;

        // Cada fila lleva un indice propio: 'repos[][tipo]' con [] vacio NO
        // agrupa los tres campos en el mismo elemento (cada [] crea otro).
        $fila = function (array $r, string $idx) use ($tipos): string {
            $tipoActual = $r['tipo'] ?? 'otro';
            $opts = '';
            foreach ($tipos as $k => [$label, $icono]) {
                $sel = $k === $tipoActual ? ' selected' : '';
                $opts .= '<option value="' . e($k) . '"' . $sel . '>' . e($label) . '</option>';
            }
            // nombre: solo si difiere de la etiqueta por defecto del tipo
            $etiquetaDef = $tipos[$tipoActual][0] ?? '';
            $nombre = ($r['label'] ?? '') !== $etiquetaDef ? ($r['label'] ?? '') : '';
            $ramaActual = trim($r['rama'] ?? '');
            $n = 'repos[' . $idx . ']';
            return '<div class="repo-fila">'
                . '<select class="select-meca repo-tipo" name="' . $n . '[tipo]" data-ms="1">' . $opts . '</select>'
                . '<input class="input-meca repo-nombre" name="' . $n . '[nombre]" maxlength="60" value="' . e($nombre) . '" placeholder="Nombre (ej. Sede Norte)">'
                . '<input class="input-meca repo-url" type="url" name="' . $n . '[url]" value="' . e($r['url'] ?? '') . '" placeholder="https://github.com/… o https://gitlab.com/…">'
                // Rama: arranca solo con lo guardado y admin.js pide la lista
                // real a ramas.php al abrir el editor (cacheada 1h en servidor)
                . '<select class="select-meca repo-rama" name="' . $n . '[rama]" data-ms="1"'
                . ' title="Rama con la que abre Métricas. «Rama por defecto» = la del repositorio.">'
                . '<option value="">Rama por defecto</option>'
                . ($ramaActual !== '' ? '<option value="' . e($ramaActual) . '" selected>' . e($ramaActual) . '</option>' : '')
                . '</select>'
                . '<button type="button" class="repo-quitar" title="Quitar"><i class="fa-solid fa-xmark"></i></button>'
                . '</div>';
        };

        $filas = '';
        $i = 0;
        foreach ($repos as $r) {
            $filas .= $fila($r, (string)$i++);
        }

        // Plantilla para filas nuevas: el JS cambia __i__ por un indice fresco
        $plantilla = '<template id="repo-fila-tpl">' . $fila(['tipo' => 'backend', 'url' => '', 'label' => ''], '__i__') . '</template>';

        return '<div class="repos-editor" id="repos-editor" data-repos-editor data-repo-siguiente="' . $i . '">'
            . '<div class="repos-filas">' . $filas . '</div>'
            . '<button type="button" class="btn-outline btn-meca btn-azul btn-sm repo-agregar">'
            . '<i class="fa-solid fa-plus"></i> Agregar repositorio</button>'
            . $plantilla
            . '<small class="campo-ayuda">Elige el tipo y, si tienes varios del mismo (p. ej. dos instituciones), ponle un nombre para distinguirlos. '
            . 'La <b>rama</b> es con la que abre Métricas: déjala vacía para usar la del repositorio (normalmente <code>main</code>) '
            . 'o escribe otra si el equipo trabaja fuera de ahí.</small>'
            . '</div>';
    }

    /** Extensiones que acepta el input de archivos (mismo criterio que el servidor). */
    public const ACEPTA_ADJUNTOS = '.jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx';

    /**
     * Campo de documentos de respaldo de una tarea: van con ella desde que se
     * asigna, para que quien la ejecute tenga el material a mano.
     *
     * La lista de los que ya tiene la rellena admin.js al abrir el modal de
     * edicion; en el de nueva tarea sale vacia.
     */
    public static function adjuntosTarea(): string
    {
        return '<div class="campo campo-adjuntos" data-adjuntos-tarea>'
            . '<span>Documentos de respaldo</span>'
            . '<ul class="adj-lista" hidden></ul>'
            . '<label class="adj-elegir">'
            . '<input type="file" name="adjuntos[]" multiple accept="' . e(self::ACEPTA_ADJUNTOS) . '">'
            . '<i class="fa-solid fa-paperclip"></i><span>Añadir archivos…</span>'
            . '</label>'
            . '<ul class="adj-nuevos" hidden></ul>'
            . '<small class="campo-ayuda">Puedes añadir varios, de golpe o de uno en uno. '
            . 'Los verá quien tenga la tarea asignada, en el detalle de la tarea. '
            . 'Imágenes, PDF, Word, Excel, PowerPoint, TXT o CSV.</small>'
            . '</div>';
    }

    /**
     * Componente ÚNICO de subida de archivos, reutilizable en todo el panel.
     *
     * Envuelve un <input type="file"> nativo (sigue enviándose con el formulario)
     * y lo enriquece por JS (data-archivo): arrastrar y soltar, estado "con
     * archivo" con nombre + peso + quitar, y validación de tipo/tamaño (error).
     *
     * $o admite:
     *   name*     nombre del input (usa 'x[]' para varios)
     *   variante  'zona' (drop-zone, por defecto) | 'inline'
     *   accept    atributo accept ('application/pdf', '.pdf,.docx', 'image/*'…)
     *   ayuda     formato corto (ej. 'PDF · máx 10 MB')
     *   multiple / required / disabled  (bool)
     *   maxMB     tope por archivo (validación en el navegador)
     *   label     etiqueta encima (opcional)
     */
    public static function archivo(array $o): string
    {
        $name   = (string)($o['name'] ?? 'archivo');
        $var    = ($o['variante'] ?? 'zona') === 'inline' ? 'inline' : 'zona';
        $accept = (string)($o['accept'] ?? '');
        $ayuda  = (string)($o['ayuda'] ?? '');
        $mult   = !empty($o['multiple']);
        $req    = !empty($o['required']);
        $dis    = !empty($o['disabled']);
        $maxMB  = (float)($o['maxMB'] ?? 0);
        $label  = (string)($o['label'] ?? '');

        // OJO: NADA de 'required' en el input (va oculto): un required en un input
        // hidden bloquea el envío del form con "no es enfocable". Lo obligatorio se
        // marca con el asterisco y se valida en el servidor.
        $inp = '<input type="file" class="fx-input" name="' . e($name) . '"'
             . ($accept !== '' ? ' accept="' . e($accept) . '"' : '')
             . ($mult ? ' multiple' : '') . ($dis ? ' disabled' : '') . ' hidden>';

        $h = '<div class="fx fx-' . $var . ($dis ? ' fx-off' : '') . '" data-archivo data-variante="' . $var . '"'
           . ($maxMB > 0 ? ' data-max="' . e((string)$maxMB) . '"' : '') . ($mult ? ' data-multi="1"' : '') . '>';
        if ($label !== '') {
            $h .= '<span class="fx-label">' . e($label) . ($req ? ' <b class="fx-req">*</b>' : '') . '</span>';
        }
        $h .= $inp;
        if ($var === 'zona') {
            $h .= '<button type="button" class="fx-disparo fx-zona-btn"' . ($dis ? ' disabled' : '') . '>'
               .  '<span class="fx-up"><i class="fa-solid fa-cloud-arrow-up"></i></span>'
               .  '<span class="fx-zona-txt"><b>Arrastra tu archivo aquí</b>'
               .  '<small>o haz clic para seleccionar' . ($ayuda !== '' ? ' · ' . e($ayuda) : '') . '</small></span>'
               .  '</button>';
        } else {
            $h .= '<button type="button" class="fx-disparo fx-inline-btn"' . ($dis ? ' disabled' : '') . '>'
               .  '<i class="fa-solid fa-paperclip fx-clip"></i>'
               .  '<span class="fx-inline-txt">Seleccionar archivo…</span>'
               .  ($ayuda !== '' ? '<span class="fx-inline-hint">' . e($ayuda) . '</span>' : '')
               .  '</button>';
        }
        $h .= '<div class="fx-files"></div>';
        $h .= '<p class="fx-error" hidden></p>';
        $h .= '</div>';
        return $h;
    }

    /**
     * Editor de texto enriquecido, reutilizable (data-editor-rico).
     *
     * Es un contenteditable con barra de formato mínima y un <textarea> oculto
     * que lleva el HTML en el envío del formulario (con el $name que se pase).
     * Sirve para PEGAR contenido con formato y TABLAS (p. ej. un correo) y que
     * se guarde y se muestre bien. El HTML se sanea SIEMPRE en el servidor
     * (HtmlRico::limpiar) antes de guardar; aquí solo se limpia al pegar para
     * que la edición se vea limpia.
     *
     * $o admite:
     *   name*        nombre del textarea que se envía
     *   valor        HTML inicial (ya saneado) — para formularios de edición
     *   id           id del contenedor (para rellenarlo por JS: MecaRT.set)
     *   placeholder  texto de ayuda cuando está vacío
     */
    public static function editorRico(array $o): string
    {
        $name = (string)($o['name'] ?? 'contenido');
        $val  = (string)($o['valor'] ?? '');
        $id   = (string)($o['id'] ?? '');
        $ph   = (string)($o['placeholder'] ?? 'Escribe o pega aquí… (puedes pegar tablas)');

        // Botones de la barra: [comando execCommand, valor, icono, título].
        $botones = [
            ['bold',        '',   'fa-bold',          'Negrita'],
            ['italic',      '',   'fa-italic',        'Cursiva'],
            ['underline',   '',   'fa-underline',     'Subrayado'],
            ['formatBlock', 'h3', 'fa-heading',       'Título'],
            ['insertUnorderedList', '', 'fa-list-ul',  'Lista'],
            ['insertOrderedList',   '', 'fa-list-ol',  'Lista numerada'],
            ['formatBlock', 'blockquote', 'fa-quote-right', 'Cita'],
            ['createLink',  '',   'fa-link',          'Enlace'],
            ['removeFormat','',   'fa-eraser',        'Quitar formato'],
        ];
        $barra = '';
        foreach ($botones as [$cmd, $cval, $ico, $tit]) {
            $barra .= '<button type="button" class="rt-b" tabindex="-1" data-cmd="' . e($cmd) . '"'
                . ($cval !== '' ? ' data-val="' . e($cval) . '"' : '')
                . ' title="' . e($tit) . '"><i class="fa-solid ' . e($ico) . '"></i></button>';
        }

        $vacio = HtmlRico::vacio($val);
        $h  = '<div class="rt' . ($vacio ? ' rt-vacio' : '') . '" data-editor-rico'
            . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>';
        $h .= '<div class="rt-barra" role="toolbar">' . $barra . '</div>';
        // El HTML inicial NO se escapa: es el contenido editable, ya saneado.
        $h .= '<div class="rt-area rt-render" contenteditable="true" data-ph="' . e($ph) . '">'
            . ($vacio ? '' : $val) . '</div>';
        $h .= '<textarea class="rt-fuente" name="' . e($name) . '" hidden>' . e($val) . '</textarea>';
        $h .= '</div>';
        return $h;
    }

    /** Icono SVG "video/reproducir" (grabación). Hereda el color (currentColor). */
    public static function iconoVideo(): string
    {
        return '<svg class="ico-svg" width="16" height="16" viewBox="0 0 24 23" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<path d="M16.1859 20.9296C16.6483 20.9296 17.0235 21.3048 17.0235 21.7671C17.0235 22.2295 16.6483 22.6047 16.1859 22.6047H7.81408C7.35172 22.6047 6.97653 22.2295 6.97653 21.7671C6.97653 21.3048 7.35172 20.9296 7.81408 20.9296H16.1859ZM22.3249 5.29781C22.3249 4.5043 22.3249 3.95913 22.2904 3.53641C22.2566 3.12337 22.1941 2.90112 22.1123 2.74065C21.9255 2.37418 21.6259 2.07533 21.2575 1.88766C21.0967 1.80576 20.8747 1.74337 20.4618 1.70961C20.0387 1.67504 19.4929 1.6751 18.6977 1.6751H5.30326C4.50829 1.6751 3.96167 1.67506 3.53823 1.70961C3.12445 1.74341 2.90139 1.80576 2.74065 1.88766C2.37332 2.0749 2.0749 2.37332 1.88766 2.74065C1.80576 2.90138 1.74341 3.12445 1.70961 3.53823C1.67506 3.96167 1.6751 4.50829 1.6751 5.30326V13.1164C1.6751 13.9116 1.67504 14.4575 1.70961 14.8805C1.74337 15.2935 1.80576 15.5155 1.88766 15.6763C2.07533 16.0446 2.37418 16.3443 2.74065 16.5311C2.90112 16.6129 3.12337 16.6754 3.53641 16.7092C3.95913 16.7437 4.5043 16.7437 5.29781 16.7437H18.7022C19.4955 16.7437 20.0403 16.7437 20.4627 16.7092C20.8752 16.6754 21.097 16.6129 21.2575 16.5311C21.6251 16.3438 21.9251 16.0439 22.1123 15.6763C22.1941 15.5157 22.2567 15.294 22.2904 14.8815C22.3249 14.4591 22.3249 13.9143 22.3249 13.121V5.29781ZM8.81423 4.28494C9.08636 4.13933 9.41674 4.15561 9.67358 4.32672L15.9525 8.51264C16.1854 8.66791 16.3258 8.92947 16.3258 9.20939C16.3258 9.4893 16.1854 9.75086 15.9525 9.90613L9.67358 14.0921C9.41674 14.2632 9.08636 14.2794 8.81423 14.1338C8.54202 13.9882 8.37184 13.7041 8.37184 13.3953V5.02347C8.37184 4.71472 8.54202 4.43062 8.81423 4.28494ZM10.0469 11.8301L13.9785 9.20939L10.0469 6.58774V11.8301ZM24 13.121C24 13.8866 24.0005 14.5113 23.9591 15.0177C23.9169 15.534 23.8268 16.0009 23.6048 16.4366C23.257 17.1193 22.7005 17.6758 22.0179 18.0236C21.5821 18.2456 21.1152 18.3357 20.5989 18.3779C20.0926 18.4193 19.4679 18.4188 18.7022 18.4188H5.29781C4.53192 18.4188 3.90685 18.4193 3.40015 18.3779C2.88379 18.3357 2.41699 18.2456 1.98123 18.0236C1.29771 17.6753 0.743411 17.1183 0.396064 16.4366C0.173876 16.0006 0.0831413 15.5338 0.0408785 15.0168C-0.000554495 14.5098 4.10332e-07 13.8839 4.10332e-07 13.1164V5.30326C4.10332e-07 4.53562 -0.000570502 3.90853 0.0408785 3.40106C0.0831299 2.8841 0.173923 2.41728 0.396064 1.98123C0.743873 1.29861 1.29861 0.743873 1.98123 0.396064C2.41728 0.173923 2.8841 0.0831299 3.40106 0.0408785C3.90853 -0.000570502 4.53562 4.10332e-07 5.30326 4.10332e-07H18.6977C19.4651 4.10332e-07 20.091 -0.000554565 20.598 0.0408785C21.115 0.0831413 21.5818 0.173876 22.0179 0.396064C22.6996 0.743411 23.2566 1.29771 23.6048 1.98123C23.8268 2.41699 23.9169 2.88379 23.9591 3.40015C24.0005 3.90685 24 4.53192 24 5.29781V13.121Z" fill="currentColor"/></svg>';
    }

    /** Icono SVG "copiar enlace". Hereda el color (currentColor). */
    public static function iconoCopiar(): string
    {
        return '<svg class="ico-svg" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<path d="M5.60328 4.15504C5.60348 1.85664 7.45992 0.000196182 9.75832 0H16.0347C16.1134 2.78423e-05 16.189 0.0123772 16.2608 0.0337437C16.5369 -0.0469334 16.8377 0.0260256 17.0442 0.232558L23.7674 6.95577C23.9163 7.10463 24 7.30703 24 7.51756V14.4542C24 16.6296 22.2329 18.3967 20.0575 18.3967C19.6191 18.3967 19.264 18.0417 19.264 17.6033C19.264 17.1649 19.6191 16.8099 20.0575 16.8098C21.3561 16.8098 22.4131 15.7528 22.4131 14.4542V8.31099H19.845C18.5402 8.31099 17.4174 8.10408 16.6566 7.34337C15.8959 6.58265 15.689 5.45975 15.689 4.15504V1.58687H9.75832C8.33673 1.58706 7.19035 2.73344 7.19015 4.15504C7.19015 4.59344 6.83512 4.94938 6.39672 4.94938C5.95831 4.94938 5.60328 4.59344 5.60328 4.15504ZM18.3967 16.9311C18.3967 19.2506 17.938 21.0807 16.7077 22.311C15.4775 23.5412 13.6473 24 11.3279 24H7.06886C4.74937 24 2.91926 23.5412 1.68901 22.311C0.458763 21.0807 3.36065e-05 19.2506 0 16.9311V12.6721C3.38416e-05 10.3527 0.458764 8.52254 1.68901 7.29229C2.91926 6.06205 4.74937 5.60332 7.06886 5.60328H10.4314L10.5098 5.60693C10.6914 5.62503 10.8621 5.70565 10.9922 5.83584L18.1642 13.0078C18.313 13.1565 18.3966 13.3582 18.3967 13.5686V16.9311ZM1.58687 16.9311C1.5869 19.094 2.02482 20.4015 2.81167 21.1883C3.59853 21.9752 4.90599 22.4131 7.06886 22.4131H11.3279C13.4907 22.4131 14.7982 21.9752 15.585 21.1883C16.3719 20.4015 16.8098 19.094 16.8098 16.9311V14.363H14.0173C12.6286 14.363 11.4496 14.1418 10.6539 13.3461C9.85816 12.5504 9.63703 11.3714 9.63703 9.98267V7.19015H7.06886C4.90599 7.19018 3.59853 7.6281 2.81167 8.41496C2.02481 9.20181 1.5869 10.5093 1.58687 12.6721V16.9311ZM11.2248 9.98267C11.2248 11.2835 11.4516 11.8985 11.7766 12.2234C12.1015 12.5484 12.7165 12.7752 14.0173 12.7752H15.6872L11.2248 8.31281V9.98267ZM17.2768 4.15504C17.2768 5.3718 17.4894 5.9308 17.7793 6.2207C18.0692 6.5106 18.6282 6.72321 19.845 6.72321H21.2905L17.2768 2.70953V4.15504Z" fill="currentColor"/></svg>';
    }

    /**
     * Icono del design system (SVG de la carpeta /Interface). Devuelve el SVG
     * ya normalizado: color heredado (currentColor), tamaño por CSS (.ico) y sin
     * IDs de clip que choquen al repetirse. Si el nombre no existe pero es un
     * "fa-…", cae a Font Awesome, así la migración es gradual y nada se rompe.
     */
    /**
     * Equivalencias de los iconos que ya se migraron.
     *
     * Cambiar el default en Config no basta: en cuanto alguien guarda Ajustes,
     * su config.json se queda con el nombre viejo y pisa el default para
     * siempre. Pasaba con los equipos y los estados de tarea, que en local
     * salían nuevos y en producción seguían en Font Awesome.
     *
     * Se traduce AL PINTAR y no se toca lo guardado: así cada entorno se
     * arregla solo al desplegar, sin migración ni entrar a Ajustes.
     */
    /** Colores que fueron default de un estado antes de la paleta del DS. */
    private const COLORES_JUBILADOS = [
        'pendiente' => ['#0b7ea8', '#0f6e92'],
        'progreso'  => ['#2b76f7'],
        'revision'  => ['#c26f0e'],
        'hecho'     => ['#2bb673'],
    ];

    private const ICONOS_MIGRADOS = [
        // Equipos
        'fa-code'                    => 'Desarrolladores',
        'fa-chart-line'              => 'Analistas',
        // Estados de tarea
        'fa-circle-dot'              => 'OctagonCheck',
        'fa-spinner'                 => 'RefreshCircle',
        'fa-magnifying-glass-chart'  => 'OctagonHelp',
        'fa-circle-check'            => 'WavyCheck',
    ];

    public static function icono(string $nombre, string $clase = ''): string
    {
        static $cache = [];
        $mapa = self::mapaIconos();
        $cls  = trim('ico ' . $clase);

        $nombre = self::ICONOS_MIGRADOS[$nombre] ?? $nombre;

        if (!isset($mapa[$nombre])) {
            // Compatibilidad: los iconos aún no migrados siguen siendo fa-*
            if (str_starts_with($nombre, 'fa-')) {
                return '<i class="fa-solid ' . e($nombre) . ($clase !== '' ? ' ' . e($clase) : '') . '"></i>';
            }
            return '';
        }
        if (!isset($cache[$nombre])) {
            $cache[$nombre] = self::normalizarSvg($mapa[$nombre]);
        }
        // Inyecta la clase en el <svg> (para tamaño/alineación desde CSS).
        return preg_replace('/<svg\b/', '<svg class="' . e($cls) . '"', $cache[$nombre], 1);
    }

    /** Mapa [nombreBase => rutaAbsoluta] de los SVG de /Interface (se cachea). */
    private static function mapaIconos(): array
    {
        static $mapa = null;
        if ($mapa !== null) {
            return $mapa;
        }
        $mapa = [];
        foreach (glob(__DIR__ . '/../iconos/*/*.svg') ?: [] as $f) {
            $mapa[basename($f, '.svg')] = $f;
        }
        return $mapa;
    }

    /** ¿Existe ese icono en el set del DS? */
    public static function hayIcono(string $nombre): bool
    {
        return isset(self::mapaIconos()[$nombre]);
    }

    /**
     * Nombres de todos los iconos del set, ordenados. La galería de ajustes
     * los saca de aquí en vez de una lista escrita a mano: al soltar un SVG
     * nuevo en admin/iconos aparece solo, sin tocar código.
     */
    public static function nombresIconos(): array
    {
        $nombres = array_keys(self::mapaIconos());
        sort($nombres, SORT_NATURAL | SORT_FLAG_CASE);
        return $nombres;
    }

    /**
     * Selector de ícono de proyecto.
     *
     * Enseña primero los elegidos en Ajustes, que son los de uso habitual, y
     * detrás de "Más íconos" el resto del set. Antes solo se veían los de
     * Ajustes: si el que querías no estaba, había que salir del formulario,
     * ir a Ajustes, marcarlo, guardar y volver.
     *
     * $actual entra siempre aunque no esté en la lista corta, para que un
     * proyecto viejo no pierda su ícono al abrir el formulario.
     */
    public static function selectorIcono(?string $actual = null): string
    {
        $cortos = Catalogo::iconosProyecto();
        if ($actual !== null && $actual !== '' && !in_array($actual, $cortos, true)) {
            array_unshift($cortos, $actual);
        }
        $resto = array_values(array_diff(self::nombresIconos(), $cortos));
        $marcado = $actual !== null && $actual !== '' ? $actual : ($cortos[0] ?? '');

        $opcion = function (string $ic, bool $extra) use ($marcado): string {
            return '<label' . ($extra ? ' class="icono-extra" hidden' : '') . '>'
                 . '<input type="radio" name="icono" value="' . e($ic) . '"'
                 . ($ic === $marcado ? ' checked' : '') . '>'
                 . self::icono($ic) . '</label>';
        };

        $html = '<div class="icon-picker">';
        foreach ($cortos as $ic) $html .= $opcion($ic, false);
        foreach ($resto  as $ic) $html .= $opcion($ic, true);
        $html .= '</div>';

        if ($resto) {
            $html .= '<button type="button" class="btn-outline btn-meca btn-sm btn-azul icon-mas"'
                   . ' data-mas="Más íconos (' . count($resto) . ')" data-menos="Ver menos">'
                   . '<i class="fa-solid fa-chevron-down"></i> '
                   . '<span class="icon-mas-txt">Más íconos (' . count($resto) . ')</span></button>';
        }
        return $html;
    }

    /**
     * Deja un SVG listo para incrustar varias veces en la página:
     *  - el color pasa a currentColor (para heredar del texto/estado),
     *  - se quitan width/height del <svg> (el tamaño lo pone .ico por CSS),
     *  - se eliminan <defs> y clip-path (evita IDs repetidos que colisionan).
     */
    private static function normalizarSvg(string $ruta): string
    {
        $svg = @file_get_contents($ruta);
        if ($svg === false) {
            return '';
        }
        // Color fijo → currentColor
        $svg = str_ireplace(['fill="#334155"', 'fill="#292D32"'], 'fill="currentColor"', $svg);
        // Quita <defs>…</defs> y los clip-path (solo unos pocos iconos los traen)
        $svg = preg_replace('/<defs>.*?<\/defs>/s', '', $svg);
        $svg = preg_replace('/\sclip-path="[^"]*"/', '', $svg);
        // Quita width/height del <svg> de apertura (conserva viewBox)
        $svg = preg_replace_callback('/<svg\b[^>]*>/', function ($m) {
            return preg_replace('/\s(width|height)="[^"]*"/', '', $m[0]);
        }, $svg, 1);
        return trim($svg);
    }

    /** Texto de ayuda bajo el selector de asignado. */
    public static function ayudaEquipoProyecto(?array $equipoProyecto): string
    {
        if ($equipoProyecto === null) {
            return 'Este proyecto está abierto a todo el equipo. Definí sus participantes en «Editar» para acortar esta lista.';
        }
        $n = count($equipoProyecto);
        return 'Solo se listan ' . $n . ' participante' . ($n === 1 ? '' : 's') . ' del proyecto.';
    }

    /**
     * Atajos para la fecha de inicio ("la otra semana", etc.) y aviso en vivo
     * de cuántos días dura la tarea. Los resuelve admin.js.
     */
    public static function atajosFecha(): string
    {
        $atajos = [
            ['dias' => 0,  'label' => 'Hoy'],
            ['dias' => 1,  'label' => 'Mañana'],
            ['lunes' => 1, 'label' => 'El lunes que viene'],
            ['dias' => 14, 'label' => 'En dos semanas'],
        ];
        $html = '<div class="wz-atajos" data-atajos-fecha><span class="wz-atajos-tit">Empezar</span>';
        foreach ($atajos as $a) {
            $attr = isset($a['lunes']) ? 'data-lunes="1"' : 'data-dias="' . (int)$a['dias'] . '"';
            $html .= '<button type="button" class="chip-atajo" ' . $attr . '>' . e($a['label']) . '</button>';
        }
        $html .= '<button type="button" class="chip-atajo chip-atajo-off" data-limpiar="1">Sin fecha</button></div>';
        return $html . '<p class="campo-ayuda wz-duracion"></p>';
    }


    /* ---------- Varios ---------- */

    /** Barra de progreso con gradiente del proyecto. */
    public static function progreso(int $pct, string $gradiente): string
    {
        return '<div class="progress-wrap">
                  <div class="progress-bar" style="width:' . $pct . '%;background:' . $gradiente . '"></div>
                </div>
                <span class="progress-num">' . $pct . '%</span>';
    }

    /** Tarjeta de estadistica para el dashboard. */
    public static function stat(string $icono, string $color, string $num, string $label): string
    {
        return '<div class="stat-card card-base" style="--pc:' . $color . '">
                  <div class="stat-icon"><i class="fa-solid ' . $icono . '"></i></div>
                  <div><b class="font-display">' . $num . '</b><small>' . e($label) . '</small></div>
                </div>';
    }

    /** Estado vacio ilustrado. */
    public static function vacio(string $icono, string $titulo, string $texto): string
    {
        return '<div class="empty-state">
                  <div class="empty-icon"><i class="fa-solid ' . $icono . '"></i></div>
                  <h3 class="font-display">' . e($titulo) . '</h3>
                  <p>' . e($texto) . '</p>
                </div>';
    }
}
