<?php
/**
 * Pestaña «Correo InnoTech» de Notificaciones: sincronizar el buzón, las
 * carpetas, la lista, leer un mensaje, redactar, responder y reenviar.
 * La carga notificaciones.php solo si existe lib/Correo.php.
 *
 * Todo sale de la sesión de quien entró (Auth::usuario), nunca del «ver como»:
 * cada persona ve solo su propio buzón.
 */

function correoUrl(array $p = []): string
{
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== false);
    // En la app del correo (correo.php, la que se instala en el celular) todo se queda dentro de ella
    if (defined('CORREO_APP')) return 'correo.php' . ($p ? '?' . http_build_query($p) : '');
    return 'notificaciones.php?' . http_build_query(['tab' => 'correo'] + $p);
}

/** Página que sirve el correo: la app aparte (correo.php) o la pestaña de Notificaciones. */
function correoPagina(): string
{
    return defined('CORREO_APP') ? 'correo.php' : 'notificaciones.php';
}

function correoColor(string $email): string
{
    $tonos = ['#2970EB', '#1BC059', '#F7931E', '#0E9FB5', '#7C5CDB', '#E5534B', '#1F4E9C', '#C08A00'];
    return $tonos[abs(crc32(strtolower($email))) % count($tonos)];
}

function correoIniciales(array $d): string
{
    $base = trim((string)($d['nombre'] ?? ''));
    if ($base === '') $base = (string)strstr((string)($d['email'] ?? '?'), '@', true) ?: '?';
    // Solo palabras que empiezan con letra («Equipo 39» → «E», no «E3»)
    $pal = array_values(array_filter(preg_split('/[\s._-]+/u', $base, -1, PREG_SPLIT_NO_EMPTY) ?: [], fn($p) => preg_match('/^\p{L}/u', $p)));
    if (!$pal) return '?';
    return mb_strtoupper(mb_substr($pal[0], 0, 1) . (isset($pal[1]) ? mb_substr($pal[1], 0, 1) : ''));
}

function correoQuien(array $d): string
{
    return ($d['nombre'] ?? '') !== '' ? $d['nombre'] : ($d['email'] ?? '');
}

function correoTam(int $b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    return max(1, (int)round($b / 1024)) . ' KB';
}

/** Cápsula de color con la prioridad que puso quien lo mandó ('' si es normal). */
function correoPrioridad(int $p): string
{
    $d = Correo::PRIORIDADES[$p] ?? null;
    if (!$d) return '';
    return ' <span class="correo-prio correo-prio--' . $d[1] . '" title="Prioridad ' . e(mb_strtolower($d[0])) . '"><span class="correo-prio__punto" aria-hidden="true"></span>' . e($d[0]) . '</span>';
}

function correoDirs(array $lista): string
{
    return implode(', ', array_map(fn($d) => $d['nombre'] !== '' ? $d['nombre'] . ' <' . $d['email'] . '>' : $d['email'], $lista));
}

/** Pantalla para sincronizar (o volver a sincronizar) el buzón. */
function correoPantallaConectar(array $yo, ?array $cuenta): void
{
    $doms = Correo::dominios();
    $email = (string)($cuenta['email'] ?? '');
    if ($email === '' && Correo::correoPermitido((string)($yo['email'] ?? ''))) $email = strtolower((string)$yo['email']);
    $reconectar = $cuenta && ($cuenta['estado'] ?? '') === 'reconectar';
    $ejemplo = 'tunombre@' . ($doms[0] ?? 'innotech-solutions.com.ec');
    ?>
<section class="mc-card correo-conectar">
  <div class="correo-conectar__info">
    <img class="correo-conectar__ilu" src="<?= e(asset('iconos/ilustraciones/correo-sincronizar.svg')) ?>" alt="" width="520" height="420">
    <h2>Sincroniza tu correo InnoTech</h2>
    <p>Lee, responde y escribe los correos de tu cuenta <b>@<?= e($doms[0] ?? 'innotech-solutions.com.ec') ?></b> aquí mismo, sin entrar al webmail del hosting.</p>
    <ul class="correo-puntos">
      <li><span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('carpeta', 18) ?></span>
        <span><b>Tus mismas carpetas.</b> Recibidos, Enviados, Borradores y Papelera tal como están en el servidor: lo que hagas aquí se ve igual en el celular o en el webmail.</span></li>
      <li><span class="mc-tile mc-tile--sm mc-tile--verde"><?= UI::iconoDS('candado', 18) ?></span>
        <span><b>Tu contraseña va cifrada.</b> Se guarda cifrada en el servidor del panel y solo se usa para conectarse a tu buzón. Nadie más del equipo puede abrir tu correo desde el panel.</span></li>
      <li><span class="mc-tile mc-tile--sm mc-tile--naranja"><?= UI::iconoDS('notificaciones', 18) ?></span>
        <span><b>La campana te avisa.</b> Arriba ves cuántos correos tienes sin leer.</span></li>
      <li><span class="mc-tile mc-tile--sm mc-tile--cian"><?= UI::iconoDS('reintentar', 18) ?></span>
        <span><b>Queda sincronizado.</b> No se cierra hasta que tú lo desconectes. Si cambias la contraseña del correo, te la pedimos de nuevo.</span></li>
    </ul>
  </div>
  <form method="post" action="actions.php" class="correo-conectar__form" autocomplete="on">
    <input type="hidden" name="accion" value="correo_conectar">
    <h3><?= $reconectar ? 'Vuelve a conectar tu correo' : 'Inicia sesión con tu correo' ?></h3>
    <?php if ($reconectar): ?>
      <?= UI::alertaDS('warning', 'Tu correo se desconectó', e((string)($cuenta['error'] ?? 'La contraseña cambió o ya no es válida.')) . ' Escríbela de nuevo para seguir.', true) ?>
    <?php endif; ?>
    <div class="mc-field">
      <label class="mc-field__label" for="correo-email">Correo InnoTech</label>
      <div class="mc-input mc-input--lg"><?= UI::iconoDS('correo', 18, 'mc-input__icon') ?>
        <input class="mc-input__control" id="correo-email" type="email" name="email" required maxlength="120" autocomplete="username"
               placeholder="<?= e($ejemplo) ?>" value="<?= e($email) ?>"></div>
    </div>
    <div class="mc-field">
      <label class="mc-field__label" for="correo-clave">Contraseña del correo</label>
      <div class="mc-input mc-input--lg"><?= UI::iconoDS('candado', 18, 'mc-input__icon') ?>
        <input class="mc-input__control" id="correo-clave" type="password" name="clave" required maxlength="200" autocomplete="current-password">
        <?= UI::botonVerClave() ?></div>
      <div class="mc-field__foot"><span class="mc-field__hint">La misma con la que entras al webmail. No es la del panel.</span></div>
    </div>
    <button class="mc-btn mc-btn--primary mc-btn--lg mc-btn--block"><?= UI::iconoDS('reintentar', 18) ?><span class="mc-btn__label">Sincronizar correo</span></button>
  </form>
</section>
    <?php
}

