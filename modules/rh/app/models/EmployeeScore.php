<?php
/**
 * EmployeeScore — pontuação interna.
 */
class EmployeeScore extends Model
{
    protected static string $table = 'rh_employee_scores';
    protected static array  $fillable = ['employee_id', 'points', 'reason', 'category', 'created_by'];

    public static function totalFor(int $employeeId): int
    {
        $stmt = self::db()->prepare('SELECT COALESCE(SUM(points), 0) FROM rh_employee_scores WHERE employee_id = ?');
        $stmt->execute([$employeeId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Lista pontos de um funcionário, com autor resolvido.
     */
    public static function listFor(int $employeeId): array
    {
        $stmt = self::db()->prepare(
            'SELECT s.*, u.name AS created_by_name
             FROM rh_employee_scores s
             LEFT JOIN users u ON s.created_by = u.id
             WHERE s.employee_id = ?
             ORDER BY s.created_at DESC'
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }
}
