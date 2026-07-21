<?php
/**
 * Bootstrap del panel: sesion, autocarga de clases, helpers
 * y datos de ejemplo la primera vez que se abre.
 */
session_start();

require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Models.php';
require_once __DIR__ . '/UI.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/Zoom.php';

/* ---------- Helpers ---------- */

/** Escapa HTML. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** Guarda un mensaje flash y redirige. */
function redirigir(string $url, string $msg = '', string $tipo = 'success'): never
{
    if ($msg !== '') {
        $_SESSION['flash'][] = [$tipo, $msg];
    }
    header('Location: ' . $url);
    exit;
}

/** Limite efectivo de subida en bytes (min de upload_max_filesize y post_max_size). */
function limiteSubidaBytes(): int
{
    $aBytes = function (string $v): int {
        $n = (int)$v;
        return match (strtoupper(substr(trim($v), -1))) {
            'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => (int)$v,
        };
    };
    return min($aBytes((string)ini_get('upload_max_filesize')), $aBytes((string)ini_get('post_max_size')));
}

/** Pagina desde la que se envio el formulario (para volver con un error). */
function paginaOrigen(): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    return $ref !== '' ? $ref : 'index.php';
}

/**
 * Procesa la foto subida de un miembro; devuelve la ruta relativa o ''.
 * Si la subida fallo (tamano, formato), redirige con un error claro
 * en vez de guardar en silencio sin foto.
 */
function guardarFoto(string $campo): string
{
    $err = $_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        redirigir(paginaOrigen(), 'La foto pesa demasiado (límite del servidor: ' . ini_get('upload_max_filesize') . '). Intenta con una más liviana.', 'error');
    }
    if ($err !== UPLOAD_ERR_OK || empty($_FILES[$campo]['tmp_name'])) {
        redirigir(paginaOrigen(), 'No se pudo subir la foto (código ' . $err . '). Intenta de nuevo.', 'error');
    }
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($_FILES[$campo]['tmp_name']);
    if (!isset($permitidos[$mime])) {
        redirigir(paginaOrigen(), 'Formato de foto no soportado. Usa JPG, PNG, WebP o GIF.', 'error');
    }
    $nombre = 'uploads/' . uniqid('foto_') . '.' . $permitidos[$mime];
    $destino = __DIR__ . '/../' . $nombre;
    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $destino)) {
        redirigir(paginaOrigen(), 'No se pudo guardar la foto en el servidor.', 'error');
    }
    return $nombre;
}

/**
 * Sube varios adjuntos (imagenes, PDF, Word) de un input múltiple.
 * Devuelve [ ['ruta'=>, 'nombre'=>, 'tipo'=>'img'|'doc', 'ext'=>], ... ].
 */
function guardarAdjuntos(string $campo): array
{
    $out = [];
    if (empty($_FILES[$campo]) || empty($_FILES[$campo]['name'])) {
        return $out;
    }
    $permitidos = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $f = $_FILES[$campo];
    $nombres = is_array($f['name']) ? $f['name'] : [$f['name']];
    for ($i = 0, $n = count($nombres); $i < $n; $i++) {
        $error = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        $tmp   = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        if ($error !== UPLOAD_ERR_OK || $tmp === '') {
            continue;
        }
        $mime = mime_content_type($tmp);
        if (!isset($permitidos[$mime])) {
            continue;
        }
        $ext  = $permitidos[$mime];
        $ruta = 'uploads/' . uniqid('obs_') . '.' . $ext;
        if (move_uploaded_file($tmp, __DIR__ . '/../' . $ruta)) {
            $original = is_array($f['name']) ? $f['name'][$i] : $f['name'];
            $out[] = [
                'ruta'   => $ruta,
                'nombre' => mb_substr($original, 0, 80),
                'tipo'   => str_starts_with($mime, 'image/') ? 'img' : 'doc',
                'ext'    => $ext,
            ];
        }
    }
    return $out;
}

/* ---------- "Ver como": filtro global por persona (transversal) ---------- */