/** Ajustes del servidor de correo (solo administradores). */
function correoAjustes(): void
{
    $c = Correo::conf();
    ?>
<details class="mc-card correo-ajustes">
  <summary><?= UI::iconoDS('configuracion', 18) ?> Servidor de correo <small>(solo administradores)</small></summary>
  <form method="post" action="actions.php" class="correo-ajustes__form">
    <input type="hidden" name="accion" value="correo_conf">
    <div class="correo-ajustes__grid">
      <div class="mc-field"><label class="mc-field__label" for="cc-imap">Servidor IMAP (leer)</label>
        <div class="mc-input"><input class="mc-input__control" id="cc-imap" name="imap_host" required maxlength="120" value="<?= e($c['imap_host']) ?>"></div></div>
      <div class="mc-field"><label class="mc-field__label" for="cc-imap-p">Puerto</label>
        <div class="mc-input"><input class="mc-input__control" id="cc-imap-p" name="imap_puerto" type="number" min="1" max="65535" value="<?= (int)$c['imap_puerto'] ?>"></div></div>
      <div class="mc-field"><label class="mc-field__label" for="cc-smtp">Servidor SMTP (enviar)</label>
        <div class="mc-input"><input class="mc-input__control" id="cc-smtp" name="smtp_host" required maxlength="120" value="<?= e($c['smtp_host']) ?>"></div></div>
      <div class="mc-field"><label class="mc-field__label" for="cc-smtp-p">Puerto</label>
        <div class="mc-input"><input class="mc-input__control" id="cc-smtp-p" name="smtp_puerto" type="number" min="1" max="65535" value="<?= (int)$c['smtp_puerto'] ?>"></div></div>
      <div class="mc-field correo-ajustes__ancho"><label class="mc-field__label" for="cc-dom">Dominios permitidos</label>
        <div class="mc-input"><input class="mc-input__control" id="cc-dom" name="dominio" maxlength="200" value="<?= e($c['dominio']) ?>"></div>
        <div class="mc-field__foot"><span class="mc-field__hint">Separados por coma. Solo esos correos pueden sincronizarse.</span></div></div>
    </div>
    <div class="correo-ajustes__checks">
      <?= UI::checkDS('activo', $c['activo'], 'Correo dentro del panel activado') ?>
      <?= UI::checkDS('verificar_ssl', $c['verificar_ssl'], 'Verificar el certificado del servidor (recomendado)') ?>
    </div>
    <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md"><span class="mc-btn__label">Guardar</span></button>
  </form>
</details>
    <?php
}

/** La pestaña completa. */
function correoVista(array $yo): void
{
    @set_time_limit(90);   // el servidor de correo puede tardar un poco
    $mid = (int)$yo['id'];
    $cuenta = Correo::cuenta($mid);
    $esAdmin = Auth::esAdmin();
    if (!$cuenta || ($cuenta['estado'] ?? '') !== 'ok') {
        correoPantallaConectar($yo, $cuenta);
        if ($esAdmin) correoAjustes();
        return;
    }
    try {
        $imap = Correo::abrir($mid);
        $carpetas = Correo::carpetas($imap, $mid);
        $carpeta = Correo::carpetaValida($carpetas, (string)($_GET['c'] ?? ''));
        $bandeja = Correo::carpetaDeTipo($carpetas, 'inbox') ?? 'INBOX';
        $noLeidos = (int)$imap->estado($bandeja)['no_leidos'];
        Correo::anotarNoLeidos($mid, $noLeidos);
        $uid = (int)($_GET['uid'] ?? 0);
        $modo = isset($_GET['redactar']) ? 'redactar' : ($uid > 0 ? 'leer' : 'lista');
        $mensaje = null;
        $lista = null;
        $base = null;
        $hilo = [];
        // Lo de Recibidos se queda guardado en el panel (lib/CorreoCopias.php): si otro programa
        // (Gmail por POP) lo saca del servidor, se sigue viendo y se abre lo guardado
        $conCopias = class_exists('CorreoCopias');
        if ($modo === 'leer') {
            $mensaje = Correo::mensaje($imap, $carpeta['nombre'], $uid);
            if ($mensaje && $conCopias) {
                try { CorreoCopias::guardar($mid, (string)$mensaje['crudo'], $carpeta['nombre'], $uid, true); } catch (Throwable $e) { /* no impide leerlo */ }
            } elseif (!$mensaje && $conCopias) {
                $mensaje = CorreoCopias::mensaje($mid, $carpeta['nombre'], $uid);
            }
            // La conversación: lo que enviaste y lo que te respondieron, en orden (Recibidos + Enviados)
            if ($mensaje) {
                try { $hilo = Correo::hilo($imap, $mid, $carpetas, $carpeta['nombre'], $mensaje); } catch (Throwable $e) { $hilo = []; }
            }
        } elseif ($modo === 'redactar') {
            $rUid = (int)($_GET['responder'] ?? $_GET['reenviar'] ?? 0);
            if ($rUid > 0) $base = Correo::mensaje($imap, $carpeta['nombre'], $rUid) ?? ($conCopias ? CorreoCopias::mensaje($mid, $carpeta['nombre'], $rUid) : null);
        } else {
            $lista = Correo::lista($imap, $carpeta['nombre'], (int)($_GET['p'] ?? 1), (string)($_GET['q'] ?? ''), 30, $carpeta['tipo'] === 'inbox' ? $mid : 0);
        }
        if ($conCopias) $noLeidos = CorreoCopias::noLeidos($imap, $mid, $bandeja);
        if ($modo === 'leer' && $carpeta['tipo'] === 'inbox') {
            $noLeidos = $conCopias ? CorreoCopias::noLeidos($imap, $mid, $bandeja) : (int)$imap->estado($bandeja)['no_leidos'];
            Correo::anotarNoLeidos($mid, $noLeidos);
        } elseif ($conCopias) {
            Correo::anotarNoLeidos($mid, $noLeidos);
        }
        $imap->cerrar();
    } catch (CorreoError $e) {
        if ($e->tipo === 'auth') {
            correoPantallaConectar($yo, Correo::cuenta($mid));
            return;
        }
        echo UI::alertaDS('danger', 'No pudimos abrir tu correo', e($e->getMessage()) . ' <a href="' . e(correoUrl()) . '">Reintentar</a>');
        return;
    }
    correoPintar($yo, $cuenta, compact('carpetas', 'carpeta', 'bandeja', 'noLeidos', 'modo', 'mensaje', 'lista', 'base', 'hilo'));
}

/**
 * Dibuja el correo ya leído del servidor (carpetas, la carpeta actual, la lista, un
 * mensaje o el formulario de redactar). Aparte de correoVista para poder probar la
 * pantalla con datos de ejemplo, sin un buzón de verdad.
 */
