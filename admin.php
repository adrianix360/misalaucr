<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/schedule.php';

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

    /* Editor de horario (sin JavaScript): el formulario lleva un único
       <input type="hidden" name="a" value="horario_guardar">, y los botones de
       agregar/eliminar filas son <button name="add_slot|del_slot|add_exc|del_exc"
       value="<índice>">. Así una sola petición conserva TODO lo escrito en el
       formulario y además indica qué fila tocar (no se puede usar name="a" y
       name="i" en un mismo botón). Aquí derivamos la acción real. */
    if (isset($_POST['del_slot']))     $a = 'horario_del_slot';
    elseif (isset($_POST['del_exc']))  $a = 'horario_del_exc';
    elseif (isset($_POST['add_slot'])) $a = 'horario_add_slot';
    elseif (isset($_POST['add_exc']))  $a = 'horario_add_exc';

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
        $campos = ['open_hour','close_hour','max_blocks_session','max_hours_week','week_start','checkin_minutes','noshow_limit','noshow_block_days','booking_release_hour'];
        $vals = [];
        foreach ($campos as $c) $vals[] = (int)$_POST[$c];
        $horizon = in_array($_POST['booking_horizon'] ?? '', ['mismo_dia','dia_siguiente','semana'], true) ? $_POST['booking_horizon'] : 'mismo_dia';
        $dias = implode(',', array_map('intval', $_POST['dias'] ?? []));
        if ((int)$_POST['open_hour'] >= (int)$_POST['close_hour']) { $msg = 'La hora de apertura debe ser menor que la de cierre.'; }
        elseif ($dias === '') { $msg = 'Seleccione al menos un día de operación.'; }
        else {
            $vals[] = $horizon; $vals[] = $dias; $vals[] = $orgId;
            $pdo->prepare("UPDATE organizations SET open_hour=?, close_hour=?, max_blocks_session=?, max_hours_week=?,
                           week_start=?, checkin_minutes=?, noshow_limit=?, noshow_block_days=?, booking_release_hour=?,
                           booking_horizon=?, days_open=? WHERE id=?")
                ->execute($vals);
            $ok = true; $msg = 'Configuración guardada.';
        }

    } elseif ($a === 'restriccion_crear_semanal') {
        $roomId = (int)$_POST['room_id'];
        $weekday = (int)$_POST['weekday'];
        $startHour = (int)$_POST['start_hour']; $endHour = (int)$_POST['end_hour'];
        $label = trim($_POST['label'] ?? '');
        $st = $pdo->prepare("SELECT COUNT(*) c FROM rooms WHERE id=? AND org_id=?"); $st->execute([$roomId, $orgId]);
        if (!(int)$st->fetch()['c']) { $msg = 'Sala no encontrada.'; }
        elseif ($weekday < 1 || $weekday > 7) { $msg = 'Día de la semana inválido.'; }
        elseif ($startHour >= $endHour) { $msg = 'La hora de inicio debe ser menor que la de fin.'; }
        else {
            $pdo->prepare("INSERT INTO room_blackouts (org_id, room_id, weekday, bdate, start_hour, end_hour, label, created_at)
                           VALUES (?,?,?,NULL,?,?,?,?)")
                ->execute([$orgId, $roomId, $weekday, $startHour, $endHour, $label ?: null, date('Y-m-d H:i:s')]);
            $ok = true; $msg = 'Restricción semanal creada.';
        }

    } elseif ($a === 'restriccion_crear_fecha') {
        $roomId = (int)$_POST['room_id'];
        $bdate = trim($_POST['bdate'] ?? '');
        $startHour = (int)$_POST['start_hour']; $endHour = (int)$_POST['end_hour'];
        $label = trim($_POST['label'] ?? '');
        $st = $pdo->prepare("SELECT COUNT(*) c FROM rooms WHERE id=? AND org_id=?"); $st->execute([$roomId, $orgId]);
        if (!(int)$st->fetch()['c']) { $msg = 'Sala no encontrada.'; }
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bdate)) { $msg = 'Fecha inválida.'; }
        elseif ($startHour >= $endHour) { $msg = 'La hora de inicio debe ser menor que la de fin.'; }
        else {
            $pdo->prepare("INSERT INTO room_blackouts (org_id, room_id, weekday, bdate, start_hour, end_hour, label, created_at)
                           VALUES (?,?,NULL,?,?,?,?,?)")
                ->execute([$orgId, $roomId, $bdate, $startHour, $endHour, $label ?: null, date('Y-m-d H:i:s')]);
            $ok = true; $msg = 'Restricción de fecha creada.';
        }

    } elseif ($a === 'restriccion_eliminar') {
        $pdo->prepare("DELETE FROM room_blackouts WHERE id=? AND org_id=?")->execute([(int)$_POST['id'], $orgId]);
        $ok = true; $msg = 'Restricción eliminada.';

    } elseif ($a === 'horario_guardar') {
        $data = horario_desde_post();
        [$ok, $msg] = save_org_schedule($orgId, $data);
        if ($ok) {
            unset($_SESSION['horario_draft']);
            log_activity($orgId, 'horario', 'Horario de atención actualizado');
        } else {
            // se conserva lo escrito para que el admin no pierda el trabajo
            $_SESSION['horario_draft'] = $data;
        }

    } elseif ($a === 'horario_add_slot') {
        $data = horario_desde_post();
        $data['slots'][] = ['start' => '08:00', 'end' => '09:00', 'days' => []];
        $_SESSION['horario_draft'] = $data;
        $ok = true; $msg = 'Franja agregada.';

    } elseif ($a === 'horario_del_slot') {
        $data = horario_desde_post();
        $i = (int)($_POST['del_slot'] ?? -1);
        if (isset($data['slots'][$i])) unset($data['slots'][$i]);
        $data['slots'] = array_values($data['slots']);
        $_SESSION['horario_draft'] = $data;
        $ok = true; $msg = 'Franja eliminada.';

    } elseif ($a === 'horario_add_exc') {
        $data = horario_desde_post();
        $data['exceptions'][] = ['date' => date('Y-m-d'), 'label' => ''];
        $_SESSION['horario_draft'] = $data;
        $ok = true; $msg = 'Excepción agregada.';

    } elseif ($a === 'horario_del_exc') {
        $data = horario_desde_post();
        $i = (int)($_POST['del_exc'] ?? -1);
        if (isset($data['exceptions'][$i])) unset($data['exceptions'][$i]);
        $data['exceptions'] = array_values($data['exceptions']);
        $_SESSION['horario_draft'] = $data;
        $ok = true; $msg = 'Excepción eliminada.';

    } elseif ($a === 'horario_descartar') {
        unset($_SESSION['horario_draft']);
        $ok = true; $msg = 'Cambios sin guardar descartados.';

    } elseif ($a === 'horario_notificar') {
        $h = get_org_schedule($orgId);
        if (!$h || !$h['slots']) { $msg = 'Publica primero un horario antes de notificar.'; }
        else {
            $resumen = horario_resumen($h['slots']);
            $st = $pdo->prepare("SELECT name, email FROM users WHERE org_id=? AND role='student' AND active=1 AND email IS NOT NULL AND email <> ''");
            $st->execute([$orgId]);
            // Cada envío es una llamada bloqueante a Resend. Con muchos estudiantes
            // el request se pasaría del tiempo máximo del servidor y moriría a medias,
            // así que solo se envía en vivo mientras haya margen: el resto queda
            // 'pendiente' en la bitácora y se despacha desde Correos → Reintentar.
            $inicio = microtime(true);
            $n = 0; $enVivo = 0;
            foreach ($st->fetchAll() as $est) {
                $aunHayTiempo = (microtime(true) - $inicio) < 20;
                queue_email($orgId, $est['email'], 'Nuevo horario de atención — ' . $org['name'],
                            email_horario_actualizado($est['name'], $org['name'], $h['title'], $resumen),
                            $aunHayTiempo);
                if ($aunHayTiempo) $enVivo++;
                $n++;
            }
            $pend = $n - $enVivo;
            log_activity($orgId, 'horario', "Horario notificado a $n estudiante(s)");
            $ok = true;
            $msg = "Notificación encolada para $n estudiante(s)."
                 . ($pend > 0 ? " $enVivo enviados ahora; $pend quedaron pendientes: use «Reintentar» en la pestaña Correos." : '');
        }
    }

    flash_set($ok, $msg);
    header('Location: admin.php?tab=' . urlencode($tab) . (isset($_POST['fecha']) ? '&fecha=' . urlencode($_POST['fecha']) : ''));
    exit;
}

