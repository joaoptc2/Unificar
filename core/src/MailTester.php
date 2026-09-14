<?php

declare(strict_types=1);

namespace Core;

/**
 * Teste de entrega de e-mail (Administração > E-mail).
 *
 * Usa o MESMO Core\Mailer que o sistema usa para valer — testar por um
 * caminho paralelo provaria o caminho paralelo, não o envio real. O que este
 * serviço acrescenta é o cuidado em volta: quem pode disparar, com que
 * frequência, o que fica registrado e o que nunca pode aparecer na tela.
 *
 * Três caminhos, porque significam coisas diferentes:
 *   - 'smtp'  → conversa direta com o servidor de saída. Um 250 no fim
 *               significa "o servidor aceitou"; a entrega na caixa do
 *               destinatário ainda depende do provedor dele.
 *   - 'mail'  → função mail() do PHP. Prova bem menos: só que o sendmail
 *               local aceitou a mensagem.
 *   - 'queue' → enfileira e espera o cron. É o teste que prova que o
 *               AGENDAMENTO existe — sem ele, tudo fica pendente para sempre.
 *
 * O assunto e o corpo são fixos, montados aqui. Nenhum texto do formulário
 * entra na mensagem: um botão que envia texto livre com o cabeçalho do
 * hospital seria uma ferramenta de phishing hospedada pelo próprio hospital.
 */
final class MailTester
{
    /** Testes por hora (qualquer administrador, somados). */
    public const LIMITE_HORA = 12;

    /** Caminhos possíveis. */
    public const CAMINHOS = [
        'smtp'  => 'Direto pelo servidor SMTP',
        'mail'  => 'Função mail() do PHP',
        'queue' => 'Pela fila (prova que o cron está agendado)',
    ];

    /**
     * Dispara um teste e devolve o id da linha em mail_tests.
     *
     * @param array<string,mixed> $override configuração ainda não salva (opcional)
     * @return array{id:int, ok:bool, code:string, error:string}
     */
    public static function run(string $to, string $path = 'smtp', array $override = [], ?string $password = null): array
    {
        $to = MailConfig::clean($to);
        if (!MailConfig::isEmail($to)) {
            return ['id' => 0, 'ok' => false, 'code' => Mailer::ERR_BAD_ADDRESS,
                    'error' => 'Endereço de destino inválido.'];
        }
        if (!array_key_exists($path, self::CAMINHOS)) {
            $path = 'smtp';
        }
        if (self::countLastHour() >= self::LIMITE_HORA) {
            return ['id' => 0, 'ok' => false, 'code' => 'LIMITE',
                    'error' => 'Limite de ' . self::LIMITE_HORA . ' testes por hora atingido. '
                             . 'Tente novamente mais tarde.'];
        }

        // Grava ANTES de enviar: um teste que estoura o tempo de execução tem
        // de contar para o limite, senão o limite não limita nada.
        $id = self::open($to, $path);

        $assunto = 'Teste de entrega — ' . Branding::name();
        $corpo   = self::body($to, $path);

        if ($path === 'queue') {
            $queueId = MailQueue::enqueueTest($to, $assunto, $corpo);
            self::close($id, [
                'ok' => $queueId > 0, 'code' => $queueId > 0 ? '' : 'FILA',
                'error' => $queueId > 0 ? '' : 'Não foi possível enfileirar a mensagem.',
                'steps' => [], 'transcript' => [], 'ms' => 0.0, 'path' => 'queue',
            ], $queueId);
            return ['id' => $id, 'ok' => $queueId > 0, 'code' => '', 'error' => ''];
        }

        $opts = [
            'transcript' => true,
            // O teste nunca cai para mail() escondido: se o SMTP falhou, quem
            // pediu o teste precisa ver a falha do SMTP.
            'fallback'   => false,
            'timeout'    => self::safeTimeout(),
        ];
        if ($override !== []) {
            $opts['config'] = $override;
        }
        if ($password !== null) {
            $opts['password'] = $password;
        }
        if ($path === 'mail') {
            // Força o caminho da função mail(): sem host, com queda permitida.
            $opts['config'] = array_merge($opts['config'] ?? [], ['host' => '']);
            $opts['fallback'] = true;
        }
        // A configuração em teste pode estar desligada; o teste vale assim mesmo.
        $opts['config'] = array_merge($opts['config'] ?? [], ['enabled' => '1']);

        $res = Mailer::sendDetailed($to, $assunto, $corpo, $opts);
        self::close($id, $res, null);

        return ['id' => $id, 'ok' => $res['ok'], 'code' => (string) $res['code'],
                'error' => (string) $res['error']];
    }

