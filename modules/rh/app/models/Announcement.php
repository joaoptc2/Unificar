<?php
class Announcement extends Model
{
    protected static string $table = 'rh_announcements';
    protected static array $fillable = ['title','body','type','department_id','published_at','expires_at','pinned','created_by'];

    public static function published(?int $deptId = null): array
    {
        $sql = "SELECT a.*, u.name AS author_name FROM rh_announcements a LEFT JOIN users u ON a.created_by = u.id WHERE a.published_at IS NOT NULL AND a.published_at <= NOW() AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())";
        $params = [];
        if ($deptId) { $sql .= ' AND (a.department_id IS NULL OR a.department_id = ?)'; $params[] = $deptId; }
        $sql .= ' ORDER BY a.pinned DESC, a.published_at DESC';
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function markRead(int $announcementId, int $userId): void
    {
        self::db()->prepare('INSERT IGNORE INTO rh_announcement_reads (announcement_id, user_id) VALUES (?, ?)')->execute([$announcementId, $userId]);
    }

    public static function isRead(int $announcementId, int $userId): bool
    {
        $stmt = self::db()->prepare('SELECT 1 FROM rh_announcement_reads WHERE announcement_id = ? AND user_id = ?');
        $stmt->execute([$announcementId, $userId]);
        return (bool)$stmt->fetchColumn();
    }
}