/* ============ helpers ============ */
/* (carne_ocupado y crear_estudiante viven en lib/rules.php, compartidas con el super-admin) */

/* Arma el arreglo de horario a partir de los campos del formulario del editor.
   Se usa en las 5 acciones "horario_*" que reenvían el formulario completo.
   Campos esperados: title, primary_color, text_color,
   slot_start[], slot_end[], slot_days[<i>][], exc_date[], exc_label[]. */
function horario_desde_post(): array {
    $starts = (array)($_POST['slot_start'] ?? []);
    $ends   = (array)($_POST['slot_end'] ?? []);
    $dias   = (array)($_POST['slot_days'] ?? []);
    $slots  = [];
    foreach ($starts as $i => $s) {
        $ds = [];
        foreach ((array)($dias[$i] ?? []) as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 7 && !in_array($d, $ds, true)) $ds[] = $d;
        }
        sort($ds);
        $slots[] = [
            'start' => trim((string)$s),
            'end'   => trim((string)($ends[$i] ?? '')),
            'days'  => $ds,
        ];
    }

    $fechas = (array)($_POST['exc_date'] ?? []);
    $labels = (array)($_POST['exc_label'] ?? []);
    $excs   = [];
    foreach ($fechas as $i => $f) {
        $excs[] = [
            'date'  => trim((string)$f),
            'label' => trim((string)($labels[$i] ?? '')),
        ];
    }

    return [
        'title'         => trim((string)($_POST['title'] ?? '')),
        'primary_color' => trim((string)($_POST['primary_color'] ?? '')),
        'text_color'    => trim((string)($_POST['text_color'] ?? '')),
        'slots'         => $slots,
        'exceptions'    => $excs,
    ];
}

