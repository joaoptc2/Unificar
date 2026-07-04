<?php

declare(strict_types=1);

namespace Core;

/**
 * Envio de e-mail via SMTP nativo (sockets) com fallback para mail().
 * Usado pelo núcleo (reset de senha) e pelos módulos (alertas de cron).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $html): bool
    {
        if (!Config::get('mail.enabled', false)) {
            error_log("Mailer desabilitado — e-mail para {$to} não enviado ({$subject})");
            return false;
        }

        $host = (string) Config::get('mail.host', '');
        if ($host !== '') {
            try {
                return self::smtp($to, $subject, $html);
            } catch (\Throwable $e) {
                error_log('SMTP falhou: ' . $e->getMessage());
            }
        }

        $headers = self::headers();
        return mail($to, self::encodeHeader($subject), $html, implode("\r\n", $headers));
    }

    private static function smtp(string $to, string $subject, string $html): bool
    {
        $host = (string) Config::get('mail.host');
        $port = (int) Config::get('mail.port', 587);
        $enc  = (string) Config::get('mail.encryption', 'tls');
        $user = (string) Config::get('mail.user', '');
        $pass = (string) Config::get('mail.pass', '');
        $from = (string) Config::get('mail.from', 'no-reply@localhost');

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            throw new \RuntimeException("Conexão SMTP falhou: {$errstr}");
        }

        $read = function () use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c, array $expect = [250]) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $resp = $read();
            $code = (int) substr($resp, 0, 3);
            if (!in_array($code, $expect, true)) {
                throw new \RuntimeException("SMTP '{$c}': {$resp}");
            }
            return $resp;
        };

        $read(); // banner
        $cmd('EHLO ' . gethostname(), [250]);

        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS falhou');
            }
            $cmd('EHLO ' . gethostname(), [250]);
        }

        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }

        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $headers   = self::headers();
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $body      = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $body      = preg_replace('/^\./m', '..', $body);

        $cmd($body . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    }

    private static function headers(): array
    {
        $from     = (string) Config::get('mail.from', 'no-reply@localhost');
        $fromName = (string) Config::get('mail.from_name', Config::get('app.name', 'Portal'));
        return [
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Date: ' . date('r'),
        ];
    }

    private static function encodeHeader(string $text): string
    {
        return preg_match('/[^\x20-\x7E]/', $text)
            ? '=?UTF-8?B?' . base64_encode($text) . '?='
            : $text;
    }
}
