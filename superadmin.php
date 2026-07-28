<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/layout.php';

$u = require_role(['super']);
process_automatic();
$pdo = db();
$tab = $_GET['tab'] ?? 'orgs';

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
            log_activity($oid, 'organizacion', "Se creó la organización $nombre con $nSalas sala(s)");
            $ok = true; $msg = "Organización creada con $nSalas sala(s) y su administrador inicial.";
        }

    } elseif ($a === 'org_toggle') {
        $id = (int)$_POST['id'];
        $motivo = trim($_POST['motivo'] ?? '');
        $st = $pdo->prepare("SELECT * FROM organizations WHERE id=?"); $st->execute([$id]);
        if ($o = $st->fetch()) {
            if ((int)$o['active']) { // congelar
                $pdo->prepare("UPDATE organizations SET active=0, frozen_reason=? WHERE id=?")
                    ->execute([$motivo ?: null, $id]);
                log_activity($id, 'organizacion', "Organización congelada" . ($motivo ? ": $motivo" : ''));
                $ok = true; $msg = 'Organización congelada: sus usuarios pierden el acceso de inmediato.';
            } else { // reactivar
                $pdo->prepare("UPDATE organizations SET active=1, frozen_reason=NULL WHERE id=?")->execute([$id]);
                log_activity($id, 'organizacion', 'Organización reactivada');
                $ok = true; $msg = 'Organización reactivada.';
            }
        }

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

    } elseif ($a === 'est_crear_su') {
        $oid = (int)$_POST['org_id'];
        $st = $pdo->prepare("SELECT COUNT(*) c FROM organizations WHERE id=?"); $st->execute([$oid]);
        if (!(int)$st->fetch()['c']) { $msg = 'Organización no válida.'; }
        else {
            [$ok, $msg] = crear_estudiante($pdo, $oid, $_POST['nombre'] ?? '', $_POST['carne'] ?? '',
                                           $_POST['email'] ?? '', $_POST['telefono'] ?? '', $_POST['password'] ?? '');
        }

    } elseif ($a === 'est_mover') {
        $id = (int)$_POST['id'];
        $dest = (int)$_POST['org_id'];
        $st = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='student'"); $st->execute([$id]);
        $s = $st->fetch();
        $st = $pdo->prepare("SELECT * FROM organizations WHERE id=?"); $st->execute([$dest]);
        $od = $st->fetch();
        if (!$s || !$od) { $msg = 'Estudiante u organización no válidos.'; }
        elseif ((int)$s['org_id'] === $dest) { $msg = 'El estudiante ya pertenece a esa organización.'; }
        elseif (carne_ocupado($pdo, $dest, (string)$s['carne'])) { $msg = "En la organización destino ya existe el carné {$s['carne']}."; }
        else {
            // Empieza limpio en la nueva asociación; su historial anterior se conserva
            // con el org_id original y deja de contar para los límites semanales.
            $pdo->prepare("UPDATE users SET org_id=?, noshow_count=0, blocked_until=NULL WHERE id=?")->execute([$dest, $id]);
            $pdo->prepare("UPDATE waitlist SET status='cancelada' WHERE user_id=? AND status='esperando'")->execute([$id]);
            log_activity($dest, 'movido', "{$s['name']} (carné {$s['carne']}) fue movido a {$od['name']}");
            $ok = true; $msg = "{$s['name']} ahora pertenece a {$od['name']} (contadores de inasistencia reiniciados).";
        }
    }

    flash_set($ok, $msg);
    header('Location: superadmin.php?tab=' . urlencode($tab) . (isset($_GET['q']) ? '&q=' . urlencode($_GET['q']) : ''));
    exit;
}

/* ============ VISTA ============ */
page_top('Plataforma', $u, 'super');
$tabs = ['orgs' => 'Organizaciones', 'dashboard' => 'Dashboard', 'estudiantes' => 'Estudiantes'];
?>
<h1>Plataforma MiSalaUCR</h1>
<p class="sub">Administración global</p>
<div class="tabs">
  <?php foreach ($tabs as $k => $lbl): ?>
    <a href="superadmin.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>
<?php show_flash(); ?>

