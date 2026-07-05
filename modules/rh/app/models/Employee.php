<?php
/**
 * Employee — model para a tabela `employees`.
 */
class Employee extends Model
{
    protected static string $table = 'rh_employees';

    protected static array $fillable = [
        'full_name', 'cpf', 'birth_date', 'gender', 'phone', 'email',
        'address_street', 'address_number', 'address_complement', 'address_neighborhood',
        'address_city', 'address_state', 'address_zip',
        'job_position_id', 'department_id',
        'admission_date', 'contract_type', 'status',
        'termination_date', 'leave_date', 'return_date',
        'regional_council', 'council_number', 'council_expiry',
        'photo', 'notes', 'created_by',
    ];

    /** Busca o funcionário com cargo e departamento resolvidos. */
    public static function findWithRelations(int $id): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT e.*, d.name AS department_name, j.title AS position_title
             FROM rh_employees e
             LEFT JOIN rh_departments d ON e.department_id = d.id
             LEFT JOIN rh_job_positions j ON e.job_position_id = j.id
             WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByCpf(string $cpf, int $excludeId = 0): ?array
    {
        $sql = 'SELECT * FROM rh_employees WHERE cpf = ?';
        $params = [$cpf];
        if ($excludeId) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = self::db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function countByStatus(): array
    {
        $stmt = self::db()->query(
            "SELECT status, COUNT(*) AS total FROM rh_employees GROUP BY status"
        );
        $out = ['ativo' => 0, 'afastado' => 0, 'desligado' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['status']] = (int)$row['total'];
        }
        return $out;
    }

    public static function countByDepartment(): array
    {
        return self::db()->query(
            "SELECT d.name, COUNT(e.id) AS total
             FROM rh_departments d
             LEFT JOIN rh_employees e ON e.department_id = d.id AND e.status = 'ativo'
             GROUP BY d.id, d.name
             HAVING total > 0
             ORDER BY total DESC"
        )->fetchAll();
    }

    public static function countByContract(): array
    {
        return self::db()->query(
            "SELECT contract_type, COUNT(*) AS total
             FROM rh_employees WHERE status = 'ativo'
             GROUP BY contract_type ORDER BY total DESC"
        )->fetchAll();
    }

    public static function birthdaysInMonth(int $month): array
    {
        $stmt = self::db()->prepare(
            "SELECT e.full_name, DAY(e.birth_date) AS birth_day, d.name AS department_name
             FROM rh_employees e
             LEFT JOIN rh_departments d ON e.department_id = d.id
             WHERE MONTH(e.birth_date) = ? AND e.status = 'ativo'
             ORDER BY DAY(e.birth_date) ASC
             LIMIT 10"
        );
        $stmt->execute([$month]);
        return $stmt->fetchAll();
    }

    public static function recentAdmissions(int $days = 30): int
    {
        $stmt = self::db()->prepare(
            'SELECT COUNT(*) FROM rh_employees WHERE admission_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return (int)$stmt->fetchColumn();
    }
}
