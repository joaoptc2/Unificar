<?php

declare(strict_types=1);

namespace Core;

/**
 * Cliente IMAP para LER uma caixa de e-mail.
 *
 * Por que não reaproveitar Core\MailInbox: aquilo é um DIAGNÓSTICO — abre a
 * conexão, autentica, conta as mensagens, lê alguns cabeçalhos e vai embora,
 * tudo em métodos estáticos com estado compartilhado. Serve para a tela de
 * "testar recebimento" e não serve para uma caixa de entrada:
 *
 *   • lê a resposta SEMPRE linha a linha (fgets). O corpo de uma mensagem
 *     chega num LITERAL — `{4096}\r\n` seguido de 4096 bytes crus — que pode
 *     conter qualquer coisa, inclusive uma linha que começa com a etiqueta do
 *     comando. Lendo linha a linha, o cliente confunde conteúdo com protocolo;
 *   • não sabe FETCH de corpo, nem UID, nem marcar como lida;
 *   • o estado estático impede duas conexões, e a instância única atrapalha
 *     quando cada usuário tem a sua caixa.
 *
 * Aqui a conexão é um objeto, a leitura entende literais, e nada além de ler
 * é feito: marcar como lida é opcional e explícito.
 *
 * Nada usa a extensão `imap` do PHP — ela foi separada do núcleo e falta na
 * maior parte das hospedagens compartilhadas.
 */
