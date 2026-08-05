<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
boot();

if (current_user()) { header('Location: ' . home_for(current_user())); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = attempt_login($_POST['ident'] ?? '', $_POST['password'] ?? '');
    if ($u && !empty($u['_suspendida'])) {
        $r = trim((string)($u['frozen_reason'] ?? ''));
        header('Location: suspendida.php' . ($r !== '' ? '?r=' . urlencode($r) : '')); exit;
    }
    if ($u) { header('Location: ' . ($u['must_change'] ? 'password.php' : home_for($u))); exit; }
    $error = 'Carné/correo o contraseña incorrectos.';
}

page_top('Iniciar sesión');
$tema = tema_global();
?>
<div class="login-box">
  <div class="brand-big">
    <span class="msu-logo msu-logo--xl msu-logo--vertical" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </span>
  </div>
  <p class="sub" style="text-align:center"><?= e(tema_txt($tema, 'login_sub', 'Reserva tu sala de estudio en segundos')) ?></p>
  <div class="card">
    <?php if ($error): ?><div class="alert bad"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label for="ident">Número de carné (o correo)</label>
      <input id="ident" name="ident" required autofocus autocomplete="username" placeholder="Ej: C12345">
      <label for="password">Contraseña</label>
      <input id="password" type="password" name="password" required autocomplete="current-password">
      <br><br>
      <button class="btn full" type="submit"><?= e(tema_txt($tema, 'login_btn', 'Entrar')) ?></button>
    </form>
    <p class="mini" style="text-align:center"><a href="forgot_password.php">¿Olvidaste tu contraseña?</a></p>
  </div>
  <p class="mini" style="text-align:center">¿No tienes cuenta? Solicítala en la oficina de tu asociación.</p>
</div>
<?php page_bottom();
