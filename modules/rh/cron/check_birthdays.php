<?php
/**
 * CRON: Notificar aniversariantes do dia (módulo RH).
 *
 * Executado pelo cron UNIFICADO da plataforma via manifesto do módulo
 * (module.php → 'cron'). O núcleo autentica/agenda a execução — não há
 * mais token próprio nem bootstrap de sessão aqui. O autoloader do módulo
 * já foi registrado pelo closure do manifesto.
 */

echo "[" . date('Y-m-d H:i:s') . "] [rh] Verificando aniversariantes do dia...\n";

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');
    $day = (int)date('j');
    $month = (int)date('n');

    // Buscar aniversariantes do dia
    $stmt = $db->prepare(
        "SELECT e.full_name, d.name as department_name
         FROM rh_employees e
         LEFT JOIN rh_departments d ON e.department_id = d.id
         WHERE DAY(e.birth_date) = ? AND MONTH(e.birth_date) = ? AND e.status = 'ativo'"
    );
    $stmt->execute([$day, $month]);
    $birthdays = $stmt->fetchAll();

    echo "Encontrados " . count($birthdays) . " aniversariante(s).\n";

    if (!empty($birthdays)) {
        $names = array_map(fn($b) => $b['full_name'], $birthdays);
        $title = "Aniversariante(s) do dia!";
        $message = "Hoje fazem aniversário: " . implode(', ', $names) . ".";
        $link = "index.php?m=rh&page=birthdays&month={$month}";

        // Notificar todos os usuários ativos que enxergam os aniversariantes
        // (micropermissão birthdays.view — Core\Perms::usersWith já inclui os
        // administradores globais).
        $users = Core\Perms::usersWith('rh', 'birthdays.view');
        foreach ($users as $userId) {
            $check = $db->prepare(
                "SELECT id FROM notifications WHERE user_id = ? AND module = 'rh' AND title = ? AND DATE(created_at) = ?"
            );
            $check->execute([$userId, $title, $today]);
            if (!$check->fetch()) {
                $db->prepare(
                    "INSERT INTO notifications (user_id, module, type, title, message, link) VALUES (?, 'rh', 'info', ?, ?, ?)"
                )->execute([$userId, $title, $message, $link]);
            }
        }

        foreach ($birthdays as $b) {
            echo "  [ANIVERSARIO] {$b['full_name']} ({$b['department_name']})\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] [rh] Verificação de aniversariantes concluída.\n";

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
