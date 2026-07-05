<?php
class Meeting extends Model
{
    protected static string $table = 'chat_meetings';
    protected static array  $fillable = [
        'channel_id', 'title', 'description', 'scheduled_at',
        'duration_minutes', 'location', 'meeting_link', 'type',
        'status', 'created_by',
    ];

    public static function withParticipants(int $id): ?array
    {
        $meeting = self::find($id);
        if (!$meeting) return null;

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.email, u.avatar, mp.status, mp.responded_at
             FROM users u
             INNER JOIN chat_meeting_participants mp ON mp.user_id = u.id
             WHERE mp.meeting_id = ?
             ORDER BY u.name ASC'
        );
        $stmt->execute([$id]);
        $meeting['participants'] = $stmt->fetchAll();

        if ($meeting['created_by']) {
            $creator = User::find($meeting['created_by']);
            $meeting['creator_name'] = $creator['name'] ?? 'Desconhecido';
        }

        return $meeting;
    }

    public static function upcoming(int $userId, int $limit = 10): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, u.name AS creator_name,
                    mp.status AS my_status
             FROM chat_meetings m
             LEFT JOIN users u ON u.id = m.created_by
             INNER JOIN chat_meeting_participants mp ON mp.meeting_id = m.id AND mp.user_id = ?
             WHERE m.scheduled_at >= NOW() AND m.status IN ("scheduled","in_progress")
             ORDER BY m.scheduled_at ASC
             LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    public static function byChannel(int $channelId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, u.name AS creator_name
             FROM chat_meetings m
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.channel_id = ?
             ORDER BY m.scheduled_at DESC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    public static function setParticipants(int $meetingId, array $userIds): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM chat_meeting_participants WHERE meeting_id = ?')->execute([$meetingId]);
        $stmt = $db->prepare(
            'INSERT INTO chat_meeting_participants (meeting_id, user_id, status) VALUES (?, ?, "pending")'
        );
        foreach ($userIds as $uid) {
            $stmt->execute([$meetingId, $uid]);
        }
    }

    public static function respond(int $meetingId, int $userId, string $status): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'UPDATE chat_meeting_participants SET status = ?, responded_at = NOW()
             WHERE meeting_id = ? AND user_id = ?'
        )->execute([$status, $meetingId, $userId]);
    }

    public static function allForCalendar(int $userId, string $start, string $end): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT m.*, mp.status AS my_status
             FROM chat_meetings m
             INNER JOIN chat_meeting_participants mp ON mp.meeting_id = m.id AND mp.user_id = ?
             WHERE m.scheduled_at BETWEEN ? AND ?
             ORDER BY m.scheduled_at ASC'
        );
        $stmt->execute([$userId, $start, $end]);
        return $stmt->fetchAll();
    }
}
