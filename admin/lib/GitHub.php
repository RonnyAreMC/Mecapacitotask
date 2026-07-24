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

    /**
     * Commits recientes de un repo (para ver "quién subió qué"), cacheado 1h.
     * Devuelve ['estado'=>'ok'|'error'|'vacio'|'sin_repo', 'commits'=>[
     *   ['sha'=>, 'msg'=>, 'login'=>, 'nombre'=>, 'fecha'=>, 'url'=>], ... ]]
     */
    public static function commitsRecientes(?string $repoUrl, int $limite = 60, string $rama = ''): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) {
            return ['estado' => 'sin_repo', 'commits' => []];
        }
        [$owner, $nombre] = $repo;
        $clave = 'commits:' . strtolower("$owner/$nombre") . ($rama !== '' ? '@' . $rama : '');

        $cacheFile = __DIR__ . '/../data/cache_github.json';
        $cache = file_exists($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
        $entrada = $cache[$clave] ?? null;
        $ttl = ($entrada['estado'] ?? '') === 'ok' ? 3600 : 180;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < $ttl) {
            return $entrada;
        }

        $qs = 'per_page=' . max(1, min(100, $limite)) . ($rama !== '' ? '&sha=' . rawurlencode($rama) : '');
        [$codigo, $cuerpo] = self::api("/repos/$owner/$nombre/commits?$qs");
        $resultado = ['t' => time(), 'estado' => 'error', 'commits' => []];

        if ($codigo === 200) {
            $lista = json_decode($cuerpo, true) ?: [];
            $commits = [];
            foreach ($lista as $c) {
                $msg = (string)($c['commit']['message'] ?? '');
                $commits[] = [
                    'sha'    => substr((string)($c['sha'] ?? ''), 0, 7),
                    'msg'    => trim(strtok($msg, "\n")),          // primera línea
                    'login'  => strtolower((string)($c['author']['login'] ?? '')),
                    'nombre' => (string)($c['commit']['author']['name'] ?? ''),
                    'fecha'  => substr((string)($c['commit']['author']['date'] ?? ''), 0, 10),
                    'url'    => (string)($c['html_url'] ?? ''),
                ];
            }
            $resultado = ['t' => time(), 'estado' => $commits ? 'ok' : 'vacio', 'commits' => $commits];
        } elseif ($codigo === 409) {
            $resultado['estado'] = 'vacio';   // repo sin commits
        }

        $cache[$clave] = $resultado;
        file_put_contents($cacheFile, json_encode($cache));
        return $resultado;
    }

    /** Nombres de las ramas de un repo (cacheado 1h). */
    public static function ramas(?string $repoUrl): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) return [];
        [$owner, $nombre] = $repo;
        $clave = 'ramas:' . strtolower("$owner/$nombre");

        $cacheFile = __DIR__ . '/../data/cache_github.json';
        $cache = file_exists($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
        $entrada = $cache[$clave] ?? null;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < 3600) {
            return $entrada['ramas'] ?? [];
        }

        [$codigo, $cuerpo] = self::api("/repos/$owner/$nombre/branches?per_page=100");
        $ramas = [];
        if ($codigo === 200) {
            foreach (json_decode($cuerpo, true) ?: [] as $b) {
                if (!empty($b['name'])) $ramas[] = $b['name'];
            }
        }
        $cache[$clave] = ['t' => time(), 'ramas' => $ramas];
        file_put_contents($cacheFile, json_encode($cache));
        return $ramas;
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
