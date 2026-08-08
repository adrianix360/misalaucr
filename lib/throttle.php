<?php
/**
 * MiSalaUCR — Límite de intentos para login y recuperación de contraseña.
 *
 * Evita fuerza bruta / password spraying (login.php) y spam de correos de
 * recuperación (forgot_password.php), contando intentos recientes por
 * identificador (carné/correo) e IP en la tabla `login_attempts`.
 */

require_once __DIR__ . '/db.php';

/** IP del cliente. No se confía en cabeceras (X-Forwarded-For, etc.),
 *  son falsificables sin un proxy confiable configurado delante. */
function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Revisa si un intento de tipo $kind ('login' | 'reset') está permitido
 * para este $ident e $ip. Bloquea si cualquiera de los dos ya alcanzó el
 * máximo de intentos dentro de la ventana configurada (cubre tanto spray
 * desde una sola IP contra muchos idents, como fuerza bruta contra un
 * mismo ident desde muchas IPs).
 *
 * @return array{0: bool, 1: int} [permitido, minutos de ventana usados]
 */
function throttle_check(string $kind, string $ident, string $ip): array {
    $sec = cfg()['security'];
    $max    = (int)($sec[$kind . '_max_attempts'] ?? 5);
    $window = (int)($sec[$kind . '_window_minutes'] ?? 15);

    $since = date('Y-m-d H:i:s', time() - $window * 60);

    $st = db()->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts
         WHERE kind = ? AND ident = ? AND created_at >= ?");
    $st->execute([$kind, $ident, $since]);
    $byIdent = (int)$st->fetch()['c'];

    $st = db()->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts
         WHERE kind = ? AND ip = ? AND created_at >= ?");
    $st->execute([$kind, $ip, $since]);
    $byIp = (int)$st->fetch()['c'];

    $allowed = $byIdent < $max && $byIp < $max;
    return [$allowed, $window];
}

/** Registra un intento (llamar solo cuando throttle_check() permitió seguir). */
function throttle_record(string $kind, string $ident, string $ip): void {
    db()->prepare(
        "INSERT INTO login_attempts (kind, ident, ip, created_at) VALUES (?,?,?,?)")
        ->execute([$kind, $ident, $ip, date('Y-m-d H:i:s')]);
}

/** Limpia el contador de un ident/ip tras un login exitoso. */
function throttle_clear(string $kind, string $ident, string $ip): void {
    db()->prepare("DELETE FROM login_attempts WHERE kind = ? AND (ident = ? OR ip = ?)")
        ->execute([$kind, $ident, $ip]);
}

/** Mantenimiento: borra intentos con más de 24 horas (llamar desde cron.php). */
function throttle_cleanup(): void {
    $since = date('Y-m-d H:i:s', time() - 24 * 3600);
    db()->prepare("DELETE FROM login_attempts WHERE created_at < ?")->execute([$since]);
}
