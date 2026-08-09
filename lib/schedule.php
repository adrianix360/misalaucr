<?php
/** MiSalaUCR — Horario de atención de cada asociación. */

require_once __DIR__ . '/db.php';

/** Nombres de días ISO (1=lunes … 7=domingo), misma convención que organizations.days_open. */
function horario_dias(): array {
    return [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
            5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
}

/** Decodifica una columna JSON; si viene vacía o corrupta devuelve lista vacía. */
function _schedule_json(?string $raw): array {
    $v = json_decode($raw ?? '', true);
    return is_array($v) ? $v : [];
}

/** Horario de una organización, o null si aún no configuró ninguno. */
function get_org_schedule(int $orgId): ?array {
    $st = db()->prepare("SELECT * FROM org_schedules WHERE org_id = ?");
    $st->execute([$orgId]);
    $row = $st->fetch();
    if (!$row) return null;

    return [
        'id'            => (int)$row['id'],
        'org_id'        => (int)$row['org_id'],
        'title'         => (string)$row['title'],
        'primary_color' => (string)$row['primary_color'],
        'text_color'    => (string)$row['text_color'],
        'slots'         => _schedule_json($row['slots']),
        'exceptions'    => _schedule_json($row['exceptions']),
        'updated_at'    => (string)$row['updated_at'],
    ];
}

/** Normaliza y valida datos crudos del formulario. Devuelve [array $limpio, ?string $error]. */
function schedule_sanitize(array $data): array {
    $error = null;

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') $title = 'Horario de atención';
    $title = mb_substr($title, 0, 150);

    $primary = (string)($data['primary_color'] ?? '');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) $primary = '#F4C430';
    $text = (string)($data['text_color'] ?? '');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $text)) $text = '#1A1A1A';

    // Franjas horarias. Una franja mal formada NO se descarta en silencio: se
    // reporta el error indicando la fila, porque descartarla haría que el
    // horario se guardara "con éxito" pero vacío, y la asociación seguiría
    // mostrando su horario operativo viejo sin enterarse.
    $slots = [];
    $rawSlots = isset($data['slots']) && is_array($data['slots']) ? $data['slots'] : [];
    foreach ($rawSlots as $i => $s) {
        $fila = (int)$i + 1;
        if (!is_array($s)) { $error = $error ?? "La franja #$fila no es válida."; continue; }
        $start = trim((string)($s['start'] ?? ''));
        $end   = trim((string)($s['end'] ?? ''));
        $hhmm  = '/^([01]\d|2[0-3]):[0-5]\d$/';

        if (!preg_match($hhmm, $start) || !preg_match($hhmm, $end)) {
            $error = $error ?? "Indique la hora de inicio y de fin de la franja #$fila.";
            continue;
        }
        // Comparar 'HH:MM' como texto es válido: el formato es de ancho fijo.
        if ($start >= $end) {
            $error = $error ?? "En la franja #$fila la hora de inicio debe ser anterior a la de fin.";
            continue;
        }

        $days = [];
        $rawDays = isset($s['days']) && is_array($s['days']) ? $s['days'] : [];
        foreach ($rawDays as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 7) $days[] = $d;
        }
        $days = array_values(array_unique($days));
        sort($days);
        if (!$days) {
            $error = $error ?? "Marque al menos un día en la franja #$fila.";
            continue;
        }

        $slots[] = ['start' => $start, 'end' => $end, 'days' => $days];
    }
    if (count($slots) > 20) {
        $error = $error ?? 'No se pueden publicar más de 20 franjas.';
        $slots = array_slice($slots, 0, 20);
    }

    // Excepciones: días sueltos sin atención (feriados, cierres puntuales).
    $exceptions = [];
    $rawExc = isset($data['exceptions']) && is_array($data['exceptions']) ? $data['exceptions'] : [];
    foreach ($rawExc as $x) {
        if (!is_array($x)) continue;
        $date = trim((string)($x['date'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) continue;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) continue;

        $label = trim((string)($x['label'] ?? ''));
        $label = mb_substr($label, 0, 120);
        if ($label === '') $label = 'Sin atención';
        $exceptions[] = ['date' => $date, 'label' => $label];
    }
    usort($exceptions, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $exceptions = array_slice($exceptions, 0, 30);

    $limpio = [
        'title'         => $title,
        'primary_color' => $primary,
        'text_color'    => $text,
        'slots'         => $slots,
        'exceptions'    => $exceptions,
    ];
    return [$limpio, $error];
}

/** Guarda (INSERT o UPDATE, una fila por organización). Devuelve [bool $ok, string $msg]. */
function save_org_schedule(int $orgId, array $data): array {
    [$limpio, $error] = schedule_sanitize($data);
    if ($error !== null) return [false, $error];

    $pdo   = db();
    $slots = json_encode($limpio['slots'], JSON_UNESCAPED_UNICODE);
    $excs  = json_encode($limpio['exceptions'], JSON_UNESCAPED_UNICODE);
    $now   = date('Y-m-d H:i:s');

    // UPSERT a mano: ON DUPLICATE KEY UPDATE es de MySQL y rompería en SQLite.
    $st = $pdo->prepare("SELECT id FROM org_schedules WHERE org_id = ?");
    $st->execute([$orgId]);
    $row = $st->fetch();

    if ($row) {
        $pdo->prepare("UPDATE org_schedules SET title = ?, primary_color = ?, text_color = ?,
                       slots = ?, exceptions = ?, updated_at = ? WHERE id = ?")
            ->execute([$limpio['title'], $limpio['primary_color'], $limpio['text_color'],
                       $slots, $excs, $now, (int)$row['id']]);
    } else {
        $pdo->prepare("INSERT INTO org_schedules (org_id, title, primary_color, text_color,
                       slots, exceptions, updated_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([$orgId, $limpio['title'], $limpio['primary_color'], $limpio['text_color'],
                       $slots, $excs, $now]);
    }
    return [true, 'Horario guardado.'];
}

/** Hasta qué hora sigue vigente la apertura manual extendida ("abrir por X
 *  tiempo"), o null si no hay ninguna activa. Gana sobre horario y excepciones. */
function org_override_until(array $org, ?string $now = null): ?string {
    $hasta = trim((string)($org['open_override_until'] ?? ''));
    if ($hasta === '') return null;
    $ts = strtotime($now ?? date('Y-m-d H:i:s'));
    return $ts <= strtotime($hasta) ? $hasta : null;
}

/** ¿La asociación está atendiendo en este momento? */
function is_org_open_now(array $org, ?array $schedule = null, ?string $now = null): bool {
    if (org_override_until($org, $now) !== null) return true;

    $ts    = strtotime($now ?? date('Y-m-d H:i:s'));
    $hoy   = date('Y-m-d', $ts);
    $dia   = (int)date('N', $ts);
    $hhmm  = date('H:i', $ts);

    // Sin horario configurado: se conserva el comportamiento de siempre
    // (días de days_open y rango [open_hour, close_hour)).
    if ($schedule === null || empty($schedule['slots'])) {
        $dias = array_map('intval', explode(',', (string)($org['days_open'] ?? '')));
        if (!in_array($dia, $dias, true)) return false;
        $hora = (int)date('G', $ts);
        return $hora >= (int)($org['open_hour'] ?? 0) && $hora < (int)($org['close_hour'] ?? 0);
    }

    // Una excepción cierra el día completo.
    foreach ($schedule['exceptions'] ?? [] as $x) {
        if (isset($x['date']) && $x['date'] === $hoy) return false;
    }

    foreach ($schedule['slots'] as $s) {
        $days = isset($s['days']) && is_array($s['days']) ? $s['days'] : [];
        if (!in_array($dia, array_map('intval', $days), true)) continue;
        if ($hhmm >= (string)$s['start'] && $hhmm < (string)$s['end']) return true;
    }
    return false;
}

/** Todas las asociaciones activas de la plataforma con su estado ahora mismo. */
function list_open_organizations(): array {
    try {
        $pdo  = db();
        $orgs = $pdo->query("SELECT * FROM organizations WHERE active = 1")->fetchAll();
        // Todos los horarios de una vez: esto corre en cada carga de página.
        $horarios = [];
        foreach ($pdo->query("SELECT * FROM org_schedules")->fetchAll() as $r) {
            $horarios[(int)$r['org_id']] = [
                'slots'      => _schedule_json($r['slots']),
                'exceptions' => _schedule_json($r['exceptions']),
            ];
        }
    } catch (PDOException $e) {
        return []; // se usa en login.php: si algo falla, la página no debe caerse
    }

    $out = [];
    foreach ($orgs as $org) {
        $sch = $horarios[(int)$org['id']] ?? null;
        $out[] = [
            'id'   => (int)$org['id'],
            'name' => (string)$org['name'],
            'open' => is_org_open_now($org, $sch),
        ];
    }
    // Abiertas primero; dentro de cada grupo, alfabético.
    usort($out, function ($a, $b) {
        if ($a['open'] !== $b['open']) return $a['open'] ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/** Excepciones de hoy en adelante, ordenadas. */
function schedule_upcoming_exceptions(?array $schedule): array {
    if ($schedule === null) return [];
    $hoy = date('Y-m-d');
    $out = [];
    foreach ($schedule['exceptions'] ?? [] as $x) {
        if (isset($x['date']) && $x['date'] >= $hoy) $out[] = $x;
    }
    usort($out, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    return $out;
}

/** Pastilla desplegable "Abiertas ahora (N/total)". HTML puro, sin JavaScript. */
function render_open_orgs_pill(): string {
    $orgs = list_open_organizations();
    if (!$orgs) return '';

    $total = count($orgs);
    $abiertas = 0;
    foreach ($orgs as $o) { if ($o['open']) $abiertas++; }

    // Dos grupos (abiertas primero, ya vienen ordenadas así por list_open_organizations()).
    $listaAbiertas = '';
    $listaCerradas = '';
    foreach ($orgs as $o) {
        $nom = htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8');
        if ($o['open']) {
            $listaAbiertas .= "<li class=\"abierta\"><span class=\"punto abierta\"></span>"
                . "<span class=\"nom\">$nom</span><span class=\"est\">Abierta</span></li>";
        } else {
            $listaCerradas .= "<li class=\"cerrada\"><span class=\"punto cerrada\"></span>"
                . "<span class=\"nom\">$nom</span><span class=\"est\">Cerrada</span></li>";
        }
    }

    // Barra de progreso: un segmento por asociación, encendido = abierta.
    $barras = '';
    for ($i = 0; $i < $total; $i++) {
        $barras .= '<i class="' . ($i < $abiertas ? 'on' : '') . '"></i>';
    }

    $grupoAbiertas = $listaAbiertas !== ''
        ? "<div class=\"pastilla-abiertas__rotulo\">Abiertas</div><ul class=\"pastilla-abiertas__lista\">$listaAbiertas</ul>"
        : '';
    $grupoCerradas = $listaCerradas !== ''
        ? "<div class=\"pastilla-abiertas__rotulo\">Cerradas</div><ul class=\"pastilla-abiertas__lista\">$listaCerradas</ul>"
        : '';

    // El punto del resumen refleja si hay alguna abierta (verde) o ninguna (gris).
    $puntoCls = $abiertas > 0 ? 'abierta' : 'cerrada';

    return "<details class=\"pastilla-abiertas\">"
         . "<summary>"
         .   "<span class=\"punto $puntoCls\"></span>"
         .   "<span class=\"pastilla-abiertas__rot\">Abiertas ahora</span>"
         .   "<span class=\"pastilla-abiertas__cuenta\">$abiertas/$total</span>"
         .   "<span class=\"pastilla-abiertas__flecha\">&#9662;</span>"
         . "</summary>"
         . "<div class=\"pastilla-abiertas__panel\">"
         .   "<div class=\"pastilla-abiertas__barras\">$barras</div>"
         .   $grupoAbiertas
         .   $grupoCerradas
         . "</div>"
         . "</details>";
}

/**
 * Agenda por día de las franjas de un horario (reemplaza la cuadrícula
 * hora×día): un renglón por día usado, con sus rangos horarios como
 * "pills". El día de hoy se resalta para que se ubique sin cruzar tabla.
 */
function render_schedule_agenda(array $slots, string $colorFondo, string $colorTexto): string {
    if (!$slots) return '';
    $dias = horario_dias();
    $hoy  = (int)date('N');

    // Agrupa los rangos por día, ordenados por hora de inicio dentro del día.
    $porDia = [];
    foreach ($slots as $s) {
        $ini = (string)($s['start'] ?? '');
        $fin = (string)($s['end'] ?? '');
        foreach ((array)($s['days'] ?? []) as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 7) $porDia[$d][] = ['ini' => $ini, 'fin' => $fin];
        }
    }
    ksort($porDia);

    $html = '<div class="agenda-horario">';
    foreach ($porDia as $d => $rangos) {
        usort($rangos, function ($a, $b) { return strcmp($a['ini'], $b['ini']); });
        $esHoy = $d === $hoy;
        $pills = '';
        foreach ($rangos as $r) {
            $pills .= '<span class="pill-hora" style="background:' . e($colorFondo) . ';color:' . e($colorTexto) . '">'
                    . e($r['ini']) . '–' . e($r['fin']) . '</span>';
        }
        $html .= '<div class="agenda-dia' . ($esHoy ? ' hoy' : '') . '">'
                . '<span class="agenda-dia__nombre">' . e($dias[$d])
                .   ($esHoy ? ' <span class="agenda-dia__etiqueta">Hoy</span>' : '') . '</span>'
                . '<span class="agenda-dia__rangos">' . $pills . '</span>'
                . '</div>';
    }
    $html .= '</div>';
    return $html;
}
