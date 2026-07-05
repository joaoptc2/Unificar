<?php
/**
 * Rate limiting de tentativas de login.
 *
 * Armazena tentativas na tabela `login_attempts` (criada na migration 002).
 * Considera IP do cliente + e-mail.
 */

/**
 * Verifica se o IP/e-mail está bloqueado por excesso de tentativas.
 * Retorna o número de minutos restantes de bloqueio, ou 0 se liberado.
 */
function login_attempts_check($email, $ip) {
    try {
        $lockout_seconds = LOGIN_LOCKOUT_MINUTES * 60;
        $row = db_query_one(
            "SELECT COUNT(*) AS total, MAX(created_at) AS last_try
             FROM login_attempts
             WHERE ip_address = ?
               AND (email = ? OR email IS NULL)
               AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ip, $email, $lockout_seconds]
        );

        if (!$row || (int) $row['total'] < LOGIN_MAX_ATTEMPTS) {
            return 0;
        }

        $last = strtotime($row['last_try']);
        $unlock_at = $last + $lockout_seconds;
        $remaining = $unlock_at - time();
        return $remaining > 0 ? (int) ceil($remaining / 60) : 0;
    } catch (Exception $ex) {
        // Se a tabela não existe ainda (migration pendente), não bloqueia
        return 0;
    }
}

/**
 * Registra uma tentativa de login.
 */
function login_attempts_record($email, $ip, $success) {
    try {
        db_execute(
            "INSERT INTO login_attempts (email, ip_address, success, created_at)
             VALUES (?, ?, ?, NOW())",
            [$email, $ip, $success ? 1 : 0]
        );

        // Em sucesso, limpa histórico para este IP
        if ($success) {
            db_execute(
                "DELETE FROM login_attempts WHERE ip_address = ? AND success = 0",
                [$ip]
            );
        }
    } catch (Exception $ex) {
        // Silencioso — não queremos impedir login se a tabela não existir
    }
}

/**
 * Limpa tentativas antigas (>7 dias). Pode ser chamado pelo cron.
 */
function login_attempts_cleanup() {
    try {
        db_execute("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Exception $ex) {}
}
