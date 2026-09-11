<?php
class EmployeeRequest extends Model
{
    protected static string $table = 'rh_requests';
    protected static array $fillable = ['employee_id','type','vacation_id','subject','body','requested_by','status','response','responded_by','responded_at'];

    public const TYPES = [
        'declaracao'          => 'Declaração',
        'alteracao_cadastral' => 'Alteração cadastral',
        'treinamento'         => 'Treinamento',
        'ferias'              => 'Férias',
        'outro'               => 'Outro',
    ];

    /** Rótulos dos status (pt-BR). */
    public const STATUS_LABELS = [
        'pendente'   => 'Pendente',
        'em_analise' => 'Em análise',
        'aprovada'   => 'Aprovada',
        'rejeitada'  => 'Rejeitada',
    ];

    public static function pendingCount(): int
    {
        return self::count("status = 'pendente'");
    }

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare(
            'SELECT r.*, u.name AS responded_by_name,
                    v.start_date AS vac_start, v.end_date AS vac_end, v.days AS vac_days, v.status AS vac_status
             FROM rh_requests r
             LEFT JOIN users u ON u.id = r.responded_by
             LEFT JOIN rh_vacations v ON v.id = r.vacation_id
             WHERE r.employee_id = ? ORDER BY r.created_at DESC'
        );
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }

    /** Avisa quem responde solicitações (requests.respond) sobre uma nova solicitação. */
    public static function notifyResponders(int $requestId, string $employeeName, string $subject): void
    {
        foreach (Core\Perms::usersWith('rh', 'requests.respond') as $uid) {
            Core\Notifications::add(
                (int)$uid,
                'Nova solicitação: ' . $subject,
                $employeeName . ' abriu uma solicitação.',
                'index.php?m=rh&page=requests&status=pendente',
                'info',
                'rh'
            );
        }
    }
}
