<?php
/** MiSalaUCR — Sesiones, login y protección de páginas. */

require_once __DIR__ . '/db.php';

function boot(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    cfg(); // fija zona horaria
    session_name('misalaucr');
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf']) . '">';
}

function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        http_response_code(403);
        exit('Sesión inválida. Recargue la página e intente de nuevo.');
    }
}

function current_user(): ?array {
    boot();
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false) {
        $st = db()->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch() ?: null;
    }
    return $u;
}

/**
 * Intenta iniciar sesión con carné o correo.
 * Devuelve el usuario, null si las credenciales fallan, o un arreglo con
 * '_suspendida' => true si las credenciales son correctas pero la
 * organización está congelada (no se abre sesión en ese caso).
 */
function attempt_login(string $ident, string $password): ?array {
    $ident = trim($ident);
    $st = db()->prepare(
        "SELECT u.*, o.active AS org_active, o.frozen_reason
         FROM users u
         LEFT JOIN organizations o ON o.id = u.org_id
         WHERE u.active = 1 AND (u.carne = ? OR u.email = ?)
         ORDER BY u.id LIMIT 1");
    $st->execute([$ident, $ident]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        if ($u['org_id'] !== null && !(int)$u['org_active']) {
            return ['_suspendida' => true, 'frozen_reason' => $u['frozen_reason']];
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        return $u;
    }
    return null;
}

function logout(): void {
    boot();
    $_SESSION = [];
    session_destroy();
}

/** Exige sesión iniciada con alguno de los roles dados; redirige si no. */
function require_role(array $roles): array {
    boot();
    $u = current_user();
    if (!$u) { header('Location: login.php'); exit; }

    // Si la organización fue congelada, la sesión existente pierde acceso de inmediato.
    if ($u['role'] !== 'super' && $u['org_id']) {
        $st = db()->prepare("SELECT active, frozen_reason FROM organizations WHERE id = ?");
        $st->execute([$u['org_id']]);
        $o = $st->fetch();
        if (!$o || !(int)$o['active']) {
            $reason = trim((string)($o['frozen_reason'] ?? ''));
            logout();
            header('Location: suspendida.php' . ($reason !== '' ? '?r=' . urlencode($reason) : ''));
            exit;
        }
    }

    if ($u['must_change'] && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') {
        header('Location: password.php'); exit;
    }
    if (!in_array($u['role'], $roles, true)) {
        header('Location: ' . home_for($u)); exit;
    }
    return $u;
}

function home_for(array $u): string {
    return ['super' => 'superadmin.php', 'admin' => 'admin.php'][$u['role']] ?? 'student.php';
}

function org_of(array $u): ?array {
    if (!$u['org_id']) return null;
    $st = db()->prepare("SELECT * FROM organizations WHERE id = ?");
    $st->execute([$u['org_id']]);
    return $st->fetch() ?: null;
}
