<?php
class Process extends Model
{
    protected static string $table = 'chat_processes';
    protected static array  $fillable = [
        'channel_id', 'title', 'description', 'status',
        'progress', 'created_by',
    ];

    public static function withSteps(int $id): ?array
    {
        $process = self::find($id);
        if (!$process) return null;

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ps.*, u.name AS assigned_name, cu.name AS completed_by_name
             FROM chat_process_steps ps
             LEFT JOIN users u ON u.id = ps.assigned_to
             LEFT JOIN users cu ON cu.id = ps.completed_by
             WHERE ps.process_id = ?
             ORDER BY ps.order_num ASC'
        );
        $stmt->execute([$id]);
        $process['steps'] = $stmt->fetchAll();

        if ($process['created_by']) {
            $creator = User::find($process['created_by']);
            $process['creator_name'] = $creator['name'] ?? 'Desconhecido';
        }

        return $process;
    }

    public static function byChannel(int $channelId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT p.*, u.name AS creator_name
             FROM chat_processes p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.channel_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    public static function active(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT p.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM chat_process_steps ps WHERE ps.process_id = p.id) AS total_steps,
                    (SELECT COUNT(*) FROM chat_process_steps ps WHERE ps.process_id = p.id AND ps.status = "completed") AS completed_steps
             FROM chat_processes p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.status = "active"
             ORDER BY p.created_at DESC'
        );
        return $stmt->fetchAll();
    }

    public static function addStep(int $processId, array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO chat_process_steps (process_id, title, description, order_num, assigned_to, due_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $processId, $data['title'], $data['description'] ?? null,
            $data['order_num'] ?? 0, $data['assigned_to'] ?? null, $data['due_date'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function updateStepStatus(int $stepId, string $status, ?int $userId = null): void
    {
        $db = Database::getInstance();
        $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
        $completedBy = $status === 'completed' ? $userId : null;

        $db->prepare(
            'UPDATE chat_process_steps SET status = ?, completed_at = ?, completed_by = ? WHERE id = ?'
        )->execute([$status, $completedAt, $completedBy, $stepId]);
    }

    public static function recalculateProgress(int $processId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT COUNT(*) FROM chat_process_steps WHERE process_id = ?');
        $stmt->execute([$processId]);
        $total = (int) $stmt->fetchColumn();

        if ($total === 0) {
            $db->prepare('UPDATE chat_processes SET progress = 0 WHERE id = ?')->execute([$processId]);
            return;
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM chat_process_steps WHERE process_id = ? AND status = "completed"');
        $stmt->execute([$processId]);
        $done = (int) $stmt->fetchColumn();

        $progress = (int) round(($done / $total) * 100);
        $db->prepare('UPDATE chat_processes SET progress = ? WHERE id = ?')->execute([$progress, $processId]);
    }
}
