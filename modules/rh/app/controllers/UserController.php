<?php
/**
 * Controller de Gestão de Usuários (Módulo 10 — Administração)
 */
class UserController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requirePermission('users', 'view');

        $users = $this->db->query(
            'SELECT u.*, (SELECT MAX(al.created_at) FROM audit_log al WHERE al.user_id = u.id) as last_action
             FROM users u ORDER BY u.name'
        )->fetchAll();

        $pageTitle = 'Usuários';
        $page = 'users';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/users/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function create(): void
    {
        Auth::requirePermission('users', 'create');
        $user = null;
        $pageTitle = 'Novo Usuário';
        $page = 'users';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/users/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        Auth::requirePermission('users', 'create');
        Csrf::check();

        $name     = Sanitize::post('name');
        $email    = Sanitize::email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = Sanitize::post('role');
        $active   = isset($_POST['active']) ? 1 : 0;

        if (empty($name) || empty($email) || empty($password)) {
            Session::flash('error', 'Preencha todos os campos obrigatórios.');
            header('Location: index.php?page=users&action=create');
            exit;
        }

        if (strlen($password) < 8) {
            Session::flash('error', 'A senha deve ter no mínimo 8 caracteres.');
            header('Location: index.php?page=users&action=create');
            exit;
        }

        // Verificar e-mail duplicado
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Session::flash('error', 'E-mail já cadastrado.');
            header('Location: index.php?page=users&action=create');
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare('INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $hash, $role, $active]);
        AuditLog::log('create', 'users', (int)$this->db->lastInsertId());

        Session::flash('success', 'Usuário criado com sucesso.');
        header('Location: index.php?page=users');
        exit;
    }

    public function edit(): void
    {
        Auth::requirePermission('users', 'edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Session::flash('error', 'Usuário não encontrado.');
            header('Location: index.php?page=users');
            exit;
        }

        $pageTitle = 'Editar Usuário';
        $page = 'users';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/users/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        Auth::requirePermission('users', 'edit');
        Csrf::check();

        $id       = Sanitize::int($_POST['id'] ?? 0);
        $name     = Sanitize::post('name');
        $email    = Sanitize::email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = Sanitize::post('role');
        $active   = isset($_POST['active']) ? 1 : 0;

        // Verificar e-mail duplicado
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            Session::flash('error', 'E-mail já cadastrado por outro usuário.');
            header('Location: index.php?page=users&action=edit&id=' . $id);
            exit;
        }

        if ($password) {
            if (strlen($password) < 8) {
                Session::flash('error', 'A senha deve ter no mínimo 8 caracteres.');
                header('Location: index.php?page=users&action=edit&id=' . $id);
                exit;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $this->db->prepare('UPDATE users SET name=?, email=?, password=?, role=?, active=? WHERE id=?');
            $stmt->execute([$name, $email, $hash, $role, $active, $id]);
        } else {
            $stmt = $this->db->prepare('UPDATE users SET name=?, email=?, role=?, active=? WHERE id=?');
            $stmt->execute([$name, $email, $role, $active, $id]);
        }

        AuditLog::log('update', 'users', $id);
        Session::flash('success', 'Usuário atualizado.');
        header('Location: index.php?page=users');
        exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('users', 'delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);

        // Não permitir excluir a si mesmo
        if ($id === Session::userId()) {
            Session::flash('error', 'Você não pode excluir seu próprio usuário.');
            header('Location: index.php?page=users');
            exit;
        }

        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        AuditLog::log('delete', 'users', $id);

        Session::flash('success', 'Usuário excluído.');
        header('Location: index.php?page=users');
        exit;
    }

    /**
     * Log de auditoria
     */
    public function audit_log(): void
    {
        Auth::requirePermission('users', 'view');

        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));

        $total = (int)$this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $pagination = new Pagination($total, $currentPage, 30);

        $stmt = $this->db->prepare(
            "SELECT al.*, u.name as user_name
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC
             LIMIT {$pagination->perPage} OFFSET {$pagination->offset}"
        );
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $pageTitle = 'Log de Auditoria';
        $page = 'users';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/users/audit_log.php';
        require __DIR__ . '/../views/layout/footer.php';
    }
}
