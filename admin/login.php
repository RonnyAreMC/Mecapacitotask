<?php
/**
 * Acceso al panel. Si todavía no existe ningún administrador, avisa cómo
 * crearlo por terminal.
 *
 * Pantalla partida y en claro, con el design system: a la izquierda las
 * ilustraciones que se van turnando (UI::entradaArte + assets/entrada.js) y a
 * la derecha el formulario.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (Auth::usuario()) {
    header('Location: ' . urlPanel('index.php'));
    exit;
}
$marca     = Config::all();
$primerUso = !Auth::hayAdmin();

// Flujo "¿quién eres?" RETIRADO: reclamar una ficha sin correo dejaba asociar
// cualquier correo a una ficha ajena. El acceso es por el correo exacto. Si
// quedó algo en sesión de antes, se descarta.
unset($_SESSION['identificar']);

// Recuperar la contraseña: ?recuperar=1 pide el correo; ?recuperar=codigo pide
// el código de 6 dígitos y la clave nueva (actions.php: auth_recuperar_*).
$recuperar = (string)($_GET['recuperar'] ?? '');
if ($recuperar === 'codigo' && empty($_SESSION['recup'])) $recuperar = '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar · <?= e($marca['titulo']) ?></title>
<link rel="icon" type="<?= logoMime(faviconPanel()) ?>" href="<?= e(faviconPanel()) ?>">
<?php if (is_file(__DIR__ . '/manifest.webmanifest')): ?><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#10285A"><link rel="apple-touch-icon" href="iconos/pwa/apple-touch-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="InnoTech"><?php endif; ?>
<link rel="preload" href="<?= asset('assets/fonts/Inter-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('assets/ds.css') ?>">
</head>
<body class="ent-body">
<?php UI::flashes(); ?>

<div class="ent">
  <?= UI::entradaArte() ?>

  <main class="ent-panel">
    <div class="ent-caja">
      <img class="ent-logo" src="<?= e(logoPanel()) ?>" alt="<?= e($marca['titulo']) ?>">

      <?php if ($recuperar === '1'): ?>
      <h1>Recupera tu contraseña</h1>
      <p class="ent-sub">Escribe tu correo o tu usuario de Git. Te mandamos un código de 6 dígitos por WhatsApp, o a tu correo si no tienes WhatsApp registrado.</p>
      <form method="post" action="actions.php" class="ent-form">
        <input type="hidden" name="accion" value="auth_recuperar_pedir">
        <div class="mc-field">
          <label class="mc-field__label" for="rec-usuario">Correo o usuario de Git</label>
          <div class="mc-input mc-input--lg"><?= UI::iconoDS('usuario', 18, 'mc-input__icon') ?>
            <input class="mc-input__control" id="rec-usuario" name="usuario" required autofocus autocomplete="username" placeholder="tucorreo@empresa.com"></div>
        </div>
        <button class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block"><span class="mc-btn__label">Enviarme el código</span></button>
      </form>
      <div class="ent-pie"><p><a class="ent-link" href="<?= e(urlPanel('login.php')) ?>">Volver a entrar<?= UI::iconoDS('chevron-der', 16) ?></a></p></div>

      <?php elseif ($recuperar === 'codigo'): ?>
      <h1>Escribe el código</h1>
      <p class="ent-sub">Llega por WhatsApp o a tu correo y vence en 10 minutos. Con él eliges tu contraseña nueva.</p>
      <form method="post" action="actions.php" class="ent-form">
        <input type="hidden" name="accion" value="auth_recuperar_cambiar">
        <div class="mc-field">
          <label class="mc-field__label" for="rec-codigo">Código de 6 dígitos</label>
          <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
            <input class="mc-input__control ent-codigo" id="rec-codigo" name="codigo" required autofocus inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" title="Los 6 números del código"></div>
        </div>
        <div class="mc-field">
          <label class="mc-field__label" for="rec-clave">Contraseña nueva</label>
          <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
            <input class="mc-input__control" id="rec-clave" type="password" name="clave" required minlength="6" autocomplete="new-password">
            <?= UI::botonVerClave() ?></div>
          <div class="mc-field__foot"><span class="mc-field__hint">Al menos 6 caracteres.</span></div>
        </div>
        <div class="mc-field">
          <label class="mc-field__label" for="rec-clave2">Repite la contraseña</label>
          <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
            <input class="mc-input__control" id="rec-clave2" type="password" name="clave2" required minlength="6" autocomplete="new-password">
            <?= UI::botonVerClave() ?></div>
        </div>
        <button class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block"><span class="mc-btn__label">Cambiar contraseña y entrar</span></button>
      </form>
      <div class="ent-pie">
        <p>¿No te llegó? <a class="ent-link" href="<?= e(urlPanel('login.php?recuperar=1')) ?>">Pedir otro código<?= UI::iconoDS('chevron-der', 16) ?></a></p>
        <p class="ent-nota">Si no tienes WhatsApp ni correo en tu ficha, pídele al administrador que te cambie la contraseña.</p>
      </div>

      <?php else: ?>
      <?php if ($primerUso): ?>
      <?= UI::alertaDS('warning', 'Este panel todavía no tiene administrador', 'Créalo por terminal: <code>php admin/crear_admin.php</code>', false, 'ent-alerta') ?>
      <?php endif; ?>

      <?php if (GoogleLogin::listo()): ?>
      <a class="mc-btn mc-btn--lg mc-btn--block ent-google" href="<?= e(GoogleLogin::urlAutorizacion()) ?>">
        <img src="../assets/google.svg" alt="" width="18" height="18"><span class="mc-btn__label">Continuar con Google</span>
      </a>
      <div class="ent-sep"><span>o con tu contraseña</span></div>
      <?php endif; ?>

      <form method="post" action="actions.php" class="ent-form">
        <input type="hidden" name="accion" value="auth_login">
        <div class="mc-field">
          <label class="mc-field__label" for="ent-usuario">Correo o usuario de Git</label>
          <div class="mc-input mc-input--lg"><?= UI::iconoDS('usuario', 18, 'mc-input__icon') ?>
            <input class="mc-input__control" id="ent-usuario" name="usuario" required autofocus autocomplete="username" placeholder="tucorreo@empresa.com"></div>
        </div>
        <div class="mc-field">
          <label class="mc-field__label" for="ent-clave">Contraseña</label>
          <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
            <input class="mc-input__control" id="ent-clave" type="password" name="clave" required autocomplete="current-password">
            <?= UI::botonVerClave() ?></div>
          <?php if (!$primerUso): ?><div class="mc-field__foot"><a class="ent-olvide" href="<?= e(urlPanel('login.php?recuperar=1')) ?>">¿Olvidaste tu contraseña?</a></div><?php endif; ?>
        </div>
        <button class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block"><span class="mc-btn__label">Entrar</span></button>
      </form>

      <div class="ent-pie">
        <?php if (!$primerUso && Auth::registro()['abierto'] && Auth::hayQuienApruebe()): ?>
        <p>¿Todavía no tienes cuenta? <a class="ent-link" href="<?= e(urlPanel('registro.php')) ?>">Crear una cuenta<?= UI::iconoDS('chevron-der', 16) ?></a></p>
        <p class="ent-nota">Un administrador aprueba cada cuenta nueva antes de darle acceso.</p>
        <?php elseif (!$primerUso): ?>
        <p class="ent-nota">¿Sin acceso? Pídele al administrador que te cree una contraseña.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script src="<?= asset('assets/admin.js') ?>"></script>
<script src="<?= asset('assets/entrada.js') ?>"></script>
</body>
</html>
