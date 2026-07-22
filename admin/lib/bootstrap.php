<?php
/**
 * Bootstrap del panel: sesion, autocarga de clases, helpers
 * y datos de ejemplo la primera vez que se abre.
 */
// Cookie de sesión endurecida (no accesible por JS, y solo por HTTPS si lo hay)
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    session_start();
}

require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Models.php';
require_once __DIR__ . '/UI.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/GoogleLogin.php';
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

/**
 * Ruta de un asset con ?v=<fecha del archivo>. Evita que un cache del
 * navegador (o del servidor) siga sirviendo una version vieja del CSS/JS
 * despues de un despliegue.
 */
function asset(string $ruta): string
{
    $v = @filemtime(__DIR__ . '/../' . $ruta) ?: 1;
    return $ruta . '?v=' . $v;
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

/* ---------- Control de acceso ---------- */
// Todas las páginas exigen sesión, salvo el login y actions.php
// (actions.php aplica su propia guarda por acción).
$scriptActual = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (PHP_SAPI !== 'cli' && !in_array($scriptActual, ['login.php', 'actions.php', 'oauth_google.php'], true)) {
    Auth::requiereLogin();
}

/** Atajo para plantillas: ¿el usuario actual puede editar? */
function esAdmin(): bool
{
    return Auth::esAdmin();
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

    // Equipo de programadores
    $miembros->crear(['nombre' => 'Kevin',           'rol' => 'Frontend Dev',        'git_user' => 'kevin',          'color' => 1,  'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Ronny Arellano',  'rol' => 'Tech Lead',           'git_user' => 'ronnyarellano',  'color' => 0,  'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Dulce Villacis',  'rol' => 'Backend Dev',         'git_user' => 'dulcevillacis', 'color' => 12, 'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Jaione Cherres',  'rol' => 'Full Stack Developer','git_user' => 'jaionecherres',  'color' => 8,  'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Jordy Pincay',    'rol' => 'Backend Dev',         'git_user' => 'jordypincay',    'color' => 2,  'equipo' => 'programacion']);

    // Equipo de analistas
    $miembros->crear(['nombre' => 'Felipe Arevalo',  'rol' => 'Analista Funcional',  'git_user' => 'felipearevalo',  'color' => 3,  'equipo' => 'analistas']);
    $miembros->crear(['nombre' => 'Erick Pastrano',  'rol' => 'Analista de Datos',   'git_user' => 'erickpastrano',  'color' => 7,  'equipo' => 'analistas']);
    $miembros->crear(['nombre' => 'Ronald',          'rol' => 'Analista Funcional',  'git_user' => 'ronald',         'color' => 5,  'equipo' => 'analistas']);

    // Proyectos
    $proyectos->crear([
        'nombre' => 'SIGE', 'icono' => 'fa-graduation-cap', 'color' => 0, 'estado' => 'activo',
        'descripcion' => 'Sistema integrado de gestión educativa.',
    ]);
    $proyectos->crear([
        'nombre' => 'TPV', 'icono' => 'fa-store', 'color' => 3, 'estado' => 'activo',
        'descripcion' => 'Terminal punto de venta.',
    ]);
    $proyectos->crear([
        'nombre' => 'CONTABILIDAD', 'icono' => 'fa-money-bill-wave', 'color' => 2, 'estado' => 'activo',
        'descripcion' => 'Módulo de contabilidad y finanzas.',
    ]);
}

sembrarDatos();
