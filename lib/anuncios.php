<?php
/**
 * MiSalaUCR — Anuncios (banners) de las asociaciones.
 *
 * Es un "plugin" por asociación: organizations.anuncios_enabled lo enciende el
 * super-admin y nace en 0, así que desplegar este código no cambia nada de lo
 * que ven los estudiantes hasta que alguien lo active a propósito.
 *
 * Las imágenes siguen el mismo criterio que las fotos de perfil (ver
 * lib/foto.php): se re-codifican con GD y viven en la base, no en una carpeta
 * pública del repositorio. La validación reutiliza los helpers de foto.php.
 *
 * Vencimiento: no hay cron. Un anuncio se ve solo mientras
 * starts_at <= ahora < ends_at, así que "se baja solo" en la misma consulta.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/foto.php';
require_once __DIR__ . '/rules.php';

const ANUNCIO_ANCHO        = 1200;  // la imagen final es 1200x450 (proporción 8:3)
const ANUNCIO_ALTO         = 450;
const ANUNCIO_CALIDAD      = 84;
const ANUNCIO_MIN_ANCHO    = 600;   // por debajo se ve borrosa al estirarla
const ANUNCIO_MAX_ACTIVOS  = 5;     // vigentes o programados a la vez
const ANUNCIO_MAX_TOTAL    = 20;    // guardados por asociación (cuida el tamaño de la BD)
const ANUNCIO_SEG_MIN      = 3;
const ANUNCIO_SEG_MAX      = 15;
const ANUNCIO_VISTA_CADA   = 3600;  // una vista por estudiante y anuncio cada hora

/** ¿La asociación tiene el plugin encendido? */
function anuncios_activos_en(array $org): bool {
    return (int)($org['anuncios_enabled'] ?? 0) === 1;
}

/** Segundos de rotación del carrusel, siempre dentro del rango permitido. */
function anuncios_segundos(array $org): int {
    $s = (int)($org['anuncios_segundos'] ?? 6);
    return max(ANUNCIO_SEG_MIN, min(ANUNCIO_SEG_MAX, $s));
}

