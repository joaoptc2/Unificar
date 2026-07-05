<?php
abstract class Model
{
    protected static string $table = '';
    protected static array  $fillable = [];

    public static function find(int $id): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM `' . static::$table . '` WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function all(array $options = []): array
    {
        $db  = Database::getInstance();
        $sql = 'SELECT * FROM `' . static::$table . '`';

        $params = $options['params'] ?? [];

        if (!empty($options['where'])) {
            $sql .= ' WHERE ' . $options['where'];
        }
        if (!empty($options['order'])) {
            $sql .= ' ORDER BY ' . $options['order'];
        }
        if (isset($options['limit'])) {
            $sql .= ' LIMIT ' . (int) $options['limit'];
            if (isset($options['offset'])) {
                $sql .= ' OFFSET ' . (int) $options['offset'];
            }
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(string $where = '', array $params = []): int
    {
        $db  = Database::getInstance();
        $sql = 'SELECT COUNT(*) FROM `' . static::$table . '`';
        if ($where) $sql .= ' WHERE ' . $where;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function insert(array $data): int
    {
        $data = self::filterFillable($data);
        $db   = Database::getInstance();
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $db->prepare("INSERT INTO `" . static::$table . "` ($cols) VALUES ($phs)");
        $stmt->execute(array_values($data));
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $data = self::filterFillable($data);
        if (empty($data)) return false;
        $db   = Database::getInstance();
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $stmt = $db->prepare("UPDATE `" . static::$table . "` SET $sets WHERE id = ?");
        $vals = array_values($data);
        $vals[] = $id;
        return $stmt->execute($vals);
    }

    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        return $db->prepare("DELETE FROM `" . static::$table . "` WHERE id = ?")->execute([$id]);
    }

    protected static function filterFillable(array $data): array
    {
        if (empty(static::$fillable)) return $data;
        return array_intersect_key($data, array_flip(static::$fillable));
    }
}
