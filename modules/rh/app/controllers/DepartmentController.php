<?php
/**
 * Controller de Departamentos — configurado na Administração central
 * (index.php?m=admin&a=module&slug=rh&tab=departments). As telas (GET)
 * são servidas por admin_panel.php; os POSTs continuam em ?m=rh&page=departments.
 */
class DepartmentController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('departments.view');

        $departments = $this->db->query(
            "SELECT d.*, (SELECT COUNT(*) FROM rh_employees e WHERE e.department_id = d.id AND e.status = 'ativo') as employee_count
             FROM rh_departments d ORDER BY d.name"
        )->fetchAll();

        $pageTitle = 'Departamentos';
        $page = 'departments';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/departments/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function create(): void
    {
        core_require('departments.create');
        $department = null;
        $pageTitle = 'Novo Departamento';
        $page = 'departments';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/departments/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        core_require('departments.create');
        Csrf::check();

        $name = Sanitize::post('name');
        $description = Sanitize::post('description');
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($name)) {
            Session::flash('error', 'Nome é obrigatório.');
            core_redirect(core_admin_url('rh', 'departments', ['action' => 'create']));
        }

        $stmt = $this->db->prepare('INSERT INTO rh_departments (name, description, active) VALUES (?, ?, ?)');
        $stmt->execute([$name, $description, $active]);
        AuditLog::log('create', 'departments', (int)$this->db->lastInsertId());

        Session::flash('success', 'Departamento criado com sucesso.');
        core_redirect(core_admin_url('rh', 'departments'));
    }

    public function edit(): void
    {
        core_require('departments.edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_departments WHERE id = ?');
        $stmt->execute([$id]);
        $department = $stmt->fetch();

        if (!$department) {
            Session::flash('error', 'Departamento não encontrado.');
            core_redirect(core_admin_url('rh', 'departments'));
        }

        $pageTitle = 'Editar Departamento';
        $page = 'departments';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/departments/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        core_require('departments.edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $name = Sanitize::post('name');
        $description = Sanitize::post('description');
        $active = isset($_POST['active']) ? 1 : 0;

        $stmt = $this->db->prepare('UPDATE rh_departments SET name=?, description=?, active=? WHERE id=?');
        $stmt->execute([$name, $description, $active, $id]);
        AuditLog::log('update', 'departments', $id);

        Session::flash('success', 'Departamento atualizado.');
        core_redirect(core_admin_url('rh', 'departments'));
    }

    public function delete(): void
    {
        core_require('departments.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);

        // Verificar se há funcionários vinculados
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM rh_employees WHERE department_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            Session::flash('error', 'Não é possível excluir: existem funcionários vinculados.');
            core_redirect(core_admin_url('rh', 'departments'));
        }

        $this->db->prepare('DELETE FROM rh_departments WHERE id = ?')->execute([$id]);
        AuditLog::log('delete', 'departments', $id);

        Session::flash('success', 'Departamento excluído.');
        core_redirect(core_admin_url('rh', 'departments'));
    }
}
