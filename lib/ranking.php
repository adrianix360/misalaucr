<?php
/**
 * MiSalaUCR — Ranking mensual de estudiantes por asociación.
 *
 * MÉTRICA: horas reservadas dentro del mes natural, contando los estados
 * 'activa', 'completada' y 'no_show' — el mismo criterio que weekly_used_hours()
 * en rules.php, para que al estudiante le cuadren las cuentas entre su saldo
 * semanal y su posición en el podio.
 *
 * VISIBILIDAD: el ranking se calcula sobre TODOS los estudiantes activos de la
 * asociación. El podio público omite a quienes se ocultaron solos
 * (ranking_opt_out) o fueron excluidos por el admin (ranking_excluded), y
 * renumera las filas visibles 1..N para que no queden huecos. La posición
 * dentro del conjunto completo se conserva en 'posicion', de modo que el
 * estudiante oculto sigue viendo la suya en privado.
 *
 * RENDIMIENTO: consulta viva, sin tabla de snapshot. Con el techo de horas por
 * semana que ya impone rules.php, un mes de una asociación son unos pocos miles
 * de filas, y los índices idx_res_org_date / idx_res_user_date (ver db.php) lo
 * resuelven en milisegundos. Si algún día deja de alcanzar, basta cambiar el
 * interior de ranking_org() por la lectura de un snapshot: ninguna vista
 * consulta la base de datos por su cuenta.
 */

require_once __DIR__ . '/db.php';

/** Estados de reserva que suman horas al ranking. */
function ranking_estados(): array {
    return ['activa', 'completada', 'no_show'];
}

/** ¿La asociación tiene el ranking encendido? */
function ranking_activo(?array $org): bool {
    return $org !== null && (int)($org['ranking_enabled'] ?? 0) === 1;
}

/**
 * Periodo del ranking: un mes natural.
 * $ym en formato 'YYYY-MM'; por defecto, el mes en curso.
 *
 * @return array{0:string,1:string,2:string,3:string} [desde, hasta, etiqueta, ym]
 */
function ranking_periodo(?string $ym = null): array {
    if ($ym === null || !preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $ts = strtotime($ym . '-01');
    if ($ts === false) {
        $ym = date('Y-m');
        $ts = strtotime($ym . '-01');
    }
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
    return [
        date('Y-m-01', $ts),
        date('Y-m-t', $ts),
        $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts),
        date('Y-m', $ts),
    ];
}

/**
 * Ranking completo de la asociación en el rango dado, ya ordenado y numerado.
 * Incluye a los ocultos (marcados con 'oculto' => true); filtrarlos es tarea de
 * ranking_visibles(), para que el admin y el propio interesado puedan verlos.
 *
 * Cada fila trae: user_id, name, carne, nombre_corto, iniciales, color,
 * tiene_foto, foto_ts, horas, sesiones, completadas, noshows, asistencia,
 * fav_room, fav_room_id, oculto, opt_out, excluido, posicion.
 */
