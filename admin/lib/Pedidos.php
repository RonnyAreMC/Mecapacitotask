<?php
/**
 * Pedidos entre equipos: alguien le pide algo a otro equipo (o a una persona
 * de ese equipo) —un cambio, un dato, revisar algo— y lo siguen por aquí,
 * con su conversación, en vez de perderse en un chat.
 *
 * Colección 'pedidos':
 *   de_id, de_equipo, para_equipo (clave de Catalogo::equipos), para_id (0 =
 *   a todo el equipo), proyecto_id (0 = ninguno), titulo, detalle (texto),
 *   prioridad, fecha_limite (Y-m-d o ''), estado, tomado_por, hilo[]
 *   {id, autor, texto, creado}, resuelto_en, creado.
 *
 * Estados: abierto → en_curso (alguien del equipo lo toma) → hecho, o
 * rechazado; quien lo pidió puede cancelarlo o reabrirlo.
 *
 * Fuera de bootstrap a propósito (subida por FTP archivo por archivo).
 */
final class PedidoRepo
{
    /** estado => [rótulo, tono de mc-status] */
    public const ESTADOS = [
        'abierto'   => ['Abierto', 'info'],
        'en_curso'  => ['En curso', 'warning'],
        'hecho'     => ['Hecho', 'success'],
        'rechazado' => ['Rechazado', 'danger'],
        'cancelado' => ['Cancelado', 'neutral'],
    ];

    private JsonStore $s;

    public function __construct()
    {
        $this->s = new JsonStore('pedidos');
    }

    public function buscar(int $id): ?array
    {
        return $this->s->find($id);
    }

    public function todos(): array
    {
        $t = $this->s->all();
        usort($t, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return $t;
    }

    public function crear(array $d): array
    {
        return $this->s->insert($d + ['estado' => 'abierto', 'tomado_por' => 0, 'hilo' => [], 'resuelto_en' => '']);
    }

    public function actualizar(int $id, array $cambios): bool
    {
        return $this->s->update($id, $cambios);
    }

    public function responder(int $id, int $autor, string $texto): void
    {
        $p = $this->buscar($id);
        if (!$p) return;
        $hilo = (array)($p['hilo'] ?? []);
        $hilo[] = ['id' => count($hilo) + 1, 'autor' => $autor, 'texto' => $texto, 'creado' => date('Y-m-d H:i')];
        $this->s->update($id, ['hilo' => $hilo]);
    }

    public static function abierto(array $p): bool
    {
        return in_array($p['estado'] ?? 'abierto', ['abierto', 'en_curso'], true);
    }

    /** ¿Le toca atenderlo a esta persona? (es para ella o para su equipo) */
    public static function esParaMi(array $p, array $yo): bool
    {
        $mid = (int)($yo['id'] ?? 0);
        if ((int)($p['para_id'] ?? 0) > 0) return (int)$p['para_id'] === $mid || (int)($p['tomado_por'] ?? 0) === $mid;
        return MiembroRepo::equipoDe($yo) === ($p['para_equipo'] ?? '') || (int)($p['tomado_por'] ?? 0) === $mid;
    }

    /** ¿Puede verlo? Quien lo pidió, el equipo que lo recibe y el administrador. */
    public static function puedeVer(array $p, array $yo): bool
    {
        return Auth::esAdmin() || (int)($p['de_id'] ?? 0) === (int)($yo['id'] ?? 0) || self::esParaMi($p, $yo);
    }

    /** A quién le llega: la persona elegida o todo el equipo. */
    public static function destinatarios(array $p, MiembroRepo $miembros): array
    {
        if ((int)($p['para_id'] ?? 0) > 0) return [(int)$p['para_id']];
        $ids = [];
        foreach ($miembros->todos() as $m) {
            if (MiembroRepo::equipoDe($m) === ($p['para_equipo'] ?? '') && !MiembroRepo::tieneAcceso($m, 'supervisor')) {
                $ids[] = (int)$m['id'];
            }
        }
        return $ids;
    }

    /** Cuántos tiene abiertos para su equipo (o para ella) quien tiene la sesión. */
    public function pendientesPara(array $yo): int
    {
        return count(array_filter($this->s->all(), fn($p) => self::abierto($p) && self::esParaMi($p, $yo) && (int)($p['de_id'] ?? 0) !== (int)($yo['id'] ?? 0)));
    }
}
