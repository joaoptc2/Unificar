<?php
/**
 * Adaptador de banco — usa a conexão PDO única da plataforma (Core\DB).
 * As tabelas do módulo têm prefixo chat_; `users`, `notifications` e
 * `audit_log` são globais (núcleo).
 */
class Database
{
    public static function getInstance(): PDO
    {
        return \Core\DB::pdo();
    }

    public static function close(): void
    {
        // conexão gerida pelo núcleo — no-op
    }
}
