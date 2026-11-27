<?php
/**
 * MiSalaUCR — Estadísticas de los dashboards (superadmin y reportes de admin).
 *
 * Solo LEE la base de datos. Las consultas usan únicamente substr/BETWEEN/GROUP BY
 * sobre columnas de texto ('Y-m-d' y 'Y-m-d H:i:s'), así funcionan igual en MySQL y
 * SQLite; lo demás (semanas, día de la semana, buckets) se agrega en PHP.
 * Las funciones est_render_* imprimen el HTML y acumulan las gráficas en $GLOBALS['_est_charts'];
 * est_scripts() las vuelca como JSON para assets/dashboard.js (Chart.js).
 */

require_once __DIR__ . '/rules.php';

const EST_LIMITE_CORREOS = 100;   // plan gratis de Resend: correos por día
const EST_UMBRAL_CORREOS = 80;    // "cerca del límite"

/* ============================ utilidades ============================ */

function est_dias_validos($v): int {
    $v = (int)$v;
    return in_array($v, [7, 30, 90], true) ? $v : 30;
}

/** Rango actual (últimos $dias días incluido hoy) y el período anterior de igual largo. */
function est_rango(int $dias): array {
    $hasta  = today();
    $desde  = date('Y-m-d', strtotime("$hasta -" . ($dias - 1) . ' days'));
    $pHasta = date('Y-m-d', strtotime("$desde -1 day"));
    $pDesde = date('Y-m-d', strtotime("$pHasta -" . ($dias - 1) . ' days'));
    return ['desde' => $desde, 'hasta' => $hasta, 'p_desde' => $pDesde, 'p_hasta' => $pHasta, 'dias' => $dias];
}

/** Lista de fechas Y-m-d entre dos fechas (inclusive). */
function est_fechas(string $desde, string $hasta): array {
    $out = [];
    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime("$d +1 day"))) $out[] = $d;
    return $out;
}

/** Lunes de la semana de una fecha. */
function est_lunes(string $d): string {
    return date('Y-m-d', strtotime($d . ' -' . ((int)date('N', strtotime($d)) - 1) . ' days'));
}

/** Variación porcentual vs período anterior (null si no hay base de comparación). */
function est_delta($cur, $prev): ?int {
    if ((float)$prev <= 0) return null;
    return (int)round(100 * ((float)$cur - (float)$prev) / (float)$prev);
}

/** Fecha corta d/m para etiquetas. */
function est_dm(string $ymd): string { return date('d/m', strtotime($ymd)); }

/* ============================ correos (Resend) ============================ */

function est_tipo_correo(string $subject): string {
    if (stripos($subject, 'reserva confirmada') !== false) return 'Reserva confirmada';
    if (stripos($subject, 'recordatorio') !== false)       return 'Recordatorio';
    if (stripos($subject, 'contrase') !== false)           return 'Contraseña';
    if (stripos($subject, 'horas extra') !== false)        return 'Horas extra';
    if (stripos($subject, 'horario') !== false)            return 'Horario';
    if (stripos($subject, 'apertura') !== false)           return 'Apertura especial';
    if (stripos($subject, 'anuncio') !== false)            return 'Anuncios';
    return 'Otros';
}

/**
 * Estadísticas de correos de TODA la plataforma (el límite diario de Resend es global).
 * Cuenta como "enviado" solo status='enviado'.
 */
