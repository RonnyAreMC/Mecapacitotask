<?php
/**
 * Crear cuenta en el panel.
 *
 * Registrarse NO da acceso: deja una solicitud que un administrador aprueba
 * o rechaza desde Equipo. Hasta entonces la persona no existe como
 * colaborador, así que no aparece en tareas, proyectos ni selectores.
 *
 * Misma pantalla partida que el login: las ilustraciones a la izquierda y el
 * formulario a la derecha.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (Auth::usuario()) {
    header('Location: ' . urlPanel('index.php'));
    exit;
}

$marca    = Config::all();
// El registro por CORREO solo necesita que esté abierto y que haya un admin que
// apruebe; Google es un extra si además está configurado.
$puedeRegistrar = Auth::registro()['abierto'] && Auth::hayQuienApruebe();
$conGoogle      = $puedeRegistrar && GoogleLogin::listo();
$dominios       = Auth::dominiosPermitidos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crear cuenta · <?= e($marca['titulo']) ?></title>
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

      <?php if (!$puedeRegistrar): ?>
        <h1>Registro cerrado</h1>
        <p class="ent-sub">
          <?php if (!Auth::hayQuienApruebe()): ?>
            Este panel todavía no tiene administrador, así que no hay quién apruebe una solicitud.
          <?php else: ?>
            Ahora mismo el panel no acepta cuentas nuevas. Pídele al administrador que te dé de alta.
          <?php endif; ?>
        </p>
        <a class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block" href="<?= e(urlPanel('login.php')) ?>"><span class="mc-btn__label">Volver a entrar</span></a>
      <?php else: ?>

        <h1>Crear cuenta</h1>
        <p class="ent-sub">
          Tu correo será tu usuario para entrar.
          <?php if ($dominios): ?>Solo se aceptan cuentas <b><?= e('@' . implode(', @', $dominios)) ?></b>.<?php endif; ?>
        </p>
        <ol class="ent-pasos" aria-label="Cómo funciona">
          <li><span>1</span>Te registras</li>
          <li><span>2</span>Un admin te aprueba</li>
          <li><span>3</span>Entras</li>
        </ol>

        <?php if ($conGoogle): ?>
        <a class="mc-btn mc-btn--lg mc-btn--block ent-google" href="<?= e(GoogleLogin::urlAutorizacion(true)) ?>">
          <img src="../assets/google.svg" alt="" width="18" height="18"><span class="mc-btn__label">Crear cuenta con Google</span>
        </a>
        <div class="ent-sep"><span>o con tu correo</span></div>
        <?php endif; ?>

        <form method="post" action="actions.php" class="ent-form" autocomplete="on">
          <input type="hidden" name="accion" value="solicitud_registrar">
          <div class="mc-field">
            <label class="mc-field__label" for="reg-nombre">Nombre y apellido</label>
            <div class="mc-input mc-input--lg"><?= UI::iconoDS('usuario', 18, 'mc-input__icon') ?>
              <input class="mc-input__control" id="reg-nombre" name="nombre" required maxlength="60" autocomplete="name" placeholder="Tu nombre" value="<?= e($_GET['nombre'] ?? '') ?>"></div>
          </div>
          <div class="mc-field">
            <label class="mc-field__label" for="reg-email">Correo (tu usuario)</label>
            <div class="mc-input mc-input--lg"><?= UI::iconoDS('correo', 18, 'mc-input__icon') ?>
              <input class="mc-input__control" id="reg-email" type="email" name="email" required maxlength="80" autocomplete="email" placeholder="nombre@empresa.com" value="<?= e($_GET['email'] ?? '') ?>"></div>
          </div>
          <div class="ent-doble">
            <div class="mc-field">
              <label class="mc-field__label" for="reg-clave">Contraseña</label>
              <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
                <input class="mc-input__control" id="reg-clave" type="password" name="clave" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6">
                <?= UI::botonVerClave() ?></div>
            </div>
            <div class="mc-field">
              <label class="mc-field__label" for="reg-clave2">Repetir contraseña</label>
              <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
                <input class="mc-input__control" id="reg-clave2" type="password" name="clave2" required minlength="6" autocomplete="new-password" placeholder="Otra vez">
                <?= UI::botonVerClave() ?></div>
            </div>
          </div>
          <button type="submit" class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block"><span class="mc-btn__label">Pedir acceso</span></button>
        </form>

        <div class="ent-pie">
          <p>¿Ya tienes cuenta? <a class="ent-link" href="<?= e(urlPanel('login.php')) ?>">Entrar al panel<?= UI::iconoDS('chevron-der', 16) ?></a></p>
          <p class="ent-nota">Registrarte no te mete al panel: deja una solicitud que un administrador aprueba. Te avisamos por correo cuando la acepten.</p>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script src="<?= asset('assets/admin.js') ?>"></script>
<script src="<?= asset('assets/entrada.js') ?>"></script>
</body>
</html>
