<?php
/**
 * Log de Auditoria — regista todas as ações relevantes no sistema
 */
class AuditLog
{
    /**
     * Regista uma ação no log de auditoria
     */
    public static function log(
        string $action,
        ?string $tableName = null,
        ?int $recordId = null,
        ?array $oldData = null,
        ?array $newData = null
    ): void {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO audit_log (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                Session::userId(),
                $action,
                $tableName,
                $recordId,
                $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
                $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
                self::getIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Exception $e) {
            // Falha silenciosa no log para não interromper a operação principal
            error_log('AuditLog Error: ' . $e->getMessage());
        }
    }

    /**
     * Obtém o IP real do cliente
     */
    private static function getIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
