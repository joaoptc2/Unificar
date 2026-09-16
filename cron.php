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

require __DIR__ . '/localizar.php';
require UNIFICAR_APP_DIR . '/core/bootstrap.php';

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
        printf("[core] e-mails: %d enviado(s), %d falha(s), %d reagendado(s), %d retida(s)\n",
            $mq['sent'], $mq['failed'], $mq['retried'], $mq['held'] ?? 0);
        if (($mq['held'] ?? 0) > 0) {
            echo "[core] as retidas esperam conserto da configuração (Administração > E-mail)\n";
        }
    } catch (Throwable $e) {
        echo "[core] ERRO na fila de e-mails: {$e->getMessage()}\n";
        error_log('cron mail_queue: ' . $e->getMessage());
    }

    // Backup automático: sai na primeira execução depois da hora marcada,
    // desde que o último tenha mais de 20 h — um cron atrasado não pode
    // deixar o dia sem cópia.
    try {
        $bk = Core\Backup::runScheduled();
        echo '[core] backup: ' . $bk['motivo'] . "\n";
    } catch (Throwable $e) {
        echo "[core] ERRO no backup automático: {$e->getMessage()}\n";
        error_log('cron backup: ' . $e->getMessage());
    }

    // Limpeza periódica: apaga o que passou do prazo configurado em
    // Administração > Configurações. Roda DEPOIS do backup de propósito —
    // o backup do dia é feito antes de qualquer coisa ser apagada.
    try {
        if (Core\Cleanup::habilitado()) {
            $cl = Core\Cleanup::run();
            printf("[core] limpeza: %d registro(s) removido(s) em %.0f ms\n", $cl['total'], $cl['ms']);
            foreach ($cl['itens'] as $chave => $i) {
                if (!empty($i['erro'])) {
                    echo "[core] limpeza ERRO em {$chave}: {$i['erro']}\n";
                }
            }
            // Retenção dos pacotes de backup (regra de diários/semanais/mensais).
            $bkc = Core\Cleanup::backups();
            if ($bkc['qtd'] > 0) {
                printf("[core] backups antigos removidos: %d (%s)\n",
                    $bkc['qtd'], Core\HealthCheck::bytes($bkc['bytes']));
            }
        } else {
            echo "[core] limpeza: desativada nas configurações\n";
        }
    } catch (Throwable $e) {
        echo "[core] ERRO na limpeza: {$e->getMessage()}\n";
        error_log('cron cleanup: ' . $e->getMessage());
    }

    // Teste de exposição das pastas internas. Roda AQUI, e não na tela do
    // checkup, porque é uma requisição do servidor para ele mesmo: em
    // hospedagem com um worker de PHP só, feita de dentro de uma requisição
    // web ela trava a própria página até estourar o tempo. Pela linha de
    // comando não há essa disputa.
    //
    // Uma vez por dia basta: o que muda o resultado é um deploy ou uma troca
    // de servidor, não o movimento do dia.
    try {
        // A marca de TENTATIVA, não a do último resultado: um teste que não
        // conclui nada não grava resultado, e olhar só o resultado faria a
        // sonda inteira rodar de novo em toda execução do cron.
        $marca = Core\Exposicao::tentadoEm();
        $idade = $marca !== null ? time() - (int) strtotime($marca) : PHP_INT_MAX;
        if ($idade > 20 * 3600) {
            $ex = Core\Exposicao::testar(false);
            $abertas = array_filter($ex['itens'], fn ($i) => $i['estado'] === 'exposta');
            $duvida  = array_filter($ex['itens'], fn ($i) => $i['estado'] === 'indeterminado');
            if ($abertas) {
                echo '[core] exposição: ' . count($abertas) . ' pasta(s) ABERTA(S) na web: '
                   . implode(', ', array_column($abertas, 'pasta')) . "\n";
            } elseif ($duvida) {
                // "Não consegui testar" NÃO é "está tudo bem". Sem este ramo,
                // um teste que falhou inteiro imprimia a linha verde.
                echo '[core] exposição: NÃO foi possível testar ' . count($duvida) . ' pasta(s): '
                   . implode(', ', array_column($duvida, 'pasta')) . "\n";
            } else {
                echo "[core] exposição: nenhuma pasta sensível é entregue pela web\n";
            }
        } else {
            printf("[core] exposição: testado há %.0f h, pulando\n", $idade / 3600);
        }
    } catch (Throwable $e) {
        echo "[core] ERRO no teste de exposição: {$e->getMessage()}\n";
        error_log('cron exposicao: ' . $e->getMessage());
    }

    // Marcador de execução: é o que permite à Administração dizer "o cron não
    // está agendado" em vez de deixar tudo pendente em silêncio.
    try {
        Core\Settings::set('cron.last_run_at', date('Y-m-d H:i:s'));
    } catch (Throwable) {
    }
}

// A partir daqui a execução está autorizada — por CLI ou pelo token conferido
// acima. Os crons dos módulos exigem esta constante e recusam qualquer outra
// forma de chegar até eles: eles dizem no próprio cabeçalho que "a
// autenticação é feita pelo cron da raiz", e essa frase só era verdade
// enquanto o servidor web recusasse o acesso direto ao arquivo — o que
// nenhum .htaccess garante.
define('CRON_AUTORIZADO', true);

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
