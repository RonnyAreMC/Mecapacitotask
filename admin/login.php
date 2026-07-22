<?php
/**
 * Acceso al panel. Si todavía no existe ningún administrador,
 * muestra el "primer acceso" para crear la cuenta de admin.
 */
require_once __DIR__ . '/lib/bootstrap.php';

if (Auth::usuario()) {
    header('Location: index.php');
    exit;
}
$marca      = Config::all();
$primerUso  = !Auth::hayAdmin();
$miembros   = (new MiembroRepo())->todos();
$opcMiembros = [];
foreach ($miembros as $m) {
    $opcMiembros[(int)$m['id']] = $m['nombre'] . (!empty($m['rol']) ? ' · ' . $m['rol'] : '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar · <?= e($marca['titulo']) ?></title>
<link rel="icon" type="image/png" href="../assets/mecapacito-logo.png">
<script>if (localStorage.getItem('meca-theme') === 'dark') document.documentElement.classList.add('dark');</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="../assets/mecapacito.css">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin-body login-body">
<?php UI::flashes(); ?>

<div class="login-caja">
  <div class="card-base login-card">
    <div class="login-marca">
      <img src="../assets/mecapacito-logo.png" alt="<?= e($marca['titulo']) ?>">
      <div>
        <strong class="font-display"><?= e($marca['titulo']) ?></strong>
        <span><?= e($marca['subtitulo']) ?></span>
      </div>
    </div>

    <?php if ($primerUso): ?>
      <h1 class="font-display">Primer acceso</h1>
      <p class="login-sub">Elige quién será el <b>administrador</b> del panel y define su contraseña.
        Los demás colaboradores entrarán en modo <b>solo lectura</b>.</p>

      <form method="post" action="actions.php" class="login-form">
        <input type="hidden" name="accion" value="auth_setup">
        <label class="campo"><span>Administrador</span>
          <?= UI::select('miembro_id', $opcMiembros, (string)array_key_first($opcMiembros)) ?>
        </label>
        <label class="campo"><span>Correo de acceso</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-envelope"></i>
            <input class="input-meca" type="email" name="email" required placeholder="tucorreo@gmail.com">
          </div>
        </label>
        <label class="campo"><span>Contraseña (mínimo 6 caracteres)</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-lock"></i>
            <input class="input-meca" type="password" name="clave" required minlength="6">
          </div>
        </label>
        <label class="campo"><span>Repetir contraseña</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-lock"></i>
            <input class="input-meca" type="password" name="clave2" required minlength="6">
          </div>
        </label>
        <button class="btn-primary btn-meca login-btn"><i class="fa-solid fa-shield-halved"></i> Crear administrador</button>
      </form>
    <?php else: ?>
      <h1 class="font-display">Entrar al panel</h1>
      <p class="login-sub"><?= GoogleLogin::listo() ? 'Entra con tu cuenta de Google o con tu contraseña.' : 'Usa tu correo o tu usuario de Git.' ?></p>

      <?php if (GoogleLogin::listo()): ?>
      <a class="btn-google" href="<?= e(GoogleLogin::urlAutorizacion()) ?>">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        Continuar con Google
      </a>
      <div class="login-sep"><span>o con tu contraseña</span></div>
      <?php endif; ?>

      <form method="post" action="actions.php" class="login-form">
        <input type="hidden" name="accion" value="auth_login">
        <label class="campo"><span>Correo o usuario de Git</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-user"></i>
            <input class="input-meca" name="usuario" required autofocus placeholder="tucorreo@gmail.com">
          </div>
        </label>
        <label class="campo"><span>Contraseña</span>
          <div class="input-prefijo">
            <i class="fa-solid fa-lock"></i>
            <input class="input-meca" type="password" name="clave" required>
          </div>
        </label>
        <button class="btn-primary btn-meca login-btn"><i class="fa-solid fa-arrow-right-to-bracket"></i> Entrar</button>
      </form>
      <p class="login-pie"><i class="fa-solid fa-circle-info"></i> ¿Sin acceso? Pídele al administrador que te cree una contraseña.</p>
    <?php endif; ?>
  </div>
</div>

<script src="assets/admin.js"></script>
</body>
</html>
