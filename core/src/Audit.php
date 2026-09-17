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
        // REMOTE_ADDR é o único valor que o cliente NÃO escolhe. Os cabeçalhos
        // de proxy só valem quando o administrador declarou confiar no proxy
        // (security.trust_proxy) — a mesma regra de Core\Https. Antes eles
        // vinham PRIMEIRO, de qualquer origem: um atacante mandava um
        // X-Forwarded-For diferente a cada requisição, o contador de força
        // bruta por IP nunca acumulava (15 senhas em 15 contas, zero
        // bloqueios, medido) e o IP gravado na auditoria era o inventado.
        $remoto = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
        if (!Https::trustProxy()) {
            return $remoto;
        }
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            if (!empty($_SERVER[$key])) {
                // Numa cadeia "cliente, proxy1, proxy2" o proxy confiável
                // acrescenta à direita; o salto mais à DIREITA não controlado
                // por nós é o que o nosso proxy viu. Como só há um proxy
                // declarado, é o último da lista.
                $partes = array_map('trim', explode(',', (string) $_SERVER[$key]));
                $ip = (string) end($partes);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return substr($ip, 0, 45);
                }
            }
        }
        return $remoto;
    }
}
