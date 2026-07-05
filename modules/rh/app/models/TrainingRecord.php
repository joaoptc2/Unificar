<?php
class TrainingRecord extends Model
{
    protected static string $table = 'training_records';
    protected static array $fillable = ['employee_id','catalog_id','title','completed_at','expires_at','hours','certificate_path','notes','created_by'];

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare(
            'SELECT tr.*, tc.category FROM training_records tr LEFT JOIN training_catalog tc ON tr.catalog_id = tc.id WHERE tr.employee_id = ? ORDER BY tr.completed_at DESC'
        );
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }

    public static function expiringInDays(int $days = 30): array
    {
        $stmt = self::db()->prepare(
            "SELECT tr.*, e.full_name AS employee_name FROM training_records tr JOIN employees e ON tr.employee_id = e.id WHERE tr.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND e.status = 'ativo' ORDER BY tr.expires_at"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
}