final class ImapCliente
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    private float $limite = 0.0;

    /** Teto de bytes de UMA mensagem. Acima disso, só o cabeçalho. */
    public const TAMANHO_MAX = 2 * 1024 * 1024;

    /** @var string[] diálogo, para a tela de diagnóstico (sem credenciais) */
    private array $registro = [];

    public function __construct(
        private string $host,
        private int $porta = 993,
        private string $seguranca = 'ssl',   // ssl | starttls | nenhuma
        private int $timeout = 15,
    ) {
    }

    // ── Conexão ────────────────────────────────────────────────────────────

    public function conectar(): void
    {
        $this->limite = microtime(true) + $this->timeout;

        $esquema = $this->seguranca === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'SNI_enabled'       => true,
        ]]);

        $erroN = 0;
        $erroS = '';
        $sock = @stream_socket_client(
            $esquema . $this->host . ':' . $this->porta,
            $erroN, $erroS, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if ($sock === false) {
            throw new \RuntimeException('Não foi possível conectar em ' . $this->host . ':' . $this->porta
                . ($erroS !== '' ? ' — ' . $erroS : '') . '.');
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;

        $saudacao = $this->lerLinha();
        if (!str_starts_with($saudacao, '* OK')) {
            $this->fechar();
            throw new \RuntimeException('O servidor não respondeu como IMAP: ' . $saudacao);
        }

        if ($this->seguranca === 'starttls') {
            $this->comando('STARTTLS');
            if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->fechar();
                throw new \RuntimeException('O servidor aceitou STARTTLS mas a criptografia falhou.');
            }
            $this->registro[] = '-- TLS ativado --';
        }
    }

    public function autenticar(string $usuario, string $senha): void
    {
        try {
            $this->comando('LOGIN ' . self::citar($usuario) . ' ' . self::citar($senha), true);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Usuário ou senha recusados pelo servidor. '
                . 'No Zoho, use uma SENHA DE APLICATIVO — a senha normal da conta é recusada '
                . 'quando há verificação em duas etapas. (' . $e->getMessage() . ')');
        }
    }

    /** Abre a caixa e devolve quantas mensagens ela tem. */
    public function selecionar(string $caixa = 'INBOX'): int
    {
        $linhas = $this->comando('SELECT ' . self::citar($caixa));
        foreach ($linhas as $l) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $l, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    public function fechar(): void
    {
        if (is_resource($this->sock)) {
            try {
                $this->comando('LOGOUT');
            } catch (\Throwable) {
                // despedida malsucedida não é erro
            }
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    // ── Leitura de mensagens ───────────────────────────────────────────────

    /**
     * Cabeçalhos das $quantos mensagens mais recentes, da mais nova para a
     * mais velha.
     *
     * Busca só os campos necessários: puxar a mensagem inteira para montar
     * uma lista de vinte linhas gastaria megabytes por abertura de tela.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listar(int $total, int $quantos = 25): array
    {
        if ($total <= 0) {
            return [];
        }
        $de  = max(1, $total - $quantos + 1);
        $res = $this->comando(
            "FETCH {$de}:{$total} (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID)])"
        );

        $msgs = [];
        foreach ($this->agrupar($res) as $item) {
            $cab = Mime::cabecalhos($item['dados']);
            $dataBruta = (string) preg_replace('/^\s*[A-Za-z]{3,9},\s*/', '', trim($cab['date'] ?? ''));
            $ts = $dataBruta !== '' ? strtotime($dataBruta) : false;
            $msgs[] = [
                'uid'     => $item['uid'],
                'seq'     => $item['seq'],
                'lida'    => str_contains($item['flags'], '\\Seen'),
                'de'      => Mime::endereco($cab['from'] ?? ''),
                'assunto' => Mime::decodeCabecalho($cab['subject'] ?? '') ?: '(sem assunto)',
                'data'    => $ts !== false ? date('Y-m-d H:i:s', $ts) : '',
            ];
        }
        // Mais recentes primeiro. A ordenação é por DATA e, empatando, pelo
        // número de sequência — em caixa com datas malformadas a sequência é
        // o único critério que o servidor garante.
        usort($msgs, fn ($a, $b) => [$b['data'], $b['seq']] <=> [$a['data'], $a['seq']]);
        return $msgs;
    }

    /**
     * Uma mensagem inteira, já decodificada.
     *
     * @return array<string,mixed>|null
     */
    public function mensagem(int $uid, bool $marcarLida = false): ?array
    {
        $res  = $this->comando("UID FETCH {$uid} (BODY.PEEK[])");
        $itens = $this->agrupar($res);
        if ($itens === []) {
            return null;
        }
        $bruto = $itens[0]['dados'];
        if (strlen($bruto) > self::TAMANHO_MAX) {
            // Não é erro: é recusa consciente. Uma mensagem de 40 MB com um
            // anexo dentro derrubaria a página por falta de memória.
            $bruto = substr($bruto, 0, self::TAMANHO_MAX);
        }
        $m = Mime::mensagem($bruto);
        $m['uid'] = $uid;

        if ($marcarLida) {
            try {
                $this->comando("UID STORE {$uid} +FLAGS (\\Seen)");
            } catch (\Throwable) {
                // Caixa somente-leitura: mostrar a mensagem continua valendo.
            }
        }
        return $m;
    }

    // ── Protocolo ──────────────────────────────────────────────────────────

    /**
     * Manda um comando e devolve as linhas da resposta.
     *
     * Os literais são resolvidos aqui: uma linha terminada em `{n}` significa
     * que vêm n bytes CRUS logo depois, e eles entram como uma "linha" só.
     *
     * @return string[]
     */
    private function comando(string $cmd, bool $sigiloso = false): array
    {
        $tag = 'P' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $this->escrever($tag . ' ' . $cmd, $sigiloso);

        $linhas = [];
        $lidas  = 0;
        while (true) {
            $l = $this->lerLinha();

            // Literal: `... {1234}` no fim da linha.
            if (preg_match('/\{(\d+)\}$/', $l, $m)) {
                $n = (int) $m[1];
                if ($n > self::TAMANHO_MAX * 4) {
                    throw new \RuntimeException('O servidor anunciou um bloco grande demais (' . $n . ' bytes).');
                }
                $linhas[] = $l;
                $linhas[] = "\x00LITERAL\x00" . $this->lerBytes($n);
                continue;
            }

            if (str_starts_with($l, $tag . ' ')) {
                $resto = trim(substr($l, strlen($tag) + 1));
                if (!str_starts_with(strtoupper($resto), 'OK')) {
                    throw new \RuntimeException($resto);
                }
                return $linhas;
            }

            $linhas[] = $l;
            if (++$lidas > 20000) {
                throw new \RuntimeException('O servidor mandou linhas demais sem concluir o comando.');
            }
        }
    }

    /**
     * Junta as linhas de um FETCH em itens {uid, seq, flags, dados}.
     *
     * @param string[] $linhas
     * @return array<int, array{uid:int, seq:int, flags:string, dados:string}>
     */
    private function agrupar(array $linhas): array
    {
        $itens = [];
        $atual = null;
        foreach ($linhas as $l) {
            if (str_starts_with($l, "\x00LITERAL\x00")) {
                if ($atual !== null) {
                    $atual['dados'] .= substr($l, 9);
                }
                continue;
            }
            if (preg_match('/^\*\s+(\d+)\s+FETCH\s*\((.*)$/is', $l, $m)) {
                if ($atual !== null) {
                    $itens[] = $atual;
                }
                $cabecalho = $m[2];
                preg_match('/UID\s+(\d+)/i', $cabecalho, $mu);
                preg_match('/FLAGS\s*\(([^)]*)\)/i', $cabecalho, $mf);
                $atual = [
                    'uid'   => (int) ($mu[1] ?? 0),
                    'seq'   => (int) $m[1],
                    'flags' => $mf[1] ?? '',
                    'dados' => '',
                ];
            }
        }
        if ($atual !== null) {
            $itens[] = $atual;
        }
        return $itens;
    }

    private function escrever(string $linha, bool $sigiloso = false): void
    {
        if (!is_resource($this->sock)) {
            throw new \RuntimeException('A conexão com o servidor foi encerrada.');
        }
        $this->registro[] = '> ' . ($sigiloso ? '(credenciais omitidas)' : $linha);
        if (@fwrite($this->sock, $linha . "\r\n") === false) {
            throw new \RuntimeException('Falha ao enviar comando ao servidor.');
        }
    }

    private function lerLinha(): string
    {
        $this->vivo();
        $l = @fgets($this->sock, 16384);
        $this->conferirTimeout();
        if ($l === false) {
            throw new \RuntimeException('O servidor encerrou a conexão sem responder.');
        }
        $l = rtrim($l, "\r\n");
        // O registro é cortado: uma mensagem inteira no diálogo não ajuda
        // ninguém a diagnosticar e enche a memória.
        $this->registro[] = '< ' . (strlen($l) > 300 ? substr($l, 0, 300) . '…' : $l);
        return $l;
    }

    /** Lê EXATAMENTE $n bytes — é isto que um literal exige. */
    private function lerBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $this->vivo();
            $pedaco = @fread($this->sock, min(65536, $n - strlen($buf)));
            $this->conferirTimeout();
            if ($pedaco === false || $pedaco === '') {
                throw new \RuntimeException('A conexão caiu no meio de uma mensagem.');
            }
            $buf .= $pedaco;
        }
        $this->registro[] = '< (' . $n . ' bytes de conteúdo)';
        return $buf;
    }

    private function vivo(): void
    {
        if (!is_resource($this->sock)) {
            throw new \RuntimeException('A conexão com o servidor foi encerrada.');
        }
        if (microtime(true) > $this->limite) {
            throw new \RuntimeException('Tempo esgotado aguardando o servidor de e-mail.');
        }
    }

    private function conferirTimeout(): void
    {
        $meta = stream_get_meta_data($this->sock);
        if (!empty($meta['timed_out'])) {
            throw new \RuntimeException('Tempo esgotado aguardando o servidor de e-mail.');
        }
    }

    /** Aspas de string IMAP. */
    private static function citar(string $v): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $v) . '"';
    }

    /** @return string[] */
    public function registro(): array
    {
        return $this->registro;
    }
}
