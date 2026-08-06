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

// Lo que se escribió antes de un error de validación, para no volver a teclearlo
$prev = $_SESSION['form_registro'] ?? [];
unset($_SESSION['form_registro']);
$val = fn(string $k) => e((string)($prev[$k] ?? ''));

// Token de un solo uso: un POST de registro solo se acepta si viene de esta
// pantalla (evita que un formulario ajeno dispare altas contra el panel).
$_SESSION['registro_token'] = bin2hex(random_bytes(16));
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
        <li><span>1</span> Completas tus datos</li>
        <li><span>2</span> Un administrador te aprueba</li>
        <li><span>3</span> Entras con tu correo y contraseña</li>
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
          <?= Auth::hayAdmin()
              ? 'Ahora mismo el panel no acepta cuentas nuevas. Pídele al administrador que te dé de alta.'
              : 'Este panel todavía no tiene administrador, así que no hay quién apruebe una solicitud.' ?>
        </p>
        <a class="btn-primary btn-meca login-btn" href="<?= e(urlPanel('login.php')) ?>">
          <i class="fa-solid fa-arrow-left"></i> Volver a entrar
        </a>
      <?php else: ?>

        <h1 class="font-display">Crear cuenta</h1>
        <?php if ($dominios): ?>
        <p class="login-sub">Solo se aceptan correos <b><?= e('@' . implode(', @', $dominios)) ?></b>.</p>
        <?php endif; ?>

        <form method="post" action="actions.php" class="login-form form-registro" autocomplete="on">
          <input type="hidden" name="accion" value="auth_registro">
          <input type="hidden" name="token" value="<?= e($_SESSION['registro_token']) ?>">
          <!-- Trampa para bots: es invisible, una persona nunca lo rellena -->
          <div class="reg-trampa" aria-hidden="true">
            <label>No rellenes esto <input type="text" name="web" tabindex="-1" autocomplete="off"></label>
          </div>

          <label class="campo"><span>Nombre y apellido *</span>
            <div class="input-prefijo">
              <i class="fa-solid fa-user"></i>
              <input class="input-meca" name="nombre" required maxlength="80" autofocus
                     autocomplete="name" value="<?= $val('nombre') ?>">
            </div>
          </label>

          <label class="campo"><span>Correo *</span>
            <div class="input-prefijo">
              <i class="fa-solid fa-envelope"></i>
              <input class="input-meca" type="email" name="email" required maxlength="120"
                     autocomplete="email" placeholder="tucorreo@<?= e($dominios[0] ?? 'gmail.com') ?>" value="<?= $val('email') ?>">
            </div>
          </label>

          <div class="reg-doble">
            <label class="campo"><span>Contraseña *</span>
              <div class="input-prefijo">
                <i class="fa-solid fa-lock"></i>
                <input class="input-meca" type="password" name="clave" required
                       minlength="<?= Auth::MIN_CLAVE ?>" autocomplete="new-password" data-fuerza>
              </div>
            </label>
            <label class="campo"><span>Repite la contraseña *</span>
              <div class="input-prefijo">
                <i class="fa-solid fa-lock"></i>
                <input class="input-meca" type="password" name="clave_repetir" required
                       minlength="<?= Auth::MIN_CLAVE ?>" autocomplete="new-password" data-fuerza-repetir>
              </div>
            </label>
          </div>
          <div class="reg-fuerza" data-fuerza-barra hidden>
            <div class="rf-barra"><span></span></div>
            <small class="rf-txt"></small>
          </div>

          <button class="btn-primary btn-meca login-btn"><i class="fa-solid fa-paper-plane"></i> Enviar solicitud</button>
        </form>

        <div class="login-pie">
          <p class="lp-nota"><i class="fa-solid fa-circle-info"></i> Al enviarla no entras todavía:
             te avisamos por correo en cuanto un administrador la apruebe.</p>
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
