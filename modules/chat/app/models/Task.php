<?php
class Task extends Model
{
    protected static string $table = 'tasks';
    protected static array  $fillable = [
        'channel_id', 'title', 'description', 'status',
        'priority', 'created_by', 'due_date', 'completed_at',
    ];

    public static function withDetails(int $id): ?array
    {
        $task = self::find($id);
        if (!$task) return null;

        $db = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.avatar, u.email
             FROM users u
             INNER JOIN task_assignees ta ON ta.user_id = u.id
             WHERE ta.task_id = ?'
        );
        $stmt->execute([$id]);
        $task['assignees'] = $stmt->fetchAll();

        $stmt = $db->prepare(
            'SELECT tc.*, u.name AS user_name, u.avatar AS user_avatar
             FROM task_comments tc
             LEFT JOIN users u ON u.id = tc.user_id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at ASC'
        );
        $stmt->execute([$id]);
        $task['comments'] = $stmt->fetchAll();

        if ($task['created_by']) {
            $creator = User::find($task['created_by']);
            $task['creator_name'] = $creator['name'] ?? 'Desconhecido';
        }

        return $task;
    }

    public static function byChannel(int $channelId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.*, u.name AS creator_name,
                    GROUP_CONCAT(ua.name SEPARATOR ", ") AS assignee_names
             FROM tasks t
             LEFT JOIN users u ON u.id = t.created_by
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             LEFT JOIN users ua ON ua.id = ta.user_id
             WHERE t.channel_id = ?
             GROUP BY t.id
             ORDER BY FIELD(t.priority, "urgent", "high", "medium", "low"), t.created_at DESC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    public static function byStatus(?int $userId = null): array
    {
        $db = Database::getInstance();
        $result = [];
        foreach (['todo', 'in_progress', 'review', 'done'] as $status) {
            if ($userId) {
                $stmt = $db->prepare(
                    'SELECT t.*, u.name AS creator_name,
                            GROUP_CONCAT(DISTINCT ua.name SEPARATOR ", ") AS assignee_names
                     FROM tasks t
                     LEFT JOIN users u ON u.id = t.created_by
                     LEFT JOIN task_assignees ta ON ta.task_id = t.id
                     LEFT JOIN users ua ON ua.id = ta.user_id
                     WHERE t.status = ? AND (t.created_by = ? OR t.id IN (SELECT task_id FROM task_assignees WHERE user_id = ?))
                     GROUP BY t.id
                     ORDER BY FIELD(t.priority, "urgent", "high", "medium", "low"), t.created_at DESC'
                );
                $stmt->execute([$status, $userId, $userId]);
            } else {
                $stmt = $db->prepare(
                    'SELECT t.*, u.name AS creator_name,
                            GROUP_CONCAT(DISTINCT ua.name SEPARATOR ", ") AS assignee_names
                     FROM tasks t
                     LEFT JOIN users u ON u.id = t.created_by
                     LEFT JOIN task_assignees ta ON ta.task_id = t.id
                     LEFT JOIN users ua ON ua.id = ta.user_id
                     WHERE t.status = ?
                     GROUP BY t.id
                     ORDER BY FIELD(t.priority, "urgent", "high", "medium", "low"), t.created_at DESC'
                );
                $stmt->execute([$status]);
            }
            $result[$status] = $stmt->fetchAll();
        }
        return $result;
    }

    public static function userTasks(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.*, u.name AS creator_name
             FROM tasks t
             LEFT JOIN users u ON u.id = t.created_by
             INNER JOIN task_assignees ta ON ta.task_id = t.id AND ta.user_id = ?
             WHERE t.status != "cancelled"
             ORDER BY FIELD(t.priority, "urgent", "high", "medium", "low"), t.due_date ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function setAssignees(int $taskId, array $userIds): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$taskId]);
        $stmt = $db->prepare('INSERT INTO task_assignees (task_id, user_id, assigned_at) VALUES (?, ?, NOW())');
        foreach ($userIds as $uid) {
            $stmt->execute([$taskId, $uid]);
        }
    }

    public static function addComment(int $taskId, int $userId, string $content): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO task_comments (task_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$taskId, $userId, $content]);
        return (int) $db->lastInsertId();
    }

    public static function stats(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT status, COUNT(*) AS cnt FROM tasks WHERE status != "cancelled" GROUP BY status'
        );
        $result = ['todo' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }
        return $result;
    }
}
