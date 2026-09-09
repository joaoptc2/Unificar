<?php
class Channel extends Model
{
    protected static string $table = 'chat_channels';
    protected static array  $fillable = [
        'name', 'slug', 'description', 'type', 'topic',
        'created_by', 'is_archived', 'is_general',
        'is_readonly', 'retention_days', 'slow_mode_seconds', 'max_pinned', 'allow_threads', 'category_id',
    ];

    public static function findBySlug(string $slug): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM chat_channels WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Canais do usuário (uma única consulta): dados do canal + papel do
     * membro + não lidas + última mensagem + categoria + favorito + (em DMs)
     * o interlocutor com presença. Evita o N+1 antigo (dmPartner por DM).
     */
    public static function userChannels(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT c.*, cm.role AS member_role, cm.notifications, cm.last_read_message_id,
                    (SELECT COUNT(*) FROM chat_messages m
                      WHERE m.channel_id = c.id AND m.deleted_at IS NULL AND m.parent_id IS NULL
                        AND m.id > COALESCE(cm.last_read_message_id, 0)
                        AND (m.user_id IS NULL OR m.user_id <> cm.user_id)) AS unread_count,
                    (SELECT MAX(m2.created_at) FROM chat_messages m2
                      WHERE m2.channel_id = c.id AND m2.deleted_at IS NULL) AS last_message_at,
                    cat.name AS category_name, cat.order_num AS category_order,
                    (f.id IS NOT NULL) AS is_favorite,
                    pu.id AS partner_id, pu.name AS partner_name, pu.avatar AS partner_avatar,
                    pu.job_title AS partner_title,
                    COALESCE(pp.status, "offline") AS partner_status
             FROM chat_channels c
             INNER JOIN chat_channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
             LEFT JOIN chat_channel_categories cat ON cat.id = c.category_id
             LEFT JOIN chat_channel_favorites f ON f.channel_id = c.id AND f.user_id = cm.user_id
             LEFT JOIN users pu ON c.type = "direct"
                    AND pu.id = (SELECT MIN(x.user_id) FROM chat_channel_members x
                                  WHERE x.channel_id = c.id AND x.user_id <> cm.user_id)
             LEFT JOIN chat_presence pp ON pp.user_id = pu.id
             WHERE c.is_archived = 0
             ORDER BY c.is_general DESC, cat.order_num IS NULL ASC, cat.order_num ASC,
                      last_message_at IS NULL ASC, last_message_at DESC, c.name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Não lidas por canal do usuário — uma única consulta (heartbeat). */
    public static function unreadCounts(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT cm.channel_id, COUNT(m.id) AS unread_count
             FROM chat_channel_members cm
             INNER JOIN chat_channels c ON c.id = cm.channel_id AND c.is_archived = 0
             LEFT JOIN chat_messages m ON m.channel_id = cm.channel_id
                    AND m.deleted_at IS NULL AND m.parent_id IS NULL
                    AND m.id > COALESCE(cm.last_read_message_id, 0)
                    AND (m.user_id IS NULL OR m.user_id <> cm.user_id)
             WHERE cm.user_id = ?
             GROUP BY cm.channel_id'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['channel_id']] = (int) $row['unread_count'];
        }
        return $out;
    }

    public static function publicChannels(): array
    {
        return self::all([
            'where' => 'type = "public" AND is_archived = 0',
            'order' => 'name ASC',
        ]);
    }

