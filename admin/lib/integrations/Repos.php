<?php
/**
 * Repos - punto unico para leer datos de un repositorio, venga de donde venga.
 *
 * El panel guarda la URL tal cual la pegan, asi que aqui se decide por el host
 * a quien preguntarle: GitHub o GitLab. Las vistas llaman siempre a esta clase
 * y reciben la misma forma de datos en los dos casos.
 *
 * Anadir un proveedor nuevo (Bitbucket, Gitea) es escribir su clase con los
 * mismos metodos y sumarlo a proveedor().
 */
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/GitLab.php';

class Repos
{
    /** Proveedor de una URL: 'github', 'gitlab' o '' si no se reconoce. */
    public static function proveedor(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://([^/]+)#i', $url, $m)) {
            return '';
        }
        $host = strtolower($m[1]);

        if (str_ends_with($host, 'github.com')) {
            return 'github';
        }
        // gitlab.com y cualquier instancia cuyo host lo lleve en el nombre
        // (gitlab.miempresa.com, git.gitlab.io, ...).
        if (str_contains($host, 'gitlab')) {
            return 'gitlab';
        }
        // Instancia autogestionada con dominio propio: se declara en Ajustes.
        foreach (self::hostsGitLab() as $propio) {
            if ($host === $propio || str_ends_with($host, '.' . $propio)) {
                return 'gitlab';
            }
        }
        return '';
    }

    /** Hosts de GitLab autogestionado declarados en Ajustes (uno por línea o coma). */
    private static function hostsGitLab(): array
    {
        $crudo = trim((string)(Config::get('gitlab_host') ?? ''));
        if ($crudo === '') return [];
        $out = [];
        foreach (preg_split('/[\s,]+/', $crudo) as $h) {
            $h = strtolower(trim($h));
            // Aceptar que peguen la URL completa y no solo el host
            if (preg_match('#^https?://([^/]+)#i', $h, $m)) $h = strtolower($m[1]);
            $h = trim($h, '/');
            if ($h !== '') $out[] = $h;
        }
        return $out;
    }

    /** ¿El panel sabe leer datos de esta URL? */
    public static function soportado(?string $url): bool
    {
        return self::proveedor($url) !== '';
    }

    /** Nombres de las ramas del repositorio. */
    public static function ramas(?string $url): array
    {
        return match (self::proveedor($url)) {
            'github' => GitHub::ramas($url),
            'gitlab' => GitLab::ramas($url),
            default  => [],
        };
    }

    /**
     * Commits recientes, con la forma que consume el gráfico de aportes:
     * ['estado'=>'ok'|'error'|'vacio'|'sin_repo', 'commits'=>[
     *   ['sha'=>, 'msg'=>, 'login'=>, 'nombre'=>, 'fecha'=>, 'url'=>], ... ]]
     */
    public static function commitsRecientes(?string $url, int $limite = 60, string $rama = '', int $dias = 400): array
    {
        return match (self::proveedor($url)) {
            'github' => GitHub::commitsRecientes($url, $limite, $rama, $dias),
            'gitlab' => GitLab::commitsRecientes($url, $limite, $rama, $dias),
            default  => ['estado' => 'sin_repo', 'commits' => [], 'truncado' => false],
        };
    }

    /** Icono de marca para el botón del repositorio. */
    public static function icono(?string $url): string
    {
        return match (self::proveedor($url)) {
            'github' => 'fa-brands fa-github',
            'gitlab' => 'fa-brands fa-gitlab',
            default  => 'fa-solid fa-code-branch',
        };
    }

    /** Clase del botón del repositorio (cada proveedor con su color de marca). */
    public static function clase(?string $url): string
    {
        return match (self::proveedor($url)) {
            'github' => 'btn-github',
            'gitlab' => 'btn-gitlab',
            default  => 'btn-repo',
        };
    }

    /** Nombre legible del proveedor, para tooltips y avisos. */
    public static function etiqueta(?string $url): string
    {
        return match (self::proveedor($url)) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            default  => 'Repositorio',
        };
    }
}
