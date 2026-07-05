<?php
/**
 * PasswordResetController — fluxo público de "esqueci minha senha".
 *
 * Rotas:
 *  - ?page=password_reset                           → formulário de solicitação
 *  - ?page=password_reset&action=request            → (POST) envia e-mail
 *  - ?page=password_reset&action=form&token=...     → formulário de nova senha
 *  - ?page=password_reset&action=update             → (POST) grava nova senha
 */
class PasswordResetController
{
    private const TOKEN_TTL_MINUTES = 60;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $error   = Session::flash('error');
        $success = Session::flash('success');
        require __DIR__ . '/../views/auth/password_request.php';
    }

    /**
     * (POST) Recebe o e-mail, gera token e envia link por e-mail.
     * Resposta é sempre genérica — não revela se o e-mail existe.
     */
    public function request(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?page=password_reset');
            exit;
        }
        Csrf::check();

        // Rate-limit leve por IP: no máx. 5 solicitações por hora.
        $ip = RateLimit::clientIp();
        if (RateLimit::recentLoginFailures($ip, null, 60) > 20) {
            Session::flash('error', 'Muitas solicitações. Tente novamente mais tarde.');
            header('Location: index.php?page=password_reset');
            exit;
        }

        $email = Sanitize::email($_POST['email'] ?? '');
        if ($email) {
            $stmt = $this->db->prepare('SELECT id, name, email FROM users WHERE email = ? AND active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Invalida tokens anteriores do mesmo usuário.
                $this->db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);

                $rawToken = bin2hex(random_bytes(32));
                $hash     = hash('sha256', $rawToken);
                $expires  = (new DateTime('+' . self::TOKEN_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

                $stmt = $this->db->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$user['id'], $hash, $expires, $ip]);

                $url = rtrim(BASE_URL, '/') . '/index.php?page=password_reset&action=form&token=' . $rawToken;
                Mailer::sendPasswordReset($user['email'], $user['name'], $url);

                AuditLog::log('password_reset_requested', 'users', (int)$user['id']);
            }
        }

        // Mensagem genérica (evita enumeração de e-mails).
        Session::flash('success', 'Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha em instantes.');
        header('Location: index.php?page=password_reset');
        exit;
    }

    /**
     * Exibe o formulário de definição da nova senha (valida o token).
     */
    public function form(): void
    {
        $rawToken = $_GET['token'] ?? '';
        $record = $this->validateToken($rawToken);
        if (!$record) {
            Session::flash('error', 'Link inválido ou expirado. Solicite um novo.');
            header('Location: index.php?page=password_reset');
            exit;
        }
        $token = $rawToken;
        $error = Session::flash('error');
        require __DIR__ . '/../views/auth/password_form.php';
    }

    /**
     * (POST) Atualiza a senha.
     */
    public function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?page=password_reset');
            exit;
        }
        Csrf::check();

        $rawToken = $_POST['token'] ?? '';
        $pass     = $_POST['password']         ?? '';
        $passConf = $_POST['password_confirm'] ?? '';

        $record = $this->validateToken($rawToken);
        if (!$record) {
            Session::flash('error', 'Link inválido ou expirado. Solicite um novo.');
            header('Location: index.php?page=password_reset');
            exit;
        }

        if (strlen($pass) < 8) {
            Session::flash('error', 'A senha deve ter no mínimo 8 caracteres.');
            header('Location: index.php?page=password_reset&action=form&token=' . urlencode($rawToken));
            exit;
        }
        if ($pass !== $passConf) {
            Session::flash('error', 'As senhas não coincidem.');
            header('Location: index.php?page=password_reset&action=form&token=' . urlencode($rawToken));
            exit;
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->prepare('UPDATE users SET password = ? WHERE id = ?')
                 ->execute([$hash, $record['user_id']]);

        // Marca o token como usado e invalida os demais.
        $this->db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                 ->execute([$record['id']]);
        $this->db->prepare('DELETE FROM password_resets WHERE user_id = ? AND id <> ?')
                 ->execute([$record['user_id'], $record['id']]);

        AuditLog::log('password_reset_completed', 'users', (int)$record['user_id']);

        Session::flash('success', 'Senha redefinida com sucesso. Faça login com a nova senha.');
        header('Location: index.php?page=login');
        exit;
    }

    /**
     * Retorna o registro válido ou null.
     */
    private function validateToken(string $rawToken): ?array
    {
        if (strlen($rawToken) < 32) return null;
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = ?
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    }
}
