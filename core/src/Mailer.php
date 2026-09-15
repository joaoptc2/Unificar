<?php

declare(strict_types=1);

namespace Core;

/**
 * Envio de e-mail via SMTP nativo (sockets), com queda opcional para mail().
 * Usado pelo núcleo (reset de senha) e pelos módulos (alertas, comunicados).
 *
 * send() continua devolvendo bool para não mexer em quem já chama. Quem
 * precisa saber POR QUE falhou usa sendDetailed(), que devolve também o
 * código do erro, o tempo de cada etapa e a conversa com o servidor — é
 * dele que vive a tela Administração > E-mail. Só existe UMA implementação
 * de SMTP aqui; a tela de teste usa a mesma, para o que se testa ser
 * exatamente o que se envia.
 *
 * Cuidados que a implementação anterior não tinha e que motivaram a revisão:
 *  - a senha (em base64) ia para a exceção e daí para o php_errors.log;
 *  - o corpo inteiro do e-mail ia junto, pelo mesmo caminho;
 *  - QUIT sem resposta — comum — derrubava um envio JÁ ACEITO pelo servidor,
 *    e a fila reenviava a mesma mensagem;
 *  - o socket ficava aberto em qualquer caminho de erro;
 *  - endereços entravam crus no protocolo (injeção de cabeçalho);
 *  - falha de SMTP caía para mail() em silêncio e a fila marcava "enviado".
 */
final class Mailer
{
    /** Códigos de falha — a tela traduz cada um em instrução para o admin. */
    public const ERR_DISABLED      = 'DESABILITADO';
    public const ERR_NO_HOST       = 'SEM_SERVIDOR';
    public const ERR_BAD_ADDRESS   = 'ENDERECO_INVALIDO';
    public const ERR_DNS           = 'DNS';
    public const ERR_CONNECT       = 'CONEXAO';
    public const ERR_TIMEOUT       = 'TEMPO_ESGOTADO';
    public const ERR_BANNER        = 'SAUDACAO';
    public const ERR_EHLO          = 'EHLO';
    public const ERR_STARTTLS      = 'STARTTLS';
    public const ERR_TLS           = 'TLS';
    public const ERR_AUTH          = 'AUTENTICACAO';
    public const ERR_AUTH_UNSUP    = 'AUTENTICACAO_NAO_SUPORTADA';
    public const ERR_FROM          = 'REMETENTE_RECUSADO';
    public const ERR_RCPT          = 'DESTINATARIO_RECUSADO';
    public const ERR_DATA          = 'MENSAGEM_RECUSADA';
    public const ERR_MAIL_FN       = 'FUNCAO_MAIL';

    /** Envio simples: true quando a mensagem foi aceita. */
    public static function send(string $to, string $subject, string $html): bool
    {
        return self::sendDetailed($to, $subject, $html)['ok'];
    }

