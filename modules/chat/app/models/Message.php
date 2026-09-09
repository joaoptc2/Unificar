<?php
class Message extends Model
{
    protected static string $table = 'chat_messages';
    protected static array  $fillable = [
        'channel_id', 'user_id', 'parent_id', 'content',
        'type', 'is_edited', 'edited_at', 'is_pinned',
        'pinned_by', 'pinned_at', 'metadata',
    ];

    /** Colunas comuns nas listagens (autor + presença). */
    private const AUTHOR_SELECT =
        'm.*, u.name AS user_name, u.avatar AS user_avatar,
         COALESCE(p.status, "offline") AS user_status';

    /**
     * Mensagens raiz do canal (mais recentes), com anexos e reações
     * carregados em lote. Usa idx_chat_msg_channel_deleted
     * (channel_id, deleted_at, created_at).
     */
    public static function channelMessages(int $channelId, int $limit = 50, ?int $before = null): array
    {
        $db = Database::getInstance();
        $sql = 'SELECT ' . self::AUTHOR_SELECT . '
                FROM chat_messages m
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN chat_presence p ON p.user_id = u.id
                WHERE m.channel_id = ? AND m.deleted_at IS NULL AND m.parent_id IS NULL';
        $params = [$channelId];

        if ($before) {
            $sql .= ' AND m.id < ?';
            $params[] = $before;
        }

        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . max(1, min(200, $limit));

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $messages = array_reverse($stmt->fetchAll());

        self::hydrate($messages);
        return $messages;
    }

    public static function threadReplies(int $parentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ' . self::AUTHOR_SELECT . '
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE m.parent_id = ? AND m.deleted_at IS NULL
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute([$parentId]);
        $replies = $stmt->fetchAll();
        self::hydrate($replies);
        return $replies;
    }

