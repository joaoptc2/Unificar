<?php
class SalaryHistory extends Model
{
    protected static string $table = 'salary_history';
    protected static array $fillable = ['employee_id','salary','reason','effective_date','created_by'];

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare(
            'SELECT sh.*, u.name AS created_by_name FROM salary_history sh LEFT JOIN users u ON sh.created_by = u.id WHERE sh.employee_id = ? ORDER BY sh.effective_date DESC'
        );
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }

    public static function currentSalary(int $empId): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM salary_history WHERE employee_id = ? ORDER BY effective_date DESC LIMIT 1');
        $stmt->execute([$empId]);
        return $stmt->fetch() ?: null;
    }
}
