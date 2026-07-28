<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/layout.php';

$u = require_role(['admin']);
$org = org_of($u);
if (!$org) { logout(); header('Location: login.php'); exit; }
process_automatic();
$pdo = db();
$orgId = (int)$org['id'];
$tab = $_GET['tab'] ?? 'reservas';

/* ============ ACCIONES ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['a'] ?? '';
    $ok = false; $msg = 'Acción desconocida.';

    if ($a === 'cancelar_reserva') {
        [$ok, $msg] = cancel_reservation($u, (int)$_POST['res_id'], true);

    } elseif ($a === 'sala_estado') {
        $rid = (int)$_POST['room_id'];
        $nuevo = $_POST['estado'] === 'bloqueada' ? 'bloqueada' : 'disponible';
        $nota  = trim($_POST['nota'] ?? '');
        $st = $pdo->prepare("UPDATE rooms SET status = ?, note = ? WHERE id = ? AND org_id = ?");
        $st->execute([$nuevo, $nota ?: null, $rid, $orgId]);
        $ok = true; $msg = $nuevo === 'bloqueada' ? 'Sala bloqueada: no aparecerá disponible.' : 'Sala habilitada de nuevo.';

    } elseif ($a === 'sala_renombrar') {
        $rid = (int)$_POST['room_id'];
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') { $msg = 'El nombre no puede estar vacío.'; }
        else {
            $st = $pdo->prepare("UPDATE rooms SET name = ? WHERE id = ? AND org_id = ?");
            $st->execute([mb_substr($nombre, 0, 100), $rid, $orgId]);
            $ok = $st->rowCount() > 0;
            $msg = $ok ? 'Sala renombrada.' : 'Sala no encontrada.';
        }

    } elseif ($a === 'est_crear') {
        [$ok, $msg] = crear_estudiante($pdo, $orgId, $_POST['nombre'] ?? '', $_POST['carne'] ?? '',
                                       $_POST['email'] ?? '', $_POST['telefono'] ?? '', $_POST['password'] ?? '');

    } elseif ($a === 'est_editar') {
        $id = (int)$_POST['id'];
        $nombre = trim($_POST['nombre'] ?? ''); $carne = trim($_POST['carne'] ?? '');
        $email = trim($_POST['email'] ?? ''); $tel = trim($_POST['telefono'] ?? '');
        if ($nombre === '' || $carne === '') { $msg = 'Nombre y carné son obligatorios.'; }
        elseif (carne_ocupado($pdo, $orgId, $carne, $id)) { $msg = "El carné $carne ya está en uso."; }
        elseif ($tel !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $tel)) { $msg = 'Teléfono inválido.'; }
        else {
            $pdo->prepare("UPDATE users SET name=?, carne=?, email=?, phone=? WHERE id=? AND org_id=? AND role='student'")
                ->execute([$nombre, $carne, $email ?: null, $tel ?: null, $id, $orgId]);
            $ok = true; $msg = 'Estudiante actualizado.';
        }

    } elseif ($a === 'est_toggle') {
        $pdo->prepare("UPDATE users SET active = 1 - active WHERE id=? AND org_id=? AND role='student'")
            ->execute([(int)$_POST['id'], $orgId]);
        $ok = true; $msg = 'Estado de la cuenta actualizado.';

    } elseif ($a === 'est_reset') {
        $nueva = trim($_POST['password'] ?? '');
        if (strlen($nueva) < 8) { $msg = 'La contraseña debe tener al menos 8 caracteres.'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=?, must_change=1 WHERE id=? AND org_id=? AND role='student'")
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), (int)$_POST['id'], $orgId]);
            $ok = true; $msg = 'Contraseña restablecida (deberá cambiarla al entrar).';
        }

    } elseif ($a === 'est_desbloquear') {
        $pdo->prepare("UPDATE users SET blocked_until = NULL, noshow_count = 0 WHERE id=? AND org_id=? AND role='student'")
            ->execute([(int)$_POST['id'], $orgId]);
        $ok = true; $msg = 'Bloqueo levantado y contador de inasistencias reiniciado.';

    } elseif ($a === 'correo_prueba') {
        $dest = trim($_POST['destino'] ?? '');
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) { $msg = 'Escriba un correo de destino válido.'; }
        else {
            queue_email($orgId, $dest, 'MiSalaUCR — Correo de prueba',
                "Hola:\n\nEste es un correo de prueba enviado desde el panel de administración de MiSalaUCR.\n\nSi lo estás leyendo, el envío de correos funciona correctamente.");
            $st = $pdo->prepare("SELECT status, error FROM email_log WHERE org_id=? ORDER BY id DESC LIMIT 1");
            $st->execute([$orgId]); $r = $st->fetch();
            $ok = ($r['status'] === 'enviado');
            $msg = $ok ? "Correo de prueba enviado a $dest. Revise la bandeja (y spam)."
                       : ($r['status'] === 'pendiente'
                          ? 'Quedó pendiente: falta el API key de Resend en config.php.'
                          : 'Error al enviar: ' . ($r['error'] ?: 'desconocido'));
        }

    } elseif ($a === 'correo_reintentar') {
        $st = $pdo->prepare("SELECT * FROM email_log WHERE org_id=? AND status <> 'enviado' ORDER BY id DESC LIMIT 20");
        $st->execute([$orgId]);
        $envs = 0; $errs = 0;
        foreach ($st->fetchAll() as $m) {
            [$okS, $err] = resend_send($m['to_email'], $m['subject'], $m['body']);
            $pdo->prepare("UPDATE email_log SET status=?, error=? WHERE id=?")
                ->execute([$okS ? 'enviado' : 'error', $err, $m['id']]);
            $okS ? $envs++ : $errs++;
        }
        $ok = $envs > 0 || ($envs + $errs === 0);
        $msg = ($envs + $errs === 0) ? 'No había correos pendientes.'
             : "Reintento: $envs enviados, $errs con error (últimos 20).";

    } elseif ($a === 'csv') {
        [$ok, $msg, $creados] = importar_csv($pdo, $orgId);
        if ($creados) $_SESSION['csv_creados'] = $creados;

    } elseif ($a === 'config') {
        $campos = ['open_hour','close_hour','max_blocks_session','max_hours_week','week_start','checkin_minutes','noshow_limit','noshow_block_days'];
        $vals = [];
        foreach ($campos as $c) $vals[] = (int)$_POST[$c];
        $dias = implode(',', array_map('intval', $_POST['dias'] ?? []));
        if ((int)$_POST['open_hour'] >= (int)$_POST['close_hour']) { $msg = 'La hora de apertura debe ser menor que la de cierre.'; }
        elseif ($dias === '') { $msg = 'Seleccione al menos un día de operación.'; }
        else {
            $vals[] = $dias; $vals[] = $orgId;
            $pdo->prepare("UPDATE organizations SET open_hour=?, close_hour=?, max_blocks_session=?, max_hours_week=?,
                           week_start=?, checkin_minutes=?, noshow_limit=?, noshow_block_days=?, days_open=? WHERE id=?")
                ->execute($vals);
            $ok = true; $msg = 'Configuración guardada.';
        }
    }

    flash_set($ok, $msg);
    header('Location: admin.php?tab=' . urlencode($tab) . (isset($_POST['fecha']) ? '&fecha=' . urlencode($_POST['fecha']) : ''));
    exit;
}

/* ============ helpers ============ */
/* (carne_ocupado y crear_estudiante viven en lib/rules.php, compartidas con el super-admin) */

