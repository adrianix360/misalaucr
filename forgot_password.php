<?php
/** MiSalaUCR — Solicitar enlace de recuperación de contraseña. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/throttle.php';
boot();

if (current_user()) { header('Location: ' . home_for(current_user())); exit; }

const RESET_TTL_MIN = 30;

$sent = false;
$throttled = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ident = trim($_POST['ident'] ?? '');
    $ip = client_ip();
    [$allowed] = throttle_check('reset', $ident, $ip);

    if (!$allowed) {
        $throttled = true;
    } else {
        throttle_record('reset', $ident, $ip);

        if ($ident !== '') {
            $st = db()->prepare(
                "SELECT * FROM users WHERE active = 1 AND (carne = ? OR email = ?) ORDER BY id LIMIT 1");
            $st->execute([$ident, $ident]);
            $u = $st->fetch();

            if ($u && !empty($u['email'])) {
                db()->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")
                    ->execute([$u['id']]);

                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + RESET_TTL_MIN * 60);
                db()->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, used, created_at)
                                VALUES (?,?,?,0,?)")
                    ->execute([$u['id'], hash('sha256', $token), $expires, date('Y-m-d H:i:s')]);

                $link = rtrim(cfg()['base_url'], '/') . '/reset_password.php?token=' . $token;
                queue_email($u['org_id'], $u['email'], 'Recupera tu contraseña — MiSalaUCR',
                    email_reset_password($u['name'], $link));
            }
        }

        // Mensaje genérico siempre, exista o no el usuario/correo: evita revelar
        // si un carné o correo está registrado en el sistema.
        $sent = true;
    }
}

page_top('Recuperar contraseña');
?>
<div class="login-box">
  <div class="brand-big">
    <span class="msu-logo msu-logo--xl msu-logo--vertical" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </span>
  </div>
  <div class="card">
    <?php if ($sent): ?>
      <div class="alert good">Si el carné o correo existe en el sistema, se envió un enlace para restablecer la contraseña. Revisa tu bandeja de entrada (y spam).</div>
      <p class="mini" style="text-align:center"><a href="login.php">Volver a iniciar sesión</a></p>
    <?php elseif ($throttled): ?>
      <div class="alert bad">Has hecho muchas solicitudes. Espera un momento e intenta de nuevo.</div>
      <p class="mini" style="text-align:center"><a href="login.php">Volver a iniciar sesión</a></p>
    <?php else: ?>
      <p class="sub">Escribe tu número de carné o correo. Si tiene una cuenta con correo registrado, te enviaremos un enlace para restablecer la contraseña.</p>
      <form method="post">
        <?= csrf_field() ?>
        <label for="ident">Número de carné (o correo)</label>
        <input id="ident" name="ident" required autofocus autocomplete="username" placeholder="Ej: C12345">
        <br><br>
        <button class="btn full" type="submit">Enviar enlace</button>
      </form>
      <p class="mini" style="text-align:center"><a href="login.php">Volver a iniciar sesión</a></p>
    <?php endif; ?>
  </div>
</div>
<?php page_bottom();
