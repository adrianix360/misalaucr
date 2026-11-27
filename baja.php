<?php
/**
 * MiSalaUCR — Baja de las notificaciones por correo (sin iniciar sesión).
 * baja.php?t=TOKEN. Un GET nunca cambia nada (los antivirus/clientes de correo
 * abren los enlaces por adelantado): hay que pulsar el botón (POST + CSRF).
 * El POST "un clic" de los clientes de correo (List-Unsubscribe=One-Click) se
 * acepta sin CSRF: el token secreto del enlace es la credencial.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
boot();

$token = (string)($_GET['t'] ?? '');
$user = null;
if (preg_match('/^[a-f0-9]{16,64}$/', $token)) {
    $st = db()->prepare("SELECT id, name, email_opt_out FROM users WHERE unsub_token = ?");
    $st->execute([$token]);
    $user = $st->fetch() ?: null;
}

$estado = null; // null | 'baja' | 'alta'
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        db()->prepare("UPDATE users SET email_opt_out = 1 WHERE id = ?")->execute([(int)$user['id']]);
        header('Content-Type: text/plain; charset=utf-8');
        exit('OK');
    }
    csrf_check();
    $baja = ($_POST['a'] ?? '') === 'alta' ? 0 : 1;
    db()->prepare("UPDATE users SET email_opt_out = ? WHERE id = ?")->execute([$baja, (int)$user['id']]);
    $user['email_opt_out'] = $baja;
    $estado = $baja ? 'baja' : 'alta';
}

page_top('Notificaciones por correo');
?>
<div class="login-box">
  <div class="brand-big">
    <span class="msu-logo msu-logo--xl msu-logo--vertical" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </span>
  </div>
  <div class="card">
  <?php if (!$user): ?>
    <div class="alert bad">Este enlace no es válido o ya no está disponible. Puedes gestionar tus notificaciones desde tu perfil en MiSalaUCR.</div>
    <p class="mini" style="text-align:center"><a href="login.php">Ir a iniciar sesión</a></p>
  <?php else: ?>
    <?php if ($estado === 'baja'): ?>
      <div class="alert good">Te diste de baja. Ya no recibirás notificaciones por correo de MiSalaUCR (los correos para restablecer tu contraseña se seguirán enviando cuando los solicites).</div>
    <?php elseif ($estado === 'alta'): ?>
      <div class="alert good">Listo, volverás a recibir las notificaciones por correo.</div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <?php if ((int)$user['email_opt_out'] === 1): ?>
        <p class="sub">Actualmente no recibes notificaciones por correo.</p>
        <input type="hidden" name="a" value="alta">
        <button class="btn full" type="submit">Volver a suscribirme</button>
      <?php else: ?>
        <p class="sub">Hola <?= e($user['name']) ?>. ¿Quieres dejar de recibir las notificaciones por correo de MiSalaUCR (confirmaciones y recordatorios de reservas, avisos de tu asociación)?</p>
        <input type="hidden" name="a" value="baja">
        <button class="btn full" type="submit">Darme de baja de las notificaciones</button>
      <?php endif; ?>
    </form>
    <p class="mini" style="text-align:center"><a href="login.php">Ir a MiSalaUCR</a></p>
  <?php endif; ?>
  </div>
</div>
<?php page_bottom();
