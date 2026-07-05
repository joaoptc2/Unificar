<?php
/**
 * Envio de e-mails.
 *
 * Usa SMTP (via `SmtpClient`) quando `mail_use_smtp` estiver habilitado no
 * `config/app.php`, caso contrário recorre à função nativa `mail()`. Em ambos
 * os casos, respeita a flag `mail_enabled` (quando false, nenhum e-mail é
 * enviado — útil em ambiente de desenvolvimento).
 */
class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $config = require __DIR__ . '/../../config/app.php';

        if (empty($config['mail_enabled'])) {
            return false;
        }

        $from     = $config['mail_from']      ?? 'no-reply@localhost';
        $fromName = $config['mail_from_name'] ?? 'RH';

        // Validação mínima do destinatário.
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('Mailer: destinatário inválido — ' . $to);
            return false;
        }

        if (!empty($config['mail_use_smtp'])) {
            return self::sendSmtp($config, $from, $fromName, $to, $subject, $htmlBody);
        }
        return self::sendNative($from, $fromName, $to, $subject, $htmlBody);
    }

    private static function sendSmtp(array $config, string $from, string $fromName, string $to, string $subject, string $htmlBody): bool
    {
        if (!class_exists('SmtpClient')) {
            require_once __DIR__ . '/SmtpClient.php';
        }
        $host  = parse_url($config['base_url'] ?? '', PHP_URL_HOST) ?: gethostname();
        $smtp  = new SmtpClient([
            'host'       => $config['mail_host']       ?? 'localhost',
            'port'       => $config['mail_port']       ?? 587,
            'username'   => $config['mail_username']   ?? '',
            'password'   => $config['mail_password']   ?? '',
            'encryption' => $config['mail_encryption'] ?? 'tls',
            'timeout'    => $config['mail_timeout']    ?? 20,
            'localhost'  => $host,
            'debug'      => !empty($config['debug']),
        ]);
        $ok = $smtp->send($from, $fromName, [$to], $subject, $htmlBody);
        if (!$ok) {
            error_log('Mailer SMTP: ' . $smtp->lastError());
        }
        return $ok;
    }

    private static function sendNative(string $from, string $fromName, string $to, string $subject, string $htmlBody): bool
    {
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encName    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $encName . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: RH-Hospital/1.0',
        ];
        return @mail($to, $encSubject, $htmlBody, implode("\r\n", $headers));
    }

    // -----------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------

    public static function sendExpiryAlert(string $to, string $employeeName, string $expiryTitle, string $expiryDate): bool
    {
        $subject = 'Alerta de Vencimento — ' . $expiryTitle;
        $body = self::wrapTemplate(
            'Alerta de Vencimento',
            '<p>O seguinte item está próximo do vencimento ou já venceu:</p>' .
            '<table style="border-collapse:collapse;width:100%;margin-top:8px">' .
            '<tr><td style="padding:8px;border:1px solid #ddd"><strong>Funcionário</strong></td><td style="padding:8px;border:1px solid #ddd">' . htmlspecialchars($employeeName) . '</td></tr>' .
            '<tr><td style="padding:8px;border:1px solid #ddd"><strong>Item</strong></td><td style="padding:8px;border:1px solid #ddd">' . htmlspecialchars($expiryTitle) . '</td></tr>' .
            '<tr><td style="padding:8px;border:1px solid #ddd"><strong>Vencimento</strong></td><td style="padding:8px;border:1px solid #ddd">' . htmlspecialchars($expiryDate) . '</td></tr>' .
            '</table>'
        );
        return self::send($to, $subject, $body);
    }

    public static function sendPasswordReset(string $to, string $userName, string $resetUrl): bool
    {
        $subject = 'Redefinição de senha';
        $body = self::wrapTemplate(
            'Redefinição de senha',
            '<p>Olá, ' . htmlspecialchars($userName) . '.</p>' .
            '<p>Recebemos uma solicitação para redefinir a senha da sua conta. Clique no botão abaixo (válido por 1 hora):</p>' .
            '<p style="margin:24px 0"><a href="' . htmlspecialchars($resetUrl) . '" style="background:#0d6efd;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none">Redefinir senha</a></p>' .
            '<p style="font-size:12px;color:#666">Se você não fez essa solicitação, ignore este e-mail.</p>' .
            '<p style="font-size:12px;color:#666">Ou copie e cole este link: ' . htmlspecialchars($resetUrl) . '</p>'
        );
        return self::send($to, $subject, $body);
    }

    private static function wrapTemplate(string $title, string $innerHtml): string
    {
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:16px">' .
               '<div style="max-width:600px;margin:0 auto;border:1px solid #eee;border-radius:8px;padding:20px">' .
               '<h2 style="color:#0d6efd;margin-top:0">' . htmlspecialchars($title) . '</h2>' .
               $innerHtml .
               '<p style="margin-top:24px;color:#888;font-size:11px">Este é um e-mail automático do sistema RH Hospital.</p>' .
               '</div></body></html>';
    }
}
