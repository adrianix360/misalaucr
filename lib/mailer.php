<?php
/**
 * MiSalaUCR — Correos (Resend).
 * Sin API key: los correos quedan como 'pendiente' en la bitácora (Admin → Correos).
 * Con API key en config.php: se envían de inmediato vía https://resend.com
 */

require_once __DIR__ . '/db.php';

function queue_email(?int $orgId, string $to, string $subject, string $body): void {
    if (!$to) return;
    $pdo = db();
    $pdo->prepare("INSERT INTO email_log (org_id, to_email, subject, body, status, created_at)
                   VALUES (?,?,?,?,'pendiente',?)")
        ->execute([$orgId, $to, $subject, $body, date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();

    $key = trim(cfg()['resend_api_key'] ?? '');
    if ($key === '') return; // preparado, se enviará cuando exista API key

    [$ok, $err] = resend_send($to, $subject, $body);
    $pdo->prepare("UPDATE email_log SET status = ?, error = ? WHERE id = ?")
        ->execute([$ok ? 'enviado' : 'error', $err, $id]);
}

function resend_send(string $to, string $subject, string $body): array {
    $cfg = cfg();
    $isHtml = str_contains($body, '<') && str_contains($body, '>');
    $html = $isHtml ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    $text = $isHtml ? trim(preg_replace('/\s+/', ' ', strip_tags($body))) : $body;
    $payload = json_encode([
        'from'    => $cfg['mail_from'],
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ]);
    $auth = 'Bearer ' . trim($cfg['resend_api_key']);

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) return [true, null];
        return [false, $err ?: ('HTTP ' . $code . ' ' . substr((string)$resp, 0, 180))];
    }

    // Alternativa sin extensión curl (streams nativos de PHP)
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Authorization: $auth\r\nContent-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    }
    if ($resp !== false && $code >= 200 && $code < 300) return [true, null];
    return [false, 'HTTP ' . $code . ' ' . substr((string)$resp, 0, 180)];
}

/* ---------- plantillas de correo con marca (isotipo + colores MiSalaUCR) ---------- */

function _email_esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** Envoltorio común: logo en cabecera navy + franja turquesa + tarjeta de detalle + nota destacada. */
function email_layout(string $badgeText, string $badgeColor, string $badgeBg, string $name,
                       string $intro, array $rows, string $noteColor, string $noteBg, string $noteHtml): string {
    $logo = _email_esc(rtrim(cfg()['base_url'], '/') . '/assets/brand/app-icon.png');
    $rowsHtml = '';
    $n = count($rows);
    foreach ($rows as $i => [$label, $value]) {
        $border = $i < $n - 1 ? 'border-bottom:1px solid #e2ddd0;' : '';
        $rowsHtml .= "<tr>
            <td style='padding:16px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#0d2233;$border'>" . _email_esc($label) . "</td>
            <td style='padding:16px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#123a5e;font-weight:700;text-align:right;$border'>" . _email_esc($value) . "</td>
        </tr>";
    }
    $name = _email_esc($name);
    return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f0eee8;font-family:Arial,Helvetica,sans-serif;'>
<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f0eee8;padding:32px 16px;'>
<tr><td align='center'>
<table role='presentation' width='480' cellpadding='0' cellspacing='0' style='max-width:480px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 10px rgba(13,34,51,.08);'>

<tr><td style='background:#123a5e;padding:28px 32px;text-align:center;'>
<img src='$logo' width='56' height='56' alt='MiSalaUCR' style='display:block;margin:0 auto 10px;border-radius:12px;'>
<span style='font-family:Arial,Helvetica,sans-serif;color:#ffffff;font-size:15px;font-weight:700;letter-spacing:.02em;'>MiSalaUCR</span>
</td></tr>

<tr><td style='padding:6px 0;background:#2ec5d8;font-size:0;line-height:0;'>&nbsp;</td></tr>

<tr><td style='padding:32px 32px 8px;'>
<table role='presentation' cellpadding='0' cellspacing='0'><tr>
<td style='background:$noteBg;color:$noteColor;font-size:12px;font-weight:700;font-family:monospace;letter-spacing:.03em;padding:5px 12px;border-radius:999px;'>" . _email_esc($badgeText) . "</td>
</tr></table>
<h1 style='font-size:19px;color:#0d2233;margin:16px 0 4px;font-family:Arial,Helvetica,sans-serif;'>Hola $name</h1>
<p style='font-size:14px;color:#6c7680;margin:0 0 20px;line-height:1.5;font-family:Arial,Helvetica,sans-serif;'>" . _email_esc($intro) . "</p>
</td></tr>

<tr><td style='padding:0 32px;'>
<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f0eee8;border-radius:12px;'>
$rowsHtml
</table>
</td></tr>

<tr><td style='padding:20px 32px 32px;'>
<p style='font-size:13px;color:#6c7680;line-height:1.6;margin:0;font-family:Arial,Helvetica,sans-serif;background:$noteBg;border-radius:10px;padding:14px 16px;'>
$noteHtml
</p>
</td></tr>

<tr><td style='padding:20px 32px;background:#f0eee8;text-align:center;'>
<p style='font-size:11px;color:#6c7680;margin:0;font-family:Arial,Helvetica,sans-serif;'>MiSalaUCR &middot; Reserva de salas de estudio<br><a href='https://www.misalaucr.com' style='color:#0d7f8f;text-decoration:none;'>www.misalaucr.com</a></p>
</td></tr>

</table>
</td></tr>
</table>
</body></html>";
}

