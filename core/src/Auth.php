<?php

declare(strict_types=1);

namespace Core;

/**
 * Autenticação única (SSO interno da plataforma).
 *
 * Ordem de autenticação:
 *  1. Usuário local (users.password_hash, bcrypt);
 *  2. Moodle (config moodle.enabled) — valida credenciais no Moodle e
 *     auto-provisiona/atualiza o usuário local.
 *
 * Variáveis de sessão definidas no login (superset usado pelos módulos):
 *  user_id, user_name, user_email, user_avatar, hospital_id, hospital_name,
 *  is_global_admin, login_time.
 */
final class Auth
{
    private static ?array $cachedUser = null;

    /** @return array{ok: bool, error?: string, user?: array} */
    public static function attempt(string $login, string $password): array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return ['ok' => false, 'error' => 'Informe usuário e senha.'];
        }

        // CPF digitado com pontuação → usuário é o CPF só com dígitos
        // (login padrão dos funcionários criados pelo módulo RH).
        if (preg_match('/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/', $login)) {
            $login = preg_replace('/\D/', '', $login);
        }

        if (RateLimit::isBlocked($login)) {
            return ['ok' => false, 'error' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'];
        }

        $user = DB::queryOne(
            'SELECT * FROM users WHERE (email = ? OR username = ?) LIMIT 1',
            [$login, $login]
        );

        // 1) Autenticação local
        if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
            if (!(int) $user['active']) {
                RateLimit::record($login, false);
                return ['ok' => false, 'error' => 'Usuário desativado. Procure o administrador.'];
            }
            RateLimit::record($login, true);
            self::establish($user);
            return ['ok' => true, 'user' => $user];
        }

        // 2) Moodle (auto-provisionamento)
        if (Config::get('moodle.enabled', false)) {
            $moodle = MoodleAuth::attempt($login, $password);
            if ($moodle['ok']) {
                $user = self::provisionFromMoodle($moodle['user'], $user);
                if (!(int) $user['active']) {
                    return ['ok' => false, 'error' => 'Usuário desativado. Procure o administrador.'];
                }
                RateLimit::record($login, true);
                self::establish($user);
                return ['ok' => true, 'user' => $user];
            }
        }

        RateLimit::record($login, false);
        return ['ok' => false, 'error' => 'Credenciais inválidas.'];
    }

    /** Cria/atualiza usuário local a partir dos dados do Moodle. */
    private static function provisionFromMoodle(array $mUser, ?array $existing): array
    {
        // Tenta casar por moodle_user_id, depois por username/email
        $local = DB::queryOne('SELECT * FROM users WHERE moodle_user_id = ? LIMIT 1', [$mUser['id']])
            ?? $existing
            ?? DB::queryOne('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$mUser['username'], $mUser['email'] ?? '']);

        if ($local) {
            DB::execute(
                'UPDATE users SET moodle_user_id = ?, auth_source = IF(password_hash IS NULL, "moodle", auth_source),
                        name = COALESCE(NULLIF(?, ""), name), email = COALESCE(NULLIF(?, ""), email)
                 WHERE id = ?',
                [$mUser['id'], $mUser['fullname'] ?? '', $mUser['email'] ?? '', $local['id']]
            );
            return DB::queryOne('SELECT * FROM users WHERE id = ?', [$local['id']]);
        }

        $email = $mUser['email'] ?? ($mUser['username'] . '@moodle.local');
        DB::execute(
            'INSERT INTO users (name, username, email, password_hash, is_admin, active, auth_source, moodle_user_id)
             VALUES (?, ?, ?, NULL, 0, 1, "moodle", ?)',
            [$mUser['fullname'] ?: $mUser['username'], $mUser['username'], $email, $mUser['id']]
        );
        $id = DB::lastId();

        // Acessos padrão para usuários vindos do Moodle
        foreach ((array) Config::get('moodle.default_access', []) as $module => $role) {
            Access::set($id, (string) $module, (string) $role, null);
        }

        Audit::log('user.provision_moodle', 'users', (string) $id, 'Usuário criado via Moodle', null);
        return DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** Define as variáveis de sessão da plataforma. */
    public static function establish(array $user): void
    {
        Session::regenerate();

        $_SESSION['user_id']         = (int) $user['id'];
        $_SESSION['user_name']       = (string) $user['name'];
        $_SESSION['user_email']      = (string) $user['email'];
        $_SESSION['user_avatar']     = $user['avatar'] ?? null;
        $_SESSION['is_global_admin'] = (bool) $user['is_admin'];
        $_SESSION['login_time']      = time();

        // Compatibilidade com módulos multi-tenant legados (hospital único)
        $_SESSION['hospital_id']   = (int) Settings::get('default_hospital_id', '1');
        $_SESSION['hospital_name'] = (string) Settings::get('org_name', core_config('app.name', 'Portal'));

        DB::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        Audit::log('login', 'users', (string) $user['id'], null, (int) $user['id']);
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('logout', 'users', (string) self::id(), null, self::id());
        }
        self::$cachedUser = null;
        Session::destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /** Usuário completo (linha da tabela users), cacheado por request. */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        if (self::$cachedUser === null) {
            self::$cachedUser = DB::queryOne('SELECT * FROM users WHERE id = ? AND active = 1', [self::id()]);
            if (self::$cachedUser === null) {
                Session::destroy(); // usuário removido/desativado
            }
        }
        return self::$cachedUser;
    }

    public static function isGlobalAdmin(): bool
    {
        return self::check() && !empty($_SESSION['is_global_admin']);
    }

    public static function requireLogin(): void
    {
        if (!self::check() || self::user() === null) {
            $intended = $_SERVER['REQUEST_URI'] ?? '';
            $_SESSION['intended_url'] = $intended;
            core_redirect('index.php?m=auth&a=login');
        }
    }

    public static function requireGlobalAdmin(): void
    {
        self::requireLogin();
        if (!self::isGlobalAdmin()) {
            http_response_code(403);
            Layout::renderError(403, 'Acesso restrito aos administradores da plataforma.');
            exit;
        }
    }
}