function correoPintar(array $yo, array $cuenta, array $d): void
{
    ['carpetas' => $carpetas, 'carpeta' => $carpeta, 'bandeja' => $bandeja, 'noLeidos' => $noLeidos,
     'modo' => $modo, 'mensaje' => $mensaje, 'lista' => $lista, 'base' => $base] = $d;
    $esAdmin = Auth::esAdmin();
    // Para la barra de abajo de la app del correo (correo.php): dónde está la persona
    $GLOBALS['correoNav'] = ['modo' => $modo, 'tipo' => (string)$carpeta['tipo'], 'bandeja' => $bandeja,
        'enviados' => Correo::carpetaDeTipo($carpetas, 'sent'), 'noLeidos' => $noLeidos];
    ?>
<div class="correo" data-correo>
  <!-- En el celular las carpetas van en un cajón que sale de la izquierda (como el menú del design system) -->
  <div class="correo-velo" data-correo-velo></div>
  <aside class="correo-lado" id="correo-cajon" aria-label="Carpetas del correo">
    <div class="correo-lado__cab">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('correo', 18) ?></span>
      <span class="correo-lado__txt"><b>Correo InnoTech</b><small title="<?= e((string)$cuenta['email']) ?>"><?= e((string)$cuenta['email']) ?></small></span>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm correo-lado__cerrar" data-correo-cajon-cerrar aria-label="Cerrar las carpetas"><?= UI::iconoDS('cerrar', 18) ?></button>
    </div>
    <span class="correo-lado__rot">Carpetas</span>
    <nav class="correo-carpetas">
      <?php foreach ($carpetas as $f): ?>
      <a class="correo-carpeta<?= $f['nombre'] === $carpeta['nombre'] && $modo !== 'redactar' ? ' is-actual' : '' ?>" href="<?= e(correoUrl(['c' => $f['nombre']])) ?>">
        <?= UI::iconoDS(Correo::ICONOS[$f['tipo']] ?? 'carpeta', 18) ?><span class="correo-carpeta__txt"><?= e($f['etiqueta']) ?></span>
        <?php if ($f['tipo'] === 'inbox' && $noLeidos > 0): ?><span class="correo-carpeta__n" data-correo-n-lado><?= $noLeidos > 999 ? '999+' : $noLeidos ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="correo-cuenta">
      <span class="correo-cuenta__ok"><?= UI::iconoDS('exito', 16) ?> Sincronizado</span>
      <form method="post" action="actions.php" data-confirmar="Dejarás de ver tu correo en el panel y se borrarán la contraseña guardada y los correos que el panel guardó. Tu buzón no cambia." data-confirmar-titulo="¿Desconectar tu correo?" data-confirmar-ok="Sí, desconectar">
        <input type="hidden" name="accion" value="correo_desconectar">
        <button class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::iconoDS('salir', 16) ?><span class="mc-btn__label">Desconectar</span></button>
      </form>
    </div>
  </aside>

  <button type="button" class="correo-cajon-btn" data-correo-cajon aria-controls="correo-cajon" aria-expanded="false">
    <?= UI::iconoDS(Correo::ICONOS[$carpeta['tipo']] ?? 'carpeta', 18) ?>
    <span><?= e($modo === 'redactar' ? 'Correo nuevo' : $carpeta['etiqueta']) ?></span>
    <?= UI::iconoDS('chevron-abajo', 16) ?>
  </button>

  <section class="mc-card correo-main">
    <?php
    if ($modo === 'leer') correoLeer($mensaje, $carpeta, $carpetas, (array)($d['hilo'] ?? []));
    elseif ($modo === 'redactar') correoRedactar($yo, $cuenta, $carpeta, $base, $carpetas);
    else correoLista($lista, $carpeta);
    ?>
  </section>
</div>
<script>
// Cajón de carpetas en el celular: lo abren los botones [data-correo-cajon] (aquí, y en la app del
// correo también la barra de arriba y la de abajo); lo cierran la ✕, el velo y Esc
(() => {
  const raiz = document.querySelector('[data-correo]');
  if (!raiz || raiz.dataset.cajon) return;
  raiz.dataset.cajon = '1';
  const cajon = raiz.querySelector('.correo-lado');
  const velo = raiz.querySelector('[data-correo-velo]');
  const poner = (abrir) => {
    cajon.classList.toggle('is-open', abrir);
    velo.classList.toggle('is-open', abrir);
    document.documentElement.classList.toggle('correo-cajon-abierto', abrir);
    document.querySelectorAll('[data-correo-cajon]').forEach((b) => b.setAttribute('aria-expanded', abrir ? 'true' : 'false'));
    if (abrir) setTimeout(() => cajon.querySelector('.correo-carpeta.is-actual, .correo-carpeta')?.focus({ preventScroll: true }), 60);
  };
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-correo-cajon]')) { e.preventDefault(); poner(!cajon.classList.contains('is-open')); }
    else if (e.target.closest('[data-correo-cajon-cerrar], [data-correo-velo]')) poner(false);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && cajon.classList.contains('is-open')) poner(false); });
})();
</script>
    <?php
    if ($esAdmin) correoAjustes();
}

function correoLista(array $lista, array $carpeta): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $desde = $lista['total'] ? ($lista['pagina'] - 1) * $lista['por'] + 1 : 0;
    $hasta = min($lista['total'], $lista['pagina'] * $lista['por']);
    $enviados = in_array($carpeta['tipo'], ['sent', 'drafts'], true);
    ?>
  <div class="correo-barra">
    <form method="get" action="<?= correoPagina() ?>" class="correo-buscar" role="search">
      <input type="hidden" name="tab" value="correo"><input type="hidden" name="c" value="<?= e($carpeta['nombre']) ?>">
      <div class="mc-input mc-input--md"><?= UI::iconoDS('buscar', 18, 'mc-input__icon') ?>
        <input class="mc-input__control" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Buscar en <?= e($carpeta['etiqueta']) ?>" aria-label="Buscar correos"></div>
    </form>
    <div class="correo-pag">
      <span><?= $lista['total'] ? $desde . '–' . $hasta . ' de ' . $lista['total'] : '0' ?></span>
      <a class="mc-iconbtn mc-iconbtn--sm<?= $lista['pagina'] <= 1 ? ' is-off' : '' ?>" href="<?= e(correoUrl(['c' => $carpeta['nombre'], 'q' => $q, 'p' => $lista['pagina'] - 1])) ?>" aria-label="Más nuevos" data-ds-tip="Más nuevos"><?= UI::iconoDS('chevron-izq', 18) ?></a>
      <a class="mc-iconbtn mc-iconbtn--sm<?= $lista['pagina'] >= $lista['paginas'] ? ' is-off' : '' ?>" href="<?= e(correoUrl(['c' => $carpeta['nombre'], 'q' => $q, 'p' => $lista['pagina'] + 1])) ?>" aria-label="Más antiguos" data-ds-tip="Más antiguos"><?= UI::iconoDS('chevron-der', 18) ?></a>
      <a class="mc-iconbtn mc-iconbtn--sm" href="<?= e(correoUrl(['c' => $carpeta['nombre'], 'q' => $q, 'p' => $lista['pagina']])) ?>" aria-label="Actualizar" data-ds-tip="Actualizar"><?= UI::iconoDS('reintentar', 18) ?></a>
    </div>
  </div>
  <?php if (!$lista['items']): ?>
    <?= $q !== ''
        ? UI::vacioDS('sin-resultados-cursos', 'Sin resultados', 'No hay correos en ' . $carpeta['etiqueta'] . ' que coincidan con «' . $q . '».', '', 'sm', false)
        : UI::vacioDS('notificaciones-vacio', 'No hay correos aquí', $carpeta['etiqueta'] . ' está vacía.', '', 'sm', false) ?>
  <?php else: ?>
  <ul class="correo-lista">
    <?php foreach ($lista['items'] as $it):
        $quien = $enviados ? ($it['para'][0] ?? ['nombre' => '', 'email' => '']) : $it['de']; ?>
    <li>
      <?php $prioL = (int)($it['prioridad'] ?? 3); ?>
      <a class="correo-fila<?= $it['leido'] ? '' : ' is-nuevo' ?><?= $prioL !== 3 ? ' correo-fila--p' . $prioL : '' ?>" href="<?= e(correoUrl(['c' => $carpeta['nombre'], 'uid' => $it['uid']])) ?>">
        <span class="correo-ini" style="background:<?= e(correoColor($quien['email'])) ?>" aria-hidden="true"><?= e(correoIniciales($quien)) ?></span>
        <span class="correo-txt">
          <span class="correo-de"><?= $enviados ? 'Para: ' : '' ?><?= e(correoQuien($quien) ?: '(sin remitente)') ?><?= correoPrioridad($prioL) ?></span>
          <span class="correo-asunto"><?= e($it['asunto'] !== '' ? $it['asunto'] : '(sin asunto)') ?></span>
          <?php
            // Por fuera, sin abrirlo: qué adjuntos trae, con el ícono de su tipo (PDF, Excel, imagen…)
            $adjsL = (array)($it['adjuntos'] ?? []);
            if ($adjsL): ?>
          <span class="correo-adjs">
            <?php foreach (array_slice($adjsL, 0, 3) as $aL): ?>
            <span class="correo-adj correo-adj--<?= e($aL['clase']) ?>" title="<?= e($aL['nombre']) ?>"><?= UI::icono('adj-' . $aL['clase'], 'correo-adj__ico') ?><span><?= e($aL['nombre']) ?></span></span>
            <?php endforeach; ?>
            <?php if (count($adjsL) > 3): ?><span class="correo-adj correo-adj--mas">+<?= count($adjsL) - 3 ?></span><?php endif; ?>
          </span>
          <?php endif; ?>
        </span>
        <span class="correo-fecha">
          <?php if (!empty($it['guardado'])): ?><span class="correo-clip" aria-label="Guardado en el panel" data-ds-tip="Ya no está en el servidor: es la copia guardada en el panel"><?= UI::icono('ds-archivar') ?></span><?php endif; ?>
          <?php if ($it['adjunto'] && empty($it['adjuntos'])): ?><span class="correo-clip" aria-label="Con adjuntos"><?= UI::icono('ds-enlace') ?></span><?php endif; ?>
          <?= e((method_exists('Correo', 'fechaLista') ? Correo::fechaLista($it['fecha']) : Correo::fechaCorta($it['fecha']))) ?>
          <?php if (!$it['leido']): ?><span class="correo-punto" aria-label="Sin leer"></span><?php endif; ?>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
    <?php
}

