<?php
/**
 * Tutoriales en video del panel. Se publican pegando el enlace de YouTube o de
 * Google Drive y se ven en un reproductor dentro del panel (un modal), sin
 * salir de la pantalla. Con «Avisar a todos», a cada persona le sale el video
 * en una ventana al entrar al panel (con su nombre), una sola vez: lo ve o lo
 * cierra (tabla «tutoriales_vistos»).
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
    /** Antes un tutorial podía tener su botón en una pantalla; ya no se usa (queda por compatibilidad). */
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

    /* ---------------- «Avisar a todos» ---------------- */

    private static function vistos(): JsonStore
    {
        return new JsonStore('tutoriales_vistos');
    }

    /** El tutorial con «Avisar a todos» (el más nuevo) que esta persona todavía no vio ni cerró, o null. */
    public static function pendiente(int $mid): ?array
    {
        if ($mid <= 0) return null;
        $avisar = array_filter(self::todos(), fn($t) => !empty($t['avisar']) && (int)($t['autor'] ?? 0) !== $mid);
        if (!$avisar) return null;
        $ya = [];
        foreach (self::vistos()->all() as $v) if ((int)$v['miembro_id'] === $mid) $ya[(int)$v['tutorial_id']] = true;
        foreach ($avisar as $t) if (!isset($ya[(int)$t['id']])) return $t;
        return null;
    }

    /** Lo vio o cerró la ventana: ya no se le vuelve a mostrar. */
    public static function marcarVisto(int $tid, int $mid, string $como = 'cerrado'): void
    {
        if ($tid <= 0 || $mid <= 0) return;
        foreach (self::vistos()->all() as $v) {
            if ((int)$v['tutorial_id'] === $tid && (int)$v['miembro_id'] === $mid) {
                if ($como === 'visto' && ($v['como'] ?? '') !== 'visto') self::vistos()->update((int)$v['id'], ['como' => 'visto']);
                return;
            }
        }
        self::vistos()->insert(['tutorial_id' => $tid, 'miembro_id' => $mid, 'como' => $como === 'visto' ? 'visto' : 'cerrado', 'cuando' => date('Y-m-d H:i')]);
    }

    /** ['vieron' => cuántos le dieron play, 'cerraron' => cuántos solo la cerraron] */
    public static function alcance(int $tid): array
    {
        $out = ['vieron' => 0, 'cerraron' => 0];
        foreach (self::vistos()->all() as $v) {
            if ((int)$v['tutorial_id'] !== $tid) continue;
            $out[($v['como'] ?? '') === 'visto' ? 'vieron' : 'cerraron']++;
        }
        return $out;
    }

    public static function avisar(int $id, bool $on): ?array
    {
        $t = self::repo()->find($id);
        if (!$t) return null;
        self::repo()->update($id, ['avisar' => $on] + ($on ? ['avisado' => date('Y-m-d H:i')] : []));
        return self::repo()->find($id);
    }

    /** Publica uno. Devuelve el tutorial o el motivo (string) si algo no sirve. */
    public static function crear(array $d, int $autor): array|string
    {
        $titulo = trim((string)($d['titulo'] ?? ''));
        if ($titulo === '') return 'Ponle un título al tutorial.';
        $v = self::analizarEnlace((string)($d['enlace'] ?? ''));
        if (isset($v['error'])) return $v['error'];
        $t = self::repo()->insert([
            'titulo'      => mb_substr($titulo, 0, 120),
            'descripcion' => mb_substr(trim((string)($d['descripcion'] ?? '')), 0, 400),
            'enlace'      => mb_substr(trim((string)$d['enlace']), 0, 500),
            'proveedor'   => $v['proveedor'],
            'video_id'    => $v['id'],
            'lugar'       => '',
            'avisar'      => !empty($d['avisar']),
            'avisado'     => !empty($d['avisar']) ? date('Y-m-d H:i') : '',
            'autor'       => $autor,
            'creado'      => date('Y-m-d H:i'),
        ]);
        return $t;
    }

    public static function quitar(int $id): ?array
    {
        $t = self::repo()->find($id);
        if ($t) self::repo()->delete($id);
        return $t;
    }

    /** Ya no hay botones por pantalla (el aviso es la ventana al entrar); queda para no romper archivos viejos. */
    public static function boton(string $lugar, string $tam = 'md'): string
    {
        return '';
    }

    /**
     * La ventana que sale al entrar al panel con el tutorial pendiente (o '').
     * Saluda por el nombre; el video se carga solo si la persona le da play.
     * Al cerrarla (con el video visto o no) no vuelve a salir.
     */
    public static function modalAviso(array $yo): string
    {
        $t = self::pendiente((int)($yo['id'] ?? 0));
        if (!$t) return '';
        $nombre = explode(' ', trim((string)($yo['nombre'] ?? '')))[0];
        $desc = trim((string)($t['descripcion'] ?? ''));
        $play = '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l10-6.5z"/></svg>';
        $x = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg>';
        ob_start(); ?>
<dialog class="ds-modal ds-tutorial tut-aviso" id="dlg-tut-aviso" data-tut-aviso="<?= (int)$t['id'] ?>" aria-labelledby="tut-aviso-t">
  <header class="ds-tutorial__cab">
    <span class="ds-tutorial__ico" aria-hidden="true"><?= $play ?></span>
    <div class="tut-aviso__txt">
      <p class="tut-aviso__hola">¡Hola<?= $nombre !== '' ? ', ' . e($nombre) : '' ?>! Hay un tutorial nuevo para ti</p>
      <h2 class="ds-modal__title" id="tut-aviso-t"><?= e((string)$t['titulo']) ?></h2>
    </div>
    <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-tut-aviso-cerrar aria-label="Cerrar"><?= $x ?></button>
  </header>
  <div class="ds-tutorial__marco">
    <button type="button" class="tut-aviso__play" data-src="<?= e(self::urlInsertar($t)) ?>" aria-label="Ver «<?= e((string)$t['titulo']) ?>»">
      <img src="<?= e(self::miniatura($t)) ?>" alt="" referrerpolicy="no-referrer" onerror="this.remove()">
      <span class="tut-play"><?= $play ?></span>
      <span class="tut-aviso__cta">Ver el video</span>
    </button>
  </div>
  <?php if ($desc !== ''): ?><p class="ds-tutorial__pie"><?= e($desc) ?></p><?php endif; ?>
  <footer class="ds-modal__foot">
    <a class="mc-btn mc-btn--ghost mc-btn--md" href="tutoriales.php"><span class="mc-btn__label">Ver todos los tutoriales</span></a>
    <button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" data-tut-aviso-cerrar><span class="mc-btn__label">Cerrar</span></button>
  </footer>
</dialog>
<script>
(() => {
  const d = document.getElementById('dlg-tut-aviso');
  if (!d || !d.showModal) return;
  let vio = false;
  const marcar = () => {
    const fd = new FormData();
    fd.append('accion', 'tutorial_visto');
    fd.append('id', d.dataset.tutAviso);
    fd.append('como', vio ? 'visto' : 'cerrado');
    if (!(navigator.sendBeacon && navigator.sendBeacon('actions.php', fd))) fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(() => {});
  };
  d.querySelector('.tut-aviso__play').addEventListener('click', (e) => {
    const b = e.currentTarget;
    const f = document.createElement('iframe');
    f.src = b.dataset.src;
    f.title = 'Tutorial en video';
    f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen';
    f.allowFullscreen = true;
    f.referrerPolicy = 'strict-origin-when-cross-origin';   // sin esto YouTube muestra el error 153
    b.replaceWith(f);
    vio = true;
  });
  d.querySelectorAll('[data-tut-aviso-cerrar]').forEach((b) => b.addEventListener('click', () => d.close()));
  d.addEventListener('click', (e) => { if (e.target === d) d.close(); });
  d.addEventListener('close', () => {
    const f = d.querySelector('iframe');
    if (f) f.src = 'about:blank';
    marcar();
  }, { once: true });
  // Si hay otra ventana abierta (por ejemplo, la del teléfono), espera a que se cierre
  const abrir = () => { if (document.querySelector('dialog[open]')) { setTimeout(abrir, 1500); return; } d.showModal(); };
  setTimeout(abrir, 900);
})();
</script>
        <?php
        return (string)ob_get_clean();
    }
}
