<?php
/**
 * MiSalaUCR — Fotos de perfil: validación, procesamiento y almacenamiento.
 *
 * Lo que se guarda NUNCA es el archivo que subió el estudiante, sino una imagen
 * re-codificada por GD. Ese re-encode es el control de seguridad principal:
 *
 *   - la salida son píxeles nuevos, así que cualquier payload PHP incrustado en
 *     un archivo que además es imagen válida (polyglot) desaparece;
 *   - todo el EXIF se pierde en el camino, incluidas las coordenadas GPS que
 *     traen por defecto las fotos de teléfono.
 *
 * Validar el tipo NO alcanza por sí solo: un archivo puede ser un JPEG legítimo
 * y llevar código pegado al final. Por eso el paso que importa es el de salida.
 *
 * La imagen vive en la tabla user_photos (ver db.php), no en el sistema de
 * archivos: el repositorio es público y el despliegue es automático por git, así
 * que una carpeta de subidas sería un descuido de .gitignore de distancia de
 * publicar fotos de estudiantes. En la base eso no puede pasar.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/throttle.php';

const FOTO_LADO       = 256;              // la imagen final es cuadrada, 256x256
const FOTO_CALIDAD    = 82;               // calidad JPEG de salida
const FOTO_MAX_BYTES  = 5 * 1024 * 1024;  // tamaño máximo del archivo subido
const FOTO_MAX_LADO   = 8000;             // tope duro de dimensiones (ver abajo)

/** ¿Está GD disponible? Sin ella se esconde la subida y todo sigue con iniciales. */
function foto_gd_disponible(): bool {
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

/** Convierte valores de php.ini tipo "256M" a bytes. Devuelve -1 si no hay límite. */
function foto_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024 * 1024 * 1024; break;
        case 'm': $n *= 1024 * 1024; break;
        case 'k': $n *= 1024; break;
    }
    return $n;
}

/** Memoria que todavía se puede usar en este request, en bytes (-1 = sin límite). */
function foto_memoria_libre(): int {
    $limite = foto_ini_bytes((string)ini_get('memory_limit'));
    return $limite < 0 ? -1 : max(0, $limite - memory_get_usage(true));
}

/**
 * ¿El envío superó post_max_size?
 *
 * Cuando eso pasa, PHP entrega $_POST y $_FILES COMPLETAMENTE VACÍOS y no marca
 * ningún error en $_FILES. El síntoma es que falla la verificación de CSRF y el
 * usuario ve "Sesión inválida", que no tiene nada que ver con la causa real.
 * Hay que detectarlo antes de validar el CSRF.
 */
function foto_post_excedido(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    $largo = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max   = foto_ini_bytes((string)ini_get('post_max_size'));
    return $largo > 0 && empty($_POST) && $max > 0 && $largo > $max;
}

/** Mensaje en español para los códigos de error de subida de PHP. */
function foto_error_subida(int $code): ?string {
    switch ($code) {
        case UPLOAD_ERR_OK:        return null;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'La foto pesa más de lo que permite el servidor. Probá con una más liviana.';
        case UPLOAD_ERR_PARTIAL:   return 'La subida se interrumpió a medio camino. Intentá de nuevo.';
        case UPLOAD_ERR_NO_FILE:   return 'No elegiste ninguna foto.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION: return 'El servidor no pudo guardar la foto. Avisale a tu asociación.';
        default:                   return 'No se pudo subir la foto.';
    }
}

/**
 * Valida y re-codifica la imagen recibida por formulario.
 * Se queda con lo propio de una subida (código de error, archivo realmente
 * subido, tamaño) y delega el procesamiento en foto_procesar_archivo().
 *
 * @return array{0:bool,1:string} [true, bytes JPEG] o [false, motivo]
 */
function foto_procesar(array $file): array {
    if (!foto_gd_disponible()) return [false, 'El servidor no puede procesar imágenes en este momento.'];

    if ($err = foto_error_subida((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))) return [false, $err];

    $tmp = (string)($file['tmp_name'] ?? '');
    // Sin esto, un parámetro manipulado podría apuntar a cualquier archivo del servidor.
    if ($tmp === '' || !is_uploaded_file($tmp)) return [false, 'No se recibió la foto.'];

    if (filesize($tmp) > FOTO_MAX_BYTES) {
        return [false, 'La foto no puede pesar más de ' . (int)(FOTO_MAX_BYTES / 1024 / 1024) . ' MB.'];
    }

    return foto_procesar_archivo($tmp);
}

/**
 * Valida y re-codifica la imagen que está en $ruta. Separada de foto_procesar()
 * para que se pueda probar sin una subida HTTP real.
 *
 * @return array{0:bool,1:string} [true, bytes JPEG] o [false, motivo]
 */
