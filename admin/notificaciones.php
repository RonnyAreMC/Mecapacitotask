<?php
/**
 * Notificaciones del panel (la campana de la barra de arriba).
 *   ?json=1&desde=ID  lo que pide la campana cada minuto (JSON).
 *   ?abrir=ID         la marca leída y lleva a donde apunta.
 *   sin parámetros    la lista completa, para ver las viejas.
 *   ?tab=correo       el correo InnoTech de la persona (lib/correo_vista.php).
 *   ?correo=adjunto   descarga un adjunto de su correo.
 *   ?tab=dispositivos dónde le llegan los avisos nativos (Web Push, lib/Push.php).
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Notificaciones.php';
// El correo dentro del panel: si al subir por FTP falta, solo no aparece.
if (is_file(__DIR__ . '/lib/Correo.php')) require_once __DIR__ . '/lib/Correo.php';
if (is_file(__DIR__ . '/lib/correo_vista.php')) require_once __DIR__ . '/lib/correo_vista.php';
if (is_file(__DIR__ . '/lib/Push.php')) require_once __DIR__ . '/lib/Push.php';
if (is_file(__DIR__ . '/lib/Tutoriales.php')) require_once __DIR__ . '/lib/Tutoriales.php';
Auth::requiereLogin();

$yo  = Auth::usuario();
$mid = (int)($yo['id'] ?? 0);
$correoOn = class_exists('Correo') && function_exists('correoVista') && Correo::activo();
$pushOn = class_exists('Push') && is_file(__DIR__ . '/sw.js') && Push::activo();

if (isset($_GET['abrir'])) {
    $n = Notif::leer((int)$_GET['abrir'], $mid);
    $url = $n ? Notif::urlSegura((string)($n['url'] ?? '')) : '';
    header('Location: ' . ($url !== '' ? $url : 'notificaciones.php'));
    exit;
}

if ($correoOn && ($_GET['correo'] ?? '') === 'adjunto') {
    correoDescargarAdjunto($mid);
}

$miembros = (new MiembroRepo())->mapa();

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $items = array_map(fn($n) => Notif::paraJs($n, $miembros), Notif::de($mid, 15, max(0, (int)($_GET['desde'] ?? 0))));
    $res = ['ok' => true, 'sinLeer' => Notif::sinLeer($mid), 'items' => $items];
    // Correos sin leer: el servidor solo se consulta cada pocos minutos
    if ($correoOn) $res['correo'] = Correo::noLeidos($mid, true);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$tabPedida = (string)($_GET['tab'] ?? '');
$tab = ($correoOn && $tabPedida === 'correo') ? 'correo' : (($pushOn && $tabPedida === 'dispositivos') ? 'dispositivos' : 'avisos');
$nSin = Notif::sinLeer($mid);
$nCorreo = $correoOn ? Correo::noLeidos($mid) : null;

// Arriba a la derecha: «Marcar todo» en avisos; «Redactar» en el correo ya sincronizado
$accionesCab = '';
if ($tab === 'avisos' && $nSin) {
    $accionesCab = '<form method="post" action="actions.php"><input type="hidden" name="accion" value="notif_leer_todas">'
        . '<button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md">' . UI::iconoDS('check-doble', 18) . '<span class="mc-btn__label">Marcar todo como leído</span></button></form>';
} elseif ($tab === 'correo' && Correo::conectado($mid) && !isset($_GET['redactar'])) {
    $accionesCab = '<a class="mc-btn mc-btn--success mc-btn--md" href="' . e(correoUrl(['redactar' => 1, 'c' => (string)($_GET['c'] ?? '')])) . '">'
        . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Redactar</span></a>';
}

// «Ver tutorial»: si el administrador asignó un video a esta pestaña, se abre ahí mismo
if (class_exists('Tutoriales')) {
    $accionesCab = Tutoriales::boton($tab === 'avisos' ? 'notificaciones' : $tab) . $accionesCab;
}

UI::inicio($tab === 'correo' ? 'Correo InnoTech' : 'Notificaciones', 'notificaciones');
UI::cabecera(
    'Notificaciones',
    [
        'correo'       => 'Tu correo InnoTech, sin salir del panel: lee, responde y escribe desde aquí.',
        'dispositivos' => 'Recibe los avisos al instante en tu celular o tu computadora, aunque el panel esté cerrado.',
        'avisos'       => 'Lo que pasó en tus proyectos y equipos: tareas que te asignaron, reuniones, pedidos de otros equipos, observaciones…',
    ][$tab],
    $accionesCab
);
?>
<div class="ds" id="notif-pagina">
  <?php if ($correoOn || $pushOn): ?>
  <nav class="mc-tabs mc-tabs--line notif-tabs" aria-label="Secciones de notificaciones">
    <a class="mc-tab<?= $tab === 'avisos' ? ' is-active' : '' ?>" href="notificaciones.php"<?= $tab === 'avisos' ? ' aria-current="page"' : '' ?>>
      <?= UI::iconoDS('notificaciones', 18) ?> Avisos del panel<?php if ($nSin): ?> <span class="mc-tab__count"><?= $nSin ?></span><?php endif; ?></a>
    <a class="mc-tab<?= $tab === 'correo' ? ' is-active' : '' ?>" href="notificaciones.php?tab=correo"<?= $tab === 'correo' ? ' aria-current="page"' : '' ?>>
      <?= UI::iconoDS('correo', 18) ?> Correo InnoTech<?php if ($nCorreo): ?> <span class="mc-tab__count"><?= $nCorreo > 999 ? '999+' : $nCorreo ?></span><?php elseif ($nCorreo === null): ?> <span class="notif-tabs__nuevo">Sincronizar</span><?php endif; ?></a>
    <?php if ($pushOn): $nDisp = count(Push::dispositivos($mid)); ?>
    <a class="mc-tab<?= $tab === 'dispositivos' ? ' is-active' : '' ?>" href="notificaciones.php?tab=dispositivos"<?= $tab === 'dispositivos' ? ' aria-current="page"' : '' ?>>
      <?= UI::iconoDS('computadora', 18) ?> Tus dispositivos<?php if ($nDisp): ?> <span class="mc-tab__count"><?= $nDisp ?></span><?php endif; ?></a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if ($tab === 'correo'): ?>
    <?php correoVista($yo); ?>
  <?php elseif ($tab === 'dispositivos'): ?>
    <?php pushDispositivos($yo); ?>
  <?php else: $todas = Notif::de($mid, 200); ?>
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
  <?php endif; ?>
</div>
<?php UI::fin(); ?>

<?php
/** Notificaciones → Tus dispositivos: dónde le llegan los avisos y cuáles. */
function pushDispositivos(array $yo): void
{
    $mid = (int)$yo['id'];
    $lista = Push::dispositivos($mid);
    $pref = Push::prefs($mid);
    usort($lista, fn($a, $b) => strcmp((string)($b['usado'] ?? ''), (string)($a['usado'] ?? '')));
    $correoSinc = class_exists('Correo') && Correo::conectado($mid);
    ?>
<section class="mc-card push-card">
  <header class="push-cab">
    <span class="mc-tile mc-tile--lg mc-tile--azul"><?= UI::iconoDS('notificaciones', 24) ?></span>
    <div class="push-cab__txt">
      <h2>Notificaciones en tus dispositivos</h2>
      <p>Te avisamos al instante —en el celular o en la computadora, aunque el panel esté cerrado— cuando te llega un correo InnoTech o te asignan algo.</p>
    </div>
    <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-push-activar><?= UI::iconoDS('notificaciones', 18) ?><span class="mc-btn__label">Activar en este dispositivo</span></button>
  </header>

  <div class="push-seccion">
    <h3>Dispositivos que reciben avisos</h3>
    <?php if (!$lista): ?>
      <p class="push-vacio">Todavía ninguno. Entra al panel desde tu celular o tu computadora y acepta las notificaciones cuando te lo preguntemos (o pulsa «Activar en este dispositivo»).</p>
    <?php else: ?>
    <ul class="push-lista">
      <?php foreach ($lista as $d):
          $tel = (bool)preg_match('/iPhone|iPad|Android/', (string)$d['equipo']); ?>
      <li class="push-equipo" data-push-endpoint="<?= e((string)$d['endpoint']) ?>">
        <span class="mc-tile mc-tile--sm mc-tile--cian"><?= UI::iconoDS($tel ? 'telefono' : 'computadora', 18) ?></span>
        <span class="push-equipo__txt">
          <b><?= e((string)$d['equipo']) ?> <span class="push-equipo__este" hidden>· este dispositivo</span></b>
          <small>Desde <?= e(Notif::hace((string)($d['creado'] ?? ''))) ?><?= !empty($d['usado']) ? ' · última vez ' . e(Notif::hace((string)$d['usado'])) : '' ?></small>
        </span>
        <form method="post" action="actions.php" data-confirmar="Ese dispositivo dejará de recibir avisos del panel." data-confirmar-titulo="¿Quitar este dispositivo?" data-confirmar-ok="Sí, quitar">
          <input type="hidden" name="accion" value="push_quitar"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::iconoDS('eliminar', 16) ?><span class="mc-btn__label">Quitar</span></button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <form method="post" action="actions.php" class="push-seccion push-prefs">
    <input type="hidden" name="accion" value="push_prefs">
    <h3>Qué te avisamos</h3>
    <?= UI::checkDS('correo', $pref['correo'], 'Correos nuevos de tu buzón InnoTech', $correoSinc ? 'Quién te escribe y el asunto, al minuto de llegar.' : 'Primero sincroniza tu correo en la pestaña «Correo InnoTech».') ?>
    <?= UI::checkDS('avisos', $pref['avisos'], 'Avisos del panel', 'Tareas que te asignan, reuniones, pedidos de otros equipos, observaciones…') ?>
    <div class="push-prefs__pie">
      <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm"><span class="mc-btn__label">Guardar</span></button>
    </div>
  </form>

  <div class="push-seccion push-prueba">
    <form method="post" action="actions.php">
      <input type="hidden" name="accion" value="push_prueba">
      <button class="mc-btn mc-btn--ghost mc-btn--sm"<?= $lista ? '' : ' disabled' ?>><?= UI::iconoDS('enviar', 16) ?><span class="mc-btn__label">Enviar un aviso de prueba</span></button>
    </form>
    <p class="ds-ayuda">En iPhone y iPad los avisos llegan si abres el panel desde la pantalla de inicio (Compartir → Agregar a inicio).</p>
  </div>

  <?php if (Auth::esAdmin()):
      $cron = json_decode((string)@file_get_contents(__DIR__ . '/data/cron_correo.json'), true);
      $haceCron = is_array($cron) ? time() - (int)($cron['t'] ?? 0) : null; ?>
  <div class="push-seccion">
    <?php if ($haceCron !== null && $haceCron < 300): ?>
      <?= UI::alertaDS('success', 'Correos al instante: activo', 'El servidor revisa los buzones sincronizados cada minuto (última revisión hace ' . max(1, (int)round($haceCron / 60)) . ' min).', true) ?>
    <?php else: ?>
      <?= UI::alertaDS('warning', 'Falta programar la revisión de correos (solo administradores)',
          'Para avisar los correos al minuto, en cPanel → <b>Cron Jobs</b> agrega uno que corra <b>cada minuto</b>:<br><code>/usr/local/bin/php ' . e(str_replace('\\', '/', __DIR__)) . '/cron_correo.php</code>'
          . ($haceCron !== null ? '<br>La última revisión fue hace ' . (int)round($haceCron / 60) . ' minutos.' : ''), true) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
    <?php
}
