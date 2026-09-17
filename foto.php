<?php
/**
 * MiSalaUCR — Entrega la foto de perfil de un estudiante.
 *
 * Exige sesión y que quien mira sea de la MISMA asociación que el dueño de la
 * foto (el super-admin ve todas). Ese control es la razón por la que las fotos
 * viven en la base y no en una carpeta pública: un archivo servido por Apache
 * quedaría accesible para cualquiera que adivinara la URL.
 *
 * Solo escribe la imagen: ninguna otra salida, ni una línea en blanco.
 */
require_once __DIR__ . '/lib/auth.php';

boot();

// Se usa current_user() y no require_role() a propósito: require_role redirige
// a login.php o password.php, y una redirección a HTML dentro de una <img> deja
// el navegador mostrando un ícono roto sin explicación. Aquí un 404 es más honesto.
$u = current_user();
if (!$u) { http_response_code(404); exit; }

$uid = (int)($_GET['u'] ?? 0);
if ($uid <= 0) { http_response_code(404); exit; }

$st = db()->prepare(
    "SELECT p.photo, p.updated_at, us.org_id
     FROM user_photos p
     JOIN users us ON us.id = p.user_id
     WHERE p.user_id = ?");
$st->execute([$uid]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit; }

// 404 y no 403: un 403 confirmaría que esa persona existe y tiene foto.
if ($u['role'] !== 'super' && (int)$row['org_id'] !== (int)$u['org_id']) {
    http_response_code(404);
    exit;
}

$bytes = (string)$row['photo'];
$etag  = '"' . md5($uid . '|' . (string)$row['updated_at'] . '|' . strlen($bytes)) . '"';

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/jpeg');
// La imagen siempre se re-codifica a JPEG, pero igual se le prohíbe al navegador
// adivinar otro tipo por si algún día entra un archivo por otra vía.
header('X-Content-Type-Options: nosniff');
// Privado: es contenido autorizado, no debe quedar en cachés compartidas.
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('Content-Length: ' . strlen($bytes));

echo $bytes;