    /** Canais públicos com contagem de membros e se o usuário participa (uma consulta). */
    public static function publicChannelsFor(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT c.*, cat.name AS category_name,
                    (SELECT COUNT(*) FROM chat_channel_members x WHERE x.channel_id = c.id) AS member_count,
                    (cm.id IS NOT NULL) AS is_member
             FROM chat_channels c
             LEFT JOIN chat_channel_categories cat ON cat.id = c.category_id
             LEFT JOIN chat_channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
             WHERE c.type = "public" AND c.is_archived = 0
             ORDER BY c.is_general DESC, c.name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Garante que o usuário participe dos canais gerais (is_general). */
    public static function ensureGeneralMembership(int $userId): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role, joined_at)
             SELECT id, ?, "member", NOW() FROM chat_channels WHERE is_general = 1 AND is_archived = 0'
        )->execute([$userId]);
    }

    public static function directChannel(int $userId1, int $userId2): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT c.* FROM chat_channels c
             INNER JOIN chat_channel_members cm1 ON cm1.channel_id = c.id AND cm1.user_id = ?
             INNER JOIN chat_channel_members cm2 ON cm2.channel_id = c.id AND cm2.user_id = ?
             WHERE c.type = "direct"
             AND (SELECT COUNT(*) FROM chat_channel_members cm3 WHERE cm3.channel_id = c.id) = 2
             LIMIT 1'
        );
        $stmt->execute([$userId1, $userId2]);
        return $stmt->fetch() ?: null;
    }

    public static function members(int $channelId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.email, u.avatar,
                    COALESCE(p.status, "offline") AS status,
                    u.job_title AS title, u.sector AS department,
                    cm.role AS channel_role, cm.joined_at
             FROM users u
             INNER JOIN chat_channel_members cm ON cm.user_id = u.id
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE cm.channel_id = ? AND u.active = 1
             ORDER BY u.name ASC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    /** IDs dos membros ativos (menções @canal / @here). */
    public static function memberIds(int $channelId, bool $onlyOnline = false): array
    {
        $db  = Database::getInstance();
        $sql = 'SELECT cm.user_id FROM chat_channel_members cm
                INNER JOIN users u ON u.id = cm.user_id AND u.active = 1
                LEFT JOIN chat_presence p ON p.user_id = cm.user_id
                WHERE cm.channel_id = ?';
        if ($onlyOnline) {
            $sql .= ' AND p.status IN ("online", "away", "dnd")';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$channelId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    public static function memberCount(int $channelId): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = ?');
        $stmt->execute([$channelId]);
        return (int) $stmt->fetchColumn();
    }

    public static function isMember(int $channelId, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM chat_channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$channelId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function memberRole(int $channelId, int $userId): ?string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT role FROM chat_channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$channelId, $userId]);
        $row = $stmt->fetch();
        return $row ? (string) $row['role'] : null;
    }

    public static function addMember(int $channelId, int $userId, string $role = 'member'): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role, joined_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$channelId, $userId, $role]);
    }

    public static function removeMember(int $channelId, int $userId): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM chat_channel_members WHERE channel_id = ? AND user_id = ?')
           ->execute([$channelId, $userId]);
    }

    public static function updateLastRead(int $channelId, int $userId, int $messageId): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'UPDATE chat_channel_members SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?)
             WHERE channel_id = ? AND user_id = ?'
        )->execute([$messageId, $channelId, $userId]);
    }

    /** Favoritos de canal: alterna e devolve o novo estado. */
    public static function toggleFavorite(int $channelId, int $userId): bool
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM chat_channel_favorites WHERE channel_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$channelId, $userId]);
        if ($row = $stmt->fetch()) {
            $db->prepare('DELETE FROM chat_channel_favorites WHERE id = ?')->execute([$row['id']]);
            return false;
        }
        $db->prepare('INSERT IGNORE INTO chat_channel_favorites (user_id, channel_id, created_at) VALUES (?, ?, NOW())')
           ->execute([$userId, $channelId]);
        return true;
    }

    public static function isFavorite(int $channelId, int $userId): bool
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM chat_channel_favorites WHERE channel_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$channelId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function dmPartner(int $channelId, int $currentUserId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.email, u.avatar, u.job_title AS title, u.sector AS department,
                    COALESCE(p.status, "offline") AS status,
                    p.status_text, p.status_emoji, p.last_seen_at
             FROM users u
             INNER JOIN chat_channel_members cm ON cm.user_id = u.id
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE cm.channel_id = ? AND u.id != ?
             LIMIT 1'
        );
        $stmt->execute([$channelId, $currentUserId]);
        return $stmt->fetch() ?: null;
    }

    /** Mensagem de sistema no canal (entrou/saiu/arquivado...). */
    public static function systemMessage(int $channelId, string $content): int
    {
        return Message::insert([
            'channel_id' => $channelId,
            'user_id'    => null,
            'content'    => $content,
            'type'       => 'system',
        ]);
    }
}
