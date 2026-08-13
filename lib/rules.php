<?php
/**
 * MiSalaUCR — Reglas de negocio: reservas, límites, check-in, no-shows.
 * Todas las reglas se leen de la configuración de cada organización.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/* ---------- utilidades de tiempo ---------- */

function today(): string { return date('Y-m-d'); }

function dt(string $date, int $hour): string {
    return sprintf('%s %02d:00:00', $date, $hour);
}

/** Lunes (o día configurado) de la semana que contiene $date. Devuelve [inicio, fin] Y-m-d. */
function week_bounds(array $org, string $date): array {
    $ws  = (int)$org['week_start'];             // 1 = lunes ... 7 = domingo (ISO)
    $dow = (int)date('N', strtotime($date));
    $diff = ($dow - $ws + 7) % 7;
    $start = date('Y-m-d', strtotime("$date -$diff days"));
    $end   = date('Y-m-d', strtotime("$start +6 days"));
    return [$start, $end];
}

function is_open_day(array $org, string $date): bool {
    return in_array(date('N', strtotime($date)), explode(',', $org['days_open']));
}

/** Horas de inicio de bloque del día operativo: [7,8,...,21] */
function block_hours(array $org): array {
    return range((int)$org['open_hour'], (int)$org['close_hour'] - 1);
}

/** Última fecha reservable según la política de la organización. */
function max_reservable_date(array $org): string {
    $hoy = today();
    if ($org['booking_horizon'] === 'dia_siguiente') return date('Y-m-d', strtotime("$hoy +1 day"));
    if ($org['booking_horizon'] === 'semana') return week_bounds($org, $hoy)[1];
    return $hoy; // mismo_dia
}

/** Fechas reservables (hoy en adelante, según la política, filtradas por días de operación). */
function reservable_dates(array $org): array {
    $max = max_reservable_date($org);
    $out = [];
    for ($d = today(); $d <= $max; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if (is_open_day($org, $d)) $out[] = $d;
    }
    return $out;
}

/* ---------- procesos automáticos (se ejecutan en cada visita y vía cron.php) ---------- */

