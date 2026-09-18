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
        // REMOTE_ADDR é o único valor que o cliente NÃO escolhe. Os cabeçalhos
        // de proxy só valem quando o administrador declarou confiar no proxy
        // (security.trust_proxy) — a MESMA regra de Core\Audit::ip() e
        // Core\Https. Antes eles vinham primeiro, de qualquer origem: um
        // atacante mandava um X-Forwarded-For diferente a cada envio e o
        // rate-limit do formulário público de vagas nunca acumulava. Sem
        // proxy confiável, cada valor forjado parecia um IP novo.
        $remoto = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!\Core\Https::trustProxy()) {
            return $remoto;
        }
        // Com proxy confiável, o salto mais à DIREITA que não controlamos é o
        // que o nosso proxy viu (ele acrescenta à direita).
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $partes = array_map('trim', explode(',', (string) $_SERVER[$h]));
                $ip = (string) end($partes);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $remoto;
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
