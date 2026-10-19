<?php
/**
 * Notificaciones del panel (la campana de la barra de arriba).
 *   ?json=1&desde=ID  lo que pide la campana cada minuto (JSON).
 *   ?abrir=ID         la marca leída y lleva a donde apunta.
 *   sin parámetros    la lista completa, para ver las viejas.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Notificaciones.php';
Auth::requiereLogin();

$yo  = Auth::usuario();
$mid = (int)($yo['id'] ?? 0);

if (isset($_GET['abrir'])) {
    $n = Notif::leer((int)$_GET['abrir'], $mid);
    $url = $n ? Notif::urlSegura((string)($n['url'] ?? '')) : '';
    header('Location: ' . ($url !== '' ? $url : 'notificaciones.php'));
    exit;
}

$miembros = (new MiembroRepo())->mapa();

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $items = array_map(fn($n) => Notif::paraJs($n, $miembros), Notif::de($mid, 15, max(0, (int)($_GET['desde'] ?? 0))));
    echo json_encode(['ok' => true, 'sinLeer' => Notif::sinLeer($mid), 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

$todas = Notif::de($mid, 200);
$nSin = Notif::sinLeer($mid);

UI::inicio('Notificaciones', 'notificaciones');
UI::cabecera(
    'Notificaciones',
    'Lo que pasó en tus proyectos y equipos: tareas que te asignaron, reuniones, pedidos de otros equipos, observaciones…',
    $nSin ? '<form method="post" action="actions.php"><input type="hidden" name="accion" value="notif_leer_todas">'
        . '<button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md">' . UI::iconoDS('check-doble', 18) . '<span class="mc-btn__label">Marcar todo como leído</span></button></form>' : ''
);
?>
<div class="ds" id="notif-pagina">
  <?php if (!$todas): ?>
    <?= UI::vacioDS('notificaciones-vacio', 'Estás al día', 'No tienes avisos. Cuando te asignen una tarea, te inviten a una reunión o te pidan algo de otro equipo, te llega aquí y en la campana de arriba.') ?>
  <?php else: ?>
  <section class="mc-card">
    <div class="mc-card__body" style="padding:6px">
      <ul class="mc-notif__list ds-notif-lista">
        <?php foreach ($todas as $n): $j = Notif::paraJs($n, $miembros); ?>
        <li>
          <a class="mc-notif__item<?= $j['leida'] ? '' : ' is-unread' ?>" href="<?= e($j['abrir']) ?>">
            <span class="mc-tile mc-tile--sm mc-tile--<?= e($j['tono']) ?>"><?= UI::iconoDS($j['icono'], 18) ?></span>
            <span class="mc-notif__body">
              <span class="mc-notif__ititle"><?= e($j['titulo']) ?></span>
              <?php if ($j['texto'] !== ''): ?><span class="mc-notif__text"><?= e($j['texto']) ?></span><?php endif; ?>
              <span class="mc-notif__time"><?= e($j['rotulo']) ?><?= $j['quien'] !== '' ? ' · ' . e($j['quien']) : '' ?> · <?= e($j['hace']) ?></span>
            </span>
            <?php if (!$j['leida']): ?><span class="mc-notif__dot" aria-label="Sin leer"></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <p class="ds-ayuda" style="margin-top:var(--space-3)">Las leídas se borran solas a los 30 días.</p>
  <?php endif; ?>
</div>
<?php UI::fin(); ?>
