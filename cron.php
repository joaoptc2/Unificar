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
// Cada módulo carrega seu próprio código legado (helpers globais como e(),
// url(), redirect()...). Para que dois módulos nunca colidam no mesmo
// processo, quando o cron roda "tudo" cada módulo é executado em um
// subprocesso (`php cron.php --module=<slug>`); se não houver como abrir
// subprocessos (exec desabilitado, binário indisponível), executa em
// processo — os módulos protegem suas funções com function_exists().
$phpBin = cron_php_binary();
$isolate = $only === '' && $phpBin !== null && function_exists('proc_open');

foreach (Modules::all() as $slug => $manifest) {
    if ($only !== '' && $only !== $slug) {
        continue;
    }
    if (empty($manifest['cron']) || !is_callable($manifest['cron'])) {
        continue;
    }
    echo "[{$slug}] executando...\n";
    if ($isolate) {
        $out  = '';
        $code = cron_run_isolated($phpBin, $slug, $out);
        echo rtrim($out) !== '' ? rtrim($out) . "\n" : '';
        if ($code !== 0) {
            echo "[{$slug}] ERRO: subprocesso terminou com código {$code}\n";
            error_log("cron {$slug}: exit {$code}");
        }
        continue;
    }
    try {
        ($manifest['cron'])();
        echo "[{$slug}] ok\n";
    } catch (Throwable $e) {
        echo "[{$slug}] ERRO: {$e->getMessage()}\n";
        error_log("cron {$slug}: " . $e->getMessage());
    }
}

echo "concluído em " . date('Y-m-d H:i:s') . "\n";

/** Binário PHP CLI utilizável para subprocessos (ou null). */
function cron_php_binary(): ?string
{
    $candidates = [];
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = PHP_BINDIR . '/php';
    $candidates[] = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    foreach ($candidates as $bin) {
        if ($bin !== '' && is_file($bin) && is_executable($bin) && !str_contains(basename($bin), 'fpm') && !str_contains(basename($bin), 'cgi')) {
            return $bin;
        }
    }
    return null;
}

/** Executa `php cron.php --module=<slug>` e devolve o código de saída. */
function cron_run_isolated(string $phpBin, string $slug, string &$output): int
{
    $cmd  = escapeshellarg($phpBin) . ' ' . escapeshellarg(__FILE__) . ' --module=' . escapeshellarg($slug);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $spec, $pipes, dirname(__FILE__));
    if (!is_resource($proc)) {
        $output = "[{$slug}] não foi possível abrir o subprocesso";
        return 1;
    }
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}
