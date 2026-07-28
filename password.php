<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
boot();
$u = current_user();
if (!$u) { header('Location: login.php'); exit; }

$msg = null; $ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actual = $_POST['actual'] ?? '';
    $nueva  = $_POST['nueva'] ?? '';
    $rep    = $_POST['repetir'] ?? '';
    if (!password_verify($actual, $u['password_hash'])) {
        $msg = 'La contraseña actual no es correcta.';
    } elseif (strlen($nueva) < 8) {
        $msg = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $rep) {
        $msg = 'Las contraseñas no coinciden.';
    } else {
        db()->prepare("UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        flash_set(true, 'Contraseña actualizada.');
        header('Location: ' . home_for($u)); exit;
    }
}

page_top('Cambiar contraseña', $u, 'password');
?>
<h1>Cambiar contraseña</h1>
<?php if ($u['must_change']): ?>
  <div class="alert good">Por seguridad, cambia tu contraseña asignada antes de continuar.</div>
<?php endif; ?>
<div class="card" style="max-width:420px">
  <?php if ($msg): ?><div class="alert bad"><?= e($msg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Contraseña actual</label>
    <input type="password" name="actual" required autocomplete="current-password">
    <label>Nueva contraseña (mínimo 8 caracteres)</label>
    <input type="password" name="nueva" required minlength="8" autocomplete="new-password">
    <label>Repetir nueva contraseña</label>
    <input type="password" name="repetir" required autocomplete="new-password">
    <br><br>
    <button class="btn full" type="submit">Guardar</button>
  </form>
</div>
<?php page_bottom();
