<?php
/**
 * Enlace para compartir un tutorial (Tutoriales → «Copiar enlace»): se abre sin
 * iniciar sesión y muestra solo ese video, con su título y su descripción. La
 * clave del enlace (v=…) es del tutorial: quitarlo del panel apaga el enlace.
 */
require_once __DIR__ . '/lib/bootstrap.php';
if (is_file(__DIR__ . '/lib/Tutoriales.php')) require_once __DIR__ . '/lib/Tutoriales.php';

$t = class_exists('Tutoriales') ? Tutoriales::porToken((string)($_GET['v'] ?? '')) : null;
if (!$t) http_response_code(404);
header('X-Robots-Tag: noindex');
// Sin autoplay: quien abre el enlace decide cuándo empieza
$src = $t ? str_replace(['&autoplay=1', '?autoplay=1'], '', Tutoriales::urlInsertar($t)) : '';
$titulo = $t ? (string)$t['titulo'] : 'Tutorial no disponible';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($titulo) ?> · InnoTech Hub</title>
  <meta property="og:title" content="<?= e($titulo) ?>">
  <?php if ($t && trim((string)($t['descripcion'] ?? '')) !== ''): ?><meta property="og:description" content="<?= e((string)$t['descripcion']) ?>"><?php endif; ?>
  <style>
    @font-face { font-family: "Inter"; src: url("<?= e(asset('assets/fonts/Inter-Variable.woff2')) ?>") format("woff2"); font-weight: 100 900; font-display: swap; }
    @font-face { font-family: "Plus Jakarta Sans"; src: url("<?= e(asset('assets/fonts/PlusJakartaSans-Variable.woff2')) ?>") format("woff2"); font-weight: 200 800; font-display: swap; }
    :root { --tinta: #172033; --tinta-2: #64748B; --linea: #E2E8F0; --azul: #2563EB; --fondo: #F5F9FF; --sup: #FFFFFF; }
    @media (prefers-color-scheme: dark) { :root { --tinta: #E9EDF6; --tinta-2: #A3AEC2; --linea: rgba(255,255,255,.1); --fondo: #141B2D; --sup: #1F2740; } }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 22px;
      padding: 32px 16px; background: radial-gradient(1200px 600px at 10% -10%, rgba(37,99,235,.08), transparent 60%),
      radial-gradient(900px 500px at 110% 110%, rgba(16,185,129,.07), transparent 60%), var(--fondo);
      color: var(--tinta); font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif; }
    .marca { display: inline-flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 14px; background: var(--sup);
      box-shadow: 0 0 0 1px var(--linea), 0 8px 24px -16px rgba(15,23,42,.4); font-family: "Plus Jakarta Sans", "Inter", sans-serif;
      font-weight: 700; font-size: 15px; color: var(--tinta); text-decoration: none; }
    .marca img { height: 26px; width: auto; display: block; }
    .caja { width: min(1040px, 100%); display: flex; flex-direction: column; gap: 16px; }
    h1 { margin: 0; font-family: "Plus Jakarta Sans", "Inter", sans-serif; font-size: clamp(22px, 3vw, 30px); font-weight: 700; letter-spacing: -.02em; line-height: 1.25; }
    p { margin: 0; color: var(--tinta-2); font-size: 15px; line-height: 1.55; max-width: 75ch; }
    .marco { position: relative; width: 100%; aspect-ratio: 16 / 9; border-radius: 18px; overflow: hidden; background: #0b1020;
      box-shadow: 0 0 0 1px var(--linea), 0 24px 60px -28px rgba(15,23,42,.55); }
    .marco iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
    .pie { color: var(--tinta-2); font-size: 13px; }
    .pie a { color: var(--azul); }
    .vacio { text-align: center; }
  </style>
</head>
<body>
  <span class="marca"><img src="logo.php" alt="" onerror="this.remove()"> InnoTech Hub</span>
  <?php if ($t): ?>
  <main class="caja">
    <h1><?= e($titulo) ?></h1>
    <?php if (trim((string)($t['descripcion'] ?? '')) !== ''): ?><p><?= e((string)$t['descripcion']) ?></p><?php endif; ?>
    <div class="marco">
      <iframe src="<?= e($src) ?>" title="<?= e($titulo) ?>" loading="eager"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen"
        allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </div>
    <span class="pie">¿No carga? <a href="<?= e(Tutoriales::urlAbrir($t)) ?>" target="_blank" rel="noopener">Ábrelo en <?= $t['proveedor'] === 'youtube' ? 'YouTube' : 'Google Drive' ?></a>.</span>
  </main>
  <?php else: ?>
  <main class="caja vacio">
    <h1>Este enlace no está disponible</h1>
    <p style="margin:0 auto">El tutorial se quitó del panel o el enlace está incompleto. Pide uno nuevo a quien te lo compartió.</p>
  </main>
  <?php endif; ?>
</body>
</html>
