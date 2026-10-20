<?php
/**
 * Correo InnoTech como app aparte, para instalar en el celular: SOLO el correo
 * (la misma vista de Notificaciones → Correo InnoTech), sin el resto del panel.
 *
 * Se instala desde el navegador del teléfono abriendo esta página:
 *   - Android (Chrome): menú ⋮ → «Instalar app» o «Agregar a la pantalla principal».
 *   - iPhone (Safari): Compartir → «Agregar a inicio».
 * Usa su propio manifest (manifest-correo.webmanifest: nombre «Correo», su ícono,
 * abre aquí) y el mismo service worker del panel, así que los correos nuevos
 * llegan como notificación del teléfono (cron_correo.php) una vez que la persona
 * activa los avisos desde aquí.
 *
 * Todos los enlaces y formularios del correo se quedan en esta página
 * (correoUrl() en lib/correo_vista.php, y actions.php vuelve aquí).
 */
define('CORREO_APP', true);
require_once __DIR__ . '/lib/bootstrap.php';      // sin sesión va a login.php y, al entrar, vuelve aquí
foreach (['Correo', 'Push'] as $m) {
    if (is_file(__DIR__ . '/lib/' . $m . '.php')) require_once __DIR__ . '/lib/' . $m . '.php';
}
if (is_file(__DIR__ . '/lib/correo_vista.php')) require_once __DIR__ . '/lib/correo_vista.php';

$yo  = Auth::usuario();
$mid = (int)$yo['id'];
$correoOn = class_exists('Correo') && function_exists('correoVista') && Correo::activo();

if ($correoOn && ($_GET['correo'] ?? '') === 'adjunto') {
    correoDescargarAdjunto($mid);
}

$conectado = $correoOn && Correo::conectado($mid);
$redactar  = $conectado && !isset($_GET['redactar']);