function foto_procesar_archivo(string $tmp): array {
    if (!foto_gd_disponible()) return [false, 'El servidor no puede procesar imágenes en este momento.'];

    // getimagesize devuelve false si no es una imagen y da el tipo REAL.
    // Nunca se mira la extensión ni $_FILES['type']: los manda el navegador.
    $info = @getimagesize($tmp);
    if ($info === false) return [false, 'Ese archivo no es una imagen.'];

    [$w, $h, $tipo] = $info;
    $permitidos = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
    if (!in_array($tipo, $permitidos, true)) return [false, 'Solo se aceptan imágenes JPG, PNG o WebP.'];
    if ($w < 1 || $h < 1) return [false, 'Esa imagen está dañada.'];

    // Bomba de descompresión: un PNG de 12000x12000 ocupa pocos cientos de KB
    // comprimido, pero GD lo decodifica a ~576 MB y tumba el proceso. Se descarta
    // ANTES de decodificar, comparando contra la memoria que de verdad queda.
    if ($w > FOTO_MAX_LADO || $h > FOTO_MAX_LADO) {
        return [false, 'La foto tiene demasiados píxeles. Probá con una versión más pequeña.'];
    }
    $libre = foto_memoria_libre();
    if ($libre >= 0 && ($w * $h * 4 + 8 * 1024 * 1024) > $libre) {
        return [false, 'La foto tiene demasiados píxeles para el servidor. Probá con una versión más pequeña.'];
    }

    switch ($tipo) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($tmp); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($tmp);  break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false; break;
        default:             $img = false;
    }
    if (!$img) return [false, 'No se pudo leer esa imagen. Probá con otra.'];

    if ($tipo === IMAGETYPE_JPEG) $img = foto_corregir_orientacion($img, $tmp);

    $w = imagesx($img);
    $h = imagesy($img);

    // Recorte cuadrado centrado y reescalado al tamaño final.
    $lado = min($w, $h);
    $sx   = (int)(($w - $lado) / 2);
    $sy   = (int)(($h - $lado) / 2);

    $dst = imagecreatetruecolor(FOTO_LADO, FOTO_LADO);
    if (!$dst) { imagedestroy($img); return [false, 'No se pudo procesar la foto.']; }
    // Fondo blanco: sin esto, la transparencia de PNG y WebP sale negra al pasar a JPEG.
    $blanco = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, FOTO_LADO, FOTO_LADO, $blanco);
    imagecopyresampled($dst, $img, 0, 0, $sx, $sy, FOTO_LADO, FOTO_LADO, $lado, $lado);
    imagedestroy($img);

    ob_start();
    $okSalida = imagejpeg($dst, null, FOTO_CALIDAD);
    $bytes = (string)ob_get_clean();
    imagedestroy($dst);

    if (!$okSalida || $bytes === '') return [false, 'No se pudo guardar la foto.'];
    return [true, $bytes];
}

/**
 * Endereza la foto según el EXIF. Sin esto las selfies de teléfono salen
 * acostadas y la gente cree que la app está rota.
 * @param resource|GdImage $img
 * @return resource|GdImage
 */
function foto_corregir_orientacion($img, string $ruta) {
    if (!function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($ruta);
    $o = (int)($exif['Orientation'] ?? 0);
    $grados = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
    if ($grados === 0) return $img;
    $rot = @imagerotate($img, $grados, 0);
    if (!$rot) return $img;
    imagedestroy($img);
    return $rot;
}

/**
 * Guarda (o reemplaza) la foto del estudiante.
 * Se hace DELETE + INSERT en transacción en vez de un upsert porque la sintaxis
 * de upsert difiere entre MySQL y SQLite, y la app corre en ambos.
 */
function foto_guardar(int $userId, string $jpeg): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM user_photos WHERE user_id = ?")->execute([$userId]);
        $st = $pdo->prepare("INSERT INTO user_photos (user_id, photo, updated_at) VALUES (?,?,?)");
        $st->bindValue(1, $userId, PDO::PARAM_INT);
        $st->bindValue(2, $jpeg, PDO::PARAM_LOB);
        $st->bindValue(3, date('Y-m-d H:i:s'));
        $st->execute();
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Elimina la foto del estudiante. Silencioso si no tenía. */
function foto_borrar(int $userId): void {
    db()->prepare("DELETE FROM user_photos WHERE user_id = ?")->execute([$userId]);
}

/** Fecha de la última foto del estudiante, o null si no tiene. No trae los bytes. */
function foto_actualizada(int $userId): ?string {
    $st = db()->prepare("SELECT updated_at FROM user_photos WHERE user_id = ?");
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ? (string)$r['updated_at'] : null;
}

/**
 * Flujo completo de subida desde un formulario: limita la frecuencia, procesa
 * y guarda. El límite reutiliza login_attempts con kind='foto'; como
 * throttle_check() cae en 5 intentos por 15 minutos cuando no hay claves de
 * configuración, no hace falta tocar config.php.
 *
 * @return array{0:bool,1:string} [ok, mensaje]
 */
function foto_subir_de_post(int $userId, ?array $file): array {
    if ($file === null) return [false, 'No se recibió la foto.'];

    $ip    = client_ip();
    $ident = 'u' . $userId;
    [$permitido] = throttle_check('foto', $ident, $ip);
    if (!$permitido) return [false, 'Probaste subir varias fotos seguidas. Esperá unos minutos.'];
    throttle_record('foto', $ident, $ip);

    [$ok, $res] = foto_procesar($file);
    if (!$ok) return [false, $res];

    foto_guardar($userId, $res);
    return [true, 'Listo, tu foto de perfil quedó guardada.'];
}