/* Resumen en texto plano de las franjas, para el cuerpo del correo.
   Ej: "Lunes, Martes: 07:00–08:50 · Miércoles: 14:00–16:00" */
function horario_resumen(array $slots): string {
    $nombres = horario_dias();
    $partes = [];
    foreach ($slots as $s) {
        $ds = [];
        foreach ((array)($s['days'] ?? []) as $d) {
            if (isset($nombres[(int)$d])) $ds[] = $nombres[(int)$d];
        }
        if (!$ds) continue;
        $partes[] = implode(', ', $ds) . ': ' . (string)($s['start'] ?? '') . '–' . (string)($s['end'] ?? '');
    }
    return implode(' · ', $partes);
}

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
$tabs = ['reservas' => 'Reservas', 'estudiantes' => 'Estudiantes', 'salas' => 'Salas', 'restricciones' => 'Restricciones', 'reportes' => 'Reportes', 'config' => 'Configuración', 'horario' => 'Horario', 'correos' => 'Correos'];
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

<?php /* ================= RESTRICCIONES ================= */
elseif ($tab === 'restricciones'):
    $rooms = $pdo->prepare("SELECT * FROM rooms WHERE org_id=? ORDER BY name"); $rooms->execute([$orgId]); $rooms = $rooms->fetchAll();
    $nombresDias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    $st = $pdo->prepare("SELECT b.*, rm.name AS room_name FROM room_blackouts b
                         JOIN rooms rm ON rm.id = b.room_id WHERE b.org_id=? ORDER BY rm.name, b.weekday, b.bdate");
    $st->execute([$orgId]); $blackouts = $st->fetchAll();
?>
<div class="card tabla-scroll">
<table class="tabla">
  <tr><th>Sala</th><th>Tipo</th><th>Día / fecha</th><th>Horario</th><th>Nota</th><th></th></tr>
  <?php foreach ($blackouts as $b): ?>
  <tr>
    <td><?= e($b['room_name']) ?></td>
    <td><?= $b['bdate'] ? 'Fecha puntual' : 'Semanal' ?></td>
    <td><?= $b['bdate'] ? e(date('d/m/Y', strtotime($b['bdate']))) : e($nombresDias[(int)$b['weekday']] ?? '?') ?></td>
    <td><?= (int)$b['start_hour'] ?>:00–<?= (int)$b['end_hour'] ?>:00</td>
    <td class="mini"><?= e($b['label']) ?></td>
    <td>
      <form class="inline" method="post" onsubmit="return confirm('¿Eliminar esta restricción?')">
        <?= csrf_field() ?><input type="hidden" name="a" value="restriccion_eliminar"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <button class="btn gris chico">Eliminar</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$blackouts): ?><tr><td colspan="6" class="mini">Sin restricciones configuradas.</td></tr><?php endif; ?>
