<?php
/**
 * EmployeeCompliment — elogios recebidos.
 */
class EmployeeCompliment extends Model
{
    protected static string $table = 'employee_compliments';
    protected static array  $fillable = ['employee_id', 'message', 'compliment_from', 'created_by'];

    public static function listFor(int $employeeId): array
    {
        $stmt = self::db()->prepare(
            'SELECT c.*, u.name AS created_by_name
             FROM employee_compliments c
             LEFT JOIN users u ON c.created_by = u.id
             WHERE c.employee_id = ?
             ORDER BY c.created_at DESC'
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }

    public static function countFor(int $employeeId): int
    {
        return self::count('employee_id = ?', [$employeeId]);
    }
}
