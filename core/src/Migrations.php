<?php

declare(strict_types=1);

namespace Core;

/**
 * Migrações de banco de dados (sql/migrations/*.sql).
 *
 * - Cada arquivo é aplicado uma única vez e registrado em schema_migrations.
 * - Os scripts devem ser IDEMPOTENTES (CREATE TABLE IF NOT EXISTS, seeds com
 *   INSERT IGNORE / WHERE NOT EXISTS). Para ALTER TABLE ADD COLUMN/INDEX,
 *   o runner tolera os erros "já existe" do MySQL/MariaDB, de modo que um
 *   script pode ser reexecutado com segurança em bancos parcialmente
 *   atualizados (compatível com MySQL 5.7, que não aceita IF NOT EXISTS
 *   em colunas).
 * - Instalações novas: install.php cria as tabelas já atualizadas pelos
 *   arquivos de schema e marca todas as migrações como aplicadas.
 * - Instalações existentes: Administração → Atualizações de banco (ou
 *   `php scripts/migrate.php`).
 */
final class Migrations
{
    /** Códigos MySQL tratados como "já aplicado" (statement pulado). */
    private const TOLERATED = [
        1050, // table already exists
        1060, // duplicate column name
        1061, // duplicate key name
        1062, // duplicate entry (seed já existente)
        1068, // multiple primary key defined
        1091, // can't DROP; check that column/key exists
        1826, // duplicate foreign key constraint name
        1022, // can't write; duplicate key
    ];

    public static function dir(): string
    {
        return BASE_PATH . '/sql/migrations';
    }

    public static function ensureTable(): void
    {
        DB::execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(150) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes      TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return string[] nomes de arquivo, em ordem de aplicação */
    public static function files(): array
    {
        $files = array_map('basename', glob(self::dir() . '/*.sql') ?: []);
        natsort($files);
        return array_values($files);
    }

    /** @return array<string, string> filename => applied_at */
    public static function applied(): array
    {
        self::ensureTable();
        $rows = DB::query('SELECT filename, applied_at FROM schema_migrations');
        return array_column($rows, 'applied_at', 'filename');
    }

    /** @return string[] */
    public static function pending(): array
    {
        $applied = self::applied();
        return array_values(array_filter(self::files(), fn ($f) => !isset($applied[$f])));
    }

    /**
     * Há migrações pendentes? Resultado cacheado na sessão por 5 minutos
     * (usado para o aviso aos administradores no layout).
     */
    public static function hasPending(): bool
    {
        if (PHP_SAPI !== 'cli' && isset($_SESSION['_mig_pending_check'])) {
            [$ts, $val] = $_SESSION['_mig_pending_check'];
            if ((time() - (int) $ts) < 300) {
                return (bool) $val;
            }
        }
        try {
            $val = self::pending() !== [];
        } catch (\Throwable) {
            $val = false;
        }
        if (PHP_SAPI !== 'cli') {
            $_SESSION['_mig_pending_check'] = [time(), $val];
        }
        return $val;
    }

    public static function forgetCache(): void
    {
        unset($_SESSION['_mig_pending_check']);
    }

    /**
     * Aplica um arquivo. Retorna ['file','ok','ran','skipped'=>[],'errors'=>[]].
     * Em erro real, interrompe no statement com falha e NÃO marca como aplicada.
     */
    public static function apply(string $filename): array
    {
        $filename = basename($filename);
        $path     = self::dir() . '/' . $filename;
        $result   = ['file' => $filename, 'ok' => false, 'ran' => 0, 'skipped' => [], 'errors' => []];

        if (!is_file($path)) {
            $result['errors'][] = 'Arquivo não encontrado.';
            return $result;
        }
        $sql = (string) file_get_contents($path);
        $pdo = DB::pdo();

        foreach (self::split($sql) as $stmt) {
            try {
                $pdo->exec($stmt);
                $result['ran']++;
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                $msg  = (string) $e->getMessage();
                $tolerated = in_array($code, self::TOLERATED, true)
                    || ($code === 1005 && str_contains($msg, '121')); // FK duplicada (MySQL 5.7)
                $short = mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 90);
                if ($tolerated) {
                    $result['skipped'][] = $short . ' … (' . $code . ')';
                    continue;
                }
                $result['errors'][] = $short . ' … → ' . $msg;
                return $result;
            }
        }

        self::ensureTable();
        DB::execute(
            'INSERT INTO schema_migrations (filename, notes) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE applied_at = NOW(), notes = VALUES(notes)',
            [$filename, $result['ran'] . ' statements; ' . count($result['skipped']) . ' pulados']
        );
        self::forgetCache();
        $result['ok'] = true;
        return $result;
    }

    /** @return array<int, array> resultados por arquivo (para na primeira falha) */
    public static function applyAll(): array
    {
        $out = [];
        foreach (self::pending() as $file) {
            $r = self::apply($file);
            $out[] = $r;
            if (!$r['ok']) {
                break;
            }
        }
        return $out;
    }

    /** Marca todos os arquivos como aplicados (instalação nova). */
    public static function markAllApplied(): void
    {
        self::ensureTable();
        foreach (self::files() as $f) {
            DB::execute(
                'INSERT INTO schema_migrations (filename, notes) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE applied_at = applied_at',
                [$f, 'instalação']
            );
        }
        self::forgetCache();
    }

    /**
     * Divide um script SQL em statements, respeitando strings, identificadores
     * entre crases e comentários (-- , # e bloco).
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $stmts   = [];
        $buf     = '';
        $len     = strlen($sql);
        $quote   = null;
        $i       = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== '`') { // escape dentro de string
                    $buf .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }

            // comentários
            if ($ch === '-' && $next === '-') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol + 1;
                $buf .= "\n";
                continue;
            }
            if ($ch === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol + 1;
                $buf .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf .= $ch;
                $i++;
                continue;
            }

            if ($ch === ';') {
                $s = trim($buf);
                if ($s !== '') {
                    $stmts[] = $s;
                }
                $buf = '';
                $i++;
                continue;
            }

            $buf .= $ch;
            $i++;
        }
        $s = trim($buf);
        if ($s !== '') {
            $stmts[] = $s;
        }
        return $stmts;
    }
}
