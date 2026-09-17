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
            // As duas listas podem existir AO MESMO TEMPO, e as duas têm de
            // sair. Era um elseif: com uma pasta aberta, as que não puderam
            // ser testadas — entre elas a de backup e a de config — sumiam da
            // saída sem uma linha. "Não consegui testar" NÃO é "está tudo bem".
            if ($abertas) {
                echo '[core] exposição: ' . count($abertas) . ' pasta(s) ABERTA(S) na web: '
                   . implode(', ', array_column($abertas, 'pasta')) . "\n";
            }
            if ($duvida) {
                echo '[core] exposição: NÃO foi possível testar ' . count($duvida) . ' pasta(s): '
                   . implode(', ', array_column($duvida, 'pasta')) . "\n";
            }
            if (!$abertas && !$duvida) {
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
$isolate = $only === '' && $phpBin !== null && cron_isolamento_disponivel();

// SEM isolamento, os módulos NÃO podem rodar juntos no mesmo processo: eles
// declaram funções globais com o mesmo nome (e, redirect, url, paginate),
// e o segundo a carregar mata o processo com Fatal error. Proteger uma
// função por function_exists só muda qual é a próxima a colidir — e pior,
// faz o módulo B rodar a url() do módulo A, gerando link errado em
// notificação, sem erro nenhum.
//
// A regra é: sem isolamento e com mais de um módulo com cron, roda SÓ o
// primeiro, e os outros ficam registrados para o checkup mostrar em
// vermelho, com a instrução exata para o agendador da hospedagem.
$comCron = [];
foreach (Modules::all() as $s => $m) {
    if (!empty($m['cron']) && is_callable($m['cron'])) {
        $comCron[] = $s;
    }
}
$semIsolamento = [];
if ($only === '' && !$isolate && count($comCron) > 1) {
    $semIsolamento = array_slice($comCron, 1);
    echo '[core] AVISO: este servidor não permite abrir subprocesso (proc_open, popen e exec '
       . "desabilitados). Os módulos não podem rodar juntos no mesmo processo; só o primeiro "
       . "({$comCron[0]}) vai rodar agora.\n";
    echo '[core] Agende no painel da hospedagem, um por linha: '
       . implode(' ; ', array_map(fn ($s) => "php cron.php --module={$s}", $comCron)) . "\n";
    error_log('cron: sem isolamento, ' . count($semIsolamento) . ' módulo(s) não rodaram: ' . implode(', ', $semIsolamento));
}
try {
    // O checkup lê isto. Limpar quando o problema some é tão importante
    // quanto gravar quando aparece.
    Core\Settings::set('cron.sem_isolamento', implode(',', $semIsolamento));
} catch (Throwable) {
}

// Um erro FATAL (E_ERROR) não é Throwable: o try/catch abaixo não o pega, e
// o processo morre sem dizer em que módulo estava. Foi assim que a rotina de
// manutenção ficou meses sem rodar numa hospedagem sem proc_open — o cron
// morria no 2º módulo por uma função redeclarada, e a saída simplesmente
// parava. Este gancho é a última palavra: se o script terminar com erro
// fatal, ele diz qual módulo estava em andamento.
$GLOBALS['cron_modulo_em_andamento'] = null;
register_shutdown_function(static function (): void {
    $e = error_get_last();
    $m = $GLOBALS['cron_modulo_em_andamento'] ?? null;
    if ($m !== null && $e !== null && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        $msg = "[{$m}] ERRO FATAL: {$e['message']} em {$e['file']}:{$e['line']}";
        echo $msg, "\n";
        error_log("cron {$m}: " . $e['message'] . ' em ' . $e['file'] . ':' . $e['line']);
    }
});

$rodaram = 0;
foreach (Modules::all() as $slug => $manifest) {
    if ($only !== '' && $only !== $slug) {
        continue;
    }
    if (empty($manifest['cron']) || !is_callable($manifest['cron'])) {
        continue;
    }
    if (in_array($slug, $semIsolamento, true)) {
        echo "[{$slug}] NÃO rodou: sem isolamento de processo (ver aviso acima)\n";
        continue;
    }
    $rodaram++;
    $GLOBALS['cron_modulo_em_andamento'] = $slug;
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
    $GLOBALS['cron_modulo_em_andamento'] = null;
}

// Zero módulos com cron NÃO é normal numa instalação com módulos ativos:
// significa que MODULES_PATH aponta para uma pasta vazia ou inexistente
// (mudança de pastas pela metade, por exemplo). Terminar com "concluído" e
// código 0 aqui era o cron fingindo que tudo correu bem enquanto nenhuma
// rotina de vencimento rodava.
if ($only === '' && $rodaram === 0) {
    $ativos = 0;
    try {
        $ativos = (int) (Core\DB::queryOne('SELECT COUNT(*) n FROM modules WHERE active = 1')['n'] ?? 0);
    } catch (Throwable) {
    }
    if ($ativos > 0) {
        echo "[core] ERRO: nenhum módulo foi encontrado em " . MODULES_PATH
           . ", mas o banco tem {$ativos} módulo(s) ativo(s). Nenhuma rotina de módulo rodou.\n";
        error_log('cron: nenhum manifesto em ' . MODULES_PATH . " com {$ativos} módulo(s) ativo(s) no banco");
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
/**
 * Há como abrir um subprocesso? disable_functions costuma tirar proc_open,
 * mas nem sempre tira popen e exec juntos — e qualquer um dos três basta.
 */
function cron_isolamento_disponivel(): bool
{
    return function_exists('proc_open') || function_exists('popen') || function_exists('exec');
}

function cron_run_isolated(string $phpBin, string $slug, string &$output): int
{
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg(__FILE__) . ' --module=' . escapeshellarg($slug);

    if (function_exists('proc_open')) {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, dirname(__FILE__));
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($proc);
        }
    }
    if (function_exists('popen')) {
        $h = @popen($cmd . ' 2>&1', 'r');
        if (is_resource($h)) {
            $output = (string) stream_get_contents($h);
            return pclose($h);
        }
    }
    if (function_exists('exec')) {
        $linhas = [];
        $code   = 1;
        @exec($cmd . ' 2>&1', $linhas, $code);
        $output = implode("\n", $linhas);
        return (int) $code;
    }
    $output = "[{$slug}] não foi possível abrir o subprocesso";
    return 1;
}
