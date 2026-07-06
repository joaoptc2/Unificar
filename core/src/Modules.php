<?php

declare(strict_types=1);

namespace Core;

/**
 * Registro de módulos. Cada módulo vive em modules/<slug>/ e possui um
 * manifesto module.php que retorna:
 *
 *  [
 *    'slug'        => 'chat',
 *    'name'        => 'Comunicação',
 *    'icon'        => 'bi-chat-dots',           // Bootstrap Icons
 *    'description' => '...',
 *    'roles'       => ['admin' => 'Administrador', ...], // maior → menor
 *    'admin_role'  => 'admin',  // papel dado a admins globais
 *    'entry'       => 'index.php',
 *    'menu'        => fn (string $role): array => [...], // sidebar
 *    'is_public'   => fn (array $get): bool => false,    // rotas sem login
 *  ]
 */
final class Modules
{
    private static ?array $manifests = null;

    /** @return array<string, array> slug => manifesto (ordenado) */
    public static function all(): array
    {
        if (self::$manifests !== null) {
            return self::$manifests;
        }

        self::$manifests = [];
        foreach (glob(MODULES_PATH . '/*/module.php') ?: [] as $file) {
            $manifest = require $file;
            if (!is_array($manifest) || empty($manifest['slug'])) {
                continue;
            }
            $manifest['path'] = dirname($file);
            self::$manifests[$manifest['slug']] = $manifest;
        }

        // Ordem/ativação definidas na tabela modules (quando o BD existe)
        try {
            $rows = DB::query('SELECT slug, sort_order, active FROM modules');
            $meta = array_column($rows, null, 'slug');
            foreach (self::$manifests as $slug => &$m) {
                $m['sort_order'] = (int) ($meta[$slug]['sort_order'] ?? 999);
                $m['active']     = (bool) ($meta[$slug]['active'] ?? true);
            }
            unset($m);
            uasort(self::$manifests, fn ($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999));
        } catch (\Throwable) {
            // instalador ainda não rodou — segue com defaults
        }

        return self::$manifests;
    }

    public static function manifest(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        $m = self::manifest($slug);
        return $m !== null && ($m['active'] ?? true);
    }

    /** Módulos visíveis no menu superior para o usuário logado. */
    public static function forUser(int $userId): array
    {
        $result = [];
        foreach (self::all() as $slug => $manifest) {
            if (!($manifest['active'] ?? true)) {
                continue;
            }
            if (Perms::hasAny($userId, $slug)) {
                $result[$slug] = $manifest;
            }
        }
        return $result;
    }
}
