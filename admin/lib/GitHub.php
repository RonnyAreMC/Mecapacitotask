<?php
/**
 * GitHub - actividad de commits de un repositorio via API publica.
 * Cachea en data/cache_github.json (1 hora) para no agotar el limite
 * de la API. Con un token (Ajustes) tambien funciona con repos privados.
 */
require_once __DIR__ . '/Models.php';

class GitHub
{
    /** Tope de commits que se leen de un repo (30 paginas de la API). */
    public const MAX_COMMITS = 3000;

    /** Y tope de tiempo: antes de agotarlo se corta y se marca 'truncado'. */
    public const MAX_SEGUNDOS = 12;

    /** Entradas que se conservan en el archivo de cache. */
    private const MAX_CACHE = 8;

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
        $cache = self::cache();
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

        self::guardar($clave, $resultado);
        return $resultado + ['url' => $urlWeb];
    }

    /**
     * Commits recientes de un repo (para ver "quién subió qué"), cacheado 1h.
     * Devuelve ['estado'=>'ok'|'error'|'vacio'|'sin_repo', 'truncado'=>bool,
     *   'commits'=>[ ['sha'=>, 'msg'=>, 'login'=>, 'nombre'=>, 'fecha'=>, 'url'=>], ... ]]
     *
     * La API da 100 por pagina como mucho: se pagina hasta juntar $limite y
     * 'truncado' avisa si quedaron commits sin leer.
     */
    public static function commitsRecientes(?string $repoUrl, int $limite = 60, string $rama = '', int $dias = 400): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) {
            return ['estado' => 'sin_repo', 'commits' => [], 'truncado' => false];
        }
        [$owner, $nombre] = $repo;
        $clave = 'commits:' . strtolower("$owner/$nombre") . ($rama !== '' ? '@' . $rama : '')
               . '#' . $limite . 'd' . $dias;

        $cache = self::cache();
        $entrada = $cache[$clave] ?? null;
        // Una lectura que se quedo a medias por un fallo no merece una hora de
        // cache: se reintenta pronto, como los errores.
        $ttl = ($entrada['estado'] ?? '') === 'ok' && empty($entrada['parcial']) ? 3600 : 180;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < $ttl) {
            return $entrada;
        }

        $limite    = max(1, min(self::MAX_COMMITS, $limite));
        $porPagina = min(100, $limite);
        $paginas   = (int)ceil($limite / $porPagina);
        // Solo el tramo que se va a pintar (el rango elegido en Métricas)
        $desde = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, min(400, $dias)) * 86400);

        $commits = [];
        $truncado = false;
        $parcial = false;
        $codigo = 0;
        $t0 = microtime(true);
        for ($p = 1; $p <= $paginas; $p++) {
            $qs = 'per_page=' . $porPagina . '&page=' . $p . '&since=' . rawurlencode($desde)
                . ($rama !== '' ? '&sha=' . rawurlencode($rama) : '');
            [$c, $cuerpo] = self::api("/repos/$owner/$nombre/commits?$qs");
            // Una pagina suelta puede fallar (limite de peticiones, hipo del
            // servidor): se reintenta una vez antes de rendirse.
            if ($c !== 200 && $p > 1) {
                usleep(400000);
                [$c, $cuerpo] = self::api("/repos/$owner/$nombre/commits?$qs");
            }
            if ($p === 1) $codigo = $c;
            if ($c !== 200) {
                // A mitad del recorrido: lo que hay esta incompleto y hay que
                // decirlo, ademas de no cachearlo como bueno una hora entera.
                if ($p > 1) { $truncado = true; $parcial = true; }
                break;
            }
            $lista = json_decode($cuerpo, true) ?: [];
            foreach ($lista as $x) {
                $msg = (string)($x['commit']['message'] ?? '');
                $commits[] = [
                    'sha'    => substr((string)($x['sha'] ?? ''), 0, 7),
                    'msg'    => trim(strtok($msg, "\n")),          // primera línea
                    'login'  => strtolower((string)($x['author']['login'] ?? '')),
                    'nombre' => (string)($x['commit']['author']['name'] ?? ''),
                    'fecha'  => substr((string)($x['commit']['author']['date'] ?? ''), 0, 10),
                    'url'    => (string)($x['html_url'] ?? ''),
                ];
            }
            if (count($lista) < $porPagina) break;      // no hay mas
            // Se para por tope de paginas o de tiempo: mas vale una vista
            // incompleta y avisada que una pagina que nunca carga.
            if ($p === $paginas || microtime(true) - $t0 > self::MAX_SEGUNDOS) {
                $truncado = true;
                break;
            }
        }

        $resultado = ['t' => time(), 'estado' => 'error', 'commits' => [], 'truncado' => false];
        if ($codigo === 200) {
            $resultado = ['t' => time(), 'estado' => $commits ? 'ok' : 'vacio',
                          'commits' => $commits, 'truncado' => $truncado, 'parcial' => $parcial];
        } elseif ($codigo === 409) {
            $resultado['estado'] = 'vacio';   // repo sin commits
        }

        self::guardar($clave, $resultado);
        return $resultado;
    }

    /** Nombres de las ramas de un repo (cacheado 1h). */
    public static function ramas(?string $repoUrl): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) return [];
        [$owner, $nombre] = $repo;
        $clave = 'ramas:' . strtolower("$owner/$nombre");

        $cache = self::cache();
        $entrada = $cache[$clave] ?? null;
        // Igual que en GitLab: una lista vacia es casi siempre un fallo
        // pasajero, asi que no se cachea una hora entera.
        $ttl = !empty($entrada['ramas']) ? 3600 : 180;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < $ttl) {
            return $entrada['ramas'] ?? [];
        }

        [$codigo, $cuerpo] = self::api("/repos/$owner/$nombre/branches?per_page=100");
        $ramas = [];
        if ($codigo === 200) {
            foreach (json_decode($cuerpo, true) ?: [] as $b) {
                if (!empty($b['name'])) $ramas[] = $b['name'];
            }
        }
        self::guardar($clave, ['t' => time(), 'ramas' => $ramas]);
        return $ramas;
    }

    /* ---------- Cache en disco (mismo esquema que GitLab) ---------- */

    private static function archivoCache(): string
    {
        return __DIR__ . '/../data/cache_github.json';
    }

    private static function cache(): array
    {
        $f = self::archivoCache();
        return file_exists($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    /**
     * Guarda una entrada y poda las viejas. Cada combinacion de rama y rango
     * cachea su propia lista de commits, asi que con muchas ramas el archivo
     * crecia sin freno: se quedan solo las ultimas MAX_CACHE.
     */
    private static function guardar(string $clave, array $valor): void
    {
        $cache = self::cache();
        $cache[$clave] = $valor;
        // Lo caducado ya no sirve a nadie, y cada lista de commits pesa
        $viejo = time() - 3600;
        $cache = array_filter($cache, fn($v) => ($v['t'] ?? 0) > $viejo);
        if (count($cache) > self::MAX_CACHE) {
            uasort($cache, fn($a, $b) => ($b['t'] ?? 0) <=> ($a['t'] ?? 0));
            $cache = array_slice($cache, 0, self::MAX_CACHE, true);
        }
        file_put_contents(self::archivoCache(), json_encode($cache));
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
