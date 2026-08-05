<?php
/** MiSalaUCR — Definir nueva contraseña a partir de un enlace de recuperación. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
boot();

if (current_user()) { header('Location: ' . home_for(current_user())); exit; }

/** Busca un token vigente (sin usar y no expirado) por su hash. */
function find_valid_reset(string $token): ?array {
    if ($token === '') return null;
    $st = db()->prepare(
        "SELECT pr.*, u.name, u.email FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used = 0 AND pr.expires_at > ?
         ORDER BY pr.id DESC LIMIT 1");
    $st->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
    return $st->fetch() ?: null;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$reset = find_valid_reset($token);
$msg = null;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nueva = $_POST['nueva'] ?? '';
    $rep   = $_POST['repetir'] ?? '';

    if (strlen($nueva) < 8) {
        $msg = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $rep) {
        $msg = 'Las contraseñas no coinciden.';
    } else {
        db()->prepare("UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $reset['user_id']]);
        db()->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
        flash_set(true, 'Contraseña actualizada. Ya puedes iniciar sesión.');
        header('Location: login.php'); exit;
    }
}

page_top('Restablecer contraseña');
?>
<div class="login-box">
  <div class="brand-big">
    <span class="msu-logo msu-logo--xl msu-logo--vertical" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </span>
  </div>
  <div class="card">
    <?php if (!$reset): ?>
      <div class="alert bad">Este enlace es inválido o ya expiró.</div>
      <p class="mini" style="text-align:center"><a href="forgot_password.php">Solicitar un enlace nuevo</a></p>
    <?php else: ?>
      <?php if ($msg): ?><div class="alert bad"><?= e($msg) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>Nueva contraseña (mínimo 8 caracteres)</label>
        <input type="password" name="nueva" required minlength="8" autocomplete="new-password" autofocus>
        <label>Repetir nueva contraseña</label>
        <input type="password" name="repetir" required autocomplete="new-password">
        <br><br>
        <button class="btn full" type="submit">Guardar contraseña</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php page_bottom();