    /**
     * Envio instrumentado.
     *
     * @param array{
     *   config?: array<string,string>, password?: string,
     *   transcript?: bool, fallback?: bool, timeout?: int, reply_to?: string
     * } $opts  config/password permitem testar valores ainda não salvos
     *
     * @return array{ok:bool, path:string, code:string, error:string,
     *                steps:array<string,float>, transcript:array<int,string>, ms:float}
     */
    public static function sendDetailed(string $to, string $subject, string $html, array $opts = []): array
    {
        $t0  = hrtime(true);
        $cfg = self::effectiveConfig($opts);
        $res = [
            'ok' => false, 'path' => 'nenhum', 'code' => '', 'error' => '',
            'steps' => [], 'transcript' => [], 'ms' => 0.0,
        ];

        if (!MailConfig::isEmail($to)) {
            return self::finish($res, $t0, self::ERR_BAD_ADDRESS, 'Endereço de destino inválido.');
        }
        if (!MailConfig::isEmail($cfg['from'])) {
            return self::finish($res, $t0, self::ERR_BAD_ADDRESS, 'Endereço do remetente inválido: ' . $cfg['from']);
        }
        if ($cfg['enabled'] !== '1') {
            error_log("Mailer desabilitado — e-mail para {$to} não enviado ({$subject})");
            return self::finish($res, $t0, self::ERR_DISABLED,
                'Envio de e-mail desligado (Administração > E-mail).');
        }

        if ($cfg['host'] !== '') {
            try {
                $smtp = self::smtp($to, $subject, $html, $cfg, $opts);
                $res['steps']      = $smtp['steps'];
                $res['transcript'] = $smtp['transcript'];
                $res['path']       = 'smtp';
                $res['ok']         = true;
                return self::finish($res, $t0, '', '');
            } catch (MailerException $e) {
                $res['steps']      = $e->steps;
                $res['transcript'] = $e->transcript;
                $res['path']       = 'smtp';
                error_log('SMTP falhou [' . $e->errorCode . ']: ' . MailConfig::redact($e->getMessage()));
                if (!self::wantsFallback($opts)) {
                    return self::finish($res, $t0, $e->errorCode, $e->getMessage());
                }
            } catch (\Throwable $e) {
                $res['path'] = 'smtp';
                error_log('SMTP falhou: ' . MailConfig::redact($e->getMessage()));
                if (!self::wantsFallback($opts)) {
                    return self::finish($res, $t0, self::ERR_CONNECT, $e->getMessage());
                }
            }
        } elseif (!self::wantsFallback($opts)) {
            return self::finish($res, $t0, self::ERR_NO_HOST,
                'Nenhum servidor SMTP configurado (Administração > E-mail).');
        }

        // Caminho mail() do PHP: entrega ao sendmail local, que é bem menos
        // garantia do que um 250 do servidor de saída — por isso 'path' é
        // diferente e a tela mostra isso com todas as letras.
        $res['path'] = 'mail';
        if (!function_exists('mail')) {
            return self::finish($res, $t0, self::ERR_MAIL_FN, 'A função mail() do PHP está desabilitada nesta hospedagem.');
        }
        $headers = self::headers($cfg, $opts);
        $ok = @mail($to, self::encodeHeader($subject), self::body($html), implode("\r\n", $headers));
        $res['ok'] = (bool) $ok;
        return $ok
            ? self::finish($res, $t0, '', '')
            : self::finish($res, $t0, self::ERR_MAIL_FN, 'A função mail() do PHP recusou a mensagem.');
    }