    /** Sempre abaixo do tempo máximo de execução: melhor um erro claro que um 500 em branco. */
    private static function safeTimeout(): int
    {
        $max = (int) ini_get('max_execution_time');
        $cfg = MailConfig::timeout();
        if ($max <= 0) {
            return $cfg; // sem limite (CLI ou host generoso)
        }
        return max(3, min($cfg, (int) floor($max * 0.6)));
    }

    /** Corpo fixo: sem nenhum texto vindo do formulário. */
    private static function body(string $to, string $path): string
    {
        $org   = core_e(Branding::name());
        $quem  = core_e((string) (Auth::user()['name'] ?? 'administrador'));
        $ip    = core_e((string) ($_SERVER['REMOTE_ADDR'] ?? 'linha de comando'));
        $data  = core_e(date('d/m/Y H:i:s'));
        $via   = core_e(self::CAMINHOS[$path] ?? $path);
        $cor   = core_e(Branding::get('primary'));
        $dest  = core_e($to);

        return '<!DOCTYPE html><html lang="pt-BR"><body style="font-family:Arial,Helvetica,sans-serif;'
             . 'color:#333;margin:0;padding:16px">'
             . '<div style="max-width:560px;margin:0 auto;border:1px solid #e6e6e6;border-radius:8px;padding:20px">'
             . '<h2 style="color:' . $cor . ';margin:0 0 12px">Teste de entrega de e-mail</h2>'
             . '<p style="margin:0 0 16px">Se você está lendo esta mensagem, o envio de e-mail do '
             . '<strong>' . $org . '</strong> está funcionando.</p>'
             . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
             . '<tr><td style="padding:6px 8px;border:1px solid #eee"><strong>Enviado em</strong></td>'
             . '<td style="padding:6px 8px;border:1px solid #eee">' . $data . '</td></tr>'
             . '<tr><td style="padding:6px 8px;border:1px solid #eee"><strong>Caminho</strong></td>'
             . '<td style="padding:6px 8px;border:1px solid #eee">' . $via . '</td></tr>'
             . '<tr><td style="padding:6px 8px;border:1px solid #eee"><strong>Destinatário</strong></td>'
             . '<td style="padding:6px 8px;border:1px solid #eee">' . $dest . '</td></tr>'
             . '<tr><td style="padding:6px 8px;border:1px solid #eee"><strong>Solicitado por</strong></td>'
             . '<td style="padding:6px 8px;border:1px solid #eee">' . $quem . ' (' . $ip . ')</td></tr>'
             . '</table>'
             . '<p style="margin:16px 0 0;color:#888;font-size:12px">Mensagem automática de teste, '
             . 'disparada em Administração &gt; E-mail. Não é necessário responder.</p>'
             . '</div></body></html>';
    }

    private static function open(string $to, string $path): int
    {
        $user = Auth::user();
        DB::execute(
            'INSERT INTO mail_tests (user_id, user_name, to_email, path, result, ip)
             VALUES (?, ?, ?, ?, "running", ?)',
            [
                $user['id'] ?? null,
                mb_substr((string) ($user['name'] ?? 'linha de comando'), 0, 150),
                $to, $path,
                mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]
        );
        return DB::lastId();
    }

    /** @param array<string,mixed> $res */
    private static function close(int $id, array $res, ?int $queueId): void
    {
        DB::execute(
            'UPDATE mail_tests
                SET finished_at = NOW(), result = ?, error_code = ?, error_message = ?,
                    duration_ms = ?, steps = ?, transcript = ?, queue_id = ?, path = ?
              WHERE id = ?',
            [
                !empty($res['ok']) ? 'ok' : 'fail',
                mb_substr((string) ($res['code'] ?? ''), 0, 40) ?: null,
                mb_substr(MailConfig::redact((string) ($res['error'] ?? '')), 0, 500) ?: null,
                (int) round((float) ($res['ms'] ?? 0)),
                json_encode($res['steps'] ?? [], JSON_UNESCAPED_UNICODE),
                mb_substr(MailConfig::redact(implode("\n", (array) ($res['transcript'] ?? []))), 0, 60000) ?: null,
                $queueId ?: null,
                (string) ($res['path'] ?? 'smtp'),
                $id,
            ]
        );
        self::purge();
    }