/** Anuncios que un estudiante debe ver ahora mismo, en el orden de la asociación. */
function anuncios_vigentes(array $org): array {
    if (!anuncios_activos_en($org)) return [];
    $now = date('Y-m-d H:i:s');
    // Esto corre en student.php, la página que más se usa: si algo falla aquí,
    // se pierde el banner, nunca la página de reservas.
    try {
        $st = db()->prepare(
            "SELECT a.*, i.updated_at AS img_ts FROM anuncios a
             JOIN anuncio_imagenes i ON i.anuncio_id = a.id
             WHERE a.org_id = ? AND a.active = 1 AND a.retirado = 0
               AND a.starts_at <= ? AND (a.ends_at IS NULL OR a.ends_at > ?)
             ORDER BY a.orden, a.id
             LIMIT " . ANUNCIO_MAX_ACTIVOS);
        $st->execute([(int)$org['id'], $now, $now]);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Todos los anuncios de la asociación (panel del admin). No trae los bytes. */
function anuncios_de_org(int $orgId): array {
    $st = db()->prepare(
        "SELECT a.*, i.updated_at AS img_ts FROM anuncios a
         LEFT JOIN anuncio_imagenes i ON i.anuncio_id = a.id
         WHERE a.org_id = ? ORDER BY a.orden, a.id");
    $st->execute([$orgId]);
    return $st->fetchAll();
}

/** Un anuncio de la asociación, o null si no existe o es de otra. */
function anuncio_de_org(int $id, int $orgId): ?array {
    $st = db()->prepare("SELECT * FROM anuncios WHERE id = ? AND org_id = ?");
    $st->execute([$id, $orgId]);
    return $st->fetch() ?: null;
}

/** Estado legible: activo | programado | vencido | pausado | retirado. */
function anuncio_estado(array $a, ?string $now = null): string {
    $now = $now ?? date('Y-m-d H:i:s');
    if ((int)$a['retirado'] === 1) return 'retirado';
    if ((int)$a['active'] !== 1)  return 'pausado';
    if ($a['ends_at'] !== null && $a['ends_at'] !== '' && $a['ends_at'] <= $now) return 'vencido';
    if ($a['starts_at'] > $now) return 'programado';
    return 'activo';
}

/** Clase de .pill (ya existentes en style.css) para cada estado. */
function anuncio_estado_pill(string $estado): string {
    return ['activo' => 'completada', 'programado' => 'activa', 'pausado' => 'cancelada',
            'vencido' => 'cancelada', 'retirado' => 'no_show'][$estado] ?? 'cancelada';
}

/** URL de la imagen; la versión rompe la caché del navegador al reemplazarla. */
function anuncio_img_url(array $a): string {
    return 'anuncio.php?img=' . (int)$a['id'] . '&v=' . substr(md5((string)($a['img_ts'] ?? '')), 0, 8);
}

/**
 * Cuántos anuncios ocupan cupo (activos o programados, sin vencer) en la
 * asociación, sin contar $exceptoId.
 */
function anuncios_ocupando_cupo(int $orgId, int $exceptoId = 0): int {
    $now = date('Y-m-d H:i:s');
    $st = db()->prepare(
        "SELECT COUNT(*) c FROM anuncios
         WHERE org_id = ? AND id <> ? AND active = 1 AND retirado = 0
           AND (ends_at IS NULL OR ends_at > ?)");
    $st->execute([$orgId, $exceptoId, $now]);
    return (int)$st->fetch()['c'];
}

/* ---------------- validación de campos ---------------- */

/** Convierte el valor de un <input type="datetime-local"> a 'Y-m-d H:i:s'. Null si vacío o inválido. */
function anuncio_fecha_de_input(?string $v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $v);
    return $d ? $d->format('Y-m-d H:i:00') : null;
}

/** Valor para precargar un <input type="datetime-local">. */
function anuncio_fecha_a_input(?string $v): string {
    return ($v === null || $v === '') ? '' : date('Y-m-d\TH:i', strtotime($v));
}

/**
 * Valida el enlace. Solo http(s): nada de javascript:, data: ni rutas raras,
 * porque anuncio.php redirige a este valor.
 * @return array{0:bool,1:?string} [ok, url normalizada o null]
 */
function anuncio_validar_link(string $v): array {
    $v = trim($v);
    if ($v === '') return [true, null];
    // Trae esquema (ftp:, mailto:, javascript:...) y no es http(s): fuera.
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $v) && !preg_match('~^https?://~i', $v)) return [false, null];
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    if (strlen($v) > 500 || !filter_var($v, FILTER_VALIDATE_URL)) return [false, null];
    $esquema = strtolower((string)parse_url($v, PHP_URL_SCHEME));
    if (!in_array($esquema, ['http', 'https'], true) || !parse_url($v, PHP_URL_HOST)) return [false, null];
    return [true, $v];
}

/**
 * Lee y valida los campos comunes de crear/editar.
 * @return array{0:bool,1:string|array} [false, motivo] o [true, campos]
 */
function anuncio_campos_de_post(array $p): array {
    $titulo = trim((string)($p['titulo'] ?? ''));
    if ($titulo === '') return [false, 'Escriba un título (se usa como descripción de la imagen).'];
    $titulo = mb_substr($titulo, 0, 120);

    [$okLink, $link] = anuncio_validar_link((string)($p['link_url'] ?? ''));
    if (!$okLink) return [false, 'El enlace no es válido. Use una dirección web completa (https://...).'];

    $ini = anuncio_fecha_de_input($p['starts_at'] ?? '') ?? date('Y-m-d H:i:00');
    $finCrudo = trim((string)($p['ends_at'] ?? ''));
    $fin = anuncio_fecha_de_input($finCrudo);
    if ($finCrudo !== '' && $fin === null) return [false, 'La fecha de fin no es válida.'];
    if ($fin !== null && $fin <= $ini) return [false, 'La fecha de fin debe ser posterior a la de inicio.'];

    return [true, ['titulo' => $titulo, 'link_url' => $link, 'starts_at' => $ini, 'ends_at' => $fin]];
}

/* ---------------- imagen ---------------- */

/**
 * Valida y re-codifica la imagen subida a un banner JPEG de 1200x450,
 * recortado al centro (como object-fit: cover).
 * @return array{0:bool,1:string} [true, bytes JPEG] o [false, motivo]
 */
function anuncio_procesar_subida(?array $file): array {
    if (!foto_gd_disponible()) return [false, 'El servidor no puede procesar imágenes en este momento.'];
    if ($file === null) return [false, 'No se recibió la imagen.'];

    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_NO_FILE) return [false, 'No eligió ninguna imagen.'];
    if ($code !== UPLOAD_ERR_OK) {
        return [false, str_replace('foto', 'imagen', (string)foto_error_subida($code))];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [false, 'No se recibió la imagen.'];
    if (filesize($tmp) > FOTO_MAX_BYTES) {
        return [false, 'La imagen no puede pesar más de ' . (int)(FOTO_MAX_BYTES / 1024 / 1024) . ' MB.'];
    }
    return anuncio_procesar_archivo($tmp);
}

/** Separada de la subida para poder probarla sin HTTP (igual que foto_procesar_archivo). */
function anuncio_procesar_archivo(string $tmp): array {
    if (!foto_gd_disponible()) return [false, 'El servidor no puede procesar imágenes en este momento.'];

    $info = @getimagesize($tmp);
    if ($info === false) return [false, 'Ese archivo no es una imagen.'];
    [$w, $h, $tipo] = $info;
    if (!in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return [false, 'Solo se aceptan imágenes JPG, PNG o WebP.'];
    }
    if ($w < 1 || $h < 1) return [false, 'Esa imagen está dañada.'];
    // Mismo freno contra bombas de descompresión que en foto.php.
    if ($w > FOTO_MAX_LADO || $h > FOTO_MAX_LADO) {
        return [false, 'La imagen tiene demasiados píxeles. Use una versión más pequeña.'];
    }
    $libre = foto_memoria_libre();
    if ($libre >= 0 && ($w * $h * 4 + 16 * 1024 * 1024) > $libre) {
        return [false, 'La imagen tiene demasiados píxeles para el servidor. Use una versión más pequeña.'];
    }

    switch ($tipo) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($tmp); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($tmp);  break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false; break;
        default:             $img = false;
    }
    if (!$img) return [false, 'No se pudo leer esa imagen. Pruebe con otra.'];
    if ($tipo === IMAGETYPE_JPEG) $img = foto_corregir_orientacion($img, $tmp);

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < ANUNCIO_MIN_ANCHO) {
        imagedestroy($img);
        return [false, 'La imagen es muy pequeña: debe medir al menos ' . ANUNCIO_MIN_ANCHO . ' px de ancho (ideal ' . ANUNCIO_ANCHO . '×' . ANUNCIO_ALTO . ').'];
    }

    // Recorte centrado a 8:3.
    $ratio = ANUNCIO_ANCHO / ANUNCIO_ALTO;
    if ($w / $h > $ratio) { $ch = $h; $cw = (int)round($h * $ratio); }
    else                  { $cw = $w; $ch = (int)round($w / $ratio); }
    $sx = (int)(($w - $cw) / 2);
    $sy = (int)(($h - $ch) / 2);

    $dst = imagecreatetruecolor(ANUNCIO_ANCHO, ANUNCIO_ALTO);
    if (!$dst) { imagedestroy($img); return [false, 'No se pudo procesar la imagen.']; }
    $blanco = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, ANUNCIO_ANCHO, ANUNCIO_ALTO, $blanco);
    imagecopyresampled($dst, $img, 0, 0, $sx, $sy, ANUNCIO_ANCHO, ANUNCIO_ALTO, $cw, $ch);
    imagedestroy($img);

    ob_start();
    $ok = imagejpeg($dst, null, ANUNCIO_CALIDAD);
    $bytes = (string)ob_get_clean();
    imagedestroy($dst);
    if (!$ok || $bytes === '') return [false, 'No se pudo guardar la imagen.'];
    return [true, $bytes];
}

