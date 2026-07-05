<?php
/**
 * UserController — "Vínculos de usuários" (administração do módulo).
 *
 * O CRUD de usuários (criação, edição, senha, papel) agora é da
 * administração CENTRAL da plataforma (?m=admin&a=users). Esta tela lista
 * os usuários globais com acesso ao módulo RH (user_module_access) e
 * permite definir o vínculo funcional do módulo (rh_user_profile:
 * employee_id / department_id).
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
        Auth::requirePermission('users', 'view'); // apenas admin do módulo

        $users = $this->db->query(
            "SELECT u.id, u.name, u.email, u.active, u.is_admin, u.last_login_at,
                    uma.role AS rh_role,
                    p.employee_id, p.department_id,
                    e.full_name AS employee_name,
                    d.name AS department_name
             FROM users u
             LEFT JOIN user_module_access uma
               ON uma.user_id = u.id AND uma.module_slug = 'rh'
             LEFT JOIN rh_user_profile p ON p.user_id = u.id
             LEFT JOIN rh_employees e ON e.id = p.employee_id
             LEFT JOIN rh_departments d ON d.id = p.department_id
             WHERE uma.id IS NOT NULL OR u.is_admin = 1
             ORDER BY u.name"
        )->fetchAll();

        $employees = $this->db->query(
            "SELECT id, full_name FROM rh_employees ORDER BY full_name"
        )->fetchAll();

        $departments = $this->db->query(
            'SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name'
        )->fetchAll();

        $pageTitle = 'Vínculos de usuários';
        $page = 'users';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/users/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /**
     * Define/atualiza o vínculo (employee_id / department_id) de um usuário.
     */
    public function link(): void
    {
        Auth::requirePermission('users', 'edit');
        Csrf::check();

        $userId       = Sanitize::int($_POST['user_id'] ?? 0);
        $employeeId   = Sanitize::int($_POST['employee_id'] ?? 0) ?: null;
        $departmentId = Sanitize::int($_POST['department_id'] ?? 0) ?: null;

        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            Session::flash('error', 'Usuário não encontrado.');
            header('Location: index.php?m=rh&page=users');
            exit;
        }

        // Evita vincular o mesmo funcionário a dois usuários.
        if ($employeeId) {
            $stmt = $this->db->prepare(
                'SELECT user_id FROM rh_user_profile WHERE employee_id = ? AND user_id <> ?'
            );
            $stmt->execute([$employeeId, $userId]);
            if ($stmt->fetch()) {
                Session::flash('error', 'Este funcionário já está vinculado a outro usuário.');
                header('Location: index.php?m=rh&page=users');
                exit;
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO rh_user_profile (user_id, employee_id, department_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id),
                                     department_id = VALUES(department_id)'
        );
        $stmt->execute([$userId, $employeeId, $departmentId]);

        AuditLog::log('link', 'rh_user_profile', $userId, null, [
            'employee_id' => $employeeId, 'department_id' => $departmentId,
        ]);

        Session::flash('success', 'Vínculo atualizado.');
        header('Location: index.php?m=rh&page=users');
        exit;
    }
}
