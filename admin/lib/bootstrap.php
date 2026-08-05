<?php
/**
 * Bootstrap del panel: sesion, autocarga de clases, helpers
 * y datos de ejemplo la primera vez que se abre.
 */
// Corta bucles de redirección. Si la URL trae una cola después del script
// (p. ej. /admin/oauth_google.php/login.php, sea por PATH_INFO o por un CGI
// que no la separó), los redirect relativos como "login.php" se resuelven
// una y otra vez sobre esa cola y el navegador entra en ERR_TOO_MANY_REDIRECTS.
// Se manda UNA vez a la URL limpia del script.
if (PHP_SAPI !== 'cli') {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!empty($_SERVER['PATH_INFO']) || preg_match('#\.php/.+#i', $sn)) {
        $limpio = preg_replace('#(\.php)/.*$#i', '$1', $sn);
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $limpio . ($qs !== '' ? '?' . $qs : ''), true, 302);
        exit;
    }
}

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
require_once __DIR__ . '/GoogleCalendar.php';
require_once __DIR__ . '/ImportadorTareas.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/GitLab.php';
require_once __DIR__ . '/Repos.php';
require_once __DIR__ . '/Zoom.php';
require_once __DIR__ . '/Reuniones.php';

// Zona horaria del equipo. Sin esto PHP usa la del servidor (normalmente UTC)
// y las horas de reuniones y fechas salen corridas al compararlas o mostrarlas.
date_default_timezone_set(Zoom::zona() ?: 'America/Guayaquil');

/* ---------- Helpers ---------- */

/** Escapa HTML. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Directorio del panel en el servidor (p. ej. "/admin"), a prueba de colas
 * PATH_INFO: corta SCRIPT_NAME en el primer ".php" antes de sacar la carpeta.
 */
function rutaPanelBase(): string
{
    $sn = $_SERVER['SCRIPT_NAME'] ?? '/admin/index.php';
    if (preg_match('#^(.*?\.php)#i', $sn, $m)) {
        $sn = $m[1];
    }
    $dir = str_replace('\\', '/', dirname($sn));
    return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
}

/**
 * Convierte un destino en una URL absoluta dentro del panel. Los redirect
 * relativos ("login.php") son frágiles: cualquier cola en la URL los enrosca
 * en un bucle. Un destino ya absoluto (http… o que empieza por "/") se respeta.
 */
function urlPanel(string $destino): string
{
    if ($destino === '') {
        return rutaPanelBase() . '/index.php';
    }
    if ($destino[0] === '/' || preg_match('#^https?://#i', $destino)) {
        return $destino;
    }
    return rutaPanelBase() . '/' . ltrim($destino, '/');
}

/** Guarda un mensaje flash y redirige (siempre con URL absoluta del panel). */
function redirigir(string $url, string $msg = '', string $tipo = 'success'): never
{
    if ($msg !== '') {
        $_SESSION['flash'][] = [$tipo, $msg];
    }
    header('Location: ' . urlPanel($url));
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

/**
 * Destino de vuelta que manda un formulario en el campo 'volver'.
 *
 * Solo se acepta una pagina del propio panel ("proyecto.php?id=3&estado=..."):
 * asi conserva los filtros de la vista sin abrir la puerta a que alguien
 * redirija a un sitio externo colando una URL absoluta.
 */
function volverAqui(string $defecto): string
{
    $v = trim((string)($_POST['volver'] ?? ''));
    return preg_match('#^[\w.-]+\.php(\?[\w=&%.,+-]*)?$#', $v) ? $v : $defecto;
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
function guardarFoto(string $campo, string $prefijo = 'foto_', string $etiqueta = 'foto'): string
{
    $err = $_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        redirigir(paginaOrigen(), 'La ' . $etiqueta . ' pesa demasiado (límite del servidor: ' . ini_get('upload_max_filesize') . '). Intenta con una más liviana.', 'error');
    }
    if ($err !== UPLOAD_ERR_OK || empty($_FILES[$campo]['tmp_name'])) {
        redirigir(paginaOrigen(), 'No se pudo subir la ' . $etiqueta . ' (código ' . $err . '). Intenta de nuevo.', 'error');
    }
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($_FILES[$campo]['tmp_name']);
    if (!isset($permitidos[$mime])) {
        redirigir(paginaOrigen(), 'Formato no soportado para la ' . $etiqueta . '. Usa JPG, PNG, WebP o GIF.', 'error');
    }
    $nombre = 'uploads/' . uniqid($prefijo) . '.' . $permitidos[$mime];
    $destino = __DIR__ . '/../' . $nombre;
    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $destino)) {
        redirigir(paginaOrigen(), 'No se pudo guardar la ' . $etiqueta . ' en el servidor.', 'error');
    }
    return $nombre;
}

