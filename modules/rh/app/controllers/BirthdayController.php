<?php
/**
 * Controller de Aniversariantes (Módulo 5)
 */
class BirthdayController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('birthdays.view');

        $month = Sanitize::int($_GET['month'] ?? date('n'));
        $department = Sanitize::int($_GET['department'] ?? 0);

        if ($month < 1 || $month > 12) $month = (int)date('n');

        $where = ["MONTH(e.birth_date) = ?", "e.status = 'ativo'"];
        $params = [$month];

        if ($department) {
            $where[] = 'e.department_id = ?';
            $params[] = $department;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT e.*, d.name as department_name, j.title as position_title,
                       DAY(e.birth_date) as birth_day
                FROM rh_employees e
                LEFT JOIN rh_departments d ON e.department_id = d.id
                LEFT JOIN rh_job_positions j ON e.job_position_id = j.id
                {$whereClause}
                ORDER BY DAY(e.birth_date) ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $birthdays = $stmt->fetchAll();

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();

        $monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

        $pageTitle = 'Aniversariantes';
        $page = 'birthdays';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/birthdays/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }
}