</table>
</div>

<div class="dos-col" style="margin-top:14px">
  <div class="card">
    <h2 style="margin-top:0">Restricción semanal recurrente</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="restriccion_crear_semanal">
      <label>Sala</label>
      <select name="room_id" required>
        <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
      <label>Día de la semana</label>
      <select name="weekday" required>
        <?php foreach ($nombresDias as $n => $d): ?><option value="<?= $n ?>"><?= e($d) ?></option><?php endforeach; ?>
      </select>
      <div class="dos-col">
        <div><label>Hora de inicio</label>
          <select name="start_hour"><?php for ($h = 0; $h <= 23; $h++): ?><option value="<?= $h ?>"><?= $h ?>:00</option><?php endfor; ?></select></div>
        <div><label>Hora de fin</label>
          <select name="end_hour"><?php for ($h = 1; $h <= 24; $h++): ?><option value="<?= $h ?>" <?= $h == 23 ? 'selected' : '' ?>><?= $h ?>:00</option><?php endfor; ?></select></div>
      </div>
      <label>Nota (opcional)</label>
      <input name="label" placeholder="Ej: limpieza semanal" maxlength="150">
      <br><button class="btn chico">Crear restricción</button>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Restricción de fecha puntual</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="restriccion_crear_fecha">
      <label>Sala</label>
      <select name="room_id" required>
        <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
      <label>Fecha</label>
      <input type="date" name="bdate" required>
      <div class="dos-col">
        <div><label>Hora de inicio</label>
          <select name="start_hour"><?php for ($h = 0; $h <= 23; $h++): ?><option value="<?= $h ?>"><?= $h ?>:00</option><?php endfor; ?></select></div>
        <div><label>Hora de fin</label>
          <select name="end_hour"><?php for ($h = 1; $h <= 24; $h++): ?><option value="<?= $h ?>" <?= $h == 23 ? 'selected' : '' ?>><?= $h ?>:00</option><?php endfor; ?></select></div>
      </div>
      <label>Nota (opcional)</label>
      <input name="label" placeholder="Ej: evento especial" maxlength="150">
      <br><button class="btn chico">Crear restricción</button>
    </form>
  </div>
