<?php
/**
 * MyController — portal do funcionário (micropermissão my.view).
 *
 * Acesso via ?m=rh&page=my  (action default = index).
 * O usuário autenticado deve estar vinculado a um employee via
 * rh_user_profile.employee_id (vínculo do módulo — a tabela global `users`
 * não carrega mais employee_id/department_id/role).
 * O layout é próprio (sem o layout padrão da plataforma).
 */
class MyController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('my.view');

        $employeeId = $this->currentEmployeeId();
        if (!$employeeId) {
            // Usuário sem vínculo com funcionário: quem enxerga o dashboard
            // volta para ele; os demais voltam ao portal inicial da
            // plataforma (evita loop my ⇄ dashboard).
            if (core_can('dashboard.view')) {
                header('Location: index.php?m=rh&page=dashboard');
                exit;
            }
            Session::flash('error', 'Seu usuário ainda não está vinculado a um funcionário. Procure o RH.');
            core_redirect('index.php');
        }

        $employee = Employee::findWithRelations($employeeId);
        if (!$employee) {
            Session::flash('error', 'Registro de funcionário não encontrado.');
            core_redirect('index.php');
        }

        $scores      = EmployeeScore::listFor($employeeId);
        $scoresTotal = EmployeeScore::totalFor($employeeId);
        $compliments = EmployeeCompliment::listFor($employeeId);

        // Próximos vencimentos do próprio funcionário (60 dias).
        $stmt = $this->db->prepare(
            "SELECT * FROM rh_expirations
             WHERE employee_id = ?
               AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             ORDER BY expiry_date ASC"
        );
        $stmt->execute([$employeeId]);
        $upcoming = $stmt->fetchAll();

        $hospitalName = Core\Settings::get('org_name', 'Hospital');

        View::renderRaw('my/index', [
            'hospitalName' => $hospitalName,
            'employee'     => $employee,
            'scores'       => $scores,
            'scoresTotal'  => $scoresTotal,
            'compliments'  => $compliments,
            'upcoming'     => $upcoming,
            'flashSuccess' => Session::flash('success'),
            'flashError'   => Session::flash('error'),
        ]);
    }

    private function currentEmployeeId(): int
    {
        $stmt = $this->db->prepare('SELECT employee_id FROM rh_user_profile WHERE user_id = ?');
        $stmt->execute([Session::userId()]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
