<?php

declare(strict_types=1);

namespace Core;

/**
 * Teste de RECEBIMENTO de e-mail (IMAP ou POP3), por sockets.
 *
 * Por que à mão: a extensão `imap` do PHP foi separada do núcleo e não está
 * presente na maioria das hospedagens (nem neste servidor). Em vez de exigir
 * a extensão — o que deixaria o teste indisponível justamente onde ele é
 * mais necessário — falamos o protocolo direto, como o Mailer já faz com
 * SMTP. Aqui só se PRECISA de pouco: conectar, autenticar, contar mensagens
 * e ler os cabeçalhos das últimas. Nada de baixar anexos.
 *
 * O uso previsto é fechar o ciclo com o teste de envio: manda-se para a
 * própria caixa e confere-se a chegada.
 */
final class MailInbox
{
    public const ERR_DISABLED  = 'DESABILITADO';
    public const ERR_NO_HOST   = 'SEM_SERVIDOR';
    public const ERR_DNS       = 'DNS';
    public const ERR_CONNECT   = 'CONEXAO';
    public const ERR_TIMEOUT   = 'TEMPO_ESGOTADO';
    public const ERR_TLS       = 'TLS';
    public const ERR_BANNER    = 'SAUDACAO';
    public const ERR_AUTH      = 'AUTENTICACAO';
    public const ERR_SELECT    = 'CAIXA';
    public const ERR_PROTOCOL  = 'PROTOCOLO';

    /** @var resource|null */
    private static $sock = null;
    private static int $tag = 0;
    private static array $transcript = [];
    private static float $prazo = 0.0;

    // ── Configuração ───────────────────────────────────────────────────────

    public const DEFAULTS = [
        'enabled'    => '0',
        'protocol'   => 'imap',        // imap | pop3
        'host'       => '',
        'port'       => '',            // vazio = padrão do protocolo+segurança
        'security'   => 'ssl',         // ssl | starttls | none
        'user'       => '',
        'mailbox'    => 'INBOX',
        'timeout'    => '12',
        'verify_peer' => '1',
    ];

