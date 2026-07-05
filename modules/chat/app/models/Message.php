<?php
class Message extends Model
{
    protected static string $table = 'messages';
    protected static array  $fillable = [
        'channel_id', 'user_id', 'parent_id', 'content',
        'type', 'is_edited', 'edited_at', 'is_pinned',
        'pinned_by', 'pinned_at', 'metadata',
    ];

    public static function channelMessages(int $channelId, int $limit = 50, ?int $before = null): array
    {
        $db = Database::getInstance();
        $sql = 'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar, u.status AS user_status
                FROM messages m
                LEFT JOIN users u ON u.id = m.user_id
                WHERE m.channel_id = ? AND m.parent_id IS NULL AND m.deleted_at IS NULL';
        $params = [$channelId];

        if ($before) {
            $sql .= ' AND m.id < ?';
            $params[] = $before;
        }

        $sql .= ' ORDER BY m.created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        return array_reverse($messages);
    }

    public static function threadReplies(int $parentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar
             FROM messages m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.parent_id = ? AND m.deleted_at IS NULL
             ORDER BY m.created_at ASC'
        );
        $stmt->execute([$parentId]);
        return $stmt->fetchAll();
    }

    public static function newMessages(int $channelId, int $afterId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar, u.status AS user_status
             FROM messages m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.channel_id = ? AND m.id > ? AND m.parent_id IS NULL AND m.deleted_at IS NULL
             ORDER BY m.created_at ASC'
        );
        $stmt->execute([$channelId, $afterId]);
        return $stmt->fetchAll();
    }

    public static function reactions(int $messageId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT mr.emoji, GROUP_CONCAT(u.name SEPARATOR ", ") AS users,
                    COUNT(*) AS count, GROUP_CONCAT(mr.user_id) AS user_ids
             FROM message_reactions mr
             INNER JOIN users u ON u.id = mr.user_id
             WHERE mr.message_id = ?
             GROUP BY mr.emoji'
        );
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }

    public static function toggleReaction(int $messageId, int $userId, string $emoji): string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'
        );
        $stmt->execute([$messageId, $userId, $emoji]);

        if ($row = $stmt->fetch()) {
            $db->prepare('DELETE FROM message_reactions WHERE id = ?')->execute([$row['id']]);
            $action = 'removed';
        } else {
            $db->prepare(
                'INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW())'
            )->execute([$messageId, $userId, $emoji]);
            $action = 'added';
        }

        $count = $db->prepare('SELECT COUNT(*) FROM message_reactions WHERE message_id = ?');
        $count->execute([$messageId]);
        $db->prepare('UPDATE messages SET reaction_count = ? WHERE id = ?')
           ->execute([$count->fetchColumn(), $messageId]);

        return $action;
    }

    public static function attachments(int $messageId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM message_attachments WHERE message_id = ?');
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }

    public static function addAttachment(int $messageId, int $userId, array $fileData): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO message_attachments (message_id, user_id, original_name, file_path, file_type, file_size, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $messageId, $userId,
            $fileData['original_name'], $fileData['path'],
            $fileData['file_type'], $fileData['file_size'],
        ]);
        return (int) $db->lastInsertId();
    }

    public static function pinnedMessages(int $channelId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar,
                    pu.name AS pinned_by_name
             FROM messages m
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN users pu ON pu.id = m.pinned_by
             WHERE m.channel_id = ? AND m.is_pinned = 1 AND m.deleted_at IS NULL
             ORDER BY m.pinned_at DESC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    public static function search(string $query, int $userId, int $limit = 50): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar, c.name AS channel_name, c.slug AS channel_slug
             FROM messages m
             LEFT JOIN users u ON u.id = m.user_id
             INNER JOIN channels c ON c.id = m.channel_id
             INNER JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
             WHERE m.content LIKE ? AND m.deleted_at IS NULL
             ORDER BY m.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$userId, "%{$query}%", $limit]);
        return $stmt->fetchAll();
    }

    public static function softDelete(int $id): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE messages SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public static function incrementReplyCount(int $parentId): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE messages SET reply_count = reply_count + 1 WHERE id = ?')
           ->execute([$parentId]);
    }
}
