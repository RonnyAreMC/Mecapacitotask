<?php
/**
 * Crear cuenta en el panel.
 *
 * Registrarse NO da acceso: deja una solicitud que un administrador aprueba
 * o rechaza desde Equipo. Hasta entonces la persona no existe como
 * colaborador, así que no aparece en tareas, proyectos ni selectores.
 *
 * Misma pantalla partida que el login: la escena animada a la izquierda y el
 * formulario a la derecha.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (Auth::usuario()) {
    header('Location: ' . urlPanel('index.php'));
    exit;
}

$marca    = Config::all();
$abierto  = Auth::registroAbierto();
$dominios = Auth::dominiosPermitidos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crear cuenta · <?= e($marca['titulo']) ?></title>
<link rel="icon" type="<?= logoMime(faviconPanel()) ?>" href="<?= e(faviconPanel()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('../assets/mecapacito.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/admin.css') ?>">
</head>
<body class="admin-body login-body">
<?php UI::flashes(); ?>

<div class="login-split">

  <!-- Lado izquierdo: el mismo flujo animado del login -->
  <aside class="login-arte">
    <canvas id="lg-flujo" aria-hidden="true"></canvas>
    <div class="arte-txt">
      <div class="arte-marca">
        <img src="<?= e(logoPanel()) ?>" alt="<?= e($marca['titulo']) ?>">
        <div>
          <strong class="font-display"><?= e($marca['titulo']) ?></strong>
          <span><?= e($marca['subtitulo']) ?></span>
        </div>
      </div>
      <h2>Pide tu acceso y<br><em>súmate al tablero</em> del equipo</h2>

      <ol class="reg-pasos">
        <li><span>1</span> Autorizas con tu cuenta de Google</li>
        <li><span>2</span> Un administrador te aprueba</li>
        <li><span>3</span> Entras con esa misma cuenta</li>
      </ol>
    </div>
  </aside>

  <!-- Lado derecho: el formulario -->
  <main class="login-panel">
    <div class="login-caja caja-registro">
      <div class="login-marca">
        <img src="<?= e(logoPanel()) ?>" alt="<?= e($marca['titulo']) ?>">
        <div>
          <strong class="font-display"><?= e($marca['titulo']) ?></strong>
          <span><?= e($marca['subtitulo']) ?></span>
        </div>
      </div>

      <?php if (!$abierto): ?>
        <h1 class="font-display">Registro cerrado</h1>
        <p class="login-sub">
          <?php if (!GoogleLogin::listo()): ?>
            Las cuentas nuevas se crean con Google, y el acceso con Google todavía no está
            configurado en este panel. Pídele al administrador que te dé de alta.
          <?php elseif (!Auth::hayQuienApruebe()): ?>
            Este panel todavía no tiene administrador, así que no hay quién apruebe una solicitud.
          <?php else: ?>
            Ahora mismo el panel no acepta cuentas nuevas. Pídele al administrador que te dé de alta.
          <?php endif; ?>
        </p>
        <a class="btn-primary btn-meca login-btn" href="<?= e(urlPanel('login.php')) ?>">
          <i class="fa-solid fa-arrow-left"></i> Volver a entrar
        </a>
      <?php else: ?>

        <h1 class="font-display">Crear cuenta</h1>
        <p class="login-sub">
          Usa tu cuenta de Google: así tu correo queda verificado y no tienes que
          inventarte otra contraseña.
          <?php if ($dominios): ?>
          Solo se aceptan cuentas <b><?= e('@' . implode(', @', $dominios)) ?></b>.
          <?php endif; ?>
        </p>

        <a class="btn-google btn-google-registro" href="<?= e(GoogleLogin::urlAutorizacion(true)) ?>">
          <img src="../assets/google.svg" alt="" width="19" height="19">
          Crear cuenta con Google
        </a>

        <ul class="reg-detalle">
          <li><i class="fa-solid fa-shield-halved"></i> Google confirma quién eres; el panel solo guarda tu nombre y tu correo.</li>
          <li><i class="fa-solid fa-user-clock"></i> Un administrador revisa la solicitud y decide tu equipo y tu rol.</li>
          <li><i class="fa-solid fa-arrow-right-to-bracket"></i> Cuando te apruebe, entras con el mismo botón de Google.</li>
        </ul>

        <div class="login-pie">
          <p class="lp-nota"><i class="fa-solid fa-circle-info"></i> Autorizar con Google no te mete al panel:
             solo deja tu solicitud. Te avisamos por correo cuando la aprueben.</p>
          <p class="lp-cta">¿Ya tienes cuenta?
            <a href="<?= e(urlPanel('login.php')) ?>"><i class="fa-solid fa-arrow-right-to-bracket"></i> Entrar al panel</a>
          </p>
        </div>
      <?php endif; ?>
    </div>
  </main>

</div>

<script src="<?= asset('assets/admin.js') ?>"></script>
<script src="<?= asset('assets/login-flujo.js') ?>"></script>
</body>
</html>
