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
        $proyectos = (new ProyectoRepo())->todos();
        $marca = Config::all();
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
<link rel="stylesheet" href="../assets/mecapacito.css">
<link rel="stylesheet" href="assets/admin.css">
<?php self::estilosConfig(); ?>
</head>
<body class="admin-body" data-limite-subida="<?= limiteSubidaBytes() ?>">
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

  <nav class="sidebar-nav">
    <span class="sidebar-label">General</span>
    <a href="index.php" class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
      <i class="fa-solid fa-table-columns"></i> <span class="truncate">Dashboard</span>
    </a>
    <a href="ajustes.php" class="sidebar-link <?= $activo === 'ajustes' ? 'active' : '' ?>" title="Ajustes">
      <i class="fa-solid fa-sliders"></i> <span class="truncate">Ajustes</span>
    </a>

    <span class="sidebar-label">Equipos</span>
    <?php foreach (Catalogo::equipos() as $ek => [$eLabel, $eIcono]): ?>
    <a href="equipo.php?e=<?= e($ek) ?>" class="sidebar-link <?= $activo === 'equipo-' . $ek ? 'active' : '' ?>" title="<?= e($eLabel) ?>">
      <i class="fa-solid <?= e($eIcono) ?>"></i> <span class="truncate"><?= e($eLabel) ?></span>
    </a>
    <?php endforeach; ?>

    <span class="sidebar-label">Proyectos</span>
    <?php foreach ($proyectos as $p): ?>
    <a href="proyecto.php?id=<?= (int)$p['id'] ?>"
       class="sidebar-link <?= $activo === 'proyecto-' . $p['id'] ? 'active' : '' ?>" title="<?= e($p['nombre']) ?>">
      <i class="fa-solid <?= e($p['icono']) ?>" style="color:<?= ProyectoRepo::colorBase($p) === '#2D3E50' ? '#40CFFF' : e(ProyectoRepo::colorBase($p)) ?>"></i>
      <span class="truncate"><?= e($p['nombre']) ?></span>
    </a>
    <?php endforeach; ?>

    <a href="index.php#nuevo" class="sidebar-link sidebar-link-new" onclick="sessionStorage.setItem('abrirNuevo','1')" title="Nuevo proyecto">
      <i class="fa-solid fa-plus"></i> <span class="truncate">Nuevo proyecto</span>
    </a>
  </nav>

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
        ?>
</main>
<script src="assets/admin.js"></script>
</body>
</html>
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

    public static function flashes(): void
    {
        if (empty($_SESSION['flash'])) return;
        foreach ($_SESSION['flash'] as [$tipo, $msg]) {
            $icono = $tipo === 'success' ? 'fa-circle-check' : ($tipo === 'error' ? 'fa-circle-xmark' : 'fa-circle-info');
            echo '<div class="toast toast-' . e($tipo) . ' toast-float"><i class="fa-solid ' . $icono . '"></i> ' . e($msg) . '</div>';
        }
        unset($_SESSION['flash']);
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
     */
    public static function select(string $name, array $opciones, string $valor = '', bool $auto = false, string $clase = ''): string
    {
        $attrs = $auto ? ' onchange="this.form.submit()"' : '';
        $html = '<select name="' . e($name) . '" class="select-meca ' . e($clase) . '"' . $attrs . '>';
        foreach ($opciones as $k => $v) {
            $label = is_array($v) ? $v[0] : $v;
            $sel = (string)$k === (string)$valor ? ' selected' : '';
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