function ranking_org(int $orgId, string $desde, string $hasta): array {
    $pdo     = db();
    $estados = ranking_estados();
    $marks   = implode(',', array_fill(0, count($estados), '?'));

    // Horas y conteos por estudiante. El LEFT JOIN a user_photos selecciona
    // únicamente updated_at: los bytes de la foto NUNCA entran en esta consulta.
    // Todas las columnas no agregadas van en el GROUP BY porque MySQL 5.7+ trae
    // ONLY_FULL_GROUP_BY activo por defecto.
    $st = $pdo->prepare(
        "SELECT r.user_id, u.name, u.carne, u.ranking_opt_out, u.ranking_excluded,
                p.updated_at AS foto_ts,
                SUM(r.end_hour - r.start_hour) AS horas,
                COUNT(*) AS sesiones,
                SUM(CASE WHEN r.status = 'completada' THEN 1 ELSE 0 END) AS completadas,
                SUM(CASE WHEN r.status = 'no_show' THEN 1 ELSE 0 END) AS noshows,
                MIN(r.created_at) AS primera
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN user_photos p ON p.user_id = u.id
         WHERE r.org_id = ? AND r.rdate BETWEEN ? AND ?
           AND r.status IN ($marks)
           AND u.active = 1 AND u.role = 'student'
         GROUP BY r.user_id, u.name, u.carne, u.ranking_opt_out, u.ranking_excluded, p.updated_at");
    $st->execute(array_merge([$orgId, $desde, $hasta], $estados));
    $filas = $st->fetchAll();
    if (!$filas) return [];

    $fav = ranking_salas_favoritas($orgId, $desde, $hasta);

    foreach ($filas as &$f) {
        $f['user_id']     = (int)$f['user_id'];
        $f['horas']       = (int)$f['horas'];
        $f['sesiones']    = (int)$f['sesiones'];
        $f['completadas'] = (int)$f['completadas'];
        $f['noshows']     = (int)$f['noshows'];
        $f['opt_out']     = (int)$f['ranking_opt_out'] === 1;
        $f['excluido']    = (int)$f['ranking_excluded'] === 1;
        $f['oculto']      = $f['opt_out'] || $f['excluido'];
        $f['tiene_foto']  = $f['foto_ts'] !== null;

        $f['fav_room_id'] = $fav[$f['user_id']]['room_id'] ?? null;
        $f['fav_room']    = $fav[$f['user_id']]['room_name'] ?? null;

        // % de asistencia: informativo, NO entra en el orden del ranking.
        // Las reservas 'activa' (aún por ocurrir) no cuentan en la base.
        $base = $f['completadas'] + $f['noshows'];
        $f['asistencia'] = $base > 0 ? (int)round(100 * $f['completadas'] / $base) : null;

        $f['nombre_corto'] = ranking_nombre_corto((string)$f['name']);
        $f['iniciales']    = ranking_iniciales((string)$f['name']);
        $f['color']        = ranking_color((string)$f['name']);
    }
    unset($f);

    // Desempate determinista, para que la posición no baile entre recargas:
    // más horas → menos inasistencias → quien empezó antes → id.
    usort($filas, function (array $a, array $b): int {
        if ($a['horas']   !== $b['horas'])   return $b['horas']   <=> $a['horas'];
        if ($a['noshows'] !== $b['noshows']) return $a['noshows'] <=> $b['noshows'];
        $c = strcmp((string)$a['primera'], (string)$b['primera']);
        if ($c !== 0) return $c;
        return $a['user_id'] <=> $b['user_id'];
    });

    $pos = 0;
    foreach ($filas as &$f) $f['posicion'] = ++$pos;
    unset($f);

    return $filas;
}

/**
 * Sala con más horas de cada estudiante en el rango: [user_id => [room_id, room_name]].
 * Empate por horas → la sala de menor id, para que el resultado sea estable.
 */
function ranking_salas_favoritas(int $orgId, string $desde, string $hasta): array {
    $estados = ranking_estados();
    $marks   = implode(',', array_fill(0, count($estados), '?'));

    $st = db()->prepare(
        "SELECT r.user_id, r.room_id, rm.name AS room_name,
                SUM(r.end_hour - r.start_hour) AS h
         FROM reservations r
         JOIN rooms rm ON rm.id = r.room_id
         JOIN users u ON u.id = r.user_id
         WHERE r.org_id = ? AND r.rdate BETWEEN ? AND ?
           AND r.status IN ($marks)
           AND u.active = 1 AND u.role = 'student'
         GROUP BY r.user_id, r.room_id, rm.name
         ORDER BY r.user_id, h DESC, r.room_id");
    $st->execute(array_merge([$orgId, $desde, $hasta], $estados));

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $uid = (int)$r['user_id'];
        if (isset($out[$uid])) continue; // el ORDER BY ya puso la favorita de primera
        $out[$uid] = ['room_id' => (int)$r['room_id'], 'room_name' => (string)$r['room_name']];
    }
    return $out;
}

/**
 * Filas que se muestran en público: sin ocultos y renumeradas 1..N sin huecos.
 * La posición pública queda en 'posicion_publica'; 'posicion' conserva la del
 * conjunto completo.
 */
function ranking_visibles(array $filas): array {
    $out = [];
    foreach ($filas as $f) if (empty($f['oculto'])) $out[] = $f;
    $pos = 0;
    foreach ($out as &$f) $f['posicion_publica'] = ++$pos;
    unset($f);
    return $out;
}

/** Fila del estudiante dentro del ranking, o null si no reservó en el periodo. */
function ranking_mi_fila(int $userId, array $filas): ?array {
    foreach ($filas as $f) if ((int)$f['user_id'] === $userId) return $f;
    return null;
}

/**
 * Cuánto le falta al estudiante para alcanzar a quien tiene justo encima.
 * Devuelve ['horas' => int, 'posicion' => int] o null si va de primero o no
 * aparece en el ranking. Si el empate en horas se resolvió por desempate, se
 * reporta 1 hora: es lo mínimo que de verdad lo pondría por delante.
 */
function ranking_faltan_para_subir(array $filas, int $userId): ?array {
    foreach ($filas as $i => $f) {
        if ((int)$f['user_id'] !== $userId) continue;
        if ($i === 0) return null;
        $arriba = $filas[$i - 1];
        return [
            'horas'    => max(1, (int)$arriba['horas'] - (int)$f['horas']),
            'posicion' => (int)$arriba['posicion'],
        ];
    }
    return null;
}

/** "Ana Mora Pérez" → "Ana Mora". En vistas de estudiante nunca se muestra el carné. */
function ranking_nombre_corto(string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$partes) return '—';
    return count($partes) === 1 ? $partes[0] : $partes[0] . ' ' . $partes[1];
}

/** Iniciales para el avatar cuando el estudiante no tiene foto: "AM". */
function ranking_iniciales(string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$partes) return '?';
    $ini = mb_strtoupper(mb_substr($partes[0], 0, 1));
    if (count($partes) > 1) $ini .= mb_strtoupper(mb_substr($partes[1], 0, 1));
    return $ini;
}

/** Color de fondo del avatar sin foto: determinista y dentro de la paleta de marca. */
function ranking_color(string $nombre): string {
    $paleta = ['#123a5e', '#0d7f8f', '#0e9c86', '#8a5a2b', '#5b4b8a', '#a8452f', '#2f6f4f'];
    $i = crc32(mb_strtolower(trim($nombre))) % count($paleta);
    if ($i < 0) $i += count($paleta); // crc32 puede dar negativo en PHP de 32 bits
    return $paleta[$i];
}
