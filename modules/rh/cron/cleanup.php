<?php
/**
 * CRON: Limpeza de dados antigos (módulo RH).
 *
 * Executado pelo cron UNIFICADO da plataforma via manifesto do módulo
 * (module.php → 'cron'). Limita-se aos dados do módulo:
 *  - notificações do módulo (module='rh') já lidas e antigas;
 *  - flags de notificação de vencimentos renovados;
 *  - rate-limit do formulário público (rh_public_submissions);
 *  - arquivos órfãos em /storage/uploads/rh/ (privados) e /uploads/rh/ (legado);
 *  - cache em arquivo expirado.
 *
 * login_attempts / password_resets / audit_log são do núcleo — a limpeza
 * dessas tabelas deixou de ser responsabilidade do módulo.
 */


// Este arquivo só existe para ser chamado pelo cron da raiz (cron.php), que é
// quem confere CLI ou token. Sem esta guarda a frase acima era uma suposição:
// um GET direto no arquivo executava a rotina inteira, sem autenticação
// nenhuma — reproduzido em modules/manutencao/cron/run.php, que respondeu
// HTTP 200 e rodou as cinco tarefas. O .htaccess não salva: o Nginx o ignora,
// o Apache com AllowOverride None também, e o do próprio módulo manutenção
// bloqueava o arquivo `cron.php` e não a PASTA `cron/`.
// 404, e não 403: quem pediu não precisa saber que o arquivo existe.
if (!defined('CRON_AUTORIZADO')) {
    http_response_code(404);
    exit;
}
/**
 * Remove arquivos em {storage/uploads/rh | uploads/rh}/{sub}/ que não são
 * referenciados no banco. Arquivos com menos de 24h são preservados (margem
 * para uploads pendentes).
 */
function rh_cleanup_orphan_files(PDO $db): int
{
    $count = 0;
    $roots = array_unique([rtrim(Upload::privateDir(), '/') . '/', rtrim(Upload::baseDir(), '/') . '/']);
    $map = [
        'documents'    => ['table' => 'rh_employee_documents',   'col' => 'file_path'],
        'certificates' => ['table' => 'rh_medical_certificates', 'col' => 'file_path'],
        'resumes'      => ['table' => 'rh_candidates',           'col' => 'resume_path'],
    ];

    // Vencimentos podem usar qualquer subdir — lista separada.
    $expStmt = $db->query("SELECT file_path FROM rh_expirations WHERE file_path IS NOT NULL");
    $expirationPaths = array_map(fn($r) => basename($r['file_path']), $expStmt->fetchAll());

    foreach ($map as $sub => $cfg) {
        $referenced = [];
        $stmt = $db->query("SELECT {$cfg['col']} FROM {$cfg['table']} WHERE {$cfg['col']} IS NOT NULL");
        foreach ($stmt->fetchAll() as $row) {
            $path = $row[$cfg['col']] ?? null;
            if ($path) $referenced[basename($path)] = true;
        }
        foreach ($expirationPaths as $b) $referenced[$b] = true;

        foreach ($roots as $root) {
            $dir = $root . $sub . '/';
            if (!is_dir($dir)) continue;
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
    }
    return $count;
}

echo "[" . date('Y-m-d H:i:s') . "] [rh] Iniciando limpeza...\n";

try {
    $db = Database::getInstance();

    // 1. Notificações do módulo lidas — com o prazo da Administração, que
    //    também sabe dizer "desligado" e "nunca apagar" (0). Eram 90 dias
    //    cravados, ignorando as duas.
    $diasNotif = (class_exists('Core\\Cleanup') && Core\Cleanup::habilitado())
        ? Core\Cleanup::dias('notifications') : 0;
    if ($diasNotif > 0) {
        $stmt = $db->prepare(
            "DELETE FROM notifications
             WHERE module = 'rh' AND read_at IS NOT NULL
               AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$diasNotif]);
        echo "  Notificações antigas removidas: {$stmt->rowCount()}\n";
    } else {
        echo "  Notificações antigas: limpeza desligada na Administração, nada removido\n";
    }

    // 2. Resetar flags de notificação para vencimentos renovados
    // SÓ o vencimento RENOVADO perde a marca. A condição antiga pegava TODO
    // vencimento ainda no futuro — inclusive o que acabou de ser avisado pelo
    // check_expirations, que roda logo antes neste mesmo cron. Resultado
    // medido: a cada execução (de hora em hora, como o checkup manda
    // agendar) o mesmo alerta saía de novo, com e-mail, para cada
    // administrador. Renovado é: já venceu e foi avisado, e agora tem data
    // futura; ou foi avisado na janela prévia e agora está fora dela.
    $stmt = $db->prepare(
        "UPDATE rh_expirations SET notified_at = NULL, notified_expired_at = NULL
         WHERE (notified_expired_at IS NOT NULL AND expiry_date > CURDATE())
            OR (notified_at IS NOT NULL
                AND expiry_date > DATE_ADD(CURDATE(), INTERVAL COALESCE(alert_days, 30) DAY))"
    );
    $stmt->execute();
    echo "  Flags de vencimento resetadas: {$stmt->rowCount()}\n";

    // 3. Rate-limit do formulário público com mais de 30 dias
    $stmt = $db->prepare("DELETE FROM rh_public_submissions WHERE submitted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    echo "  Registros de rate-limit removidos: {$stmt->rowCount()}\n";

    // 4. Arquivos órfãos em /storage/uploads/rh/ e /uploads/rh/
    $orphans = rh_cleanup_orphan_files($db);
    echo "  Arquivos órfãos removidos: {$orphans}\n";

    // 5. Cache em arquivo expirado
    if (class_exists('FileCache')) {
        $purged = FileCache::purgeExpired();
        echo "  Entradas de cache expiradas removidas: {$purged}\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] [rh] Limpeza concluída.\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
