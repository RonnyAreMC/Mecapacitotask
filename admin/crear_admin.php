<?php
/**
 * Crea o actualiza un ADMINISTRADOR del panel. Solo por terminal:
 * asi nadie puede darse acceso desde la web.
 *
 *   php admin/crear_admin.php                          (modo guiado)
 *   php admin/crear_admin.php "Ronny" correo@x.com clave123
 *   php admin/crear_admin.php --listar
 *
 * Si la persona ya existe, le actualiza el correo, la contrasena y el acceso.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}
require_once __DIR__ . '/lib/bootstrap.php';

$repo   = new MiembroRepo();
$equipo = $repo->todos();

function linea(string $pregunta, bool $oculto = false): string
{
    fwrite(STDOUT, $pregunta);
    if ($oculto && DIRECTORY_SEPARATOR !== '\\') {
        shell_exec('stty -echo 2>/dev/null');
        $v = trim((string)fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
        return $v;
    }
    return trim((string)fgets(STDIN));
}

function tabla(array $equipo): void
{
    fwrite(STDOUT, "\nEquipo:\n");
    foreach ($equipo as $m) {
        printf("  [%2d] %-18s %-22s %-30s %s\n",
            $m['id'], $m['nombre'], $m['rol'] ?: '—',
            $m['email'] ?: '(sin correo)',
            ($m['acceso'] ?? 'lector') === 'admin' ? 'ADMIN' : 'solo lectura');
    }
    fwrite(STDOUT, "\n");
}

if (!$equipo) {
    exit("No hay colaboradores todavía. Crea el equipo desde el panel primero.\n");
}
if (in_array($argv[1] ?? '', ['--listar', '-l'], true)) {
    tabla($equipo);
    exit(0);
}

$quien = $argv[1] ?? '';
$mail  = $argv[2] ?? '';
$clave = $argv[3] ?? '';

if ($quien === '') {
    tabla($equipo);
    $quien = linea('¿Quién será administrador? (id o nombre): ');
}

// Buscar por id exacto o por nombre (coincidencia parcial, sin distinguir mayúsculas)
$miembro = null;
if (ctype_digit($quien)) {
    $miembro = $repo->buscar((int)$quien);
} else {
    $hallados = array_values(array_filter($equipo, fn($m) => stripos($m['nombre'], $quien) !== false));
    if (count($hallados) > 1) {
        exit("Hay más de un colaborador que coincide con «{$quien}». Usa el id.\n");
    }
    $miembro = $hallados[0] ?? null;
}
if (!$miembro) {
    exit("No encontré a «{$quien}» en el equipo. Corre: php admin/crear_admin.php --listar\n");
}

if ($mail === '') {
    $actual = $miembro['email'] ?? '';
    $mail = linea('Correo de acceso' . ($actual ? " [$actual]" : '') . ': ') ?: $actual;
}
if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    exit("«{$mail}» no es un correo válido.\n");
}
foreach ($equipo as $m) {
    if ((int)$m['id'] !== (int)$miembro['id'] && strcasecmp($m['email'] ?? '', $mail) === 0) {
        exit("Ese correo ya lo usa {$m['nombre']}.\n");
    }
}

if ($clave === '') {
    $clave = linea('Contraseña (mínimo 6): ', true);
    if ($clave !== linea('Repetir contraseña: ', true)) {
        exit("Las contraseñas no coinciden.\n");
    }
}
if (strlen($clave) < 6) {
    exit("La contraseña debe tener al menos 6 caracteres.\n");
}

$repo->actualizar((int)$miembro['id'], [
    'email'     => $mail,
    'acceso'    => 'admin',
    'pass_hash' => Auth::hash($clave),
]);

fwrite(STDOUT, "\n{$miembro['nombre']} ya es administrador del panel.\n");
fwrite(STDOUT, "  Entra en /admin/login.php con $mail y la contraseña que pusiste.\n\n");