    /** Mensagens raiz novas desde $afterId (polling). */
    public static function newMessages(int $channelId, int $afterId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ' . self::AUTHOR_SELECT . '
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE m.channel_id = ? AND m.deleted_at IS NULL AND m.parent_id IS NULL AND m.id > ?
             ORDER BY m.id ASC
             LIMIT 200'
        );
        $stmt->execute([$channelId, $afterId]);
        $messages = $stmt->fetchAll();
        self::hydrate($messages);
        return $messages;
    }

    /** Uma mensagem com autor, anexos e reações (resposta do sendMessage). */
    public static function findFull(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ' . self::AUTHOR_SELECT . '
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE m.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $rows = [$row];
        self::hydrate($rows);
        return $rows[0];
    }

    /**
     * Mensagens alteradas recentemente (edição, exclusão, fixação, contagem
     * de respostas) entre as já exibidas — sincronização do polling.
     */
    public static function changedSince(int $channelId, int $afterId, int $seconds = 20): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.id, m.content, m.is_edited, m.reply_count, m.is_pinned,
                    (m.deleted_at IS NOT NULL) AS is_deleted
             FROM chat_messages m
             WHERE m.channel_id = ? AND m.parent_id IS NULL AND m.id <= ?
               AND m.updated_at >= DATE_SUB(NOW(), INTERVAL ' . max(5, $seconds) . ' SECOND)
             ORDER BY m.id ASC LIMIT 200'
        );
        $stmt->execute([$channelId, $afterId]);
        return $stmt->fetchAll();
    }

    /** Anexos + reações em lote para uma lista de mensagens. */
    public static function hydrate(array &$messages): void
    {
        if (!$messages) {
            return;
        }
        $ids = array_map(fn ($m) => (int) $m['id'], $messages);
        $attachments = self::attachmentsFor($ids);
        $reactions   = self::reactionsFor($ids);
        foreach ($messages as &$m) {
            $id = (int) $m['id'];
            $m['attachments'] = $attachments[$id] ?? [];
            $m['reactions']   = $reactions[$id] ?? [];
            $m['reply_count'] = (int) ($m['reply_count'] ?? 0);
            $m['is_pinned']   = (int) ($m['is_pinned'] ?? 0);
            $m['is_edited']   = (int) ($m['is_edited'] ?? 0);
        }
        unset($m);
    }

    /** @return array<int, array[]> message_id => anexos (com url/is_image) */
    public static function attachmentsFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $db   = Database::getInstance();
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM chat_message_attachments WHERE message_id IN ($in) ORDER BY id ASC");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $a) {
            $a['url']       = Upload::url($a['file_path']);
            $a['is_image']  = Upload::isImage((string) $a['file_type']);
            $a['size_text'] = Upload::formatSize((int) $a['file_size']);
            $out[(int) $a['message_id']][] = $a;
        }
        return $out;
    }

    /** @return array<int, array[]> message_id => reações agrupadas por emoji */
    public static function reactionsFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $db   = Database::getInstance();
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT mr.message_id, mr.emoji, COUNT(*) AS count,
                    GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS users,
                    GROUP_CONCAT(mr.user_id) AS user_ids
             FROM chat_message_reactions mr
             INNER JOIN users u ON u.id = mr.user_id
             WHERE mr.message_id IN ($in)
             GROUP BY mr.message_id, mr.emoji
             ORDER BY mr.message_id, MIN(mr.id)"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $mid = (int) $r['message_id'];
            unset($r['message_id']);
            $r['count'] = (int) $r['count'];
            $out[$mid][] = $r;
        }
        return $out;
    }

    public static function reactions(int $messageId): array
    {
        return self::reactionsFor([$messageId])[$messageId] ?? [];
    }

    public static function toggleReaction(int $messageId, int $userId, string $emoji): string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id FROM chat_message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'
        );
        $stmt->execute([$messageId, $userId, $emoji]);

        if ($row = $stmt->fetch()) {
            $db->prepare('DELETE FROM chat_message_reactions WHERE id = ?')->execute([$row['id']]);
            $action = 'removed';
        } else {
            $db->prepare(
                'INSERT INTO chat_message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW())'
            )->execute([$messageId, $userId, $emoji]);
            $action = 'added';
        }

        // Atualiza o contador (e o updated_at, que sincroniza o polling)
        $db->prepare(
            'UPDATE chat_messages SET reaction_count = (SELECT COUNT(*) FROM chat_message_reactions WHERE message_id = ?),
                                     updated_at = NOW()
             WHERE id = ?'
        )->execute([$messageId, $messageId]);

        return $action;
    }

    public static function attachments(int $messageId): array
    {
        return self::attachmentsFor([$messageId])[$messageId] ?? [];
    }

    public static function addAttachment(int $messageId, int $userId, array $fileData): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO chat_message_attachments (message_id, user_id, original_name, file_path, file_type, file_size, created_at)
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
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN users pu ON pu.id = m.pinned_by
             WHERE m.channel_id = ? AND m.is_pinned = 1 AND m.deleted_at IS NULL
             ORDER BY m.pinned_at DESC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    public static function pinnedCount(int $channelId): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT COUNT(*) FROM chat_messages WHERE channel_id = ? AND is_pinned = 1 AND deleted_at IS NULL');
        $stmt->execute([$channelId]);
        return (int) $stmt->fetchColumn();
    }

    /** Busca textual restrita aos canais em que o usuário participa. */
    public static function search(string $query, int $userId, int $limit = 50, int $channelId = 0): array
    {
        $db  = Database::getInstance();
        $sql = 'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar,
                       c.name AS channel_name, c.slug AS channel_slug, c.type AS channel_type
                FROM chat_messages m
                INNER JOIN chat_channels c ON c.id = m.channel_id
                INNER JOIN chat_channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
                LEFT JOIN users u ON u.id = m.user_id
                WHERE m.deleted_at IS NULL AND m.type <> "system" AND m.content LIKE ?';
        $params = [$userId, '%' . self::escapeLike($query) . '%'];
        if ($channelId > 0) {
            $sql .= ' AND m.channel_id = ?';
            $params[] = $channelId;
        }
        $sql .= ' ORDER BY m.created_at DESC LIMIT ' . max(1, min(200, $limit));
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    public static function lastIdOf(int $channelId): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT MAX(id) FROM chat_messages WHERE channel_id = ? AND deleted_at IS NULL');
        $stmt->execute([$channelId]);
        return (int) $stmt->fetchColumn();
    }

    /** Segundos desde a última mensagem do usuário no canal (slow mode). */
    public static function secondsSinceLastOf(int $channelId, int $userId): ?int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM chat_messages
             WHERE channel_id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$channelId, $userId]);
        $v = $stmt->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    public static function softDelete(int $id): void
    {
        $db  = Database::getInstance();
        $msg = self::find($id);
        $db->prepare('UPDATE chat_messages SET deleted_at = NOW(), is_pinned = 0 WHERE id = ?')->execute([$id]);
        if ($msg && !empty($msg['parent_id'])) {
            $db->prepare('UPDATE chat_messages SET reply_count = GREATEST(reply_count - 1, 0) WHERE id = ?')
               ->execute([(int) $msg['parent_id']]);
        }
    }

    public static function incrementReplyCount(int $parentId): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE chat_messages SET reply_count = reply_count + 1 WHERE id = ?')
           ->execute([$parentId]);
    }
}
