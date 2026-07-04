<?php

declare(strict_types=1);

namespace Core;

/**
 * Notificações unificadas. Todos os módulos gravam e leem daqui
 * (tabela notifications, coluna module identifica a origem).
 */
final class Notifications
{
    public static function add(int $userId, string $title, ?string $message = null, ?string $link = null, ?string $type = null, ?string $module = null): void
    {
        DB::execute(
            'INSERT INTO notifications (user_id, module, type, title, message, link) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $module ?? (defined('MODULE_SLUG') ? MODULE_SLUG : null), $type, $title, $message, $link]
        );
    }

    public static function unreadCount(int $userId, ?string $module = null): int
    {
        $sql    = 'SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL';
        $params = [$userId];
        if ($module !== null) {
            $sql .= ' AND module = ?';
            $params[] = $module;
        }
        $row = DB::queryOne($sql, $params);
        return (int) ($row['n'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public static function latest(int $userId, int $limit = 15, ?string $module = null): array
    {
        $sql    = 'SELECT * FROM notifications WHERE user_id = ?';
        $params = [$userId];
        if ($module !== null) {
            $sql .= ' AND module = ?';
            $params[] = $module;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);
        return DB::query($sql, $params);
    }

    public static function markRead(int $userId, ?int $id = null): void
    {
        if ($id === null) {
            DB::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
        } else {
            DB::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND id = ?', [$userId, $id]);
        }
    }
}