    /**
     * Conversa SMTP.
     *
     * @throws MailerException
     * @return array{steps:array<string,float>, transcript:array<int,string>}
     */
    private static function smtp(string $to, string $subject, string $html, array $cfg, array $opts): array
    {
        $capture = (bool) ($opts['transcript'] ?? false);
        $timeout = (int) ($opts['timeout'] ?? (int) $cfg['timeout']);
        $timeout = max(3, min(60, $timeout ?: 15));
        $enc     = $cfg['encryption'];
        $host    = $cfg['host'];
        $port    = (int) $cfg['port'] ?: 587;
        $user    = $cfg['user'];
        $pass    = (string) ($opts['password'] ?? MailConfig::password());

        // Orçamento TOTAL da conversa. Limitar só cada leitura não basta:
        // contra um servidor vivo porém lento, cada etapa cabe no limite e a
        // soma não cabe — o PHP mata o request no meio e a tela devolve 500
        // em branco. O prazo abaixo faz o erro chegar legível antes disso.
        $orcamento = $timeout * 3;
        $maxExec   = (int) ini_get('max_execution_time');
        if ($maxExec > 0) {
            // Sempre abaixo do que a hospedagem permite: melhor um erro
            // legível do que o request morto no meio, que deixa a tela em
            // branco e o registro do teste preso em "em andamento".
            $orcamento = min($orcamento, max(3, (int) floor($maxExec * 0.8)));
        }
        $prazo = (float) hrtime(true) + $orcamento * 1e9;
        $sobra = static function () use ($prazo, $timeout): int {
            $s = (int) ceil(($prazo - hrtime(true)) / 1e9);
            if ($s <= 0) {
                throw new \RuntimeException('TIMEOUT:A conversa com o servidor passou do tempo previsto.');
            }
            return max(1, min($timeout, $s));
        };

        $steps = [];
        $log   = [];
        $mark  = static function (string $name, float $since) use (&$steps): float {
            $steps[$name] = round((hrtime(true) - $since) / 1e6, 1);
            return (float) hrtime(true);
        };
        $say = static function (string $line) use ($capture, &$log): void {
            if ($capture) {
                $log[] = MailConfig::redact(rtrim($line, "\r\n"));
            }
        };

        $t = (float) hrtime(true);
        // Resolução de nome separada da conexão: o erro fica legível.
        if (!filter_var($host, FILTER_VALIDATE_IP) && gethostbyname($host) === $host) {
            throw new MailerException(self::ERR_DNS,
                "Não foi possível resolver o endereço do servidor ({$host}).", $steps, $log);
        }
        $t = $mark('dns', $t);

        $fp = null;
        try {
            $remote  = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
            $context = stream_context_create(['ssl' => [
                // Padrão do PHP desde a 5.6; explícito aqui para deixar claro
                // que validar o certificado é intencional.
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'peer_name'         => $host,
                'SNI_enabled'       => true,
            ]]);
            $say("… conectando em {$remote}");
            $fp = @stream_socket_client($remote, $errno, $errstr, $timeout,
                STREAM_CLIENT_CONNECT, $context);
            if (!$fp) {
                $code = str_contains(strtolower((string) $errstr), 'timed out')
                    ? self::ERR_TIMEOUT : self::ERR_CONNECT;
                throw new MailerException($code,
                    "Conexão com {$host}:{$port} falhou: " . ($errstr ?: 'sem detalhe') . ".", $steps, $log);
            }
            stream_set_timeout($fp, $timeout);
            $t = $mark('conexao', $t);

            $read = static function () use ($fp, $say, $timeout, $sobra): string {
                stream_set_timeout($fp, $sobra());
                $data = '';
                while (($line = fgets($fp, 998)) !== false) {
                    $data .= $line;
                    $say('S: ' . $line);
                    if (isset($line[3]) && $line[3] === ' ') {
                        break;
                    }
                }
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException("TIMEOUT:O servidor não respondeu em {$timeout}s.");
                }
                return $data;
            };
            /** @var callable(string,array<int,int>,string,bool):string $cmd */
            $cmd = static function (string $payload, array $expect, string $label, bool $secret = false)
                use ($fp, $read, $say, &$steps, $timeout, $sobra): string {
                $sobra(); // estoura aqui se o orçamento total acabou
                $say('C: ' . ($secret ? '••••••' : $payload));
                if (@fwrite($fp, $payload . "\r\n") === false) {
                    throw new \RuntimeException("ESCRITA:A conexão caiu durante {$label}.");
                }
                $resp = $read();
                $code = (int) substr($resp, 0, 3);
                if (!in_array($code, $expect, true)) {
                    // NUNCA ecoa o payload: seria a senha (AUTH) ou a mensagem (DATA).
                    throw new \RuntimeException("RESPOSTA:O servidor recusou na etapa \"{$label}\": " . trim($resp));
                }
                return $resp;
            };

            $banner = $read();
            if ((int) substr($banner, 0, 3) !== 220) {
                throw new MailerException(self::ERR_BANNER,
                    'O servidor não apresentou a saudação esperada: ' . trim($banner), $steps, $log);
            }
            $t = $mark('saudacao', $t);

            $ehloName = $cfg['ehlo'] !== '' ? $cfg['ehlo'] : MailConfig::ehlo();
            $caps = self::try($cmd, 'EHLO ' . $ehloName, [250], 'EHLO', self::ERR_EHLO, $steps, $log);
            $t = $mark('ehlo', $t);

            if ($enc === 'tls') {
                self::try($cmd, 'STARTTLS', [220], 'STARTTLS', self::ERR_STARTTLS, $steps, $log);
                $err = null;
                set_error_handler(static function (int $no, string $msg) use (&$err): bool {
                    $err = $msg;
                    return true;
                });
                $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                restore_error_handler();
                if (!$crypto) {
                    $detail = (string) $err;
                    $isCert = stripos($detail, 'certificate') !== false
                        || stripos($detail, 'self signed') !== false
                        || stripos($detail, 'verify failed') !== false;
                    throw new MailerException(self::ERR_TLS,
                        ($isCert
                            ? 'O certificado do servidor não pôde ser validado'
                            : 'A negociação TLS falhou')
                        . ($detail !== '' ? ': ' . $detail : '.'), $steps, $log);
                }
                $say('… canal cifrado (TLS)');
                $t = $mark('tls', $t);
                // Depois do STARTTLS o EHLO é refeito: as capacidades mudam.
                $caps = self::try($cmd, 'EHLO ' . $ehloName, [250], 'EHLO (após TLS)', self::ERR_EHLO, $steps, $log);
                $t = $mark('ehlo_tls', $t);
            }

            if ($user !== '') {
                $upper = strtoupper($caps);
                if (str_contains($upper, 'AUTH') === false) {
                    throw new MailerException(self::ERR_AUTH_UNSUP,
                        'O servidor não anunciou suporte a autenticação (AUTH). '
                        . 'Se ele não exige usuário e senha, deixe esses campos em branco.', $steps, $log);
                }
                if (str_contains($upper, 'LOGIN')) {
                    self::try($cmd, 'AUTH LOGIN', [334], 'AUTH LOGIN', self::ERR_AUTH, $steps, $log);
                    self::try($cmd, base64_encode($user), [334], 'usuário', self::ERR_AUTH, $steps, $log, true);
                    self::try($cmd, base64_encode($pass), [235], 'senha', self::ERR_AUTH, $steps, $log, true);
                } elseif (str_contains($upper, 'PLAIN')) {
                    self::try($cmd, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass),
                        [235], 'autenticação', self::ERR_AUTH, $steps, $log, true);
                } else {
                    throw new MailerException(self::ERR_AUTH_UNSUP,
                        'O servidor só aceita métodos de autenticação que o sistema não implementa (anunciou: '
                        . trim(substr($caps, 0, 200)) . ').', $steps, $log);
                }
                $t = $mark('autenticacao', $t);
            }

