<?php
/**
 * ProfileController — permite que o usuário logado altere seus dados
 * (nome, e-mail, senha). Não permite alterar o próprio role/ativo —
 * essas operações continuam restritas ao módulo de usuários.
 */
class ProfileController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requireLogin();

        $stmt = $this->db->prepare('SELECT id, name, email, role, last_login FROM users WHERE id = ?');
        $stmt->execute([Session::userId()]);
        $user = $stmt->fetch();

        if (!$user) {
            Auth::logout();
            header('Location: index.php?page=login');
            exit;
        }

        $pageTitle = 'Meu Perfil';
        $page = 'profile';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/profile/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId   = Session::userId();
        $name     = Sanitize::post('name');
        $email    = Sanitize::email($_POST['email'] ?? '');
        $current  = $_POST['current_password']  ?? '';
        $new      = $_POST['new_password']      ?? '';
        $confirm  = $_POST['confirm_password']  ?? '';

        if (empty($name) || empty($email)) {
            Session::flash('error', 'Nome e e-mail são obrigatórios.');
            header('Location: index.php?page=profile');
            exit;
        }

        // E-mail único.
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            Session::flash('error', 'Este e-mail já está em uso por outro usuário.');
            header('Location: index.php?page=profile');
            exit;
        }

        // Carrega dados atuais do usuário para validar senha atual.
        $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Auth::logout();
            header('Location: index.php?page=login');
            exit;
        }

        $changePassword = ($new !== '' || $confirm !== '');
        if ($changePassword) {
            if ($current === '' || !password_verify($current, $user['password'])) {
                Session::flash('error', 'Senha atual incorreta.');
                header('Location: index.php?page=profile');
                exit;
            }
            if (strlen($new) < 8) {
                Session::flash('error', 'A nova senha deve ter no mínimo 8 caracteres.');
                header('Location: index.php?page=profile');
                exit;
            }
            if ($new !== $confirm) {
                Session::flash('error', 'A confirmação da senha não confere.');
                header('Location: index.php?page=profile');
                exit;
            }
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $this->db->prepare('UPDATE users SET name=?, email=?, password=? WHERE id=?');
            $stmt->execute([$name, $email, $hash, $userId]);
            AuditLog::log('profile_password_change', 'users', (int)$userId);
        } else {
            $stmt = $this->db->prepare('UPDATE users SET name=?, email=? WHERE id=?');
            $stmt->execute([$name, $email, $userId]);
            AuditLog::log('profile_update', 'users', (int)$userId);
        }

        // Atualiza dados da sessão.
        Session::set('user_name', $name);
        Session::set('user_email', $email);

        Session::flash('success', 'Perfil atualizado com sucesso.');
        header('Location: index.php?page=profile');
        exit;
    }
}
