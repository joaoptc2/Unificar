<?php
/**
 * SMTP Mailer — leve, sem dependências externas.
 *
 * Funciona com Hostinger (smtp.hostinger.com:465 SSL) e outros provedores.
 * Suporta SSL (porta 465), STARTTLS (587) e conexão simples.
 *
 * Uso:
 *   send_mail('destinatario@x.com', 'Assunto', '<p>Corpo HTML</p>');
 */

/**
 * Envia um e-mail. Usa config do .env (MAIL_*).
 *
 * @param string|array $to      Destinatário(s)
 * @param string       $subject Assunto
 * @param string       $html    Corpo em HTML (texto plano também aceito)
 * @param array        $opts    ['reply_to' => ..., 'text' => 'versão texto']
 * @return bool
 */
function send_mail($to, $subject, $html, array $opts = []) {
    if (!MAIL_ENABLED) return false;
    if (empty(MAIL_HOST) || empty(MAIL_USER)) return false;

    $recipients = is_array($to) ? $to : [$to];
    $recipients = array_filter($recipients, 'is_valid_email');
    if (empty($recipients)) return false;

    try {
        return _smtp_send($recipients, $subject, $html, $opts);
    } catch (Throwable $ex) {
        log_error('send_mail', $ex);
        return false;
    }
}

/**
 * Implementação SMTP mínima com autenticação AUTH LOGIN.
 */
function _smtp_send(array $recipients, $subject, $html, array $opts) {
    $host = MAIL_HOST;
    $port = MAIL_PORT;
    $enc  = strtolower(MAIL_ENCRYPTION);
    $user = MAIL_USER;
    $pass = MAIL_PASS;

    $transport = ($enc === 'ssl') ? 'ssl://' . $host : $host;
    $errno = 0; $errstr = '';

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ]);

    $sock = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($sock, 15);

    _smtp_expect($sock, '220');

    $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
    _smtp_cmd($sock, "EHLO $hostname");
    _smtp_read_multiline($sock);

    if ($enc === 'tls') {
        _smtp_cmd($sock, 'STARTTLS');
        _smtp_expect($sock, '220');
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        _smtp_cmd($sock, "EHLO $hostname");
        _smtp_read_multiline($sock);
    }

    _smtp_cmd($sock, 'AUTH LOGIN');
    _smtp_expect($sock, '334');
    _smtp_cmd($sock, base64_encode($user));
    _smtp_expect($sock, '334');
    _smtp_cmd($sock, base64_encode($pass));
    _smtp_expect($sock, '235');

    _smtp_cmd($sock, 'MAIL FROM: <' . $user . '>');
    _smtp_expect($sock, '250');

    foreach ($recipients as $rcpt) {
        _smtp_cmd($sock, 'RCPT TO: <' . $rcpt . '>');
        _smtp_expect($sock, ['250', '251']);
    }

    _smtp_cmd($sock, 'DATA');
    _smtp_expect($sock, '354');

    // Monta cabeçalhos + corpo
    $boundary = 'bnd_' . md5(uniqid('', true));
    $from_name = MAIL_FROM_NAME;

    $headers = [
        'Date: ' . date('r'),
        'From: ' . _encode_header($from_name) . ' <' . $user . '>',
        'To: ' . implode(', ', $recipients),
        'Subject: ' . _encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if (!empty($opts['reply_to'])) {
        $headers[] = 'Reply-To: <' . $opts['reply_to'] . '>';
    }
    $headers[] = 'X-Mailer: ' . APP_NAME;

    $text_part = $opts['text'] ?? strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));

    $body = implode("\r\n", $headers) . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $text_part . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--$boundary--\r\n";

    // Dot-stuffing (RFC 5321)
    $body = preg_replace('/^\./m', '..', $body);

    fwrite($sock, $body . "\r\n.\r\n");
    _smtp_expect($sock, '250');

    _smtp_cmd($sock, 'QUIT');
    fclose($sock);
    return true;
}

function _encode_header($text) {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function _smtp_cmd($sock, $cmd) {
    fwrite($sock, $cmd . "\r\n");
}

function _smtp_read_line($sock) {
    $line = '';
    while (!feof($sock)) {
        $chunk = fgets($sock, 515);
        if ($chunk === false) break;
        $line .= $chunk;
        // Última linha do multi-line não tem '-' na posição 3
        if (isset($chunk[3]) && $chunk[3] === ' ') break;
        if (strlen($chunk) < 4) break;
    }
    return $line;
}

function _smtp_read_multiline($sock) {
    return _smtp_read_line($sock);
}

function _smtp_expect($sock, $code) {
    $codes = (array) $code;
    $resp = _smtp_read_line($sock);
    $ok = false;
    foreach ($codes as $c) {
        if (strpos($resp, $c) === 0) { $ok = true; break; }
    }
    if (!$ok) {
        throw new RuntimeException('SMTP expected ' . implode('/', $codes) . ', got: ' . trim($resp));
    }
    return $resp;
}

/**
 * Template simples para e-mails do sistema (cabeçalho + rodapé).
 */
function mail_template($title, $body_html, $cta_url = null, $cta_text = null) {
    $cta = '';
    if ($cta_url && $cta_text) {
        $cta = '<p style="margin:24px 0;text-align:center">'
             . '<a href="' . e($cta_url) . '" style="display:inline-block;padding:12px 24px;'
             . 'background:#4f46e5;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">'
             . e($cta_text) . '</a></p>';
    }

    return '<!DOCTYPE html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f1f5f9">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:20px 0">
<tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
    <tr><td style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:24px;color:#fff">
      <h2 style="margin:0;font-size:18px">' . e(APP_NAME) . '</h2>
    </td></tr>
    <tr><td style="padding:28px 24px;color:#1e293b;font-size:14px;line-height:1.6">
      <h3 style="margin:0 0 16px;color:#0f172a">' . e($title) . '</h3>'
      . $body_html
      . $cta
    . '</td></tr>
    <tr><td style="background:#f8fafc;padding:16px 24px;font-size:12px;color:#64748b;text-align:center">
      Este é um e-mail automático. Por favor, não responda.<br>
      &copy; ' . date('Y') . ' ' . e(APP_NAME) . '
    </td></tr>
  </table>
</td></tr>
</table></body></html>';
}
