<?php
/**
 * Enlace para compartir una nota (Mis notas → Compartir): se abre sin iniciar
 * sesión y muestra solo esa nota, para leer. Si la nota tiene pizarra, debajo
 * va su vista previa y «Ver pizarra» la abre entera, también solo para mirar
 * (?pizarra=1: la pinta pizarra.php, en solo lectura y contra
 * pizarra_api.php?t=…, que sin sesión solo contesta «estado»).
 *
 * La clave del enlace (t=…) es de la nota: si su dueño deja de compartirla,
 * todo esto deja de verse; si la vuelve a compartir, el enlace es otro.
 */
require_once __DIR__ . '/lib/bootstrap.php';
if (is_file(__DIR__ . '/lib/Notas.php')) require_once __DIR__ . '/lib/Notas.php';
if (is_file(__DIR__ . '/lib/Pizarra.php')) require_once __DIR__ . '/lib/Pizarra.php';

$tk = is_string($_GET['t'] ?? null) ? (string)$_GET['t'] : '';
$n = class_exists('Notas') ? Notas::porToken($tk) : null;
header('X-Robots-Tag: noindex');
$conPizarra = $n && !empty($n['pizarra']) && class_exists('Pizarra') && is_file(__DIR__ . '/pizarra.php');

// La pizarra entera (o su vista previa con &embed=1), solo para mirar
if ($conPizarra && !empty($_GET['pizarra'])) {
    $pzPublica = $n;
    require __DIR__ . '/pizarra.php';
    exit;
}
if (!$n) http_response_code(404);

