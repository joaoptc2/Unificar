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

    /** Envia até $limit pendentes. @return array{sent:int, failed:int, retried:int} */
    public static function process(int $limit = 100): array
    {
        $rows = DB::query(
            'SELECT * FROM mail_queue WHERE status = "pending" AND attempts < ?
             ORDER BY id ASC LIMIT ' . max(1, min(1000, $limit)),
            [self::MAX_ATTEMPTS]
        );
        $stats = ['sent' => 0, 'failed' => 0, 'retried' => 0];
        foreach ($rows as $row) {
            $ok = false;
            $err = null;
            try {
                $ok = Mailer::send((string) $row['to_email'], (string) $row['subject'], (string) $row['body_html']);
                if (!$ok) {
                    $err = Config::get('mail.enabled', false) ? 'Falha no envio (ver php_errors.log)' : 'Envio de e-mail desabilitado (mail.enabled = false em config/config.php)';
                }
            } catch (\Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 500);
            }
            $attempts = (int) $row['attempts'] + 1;
            if ($ok) {
                DB::execute('UPDATE mail_queue SET status = "sent", attempts = ?, sent_at = NOW(), last_error = NULL WHERE id = ?', [$attempts, $row['id']]);
                $stats['sent']++;
            } elseif ($attempts >= self::MAX_ATTEMPTS) {
                DB::execute('UPDATE mail_queue SET status = "failed", attempts = ?, last_error = ? WHERE id = ?', [$attempts, $err, $row['id']]);
                $stats['failed']++;
            } else {
                DB::execute('UPDATE mail_queue SET attempts = ?, last_error = ? WHERE id = ?', [$attempts, $err, $row['id']]);
                $stats['retried']++;
            }
        }
        return $stats;
    }

    /** @return array{pending:int, sent:int, failed:int} */
    public static function stats(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        try {
            foreach (DB::query('SELECT status, COUNT(*) n FROM mail_queue GROUP BY status') as $r) {
                $out[$r['status']] = (int) $r['n'];
            }
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
        return DB::execute('UPDATE mail_queue SET status = "pending", attempts = 0 WHERE status = "failed"');
    }
}
