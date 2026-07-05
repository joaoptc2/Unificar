<?php
class User extends Model
{
    protected static string $table = 'users';
    protected static array  $fillable = [
        'name', 'email', 'password', 'avatar', 'role',
        'status', 'status_text', 'status_emoji',
        'title', 'department', 'phone', 'timezone', 'is_active',
    ];

    public static function findByEmail(string $email): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function online(): array
    {
        return self::all([
            'where' => 'status IN ("online","away","dnd") AND is_active = 1',
            'order' => 'name ASC',
        ]);
    }

    public static function active(): array
    {
        return self::all([
            'where' => 'is_active = 1',
            'order' => 'name ASC',
        ]);
    }

    public static function updateStatus(int $id, string $status): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE users SET status = ?, last_seen_at = NOW() WHERE id = ?')
           ->execute([$status, $id]);
    }

    public static function updateLastSeen(int $id): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public static function search(string $query): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, name, email, avatar, status, title, department
             FROM users WHERE is_active = 1 AND (name LIKE ? OR email LIKE ?) ORDER BY name ASC LIMIT 20'
        );
        $like = "%{$query}%";
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll();
    }

    public static function avatarUrl(?string $avatar): string
    {
        if ($avatar && file_exists(BASE_PATH . '/public/' . $avatar)) {
            return BASE_URL . '/public/' . $avatar;
        }
        return '';
    }

    public static function initials(string $name): string
    {
        $parts = explode(' ', trim($name));
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $last  = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)) : '';
        return $first . $last;
    }
}
