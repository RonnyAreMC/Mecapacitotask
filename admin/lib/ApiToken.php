<?php
/**
 * Token personal para la API (api.php): con él, Claude Code consulta y mueve las
 * tareas de esa persona sin entrar al panel, con sus mismos permisos.
 *
 * Del token solo se guarda su huella (sha256) en el miembro, nunca el token: se ve
 * UNA vez, al crearlo (Mi perfil → Claude Code). Cada persona tiene uno; crear
 * otro anula el anterior, y quitarlo lo anula sin poner otro.
 */
require_once __DIR__ . '/Models.php';

final class ApiToken
{
    private const PREFIJO = 'mch_';

    /** Uso anotado cada tanto, no en cada llamada (cada anotación reescribe al miembro) */
    private const ANOTAR_CADA = 600;

    /** Crea (o reemplaza) el token de la persona. Devuelve el token en claro, para mostrarlo una sola vez. */
    public static function crear(int $mid): string
    {
        $token = self::PREFIJO . bin2hex(random_bytes(24));
        (new MiembroRepo())->actualizar($mid, [
            'api_token'        => hash('sha256', $token),
            'api_token_fin'    => substr($token, -4),
            'api_token_creado' => date('Y-m-d H:i'),
            'api_token_usado'  => '',
        ]);
        return $token;
    }

    public static function quitar(int $mid): void
    {
        (new MiembroRepo())->actualizar($mid, ['api_token' => '', 'api_token_fin' => '', 'api_token_creado' => '', 'api_token_usado' => '']);
    }

    /** La persona dueña del token, o null si no es de nadie. */
    public static function miembro(string $token): ?array
    {
        $token = trim($token);
        if (!str_starts_with($token, self::PREFIJO) || strlen($token) < 20) return null;
        $huella = hash('sha256', $token);
        foreach ((new MiembroRepo())->todos() as $m) {
            if (!empty($m['api_token']) && hash_equals((string)$m['api_token'], $huella)) return $m;
        }
        return null;
    }

    public static function anotarUso(array $m): void
    {
        $ultimo = strtotime((string)($m['api_token_usado'] ?? '')) ?: 0;
        if (time() - $ultimo >= self::ANOTAR_CADA) (new MiembroRepo())->actualizar((int)$m['id'], ['api_token_usado' => date('Y-m-d H:i')]);
    }

    /** ['tiene' => bool, 'fin' => últimas 4 letras, 'creado', 'usado'] para mostrarlo en Mi perfil */
    public static function estado(array $m): array
    {
        return [
            'tiene'  => !empty($m['api_token']),
            'fin'    => (string)($m['api_token_fin'] ?? ''),
            'creado' => (string)($m['api_token_creado'] ?? ''),
            'usado'  => (string)($m['api_token_usado'] ?? ''),
        ];
    }
}
