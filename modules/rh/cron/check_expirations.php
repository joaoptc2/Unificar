<?php
/**
 * CRON Job: Verificar vencimentos e gerar notificações
 *
 * Configurar no cPanel/Hostinger:
 * Frequência: Diariamente às 07:00
 * Comando: php /home/usuario/public_html/cron/check_expirations.php
 *
 * Ou via crontab:
 * 0 7 * * * php /caminho/para/cron/check_expirations.php >> /caminho/para/logs/cron.log 2>&1
 */

define('BASE_PATH', dirname(__DIR__));

// Bootstrap comum: valida contexto (CLI ou token), carrega config e autoloader.
require __DIR__ . '/bootstrap.php';

echo "[" . date('Y-m-d H:i:s') . "] Iniciando verificação de vencimentos...\n";

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');

    // 1. Buscar vencimentos que vencem nos próximos N dias (conforme alert_days)
    $stmt = $db->prepare(
        "SELECT ex.*, e.full_name as employee_name
         FROM expirations ex
         JOIN employees e ON ex.employee_id = e.id
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
         FROM expirations ex
         JOIN employees e ON ex.employee_id = e.id
         WHERE e.status = 'ativo'
         AND ex.expiry_date < ?
         AND ex.notified_expired_at IS NULL"
    );
    $stmt->execute([$today]);
    $expired = $stmt->fetchAll();

    echo "Encontrados " . count($expired) . " vencimentos expirados.\n";

    // 3. Buscar todos os admins e RH para notificar
    $admins = $db->query("SELECT id, email, name FROM users WHERE role IN ('admin', 'rh') AND active = 1")->fetchAll();

    // 4. Gerar notificações para vencimentos próximos
    foreach ($upcoming as $exp) {
        $diff = (int)(new DateTime($today))->diff(new DateTime($exp['expiry_date']))->format('%r%a');
        $title = "Vencimento próximo: {$exp['title']}";
        $message = "{$exp['employee_name']} — {$exp['title']} vence em {$diff} dia(s) ({$exp['expiry_date']}).";
        $link = "index.php?page=expirations&action=edit&id={$exp['id']}";

        $emailSent = false;
        foreach ($admins as $admin) {
            $stmt = $db->prepare(
                'INSERT INTO notifications (user_id, title, message, type, link, sent_email) VALUES (?, ?, ?, "warning", ?, ?)'
            );
            $mailed = Mailer::sendExpiryAlert(
                $admin['email'],
                $exp['employee_name'],
                $exp['title'],
                date('d/m/Y', strtotime($exp['expiry_date']))
            );
            if ($mailed) $emailSent = true;
            $stmt->execute([$admin['id'], $title, $message, $link, $mailed ? 1 : 0]);
        }

        // Marcar como notificado
        $db->prepare('UPDATE expirations SET notified_at = NOW() WHERE id = ?')->execute([$exp['id']]);

        echo "  [ALERTA] {$exp['employee_name']} — {$exp['title']} (vence em {$diff}d)" . ($emailSent ? ' [e-mail]' : '') . "\n";
    }

    // 5. Gerar notificações para vencimentos expirados
    foreach ($expired as $exp) {
        $title = "VENCIDO: {$exp['title']}";
        $message = "{$exp['employee_name']} — {$exp['title']} VENCEU em " . date('d/m/Y', strtotime($exp['expiry_date'])) . ".";
        $link = "index.php?page=expirations&action=edit&id={$exp['id']}";

        $emailSent = false;
        foreach ($admins as $admin) {
            $stmt = $db->prepare(
                'INSERT INTO notifications (user_id, title, message, type, link, sent_email) VALUES (?, ?, ?, "danger", ?, ?)'
            );
            $mailed = Mailer::sendExpiryAlert(
                $admin['email'],
                $exp['employee_name'],
                $exp['title'] . ' (VENCIDO)',
                date('d/m/Y', strtotime($exp['expiry_date']))
            );
            if ($mailed) $emailSent = true;
            $stmt->execute([$admin['id'], $title, $message, $link, $mailed ? 1 : 0]);
        }

        // Marcar como notificado
        $db->prepare('UPDATE expirations SET notified_expired_at = NOW() WHERE id = ?')->execute([$exp['id']]);

        echo "  [VENCIDO] {$exp['employee_name']} — {$exp['title']}" . ($emailSent ? ' [e-mail]' : '') . "\n";
    }

    // 6. Verificar conselhos regionais prestes a vencer
    $stmt = $db->prepare(
        "SELECT e.id, e.full_name, e.regional_council, e.council_number, e.council_expiry
         FROM employees e
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
        $link = "index.php?page=employees&action=show&id={$c['id']}";

        foreach ($admins as $admin) {
            // Evitar duplicatas no mesmo dia
            $check = $db->prepare(
                "SELECT id FROM notifications WHERE user_id = ? AND title = ? AND DATE(created_at) = ?"
            );
            $check->execute([$admin['id'], $title, $today]);
            if (!$check->fetch()) {
                $db->prepare(
                    'INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, "warning", ?)'
                )->execute([$admin['id'], $title, $message, $link]);
            }
        }

        echo "  [CONSELHO] {$c['full_name']} — {$c['regional_council']} (vence em {$diff}d)\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Verificação concluída com sucesso.\n";

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
    exit(1);
}