/** Guarda o reemplaza la imagen (DELETE + INSERT: el upsert difiere entre MySQL y SQLite). */
function anuncio_guardar_imagen(int $anuncioId, string $jpeg): void {
    $pdo = db();
    $pdo->prepare("DELETE FROM anuncio_imagenes WHERE anuncio_id = ?")->execute([$anuncioId]);
    $st = $pdo->prepare("INSERT INTO anuncio_imagenes (anuncio_id, imagen, updated_at) VALUES (?,?,?)");
    $st->bindValue(1, $anuncioId, PDO::PARAM_INT);
    $st->bindValue(2, $jpeg, PDO::PARAM_LOB);
    $st->bindValue(3, date('Y-m-d H:i:s'));
    $st->execute();
}

/* ---------------- acciones del admin de la asociación ---------------- */

/** @return array{0:bool,1:string} */
function anuncio_crear(int $orgId, array $post, ?array $file): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) c FROM anuncios WHERE org_id = ?");
    $st->execute([$orgId]);
    if ((int)$st->fetch()['c'] >= ANUNCIO_MAX_TOTAL) {
        return [false, 'Llegó al máximo de ' . ANUNCIO_MAX_TOTAL . ' anuncios guardados. Elimine alguno viejo para subir otro.'];
    }
    if (anuncios_ocupando_cupo($orgId) >= ANUNCIO_MAX_ACTIVOS) {
        return [false, 'Ya tiene ' . ANUNCIO_MAX_ACTIVOS . ' anuncios activos o programados. Pause o elimine uno primero.'];
    }

    [$ok, $c] = anuncio_campos_de_post($post);
    if (!$ok) return [false, $c];

    [$okImg, $jpeg] = anuncio_procesar_subida($file);
    if (!$okImg) return [false, $jpeg];

    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("SELECT COALESCE(MAX(orden), 0) m FROM anuncios WHERE org_id = ?");
    $st->execute([$orgId]);
    $orden = (int)$st->fetch()['m'] + 1;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO anuncios (org_id, titulo, link_url, starts_at, ends_at, orden, active, retirado,
                                             vistas, clics, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,1,0,0,0,?,?)")
            ->execute([$orgId, $c['titulo'], $c['link_url'], $c['starts_at'], $c['ends_at'], $orden, $now, $now]);
        $id = (int)$pdo->lastInsertId();
        anuncio_guardar_imagen($id, $jpeg);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [false, 'No se pudo guardar el anuncio. Intente de nuevo.'];
    }
    log_activity($orgId, 'anuncio', 'Anuncio publicado: ' . $c['titulo']);
    return [true, $c['starts_at'] > $now
        ? 'Anuncio guardado. Se mostrará desde el ' . date('d/m/Y H:i', strtotime($c['starts_at'])) . '.'
        : 'Anuncio publicado: los estudiantes ya lo ven.'];
}

