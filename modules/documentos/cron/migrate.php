<?php
/**
 * Aplicador de migrations.
 *
 * Uso:
 *   CLI:  php cron/migrate.php
 *   Web:  https://seudominio.com.br/cron/migrate.php?key=SUA_CHAVE
 *
 * O .htaccess bloqueia acesso direto à pasta cron/, então para rodar via web
 * temporariamente mova este arquivo para a raiz ou acesse via cron CLI.
 *
 * Aplica em ordem qualquer arquivo .sql em database/migrations/ que ainda
 * não conste na tabela `schema_migrations`. Idempotente: pode rodar várias vezes.
 */

// Bloqueio via web básico: exige ?key= que bate com APP_KEY do .env
if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/includes/config.php';
    $provided = $_GET['key'] ?? '';
    $expected = (string) env('MIGRATE_KEY', '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo 'Acesso negado. Defina MIGRATE_KEY no .env e passe ?key=... na URL.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once dirname(__DIR__) . '/includes/config.php';
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db_connect();

// Cria tabela de controle
$pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration`  VARCHAR(200) NOT NULL,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$dir = dirname(__DIR__) . '/database/migrations';
if (!is_dir($dir)) {
    echo "Pasta $dir não encontrada.\n";
    exit(1);
}

$files = glob($dir . '/*.sql');
sort($files);

$applied = 0;
$skipped = 0;

foreach ($files as $file) {
    $name = basename($file);

    $row = db_query_one("SELECT id FROM schema_migrations WHERE migration = ?", [$name]);
    if ($row) {
        echo "  [skip] $name (já aplicada)\n";
        $skipped++;
        continue;
    }

    echo "  [apply] $name ...";
    $sql = file_get_contents($file);

    // Quebra em statements (separador ;) — simples, não suporta DELIMITER
    $stmts = array_filter(array_map('trim', explode(';', $sql)), function($s) {
        return $s !== '' && !preg_match('/^\s*--/', $s);
    });

    try {
        $pdo->beginTransaction();
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (PDOException $ex) {
                // Ignora "já existe" para statements idempotentes
                $m = $ex->getMessage();
                if (stripos($m, 'duplicate') !== false
                    || stripos($m, 'already exists') !== false
                    || stripos($m, 'check that column/key exists') !== false) {
                    continue;
                }
                throw $ex;
            }
        }
        $pdo->commit();
        db_execute("INSERT INTO schema_migrations (migration) VALUES (?)", [$name]);
        echo " OK\n";
        $applied++;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo " FALHOU: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

echo "\nAplicadas: $applied | Já existentes: $skipped\n";
echo "Concluído.\n";
