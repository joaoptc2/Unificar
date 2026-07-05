<?php
/**
 * CRON JOB UNIFICADO - ManuHosp
 *
 * Responsável por varrer o banco e manter o sistema vivo sem intervenção
 * manual. Execute pelo cron do Hostinger (ex.: diariamente às 03:00):
 *
 *     php -f /home/USUARIO/public_html/cron.php token=SEU_SEGREDO
 *
 * Tarefas:
 *   1. Materializa planos de manutenção preventiva em ordens de serviço
 *      quando `next_date <= hoje`, atualizando a próxima data conforme
 *      a frequência cadastrada.
 *   2. Gera notificações de calibrações vencidas ou a vencer (30 e 15 dias).
 *   3. Gera notificações de peças com estoque abaixo do mínimo.
 *   4. Limpa notificações lidas com mais de 60 dias.
 *   5. Remove arquivos antigos em logs/login_attempts.json.
 *
 * Idempotência: para cada "assunto × dia" só gera uma notificação,
 * evitando flood mesmo que o cron seja executado múltiplas vezes ao dia.
 */

require __DIR__ . '/config.php';

/* ------------------------------------------------------------------
 * Controle de acesso
 *
 * Aceita execução por CLI sem restrições (cron nativo) ou por HTTP
 * desde que `token` bata com CRON_SECRET definido em db-config.php.
 * Caso nenhuma constante esteja definida, só CLI é permitido.
 * ------------------------------------------------------------------ */
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $sentToken = $_GET['token'] ?? $_POST['token'] ?? '';
    $expected  = defined('CRON_SECRET') ? CRON_SECRET : null;
    if (!$expected || !hash_equals($expected, (string)$sentToken)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// Queremos logs visíveis em CLI mas silenciosos via HTTP
header_remove();
header('Content-Type: text/plain; charset=utf-8');

$startedAt = microtime(true);
$log = [];
function c_log(string $msg): void
{
    global $log;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    echo $line, "\n";
}

c_log('Iniciando cron ManuHosp');

try {
    $pdo = db();
} catch (Throwable $ex) {
    c_log('ERRO: ' . $ex->getMessage());
    exit(1);
}

/**
 * Evita criar notificação duplicada no mesmo dia para a combinação
 * (hospital_id, type, reference_id). Retorna true se criou.
 *
 * Quando cria, dispara email aos admins do hospital (best-effort).
 */
function pushNotification(PDO $pdo, int $hospitalId, string $type, string $title, string $message, ?int $referenceId = null): bool
{
    $sql = "SELECT 1 FROM notifications
            WHERE hospital_id = ?
              AND type = ?
              AND (reference_id <=> ?)
              AND DATE(created_at) = CURDATE()
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$hospitalId, $type, $referenceId]);
    if ($st->fetchColumn()) {
        return false;
    }
    $pdo->prepare("
        INSERT INTO notifications (hospital_id, user_id, type, title, message, reference_id, is_read, created_at)
        VALUES (?, NULL, ?, ?, ?, ?, 0, NOW())
    ")->execute([$hospitalId, $type, $title, $message, $referenceId]);

    // Envia email para administradores ativos do hospital
    try {
        $admins = $pdo->prepare("
            SELECT email, name FROM users
            WHERE hospital_id = ? AND role IN ('admin','manager') AND status = 'active' AND email <> ''
        ");
        $admins->execute([$hospitalId]);
        while ($row = $admins->fetch()) {
            $html = '<h3 style="margin:0 0 8px;color:#0f172a">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
                  . '<p style="color:#334155;line-height:1.5">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
                  . '<p style="color:#94a3b8;font-size:12px;margin-top:16px">Enviado automaticamente pelo cron do ManuHosp.</p>';
            sendMail($row['email'], '[ManuHosp] ' . $title, $html);
        }
    } catch (Throwable $ex) {
        error_log('cron email error: ' . $ex->getMessage());
    }

    return true;
}

/* ------------------------------------------------------------------
 * 1. Planos de manutenção preventiva → Ordens de Serviço
 * ------------------------------------------------------------------ */
$osCreated = 0;
try {
    $plans = $pdo->query("
        SELECT mp.*, e.name AS equipment_name
        FROM maintenance_plans mp
        LEFT JOIN equipment e ON e.id = mp.equipment_id
        WHERE mp.status = 'active'
          AND mp.next_date IS NOT NULL
          AND mp.next_date <= CURDATE()
    ")->fetchAll();

    $insertOs = $pdo->prepare("
        INSERT INTO service_orders
            (hospital_id, equipment_id, os_number, type, priority, status,
             title, description, scheduled_date, created_at)
        VALUES (?, ?, ?, 'preventive', 'medium', 'open', ?, ?, ?, NOW())
    ");
    $updatePlan = $pdo->prepare("UPDATE maintenance_plans SET next_date = ?, last_executed = NOW() WHERE id = ?");

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
        $newOsId = (int)$pdo->lastInsertId();

        $nextDate = calcNextDate(date('Y-m-d'), $plan['frequency']);
        $updatePlan->execute([$nextDate, $plan['id']]);

        pushNotification(
            $pdo,
            (int)$plan['hospital_id'],
            'maintenance_due',
            'Preventiva gerada: ' . $plan['title'],
            'OS ' . $osNumber . ' criada automaticamente para o equipamento "' . ($plan['equipment_name'] ?? '—') . '".',
            $newOsId
        );

        $osCreated++;
    }
    c_log(sprintf('Preventivas: %d OS criadas.', $osCreated));
} catch (Throwable $ex) {
    c_log('ERRO em preventivas: ' . $ex->getMessage());
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
            FROM equipment_calibrations
            GROUP BY equipment_id
        ) last
        JOIN equipment_calibrations c ON c.equipment_id = last.equipment_id AND c.next_date = last.max_next
        JOIN equipment e ON e.id = c.equipment_id
        WHERE c.next_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ")->fetchAll();

    foreach ($rows as $r) {
        $dt   = new DateTime($r['next_date']);
        $days = (int)(new DateTime('today'))->diff($dt)->format('%r%a');

        if ($days < 0) {
            $created = pushNotification(
                $pdo,
                (int)$r['hospital_id'],
                'calibration_overdue',
                'Calibração VENCIDA: ' . $r['equipment_name'],
                'O equipamento "' . $r['equipment_name'] . '" está com calibração vencida há ' . abs($days) . ' dia(s).',
                (int)$r['equipment_id']
            );
        } elseif ($days <= 15) {
            $created = pushNotification(
                $pdo,
                (int)$r['hospital_id'],
                'calibration_urgent',
                'Calibração urgente: ' . $r['equipment_name'],
                'Vence em ' . $days . ' dia(s). Programe a calibração imediatamente.',
                (int)$r['equipment_id']
            );
        } else {
            $created = pushNotification(
                $pdo,
                (int)$r['hospital_id'],
                'calibration_due_soon',
                'Calibração a vencer: ' . $r['equipment_name'],
                'Vence em ' . $days . ' dia(s).',
                (int)$r['equipment_id']
            );
        }
        if ($created) $calibNotifs++;
    }
    c_log(sprintf('Calibração: %d notificações geradas.', $calibNotifs));
} catch (Throwable $ex) {
    c_log('ERRO em calibração: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 3. Estoque abaixo do mínimo
 * ------------------------------------------------------------------ */
$stockNotifs = 0;
try {
    $rows = $pdo->query("
        SELECT id, hospital_id, name, quantity, min_quantity
        FROM parts
        WHERE status = 'active' AND quantity <= min_quantity
    ")->fetchAll();

    foreach ($rows as $p) {
        $created = pushNotification(
            $pdo,
            (int)$p['hospital_id'],
            'stock_low',
            'Estoque baixo: ' . $p['name'],
            'Quantidade atual: ' . $p['quantity'] . ' / mínimo: ' . $p['min_quantity'] . '.',
            (int)$p['id']
        );
        if ($created) $stockNotifs++;
    }
    c_log(sprintf('Estoque: %d notificações geradas.', $stockNotifs));
} catch (Throwable $ex) {
    c_log('ERRO em estoque: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 4. Limpeza de notificações lidas antigas
 * ------------------------------------------------------------------ */
try {
    $n = $pdo->exec("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
    c_log(sprintf('Notificações antigas removidas: %d.', (int)$n));
} catch (Throwable $ex) {
    c_log('ERRO ao limpar notificações: ' . $ex->getMessage());
}

/* ------------------------------------------------------------------
 * 5. Expira rate-limit de login > 1h
 * ------------------------------------------------------------------ */
$attemptsFile = __DIR__ . '/logs/login_attempts.json';
if (file_exists($attemptsFile)) {
    $data = json_decode((string)file_get_contents($attemptsFile), true) ?: [];
    $now = time();
    $kept = [];
    foreach ($data as $ip => $entry) {
        $blockedUntil = (int)($entry['blocked_until'] ?? 0);
        $first        = (int)($entry['first'] ?? 0);
        if ($blockedUntil > $now || ($now - $first) < 3600) {
            $kept[$ip] = $entry;
        }
    }
    @file_put_contents($attemptsFile, json_encode($kept), LOCK_EX);
    c_log('Rate-limit de login depurado (' . count($kept) . ' entradas ativas).');
}

/* ------------------------------------------------------------------ */
auditLog('cron_run', 'system', null, sprintf('os=%d calib=%d stock=%d', $osCreated, $calibNotifs, $stockNotifs));

$elapsed = round(microtime(true) - $startedAt, 3);
c_log(sprintf('Concluído em %.3fs.', $elapsed));
