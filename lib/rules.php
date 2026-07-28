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

/* ---------- procesos automáticos (se ejecutan en cada visita y vía cron.php) ---------- */

function process_automatic(): void {
    $pdo = db();
    $now = date('Y-m-d H:i:s');

    // 1) No-shows: reservas activas sin check-in cuya ventana ya venció.
    $rows = $pdo->query(
        "SELECT r.*, o.checkin_minutes, o.noshow_limit, o.noshow_block_days
         FROM reservations r JOIN organizations o ON o.id = r.org_id
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
        }
    }

    // 2) Completar reservas con check-in cuyo bloque ya terminó.
    $pdo->prepare("UPDATE reservations SET status = 'completada'
                   WHERE status = 'activa' AND checked_in_at IS NOT NULL
                     AND (rdate < ? OR (rdate = ? AND end_hour <= ?))")
        ->execute([today(), today(), (int)date('G')]);

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
        if ($r['email']) queue_email((int)$r['org_id'], $r['email'],
            'MiSalaUCR — Recordatorio de tu reserva',
            "Hola {$r['uname']}:\n\nTe recordamos tu reserva de hoy en {$r['room_name']} de " .
            sprintf('%d:00 a %d:00', $r['start_hour'], $r['end_hour']) .
            ".\n\nRecuerda confirmar tu llegada en la app durante los primeros 10 minutos, o el espacio se liberará.");
    }
}

/* ---------- consultas ---------- */

/** Horas ya usadas por el estudiante en la semana de $date (activas + completadas + no-shows). */
function weekly_used_hours(int $userId, array $org, string $date): int {
    [$a, $b] = week_bounds($org, $date);
    $st = db()->prepare(
        "SELECT COALESCE(SUM(end_hour - start_hour),0) AS h FROM reservations
         WHERE user_id = ? AND rdate BETWEEN ? AND ? AND status IN ('activa','completada','no_show')");
    $st->execute([$userId, $a, $b]);
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

/* ---------- acciones ---------- */

/**
 * Intenta crear una reserva HOY. $nBlocks = 1 o 2 bloques consecutivos.
 * Devuelve [true, mensaje] o [false, motivo].
 */
function try_reserve(array $u, array $org, int $roomId, int $startHour, int $nBlocks): array {
    $pdo  = db();
    $date = today();
    $now  = time();

    if ((int)$u['org_id'] !== (int)$org['id']) return [false, 'No perteneces a esta organización.'];
    if ($msg = student_block_reason($u)) return [false, $msg];
    if (!$org['active']) return [false, 'La organización no está activa.'];
    if (!is_open_day($org, $date)) return [false, 'Hoy las salas no están en operación (lunes a viernes).'];
    if ($now < strtotime(dt($date, (int)$org['open_hour']))) {
        return [false, 'Las reservas del día se abren a las ' . (int)$org['open_hour'] . ':00 a.m.'];
    }

    $nBlocks = max(1, min($nBlocks, (int)$org['max_blocks_session']));
    $endHour = $startHour + $nBlocks;
    if ($startHour < (int)$org['open_hour'] || $endHour > (int)$org['close_hour']) {
        return [false, 'Horario fuera del rango operativo.'];
    }

    // Solo se reserva el mismo día y el bloque no debe haber vencido
    // (se permite tomar el bloque en curso durante sus primeros minutos de check-in).
    $graceEnd = strtotime(dt($date, $startHour)) + 60 * (int)$org['checkin_minutes'];
    if ($now >= $graceEnd) return [false, 'Ese bloque ya pasó. Elige un bloque más tarde.'];

    // Sala disponible y de la misma organización
    $st = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND org_id = ?");
    $st->execute([$roomId, (int)$org['id']]);
    $room = $st->fetch();
    if (!$room) return [false, 'Sala no encontrada.'];
    if ($room['status'] !== 'disponible') return [false, 'La sala está bloqueada por mantenimiento.'];

    // Límite semanal
    $used = weekly_used_hours((int)$u['id'], $org, $date);
    $left = (int)$org['max_hours_week'] - $used;
    if ($nBlocks > $left) {
        return [false, $left <= 0
            ? 'Ya usaste tus ' . $org['max_hours_week'] . ' horas de esta semana.'
            : "Solo te queda $left hora(s) esta semana."];
    }

    // Crear reserva + bloques en una transacción; el índice único evita choques simultáneos.
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO reservations (org_id, room_id, user_id, rdate, start_hour, end_hour, status, created_at)
                       VALUES (?,?,?,?,?,?,'activa',?)")
            ->execute([(int)$org['id'], $roomId, (int)$u['id'], $date, $startHour, $endHour, date('Y-m-d H:i:s')]);
        $resId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO reservation_blocks (room_id, rdate, hour, reservation_id) VALUES (?,?,?,?)");
        for ($h = $startHour; $h < $endHour; $h++) $ins->execute([$roomId, $date, $h, $resId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [false, 'Alguien reservó ese bloque justo antes que tú. Elige otro.'];
    }

    if ($u['email']) queue_email((int)$org['id'], $u['email'],
        'MiSalaUCR — Reserva confirmada',
        "Hola {$u['name']}:\n\nTu reserva quedó confirmada:\n\nSala: {$room['name']}\nFecha: " .
        date('d/m/Y', strtotime($date)) . "\nHorario: $startHour:00 a $endHour:00\n\n" .
        "Al iniciar tu bloque tendrás {$org['checkin_minutes']} minutos para confirmar tu llegada en la app; " .
        "si no lo haces, el espacio se libera y cuenta como inasistencia.");

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
        if (strtotime(dt($r['rdate'], (int)$r['start_hour'])) <= time() && $r['checked_in_at']) {
            return [false, 'La reserva ya inició.'];
        }
    }

    $pdo->prepare("UPDATE reservations SET status = 'cancelada' WHERE id = ?")->execute([$resId]);
    $pdo->prepare("DELETE FROM reservation_blocks WHERE reservation_id = ?")->execute([$resId]);
    return [true, 'Reserva cancelada. El bloque quedó libre para otros.'];
}

/** Check-in dentro de la ventana permitida. */
function do_checkin(array $u, int $resId): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT r.*, o.checkin_minutes FROM reservations r
                         JOIN organizations o ON o.id = r.org_id WHERE r.id = ? AND r.user_id = ?");
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
    return [true, '¡Llegada confirmada! Disfruta tu sesión de estudio.'];
}
