<?php

declare(strict_types=1);

namespace Core;

/** Proteção de força bruta no login (tabela login_attempts). */
final class RateLimit
{
    public static function isBlocked(string $identifier): bool
    {
        $max    = (int) Config::get('security.login_max_attempts', 5);
        $window = (int) Config::get('security.login_lockout_min', 15);

        foreach ([$identifier, Audit::ip()] as $key) {
            if ($key === '') {
                continue;
            }
            $row = DB::queryOne(
                'SELECT COUNT(*) AS n FROM login_attempts
                 WHERE identifier = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                [$key, $window]
            );
            if ((int) ($row['n'] ?? 0) >= $max) {
                return true;
            }
        }
        return false;
    }

    public static function record(string $identifier, bool $success): void
    {
        DB::execute('INSERT INTO login_attempts (identifier, success) VALUES (?, ?)', [$identifier, $success ? 1 : 0]);
        DB::execute('INSERT INTO login_attempts (identifier, success) VALUES (?, ?)', [Audit::ip(), $success ? 1 : 0]);
        if ($success) {
            DB::execute('DELETE FROM login_attempts WHERE identifier IN (?, ?)', [$identifier, Audit::ip()]);
        }
        // Limpeza oportunista
        if (random_int(1, 50) === 1) {
            DB::execute('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)');
        }
    }
}
