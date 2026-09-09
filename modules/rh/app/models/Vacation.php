<?php
class Vacation extends Model
{
    protected static string $table = 'rh_vacations';
    protected static array $fillable = ['employee_id','period_start','period_end','start_date','end_date','days','sold_days','installment','status','approved_by','approved_at','notes','created_by'];

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare(
            'SELECT v.*, u.name AS approved_by_name
             FROM rh_vacations v LEFT JOIN users u ON u.id = v.approved_by
             WHERE v.employee_id = ? ORDER BY v.start_date DESC, v.id DESC'
        );
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

    /**
     * Decide uma solicitação de férias (aprovada/rejeitada) mantendo a
     * solicitação vinculada em rh_requests sincronizada e notificando o
     * funcionário. Usado pelo painel de Férias e pela aba Solicitações.
     */
    public static function decide(int $vacationId, string $status, ?int $by, string $response = ''): ?array
    {
        $vac = self::find($vacationId);
        if (!$vac) {
            return null;
        }
        $status = $status === 'aprovada' ? 'aprovada' : 'rejeitada';
        self::db()->prepare('UPDATE rh_vacations SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?')
            ->execute([$status, $by, $vacationId]);
        self::db()->prepare(
            "UPDATE rh_requests SET status = ?, response = COALESCE(NULLIF(?, ''), response), responded_by = ?, responded_at = NOW()
             WHERE vacation_id = ? AND status IN ('pendente','em_analise')"
        )->execute([$status, $response, $by, $vacationId]);

        // Notifica o funcionário (quem abriu a solicitação ou o usuário vinculado).
        $req = self::db()->prepare('SELECT requested_by FROM rh_requests WHERE vacation_id = ? ORDER BY id DESC LIMIT 1');
        $req->execute([$vacationId]);
        $userId = (int)($req->fetchColumn() ?: 0);
        if (!$userId) {
            $st = self::db()->prepare('SELECT user_id FROM rh_user_profile WHERE employee_id = ?');
            $st->execute([(int)$vac['employee_id']]);
            $userId = (int)($st->fetchColumn() ?: 0);
        }
        if ($userId) {
            $periodo = Sanitize::formatDate($vac['start_date']) . ' a ' . Sanitize::formatDate($vac['end_date']);
            Core\Notifications::add(
                $userId,
                'Férias ' . $status,
                'Sua solicitação de férias (' . $periodo . ') foi ' . $status . '.' . ($response !== '' ? ' ' . $response : ''),
                'index.php?m=rh&page=my#ferias',
                $status === 'aprovada' ? 'success' : 'warning',
                'rh'
            );
        }
        $vac['status'] = $status;
        return $vac;
    }
}
