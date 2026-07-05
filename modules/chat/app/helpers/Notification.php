<?php
/**
 * Adaptador de notificações — grava/lê a tabela GLOBAL `notifications`
 * (user_id, module, type, title, message, link, read_at, created_at)
 * com module = 'chat'. Links devem carregar m=chat.
 */
class Notification
{
    private const MODULE = 'chat';

    public static function create(int $userId, string $type, string $title, ?string $content = null, ?string $link = null): void
    {
        \Core\Notifications::add($userId, $title, $content, self::link($link), $type, self::MODULE);
    }

    public static function createForMany(array $userIds, string $type, string $title, ?string $content = null, ?string $link = null): void
    {
        foreach ($userIds as $uid) {
            self::create((int) $uid, $type, $title, $content, $link);
        }
    }

    public static function unreadCount(int $userId): int
    {
        return \Core\Notifications::unreadCount($userId, self::MODULE);
    }

    public static function recent(int $userId, int $limit = 20): array
    {
        $rows = \Core\Notifications::latest($userId, $limit, self::MODULE);
        // Compatibilidade com o formato legado (content / is_read)
        foreach ($rows as &$row) {
            $row['content'] = $row['message'] ?? null;
            $row['is_read'] = $row['read_at'] !== null ? 1 : 0;
        }
        unset($row);
        return $rows;
    }

    public static function markRead(int $id, int $userId): void
    {
        \Core\Notifications::markRead($userId, $id);
    }

    public static function markAllRead(int $userId): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND module = ? AND read_at IS NULL')
           ->execute([$userId, self::MODULE]);
    }

    /** Garante que links internos do módulo carreguem m=chat. */
    private static function link(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return $link;
        }
        if (str_starts_with($link, 'index.php?') && !str_contains($link, 'm=')) {
            $link = 'index.php?m=chat&' . substr($link, strlen('index.php?'));
        }
        return $link;
    }
}
