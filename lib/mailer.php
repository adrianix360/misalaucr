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
    $payload = json_encode([
        'from'    => $cfg['mail_from'],
        'to'      => [$to],
        'subject' => $subject,
        'text'    => $body,
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
