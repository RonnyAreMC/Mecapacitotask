<?php
/**
 * Auth - acceso al panel con roles.
 *
 *   admin  -> control total (crear/editar/borrar, ajustes, credenciales)
 *   lector -> solo ve; únicamente puede anotar observaciones
 *
 * Los usuarios son los propios colaboradores (data/miembros.json):
 * entran con su correo o su usuario de Git + contraseña (hash bcrypt).
 */
require_once __DIR__ . '/Models.php';

class Auth
{
    public const ROLES = ['admin' => 'Administrador', 'lector' => 'Solo lectura'];

    /** Largo minimo de una contraseña creada desde el registro publico. */
    public const MIN_CLAVE = 8;

    /** Colaborador con la sesión iniciada, o null. */
    public static function usuario(): ?array
    {
        static $cache = false;
        if ($cache !== false) return $cache;
        $id = (int)($_SESSION['uid'] ?? 0);
        return $cache = ($id > 0 ? (new MiembroRepo())->buscar($id) : null);
    }

    public static function rol(): string
    {
        $u = self::usuario();
        return $u ? ($u['acceso'] ?? 'lector') : '';
    }

    public static function esAdmin(): bool
    {
        return self::rol() === 'admin';
    }

    /** ¿Ya existe al menos un administrador con contraseña? */
    public static function hayAdmin(): bool
    {
        foreach ((new MiembroRepo())->todos() as $m) {
            if (!empty($m['pass_hash']) && ($m['acceso'] ?? '') === 'admin') return true;
        }
        return false;
    }

    /** Verifica credenciales (correo o usuario de Git) e inicia sesión. */
    public static function login(string $usuario, string $clave): bool
    {
        $usuario = trim($usuario);
        foreach ((new MiembroRepo())->todos() as $m) {
            if (empty($m['pass_hash'])) continue;
            $coincide = strcasecmp($m['email'] ?? '', $usuario) === 0
                     || strcasecmp($m['git_user'] ?? '', ltrim($usuario, '@')) === 0;
            if ($coincide && password_verify($clave, $m['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$m['id'];
                return true;
            }
        }
        return false;
    }

    /** Inicia sesión directamente para un colaborador (usado por Google login). */
    public static function iniciarSesion(int $miembroId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $miembroId;
    }

    public static function salir(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Genera el hash de una contraseña (mínimo 6 caracteres). */
    public static function hash(string $clave): string
    {
        return password_hash($clave, PASSWORD_DEFAULT);
    }

    /* ---------- Registro público ---------- */

    /** Config del registro, ya normalizada. */
    public static function registro(): array
    {
        $r = (array)(Config::get('registro') ?? []);
        return [
            'abierto'  => !empty($r['abierto']),
            'dominios' => trim((string)($r['dominios'] ?? '')),
            'avisar'   => !empty($r['avisar']),
        ];
    }

    /**
     * ¿Se puede crear una cuenta desde login? Mientras no exista el primer
     * administrador no tiene sentido: no habría quién apruebe la solicitud.
     */
    public static function registroAbierto(): bool
    {
        return self::registro()['abierto'] && self::hayAdmin();
    }

    /** Lista de dominios permitidos (vacía = cualquier correo). */
    public static function dominiosPermitidos(): array
    {
        $txt = self::registro()['dominios'];
        $out = [];
        foreach (preg_split('/[\s,;]+/', $txt) as $d) {
            $d = ltrim(strtolower(trim($d)), '@');
            if ($d !== '') $out[] = $d;
        }
        return $out;
    }

    /** ¿El correo pertenece a un dominio permitido? */
    public static function dominioPermitido(string $email): bool
    {
        $dominios = self::dominiosPermitidos();
        if (!$dominios) return true;
        $host = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach ($dominios as $d) {
            // Acepta el dominio y sus subdominios (mail.itb.edu.ec)
            if ($host === $d || str_ends_with($host, '.' . $d)) return true;
        }
        return false;
    }

    /** Correos de los administradores, para avisarles de una solicitud. */
    public static function correosAdmin(): array
    {
        $out = [];
        foreach ((new MiembroRepo())->todos() as $m) {
            if (($m['acceso'] ?? '') === 'admin' && !empty($m['email'])) {
                $out[strtolower($m['email'])] = $m['email'];
            }
        }
        // El correo de contacto de Ajustes, si está configurado y no repetido
        $extra = trim((string)(Config::get('correo')['admin_email'] ?? ''));
        if ($extra !== '' && !isset($out[strtolower($extra)])) {
            $out[strtolower($extra)] = $extra;
        }
        return array_values($out);
    }

    /** Exige sesión iniciada; si no, manda al login. */
    public static function requiereLogin(): void
    {
        if (PHP_SAPI === 'cli') return;
        if (!self::usuario()) {
            header('Location: ' . urlPanel('login.php'));
            exit;
        }
    }

    /** Exige rol administrador para acciones que modifican datos. */
    public static function requiereAdmin(): void
    {
        if (PHP_SAPI === 'cli') return;
        self::requiereLogin();
        if (!self::esAdmin()) {
            redirigir('index.php', 'Tu cuenta es de solo lectura: no puedes hacer esa acción.', 'error');
        }
    }
}
