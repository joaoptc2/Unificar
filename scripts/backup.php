<?php
/**
 * Backup da plataforma pela linha de comando.
 *
 * Uso:
 *   php scripts/backup.php                      gera um backup completo
 *   php scripts/backup.php --no-files           só o banco (sem uploads)
 *   php scripts/backup.php --no-db              só os arquivos
 *   php scripts/backup.php --out=/caminho/dir   grava em outro diretório
 *   php scripts/backup.php --include-config     inclui config/config.php (SEGREDOS)
 *   php scripts/backup.php --motivo="antes da atualização"
 *
 *   php scripts/backup.php --list               lista os pacotes existentes
 *   php scripts/backup.php --verify[=ID]        confere um pacote (padrão: o mais recente)
 *   php scripts/backup.php --manifest=ID        mostra o manifesto
 *   php scripts/backup.php --delete=ID          apaga um pacote
 *   php scripts/backup.php --retention          aplica a política de retenção
 *   php scripts/backup.php --scheduled          rodada do cron (só age se for a hora)
 *   php scripts/backup.php --restore=ID [--into=banco] [--no-files] [--restore-config]
 *
 * No cron da hospedagem, uma linha por dia basta:
 *   php /caminho/para/scripts/backup.php --scheduled
 * (ele mesmo decide se já é hora; ligue em Settings backup.schedule_enabled=1)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Core\Backup;
use Core\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script é só para a linha de comando.\n");
}

$args = bkp_args($argv ?? []);

if (isset($args['help']) || isset($args['h'])) {
    bkp_ajuda();
    exit(0);
}

// --out troca o diretório de destino só para esta execução (não mexe no
// config/config.php da instalação).
if (!empty($args['out'])) {
    $dir = (string) $args['out'];
    Config::load(array_merge(Config::all(), ['backup' => array_merge(
        (array) Config::get('backup', []),
        ['path' => $dir]
    )]));
    echo "Diretório de destino: " . Backup::dir() . "\n";
}

try {
    if (isset($args['list'])) {
        exit(bkp_lista());
    }
    if (isset($args['manifest'])) {
        exit(bkp_manifesto((string) $args['manifest']));
    }
    if (isset($args['verify'])) {
        exit(bkp_confere(is_string($args['verify']) ? $args['verify'] : ''));
    }
    if (isset($args['delete'])) {
        exit(bkp_apaga((string) $args['delete']));
    }
    if (isset($args['retention'])) {
        exit(bkp_retencao());
    }
    if (isset($args['restore'])) {
        exit(bkp_restaura((string) $args['restore'], $args));
    }
    if (isset($args['scheduled'])) {
        exit(bkp_agendado($args));
    }
    exit(bkp_cria($args));
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

function bkp_cria(array $args): int
{
    $opts = [
        'files'          => !isset($args['no-files']),
        'database'       => !isset($args['no-db']),
        'include_config' => isset($args['include-config']),
        'retencao'       => !isset($args['no-retention']),
        'motivo'         => (string) ($args['motivo'] ?? 'manual (cli)'),
    ];

    $oque = match (true) {
        $opts['files'] && $opts['database'] => 'arquivos + banco',
        $opts['database']                   => 'só o banco',
        $opts['files']                      => 'só os arquivos',
        default                             => 'nada (use sem --no-files/--no-db)',
    };
    echo "Gerando backup ({$oque})...\n";
    $t0 = microtime(true);
    $r  = Backup::create($opts);

    $m = $r['manifesto'];
    echo "\n";
    echo "  pacote .......... {$r['arquivo']}\n";
    echo "  em .............. " . dirname($r['caminho']) . "\n";
    echo "  tamanho ......... " . bkp_bytes($r['bytes']) . "\n";
    echo "  banco ........... " . count($m['banco']['tabelas']) . " tabela(s), "
        . bkp_linhas($m) . " linha(s), " . bkp_bytes((int) $m['banco']['bytes']) . " de SQL\n";
    echo "  arquivos ........ " . (int) $m['arquivos']['total'] . " arquivo(s) em "
        . (int) $m['arquivos']['diretorios'] . " diretório(s), " . bkp_bytes((int) $m['arquivos']['bytes']) . "\n";
    echo "  migrações ....... " . count($m['migracoes']['aplicadas']) . " aplicada(s), "
        . count($m['migracoes']['pendentes']) . " pendente(s)\n";
    echo "  conferência ..... OK (cada entrada relida e comparada ao manifesto)\n";
    echo "  tempo ........... " . number_format(microtime(true) - $t0, 2, ',', '.') . "s\n";

    bkp_avisos($r['avisos']);
    echo "\nPronto.\n";
    return 0;
}

function bkp_lista(): int
{
    $itens = Backup::list();
    if ($itens === []) {
        echo "Nenhum backup em " . Backup::dir() . "\n";
        return 0;
    }
    echo "Backups em " . Backup::dir() . ":\n\n";
    printf("  %-19s %10s %8s %8s %-18s %s\n", 'QUANDO', 'TAMANHO', 'TABELAS', 'ARQUIVOS', 'POR', 'ID');
    $total = 0;
    foreach ($itens as $i) {
        $total += (int) $i['bytes'];
        printf(
            "  %-19s %10s %8d %8d %-18s %s\n",
            date('d/m/Y H:i:s', strtotime((string) $i['criado_em']) ?: (int) $i['mtime']),
            bkp_bytes((int) $i['bytes']),
            (int) $i['tabelas'],
            (int) $i['arquivos'],
            mb_substr((string) $i['gerado_por'], 0, 18),
            $i['id']
        );
    }
    echo "\n  " . count($itens) . " pacote(s), " . bkp_bytes($total) . " no total.\n";
    return 0;
}

function bkp_confere(string $id): int
{
    $item = $id === '' ? (Backup::list()[0] ?? null) : Backup::find($id);
    if ($item === null) {
        fwrite(STDERR, "Backup não encontrado.\n");
        return 1;
    }
    echo "Conferindo {$item['id']} (" . bkp_bytes((int) $item['bytes']) . ")...\n";
    $man = Backup::manifest((string) $item['id']);
    $r   = Backup::verify((string) $item['caminho'], $man);

    echo "  entradas ........ {$r['entradas']}\n";
    echo "  bytes lidos ..... " . bkp_bytes($r['bytes']) . "\n";
    if ($r['ok']) {
        echo "  resultado ....... OK — tudo bate com o manifesto.\n";
        return 0;
    }
    echo "  resultado ....... FALHOU\n";
    foreach ($r['erros'] as $e) {
        echo "    ! {$e}\n";
    }
    return 1;
}

function bkp_manifesto(string $id): int
{
    $m = Backup::manifest($id);
    if ($m === null) {
        fwrite(STDERR, "Manifesto não encontrado.\n");
        return 1;
    }
    echo json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    return 0;
}

function bkp_apaga(string $id): int
{
    if (!Backup::delete($id)) {
        fwrite(STDERR, "Não foi possível apagar (não encontrado?): {$id}\n");
        return 1;
    }
    echo "Apagado: {$id}\n";
    return 0;
}

function bkp_retencao(): int
{
    $r = Backup::retention();
    echo "Retenção aplicada.\n";
    echo "  apagados ........ " . count($r['apagados']) . "\n";
    foreach ($r['apagados'] as $a) {
        echo "    - {$a}\n";
    }
    echo "  mantidos ........ {$r['mantidos']} (" . bkp_bytes((int) $r['bytes']) . ")\n";
    return 0;
}

function bkp_agendado(array $args): int
{
    $r = Backup::runScheduled();
    if (!$r['executou']) {
        echo "Nada a fazer: {$r['motivo']}\n";
        return 0;
    }
    echo "Backup agendado gerado: {$r['backup']['arquivo']} (" . bkp_bytes((int) $r['backup']['bytes']) . ")\n";
    bkp_avisos($r['backup']['avisos']);
    return 0;
}

function bkp_restaura(string $id, array $args): int
{
    $item = Backup::find($id);
    if ($item === null) {
        fwrite(STDERR, "Backup não encontrado: {$id}\n");
        return 1;
    }
    $destino = (string) ($args['into'] ?? Config::get('db.name', ''));

    echo "ATENÇÃO: a restauração APAGA e recria as tabelas do banco '{$destino}'.\n";
    echo "  pacote .......... {$item['id']}\n";
    echo "  arquivos ........ " . (isset($args['no-files']) ? 'não' : 'sim') . "\n";
    if (!isset($args['yes']) && !isset($args['y'])) {
        echo "\nConfirma? Digite 'restaurar' para seguir: ";
        $resp = trim((string) fgets(STDIN));
        if ($resp !== 'restaurar') {
            echo "Cancelado.\n";
            return 1;
        }
    }

    $r = Backup::restore($item['id'], [
        'files'          => !isset($args['no-files']),
        'database'       => !isset($args['no-db']),
        'database_name'  => $destino !== '' ? $destino : null,
        'restore_config' => isset($args['restore-config']),
    ]);

    echo "\n";
    echo "  comandos SQL .... {$r['banco']['statements']}\n";
    echo "  tabelas ......... {$r['banco']['tabelas_conferidas']} conferida(s) pelo digest\n";
    echo "  arquivos ........ {$r['arquivos']['restaurados']} restaurado(s), {$r['arquivos']['ignorados']} ignorado(s)\n";
    echo "  tempo ........... " . number_format((float) $r['segundos'], 2, ',', '.') . "s\n";

    if ($r['banco']['divergentes'] !== []) {
        echo "  resultado ....... DIVERGÊNCIAS:\n";
        foreach ($r['banco']['divergentes'] as $d) {
            echo "    ! {$d}\n";
        }
    } else {
        echo "  resultado ....... OK — todas as tabelas com linhas e digest iguais aos do manifesto.\n";
    }
    bkp_avisos($r['avisos']);
    return $r['ok'] ? 0 : 1;
}

// ---------------------------------------------------------------------
// Apoio
// ---------------------------------------------------------------------

/** @return array<string, string|bool> */
function bkp_args(array $argv): array
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

function bkp_avisos(array $avisos): void
{
    if ($avisos === []) {
        return;
    }
    echo "\nAvisos (" . count($avisos) . "):\n";
    foreach ($avisos as $a) {
        echo "  ~ " . wordwrap((string) $a, 92, "\n    ", false) . "\n";
    }
}

function bkp_linhas(array $manifesto): int
{
    $n = 0;
    foreach ($manifesto['banco']['tabelas'] ?? [] as $t) {
        $n += (int) ($t['linhas'] ?? 0);
    }
    return $n;
}

function bkp_bytes(int $b): string
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

function bkp_ajuda(): void
{
    $doc = (string) file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', $doc, $m) === 1) {
        echo trim((string) preg_replace('/^[ \t]*\* ?/m', '', $m[1])), "\n";
        return;
    }
    echo "php scripts/backup.php [--no-files] [--no-db] [--out=DIR] [--list] [--verify[=ID]] ...\n";
}
