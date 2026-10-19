<?php
/**
 * Pedidos entre equipos: lo que otros equipos te piden (a ti o a tu equipo)
 * y lo que tú pediste, cada uno con su conversación y su estado. El pedido
 * nuevo se hace desde el botón de la barra de arriba (UI::dialogPedido).
 */
require_once __DIR__ . '/lib/bootstrap.php';
// Fuera de bootstrap a propósito: si al subir por FTP falta, falla solo esto.
require_once __DIR__ . '/lib/Pedidos.php';
if (is_file(__DIR__ . '/lib/Notificaciones.php')) require_once __DIR__ . '/lib/Notificaciones.php';
Auth::requiereLogin();

/** «hace 5 min» (con lib/Notificaciones.php); si no está, la fecha tal cual. */
function haceTexto(string $f): string
{
    return class_exists('Notif') ? Notif::hace($f) : substr($f, 0, 16);
}

/** Abiertos primero; dentro, el más nuevo arriba. */
function ordenPedidos(array $l): array
{
    usort($l, fn($a, $b) => [PedidoRepo::abierto($b), (int)$b['id']] <=> [PedidoRepo::abierto($a), (int)$a['id']]);
    return $l;
}

$yo  = Auth::usuario();
$mid = (int)$yo['id'];
$miembros = (new MiembroRepo())->mapa();
$equipos  = Catalogo::equipos();
$proyMapa = [];
foreach ((new ProyectoRepo())->todos() as $p) $proyMapa[(int)$p['id']] = $p;
$prioridades = Catalogo::prioridades();

$todos = (new PedidoRepo())->todos();
$recibidos = array_values(array_filter($todos, fn($p) => PedidoRepo::esParaMi($p, $yo) && (int)$p['de_id'] !== $mid));
$enviados  = array_values(array_filter($todos, fn($p) => (int)$p['de_id'] === $mid));
$recibidos = ordenPedidos($recibidos);
$enviados  = ordenPedidos($enviados);
$abiertos  = fn(array $l) => count(array_filter($l, [PedidoRepo::class, 'abierto']));

$tabs = ['recibidos' => ['Para mí y mi equipo', 'usuarios', $abiertos($recibidos)], 'enviados' => ['Los que pedí', 'enviar', $abiertos($enviados)]];
if (Auth::esAdmin()) $tabs['todos'] = ['Todos', 'global', $abiertos($todos)];
$listas = ['recibidos' => $recibidos, 'enviados' => $enviados, 'todos' => Auth::esAdmin() ? ordenPedidos($todos) : []];

$nombre = fn(int $id) => (string)($miembros[$id]['nombre'] ?? 'Alguien');
$equipoTxt = fn(string $k) => (string)($equipos[$k][0] ?? $k);
$puedePedir = !Auth::esSupervisor();

