<?php
/**
 * CRON Job: Limpeza de dados antigos
 *
 * Configurar no cPanel/Hostinger:
 * Frequência: Semanalmente (domingo às 03:00)
 * Comando: php /home/usuario/public_html/cron/cleanup.php
 */

define('BASE_PATH', dirname(__DIR__));

// Bootstrap comum: valida contexto, carrega config e autoloader.
require __DIR__ . '/bootstrap.php';

/**
 * Remove arquivos em storage/uploads/{sub}/ que não são referenciados no banco.
 * Arquivos com menos de 24h são preservados (margem para uploads pendentes).
 */
function cleanupOrphanFiles(PDO $db): int
{
    $count = 0;
    $storageBase = BASE_PATH . '/storage/uploads/';
    $map = [
        'documents'    => ['table' => 'employee_documents',    'col' => 'file_path'],
        'certificates' => ['table' => 'medical_certificates',  'col' => 'file_path'],
        'resumes'      => ['table' => 'candidates',            'col' => 'resume_path'],
    ];

    // Vencimentos podem usar qualquer subdir — lista separada.
    $expStmt = $db->query("SELECT file_path FROM expirations WHERE file_path IS NOT NULL");
    $expirationPaths = array_map(fn($r) => basename($r['file_path']), $expStmt->fetchAll());

    foreach ($map as $sub => $cfg) {
        $dir = $storageBase . $sub . '/';
        if (!is_dir($dir)) continue;

        $referenced = [];
        $stmt = $db->query("SELECT {$cfg['col']} FROM {$cfg['table']} WHERE {$cfg['col']} IS NOT NULL");
        foreach ($stmt->fetchAll() as $row) {
            $path = $row[$cfg['col']] ?? null;
            if ($path) $referenced[basename($path)] = true;
        }
        foreach ($expirationPaths as $b) $referenced[$b] = true;

        foreach (glob($dir . '*') ?: [] as $file) {
            if (!is_file($file)) continue;
            $base = basename($file);
            if ($base === '.htaccess' || $base === '.gitkeep') continue;
            if (filemtime($file) > time() - 86400) continue;
            if (!isset($referenced[$base])) {
                @unlink($file);
                $count++;
            }
        }
    }
    return $count;
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando limpeza...\n";

try {
    $db = Database::getInstance();

    // 1. Notificações lidas com mais de 90 dias
    $stmt = $db->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    echo "  Notificações antigas removidas: {$stmt->rowCount()}\n";

    // 2. Logs de auditoria com mais de 365 dias
    $stmt = $db->prepare("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");
    $stmt->execute();
    echo "  Logs de auditoria antigos removidos: {$stmt->rowCount()}\n";

    // 3. Resetar flags de notificação para vencimentos renovados
    $stmt = $db->prepare(
        "UPDATE expirations SET notified_at = NULL, notified_expired_at = NULL
         WHERE expiry_date > CURDATE() AND (notified_at IS NOT NULL OR notified_expired_at IS NOT NULL)"
    );
    $stmt->execute();
    echo "  Flags de vencimento resetadas: {$stmt->rowCount()}\n";

    // 4. Tokens de reset usados/expirados
    $stmt = $db->prepare("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW()");
    $stmt->execute();
    echo "  Tokens de reset expirados removidos: {$stmt->rowCount()}\n";

    // 5. Tentativas de login com mais de 30 dias
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    echo "  Tentativas de login antigas removidas: {$stmt->rowCount()}\n";

    // 6. Rate-limit do formulário público com mais de 30 dias
    $stmt = $db->prepare("DELETE FROM public_submissions WHERE submitted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    echo "  Registros de rate-limit removidos: {$stmt->rowCount()}\n";

    // 7. Arquivos órfãos em storage/uploads/
    $orphans = cleanupOrphanFiles($db);
    echo "  Arquivos órfãos removidos: {$orphans}\n";

    // 8. Cache em arquivo expirado
    if (class_exists('FileCache')) {
        $purged = FileCache::purgeExpired();
        echo "  Entradas de cache expiradas removidas: {$purged}\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Limpeza concluída.\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
    exit(1);
}