</div>
<p class="mini">Una sala restringida en un día/horario específico se muestra como "Restringido" en la rejilla del estudiante para ese día y hora, sin afectar el resto de la sala ni de la semana.</p>

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
      <div><label>Hora de apertura de reservas</label>
        <select name="booking_release_hour"><?php for ($h = 0; $h <= 23; $h++): ?><option value="<?= $h ?>" <?= $h == $org['booking_release_hour'] ? 'selected' : '' ?>><?= $h ?>:00</option><?php endfor; ?></select>
        <p class="mini" style="margin:4px 0 0">Hora en que se habilitan los cupos del día (puede ser distinta al horario de apertura de la sala).</p></div>
      <div><label>Política de reservas</label>
        <select name="booking_horizon">
          <option value="mismo_dia" <?= ($org['booking_horizon'] ?? 'mismo_dia') === 'mismo_dia' ? 'selected' : '' ?>>Solo el mismo día</option>
          <option value="dia_siguiente" <?= ($org['booking_horizon'] ?? '') === 'dia_siguiente' ? 'selected' : '' ?>>Hasta el día siguiente</option>
          <option value="semana" <?= ($org['booking_horizon'] ?? '') === 'semana' ? 'selected' : '' ?>>Toda la semana operativa</option>
        </select></div>
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

<?php /* ================= HORARIO ================= */
elseif ($tab === 'horario'):
    $hGuardado = get_org_schedule($orgId);
    $h = $_SESSION['horario_draft'] ?? $hGuardado ?? ['title' => 'Horario de atención', 'primary_color' => '#F4C430', 'text_color' => '#1A1A1A', 'slots' => [], 'exceptions' => []];
    $nombresDias = horario_dias();
    $slots = (array)($h['slots'] ?? []);
    $excs  = (array)($h['exceptions'] ?? []);
    // colores efectivos (con respaldo si vinieran vacíos o inválidos)
    $colFondo = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($h['primary_color'] ?? '')) ? $h['primary_color'] : '#F4C430';
    $colTexto = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($h['text_color'] ?? '')) ? $h['text_color'] : '#1A1A1A';
    // estudiantes que recibirían el aviso
    $st = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE org_id=? AND role='student' AND active=1 AND email IS NOT NULL AND email <> ''");
    $st->execute([$orgId]); $nDestinatarios = (int)$st->fetch()['c'];
?>
<div class="card">
  <h2 style="margin-top:0">Horario de atención</h2>
  <p class="mini">Este es el horario que verán sus estudiantes. Los botones «Agregar» y «Eliminar» conservan lo que ya escribió; los cambios se publican al presionar <b>Guardar horario</b>.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="horario_guardar">

    <label>Título</label>
    <input type="text" name="title" maxlength="120" value="<?= e((string)($h['title'] ?? '')) ?>" placeholder="Horario de atención">

    <div class="dos-col">
      <div><label>Color de fondo</label><input type="color" name="primary_color" value="<?= e($colFondo) ?>"></div>
      <div><label>Color de texto</label><input type="color" name="text_color" value="<?= e($colTexto) ?>"></div>
    </div>

    <h3 style="margin:18px 0 6px">Franjas horarias</h3>
    <?php if (!$slots): ?>
      <p class="mini">Aún no hay franjas. Agregue al menos una franja para poder guardar y publicar el horario.</p>
    <?php endif; ?>
    <?php foreach ($slots as $i => $sl):
        $sDays = (array)($sl['days'] ?? []); ?>
      <div class="card" style="padding:10px; margin:8px 0">
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap">
          <div><label>Desde</label><input type="time" name="slot_start[]" value="<?= e((string)($sl['start'] ?? '')) ?>"></div>
          <div><label>Hasta</label><input type="time" name="slot_end[]" value="<?= e((string)($sl['end'] ?? '')) ?>"></div>
          <button type="submit" class="btn gris chico" name="del_slot" value="<?= (int)$i ?>" formnovalidate>Eliminar franja</button>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px">
          <?php foreach ($nombresDias as $n => $d): ?>
            <label style="display:flex; gap:4px; align-items:center; font-weight:400">
              <input type="checkbox" name="slot_days[<?= (int)$i ?>][]" value="<?= (int)$n ?>" style="width:auto"
                     <?= in_array((int)$n, array_map('intval', $sDays), true) ? 'checked' : '' ?>> <?= e($d) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <button type="submit" class="btn gris chico" name="add_slot" value="1" formnovalidate>Agregar franja</button>

    <h3 style="margin:18px 0 6px">Excepciones (días sin atención)</h3>
    <?php if (!$excs): ?>
      <p class="mini">Sin excepciones. Úselas para feriados o actividades puntuales.</p>
    <?php endif; ?>
    <?php foreach ($excs as $i => $ex): ?>
      <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin:8px 0">
        <div><label>Fecha</label><input type="date" name="exc_date[]" value="<?= e((string)($ex['date'] ?? '')) ?>"></div>
        <div style="flex:1; min-width:180px"><label>Motivo</label><input type="text" name="exc_label[]" maxlength="120" value="<?= e((string)($ex['label'] ?? '')) ?>" placeholder="Feriado, actividad…"></div>
        <button type="submit" class="btn gris chico" name="del_exc" value="<?= (int)$i ?>" formnovalidate>Eliminar</button>
      </div>
    <?php endforeach; ?>
    <button type="submit" class="btn gris chico" name="add_exc" value="1" formnovalidate>Agregar excepción</button>

    <br><br><button class="btn">Guardar horario</button>
  </form>
  <?php if (isset($_SESSION['horario_draft'])): ?>
    <div class="alert bad" style="margin-top:12px">
      Tiene cambios <b>sin guardar</b>. No los verán los estudiantes hasta que presione «Guardar horario».
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="a" value="horario_descartar">
        <button class="btn gris chico">Descartar cambios</button>
      </form>
    </div>
  <?php endif; ?>
  <?php if ($hGuardado && !empty($hGuardado['updated_at'])): ?>
    <p class="mini">Última actualización: <?= e(date('d/m/Y H:i', strtotime($hGuardado['updated_at']))) ?></p>
  <?php endif; ?>
