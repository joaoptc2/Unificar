<?php
class Warning extends Model
{
    protected static string $table = 'warnings';
    protected static array $fillable = ['employee_id','type','reason','incident_date','witnesses','file_path','signature_id','created_by'];

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare(
            'SELECT w.*, u.name AS created_by_name FROM warnings w LEFT JOIN users u ON w.created_by = u.id WHERE w.employee_id = ? ORDER BY w.incident_date DESC'
        );
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }
}
