<?php
/**
 * Controller de Departamentos (Administração)
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
        Auth::requirePermission('departments', 'view');

        $departments = $this->db->query(
            "SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.status = 'ativo') as employee_count
             FROM departments d ORDER BY d.name"
        )->fetchAll();

        $pageTitle = 'Departamentos';
        $page = 'departments';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/departments/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function create(): void
    {
        Auth::requirePermission('departments', 'create');
        $department = null;
        $pageTitle = 'Novo Departamento';
        $page = 'departments';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/departments/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        Auth::requirePermission('departments', 'create');
        Csrf::check();

        $name = Sanitize::post('name');
        $description = Sanitize::post('description');
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($name)) {
            Session::flash('error', 'Nome é obrigatório.');
            header('Location: index.php?page=departments&action=create');
            exit;
        }

        $stmt = $this->db->prepare('INSERT INTO departments (name, description, active) VALUES (?, ?, ?)');
        $stmt->execute([$name, $description, $active]);
        AuditLog::log('create', 'departments', (int)$this->db->lastInsertId());

        Session::flash('success', 'Departamento criado com sucesso.');
        header('Location: index.php?page=departments');
        exit;
    }

    public function edit(): void
    {
        Auth::requirePermission('departments', 'edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM departments WHERE id = ?');
        $stmt->execute([$id]);
        $department = $stmt->fetch();

        if (!$department) {
            Session::flash('error', 'Departamento não encontrado.');
            header('Location: index.php?page=departments');
            exit;
        }

        $pageTitle = 'Editar Departamento';
        $page = 'departments';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/departments/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        Auth::requirePermission('departments', 'edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $name = Sanitize::post('name');
        $description = Sanitize::post('description');
        $active = isset($_POST['active']) ? 1 : 0;

        $stmt = $this->db->prepare('UPDATE departments SET name=?, description=?, active=? WHERE id=?');
        $stmt->execute([$name, $description, $active, $id]);
        AuditLog::log('update', 'departments', $id);

        Session::flash('success', 'Departamento atualizado.');
        header('Location: index.php?page=departments');
        exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('departments', 'delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);

        // Verificar se há funcionários vinculados
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM employees WHERE department_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            Session::flash('error', 'Não é possível excluir: existem funcionários vinculados.');
            header('Location: index.php?page=departments');
            exit;
        }

        $this->db->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
        AuditLog::log('delete', 'departments', $id);

        Session::flash('success', 'Departamento excluído.');
        header('Location: index.php?page=departments');
        exit;
    }
}
