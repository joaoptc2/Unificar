<?php
/**
 * Acesso a banco do módulo DOCUMENTOS — adaptador do núcleo.
 *
 * A conexão é a PDO única da plataforma (Core\DB::pdo()). Os wrappers
 * procedurais legados (db_query, db_query_one, db_execute, db_last_id,
 * db_transaction) são mantidos para não reescrever models/controllers.
 */

function db_connect() {
    return Core\DB::pdo();
}

function db_query($sql, $params = []) {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_query_one($sql, $params = []) {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_execute($sql, $params = []) {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_last_id() {
    return db_connect()->lastInsertId();
}

/**
 * Verifica se uma coluna existe em uma tabela (tolerância a schema antigo).
 * Resultado cacheado estaticamente dentro da requisição.
 */
function db_has_column($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $row = db_query_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return $cache[$key] = !empty($row);
    } catch (Exception $ex) {
        return $cache[$key] = false;
    }
}

/**
 * Verifica se uma tabela existe.
 */
function db_has_table($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $row = db_query_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
        return $cache[$table] = !empty($row);
    } catch (Exception $ex) {
        return $cache[$table] = false;
    }
}

/**
 * Executa bloco em transação. Faz rollback em caso de exceção.
 */
function db_transaction(callable $fn) {
    return Core\DB::transaction($fn);
}
