<?php
/**
 * Adaptador de auditoria — grava no log unificado da plataforma
 * (tabela global audit_log, module = 'chat') via Core\Audit.
 */
class AuditLog
{
    public static function log(
        string $action,
        string $entityType,
        ?int   $entityId = null,
        mixed  $oldData = null,
        mixed  $newData = null
    ): void {
        $details = null;
        if ($oldData !== null || $newData !== null) {
            $details = json_encode(
                ['old' => $oldData, 'new' => $newData],
                JSON_UNESCAPED_UNICODE
            );
        }

        \Core\Audit::log(
            $action,
            $entityType,
            $entityId !== null ? (string) $entityId : null,
            $details,
            Session::userId(),
            'chat'
        );
    }
}
