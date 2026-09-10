<?php
/**
 * CRON DO MÓDULO MANUTENÇÃO
 *
 * Executado pelo cron unificado da raiz (cron.php) através do manifesto
 * do módulo ('cron' => require deste arquivo). A autenticação (CLI ou
 * token) é feita pelo cron da raiz — não há check de segredo aqui.
 *
 * Tarefas:
 *   1. Materializa planos de manutenção preventiva em ordens de serviço
 *      quando `next_date <= hoje`, atualizando a próxima data conforme
 *      a frequência cadastrada.
 *   2. Gera notificações de calibrações vencidas ou a vencer (30 e 15 dias).
 *   3. Gera notificações de peças com estoque abaixo do mínimo.
 *   4. Limpa notificações lidas do módulo com mais de 60 dias.
 *   5. Atribui código de identificação (asset_code) aos equipamentos
 *      que ainda não têm (lib/asset_code.php).
 *
 * Notificações agora são por usuário (tabela global notifications):
 * cada alerta vai para quem tem a micropermissão do assunto
 * (Core\Perms::usersWith — preventivas → service_orders.edit,
 * calibração → calibration.edit, estoque → stock.edit; admins globais
 * sempre incluídos).
 *
 * Idempotência: para cada "assunto × dia × usuário" só gera uma
 * notificação, evitando flood mesmo com múltiplas execuções ao dia.
 */

require_once __DIR__ . '/../config.php';

$startedAt = microtime(true);

