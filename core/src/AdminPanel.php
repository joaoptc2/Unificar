<?php

declare(strict_types=1);

namespace Core;

/**
 * Painéis de configuração dos módulos dentro da Administração central.
 *
 * Cada módulo pode declarar no manifesto (module.php):
 *
 *   'admin' => [
 *       'label' => 'Documentos',            // opcional (padrão: name)
 *       'icon'  => 'bi-file-earmark-text',  // opcional
 *       'entry' => 'admin.php',             // arquivo do módulo que despacha a aba
 *       'tabs'  => [
 *           'sectors'    => ['label' => 'Setores',    'icon' => 'bi-diagram-3', 'perm' => 'sectors.view'],
 *           'categories' => ['label' => 'Categorias', 'icon' => 'bi-tags',      'perm' => ['categories.view']],
 *       ],
 *   ],
 *
 * Regra de acesso: administrador global vê tudo; os demais veem as abas
 * cujo 'perm' (uma chave ou lista "qualquer uma") eles possuem no módulo.
 * A administração central passa a ser acessível a quem tem ao menos uma
 * aba de módulo, mesmo sem ser administrador global (as telas do núcleo —
 * usuários, grupos, módulos, configurações, auditoria — continuam
 * exclusivas dos administradores globais).
 *
 * A URL é index.php?m=admin&a=module&slug=<módulo>&tab=<aba>[&action=...].
 * O núcleo prepara o contexto do módulo (MODULE_SLUG, MODULE_PATH,
 * MODULE_URL, MODULE_PERMS) e inclui o 'entry'; a tela do módulo é
 * renderizada pelo Core\Layout já dentro do "chrome" da administração
 * (Layout::embed), sem alterações nas views do módulo.
 */
final class AdminPanel
{
    /** @return array<string, array> tabKey => definição (somente as acessíveis) */
    public static function tabsFor(int $userId, string $slug): array
    {
        $manifest = Modules::manifest($slug);
        $def      = $manifest['admin'] ?? null;
        if (!$manifest || !($manifest['active'] ?? true) || !$def || empty($def['tabs'])) {
            return [];
        }
        $isAdmin = self::isGlobalAdmin($userId);
        $out = [];
        foreach ((array) $def['tabs'] as $key => $tab) {
            $perms = (array) ($tab['perm'] ?? []);
            $ok = $isAdmin;
            if (!$ok) {
                foreach ($perms as $p) {
                    if (Perms::can($userId, $slug, (string) $p)) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                $out[(string) $key] = (array) $tab;
            }
        }
        return $out;
    }

    /** @return array<string, array{manifest: array, tabs: array}> módulos com painel acessível */
    public static function modulesFor(int $userId): array
    {
        $out = [];
        foreach (Modules::all() as $slug => $manifest) {
            if (empty($manifest['admin'])) {
                continue;
            }
            $tabs = self::tabsFor($userId, $slug);
            if ($tabs !== []) {
                $out[$slug] = ['manifest' => $manifest, 'tabs' => $tabs];
            }
        }
        return $out;
    }

    /** Pode abrir a administração central (global ou algum painel de módulo)? */
    public static function canAccess(int $userId): bool
    {
        if (self::isGlobalAdmin($userId)) {
            return true;
        }
        if (self::canManageLayouts($userId)) {
            return true;
        }
        return self::modulesFor($userId) !== [];
    }

    /**
     * Pode gerenciar os layouts de documentos (papel timbrado)? Admin global
     * ou quem tem 'layouts.view' em qualquer módulo que declare esse recurso.
     */
    public static function canManageLayouts(int $userId, string $action = 'view'): bool
    {
        if (self::isGlobalAdmin($userId)) {
            return true;
        }
        foreach (Modules::all() as $slug => $manifest) {
            if (isset($manifest['permissions']['layouts']) && Perms::can($userId, $slug, 'layouts.' . $action)) {
                return true;
            }
        }
        return false;
    }

    public static function url(string $slug, string $tab = '', array $extra = []): string
    {
        $params = ['a' => 'module', 'slug' => $slug];
        if ($tab !== '') {
            $params['tab'] = $tab;
        }
        return core_module_url('admin', array_merge($params, $extra));
    }

    private static function isGlobalAdmin(int $userId): bool
    {
        return Perms::isGlobalAdmin($userId);
    }
}
