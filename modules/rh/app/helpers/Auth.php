<?php
/**
 * Autenticação e controle de acesso (RBAC)
 */
class Auth
{
    /**
     * Mapa de permissões por perfil
     * Formato: 'modulo' => ['ação1', 'ação2', ...]
     */
    private static array $permissions = [
        'admin' => [
            '*' => ['*'], // acesso total
        ],
        'rh' => [
            'dashboard'     => ['view'],
            'employees'     => ['view', 'create', 'edit', 'delete', 'export'],
            'documents'     => ['view', 'create', 'edit', 'delete'],
            'expirations'   => ['view', 'create', 'edit', 'delete', 'export'],
            'schedules'     => ['view', 'create', 'edit', 'delete'],
            'birthdays'     => ['view'],
            'recruitment'   => ['view', 'create', 'edit', 'delete'],
            'talent_pool'   => ['view', 'create', 'edit', 'delete', 'export'],
            'notifications' => ['view', 'delete'],
            'certificates'  => ['view', 'create', 'edit', 'delete'],
            'departments'   => ['view'],
            'positions'     => ['view'],
            'vacations'     => ['view', 'create', 'edit', 'delete'],
            'shifts'        => ['view', 'create', 'edit', 'delete'],
            'onboarding'    => ['view', 'create', 'edit', 'delete'],
            'announcements' => ['view', 'create', 'edit', 'delete'],
            'surveys'       => ['view', 'create', 'edit', 'delete'],
            'trainings'     => ['view', 'create', 'edit', 'delete'],
            'requests'      => ['view', 'edit'],
        ],
        'gestor' => [
            'dashboard'     => ['view'],
            'employees'     => ['view'],
            'documents'     => ['view'],
            'expirations'   => ['view'],
            'schedules'     => ['view', 'create', 'edit'],
            'birthdays'     => ['view'],
            'recruitment'   => ['view'],
            'talent_pool'   => ['view'],
            'notifications' => ['view'],
            'certificates'  => ['view'],
            'vacations'     => ['view', 'edit'],
            'shifts'        => ['view', 'create', 'edit'],
            'onboarding'    => ['view', 'edit'],
            'announcements' => ['view'],
            'surveys'       => ['view'],
            'trainings'     => ['view'],
            'requests'      => ['view'],
        ],
        'visualizador' => [
            'dashboard'     => ['view'],
            'employees'     => ['view'],
            'birthdays'     => ['view'],
            'notifications' => ['view'],
            'announcements' => ['view'],
        ],
        // Funcionário comum — acesso exclusivo ao portal "Minha Área".
        // Não tem permissão para dashboards de RH, listagens, etc.
        'funcionario' => [
            'my' => ['view'],
        ],
    ];

    /**
     * Tenta autenticar o utilizador.
     *
     * Retorna:
     *   - 'success'    → login completo, sessão preenchida.
     *   - '2fa'        → senha correta, mas o usuário tem 2FA ativo. O fluxo
     *                    deve redirecionar para a tela de challenge.
     *   - 'failed'     → credenciais inválidas.
     *
     * Registra a tentativa (sucesso ou falha) em `login_attempts` e no
     * audit_log. O chamador deve checar `Auth::isLoginLocked()` antes.
     */
    public static function attempt(string $email, string $password): string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, name, email, password, role, department_id, active
                              FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $ip = RateLimit::clientIp();

        if (!$user || !password_verify($password, $user['password'])) {
            RateLimit::recordLogin($ip, $email, false);
            AuditLog::log('login_failed', 'users', null, null, ['email' => $email]);
            return 'failed';
        }

        // Verifica 2FA.
        $stmt = $db->prepare('SELECT enabled FROM user_2fa WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $twoFactor = $stmt->fetch();

        if ($twoFactor && (int)$twoFactor['enabled'] === 1) {
            // Não loga ainda — guarda o ID em sessão temporária.
            session_regenerate_id(true);
            Session::set('2fa_pending_user_id', (int)$user['id']);
            Session::set('2fa_pending_email',   $user['email']);
            Session::set('2fa_pending_at',      time());
            return '2fa';
        }

        self::completeLoginInternal($user, $ip);
        return 'success';
    }