function correoLeer(?array $m, array $carpeta, array $carpetas, array $hilo = []): void
{
    $volver = correoUrl(['c' => $carpeta['nombre']]);
    if (!$m) {
        echo '<div class="correo-pad">' . UI::alertaDS('info', 'Ese correo ya no está', 'Quizá se movió o se borró desde otro dispositivo. <a href="' . e($volver) . '">Volver a ' . e($carpeta['etiqueta']) . '</a>') . '</div>';
        return;
    }
    $remotas = !empty($_GET['img']);
    // Imágenes dentro del correo (cid:) como data: para el marco aislado
    $cids = [];
    foreach ($m['adjuntos'] as $a) {
        if ($a['cid'] !== '' && str_starts_with($a['tipo'], 'image/') && $a['tam'] <= 4 * 1048576) {
            $cids[$a['cid']] = 'data:' . $a['tipo'] . ';base64,' . base64_encode($a['datos']);
        }
    }
    $doc = null;
    $hayRemotas = false;
    if ($m['html'] !== null && trim($m['html']) !== '') {
        $l = CorreoHtml::limpiar($m['html'], $cids, $remotas);
        if ($l !== null) {
            [$cuerpo, $estilos, $hayRemotas] = $l;
            $doc = CorreoHtml::documento($cuerpo, $estilos, $remotas);
        }
    }
    $visibles = [];
    foreach ($m['adjuntos'] as $i => $a) {
        $enLinea = $a['cid'] !== '' && $m['html'] !== null && stripos($m['html'], 'cid:' . $a['cid']) !== false;
        if (!$enLinea) $visibles[$i] = $a;
    }
    $de = $m['de'][0] ?? ['nombre' => '', 'email' => ''];
    $enPapelera = $carpeta['tipo'] === 'trash';
    $esCopia = !empty($m['copia']);   // ya no está en el servidor: es lo que guardó el panel
    $c = $carpeta['nombre'];
    ?>
  <div class="correo-barra correo-barra--msg">
    <a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?= e($volver) ?>"><?= UI::iconoDS('chevron-izq', 16) ?><span class="mc-btn__label"><?= e($carpeta['etiqueta']) ?></span></a>
    <div class="correo-acciones">
      <a class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" href="<?= e(correoUrl(['redactar' => 1, 'c' => $c, 'responder' => $m['uid']])) ?>"><?= UI::iconoDS('mensajes', 16) ?><span class="mc-btn__label">Responder</span></a>
      <?php if (Auth::esAdmin() && !verComo() && UI::ia('responder_correo')): /* lee, resume y propone la respuesta (assets/ia.js) */ ?>
      <button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" data-ia-responder data-c="<?= e($c) ?>" data-uid="<?= (int)$m['uid'] ?>"
              data-de="<?= e(correoQuien($de)) ?>" data-asunto="<?= e((string)$m['asunto']) ?>"><?= UI::iconoDS('ia', 16) ?><span class="mc-btn__label">Responder con IA</span></button>
      <?php endif; ?>
      <?php if (count($m['para']) + count($m['cc']) > 1): ?>
      <a class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" href="<?= e(correoUrl(['redactar' => 1, 'c' => $c, 'responder' => $m['uid'], 'todos' => 1])) ?>"><?= UI::iconoDS('usuarios', 16) ?><span class="mc-btn__label">A todos</span></a>
      <?php endif; ?>
      <a class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" href="<?= e(correoUrl(['redactar' => 1, 'c' => $c, 'reenviar' => $m['uid']])) ?>"><?= UI::iconoDS('enviar', 16) ?><span class="mc-btn__label">Reenviar</span></a>
      <form method="post" action="actions.php" class="correo-inline">
        <input type="hidden" name="accion" value="correo_marcar"><input type="hidden" name="c" value="<?= e($c) ?>">
        <input type="hidden" name="uid" value="<?= (int)$m['uid'] ?>"><input type="hidden" name="leido" value="0">
        <button class="mc-iconbtn mc-iconbtn--sm" aria-label="Marcar como no leído" data-ds-tip="Marcar como no leído"><?= UI::iconoDS('correo', 18) ?></button>
      </form>
      <form method="post" action="actions.php" class="correo-inline"<?= $enPapelera ? ' data-confirmar="Se borrará para siempre de tu buzón." data-confirmar-titulo="¿Borrar este correo?" data-confirmar-ok="Sí, borrar"' : '' ?>>
        <input type="hidden" name="accion" value="correo_eliminar"><input type="hidden" name="c" value="<?= e($c) ?>">
        <input type="hidden" name="uid" value="<?= (int)$m['uid'] ?>">
        <button class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--danger" aria-label="<?= $enPapelera ? 'Borrar para siempre' : 'Mover a la papelera' ?>" data-ds-tip="<?= $enPapelera ? 'Borrar para siempre' : 'Mover a la papelera' ?>"><?= UI::iconoDS('eliminar', 18) ?></button>
      </form>
    </div>
  </div>
  <header class="correo-msg__cab">
    <h2 class="correo-msg__asunto"><?= e($m['asunto'] !== '' ? $m['asunto'] : '(sin asunto)') ?><?= correoPrioridad((int)($m['prioridad'] ?? 3)) ?></h2>
    <div class="correo-msg__quien">
      <span class="correo-ini correo-ini--lg" style="background:<?= e(correoColor($de['email'])) ?>" aria-hidden="true"><?= e(correoIniciales($de)) ?></span>
      <div class="correo-msg__meta">
        <p><b><?= e(correoQuien($de)) ?></b><?php if ($de['nombre'] !== ''): ?> <span>&lt;<?= e($de['email']) ?>&gt;</span><?php endif; ?></p>
        <?php
          // A quién más le llegó: cada destinatario como ficha (Para y CC), con «Tú» marcado.
          // Si no estás en ninguno de los dos, te llegó con copia oculta o por una lista.
          $yoMailL = strtolower((string)((Correo::cuenta((int)(Auth::usuario()['id'] ?? 0)) ?? [])['email'] ?? ''));
          $esYo = fn(array $d) => $yoMailL !== '' && strtolower((string)$d['email']) === $yoMailL;
          // Cada uno con su bolita de color e iniciales (el mismo color que tendría como remitente)
          $fichas = fn(array $l) => implode('', array_map(fn($d) => '<span class="correo-dest' . ($esYo($d) ? ' correo-dest--yo' : '') . '" data-ds-tip="' . e((string)$d['email']) . '">'
              . '<span class="correo-dest__ini" style="background:' . e(correoColor((string)$d['email'])) . '" aria-hidden="true">' . e(correoIniciales($d)) . '</span>'
              . '<span class="correo-dest__nom">' . e($esYo($d) ? 'Tú' : correoQuien($d)) . '</span></span>', array_slice($l, 0, 60)))
              . (count($l) > 60 ? '<span class="correo-dest-mas">y ' . (count($l) - 60) . ' más</span>' : '');
          $yoEnPara = (bool)array_filter($m['para'], $esYo);
          $yoEnCc = (bool)array_filter($m['cc'], $esYo);
          $esMio = $esYo($de);
        ?>
        <p class="correo-dest-fila"><span class="correo-dest-tit">Para</span><?= $m['para'] ? $fichas($m['para']) : '<span class="correo-dest-mas">—</span>' ?></p>
        <?php if ($m['cc']): ?><p class="correo-dest-fila"><span class="correo-dest-tit">CC</span><?= $fichas($m['cc']) ?></p><?php endif; ?>
        <?php if ($yoMailL !== '' && !$esMio && !$yoEnPara && $yoEnCc): ?>
        <p class="correo-dest-nota">Te llegó con copia (CC).</p>
        <?php elseif ($yoMailL !== '' && !$esMio && !$yoEnPara && !$yoEnCc): ?>
        <p class="correo-dest-nota">No estás en «Para» ni en «CC»: te llegó con copia oculta (CCO), por una lista o por un reenvío automático.</p>
        <?php endif; ?>
      </div>
      <time class="correo-msg__fecha"><?= e(Correo::fechaLarga($m['fecha'])) ?></time>
    </div>
  </header>
  <?php if (count($hilo) > 1) correoHilo($hilo); ?>
  <?php if ($esCopia): ?>
  <div class="correo-pad correo-pad--fino">
    <?= UI::alertaDS('info', '', 'Este correo ya no está en el servidor (otro programa, como Gmail, lo descargó y lo quitó). '
        . 'Es la copia que el panel guardó el ' . e(Correo::fechaLarga((int)($m['guardado'] ?? time()))) . '.', true) ?>
  </div>
  <?php endif; ?>
  <?php /* «Analizar con IA» siempre: si ya no está en el servidor, la IA lee la copia guardada */
  if (empty($m['solo_cabecera'])) correoBloqueRequerimiento($m, $c); ?>
  <?php if ($hayRemotas && !$remotas): ?>
  <div class="correo-pad correo-pad--fino">
    <?= UI::alertaDS('info', '', 'Las imágenes externas están ocultas para cuidar tu privacidad (pueden avisar al remitente que abriste el correo). '
        . '<a href="' . e(correoUrl(['c' => $c, 'uid' => $m['uid'], 'img' => 1])) . '">Mostrar imágenes</a>', true) ?>
  </div>
  <?php endif; ?>
  <div class="correo-msg__cuerpo">
    <?php if ($doc !== null): ?>
    <iframe class="correo-marco" title="Contenido del correo" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin" referrerpolicy="no-referrer"
            srcdoc="<?= e($doc) ?>" data-correo-marco></iframe>
    <?php else: ?>
    <div class="correo-texto"><?= CorreoHtml::texto((string)($m['texto'] ?? '')) ?: '<i>(sin contenido)</i>' ?></div>
    <?php endif; ?>
  </div>
  <?php if ($visibles): ?>
  <div class="correo-adjuntos" data-visor-grupo>
    <?php foreach ($visibles as $i => $a):
        // PDF e imágenes se abren en el visor del panel (admin.js), con flechas y «Descargar»; lo demás se descarga
        $tipoVer = correoTipoVisible($a);
        $urlAdj = correoPagina() . '?' . http_build_query(['correo' => 'adjunto', 'c' => $c, 'uid' => $m['uid'], 'i' => $i] + ($tipoVer !== '' ? ['ver' => 1] : []));
    ?>
    <a class="correo-adjunto<?= $tipoVer !== '' ? ' correo-adjunto--ver' : '' ?>" href="<?= e($urlAdj) ?>"<?= $tipoVer !== ''
        ? ' data-visor data-ext="' . ($tipoVer === 'application/pdf' ? 'pdf' : 'png') . '" data-nombre="' . e($a['nombre']) . '"'
          . ' data-bajar="' . e(correoPagina() . '?' . http_build_query(['correo' => 'adjunto', 'c' => $c, 'uid' => $m['uid'], 'i' => $i])) . '"' : '' ?>>
      <span class="correo-adjunto__tipo"><?= UI::icono('adj-' . Correo::claseArchivo((string)$a['nombre'], (string)$a['tipo']), 'correo-adj__ico') ?></span>
      <span class="correo-adjunto__txt"><b><?= e($a['nombre']) ?></b><small><?= e(correoTam($a['tam'])) . ($tipoVer !== '' ? ' · Ver' : '') ?></small></span>
      <?= UI::iconoDS($tipoVer !== '' ? 'ver' : 'descargar', 16) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
    <?php
}

