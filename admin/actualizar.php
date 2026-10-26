<?php
/**
 * Actualizar el panel después de subir una versión nueva: UN solo comando.
 *
 *   php admin/actualizar.php                 deja todo listo (se puede repetir sin riesgo)
 *   php admin/actualizar.php --seco          solo dice qué haría, sin escribir nada
 *   php admin/actualizar.php --openai=sk-… --gemini=AIza…
 *                                            además guarda esas claves de IA (cifradas)
 *
 * Las claves también se leen de las variables de entorno OPENAI_API_KEY y
 * GEMINI_API_KEY. Nunca se escriben en este archivo ni en el repositorio.
 *
 * Sin terminal (hosting solo por FTP): entra como administrador a
 * admin/actualizar.php y pulsa «Actualizar ahora». Hace lo mismo.
 *
 * Qué hace, en orden:
 *  1. Revisa que el servidor tenga lo necesario (PHP 8, SQLite, OpenSSL, cURL, zlib, mbstring).
 *  2. Revisa que estén subidos todos los archivos de esta versión, y que no
 *     haya quedado alguno viejo (los que faltan o están desactualizados, los nombra).
 *  3. Revisa los permisos de escritura (data/, uploads/) y que data/ siga cerrada a la web.
 *  4. Crea las tablas nuevas: notificaciones, pedidos entre equipos y consumo de IA.
 *  5. Semilla de IA: modelos con sus precios de referencia y qué modelo usa cada
 *     función (con su respaldo), sin pisar lo que ya se haya cambiado en la
 *     Consola de IA; crea la llave con la que se cifran las claves.
 *  6. Resume lo que conviene revisar: portada de cada proyecto, quién no tiene
 *     teléfono (se le pedirá al entrar) y qué proyectos tienen PO de relevo.
 *  7. Vacía la caché de PHP (OPcache) para que el servidor use los archivos nuevos.
 */
$esCli = PHP_SAPI === 'cli';
require_once __DIR__ . '/lib/bootstrap.php';
if (!$esCli) Auth::requiereAdmin();

/* ---------- Registro de lo que va pasando ---------- */
$pasos = [];          // [titulo, [[nivel, texto], ...]]
$hayError = false;
function paso(string $titulo): void { global $pasos; $pasos[] = [$titulo, []]; }
function nota(string $nivel, string $texto): void
{
    global $pasos, $hayError;
    $pasos[count($pasos) - 1][1][] = [$nivel, $texto];
    if ($nivel === 'error') $hayError = true;
}

/* ---------- Opciones ---------- */
$opc = ['seco' => false, 'openai' => '', 'gemini' => ''];
if ($esCli) {
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--seco' || $a === '--dry-run') $opc['seco'] = true;
        elseif (preg_match('/^--(openai|gemini)=(.+)$/', $a, $mm)) $opc[$mm[1]] = trim($mm[2]);
    }
    $opc['openai'] = $opc['openai'] ?: trim((string)getenv('OPENAI_API_KEY'));
    $opc['gemini'] = $opc['gemini'] ?: trim((string)getenv('GEMINI_API_KEY'));
}
$ejecutar = $esCli || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