function importar_csv(PDO $pdo, int $orgId): array {
    if (empty($_FILES['archivo']['tmp_name'])) return [false, 'No se recibió el archivo.', []];
    $fh = fopen($_FILES['archivo']['tmp_name'], 'r');
    if (!$fh) return [false, 'No se pudo leer el archivo.', []];
    $creados = []; $errores = []; $fila = 0;
    while (($cols = fgetcsv($fh, 2000, ',')) !== false) {
        $fila++;
        $cols = array_map(fn($c) => trim((string)$c), $cols);
        if (count($cols) === 1 && $cols[0] === '') continue;
        // saltar encabezado
        if ($fila === 1 && stripos(implode(',', $cols), 'carn') !== false) continue;
        // orden: nombre, carné, correo, teléfono, contraseña (los tres últimos opcionales)
        [$nombre, $carne, $email, $tel, $pass] = [$cols[0] ?? '', $cols[1] ?? '', $cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''];
        $passFinal = $pass !== '' ? $pass : substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
        [$ok, $msg] = crear_estudiante($pdo, $orgId, $nombre, $carne, $email, $tel, $passFinal);
        if ($ok) $creados[] = ['nombre' => $nombre, 'carne' => $carne, 'email' => $email, 'tel' => $tel, 'pass' => $passFinal];
        else $errores[] = "Fila $fila: $msg";
    }
    fclose($fh);
    $msg = count($creados) . ' estudiante(s) creados.';
    if ($errores) $msg .= ' Errores: ' . implode(' | ', array_slice($errores, 0, 5));
    return [count($creados) > 0 || !$errores, $msg, $creados];
}