/**
 * Zona para adjuntar archivos con el estilo del sistema: se sueltan encima o
 * se eligen; debajo, la lista con el tamaño de cada uno y una × para quitarlo
 * (assets/ds.js). Sin JS sigue funcionando: la etiqueta abre el selector.
 */
/**
 * En un correo abierto, para el administrador: si ya es un requerimiento (o
 * está listo para revisar), lo dice con el enlace; si no, ofrece armarlo con
 * la IA (lib/ReqCorreo.php: institución, responsable, plazo y adjuntos).
 */
/**
 * La conversación (Correo::hilo): cada correo en su orden, «Tú enviaste a …»,
 * «Felipe respondió», con su fecha; el que estás leyendo, marcado. Un clic abre
 * cualquiera. Plegada si es larga.
 */
function correoHilo(array $hilo): void
{
    $n = count($hilo);
    echo '<div class="correo-pad correo-pad--fino"><details class="correo-hilo"' . ($n <= 6 ? ' open' : '') . '><summary>' . UI::iconoDS('mensajes', 16)
        . '<span>Conversación · ' . $n . ' correos</span><small>Lo que enviaste y lo que te respondieron, en orden</small></summary><ol class="correo-hilo__lista">';
    foreach (array_values($hilo) as $i => $x) {
        $reenvio = preg_match('/^\s*(fw|fwd|rv|reenv)\s*:/i', (string)$x['asunto']) === 1;
        $verbo = $i === 0 ? ($x['mio'] ? 'enviaste' : 'escribió')
            : ($reenvio ? ($x['mio'] ? 'reenviaste' : 'reenvió') : ($x['mio'] ? 'respondiste' : 'respondió'));
        $para = array_values(array_filter((array)$x['para'], fn($d) => !empty($d['email'])));
        $a = implode(', ', array_map(fn($d) => correoQuien($d), array_slice($para, 0, 3))) . (count($para) > 3 ? ' y ' . (count($para) - 3) . ' más' : '');
        $dentro = '<b>' . e($x['mio'] ? 'Tú' : correoQuien((array)$x['de'])) . '</b> <span>' . e($verbo) . ($x['mio'] && $a !== '' ? ' a ' . e($a) : '') . '</span>'
            . '<small>' . e(Correo::fechaLarga((int)$x['fecha'])) . ($x['tipo'] === 'sent' ? ' · en Enviados' : '') . '</small>';
        echo '<li class="correo-hilo__item' . ($x['este'] ? ' is-este' : '') . ($x['mio'] ? ' is-mio' : '') . '">'
            . '<span class="correo-hilo__ini" style="background:' . e(correoColor((string)($x['de']['email'] ?? ''))) . '" aria-hidden="true">' . e(correoIniciales((array)$x['de'])) . '</span>'
            . ($x['este'] ? '<div class="correo-hilo__txt">' . $dentro . '</div><span class="correo-hilo__aqui">Estás aquí</span>'
                          : '<a class="correo-hilo__txt" href="' . e(correoUrl(['c' => $x['carpeta'], 'uid' => $x['uid']])) . '">' . $dentro . '</a>')
            . '</li>';
    }
    echo '</ol></details></div>';
}

