<?php
/**
 * Bandeja de entrada: los requerimientos sueltos que a UNO le tocan.
 *
 * El modulo de requerimientos es del administrador, que es quien los reparte.
 * Esta pantalla es la otra mitad: cada quien ve lo que le han asignado, con su
 * plazo y con quien lo comparte. Es solo de lectura — quien decide y cierra es
 * el administrador — pero al menos ya no hay que enterarse solo por correo.
 *
 * Con "ver como" activo, el administrador ve la bandeja de esa persona.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/req_ui.php';

$reqRepo  = new RequerimientoRepo();
$miembros = new MiembroRepo();
$mapa     = $miembros->mapa();

$verComo = verComo();
$yoId    = $verComo ? (int)$verComo['id'] : (int)(Auth::usuario()['id'] ?? 0);
$yo      = $verComo ?: (Auth::usuario() ?? []);

$prioridades = Catalogo::prioridades();
$estados     = RequerimientoRepo::ESTADOS;
reqUiInst((new InstitucionRepo())->mapa());   // logos de institución en las filas

// Solo lo suyo. Lo que no le han asignado no es asunto de esta pantalla: para
// ver el reparto entero esta el modulo del administrador.
$mios = array_values(array_filter(
    $reqRepo->todos(),
    fn($r) => RequerimientoRepo::tieneAsignado($r, $yoId)
));

// Lo que corre prisa arriba: primero lo que ya venció, luego por fecha de
// entrega, y los cerrados al final.
$abiertos = array_values(array_filter($mios, fn($r) => !RequerimientoRepo::cerrado($r)));
$cerrados = array_values(array_filter($mios, fn($r) => RequerimientoRepo::cerrado($r)));
usort($abiertos, function ($a, $b) {
    $fa = RequerimientoRepo::fechaFin($a) ?: '9999-99-99';
    $fb = RequerimientoRepo::fechaFin($b) ?: '9999-99-99';
    return $fa !== $fb ? strcmp($fa, $fb) : strcmp($b['creado'] ?? '', $a['creado'] ?? '');
});

$vencidos = array_values(array_filter($abiertos, fn($r) => RequerimientoRepo::vencido($r)));
$alDia    = array_values(array_filter($abiertos, fn($r) => !RequerimientoRepo::vencido($r)));

// El que vence antes, para el aviso de la cabecera
$proximo = $alDia ? plazoDe($alDia[0]) : null;

UI::inicio('Bandeja de entrada', 'bandeja');
UI::cabecera(
    'Tu <span class="text-secondary">bandeja</span>',
    $verComo
        ? 'Lo que ve <b>' . e(explode(' ', trim($verComo['nombre']))[0]) . '</b>: los requerimientos sueltos que le asignaron.'
        : 'Peticiones que no pertenecen a ningún proyecto y que te asignó el administrador.'
);
?>

<?php if (!$mios): ?>
  <?= UI::vacio('fa-mug-hot', 'No tienes requerimientos',
        $verComo
          ? 'A esta persona no le han asignado ninguna petición suelta.'
          : 'Cuando el administrador te asigne una petición que no encaja en ningún proyecto, aparecerá aquí y te llegará un correo.') ?>
<?php else: ?>

<!-- Tres números y ya: cuántos tienes encima, cuántos van tarde y el próximo
     que vence. Es lo que se pregunta uno al abrir su bandeja. -->
<section class="bandeja-resumen">
  <?php
  // Un cero no es un aviso: el que está a cero se apaga y así el color queda
  // para lo que sí tiene algo.
  $tiles = [
      ['sit-curso',   'fa-list-check',           count($abiertos), 'Por hacer'],
      ['sit-tarde',   'fa-triangle-exclamation', count($vencidos), 'Pasados de fecha'],
      ['sit-cerrado', 'fa-circle-check',         count($cerrados), 'Cerrados'],
  ];
  foreach ($tiles as [$clase, $icono, $n, $label]): ?>
  <div class="estado-tile <?= $clase ?><?= $n ? '' : ' tile-apagado' ?>">
    <span class="et-icono"><i class="fa-solid <?= $icono ?>"></i></span>
    <span class="et-datos">
      <b class="font-display"><?= $n ?></b>
      <small><?= e($label) ?></small>
    </span>
  </div>
  <?php endforeach; ?>
  <div class="estado-tile sit-proximo<?= $proximo ? '' : ' tile-apagado' ?>">
    <span class="et-icono"><i class="fa-solid fa-hourglass-half"></i></span>
    <span class="et-datos">
      <b class="font-display bandeja-proximo"><?= $proximo ? e($proximo[0]) : '—' ?></b>
      <small><?= $proximo ? 'El próximo' : 'Nada en cola' ?></small>
    </span>
  </div>
</section>

<?php
  bloqueRequerimientos('Pasados de fecha', 'fa-triangle-exclamation', $vencidos, $mapa, $prioridades, $estados,
      'ya deberían estar entregados');
  bloqueRequerimientos('Por hacer', 'fa-list-check', $alDia, $mapa, $prioridades, $estados,
      'lo que vence antes, arriba');
  bloqueRequerimientos('Cerrados', 'fa-circle-check', $cerrados, $mapa, $prioridades, $estados);
?>

<?php endif; ?>

<?php fichaRequerimiento(false); ?>

<?php UI::fin(); ?>
