<?php
/**
 * Cron unificado. Executa as rotinas periódicas de todos os módulos.
 *
 * CLI:  php cron.php [--module=<slug>]
 * HTTP: cron.php?token=<cron_secret>[&module=<slug>]
 *
 * Cada módulo pode expor 'cron' => callable no manifesto (module.php).
 */

declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

use Core\Config;
use Core\Modules;

// ---- Autorização ---------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals((string) Config::get('cron_secret', ''), $token)) {
        http_response_code(403);
        exit('Token inválido.');
    }
    header('Content-Type: text/plain; charset=utf-8');
    $only = (string) ($_GET['module'] ?? '');
} else {
    $only = '';
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--module=')) {
            $only = substr($arg, 9);
        }
    }
}

// ---- Núcleo: fila de e-mails -------------------------------------------
if ($only === '' || $only === 'core') {
    echo "[core] fila de e-mails...\n";
    try {
        $mq = Core\MailQueue::process(300);
        echo "[core] e-mails: {$mq['sent']} enviado(s), {$mq['failed']} falha(s), {$mq['retried']} reagendado(s)\n";
    } catch (Throwable $e) {
        echo "[core] ERRO na fila de e-mails: {$e->getMessage()}\n";
        error_log('cron mail_queue: ' . $e->getMessage());
    }
}

// ---- Execução -------------------------------------------------------------
foreach (Modules::all() as $slug => $manifest) {
    if ($only !== '' && $only !== $slug) {
        continue;
    }
    if (empty($manifest['cron']) || !is_callable($manifest['cron'])) {
        continue;
    }
    echo "[{$slug}] executando...\n";
    try {
        ($manifest['cron'])();
        echo "[{$slug}] ok\n";
    } catch (Throwable $e) {
        echo "[{$slug}] ERRO: {$e->getMessage()}\n";
        error_log("cron {$slug}: " . $e->getMessage());
    }
}

echo "concluído em " . date('Y-m-d H:i:s') . "\n";