/** @return array{0:bool,1:string} */
function anuncio_editar(int $orgId, int $id, array $post, ?array $file): array {
    $a = anuncio_de_org($id, $orgId);
    if (!$a) return [false, 'Anuncio no encontrado.'];

    [$ok, $c] = anuncio_campos_de_post($post);
    if (!$ok) return [false, $c];

    // Si con las fechas nuevas vuelve a ocupar cupo, hay que respetar el tope.
    $now = date('Y-m-d H:i:s');
    $ocupara = (int)$a['active'] === 1 && (int)$a['retirado'] === 0 && ($c['ends_at'] === null || $c['ends_at'] > $now);
    if ($ocupara && anuncios_ocupando_cupo($orgId, $id) >= ANUNCIO_MAX_ACTIVOS) {
        return [false, 'Ya hay ' . ANUNCIO_MAX_ACTIVOS . ' anuncios activos o programados. Pause otro antes de extender este.'];
    }

    $jpeg = null;
    if ($file !== null && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$okImg, $res] = anuncio_procesar_subida($file);
        if (!$okImg) return [false, $res];
        $jpeg = $res;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE anuncios SET titulo=?, link_url=?, starts_at=?, ends_at=?, updated_at=?
                       WHERE id=? AND org_id=?")
            ->execute([$c['titulo'], $c['link_url'], $c['starts_at'], $c['ends_at'], $now, $id, $orgId]);
        if ($jpeg !== null) anuncio_guardar_imagen($id, $jpeg);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [false, 'No se pudo guardar el cambio. Intente de nuevo.'];
    }
    log_activity($orgId, 'anuncio', 'Anuncio editado: ' . $c['titulo']);
    return [true, 'Anuncio actualizado.'];
}

/** Pausa o reactiva. Un anuncio retirado por la plataforma no se puede reactivar. */
function anuncio_toggle(int $orgId, int $id): array {
    $a = anuncio_de_org($id, $orgId);
    if (!$a) return [false, 'Anuncio no encontrado.'];
    if ((int)$a['retirado'] === 1) return [false, 'Este anuncio fue retirado por la administración de MiSalaUCR.'];

    $activar = (int)$a['active'] !== 1;
    if ($activar) {
        $now = date('Y-m-d H:i:s');
        $vencido = $a['ends_at'] !== null && $a['ends_at'] <= $now;
        if ($vencido) return [false, 'Ese anuncio ya venció. Edítelo y cambie la fecha de fin para volver a mostrarlo.'];
        if (anuncios_ocupando_cupo($orgId, $id) >= ANUNCIO_MAX_ACTIVOS) {
            return [false, 'Ya hay ' . ANUNCIO_MAX_ACTIVOS . ' anuncios activos o programados. Pause otro primero.'];
        }
    }
    db()->prepare("UPDATE anuncios SET active=?, updated_at=? WHERE id=? AND org_id=?")
        ->execute([$activar ? 1 : 0, date('Y-m-d H:i:s'), $id, $orgId]);
    log_activity($orgId, 'anuncio', ($activar ? 'Anuncio reactivado: ' : 'Anuncio pausado: ') . $a['titulo']);
    return [true, $activar ? 'Anuncio reactivado.' : 'Anuncio pausado: deja de verse de inmediato.'];
}

