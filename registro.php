<?php
/**
 * MiSalaUCR — Auto-registro de estudiantes con enlace de un solo uso.
 * Página pública (sin login): el token del enlace define la asociación.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/throttle.php';
boot();

if (current_user()) { header('Location: ' . home_for(current_user())); exit; }

$ip    = client_ip();
$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
[$permitido] = throttle_check('registro', $ip, $ip);

$inv = null; $org = null; $errorEnlace = null; $error = null;
$v = ['nombre' => '', 'carne' => '', 'email' => '', 'phone' => ''];

if (!$permitido) {
    $errorEnlace = 'Demasiados intentos. Espera unos minutos e intenta de nuevo.';
} else {
    $inv = invite_valido($token);
    if ($inv) {
        $st = db()->prepare("SELECT * FROM organizations WHERE id = ? AND active = 1");
        $st->execute([(int)$inv['org_id']]);
        $org = $st->fetch() ?: null;
    }
    if (!$inv || !$org) {
        throttle_record('registro', $ip, $ip);
        $inv = null;
        $errorEnlace = 'Este enlace de registro no es válido: ya se usó, venció o fue revocado. Pide uno nuevo a tu asociación.';
    }
}

if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    $v = [
        'nombre' => trim((string)($_POST['nombre'] ?? '')),
        'carne'  => trim((string)($_POST['carne'] ?? '')),
        'email'  => trim((string)($_POST['email'] ?? '')),
        'phone'  => trim((string)($_POST['phone'] ?? '')),
    ];
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($v['nombre'] === '' || $v['carne'] === '' || $v['email'] === '') {
        $error = 'Nombre, carné y correo son obligatorios.';
    } elseif (mb_strlen($v['nombre']) > 150 || mb_strlen($v['carne']) > 50 || mb_strlen($v['email']) > 150) {
        $error = 'Alguno de los datos es demasiado largo.';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no es válido.';
    } elseif (strlen($pass) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        // El login acepta carné o correo, así que ninguno de los dos puede repetirse en la plataforma.
        $st = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE carne = ? OR email = ?");
        $st->execute([$v['carne'], $v['email']]);
        if ((int)$st->fetch()['c'] > 0) $error = 'Ese carné o correo ya está registrado. Si es tuyo, inicia sesión o contacta a tu asociación.';
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();
            // Se reclama el enlace primero y de forma atómica: si dos personas llegan a la vez, solo una pasa.
            $now = date('Y-m-d H:i:s');
            $claim = $pdo->prepare("UPDATE invite_tokens SET used_at = ?
                                    WHERE id = ? AND used_at IS NULL AND revoked = 0 AND expires_at > ?");
            $claim->execute([$now, (int)$inv['id'], $now]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                $inv = null;
                $errorEnlace = 'Este enlace acaba de usarse o dejó de ser válido. Pide uno nuevo a tu asociación.';
            } else {
                [$ok, $msg] = crear_estudiante($pdo, (int)$org['id'], $v['nombre'], $v['carne'], $v['email'], $v['phone'], $pass);
                if (!$ok) {
                    $pdo->rollBack();   // el enlace queda sin usar
                    $error = $msg;
                } else {
                    $st = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND carne = ? ORDER BY id DESC LIMIT 1");
                    $st->execute([(int)$org['id'], $v['carne']]);
                    $nuevoId = (int)($st->fetch()['id'] ?? 0);
                    // La contraseña la eligió la propia persona: no se le obliga a cambiarla.
                    $pdo->prepare("UPDATE users SET must_change = 0 WHERE id = ?")->execute([$nuevoId]);
                    $pdo->prepare("UPDATE invite_tokens SET used_by_user_id = ? WHERE id = ?")->execute([$nuevoId, (int)$inv['id']]);
                    $pdo->commit();
                    flash_set(true, '¡Cuenta creada! Ya puedes iniciar sesión con tu carné y tu contraseña.');
                    header('Location: login.php'); exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'No se pudo crear la cuenta. Intenta de nuevo.';
        }
    }
    if ($error) throttle_record('registro', $ip, $ip);
}

page_top('Crear cuenta');
?>
<div class="login-box">
  <div class="brand-big">
    <span class="msu-logo msu-logo--xl msu-logo--vertical" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </span>
  </div>
  <?php if (!$inv): ?>
  <div class="card">
    <div class="alert bad"><?= e($errorEnlace ?? 'Enlace no válido.') ?></div>
    <p class="mini" style="text-align:center"><a href="login.php">Ir a iniciar sesión</a></p>
  </div>
  <?php else: ?>
  <p class="sub" style="text-align:center">Crea tu cuenta en <b><?= e($org['name']) ?></b></p>
  <div class="card">
    <?php if ($error): ?><div class="alert bad"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <label for="nombre">Nombre completo</label>
      <input id="nombre" name="nombre" value="<?= e($v['nombre']) ?>" required maxlength="150" autofocus>
      <label for="carne">Número de carné</label>
      <input id="carne" name="carne" value="<?= e($v['carne']) ?>" required maxlength="50" placeholder="Ej: C12345">
      <label for="email">Correo</label>
      <input id="email" type="email" name="email" value="<?= e($v['email']) ?>" required maxlength="150" placeholder="nombre@ucr.ac.cr">
      <label for="phone">Teléfono (opcional)</label>
      <input id="phone" name="phone" value="<?= e($v['phone']) ?>" maxlength="20" placeholder="8888-8888">
      <label for="password">Contraseña (mínimo 8 caracteres)</label>
      <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
      <label for="password2">Repite la contraseña</label>
      <input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password">
      <br><br>
      <button class="btn full" type="submit">Crear mi cuenta</button>
    </form>
  </div>
  <p class="mini" style="text-align:center">Este enlace sirve una sola vez.</p>
  <?php endif; ?>
</div>
<?php page_bottom();
