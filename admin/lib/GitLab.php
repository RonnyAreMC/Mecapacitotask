<?php
/**
 * GitLab - ramas y commits de un repositorio via API v4.
 *
 * Devuelve exactamente la misma forma que GitHub, para que las vistas (el
 * grafico de aportes) no tengan que saber de donde vienen los datos. El
 * despacho entre uno y otro lo hace Repos.
 *
 * Funciona con gitlab.com y con instancias autogestionadas: la base de la API
 * se arma con el host de la propia URL del repositorio, no con una constante.
 * Cachea en data/cache_gitlab.json (1 hora) igual que GitHub.
 */
require_once __DIR__ . '/Models.php';

class GitLab
{
    /** Tope de commits que se leen de un repo (30 paginas de la API). */
    public const MAX_COMMITS = 3000;

    /** Y tope de tiempo: antes de agotarlo se corta y se marca 'truncado'. */
    public const MAX_SEGUNDOS = 12;

    /** Entradas que se conservan en el archivo de cache. */
    private const MAX_CACHE = 8;

    /**
     * 'https://gitlab.com/grupo/subgrupo/repo(.git)' => ['gitlab.com', 'grupo/subgrupo/repo'].
     * GitLab admite grupos anidados, asi que la ruta puede tener varios niveles.
     */
    public static function parsearRepo(?string $url): ?array
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://([^/]+)/(.+)$#i', $url, $m)) {
            return null;
        }
        $host = strtolower($m[1]);
        $ruta = trim($m[2], '/');
        $ruta = preg_replace('/\.git$/i', '', $ruta);
        // Partes de la interfaz web que no forman parte de la ruta del proyecto
        $ruta = preg_replace('#/-/.*$#', '', $ruta);
        if ($ruta === '' || !str_contains($ruta, '/')) {
            return null;                       // hace falta al menos grupo/proyecto
        }
        return [$host, $ruta];
    }

    /** Nombres de las ramas del repo (cacheado 1h). */
    public static function ramas(?string $repoUrl): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) return [];
        [$host, $ruta] = $repo;

        $cache = self::cache();
        $clave = 'ramas:' . strtolower("$host/$ruta");
        $entrada = $cache[$clave] ?? null;
        // Una lista vacia casi siempre es un fallo pasajero (todavia sin token,
        // repo privado, red caida). Se reintenta pronto en vez de dejarla
        // clavada una hora, que es lo que se espera de un resultado bueno.
        $ttl = !empty($entrada['ramas']) ? 3600 : 180;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < $ttl) {
            return $entrada['ramas'] ?? [];
        }

        [$codigo, $cuerpo] = self::api($host, self::ruta($ruta) . '/repository/branches?per_page=100');
        $ramas = [];
        if ($codigo === 200) {
            foreach (json_decode($cuerpo, true) ?: [] as $b) {
                if (!empty($b['name'])) $ramas[] = $b['name'];
            }
        }
        self::guardar($clave, ['t' => time(), 'ramas' => $ramas]);
        return $ramas;
    }

    /**
     * Commits recientes. Misma forma que GitHub::commitsRecientes():
     * ['estado'=>'ok'|'error'|'vacio'|'sin_repo', 'commits'=>[...], 'truncado'=>bool]
     *
     * La API devuelve 100 por pagina como mucho, asi que se pagina hasta
     * juntar $limite. 'truncado' avisa de que quedaron commits sin leer, para
     * que el panel no de a entender que eso es todo lo que hay.
     */
    public static function commitsRecientes(?string $repoUrl, int $limite = 60, string $rama = '', int $dias = 400): array
    {
        $repo = self::parsearRepo($repoUrl);
        if (!$repo) {
            return ['estado' => 'sin_repo', 'commits' => [], 'truncado' => false];
        }
        [$host, $ruta] = $repo;

        $cache = self::cache();
        $clave = 'commits:' . strtolower("$host/$ruta") . ($rama !== '' ? '@' . $rama : '')
               . '#' . $limite . 'd' . $dias;
        $entrada = $cache[$clave] ?? null;
        // Una lectura que se quedo a medias por un fallo no merece una hora de
        // cache: se reintenta pronto, como los errores.
        $ttl = ($entrada['estado'] ?? '') === 'ok' && empty($entrada['parcial']) ? 3600 : 180;
        if ($entrada && (time() - ($entrada['t'] ?? 0)) < $ttl) {
            return $entrada;
        }

        [$commits, $truncado, $codigo, $parcial] = self::paginar($host, $ruta, $limite, $rama, $dias);
        $resultado = ['t' => time(), 'estado' => 'error', 'commits' => [], 'truncado' => false];
        if ($codigo === 200) {
            $resultado = ['t' => time(), 'estado' => $commits ? 'ok' : 'vacio',
                          'commits' => $commits, 'truncado' => $truncado, 'parcial' => $parcial];
        }
        // Cualquier otro codigo (404 de repo privado sin token, 401, red caida)
        // queda como 'error' y se reintenta pronto por el TTL corto.

        self::guardar($clave, $resultado);
        return $resultado;
    }

    /**
     * Recorre las paginas de commits hasta juntar $limite o agotar el historial
     * reciente. Devuelve [commits, truncado, codigoHttpDeLaPrimeraLlamada].
     */
    private static function paginar(string $host, string $ruta, int $limite, string $rama, int $dias): array
    {
        $limite    = max(1, min(self::MAX_COMMITS, $limite));
        $porPagina = min(100, $limite);
        $paginas   = (int)ceil($limite / $porPagina);
        // Solo el tramo que se va a pintar: en un repo movido, pedir un año
        // cuando se miran 30 dias son diez llamadas tiradas a la basura.
        $desde = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, min(400, $dias)) * 86400);

        $commits = [];
        $truncado = false;
        $parcial = false;
        $codigo = 0;
        $t0 = microtime(true);
        for ($p = 1; $p <= $paginas; $p++) {
            $qs = 'per_page=' . $porPagina . '&page=' . $p . '&since=' . rawurlencode($desde)
                . ($rama !== '' ? '&ref_name=' . rawurlencode($rama) : '');
            [$c, $cuerpo] = self::api($host, self::ruta($ruta) . '/repository/commits?' . $qs);
            // Una pagina suelta puede fallar (limite de peticiones, hipo del
            // servidor): se reintenta una vez antes de rendirse.
            if ($c !== 200 && $p > 1) {
                usleep(400000);
                [$c, $cuerpo] = self::api($host, self::ruta($ruta) . '/repository/commits?' . $qs);
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
                $commits[] = self::commit($x);
            }
            if (count($lista) < $porPagina) break;      // no hay mas
            // Se para por tope de paginas o de tiempo: mas vale una vista
            // incompleta y avisada que una pagina que nunca carga.
            if ($p === $paginas || microtime(true) - $t0 > self::MAX_SEGUNDOS) {
                $truncado = true;
                break;
            }
        }
        return [$commits, $truncado, $codigo, $parcial];
    }

    /** Un commit de la API con la forma que consumen las vistas. */
    private static function commit(array $c): array
    {
        $correo = (string)($c['author_email'] ?? '');
        return [
            'sha'    => (string)($c['short_id'] ?? substr((string)($c['id'] ?? ''), 0, 7)),
            'msg'    => trim(strtok((string)($c['title'] ?? $c['message'] ?? ''), "\n")),
            // GitLab no devuelve el usuario en este endpoint. La parte local
            // del correo suele coincidir con el usuario de Git, y si no, el
            // panel cae al nombre del autor.
            'login'  => strtolower((string)strtok($correo, '@')),
            'nombre' => (string)($c['author_name'] ?? ''),
            'fecha'  => substr((string)($c['created_at'] ?? ''), 0, 10),
            'url'    => (string)($c['web_url'] ?? ''),
        ];
    }

    /** Ruta del proyecto codificada para la API ('grupo/repo' => 'grupo%2Frepo'). */
    private static function ruta(string $rutaProyecto): string
    {
        return '/projects/' . rawurlencode($rutaProyecto);
    }

    /* ---------- Cache en disco (mismo esquema que GitHub) ---------- */

    private static function archivoCache(): string
    {
        return __DIR__ . '/../data/cache_gitlab.json';
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

    /** GET a la API v4 del host indicado. Devuelve [codigoHttp, cuerpo]. */
    private static function api(string $host, string $ruta): array
    {
        $cabeceras = ['Accept: application/json'];
        $token = trim((string)(Config::get('gitlab_token') ?? ''));
        if ($token !== '') {
            // Los tokens personales de GitLab van en PRIVATE-TOKEN, no en Bearer
            $cabeceras[] = 'PRIVATE-TOKEN: ' . $token;
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $cabeceras),
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $cuerpo = @file_get_contents('https://' . $host . '/api/v4' . $ruta, false, $ctx);
        $codigo = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
        }
        return [$codigo, (string)$cuerpo];
    }
}