/** Miembro seleccionado en "Ver como", o null si se ve todo el equipo. */
function verComo(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = (int)($_SESSION['ver_como'] ?? 0);
    return $cache = ($id > 0 ? (new MiembroRepo())->buscar($id) : null);
}

/** URL actual con el parametro ver_como (para los enlaces del selector). */
function urlConVerComo(int $id): string
{
    $qs = $_GET;
    $qs['ver_como'] = $id;
    return '?' . http_build_query($qs);
}

// ?ver_como=N en cualquier pagina: fija la sesion y limpia la URL
if (isset($_GET['ver_como'])) {
    $_SESSION['ver_como'] = max(0, (int)$_GET['ver_como']);
    $qs = $_GET;
    unset($qs['ver_como']);
    redirigir(strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . http_build_query($qs) : ''));
}

/* ---------- Datos de ejemplo (solo primera vez) ---------- */

function sembrarDatos(): void
{
    $flag = __DIR__ . '/../data/.seeded';
    if (file_exists($flag)) return;
    touch($flag);

    $miembros = new MiembroRepo();
    $proyectos = new ProyectoRepo();
    $tareas = new TareaRepo();

    if (count($miembros->todos()) > 0 || count($proyectos->todos()) > 0) return;

    $m1 = $miembros->crear(['nombre' => 'Ronny Zapata', 'rol' => 'Tech Lead',        'git_user' => 'ronnyzapata', 'color' => 0]);
    $m2 = $miembros->crear(['nombre' => 'María Torres', 'rol' => 'Frontend Dev',     'git_user' => 'mtorres-dev', 'color' => 3]);
    $m3 = $miembros->crear(['nombre' => 'Carlos Vega',  'rol' => 'Backend Dev',      'git_user' => 'cvega',       'color' => 2]);

    $p1 = $proyectos->crear([
        'nombre' => 'Sitio Mecapacito', 'icono' => 'fa-globe', 'color' => 0, 'estado' => 'activo',
        'descripcion' => 'Sitio corporativo con landing, servicios y portafolio de proyectos.',
        'repo' => 'https://github.com/mecapacito/mecapasite',
    ]);
    $p2 = $proyectos->crear([
        'nombre' => 'POS Tiendas', 'icono' => 'fa-store', 'color' => 3, 'estado' => 'activo',
        'descripcion' => 'Punto de venta parametrizable para tiendas y minimarkets.',
        'repo' => 'https://github.com/mecapacito/pos-tiendas',
    ]);

    $tareas->crear(['proyecto_id' => $p1['id'], 'titulo' => 'Hero con carrusel animado', 'estado' => 'hecho',     'prioridad' => 'alta',  'asignado_id' => $m2['id'], 'descripcion' => 'Slides de servicios con blobs y gradientes.']);
    $tareas->crear(['proyecto_id' => $p1['id'], 'titulo' => 'Sección equipo con avatares', 'estado' => 'progreso', 'prioridad' => 'media', 'asignado_id' => $m2['id'], 'descripcion' => 'CEO destacado + grid de developers.']);
    $tareas->crear(['proyecto_id' => $p1['id'], 'titulo' => 'Formulario de contacto con validación', 'estado' => 'pendiente', 'prioridad' => 'media', 'asignado_id' => $m1['id'], 'descripcion' => '']);
    $tareas->crear(['proyecto_id' => $p2['id'], 'titulo' => 'Módulo de inventario', 'estado' => 'progreso', 'prioridad' => 'alta', 'asignado_id' => $m3['id'], 'descripcion' => 'CRUD de productos con categorías y stock mínimo.']);
    $tareas->crear(['proyecto_id' => $p2['id'], 'titulo' => 'Facturación electrónica SRI', 'estado' => 'revision', 'prioridad' => 'alta', 'asignado_id' => $m1['id'], 'descripcion' => 'Firma y envío de comprobantes.']);
    $tareas->crear(['proyecto_id' => $p2['id'], 'titulo' => 'Reporte de ventas diarias', 'estado' => 'pendiente', 'prioridad' => 'baja', 'asignado_id' => $m3['id'], 'descripcion' => '']);
}

sembrarDatos();