/* ============ VISTA ============ */
page_top('Panel de administración', $u, 'admin');
$tabs = ['reservas' => 'Reservas', 'estudiantes' => 'Estudiantes', 'salas' => 'Salas', 'reportes' => 'Reportes', 'config' => 'Configuración', 'correos' => 'Correos'];
?>
<h1>Panel de administración</h1>
<p class="sub"><?= e($org['name']) ?></p>
<div class="tabs">
  <?php foreach ($tabs as $k => $lbl): ?>
    <a href="admin.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>
<?php show_flash(); ?>

<?php /* ================= RESERVAS ================= */
if ($tab === 'reservas'):
    $fecha = $_GET['fecha'] ?? today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = today();
    $rooms = $pdo->prepare("SELECT * FROM rooms WHERE org_id=? ORDER BY name"); $rooms->execute([$orgId]); $rooms = $rooms->fetchAll();
    $st = $pdo->prepare("SELECT r.*, rm.name room_name, us.name uname, us.carne
                         FROM reservations r JOIN rooms rm ON rm.id=r.room_id JOIN users us ON us.id=r.user_id
                         WHERE r.org_id=? AND r.rdate=? ORDER BY r.start_hour, rm.name");
    $st->execute([$orgId, $fecha]); $resDia = $st->fetchAll();
    $porBloque = [];
    foreach ($resDia as $r) if ($r['status'] === 'activa')
        foreach (range($r['start_hour'], $r['end_hour'] - 1) as $h) $porBloque[$r['room_id']][$h] = $r;
?>
<form method="get" class="card" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap">
  <input type="hidden" name="tab" value="reservas">
  <div><label>Ver día</label><input type="date" name="fecha" value="<?= e($fecha) ?>"></div>
  <button class="btn">Ver</button>
  <span class="mini"><?= count(array_filter($resDia, fn($r) => $r['status'] === 'activa')) ?> reservas activas este día</span>
</form>

<div class="card tabla-scroll">
  <div class="grid-salas">
    <div class="hd"></div>
    <?php foreach ($rooms as $r): ?><div class="hd"><?= e($r['name']) ?><?= $r['status'] !== 'disponible' ? ' 🔒' : '' ?></div><?php endforeach; ?>
    <?php foreach (block_hours($org) as $h): ?>
      <div class="hora"><?= $h ?>:00</div>
      <?php foreach ($rooms as $r):
        $b = $porBloque[$r['id']][$h] ?? null;
        if ($r['status'] !== 'disponible') echo '<span class="blk cerrado">Mant.</span>';
        elseif ($b) echo '<span class="blk mio" title="' . e($b['uname']) . '">' . e(mb_strimwidth($b['uname'], 0, 14, '…')) . '</span>';
        else echo '<span class="blk libre" style="opacity:.55">Libre</span>';
      endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<h2>Detalle de reservas del día</h2>
<div class="card tabla-scroll">
<table class="tabla">
  <tr><th>Hora</th><th>Sala</th><th>Estudiante</th><th>Carné</th><th>Estado</th><th>Check-in</th><th></th></tr>
  <?php if (!$resDia): ?><tr><td colspan="7" class="mini">Sin reservas este día.</td></tr><?php endif; ?>
  <?php foreach ($resDia as $r): ?>
  <tr>
    <td><?= (int)$r['start_hour'] ?>:00–<?= (int)$r['end_hour'] ?>:00</td>
    <td><?= e($r['room_name']) ?></td>
    <td><?= e($r['uname']) ?></td>
    <td><?= e($r['carne']) ?></td>
    <td><span class="pill <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
    <td><?= $r['checked_in_at'] ? '✅ ' . e(date('H:i', strtotime($r['checked_in_at']))) : '—' ?></td>
    <td><?php if ($r['status'] === 'activa'): ?>
      <form class="inline" method="post" onsubmit="return confirm('¿Cancelar la reserva de <?= e($r['uname']) ?>?')">
        <?= csrf_field() ?><input type="hidden" name="a" value="cancelar_reserva"><input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <button class="btn rojo chico">Cancelar</button></form>
    <?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</div>

<?php /* ================= ESTUDIANTES ================= */
elseif ($tab === 'estudiantes'):
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT * FROM users WHERE org_id=? AND role='student'";
    $par = [$orgId];
    if ($q !== '') { $sql .= " AND (name LIKE ? OR carne LIKE ? OR email LIKE ?)"; array_push($par, "%$q%", "%$q%", "%$q%"); }
    $sql .= " ORDER BY name";
    $st = $pdo->prepare($sql); $st->execute($par); $ests = $st->fetchAll();
    $editar = (int)($_GET['editar'] ?? 0);
    $creados = $_SESSION['csv_creados'] ?? null; unset($_SESSION['csv_creados']);
?>
<?php if ($creados): ?>
<div class="card">
  <h2 style="margin-top:0">Cuentas creadas — entregue estas credenciales a cada estudiante</h2>
  <div class="tabla-scroll"><table class="tabla">
    <tr><th>Nombre</th><th>Carné</th><th>Correo</th><th>Teléfono</th><th>Contraseña temporal</th></tr>
    <?php foreach ($creados as $c): ?>
    <tr><td><?= e($c['nombre']) ?></td><td><?= e($c['carne']) ?></td><td><?= e($c['email']) ?></td><td><?= e($c['tel'] ?? '') ?></td><td><b><?= e($c['pass']) ?></b></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="mini">⚠️ Estas contraseñas no se volverán a mostrar. Cada estudiante deberá cambiarla en su primer ingreso.</p>
</div>
<?php endif; ?>

<div class="dos-col">
  <div class="card">
    <h2 style="margin-top:0">Registrar estudiante</h2>
    <p class="mini">Registro presencial: solicite nombre completo, carné y correo institucional.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="est_crear">
      <label>Nombre completo</label><input name="nombre" required>
      <label>Número de carné</label><input name="carne" required placeholder="C12345">
      <label>Correo institucional</label><input type="email" name="email" placeholder="nombre@ucr.ac.cr">
      <label>Teléfono</label><input name="telefono" placeholder="8888-8888" maxlength="20">
      <label>Contraseña temporal (vacío = generar automática)</label><input name="password" minlength="8" placeholder="mínimo 8 caracteres">
      <br><button class="btn">Crear cuenta</button>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Carga masiva (CSV)</h2>
    <p class="mini">Un estudiante por línea: <code>nombre,carné,correo,teléfono,contraseña</code>. El correo, el teléfono y la contraseña son opcionales (si falta la contraseña, se genera una automática). Ejemplo:<br>
    <code>Ana Mora Pérez,C23451,ana.mora@ucr.ac.cr,8888-8888</code></p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="a" value="csv">
      <label>Archivo .csv</label><input type="file" name="archivo" accept=".csv,text/csv" required>
      <br><button class="btn">Importar</button>
    </form>
  </div>
</div>

<div class="card">
  <form method="get" style="display:flex; gap:8px; align-items:end; margin-bottom:10px">
    <input type="hidden" name="tab" value="estudiantes">
    <div style="flex:1"><label>Buscar</label><input name="q" value="<?= e($q) ?>" placeholder="nombre, carné o correo"></div>
    <button class="btn gris">Buscar</button>
  </form>
  <div class="tabla-scroll">
  <table class="tabla">
    <tr><th>Nombre</th><th>Carné</th><th>Correo</th><th>Teléfono</th><th>No-shows</th><th>Estado</th><th>Acciones</th></tr>
    <?php if (!$ests): ?><tr><td colspan="7" class="mini">Aún no hay estudiantes registrados.</td></tr><?php endif; ?>
    <?php foreach ($ests as $s):
        $bloq = $s['blocked_until'] && $s['blocked_until'] > date('Y-m-d H:i:s'); ?>
    <tr>
      <?php if ($editar === (int)$s['id']): ?>
      <td colspan="7">
        <form method="post" style="display:grid; grid-template-columns: 2fr 1fr 2fr 1fr auto; gap:8px; align-items:end">
          <?= csrf_field() ?><input type="hidden" name="a" value="est_editar"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <div><label>Nombre</label><input name="nombre" value="<?= e($s['name']) ?>" required></div>
          <div><label>Carné</label><input name="carne" value="<?= e($s['carne']) ?>" required></div>
          <div><label>Correo</label><input name="email" value="<?= e($s['email']) ?>"></div>
          <div><label>Teléfono</label><input name="telefono" value="<?= e($s['phone'] ?? '') ?>" maxlength="20"></div>
          <div style="display:flex; gap:6px"><button class="btn chico">Guardar</button><a class="btn gris chico" href="admin.php?tab=estudiantes">Cerrar</a></div>
        </form>
        <form method="post" style="display:flex; gap:8px; align-items:end; margin-top:8px">
          <?= csrf_field() ?><input type="hidden" name="a" value="est_reset"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <div style="flex:1"><label>Nueva contraseña temporal</label><input name="password" minlength="8" required></div>
          <button class="btn gris chico">Restablecer contraseña</button>
        </form>
      </td>
      <?php else: ?>
      <td><?= e($s['name']) ?></td>
      <td><?= e($s['carne']) ?></td>
      <td><?= e($s['email']) ?></td>
      <td><?= e($s['phone'] ?? '') ?></td>
      <td><?= (int)$s['noshow_count'] ?><?= $bloq ? ' · <span class="pill no_show">bloqueado hasta ' . e(date('d/m H:i', strtotime($s['blocked_until']))) . '</span>' : '' ?></td>
      <td><span class="pill <?= $s['active'] ? 'activa' : 'cancelada' ?>"><?= $s['active'] ? 'activa' : 'inactiva' ?></span></td>
      <td style="white-space:nowrap">
        <a class="btn gris chico" href="admin.php?tab=estudiantes&editar=<?= (int)$s['id'] ?>">Editar</a>
        <?php if ($bloq): ?>
        <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="a" value="est_desbloquear"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn verde chico">Desbloquear</button></form>
        <?php endif; ?>
        <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="a" value="est_toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn <?= $s['active'] ? 'rojo' : 'verde' ?> chico"><?= $s['active'] ? 'Desactivar' : 'Activar' ?></button></form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<?php /* ================= SALAS ================= */
elseif ($tab === 'salas'):
    $rooms = $pdo->prepare("SELECT * FROM rooms WHERE org_id=? ORDER BY name"); $rooms->execute([$orgId]); $rooms = $rooms->fetchAll();
?>
<div class="card tabla-scroll">
<table class="tabla">
  <tr><th>Sala</th><th>Capacidad</th><th>Estado</th><th>Nota</th><th></th></tr>
  <?php foreach ($rooms as $r): ?>
  <tr>
    <td>
      <form class="inline" method="post" style="display:flex; gap:6px; align-items:center">
        <?= csrf_field() ?><input type="hidden" name="a" value="sala_renombrar"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
        <input name="nombre" value="<?= e($r['name']) ?>" required style="width:150px; font-weight:700">
        <button class="btn gris chico" title="Guardar nombre">✎</button>
      </form>
    </td>
    <td><?= (int)$r['capacity'] ?> personas</td>
    <td><span class="pill <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
    <td class="mini"><?= e($r['note']) ?></td>
    <td>
      <form class="inline" method="post" style="display:flex; gap:6px; flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="a" value="sala_estado"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
        <?php if ($r['status'] === 'disponible'): ?>
          <input type="hidden" name="estado" value="bloqueada">
          <input name="nota" placeholder="Motivo (ej. mantenimiento)" style="width:220px">
          <button class="btn rojo chico">Bloquear sala</button>
        <?php else: ?>
          <input type="hidden" name="estado" value="disponible">
          <button class="btn verde chico">Habilitar sala</button>
        <?php endif; ?>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<p class="mini">Bloquear una sala impide nuevas reservas mientras esté bloqueada. Las reservas ya existentes no se cancelan automáticamente: cancélelas desde la pestaña Reservas si es necesario.</p>

<?php /* ================= REPORTES ================= */
elseif ($tab === 'reportes'):
    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-29 days'));
    $hasta = $_GET['hasta'] ?? today();
    foreach (['desde','hasta'] as $v) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) $$v = today();

    $st = $pdo->prepare("SELECT r.*, rm.name room_name, us.name uname, us.carne
                         FROM reservations r JOIN rooms rm ON rm.id=r.room_id JOIN users us ON us.id=r.user_id
                         WHERE r.org_id=? AND r.rdate BETWEEN ? AND ?");
    $st->execute([$orgId, $desde, $hasta]); $rs = $st->fetchAll();

    $tot = count($rs);
    $horas = 0; $noshows = 0; $completadas = 0; $canceladas = 0;
    $porSala = []; $porHora = []; $porEst = [];
    foreach ($rs as $r) {
        $dur = $r['end_hour'] - $r['start_hour'];
        if ($r['status'] === 'no_show') $noshows++;
        if ($r['status'] === 'completada') $completadas++;
        if ($r['status'] === 'cancelada') $canceladas++;
        if (in_array($r['status'], ['activa','completada','no_show'])) {
            $horas += $dur;
            $porSala[$r['room_name']] = ($porSala[$r['room_name']] ?? 0) + $dur;
            for ($h = $r['start_hour']; $h < $r['end_hour']; $h++) $porHora[$h] = ($porHora[$h] ?? 0) + 1;
            $k = $r['uname'] . '|' . $r['carne'];
            $porEst[$k] = ($porEst[$k] ?? ['h' => 0, 'ns' => 0]);
            $porEst[$k]['h'] += $dur;
            if ($r['status'] === 'no_show') $porEst[$k]['ns']++;
        }
    }
    $noCanceladas = $tot - $canceladas;
    $tasaNS = $noCanceladas > 0 ? round(100 * $noshows / $noCanceladas) : 0;

    // capacidad total de bloques del rango (para % ocupación)
    $nRooms = (int)$pdo->query("SELECT COUNT(*) c FROM rooms WHERE org_id=$orgId")->fetch()['c'];
    $diasHabiles = 0;
    for ($d = strtotime($desde); $d <= strtotime($hasta); $d += 86400)
        if (is_open_day($org, date('Y-m-d', $d))) $diasHabiles++;
    $capacidad = $nRooms * $diasHabiles * ((int)$org['close_hour'] - (int)$org['open_hour']);
    $ocup = $capacidad > 0 ? round(100 * $horas / $capacidad) : 0;
    arsort($porSala); ksort($porHora);
    uasort($porEst, fn($x, $y) => $y['h'] <=> $x['h']);
    $maxHora = $porHora ? max($porHora) : 1;
?>
<form method="get" class="card" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap">
  <input type="hidden" name="tab" value="reportes">
  <div><label>Desde</label><input type="date" name="desde" value="<?= e($desde) ?>"></div>
  <div><label>Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>"></div>
  <button class="btn">Aplicar</button>
</form>

<div class="kpis">
  <div class="kpi"><b><?= $tot ?></b><span>reservas</span></div>
  <div class="kpi"><b><?= $horas ?>h</b><span>horas reservadas</span></div>
  <div class="kpi"><b><?= $ocup ?>%</b><span>ocupación</span></div>
  <div class="kpi"><b><?= $tasaNS ?>%</b><span>tasa de no-show (<?= $noshows ?>)</span></div>
</div>

<div class="dos-col" style="margin-top:14px">
  <div class="card">
    <h2 style="margin-top:0">Ocupación por sala</h2>
    <table class="tabla">
      <tr><th>Sala</th><th>Horas</th></tr>
      <?php foreach ($porSala as $sala => $h): ?><tr><td><?= e($sala) ?></td><td><?= $h ?>h</td></tr><?php endforeach; ?>
      <?php if (!$porSala): ?><tr><td colspan="2" class="mini">Sin datos en el rango.</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Demanda por hora del día</h2>
    <?php foreach ($porHora as $h => $n): ?>
      <div style="display:flex; gap:8px; align-items:center; margin:4px 0">
        <span class="mini" style="width:48px"><?= $h ?>:00</span>
        <div class="barra" style="flex:1"><i style="width:<?= round(100 * $n / $maxHora) ?>%"></i></div>
        <span class="mini" style="width:24px; text-align:right"><?= $n ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$porHora): ?><p class="mini">Sin datos en el rango.</p><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Uso por estudiante</h2>
  <div class="tabla-scroll"><table class="tabla">
    <tr><th>Estudiante</th><th>Carné</th><th>Horas</th><th>No-shows</th></tr>
    <?php foreach (array_slice($porEst, 0, 30, true) as $k => $v): [$n, $c] = explode('|', $k); ?>
    <tr><td><?= e($n) ?></td><td><?= e($c) ?></td><td><?= $v['h'] ?>h</td><td><?= $v['ns'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$porEst): ?><tr><td colspan="4" class="mini">Sin datos en el rango.</td></tr><?php endif; ?>
  </table></div>
