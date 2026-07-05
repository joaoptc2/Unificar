<?php
/**
 * RateLimit — controle de tentativas por IP/identificador.
 *
 * Usa as tabelas `login_attempts` e `public_submissions` (criadas pela
 * migração 001). Todas as chaves são indexadas para performance.
 */
class RateLimit
{
    /**
     * Retorna o IP real do cliente, considerando proxies comuns
     * (Cloudflare, cPanel, etc.) da Hostinger.
     */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    /**
     * Registra uma tentativa de login (sucesso ou falha).
     */
    public static function recordLogin(string $ip, ?string $email, bool $success): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO login_attempts (ip_address, email, success, user_agent)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $ip,
                $email ? mb_substr($email, 0, 200) : null,
                $success ? 1 : 0,
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        } catch (\Exception $e) {
            error_log('RateLimit::recordLogin: ' . $e->getMessage());
        }
    }

    /**
     * Conta falhas recentes para um IP dentro da janela (minutos).
     */
    public static function recentLoginFailures(string $ip, ?string $email, int $windowMinutes): int
    {
        try {
            $db = Database::getInstance();
            $sql = 'SELECT COUNT(*) FROM login_attempts
                     WHERE success = 0
                       AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                       AND (ip_address = ?';
            $params = [$windowMinutes, $ip];
            if ($email) {
                $sql .= ' OR email = ?';
                $params[] = $email;
            }
            $sql .= ')';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            // Se a tabela ainda não existir (migração não rodada), não bloquear login.
            error_log('RateLimit::recentLoginFailures: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Em quantos segundos o bloqueio expira (0 = não bloqueado).
     */
    public static function loginLockoutRemaining(string $ip, ?string $email, int $windowMinutes): int
    {
        try {
            $db = Database::getInstance();
            $sql = 'SELECT MAX(attempted_at) FROM login_attempts
                     WHERE success = 0
                       AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                       AND (ip_address = ?';
            $params = [$windowMinutes, $ip];
            if ($email) {
                $sql .= ' OR email = ?';
                $params[] = $email;
            }
            $sql .= ')';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $last = $stmt->fetchColumn();
            if (!$last) return 0;
            $unlocksAt = strtotime($last) + ($windowMinutes * 60);
            return max(0, $unlocksAt - time());
        } catch (\Exception $e) {
            return 0;
        }
    }

    // -----------------------------------------------------------------
    // Recrutamento público
    // -----------------------------------------------------------------

    public static function recordPublicSubmission(string $ip, ?int $jobId, ?string $email): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO public_submissions (ip_address, job_id, email) VALUES (?, ?, ?)'
            );
            $stmt->execute([$ip, $jobId ?: null, $email ? mb_substr($email, 0, 200) : null]);
        } catch (\Exception $e) {
            error_log('RateLimit::recordPublicSubmission: ' . $e->getMessage());
        }
    }

    public static function recentPublicSubmissions(string $ip, int $windowMinutes = 60): int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM public_submissions
                 WHERE ip_address = ?
                   AND submitted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
            );
            $stmt->execute([$ip, $windowMinutes]);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public static function candidateAlreadyApplied(int $jobId, string $email): bool
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT 1 FROM candidates WHERE job_id = ? AND email = ? LIMIT 1'
            );
            $stmt->execute([$jobId, $email]);
            return (bool)$stmt->fetchColumn();
        } catch (\Exception $e) {
            return false;
        }
    }
}
