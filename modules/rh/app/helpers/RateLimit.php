<?php
/**
 * RateLimit — controle de envios do formulário PÚBLICO de vagas.
 *
 * O rate-limit de login saiu do módulo: autenticação (e suas tentativas)
 * é responsabilidade do núcleo (Core\RateLimit + tabela login_attempts).
 * Aqui fica apenas o controle da página pública de recrutamento
 * (tabela rh_public_submissions).
 */
class RateLimit
{
    /**
     * Retorna o IP real do cliente, considerando proxies comuns
     * (Cloudflare, cPanel, etc.).
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
    // Recrutamento público
    // -----------------------------------------------------------------

    public static function recordPublicSubmission(string $ip, ?int $jobId, ?string $email): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO rh_public_submissions (ip_address, job_id, email) VALUES (?, ?, ?)'
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
                'SELECT COUNT(*) FROM rh_public_submissions
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
                'SELECT 1 FROM rh_candidates WHERE job_id = ? AND email = ? LIMIT 1'
            );
            $stmt->execute([$jobId, $email]);
            return (bool)$stmt->fetchColumn();
        } catch (\Exception $e) {
            return false;
        }
    }
}
