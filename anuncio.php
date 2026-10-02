<?php
/**
 * MiSalaUCR — Anuncios de las asociaciones.
 *
 *   GET  anuncio.php?img=ID   entrega la imagen (mismo control que foto.php:
 *                             sesión y misma asociación; el super-admin ve todas).
 *   GET  anuncio.php?go=ID    cuenta el clic y redirige al enlace guardado.
 *   POST anuncio.php vista=ID cuenta una vista (lo manda assets/anuncios.js).
 *
 * Solo cuentan vistas y clics de estudiantes, para que las pruebas del admin
 * no inflen las métricas de su propia asociación.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/anuncios.php';

boot();

// current_user() y no require_role(): una redirección a HTML dentro de una
// <img> o de un beacon no le sirve a nadie. Un 404 es más honesto.
$u = current_user();
if (!$u) { http_response_code(404); exit; }
$esSuper = $u['role'] === 'super';

/* ---------- imagen ---------- */
if (isset($_GET['img'])) {
    // Nada más de esta petición toca la sesión: se libera el bloqueo para que
    // las imágenes del carrusel no se carguen de una en una.
    session_write_close();

    $st = db()->prepare(
        "SELECT i.imagen, i.updated_at, a.org_id, a.active, a.retirado, a.starts_at, a.ends_at, o.anuncios_enabled
         FROM anuncio_imagenes i
         JOIN anuncios a ON a.id = i.anuncio_id
         JOIN organizations o ON o.id = a.org_id
         WHERE i.anuncio_id = ?");
    $st->execute([(int)$_GET['img']]);
    $row = $st->fetch();
    if (!$row || (!$esSuper && (int)$row['org_id'] !== (int)$u['org_id'])) { http_response_code(404); exit; }
    // El estudiante solo ve lo que está publicado: ni programados (adelantarse a
    // un anuncio) ni retirados por moderación. El admin ve todos los suyos.
    if ($u['role'] === 'student' && ((int)$row['anuncios_enabled'] !== 1 || anuncio_estado($row) !== 'activo')) {
        http_response_code(404); exit;
    }

    $bytes = (string)$row['imagen'];
    $etag  = '"' . md5((int)$_GET['img'] . '|' . (string)$row['updated_at'] . '|' . strlen($bytes)) . '"';
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }

    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/** Anuncio de la asociación del usuario, o null. */
function anuncio_del_usuario(array $u, int $id): ?array {
    $st = db()->prepare("SELECT a.*, o.anuncios_enabled FROM anuncios a
                         JOIN organizations o ON o.id = a.org_id WHERE a.id = ?");
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) return null;
    if ($u['role'] !== 'super' && (int)$a['org_id'] !== (int)$u['org_id']) return null;
    return $a;
}

/** ¿Este evento debe contarse? Solo estudiantes, anuncio vigente y sin repetir dentro de $cada segundos. */
function anuncio_debe_contar(array $u, array $a, string $tipo, int $cada): bool {
    if ($u['role'] !== 'student') return false;
    if ((int)$a['anuncios_enabled'] !== 1 || anuncio_estado($a) !== 'activo') return false;
    $k = (int)$a['id'];
    $ult = (int)($_SESSION['anuncios_' . $tipo][$k] ?? 0);
    if (time() - $ult < $cada) return false;
    $_SESSION['anuncios_' . $tipo][$k] = time();
    return true;
}

/* ---------- clic ---------- */
if (isset($_GET['go'])) {
    $a = anuncio_del_usuario($u, (int)$_GET['go']);
    // Solo se redirige al enlace GUARDADO (validado como http/https al crearlo),
    // nunca a algo que venga en la URL: así esto no es un redirector abierto.
    [$okLink, $destino] = $a ? anuncio_validar_link((string)$a['link_url']) : [false, null];
    if (!$a || !$okLink || $destino === null) { header('Location: ' . home_for($u)); exit; }

    if (anuncio_debe_contar($u, $a, 'clics', 10)) {
        db()->prepare("UPDATE anuncios SET clics = clics + 1 WHERE id = ?")->execute([(int)$a['id']]);
    }
    session_write_close();
    header('Location: ' . $destino, true, 302);
    exit;
}

/* ---------- vista ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vista'])) {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(403); exit; }
    $a = anuncio_del_usuario($u, (int)$_POST['vista']);
    if ($a && anuncio_debe_contar($u, $a, 'vistas', ANUNCIO_VISTA_CADA)) {
        db()->prepare("UPDATE anuncios SET vistas = vistas + 1 WHERE id = ?")->execute([(int)$a['id']]);
    }
    http_response_code(204);
    exit;
}

http_response_code(404);
