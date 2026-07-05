<?php
/**
 * ScoreController — lançamento de pontos por funcionário.
 *
 * Rotas:
 *   ?page=scores&action=store  → POST: adiciona pontos
 *   ?page=scores&action=delete → POST: remove um lançamento
 */
class ScoreController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        header('Location: index.php?m=rh&page=employees');
        exit;
    }

    public function store(): void
    {
        // Pontos ficam disponíveis para quem pode editar funcionários.
        Auth::requirePermission('employees', 'edit');
        Csrf::check();

        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $points     = (int)($_POST['points'] ?? 0);
        $reason     = Sanitize::post('reason');
        $category   = Sanitize::post('category');

        if ($employeeId <= 0 || $points === 0 || $reason === '') {
            Session::flash('error', 'Preencha funcionário, pontuação (diferente de zero) e motivo.');
            header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
            exit;
        }
        if (abs($points) > 1000) {
            Session::flash('error', 'Valor fora da faixa permitida (-1000 a +1000).');
            header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
            exit;
        }

        EmployeeScore::insert([
            'employee_id' => $employeeId,
            'points'      => $points,
            'reason'      => $reason,
            'category'    => $category ?: null,
            'created_by'  => Session::userId(),
        ]);

        AuditLog::log('create', 'employee_scores', (int)$this->db->lastInsertId(), null,
            ['employee_id' => $employeeId, 'points' => $points, 'reason' => $reason]);

        Session::flash('success', 'Pontos lançados com sucesso.');
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
        exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('employees', 'edit');
        Csrf::check();

        $id         = Sanitize::int($_POST['id'] ?? 0);
        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);

        EmployeeScore::delete($id);
        AuditLog::log('delete', 'employee_scores', $id);

        Session::flash('success', 'Lançamento removido.');
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
        exit;
    }
}