</div>

<?php /* ================= CONFIG ================= */
elseif ($tab === 'config'):
    $diasSel = explode(',', $org['days_open']);
    $nombresDias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
?>
<div class="card" style="max-width:560px">
  <h2 style="margin-top:0">Reglas de reservación de la asociación</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="config">
    <div class="dos-col">
      <div><label>Hora de apertura</label>
        <select name="open_hour"><?php for ($h = 5; $h <= 12; $h++): ?><option value="<?= $h ?>" <?= $h == $org['open_hour'] ? 'selected' : '' ?>><?= $h ?>:00</option><?php endfor; ?></select></div>
      <div><label>Hora de cierre</label>
        <select name="close_hour"><?php for ($h = 13; $h <= 23; $h++): ?><option value="<?= $h ?>" <?= $h == $org['close_hour'] ? 'selected' : '' ?>><?= $h ?>:00</option><?php endfor; ?></select></div>
      <div><label>Máx. horas por sesión</label>
        <select name="max_blocks_session"><?php for ($h = 1; $h <= 4; $h++): ?><option value="<?= $h ?>" <?= $h == $org['max_blocks_session'] ? 'selected' : '' ?>><?= $h ?></option><?php endfor; ?></select></div>
      <div><label>Máx. horas por semana</label>
        <select name="max_hours_week"><?php for ($h = 1; $h <= 20; $h++): ?><option value="<?= $h ?>" <?= $h == $org['max_hours_week'] ? 'selected' : '' ?>><?= $h ?></option><?php endfor; ?></select></div>
      <div><label>Inicio del ciclo semanal</label>
        <select name="week_start"><?php foreach ($nombresDias as $n => $d): ?><option value="<?= $n ?>" <?= $n == $org['week_start'] ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
      <div><label>Ventana de check-in (min)</label>
        <input type="number" name="checkin_minutes" min="5" max="30" value="<?= (int)$org['checkin_minutes'] ?>"></div>
      <div><label>No-shows para bloqueo</label>
        <input type="number" name="noshow_limit" min="1" max="10" value="<?= (int)$org['noshow_limit'] ?>"></div>
      <div><label>Días de bloqueo</label>
        <input type="number" name="noshow_block_days" min="1" max="30" value="<?= (int)$org['noshow_block_days'] ?>"></div>
    </div>
    <label style="margin-top:12px">Días de operación</label>
    <div style="display:flex; gap:10px; flex-wrap:wrap">
      <?php foreach ($nombresDias as $n => $d): ?>
        <label style="display:flex; gap:4px; align-items:center; font-weight:400">
          <input type="checkbox" name="dias[]" value="<?= $n ?>" style="width:auto" <?= in_array((string)$n, $diasSel) ? 'checked' : '' ?>> <?= $d ?></label>
      <?php endforeach; ?>
    </div>
    <br><button class="btn">Guardar configuración</button>
  </form>
