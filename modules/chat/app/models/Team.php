<?php
class Team extends Model
{
    protected static string $table = 'teams';
    protected static array  $fillable = [
        'name', 'slug', 'description', 'color', 'created_by', 'is_active',
    ];

    public static function withMembers(int $id): ?array
    {
        $team = self::find($id);
        if (!$team) return null;

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.email, u.avatar, u.status, u.title, tm.role
             FROM users u
             INNER JOIN team_members tm ON tm.user_id = u.id
             WHERE tm.team_id = ? AND u.is_active = 1
             ORDER BY tm.role ASC, u.name ASC'
        );
        $stmt->execute([$id]);
        $team['members'] = $stmt->fetchAll();

        return $team;
    }

    public static function userTeams(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.*, tm.role AS member_role,
                    (SELECT COUNT(*) FROM team_members tm2 WHERE tm2.team_id = t.id) AS member_count
             FROM teams t
             INNER JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
             WHERE t.is_active = 1
             ORDER BY t.name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function allActive(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS member_count
             FROM teams t WHERE t.is_active = 1 ORDER BY t.name ASC'
        );
        return $stmt->fetchAll();
    }

    public static function addMember(int $teamId, int $userId, string $role = 'member'): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT IGNORE INTO team_members (team_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$teamId, $userId, $role]);
    }

    public static function removeMember(int $teamId, int $userId): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?')
           ->execute([$teamId, $userId]);
    }

    public static function isMember(int $teamId, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$teamId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function memberIds(int $teamId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT user_id FROM team_members WHERE team_id = ?');
        $stmt->execute([$teamId]);
        return array_column($stmt->fetchAll(), 'user_id');
    }

    public static function findBySlug(string $slug): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM teams WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }
}