function process_automatic(): void {
    $pdo = db();
    $now = date('Y-m-d H:i:s');

    // 0) Caducar inscripciones de fila de espera cuyo bloque ya terminó.
    //    (hora interpolada como entero: PDO liga parámetros como texto y en SQLite
    //     "expresión numérica <= texto" siempre es verdadero)
    $hAct = (int)date('G');
    $pdo->prepare("UPDATE waitlist SET status = 'cancelada'
                   WHERE status = 'esperando' AND (rdate < ? OR (rdate = ? AND start_hour + n_blocks <= $hAct))")
        ->execute([today(), today()]);

    // 1) No-shows: reservas activas sin check-in cuya ventana ya venció.
    $rows = $pdo->query(
        "SELECT r.*, o.checkin_minutes, o.noshow_limit, o.noshow_block_days, rm.name AS room_name
         FROM reservations r JOIN organizations o ON o.id = r.org_id
         JOIN rooms rm ON rm.id = r.room_id
         WHERE r.status = 'activa' AND r.checked_in_at IS NULL")->fetchAll();
    foreach ($rows as $r) {
        $deadline = date('Y-m-d H:i:s', max(
            strtotime(dt($r['rdate'], (int)$r['start_hour'])),
            strtotime($r['created_at'])
        ) + 60 * (int)$r['checkin_minutes']);
        if ($deadline >= $now) continue;

        $pdo->prepare("UPDATE reservations SET status = 'no_show' WHERE id = ? AND status = 'activa'")
            ->execute([$r['id']]);
        $pdo->prepare("DELETE FROM reservation_blocks WHERE reservation_id = ?")->execute([$r['id']]);

        // Contador de inasistencias y bloqueo temporal
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([$r['user_id']]);
        if ($u = $st->fetch()) {
            $count = (int)$u['noshow_count'] + 1;
            if ($count >= (int)$r['noshow_limit']) {
                $until = date('Y-m-d H:i:s', time() + 86400 * (int)$r['noshow_block_days']);
                $pdo->prepare("UPDATE users SET noshow_count = 0, blocked_until = ? WHERE id = ?")
                    ->execute([$until, $u['id']]);
                if ($u['email']) queue_email((int)$r['org_id'], $u['email'],
                    'MiSalaUCR — Cuenta bloqueada temporalmente',
                    "Hola {$u['name']}:\n\nAcumulaste {$r['noshow_limit']} inasistencias (no-show), por lo que no podrás hacer nuevas reservas hasta el " . date('d/m/Y', strtotime($until)) . ".\n\nSi crees que es un error, contacta a tu asociación.");
            } else {
                $pdo->prepare("UPDATE users SET noshow_count = ? WHERE id = ?")->execute([$count, $u['id']]);
            }
            log_activity((int)$r['org_id'], 'no_show',
                "{$u['name']} no llegó a su reserva ({$r['room_name']}, {$r['rdate']} {$r['start_hour']}:00)");
        }

        // El bloque liberado puede tener fila de espera.
        for ($h = (int)$r['start_hour']; $h < (int)$r['end_hour']; $h++) {
            promote_waitlist((int)$r['room_id'], $r['rdate'], $h);
        }
    }

    // 2) Completar reservas con check-in cuyo bloque ya terminó.
    $pdo->prepare("UPDATE reservations SET status = 'completada'
                   WHERE status = 'activa' AND checked_in_at IS NOT NULL
                     AND (rdate < ? OR (rdate = ? AND end_hour <= $hAct))")
        ->execute([today(), today()]);

    // 3) Recordatorios por correo.
    $mins = (int)(cfg()['reminder_minutes'] ?? 30);
    $rows = $pdo->query(
        "SELECT r.*, u.email, u.name AS uname, rm.name AS room_name
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         JOIN rooms rm ON rm.id = r.room_id
         WHERE r.status = 'activa' AND r.reminder_sent = 0")->fetchAll();
    foreach ($rows as $r) {
        $startTs = strtotime(dt($r['rdate'], (int)$r['start_hour']));
        if ($startTs - time() > 60 * $mins || $startTs < time()) {
            if ($startTs < time()) // ya inició: no tiene sentido recordar
                $pdo->prepare("UPDATE reservations SET reminder_sent = 1 WHERE id = ?")->execute([$r['id']]);
            continue;
        }
        $pdo->prepare("UPDATE reservations SET reminder_sent = 1 WHERE id = ?")->execute([$r['id']]);
        if ($r['email']) {
            $stOrg = $pdo->prepare("SELECT checkin_minutes FROM organizations WHERE id = ?");
            $stOrg->execute([(int)$r['org_id']]);
            $checkinMin = (int)($stOrg->fetch()['checkin_minutes'] ?? 10);
            queue_email((int)$r['org_id'], $r['email'],
                'MiSalaUCR — Recordatorio de tu reserva',
                email_recordatorio($r['uname'], $r['room_name'], (int)$r['start_hour'], (int)$r['end_hour'], $checkinMin));
        }
    }
}

/* ---------- consultas ---------- */

/** Horas ya usadas por el estudiante en la semana de $date (activas + completadas + no-shows). */
function weekly_used_hours(int $userId, array $org, string $date): int {
    [$a, $b] = week_bounds($org, $date);
    $st = db()->prepare(
        "SELECT COALESCE(SUM(end_hour - start_hour),0) AS h FROM reservations
         WHERE user_id = ? AND org_id = ? AND rdate BETWEEN ? AND ? AND status IN ('activa','completada','no_show')");
    $st->execute([$userId, (int)$org['id'], $a, $b]);
    return (int)$st->fetch()['h'];
}

/** null si puede reservar; texto del motivo si está bloqueado. */
function student_block_reason(array $u): ?string {
    if ($u['blocked_until'] && $u['blocked_until'] > date('Y-m-d H:i:s')) {
        return 'Tu cuenta está bloqueada para reservar hasta el ' .
               date('d/m/Y H:i', strtotime($u['blocked_until'])) . ' por acumular inasistencias.';
    }
    return null;
}

/** Mapa de ocupación del día: [room_id][hour] => reservation_id */
function day_occupancy(int $orgId, string $date): array {
    $st = db()->prepare(
        "SELECT b.room_id, b.hour, b.reservation_id FROM reservation_blocks b
         JOIN rooms rm ON rm.id = b.room_id WHERE rm.org_id = ? AND b.rdate = ?");
    $st->execute([$orgId, $date]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['room_id']][$r['hour']] = (int)$r['reservation_id'];
    return $map;
}

/** Mapa de restricciones del día: [room_id][hour] => etiqueta (o 'Restringido' si no tiene). */
function day_blackout_map(int $orgId, string $date): array {
    $weekday = (int)date('N', strtotime($date));
    $st = db()->prepare(
        "SELECT b.room_id, b.start_hour, b.end_hour, b.label FROM room_blackouts b
         JOIN rooms rm ON rm.id = b.room_id
         WHERE rm.org_id = ? AND (b.bdate = ? OR (b.bdate IS NULL AND b.weekday = ?))");
    $st->execute([$orgId, $date, $weekday]);
    $map = [];
    foreach ($st->fetchAll() as $b) {
        for ($h = (int)$b['start_hour']; $h < (int)$b['end_hour']; $h++) {
            $map[$b['room_id']][$h] = $b['label'] ?: 'Restringido';
        }
    }
    return $map;
}

/** ¿La sala tiene una restricción activa en esa fecha+hora? */
function room_blackout_activo(int $roomId, string $date, int $hour): bool {
    $weekday = (int)date('N', strtotime($date));
    $st = db()->prepare(
        "SELECT COUNT(*) c FROM room_blackouts
         WHERE room_id = ? AND start_hour <= ? AND end_hour > ?
           AND (bdate = ? OR (bdate IS NULL AND weekday = ?))");
    $st->execute([$roomId, $hour, $hour, $date, $weekday]);
    return (int)$st->fetch()['c'] > 0;
}

/* ---------- acciones ---------- */

/**
 * Intenta crear una reserva para $date (según la política de reserva anticipada
 * de la organización). $nBlocks = 1 o 2 bloques consecutivos.
 * Devuelve [true, mensaje] o [false, motivo].
 */
function try_reserve(array $u, array $org, int $roomId, int $startHour, int $nBlocks, ?string $date = null): array {
    $pdo  = db();
    $date = $date ?? today();
    $now  = time();
    $esHoy = $date === today();

    if ((int)$u['org_id'] !== (int)$org['id']) return [false, 'No perteneces a esta organización.'];
    if ($msg = student_block_reason($u)) return [false, $msg];
    if (!$org['active']) return [false, 'La organización no está activa.'];
    if (!in_array($date, reservable_dates($org), true)) {
        return [false, 'Esa fecha no está disponible para reservar.'];
    }
    if ($esHoy && $now < strtotime(dt($date, (int)$org['booking_release_hour']))) {
        return [false, 'Las reservas del día se abren a las ' . (int)$org['booking_release_hour'] . ':00 a.m.'];
    }

    $nBlocks = max(1, min($nBlocks, (int)$org['max_blocks_session']));
    $endHour = $startHour + $nBlocks;
    if ($startHour < (int)$org['open_hour'] || $endHour > (int)$org['close_hour']) {
        return [false, 'Horario fuera del rango operativo.'];
    }

    // Si es hoy, el bloque no debe haber vencido ya
    // (se permite tomar el bloque en curso durante sus primeros minutos de check-in).
    $graceEnd = strtotime(dt($date, $startHour)) + 60 * (int)$org['checkin_minutes'];
    if ($now >= $graceEnd) return [false, 'Ese bloque ya pasó. Elige un bloque más tarde.'];

    // Sala disponible y de la misma organización
    $st = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND org_id = ?");
    $st->execute([$roomId, (int)$org['id']]);
    $room = $st->fetch();
    if (!$room) return [false, 'Sala no encontrada.'];
    if ($room['status'] !== 'disponible') return [false, 'La sala está bloqueada por mantenimiento.'];

    for ($h = $startHour; $h < $endHour; $h++) {
        if (room_blackout_activo($roomId, $date, $h)) {
            return [false, 'Ese horario está restringido para esta sala.'];
        }
    }

    // Límite semanal
    $used = weekly_used_hours((int)$u['id'], $org, $date);
    $left = (int)$org['max_hours_week'] - $used;
    if ($nBlocks > $left) {
        return [false, $left <= 0
            ? 'Ya usaste tus ' . $org['max_hours_week'] . ' horas de esta semana.'
            : "Solo te queda $left hora(s) esta semana."];
    }

    // Crear reserva + bloques en una transacción; el índice único evita choques simultáneos.
    $nowStr = date('Y-m-d H:i:s');
    // Si la asociación no exige confirmar llegada, la reserva nace ya confirmada
    // (así el resto de la lógica de no-show, que solo mira checked_in_at, no necesita tocarse).
    $checkinAuto = $org['require_checkin'] ? null : $nowStr;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO reservations (org_id, room_id, user_id, rdate, start_hour, end_hour, status, created_at, checked_in_at)
                       VALUES (?,?,?,?,?,?,'activa',?,?)")
            ->execute([(int)$org['id'], $roomId, (int)$u['id'], $date, $startHour, $endHour, $nowStr, $checkinAuto]);
        $resId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO reservation_blocks (room_id, rdate, hour, reservation_id) VALUES (?,?,?,?)");
        for ($h = $startHour; $h < $endHour; $h++) $ins->execute([$roomId, $date, $h, $resId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [false, 'Alguien reservó ese bloque justo antes que tú. Elige otro.'];
    }

    log_activity((int)$org['id'], 'reserva', "{$u['name']} reservó {$room['name']} $startHour:00–$endHour:00");

    if ($u['email']) queue_email((int)$org['id'], $u['email'],
        'MiSalaUCR — Reserva confirmada',
        email_reserva_confirmada($u['name'], $room['name'], date('d/m/Y', strtotime($date)),
            $startHour, $endHour, (int)$org['checkin_minutes'], (bool)$org['require_checkin']));

    return [true, "Reserva confirmada: {$room['name']}, $startHour:00–$endHour:00."];
}

/** Cancela una reserva. $isAdmin permite cancelar reservas ajenas y ya iniciadas. */
function cancel_reservation(array $u, int $resId, bool $isAdmin = false): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
    $st->execute([$resId]);
    $r = $st->fetch();
    if (!$r || $r['status'] !== 'activa') return [false, 'La reserva no está activa.'];

    if ($isAdmin) {
        if ((int)$r['org_id'] !== (int)$u['org_id']) return [false, 'Reserva de otra organización.'];
    } else {
        if ((int)$r['user_id'] !== (int)$u['id']) return [false, 'Esta reserva no es tuya.'];
        // El estudiante solo puede cancelar hasta 10 minutos antes del inicio.
        if (time() >= strtotime(dt($r['rdate'], (int)$r['start_hour'])) - 600) {
            return [false, 'Ya no se puede cancelar esta reserva: faltan menos de 10 minutos para el inicio (o ya inició).'];
        }
    }

    $pdo->prepare("UPDATE reservations SET status = 'cancelada' WHERE id = ?")->execute([$resId]);
    $pdo->prepare("DELETE FROM reservation_blocks WHERE reservation_id = ?")->execute([$resId]);

    $sr = $pdo->prepare("SELECT name FROM rooms WHERE id = ?"); $sr->execute([$r['room_id']]);
    $roomName = ($sr->fetch()['name'] ?? ('Sala #' . $r['room_id']));
    log_activity((int)$r['org_id'], 'cancelacion',
        ($isAdmin ? "El admin {$u['name']} canceló una reserva" : "{$u['name']} canceló su reserva") .
        " ($roomName, {$r['rdate']} {$r['start_hour']}:00–{$r['end_hour']}:00)");

    // Si alguien esperaba este bloque, asignárselo en orden de llegada.
    for ($h = (int)$r['start_hour']; $h < (int)$r['end_hour']; $h++) {
        promote_waitlist((int)$r['room_id'], $r['rdate'], $h);
    }
    return [true, 'Reserva cancelada. El bloque quedó libre para otros.'];
}

/** Check-in dentro de la ventana permitida. */
function do_checkin(array $u, int $resId): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT r.*, o.checkin_minutes, rm.name AS room_name FROM reservations r
                         JOIN organizations o ON o.id = r.org_id
                         JOIN rooms rm ON rm.id = r.room_id WHERE r.id = ? AND r.user_id = ?");
    $st->execute([$resId, (int)$u['id']]);
    $r = $st->fetch();
    if (!$r || $r['status'] !== 'activa') return [false, 'La reserva no está activa.'];
    if ($r['checked_in_at']) return [false, 'Ya confirmaste tu llegada.'];

    $winStart = max(strtotime(dt($r['rdate'], (int)$r['start_hour'])), strtotime($r['created_at']));
    $winEnd   = $winStart + 60 * (int)$r['checkin_minutes'];
    $now = time();
    if ($now < strtotime(dt($r['rdate'], (int)$r['start_hour'])) && $now < $winStart) {
        return [false, 'Aún no inicia tu bloque.'];
    }
    if ($now > $winEnd) return [false, 'La ventana de check-in ya venció.'];

    $pdo->prepare("UPDATE reservations SET checked_in_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $resId]);
    log_activity((int)$r['org_id'], 'checkin', "{$u['name']} confirmó su llegada ({$r['room_name']}, {$r['start_hour']}:00)");
    return [true, '¡Llegada confirmada! Disfruta tu sesión de estudio.'];
}

/* ---------- bitácora de actividad ---------- */

function log_activity(?int $orgId, string $kind, string $desc): void {
    db()->prepare("INSERT INTO activity_log (org_id, kind, description, created_at) VALUES (?,?,?,?)")
        ->execute([$orgId, $kind, mb_substr($desc, 0, 250), date('Y-m-d H:i:s')]);
}

/* ---------- fila de espera ---------- */

/**
 * Inscribe al estudiante en la fila de espera de un bloque exacto (sala+fecha+hora).
 * Valida las mismas condiciones base que una reserva normal.
 */
function join_waitlist(array $u, array $org, int $roomId, int $startHour, int $nBlocks, ?string $date = null): array {
    $pdo  = db();
    $date = $date ?? today();
    $now  = time();
    $esHoy = $date === today();

    if ((int)$u['org_id'] !== (int)$org['id']) return [false, 'No perteneces a esta organización.'];
    if ($msg = student_block_reason($u)) return [false, $msg];
    if (!$org['active']) return [false, 'La organización no está activa.'];
    if (!in_array($date, reservable_dates($org), true)) {
        return [false, 'Esa fecha no está disponible para reservar.'];
    }
    if ($esHoy && $now < strtotime(dt($date, (int)$org['booking_release_hour']))) {
        return [false, 'Las reservas del día se abren a las ' . (int)$org['booking_release_hour'] . ':00 a.m.'];
    }

    $nBlocks = max(1, min($nBlocks, (int)$org['max_blocks_session']));
    $endHour = $startHour + $nBlocks;
    if ($startHour < (int)$org['open_hour'] || $endHour > (int)$org['close_hour']) {
        return [false, 'Horario fuera del rango operativo.'];
    }
    if ($now >= strtotime(dt($date, $startHour)) + 60 * (int)$org['checkin_minutes']) {
        return [false, 'Ese bloque ya pasó.'];
    }

    $st = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND org_id = ?");
    $st->execute([$roomId, (int)$org['id']]);
    $room = $st->fetch();
    if (!$room) return [false, 'Sala no encontrada.'];
    if ($room['status'] !== 'disponible') return [false, 'La sala está bloqueada por mantenimiento.'];
    if (room_blackout_activo($roomId, $date, $startHour)) {
        return [false, 'Ese horario está restringido para esta sala.'];
    }

    $left = (int)$org['max_hours_week'] - weekly_used_hours((int)$u['id'], $org, $date);
    if ($nBlocks > $left) {
        return [false, $left <= 0
            ? 'Ya usaste tus ' . $org['max_hours_week'] . ' horas de esta semana.'
            : "Solo te queda $left hora(s) esta semana."];
    }

    // No duplicar inscripción para el mismo bloque exacto.
    $st = $pdo->prepare("SELECT COUNT(*) c FROM waitlist
                         WHERE user_id = ? AND room_id = ? AND rdate = ? AND start_hour = ? AND status = 'esperando'");
    $st->execute([(int)$u['id'], $roomId, $date, $startHour]);
    if ((int)$st->fetch()['c'] > 0) return [false, 'Ya estás en la fila de espera de ese bloque.'];

    // Si el bloque está libre, no tiene sentido la fila.
    $st = $pdo->prepare("SELECT COUNT(*) c FROM reservation_blocks WHERE room_id = ? AND rdate = ? AND hour = ?");
    $st->execute([$roomId, $date, $startHour]);
    if ((int)$st->fetch()['c'] === 0) return [false, 'Ese bloque está libre: resérvalo directamente.'];

    $st = $pdo->prepare("SELECT COUNT(*) c FROM waitlist
                         WHERE room_id = ? AND rdate = ? AND start_hour = ? AND status = 'esperando'");
    $st->execute([$roomId, $date, $startHour]);
    $pos = (int)$st->fetch()['c'] + 1;

    $pdo->prepare("INSERT INTO waitlist (org_id, room_id, rdate, start_hour, n_blocks, user_id, status, created_at)
                   VALUES (?,?,?,?,?,?,'esperando',?)")
        ->execute([(int)$org['id'], $roomId, $date, $startHour, $nBlocks, (int)$u['id'], date('Y-m-d H:i:s')]);

    log_activity((int)$org['id'], 'fila', "{$u['name']} entró a la fila de espera ({$room['name']}, $startHour:00)");
    return [true, "Quedaste en la fila de espera de {$room['name']} a las $startHour:00 (posición $pos). Si se libera, la reserva se te asignará automáticamente y te avisaremos por correo."];
}

/** El estudiante cancela su inscripción en la fila (libre, en cualquier momento). */
function cancel_waitlist(array $u, int $wid): array {
    $st = db()->prepare("UPDATE waitlist SET status = 'cancelada'
                         WHERE id = ? AND user_id = ? AND status = 'esperando'");
    $st->execute([$wid, (int)$u['id']]);
    return $st->rowCount() > 0
        ? [true, 'Saliste de la fila de espera.']
        : [false, 'Esa inscripción no está activa.'];
}

/**
 * Al liberarse un bloque (cancelación o no-show), asigna la reserva al primero
 * de la fila cuyas condiciones sigan cumpliéndose, y le avisa por correo.
 */
function promote_waitlist(int $roomId, string $rdate, int $startHour): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM waitlist
                         WHERE room_id = ? AND rdate = ? AND start_hour = ? AND status = 'esperando'
                         ORDER BY created_at, id");
    $st->execute([$roomId, $rdate, $startHour]);
    $cands = $st->fetchAll();
    if (!$cands) return;

    $st = $pdo->prepare("SELECT o.* FROM organizations o JOIN rooms rm ON rm.org_id = o.id WHERE rm.id = ?");
    $st->execute([$roomId]);
    $org = $st->fetch();
    if (!$org || !$org['active']) return;

    $st = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $st->execute([$roomId]);
    $room = $st->fetch();
    if (!$room || $room['status'] !== 'disponible') return;

    foreach ($cands as $w) {
        $n = max(1, (int)$w['n_blocks']);
        $endHour = $startHour + $n;

        // El período del bloque no debe haber terminado.
        if (time() >= strtotime(dt($rdate, $endHour))) continue;
        if ($endHour > (int)$org['close_hour']) continue;

        // Restricción agregada después de que alguien entrara a la fila.
        $blackout = false;
        for ($h = $startHour; $h < $endHour; $h++) if (room_blackout_activo($roomId, $rdate, $h)) { $blackout = true; break; }
        if ($blackout) continue;

        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
        $st->execute([(int)$w['user_id']]);
        $cu = $st->fetch();
        if (!$cu || (int)$cu['org_id'] !== (int)$w['org_id'] || student_block_reason($cu)) continue;

        if (weekly_used_hours((int)$cu['id'], $org, $rdate) + $n > (int)$org['max_hours_week']) continue;

        // Todas las horas necesarias deben estar libres.
        $marks = implode(',', array_fill(0, $n, '?'));
        $st = $pdo->prepare("SELECT COUNT(*) c FROM reservation_blocks WHERE room_id = ? AND rdate = ? AND hour IN ($marks)");
        $st->execute(array_merge([$roomId, $rdate], range($startHour, $endHour - 1)));
        if ((int)$st->fetch()['c'] > 0) continue;

        $nowStr = date('Y-m-d H:i:s');
        $checkinAuto = $org['require_checkin'] ? null : $nowStr;
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO reservations (org_id, room_id, user_id, rdate, start_hour, end_hour, status, created_at, checked_in_at)
                           VALUES (?,?,?,?,?,?,'activa',?,?)")
                ->execute([(int)$org['id'], $roomId, (int)$cu['id'], $rdate, $startHour, $endHour, $nowStr, $checkinAuto]);
            $resId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO reservation_blocks (room_id, rdate, hour, reservation_id) VALUES (?,?,?,?)");
            for ($h = $startHour; $h < $endHour; $h++) $ins->execute([$roomId, $rdate, $h, $resId]);
            $pdo->prepare("UPDATE waitlist SET status = 'asignada' WHERE id = ?")->execute([(int)$w['id']]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            return; // alguien tomó el bloque en paralelo
        }

        log_activity((int)$org['id'], 'asignacion',
            "Fila de espera: se asignó {$room['name']} $startHour:00–$endHour:00 a {$cu['name']}");
        $notaCheckin = $org['require_checkin']
            ? "Recuerda confirmar tu llegada en la app durante los primeros {$org['checkin_minutes']} minutos, o el espacio se liberará de nuevo."
            : "Tu reserva ya queda confirmada; esta asociación no requiere confirmar llegada en la app.";
        if ($cu['email']) queue_email((int)$org['id'], $cu['email'],
            'MiSalaUCR — ¡Se liberó tu espacio!',
            "Hola {$cu['name']}:\n\n¡Buenas noticias! Se liberó el bloque que esperabas y la reserva ya quedó a tu nombre:\n\n" .
            "Sala: {$room['name']}\nFecha: " . date('d/m/Y', strtotime($rdate)) . "\nHorario: $startHour:00 a $endHour:00\n\n" .
            $notaCheckin);
        return;
    }
}

/* ---------- gestión de estudiantes (compartida por admin y super-admin) ---------- */

function carne_ocupado(PDO $pdo, int $orgId, string $carne, int $exceptId = 0): bool {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE org_id = ? AND carne = ? AND id <> ?");
    $st->execute([$orgId, $carne, $exceptId]);
    return (int)$st->fetch()['c'] > 0;
}

function crear_estudiante(PDO $pdo, int $orgId, string $nombre, string $carne, string $email, string $phone, string $pass): array {
    $nombre = trim($nombre); $carne = trim($carne); $email = trim($email); $phone = trim($phone); $pass = trim($pass);
    if ($nombre === '' || $carne === '') return [false, 'Nombre y carné son obligatorios.'];
    if (carne_ocupado($pdo, $orgId, $carne)) return [false, "El carné $carne ya existe."];
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) return [false, 'Teléfono inválido.'];
    if ($pass === '') $pass = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
    if (strlen($pass) < 8) return [false, 'La contraseña debe tener al menos 8 caracteres.'];
    $pdo->prepare("INSERT INTO users (org_id, role, name, carne, email, phone, password_hash, must_change, active, created_at)
                   VALUES (?,'student',?,?,?,?,?,1,1,?)")
        ->execute([$orgId, $nombre, $carne, $email ?: null, $phone ?: null,
                   password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    log_activity($orgId, 'estudiante', "Se registró el estudiante $nombre (carné $carne)");
    return [true, "Estudiante creado. Carné: $carne · Contraseña temporal: $pass (deberá cambiarla al entrar)."];
}