/** Sube (-1) o baja (+1) un anuncio en el orden y renumera la lista. */
function anuncio_mover(int $orgId, int $id, int $dir): array {
    $ids = array_map(fn($r) => (int)$r['id'], anuncios_de_org($orgId));
    $i = array_search($id, $ids, true);
    if ($i === false) return [false, 'Anuncio no encontrado.'];
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($j < 0 || $j >= count($ids)) return [true, 'El anuncio ya está en esa posición.'];
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

    $pdo = db();
    $st = $pdo->prepare("UPDATE anuncios SET orden=? WHERE id=? AND org_id=?");
    $pdo->beginTransaction();
    foreach ($ids as $pos => $aid) $st->execute([$pos + 1, $aid, $orgId]);
    $pdo->commit();
    return [true, 'Orden actualizado.'];
}

function anuncio_borrar(int $orgId, int $id): array {
    $a = anuncio_de_org($id, $orgId);
    if (!$a) return [false, 'Anuncio no encontrado.'];
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM anuncio_imagenes WHERE anuncio_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM anuncios WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
    $pdo->commit();
    log_activity($orgId, 'anuncio', 'Anuncio eliminado: ' . $a['titulo']);
    return [true, 'Anuncio eliminado.'];
}

function anuncios_set_segundos(int $orgId, int $seg): array {
    $seg = max(ANUNCIO_SEG_MIN, min(ANUNCIO_SEG_MAX, $seg));
    db()->prepare("UPDATE organizations SET anuncios_segundos=? WHERE id=?")->execute([$seg, $orgId]);
    return [true, "El carrusel ahora cambia cada $seg segundos."];
}

/* ---------------- vista (estudiante y vista previa) ---------------- */

/**
 * Imprime el carrusel. Sin anuncios no imprime NADA: el espacio colapsa y la
 * página queda exactamente como antes.
 *
 * $medir = true solo en la vista real del estudiante: los clics pasan por
 * anuncio.php?go= y las vistas se reportan. En la vista previa del admin los
 * enlaces van directo y no se cuenta nada.
 */
function anuncios_render(array $ads, array $org, bool $medir): void {
    if (!$ads) return;
    $n   = count($ads);
    $seg = anuncios_segundos($org);
    ?>
<section class="anuncios" aria-roledescription="carrusel" aria-label="Anuncios de <?= e($org['name']) ?>"
         data-seg="<?= $seg ?>" data-key="msu-anuncio-<?= (int)$org['id'] ?>"
         <?php if ($medir): ?>data-vista="anuncio.php" data-csrf="<?= e($_SESSION['csrf'] ?? '') ?>"<?php endif; ?>>
  <div class="anuncios-pista" tabindex="0">
    <?php foreach ($ads as $i => $a):
        $img = '<img src="' . e(anuncio_img_url($a)) . '" alt="' . e($a['titulo']) . '" width="' . ANUNCIO_ANCHO
             . '" height="' . ANUNCIO_ALTO . '"' . ($i === 0 ? '' : ' loading="lazy"') . ' draggable="false">';
        $href = null;
        if (!empty($a['link_url'])) $href = $medir ? 'anuncio.php?go=' . (int)$a['id'] : $a['link_url'];
    ?>
    <div class="anuncio" role="group" aria-roledescription="anuncio" aria-label="<?= $i + 1 ?> de <?= $n ?>" data-id="<?= (int)$a['id'] ?>">
      <?php if ($href): ?>
        <a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= $img ?></a>
      <?php else: ?>
        <?= $img ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <span class="anuncios-etq" aria-hidden="true">Anuncio</span>
  <?php if ($n > 1): ?>
    <button type="button" class="anuncios-flecha prev" aria-label="Anuncio anterior">‹</button>
    <button type="button" class="anuncios-flecha next" aria-label="Anuncio siguiente">›</button>
    <div class="anuncios-puntos">
      <?php for ($i = 0; $i < $n; $i++): ?>
        <button type="button" aria-label="Ver anuncio <?= $i + 1 ?>"<?= $i === 0 ? ' class="on"' : '' ?>></button>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</section>
<?php anuncios_script();
}

/** Incluye assets/anuncios.js una sola vez por página. */
function anuncios_script(): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $f = __DIR__ . '/../assets/anuncios.js';
    $v = is_file($f) ? filemtime($f) : 0;
    echo '<script src="assets/anuncios.js?v=' . $v . '" defer></script>';
}
