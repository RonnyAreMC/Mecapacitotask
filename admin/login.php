<?php
/**
 * Acceso al panel. Si todavía no existe ningún administrador,
 * muestra el "primer acceso" para crear la cuenta de admin.
 *
 * Pantalla partida: a la izquierda la escena animada del flujo de tareas
 * (assets/login-flujo.js) y a la derecha el formulario, siempre en claro.
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('../assets/mecapacito.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/admin.css') ?>">
</head>
<body class="admin-body login-body">
<?php UI::flashes(); ?>

<div class="login-split">

  <!-- Lado izquierdo: flujo de tareas animado -->
  <aside class="login-arte">
    <canvas id="lg-flujo" aria-hidden="true"></canvas>
    <div class="arte-txt">
      <div class="arte-marca">
        <img src="../assets/mecapacito-logo.png" alt="<?= e($marca['titulo']) ?>">
        <div>
          <strong class="font-display"><?= e($marca['titulo']) ?></strong>
          <span><?= e($marca['subtitulo']) ?></span>
        </div>
      </div>
      <h2>Del backlog al deploy,<br><em>todo el equipo</em> en un tablero.</h2>
      <p>Proyectos con tareas encadenadas, observaciones de revisión, reuniones y
         el avance real de cada persona, actualizado al instante.</p>
      <?php
      // Chips que desfilan: la lista se imprime dos veces para que el
      // desplazamiento sea continuo y sin salto.
      $chips = [
          ['fa-solid fa-diagram-project', 'Dependencias'],
          ['fa-solid fa-table-columns',   'Kanban'],
          ['fa-solid fa-clipboard-check', 'Observaciones de QA'],
          ['fa-brands fa-github',         'Actividad de repos'],
          ['fa-solid fa-calendar-days',   'Calendario'],
          ['fa-solid fa-video',           'Reuniones Zoom'],
          ['fa-solid fa-bell',            'Recordatorios'],
          ['fa-solid fa-chart-line',      'Métricas del equipo'],
      ];
      ?>
      <div class="arte-marquee">
        <div class="arte-pista">
          <?php for ($v = 0; $v < 2; $v++): foreach ($chips as $i => [$ic, $tx]): ?>
          <span class="arte-chip c<?= $i % 4 ?>" <?= $v ? 'aria-hidden="true"' : '' ?>>
            <i class="<?= e($ic) ?>"></i> <?= e($tx) ?>
          </span>
          <?php endforeach; endfor; ?>
        </div>
      </div>
    </div>
  </aside>

  <!-- Lado derecho: el formulario -->
  <main class="login-panel">
    <div class="login-caja">
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

        <?php if (GoogleLogin::listo()): ?>
        <div class="login-sep"><span>o</span></div>
        <a class="btn-google" href="<?= e(GoogleLogin::urlAutorizacion()) ?>">
          <img src="../assets/google.svg" alt="" width="19" height="19">
          Continuar con Google
        </a>
        <?php endif; ?>

        <p class="login-pie"><i class="fa-solid fa-circle-info"></i> ¿Sin acceso? Pídele al administrador que te cree una contraseña.</p>
      <?php endif; ?>
    </div>
  </main>

</div>

<script src="<?= asset('assets/admin.js') ?>"></script>
<script src="<?= asset('assets/login-flujo.js') ?>"></script>
</body>
</html>