    /** Testes na última hora (inclui os 'running' recentes, para o limite não ser burlado). */
    public static function countLastHour(): int
    {
        try {
            $r = DB::query('SELECT COUNT(*) n FROM mail_tests WHERE created_at > (NOW() - INTERVAL 1 HOUR)');
            return (int) ($r[0]['n'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        try {
            return DB::query('SELECT * FROM mail_tests ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function find(int $id): ?array
    {
        try {
            return DB::query('SELECT * FROM mail_tests WHERE id = ?', [$id])[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Expurgo oportunista: transcript é volumoso e não serve como histórico
     * permanente. Também destrava os 'running' que morreram no meio (senão o
     * limite por hora ficaria preso para sempre).
     */
    private static function purge(): void
    {
        try {
            DB::execute(
                'UPDATE mail_tests SET result = "fail", error_code = "INTERROMPIDO",
                        error_message = "O teste não terminou (tempo de execução esgotado?)."
                  WHERE result = "running" AND created_at < (NOW() - INTERVAL 10 MINUTE)'
            );
            if (random_int(1, 20) === 1) {
                DB::execute('DELETE FROM mail_tests WHERE created_at < (NOW() - INTERVAL 90 DAY)');
            }
        } catch (\Throwable) {
        }
    }

    /** Texto de ajuda para cada código de erro — é o que transforma o teste em conserto. */
    public static function explain(string $code): string
    {
        return match ($code) {
            Mailer::ERR_DISABLED    => 'O envio está desligado. Marque "Enviar e-mails" na aba Configuração.',
            Mailer::ERR_NO_HOST     => 'Nenhum servidor SMTP informado. Preencha o servidor na aba Configuração '
                                     . '(ou use o caminho "função mail()" se a hospedagem entrega por sendmail).',
            Mailer::ERR_BAD_ADDRESS => 'Endereço inválido. Confira o destinatário e o remetente.',
            Mailer::ERR_DNS         => 'O nome do servidor não foi resolvido. Verifique se está escrito certo '
                                     . '(ex.: smtp.seudominio.com.br) e se a hospedagem permite consulta de DNS.',
            Mailer::ERR_CONNECT     => 'Não foi possível conectar. Em geral é porta bloqueada pela hospedagem: '
                                     . 'tente 587 com STARTTLS ou 465 com SSL/TLS direto.',
            Mailer::ERR_TIMEOUT     => 'O servidor não respondeu a tempo. Porta filtrada ou servidor sobrecarregado.',
            Mailer::ERR_BANNER      => 'O que respondeu na porta não parece ser um servidor de e-mail. '
                                     . 'Confira a porta.',
            Mailer::ERR_EHLO        => 'O servidor recusou a apresentação (EHLO). Alguns exigem um nome com '
                                     . 'domínio: preencha "Nome na apresentação (EHLO)".',
            Mailer::ERR_STARTTLS    => 'O servidor não aceitou STARTTLS nessa porta. Use SSL/TLS direto na 465 '
                                     . 'ou confirme a porta com o provedor.',
            Mailer::ERR_TLS         => 'A conexão segura falhou. Certificado vencido, nome do servidor diferente '
                                     . 'do certificado, ou versão de TLS incompatível.',
            Mailer::ERR_AUTH        => 'Usuário ou senha recusados. Em provedores com verificação em duas etapas '
                                     . '(Gmail, Outlook) é preciso gerar uma "senha de aplicativo".',
            Mailer::ERR_AUTH_UNSUP  => 'O servidor não oferece a autenticação pedida. Se ele é interno e não exige '
                                     . 'login, deixe usuário e senha em branco.',
            Mailer::ERR_FROM        => 'O remetente foi recusado. Quase sempre o endereço "De" precisa pertencer '
                                     . 'ao domínio da conta SMTP.',
            Mailer::ERR_RCPT        => 'O destinatário foi recusado. Endereço inexistente ou o servidor não aceita '
                                     . 'enviar para fora do domínio (relay negado).',
            Mailer::ERR_DATA        => 'A mensagem foi recusada depois de aceito o envelope — costuma ser filtro '
                                     . 'antispam ou limite de tamanho.',
            Mailer::ERR_MAIL_FN     => 'A função mail() do PHP não está disponível ou recusou a mensagem. '
                                     . 'Nessa hospedagem, configure um servidor SMTP.',
            'LIMITE'                => 'Muitos testes seguidos. O limite existe para o botão não virar '
                                     . 'ferramenta de disparo.',
            'INTERROMPIDO'          => 'O teste não chegou ao fim. Reduza o tempo limite na aba Configuração.',
            default                 => '',
        };
    }
}
