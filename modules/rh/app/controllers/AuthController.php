<?php
/**
 * Controller de Autenticação
 */
class AuthController
{
    public function index(): void
    {
        if (Session::isLoggedIn()) {
            header('Location: index.php?page=dashboard');
            exit;
        }
        $error = Session::flash('error');
        $success = Session::flash('success');
        require __DIR__ . '/../views/auth/login.php';
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?page=login');
            exit;
        }

        Csrf::check();

        $email    = Sanitize::email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            Session::flash('error', 'Preencha todos os campos.');
            header('Location: index.php?page=login');
            exit;
        }

        // Rate-limit: bloqueia após N tentativas falhas na janela configurada.
        $remaining = Auth::isLoginLocked($email);
        if ($remaining > 0) {
            $minutes = (int)ceil($remaining / 60);
            Session::flash('error',
                'Muitas tentativas falhas. Tente novamente em ' . $minutes . ' minuto(s).');
            header('Location: index.php?page=login');
            exit;
        }

        $result = Auth::attempt($email, $password);
        if ($result === 'success') {
            // Funcionários vão para o portal "Minha Área"; demais para o dashboard.
            $dest = Session::userRole() === 'funcionario' ? 'my' : 'dashboard';
            header('Location: index.php?page=' . $dest);
            exit;
        }
        if ($result === '2fa') {
            header('Location: index.php?page=two_factor&action=challenge');
            exit;
        }

        Session::flash('error', 'E-mail ou senha incorretos.');
        header('Location: index.php?page=login');
        exit;
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        Session::flash('success', 'Sessão encerrada com sucesso.');
        header('Location: index.php?page=login');
        exit;
    }
}
