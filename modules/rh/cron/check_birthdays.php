<?php
/**
 * CRON Job: Notificar aniversariantes do dia
 *
 * Configurar no cPanel/Hostinger:
 * Frequência: Diariamente às 08:00
 * Comando: php /home/usuario/public_html/cron/check_birthdays.php
 */

define('BASE_PATH', dirname(__DIR__));

// Bootstrap comum: valida contexto, carrega config e autoloader.
require __DIR__ . '/bootstrap.php';

echo "[" . date('Y-m-d H:i:s') . "] Verificando aniversariantes do dia...\n";

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');
    $day = (int)date('j');
    $month = (int)date('n');

    // Buscar aniversariantes do dia
    $stmt = $db->prepare(
        "SELECT e.full_name, d.name as department_name
         FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id
         WHERE DAY(e.birth_date) = ? AND MONTH(e.birth_date) = ? AND e.status = 'ativo'"
    );
    $stmt->execute([$day, $month]);
    $birthdays = $stmt->fetchAll();

    echo "Encontrados " . count($birthdays) . " aniversariante(s).\n";

    if (!empty($birthdays)) {
        $names = array_map(fn($b) => $b['full_name'], $birthdays);
        $title = "Aniversariante(s) do dia!";
        $message = "Hoje fazem aniversário: " . implode(', ', $names) . ".";
        $link = "index.php?page=birthdays&month={$month}";

        // Notificar todos os usuários ativos
        $users = $db->query("SELECT id FROM users WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $userId) {
            $check = $db->prepare(
                "SELECT id FROM notifications WHERE user_id = ? AND title = ? AND DATE(created_at) = ?"
            );
            $check->execute([$userId, $title, $today]);
            if (!$check->fetch()) {
                $db->prepare(
                    'INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, "info", ?)'
                )->execute([$userId, $title, $message, $link]);
            }
        }

        foreach ($birthdays as $b) {
            echo "  [ANIVERSARIO] {$b['full_name']} ({$b['department_name']})\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Verificação concluída.\n";

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
    exit(1);
}
