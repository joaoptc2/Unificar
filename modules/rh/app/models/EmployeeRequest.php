<?php
class EmployeeRequest extends Model
{
    protected static string $table = 'requests';
    protected static array $fillable = ['employee_id','type','subject','body','status','response','responded_by','responded_at'];

    public static function pendingCount(): int
    {
        return self::count("status = 'pendente'");
    }

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM requests WHERE employee_id = ? ORDER BY created_at DESC');
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }
}
