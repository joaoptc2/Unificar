<?php
/**
 * Controller de Cargos — configurado na Administração central
 * (index.php?m=admin&a=module&slug=rh&tab=positions). As telas (GET)
 * são servidas por admin_panel.php; os POSTs continuam em ?m=rh&page=positions.
 */
class PositionController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('positions.view');

        $positions = $this->db->query(
            "SELECT j.*, d.name as department_name,
                    (SELECT COUNT(*) FROM rh_employees e WHERE e.job_position_id = j.id AND e.status = 'ativo') as employee_count
             FROM rh_job_positions j
             LEFT JOIN rh_departments d ON j.department_id = d.id
             ORDER BY j.title"
        )->fetchAll();

        $pageTitle = 'Cargos';
        $page = 'positions';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/positions/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function create(): void
    {
        core_require('positions.create');
        $position = null;
        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();

        $pageTitle = 'Novo Cargo';
        $page = 'positions';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/positions/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        core_require('positions.create');
        Csrf::check();

        $stmt = $this->db->prepare('INSERT INTO rh_job_positions (title, department_id, description, active) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            Sanitize::post('title'),
            Sanitize::int($_POST['department_id'] ?? 0) ?: null,
            Sanitize::post('description'),
            isset($_POST['active']) ? 1 : 0
        ]);
        AuditLog::log('create', 'job_positions', (int)$this->db->lastInsertId());

        Session::flash('success', 'Cargo criado com sucesso.');
        core_redirect(core_admin_url('rh', 'positions'));
    }

    public function edit(): void
    {
        core_require('positions.edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_job_positions WHERE id = ?');
        $stmt->execute([$id]);
        $position = $stmt->fetch();

        if (!$position) {
            Session::flash('error', 'Cargo não encontrado.');
            core_redirect(core_admin_url('rh', 'positions'));
        }

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();

        $pageTitle = 'Editar Cargo';
        $page = 'positions';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/positions/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        core_require('positions.edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE rh_job_positions SET title=?, department_id=?, description=?, active=? WHERE id=?');
        $stmt->execute([
            Sanitize::post('title'),
            Sanitize::int($_POST['department_id'] ?? 0) ?: null,
            Sanitize::post('description'),
            isset($_POST['active']) ? 1 : 0,
            $id
        ]);
        AuditLog::log('update', 'job_positions', $id);

        Session::flash('success', 'Cargo atualizado.');
        core_redirect(core_admin_url('rh', 'positions'));
    }

    public function delete(): void
    {
        core_require('positions.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM rh_employees WHERE job_position_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            Session::flash('error', 'Não é possível excluir: existem funcionários vinculados.');
            core_redirect(core_admin_url('rh', 'positions'));
        }

        $this->db->prepare('DELETE FROM rh_job_positions WHERE id = ?')->execute([$id]);
        AuditLog::log('delete', 'job_positions', $id);

        Session::flash('success', 'Cargo excluído.');
        core_redirect(core_admin_url('rh', 'positions'));
    }
}