/** Ruta del logo del panel: la imagen subida en Ajustes o el logo por defecto. */
function logoPanel(): string
{
    $logo = trim((string)(Config::get('logo') ?? ''));
    if ($logo !== '' && is_file(__DIR__ . '/../' . $logo)) {
        return $logo;
    }
    // Marca de esta instancia: si está el logo de InnoTech Hub (SVG preferido,
    // luego PNG), se usa por defecto sin tener que subirlo en Ajustes.
    foreach (['innotech-hub-logo.svg', 'innotech-hub-logo.png'] as $arch) {
        if (is_file(__DIR__ . '/../../assets/' . $arch)) {
            return '../assets/' . $arch;
        }
    }
    return '../assets/mecapacito-logo.png';
}

/**
 * Icono para la pestaña del navegador. NO sirve el logo del panel: ese es el
 * wordmark (281x59), y a 16px el texto se aplasta hasta volverse ilegible.
 * Se usa el icono cuadrado, que es lo que se lee a ese tamaño.
 * Si en Ajustes subieron un logo propio, ese manda: es una decisión explícita.
 */
function faviconPanel(): string
{
    $logo = trim((string)(Config::get('logo') ?? ''));
    if ($logo !== '' && is_file(__DIR__ . '/../' . $logo)) {
        return $logo;
    }
    foreach (['innotech-hub-icon.svg', 'innotech-hub-icon.png'] as $arch) {
        if (is_file(__DIR__ . '/../../assets/' . $arch)) {
            return '../assets/' . $arch;
        }
    }
    return logoPanel();          // sin icono propio, mejor el logo que nada
}

/** Tipo MIME de una imagen del panel, para el atributo type del favicon. */
function logoMime(string $ruta = ''): string
{
    $ruta = $ruta !== '' ? $ruta : logoPanel();
    return str_ends_with(strtolower($ruta), '.svg') ? 'image/svg+xml' : 'image/png';
}

/**
 * Formatos admitidos como adjunto: extension => tipos reales aceptables.
 *
 * Se comprueban los dos. Solo por extension seria confiar en el nombre que
 * manda el navegador; solo por tipo real no funciona con Office moderno,
 * porque un .docx es un ZIP por dentro y muchos servidores lo reportan como
 * application/zip (asi se descartaban en silencio archivos legitimos).
 */
const ADJUNTOS_PERMITIDOS = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'gif'  => ['image/gif'],
    'pdf'  => ['application/pdf'],
    'txt'  => ['text/plain'],
    'csv'  => ['text/plain', 'text/csv', 'application/csv'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage'],
    'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage'],
    'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
];

/**
 * Sube varios adjuntos de un input múltiple.
 * Devuelve [ ['ruta'=>, 'nombre'=>, 'tipo'=>'img'|'doc', 'ext'=>], ... ].
 * Los que no se pudieron subir salen por $rechazados, para poder decirlo en
 * pantalla en vez de perderlos sin avisar.
 */
function guardarAdjuntos(string $campo, string $prefijo = 'obs_', array &$rechazados = []): array
{
    $out = [];
    if (empty($_FILES[$campo]) || empty($_FILES[$campo]['name'])) {
        return $out;
    }
    $f = $_FILES[$campo];
    $nombres = is_array($f['name']) ? $f['name'] : [$f['name']];
    for ($i = 0, $n = count($nombres); $i < $n; $i++) {
        $error    = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        $tmp      = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        $original = (string)(is_array($f['name']) ? $f['name'][$i] : $f['name']);
        if ($error === UPLOAD_ERR_NO_FILE || $original === '') {
            continue;                       // hueco del input, no es un fallo
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '') {
            $rechazados[] = $original;
            continue;
        }
        $ext   = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime  = (string)mime_content_type($tmp);
        $tipos = ADJUNTOS_PERMITIDOS[$ext] ?? null;
        if (!$tipos || !in_array($mime, $tipos, true)) {
            $rechazados[] = $original;
            continue;
        }
        $ruta = 'uploads/' . uniqid($prefijo) . '.' . $ext;
        if (!move_uploaded_file($tmp, __DIR__ . '/../' . $ruta)) {
            $rechazados[] = $original;
            continue;
        }
        $out[] = [
            'ruta'   => $ruta,
            'nombre' => mb_substr($original, 0, 80),
            'tipo'   => str_starts_with($mime, 'image/') ? 'img' : 'doc',
            'ext'    => $ext,
        ];
    }
    return $out;
}

