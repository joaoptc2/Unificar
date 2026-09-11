<?php
/**
 * CRON do módulo DOCUMENTOS: documentos CONTROLADOS vencendo/vencidos →
 * notificações (tabela global, module='documentos') + e-mails.
 * Documentos não controlados (is_controlled = 0) não têm validade e são
 * ignorados.
 *
 * Executado pelo cron unificado da plataforma via manifesto:
 *   php cron.php --module=documentos
 *   (ou cron.php?token=<cron_secret>&module=documentos)
 *
 * O núcleo (core/bootstrap.php) já está carregado — este arquivo apenas
 * carrega a infraestrutura do próprio módulo (sem bootstrap próprio).
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/cache.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/formula.php';
require_once dirname(__DIR__) . '/includes/analysis.php';

foreach (glob(dirname(__DIR__) . '/models/*.php') as $m) require_once $m;

set_time_limit(0);

echo "[" . date('Y-m-d H:i:s') . "] Iniciando verificação...\n";

$total_notifications = 0;
$total_emails        = 0;

/**
 * Link relativo para um documento (as views do núcleo resolvem a partir
 * da raiz da plataforma; e-mails usam url() absoluta quando BASE_URL existe).
 */
$doc_link = function ($doc_id) {
    return 'index.php?' . http_build_query(['m' => 'documentos', 'url' => 'documents/view', 'id' => (int) $doc_id]);
};

try {
    // 1. Documentos vencendo nos próximos N dias (campo notify_days_before por doc)
    $documents = db_query(
        "SELECT d.id, d.title, d.category, d.expiration_date, d.notify_days_before, d.hospital_id
         FROM doc_documents d
         WHERE d.deleted_at IS NULL AND d.is_controlled = 1
           AND d.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL d.notify_days_before DAY)"
    );

    echo "Documentos vencendo: " . count($documents) . "\n";

    $managers = user_managers_of(); // gestores + admins do módulo (globais)

    foreach ($documents as $doc) {
        $days = (int) ((strtotime($doc['expiration_date']) - strtotime('today')) / 86400);

        foreach ($managers as $user) {
            // Evita spam: um aviso por documento/usuário a cada 24h
            $exists = db_query_one(
                "SELECT id FROM notifications
                 WHERE user_id = ? AND module = 'documentos' AND type = 'document_expiring'
                   AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                [$user['id'], '%doc_id=' . $doc['id'] . '%']
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

            notification_create($user['id'], $title, $msg, 'document_expiring', $doc_link($doc['id']));
            $total_notifications++;

            // E-mail (Core\Mailer decide se mail.enabled está ativo)
            if (!empty($user['email'])) {
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
             FROM doc_documents
             WHERE deleted_at IS NULL AND is_controlled = 1
               AND expiration_date IS NOT NULL AND expiration_date < CURDATE()"
        );
        echo "Documentos vencidos: " . count($expired) . "\n";

        foreach ($expired as $doc) {
            foreach ($managers as $user) {
                $exists = db_query_one(
                    "SELECT id FROM notifications
                     WHERE user_id = ? AND module = 'documentos' AND type = 'document_expired'
                       AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                    [$user['id'], '%doc_id=' . $doc['id'] . '%']
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

                notification_create($user['id'], $title, $msg, 'document_expired', $doc_link($doc['id']));
                $total_notifications++;

                if (!empty($user['email'])) {
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

    // Limpezas de login_attempts / password_resets / auditoria são do núcleo.

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
    throw $ex; // deixa o cron unificado registrar a falha do módulo
}