// Avisos nativos: lo mismo que lleva la barra de arriba del panel (assets/ds.js lo lee
// para activar este dispositivo y mantenerlo anotado sin volver a preguntar)
$pushAttrs = '';
$pushEquipo = '';
if (class_exists('Push') && is_file(__DIR__ . '/sw.js') && method_exists('Push', 'atributosPagina')) {
    $pushAttrs = Push::atributosPagina($mid);
    if ($pushAttrs !== '') $pushEquipo = Push::equipo((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
$nSin = $conectado ? (int)Correo::noLeidos($mid) : 0;
?>
<!DOCTYPE html>
<html lang="es" class="correo-app">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $nSin ? '(' . $nSin . ') ' : '' ?>Correo InnoTech</title>
<link rel="icon" type="image/svg+xml" href="iconos/pwa/app-correo.svg">
<link rel="alternate icon" type="image/png" href="iconos/pwa/correo-192.png">
<link rel="manifest" href="manifest-correo.webmanifest">
<meta name="theme-color" content="#10285A">
<link rel="apple-touch-icon" href="iconos/pwa/correo-apple-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Correo">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script>
(function () {
  try { if (localStorage.getItem('meca-theme') === 'dark') document.documentElement.classList.add('dark'); } catch (e) { /* sin almacenamiento */ }
})();
</script>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preload" href="<?= asset('assets/fonts/Inter-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('../assets/mecapacito.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/admin.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/ds.css') ?>">
<?php UI::estilosConfig(); ?>
</head>
<body class="admin-body" data-limite-subida="<?= limiteSubidaBytes() ?>" data-rol="<?= e(Auth::rol()) ?>">

<header class="ds-enc correo-app__enc" id="ds-topbar"<?= $pushAttrs ?>>
  <?php if ($pushAttrs !== ''): ?>
  <!-- Pregunta si activamos los avisos en este teléfono (assets/ds.js la muestra mientras no acepte ni diga «No, gracias») -->
  <div class="ds-push" role="dialog" aria-labelledby="ds-push-t" aria-describedby="ds-push-d" data-push-modal hidden>
    <span class="mc-tile mc-tile--azul ds-push__ico"><?= UI::iconoDS('notificaciones', 22) ?></span>
    <div class="ds-push__txt">
      <p class="ds-push__t" id="ds-push-t">¿Te avisamos cuando llegue un correo?</p>
      <p class="ds-push__d" id="ds-push-d" data-push-normal>Te sale la notificación del teléfono al instante, aunque la app esté cerrada.</p>
      <p class="ds-push__d" data-push-ios hidden>En iPhone y iPad, Apple solo deja avisar si abres el correo desde la pantalla de inicio: toca <b>Compartir</b> → <b>Agregar a inicio</b>, entra desde ese ícono y ahí activas los avisos.</p>
      <span class="ds-push__equipo"><?= UI::iconoDS('computadora', 14) ?> <span data-push-equipo><?= e($pushEquipo) ?></span></span>
    </div>
    <div class="ds-push__acciones">
      <button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-push-si><span class="mc-btn__label">Activar avisos</span></button>
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-push-no><span class="mc-btn__label">No, gracias</span></button>
    </div>
    <button type="button" class="mc-iconbtn mc-iconbtn--sm ds-push__cerrar" data-push-cerrar aria-label="Cerrar (te lo preguntamos otra vez)"><?= UI::iconoDS('cerrar', 16) ?></button>
  </div>
  <?php endif; ?>
  <div class="ds-enc__fila">
    <?php if ($conectado): ?>
    <button type="button" class="mc-iconbtn correo-app__menu" data-correo-cajon aria-controls="correo-cajon" aria-expanded="false" aria-label="Carpetas">
      <?= UI::iconoDS('menu', 22) ?>
    </button>
    <?php endif; ?>
    <a class="correo-app__marca" href="correo.php" aria-label="Correo InnoTech: Recibidos">
      <img src="iconos/pwa/app-correo.svg" alt="" width="36" height="36">
      <span><b>Correo</b><small><?= e((string)Config::get('titulo')) ?></small></span>
    </a>
    <div class="ds-enc__tools">
      <!-- Instalar la app: aparece solo si el navegador ofrece instalarla con un clic (window.MCInstalar, ds.js) -->
      <button type="button" class="mc-btn mc-btn--outline mc-btn--md correo-app__instalar" data-correo-instalar hidden>
        <?= UI::iconoDS('descargar', 18) ?><span class="mc-btn__label">Instalar</span></button>
      <?php if ($pushAttrs !== ''): ?>
      <button type="button" class="mc-iconbtn correo-app__avisos" data-correo-avisos hidden aria-label="Activar los avisos en este teléfono" data-ds-tip="Activar los avisos en este teléfono">
        <?= UI::iconoDS('notificaciones', 20) ?>
      </button>
      <?php endif; ?>
      <?php if ($redactar): ?>
      <a class="mc-btn mc-btn--success mc-btn--md correo-app__redactar" href="<?= e(correoUrl(['redactar' => 1, 'c' => (string)($_GET['c'] ?? '')])) ?>">
        <?= UI::iconoDS('mas', 18) ?><span class="mc-btn__label">Redactar</span></a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="admin-main correo-app__main">
  <?php UI::flashes(); ?>
  <?php if (isset($_GET['instalar'])): /* llega desde Notificaciones → Tus dispositivos → Instala la app */ ?>
  <section class="mc-card correo-instalar" data-correo-instalar-tarjeta>
    <img src="iconos/pwa/app-correo.svg" alt="" width="64" height="64">
    <div class="correo-instalar__txt">
      <h2>Instala Correo en este dispositivo</h2>
      <p>Se abre directo en tu correo, en su propia ventana, y te avisa al instante cuando llega uno nuevo.</p>
      <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-instalar-ya hidden><?= UI::iconoDS('descargar', 18) ?><span class="mc-btn__label">Instalar Correo</span></button>
      <p class="correo-instalar__pasos" data-instalar-pasos></p>
    </div>
  </section>
  <?php endif; ?>
  <div class="ds">
    <?php if ($correoOn): ?>
      <?php correoVista($yo); ?>
    <?php else: ?>
      <?= UI::vacioDS('notificaciones-vacio', 'El correo InnoTech no está activo', 'Un administrador lo enciende en el panel: Notificaciones → Correo InnoTech → Servidor de correo.') ?>
    <?php endif; ?>
  </div>
</main>

<?php
// Barra de abajo en el celular (BottomNav del design system): Recibidos, Enviados, Redactar y
// Carpetas (abre el cajón). La píldora azul se pone en el destino actual.
$nav = $conectado ? (array)($GLOBALS['correoNav'] ?? []) : [];
if ($nav):
    $enOtra = $nav['modo'] !== 'redactar' && !in_array($nav['tipo'], ['inbox', 'sent'], true);
    $destinos = [['Recibidos', 'correo', correoUrl(['c' => $nav['bandeja']]), $nav['modo'] !== 'redactar' && $nav['tipo'] === 'inbox', (int)$nav['noLeidos']]];
    if (!empty($nav['enviados'])) {
        $destinos[] = ['Enviados', 'enviar', correoUrl(['c' => $nav['enviados']]), $nav['modo'] !== 'redactar' && $nav['tipo'] === 'sent', 0];
    }
    $destinos[] = ['Redactar', 'mas', correoUrl(['redactar' => 1]), $nav['modo'] === 'redactar', 0];
    $destinos[] = ['Carpetas', 'carpeta', '', $enOtra, 0];
    $iAct = 0;
    foreach ($destinos as $i => $d) if ($d[3]) { $iAct = $i; break; }
?>
<nav class="mc-bottomnav is-fixed correo-app__nav" style="--n:<?= count($destinos) ?>; --i:<?= $iAct ?>" aria-label="Navegación del correo">
  <span class="mc-bottomnav__ind"><span></span></span>
  <?php foreach ($destinos as [$txt, $ico, $url, $actual, $n]): ?>
  <?php if ($url === ''): ?>
  <button type="button" class="mc-bottomnav__item<?= $actual ? ' is-active' : '' ?>" data-correo-cajon aria-controls="correo-cajon" aria-expanded="false">
  <?php else: ?>
  <a class="mc-bottomnav__item<?= $actual ? ' is-active' : '' ?>" href="<?= e($url) ?>"<?= $actual ? ' aria-current="page"' : '' ?>>
  <?php endif; ?>
    <span class="mc-bottomnav__icon"><?= UI::iconoDS($ico, 22) ?><?php if ($n > 0): ?><span class="mc-bottomnav__badge is-alert"><?= $n > 99 ? '99+' : $n ?></span><?php endif; ?></span>
    <span class="mc-bottomnav__label"><?= e($txt) ?></span>
  <?= $url === '' ? '</button>' : '</a>' ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php
// El teléfono que falta o está mal escrito se pide también aquí (no se cierra hasta guardarlo)
$telDlg = method_exists('UI', 'dialogTelefonoHtml') ? UI::dialogTelefonoHtml() : '';
if ($telDlg !== ''):
    echo $telDlg; ?>
<script>
(() => {
  const d = document.getElementById('dlg-telefono');
  if (!d || !d.showModal) return;
  d.addEventListener('cancel', (e) => e.preventDefault());
  d.showModal();
})();
</script>
<?php endif; ?>
<script src="<?= asset('assets/admin.js') ?>"></script>
<script src="<?= asset('assets/ds.js') ?>"></script>
<script>
// Botón de la campana: aparece mientras este teléfono no reciba los avisos, y los activa con un toque
(() => {
  const b = document.querySelector('[data-correo-avisos]');
  if (!b || !window.MCPush || !MCPush.soporta) return;
  const pintar = () => { b.hidden = MCPush.recibe ? MCPush.recibe('correo') : Notification.permission === 'granted'; };
  setTimeout(pintar, 1500);                 // tras la revisión silenciosa de ds.js
  b.addEventListener('click', async () => { await MCPush.activar(); pintar(); });
})();
// Instalar la app: el botón de arriba y, con ?instalar=1, la tarjeta con el botón o los pasos de este sistema
(() => {
  const I = window.MCInstalar;
  if (!I) return;
  const boton = document.querySelector('[data-correo-instalar]');
  const tarjeta = document.querySelector('[data-correo-instalar-tarjeta]');
  const pasos = {
    ios: 'En Safari toca <b>Compartir</b> (el cuadrito con la flecha hacia arriba) → <b>Agregar a inicio</b>. Luego abre Correo desde ese ícono y activa los avisos.',
    android: 'En Chrome toca el menú <b>⋮</b> → <b>Instalar app</b> (o «Agregar a la pantalla principal»).',
    windows: 'En Chrome o Edge, toca el ícono de instalar al final de la barra de direcciones, o el menú <b>⋮</b> → «Instalar Correo».',
    mac: I.navegador === 'safari' ? 'En Safari: menú <b>Archivo</b> → <b>Agregar al Dock</b>.' : 'En Chrome, toca el ícono de instalar al final de la barra de direcciones, o el menú <b>⋮</b> → «Instalar Correo».',
    otro: 'Abre esta página en Chrome o Edge y busca «Instalar» en el menú del navegador.',
  };
  const pintar = () => {
    const puede = I.disponible && !I.instalada;
    if (boton) boton.hidden = !puede;
    if (!tarjeta) return;
    if (I.instalada) { tarjeta.querySelector('.correo-instalar__txt').innerHTML = '<h2>Ya la estás usando instalada</h2><p>Listo: Correo abre en su propia ventana.</p>'; return; }
    tarjeta.querySelector('[data-instalar-ya]').hidden = !I.disponible;
    const p = tarjeta.querySelector('[data-instalar-pasos]');
    p.hidden = I.disponible;
    p.innerHTML = pasos[I.plataforma] || pasos.otro;
  };
  const instalar = async () => { await I.instalar(); pintar(); };
  if (boton) boton.addEventListener('click', instalar);
  if (tarjeta) tarjeta.querySelector('[data-instalar-ya]').addEventListener('click', instalar);
  document.addEventListener('mcinstalar:cambio', pintar);
  pintar();
})();
// Llegó un correo con la app abierta: si estás en la lista (no leyendo ni escribiendo), se pone al día sola
document.addEventListener('meca:push', (ev) => {
  const d = ev.detail || {};
  const q = new URLSearchParams(location.search);
  if (String(d.tag || '').startsWith('correo') && !q.has('uid') && !q.has('redactar')) location.reload();
});
</script>
</body>
</html>