/**
 * Borra del disco los archivos de una lista de adjuntos. Solo toca lo que
 * vive en uploads/ y sin barras: una ruta manipulada no puede sacar el borrado
 * de esa carpeta.
 */
function borrarAdjuntos(array $adjuntos): void
{
    foreach ($adjuntos as $a) {
        $ruta = (string)($a['ruta'] ?? '');
        if (!preg_match('#^uploads/[\w.-]+$#', $ruta)) {
            continue;
        }
        $archivo = __DIR__ . '/../' . $ruta;
        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }
}

/**
 * Añade al mensaje de vuelta los adjuntos que no se pudieron guardar, y lo
 * marca como error: perder un documento en silencio es peor que un aviso.
 * Devuelve [mensaje, tipo].
 */
function avisoAdjuntos(array $rechazados, string $msg = '', string $tipo = 'success'): array
{
    if (!$rechazados) {
        return [$msg, $tipo];
    }
    $nombres = implode(', ', array_map(fn($n) => mb_substr((string)$n, 0, 40), $rechazados));
    return [
        $msg . ' No se pudo adjuntar: ' . $nombres
             . '. Se admiten imágenes, PDF, Word, Excel, PowerPoint, TXT y CSV.',
        'error',
    ];
}

/** Icono de Font Awesome para un adjunto, segun su extension. */
function iconoAdjunto(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf'                        => 'fa-file-pdf',
        'doc', 'docx'                => 'fa-file-word',
        'xls', 'xlsx', 'csv'         => 'fa-file-excel',
        'ppt', 'pptx'                => 'fa-file-powerpoint',
        'txt'                        => 'fa-file-lines',
        'jpg', 'jpeg', 'png', 'webp', 'gif' => 'fa-file-image',
        default                      => 'fa-paperclip',
    };
}

/* ---------- Control de acceso ---------- */
// Todas las páginas exigen sesión, salvo el login y actions.php
// (actions.php aplica su propia guarda por acción).
// logo.php sirve la imagen de marca a los correos: los clientes la piden sin
// sesión, así que no puede exigir login (no expone nada privado).
$scriptActual = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (PHP_SAPI !== 'cli' && !in_array($scriptActual, ['login.php', 'actions.php', 'oauth_google.php', 'logo.php'], true)) {
    Auth::requiereLogin();
}

/** Atajo para plantillas: ¿el usuario actual puede editar? */
function esAdmin(): bool
{
    return Auth::esAdmin();
}

/* ---------- Alcance: que proyectos puede ver cada quien ---------- */

/**
 * Proyectos visibles para el usuario con la sesion iniciada.
 *
 * Devuelve null si los ve todos (administrador) o un set
 * [proyecto_id => true] con aquellos en los que participa.
 *
 * Se participa en un proyecto si se figura en su equipo, si se tiene una
 * tarea asignada, si se esta invitado a alguna de sus reuniones o si se
 * escribio una observacion.
 */
function alcanceProyectos(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    if (Auth::esAdmin()) {
        return $cache = null;
    }

    $yo  = (int)(Auth::usuario()['id'] ?? 0);
    $ids = [];
    if ($yo > 0) {
        foreach ((new ProyectoRepo())->todos() as $p) {
            $suEquipo = ProyectoRepo::miembrosDe($p);
            if ($suEquipo !== null && in_array($yo, $suEquipo, true)) {
                $ids[(int)$p['id']] = true;
            }
        }
        foreach ((new TareaRepo())->todas() as $t) {
            if (TareaRepo::tieneAsignado($t, $yo)) {
                $ids[(int)$t['proyecto_id']] = true;
            }
        }
        foreach ((new JsonStore('reuniones'))->all() as $r) {
            if (in_array($yo, array_map('intval', (array)($r['invitados'] ?? [])), true)) {
                $ids[(int)$r['proyecto_id']] = true;
            }
        }
        foreach ((new JsonStore('observaciones'))->all() as $o) {
            if ((int)($o['autor_id'] ?? 0) === $yo) {
                $ids[(int)$o['proyecto_id']] = true;
            }
        }
    }
    return $cache = $ids;
}