    public static function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $padrao) {
            $out[$k] = (string) Settings::get('mail.inbox.' . $k, $padrao);
        }
        if ($out['port'] === '') {
            $out['port'] = (string) self::defaultPort($out['protocol'], $out['security']);
        }
        return $out;
    }

    public static function defaultPort(string $proto, string $sec): int
    {
        if ($proto === 'pop3') {
            return $sec === 'ssl' ? 995 : 110;
        }
        return $sec === 'ssl' ? 993 : 143;
    }

    public static function save(array $v, ?string $password = null): void
    {
        foreach (self::DEFAULTS as $k => $padrao) {
            if (!array_key_exists($k, $v)) {
                continue;
            }
            Settings::set('mail.inbox.' . $k, self::normalize($k, (string) $v[$k]));
        }
        if ($password !== null && $password !== '') {
            // Mesma proteção da senha do SMTP: nunca em texto claro no banco.
            Settings::set('mail.inbox.password', MailSecret::hide($password));
        }
        Settings::flush();
    }

    public static function password(): string
    {
        return MailSecret::reveal((string) Settings::get('mail.inbox.password', ''));
    }

    public static function passwordIsSet(): bool
    {
        // Ao VALOR decifrado, não ao blob — ver MailSecret::unreadable().
        $blob = (string) Settings::get('mail.inbox.password', '');
        return $blob !== '' && !MailSecret::unreadable($blob);
    }

    /** Guardada, mas cifrada com uma chave que este servidor não tem? */
    public static function passwordUnreadable(): bool
    {
        $blob = (string) Settings::get('mail.inbox.password', '');
        return $blob !== '' && MailSecret::unreadable($blob);
    }

    public static function forgetPassword(): void
    {
        Settings::forget('mail.inbox.password');
        Settings::flush();
    }

    private static function normalize(string $key, string $raw): string
    {
        $v = trim($raw);
        return match ($key) {
            'enabled', 'verify_peer' => $v === '1' ? '1' : '0',
            'protocol' => $v === 'pop3' ? 'pop3' : 'imap',
            'security' => in_array($v, ['ssl', 'starttls', 'none'], true) ? $v : 'ssl',
            'port'     => $v === '' ? '' : (string) max(1, min(65535, (int) $v)),
            'timeout'  => (string) max(3, min(60, (int) $v ?: 12)),
            'host'     => preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $v) ?? '',
            'mailbox'  => mb_substr(preg_replace('/[\r\n]/', '', $v) ?? '', 0, 100) ?: 'INBOX',
            default    => mb_substr(preg_replace('/[\r\n]/', '', $v) ?? '', 0, 200),
        };
    }

    // ── Teste ──────────────────────────────────────────────────────────────

    /**
     * Conecta, autentica e lê as últimas mensagens.
     *
     * @param array  $override configuração alternativa (testar sem salvar)
     * @param string|null $password senha alternativa (idem)
     * @param string $procurar assunto a procurar (fecha o ciclo com o envio)
     *
     * @return array{ok:bool, code:string, error:string, total:int,
     *                mensagens:array, encontrada:?array, transcript:array, ms:float}
     */
    public static function test(array $override = [], ?string $password = null, string $procurar = ''): array
    {
        $t0  = hrtime(true);
        $cfg = array_merge(self::all(), array_filter($override, static fn ($v) => $v !== null && $v !== ''));
        $senha = $password !== null && $password !== '' ? $password : self::password();

        self::$transcript = [];
        self::$tag = 0;

        $res = [
            'ok' => false, 'code' => '', 'error' => '', 'total' => 0,
            'mensagens' => [], 'encontrada' => null, 'transcript' => [], 'ms' => 0.0,
            'protocol' => $cfg['protocol'],
        ];

        if ($cfg['host'] === '') {
            return self::fim($res, $t0, self::ERR_NO_HOST, 'Informe o servidor de recebimento (IMAP ou POP3).');
        }
        if ($cfg['user'] === '') {
            return self::fim($res, $t0, self::ERR_AUTH, 'Informe o usuário da caixa.');
        }

        $timeout = max(3, min(60, (int) $cfg['timeout']));
        // Orçamento total, como no Mailer: um servidor que aceita a conexão e
        // depois emudece não pode segurar a página até o limite do PHP.
        $orc = $timeout * 3;
        $max = (int) ini_get('max_execution_time');
        if ($max > 0) {
            $orc = min($orc, max(3, (int) floor($max * 0.8)));
        }
        self::$prazo = (float) hrtime(true) + $orc * 1e9;

        try {
            self::conectar($cfg, $timeout);
            if ($cfg['protocol'] === 'pop3') {
                self::pop3($cfg, $senha, $res, $procurar);
            } else {
                self::imap($cfg, $senha, $res, $procurar);
            }
            $res['ok'] = true;
            return self::fim($res, $t0, '', '');
        } catch (MailerException $e) {
            return self::fim($res, $t0, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return self::fim($res, $t0, self::ERR_PROTOCOL, $e->getMessage());
        } finally {
            self::fechar();
        }
    }

    private static function fim(array $res, float $t0, string $code, string $erro): array
    {
        $res['code']       = $code;
        $res['error']      = $erro === '' ? '' : MailConfig::redact($erro);
        $res['transcript'] = self::$transcript;
        $res['ms']         = round((hrtime(true) - $t0) / 1e6, 1);
        return $res;
    }

    // ── Conexão ────────────────────────────────────────────────────────────

    private static function conectar(array $cfg, int $timeout): void
    {
        $porta = (int) ($cfg['port'] ?: self::defaultPort($cfg['protocol'], $cfg['security']));
        $host  = $cfg['host'];

        // DNS antes de abrir: erro de digitação no servidor vira uma mensagem
        // clara em vez de um "conexão recusada" genérico.
        if (!filter_var($host, FILTER_VALIDATE_IP) && !gethostbynamel($host) && !@dns_get_record($host, DNS_AAAA)) {
            throw new MailerException(self::ERR_DNS, 'Servidor não encontrado: ' . $host);
        }

        $esquema = $cfg['security'] === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => $cfg['verify_peer'] === '1',
            'verify_peer_name'  => $cfg['verify_peer'] === '1',
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $sock = @stream_socket_client($esquema . $host . ':' . $porta, $errno, $errstr,
                                      $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new MailerException(
                $errno === SOCKET_ETIMEDOUT ? self::ERR_TIMEOUT : self::ERR_CONNECT,
                'Não foi possível conectar em ' . $host . ':' . $porta . ' — ' . ($errstr ?: 'sem detalhe')
            );
        }
        stream_set_timeout($sock, $timeout);
        self::$sock = $sock;
        self::reg('conectado a ' . $host . ':' . $porta . ' (' . $cfg['security'] . ')');
    }

    private static function fechar(): void
    {
        if (is_resource(self::$sock)) {
            @fclose(self::$sock);
        }
        self::$sock = null;
    }

    private static function reg(string $linha): void
    {
        if (count(self::$transcript) < 120) {
            self::$transcript[] = MailConfig::redact($linha);
        }
    }

    private static function expirou(): bool
    {
        return (float) hrtime(true) > self::$prazo;
    }

    private static function escrever(string $linha, bool $sigiloso = false): void
    {
        if (!is_resource(self::$sock)) {
            throw new MailerException(self::ERR_CONNECT, 'Conexão fechada.');
        }
        self::reg('> ' . ($sigiloso ? '(credenciais omitidas)' : $linha));
        if (@fwrite(self::$sock, $linha . "\r\n") === false) {
            throw new MailerException(self::ERR_CONNECT, 'Falha ao escrever no servidor.');
        }
    }

    private static function ler(): string
    {
        if (!is_resource(self::$sock)) {
            throw new MailerException(self::ERR_CONNECT, 'Conexão fechada.');
        }
        if (self::expirou()) {
            throw new MailerException(self::ERR_TIMEOUT, 'Tempo esgotado aguardando o servidor.');
        }
        $linha = @fgets(self::$sock, 8192);
        $meta  = stream_get_meta_data(self::$sock);
        if (!empty($meta['timed_out'])) {
            throw new MailerException(self::ERR_TIMEOUT, 'Tempo esgotado aguardando o servidor.');
        }
        if ($linha === false) {
            throw new MailerException(self::ERR_PROTOCOL, 'O servidor encerrou a conexão.');
        }
        $linha = rtrim($linha, "\r\n");
        self::reg('< ' . $linha);
        return $linha;
    }

    // ── IMAP ───────────────────────────────────────────────────────────────

    private static function imap(array $cfg, string $senha, array &$res, string $procurar): void
    {
        $saud = self::ler();
        if (!str_starts_with($saud, '* OK')) {
            throw new MailerException(self::ERR_BANNER, 'Saudação IMAP inesperada: ' . $saud);
        }

        if ($cfg['security'] === 'starttls') {
            self::imapCmd('STARTTLS');
            if (!@stream_socket_enable_crypto(self::$sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailerException(self::ERR_TLS, 'Falha ao ativar TLS (STARTTLS).');
            }
            self::reg('-- TLS ativado --');
        }

        try {
            self::imapCmd('LOGIN ' . self::quote($cfg['user']) . ' ' . self::quote($senha), true);
        } catch (MailerException $e) {
            throw new MailerException(self::ERR_AUTH,
                'Usuário ou senha recusados pelo servidor IMAP. ' . $e->getMessage());
        }

        $caixa = $cfg['mailbox'] ?: 'INBOX';
        try {
            $sel = self::imapCmd('SELECT ' . self::quote($caixa));
        } catch (MailerException $e) {
            throw new MailerException(self::ERR_SELECT, 'Não foi possível abrir a caixa "' . $caixa . '". ' . $e->getMessage());
        }
        foreach ($sel as $l) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $l, $m)) {
                $res['total'] = (int) $m[1];
            }
        }

        if ($res['total'] > 0) {
            $de   = max(1, $res['total'] - 9);
            $ate  = $res['total'];
            $resp = self::imapCmd("FETCH {$de}:{$ate} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])");
            $res['mensagens'] = self::cabecalhosImap($resp);
        }

        self::procurarAssunto($res, $procurar);
        try { self::imapCmd('LOGOUT'); } catch (\Throwable $ignorado) { /* despedida não é erro */ }
    }

    /** @return string[] linhas da resposta */
    private static function imapCmd(string $cmd, bool $sigiloso = false): array
    {
        $tag = 'A' . str_pad((string) ++self::$tag, 3, '0', STR_PAD_LEFT);
        self::escrever($tag . ' ' . $cmd, $sigiloso);

        $linhas = [];
        while (true) {
            $l = self::ler();
            if (str_starts_with($l, $tag . ' ')) {
                $resto = substr($l, strlen($tag) + 1);
                if (!str_starts_with($resto, 'OK')) {
                    throw new MailerException(self::ERR_PROTOCOL, trim($resto));
                }
                return $linhas;
            }
            $linhas[] = $l;
            if (count($linhas) > 500) {
                throw new MailerException(self::ERR_PROTOCOL, 'Resposta longa demais do servidor.');
            }
        }
    }

    private static function quote(string $v): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $v) . '"';
    }

    /** Agrupa as linhas de cabeçalho devolvidas pelo FETCH em mensagens. */
    private static function cabecalhosImap(array $linhas): array
    {
        $msgs = [];
        $atual = null;
        foreach ($linhas as $l) {
            if (preg_match('/^\*\s+(\d+)\s+FETCH/i', $l, $m)) {
                if ($atual) { $msgs[] = $atual; }
                $atual = ['n' => (int) $m[1], 'from' => '', 'subject' => '', 'date' => ''];
                continue;
            }
            if (!$atual) { continue; }
            if (preg_match('/^From:\s*(.+)$/i', $l, $m))    { $atual['from']    = self::decodeHeader(trim($m[1])); }
            if (preg_match('/^Subject:\s*(.+)$/i', $l, $m)) { $atual['subject'] = self::decodeHeader(trim($m[1])); }
            if (preg_match('/^Date:\s*(.+)$/i', $l, $m))    { $atual['date']    = trim($m[1]); }
        }
        if ($atual) { $msgs[] = $atual; }
        return array_reverse($msgs);   // mais recentes primeiro
    }

    // ── POP3 ───────────────────────────────────────────────────────────────

    private static function pop3(array $cfg, string $senha, array &$res, string $procurar): void
    {
        $saud = self::ler();
        if (!str_starts_with($saud, '+OK')) {
            throw new MailerException(self::ERR_BANNER, 'Saudação POP3 inesperada: ' . $saud);
        }

        if ($cfg['security'] === 'starttls') {
            self::pop3Cmd('STLS');
            if (!@stream_socket_enable_crypto(self::$sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailerException(self::ERR_TLS, 'Falha ao ativar TLS (STLS).');
            }
            self::reg('-- TLS ativado --');
        }

        try {
            self::pop3Cmd('USER ' . $cfg['user'], true);
            self::pop3Cmd('PASS ' . $senha, true);
        } catch (MailerException $e) {
            throw new MailerException(self::ERR_AUTH,
                'Usuário ou senha recusados pelo servidor POP3. ' . $e->getMessage());
        }

        $stat = self::pop3Cmd('STAT');
        if (preg_match('/^\+OK\s+(\d+)/', $stat, $m)) {
            $res['total'] = (int) $m[1];
        }

        $msgs = [];
        $de = max(1, $res['total'] - 9);
        for ($i = $res['total']; $i >= $de; $i--) {
            self::pop3Cmd('TOP ' . $i . ' 0');
            $msg = ['n' => $i, 'from' => '', 'subject' => '', 'date' => ''];
            while (true) {
                $l = self::ler();
                if ($l === '.') { break; }
                if (preg_match('/^From:\s*(.+)$/i', $l, $mm))    { $msg['from']    = self::decodeHeader(trim($mm[1])); }
                if (preg_match('/^Subject:\s*(.+)$/i', $l, $mm)) { $msg['subject'] = self::decodeHeader(trim($mm[1])); }
                if (preg_match('/^Date:\s*(.+)$/i', $l, $mm))    { $msg['date']    = trim($mm[1]); }
            }
            $msgs[] = $msg;
        }
        $res['mensagens'] = $msgs;

        self::procurarAssunto($res, $procurar);
        try { self::pop3Cmd('QUIT'); } catch (\Throwable $ignorado) { /* despedida não é erro */ }
    }

    private static function pop3Cmd(string $cmd, bool $sigiloso = false): string
    {
        self::escrever($cmd, $sigiloso);
        $l = self::ler();
        if (!str_starts_with($l, '+OK')) {
            throw new MailerException(self::ERR_PROTOCOL, trim($l));
        }
        return $l;
    }

    // ── Comuns ─────────────────────────────────────────────────────────────

    private static function procurarAssunto(array &$res, string $procurar): void
    {
        if ($procurar === '') {
            return;
        }
        foreach ($res['mensagens'] as $m) {
            if (str_contains($m['subject'], $procurar)) {
                $res['encontrada'] = $m;
                return;
            }
        }
    }

    /** Decodifica "=?UTF-8?B?...?=" dos assuntos (RFC 2047). */
    private static function decodeHeader(string $v): string
    {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $d === false ? $v : $d;
    }

    /** Explicação e próximo passo para cada código de erro. */
    public static function explain(string $code): string
    {
        return match ($code) {
            self::ERR_NO_HOST  => 'Nenhum servidor de recebimento informado.',
            self::ERR_DNS      => 'O nome do servidor não resolve. Confira a digitação (ex.: imap.seudominio.com.br).',
            self::ERR_CONNECT  => 'A conexão não foi aceita. Confira a porta e se o firewall do servidor permite a saída (993/143 para IMAP, 995/110 para POP3).',
            self::ERR_TIMEOUT  => 'O servidor aceitou a conexão mas não respondeu a tempo.',
            self::ERR_TLS      => 'A conexão segura falhou. Tente outra opção de segurança (SSL direto x STARTTLS).',
            self::ERR_BANNER   => 'A resposta inicial não parece ser deste protocolo. Confira se a porta corresponde a IMAP ou POP3.',
            self::ERR_AUTH     => 'Usuário ou senha recusados. Em contas com verificação em duas etapas, use uma senha de aplicativo.',
            self::ERR_SELECT   => 'A caixa informada não existe. Em geral o nome correto é INBOX.',
            self::ERR_PROTOCOL => 'O servidor recusou um comando. Veja a conversa abaixo.',
            default            => '',
        };
    }
}
