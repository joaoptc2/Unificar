<?php
/**
 * CRON: Verificar vencimentos e gerar notificações (módulo RH).
 *
 * Executado pelo cron UNIFICADO da plataforma via manifesto do módulo
 * (module.php → 'cron'). O núcleo autentica/agenda a execução — não há
 * mais token próprio nem bootstrap de sessão aqui.
 */

echo "[" . date('Y-m-d H:i:s') . "] [rh] Iniciando verificação de vencimentos...\n";

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');

    // 1. Buscar vencimentos que vencem nos próximos N dias (conforme alert_days)
    $stmt = $db->prepare(
        "SELECT ex.*, e.full_name as employee_name
         FROM rh_expirations ex
         JOIN rh_employees e ON ex.employee_id = e.id
         WHERE e.status = 'ativo'
         AND ex.expiry_date BETWEEN ? AND DATE_ADD(?, INTERVAL ex.alert_days DAY)
         AND ex.notified_at IS NULL"
    );
    $stmt->execute([$today, $today]);
    $upcoming = $stmt->fetchAll();

    echo "Encontrados " . count($upcoming) . " vencimentos próximos.\n";

    // 2. Buscar vencimentos já vencidos não notificados
    $stmt = $db->prepare(
        "SELECT ex.*, e.full_name as employee_name
         FROM rh_expirations ex
         JOIN rh_employees e ON ex.employee_id = e.id
         WHERE e.status = 'ativo'
         AND ex.expiry_date < ?
         AND ex.notified_expired_at IS NULL"
    );
    $stmt->execute([$today]);
    $expired = $stmt->fetchAll();

    echo "Encontrados " . count($expired) . " vencimentos expirados.\n";

    // 3. Buscar quem deve ser notificado: usuários com a micropermissão de
    //    editar vencimentos (expirations.edit) — Core\Perms::usersWith já
    //    inclui os administradores globais.
    $adminIds = Core\Perms::usersWith('rh', 'expirations.edit');
    $admins = [];
    if ($adminIds) {
        $in = implode(',', array_fill(0, count($adminIds), '?'));
        $stmt = $db->prepare("SELECT id, email, name FROM users WHERE id IN ({$in})");
        $stmt->execute($adminIds);
        $admins = $stmt->fetchAll();
    }

    $notify = function (int $userId, string $type, string $title, string $message, string $link) use ($db): void {
        $db->prepare(
            "INSERT INTO notifications (user_id, module, type, title, message, link) VALUES (?, 'rh', ?, ?, ?, ?)"
        )->execute([$userId, $type, $title, $message, $link]);
    };

    // 4. Gerar notificações para vencimentos próximos
    foreach ($upcoming as $exp) {
        $diff = (int)(new DateTime($today))->diff(new DateTime($exp['expiry_date']))->format('%r%a');
        $title = "Vencimento próximo: {$exp['title']}";
        $message = "{$exp['employee_name']} — {$exp['title']} vence em {$diff} dia(s) ({$exp['expiry_date']}).";
        $link = "index.php?m=rh&page=expirations&action=edit&id={$exp['id']}";

        $emailSent = false;
        foreach ($admins as $admin) {
            $mailed = Mailer::sendExpiryAlert(
                $admin['email'],
                $exp['employee_name'],
                $exp['title'],
                date('d/m/Y', strtotime($exp['expiry_date']))
            );
            if ($mailed) $emailSent = true;
            $notify((int)$admin['id'], 'warning', $title, $message, $link);
        }

        // Marcar como notificado
        $db->prepare('UPDATE rh_expirations SET notified_at = NOW() WHERE id = ?')->execute([$exp['id']]);

        echo "  [ALERTA] {$exp['employee_name']} — {$exp['title']} (vence em {$diff}d)" . ($emailSent ? ' [e-mail]' : '') . "\n";
    }

    // 5. Gerar notificações para vencimentos expirados
    foreach ($expired as $exp) {
        $title = "VENCIDO: {$exp['title']}";
        $message = "{$exp['employee_name']} — {$exp['title']} VENCEU em " . date('d/m/Y', strtotime($exp['expiry_date'])) . ".";
        $link = "index.php?m=rh&page=expirations&action=edit&id={$exp['id']}";

        $emailSent = false;
        foreach ($admins as $admin) {
            $mailed = Mailer::sendExpiryAlert(
                $admin['email'],
                $exp['employee_name'],
                $exp['title'] . ' (VENCIDO)',
                date('d/m/Y', strtotime($exp['expiry_date']))
            );
            if ($mailed) $emailSent = true;
            $notify((int)$admin['id'], 'danger', $title, $message, $link);
        }

        // Marcar como notificado
        $db->prepare('UPDATE rh_expirations SET notified_expired_at = NOW() WHERE id = ?')->execute([$exp['id']]);

        echo "  [VENCIDO] {$exp['employee_name']} — {$exp['title']}" . ($emailSent ? ' [e-mail]' : '') . "\n";
    }

    // 6. Verificar conselhos regionais prestes a vencer
    $stmt = $db->prepare(
        "SELECT e.id, e.full_name, e.regional_council, e.council_number, e.council_expiry
         FROM rh_employees e
         WHERE e.status = 'ativo'
         AND e.council_expiry IS NOT NULL
         AND e.council_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)"
    );
    $stmt->execute([$today, $today]);
    $councils = $stmt->fetchAll();

    foreach ($councils as $c) {
        $diff = (int)(new DateTime($today))->diff(new DateTime($c['council_expiry']))->format('%r%a');
        $title = "Conselho Regional próximo do vencimento";
        $message = "{$c['full_name']} — {$c['regional_council']} {$c['council_number']} vence em {$diff} dia(s).";
        $link = "index.php?m=rh&page=employees&action=show&id={$c['id']}";

        foreach ($admins as $admin) {
            // Evitar duplicatas no mesmo dia
            $check = $db->prepare(
                "SELECT id FROM notifications WHERE user_id = ? AND module = 'rh' AND title = ? AND DATE(created_at) = ?"
            );
            $check->execute([$admin['id'], $title, $today]);
            if (!$check->fetch()) {
                $notify((int)$admin['id'], 'warning', $title, $message, $link);
            }
        }

        echo "  [CONSELHO] {$c['full_name']} — {$c['regional_council']} (vence em {$diff}d)\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] [rh] Verificação de vencimentos concluída com sucesso.\n";

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