/** ¿El usuario actual puede abrir este proyecto? */
function puedeVerProyecto(int $proyectoId): bool
{
    $alcance = alcanceProyectos();
    return $alcance === null || isset($alcance[$proyectoId]);
}

/** Deja de una lista de proyectos solo los que el usuario puede ver. */
function soloProyectosVisibles(array $proyectos): array
{
    $alcance = alcanceProyectos();
    if ($alcance === null) {
        return $proyectos;
    }
    return array_values(array_filter($proyectos, fn($p) => isset($alcance[(int)$p['id']])));
}

/** Corta la pagina si el proyecto no es de los suyos. */
function exigirProyecto(int $proyectoId): void
{
    if (!puedeVerProyecto($proyectoId)) {
        redirigir('index.php', 'Ese proyecto no es tuyo: solo ves los proyectos en los que participas.', 'error');
    }
}

/* ---------- "Ver como": filtro global por persona (transversal) ---------- */

/**
 * Solo el administrador puede mirar el panel "como" otra persona.
 * Para un colaborador de solo lectura no tendria sentido: ya ve
 * unicamente lo suyo, y le dejaria espiar el resto del equipo.
 */
function puedeVerComo(): bool
{
    return Auth::esAdmin();
}

/** Miembro seleccionado en "Ver como", o null si se ve todo el equipo. */
function verComo(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    if (!puedeVerComo()) {
        return $cache = null;
    }
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

// ?ver_como=N en cualquier pagina: fija la sesion y limpia la URL.
// Si quien lo pide no es administrador, el filtro se descarta sin más.
if (isset($_GET['ver_como'])) {
    if (puedeVerComo()) {
        $_SESSION['ver_como'] = max(0, (int)$_GET['ver_como']);
    } else {
        unset($_SESSION['ver_como']);
    }
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

    // Equipo de programadores (InnoTech Académico)
    $miembros->crear(['nombre' => 'Eder Ordoñez',    'rol' => 'Developer',            'git_user' => 'ederordonez',   'color' => 1,  'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Jaione Cherres',  'rol' => 'Full Stack Developer', 'git_user' => 'jaionecherres',  'color' => 8,  'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Miller Moran',    'rol' => 'Developer',            'git_user' => 'millermoran',    'color' => 2,  'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Vanessa Murillo', 'rol' => 'Developer',            'git_user' => 'vanessamurillo', 'color' => 12, 'equipo' => 'programacion']);
    $miembros->crear(['nombre' => 'Carlos Rodriguez','rol' => 'Developer',            'git_user' => 'carlosrodriguez','color' => 5,  'equipo' => 'programacion']);
    $ronny = $miembros->crear(['nombre' => 'Ronny Arellano', 'rol' => 'Tech Lead',    'git_user' => 'ronnyarellano',  'color' => 0,  'equipo' => 'programacion']);

    // Ronny es el administrador del panel (queda fijo en el seed), con acceso
    // por correo+contraseña listo desde el primer arranque (sin usar la shell).
    $miembros->actualizar((int)$ronny['id'], [
        'acceso'    => 'admin',
        'email'     => 'ronnyareu22@gmail.com',
        'pass_hash' => Auth::hash('academico2026'),
    ]);

    // Equipo de analistas
    $miembros->crear(['nombre' => 'Felipe Arevalo',  'rol' => 'Analista Funcional',  'git_user' => 'felipearevalo',  'color' => 3,  'equipo' => 'analistas']);
    $miembros->crear(['nombre' => 'Gabriel Alavera', 'rol' => 'Analista Funcional',  'git_user' => 'gabrielalavera', 'color' => 7,  'equipo' => 'analistas']);

    // Único proyecto: SIGE Académico (lo ve todo el equipo por no fijar miembros).
    $proyectos->crear([
        'nombre' => 'SIGE Académico', 'icono' => 'fa-graduation-cap', 'color' => 0, 'estado' => 'activo',
        'descripcion' => 'Sistema integrado de gestión educativa — módulo académico.',
    ]);
}

sembrarDatos();