    /**
     * Conclui o login após validação de 2FA bem-sucedida.
     */
    public static function completePendingLogin(): bool
    {
        $userId = (int)Session::get('2fa_pending_user_id');
        if (!$userId) return false;

        // Janela de 5 min para concluir o desafio.
        $startedAt = (int)Session::get('2fa_pending_at');
        if ($startedAt > 0 && (time() - $startedAt) > 300) {
            Session::remove('2fa_pending_user_id');
            Session::remove('2fa_pending_email');
            Session::remove('2fa_pending_at');
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, name, email, role, department_id, active
                              FROM users WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Session::remove('2fa_pending_user_id');
            return false;
        }

        Session::remove('2fa_pending_user_id');
        Session::remove('2fa_pending_email');
        Session::remove('2fa_pending_at');

        self::completeLoginInternal($user, RateLimit::clientIp());
        return true;
    }

    private static function completeLoginInternal(array $user, string $ip): void
    {
        // Regenerar sessão ao fazer login
        session_regenerate_id(true);

        Session::set('user_id', (int)$user['id']);
        Session::set('user_name', $user['name']);
        Session::set('user_email', $user['email']);
        Session::set('user_role', $user['role']);
        Session::set('user_department_id', $user['department_id'] ?? null);
        Session::set('_created', time());

        // Atualizar último login
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $stmt->execute([$user['id']]);

        RateLimit::recordLogin($ip, $user['email'], true);
        AuditLog::log('login', 'users', (int)$user['id']);
    }

    /**
     * Verifica se o IP/e-mail atingiu o limite de tentativas falhas.
     * Retorna segundos restantes de bloqueio (0 = liberado).
     */
    public static function isLoginLocked(string $email = ''): int
    {
        $config = require __DIR__ . '/../../config/app.php';
        $max    = (int)($config['login_max_attempts'] ?? 5);
        $window = (int)($config['login_lockout_minutes'] ?? 15);
        if ($max <= 0) return 0;

        $ip = RateLimit::clientIp();
        $failures = RateLimit::recentLoginFailures($ip, $email ?: null, $window);
        if ($failures < $max) return 0;

        return RateLimit::loginLockoutRemaining($ip, $email ?: null, $window);
    }

    /**
     * Encerra a sessão do utilizador
     */
    public static function logout(): void
    {
        if (Session::userId()) {
            AuditLog::log('logout', 'users', Session::userId());
        }
        Session::destroy();
    }

    /**
     * Exige que o utilizador esteja autenticado
     */
    public static function requireLogin(): void
    {
        if (!Session::isLoggedIn()) {
            Session::flash('error', 'Você precisa estar logado para acessar esta página.');
            header('Location: index.php?page=login');
            exit;
        }
    }

    /**
     * Verifica se o utilizador tem permissão para a ação
     */
    public static function can(string $module, string $action): bool
    {
        $role = Session::userRole();
        if (!$role) return false;

        // Admin tem acesso total
        if ($role === 'admin') return true;

        $perms = self::$permissions[$role] ?? [];

        if (!isset($perms[$module])) return false;

        return in_array('*', $perms[$module]) || in_array($action, $perms[$module]);
    }

    /**
     * Exige permissão ou aborta
     */
    public static function requirePermission(string $module, string $action): void
    {
        self::requireLogin();
        if (!self::can($module, $action)) {
            http_response_code(403);
            Session::flash('error', 'Você não tem permissão para acessar este recurso.');
            header('Location: index.php?page=dashboard');
            exit;
        }
    }

    /**
     * Verifica se o utilizador é admin
     */
    public static function isAdmin(): bool
    {
        return Session::userRole() === 'admin';
    }
}
