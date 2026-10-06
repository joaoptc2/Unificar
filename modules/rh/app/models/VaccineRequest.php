<?php
/**
 * VaccineRequest — solicitação de renovação/regularização de vacina.
 *
 * Fecha o ciclo: RH (ou o cron, ao vencer) pede ao funcionário → o
 * funcionário envia o comprovante pela Minha Área (vira um registro de dose
 * NÃO verificado) → o RH valida na ficha → a solicitação conclui.
 */
class VaccineRequest extends Model
{
    protected static string $table = 'rh_vaccine_requests';
    protected static array $fillable = ['employee_id','vaccine_id','kind','requested_by','due_date','message','status',
        'employee_vaccine_id','employee_note','responded_at','closed_by','closed_at','notified_at'];

    public const STATUS_LABELS = [
        'aberta'    => 'Aguardando o funcionário',
        'enviada'   => 'Comprovante enviado — validar',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    public static function badge(string $status): string
    {
        return match ($status) {
            'enviada'   => 'bg-info text-dark',
            'concluida' => 'bg-success',
            'cancelada' => 'bg-secondary',
            default     => 'bg-warning text-dark',
        };
    }

    /** Solicitação em aberto (aberta/enviada) deste funcionário para esta vacina, ou null. */
    public static function open(int $employeeId, int $vaccineId): ?array
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM rh_vaccine_requests WHERE employee_id = ? AND vaccine_id = ? AND status IN ('aberta','enviada')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$employeeId, $vaccineId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cria a solicitação se não houver uma em aberto e avisa o funcionário
     * (notificação in-app; e-mail pela fila quando houver endereço real).
     * Devolve o id (novo ou o já existente).
     */
    public static function request(int $employeeId, int $vaccineId, string $kind, ?int $by, ?string $message, ?string $dueDate = null): int
    {
        if ($ja = self::open($employeeId, $vaccineId)) {
            return (int) $ja['id'];
        }
        $db = self::db();
        $db->prepare(
            'INSERT INTO rh_vaccine_requests (employee_id, vaccine_id, kind, requested_by, due_date, message) VALUES (?,?,?,?,?,?)'
        )->execute([$employeeId, $vaccineId, $kind, $by, $dueDate, $message ?: null]);
        $id = (int) $db->lastInsertId();
        self::notifyEmployee($id);
        return $id;
    }

    /** Avisa o funcionário vinculado (notificação + e-mail). */
    public static function notifyEmployee(int $id): void
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT r.*, v.name AS vaccine_name, e.full_name, p.user_id
             FROM rh_vaccine_requests r
             JOIN rh_vaccines v ON v.id = r.vaccine_id
             JOIN rh_employees e ON e.id = r.employee_id
             LEFT JOIN rh_user_profile p ON p.employee_id = e.id
             WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return;
        }
        $titulo = ($r['kind'] === 'pendente' ? 'Vacina pendente: ' : 'Renovação de vacina: ') . $r['vaccine_name'];
        $msg = 'O RH solicita o comprovante de vacinação' . ($r['due_date'] ? ' até ' . Sanitize::formatDate($r['due_date']) : '') . '.'
             . ($r['message'] ? ' ' . $r['message'] : '');
        if (!empty($r['user_id'])) {
            Core\Notifications::add((int) $r['user_id'], $titulo, $msg, 'index.php?m=rh&page=my&tab=vacinas', 'warning', 'rh');
            $u = $db->prepare('SELECT email FROM users WHERE id = ?');
            $u->execute([(int) $r['user_id']]);
            $email = (string) ($u->fetchColumn() ?: '');
            if ($email !== '' && !EmployeeAccess::isPlaceholderEmail($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    Core\MailQueue::enqueue($email, '[RH] ' . $titulo, '<p>Olá, ' . Sanitize::e($r['full_name']) . '.</p><p>' . Sanitize::e($msg)
                        . '</p><p>Envie o comprovante pela Minha Área (aba Vacinas) do portal.</p>', 'rh', 'vaccine_request', $id);
                } catch (\Throwable $e) {
                    error_log('vacinas: e-mail não enfileirado: ' . $e->getMessage());
                }
            }
        }
        $db->prepare('UPDATE rh_vaccine_requests SET notified_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /** Solicitações de um funcionário (portal e ficha). */
    public static function forEmployee(int $employeeId, bool $onlyOpen = false): array
    {
        $stmt = self::db()->prepare(
            'SELECT r.*, v.name AS vaccine_name, u.name AS requested_by_name
             FROM rh_vaccine_requests r
             JOIN rh_vaccines v ON v.id = r.vaccine_id
             LEFT JOIN users u ON u.id = r.requested_by
             WHERE r.employee_id = ?' . ($onlyOpen ? " AND r.status IN ('aberta','enviada')" : '') . '
             ORDER BY FIELD(r.status,\'enviada\',\'aberta\',\'concluida\',\'cancelada\'), r.created_at DESC'
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }

    /** Lista geral (painel do RH). */
    public static function listing(string $status = ''): array
    {
        $wc = $status !== '' ? 'WHERE r.status = ?' : '';
        $stmt = self::db()->prepare(
            "SELECT r.*, v.name AS vaccine_name, e.full_name AS employee_name, d.name AS department_name
             FROM rh_vaccine_requests r
             JOIN rh_vaccines v ON v.id = r.vaccine_id
             JOIN rh_employees e ON e.id = r.employee_id
             LEFT JOIN rh_departments d ON d.id = e.department_id
             $wc
             ORDER BY FIELD(r.status,'enviada','aberta','concluida','cancelada'), r.created_at DESC LIMIT 300"
        );
        $stmt->execute($status !== '' ? [$status] : []);
        return $stmt->fetchAll();
    }

    public static function pendingValidationCount(): int
    {
        return self::count("status = 'enviada'");
    }
}