function email_reserva_confirmada(string $name, string $roomName, string $dateStr, int $startHour, int $endHour, int $checkinMinutes): string {
    return email_layout(
        'RESERVA CONFIRMADA', '#0e9c86', '#d7f2ec',
        $name, 'Tu espacio de estudio quedó reservado. Aquí el detalle:',
        [['Sala', $roomName], ['Fecha', $dateStr], ['Horario', sprintf('%d:00 – %d:00', $startHour, $endHour)]],
        '#0d7f8f', '#e2f6f9',
        "<strong style='color:#0d7f8f;'>Recuerda:</strong> al iniciar tu bloque tendrás <strong>{$checkinMinutes} minutos</strong> para confirmar tu llegada en la app. Si no lo haces, el espacio se libera y cuenta como inasistencia."
    );
}

function email_recordatorio(string $name, string $roomName, int $startHour, int $endHour, int $checkinMinutes): string {
    return email_layout(
        'FALTAN 30 MINUTOS', '#c14a44', '#f8e8e6',
        $name, 'Te recordamos tu reserva de hoy:',
        [['Sala', $roomName], ['Horario', sprintf('%d:00 – %d:00', $startHour, $endHour)]],
        '#c14a44', '#f8e8e6',
        "<strong style='color:#c14a44;'>Importante:</strong> confirma tu llegada en la app durante los primeros <strong>{$checkinMinutes} minutos</strong> de tu bloque, o el espacio se liberará para otros estudiantes."
    );
}

function email_reset_password(string $name, string $link): string {
    // $noteHtml de email_layout se inserta sin escapar (igual que en las plantillas
    // de arriba), por eso el botón con HTML va ahí y no en $rows (que sí se escapa).
    $safeLink = _email_esc($link);
    $button = "<a href='$safeLink' style='display:inline-block;background:#c14a44;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:10px 20px;border-radius:8px;font-family:Arial,Helvetica,sans-serif;margin-top:8px;'>Restablecer contraseña</a>";
    return email_layout(
        'RECUPERAR CONTRASEÑA', '#c14a44', '#f8e8e6',
        $name, 'Solicitaste restablecer tu contraseña. Este enlace es válido por 30 minutos:',
        [['Enlace válido por', '30 minutos']],
        '#c14a44', '#f8e8e6',
        "$button<br><br>Si el botón no funciona, copia y pega esta dirección en tu navegador:<br>$safeLink<br><br>Si no solicitaste este cambio, ignora este correo — tu contraseña actual sigue siendo válida."
    );
}
