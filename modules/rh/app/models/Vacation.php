<?php
class Vacation extends Model
{
    protected static string $table = 'rh_vacations';
    protected static array $fillable = ['employee_id','period_start','period_end','start_date','end_date','days','sold_days','installment','status','approved_by','approved_at','notes','created_by'];

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM rh_vacations WHERE employee_id = ? ORDER BY period_start DESC');
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }

    public static function overlapping(int $empId, string $start, string $end, int $excludeId = 0): bool
    {
        $sql = 'SELECT 1 FROM rh_vacations WHERE employee_id = ? AND status NOT IN ("rejeitada","concluida") AND start_date <= ? AND end_date >= ?';
        $params = [$empId, $end, $start];
        if ($excludeId) { $sql .= ' AND id <> ?'; $params[] = $excludeId; }
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }
}