if (!function_exists('man_cron_log')) {
    function man_cron_log(string $msg): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

man_cron_log('Iniciando cron do módulo manutencao');

try {
    $pdo = db();
} catch (Throwable $ex) {
    man_cron_log('ERRO: ' . $ex->getMessage());
    return;
}

if (!function_exists('man_cron_push_notification')) {
    /**
     * Notifica os usuários com a micropermissão indicada (dedupe por
     * usuário × tipo × link × dia). Quando cria ao menos uma notificação,
     * dispara email best-effort. Retorna true se criou alguma nova.
     */
    function man_cron_push_notification(PDO $pdo, string $type, string $title, string $message, ?string $link = null, string $permKey = 'service_orders.edit'): bool
    {
        $createdAny = false;
        $check = $pdo->prepare("
            SELECT 1 FROM notifications
            WHERE user_id = ?
              AND module = ?
              AND type = ?
              AND (link <=> ?)
              AND title = ?
              AND DATE(created_at) = CURDATE()
            LIMIT 1
        ");
        $insert = $pdo->prepare("
            INSERT INTO notifications (user_id, module, type, title, message, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach (manModuleManagers($permKey) as $u) {
            try {
                $check->execute([(int) $u['id'], MAN_MODULE_SLUG, $type, $link, $title]);
                if ($check->fetchColumn()) {
                    continue;
                }
                $insert->execute([(int) $u['id'], MAN_MODULE_SLUG, $type, $title, $message, $link]);
                $createdAny = true;

                if (!empty($u['email'])) {
                    $html = '<h3 style="margin:0 0 8px;color:#0f172a">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
                          . '<p style="color:#334155;line-height:1.5">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
                          . '<p style="color:#94a3b8;font-size:12px;margin-top:16px">Enviado automaticamente pelo cron do módulo Manutenção.</p>';
                    sendMail((string) $u['email'], '[Manutenção] ' . $title, $html);
                }
            } catch (Throwable $ex) {
                error_log('cron manutencao notification error: ' . $ex->getMessage());
            }
        }
        return $createdAny;
    }
}

/* ------------------------------------------------------------------
 * 1. Planos de manutenção preventiva → Ordens de Serviço
 * ------------------------------------------------------------------ */
$osCreated = 0;
try {
    $plans = $pdo->query("
        SELECT mp.*, e.name AS equipment_name
        FROM man_maintenance_plans mp
        LEFT JOIN man_equipment e ON e.id = mp.equipment_id
        WHERE mp.status = 'active'
          AND mp.next_date IS NOT NULL
          AND mp.next_date <= CURDATE()
    ")->fetchAll();

    $insertOs = $pdo->prepare("
        INSERT INTO man_service_orders
            (hospital_id, equipment_id, os_number, type, priority, status,
             title, description, scheduled_date, created_at)
        VALUES (?, ?, ?, 'preventive', 'medium', 'open', ?, ?, ?, NOW())
    ");
    $updatePlan = $pdo->prepare("UPDATE man_maintenance_plans SET next_date = ?, last_executed = NOW() WHERE id = ?");

    foreach ($plans as $plan) {
        $osNumber = generateOsNumber();
        $title    = '[Preventiva] ' . $plan['title'];
        $descr    = trim(($plan['description'] ?? '') . "\nGerado automaticamente pelo plano de manutenção #" . $plan['id']);

        $insertOs->execute([
            $plan['hospital_id'],
            $plan['equipment_id'],
            $osNumber,
            $title,
            $descr,
            $plan['next_date'],
        ]);
        $newOsId = (int) $pdo->lastInsertId();

        $nextDate = calcNextDate(date('Y-m-d'), $plan['frequency']);
        $updatePlan->execute([$nextDate, $plan['id']]);

        man_cron_push_notification(
            $pdo,
            'maintenance_due',
            'Preventiva gerada: ' . $plan['title'],
            'OS ' . $osNumber . ' criada automaticamente para o equipamento "' . ($plan['equipment_name'] ?? '—') . '".',
            'index.php?m=manutencao&page=service-orders&action=edit&id=' . $newOsId,
            'service_orders.edit'
        );

        $osCreated++;
    }
    man_cron_log(sprintf('Preventivas: %d OS criadas.', $osCreated));
} catch (Throwable $ex) {
    man_cron_log('ERRO em preventivas: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 2. Calibrações vencidas / a vencer
 * ------------------------------------------------------------------ */
$calibNotifs = 0;
try {
    // Pega a calibração mais recente de cada equipamento
    $rows = $pdo->query("
        SELECT c.hospital_id, c.equipment_id, c.next_date, e.name AS equipment_name
        FROM (
            SELECT equipment_id, MAX(next_date) AS max_next
            FROM man_equipment_calibrations
            GROUP BY equipment_id
        ) last
        JOIN man_equipment_calibrations c ON c.equipment_id = last.equipment_id AND c.next_date = last.max_next
        JOIN man_equipment e ON e.id = c.equipment_id
        WHERE c.next_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ")->fetchAll();

    foreach ($rows as $r) {
        $dt   = new DateTime($r['next_date']);
        $days = (int) (new DateTime('today'))->diff($dt)->format('%r%a');
        $link = 'index.php?m=manutencao&page=calibration&status=overdue';

        if ($days < 0) {
            $created = man_cron_push_notification(
                $pdo,
                'calibration_overdue',
                'Calibração VENCIDA: ' . $r['equipment_name'],
                'O equipamento "' . $r['equipment_name'] . '" está com calibração vencida há ' . abs($days) . ' dia(s).',
                $link,
                'calibration.edit'
            );
        } elseif ($days <= 15) {
            $created = man_cron_push_notification(
                $pdo,
                'calibration_urgent',
                'Calibração urgente: ' . $r['equipment_name'],
                'Vence em ' . $days . ' dia(s). Programe a calibração imediatamente.',
                'index.php?m=manutencao&page=calibration&status=due_soon',
                'calibration.edit'
            );
        } else {
            $created = man_cron_push_notification(
                $pdo,
                'calibration_due_soon',
                'Calibração a vencer: ' . $r['equipment_name'],
                'Vence em ' . $days . ' dia(s).',
                'index.php?m=manutencao&page=calibration&status=due_soon',
                'calibration.edit'
            );
        }
        if ($created) $calibNotifs++;
    }
    man_cron_log(sprintf('Calibração: %d notificações geradas.', $calibNotifs));
} catch (Throwable $ex) {
    man_cron_log('ERRO em calibração: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 3. Estoque abaixo do mínimo
 * ------------------------------------------------------------------ */
$stockNotifs = 0;
try {
    $rows = $pdo->query("
        SELECT id, hospital_id, name, quantity, min_quantity
        FROM man_parts
        WHERE status = 'active' AND quantity <= min_quantity
    ")->fetchAll();

    foreach ($rows as $p) {
        $created = man_cron_push_notification(
            $pdo,
            'stock_low',
            'Estoque baixo: ' . $p['name'],
            'Quantidade atual: ' . $p['quantity'] . ' / mínimo: ' . $p['min_quantity'] . '.',
            'index.php?m=manutencao&page=stock&action=edit&id=' . (int) $p['id'],
            'stock.edit'
        );
        if ($created) $stockNotifs++;
    }
    man_cron_log(sprintf('Estoque: %d notificações geradas.', $stockNotifs));
} catch (Throwable $ex) {
    man_cron_log('ERRO em estoque: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 4. Limpeza de notificações lidas antigas (somente deste módulo)
 * ------------------------------------------------------------------ */
try {
    $st = $pdo->prepare("
        DELETE FROM notifications
        WHERE module = ?
          AND read_at IS NOT NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
    ");
    $st->execute([MAN_MODULE_SLUG]);
    man_cron_log(sprintf('Notificações antigas removidas: %d.', $st->rowCount()));
} catch (Throwable $ex) {
    man_cron_log('ERRO ao limpar notificações: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 5. Código de identificação dos equipamentos sem asset_code
 * ------------------------------------------------------------------ */
$codesAssigned = 0;
try {
    if (man_asset_code_missing_count() > 0) {
        // Teto por execução também aqui: em uma base muito grande a rotina
        // converte 5.000 por rodada em vez de segurar o cron indefinidamente
        // (as execuções seguintes continuam de onde parou).
        $codesAssigned = man_asset_code_ensure_all(5000);
    }
    $stillMissing = man_asset_code_missing_count();
    man_cron_log(sprintf(
        'Códigos de identificação atribuídos: %d.%s',
        $codesAssigned,
        $stillMissing > 0 ? sprintf(' Restam %d para a próxima execução.', $stillMissing) : ''
    ));
} catch (Throwable $ex) {
    man_cron_log('ERRO ao atribuir códigos de identificação: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------ */
auditLog('cron_run', 'system', null, sprintf('os=%d calib=%d stock=%d codes=%d', $osCreated, $calibNotifs, $stockNotifs, $codesAssigned));

$elapsed = round(microtime(true) - $startedAt, 3);
man_cron_log(sprintf('Concluído em %.3fs.', $elapsed));