</div>

<div class="card tabla-scroll">
  <h2 style="margin-top:0">Vista previa</h2>
  <p class="mini">Así se verá el horario para los estudiantes.</p>
  <table class="tabla horario-grid">
    <caption style="caption-side:top; text-align:left; font-weight:700; padding:6px 0"><?= e((string)($h['title'] ?? '')) ?></caption>
    <tr>
      <th>Hora</th>
      <?php foreach ($nombresDias as $d): ?><th><?= e($d) ?></th><?php endforeach; ?>
    </tr>
    <?php if (!$slots): ?>
      <tr><td colspan="8" class="mini">Agregue franjas para ver la vista previa.</td></tr>
    <?php endif; ?>
    <?php foreach ($slots as $sl):
        $sDays = array_map('intval', (array)($sl['days'] ?? [])); ?>
    <tr>
      <td><?= e((string)($sl['start'] ?? '')) ?>–<?= e((string)($sl['end'] ?? '')) ?></td>
      <?php foreach ($nombresDias as $n => $d): ?>
        <?php if (in_array((int)$n, $sDays, true)): ?>
          <td style="background:<?= e($colFondo) ?>; color:<?= e($colTexto) ?>; text-align:center; font-weight:700">Atención</td>
        <?php else: ?>
          <td style="text-align:center; opacity:.4">—</td>
        <?php endif; ?>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($excs): ?>
    <p class="mini" style="margin-top:10px"><b>Excepciones:</b>
      <?php $tx = [];
            foreach ($excs as $ex) {
                $f = (string)($ex['date'] ?? '');
                $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? strtotime($f) : false;
                $tx[] = ($ts ? date('d/m/Y', $ts) : ($f !== '' ? $f : '—'))
                      . (!empty($ex['label']) ? ' (' . $ex['label'] . ')' : '');
            }
            echo e(implode(' · ', $tx)); ?>
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Notificar a estudiantes</h2>
  <p class="mini"><b><?= $nDestinatarios ?></b> estudiante(s) con correo registrado recibirán el aviso.
     El envío es <b>inmediato</b> al presionar el botón: revise antes la vista previa.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="horario_notificar">
    <button class="btn gris">Notificar cambio de horario</button>
  </form>
  <p class="mini">Se envía el horario ya <b>guardado</b>, no el borrador en edición.</p>
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
