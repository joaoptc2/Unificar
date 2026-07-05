<?php

class AuthController
{
    /**
     * Show login form.
     */
    public function login(): void
    {
        $data = [
            'pageTitle' => 'Login',
        ];

        View::renderRaw('auth/login', $data);
    }

    /**
     * Process login POST request.
     */
    public function doLogin(): void
    {
        Csrf::check();

        $email    = Sanitize::email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            Session::flash('error', 'Preencha todos os campos.');
            header('Location: index.php?page=login');
            exit;
        }

        if (Auth::attempt($email, $password)) {
            AuditLog::log('login', 'user', Session::userId());
            header('Location: index.php?page=chat');
            exit;
        }

        Session::flash('error', 'E-mail ou senha inválidos.');
        header('Location: index.php?page=login');
        exit;
    }

    /**
     * Show registration form.
     */
    public function register(): void
    {
        $data = [
            'pageTitle' => 'Criar Conta',
        ];

        View::renderRaw('auth/register', $data);
    }

    /**
     * Process registration POST request.
     */
    public function doRegister(): void
    {
        Csrf::check();

        $name            = Sanitize::string($_POST['name'] ?? '');
        $email           = Sanitize::email($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // --- Validation ---------------------------------------------------

        $errors = [];

        if ($name === '') {
            $errors[] = 'O nome é obrigatório.';
        }

        if ($email === '') {
            $errors[] = 'Informe um e-mail válido.';
        }

        if (strlen($password) < 6) {
            $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'As senhas não coincidem.';
        }

        // Check email uniqueness
        if ($email !== '' && User::findByEmail($email)) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }

        if (!empty($errors)) {
            Session::flash('error', implode('<br>', $errors));
            header('Location: index.php?page=register');
            exit;
        }

        // --- Create user --------------------------------------------------

        $db = Database::getInstance();

        $userId = User::insert([
            'name'      => $name,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
            'role'      => 'member',
            'status'    => 'online',
            'is_active' => 1,
        ]);

        // Auto-login
        Session::set('user_id', $userId);
        Session::set('user_name', $name);
        Session::set('user_email', $email);
        Session::set('user_role', 'member');
        Session::set('user_avatar', null);

        // Add to #geral channel
        $geral = Channel::findBySlug('geral');
        if ($geral) {
            Channel::addMember((int) $geral['id'], $userId);
        }

        AuditLog::log('register', 'user', $userId);

        header('Location: index.php?page=chat');
        exit;
    }

    /**
     * Logout current user and redirect.
     */
    public function logout(): void
    {
        AuditLog::log('logout', 'user', Session::userId());
        Auth::logout();

        header('Location: index.php?page=login');
        exit;
    }
}
