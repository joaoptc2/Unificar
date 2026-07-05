<?php
class Shift extends Model
{
    protected static string $table = 'rh_shifts';
    protected static array $fillable = ['employee_id','department_id','template_id','shift_date','start_time','end_time','type','status','swap_with_id','notes','created_by'];

    public static function forDepartmentRange(int $deptId, string $from, string $to): array
    {
        $stmt = self::db()->prepare(
            'SELECT s.*, e.full_name AS employee_name, st.name AS template_name, st.color
             FROM rh_shifts s
             JOIN rh_employees e ON s.employee_id = e.id
             LEFT JOIN rh_shift_templates st ON s.template_id = st.id
             WHERE s.department_id = ? AND s.shift_date BETWEEN ? AND ?
             ORDER BY s.shift_date, s.start_time'
        );
        $stmt->execute([$deptId, $from, $to]);
        return $stmt->fetchAll();
    }

    public static function forEmployeeRange(int $empId, string $from, string $to): array
    {
        $stmt = self::db()->prepare(
            'SELECT s.*, st.name AS template_name, st.color, d.name AS department_name
             FROM rh_shifts s
             LEFT JOIN rh_shift_templates st ON s.template_id = st.id
             LEFT JOIN rh_departments d ON s.department_id = d.id
             WHERE s.employee_id = ? AND s.shift_date BETWEEN ? AND ?
             ORDER BY s.shift_date, s.start_time'
        );
        $stmt->execute([$empId, $from, $to]);
        return $stmt->fetchAll();
    }

    public static function hasConflict(int $empId, string $date, string $startTime, string $endTime, int $excludeId = 0): bool
    {
        $sql = 'SELECT 1 FROM rh_shifts WHERE employee_id = ? AND shift_date = ? AND start_time < ? AND end_time > ? AND status NOT IN ("falta","trocado")';
        $params = [$empId, $date, $endTime, $startTime];
        if ($excludeId) { $sql .= ' AND id <> ?'; $params[] = $excludeId; }
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }
}
