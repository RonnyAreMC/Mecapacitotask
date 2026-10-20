<?php
/**
 * Tutoriales en video del panel. Se publican pegando el enlace de YouTube o de
 * Google Drive y se ven en un reproductor dentro del panel (un modal), sin
 * salir de la pantalla. Un tutorial puede además «aparecer» en una pantalla
 * concreta (por ejemplo, el correo): ahí sale el botón «Ver tutorial».
 *
 * Errores típicos que se evitan:
 *  - 153 de YouTube («error de configuración del reproductor»): el marco va con
 *    referrerpolicy="strict-origin-when-cross-origin" (YouTube exige saber
 *    desde qué sitio se inserta).
 *  - Listas, carpetas de Drive o enlaces de otros sitios: se rechazan al pegar,
 *    con el motivo.
 *  - 101/150 (el dueño no permite insertarlo) y Drive privado: no se pueden
 *    arreglar desde aquí; el reproductor lo explica y ofrece abrirlo afuera.
 *
 * Se carga con is_file (no desde bootstrap): si al subir por FTP falta, el
 * panel sigue igual y solo no aparecen los tutoriales.
 */
final class Tutoriales
{
    /** Pantallas donde puede aparecer el botón «Ver tutorial». */
    public const LUGARES = [
        ''               => 'Solo en Tutoriales',
        'correo'         => 'Correo InnoTech',
        'dispositivos'   => 'Notificaciones en tus dispositivos',
        'notificaciones' => 'Avisos del panel',
        'pedidos'        => 'Pedidos entre equipos',
        'proyecto'       => 'Tablero del proyecto',
        'ia'             => 'Consola de IA',
    ];

    private static function repo(): JsonStore
    {
        return new JsonStore('tutoriales');
    }

    /** ['proveedor' => 'youtube'|'drive', 'id' => …] o ['error' => motivo legible]. */
    public static function analizarEnlace(string $url): array
    {
        $url = trim($url);
        if ($url === '') return ['error' => 'Pega el enlace del video.'];
        if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return ['error' => 'Ese enlace no es válido.'];
        $host = strtolower((string)preg_replace('/^(www|m)\./i', '', (string)$p['host']));
        $ruta = (string)($p['path'] ?? '');
        parse_str((string)($p['query'] ?? ''), $q);
        $yt = '/^[A-Za-z0-9_-]{11}$/';

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            $id = is_string($q['v'] ?? null) ? (string)$q['v'] : '';
            if ($id === '' && preg_match('~^/(?:shorts|embed|live|v)/([A-Za-z0-9_-]{11})~', $ruta, $m)) $id = $m[1];
            if ($id === '' && isset($q['list'])) return ['error' => 'Es una lista de reproducción: abre el video que quieres y copia su enlace.'];
            if (!preg_match($yt, $id)) return ['error' => 'Ese enlace de YouTube no lleva a un video.'];
            return ['proveedor' => 'youtube', 'id' => $id];
        }
        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($ruta, '/'))[0] ?? '';
            if (!preg_match($yt, $id)) return ['error' => 'Ese enlace corto de YouTube no es válido.'];
            return ['proveedor' => 'youtube', 'id' => $id];
        }
        if (in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
            if (str_contains($ruta, '/folders/')) return ['error' => 'Es una carpeta de Drive: abre el video y copia el enlace del archivo.'];
            $id = '';
            if (preg_match('~/file/d/([A-Za-z0-9_-]{20,})~', $ruta, $m)) $id = $m[1];
            elseif (is_string($q['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{20,}$/', (string)$q['id'])) $id = (string)$q['id'];
            if ($id === '') return ['error' => 'Ese enlace de Drive no lleva a un archivo.'];
            return ['proveedor' => 'drive', 'id' => $id];
        }
        return ['error' => 'Solo se aceptan enlaces de YouTube o de Google Drive.'];
    }

    /** Dirección para el reproductor dentro del panel. */
    public static function urlInsertar(array $t): string
    {
        $id = rawurlencode((string)$t['video_id']);
        return $t['proveedor'] === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1&playsinline=1&autoplay=1'
            : 'https://drive.google.com/file/d/' . $id . '/preview';
    }

    /** Dirección para abrirlo afuera (si no se deja insertar). */
    public static function urlAbrir(array $t): string
    {
        $id = rawurlencode((string)$t['video_id']);
        return $t['proveedor'] === 'youtube' ? 'https://www.youtube.com/watch?v=' . $id : 'https://drive.google.com/file/d/' . $id . '/view';
    }

    public static function miniatura(array $t): string
    {
        $id = rawurlencode((string)$t['video_id']);
        return $t['proveedor'] === 'youtube' ? 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' : 'https://drive.google.com/thumbnail?id=' . $id . '&sz=w640';
    }

    /** Los atributos que abren el reproductor (los lee assets/ds.js). */
    public static function datos(array $t): string
    {
        return 'data-tutorial-ver data-src="' . e(self::urlInsertar($t)) . '" data-abrir="' . e(self::urlAbrir($t)) . '"'
            . ' data-titulo="' . e((string)$t['titulo']) . '" data-proveedor="' . e((string)$t['proveedor']) . '"';
    }

    /** Todos, el más nuevo primero. */
    public static function todos(): array
    {
        $l = self::repo()->all();
        usort($l, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return $l;
    }

    /** El tutorial (el más nuevo) asignado a una pantalla, o null. */
    public static function delLugar(string $lugar): ?array
    {
        if ($lugar === '') return null;
        foreach (self::todos() as $t) if (($t['lugar'] ?? '') === $lugar) return $t;
        return null;
    }

    /** Publica uno. Devuelve el tutorial o el motivo (string) si algo no sirve. */
    public static function crear(array $d, int $autor): array|string
    {
        $titulo = trim((string)($d['titulo'] ?? ''));
        if ($titulo === '') return 'Ponle un título al tutorial.';
        $v = self::analizarEnlace((string)($d['enlace'] ?? ''));
        if (isset($v['error'])) return $v['error'];
        $lugar = (string)($d['lugar'] ?? '');
        if (!isset(self::LUGARES[$lugar])) $lugar = '';
        return self::repo()->insert([
            'titulo'      => mb_substr($titulo, 0, 120),
            'descripcion' => mb_substr(trim((string)($d['descripcion'] ?? '')), 0, 400),
            'enlace'      => mb_substr(trim((string)$d['enlace']), 0, 500),
            'proveedor'   => $v['proveedor'],
            'video_id'    => $v['id'],
            'lugar'       => $lugar,
            'autor'       => $autor,
            'creado'      => date('Y-m-d H:i'),
        ]);
    }

    public static function quitar(int $id): ?array
    {
        $t = self::repo()->find($id);
        if ($t) self::repo()->delete($id);
        return $t;
    }

    /** Botón «Ver tutorial» para una pantalla ('' si esa pantalla no tiene). */
    public static function boton(string $lugar, string $tam = 'md'): string
    {
        $t = self::delLugar($lugar);
        if (!$t) return '';
        $tam = $tam === 'sm' ? 'sm' : 'md';
        return '<button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--' . $tam . ' tut-boton" ' . self::datos($t) . '>'
            . UI::iconoDS('play', $tam === 'sm' ? 16 : 18) . '<span class="mc-btn__label">Ver tutorial</span></button>';
    }
}
