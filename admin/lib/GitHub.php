<?php
/**
 * GitHub - actividad de commits de un repositorio via API publica.
 * Cachea en data/cache_github.json (1 hora) para no agotar el limite
 * de la API. Con un token (Ajustes) tambien funciona con repos privados.
 */
require_once __DIR__ . '/Models.php';

class GitHub
{
    /** 'https://github.com/owner/repo(.git)' => ['owner', 'repo'] o null. */
    public static function parsearRepo(?string $url): ?array
    {
        if (!$url || !preg_match('#github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i', $url, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /**
     * Actividad semanal de commits (hasta 52 semanas).
     * Devuelve ['estado' => 'ok'|'pendiente'|'error'|'sin_repo', 'semanas' => [], 'total' => int, 'url' => string]
     */
    public static function actividad(?string $repoUrl): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) {
            return ['estado' => 'sin_repo', 'semanas' => [], 'total' => 0, 'url' => ''];
        }
        [$owner, $nombre] = $repo;
        $clave = strtolower("$owner/$nombre");
        $urlWeb = "https://github.com/$owner/$nombre";

        // Cache
        $cacheFile = __DIR__ . '/../data/cache_github.json';
        $cache = file_exists($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
        $entrada = $cache[$clave] ?? null;
        $ttl = ($entrada['estado'] ?? '') === 'ok' ? 3600 : 180;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < $ttl) {
            return $entrada + ['url' => $urlWeb];
        }

        [$codigo, $cuerpo] = self::api("/repos/$owner/$nombre/stats/commit_activity");
        $resultado = ['t' => time(), 'estado' => 'error', 'semanas' => [], 'total' => 0];

        if ($codigo === 200) {
            $semanas = json_decode($cuerpo, true);
            if (is_array($semanas)) {
                $total = array_sum(array_column($semanas, 'total'));
                $resultado = ['t' => time(), 'estado' => 'ok', 'semanas' => $semanas, 'total' => $total];
            }
        } elseif ($codigo === 202) {
            // GitHub esta calculando las estadisticas; reintentar luego
            $resultado['estado'] = 'pendiente';
        }

        $cache[$clave] = $resultado;
        file_put_contents($cacheFile, json_encode($cache));
        return $resultado + ['url' => $urlWeb];
    }

    /** GET a la API de GitHub. Devuelve [codigoHttp, cuerpo]. */
    private static function api(string $ruta): array
    {
        $cabeceras = [
            'User-Agent: Mecapacito-Panel',
            'Accept: application/vnd.github+json',
        ];
        $token = (string)(Config::get('github_token') ?? '');
        if ($token !== '') {
            $cabeceras[] = 'Authorization: Bearer ' . $token;
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $cabeceras),
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $cuerpo = @file_get_contents('https://api.github.com' . $ruta, false, $ctx);
        $codigo = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
        }
        return [$codigo, (string)$cuerpo];
    }
}
