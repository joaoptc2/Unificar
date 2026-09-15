<?php
/**
 * Compara DOIS bancos tabela a tabela: contagem de linhas + digest do
 * conteúdo.
 *
 * Serve para provar, por fora do motor de backup, que uma restauração
 * devolveu o mesmo dado: o digest é calculado lendo as duas bases com o
 * mesmo código (Core\BackupDump::digestTable), sem olhar o manifesto.
 *
 * Uso:
 *   php scripts/backup_verify.php --b=portal_restore_test
 *       compara o banco do config (db.name) com o informado
 *
 *   php scripts/backup_verify.php --a=portal --b=portal_restore_test
 *   php scripts/backup_verify.php --a=... --b=... --tables=users,settings
 *   php scripts/backup_verify.php --a=... --b=... --only-diff   (só o que difere)
 *   php scripts/backup_verify.php --a=... --b=... --json        (saída em JSON)
 *
 * Código de saída: 0 quando os dois bancos estão iguais, 1 quando não.
 *
 * O digest é o mesmo do backup: sha256 por linha (com o comprimento de cada
 * valor e marcador próprio para NULL), somado em 8 palavras de 32 bits com
 * estouro — soma, não XOR, porque com XOR duas linhas iguais se cancelariam.
 * Ele é ORDEM-INDEPENDENTE de propósito: as duas bases podem ter os mesmos
 * dados em ordem física diferente.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Core\BackupDump;
use Core\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script é só para a linha de comando.\n");
}

$args = bv_args($argv ?? []);
if (isset($args['help']) || isset($args['h'])) {
    bv_ajuda();
    exit(0);
}

$bancoA = (string) ($args['a'] ?? Config::get('db.name', ''));
$bancoB = (string) ($args['b'] ?? '');

if ($bancoA === '' || $bancoB === '') {
    bv_ajuda();
    fwrite(STDERR, "\nInforme ao menos --b=<banco> (o --a padrão é o db.name do config).\n");
    exit(1);
}
if ($bancoA === $bancoB) {
    fwrite(STDERR, "Os dois bancos são o mesmo ({$bancoA}): não há o que comparar.\n");
    exit(1);
}

$somenteJson = isset($args['json']);
$soDiff      = isset($args['only-diff']);
$filtro      = isset($args['tables']) && is_string($args['tables'])
    ? array_filter(array_map('trim', explode(',', $args['tables'])))
    : [];

try {
    $pdoA = bv_conecta($bancoA);
    $pdoB = bv_conecta($bancoB);

    $tabA = bv_tabelas($pdoA, $bancoA);
    $tabB = bv_tabelas($pdoB, $bancoB);

    $comuns    = array_values(array_intersect($tabA, $tabB));
    $soEmA     = array_values(array_diff($tabA, $tabB));
    $soEmB     = array_values(array_diff($tabB, $tabA));

    if ($filtro !== []) {
        $comuns = array_values(array_intersect($comuns, $filtro));
        $soEmA  = array_values(array_intersect($soEmA, $filtro));
        $soEmB  = array_values(array_intersect($soEmB, $filtro));
    }

    $linhas      = [];
    $iguais      = 0;
    $diferentes  = 0;
    $indicativas = 0;
    $totalA      = 0;
    $totalB      = 0;
    $t0          = microtime(true);

    foreach ($comuns as $t) {
        $a = BackupDump::digestTable($pdoA, $t, $bancoA);
        $b = BackupDump::digestTable($pdoB, $t, $bancoB);

        $ok = $a['linhas'] === $b['linhas'] && hash_equals($a['digest'], $b['digest']);
        $ok ? $iguais++ : $diferentes++;
        $totalA += $a['linhas'];
        $totalB += $b['linhas'];
        // Tabela sem PRIMARY KEY é lida por LIMIT/OFFSET: bateu, mas o digest
        // dela vale como indício, não como prova.
        $semPk = $a['chave'] === 'limit-offset' || $b['chave'] === 'limit-offset';
        if ($semPk) {
            $indicativas++;
        }

        $linhas[] = [
            'tabela'     => $t,
            'linhas_a'   => $a['linhas'],
            'linhas_b'   => $b['linhas'],
            'digest_a'   => $a['digest'],
            'digest_b'   => $b['digest'],
            'chave'      => $a['chave'],
            'sem_pk'     => $semPk,
            'ok'         => $ok,
        ];
    }

    $resultado = [
        'a'            => ['banco' => $bancoA, 'tabelas' => count($tabA), 'linhas' => $totalA],
        'b'            => ['banco' => $bancoB, 'tabelas' => count($tabB), 'linhas' => $totalB],
        'comparadas'   => count($comuns),
        'iguais'       => $iguais,
        'diferentes'   => $diferentes,
        'indicativas'  => $indicativas,
        'so_em_a'      => $soEmA,
        'so_em_b'      => $soEmB,
        'tabelas'      => $linhas,
        'segundos'     => round(microtime(true) - $t0, 3),
    ];
    $ok = $diferentes === 0 && $soEmA === [] && $soEmB === [];

    if ($somenteJson) {
        echo json_encode($resultado + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        exit($ok ? 0 : 1);
    }

    echo "Comparando '{$bancoA}' (A) × '{$bancoB}' (B)\n\n";
    printf("  %-34s %9s %9s  %-8s %s\n", 'TABELA', 'LINHAS A', 'LINHAS B', 'DIGEST', 'RESULTADO');
    foreach ($linhas as $l) {
        if ($soDiff && $l['ok']) {
            continue;
        }
        printf(
            "  %-34s %9d %9d  %-8s %s%s\n",
            bv_corta($l['tabela'], 34),
            $l['linhas_a'],
            $l['linhas_b'],
            substr($l['digest_a'], 0, 8),
            $l['ok'] ? 'igual' : 'DIFERENTE (B: ' . substr($l['digest_b'], 0, 8) . ')',
            $l['sem_pk'] ? '  [sem PK: indicativo]' : ''
        );
    }

    echo "\n";
    echo "  A ............... " . count($tabA) . " tabela(s), " . number_format($totalA, 0, ',', '.') . " linha(s)\n";
    echo "  B ............... " . count($tabB) . " tabela(s), " . number_format($totalB, 0, ',', '.') . " linha(s)\n";
    echo "  comparadas ...... " . count($comuns) . " ({$iguais} iguais, {$diferentes} diferentes)\n";
    if ($indicativas > 0) {
        echo "  sem PRIMARY KEY . {$indicativas} tabela(s) — digest indicativo\n";
    }
    if ($soEmA !== []) {
        echo "  só em A ......... " . count($soEmA) . ": " . implode(', ', array_slice($soEmA, 0, 12)) . "\n";
    }
    if ($soEmB !== []) {
        echo "  só em B ......... " . count($soEmB) . ": " . implode(', ', array_slice($soEmB, 0, 12)) . "\n";
    }
    echo "  tempo ........... " . number_format($resultado['segundos'], 2, ',', '.') . "s\n";
    echo "\n" . ($ok
        ? "RESULTADO: os dois bancos têm as MESMAS tabelas, com as mesmas linhas e o mesmo digest.\n"
        : "RESULTADO: OS BANCOS DIFEREM (veja acima).\n");

    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nERRO: " . $e->getMessage() . "\n");
    exit(2);
}

// ---------------------------------------------------------------------

/**
 * Conexão própria já apontada para o banco pedido.
 *
 * Nunca Core\DB::pdo(): o singleton é da aplicação e não pode receber USE.
 */
function bv_conecta(string $banco): PDO
{
    if (preg_match('/^[A-Za-z0-9_$]{1,64}$/', $banco) !== 1) {
        throw new RuntimeException('Nome de banco inválido: ' . $banco);
    }
    $pdo = BackupDump::connect();
    $pdo->exec('USE `' . str_replace('`', '``', $banco) . '`');
    return $pdo;
}

/** @return string[] */
function bv_tabelas(PDO $pdo, string $banco): array
{
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    $st->execute([$banco]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** @return array<string, string|bool> */
function bv_args(array $argv): array
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

function bv_corta(string $s, int $n): string
{
    return mb_strlen($s) <= $n ? $s : mb_substr($s, 0, $n - 1) . '…';
}

function bv_ajuda(): void
{
    $doc = (string) file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', $doc, $m) === 1) {
        echo trim((string) preg_replace('/^[ \t]*\* ?/m', '', $m[1])), "\n";
    }
}