</div>

<?php /* ================= CORREOS ================= */
elseif ($tab === 'correos'):
    $st = $pdo->prepare("SELECT * FROM email_log WHERE org_id=? ORDER BY id DESC LIMIT 50");
    $st->execute([$orgId]); $mails = $st->fetchAll();
    $sinKey = trim(cfg()['resend_api_key'] ?? '') === '';
?>
<?php if ($sinKey): ?>
<div class="alert bad">El envío real de correos está <b>pendiente de activar</b>: falta el API key de Resend en <code>config.php</code>. Mientras tanto, los correos quedan registrados aquí y la app funciona con normalidad.</div>
<?php endif; ?>
<div class="dos-col">
  <div class="card">
    <h2 style="margin-top:0">Enviar correo de prueba</h2>
    <form method="post" style="display:flex; gap:8px; align-items:end">
      <?= csrf_field() ?><input type="hidden" name="a" value="correo_prueba">
      <div style="flex:1"><label>Destino</label><input type="email" name="destino" required placeholder="su-correo@gmail.com"></div>
      <button class="btn chico">Enviar prueba</button>
    </form>
    <p class="mini">Remitente actual: <code><?= e(cfg()['mail_from']) ?></code></p>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Correos pendientes</h2>
    <p class="mini">Reintenta el envío de los correos que quedaron pendientes o con error (los 20 más recientes).</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="correo_reintentar">
      <button class="btn gris chico">Reenviar pendientes</button>
    </form>
  </div>
</div>
<div class="card tabla-scroll">
<table class="tabla">
  <tr><th>Fecha</th><th>Para</th><th>Asunto</th><th>Estado</th></tr>
  <?php if (!$mails): ?><tr><td colspan="4" class="mini">Aún no se han generado correos.</td></tr><?php endif; ?>
  <?php foreach ($mails as $m): ?>
  <tr>
    <td class="mini"><?= e(date('d/m H:i', strtotime($m['created_at']))) ?></td>
    <td><?= e($m['to_email']) ?></td>
    <td><?= e($m['subject']) ?><?= $m['error'] ? '<br><span class="mini">' . e($m['error']) . '</span>' : '' ?></td>
    <td><span class="pill <?= $m['status'] === 'enviado' ? 'completada' : ($m['status'] === 'error' ? 'no_show' : 'activa') ?>"><?= e($m['status']) ?></span></td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php page_bottom();
