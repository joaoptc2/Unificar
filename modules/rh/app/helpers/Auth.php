<?php
/**
 * Auth — controle de acesso (RBAC) do módulo RH.
 *
 * Autenticação (login/logout/2FA/reset) é responsabilidade do núcleo.
 * Este adaptador mantém a MESMA matriz de permissões legada, mas o papel
 * do usuário vem de $GLOBALS['MODULE_ROLE'] (RBAC central por módulo).
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
        // (announcements.view permite ler os comunicados internos, item
        // presente no menu do funcionário na plataforma unificada.)
        'funcionario' => [
            'my'            => ['view'],
            'announcements' => ['view'],
        ],
    ];

    /**
     * Exige que o utilizador esteja autenticado (delegado ao núcleo).
     */
    public static function requireLogin(): void
    {
        Core\Auth::requireLogin();
    }

    /**
     * Verifica se o utilizador tem permissão para a ação
     */
    public static function can(string $module, string $action): bool
    {
        $role = Session::userRole();
        if (!$role || $role === 'none') return false;

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
            header('Location: index.php?m=rh&page=dashboard');
            exit;
        }
    }

    /**
     * Verifica se o utilizador é admin (do módulo RH ou admin global,
     * que recebe automaticamente o admin_role do manifesto).
     */
    public static function isAdmin(): bool
    {
        return Session::userRole() === 'admin';
    }
}
