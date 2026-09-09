<?php
/**
 * Aplica as migrações de banco pendentes (sql/migrations/*.sql).
 *
 * Uso: php scripts/migrate.php [--status]
 *   --status  apenas lista o estado de cada arquivo (não aplica nada)
 *
 * O mesmo pode ser feito pela interface: Administração → Atualizações de banco.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Core\Migrations;

$statusOnly = in_array('--status', $argv ?? [], true);
$applied    = Migrations::applied();

echo "Migrações em " . Migrations::dir() . "\n";
foreach (Migrations::files() as $f) {
    echo sprintf("  [%s] %s%s\n", isset($applied[$f]) ? 'x' : ' ', $f, isset($applied[$f]) ? '  (' . $applied[$f] . ')' : '');
}
if ($statusOnly) {
    exit(0);
}

$pending = Migrations::pending();
if (!$pending) {
    echo "Nada pendente.\n";
    exit(0);
}

$failed = false;
foreach (Migrations::applyAll() as $r) {
    echo "\n== {$r['file']}: " . ($r['ok'] ? 'OK' : 'FALHOU') . " ({$r['ran']} statements";
    echo $r['skipped'] ? ', ' . count($r['skipped']) . ' pulados por já existirem' : '';
    echo ")\n";
    foreach ($r['skipped'] as $s) {
        echo "   ~ {$s}\n";
    }
    foreach ($r['errors'] as $e) {
        echo "   ! {$e}\n";
        $failed = true;
    }
}
exit($failed ? 1 : 0);
