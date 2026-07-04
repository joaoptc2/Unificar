<?php

declare(strict_types=1);

namespace Core;

/**
 * RBAC por módulo. Cada módulo declara seus próprios níveis no manifesto
 * (modules/<slug>/module.php, chave 'roles' — ordenados do maior para o
 * menor privilégio). 'none' = sem acesso.
 *
 * Administradores globais (users.is_admin) recebem automaticamente o
 * papel declarado em 'admin_role' no manifesto do módulo.
 */
final class Access
{
    /** @var array<int, array<string, string>> cache por usuário */
    private static array $cache = [];

    public static function roleFor(int $userId, string $module): string
    {
        if (!isset(self::$cache[$userId])) {
            $rows = DB::query('SELECT module_slug, role FROM user_module_access WHERE user_id = ?', [$userId]);
            self::$cache[$userId] = array_column($rows, 'role', 'module_slug');
        }

        $role = self::$cache[$userId][$module] ?? 'none';

        if ($role === 'none' && self::isGlobalAdmin($userId)) {
            $manifest = Modules::manifest($module);
            $role = $manifest['admin_role'] ?? 'admin';
        }
        return $role;
    }

    public static function has(int $userId, string $module): bool
    {
        return self::roleFor($userId, $module) !== 'none';
    }

    /** Define (ou remove, com 'none') o papel de um usuário em um módulo. */
    public static function set(int $userId, string $module, string $role, ?int $grantedBy): void
    {
        if ($role === 'none' || $role === '') {
            DB::execute('DELETE FROM user_module_access WHERE user_id = ? AND module_slug = ?', [$userId, $module]);
        } else {
            DB::execute(
                'INSERT INTO user_module_access (user_id, module_slug, role, granted_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), granted_by = VALUES(granted_by), granted_at = NOW()',
                [$userId, $module, $role, $grantedBy]
            );
        }
        unset(self::$cache[$userId]);
    }

    /** @return array<string, string> module_slug => role */
    public static function allFor(int $userId): array
    {
        $result = [];
        foreach (Modules::all() as $slug => $manifest) {
            $role = self::roleFor($userId, $slug);
            if ($role !== 'none') {
                $result[$slug] = $role;
            }
        }
        return $result;
    }

    /** Interrompe com 403 se o usuário não tem acesso ao módulo. */
    public static function requireModule(string $module): void
    {
        Auth::requireLogin();
        if (!self::has((int) Auth::id(), $module)) {
            http_response_code(403);
            Layout::renderError(403, 'Você não tem acesso a este módulo. Solicite ao administrador.');
            exit;
        }
    }

    private static function isGlobalAdmin(int $userId): bool
    {
        if (Auth::id() === $userId) {
            return Auth::isGlobalAdmin();
        }
        $row = DB::queryOne('SELECT is_admin FROM users WHERE id = ?', [$userId]);
        return (bool) ($row['is_admin'] ?? false);
    }
}
