<?php
/**
 * Adaptador de autenticação — delega ao núcleo (Core\Auth / Core\Access).
 *
 * - Login/logout/registro saem do módulo (telas do núcleo em ?m=auth).
 * - O papel do usuário neste módulo vem de $GLOBALS['MODULE_ROLE'] e é
 *   aplicado sobre a MESMA matriz de permissões do sistema legado.
 * - user() devolve a linha global de `users` ENRIQUECIDA com a presença
 *   do chat (chat_presence) e aliases legados (title, department, status).
 */
class Auth
{
    private static ?array $cachedUser = null;

    private static array $permissions = [
        'admin' => '*',
        'manager' => [
            'chat'      => ['view', 'create', 'edit', 'delete'],
            'channels'  => ['view', 'create', 'edit', 'delete', 'archive'],
            'tasks'     => ['view', 'create', 'edit', 'delete', 'assign'],
            'teams'     => ['view', 'create', 'edit', 'delete'],
            'meetings'  => ['view', 'create', 'edit', 'delete'],
            'processes' => ['view', 'create', 'edit', 'delete'],
            'members'   => ['view', 'manage'],
            'search'    => ['view'],
            'profile'   => ['view', 'edit'],
        ],
        'member' => [
            'chat'      => ['view', 'create', 'edit'],
            'channels'  => ['view', 'create'],
            'tasks'     => ['view', 'create', 'edit'],
            'teams'     => ['view'],
            'meetings'  => ['view', 'create', 'edit'],
            'processes' => ['view'],
            'search'    => ['view'],
            'profile'   => ['view', 'edit'],
        ],
    ];

    public static function requireLogin(): void
    {
        if (!\Core\Auth::check()) {
            core_redirect('index.php?m=auth&a=login');
        }
    }

    public static function requirePermission(string $module, string $action): void
    {
        if (!self::can($module, $action)) {
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }
    }

    public static function can(string $module, string $action): bool
    {
        $role = Session::userRole();
        if (!$role || $role === 'none') return false;
        if ((self::$permissions[$role] ?? null) === '*') return true;

        $perms = self::$permissions[$role][$module] ?? [];
        return in_array($action, $perms, true);
    }

    public static function isAdmin(): bool
    {
        return Session::userRole() === 'admin';
    }

    public static function isManager(): bool
    {
        return in_array(Session::userRole(), ['admin', 'manager'], true);
    }

    /**
     * Usuário logado (linha global `users`) + presença do chat.
     * Mantém as chaves que o legado espera: status, status_text,
     * status_emoji, timezone, last_seen_at, title, department, role.
     */
    public static function user(): ?array
    {
        if (!\Core\Auth::check()) return null;

        if (self::$cachedUser === null) {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT u.*,
                        u.job_title AS title,
                        u.sector    AS department,
                        COALESCE(p.status, "offline") AS status,
                        p.status_text, p.status_emoji,
                        COALESCE(p.timezone, "America/Sao_Paulo") AS timezone,
                        p.last_seen_at
                 FROM users u
                 LEFT JOIN chat_presence p ON p.user_id = u.id
                 WHERE u.id = ? LIMIT 1'
            );
            $stmt->execute([Session::userId()]);
            $user = $stmt->fetch() ?: null;
            if ($user) {
                $user['role'] = Session::userRole(); // papel no módulo
            }
            self::$cachedUser = $user;
        }
        return self::$cachedUser;
    }
}
