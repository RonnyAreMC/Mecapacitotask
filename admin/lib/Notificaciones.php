<?php
/**
 * Notificaciones dentro del panel: la campana de la barra de arriba.
 * Van aparte del correo (salen aunque el correo no esté configurado) y a la
 * misma gente que el correo de cada evento: tarea asignada, reunión, pedido
 * de otro equipo, requerimiento, observación, dependencia, intercambio…
 *
 * Colección 'notificaciones', una fila por persona y aviso:
 *   para (id del miembro), tipo, titulo, texto, url (relativa al panel),
 *   de (quién lo provocó), leida (0/1), creado.
 * Nadie se avisa a sí mismo.
 *
 * El almacén carga la colección entera en cada petición, así que se poda
 * una vez al día: las leídas de más de 30 días y todo lo de más de 90.
 *
 * Fuera de bootstrap a propósito (el panel se sube por FTP archivo por
 * archivo): quien la usa la carga con require_once si el archivo existe.
 */
final class Notif
{
    /** tipo => [rótulo, ícono del design system, tono de la baldosa] */
    public const TIPOS = [
        'tarea'         => ['Tarea', 'completar', 'azul'],
        'tarea_hecha'   => ['Tarea terminada', 'exito', 'verde'],
        'reunion'       => ['Reunión', 'agenda', 'cian'],
        'pedido'        => ['Pedido entre equipos', 'usuarios', 'verde'],
        'requerimiento' => ['Requerimiento', 'documento', 'naranja'],
        'observacion'   => ['Observación', 'mensajes', 'amarillo'],
        'dependencia'   => ['Dependencia', 'relacionar', 'petroleo'],
        'intercambio'   => ['Intercambio de tareas', 'transferencia', 'petroleo'],
        'proyecto'      => ['Proyecto', 'carpeta', 'azul'],
        'correo'        => ['Correo', 'correo', 'azul'],
        'deploy'        => ['Subida al servidor', 'publicar', 'verde'],
        'release'       => ['Releases', 'ruta', 'petroleo'],
    ];

    private static ?JsonStore $store = null;

    private static function store(): JsonStore
    {
        return self::$store ??= new JsonStore('notificaciones');
    }

