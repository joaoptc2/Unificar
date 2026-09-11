<?php
/**
 * RewardRedemption — resgates de brindes.
 *
 * Fluxo: pendente → aprovada (lança pontos negativos, decrementa estoque)
 *        → entregue; ou rejeitada/cancelada (devolve pontos e estoque se já
 *        lançados).
 */
class RewardRedemption extends Model
{
    protected static string $table = 'rh_reward_redemptions';
    protected static array $fillable = ['reward_id', 'employee_id', 'points_spent', 'status', 'notes', 'response', 'score_id', 'responded_by', 'responded_at'];

    public const STATUS = [
        'pendente'  => ['Pendente',  'bg-warning text-dark'],
        'aprovada'  => ['Aprovada',  'bg-primary'],
        'entregue'  => ['Entregue',  'bg-success'],
        'rejeitada' => ['Rejeitada', 'bg-danger'],
        'cancelada' => ['Cancelada', 'bg-secondary'],
    ];

    public static function listAll(?string $status = null): array
    {
        $sql = 'SELECT d.*, r.name AS reward_name, r.image_path, e.full_name AS employee_name, u.name AS responded_by_name
                FROM rh_reward_redemptions d
                JOIN rh_rewards r ON r.id = d.reward_id
                JOIN rh_employees e ON e.id = d.employee_id
                LEFT JOIN users u ON u.id = d.responded_by';
        $params = [];
        if ($status) { $sql .= ' WHERE d.status = ?'; $params[] = $status; }
        $sql .= " ORDER BY FIELD(d.status,'pendente','aprovada','entregue','rejeitada','cancelada'), d.created_at DESC LIMIT 300";
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function forEmployee(int $employeeId): array
    {
        $stmt = self::db()->prepare(
            'SELECT d.*, r.name AS reward_name, r.image_path
             FROM rh_reward_redemptions d JOIN rh_rewards r ON r.id = d.reward_id
             WHERE d.employee_id = ? ORDER BY d.created_at DESC'
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }

    /** Pontos já comprometidos em resgates pendentes (não lançados ainda). */
    public static function reservedFor(int $employeeId): int
    {
        $stmt = self::db()->prepare("SELECT COALESCE(SUM(points_spent),0) FROM rh_reward_redemptions WHERE employee_id = ? AND status = 'pendente'");
        $stmt->execute([$employeeId]);
        return (int)$stmt->fetchColumn();
    }

    /** Saldo disponível = pontos acumulados − resgates pendentes. */
    public static function availableFor(int $employeeId): int
    {
        return EmployeeScore::totalFor($employeeId) - self::reservedFor($employeeId);
    }
}
