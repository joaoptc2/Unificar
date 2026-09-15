<?php
/**
 * Restauração de um pacote de backup pela linha de comando.
 *
 * Uso:
 *   php scripts/restore.php --list
 *       lista os pacotes disponíveis (o mesmo diretório do backup)
 *
 *   php scripts/restore.php --inspect[=ID|caminho]
 *       abre o pacote, confere o manifesto e cada sha256 e mostra o
 *       comparativo com o banco atual. NÃO escreve nada, nem no banco.
 *
 *   php scripts/restore.php --restore=ID [opções]
 *       --target=producao          restaura na instalação (PADRÃO; pede confirmação)
 *       --target=outro_banco       MODO DE TESTE: restaura em outro banco
 *       --no-files                 não restaura os arquivos do pacote (padrão em produção: restaura)
 *       --files                    restaura os arquivos também no MODO DE TESTE
 *                                  (para um diretório separado, nunca sobre a instalação)
 *       --files-dir=/caminho       raiz onde gravar os arquivos
 *       --no-sanitize              (modo de teste) NÃO neutraliza as credenciais
 *       --sanitize                 (modo de teste) força a neutralização
 *       --fila-email=cancelar|apagar|manter   o que fazer com a fila pendente
 *       --no-safety                não gera o backup de segurança (produção)
 *       --restore-config           sobrescreve config/config.php (SEGREDOS)
 *       --no-verify                pula a conferência do pacote (EMERGÊNCIA)
 *       --yes                      não pergunta (para o cron/scripts)
 *
 *   php scripts/restore.php --epoch
 *       mostra a época de sessão atual e o trecho de integração a colar
 *
 * DDL NÃO TEM ROLLBACK: a partir do primeiro DROP TABLE não existe desfazer.
 * Em produção, o backup de segurança é gerado ANTES e é o caminho de volta.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Core\Backup;
use Core\BackupRestore;
use Core\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script é só para a linha de comando.\n");
}

$args = rst_args($argv ?? []);

if ($args === [] || isset($args['help']) || isset($args['h'])) {
    rst_ajuda();
    exit(isset($args['help']) || isset($args['h']) ? 0 : 1);
}

try {
    if (isset($args['list'])) {
        exit(rst_lista());
    }
    if (isset($args['epoch'])) {
        exit(rst_epoca());
    }
    if (isset($args['inspect'])) {
        exit(rst_inspeciona(is_string($args['inspect']) ? $args['inspect'] : ''));
    }
    if (isset($args['restore'])) {
        exit(rst_restaura(is_string($args['restore']) ? $args['restore'] : '', $args));
    }
    rst_ajuda();
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nERRO: " . $e->getMessage() . "\n");
    if (Config::get('app.debug', false)) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}

// ---------------------------------------------------------------------
// Ações
// ---------------------------------------------------------------------

function rst_lista(): int
{
    $itens = Backup::list();
    if ($itens === []) {
        echo "Nenhum backup em " . Backup::dir() . "\n";
        return 1;
    }
    echo "Pacotes em " . Backup::dir() . ":\n\n";
    printf("  %-19s %10s %8s %9s  %s\n", 'QUANDO', 'TAMANHO', 'TABELAS', 'LINHAS', 'ID');
    foreach ($itens as $i) {
        printf(
            "  %-19s %10s %8d %9d  %s\n",
            date('d/m/Y H:i:s', strtotime((string) $i['criado_em']) ?: (int) $i['mtime']),
            rst_bytes((int) $i['bytes']),
            (int) $i['tabelas'],
            (int) $i['linhas'],
            $i['id']
        );
    }
    echo "\n  " . count($itens) . " pacote(s).\n";
    return 0;
}

function rst_inspeciona(string $alvo): int
{
    if ($alvo === '') {
        $mais = Backup::list()[0] ?? null;
        if ($mais === null) {
            fwrite(STDERR, "Nenhum backup para inspecionar.\n");
            return 1;
        }
        $alvo = (string) $mais['id'];
    }

    echo "Inspecionando {$alvo}...\n";
    $r = BackupRestore::inspect($alvo);
    $m = $r['manifesto'];

    echo "\n";
    echo "  arquivo ......... " . basename((string) $r['caminho']) . " (" . rst_bytes((int) $r['bytes']) . ")\n";
    echo "  formato ......... {$r['formato']} " . ($r['formato_ok'] ? '(reconhecido)' : '(DESCONHECIDO)') . "\n";
    echo "  criado em ....... " . rst_data((string) $r['resumo']['criado_em']) . " por "
        . $r['resumo']['gerado_por'] . " (" . $r['resumo']['origem'] . ")\n";
    if ($r['resumo']['motivo'] !== '') {
        echo "  motivo .......... {$r['resumo']['motivo']}\n";
    }
    echo "  origem .......... banco '{$r['resumo']['banco']}' em {$r['resumo']['servidor']}, PHP {$r['resumo']['php']}\n";

    echo "\n  Integridade\n";
    echo "  entradas ........ {$r['entradas']['conferidas']}/{$r['entradas']['total']} conferidas por tamanho + sha256\n";
    $db = $r['entradas']['database_sql'];
    if (!empty($db['ausente'])) {
        echo "  database.sql .... ausente (pacote só de arquivos)\n";
    } else {
        echo "  database.sql .... " . ($db['ok'] ? 'OK' : 'NÃO CONFERE') . "  sha256 "
            . substr((string) $db['obtido'], 0, 32) . "…\n";
        if (!$db['ok']) {
            echo "                    esperado " . substr((string) $db['esperado'], 0, 32) . "…\n";
        }
    }
    echo "  arquivos ........ " . (int) $r['resumo']['arquivos'] . " no pacote"
        . ($r['resumo']['com_config'] ? ' (INCLUI config/config.php)' : '') . "\n";

    $c = $r['comparativo'];
    echo "\n  Comparativo\n";
    echo "  o backup tem .... " . (int) $c['backup']['tabelas'] . " tabela(s) / "
        . number_format((int) $c['backup']['linhas'], 0, ',', '.') . " linha(s)\n";
    if (!empty($c['disponivel'])) {
        echo "  o banco atual ... " . (int) $c['atual']['tabelas'] . " tabela(s) / "
            . number_format((int) $c['atual']['linhas'], 0, ',', '.') . " linha(s)  (banco '{$c['atual']['banco']}')\n";
        $delta = (int) $c['backup']['linhas'] - (int) $c['atual']['linhas'];
        echo "  diferença ....... " . ($delta === 0 ? 'nenhuma no total' : sprintf('%+d linha(s) ao restaurar', $delta)) . "\n";
        if ($c['diferentes'] !== []) {
            echo "  tabelas com contagem diferente (" . count($c['diferentes']) . "):\n";
            $n = 0;
            foreach ($c['diferentes'] as $t => $d) {
                printf("    %-34s backup %7d   atual %7d  (%+d)\n", $t, $d['backup'], $d['atual'], $d['delta']);
                if (++$n >= 20) {
                    echo "    … e mais " . (count($c['diferentes']) - 20) . " tabela(s).\n";
                    break;
                }
            }
        }
        foreach (['so_no_backup' => 'só no backup', 'so_no_banco' => 'só no banco atual'] as $k => $rot) {
            if (!empty($c[$k])) {
                echo "  " . count($c[$k]) . " tabela(s) {$rot}: " . implode(', ', array_slice($c[$k], 0, 10)) . "\n";
            }
        }
    } else {
        echo "  o banco atual ... indisponível (" . ($c['motivo'] ?? 'sem comparação') . ")\n";
    }

    echo "\n  Migrações\n";
    echo "  no pacote ....... {$r['migracoes']['no_pacote']} aplicada(s)\n";
    echo "  no código ....... {$r['migracoes']['no_codigo']} arquivo(s) em sql/migrations\n";
    if ($r['migracoes']['faltarao'] !== []) {
        echo "  faltarão ........ " . count($r['migracoes']['faltarao']) . " → rode php scripts/migrate.php depois\n";
    }

    rst_avisos((array) $r['avisos']);

    if ($r['ok']) {
        echo "\nResultado: PACOTE ÍNTEGRO — pode ser restaurado.\n";
        return 0;
    }
    echo "\nResultado: PACOTE RECUSADO\n";
    foreach ($r['erros'] as $e) {
        echo "  ! {$e}\n";
    }
    return 1;
}

function rst_restaura(string $id, array $args): int
{
    if ($id === '') {
        fwrite(STDERR, "Informe o backup: --restore=ID\n");
        return 1;
    }

    $target   = (string) ($args['target'] ?? 'producao');
    $producao = ($target === '' || $target === 'producao' || $target === 'produção');

    // Antes de qualquer coisa: inspeção completa na tela de quem vai decidir.
    $ins = BackupRestore::inspect($id);
    $c   = $ins['comparativo'];

    echo ($producao ? "RESTAURAÇÃO EM PRODUÇÃO" : "RESTAURAÇÃO EM MODO DE TESTE (banco '{$target}')") . "\n\n";
    echo "  pacote .......... {$ins['id']}\n";
    echo "  criado em ....... " . rst_data((string) $ins['resumo']['criado_em']) . "\n";
    echo "  integridade ..... " . ($ins['ok'] ? 'OK' : 'FALHOU') . " ({$ins['entradas']['conferidas']}/{$ins['entradas']['total']} entradas)\n";
    echo "  o backup tem .... " . (int) $c['backup']['tabelas'] . " tabela(s) / "
        . number_format((int) $c['backup']['linhas'], 0, ',', '.') . " linha(s)\n";
    if (!empty($c['disponivel'])) {
        echo "  o banco atual ... " . (int) $c['atual']['tabelas'] . " tabela(s) / "
            . number_format((int) $c['atual']['linhas'], 0, ',', '.') . " linha(s) (banco '{$c['atual']['banco']}')\n";
    }

    if (!$ins['ok'] && !isset($args['no-verify'])) {
        echo "\nO pacote NÃO passou na conferência:\n";
        foreach ($ins['erros'] as $e) {
            echo "  ! {$e}\n";
        }
        echo "\nRestauração cancelada.\n";
        return 1;
    }

    $sanitizePadrao = !$producao;
    if (isset($args['no-sanitize'])) {
        $sanitizePadrao = false;
    }
    if (isset($args['sanitize'])) {
        $sanitizePadrao = true;
    }

    echo "\n";
    if ($producao) {
        echo "  ATENÇÃO: isto APAGA e recria as tabelas do banco de produção '"
            . Config::get('db.name', '') . "'.\n";
        echo "  DDL não tem rollback: do primeiro DROP TABLE em diante não há desfazer.\n";
        echo "  Um backup de segurança será gerado antes"
            . (isset($args['no-safety']) ? " — NÃO, você passou --no-safety (sem rede).\n" : ".\n");
    } else {
        echo "  O banco '{$target}' será APAGADO e recriado a partir do pacote.\n";
        echo "  Credenciais: " . ($sanitizePadrao
            ? "serão neutralizadas (senha, segundo fator e envio de e-mail).\n"
            : "NÃO serão neutralizadas — a cópia fica com as senhas reais de todos.\n");
    }
    $comArquivos = isset($args['files']) || (!isset($args['no-files']) && $producao);
    echo "  arquivos ........ " . ($comArquivos ? 'sim' : 'não') . "\n";

    if (!isset($args['yes']) && !isset($args['y'])) {
        echo "\nConfirma? Digite 'restaurar' para seguir: ";
        $resp = trim((string) fgets(STDIN));
        if ($resp !== 'restaurar') {
            echo "Cancelado.\n";
            return 1;
        }
    }

    $opts = [
        'target'           => $target,
        'confirmado'       => true,
        // O comparativo já foi mostrado acima: pedir de novo só repetiria um
        // COUNT(*) por tabela. A conferência de integridade do pacote, essa,
        // a restauração refaz por conta própria — e é a que importa.
        'comparar'         => false,
        'with_files'       => $comArquivos,
        'sanitize'         => $sanitizePadrao,
        'verify'           => !isset($args['no-verify']),
        'backup_seguranca' => !isset($args['no-safety']),
        'restore_config'   => isset($args['restore-config']),
        'fila_email'       => (string) ($args['fila-email'] ?? 'cancelar'),
    ];
    if (!empty($args['files-dir'])) {
        $opts['files_dir'] = (string) $args['files-dir'];
    }

    echo "\nRestaurando...\n";
    $r = BackupRestore::restore($id, $opts);

    echo "\n";
    if ($r['backup_seguranca'] !== null) {
        echo "  backup de segurança .. {$r['backup_seguranca']['id']} ("
            . rst_bytes((int) $r['backup_seguranca']['bytes']) . ")\n";
    }
    echo "  banco ................ {$r['alvo']} ({$r['modo']})\n";
    echo "  comandos SQL ......... " . (int) $r['banco']['statements'] . " em "
        . number_format((float) $r['banco']['segundos'], 2, ',', '.') . "s\n";
    if (!empty($r['banco']['sessao'])) {
        $s = $r['banco']['sessao'];
        echo "  checagens ............ foreign_key_checks={$s['depois']['foreign_key_checks']}, "
            . "unique_checks={$s['depois']['unique_checks']}\n";
        echo "  sql_mode ............. " . rst_corta($s['depois']['sql_mode'], 60) . "\n";
    }
    echo "  conferência .......... {$r['conferencia']['iguais']}/{$r['conferencia']['tabelas']} tabela(s) com "
        . "linhas e digest iguais aos do manifesto (" . number_format((int) $r['conferencia']['linhas'], 0, ',', '.') . " linhas)\n";
    if ($r['arquivos']['destino'] !== '') {
        echo "  arquivos ............. {$r['arquivos']['restaurados']} restaurado(s) ("
            . rst_bytes((int) $r['arquivos']['bytes']) . "), {$r['arquivos']['ignorados']} ignorado(s)\n";
        echo "  destino .............. {$r['arquivos']['destino']}\n";
    }
    echo "  caches em disco ...... {$r['caches']} arquivo(s) apagados\n";

    if ($r['higiene'] !== []) {
        $h = $r['higiene'];
        echo "\n  Higiene pós-restauração (produção)\n";
        echo "  fila de e-mail ....... {$h['mail_queue']['afetadas']} pendente(s) "
            . ($h['mail_queue']['modo'] === 'apagar' ? 'apagada(s)' : ($h['mail_queue']['modo'] === 'manter' ? 'MANTIDA(S)' : 'cancelada(s)')) . "\n";
        echo "  tokens de senha ...... {$h['password_resets']} removido(s)\n";
        echo "  tentativas de login .. {$h['login_attempts']} removida(s)\n";
        echo "  época de sessão ...... {$h['epoca_de_sessao']} (sessões antigas devem cair)\n";
    }
    if ($r['sanitize'] !== []) {
        $s = $r['sanitize'];
        echo "\n  Sanitização (modo de teste)\n";
        echo "  usuários ............. {$s['usuarios']} com senha neutralizada, {$s['com_2fa']} tinham segundo fator\n";
        echo "  e-mail ............... " . ($s['email'] ? 'desligado na base restaurada' : 'NÃO foi possível desligar') . "\n";
        echo "  fila/tokens/logins ... {$s['fila']}/{$s['tokens']}/{$s['tentativas']} limpos\n";
    }

    if ($r['conferencia']['divergentes'] !== []) {
        echo "\n  DIVERGÊNCIAS:\n";
        foreach ($r['conferencia']['divergentes'] as $d) {
            echo "    ! {$d}\n";
        }
    }

    rst_avisos((array) $r['avisos']);

    echo "\n  " . wordwrap((string) $r['ddl'], 92, "\n  ", false) . "\n";

    $i = $r['integracao'];
    echo "\n  Para que as sessões antigas realmente caiam, cole em {$i['arquivo']} "
        . "logo depois da linha {$i['linha']} ({$i['ancora']}):\n\n";
    foreach (explode("\n", (string) $i['trecho']) as $l) {
        echo "  | {$l}\n";
    }

    echo "\n" . ($r['ok'] ? "Restauração concluída." : "Restauração concluída COM ERROS:") . "\n";
    foreach ($r['erros'] as $e) {
        echo "  ! {$e}\n";
    }
    echo "  tempo ................ " . number_format((float) $r['segundos'], 2, ',', '.') . "s\n";

    return $r['ok'] ? 0 : 1;
}

function rst_epoca(): int
{
    $e = BackupRestore::sessionEpoch();
    echo "Época de sessão atual: " . ($e === 0 ? '0 (nunca restaurado)' : $e . ' — ' . date('d/m/Y H:i:s', $e)) . "\n";
    $i = BackupRestore::sessionEpochHint();
    echo "\nTrecho a colar em {$i['arquivo']}, logo depois da linha {$i['linha']} ({$i['ancora']}):\n\n";
    echo $i['trecho'], "\n";
    return 0;
}

// ---------------------------------------------------------------------
// Apoio
// ---------------------------------------------------------------------

/** @return array<string, string|bool> */
function rst_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!str_starts_with($a, '--')) {
            continue;
        }
        $a = substr($a, 2);
        if (str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $out[$k] = $v;
            continue;
        }
        $out[$a] = true;
    }
    return $out;
}

function rst_avisos(array $avisos): void
{
    if ($avisos === []) {
        return;
    }
    echo "\nAvisos (" . count($avisos) . "):\n";
    foreach ($avisos as $a) {
        echo "  ~ " . wordwrap((string) $a, 92, "\n    ", false) . "\n";
    }
}

function rst_data(string $iso): string
{
    $t = strtotime($iso);
    return $t === false ? $iso : date('d/m/Y H:i:s', $t);
}

function rst_corta(string $s, int $n): string
{
    return mb_strlen($s) <= $n ? $s : mb_substr($s, 0, $n - 1) . '…';
}

function rst_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return number_format($v, $i === 0 ? 0 : 1, ',', '.') . ' ' . $u[$i];
}

function rst_ajuda(): void
{
    $doc = (string) file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', $doc, $m) === 1) {
        echo trim((string) preg_replace('/^[ \t]*\* ?/m', '', $m[1])), "\n";
        return;
    }
    echo "php scripts/restore.php --list | --inspect[=ID] | --restore=ID [--target=banco] ...\n";
}
