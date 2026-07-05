<?php
/**
 * E-mail do módulo DOCUMENTOS — adaptador do núcleo.
 *
 * send_mail() delega para Core\Mailer::send (SMTP configurado no núcleo,
 * chaves mail.* do config/config.php). A implementação SMTP própria do
 * legado foi removida.
 *
 * Uso:
 *   send_mail('destinatario@x.com', 'Assunto', '<p>Corpo HTML</p>');
 */

/**
 * Envia um e-mail via núcleo.
 *
 * @param string|array $to      Destinatário(s)
 * @param string       $subject Assunto
 * @param string       $html    Corpo em HTML
 * @param array        $opts    Mantido por compatibilidade (ignorado)
 * @return bool true se ao menos um envio foi aceito
 */
function send_mail($to, $subject, $html, array $opts = []) {
    $recipients = is_array($to) ? $to : [$to];
    $recipients = array_filter($recipients, 'is_valid_email');
    if (empty($recipients)) return false;

    $ok = false;
    foreach ($recipients as $rcpt) {
        try {
            if (Core\Mailer::send($rcpt, (string) $subject, (string) $html)) {
                $ok = true;
            }
        } catch (Throwable $ex) {
            log_error('send_mail', $ex);
        }
    }
    return $ok;
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
