<?php
/**
 * MyController — portal do funcionário (role = 'funcionario').
 *
 * Acesso via ?page=my  (action default = index).
 * O usuário autenticado deve estar vinculado a um employee via users.employee_id.
 * O layout é próprio (sem o header/sidebar padrão do RH).
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
        Auth::requireLogin();

        // Admin/RH também podem "ver" o portal para inspeção — mas apenas
        // se fornecerem ?id=, caso contrário precisamos do employee_id do user.
        $user = $this->currentUserRow();
        if (!$user || !$user['employee_id']) {
            // Usuário sem vínculo com funcionário — redireciona para dashboard.
            header('Location: index.php?page=dashboard');
            exit;
        }

        $employeeId = (int)$user['employee_id'];
        $employee   = Employee::findWithRelations($employeeId);
        if (!$employee) {
            Session::flash('error', 'Registro de funcionário não encontrado.');
            Auth::logout();
            header('Location: index.php?page=login');
            exit;
        }

        $scores      = EmployeeScore::listFor($employeeId);
        $scoresTotal = EmployeeScore::totalFor($employeeId);
        $compliments = EmployeeCompliment::listFor($employeeId);

        // Próximos vencimentos do próprio funcionário (30 dias).
        $stmt = $this->db->prepare(
            "SELECT * FROM expirations
             WHERE employee_id = ?
               AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             ORDER BY expiry_date ASC"
        );
        $stmt->execute([$employeeId]);
        $upcoming = $stmt->fetchAll();

        $appConfig    = require __DIR__ . '/../../config/app.php';
        $hospitalName = $appConfig['app_name'] ?? 'Hospital';

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

    private function currentUserRow(): ?array
    {
        $stmt = $this->db->prepare('SELECT id, email, employee_id, role FROM users WHERE id = ?');
        $stmt->execute([Session::userId()]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