    /**
     * Crea el aviso para cada id de $para (sin repetir y sin quien lo provoca).
     * $de = -1: quien tiene la sesión. Devuelve a cuántos les llegó.
     */
    public static function crear(array $para, string $tipo, string $titulo, string $texto = '', string $url = '', int $de = -1): int
    {
        if ($de < 0) $de = (int)(Auth::usuario()['id'] ?? 0);
        if (!isset(self::TIPOS[$tipo])) $tipo = 'proyecto';
        $url = self::urlSegura($url);
        $n = 0;
        $creadas = [];
        try {
            foreach (array_unique(array_map('intval', $para)) as $mid) {
                if ($mid <= 0 || $mid === $de) continue;
                $fila = self::store()->insert([
                    'para'   => $mid,
                    'tipo'   => $tipo,
                    'titulo' => mb_substr(trim($titulo), 0, 140),
                    'texto'  => mb_substr(trim(preg_replace('/\s+/u', ' ', $texto)), 0, 280),
                    'url'    => $url,
                    'de'     => $de,
                    'leida'  => 0,
                ]);
                $creadas[$mid] = (int)($fila['id'] ?? 0);
                $n++;
            }
            if ($n) self::podar();
        } catch (\Throwable $e) {
            // Un aviso que no se pudo guardar nunca debe tumbar la acción que lo provocó
            error_log('Notif: ' . $e->getMessage());
        }
        // También como notificación nativa del sistema (Mac, Windows, Android, iPhone) en todos
        // los dispositivos que la persona activó, para cualquier tipo de aviso de la campana.
        // Sale al terminar la petición: quien hizo la acción no espera.
        if ($creadas && is_file(__DIR__ . '/Push.php')) {
            try {
                require_once __DIR__ . '/Push.php';
                foreach ($creadas as $mid => $id) {
                    Push::avisar([$mid], [
                        'titulo' => trim($titulo),
                        'texto'  => trim(preg_replace('/\s+/u', ' ', $texto)),
                        'url'    => 'notificaciones.php?abrir=' . $id,
                        'tag'    => 'notif-' . $id,
                        'n'      => self::pendientes($mid),     // el número del ícono de la app instalada
                    ], 'avisos');
                }
            } catch (\Throwable $e) {
                error_log('Push: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Las de una persona, de la más nueva a la más vieja. $desde: solo las de id mayor. */
    public static function de(int $mid, int $limite = 20, int $desde = 0): array
    {
        $out = array_filter(self::store()->where('para', $mid), fn($n) => (int)$n['id'] > $desde);
        usort($out, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return array_slice(array_values($out), 0, $limite);
    }

    public static function sinLeer(int $mid): int
    {
        return count(array_filter(self::store()->where('para', $mid), fn($n) => empty($n['leida'])));
    }

    /** Lo que marca la campana en rojo: avisos sin leer + correos InnoTech sin leer (el último conteo, sin ir al buzón). */
    public static function pendientes(int $mid): int
    {
        $correo = 0;
        try {
            if (class_exists('Correo') && Correo::activo()) $correo = (int)(Correo::noLeidos($mid) ?? 0);
        } catch (\Throwable $e) { /* sin correo: solo los avisos */ }
        return self::sinLeer($mid) + $correo;
    }

    public static function buscar(int $id): ?array
    {
        return self::store()->find($id);
    }

    /** Marca leída una notificación, solo si es de esa persona. */
    public static function leer(int $id, int $mid): ?array
    {
        $n = self::store()->find($id);
        if (!$n || (int)$n['para'] !== $mid) return null;
        if (empty($n['leida'])) self::store()->update($id, ['leida' => 1]);
        return $n;
    }

    public static function leerTodas(int $mid): int
    {
        $c = 0;
        foreach (self::store()->where('para', $mid) as $n) {
            if (empty($n['leida'])) { self::store()->update((int)$n['id'], ['leida' => 1]); $c++; }
        }
        return $c;
    }

    /** Lo que necesita la campana (también para el JSON de notificaciones.php). */
    public static function paraJs(array $n, array $miembros): array
    {
        [$rotulo, $ico, $tono] = self::TIPOS[$n['tipo'] ?? ''] ?? self::TIPOS['proyecto'];
        $quien = $miembros[(int)($n['de'] ?? 0)]['nombre'] ?? '';
        return [
            'id'     => (int)$n['id'],
            'tipo'   => (string)($n['tipo'] ?? ''),
            'rotulo' => $rotulo,
            'icono'  => $ico,
            'tono'   => $tono,
            'titulo' => (string)($n['titulo'] ?? ''),
            'texto'  => (string)($n['texto'] ?? ''),
            'quien'  => $quien,
            'leida'  => !empty($n['leida']),
            'hace'   => self::hace((string)($n['creado'] ?? '')),
            'fecha'  => (string)($n['creado'] ?? ''),
            'abrir'  => 'notificaciones.php?abrir=' . (int)$n['id'],
        ];
    }

    /** «hace 5 min», «ayer», «12 oct». */
    public static function hace(string $fecha): string
    {
        $t = strtotime($fecha);
        if (!$t) return '';
        $s = time() - $t;
        if ($s < 60) return 'ahora';
        if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
        if ($s < 86400 && date('Y-m-d', $t) === date('Y-m-d')) return 'hace ' . intdiv($s, 3600) . ' h';
        if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'ayer, ' . date('H:i', $t);
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        return (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
    }

    /** Solo enlaces dentro del panel (nada de «https://…» ni «//otro-sitio»). */
    public static function urlSegura(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|\\\\)#i', $url) || str_contains($url, "\n")) return '';
        return ltrim($url, '/');
    }

    /** Una vez al día: fuera las leídas de más de 30 días y todo lo de más de 90. */
    private static function podar(): void
    {
        $marca = __DIR__ . '/../data/notif_poda.txt';
        if (is_file($marca) && trim((string)@file_get_contents($marca)) === date('Y-m-d')) return;
        @file_put_contents($marca, date('Y-m-d'));
        $leidas = date('Y-m-d H:i', strtotime('-30 days'));
        $todas  = date('Y-m-d H:i', strtotime('-90 days'));
        foreach (self::store()->all() as $n) {
            $c = (string)($n['creado'] ?? '');
            if ($c !== '' && ($c < $todas || (!empty($n['leida']) && $c < $leidas))) {
                self::store()->delete((int)$n['id']);
            }
        }
    }
}