function correoBloqueRequerimiento(array $m, string $carpeta): void
{
    if (!class_exists('ReqCorreo') || !Auth::esAdmin() || verComo()) return;
    $fila = ReqCorreo::deMensaje((int)(Auth::usuario()['id'] ?? 0), (string)$m['message_id']);
    $estado = (string)($fila['estado'] ?? '');
    $titulo = (string)($fila['datos']['titulo'] ?? '');
    echo '<div class="correo-pad correo-pad--fino correo-req">';
    if ($estado === 'registrado') {
        echo UI::alertaDS('success', 'Ya es un requerimiento', '«' . e($titulo) . '» · <a href="requerimientos.php?ver=' . (int)$fila['req_id'] . '">Ver el requerimiento</a>', true);
    } elseif ($estado === 'propuesta') {
        echo UI::alertaDS('info', 'La IA lo preparó como requerimiento', '«' . e($titulo) . '» · <a href="requerimientos.php?propuesta=' . (int)$fila['id'] . '">Revisar y registrar</a>', true);
    }
    $conReq = UI::ia('req_desde_correo');
    $conTareas = class_exists('TareasCorreo') && UI::ia('tareas_desde_correo');
    // Lo que ya salió de este correo como tareas de un proyecto (lib/TareasCorreo.php)
    $proyTc = [];
    $hechasTc = [];
    if ($conTareas) {
        foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) {
            $proyTc[] = ['id' => (int)$p['id'], 'nombre' => (string)$p['nombre'], 'activo' => ($p['estado'] ?? 'activo') === 'activo'];
        }
        usort($proyTc, fn($a, $b) => [!$a['activo'], $a['nombre']] <=> [!$b['activo'], $b['nombre']]);
        $nombreTc = array_column($proyTc, 'nombre', 'id');
        $hechasTc = TareasCorreo::deMensaje((int)(Auth::usuario()['id'] ?? 0), (string)$m['message_id']);
        if ($hechasTc) {
            echo UI::alertaDS('success', 'Ya salieron tareas de este correo', implode(' · ', array_map(fn($h) => $h['n'] . ' tarea' . ($h['n'] === 1 ? '' : 's')
                . ' en <a href="proyecto.php?id=' . (int)$h['proyecto_id'] . '">«' . e($nombreTc[$h['proyecto_id']] ?? 'un proyecto') . '»</a>', $hechasTc)), true);
        }
    }
    // Un solo botón con la estrella de la IA: abre el asistente (assets/ia.js) donde el administrador
    // elige si son tareas de un proyecto o un requerimiento suelto. La IA solo corre cuando él lo pide.
    if ($conReq || $conTareas) {
        $texto = match ($estado) {
            'no_es'      => 'La IA creyó que este correo no pedía un trabajo. Si sí lo pide, analízalo igual.',
            'error'      => 'No se pudo analizar: ' . e((string)($fila['error'] ?? '')) . ' Prueba de nuevo.',
            'descartado' => 'Descartaste el requerimiento de este correo. Puedes analizarlo otra vez.',
            default      => ($estado === 'registrado' || $estado === 'propuesta' || $hechasTc)
                ? '¿Trae más trabajo? Puedes analizarlo otra vez.'
                : '¿Trae trabajo? Conviértelo en tareas de un proyecto o en un requerimiento suelto: tú eliges y la IA lo arma.',
        };
        echo '<div class="correo-req__fila"><span class="mc-tile mc-tile--sm mc-tile--azul">' . UI::iconoDS('ia', 18) . '</span>'
            . '<p>' . $texto . '</p>'
            . '<button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-ia-correo data-c="' . e($carpeta) . '" data-uid="' . (int)$m['uid'] . '"'
            . ' data-req="' . ($conReq ? '1' : '') . '" data-tareas="' . ($conTareas ? '1' : '') . '" data-asunto="' . e((string)$m['asunto']) . '"'
            . ' data-proyectos="' . e(json_encode(array_map(fn($p) => ['id' => $p['id'], 'nombre' => $p['nombre'] . ($p['activo'] ? '' : ' (no activo)')], $proyTc), JSON_UNESCAPED_UNICODE)) . '">'
            . UI::iconoDS('ia', 16) . '<span class="mc-btn__label">Analizar con IA</span></button></div>';
    }
    echo '</div>';
}

function correoZonaAdjuntos(string $name, string $id, string $etiqueta): string
{
    $mb = (int)(Correo::TOPE_ADJUNTOS / 1048576);
    return '<div class="ds-adjuntos" data-adjuntos data-tope="' . Correo::TOPE_ADJUNTOS . '">'
        . '<input class="mc-sr-only" id="' . e($id) . '" type="file" name="' . e($name) . '" multiple data-adjuntos-input aria-labelledby="' . e($etiqueta) . '">'
        . '<label class="ds-adjuntos__zona" for="' . e($id) . '" data-adjuntos-zona>'
        . '<span class="mc-tile mc-tile--sm mc-tile--azul">' . UI::iconoDS('importar', 18) . '</span>'
        . '<span class="ds-adjuntos__txt"><b>Arrastra los archivos aquí o <u>elígelos</u></b>'
        . '<small>PDF, imágenes, documentos… hasta ' . $mb . ' MB en total</small></span></label>'
        . '<ul class="ds-adjuntos__lista" data-adjuntos-lista></ul>'
        . '<p class="ds-adjuntos__total" data-adjuntos-total hidden></p>'
        . '</div>';
}

