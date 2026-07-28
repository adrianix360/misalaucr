<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/layout.php';

$u = require_role(['super']);
process_automatic();
$pdo = db();

/* ============ ACCIONES ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['a'] ?? '';
    $ok = false; $msg = 'Acción desconocida.';

    if ($a === 'org_crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $adminNombre = trim($_POST['admin_nombre'] ?? '');
        $adminEmail  = trim($_POST['admin_email'] ?? '');
        $adminPass   = trim($_POST['admin_pass'] ?? '');
        $nSalas      = max(1, min(20, (int)($_POST['salas'] ?? 3)));
        $capacidad   = max(1, min(50, (int)($_POST['capacidad'] ?? 6)));
        if ($nombre === '' || $adminNombre === '' || $adminEmail === '' || strlen($adminPass) < 8) {
            $msg = 'Complete todos los campos (contraseña mínimo 8 caracteres).';
        } else {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO organizations (name, active, created_at) VALUES (?,1,?)")->execute([$nombre, $now]);
            $oid = (int)$pdo->lastInsertId();
            for ($i = 1; $i <= $nSalas; $i++)
                $pdo->prepare("INSERT INTO rooms (org_id, name, capacity) VALUES (?,?,?)")->execute([$oid, "Sala $i", $capacidad]);
            $pdo->prepare("INSERT INTO users (org_id, role, name, email, password_hash, must_change, active, created_at)
                           VALUES (?,'admin',?,?,?,1,1,?)")
                ->execute([$oid, $adminNombre, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), $now]);
            $ok = true; $msg = "Organización creada con $nSalas sala(s) y su administrador inicial.";
        }

    } elseif ($a === 'org_toggle') {
        $pdo->prepare("UPDATE organizations SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
        $ok = true; $msg = 'Estado de la organización actualizado.';

    } elseif ($a === 'org_renombrar') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') $msg = 'El nombre no puede estar vacío.';
        else {
            $pdo->prepare("UPDATE organizations SET name=? WHERE id=?")->execute([$nombre, (int)$_POST['id']]);
            $ok = true; $msg = 'Nombre actualizado.';
        }

    } elseif ($a === 'admin_editar') {
        $id = (int)$_POST['id'];
        $nombre = trim($_POST['nombre'] ?? ''); $email = trim($_POST['email'] ?? ''); $pass = trim($_POST['password'] ?? '');
        if ($nombre === '' || $email === '') $msg = 'Nombre y correo son obligatorios.';
        elseif ($pass !== '' && strlen($pass) < 8) $msg = 'La contraseña debe tener al menos 8 caracteres.';
        else {
            $pdo->prepare("UPDATE users SET name=?, email=? WHERE id=? AND role='admin'")->execute([$nombre, $email, $id]);
            if ($pass !== '')
                $pdo->prepare("UPDATE users SET password_hash=?, must_change=1 WHERE id=? AND role='admin'")
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $ok = true; $msg = 'Administrador actualizado.' . ($pass !== '' ? ' Deberá cambiar la contraseña al entrar.' : '');
        }
    }

    flash_set($ok, $msg);
    header('Location: superadmin.php'); exit;
}

/* ============ VISTA ============ */
$orgs = $pdo->query("SELECT o.*,
        (SELECT COUNT(*) FROM rooms r WHERE r.org_id = o.id) AS n_salas,
        (SELECT COUNT(*) FROM users s WHERE s.org_id = o.id AND s.role='student') AS n_est
    FROM organizations o ORDER BY o.id")->fetchAll();
$admins = $pdo->query("SELECT * FROM users WHERE role='admin' ORDER BY org_id, id")->fetchAll();
$adminsPorOrg = [];
foreach ($admins as $a2) $adminsPorOrg[$a2['org_id']][] = $a2;
$editAdmin = (int)($_GET['admin'] ?? 0);

page_top('Organizaciones', $u, 'super');
?>
<h1>Organizaciones clientes</h1>
<p class="sub">Plataforma MiSalaUCR — administración global</p>
<?php show_flash(); ?>

<?php foreach ($orgs as $o): ?>
<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap">
    <div>
      <b style="font-size:1.05rem"><?= e($o['name']) ?></b>
      <span class="pill <?= $o['active'] ? 'activa' : 'cancelada' ?>"><?= $o['active'] ? 'activa' : 'inactiva' ?></span>
      <div class="mini"><?= (int)$o['n_salas'] ?> salas · <?= (int)$o['n_est'] ?> estudiantes ·
        horario <?= (int)$o['open_hour'] ?>:00–<?= (int)$o['close_hour'] ?>:00 ·
        máx <?= (int)$o['max_hours_week'] ?>h/semana</div>
    </div>
    <form class="inline" method="post" onsubmit="return confirm('¿Cambiar el estado de esta organización?')">
      <?= csrf_field() ?><input type="hidden" name="a" value="org_toggle"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
      <button class="btn <?= $o['active'] ? 'rojo' : 'verde' ?> chico"><?= $o['active'] ? 'Desactivar' : 'Activar' ?></button>
    </form>
  </div>
  <form method="post" style="display:flex; gap:8px; margin-top:10px; align-items:end; flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="a" value="org_renombrar"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
    <div style="flex:1; min-width:220px"><label>Nombre</label><input name="nombre" value="<?= e($o['name']) ?>"></div>
    <button class="btn gris chico">Renombrar</button>
  </form>

  <h2>Administradores</h2>
  <?php foreach ($adminsPorOrg[$o['id']] ?? [] as $ad): ?>
    <?php if ($editAdmin === (int)$ad['id']): ?>
    <form method="post" class="res" style="display:grid; grid-template-columns:1fr; gap:8px">
      <?= csrf_field() ?><input type="hidden" name="a" value="admin_editar"><input type="hidden" name="id" value="<?= (int)$ad['id'] ?>">
      <div><label>Nombre</label><input name="nombre" value="<?= e($ad['name']) ?>" required></div>
      <div><label>Correo (usuario de ingreso)</label><input type="email" name="email" value="<?= e($ad['email']) ?>" required></div>
      <div><label>Nueva contraseña (vacío = no cambiar)</label><input name="password" minlength="8" placeholder="mínimo 8 caracteres"></div>
      <div style="display:flex; gap:6px"><button class="btn chico">Guardar</button><a class="btn gris chico" href="superadmin.php">Cerrar</a></div>
    </form>
    <?php else: ?>
    <div class="res">
      <div>
        <div class="qué"><?= e($ad['name']) ?></div>
        <div class="cuando"><?= e($ad['email']) ?><?= $ad['must_change'] ? ' · debe cambiar contraseña en su primer ingreso' : '' ?></div>
      </div>
      <a class="btn gris chico" href="superadmin.php?admin=<?= (int)$ad['id'] ?>">Editar</a>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="card" style="max-width:560px">
  <h2 style="margin-top:0">Crear nueva organización</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="org_crear">
    <label>Nombre de la asociación</label><input name="nombre" required placeholder="Asociación de Estudiantes de ...">
    <div class="dos-col">
      <div><label>Número de salas</label><input type="number" name="salas" min="1" max="20" value="3"></div>
      <div><label>Capacidad por sala</label><input type="number" name="capacidad" min="1" max="50" value="6"></div>
    </div>
    <label>Nombre del administrador inicial</label><input name="admin_nombre" required>
    <label>Correo del administrador (será su usuario)</label><input type="email" name="admin_email" required>
    <label>Contraseña temporal (mínimo 8)</label><input name="admin_pass" minlength="8" required>
    <br><button class="btn">Crear organización</button>
  </form>
  <p class="mini">La organización se crea con las reglas estándar (L–V 7:00–22:00, 2h por sesión, 4h por semana). Su administrador puede ajustarlas en su panel → Configuración.</p>
</div>
<?php page_bottom();