function est_correos(PDO $pdo, int $dias): array {
    $r = est_rango($dias);
    $ini = $r['desde'];
    $fin = date('Y-m-d', strtotime($r['hasta'] . ' +1 day'));
    $hoy = today();

    $st = $pdo->prepare(
        "SELECT substr(created_at,1,10) d, substr(created_at,12,2) h, status, org_id, subject, COUNT(*) n
         FROM email_log WHERE created_at >= ? AND created_at < ?
         GROUP BY substr(created_at,1,10), substr(created_at,12,2), status, org_id, subject");
    $st->execute([$ini, $fin]);

    $porDia = array_fill_keys(est_fechas($r['desde'], $r['hasta']), 0);
    $porHora = array_fill(0, 24, 0);
    $porTipo = []; $porOrg = [];
    $estados = ['pendiente' => 0, 'error' => 0, 'omitido' => 0];
    $totEnv = 0;
    foreach ($st->fetchAll() as $row) {
        $n = (int)$row['n'];
        if ($row['status'] === 'enviado') {
            if (isset($porDia[$row['d']])) $porDia[$row['d']] += $n;
            $h = (int)$row['h']; if ($h >= 0 && $h < 24) $porHora[$h] += $n;
            $t = est_tipo_correo((string)$row['subject']);
            $porTipo[$t] = ($porTipo[$t] ?? 0) + $n;
            $k = $row['org_id'] === null ? 0 : (int)$row['org_id'];
            $porOrg[$k] = ($porOrg[$k] ?? 0) + $n;
            $totEnv += $n;
        } elseif (isset($estados[$row['status']])) {
            $estados[$row['status']] += $n;
        }
    }

    $nombres = [0 => 'Plataforma / sin organización'];
    foreach ($pdo->query("SELECT id, name FROM organizations")->fetchAll() as $o) $nombres[(int)$o['id']] = $o['name'];
    $orgs = [];
    foreach ($porOrg as $id => $n) $orgs[] = ['name' => $nombres[$id] ?? ("Org #$id"), 'n' => $n];
    usort($orgs, fn($a, $b) => $b['n'] <=> $a['n']);
    arsort($porTipo);

    $st = $pdo->prepare("SELECT COUNT(*) c FROM email_log WHERE status='enviado' AND created_at >= ?");
    $st->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $ult24 = (int)$st->fetch()['c'];

    $enviadosHoy = $porDia[$hoy] ?? 0;
    $horasTrans = (int)date('G') + (int)date('i') / 60;
    $proy = $horasTrans >= 1 ? (int)round($enviadosHoy / $horasTrans * 24) : null;

    $dias80 = 0; $dias100 = 0; $pico = ['d' => null, 'n' => 0];
    foreach ($porDia as $d => $n) {
        if ($n >= EST_UMBRAL_CORREOS) $dias80++;
        if ($n >= EST_LIMITE_CORREOS) $dias100++;
        if ($n > $pico['n']) $pico = ['d' => $d, 'n' => $n];
    }
    $maxH = max($porHora);
    $horaPico = $maxH > 0 ? (int)array_search($maxH, $porHora, true) : null;

    return [
        'rango' => $r, 'hoy' => $enviadosHoy, 'ult24' => $ult24, 'proy' => $proy,
        'por_dia' => $porDia, 'por_hora' => $porHora, 'por_tipo' => $porTipo, 'por_org' => $orgs,
        'estados' => $estados, 'total' => $totEnv, 'dias80' => $dias80, 'dias100' => $dias100,
        'promedio' => $dias > 0 ? round($totEnv / $dias, 1) : 0, 'pico' => $pico, 'hora_pico' => $horaPico,
    ];
}

/** Correos de UNA organización en el rango (para el reporte de la asociación). */
function est_correos_org(PDO $pdo, int $orgId, int $dias): array {
    $r = est_rango($dias);
    $fin = date('Y-m-d', strtotime($r['hasta'] . ' +1 day'));
    $st = $pdo->prepare("SELECT status, COUNT(*) n FROM email_log
                         WHERE org_id = ? AND created_at >= ? AND created_at < ? GROUP BY status");
    $st->execute([$orgId, $r['desde'], $fin]);
    $out = ['enviado' => 0, 'pendiente' => 0, 'error' => 0, 'omitido' => 0];
    foreach ($st->fetchAll() as $row) if (isset($out[$row['status']])) $out[$row['status']] = (int)$row['n'];
    return $out;
}

/* ============================ uso de salas ============================ */

/**
 * Estadísticas de uso. $orgId = null → toda la plataforma.
 * "Cuenta" = reservas no canceladas (activa, completada, no_show); fechas por rdate.
 */
function est_uso(PDO $pdo, ?int $orgId, int $dias): array {
    $r = est_rango($dias);
    $hoy = $r['hasta'];
    $fOrg = $orgId ? ' AND org_id = ?' : '';
    $pOrg = $orgId ? [$orgId] : [];
    $run = function (string $sql, array $params) use ($pdo) {
        $st = $pdo->prepare($sql); $st->execute($params); return $st;
    };

    $orgRow = null;
    if ($orgId) { $orgRow = $run("SELECT * FROM organizations WHERE id = ?", [$orgId])->fetch() ?: null; }
    [$wa, $wb] = week_bounds($orgRow ?: ['week_start' => 1], $hoy);

    // Totales de un período (no canceladas)
    $totales = function (string $a, string $b) use ($run, $fOrg, $pOrg): array {
        $x = $run("SELECT COUNT(*) res, COUNT(DISTINCT user_id) uniq,
                          COALESCE(SUM(end_hour - start_hour),0) h,
                          COALESCE(SUM(CASE WHEN status='no_show' THEN 1 ELSE 0 END),0) ns
                   FROM reservations WHERE rdate BETWEEN ? AND ? AND status <> 'cancelada'$fOrg",
                  array_merge([$a, $b], $pOrg))->fetch();
        return ['res' => (int)$x['res'], 'uniq' => (int)$x['uniq'], 'h' => (int)$x['h'], 'ns' => (int)$x['ns'],
                'tasa' => (int)$x['res'] > 0 ? round(100 * (int)$x['ns'] / (int)$x['res'], 1) : 0.0];
    };
    $cur = $totales($r['desde'], $r['hasta']);
    $prev = $totales($r['p_desde'], $r['p_hasta']);

    $uniqRango = function (string $a, string $b) use ($run, $fOrg, $pOrg): int {
        return (int)$run("SELECT COUNT(DISTINCT user_id) c FROM reservations
                          WHERE rdate BETWEEN ? AND ? AND status <> 'cancelada'$fOrg",
                         array_merge([$a, $b], $pOrg))->fetch()['c'];
    };
    $uniqHoy = $uniqRango($hoy, $hoy);
    $uniqSem = $uniqRango($wa, $wb);

    // Por día
    $fechas = est_fechas($r['desde'], $r['hasta']);
    $dDia = array_fill_keys($fechas, ['uniq' => 0, 'res' => 0, 'ns' => 0]);
    foreach ($run("SELECT rdate, COUNT(*) res, COUNT(DISTINCT user_id) uniq,
                          COALESCE(SUM(CASE WHEN status='no_show' THEN 1 ELSE 0 END),0) ns
                   FROM reservations WHERE rdate BETWEEN ? AND ? AND status <> 'cancelada'$fOrg GROUP BY rdate",
                  array_merge([$r['desde'], $r['hasta']], $pOrg))->fetchAll() as $x) {
        if (isset($dDia[$x['rdate']]))
            $dDia[$x['rdate']] = ['uniq' => (int)$x['uniq'], 'res' => (int)$x['res'], 'ns' => (int)$x['ns']];
    }

    // No-show por semana (lunes) — agregado en PHP
    $semNs = [];
    foreach ($dDia as $d => $v) {
        $w = est_lunes($d);
        $semNs[$w] = $semNs[$w] ?? ['res' => 0, 'ns' => 0];
        $semNs[$w]['res'] += $v['res']; $semNs[$w]['ns'] += $v['ns'];
    }

    // Estados (todas, incluidas canceladas)
    $estados = ['completada' => 0, 'cancelada' => 0, 'no_show' => 0, 'activa' => 0];
    foreach ($run("SELECT status, COUNT(*) n FROM reservations WHERE rdate BETWEEN ? AND ?$fOrg GROUP BY status",
                  array_merge([$r['desde'], $r['hasta']], $pOrg))->fetchAll() as $x)
        if (isset($estados[$x['status']])) $estados[$x['status']] = (int)$x['n'];

    // Mapa de calor día de la semana (1=lunes..7) × hora
    $heat = []; $heatMax = 0; $hMin = 24; $hMax = -1;
    foreach ($run("SELECT rdate, start_hour, end_hour, COUNT(*) n FROM reservations
                   WHERE rdate BETWEEN ? AND ? AND status <> 'cancelada'$fOrg
                   GROUP BY rdate, start_hour, end_hour", array_merge([$r['desde'], $r['hasta']], $pOrg))->fetchAll() as $x) {
        $dow = (int)date('N', strtotime($x['rdate']));
        for ($h = (int)$x['start_hour']; $h < (int)$x['end_hour']; $h++) {
            $heat[$dow][$h] = ($heat[$dow][$h] ?? 0) + (int)$x['n'];
            if ($heat[$dow][$h] > $heatMax) $heatMax = $heat[$dow][$h];
            $hMin = min($hMin, $h); $hMax = max($hMax, $h);
        }
    }
    if ($hMax < 0) { $hMin = 7; $hMax = 21; }

    // Estudiantes nuevos (por día del rango actual y total del anterior)
    $finCur = date('Y-m-d', strtotime($r['hasta'] . ' +1 day'));
    $finPrev = date('Y-m-d', strtotime($r['p_hasta'] . ' +1 day'));
    $nuevosDia = [];
    foreach ($run("SELECT substr(created_at,1,10) d, COUNT(*) n FROM users
                   WHERE role='student' AND created_at >= ? AND created_at < ?$fOrg
                   GROUP BY substr(created_at,1,10)", array_merge([$r['desde'], $finCur], $pOrg))->fetchAll() as $x)
        $nuevosDia[$x['d']] = (int)$x['n'];
    $nuevos = array_sum($nuevosDia);
    $nuevosPrev = (int)$run("SELECT COUNT(*) c FROM users WHERE role='student' AND created_at >= ? AND created_at < ?$fOrg",
                            array_merge([$r['p_desde'], $finPrev], $pOrg))->fetch()['c'];
    $nuevosSem = [];
    foreach ($fechas as $d) { $w = est_lunes($d); $nuevosSem[$w] = ($nuevosSem[$w] ?? 0) + ($nuevosDia[$d] ?? 0); }

    // Ocupación por organización
    $orgs = $orgId ? ($orgRow ? [$orgRow] : [])
                   : $pdo->query("SELECT * FROM organizations ORDER BY id")->fetchAll();
    $salasPorOrg = [];
    foreach ($pdo->query("SELECT org_id, COUNT(*) c FROM rooms GROUP BY org_id")->fetchAll() as $x)
        $salasPorOrg[(int)$x['org_id']] = (int)$x['c'];
    $horasPorOrg = [];
    foreach ($run("SELECT org_id, COALESCE(SUM(end_hour - start_hour),0) h FROM reservations
                   WHERE rdate BETWEEN ? AND ? AND status <> 'cancelada'$fOrg GROUP BY org_id",
                  array_merge([$r['desde'], $r['hasta']], $pOrg))->fetchAll() as $x)
        $horasPorOrg[(int)$x['org_id']] = (int)$x['h'];
    $ocupacion = [];
    foreach ($orgs as $o) {
        $diasAb = 0;
        foreach ($fechas as $d) if (is_open_day($o, $d)) $diasAb++;
        $cap = ($salasPorOrg[(int)$o['id']] ?? 0) * $diasAb * max(0, (int)$o['close_hour'] - (int)$o['open_hour']);
        $h = $horasPorOrg[(int)$o['id']] ?? 0;
        $ocupacion[] = ['name' => $o['name'], 'h' => $h, 'cap' => $cap, 'pct' => $cap > 0 ? min(100, round(100 * $h / $cap)) : 0];
    }
    usort($ocupacion, fn($a, $b) => $b['pct'] <=> $a['pct']);

    // Top 10 salas por horas
    $top = $run("SELECT rm.name AS sala, o.name AS org, SUM(r.end_hour - r.start_hour) h
                 FROM reservations r JOIN rooms rm ON rm.id = r.room_id JOIN organizations o ON o.id = r.org_id
                 WHERE r.rdate BETWEEN ? AND ? AND r.status <> 'cancelada'" . ($orgId ? ' AND r.org_id = ?' : '') . "
                 GROUP BY r.room_id, rm.name, o.name ORDER BY h DESC LIMIT 10",
                array_merge([$r['desde'], $r['hasta']], $pOrg))->fetchAll();

    // Tarjetas pequeñas
    $coord = (int)$run("SELECT COUNT(*) c FROM users WHERE role='student' AND is_coordinator = 1$fOrg", $pOrg)->fetch()['c'];
    $optout = (int)$run("SELECT COUNT(*) c FROM users WHERE role='student' AND email_opt_out = 1$fOrg", $pOrg)->fetch()['c'];
    $extra = (int)$run("SELECT COALESCE(SUM(hours),0) h FROM extra_hours WHERE created_at >= ? AND created_at < ?$fOrg",
                       array_merge([$r['desde'], $finCur], $pOrg))->fetch()['h'];

    return [
        'rango' => $r, 'org_id' => $orgId,
        'cur' => $cur, 'prev' => $prev,
        'uniq_hoy' => $uniqHoy, 'uniq_sem' => $uniqSem,
        'nuevos' => $nuevos, 'nuevos_prev' => $nuevosPrev,
        'por_dia' => $dDia, 'sem_ns' => $semNs, 'nuevos_sem' => $nuevosSem,
        'estados' => $estados, 'heat' => $heat, 'heat_max' => $heatMax, 'h_min' => $hMin, 'h_max' => $hMax,
        'ocupacion' => $ocupacion, 'top_salas' => $top,
        'coordinadores' => $coord, 'optout' => $optout, 'horas_extra' => $extra,
    ];
}

/* ============================ render: piezas ============================ */

function est_chart(array $spec): void { $GLOBALS['_est_charts'][] = $spec; }

/** Imprime el JSON de gráficas + los scripts (Chart.js CDN y dashboard.js). Llamar una vez al final. */
function est_scripts(): void {
    $charts = $GLOBALS['_est_charts'] ?? [];
    $ver = (int)(@filemtime(__DIR__ . '/../assets/dashboard.js') ?: 1);
    echo '<script type="application/json" id="dash-data">'
        . json_encode(['charts' => $charts], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>' . "\n";
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>' . "\n";
    echo '<script src="assets/dashboard.js?v=' . $ver . '" defer></script>' . "\n";
}

function est_canvas(string $id, string $titulo, string $sub = '', string $extra = ''): string {
    return '<div class="card dash-card ' . e($extra) . '"><h3 class="dash-h3">' . e($titulo) . '</h3>'
        . ($sub !== '' ? '<p class="mini dash-sub">' . e($sub) . '</p>' : '')
        . '<div class="dash-chart"><canvas id="' . e($id) . '" role="img" aria-label="' . e($titulo) . '"></canvas></div></div>';
}

/** Tarjeta KPI: $inverse = true si subir es malo (no-show). $delta = null → sin comparación. */
function est_kpi(string $icono, string $label, string $valor, string $tono, ?int $delta = null, bool $inverse = false, string $nota = ''): string {
    $dh = '';
    if ($delta !== null) {
        $cls = $delta === 0 ? 'eq' : (($delta > 0) !== $inverse ? 'good' : 'bad');
        $flecha = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '■');
        $dh = '<em class="dk-delta ' . $cls . '" title="Frente al período anterior de igual duración">'
            . $flecha . ' ' . abs($delta) . '%</em>';
    } elseif ($nota !== '') {
        $dh = '<em class="dk-delta eq">' . e($nota) . '</em>';
    }
    return '<div class="dk dk-' . e($tono) . '"><div class="dk-ico" aria-hidden="true">' . $icono . '</div>'
        . '<div class="dk-body"><b>' . e($valor) . '</b><span>' . e($label) . '</span>' . $dh . '</div></div>';
}

function est_mini(string $icono, string $valor, string $label): string {
    return '<div class="dash-mini"><span aria-hidden="true">' . $icono . '</span><b>' . e($valor) . '</b><small>' . e($label) . '</small></div>';
}

/** Barras horizontales en HTML/CSS puro (no dependen de Chart.js). $filas = [[etiqueta, valor, texto], ...] */
function est_barras(array $filas, int $max = 0, string $vacio = 'Sin datos en el rango.'): string {
    if (!$filas) return '<p class="mini">' . e($vacio) . '</p>';
    if ($max <= 0) foreach ($filas as $f) $max = max($max, $f[1]);
    $max = max(1, $max);
    $h = '<div class="dash-bars">';
    foreach ($filas as $f) {
        $h .= '<div class="dash-bar"><span class="dash-bar-l" title="' . e($f[0]) . '">' . e($f[0]) . '</span>'
            . '<div class="barra"><i style="width:' . round(100 * $f[1] / $max) . '%"></i></div>'
            . '<span class="dash-bar-v">' . e($f[2] ?? (string)$f[1]) . '</span></div>';
    }
    return $h . '</div>';
}

function est_heatmap(array $d): string {
    $dn = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    $h0 = $d['h_min']; $h1 = $d['h_max']; $cols = $h1 - $h0 + 1; $max = max(1, $d['heat_max']);
    $o = '<div class="dash-heat-wrap"><div class="dash-heat" style="--cols:' . $cols . '">';
    $o .= '<span class="hm-corner"></span>';
    for ($h = $h0; $h <= $h1; $h++) $o .= '<span class="hm-h">' . $h . '</span>';
    foreach ($dn as $k => $nom) {
        $o .= '<span class="hm-d">' . $nom . '</span>';
        for ($h = $h0; $h <= $h1; $h++) {
            $n = $d['heat'][$k][$h] ?? 0;
            $o .= '<span class="hm-c" style="--i:' . ($n > 0 ? round(0.12 + 0.88 * $n / $max, 2) : 0)
                . '" title="' . $nom . ' ' . $h . ':00 — ' . $n . ' reserva(s)">' . ($n > 0 ? $n : '') . '</span>';
        }
    }
    return $o . '</div></div><p class="mini">Reservas por día de la semana y hora (más intenso = más reservas).</p>';
}

/* ============================ render: uso de salas ============================ */

/**
 * @param array $d  resultado de est_uso()
 * @param bool $reducido true = versión corta para el panel de la asociación
 */
function est_render_uso(array $d, bool $reducido = false): void {
    $c = $d['cur']; $p = $d['prev']; $dias = $d['rango']['dias'];
    $fmt = fn($n) => number_format((float)$n, 0, ',', '.');

    echo '<div class="dash-kpis">';
    echo est_kpi('👤', 'Estudiantes únicos hoy', $fmt($d['uniq_hoy']), 'teal', null, false, 'reservaron hoy');
    echo est_kpi('📆', 'Únicos esta semana', $fmt($d['uniq_sem']), 'navy', null, false, 'semana en curso');
    echo est_kpi('🎓', "Únicos en $dias días", $fmt($c['uniq']), 'verde', est_delta($c['uniq'], $p['uniq']));
    echo est_kpi('⏱️', 'Horas reservadas', $fmt($c['h']) . ' h', 'amber', est_delta($c['h'], $p['h']));
    echo est_kpi('🗓️', 'Reservas', $fmt($c['res']), 'teal', est_delta($c['res'], $p['res']));
    echo est_kpi('🚫', 'Tasa de no-show', number_format($c['tasa'], 1, ',', '') . '%', 'rojo',
        est_delta($c['tasa'], $p['tasa']), true);
    echo est_kpi('✨', 'Estudiantes nuevos', $fmt($d['nuevos']), 'navy', est_delta($d['nuevos'], $d['nuevos_prev']));
    echo '</div>';

    // Línea: estudiantes únicos y reservas por día
    $labels = []; $uniq = []; $res = [];
    foreach ($d['por_dia'] as $f => $v) { $labels[] = est_dm($f); $uniq[] = $v['uniq']; $res[] = $v['res']; }
    est_chart(['id' => 'c-actividad', 'type' => 'line', 'labels' => $labels, 'datasets' => [
        ['label' => 'Estudiantes únicos', 'data' => $uniq, 'color' => 'teal', 'fill' => true],
        ['label' => 'Reservas', 'data' => $res, 'color' => 'navy'],
    ]]);

    // Estados
    $est = $d['estados'];
    est_chart(['id' => 'c-estados', 'type' => 'doughnut',
        'labels' => ['Completadas', 'Canceladas', 'No-show', 'Activas'],
        'datasets' => [['data' => [$est['completada'], $est['cancelada'], $est['no_show'], $est['activa']],
                        'colors' => ['verde', 'gris', 'rojo', 'teal']]]]);

    echo '<div class="dash-grid dash-g2">';
    echo est_canvas('c-actividad', 'Actividad diaria', 'Estudiantes únicos y reservas por día (sin canceladas)', 'dash-span2');
    echo est_canvas('c-estados', 'Estado de las reservas', "Últimos $dias días");
    echo '</div>';

    echo '<div class="dash-grid dash-g2">';
    echo '<div class="card dash-card dash-span2"><h3 class="dash-h3">Mapa de calor: día × hora</h3>' . est_heatmap($d) . '</div>';
    echo '<div class="card dash-card"><h3 class="dash-h3">Top salas por horas</h3>';
    $f = [];
    foreach ($d['top_salas'] as $s) $f[] = [$s['sala'] . ($d['org_id'] ? '' : ' · ' . $s['org']), (int)$s['h'], (int)$s['h'] . ' h'];
    echo est_barras($f) . '</div>';
    echo '</div>';

    if ($reducido) return;

    // Registros por semana
    $sl = []; $sv = [];
    foreach ($d['nuevos_sem'] as $w => $n) { $sl[] = 'Sem. ' . est_dm($w); $sv[] = $n; }
    est_chart(['id' => 'c-nuevos', 'type' => 'bar', 'labels' => $sl,
        'datasets' => [['label' => 'Estudiantes nuevos', 'data' => $sv, 'color' => 'verde']]]);
    // No-show por semana
    $nl = []; $nv = [];
    foreach ($d['sem_ns'] as $w => $v) { $nl[] = 'Sem. ' . est_dm($w); $nv[] = $v['res'] > 0 ? round(100 * $v['ns'] / $v['res'], 1) : 0; }
    est_chart(['id' => 'c-noshow', 'type' => 'line', 'labels' => $nl, 'pct' => true,
        'datasets' => [['label' => 'Tasa de no-show (%)', 'data' => $nv, 'color' => 'rojo', 'fill' => true]]]);

    echo '<div class="dash-grid dash-g2">';
    echo est_canvas('c-nuevos', 'Registros nuevos por semana', 'Estudiantes que se inscribieron (semanas desde el lunes)');
    echo est_canvas('c-noshow', 'No-show por semana', 'Porcentaje sobre reservas no canceladas');
    echo '</div>';

    echo '<div class="dash-grid dash-g2"><div class="card dash-card"><h3 class="dash-h3">Ocupación por organización</h3>';
    $f = [];
    foreach ($d['ocupacion'] as $o) $f[] = [$o['name'], (int)$o['pct'], $o['pct'] . '% · ' . $o['h'] . '/' . $o['cap'] . ' h'];
    echo est_barras($f, 100);
    echo '<p class="mini">Horas reservadas ÷ horas disponibles (salas × días abiertos × horario). Es una aproximación: no descuenta restricciones ni salas bloqueadas.</p></div>';
    echo '<div class="dash-minis">';
    echo est_mini('🧑‍🏫', (string)$d['coordinadores'], 'coordinadores');
    echo est_mini('➕', $d['horas_extra'] . ' h', "horas extra otorgadas ($dias d)");
    echo est_mini('🔕', (string)$d['optout'], 'estudiantes sin correos');
    echo '</div></div>';
}

/* ============================ render: correos ============================ */

function est_color_correos(int $n): string { return $n >= 85 ? 'rojo' : ($n >= 60 ? 'amber' : 'verde'); }

function est_render_correos(array $m): void {
    $dias = $m['rango']['dias']; $lim = EST_LIMITE_CORREOS;
    $hoy = $m['hoy']; $pct = min(100, (int)round(100 * $hoy / $lim));
    $col = est_color_correos($hoy);

    echo '<div class="dash-grid dash-gmail">';
    echo '<div class="card dash-card dash-gauge-card"><h3 class="dash-h3">Cuota de hoy</h3>';
    echo '<div class="dash-ring-box" role="img" aria-label="' . $hoy . ' de ' . $lim . ' correos hoy"><div class="dash-ring dash-ring-' . $col . '" style="--p:' . $pct . '"></div>'
        . '<div class="dash-ring-txt"><b>' . $hoy . '</b><span>de ' . $lim . ' hoy</span></div></div>';
    echo '<p class="dash-gauge-line"><b>' . $m['ult24'] . '</b> en las últimas 24 h</p>';
    echo '<p class="dash-gauge-line">Proyección hoy: <b>' . ($m['proy'] === null ? '—' : '≈ ' . $m['proy']) . '</b></p>';
    echo '<p class="mini">Resend podría contar por día UTC; aquí los días son hora local del servidor (Costa Rica). Verde &lt;60 · ámbar 60–84 · rojo ≥85.</p></div>';

    echo '<div class="dash-kpis dash-kpis-in">';
    echo est_kpi('📈', 'Promedio por día', number_format($m['promedio'], 1, ',', ''), 'teal');
    echo est_kpi('⚠️', 'Días ≥ ' . EST_UMBRAL_CORREOS, (string)$m['dias80'], 'amber', null, false, "de $dias días");
    echo est_kpi('⛔', 'Días ≥ ' . $lim, (string)$m['dias100'], 'rojo', null, false, "de $dias días");
    echo est_kpi('🏔️', 'Día pico', $m['pico']['d'] ? (string)$m['pico']['n'] : '—', 'navy', null, false,
        $m['pico']['d'] ? est_dm($m['pico']['d']) : 'sin envíos');
    echo est_kpi('🕐', 'Hora pico', $m['hora_pico'] === null ? '—' : sprintf('%02d:00', $m['hora_pico']), 'verde', null, false, 'más envíos');
    echo est_kpi('✉️', 'Enviados en el rango', (string)$m['total'], 'teal');
    echo '</div></div>';

    if ($m['dias80'] > 0) {
        $cada = round($dias / $m['dias80'], 1);
        $frase = "Te acercas al límite (≥" . EST_UMBRAL_CORREOS . ") en {$m['dias80']} de los últimos $dias días (≈ cada " . rtrim(rtrim(number_format($cada, 1, ',', ''), '0'), ',') . " días).";
    } else {
        $frase = "No has llegado a " . EST_UMBRAL_CORREOS . " correos en ningún día de los últimos $dias días.";
    }
    echo '<p class="dash-frase">' . e($frase) . '</p>';

    $dl = []; $dv = [];
    foreach ($m['por_dia'] as $f => $n) { $dl[] = est_dm($f); $dv[] = $n; }
    est_chart(['id' => 'c-correos-dia', 'type' => 'bar', 'labels' => $dl, 'threshold' => true,
        'datasets' => [['label' => 'Enviados', 'data' => $dv, 'color' => 'verde']],
        'lines' => [['label' => 'Aviso (' . EST_UMBRAL_CORREOS . ')', 'value' => EST_UMBRAL_CORREOS, 'color' => 'amber'],
                    ['label' => 'Límite (' . $lim . ')', 'value' => $lim, 'color' => 'rojo']]]);
    $hl = []; foreach (range(0, 23) as $h) $hl[] = sprintf('%02d', $h);
    est_chart(['id' => 'c-correos-hora', 'type' => 'bar', 'labels' => $hl,
        'datasets' => [['label' => 'Enviados', 'data' => array_values($m['por_hora']), 'color' => 'teal']]]);

    echo '<div class="dash-grid dash-g2">';
    echo est_canvas('c-correos-dia', 'Correos enviados por día', "Últimos $dias días, con líneas en " . EST_UMBRAL_CORREOS . ' y ' . $lim, 'dash-span2');
    echo est_canvas('c-correos-hora', 'Envíos por hora del día', 'Suma del rango (hora local 0–23)');
    echo '</div>';

    echo '<div class="dash-grid dash-g3">';
    echo '<div class="card dash-card"><h3 class="dash-h3">Por tipo de correo</h3>';
    $f = []; foreach ($m['por_tipo'] as $t => $n) $f[] = [$t, $n];
    echo est_barras($f) . '</div>';
    echo '<div class="card dash-card"><h3 class="dash-h3">Por organización</h3>';
    $f = []; foreach ($m['por_org'] as $o) $f[] = [$o['name'], $o['n']];
    echo est_barras($f) . '</div>';
    echo '<div class="card dash-card"><h3 class="dash-h3">Otros estados</h3><div class="dash-minis dash-minis-col">';
    echo est_mini('⏳', (string)$m['estados']['pendiente'], 'pendientes');
    echo est_mini('❌', (string)$m['estados']['error'], 'con error');
    echo est_mini('🙈', (string)$m['estados']['omitido'], 'omitidos (baja)');
    echo '</div></div></div>';
}

/** Selector de rango (y organización si $orgs ≠ null). $extra = parámetros ocultos. */
function est_filtros(string $accion, array $hidden, int $dias, ?array $orgs = null, int $orgSel = 0): void {
    echo '<form method="get" action="' . e($accion) . '" class="card dash-filtros">';
    foreach ($hidden as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    echo '<div><label>Rango</label><select name="dias">';
    foreach ([7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días'] as $k => $l)
        echo '<option value="' . $k . '"' . ($k === $dias ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></div>';
    if ($orgs !== null) {
        echo '<div><label>Organización (uso de salas)</label><select name="org"><option value="0">Todas</option>';
        foreach ($orgs as $o)
            echo '<option value="' . (int)$o['id'] . '"' . ((int)$o['id'] === $orgSel ? ' selected' : '') . '>' . e($o['name']) . '</option>';
        echo '</select></div>';
    }
    echo '<button class="btn">Aplicar</button></form>';
}
