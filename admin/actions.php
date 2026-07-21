<?php
/**
 * Punto unico de entrada para todas las acciones POST del panel.
 * Cada accion valida, ejecuta sobre el repositorio y redirige con flash.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('index.php');
}

// Si el envio supero post_max_size, PHP descarta TODO el formulario en silencio
// (por eso "no guarda ni el nombre"). Avisamos claramente en vez de ignorarlo.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    redirigir(
        paginaOrigen(),
        'El envío supera el límite del servidor (' . ini_get('post_max_size') . '), probablemente por una foto muy pesada. No se guardó nada.',
        'error'
    );
}

$accion = $_POST['accion'] ?? '';
$proyectos = new ProyectoRepo();
$miembros  = new MiembroRepo();
$tareas    = new TareaRepo();

/**
 * Si la tarea quedo asignada a alguien nuevo, le envia el correo.
 * Devuelve [sufijo para el mensaje flash, tipo de toast].
 */
function notificarSiAsignada(array $tarea, int $asignadoNuevo, int $asignadoAntes, ProyectoRepo $proyectos, MiembroRepo $miembros): array
{
    if ($asignadoNuevo === 0 || $asignadoNuevo === $asignadoAntes) {
        return ['', 'success'];
    }
    $m = $miembros->buscar($asignadoNuevo);
    $p = $proyectos->buscar((int)($tarea['proyecto_id'] ?? 0));
    if (!$m || !$p) {
        return ['', 'success'];
    }
    $resultado = Mailer::notificarAsignacion($tarea, $m, $p);
    if ($resultado === true) {
        return [' 📧 ' . $m['nombre'] . ' fue notificado por correo.', 'success'];
    }
    if (is_string($resultado)) {
        return [' Pero el correo a ' . $m['nombre'] . ' falló: ' . $resultado, 'error'];
    }
    return ['', 'success']; // sin correo registrado o notificaciones apagadas
}