$titulo = $n ? Notas::nombre($n, 120) : 'Nota no disponible';
$html = $n ? HtmlRico::limpiar((string)($n['html'] ?? '')) : '';
// Los enlaces de adentro se abren aparte y no le pasan nada al sitio de destino
$html = preg_replace('/<a\s+href=/i', '<a rel="nofollow noopener noreferrer" target="_blank" href=', $html);
$conTitulo = $n && trim((string)($n['titulo'] ?? '')) !== '';
$resumen = $n ? HtmlRico::texto((string)($n['html'] ?? ''), 160) : '';
$color = $n && isset(Notas::COLORES[(string)($n['color'] ?? '')]) ? (string)$n['color'] : '';
$nPizarra = $conPizarra ? Pizarra::cuantos(Pizarra::ridNota((int)$n['id'])) : 0;
$mes = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$ts = $n ? (strtotime((string)($n['actualizado'] ?? $n['creado'] ?? '')) ?: 0) : 0;
$fecha = $ts ? (int)date('j', $ts) . ' ' . $mes[(int)date('n', $ts)] . ' ' . date('Y', $ts) : '';
$base = 'nota_ver.php?t=' . rawurlencode($tk);
$esMia = $n && (int)(Auth::usuario()['id'] ?? 0) === (int)($n['miembro_id'] ?? -1);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($titulo) ?> · InnoTech Hub</title>
  <meta property="og:title" content="<?= e($titulo) ?>">
  <?php if ($resumen !== ''): ?><meta property="og:description" content="<?= e($resumen) ?>"><?php endif; ?>
  <style>
    @font-face { font-family: "Inter"; src: url("<?= e(asset('assets/fonts/Inter-Variable.woff2')) ?>") format("woff2"); font-weight: 100 900; font-display: swap; }
    @font-face { font-family: "Plus Jakarta Sans"; src: url("<?= e(asset('assets/fonts/PlusJakartaSans-Variable.woff2')) ?>") format("woff2"); font-weight: 200 800; font-display: swap; }
    :root { --tinta: #172033; --tinta-2: #64748B; --linea: #E2E8F0; --azul: #2563EB; --fondo: #F5F9FF; --sup: #FFFFFF;
      --c-celeste: #E4F2FD; --c-menta: #DFF5E9; --c-lila: #EDE7FC; --c-rosa: #FCE8F0; --c-durazno: #FFECDF; --c-vainilla: #FFF5D6; }
    @media (prefers-color-scheme: dark) { :root { --tinta: #E9EDF6; --tinta-2: #A3AEC2; --linea: rgba(255,255,255,.1); --fondo: #141B2D; --sup: #1F2740;
      --c-celeste: #17344D; --c-menta: #143B2F; --c-lila: #2B2752; --c-rosa: #42223B; --c-durazno: #452C1D; --c-vainilla: #3B3317; } }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; align-items: center; gap: 22px;
      padding: 32px 16px 48px; background: radial-gradient(1200px 600px at 10% -10%, rgba(37,99,235,.08), transparent 60%),
      radial-gradient(900px 500px at 110% 110%, rgba(16,185,129,.07), transparent 60%), var(--fondo);
      color: var(--tinta); font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif; }
    .marca { display: inline-flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 14px; background: var(--sup);
      box-shadow: 0 0 0 1px var(--linea), 0 8px 24px -16px rgba(15,23,42,.4); font-family: "Plus Jakarta Sans", "Inter", sans-serif;
      font-weight: 700; font-size: 15px; color: var(--tinta); text-decoration: none; }
    .marca img { height: 26px; width: auto; display: block; }
    .caja { width: min(760px, 100%); display: flex; flex-direction: column; gap: 16px; }
    .nota { --papel: var(--sup); display: flex; flex-direction: column; gap: 14px; padding: clamp(20px, 4vw, 36px); border-radius: 20px; background: var(--papel);
      box-shadow: 0 0 0 1px var(--linea), 0 24px 60px -32px rgba(15,23,42,.45); overflow-wrap: anywhere; }
    .nota[data-color="celeste"] { --papel: var(--c-celeste); } .nota[data-color="menta"] { --papel: var(--c-menta); }
    .nota[data-color="lila"] { --papel: var(--c-lila); } .nota[data-color="rosa"] { --papel: var(--c-rosa); }
    .nota[data-color="durazno"] { --papel: var(--c-durazno); } .nota[data-color="vainilla"] { --papel: var(--c-vainilla); }
    .meta { color: var(--tinta-2); font-size: 13px; }
    h1 { margin: 0; font-family: "Plus Jakarta Sans", "Inter", sans-serif; font-size: clamp(22px, 3vw, 30px); font-weight: 800; letter-spacing: -.02em; line-height: 1.25; }
    .texto { font-size: 16px; line-height: 1.65; }
    .texto > :first-child { margin-top: 0; } .texto > :last-child { margin-bottom: 0; }
    .texto p { margin: 0 0 .7em; } .texto h1, .texto h2, .texto h3, .texto h4 { margin: .6em 0 .4em; line-height: 1.3; }
    .texto ul, .texto ol { margin: 0 0 .7em; padding-left: 1.4em; } .texto li { margin: 0 0 .25em; }
    .texto a { color: var(--azul); }
    .texto blockquote { margin: 0 0 .7em; padding: 8px 14px; border-radius: 10px; background: rgba(100,116,139,.1); color: var(--tinta-2); }
    .texto code, .texto pre { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .9em; background: rgba(100,116,139,.12); border-radius: 6px; }
    .texto code { padding: 1px 5px; } .texto pre { padding: 10px 12px; overflow-x: auto; }
    .texto table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 14px; display: block; overflow-x: auto; }
    .texto th, .texto td { border: 1px solid var(--linea); padding: 6px 10px; text-align: left; vertical-align: top; }
    .pz { display: flex; flex-direction: column; gap: 10px; }
    .pz-cab { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .pz-t { color: var(--tinta-2); font-size: 13px; font-weight: 700; }
    .boton { display: inline-flex; align-items: center; height: 34px; padding: 0 16px; border-radius: 999px; background: var(--azul); color: #fff;
      font-size: 14px; font-weight: 700; text-decoration: none; }
    .boton:hover { filter: brightness(1.08); }
    .marco { position: relative; display: block; height: 280px; border-radius: 14px; overflow: hidden; background: var(--sup);
      box-shadow: 0 0 0 1px var(--linea); }
    .marco iframe { display: block; width: 100%; height: 100%; border: 0; pointer-events: none; }
    .pie { color: var(--tinta-2); font-size: 13px; text-align: center; }
    .pie a { color: var(--azul); }
    .vacio { text-align: center; }
    @media (max-width: 480px) { .marco { height: 220px; } }
  </style>
</head>
<body>
  <span class="marca"><img src="logo.php" alt="" onerror="this.remove()"> InnoTech Hub</span>
  <?php if ($n): ?>
  <main class="caja">
    <article class="nota" data-color="<?= e($color) ?>">
      <?php if ($fecha !== ''): ?><span class="meta">Actualizada el <?= e($fecha) ?> · solo lectura</span><?php endif; ?>
      <?php if ($conTitulo || $html === ''): ?><h1><?= e($titulo) ?></h1><?php endif; ?>
      <?php if ($html !== ''): ?><div class="texto"><?= $html /* ya saneado con HtmlRico::limpiar */ ?></div><?php endif; ?>
      <?php if ($conPizarra && $nPizarra > 0): ?>
      <section class="pz" aria-label="Pizarra de la nota">
        <div class="pz-cab">
          <span class="pz-t">Pizarra</span>
          <a class="boton" href="<?= e($base . '&pizarra=1') ?>">Ver pizarra</a>
        </div>
        <a class="marco" href="<?= e($base . '&pizarra=1') ?>" aria-label="Ver la pizarra entera">
          <iframe src="<?= e($base . '&pizarra=1&embed=1') ?>" title="Vista previa de la pizarra" loading="lazy" tabindex="-1" aria-hidden="true"></iframe>
        </a>
      </section>
      <?php endif; ?>
    </article>
    <span class="pie"><?= $esMia ? 'Es tu nota. <a href="' . e('notas.php?id=' . (int)$n['id']) . '">Ábrela en el panel</a> para cambiarla.' : 'Compartida desde InnoTech Hub. Solo para leer.' ?></span>
  </main>
  <?php else: ?>
  <main class="caja vacio">
    <h1>Este enlace no está disponible</h1>
    <p class="meta" style="font-size:15px;line-height:1.55">Quien te la compartió dejó de compartirla, la borró o el enlace está incompleto. Pídele uno nuevo.</p>
  </main>
  <?php endif; ?>
</body>
</html>