/**
 * Directorio para sugerir destinatarios al redactar: la gente del panel (con
 * su correo InnoTech si lo sincronizó; si no, el de su cuenta) y las
 * direcciones a las que la persona ya escribió. Sin la propia.
 */
function correoDirectorio(int $mid, string $yoMail): array
{
    $sinc = Correo::correosSincronizados();
    $equipos = Catalogo::equipos();
    $equipo = [];
    foreach ((new MiembroRepo())->todos() as $m) {
        $email = $sinc[(int)$m['id']] ?? strtolower(trim((string)($m['email'] ?? '')));
        if ($email === '' || $email === $yoMail || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($equipo[$email])) continue;
        $desc = array_filter([trim((string)($m['rol'] ?? '')), $equipos[$m['equipo'] ?? ''][0] ?? '']);
        $equipo[$email] = [
            'n' => trim((string)($m['nombre'] ?? '')),
            'e' => $email,
            'd' => implode(' · ', $desc),
            'i' => MiembroRepo::iniciales($m),
            'c' => Catalogo::colorDe($m['color'] ?? 0),
            'f' => (string)($m['foto'] ?? ''),
        ];
    }
    $recientes = array_values(array_filter(Correo::recientes($mid), fn($r) => $r['e'] !== $yoMail));
    return ['equipo' => array_values($equipo), 'recientes' => array_map(fn($r) => ['e' => $r['e'], 'n' => (string)($r['n'] ?? '')], $recientes)];
}