            self::try($cmd, 'MAIL FROM:<' . $cfg['from'] . '>', [250], 'remetente (MAIL FROM)',
                self::ERR_FROM, $steps, $log);
            self::try($cmd, 'RCPT TO:<' . $to . '>', [250, 251], 'destinatário (RCPT TO)',
                self::ERR_RCPT, $steps, $log);
            self::try($cmd, 'DATA', [354], 'DATA', self::ERR_DATA, $steps, $log);
            $t = $mark('envelope', $t);

            $headers   = self::headers($cfg, $opts);
            $headers[] = 'To: <' . $to . '>';
            $headers[] = 'Subject: ' . self::encodeHeader($subject);
            $message   = implode("\r\n", $headers) . "\r\n\r\n" . self::body($html);
            $message   = preg_replace('/^\./m', '..', $message);
            $say('C: <mensagem de ' . strlen((string) $message) . ' bytes>');
            if (@fwrite($fp, $message . "\r\n.\r\n") === false) {
                throw new MailerException(self::ERR_DATA, 'A conexão caiu durante o envio da mensagem.', $steps, $log);
            }
            $resp = $read();
            if ((int) substr($resp, 0, 3) !== 250) {
                throw new MailerException(self::ERR_DATA,
                    'A mensagem foi recusada pelo servidor: ' . trim($resp), $steps, $log);
            }
            $mark('mensagem', $t);

