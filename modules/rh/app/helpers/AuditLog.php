<?php
/**
 * AuditLog — adaptador do log de auditoria unificado (Core\Audit).
 * Grava na tabela global `audit_log` com module = 'rh'.
 * Mantém a assinatura legada (old/new data serializados em `details`).
 */
class AuditLog
{
    public static function log(
        string $action,
        ?string $tableName = null,
        ?int $recordId = null,
        ?array $oldData = null,
        ?array $newData = null
    ): void {
        $details = null;
        if ($oldData !== null || $newData !== null) {
            $details = [];
            if ($oldData !== null) $details['old'] = $oldData;
            if ($newData !== null) $details['new'] = $newData;
        }

        Core\Audit::log(
            $action,
            $tableName,
            $recordId !== null ? (string)$recordId : null,
            $details,
            Session::userId(),
            'rh'
        );
    }
}
