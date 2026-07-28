<?php
/** MiSalaUCR — Plantilla HTML compartida. */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page_top(string $title, ?array $u = null, string $active = ''): void {
    $links = [];
    if ($u) {
        if ($u['role'] === 'student') $links = [['student.php', 'Reservar', 'student']];
        if ($u['role'] === 'admin')   $links = [['admin.php', 'Panel', 'admin']];
        if ($u['role'] === 'super')   $links = [['superadmin.php', 'Organizaciones', 'super']];
        $links[] = ['password.php', 'Contraseña', 'password'];
    }
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — MiSalaUCR</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="wrap bar">
    <a class="brand" href="index.php">Mi<span>Sala</span>UCR</a>
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
<main class="wrap">
<?php
}

function page_bottom(): void {
    ?></main>
<footer class="foot">MiSalaUCR · Sistema de reservación de salas de estudio</footer>
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
