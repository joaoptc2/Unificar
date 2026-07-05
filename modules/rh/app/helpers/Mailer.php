<?php
/**
 * Mailer — adaptador do envio de e-mail do núcleo (Core\Mailer).
 *
 * A configuração SMTP agora é única da plataforma (core_config('mail.*')):
 * o SmtpClient próprio do módulo foi removido. Ficam aqui apenas os
 * templates de e-mail específicos do RH.
 */
class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('Mailer: destinatário inválido — ' . $to);
            return false;
        }
        return Core\Mailer::send($to, $subject, $htmlBody);
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

    private static function wrapTemplate(string $title, string $innerHtml): string
    {
        $appName = Core\Settings::get('org_name', 'RH');
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:16px">' .
               '<div style="max-width:600px;margin:0 auto;border:1px solid #eee;border-radius:8px;padding:20px">' .
               '<h2 style="color:#0d6efd;margin-top:0">' . htmlspecialchars($title) . '</h2>' .
               $innerHtml .
               '<p style="margin-top:24px;color:#888;font-size:11px">Este é um e-mail automático do módulo RH — ' . htmlspecialchars((string)$appName) . '.</p>' .
               '</div></body></html>';
    }
}
