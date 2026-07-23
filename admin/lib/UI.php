<?php
/**
 * UI - componentes visuales reutilizables del panel.
 * Todos son metodos estaticos que devuelven/imprimen HTML.
 */
require_once __DIR__ . '/Models.php';

class UI
{
    /* ---------- Layout ---------- */

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
<title><?= e($titulo) ?> · Mecapacito Admin</title>
<link rel="icon" type="image/png" href="../assets/mecapacito-logo.png">
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('../assets/mecapacito.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/admin.css') ?>">
<?php self::estilosConfig(); ?>
</head>
<body class="admin-body" data-limite-subida="<?= limiteSubidaBytes() ?>" data-rol="<?= e(Auth::rol()) ?>">
<aside class="sidebar">
  <div class="sidebar-top">
    <a href="index.php" class="sidebar-brand">
      <img src="../assets/mecapacito-logo.png" alt="<?= e($marca['titulo']) ?>">
      <div>
        <strong><?= e($marca['titulo']) ?></strong>
        <span><?= e($marca['subtitulo']) ?></span>
      </div>
    </a>
    <button type="button" id="sidebar-toggle" class="sidebar-toggle" title="Ocultar / mostrar menú">
      <i class="fa-solid fa-angles-left"></i>
    </button>
  </div>

  <!-- Filtro global "Ver como" (solo administradores) -->
  <?php if (puedeVerComo()): ?>
  <div class="ver-como <?= $verComo ? 'activo' : '' ?>">
    <button type="button" class="vc-abrir" onclick="document.getElementById('dlg-ver-como').showModal()"
            title="<?= $verComo ? 'Viendo solo lo de ' . e($verComo['nombre']) : 'Filtrar todo el panel por una persona' ?>">
      <?php if ($verComo): ?>
        <?= self::avatar($verComo, 26) ?>
        <span class="truncate">Viendo a <b><?= e(explode(' ', $verComo['nombre'])[0]) ?></b></span>
      <?php else: ?>
        <i class="fa-regular fa-eye"></i> <span class="truncate">Ver como...</span>
      <?php endif; ?>
    </button>
    <?php if ($verComo): ?>
    <a class="vc-quitar" href="<?= e(urlConVerComo(0)) ?>" title="Volver a ver todo"><i class="fa-solid fa-xmark"></i></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="sidebar-nav">
    <span class="sidebar-label">General</span>
    <a href="index.php" class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
      <i class="fa-solid fa-table-columns"></i> <span class="truncate">Dashboard</span>
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
        <i class="fa-solid fa-folder-open"></i>
        <span class="truncate">Proyectos</span>
        <?php if ($proyectos): ?><span class="nav-grupo-n"><?= count($proyectos) ?></span><?php endif; ?>
      </button>

      <div class="nav-grupo-items">
        <div class="nav-grupo-cab"><i class="fa-solid fa-folder-open"></i> Proyectos</div>
        <?php foreach ($proyectos as $p): ?>
        <a href="proyecto.php?id=<?= (int)$p['id'] ?>"
           class="sidebar-link <?= $activo === 'proyecto-' . $p['id'] ? 'active' : '' ?>" title="<?= e($p['nombre']) ?>">
          <i class="fa-solid <?= e($p['icono']) ?>" style="color:<?= ProyectoRepo::colorBase($p) === '#2D3E50' ? '#40CFFF' : e(ProyectoRepo::colorBase($p)) ?>"></i>
          <span class="truncate"><?= e($p['nombre']) ?></span>
        </a>
        <?php endforeach; ?>
        <?php if (!$proyectos): ?>
        <p class="nav-grupo-vacio">Todavía no hay proyectos.</p>
        <?php endif; ?>

        <a href="index.php#nuevo" class="sidebar-link sidebar-link-new solo-admin" onclick="sessionStorage.setItem('abrirNuevo','1')" title="Nuevo proyecto">
          <i class="fa-solid fa-plus"></i> <span class="truncate">Nuevo proyecto</span>
        </a>
      </div>
    </div>

    <span class="sidebar-label">Equipos</span>
    <?php foreach (Catalogo::equipos() as $ek => [$eLabel, $eIcono]): ?>
    <a href="equipo.php?e=<?= e($ek) ?>" class="sidebar-link <?= $activo === 'equipo-' . $ek ? 'active' : '' ?>" title="<?= e($eLabel) ?>">
      <i class="fa-solid <?= e($eIcono) ?>"></i> <span class="truncate"><?= e($eLabel) ?></span>
    </a>
    <?php endforeach; ?>

    <?php if (Auth::esAdmin()): ?>
    <span class="sidebar-label">Configuración</span>
    <a href="ajustes.php" class="sidebar-link <?= $activo === 'ajustes' ? 'active' : '' ?>" title="Ajustes">
      <i class="fa-solid fa-sliders"></i> <span class="truncate">Ajustes</span>
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
      <a href="perfil.php" class="sidebar-link <?= $activo === 'perfil' ? 'active' : '' ?>">
        <i class="fa-solid fa-id-badge"></i> <span class="truncate">Mi perfil</span>
      </a>
      <form method="post" action="actions.php" class="cuenta-form">
        <input type="hidden" name="accion" value="auth_logout">
        <button class="sidebar-link cuenta-salir">
          <i class="fa-solid fa-right-from-bracket"></i> <span class="truncate">Cerrar sesión</span>
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
    <span><i class="fa-solid fa-code"></i> Equipo dev</span>
  </div>
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
        foreach ($cfg['estados_tarea'] as $k => $v) {
            $color = $v['color'] ?? '#64748b';
            $esDefault = isset($def['estados_tarea'][$k]) && strcasecmp($color, $def['estados_tarea'][$k]['color']) === 0;
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
        $attrs = $auto ? ' onchange="this.form.submit()"' : '';
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
            $n = 'repos[' . $idx . ']';
            return '<div class="repo-fila">'
                . '<select class="select-meca repo-tipo" name="' . $n . '[tipo]" data-ms="1">' . $opts . '</select>'
                . '<input class="input-meca repo-nombre" name="' . $n . '[nombre]" maxlength="60" value="' . e($nombre) . '" placeholder="Nombre (ej. Sede Norte)">'
                . '<input class="input-meca repo-url" type="url" name="' . $n . '[url]" value="' . e($r['url'] ?? '') . '" placeholder="https://github.com/…">'
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
            . '<button type="button" class="btn-outline btn-meca btn-sm repo-agregar">'
            . '<i class="fa-solid fa-plus"></i> Agregar repositorio</button>'
            . $plantilla
            . '<small class="campo-ayuda">Elige el tipo y, si tienes varios del mismo (p. ej. dos instituciones), ponle un nombre para distinguirlos.</small>'
            . '</div>';
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