/** Una tarjeta de pedido, con su conversación y lo que puede hacer quien mira. */
$tarjeta = function (array $p) use ($yo, $mid, $miembros, $nombre, $equipoTxt, $proyMapa, $prioridades, $puedePedir): string {
    [$estTxt, $estTono] = PedidoRepo::ESTADOS[$p['estado'] ?? 'abierto'] ?? PedidoRepo::ESTADOS['abierto'];
    $abierto = PedidoRepo::abierto($p);
    $esMio   = (int)$p['de_id'] === $mid;
    $atiendo = (PedidoRepo::esParaMi($p, $yo) || Auth::esAdmin()) && $puedePedir;
    $id      = (int)$p['id'];
    $para    = (int)($p['para_id'] ?? 0) > 0 ? $nombre((int)$p['para_id']) . ' (' . $equipoTxt((string)$p['para_equipo']) . ')' : 'Todo el equipo de ' . $equipoTxt((string)$p['para_equipo']);
    $h  = '<section class="mc-card ds-pedido' . ($abierto ? '' : ' es-cerrado') . '" id="p-' . $id . '">';
    $h .= '<header class="mc-card__head">' . UI::avatar($miembros[(int)$p['de_id']] ?? null, 36)
        . '<div class="mc-card__titles"><h3 class="mc-card__title">' . e((string)$p['titulo']) . '</h3>'
        . '<p class="mc-card__sub"><b>' . e($nombre((int)$p['de_id'])) . '</b> · ' . e($equipoTxt((string)($p['de_equipo'] ?? ''))) . ' → ' . e($para)
        . ' · ' . e(haceTexto((string)($p['creado'] ?? ''))) . '</p></div>'
        . '<span class="mc-status mc-status--pill mc-status--' . $estTono . '"><span class="mc-status__content"><span class="mc-status__label">' . e($estTxt) . '</span></span></span></header>';
    $h .= '<div class="mc-card__body ds-grid" style="gap:var(--space-3)">';
    // Datos del pedido
    $chips = [];
    $chips[] = 'Prioridad: <b>' . e($prioridades[$p['prioridad'] ?? ''][0] ?? (string)($p['prioridad'] ?? '')) . '</b>';
    if (!empty($p['fecha_limite'])) $chips[] = 'Para el <b>' . e((string)$p['fecha_limite']) . '</b>' . ($abierto && $p['fecha_limite'] < date('Y-m-d') ? ' <span style="color:var(--danger-ink)">(vencido)</span>' : '');
    if (!empty($p['proyecto_id']) && isset($proyMapa[(int)$p['proyecto_id']])) $chips[] = 'Proyecto: <a href="proyecto.php?id=' . (int)$p['proyecto_id'] . '">' . e($proyMapa[(int)$p['proyecto_id']]['nombre']) . '</a>';
    if ((int)($p['tomado_por'] ?? 0) > 0) $chips[] = 'Lo lleva <b>' . e($nombre((int)$p['tomado_por'])) . '</b>';
    // Cada dato en su <span>: en un contenedor flex, el espacio tras «Prioridad:» se perdía
    $h .= '<p class="ds-pedido__datos"><span>' . implode('</span><span class="ds-pedido__sep">·</span><span>', $chips) . '</span></p>';
    if (trim((string)($p['detalle'] ?? '')) !== '') $h .= '<div class="ds-pedido__detalle">' . nl2br(e((string)$p['detalle'])) . '</div>';
    // Conversación
    $hilo = (array)($p['hilo'] ?? []);
    if ($hilo) {
        $h .= '<ol class="ds-pedido__hilo">';
        foreach ($hilo as $m) {
            $h .= '<li>' . UI::avatar($miembros[(int)$m['autor']] ?? null, 28) . '<div><p class="ds-pedido__quien"><b>' . e($nombre((int)$m['autor'])) . '</b> · ' . e(haceTexto((string)$m['creado'])) . '</p>'
                . '<p class="ds-pedido__msg">' . nl2br(e((string)$m['texto'])) . '</p></div></li>';
        }
        $h .= '</ol>';
    }
    // Qué puede hacer quien mira
    if ($puedePedir && ($esMio || $atiendo)) {
        $h .= '<form method="post" action="actions.php" class="ds-pedido__form">'
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="pedido_responder">'
            . '<div class="mc-input mc-input--md ds-input-area ds-pedido__caja"><textarea class="mc-input__control" name="texto" rows="2" maxlength="2000" placeholder="'
            . ($abierto ? 'Escribe una respuesta o una nota…' : 'Agregar una nota…') . '" aria-label="Respuesta"></textarea></div>'
            . '<div class="ds-fila" style="justify-content:flex-end;gap:var(--space-2)">';
        $boton = fn(string $op, string $txt, string $clase, string $ico) => '<button class="mc-btn mc-btn--sm ' . $clase . '" name="op" value="' . $op
            . '" formaction="actions.php" onclick="this.form.accion.value=\'pedido_estado\'">' . UI::iconoDS($ico, 16) . '<span class="mc-btn__label">' . $txt . '</span></button>';
        if ($atiendo && ($p['estado'] ?? '') === 'abierto') $h .= $boton('tomar', 'Lo tomo', 'mc-btn--outline mc-btn--t-azul', 'perfil');
        if ($atiendo && $abierto) $h .= $boton('rechazar', 'Rechazar', 'mc-btn--ghost', 'cerrar') . $boton('hecho', 'Marcar hecho', 'mc-btn--outline mc-btn--t-verde', 'check');
        if ($esMio && $abierto) $h .= $boton('cancelar', 'Cancelar pedido', 'mc-btn--ghost', 'cerrar');
        if ($esMio && !$abierto) $h .= $boton('reabrir', 'Reabrir', 'mc-btn--outline mc-btn--t-azul', 'reintentar');
        $h .= '<button class="mc-btn mc-btn--primary mc-btn--sm">' . UI::iconoDS('enviar', 16) . '<span class="mc-btn__label">Responder</span></button>';
        $h .= '</div></form>';
    }
    return $h . '</div></section>';
};

UI::inicio('Pedidos entre equipos', 'pedidos');
UI::cabecera(
    'Pedidos entre <span class="text-secondary">equipos</span>',
    'Pídele algo a otro equipo (o a alguien de ese equipo) y síguelo aquí: quién lo tomó, qué respondió y cuándo quedó hecho. A cada paso le llega el aviso a la otra parte, en la campana y por correo.',
    $puedePedir ? '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-pedido-nuevo>' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Nuevo pedido</span></button>' : ''
);
?>
<div class="ds" id="pedidos">
  <div class="mc-tabs mc-tabs--line" role="tablist" aria-label="Pedidos" data-ds-tabs-auto>
    <?php $i = 0; foreach ($tabs as $tid => [$etq, $ico, $n]): ?>
    <button type="button" class="mc-tab<?= $i === 0 ? ' is-active' : '' ?>" role="tab" id="tab-<?= $tid ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
            aria-controls="panel-<?= $tid ?>" data-tab="<?= $tid ?>" <?= $i++ ? 'tabindex="-1"' : '' ?>>
      <?= UI::iconoDS($ico, 18) ?><span class="mc-tab__label"><?= e($etq) ?></span><?php if ($n): ?><span class="mc-tab__count"><?= (int)$n ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <span class="mc-tabs__ink"></span>
  </div>

  <?php foreach ($tabs as $tid => $_): $lista = $listas[$tid]; ?>
  <section class="ds-panel ds-grid" id="panel-<?= $tid ?>" role="tabpanel" aria-labelledby="tab-<?= $tid ?>" data-panel="<?= $tid ?>"<?= $tid === array_key_first($tabs) ? '' : ' hidden' ?>>
    <?php if (!$lista): ?>
      <?= $tid === 'enviados'
          ? UI::vacioDS('instructores-equipo', 'Todavía no has pedido nada', 'Con «Nuevo pedido» le pides algo a otro equipo: un cambio, un dato, que revisen algo. Le llega el aviso a todo el equipo (o a la persona que elijas).',
              $puedePedir ? '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-pedido-nuevo>' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Nuevo pedido</span></button>' : '')
          : UI::vacioDS('notificaciones-vacio', $tid === 'todos' ? 'Todavía no hay pedidos' : 'Nada pendiente para ti ni para tu equipo', $tid === 'todos'
              ? 'Cuando alguien le pida algo a otro equipo, aparece aquí.'
              : 'Cuando otro equipo les pida algo, aparece aquí y en la campana de arriba.') ?>
    <?php else: foreach ($lista as $p) echo $tarjeta($p); endif; ?>
  </section>
  <?php endforeach; ?>
</div>
<?php UI::fin(); ?>
