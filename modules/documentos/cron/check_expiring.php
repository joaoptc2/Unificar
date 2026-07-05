<?php
/**
 * CRON: Verificar documentos vencendo, gerar notificações e enviar e-mails.
 *
 * Agende na Hostinger (hPanel > Avançado > Cron Jobs):
 *   /usr/bin/php -f /home/uXXXXXX/domains/SEUDOMINIO/public_html/cron/check_expiring.php
 *   Frequência: diária (00:00)
 */

// Evita execução via HTTP
if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_ADDR']) === false) {
    // Permite Hostinger Cron (sem REMOTE_ADDR) mas bloqueia acesso externo direto.
    http_response_code(403); exit;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/cache.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/rate_limit.php';
require_once dirname(__DIR__) . '/includes/formula.php';
require_once dirname(__DIR__) . '/includes/analysis.php';

foreach (glob(MODELS_PATH . '/*.php') as $m) require_once $m;

set_time_limit(0);

echo "[" . date('Y-m-d H:i:s') . "] Iniciando verificação...\n";

$total_notifications = 0;
$total_emails        = 0;

try {
    // 1. Documentos vencendo nos próximos N dias (campo notify_days_before por doc)
    $documents = db_query(
        "SELECT d.id, d.title, d.category, d.expiration_date, d.notify_days_before, d.hospital_id
         FROM documents d
         WHERE d.deleted_at IS NULL
           AND d.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL d.notify_days_before DAY)"
    );

    echo "Documentos vencendo: " . count($documents) . "\n";

    foreach ($documents as $doc) {
        $days = (int) ((strtotime($doc['expiration_date']) - strtotime('today')) / 86400);
        $managers = user_managers_of($doc['hospital_id']);

        foreach ($managers as $user) {
            // Evita spam: um aviso por documento/usuário a cada 24h
            $exists = db_query_one(
                "SELECT id FROM notifications
                 WHERE hospital_id = ? AND user_id = ? AND type = 'document_expiring'
                   AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                [$doc['hospital_id'], $user['id'], '%doc_id=' . $doc['id'] . '%']
            );
            if ($exists) continue;

            $title = $days === 0
                ? "Documento vence HOJE: {$doc['title']}"
                : "Documento vence em {$days} dias: {$doc['title']}";
            $msg = sprintf(
                'O documento "%s" (categoria: %s) vence em %s. [doc_id=%d]',
                $doc['title'], $doc['category'],
                date('d/m/Y', strtotime($doc['expiration_date'])),
                $doc['id']
            );

            notification_create($doc['hospital_id'], $user['id'], $title, $msg, 'document_expiring');
            $total_notifications++;

            // E-mail
            if (MAIL_ENABLED && !empty($user['email'])) {
                $body = '<p>Olá ' . e($user['name']) . ',</p>'
                      . '<p>O documento <strong>' . e($doc['title']) . '</strong> '
                      . ($days === 0
                          ? 'vence <strong>HOJE</strong>.'
                          : 'vencerá em <strong>' . $days . ' dia(s)</strong>.')
                      . '</p>'
                      . '<p><strong>Data de validade:</strong> '
                      . date('d/m/Y', strtotime($doc['expiration_date'])) . '<br>'
                      . '<strong>Categoria:</strong> ' . e($doc['category']) . '</p>';

                if (send_mail(
                    $user['email'],
                    '[' . APP_NAME . '] ' . $title,
                    mail_template('Documento vencendo', $body,
                                  url('documents/view?id=' . $doc['id']), 'Ver documento')
                )) {
                    $total_emails++;
                }
            }
        }
    }

    // 2. Documentos vencidos (aviso semanal, segundas)
    if ((int) date('N') === 1) {
        $expired = db_query(
            "SELECT id, title, category, expiration_date, hospital_id
             FROM documents
             WHERE deleted_at IS NULL AND expiration_date < CURDATE()"
        );
        echo "Documentos vencidos: " . count($expired) . "\n";

        foreach ($expired as $doc) {
            $managers = user_managers_of($doc['hospital_id']);

            foreach ($managers as $user) {
                $exists = db_query_one(
                    "SELECT id FROM notifications
                     WHERE hospital_id = ? AND user_id = ? AND type = 'document_expired'
                       AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                    [$doc['hospital_id'], $user['id'], '%doc_id=' . $doc['id'] . '%']
                );
                if ($exists) continue;

                $days_expired = (int) ((strtotime('today') - strtotime($doc['expiration_date'])) / 86400);
                $title = "VENCIDO: {$doc['title']}";
                $msg = sprintf(
                    'O documento "%s" está vencido há %d dias (venceu em %s). [doc_id=%d]',
                    $doc['title'], $days_expired,
                    date('d/m/Y', strtotime($doc['expiration_date'])),
                    $doc['id']
                );

                notification_create($doc['hospital_id'], $user['id'], $title, $msg, 'document_expired');
                $total_notifications++;

                if (MAIL_ENABLED && !empty($user['email'])) {
                    $body = '<p>Olá ' . e($user['name']) . ',</p>'
                          . '<p>O documento <strong>' . e($doc['title']) . '</strong> '
                          . 'está vencido há <strong>' . $days_expired . ' dia(s)</strong>.</p>';
                    if (send_mail(
                        $user['email'],
                        '[' . APP_NAME . '] ' . $title,
                        mail_template('Documento vencido', $body,
                                      url('documents/view?id=' . $doc['id']), 'Ver documento')
                    )) {
                        $total_emails++;
                    }
                }
            }
        }
    }

    // 3. Limpeza de tentativas de login antigas
    login_attempts_cleanup();

    // 4. Retenção de audit_logs: mantém 12 meses
    db_execute("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)");

    // 5. Tokens de reset expirados
    db_execute("DELETE FROM password_resets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

    echo "Notificações criadas: $total_notifications\n";
    echo "E-mails enviados: $total_emails\n";
    echo "[" . date('Y-m-d H:i:s') . "] Verificação concluída.\n";

} catch (Exception $ex) {
    $msg = "ERRO: " . $ex->getMessage();
    echo $msg . "\n";
    @file_put_contents(
        LOGS_PATH . '/cron_error.log',
        date('Y-m-d H:i:s') . " | " . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
    exit(1);
}
