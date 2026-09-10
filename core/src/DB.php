<?php

declare(strict_types=1);

namespace Core;

use PDO;

/**
 * Conexão PDO única compartilhada por núcleo e módulos.
 * Todos os módulos usam o mesmo banco; as tabelas de cada módulo
 * são prefixadas (doc_, chat_, rh_, man_).
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host    = (string) Config::get('db.host', 'localhost');
            $port    = (int) Config::get('db.port', 3306);
            $name    = (string) Config::get('db.name', '');
            $charset = (string) Config::get('db.charset', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            self::$pdo = new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // O banco passa a usar o mesmo fuso do PHP: assim NOW()/CURDATE()
            // (usados pelos módulos) e date() marcam a mesma hora, sem
            // depender do fuso do servidor de banco.
            self::applyTimezone(self::$pdo);
        }
        return self::$pdo;
    }

    /**
     * Fuso da sessão do banco (config db.timezone):
     *   'app'    → mesmo fuso do PHP, para que NOW() e date() coincidam;
     *   'server' → não mexe (fuso do servidor de banco);
     *   '-03:00' → deslocamento fixo.
     */
    private static function applyTimezone(PDO $pdo): void
    {
        $mode = (string) Config::get('db.timezone', 'app');
        if ($mode === 'server' || $mode === '') {
            return;
        }
        try {
            $offset = $mode === 'app'
                ? (new \DateTime('now', new \DateTimeZone(date_default_timezone_get())))->format('P')
                : $mode;
            if (!preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
                return;
            }
            $pdo->exec("SET time_zone = '{$offset}'");
        } catch (\Throwable $e) {
            // Banco sem permissão para trocar o fuso da sessão: segue com o
            // fuso do servidor (comportamento anterior).
            error_log('DB: não foi possível alinhar o fuso da sessão: ' . $e->getMessage());
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
