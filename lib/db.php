<?php
/**
 * MiSalaUCR — Conexión a base de datos, esquema y datos iniciales.
 * Compatible con SQLite (local) y MySQL (Hostinger).
 */

function cfg(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
        date_default_timezone_set($cfg['timezone'] ?? 'America/Costa_Rica');
    }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = cfg()['db'];
    if ($c['driver'] === 'mysql') {
        $m = $c['mysql'];
        $pdo = new PDO(
            "mysql:host={$m['host']};dbname={$m['dbname']};charset=utf8mb4",
            $m['user'], $m['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } else {
        $path = $c['sqlite_path'];
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $pdo = new PDO('sqlite:' . $path, null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    db_init($pdo, $c['driver']);
    return $pdo;
}

function db_init(PDO $pdo, string $driver): void {
    $isMysql = ($driver === 'mysql');
    $PK  = $isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suf = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS organizations (
        id $PK,
        name VARCHAR(150) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        open_hour INT NOT NULL DEFAULT 7,
        close_hour INT NOT NULL DEFAULT 22,
        days_open VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
        max_blocks_session INT NOT NULL DEFAULT 2,
        max_hours_week INT NOT NULL DEFAULT 4,
        week_start INT NOT NULL DEFAULT 1,
        checkin_minutes INT NOT NULL DEFAULT 10,
        noshow_limit INT NOT NULL DEFAULT 3,
        noshow_block_days INT NOT NULL DEFAULT 7,
        booking_horizon VARCHAR(12) NOT NULL DEFAULT 'mismo_dia', -- mismo_dia|dia_siguiente|semana
        booking_release_hour INT NOT NULL DEFAULT 7,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id $PK,
        org_id INT NULL,
        role VARCHAR(10) NOT NULL, -- 'super' | 'admin' | 'student'
        name VARCHAR(150) NOT NULL,
        carne VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        password_hash VARCHAR(255) NOT NULL,
        must_change TINYINT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        noshow_count INT NOT NULL DEFAULT 0,
        blocked_until VARCHAR(19) NULL,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id $PK,
        org_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        capacity INT NOT NULL DEFAULT 6,
        status VARCHAR(15) NOT NULL DEFAULT 'disponible', -- 'disponible' | 'bloqueada'
        note VARCHAR(255) NULL
    )$suf");

    // Restricciones puntuales de sala: cierre recurrente por día de semana (weekday)
    // o cierre de una fecha exacta (bdate). Exactamente uno de los dos va lleno.
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_blackouts (
        id $PK,
        org_id INT NOT NULL,
        room_id INT NOT NULL,
        weekday INT NULL,
        bdate VARCHAR(10) NULL,
        start_hour INT NOT NULL,
        end_hour INT NOT NULL,
        label VARCHAR(150) NULL,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id $PK,
        org_id INT NOT NULL,
        room_id INT NOT NULL,
        user_id INT NOT NULL,
        rdate VARCHAR(10) NOT NULL,
        start_hour INT NOT NULL,
        end_hour INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'activa', -- activa|cancelada|completada|no_show
        checked_in_at VARCHAR(19) NULL,
        reminder_sent TINYINT NOT NULL DEFAULT 0,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    // Un bloque físico por sala/fecha/hora: el índice único impide dobles reservas
    // incluso con usuarios reservando en simultáneo.
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_blocks (
        id $PK,
        room_id INT NOT NULL,
        rdate VARCHAR(10) NOT NULL,
        hour INT NOT NULL,
        reservation_id INT NOT NULL,
        CONSTRAINT uq_block UNIQUE (room_id, rdate, hour)
    )$suf");

    $pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
        id $PK,
        org_id INT NULL,
        to_email VARCHAR(150) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pendiente', -- pendiente|enviado|error
        error VARCHAR(255) NULL,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    // Tokens de recuperación de contraseña (un solo uso, expiran solos).
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id $PK,
        user_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at VARCHAR(19) NOT NULL,
        used TINYINT NOT NULL DEFAULT 0,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    // Fila de espera por bloque exacto (sala + fecha + hora de inicio)
    $pdo->exec("CREATE TABLE IF NOT EXISTS waitlist (
        id $PK,
        org_id INT NOT NULL,
        room_id INT NOT NULL,
        rdate VARCHAR(10) NOT NULL,
        start_hour INT NOT NULL,
        n_blocks INT NOT NULL DEFAULT 1,
        user_id INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'esperando', -- esperando|asignada|cancelada
        created_at VARCHAR(19) NOT NULL
    )$suf");

    // Bitácora de actividad de la plataforma (dashboard del super-admin)
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id $PK,
        org_id INT NULL,
        kind VARCHAR(20) NOT NULL,
        description VARCHAR(255) NOT NULL,
        created_at VARCHAR(19) NOT NULL
    )$suf");

    // Migraciones suaves: columnas nuevas sobre bases ya existentes
    foreach (["ALTER TABLE organizations ADD COLUMN frozen_reason VARCHAR(255) NULL",
              "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL",
              "ALTER TABLE organizations ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'none'",
              "ALTER TABLE organizations ADD COLUMN booking_horizon VARCHAR(12) NOT NULL DEFAULT 'mismo_dia'",
              "ALTER TABLE organizations ADD COLUMN booking_release_hour INT NULL"] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* la columna ya existe */ }
    }

    // Asociaciones ya existentes: su hora de apertura de reservas empieza igual
    // a su open_hour actual (mismo comportamiento de hoy), y desde aquí queda editable aparte.
    $pdo->exec("UPDATE organizations SET booking_release_hour = open_hour WHERE booking_release_hour IS NULL");

    // Datos iniciales (solo la primera vez)
    $n = (int)$pdo->query("SELECT COUNT(*) AS c FROM organizations")->fetch()['c'];
    if ($n === 0) db_seed($pdo);
}

/** Genera una contraseña aleatoria legible (sin caracteres ambiguos). */
function seed_random_pass(int $len = 12): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
    return $out;
}

function db_seed(PDO $pdo): void {
    $now  = date('Y-m-d H:i:s');
    $seed = cfg()['seed'] ?? [];

    $pdo->prepare("INSERT INTO organizations (name, active, open_hour, close_hour, days_open,
        max_blocks_session, max_hours_week, week_start, checkin_minutes, noshow_limit, noshow_block_days, created_at)
        VALUES (?,1,7,22,'1,2,3,4,5',2,4,1,10,3,7,?)")
        ->execute(['Asociación de Estudiantes de Ingeniería Civil UCR', $now]);
    $orgId = (int)$pdo->lastInsertId();

    foreach (['Sala 1', 'Sala 2', 'Sala 3'] as $name) {
        $pdo->prepare("INSERT INTO rooms (org_id, name, capacity, status) VALUES (?,?,6,'disponible')")
            ->execute([$orgId, $name]);
    }

    // Credenciales iniciales: salen de config.local.php; si faltan, se generan.
    $generated  = [];
    $superEmail = $seed['super_email'] ?: 'super@misalaucr.local';
    $superPass  = (string)($seed['super_pass'] ?? '');
    if ($superPass === '') { $superPass = seed_random_pass(); $generated[] = "Super-admin ($superEmail): $superPass"; }

    $adminEmail = $seed['admin_email'] ?: 'admin@misalaucr.local';
    $adminPass  = (string)($seed['admin_pass'] ?? '');
    if ($adminPass === '') { $adminPass = seed_random_pass(); $generated[] = "Admin ($adminEmail): $adminPass"; }

    // Super-administrador de la plataforma
    $pdo->prepare("INSERT INTO users (org_id, role, name, email, password_hash, must_change, active, created_at)
        VALUES (NULL,'super','Super Administrador',?,?,0,1,?)")
        ->execute([$superEmail, password_hash($superPass, PASSWORD_DEFAULT), $now]);

    // Administrador de la asociación (placeholder: edítelo desde el panel de super-admin)
    $pdo->prepare("INSERT INTO users (org_id, role, name, email, password_hash, must_change, active, created_at)
        VALUES (?,'admin','Admin Ingeniería Civil',?,?,1,1,?)")
        ->execute([$orgId, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), $now]);

    // Si alguna contraseña se generó al azar, se guarda una única vez en texto.
    if ($generated) {
        $file = __DIR__ . '/../data/credenciales_iniciales.txt';
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        @file_put_contents($file,
            "MiSalaUCR — credenciales iniciales generadas automáticamente ($now).\n" .
            "Cámbielas al iniciar sesión y borre este archivo.\n\n" .
            implode("\n", $generated) . "\n");
    }
}