switch ($accion) {

    /* ---------- Proyectos ---------- */

    case 'proyecto_crear':
        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir('index.php', 'El nombre del proyecto es obligatorio.', 'error');
        }
        $p = $proyectos->crear($_POST);
        redirigir('proyecto.php?id=' . $p['id'], 'Proyecto «' . $p['nombre'] . '» creado.');

    case 'proyecto_editar':
        $id = (int)($_POST['id'] ?? 0);
        if (!$proyectos->buscar($id)) {
            redirigir('index.php', 'Proyecto no encontrado.', 'error');
        }
        $proyectos->actualizar($id, [
            'nombre'      => trim($_POST['nombre'] ?? ''),
            'descripcion' => trim($_POST['descripcion'] ?? ''),
            'repo'        => trim($_POST['repo'] ?? ''),
            'estado'      => $_POST['estado'] ?? 'activo',
            'icono'       => $_POST['icono'] ?? 'fa-rocket',
            'color'       => Catalogo::colorEntrada($_POST),
        ]);
        redirigir('proyecto.php?id=' . $id, 'Proyecto actualizado.');

    case 'proyecto_estado':
        $id = (int)($_POST['id'] ?? 0);
        $proyectos->actualizar($id, ['estado' => $_POST['estado'] ?? 'activo']);
        redirigir($_POST['volver'] ?? 'index.php', 'Estado del proyecto actualizado.');

    case 'proyecto_eliminar':
        $id = (int)($_POST['id'] ?? 0);
        $p = $proyectos->buscar($id);
        $proyectos->eliminar($id);
        redirigir('index.php', 'Proyecto «' . ($p['nombre'] ?? '') . '» eliminado junto con sus tareas.');

    /* ---------- Tareas ---------- */

    case 'tarea_crear':
        $pid = (int)($_POST['proyecto_id'] ?? 0);
        if (trim($_POST['titulo'] ?? '') === '') {
            redirigir('proyecto.php?id=' . $pid, 'El título de la tarea es obligatorio.', 'error');
        }
        $t = $tareas->crear($_POST);
        [$msg, $tipo] = notificarSiAsignada($t, (int)$t['asignado_id'], 0, $proyectos, $miembros);
        redirigir('proyecto.php?id=' . $pid, 'Tarea creada.' . $msg, $tipo);

    case 'tarea_estado':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if ($t) {
            $tareas->actualizar((int)$t['id'], ['estado' => $_POST['estado'] ?? 'pendiente']);
            redirigir('proyecto.php?id=' . $t['proyecto_id'], 'Estado actualizado.');
        }
        redirigir('index.php', 'Tarea no encontrada.', 'error');

    case 'tarea_editar':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if (!$t) {
            redirigir('index.php', 'Tarea no encontrada.', 'error');
        }
        $asignadoAntes = (int)($t['asignado_id'] ?? 0);
        $tareas->actualizar((int)$t['id'], [
            'titulo'       => trim($_POST['titulo'] ?? ''),
            'descripcion'  => trim($_POST['descripcion'] ?? ''),
            'prioridad'    => $_POST['prioridad'] ?? 'media',
            'estado'       => $_POST['estado'] ?? 'pendiente',
            'asignado_id'  => (int)($_POST['asignado_id'] ?? 0),
            'fecha_limite' => $_POST['fecha_limite'] ?? '',
        ]);
        $tActual = $tareas->buscar((int)$t['id']);
        [$msg, $tipo] = notificarSiAsignada($tActual, (int)$tActual['asignado_id'], $asignadoAntes, $proyectos, $miembros);
        redirigir('proyecto.php?id=' . $t['proyecto_id'], 'Tarea actualizada.' . $msg, $tipo);

    case 'tarea_eliminar':
        $t = $tareas->buscar((int)($_POST['id'] ?? 0));
        if ($t) {
            $tareas->eliminar((int)$t['id']);
            redirigir('proyecto.php?id=' . $t['proyecto_id'], 'Tarea eliminada.');
        }
        redirigir('index.php', 'Tarea no encontrada.', 'error');

    /* ---------- Miembros ---------- */

    case 'miembro_crear':
        if (trim($_POST['nombre'] ?? '') === '') {
            redirigir('equipo.php', 'El nombre del colaborador es obligatorio.', 'error');
        }
        $datos = $_POST;
        $datos['foto'] = guardarFoto('foto');
        $m = $miembros->crear($datos);
        redirigir('equipo.php?e=' . $m['equipo'], '¡' . $m['nombre'] . ' se unió al equipo!');

    case 'miembro_editar':
        $id = (int)($_POST['id'] ?? 0);
        $m = $miembros->buscar($id);
        if (!$m) {
            redirigir('equipo.php', 'Colaborador no encontrado.', 'error');
        }
        $cambios = [
            'nombre'   => trim($_POST['nombre'] ?? ''),
            'rol'      => trim($_POST['rol'] ?? ''),
            'git_user' => ltrim(trim($_POST['git_user'] ?? ''), '@'),
            'email'    => filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
            'color'    => Catalogo::colorEntrada($_POST),
            'equipo'   => MiembroRepo::equipoValido($_POST['equipo'] ?? ''),
        ];
        $foto = guardarFoto('foto');
        if ($foto !== '') {
            if (!empty($m['foto']) && file_exists(__DIR__ . '/' . $m['foto'])) {
                @unlink(__DIR__ . '/' . $m['foto']);
            }
            $cambios['foto'] = $foto;
        }
        $miembros->actualizar($id, $cambios);
        redirigir('equipo.php?e=' . $cambios['equipo'], 'Colaborador actualizado.');

    case 'miembro_eliminar':
        $id = (int)($_POST['id'] ?? 0);
        $m = $miembros->buscar($id);
        $miembros->eliminar($id);
        redirigir('equipo.php', ($m['nombre'] ?? 'Colaborador') . ' fue retirado del equipo.');

    /* ---------- Ajustes (parametrizacion) ---------- */

    case 'config_guardar':
        $def = Config::defaults();
        $hex = fn(string $v, string $fallback) =>
            preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $fallback;
        $fa = fn(string $v, string $fallback) =>
            preg_match('/^fa-[a-z0-9-]+$/', trim($v)) ? trim($v) : $fallback;

        // Clave interna a partir de la etiqueta (para entradas nuevas)
        $slug = function (string $label): string {
            $s = strtolower(trim($label));
            $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
            $s = preg_replace('/[^a-z0-9]+/', '-', $s);
            return trim($s, '-') ?: uniqid('item');
        };

        /** Lee un catalogo de filas del POST: [['key','icono','label','color'?,'final'?], ...] */
        $leerCatalogo = function (string $campo, string $iconoDef, bool $conColor, bool $conFinal) use ($hex, $fa, $slug): array {
            $out = [];
            foreach ((array)($_POST[$campo] ?? []) as $fila) {
                if (!is_array($fila)) continue;
                $label = trim($fila['label'] ?? '');
                if ($label === '') continue;
                $key = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($fila['key'] ?? '')));
                if ($key === '') $key = $slug($label);
                while (isset($out[$key])) $key .= '-2';
                $item = ['label' => $label, 'icono' => $fa($fila['icono'] ?? '', $iconoDef)];
                if ($conColor) $item['color'] = $hex($fila['color'] ?? '', '#2B76F7');
                if ($conFinal) $item['final'] = !empty($fila['final']);
                $out[$key] = $item;
            }
            return $out;
        };

        $estados = $leerCatalogo('et', 'fa-circle-dot', true, true) ?: $def['estados_tarea'];
        // Siempre debe existir al menos un estado "final" para calcular el avance
        if (!array_filter($estados, fn($v) => !empty($v['final']))) {
            $estados[array_key_last($estados)]['final'] = true;
        }
        $prioridades     = $leerCatalogo('pr', 'fa-equals', true, false) ?: $def['prioridades'];
        $estadosProyecto = $leerCatalogo('ep', 'fa-flag', false, false) ?: $def['estados_proyecto'];
        $equiposCat      = $leerCatalogo('eqs', 'fa-users', false, false) ?: $def['equipos'];

        $lineas = fn(string $texto) => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $texto))));
        $iconos = array_values(array_filter($lineas($_POST['iconos'] ?? ''), fn($i) => preg_match('/^fa-[a-z0-9-]+$/', $i)));

        $correoPost = (array)($_POST['correo'] ?? []);
        $correo = [
            'activo'    => !empty($correoPost['activo']),
            'modo'      => in_array($correoPost['modo'] ?? '', ['smtp', 'gmail_api'], true) ? $correoPost['modo'] : 'smtp',
            'host'      => trim($correoPost['host'] ?? '') ?: $def['correo']['host'],
            'puerto'    => (int)($correoPost['puerto'] ?? 0) ?: $def['correo']['puerto'],
            'usuario'   => trim($correoPost['usuario'] ?? ''),
            'clave'     => trim($correoPost['clave'] ?? ''),
            'remitente' => trim($correoPost['remitente'] ?? '') ?: $def['correo']['remitente'],
            'url_panel' => trim($correoPost['url_panel'] ?? ''),
            'client_id'     => trim($correoPost['client_id'] ?? ''),
            'client_secret' => trim($correoPost['client_secret'] ?? ''),
            'refresh_token' => trim($correoPost['refresh_token'] ?? ''),
        ];

        // Roles: filas del catalogo (rl[]) o, por compatibilidad, textarea 'roles'
        $roles = array_values(array_filter(array_map('trim', (array)($_POST['rl'] ?? []))));
        if (!$roles) {
            $roles = $lineas($_POST['roles'] ?? '');
        }

        Config::guardar([
            'titulo'           => trim($_POST['titulo'] ?? '') ?: $def['titulo'],
            'subtitulo'        => trim($_POST['subtitulo'] ?? '') ?: $def['subtitulo'],
            'color_secundario' => $hex($_POST['color_secundario'] ?? '', $def['color_secundario']),
            'color_acento'     => $hex($_POST['color_acento'] ?? '', $def['color_acento']),
            'estados_tarea'    => $estados,
            'prioridades'      => $prioridades,
            'estados_proyecto' => $estadosProyecto,
            'equipos'          => $equiposCat,
            'iconos'           => $iconos ?: $def['iconos'],
            'roles'            => $roles ?: $def['roles'],
            'correo'           => $correo,
        ]);

        // Remapear datos existentes: si se elimino un estado/prioridad en uso,
        // las tareas y proyectos afectados pasan a la primera opcion del catalogo.
        $ek = array_keys($estados);
        $pk = array_keys($prioridades);
        $epk = array_keys($estadosProyecto);
        $storeTareas = new JsonStore('tareas');
        foreach ($storeTareas->all() as $t) {
            $cambios = [];
            if (!in_array($t['estado'] ?? '', $ek, true))    $cambios['estado'] = $ek[0];
            if (!in_array($t['prioridad'] ?? '', $pk, true)) $cambios['prioridad'] = $pk[0];
            if ($cambios) $storeTareas->update((int)$t['id'], $cambios);
        }
        $storeProyectos = new JsonStore('proyectos');
        foreach ($storeProyectos->all() as $p) {
            if (!in_array($p['estado'] ?? '', $epk, true)) {
                $storeProyectos->update((int)$p['id'], ['estado' => $epk[0]]);
            }
        }
        $eqk = array_keys($equiposCat);
        $storeMiembros = new JsonStore('miembros');
        foreach ($storeMiembros->all() as $m) {
            if (!in_array($m['equipo'] ?? '', $eqk, true)) {
                $storeMiembros->update((int)$m['id'], ['equipo' => $eqk[0]]);
            }
        }
        redirigir('ajustes.php', 'Ajustes guardados. ¡El panel ya usa tu configuración!');

    case 'config_reset':
        Config::restaurar();
        redirigir('ajustes.php', 'Ajustes restaurados a los valores por defecto.');

    case 'correo_prueba':
        $para = filter_var(trim($_POST['para'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$para) {
            redirigir('ajustes.php', 'Escribe un correo de destino válido para la prueba.', 'error');
        }
        if (!Mailer::listo()) {
            redirigir('ajustes.php', 'Primero guarda la configuración de correo (activo, usuario y contraseña).', 'error');
        }
        $r = Mailer::enviar($para, '✅ Prueba de correo — Panel Mecapacito',
            '<p style="font-family:Arial;font-size:15px;">¡Funciona! 🎉 El panel Mecapacito ya puede enviar notificaciones por correo.</p>');
        if ($r === true) {
            redirigir('ajustes.php', 'Correo de prueba enviado a ' . $para . '. ¡Revisa la bandeja!');
        }
        redirigir('ajustes.php', 'El envío falló: ' . $r, 'error');

    default:
        redirigir('index.php', 'Acción no reconocida.', 'error');
}
