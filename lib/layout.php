<?php
/** MiSalaUCR — Plantilla HTML compartida. */

require_once __DIR__ . '/temas.php';
require_once __DIR__ . '/schedule.php';

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page_top(string $title, ?array $u = null, string $active = ''): void {
    $links = [];
    if ($u) {
        if ($u['role'] === 'student') $links = [['student.php', 'Reservar', 'student'], ['horario.php', 'Horario', 'horario']];
        if ($u['role'] === 'admin')   $links = [['admin.php', 'Panel', 'admin']];
        if ($u['role'] === 'super')   $links = [['superadmin.php', 'Organizaciones', 'super']];
        $links[] = ['password.php', 'Contraseña', 'password'];
    }

    // Tema de temporada: el del usuario si hay sesión, si no el de la primera
    // organización activa (para que login/suspendida también se vean temáticos).
    $org  = $u ? org_of($u) : (db()->query("SELECT * FROM organizations WHERE active=1 ORDER BY id LIMIT 1")->fetch() ?: null);
    $tema = tema_actual($org);
    $GLOBALS['_msu_tema'] = $tema;
    $GLOBALS['_msu_ingreso'] = !$u;
    $pantalla = $active === '' ? ' pantalla-ingreso' : '';
    // Versión de los assets estáticos (fecha de modificación de style.css). Se
    // usa para el cache-busting del <link> Y para que el JS de abajo detecte
    // cuándo hay un despliegue nuevo mientras la pestaña ya estaba abierta.
    $assetVer = (int)(@filemtime(__DIR__ . '/../assets/style.css') ?: 1);
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="<?= e(tema_color_barra($tema)) ?>">
<title><?= e($title) ?> — MiSalaUCR</title>
<link rel="icon" href="assets/brand/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/brand/app-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=IBM+Plex+Mono:wght@500<?= tema_fuentes($tema) ?>&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= $assetVer ?>">
<?php if ($tema !== 'none'): ?><link rel="stylesheet" href="assets/temas.css?v=<?= (int)(@filemtime(__DIR__ . '/../assets/temas.css') ?: 1) ?>"><?php endif; ?>
<script>window.MSU_VER = <?= $assetVer ?>;</script>
</head>
<body class="<?= $tema !== 'none' ? 'tema-' . e($tema) . $pantalla : '' ?>">
<header class="topbar">
  <div class="wrap bar">
    <a class="msu-logo msu-logo--sm" href="index.php" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </a>
    <?php if ($u): ?>
    <nav>
      <?php foreach ($links as [$href, $label, $key]): ?>
        <a href="<?= e($href) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <a href="logout.php" class="salir">Salir</a>
    </nav>
    <?php endif; ?>
  </div>
</header>
<?php if ($u) tema_deco_barra($tema); ?>
<?php if ($u): ?><div class="wrap barra-abiertas"><?= render_open_orgs_pill() ?></div><?php endif; ?>
<main class="wrap">
<?php if (!$u) tema_deco_ingreso($tema); ?>
<?php
}

/** Tema de temporada activo en la página actual (disponible tras llamar page_top()). */
function tema_global(): string { return $GLOBALS['_msu_tema'] ?? 'none'; }

function page_bottom(): void {
    $tema = $GLOBALS['_msu_tema'] ?? 'none';
    ?></main>
<?php if ($GLOBALS['_msu_ingreso'] ?? false) tema_deco_pie($tema); ?>
<footer class="foot">MiSalaUCR · Sistema de reservación de salas de estudio · <?= date('Y') ?></footer>
<script>
/* Avisa si hay una versión más nueva desplegada mientras esta pestaña ya
   estaba abierta (el <link> con ?v= solo ayuda en cargas nuevas). Revisa
   cada minuto contra version.php; si detecta cambio, avisa y recarga sola
   tras una cuenta atrás, dando tiempo a terminar de escribir. */
(function () {
  if (!window.MSU_VER) return;
  var VER = window.MSU_VER;
  var temporizador = null;

  function verificar() {
    fetch('version.php?_=' + Date.now(), { cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        var nueva = parseInt(txt, 10);
        if (nueva && nueva > VER) mostrarAviso(nueva);
      })
      .catch(function () {});
  }

  function mostrarAviso(nueva) {
    if (document.getElementById('msuAvisoUpd')) return;
    if (sessionStorage.getItem('msu_ver_ignorado') === String(nueva)) return;
    var seg = 8;
    var div = document.createElement('div');
    div.id = 'msuAvisoUpd';
    div.className = 'msu-aviso-upd';
    div.innerHTML = '<span>Hay una actualización disponible. Se recargará en <b id="msuSeg">' + seg + '</b>s.</span>'
      + '<button type="button" id="msuRecargar">Recargar ahora</button>'
      + '<button type="button" id="msuSeguir">Seguir editando</button>';
    document.body.appendChild(div);
    document.getElementById('msuRecargar').addEventListener('click', function () { location.reload(); });
    document.getElementById('msuSeguir').addEventListener('click', function () {
      clearInterval(temporizador);
      sessionStorage.setItem('msu_ver_ignorado', String(nueva));
      div.remove();
    });
    temporizador = setInterval(function () {
      seg--;
      var el = document.getElementById('msuSeg');
      if (el) el.textContent = seg;
      if (seg <= 0) { clearInterval(temporizador); location.reload(); }
    }, 1000);
  }

  setInterval(verificar, 60000);
})();
</script>
</body>
</html><?php
}

function flash_get(): array {
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m ? [$m['ok'], $m['msg']] : [null, null];
}

function flash_set(bool $ok, string $msg): void {
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
}

function show_flash(): void {
    [$ok, $msg] = flash_get();
    if ($msg !== null) {
        echo '<div class="alert ' . ($ok ? 'good' : 'bad') . '">' . e($msg) . '</div>';
    }
}