function actualizarPanel(array $opc): void
{
    $seco = $opc['seco'];
    $dir  = __DIR__;

    /* 1. Servidor */
    paso('Servidor');
    version_compare(PHP_VERSION, '8.0.0', '>=')
        ? nota('ok', 'PHP ' . PHP_VERSION)
        : nota('error', 'PHP ' . PHP_VERSION . ': el panel necesita PHP 8.0 o más nuevo.');
    $ext = [
        'pdo_sqlite' => ['error', 'la base de datos del panel (data/panel.sqlite)'],
        'openssl'    => ['aviso', 'cifrar las claves de IA y conectar el correo InnoTech (sin él no aparece el correo)'],
        'dom'        => ['aviso', 'mostrar los correos con su formato (sin él se leen como texto)'],
        'curl'       => ['aviso', 'llamar a OpenAI y Gemini (sin él se usa un método más lento)'],
        'zlib'       => ['aviso', 'generar los reportes en PowerPoint y Word comprimidos'],
        'mbstring'   => ['error', 'textos con tildes y eñes'],
    ];
    foreach ($ext as $e => [$nivel, $para]) {
        extension_loaded($e) ? nota('ok', 'Extensión ' . $e) : nota($nivel, 'Falta la extensión ' . $e . ': se usa para ' . $para . '.');
    }

    /* 2. Archivos de esta versión */
    paso('Archivos de esta versión');
    $archivos = [
        'actualizar.php', 'ia.php', 'notificaciones.php', 'pedidos.php', 'reporteria.php', '.htaccess',
        'assets/ds.css', 'assets/ds.js', 'assets/ia.js', 'assets/admin.css', 'assets/admin.js',
        'assets/fonts/Inter-Variable.woff2', 'assets/fonts/PlusJakartaSans-Variable.woff2',
        'lib/IA.php', 'lib/IAFunciones.php', 'lib/Notificaciones.php', 'lib/Pedidos.php',
        'lib/Reporte.php', 'lib/ReporteDocx.php', 'lib/ReportePptx.php', 'lib/reporte_documento.php',
        'lib/reporteria/plantilla-pptx.json', 'lib/reporteria/plantilla-docx.json', 'lib/reporteria/semilla.json',
        'lib/reporteria/logo-color.png', 'lib/reporteria/logo-blanco.png',
        'iconos/OfficeEditing/Reporteria.svg', 'iconos/DS/ds-requerimiento.svg',
        'lib/Correo.php', 'lib/correo_vista.php', 'assets/entrada.js',
        'lib/Push.php', 'sw.js', 'manifest.webmanifest', 'cron_correo.php',
        'iconos/pwa/icono-192.png', 'iconos/pwa/icono-512.png', 'iconos/pwa/icono-maskable-512.png',
        'iconos/pwa/apple-touch-180.png', 'iconos/pwa/insignia-96.png',
        'tutoriales.php', 'lib/Tutoriales.php', 'lib/ReqCorreo.php', 'lib/ReqSeguimiento.php', 'arreglar_tildes.php', 'lib/releases_ui.php', 'lib/CPanel.php', 'buzones.php', 'assets/buzones.js', 'lib/CorreoCopias.php',
        'iconos/DS/ds-programadores.svg', 'iconos/DS/ds-analistas.svg',
        'lib/Whatsapp.php', 'cron_whatsapp.php',
        'correo.php', 'manifest-correo.webmanifest',
        'iconos/pwa/correo-192.png', 'iconos/pwa/correo-512.png', 'iconos/pwa/correo-apple-180.png', 'iconos/pwa/correo-maskable-512.png', 'iconos/pwa/app-correo.svg', 'iconos/ilustraciones/ia-mascota.svg',
        'iconos/ilustraciones/equipo-programadores.svg', 'iconos/ilustraciones/equipo-analistas.svg',
        'equipos.php', 'iconos/DS/ds-disenadores.svg', 'iconos/ilustraciones/equipo-disenadores.svg',
        'iconos/Equipos/eq-programacion.svg', 'iconos/Equipos/eq-analistas.svg', 'iconos/Equipos/eq-disenadores.svg',
        'iconos/Archivos/adj-pdf.svg', 'iconos/Archivos/adj-excel.svg', 'iconos/Archivos/adj-word.svg', 'iconos/Archivos/adj-ppt.svg', 'iconos/Archivos/adj-imagen.svg', 'iconos/Archivos/adj-otro.svg', 'iconos/ilustraciones/correo-bandeja.svg',
        'lib/ZoomDrive.php', 'zoom_drive.php', 'cron_zoom_drive.php', 'lib/TareasCorreo.php',
        'lib/Releases.php', 'releases.php', 'assets/releases.js', 'seed_releases.php', 'semillas/releases-siau-ube-v08.1.json', 'semillas/.htaccess',
        'lib/Cronograma.php', 'cron_deploys.php', 'lib/GitLab.php', 'lib/deploy_tira.php', 'deploys.php',
    ];
    $faltan = array_values(array_filter($archivos, fn($f) => !is_file($dir . '/' . $f)));
    $faltan ? nota('error', 'Faltan por subir: ' . implode(', ', $faltan) . '.') : nota('ok', count($archivos) . ' archivos principales en su lugar');

    $nNexo = count(glob($dir . '/iconos/nexo/*.webp') ?: []);
    $nNexo >= 40 ? nota('ok', $nNexo . ' poses de Nexo (iconos/nexo)') : nota('error', 'Solo hay ' . $nNexo . ' imágenes de Nexo en iconos/nexo: falta subir la carpeta completa (40).');
    $nIconos = count(glob($dir . '/iconos/DS/ds-*.svg') ?: []);
    $nIconos >= 118 ? nota('ok', $nIconos . ' íconos del sistema (iconos/DS)') : nota('error', 'Solo hay ' . $nIconos . ' íconos en iconos/DS: falta subir la carpeta completa (118).');
    $ilus = ['notificaciones-vacio', 'reportes-vacio', 'instructores-equipo', 'asistente-ia', 'sin-resultados-cursos', 'curso-sin-modulos',
             'banco-vacio', 'sin-ordenes', 'error-amable', 'construccion', 'despliegue', 'universidad-agraria', 'ingles',
             'unidad-educativa', 'academico', 'talento-humano', 'financiero', 'mesa-ayuda', 'sitios-web', 'asistente-virtual',
             'app-movil', 'emprendimiento', 'entrada-colaborativo', 'entrada-equipo', 'entrada-reunion', 'entrada-crm',
             'entrada-avance', 'correo-sincronizar', 'releases-tablero', 'releases-release', 'releases-equipos', 'releases-revisiones',
             'releases-hitos', 'releases-importar'];
    $faltanIlus = array_values(array_filter($ilus, fn($n) => !is_file($dir . '/iconos/ilustraciones/' . $n . '.svg')));
    $faltanIlus ? nota('error', 'Faltan ilustraciones en iconos/ilustraciones: ' . implode(', ', $faltanIlus) . '.') : nota('ok', count($ilus) . ' ilustraciones (portadas y estados vacíos)');

    // Un archivo subido a medias o de la versión anterior se reconoce por lo que le falta adentro
    $marcas = [
        'lib/UI.php'        => ['dialogTelefonoHtml', 'atributosPagina', 'selectorPortada', 'barraSuperior', 'TELEFONO_PATRON', "'app-movil'", 'entradaArte', 'data-push-modal', 'tutoriales.php', 'data-correo-n-menu', 'modalAviso', 'iconoEquipo', 'function nexo', 'nexo:descansando', 'releases.php', 'SIDEBAR_RECOGIDO', 'navGrupo', 'fa-table', 'Qué tiene que saber', 'buzones.php', 'function menuRadial', 'rad-aguja', '$icono: el del'],
        'lib/Notificaciones.php' => ['Push::avisar', 'function pendientes', "'deploy'", "'release'"],
        'lib/Push.php'      => ['push_claves', 'estadoPagina', 'atributosPagina'],
        'sw.js'             => ['meca-push', 'clavePanel'],
        'cron_correo.php'   => ['Notif::pendientes', 'ReqCorreo', 'CorreoCopias', 'Cronograma::paso', 'cronCallado', 'Releases::paso', 'ReqSeguimiento::paso'],
        'cron_zoom_drive.php' => ['cronCallado'],
        'cron_whatsapp.php' => ['cronCallado'],
        'cron_deploys.php' => ['cronCallado'],
        'cron_recordatorios.php' => ['cronCallado'],
        'lib/Models.php'    => ['function respaldos', 'ds-programadores', 'telefonoValido', 'ds-disenadores', "'origen'", 'function agregadaPor', 'function agregarColaborador'],
        'lib/ImportadorTareas.php' => ['$origen', 'item_release'],
        'lib/bootstrap.php' => ['function esPOde', 'avisarTareaTerminada', 'opcache_limpiar', 'function cronogramaDeploys', 'function cronCallado'],
        'actions.php'       => ['perfil_telefono', 'po_relevo', 'pedido_crear', 'ia_guardar', 'exigirTelefonoValido', 'correo_conectar', 'tutorial_crear', 'UI::hayIcono', 'auth_recuperar_pedir', 'whatsapp_estado', 'enAppC', 'req_correo_conf', 'ia_req_correo', 'CorreoCopias::quitar', 'equipo_mover', 'tareas_correo_crear', 'ia_tareas_correo', 'ia_responder_correo', 'correo_respuesta_ia', 'alRegistrarAMano', 'cronogramaDeploys', 'impIa->origen', 'rel_item_guardar', 'relMoverReunion', 'rel_daily_registrar', 'relAmarrar', 'req_colaborador', 'reqObsGuardar', 'function transcripcionReunion', 'function reunionNoPasada'],
        'assets/ds.js'      => ['soloNumerosTel', 'data-correo-marco', 'data-adjuntos', 'MCPush', 'DSTutorial', 'data-destinatarios', 'j.telefono', 'pintarTotal', 'appAislada', 'MCInstalar', 'mcinstalar:cambio'],
        'assets/ia.js'      => ['conGiro', 'data-ia-correo', 'tcw-listo', 'data-ia-responder', 'mostrarCarga', 'iconos/nexo/', 'responsables: elegidos'],
        'planificar.php'    => ['dlg-ia-plan', 'item_release', 'plan-item'],
        'lib/req_ui.php'    => ['req-tr', 'data-req-id', 'fq-cols', 'fq-colab', 'fq-obs-editor', 'fq-colab-form'],
        'assets/ds.css'     => ['.ia-carga', '.ds-enc', '.pac-portada', '.req-tabla', '.ent-arte', '.correo-lado', '.ds-adjuntos', '.ds-push', '.ds-tutorial', '.ds-dest__chip', '.page-head__arte', '.celda-contacto', '.push-aqui', '.correo-app__enc', '.ins-boton', '.ins-pasos__lista', '.pm-tarea__abrir', '.req-propuestas', '.eqo-tablero', '.mc-toast__accion', '.ico-equipo', '.zd-tiles', '.tc-tarea', '.tcw-listo', '.dep-crono', '.tarea-autor', '.ent-nexo', '.page-head__nexo', '.dep-tira-url', '.eq-card', '.rl-res__main', '.rl-dailies', '.rl-ses__editar', '.bz-kpis', 'clamp(320px, 34%', '.zd-tr-errores'],
        'notificaciones.php' => ['tab=correo', 'correoVista', 'pushDispositivos', 'dialogTelefonoHtml', 'data-push-aqui', 'pushInstalarApps', 'data-instalar-app', 'ReqCorreo.php', 'TareasCorreo.php', 'nexo:descansando'],
        'lib/Correo.php'    => ['revisarNuevos', 'anotarRecientes', 'function sellar', 'Disposition-Notification-To', 'function hilo', 'adjuntosDe', 'prioridadDe', 'fechaLista', 'function sinMojibake', 'HtmlRico::paraCorreo', 'guion invisible', 'function actualizarClave'],
        'lib/Tutoriales.php' => ['function pendiente'],
        'tutoriales.php'    => ['tutorial_avisar'],
        'lib/correo_vista.php' => ['correoDirectorio', 'correoPagina', 'correoBloqueRequerimiento', 'CorreoCopias::mensaje', 'data-ia-correo', 'correoTipoVisible', 'correo-dest-fila', 'correo-opciones', 'correoHilo', 'correo-adjs', 'correoPrioridad', 'correo_respuesta_ia', 'solo_cabecera', 'nexo:descansando', 'correoTextoAHtml'],
        'lib/ReqCorreo.php' => ['crudoCopia', 'function plazoDe', 'function gente'],
        'lib/ReqSeguimiento.php' => ['function avisarTerminado'],
        'lib/Releases.php'  => ['function importar', 'function tablero', 'function paso', 'function sembrar', 'dependencias', 'function veTodo', 'function resumenDailies', 'function itemsDeProyecto', 'function mismoNombre', 'function dailiesDelDia', '$diasPasados'],
        'releases.php'      => ['Releases::', 'rl-rfila__cuerpo', 'VISTAS_LECTOR', 'rl-dlg--ancho', 'rl-dlg-daily', 'data-rl-editar-rev', '$pasadosDl'],
        'lib/releases_ui.php' => ['function rlVistaRelease', 'function rlRecursosJs'],
        'assets/releases.js' => ['data-rl-abrir-fila', 'rl-frase', 'data-rl-agenda', 'data-rl-daily-reg', 'rl-gfila__lbl', 'DL.pasados'],
        'lib/Zoom.php'      => ['function participantes'],
        'assets/admin.css'  => ['nav-grupo.plegado', 'rt--correo', 'fq-hist', 'rt--obs', 'fq-colab-abrir', 'fq-terminar-acc', '.rad-menu', '.rad-aguja', 'tabla-toolbar--tareas', 'rad-op.is-sel { z-index: 3; }', '.grab-espera'],
        'lib/CorreoCopias.php' => ['function crudoDe'],
        'requerimientos.php' => ['dlg-req-correo', 'propuesta', 'dlg-req-seg', 'panelObservacion'],
        'lib/IAFunciones.php' => ['requerimientoCorreo', 'tareasCorreo', 'respuestaCorreo', 'releaseTareas', 'PLAZO_REQ', 'itemsRel'],
        'lib/IA.php'        => ['req_desde_correo', 'tareas_desde_correo', 'responder_correo', 'release_tareas'],
        'login.php'         => ['entradaArte', 'auth_recuperar_cambiar', 'ent-nexo'],
        'ajustes.php'       => ['whatsapp[activo]', 'deploys[cronograma][hora]', 'deploys[url]'],
        'lib/Auth.php'      => ['buscarPorUsuario', 'trasLogin'],
        'registro.php'      => ['entradaArte'],
        'assets/admin.js'   => ['.req-fila, .req-tr', 'mc-toast-slot', '--spin-c', 'proyectoVistaCelular', 'mc-toast__accion', 'data-visor-grupo', 'data-cuenta-regresiva', 'dt-autor', 'data-buscar-lista', 'meca-nav-plegados', 'insertTable', 'js-et-rel', 'fq-colab', 'fq-obs-rico', 'data-rad-btn', 'Clic derecho: abre la opción marcada', 'tipoPuntero'],
        'index.php'         => ['nexo:saludando', 'portadaDe', 'dep-tira-crono'],
        'deploys.php'       => ['Horario de subidas', 'data-cuenta-regresiva', 'dep-cards', 'ultimoDeGitLab'],
        'lib/Cronograma.php' => ['function deDeploy', 'deploysExitosos', 'no sale en ningún lado'],
        'lib/deploy_tira.php' => ['dep-prox', 'dep-tira-url'],
        'lib/GitLab.php'    => ['deploysExitosos', 'GITLAB_PRUEBA'],
        'proyecto.php'      => ['ph-limpio', 'dlg-obs-nueva', 'po_relevo', 'pm-tarea__abrir', 'tarea-autor', 'relChips', 'relCampo', 'rlVistaRelease', 'radVistas', 'grab-espera'],
        'equipo.php'        => ['name="telefono"', 'Copiar teléfono', 'eq-cards', 'equipos.php?desde='],
        'equipos.php'       => ['equipo_mover', 'data-eqo-mover'],
        'zoom_drive.php'    => ['ZoomDrive::paso', 'data-zd-seguir', 'List users', 'auto_estado', 'traspaso_paso'],
        'lib/ZoomDrive.php' => ['function errorDrive', 'function faltanPermisos', 'function automatico', 'function deReuniones', 'function traspasoPaso'],
        'lib/GoogleLogin.php' => ["'scope'", 'function flujo', 'oauth_drive_dueno'],
        'lib/Mailer.php'    => ['codigoRecuperacion', '/auth/gmail.send', 'avisoRelease', 'seguimientoRequerimientos'],
        'oauth_google.php'  => ['oauth_drive', '/auth/drive', 'oauth_drive_dueno'],
    ];
    $viejos = [];
    foreach ($marcas as $f => $buscar) {
        $txt = is_file($dir . '/' . $f) ? (string)file_get_contents($dir . '/' . $f) : '';
        foreach ($buscar as $b) {
            if (!str_contains($txt, $b)) { $viejos[] = $f; break; }
        }
    }
    // equipo.php no lleva name="telefono" (eso está en campos_persona.php): lo revisa aparte
    $viejos = array_values(array_diff($viejos, ['equipo.php']));
    $txtEquipo = (string)@file_get_contents($dir . '/equipo.php');
    if (!str_contains($txtEquipo, 'Copiar teléfono') || !str_contains($txtEquipo, 'eq-cards')) $viejos[] = 'equipo.php';
    $txtCampos = (string)@file_get_contents($dir . '/lib/campos_persona.php');
    if (!str_contains($txtCampos, 'name="telefono"') || !str_contains($txtCampos, 'TELEFONO_PATRON')) $viejos[] = 'lib/campos_persona.php';
    $viejos ? nota('error', 'Estos archivos son de la versión anterior (vuelve a subirlos): ' . implode(', ', array_unique($viejos)) . '.')
            : nota('ok', 'Todos los archivos modificados son de esta versión');

    /* 3. Permisos y protección */
    paso('Permisos y protección');
    foreach (['data', 'uploads'] as $d) {
        if (!is_dir($dir . '/' . $d) && !$seco) @mkdir($dir . '/' . $d, 0775, true);
        // Prueba de verdad (crear y borrar un archivo): is_writable() se equivoca
        // en Windows/OneDrive, que marcan las carpetas como «solo lectura».
        $prueba = $dir . '/' . $d . '/.prueba-escritura-' . bin2hex(random_bytes(3));
        $sePuede = is_dir($dir . '/' . $d) && @file_put_contents($prueba, 'x') !== false;
        if ($sePuede) @unlink($prueba);
        $sePuede ? nota('ok', $d . '/ se puede escribir') : nota('error', $d . '/ no se puede escribir: dale permisos de escritura (775).');
    }
    $hta = $dir . '/data/.htaccess';
    if (!is_file($hta)) {
        if (!$seco) {
            file_put_contents($hta, "# Bloquea TODO acceso web a la carpeta de datos.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        nota('aviso', 'data/.htaccess no estaba' . ($seco ? ' (se crearía)' : ': lo creé') . '. Sin él, la base de datos y las claves quedarían visibles en la web.');
    } else {
        nota('ok', 'data/ cerrada a la web (data/.htaccess)');
    }

    /* 4. Tablas nuevas */
    paso('Base de datos');
    if (!JsonStore::disponible()) {
        nota('error', 'Este PHP no tiene SQLite: no se pueden crear las tablas.');
    } else {
        foreach (['notificaciones' => 'Notificaciones (campana)', 'pedidos' => 'Pedidos entre equipos', 'ia_uso' => 'Consumo de IA',
                  'correo_cuentas' => 'Correo InnoTech (cuentas sincronizadas)',
                  'push_suscripciones' => 'Notificaciones nativas (dispositivos)',
                  'tutoriales' => 'Tutoriales en video',
                  'req_correo' => 'Requerimientos desde el correo',
                  'correo_copias' => 'Copias de los correos en el panel',
                  'tutoriales_vistos' => 'Quién ya vio cada tutorial',
                  'zoom_drive' => 'Grabaciones de Zoom → Drive', 'zoom_drive_carpetas' => 'Carpetas creadas en Drive',
                  'tareas_correo' => 'Tareas creadas desde un correo', 'deploy_builds' => 'Builds de GitLab del horario de subidas'] as $t => $etq) {
            if ($seco) { nota('info', $etq . ': se crearía la tabla «' . $t . '» si no existe'); continue; }
            $n = count((new JsonStore($t))->all());
            nota('ok', $etq . ' — tabla «' . $t . '» lista (' . $n . ' registro' . ($n === 1 ? '' : 's') . ')');
        }
    }

    /* 5. Semilla de IA */
    paso('Semilla de IA');
    if (!is_file($dir . '/lib/IA.php')) {
        nota('error', 'Falta lib/IA.php: súbelo y vuelve a ejecutar.');
    } else {
        require_once $dir . '/lib/IA.php';
        $archivoIa = $dir . '/data/ia.json';
        $existia   = is_file($archivoIa);
        $cfg       = IA::config();          // ya completa lo que falte con los valores de fábrica
        $base      = (new ReflectionClassConstant('IA', 'MODELOS_BASE'))->getValue();
        $hay       = [];
        foreach ($cfg['modelos'] as $m) $hay[$m['proveedor'] . ':' . $m['codigo']] = true;
        $nuevosModelos = 0;
        foreach ($base as [$p, $c, $n, $e, $s]) {
            if (isset($hay[$p . ':' . $c])) continue;
            $cfg['modelos'][] = ['proveedor' => $p, 'codigo' => $c, 'nombre' => $n, 'entrada' => $e, 'salida' => $s, 'activo' => true];
            $nuevosModelos++;
        }
        $bruto = $existia ? (json_decode((string)file_get_contents($archivoIa), true) ?: []) : [];
        $funcionesNuevas = count(array_diff_key(IA::TAREAS, (array)($bruto['asignaciones'] ?? [])));

        // Claves pasadas por la terminal o el entorno (sin repetir una ya guardada)
        $clavesNuevas = 0;
        foreach (['openai', 'gemini'] as $prov) {
            $valor = $opc[$prov] ?? '';
            if ($valor === '') continue;
            $repetida = false;
            foreach ($cfg['claves'][$prov] ?? [] as $k) {
                if (IA::descifrar((string)$k['valor']) === $valor) { $repetida = true; break; }
            }
            if ($repetida) { nota('info', 'La clave de ' . IA::PROVEEDORES[$prov]['nombre'] . ' ya estaba guardada'); continue; }
            if (!$seco) {
                $cfg['claves'][$prov][] = ['id' => bin2hex(random_bytes(4)), 'etiqueta' => 'Principal', 'valor' => IA::cifrar($valor),
                                           'final' => mb_substr($valor, -4), 'activa' => true];
            }
            $clavesNuevas++;
        }

        if (!$seco && (!$existia || $nuevosModelos || $funcionesNuevas || $clavesNuevas)) IA::guardar($cfg);
        $verbo = $seco ? 'se agregaría' : 'agregué';
        nota('ok', $existia ? 'Configuración de IA existente: se respetan los cambios hechos en la consola'
                            : ($seco ? 'Se crearía data/ia.json con los valores de fábrica' : 'Creé data/ia.json con los valores de fábrica'));
        nota($nuevosModelos ? 'ok' : 'info', $nuevosModelos ? $nuevosModelos . ' modelo(s) de referencia nuevos: ' . $verbo : count($cfg['modelos']) . ' modelos con sus precios');
        nota($funcionesNuevas && $existia ? 'ok' : 'info', count(IA::TAREAS) . ' funciones de IA con modelo principal y de respaldo'
            . ($funcionesNuevas && $existia ? ' (' . $funcionesNuevas . ' nueva' . ($funcionesNuevas === 1 ? '' : 's') . ')' : ''));
        nota('info', 'Tope de gasto mensual: US$ ' . number_format((float)$cfg['tope_mensual'], 2) . ($cfg['activa'] ? '' : ' · la IA está apagada en la consola'));
        if (!$seco) IA::cifrar('prueba');   // crea data/ia.secreto (la llave de cifrado) si no existe
        is_file($dir . '/data/ia.secreto') ? nota('ok', 'Llave de cifrado de las claves (data/ia.secreto)') : nota('info', 'La llave de cifrado se crearía al ejecutar sin --seco');
        if ($clavesNuevas) nota('ok', $clavesNuevas . ' clave(s) de IA ' . ($seco ? 'se guardarían' : 'guardadas') . ' cifradas');
        $nClaves = array_sum(array_map('count', IA::config()['claves']));
        $nClaves ? nota('ok', $nClaves . ' clave(s) de IA activas en la consola')
                 : nota('aviso', 'Todavía no hay claves de IA: agrégalas en Consola de IA → Claves (o con --openai= / --gemini=). Sin clave, los botones de IA no aparecen.');
    }

    /* 6. Lo que conviene revisar */
    paso('Para revisar');
    $proyectos = (new ProyectoRepo())->todos();
    $conPortada = $sinPortada = [];
    $conRelevo = 0;
    foreach ($proyectos as $p) {
        $port = UI::portadaDe($p);
        if ($port !== '') $conPortada[] = $p['nombre'] . ' → ' . UI::PORTADAS[$port];
        else $sinPortada[] = $p['nombre'];
        if ((int)($p['po_relevo'] ?? 0) > 0) $conRelevo++;
    }
    nota('info', count($conPortada) . ' de ' . count($proyectos) . ' proyectos con portada' . ($conPortada ? ': ' . implode('; ', $conPortada) : ''));
    if ($sinPortada) nota('aviso', 'Sin portada (se elige en Editar proyecto → Portada): ' . implode(', ', $sinPortada) . '.');
    nota('info', $conRelevo . ' proyecto(s) con PO de relevo');
    // Deploys de GitLab: no se muestra quién lanzó el pipeline. Lo que ya se registró con
    // «lo lanzó …» (y con su autor) queda sin nombre; los pipelines guardados, sin quién.
    $repoDep = new DeployRepo();
    $depLimpios = 0;
    foreach ($repoDep->todos() as $dL) {
        if (($dL['origen'] ?? '') !== 'gitlab') continue;
        $notaL = preg_replace('/ · lo lanzó [^\n]*?\.(?=\n|$)/u', '.', (string)($dL['nota'] ?? ''));
        if ($notaL === (string)($dL['nota'] ?? '') && (int)($dL['autor_id'] ?? 0) === 0) continue;
        $depLimpios++;
        if (!$seco) $repoDep->actualizar((int)$dL['id'], ['nota' => $notaL, 'autor_id' => 0]);
    }
    $storeBuilds = new JsonStore('deploy_builds');
    foreach ($storeBuilds->all() as $bL) {
        if (!isset($bL['lanzo']) && !isset($bL['usuario']) && !isset($bL['autor'])) continue;
        if (!$seco) $storeBuilds->update((int)$bL['id'], ['lanzo' => null, 'usuario' => null, 'autor' => null]);
    }
    if ($depLimpios) nota('ok', $depLimpios . ' deploy(s) de GitLab ' . ($seco ? 'quedarían' : 'quedaron') . ' sin el nombre de quién lo lanzó');
    // Teléfonos: van solo con números. Los guardados con +, espacios o guiones quedan en dígitos.
    $repoTel = new MiembroRepo();
    $telLimpios = 0;
    $telMalos = [];
    foreach ($repoTel->todos() as $m) {
        $tel = trim((string)($m['telefono'] ?? ''));
        if ($tel === '' || preg_match('/^\d{7,15}$/', $tel)) continue;
        $digitos = preg_replace('/\D/', '', $tel);
        $nuevo = preg_match('/^\d{7,15}$/', $digitos) ? $digitos : '';
        $nuevo === '' ? $telMalos[] = $m['nombre'] : $telLimpios++;
        if (!$seco) $repoTel->actualizar((int)$m['id'], ['telefono' => $nuevo]);
    }
    if ($telLimpios) nota('ok', $telLimpios . ' teléfono(s) ' . ($seco ? 'se dejarían' : 'dejados') . ' solo con números (sin +, espacios ni guiones)');
    if ($telMalos) nota('aviso', 'Teléfono no válido ' . ($seco ? '(se borraría' : '(lo borré') . ' y el panel lo pedirá de nuevo): ' . implode(', ', $telMalos) . '.');
    // Mal escritos (les falta un dígito, no empiezan con 09…): no se borran, el panel les pide corregirlos al entrar
    $telDe = fn($m) => trim((string)($m['telefono'] ?? ''));
    $telInvalidos = array_values(array_filter($repoTel->todos(), fn($m) => $telDe($m) !== '' && !MiembroRepo::telefonoValido($telDe($m))));
    if ($telInvalidos) {
        nota('aviso', count($telInvalidos) . ' persona(s) con el teléfono mal escrito: el panel les pedirá corregirlo cuando entren ('
            . implode(', ', array_map(fn($m) => $m['nombre'] . ' · ' . $telDe($m), array_slice($telInvalidos, 0, 8))) . (count($telInvalidos) > 8 ? '…' : '') . ').');
    }
    $sinTel = array_values(array_filter($repoTel->todos(), fn($m) => trim((string)($m['telefono'] ?? '')) === ''));
    nota($sinTel ? 'aviso' : 'ok', $sinTel ? count($sinTel) . ' persona(s) sin teléfono: el panel se lo pedirá cuando entren.' : 'Todas las personas tienen teléfono');
    // Notificaciones nativas: las claves del panel y el cron que avisa los correos
    if (is_file($dir . '/lib/Push.php')) {
        require_once $dir . '/lib/Push.php';
        if ($seco && !is_file($dir . '/data/push.json')) {
            nota('info', 'Notificaciones nativas: se crearían las claves del panel (data/push.json)');
        } elseif (Push::activo()) {
            $subsP = $seco ? [] : (new JsonStore('push_suscripciones'))->all();
            $malP  = count(array_filter($subsP, fn($sP) => (Push::estado($sP)['tono'] ?? '') === 'error'));
            nota('ok', 'Notificaciones nativas listas (claves fijas en data/push.json, respaldadas en la base) · ' . count($subsP) . ' dispositivo(s) anotado(s)');
            if ($malP) nota('aviso', $malP . ' dispositivo(s) con el último aviso rechazado: el motivo está en Notificaciones → Tus dispositivos.');
        } else {
            nota('aviso', 'Este PHP no pudo crear las claves de las notificaciones nativas (falta openssl con curvas EC): el panel funciona igual, sin avisos al celular.');
        }
        $cronC = json_decode((string)@file_get_contents($dir . '/data/cron_correo.json'), true);
        $haceC = is_array($cronC) ? time() - (int)($cronC['t'] ?? 0) : null;
        ($haceC !== null && $haceC < 300)
            ? nota('ok', 'Cron de correos nuevos corriendo (última revisión hace ' . max(1, (int)round($haceC / 60)) . ' min)')
            : nota('aviso', 'Programa en cPanel → Cron Jobs, cada minuto: /usr/local/bin/php ' . str_replace('\\', '/', $dir) . '/cron_correo.php (avisa los correos al instante).');
    }
    // Correo InnoTech: a qué servidor se conecta y cuántos ya lo sincronizaron
    if (is_file($dir . '/lib/Correo.php')) {
        require_once $dir . '/lib/Correo.php';
        // Semilla: el buzón del mismo cPanel (mail.innotech-solutions.com.ec, IMAP 993 y SMTP 465,
        // certificado verificado). Solo si nunca se configuró: lo que el admin cambió se respeta.
        // Con un Models.php anterior, Config::guardar() reescribe el archivo entero y
        // borraría Zoom, GitHub y Google: en ese caso no se siembra.
        if (empty((array)(Config::get('correo_buzon') ?? []))) {
            if (!method_exists('Config', 'respaldos')) {
                nota('error', 'No se sembró el servidor de correo: lib/Models.php es de la versión anterior y borraría el resto de la configuración. Súbelo de nuevo y repite.');
            } else {
                if (!$seco) Config::guardar(['correo_buzon' => Correo::DEFECTO]);
                nota('ok', 'Servidor de correo ' . ($seco ? 'se sembraría' : 'sembrado') . ': ' . Correo::DEFECTO['imap_host'] . ' (IMAP ' . Correo::DEFECTO['imap_puerto'] . ', SMTP ' . Correo::DEFECTO['smtp_puerto'] . ') para @' . Correo::DEFECTO['dominio']);
            }
        }
        $cc = Correo::conf();
        if (!Correo::activo()) {
            nota('aviso', 'Correo InnoTech apagado' . (function_exists('openssl_encrypt') ? ' (se prende en Notificaciones → Correo → Servidor de correo)' : ': falta la extensión openssl'));
        } else {
            $tc = $seco ? ['ok' => 0, 'reconectar' => 0] : Correo::totalCuentas();
            nota('info', 'Correo InnoTech: lee de ' . $cc['imap_host'] . ':' . $cc['imap_puerto'] . ' y envía por ' . $cc['smtp_host'] . ':' . $cc['smtp_puerto']
                . ' · ' . $tc['ok'] . ' persona(s) sincronizada(s)' . ($tc['reconectar'] ? ', ' . $tc['reconectar'] . ' por reconectar' : ''));
        }
    }

    /* Credenciales de Ajustes: si faltan todas, la config probablemente se reinició */
    /* Íconos de los catálogos: guardar Ajustes solo aceptaba fa-*, y los del
       design system quedaban con el genérico (todos los equipos con el mismo,
       todos los estados con el mismo). Los que vienen de fábrica vuelven al suyo. */
    paso('Íconos de estados, prioridades y equipos');
    $genericos = ['estados_tarea' => 'fa-circle-dot', 'prioridades' => 'fa-equals', 'estados_proyecto' => 'fa-flag', 'equipos' => 'fa-users'];
    $defC   = Config::defaults();
    $crudoC = json_decode((string)@file_get_contents($dir . '/data/config.json'), true) ?: [];
    $arreglos = [];
    $nArreglos = 0;
    foreach ($genericos as $cat => $gen) {
        $lista = $crudoC[$cat] ?? null;
        if (!is_array($lista)) continue;
        foreach ($lista as $k => $item) {
            $defIco = $defC[$cat][$k]['icono'] ?? '';
            if (is_array($item) && ($item['icono'] ?? '') === $gen && $defIco !== '' && $defIco !== $gen) {
                $lista[$k]['icono'] = $defIco;
                $arreglos[$cat] = $lista;
                $nArreglos++;
            }
        }
    }
    if ($arreglos && !$seco) Config::guardar($arreglos);
    $nArreglos
        ? nota('ok', $nArreglos . ' ícono(s) ' . ($seco ? 'volverían' : 'volvieron') . ' al del design system: ' . implode(', ', array_keys($arreglos)))
        : nota('ok', 'Cada estado, prioridad y equipo tiene su ícono');

    /* Equipo de Diseñadores: se agrega UNA vez a los equipos guardados (si
       luego alguien lo quita en Ajustes, no vuelve). Si nunca se guardaron
       equipos, ya sale de los de fábrica (Config::defaults). */
    paso('Equipos');
    $crudoE   = json_decode((string)@file_get_contents($dir . '/data/config.json'), true) ?: [];
    $listaE   = $crudoE['equipos'] ?? null;
    $semillaE = (array)($crudoE['semillas'] ?? []);
    if (is_array($listaE) && $listaE !== [] && empty($semillaE['equipo_disenadores'])) {
        $yaHayE = false;
        foreach ($listaE as $kE => $vE) {
            $etqE = mb_strtolower(strtr((string)($vE['label'] ?? $kE), ['ñ' => 'n', 'Ñ' => 'n']));
            if ($kE === 'disenadores' || str_starts_with($etqE, 'disen')) $yaHayE = true;
        }
        if (!method_exists('Config', 'respaldos')) {
            nota('error', 'No se agregó el equipo Diseñadores: lib/Models.php es de la versión anterior y borraría el resto de la configuración. Súbelo de nuevo y repite.');
        } else {
            if (!$yaHayE) $listaE['disenadores'] = ['label' => 'Diseñadores', 'icono' => 'ds-disenadores'];
            if (!$seco) Config::guardar(($yaHayE ? [] : ['equipos' => $listaE]) + ['semillas' => ['equipo_disenadores' => date('Y-m-d')] + $semillaE]);
            nota('ok', $yaHayE ? 'Ya había un equipo de diseño: no se agrega otro'
                : 'Equipo «Diseñadores» ' . ($seco ? 'se agregaría' : 'agregado') . ': sale en el menú de la izquierda, debajo de Analistas');
        }
    }
    $cuentaE = array_fill_keys(array_keys(Catalogo::equipos()), 0);
    foreach ((new MiembroRepo())->todos() as $mE) $cuentaE[MiembroRepo::equipoDe($mE)]++;
    $partesE = [];
    foreach (Catalogo::equipos() as $kE => [$etqE]) $partesE[] = $etqE . ': ' . $cuentaE[$kE];
    nota('info', implode(' · ', $partesE) . '. Para pasar gente de un equipo a otro: cualquier equipo → «Organizar equipos».');

    /* Grabaciones de Zoom → Google Drive (zoom_drive.php) */
    if (is_file($dir . '/lib/ZoomDrive.php')) {
        foreach (['Correo', 'ZoomDrive'] as $mZ) if (is_file($dir . '/lib/' . $mZ . '.php')) require_once $dir . '/lib/' . $mZ . '.php';
        paso('Grabaciones de Zoom → Drive');
        if (!function_exists('curl_init')) nota('error', 'Falta la extensión cURL de PHP: sin ella no se pueden pasar las grabaciones a Drive.');
        $cZ = ZoomDrive::conf();
        if (!ZoomDrive::driveListo()) {
            nota('info', 'Sin conectar: entra a Ajustes → Reuniones → «Grabaciones a Drive» y pulsa «Conectar Google Drive».');
        } else {
            $rZ = ZoomDrive::resumen();
            nota('ok', 'Google Drive conectado (' . $cZ['email'] . ') · en cola: ' . $rZ['cola'] . ' · en Drive: ' . $rZ['drive']
                . ' · liberado de Zoom: ' . ZoomDrive::bytes((int)$rZ['liberado_bytes']) . ($rZ['errores'] ? ' · con error: ' . $rZ['errores'] : ''));
            $cronZ = json_decode((string)@file_get_contents($dir . '/data/cron_zoom_drive.json'), true);
            $haceZ = is_array($cronZ) ? time() - (int)($cronZ['t'] ?? 0) : null;
            ($haceZ !== null && $haceZ < 900)
                ? nota('ok', 'Cron de grabaciones corriendo (última vuelta hace ' . max(1, (int)round($haceZ / 60)) . ' min)')
                : nota('aviso', 'Para que las grabaciones pasen solas a Drive, en cPanel → Cron Jobs, cada 5 minutos: /usr/local/bin/php ' . str_replace('\\', '/', $dir) . '/cron_zoom_drive.php');
        }
    }

    /* Releases: la planificación estratégica. Si el módulo está vacío y el repo trae
       la semilla (admin/semillas/releases-*.json, sacada de los Excel), se carga sola. */
    if (is_file($dir . '/lib/Releases.php')) {
        require_once $dir . '/lib/Releases.php';
        paso('Releases');
        try {
            $nRel = count((new JsonStore('rel_items'))->all());
            $semRel = Releases::semilla();
            if ($nRel === 0 && $semRel) {
                $rRel = Releases::sembrar($semRel, 'agregar', $seco);
                nota('ok', ($seco ? 'Cargaría' : 'Cargada') . ' la semilla ' . basename($semRel) . ': ' . $rRel['items_nuevos'] . ' ítems, '
                    . $rRel['revisiones'] . ' revisiones y ' . $rRel['hitos'] . ' hitos. Los equipos se vinculan solos a los proyectos «Equipo Alpha», «Equipo Beta»…');
            } elseif ($nRel === 0) {
                nota('info', 'Sin datos todavía: en el panel, Releases → Ajustes → Importar los dos Excel del cronograma.');
            } else {
                $tRel = Releases::tablero();
                nota('ok', $nRel . ' ítems · avance general ' . $tRel['total']['pct'] . ' % (esperado ' . $tRel['total']['esperado'] . ' %) · '
                    . $tRel['total']['atrasados'] . ' atrasados' . ($semRel ? ' · para volver a cargar la semilla: php admin/seed_releases.php' : ''));
            }
        } catch (Throwable $e) {
            nota('error', 'Releases: ' . $e->getMessage());
        }
    }

    /* WhatsApp: reuniones y código para recuperar la contraseña */
    if (is_file($dir . '/lib/Whatsapp.php')) {
        require_once $dir . '/lib/Whatsapp.php';
        paso('WhatsApp');
        $waC = Whatsapp::conf();
        if (!Whatsapp::activo()) {
            nota('info', 'WhatsApp apagado: las reuniones y el código para recuperar la contraseña van por correo (se enciende en Ajustes → Reuniones → WhatsApp).');
        } else {
            $waOk = $seco ? null : Whatsapp::salud();
            $waOk === false
                ? nota('aviso', 'La pasarela de WhatsApp no contesta en ' . $waC['url'] . ': revisa la app en cPanel → Setup Node.js App (servicios/whatsapp/README.md).')
                : nota('ok', 'Pasarela de WhatsApp: ' . $waC['url'] . ($waOk ? ' (contesta)' : ''));
            $waCron = json_decode((string)@file_get_contents($dir . '/data/cron_whatsapp.json'), true);
            $waHace = is_array($waCron) ? time() - (int)($waCron['t'] ?? 0) : null;
            ($waHace !== null && $waHace < 300)
                ? nota('ok', 'Cron de WhatsApp corriendo (última vuelta hace ' . max(1, (int)round($waHace / 60)) . ' min)')
                : nota('aviso', 'Programa en cPanel → Cron Jobs, cada minuto: /usr/local/bin/php ' . str_replace('\\', '/', $dir) . '/cron_whatsapp.php (manda los avisos y los recordatorios).');
            if (!$seco) {
                $waCola = Whatsapp::resumenCola();
                if ($waCola['pendientes'] || $waCola['fallidos']) nota('info', 'Mensajes de WhatsApp en cola: ' . $waCola['pendientes'] . ' · fallidos en 24 h: ' . $waCola['fallidos']);
            }
        }
    }

    paso('Credenciales de Ajustes');
    $cfgA  = Config::all();
    $zoomA = (array)($cfgA['zoom'] ?? []);
    $corA  = (array)($cfgA['correo'] ?? []);
    $glA   = (array)($cfgA['google_login'] ?? []);
    $cred = [
        'Token de GitHub'          => ($cfgA['github_token'] ?? '') !== '',
        'Zoom (Account y Client)'  => ($zoomA['account_id'] ?? '') !== '' && ($zoomA['client_id'] ?? '') !== '' && ($zoomA['client_secret'] ?? '') !== '',
        'Correo de avisos (Gmail)' => !empty($corA['activo']) && (($corA['refresh_token'] ?? '') !== '' || ($corA['clave'] ?? '') !== ''),
        'Google (Client ID)'       => ($glA['client_id'] ?? '') !== '' || ($corA['client_id'] ?? '') !== '',
    ];
    foreach ($cred as $nombre => $hay) {
        $hay ? nota('ok', $nombre . ': guardado') : nota('aviso', $nombre . ': vacío (Ajustes → Integraciones)');
    }
    $respA = method_exists('Config', 'respaldos') ? Config::respaldos() : [];
    if ($respA) {
        nota('info', 'Respaldos de la configuración en data/: ' . implode(', ', array_map('basename', array_slice($respA, 0, 5)))
            . '. Para volver a uno: cp ' . $respA[0] . ' ' . $dir . '/data/config.json');
    }
    if (!in_array(true, $cred, true) && $respA) {
        nota('aviso', 'No hay ninguna credencial guardada pero sí respaldos: es probable que la configuración se haya reiniciado.');
    }

    /* 7. Caché de PHP */
    paso('Caché');
    if (PHP_SAPI === 'cli' && !$seco) {
        // La caché de la web es otra: la vacía la primera visita al panel (lib/bootstrap.php)
        @file_put_contents($dir . '/data/opcache_limpiar.json', json_encode(['t' => date('c')])) !== false
            ? nota('ok', 'La web vacía su caché de PHP (OPcache) sola en la primera visita al panel: entra y ya ves la versión nueva.')
            : nota('aviso', 'No se pudo dejar la marca para vaciar la caché de la web: entra como administrador a Ajustes → Actualizar el panel → «Actualizar ahora».');
        if (function_exists('opcache_reset')) @opcache_reset();
    } elseif (function_exists('opcache_reset') && !$seco) {
        @opcache_reset();
        nota('ok', 'Caché de PHP (OPcache) vaciada');
    } else {
        nota('info', 'Sin OPcache que vaciar');
    }
    nota('info', 'El CSS y el JS se actualizan solos en el navegador: cada archivo va con la fecha en que se subió.');
}

if ($ejecutar) actualizarPanel($opc);

/* ---------- En la terminal: cada paso con su título ---------- */
if ($esCli) {
    echo PHP_EOL . 'Actualizando el panel' . ($opc['seco'] ? ' (solo mirando, sin escribir)' : '') . PHP_EOL;
    $marcas = ['ok' => '  OK     ', 'aviso' => '  AVISO  ', 'error' => '  ERROR  ', 'info' => '  ·      '];
    foreach ($pasos as [$titulo, $notas]) {
        echo PHP_EOL . '— ' . $titulo . PHP_EOL;
        foreach ($notas as [$nivel, $texto]) echo ($marcas[$nivel] ?? '  ') . $texto . PHP_EOL;
    }
    echo PHP_EOL . ($hayError ? 'Terminó CON ERRORES: revisa las líneas ERROR de arriba.'
        : ($opc['seco'] ? 'Revisión terminada: no se escribió nada (quita --seco para aplicarlo).' : 'Listo: el panel quedó actualizado.')) . PHP_EOL;
    exit($hayError ? 1 : 0);
}

/* ---------- En el navegador (solo administrador) ---------- */
UI::inicio('Actualizar el panel', 'ajustes');
UI::cabecera(
    'Actualizar el <span class="text-secondary">panel</span>',
    'Después de subir una versión nueva por FTP: revisa que esté todo, crea lo que falte y deja la IA con sus valores de fábrica (sin pisar lo que ya cambiaste). Se puede repetir sin riesgo.',
    '<form method="post"><button class="mc-btn mc-btn--primary mc-btn--md">' . UI::iconoDS('reintentar', 18) . '<span class="mc-btn__label">' . ($ejecutar ? 'Volver a ejecutar' : 'Actualizar ahora') . '</span></button></form>'
);
$tonos = ['ok' => ['success', 'exito'], 'aviso' => ['warning', 'alerta'], 'error' => ['danger', 'error'], 'info' => ['neutral', 'info']];
?>
<div class="ds" style="display:grid;gap:var(--space-4)">
  <?php if (!$ejecutar): ?>
    <?= UI::alertaDS('info', 'Todavía no se ha ejecutado', 'Pulsa «Actualizar ahora». Si tienes terminal, también puedes usar: <code class="mc-code-inline">php admin/actualizar.php</code>') ?>
  <?php else: ?>
    <?= $hayError
        ? UI::alertaDS('danger', 'Terminó con errores', 'Revisa los puntos en rojo: casi siempre es un archivo que no se subió.')
        : UI::alertaDS('success', 'El panel quedó actualizado', 'Los avisos en amarillo no impiden usarlo; son cosas para revisar.') ?>
    <?php foreach ($pasos as [$titulo, $notas]): ?>
    <section class="mc-card">
      <header class="mc-card__head"><div class="mc-card__titles"><h3 class="mc-card__title"><?= e($titulo) ?></h3></div></header>
      <div class="mc-card__body" style="display:grid;gap:8px">
        <?php foreach ($notas as [$nivel, $texto]): [$tono, $ico] = $tonos[$nivel]; ?>
        <div class="ds-fila" style="align-items:flex-start;flex-wrap:nowrap">
          <span class="mc-status mc-status--pill mc-status--<?= $tono ?>" style="flex:none"><span class="mc-status__content"><?= UI::iconoDS($ico, 14) ?><span class="mc-status__label"><?= ['ok' => 'Listo', 'aviso' => 'Revisar', 'error' => 'Error', 'info' => 'Dato'][$nivel] ?></span></span></span>
          <span style="font-size:var(--mc-fs-sm,.8125rem);line-height:1.5"><?= e($texto) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php UI::fin(); ?>
