<?php
/**
 * Usuários — tabela GLOBAL `users` do núcleo (sem prefixo).
 * Presença (status/status_text/status_emoji/timezone/last_seen_at) vive na
 * tabela do módulo `chat_presence` (LEFT JOIN). Aliases legados:
 * title → users.job_title, department → users.sector, is_active → active.
 */
class User extends Model
{
    protected static string $table = 'users';
    protected static array  $fillable = [
        'name', 'email', 'avatar', 'phone', 'job_title', 'sector',
    ];

    /** Colunas de presença + aliases legados, para SELECTs com JOIN. */
    private const PRESENCE_SELECT =
        'u.id, u.name, u.email, u.avatar, u.phone, u.active,
         u.job_title AS title, u.sector AS department,
         COALESCE(p.status, "offline") AS status,
         p.status_text, p.status_emoji,
         COALESCE(p.timezone, "America/Sao_Paulo") AS timezone,
         p.last_seen_at';

    public static function findByEmail(string $email): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /** Usuário + linha de presença do chat (para exibição de status). */
    public static function findWithPresence(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ' . self::PRESENCE_SELECT . '
             FROM users u
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function online(): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ' . self::PRESENCE_SELECT . '
             FROM users u
             INNER JOIN chat_presence p ON p.user_id = u.id
             WHERE p.status IN ("online","away","dnd") AND u.active = 1
             ORDER BY u.name ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function active(): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ' . self::PRESENCE_SELECT . '
             FROM users u
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE u.active = 1
             ORDER BY u.name ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Presença: grava em chat_presence (upsert). */
    public static function updateStatus(int $id, string $status): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO chat_presence (user_id, status, last_seen_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), last_seen_at = NOW()'
        )->execute([$id, $status]);
    }

    public static function updateLastSeen(int $id): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO chat_presence (user_id, last_seen_at)
             VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
        )->execute([$id]);
    }

    /** Texto/emoji de status personalizados (perfil do chat). */
    public static function updateStatusText(int $id, string $statusText, string $statusEmoji): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO chat_presence (user_id, status_text, status_emoji, last_seen_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status_text = VALUES(status_text),
                                     status_emoji = VALUES(status_emoji)'
        )->execute([$id, $statusText, $statusEmoji]);
    }

    public static function search(string $query): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.email, u.avatar,
                    COALESCE(p.status, "offline") AS status,
                    u.job_title AS title, u.sector AS department
             FROM users u
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE u.active = 1 AND (u.name LIKE ? OR u.email LIKE ?)
             ORDER BY u.name ASC LIMIT 20'
        );
        $like = "%{$query}%";
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll();
    }

    public static function avatarUrl(?string $avatar): string
    {
        if ($avatar && file_exists(BASE_PATH . '/' . ltrim($avatar, '/'))) {
            return BASE_URL . '/' . ltrim($avatar, '/');
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
