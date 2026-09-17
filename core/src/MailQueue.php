<?php

declare(strict_types=1);

namespace Core;

/**
 * Fila de e-mails (tabela mail_queue).
 *
 * Envios em massa (comunicados, pesquisas, avisos) não devem bloquear a
 * requisição: os módulos enfileiram e o cron unificado (cron.php) — ou o
 * botão "Processar agora" na Administração — faz o envio via Core\Mailer.
 */
final class MailQueue
{
    public const MAX_ATTEMPTS = 3;

    public static function enqueue(
        string $to,
        string $subject,
        string $html,
        ?string $module = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $toName = null
    ): int {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        DB::execute(
            'INSERT INTO mail_queue (to_email, to_name, subject, body_html, module, ref_type, ref_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$to, $toName, mb_substr($subject, 0, 250), $html,
             $module ?? (defined('MODULE_SLUG') ? MODULE_SLUG : null), $refType, $refId]
        );
        return DB::lastId();
    }

    /**
     * Enfileira o mesmo e-mail para vários destinatários.
     * @param array<int, array{email: string, name?: string}|string> $recipients
     * @return int quantidade enfileirada (e-mails inválidos/duplicados são ignorados)
     */
    public static function enqueueMany(
        array $recipients,
        string $subject,
        string $html,
        ?string $module = null,
        ?string $refType = null,
        ?int $refId = null
    ): int {
        $seen = [];
        $n = 0;
        foreach ($recipients as $r) {
            $email = is_array($r) ? (string) ($r['email'] ?? '') : (string) $r;
            $name  = is_array($r) ? ($r['name'] ?? null) : null;
            $key   = mb_strtolower(trim($email));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (self::enqueue($email, $subject, $html, $module, $refType, $refId, $name) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Envia até $limit pendentes.
     *
     * Duas correções em relação à versão anterior, ambas sentidas em produção:
     *
     * 1. RESERVA antes de enviar. Antes, a linha só era atualizada DEPOIS do
     *    envio: duas execuções sobrepostas (o cron de hora em hora e o botão
     *    "Processar agora", ou um cron que demora mais que o intervalo)
     *    pegavam a mesma linha e o destinatário recebia o mesmo comunicado
     *    duas vezes. Agora cada rodada marca as linhas com um bilhete próprio
     *    e só trabalha nas suas.
     *
     * 2. Falha de CONFIGURAÇÃO não gasta tentativa. Com o envio desligado (ou
     *    sem servidor), três passagens do cron queimavam as 3 tentativas e a
     *    fila inteira virava "falha" permanente — inclusive comunicados que
     *    nunca chegaram a ser oferecidos a um servidor. Esses casos voltam
     *    para "pendente" e esperam o admin arrumar a configuração.
     *
     * @return array{sent:int, failed:int, retried:int, held:int}
     */
    public static function process(int $limit = 100): array
    {
        $stats  = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'held' => 0];
        $ticket = bin2hex(random_bytes(8));
        $limit  = max(1, min(1000, $limit));

        // Reserva: só pega o que está pendente e não está reservado por uma
        // rodada ainda viva (10 min cobre a rodada mais lenta imaginável).
        $reserved = DB::execute(
            'UPDATE mail_queue
                SET reserved_by = ?, reserved_at = NOW()
              WHERE status = "pending"
                AND attempts < ?
                AND (reserved_at IS NULL OR reserved_at < (NOW() - INTERVAL 10 MINUTE))
              ORDER BY id ASC
              LIMIT ' . $limit,
            [$ticket, self::MAX_ATTEMPTS]
        );
        if ($reserved < 1) {
            return $stats;
        }
        $rows = DB::query('SELECT * FROM mail_queue WHERE reserved_by = ? ORDER BY id ASC', [$ticket]);

        foreach ($rows as $row) {
            // REIVINDICA A LINHA NA HORA DE ENVIAR. A reserva do lote inteiro
            // era feita uma vez, no início, com um reserved_at só — e um lote
            // de 300 com SMTP lento passa de 10 minutos. Aí um segundo
            // processo (o botão "Processar agora", ou outro cron) via as linhas
            // ainda pendentes com reserved_at velho, re-reservava e enviava; o
            // primeiro, que ainda as tinha em memória, enviava também. Medido:
            // 85 mensagens a 8 s, 9 destinatários receberam em dobro, e o banco
            // ficou com 85 "sent" e nenhum rastro.
            //
            // Este UPDATE faz duas coisas: renova reserved_at (a janela de 10
            // min passa a valer POR LINHA, não por lote) e confere que a linha
            // ainda é nossa. Zero linhas afetadas = alguém a roubou: pula.
            $minha = DB::execute(
                'UPDATE mail_queue SET reserved_at = NOW()
                  WHERE id = ? AND reserved_by = ? AND status = "pending"',
                [$row['id'], $ticket]
            );
            if ($minha < 1) {
                continue;
            }

            $r = Mailer::sendDetailed(
                (string) $row['to_email'],
                (string) $row['subject'],
                (string) $row['body_html']
            );
            $attempts = (int) $row['attempts'];

            if ($r['ok']) {
                DB::execute(
                    'UPDATE mail_queue
                        SET status = "sent", attempts = ?, sent_at = NOW(), last_error = NULL,
                            error_code = NULL, delivery = ?, reserved_by = NULL, reserved_at = NULL
                      WHERE id = ? AND reserved_by = ?',
                    [$attempts + 1, $r['path'], $row['id'], $ticket]
                );
                $stats['sent']++;
                continue;
            }

            $erro = mb_substr($r['error'] ?: 'Falha no envio.', 0, 500);
            if (self::isConfigFailure((string) $r['code'])) {
                // Não é culpa da mensagem: devolve para a fila sem gastar tentativa.
                DB::execute(
                    'UPDATE mail_queue
                        SET last_error = ?, error_code = ?, reserved_by = NULL, reserved_at = NULL
                      WHERE id = ? AND reserved_by = ?',
                    [$erro, $r['code'], $row['id'], $ticket]
                );
                $stats['held']++;
                continue;
            }

            $attempts++;
            $final = $attempts >= self::MAX_ATTEMPTS;
            DB::execute(
                'UPDATE mail_queue
                    SET status = ?, attempts = ?, last_error = ?, error_code = ?,
                        reserved_by = NULL, reserved_at = NULL
                  WHERE id = ? AND reserved_by = ?',
                [$final ? 'failed' : 'pending', $attempts, $erro, $r['code'], $row['id'], $ticket]
            );
            $stats[$final ? 'failed' : 'retried']++;
        }
        return $stats;
    }

    /** A falha impede QUALQUER envio (configuração), não só o desta mensagem? */
    private static function isConfigFailure(string $code): bool
    {
        return in_array($code, [
            Mailer::ERR_DISABLED,
            Mailer::ERR_NO_HOST,
            Mailer::ERR_AUTH,
            Mailer::ERR_AUTH_UNSUP,
            Mailer::ERR_DNS,
            Mailer::ERR_MAIL_FN,
        ], true);
    }

    /** @return array{pending:int, sent:int, failed:int, held:int} */
    public static function stats(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'held' => 0];
        try {
            foreach (DB::query('SELECT status, COUNT(*) n FROM mail_queue GROUP BY status') as $r) {
                $out[$r['status']] = (int) $r['n'];
            }
            // Retidas: pendentes que já falharam por configuração e esperam conserto.
            $out['held'] = (int) (DB::query(
                'SELECT COUNT(*) n FROM mail_queue WHERE status = "pending" AND error_code IS NOT NULL'
            )[0]['n'] ?? 0);
        } catch (\Throwable) {
        }
        return $out;
    }

    public static function recent(int $limit = 50): array
    {
        return DB::query('SELECT * FROM mail_queue ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** Recoloca os falhados na fila. */
    public static function retryFailed(): int
    {
        return DB::execute(
            'UPDATE mail_queue
                SET status = "pending", attempts = 0, reserved_by = NULL, reserved_at = NULL
              WHERE status = "failed"'
        );
    }

    /** Enfileira a mensagem de teste da tela de e-mail (marcada como teste). */
    public static function enqueueTest(string $to, string $subject, string $html): int
    {
        $id = self::enqueue($to, $subject, $html, 'core', 'mail_test', null);
        if ($id > 0) {
            DB::execute('UPDATE mail_queue SET is_test = 1 WHERE id = ?', [$id]);
        }
        return $id;
    }

    public static function find(int $id): ?array
    {
        $rows = DB::query('SELECT * FROM mail_queue WHERE id = ?', [$id]);
        return $rows[0] ?? null;
    }
}