<?php /* ================= ORGANIZACIONES ================= */
if ($tab === 'orgs'):
    $orgs = $pdo->query("SELECT o.*,
            (SELECT COUNT(*) FROM rooms r WHERE r.org_id = o.id) AS n_salas,
            (SELECT COUNT(*) FROM users s WHERE s.org_id = o.id AND s.role='student') AS n_est
        FROM organizations o ORDER BY o.id")->fetchAll();
    $admins = $pdo->query("SELECT * FROM users WHERE role='admin' ORDER BY org_id, id")->fetchAll();
    $adminsPorOrg = [];
    foreach ($admins as $a2) $adminsPorOrg[$a2['org_id']][] = $a2;
    $editAdmin = (int)($_GET['admin'] ?? 0);
?>
<?php foreach ($orgs as $o): ?>
<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap">
    <div>
      <b style="font-size:1.05rem"><?= e($o['name']) ?></b>
      <span class="pill <?= $o['active'] ? 'activa' : 'cancelada' ?>"><?= $o['active'] ? 'activa' : 'congelada' ?></span>
      <div class="mini"><?= (int)$o['n_salas'] ?> salas · <?= (int)$o['n_est'] ?> estudiantes ·
        horario <?= (int)$o['open_hour'] ?>:00–<?= (int)$o['close_hour'] ?>:00 ·
        máx <?= (int)$o['max_hours_week'] ?>h/semana</div>
      <?php if (!$o['active'] && $o['frozen_reason']): ?>
        <div class="alert bad" style="margin:8px 0 0"><b>Motivo del congelamiento:</b> <?= e($o['frozen_reason']) ?></div>
      <?php endif; ?>
    </div>
    <form class="inline" method="post" onsubmit="return confirm('¿<?= $o['active'] ? 'Congelar' : 'Reactivar' ?> esta organización?')"
          style="display:flex; gap:6px; align-items:end; flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="a" value="org_toggle"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
      <?php if ($o['active']): ?>
        <div><label>Motivo (ej. impago)</label><input name="motivo" placeholder="Pago de mensualidad pendiente..." style="width:230px"></div>
        <button class="btn rojo chico">Congelar</button>
      <?php else: ?>
        <button class="btn verde chico">Reactivar</button>
      <?php endif; ?>
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

<?php /* ================= DASHBOARD ================= */
elseif ($tab === 'dashboard'):
    $hoy = today();
    $orgs = $pdo->query("SELECT * FROM organizations ORDER BY id")->fetchAll();
    $totOrgs = count($orgs);
    $activas = count(array_filter($orgs, fn($o) => (int)$o['active']));
    $totEst = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='student'")->fetch()['c'];
    $st = $pdo->prepare("SELECT COUNT(*) c FROM reservations WHERE created_at LIKE ?");
    $st->execute([$hoy . '%']);
    $resHoy = (int)$st->fetch()['c'];

    // Ranking por organización (semana y mes en curso + tasa de no-show del mes)
    $mesIni = date('Y-m-01');
    $ranking = [];
    foreach ($orgs as $o) {
        [$wa, $wb] = week_bounds($o, $hoy);
        $st = $pdo->prepare("SELECT COALESCE(SUM(end_hour-start_hour),0) h FROM reservations
                             WHERE org_id=? AND rdate BETWEEN ? AND ? AND status IN ('activa','completada','no_show')");
        $st->execute([(int)$o['id'], $wa, $wb]);
        $hSem = (int)$st->fetch()['h'];
        $st = $pdo->prepare("SELECT COALESCE(SUM(end_hour-start_hour),0) h,
                                    COUNT(*) tot,
                                    SUM(CASE WHEN status='no_show' THEN 1 ELSE 0 END) ns
                             FROM reservations WHERE org_id=? AND rdate BETWEEN ? AND ?
                               AND status IN ('activa','completada','no_show')");
        $st->execute([(int)$o['id'], $mesIni, $hoy]);
        $m = $st->fetch();
        $ranking[] = [
            'name' => $o['name'], 'active' => (int)$o['active'],
            'sem' => $hSem, 'mes' => (int)$m['h'],
            'tasa' => (int)$m['tot'] > 0 ? round(100 * (int)$m['ns'] / (int)$m['tot']) : 0,
        ];
    }
    usort($ranking, fn($x, $y) => $y['sem'] <=> $x['sem'] ?: $y['mes'] <=> $x['mes']);

    $actividad = $pdo->query(
        "SELECT a.*, o.name AS org_name FROM activity_log a
         LEFT JOIN organizations o ON o.id = a.org_id
         ORDER BY a.id DESC LIMIT 20")->fetchAll();
    $iconos = ['reserva'=>'📅','cancelacion'=>'✖️','checkin'=>'✅','no_show'=>'🚫','estudiante'=>'👤',
               'fila'=>'⏳','asignacion'=>'🎟️','organizacion'=>'🏛️','movido'=>'🔁'];
?>
<div class="kpis">
  <div class="kpi"><b><?= $totOrgs ?></b><span>organizaciones (<?= $activas ?> activas · <?= $totOrgs - $activas ?> congeladas)</span></div>
  <div class="kpi"><b><?= $totEst ?></b><span>estudiantes en la plataforma</span></div>
  <div class="kpi"><b><?= $resHoy ?></b><span>reservas creadas hoy</span></div>
  <div class="kpi"><b><?= count($actividad) ?></b><span>eventos recientes</span></div>
</div>

<div class="card" style="margin-top:14px">
  <h2 style="margin-top:0">Ranking de uso por asociación</h2>
  <div class="tabla-scroll"><table class="tabla">
    <tr><th>Organización</th><th>Horas esta semana</th><th>Horas este mes</th><th>Tasa no-show (mes)</th><th>Estado</th></tr>
    <?php foreach ($ranking as $rk): ?>
    <tr>
      <td><?= e($rk['name']) ?></td>
      <td><b><?= $rk['sem'] ?>h</b></td>
      <td><?= $rk['mes'] ?>h</td>
      <td><?= $rk['tasa'] ?>%</td>
      <td><span class="pill <?= $rk['active'] ? 'activa' : 'cancelada' ?>"><?= $rk['active'] ? 'activa' : 'congelada' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<div class="card">
  <h2 style="margin-top:0">Actividad reciente</h2>
  <?php if (!$actividad): ?><p class="mini">Aún no hay actividad registrada.</p><?php endif; ?>
  <?php foreach ($actividad as $ev): ?>
  <div class="res">
    <div>
      <div class="qué"><?= $iconos[$ev['kind']] ?? '•' ?> <?= e($ev['description']) ?></div>
      <div class="cuando"><?= e($ev['org_name'] ?? 'Plataforma') ?> · <?= e(date('d/m H:i', strtotime($ev['created_at']))) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php /* ================= ESTUDIANTES ================= */
elseif ($tab === 'estudiantes'):
    $orgs = $pdo->query("SELECT id, name, active FROM organizations ORDER BY name")->fetchAll();
    $q = trim($_GET['q'] ?? '');
    $ests = [];
    if ($q !== '') {
        $st = $pdo->prepare("SELECT u.*, o.name AS org_name FROM users u
                             JOIN organizations o ON o.id = u.org_id
                             WHERE u.role='student' AND (u.name LIKE ? OR u.carne LIKE ?)
                             ORDER BY u.name LIMIT 100");
        $st->execute(["%$q%", "%$q%"]);
        $ests = $st->fetchAll();
    }
?>
<div class="dos-col">
  <div class="card">
    <h2 style="margin-top:0">Crear estudiante en cualquier organización</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="est_crear_su">
      <label>Organización</label>
      <select name="org_id" required>
        <?php foreach ($orgs as $o): ?>
          <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?><?= $o['active'] ? '' : ' (congelada)' ?></option>
        <?php endforeach; ?>
      </select>
      <label>Nombre completo</label><input name="nombre" required>
      <label>Número de carné</label><input name="carne" required placeholder="C12345">
      <label>Correo institucional</label><input type="email" name="email" placeholder="nombre@ucr.ac.cr">
      <label>Teléfono</label><input name="telefono" placeholder="8888-8888" maxlength="20">
      <label>Contraseña temporal (vacío = generar automática)</label><input name="password" minlength="8">
      <br><button class="btn">Crear cuenta</button>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Buscar y mover estudiantes</h2>
    <form method="get" style="display:flex; gap:8px; align-items:end">
      <input type="hidden" name="tab" value="estudiantes">
      <div style="flex:1"><label>Nombre o carné</label><input name="q" value="<?= e($q) ?>" placeholder="Ej: María o C12345"></div>
      <button class="btn gris">Buscar</button>
    </form>
    <p class="mini">Al mover un estudiante a otra asociación, sus contadores de inasistencia y bloqueos se reinician; su historial de reservas anterior se conserva pero no cuenta para los límites de la nueva asociación.</p>
  </div>
</div>

<?php if ($q !== ''): ?>
<div class="card tabla-scroll">
<table class="tabla">
  <tr><th>Nombre</th><th>Carné</th><th>Organización actual</th><th>Estado</th><th>Mover a</th></tr>
  <?php if (!$ests): ?><tr><td colspan="5" class="mini">Sin resultados para "<?= e($q) ?>".</td></tr><?php endif; ?>
  <?php foreach ($ests as $s): ?>
  <tr>
    <td><?= e($s['name']) ?></td>
    <td><?= e($s['carne']) ?></td>
    <td><?= e($s['org_name']) ?></td>
    <td><span class="pill <?= $s['active'] ? 'activa' : 'cancelada' ?>"><?= $s['active'] ? 'activa' : 'inactiva' ?></span></td>
    <td>
      <form class="inline" method="post" style="display:flex; gap:6px"
            onsubmit="return confirm('¿Mover a <?= e($s['name']) ?> a la organización seleccionada? Sus penalizaciones se reiniciarán.')">
        <?= csrf_field() ?><input type="hidden" name="a" value="est_mover"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <select name="org_id" required>
          <?php foreach ($orgs as $o): if ((int)$o['id'] === (int)$s['org_id']) continue; ?>
            <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn chico">Mover</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php page_bottom();
