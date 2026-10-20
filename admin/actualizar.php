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
        'tutoriales.php', 'lib/Tutoriales.php',
    ];
    $faltan = array_values(array_filter($archivos, fn($f) => !is_file($dir . '/' . $f)));
    $faltan ? nota('error', 'Faltan por subir: ' . implode(', ', $faltan) . '.') : nota('ok', count($archivos) . ' archivos principales en su lugar');

    $nIconos = count(glob($dir . '/iconos/DS/ds-*.svg') ?: []);
    $nIconos >= 116 ? nota('ok', $nIconos . ' íconos del sistema (iconos/DS)') : nota('error', 'Solo hay ' . $nIconos . ' íconos en iconos/DS: falta subir la carpeta completa (116).');
    $ilus = ['notificaciones-vacio', 'reportes-vacio', 'instructores-equipo', 'asistente-ia', 'sin-resultados-cursos', 'curso-sin-modulos',
             'banco-vacio', 'sin-ordenes', 'error-amable', 'construccion', 'despliegue', 'universidad-agraria', 'ingles',
             'unidad-educativa', 'academico', 'talento-humano', 'financiero', 'mesa-ayuda', 'sitios-web', 'asistente-virtual',
             'app-movil', 'emprendimiento', 'entrada-colaborativo', 'entrada-equipo', 'entrada-reunion', 'entrada-crm',
             'entrada-avance', 'correo-sincronizar'];
    $faltanIlus = array_values(array_filter($ilus, fn($n) => !is_file($dir . '/iconos/ilustraciones/' . $n . '.svg')));
    $faltanIlus ? nota('error', 'Faltan ilustraciones en iconos/ilustraciones: ' . implode(', ', $faltanIlus) . '.') : nota('ok', count($ilus) . ' ilustraciones (portadas y estados vacíos)');

    // Un archivo subido a medias o de la versión anterior se reconoce por lo que le falta adentro
    $marcas = [
        'lib/UI.php'        => ['dialogTelefono', 'selectorPortada', 'barraSuperior', 'pattern="[0-9]{7,15}"', "'app-movil'", 'entradaArte', 'ds-correo-fila', 'data-push-modal', 'tutoriales.php'],
        'lib/Notificaciones.php' => ['Push::avisar'],
        'lib/bootstrap.php' => ['function esPOde', 'avisarTareaTerminada'],
        'actions.php'       => ['perfil_telefono', 'po_relevo', 'pedido_crear', 'ia_guardar', 'exigirTelefonoValido', 'correo_conectar', 'tutorial_crear'],
        'assets/ds.js'      => ['soloNumerosTel', 'data-correo-marco', 'data-adjuntos', 'MCPush', 'DSTutorial', 'data-destinatarios'],
        'lib/req_ui.php'    => ['req-tr'],
        'assets/ds.css'     => ['.ds-enc', '.pac-portada', '.req-tabla', '.ent-arte', '.correo-lado', '.ds-adjuntos', '.ds-push', '.ds-tutorial', '.ds-dest__chip'],
        'notificaciones.php' => ['tab=correo', 'correoVista', 'pushDispositivos', 'Tutoriales::boton'],
        'lib/Correo.php'    => ['revisarNuevos', 'anotarRecientes'],
        'lib/correo_vista.php' => ['correoDirectorio'],
        'login.php'         => ['entradaArte'],
        'registro.php'      => ['entradaArte'],
        'assets/admin.js'   => ['.req-fila, .req-tr', 'mc-toast-slot'],
        'index.php'         => ['ds-indicadores', 'portadaDe'],
        'proyecto.php'      => ['ph-limpio', 'dlg-obs-nueva', 'po_relevo'],
        'equipo.php'        => ['name="telefono"', 'Copiar teléfono'],
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
    if (!str_contains((string)@file_get_contents($dir . '/equipo.php'), 'Copiar teléfono')) $viejos[] = 'equipo.php';
    if (!str_contains((string)@file_get_contents($dir . '/lib/campos_persona.php'), 'name="telefono"')) $viejos[] = 'lib/campos_persona.php';
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
                  'tutoriales' => 'Tutoriales en video'] as $t => $etq) {
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
    $sinTel = array_values(array_filter($repoTel->todos(), fn($m) => trim((string)($m['telefono'] ?? '')) === ''));
    nota($sinTel ? 'aviso' : 'ok', $sinTel ? count($sinTel) . ' persona(s) sin teléfono: el panel se lo pedirá cuando entren.' : 'Todas las personas tienen teléfono');
    // Notificaciones nativas: las claves del panel y el cron que avisa los correos
    if (is_file($dir . '/lib/Push.php')) {
        require_once $dir . '/lib/Push.php';
        if ($seco && !is_file($dir . '/data/push.json')) {
            nota('info', 'Notificaciones nativas: se crearían las claves del panel (data/push.json)');
        } elseif (Push::activo()) {
            $nDisp = $seco ? 0 : count((new JsonStore('push_suscripciones'))->all());
            nota('ok', 'Notificaciones nativas listas (claves en data/push.json) · ' . $nDisp . ' dispositivo(s) anotado(s)');
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
        if (empty((array)(Config::get('correo_buzon') ?? []))) {
            if (!$seco) Config::guardar(['correo_buzon' => Correo::DEFECTO]);
            nota('ok', 'Servidor de correo ' . ($seco ? 'se sembraría' : 'sembrado') . ': ' . Correo::DEFECTO['imap_host'] . ' (IMAP ' . Correo::DEFECTO['imap_puerto'] . ', SMTP ' . Correo::DEFECTO['smtp_puerto'] . ') para @' . Correo::DEFECTO['dominio']);
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

    /* 7. Caché de PHP */
    paso('Caché');
    if (function_exists('opcache_reset') && !$seco) {
        @opcache_reset();
        nota('ok', 'Caché de PHP (OPcache) vaciada' . (PHP_SAPI === 'cli' ? ' en la terminal; la web se refresca sola en unos minutos, o abre esta página como administrador' : ''));
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