function correoRedactar(array $yo, array $cuenta, array $carpeta, ?array $base, array $carpetas = []): void
{
    $yoMail = strtolower((string)$cuenta['email']);
    $para = $cc = $cco = $asunto = $texto = $enResp = $refs = '';
    // Opciones de envío: prioridad normal, sin confirmación ni aviso, copia en Enviados
    $prioridad = 3;
    $acuse = $dsn = false;
    $guardarEn = (string)(Correo::carpetaDeTipo($carpetas, 'sent') ?? '');
    $reenviar = isset($_GET['reenviar']) && $base;
    if ($base) {
        $de = $base['de'][0] ?? ['nombre' => '', 'email' => ''];
        $quien = correoQuien($de);
        $cita = "\n\n\nEl " . Correo::fechaLarga($base['fecha']) . ', ' . $quien . " escribió:\n"
            . preg_replace('/^/m', '> ', trim((string)$base['texto_cita']));
        if ($reenviar) {
            $asunto = preg_match('/^(rv|fwd?|reenv)\s*:/i', $base['asunto']) ? $base['asunto'] : 'Rv: ' . $base['asunto'];
            $texto = "\n\n\n---------- Mensaje reenviado ----------\nDe: " . correoDirs($base['de']) . "\nFecha: " . Correo::fechaLarga($base['fecha'])
                . "\nAsunto: " . $base['asunto'] . "\nPara: " . correoDirs($base['para']) . "\n\n" . trim((string)$base['texto_cita']);
        } else {
            $responderA = $base['responder_a'] ?: $base['de'];
            $para = implode(', ', array_column($responderA, 'email'));
            if (!empty($_GET['todos'])) {
                $otros = array_filter(array_merge($base['para'], $base['cc']), fn($d) => $d['email'] !== $yoMail && !in_array($d['email'], array_column($responderA, 'email'), true));
                $cc = implode(', ', array_unique(array_column($otros, 'email')));
            }
            $asunto = preg_match('/^re\s*:/i', $base['asunto']) ? $base['asunto'] : 'Re: ' . $base['asunto'];
            $texto = $cita;
            $enResp = $base['message_id'];
            $refs = trim($base['referencias'] . ' ' . $base['message_id']);
        }
    }
    // La respuesta que armó la IA («Responder con IA»): arriba, con la cita del original debajo
    $deIa = false;
    $ri = $_SESSION['correo_respuesta_ia'] ?? null;
    if (is_array($ri) && $base && !$reenviar && (int)($ri['uid'] ?? 0) === (int)$base['uid'] && (string)($ri['c'] ?? '') === (string)$carpeta['nombre']) {
        unset($_SESSION['correo_respuesta_ia']);
        $texto = rtrim((string)$ri['texto']) . $texto;
        $deIa = true;
    }
    // Si el envío anterior falló, vuelve lo que la persona había escrito
    if (!empty($_SESSION['correo_borrador']) && is_array($_SESSION['correo_borrador'])) {
        $bor = $_SESSION['correo_borrador'];
        unset($_SESSION['correo_borrador']);
        $para = (string)($bor['para'] ?? $para);
        $cc = (string)($bor['cc'] ?? $cc);
        $cco = (string)($bor['cco'] ?? $cco);
        $asunto = (string)($bor['asunto'] ?? $asunto);
        $texto = (string)($bor['texto'] ?? $texto);
        $enResp = (string)($bor['en_respuesta_a'] ?? $enResp);
        $refs = (string)($bor['referencias'] ?? $refs);
        if ((int)($bor['reenviar_uid'] ?? 0) > 0) $reUid = (int)$bor['reenviar_uid'];
        $prioridad = (int)($bor['prioridad'] ?? $prioridad);
        $acuse = !empty($bor['acuse']);
        $dsn = !empty($bor['dsn']);
        $guardarEn = (string)($bor['guardar_en'] ?? $guardarEn);
    }
    $reUid = $reUid ?? ($reenviar ? (int)$base['uid'] : 0);
    $titulo = $reenviar ? 'Reenviar' : ($base ? 'Responder' : 'Nuevo correo');
    ?>
  <div class="correo-barra correo-barra--msg">
    <a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?= e($base ? correoUrl(['c' => $carpeta['nombre'], 'uid' => $base['uid']]) : correoUrl(['c' => $carpeta['nombre']])) ?>"><?= UI::iconoDS('chevron-izq', 16) ?><span class="mc-btn__label">Cancelar</span></a>
    <h2 class="correo-redactar__titulo"><?= e($titulo) ?></h2>
  </div>
  <form method="post" action="actions.php" enctype="multipart/form-data" class="correo-redactar">
    <input type="hidden" name="accion" value="correo_enviar">
    <input type="hidden" name="c" value="<?= e($carpeta['nombre']) ?>">
    <input type="hidden" name="en_respuesta_a" value="<?= e($enResp) ?>">
    <input type="hidden" name="referencias" value="<?= e($refs) ?>">
    <?php if ($reUid > 0): ?><input type="hidden" name="reenviar_uid" value="<?= $reUid ?>"><?php endif; ?>
    <script type="application/json" id="correo-directorio"><?= json_encode(correoDirectorio((int)$yo['id'], $yoMail), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <p class="correo-redactar__de">De: <b><?= e(trim((string)$yo['nombre'])) ?></b> &lt;<?= e($yoMail) ?>&gt;</p>
    <?php
      // Opciones de envío, como en Roundcube: arriba, plegadas; se abren solas si alguna no está en lo normal
      $opcCarpetas = [];
      foreach ($carpetas as $f) if (!in_array($f['tipo'], ['trash', 'junk', 'spam'], true)) $opcCarpetas[$f['nombre']] = $f['etiqueta'];
      $opcCarpetas['-'] = 'No guardar una copia';
      $enviadosNom = (string)(Correo::carpetaDeTipo($carpetas, 'sent') ?? '');
      $abiertas = $prioridad !== 3 || $acuse || $dsn || ($guardarEn !== '' && $guardarEn !== $enviadosNom);
    ?>
    <details class="correo-opciones"<?= $abiertas ? ' open' : '' ?>>
      <summary><?= UI::iconoDS('configuracion', 16) ?><span>Opciones de envío</span><small>Prioridad, confirmación de lectura, aviso de entrega y dónde guardarlo</small></summary>
      <div class="correo-opciones__cuerpo">
        <div class="correo-doble">
          <div class="mc-field"><span class="mc-field__label">Prioridad</span>
            <?= UI::selectDS('prioridad', ['1' => 'Más alta', '2' => 'Alta', '3' => 'Normal', '4' => 'Baja', '5' => 'Más baja'], (string)$prioridad, ['tam' => 'md', 'aria' => 'Prioridad']) ?></div>
          <div class="mc-field"><span class="mc-field__label">Guardar el mensaje enviado en</span>
            <?= UI::selectDS('guardar_en', $opcCarpetas, $guardarEn !== '' ? $guardarEn : $enviadosNom, ['tam' => 'md', 'aria' => 'Guardar el mensaje enviado en']) ?></div>
        </div>
        <?= UI::checkDS('acuse', $acuse, 'Pedir confirmación de lectura', 'A quien lo abra, su programa le pregunta si quiere avisarte que lo leyó (puede decir que no).') ?>
        <?= UI::checkDS('dsn', $dsn, 'Avisarme cuando se entregue', 'El servidor te manda un correo cuando llega a cada destinatario, o si no se pudo entregar.') ?>
      </div>
    </details>
    <div class="mc-field"><label class="mc-field__label" for="cr-para">Para</label>
      <div class="mc-input"><input class="mc-input__control" id="cr-para" name="para" required maxlength="2000" value="<?= e($para) ?>" placeholder="Escribe un nombre o un correo" autocomplete="off" data-destinatarios="para"<?= $para === '' ? ' autofocus' : '' ?>></div></div>
    <div class="correo-doble">
      <div class="mc-field"><label class="mc-field__label" for="cr-cc">CC <small>(con copia)</small></label>
        <div class="mc-input"><input class="mc-input__control" id="cr-cc" name="cc" maxlength="2000" value="<?= e($cc) ?>" autocomplete="off" placeholder="Nombre o correo" data-destinatarios="cc"></div></div>
      <div class="mc-field"><label class="mc-field__label" for="cr-cco">CCO <small>(copia oculta)</small></label>
        <div class="mc-input"><input class="mc-input__control" id="cr-cco" name="cco" maxlength="2000" value="<?= e($cco) ?>" autocomplete="off" placeholder="Nombre o correo" data-destinatarios="cco"></div>
        <div class="mc-field__foot"><span class="mc-field__hint">Les llega, pero nadie más ve que se les envió.</span></div></div>
    </div>
    <div class="mc-field"><label class="mc-field__label" for="cr-asunto">Asunto</label>
      <div class="mc-input"><input class="mc-input__control" id="cr-asunto" name="asunto" maxlength="250" value="<?= e($asunto) ?>"></div></div>
    <?php if ($deIa): ?><?= UI::alertaDS('info', '', 'La respuesta la armó la IA: revísala (y completa lo que esté entre [corchetes]) antes de enviar.', true) ?><?php endif; ?>
    <div class="mc-field"><label class="mc-field__label" for="cr-texto">Mensaje</label>
      <div class="mc-input mc-input--multiline"><textarea class="mc-input__control correo-redactar__texto" id="cr-texto" name="texto" rows="12"<?= $para !== '' ? ' autofocus' : '' ?>><?= e($texto) ?></textarea></div></div>
    <div class="mc-field">
      <span class="mc-field__label" id="cr-adj-l">Adjuntos</span>
      <?= correoZonaAdjuntos('adjuntos[]', 'cr-adj', 'cr-adj-l') ?>
      <?php if ($reenviar && array_filter($base['adjuntos'], fn($a) => !$a['inline'] || $a['cid'] === '')): ?>
      <div class="mc-field__foot"><span class="mc-field__hint">Van también los adjuntos del correo original.</span></div>
      <?php endif; ?>
    </div>
    <div class="correo-redactar__pie">
      <button class="mc-btn mc-btn--success mc-btn--md" data-enviar><?= UI::iconoDS('enviar', 18) ?><span class="mc-btn__label">Enviar</span></button>
    </div>
  </form>
    <?php
}

/** Descarga un adjunto (?correo=adjunto&c=&uid=&i=). Termina la petición. */
function correoDescargarAdjunto(int $mid): void
{
    try {
        $imap = Correo::abrir($mid);
        $carpetas = Correo::carpetas($imap, $mid);
        $carpeta = Correo::carpetaValida($carpetas, (string)($_GET['c'] ?? ''));
        $imap->abrir($carpeta['nombre']);
        $crudo = $imap->crudo((int)($_GET['uid'] ?? 0));
        $imap->cerrar();
    } catch (CorreoError $e) {
        http_response_code(502);
        exit('No se pudo descargar el adjunto: ' . $e->getMessage());
    }
    $m = $crudo !== '' ? CorreoMime::analizar($crudo)
        : ['adjuntos' => class_exists('CorreoCopias') ? CorreoCopias::adjuntos($mid, $carpeta['nombre'], (int)($_GET['uid'] ?? 0)) : []];
    $a = $m['adjuntos'][(int)($_GET['i'] ?? -1)] ?? null;
    if (!$a) {
        http_response_code(404);
        exit('Ese adjunto ya no está.');
    }
    correoEntregarAdjunto($a, !empty($_GET['ver']));
}

/**
 * Tipo con el que un adjunto se puede mostrar dentro del panel (visor de PDF e
 * imágenes), mirando sus primeros bytes y no lo que dice el correo. Solo PDF e
 * imágenes de mapa de bits: un SVG o un HTML podrían llevar código. '' = no.
 */
function correoTipoVisible(array $a): string
{
    $d = (string)$a['datos'];
    return match (true) {
        str_starts_with($d, '%PDF-')                                    => 'application/pdf',
        str_starts_with($d, "\x89PNG\r\n\x1a\n")                        => 'image/png',
        str_starts_with($d, "\xFF\xD8\xFF")                             => 'image/jpeg',
        str_starts_with($d, 'GIF87a') || str_starts_with($d, 'GIF89a') => 'image/gif',
        str_starts_with($d, 'RIFF') && substr($d, 8, 4) === 'WEBP'     => 'image/webp',
        default                                                         => '',
    };
}

/** Manda un adjunto al navegador: como descarga o, si se pidió verlo y se puede, para mostrarlo. */
function correoEntregarAdjunto(array $a, bool $verEnLinea = false): never
{
    $nombre = CorreoMime::nombreArchivo($a['nombre']);
    $ascii = preg_replace('/[^\x20-\x7e]|"/', '_', $nombre);
    $tipo = $verEnLinea ? correoTipoVisible($a) : '';
    header('Content-Type: ' . ($tipo !== '' ? $tipo : 'application/octet-stream'));
    header('Content-Disposition: ' . ($tipo !== '' ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    header('Content-Length: ' . strlen($a['datos']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $a['datos'];
    exit;
}
