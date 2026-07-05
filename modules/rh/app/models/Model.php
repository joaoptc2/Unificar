<?php
/**
 * Model — classe base com helpers para CRUD simples via PDO.
 *
 * Não é um ORM completo. Oferece:
 *   - find($id)                → linha por PK
 *   - all(['where' => ...])    → listagem paginada
 *   - count($where, $params)   → contador
 *   - insert($data)            → INSERT; retorna ID
 *   - update($id, $data)       → UPDATE por PK
 *   - delete($id)              → DELETE por PK
 *
 * As subclasses definem `$table` e `$primaryKey`. Campos permitidos são
 * controlados pela propriedade `$fillable` (prevenção mass-assignment).
 */
abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';
    protected static array  $fillable   = [];

    protected static function db(): PDO
    {
        return Database::getInstance();
    }

    public static function find(int $id): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1',
            static::$table, static::$primaryKey);
        $stmt = self::db()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Listagem com filtros, ordenação e paginação.
     *
     * @param array{
     *   where?: string,
     *   params?: array,
     *   order?: string,
     *   limit?: int,
     *   offset?: int,
     *   columns?: string,
     * } $options
     */
    public static function all(array $options = []): array
    {
        $columns = $options['columns'] ?? '*';
        $where   = !empty($options['where']) ? 'WHERE ' . $options['where'] : '';
        $order   = !empty($options['order']) ? 'ORDER BY ' . $options['order'] : '';
        $limit   = '';
        if (isset($options['limit'])) {
            $limit = sprintf('LIMIT %d OFFSET %d',
                max(0, (int)$options['limit']),
                max(0, (int)($options['offset'] ?? 0))
            );
        }
        $sql = sprintf('SELECT %s FROM `%s` %s %s %s',
            $columns, static::$table, $where, $order, $limit);
        $stmt = self::db()->prepare($sql);
        $stmt->execute($options['params'] ?? []);
        return $stmt->fetchAll();
    }

    public static function count(string $where = '', array $params = []): int
    {
        $whereClause = $where !== '' ? 'WHERE ' . $where : '';
        $sql = sprintf('SELECT COUNT(*) FROM `%s` %s', static::$table, $whereClause);
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function insert(array $data): int
    {
        $data = self::filter($data);
        if (empty($data)) {
            throw new InvalidArgumentException('Model::insert recebeu payload vazio.');
        }
        $cols = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)',
            static::$table, implode('`,`', $cols), $placeholders);
        $stmt = self::db()->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)self::db()->lastInsertId();
    }

    public static function update(int $id, array $data): int
    {
        $data = self::filter($data);
        if (empty($data)) return 0;
        $set = implode(',', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $sql = sprintf('UPDATE `%s` SET %s WHERE `%s` = ?',
            static::$table, $set, static::$primaryKey);
        $stmt = self::db()->prepare($sql);
        $stmt->execute([...array_values($data), $id]);
        return $stmt->rowCount();
    }

    public static function delete(int $id): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE `%s` = ?', static::$table, static::$primaryKey);
        $stmt = self::db()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /**
     * Filtra o payload pelos campos permitidos (whitelist $fillable).
     */
    protected static function filter(array $data): array
    {
        if (empty(static::$fillable)) return $data;
        return array_intersect_key($data, array_flip(static::$fillable));
    }
}
