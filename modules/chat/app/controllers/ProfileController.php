<?php

class ProfileController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Show current user profile.
     */
    public function index(): void
    {
        Auth::requireLogin();

        $userId = Session::userId();
        $user   = User::find($userId);

        // User teams
        $teams = Team::userTeams($userId);

        // Channels count
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM channel_members WHERE user_id = ?');
        $stmt->execute([$userId]);
        $channelsCount = (int) $stmt->fetchColumn();

        $data = [
            'pageTitle'     => 'Meu Perfil',
            'user'          => $user,
            'teams'         => $teams,
            'channelsCount' => $channelsCount,
        ];

        View::render('profile/index', $data);
    }

    /**
     * Show profile edit form.
     */
    public function edit(): void
    {
        Auth::requireLogin();

        $user = User::find(Session::userId());

        $data = [
            'pageTitle' => 'Editar Perfil',
            'user'      => $user,
        ];

        View::render('profile/form', $data);
    }

    /**
     * Process profile update POST request.
     */
    public function update(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId = Session::userId();
        $user   = User::find($userId);

        $name       = Sanitize::string($_POST['name'] ?? '');
        $email      = Sanitize::email($_POST['email'] ?? '');
        $title      = Sanitize::string($_POST['title'] ?? '');
        $department = Sanitize::string($_POST['department'] ?? '');
        $phone      = Sanitize::string($_POST['phone'] ?? '');
        $timezone   = Sanitize::string($_POST['timezone'] ?? '');

        // --- Validation ---------------------------------------------------

        $errors = [];

        if ($name === '') {
            $errors[] = 'O nome é obrigatório.';
        }

        if ($email === '') {
            $errors[] = 'Informe um e-mail válido.';
        }

        // Check email uniqueness (excluding current user)
        if ($email !== '' && $email !== $user['email']) {
            $existing = User::findByEmail($email);
            if ($existing) {
                $errors[] = 'Este e-mail já está em uso.';
            }
        }

        if (!empty($errors)) {
            Session::flash('error', implode('<br>', $errors));
            header('Location: index.php?page=profile&action=edit');
            exit;
        }

        // --- Update data --------------------------------------------------

        $updateData = [
            'name'       => $name,
            'email'      => $email,
            'title'      => $title,
            'department' => $department,
            'phone'      => $phone,
            'timezone'   => $timezone,
        ];

        // Handle avatar upload
        if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload = Upload::handle('avatar', 'avatars');

            if ($upload['success']) {
                // Delete old avatar if exists
                if (!empty($user['avatar'])) {
                    Upload::delete($user['avatar']);
                }
                $updateData['avatar'] = $upload['path'];
            } else {
                Session::flash('error', $upload['error']);
                header('Location: index.php?page=profile&action=edit');
                exit;
            }
        }

        User::update($userId, $updateData);

        // Refresh session data
        Session::set('user_name', $name);
        Session::set('user_email', $email);
        if (isset($updateData['avatar'])) {
            Session::set('user_avatar', $updateData['avatar']);
        }

        AuditLog::log('update', 'user', $userId, $user, $updateData);

        Session::flash('success', 'Perfil atualizado com sucesso.');
        header('Location: index.php?page=profile');
        exit;
    }

    /**
     * Update user status via AJAX POST.
     */
    public function updateStatus(): void
    {
        Auth::requireLogin();

        if (!Csrf::checkAjax()) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido.']);
            return;
        }

        $userId      = Session::userId();
        $statusText  = Sanitize::string($_POST['status_text'] ?? '');
        $statusEmoji = Sanitize::string($_POST['status_emoji'] ?? '');

        User::update($userId, [
            'status_text'  => $statusText,
            'status_emoji' => $statusEmoji,
        ]);

        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'status_text'  => $statusText,
            'status_emoji' => $statusEmoji,
        ]);
    }

    /**
     * Process password change POST request.
     */
    public function password(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId = Session::userId();
        $user   = User::find($userId);

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['password_confirm'] ?? '';

        // Verify current password
        if (!password_verify($currentPassword, $user['password'])) {
            Session::flash('error', 'Senha atual incorreta.');
            header('Location: index.php?page=profile&action=edit');
            exit;
        }

        // Validate new password
        if (strlen($newPassword) < 6) {
            Session::flash('error', 'A nova senha deve ter pelo menos 6 caracteres.');
            header('Location: index.php?page=profile&action=edit');
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            Session::flash('error', 'As senhas não coincidem.');
            header('Location: index.php?page=profile&action=edit');
            exit;
        }

        // Update password
        $this->db->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

        AuditLog::log('password_change', 'user', $userId);

        Session::flash('success', 'Senha alterada com sucesso.');
        header('Location: index.php?page=profile');
        exit;
    }
}
