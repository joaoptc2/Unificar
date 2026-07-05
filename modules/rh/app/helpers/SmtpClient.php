<?php
/**
 * Cliente SMTP mínimo em PHP puro.
 *
 * Suporta STARTTLS (porta 587), TLS implícito (porta 465) e autenticação
 * LOGIN/PLAIN. Escrito para rodar em hospedagem compartilhada (Hostinger,
 * cPanel, Locaweb) sem Composer ou dependências externas.
 *
 * Uso típico:
 *   $smtp = new SmtpClient([
 *       'host' => 'smtp.hostinger.com',
 *       'port' => 587,
 *       'username' => 'rh@empresa.com',
 *       'password' => '...',
 *       'encryption' => 'tls', // 'tls' (STARTTLS) | 'ssl' (TLS impl.) | ''
 *       'timeout' => 20,
 *   ]);
 *   $smtp->send('from@x.com', 'From Name', ['to@y.com'], 'Assunto', $html);
 */
class SmtpClient
{
    /** @var resource|false */
    private $socket = false;
    private array $config;
    private string $lastError = '';
    private string $transcript = '';

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host'       => 'localhost',
            'port'       => 587,
            'username'   => '',
            'password'   => '',
            'encryption' => 'tls',
            'timeout'    => 20,
            'localhost'  => 'localhost',
            'debug'      => false,
        ], $config);
    }

    public function lastError(): string  { return $this->lastError; }
    public function transcript(): string { return $this->transcript; }

    /**
     * @param string   $fromEmail
     * @param string   $fromName
     * @param string[] $to
     * @param string   $subject
     * @param string   $htmlBody
     * @param string[] $replyTo
     */
    public function send(
        string $fromEmail,
        string $fromName,
        array  $to,
        string $subject,
        string $htmlBody,
        array  $replyTo = []
    ): bool {
        if (!$this->connect())    return false;
        if (!$this->handshake())  return $this->bye(false);
        if (!$this->starttls())   return $this->bye(false);
        if (!$this->authenticate()) return $this->bye(false);

        if (!$this->cmd('MAIL FROM:<' . $fromEmail . '>', 250)) return $this->bye(false);
        foreach ($to as $rcpt) {
            if (!$this->cmd('RCPT TO:<' . $rcpt . '>', [250, 251])) return $this->bye(false);
        }
        if (!$this->cmd('DATA', 354)) return $this->bye(false);

        $headers = $this->buildHeaders($fromEmail, $fromName, $to, $subject, $replyTo);
        $body = $headers . "\r\n\r\n" . $this->prepareBody($htmlBody);
        // RFC 5321: dot-stuffing.
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($this->socket, $body . "\r\n.\r\n");

        if (!$this->expect(250)) return $this->bye(false);
        return $this->bye(true);
    }

    // -----------------------------------------------------------------

    private function connect(): bool
    {
        $host = $this->config['host'];
        if ($this->config['encryption'] === 'ssl') {
            $host = 'ssl://' . $host;
        }
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $host . ':' . (int)$this->config['port'],
            $errno, $errstr,
            (int)$this->config['timeout'],
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ])
        );
        if (!$this->socket) {
            $this->lastError = "Falha de conexão SMTP: {$errstr} ({$errno})";
            return false;
        }
        stream_set_timeout($this->socket, (int)$this->config['timeout']);
        return $this->expect(220);
    }

    private function handshake(): bool
    {
        if (!$this->cmd('EHLO ' . $this->config['localhost'], 250)) {
            // Alguns servidores legados só respondem a HELO.
            return $this->cmd('HELO ' . $this->config['localhost'], 250);
        }
        return true;
    }

    private function starttls(): bool
    {
        if ($this->config['encryption'] !== 'tls') return true;
        if (!$this->cmd('STARTTLS', 220)) return false;
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
            $this->lastError = 'Falha ao ativar TLS.';
            return false;
        }
        // EHLO novamente após TLS.
        return $this->cmd('EHLO ' . $this->config['localhost'], 250);
    }

    private function authenticate(): bool
    {
        if ($this->config['username'] === '') return true;
        if (!$this->cmd('AUTH LOGIN', 334)) return false;
        if (!$this->cmd(base64_encode($this->config['username']), 334)) return false;
        return $this->cmd(base64_encode($this->config['password']), 235);
    }

    /**
     * Envia comando e valida o código de resposta esperado.
     * @param int|int[] $expected
     */
    private function cmd(string $command, $expected): bool
    {
        if ($this->config['debug']) $this->transcript .= '> ' . $command . "\n";
        fwrite($this->socket, $command . "\r\n");
        return $this->expect($expected);
    }

    /** @param int|int[] $expected */
    private function expect($expected): bool
    {
        $expected = (array)$expected;
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if ($this->config['debug']) $this->transcript .= '< ' . $line;
            // Resposta SMTP: '250-...' (continua) ou '250 ...' (último).
            if (preg_match('/^\d{3} /', $line)) break;
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $this->lastError = 'SMTP: resposta inesperada — ' . trim($response);
            return false;
        }
        return true;
    }

    private function buildHeaders(string $fromEmail, string $fromName, array $to, string $subject, array $replyTo): string
    {
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $h = [];
        $h[] = 'Date: ' . date('r');
        $h[] = 'From: ' . $encFromName . ' <' . $fromEmail . '>';
        $h[] = 'To: ' . implode(', ', array_map(fn($a) => '<' . $a . '>', $to));
        if ($replyTo) {
            $h[] = 'Reply-To: ' . implode(', ', array_map(fn($a) => '<' . $a . '>', $replyTo));
        }
        $h[] = 'Subject: ' . $encSubject;
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($this->config['localhost'] ?: 'localhost') . '>';
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/html; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        $h[] = 'X-Mailer: RH-Hospital/1.0';
        return implode("\r\n", $h);
    }

    private function prepareBody(string $htmlBody): string
    {
        return chunk_split(base64_encode($htmlBody), 76, "\r\n");
    }

    private function bye(bool $ok): bool
    {
        if ($this->socket) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
            $this->socket = false;
        }
        return $ok;
    }
}