            // Daqui para baixo a mensagem JÁ FOI ACEITA. Nada pode transformar
            // este envio em erro — senão a fila reenvia e o hospital recebe
            // tudo duas vezes. Servidor que fecha sem responder ao QUIT é comum.
            try {
                @fwrite($fp, "QUIT\r\n");
                $say('C: QUIT');
            } catch (\Throwable) {
                // ignorado de propósito
            }

            return ['steps' => $steps, 'transcript' => $log];
        } catch (MailerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Erros vindos dos closures chegam prefixados pelo motivo.
            $msg  = $e->getMessage();
            $code = self::ERR_CONNECT;
            foreach ([
                'TIMEOUT:'  => self::ERR_TIMEOUT,
                'ESCRITA:'  => self::ERR_CONNECT,
                'RESPOSTA:' => self::ERR_DATA,
            ] as $prefix => $mapped) {
                if (str_starts_with($msg, $prefix)) {
                    $code = $mapped;
                    $msg  = substr($msg, strlen($prefix));
                    break;
                }
            }
            throw new MailerException($code, $msg, $steps, $log);
        } finally {
            if (is_resource($fp)) {
                @fclose($fp);
            }
        }
    }

    /** Executa um comando traduzindo a falha para o código certo. */
    private static function try(callable $cmd, string $payload, array $expect, string $label,
                                string $code, array &$steps, array &$log, bool $secret = false): string
    {
        try {
            return $cmd($payload, $expect, $label, $secret);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'TIMEOUT:')) {
                throw new MailerException(self::ERR_TIMEOUT, substr($msg, 8), $steps, $log);
            }
            if (str_starts_with($msg, 'ESCRITA:')) {
                throw new MailerException(self::ERR_CONNECT, substr($msg, 8), $steps, $log);
            }
            throw new MailerException($code, str_replace('RESPOSTA:', '', $msg), $steps, $log);
        }
    }

    /** @return array<string,string> */
    private static function effectiveConfig(array $opts): array
    {
        $cfg = MailConfig::all();
        foreach ((array) ($opts['config'] ?? []) as $k => $v) {
            if (array_key_exists($k, MailConfig::FIELDS)) {
                $cfg[$k] = MailConfig::clean((string) $v);
            }
        }
        if (($cfg['from'] ?? '') === '') {
            $cfg['from'] = MailConfig::from();
        }
        if (($cfg['from_name'] ?? '') === '') {
            $cfg['from_name'] = MailConfig::fromName();
        }
        return $cfg;
    }

    private static function wantsFallback(array $opts): bool
    {
        return array_key_exists('fallback', $opts)
            ? (bool) $opts['fallback']
            : MailConfig::fallbackToMail();
    }

    private static function finish(array $res, float $t0, string $code, string $error): array
    {
        $res['code']  = $code;
        $res['error'] = MailConfig::redact($error);
        $res['ms']    = round((hrtime(true) - $t0) / 1e6, 1);
        return $res;
    }

    /** @return array<int,string> */
    private static function headers(array $cfg, array $opts = []): array
    {
        $from     = MailConfig::clean((string) ($cfg['from'] ?? ''));
        $fromName = MailConfig::clean((string) ($cfg['from_name'] ?? ''));
        $replyTo  = MailConfig::clean((string) ($opts['reply_to'] ?? $cfg['reply_to'] ?? ''));
        $domain   = substr(strrchr($from, '@') ?: '@localhost', 1);

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];
        if ($replyTo !== '' && MailConfig::isEmail($replyTo)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        return $headers;
    }

    /** Normaliza as quebras de linha do corpo (o SMTP exige CRLF). */
    private static function body(string $html): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $html) ?? $html;
    }

    private static function encodeHeader(string $text): string
    {
        $text = MailConfig::clean($text);
        return preg_match('/[^\x20-\x7E]/', $text)
            ? '=?UTF-8?B?' . base64_encode($text) . '?='
            : $text;
    }
}
