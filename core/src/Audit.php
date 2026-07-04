<?php

declare(strict_types=1);

namespace Core;

/** Log de auditoria unificado (tabela audit_log, coluna module). */
final class Audit
{
    public static function log(
        string $action,
        ?string $entity = null,
        ?string $entityId = null,
        string|array|null $details = null,
        ?int $userId = null,
        ?string $module = null
    ): void {
        try {
            DB::execute(
                'INSERT INTO audit_log (user_id, module, action, entity, entity_id, details, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId ?? Auth::id(),
                    $module ?? (defined('MODULE_SLUG') ? MODULE_SLUG : null),
                    $action,
                    $entity,
                    $entityId,
                    is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
                    self::ip(),
                ]
            );
        } catch (\Throwable $e) {
            error_log('audit_log falhou: ' . $e->getMessage());
        }
    }

    public static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', (string) $_SERVER[$key])[0];
                return substr(trim($ip), 0, 45);
            }
        }
        return '';
    }
}
