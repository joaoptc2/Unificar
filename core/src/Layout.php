<?php

declare(strict_types=1);

namespace Core;

/**
 * Layout padrão da plataforma:
 *  - menu superior fixo com o seletor de módulos (filtrado por permissão),
 *    sino de notificações unificado e menu do usuário;
 *  - menu lateral definido pelo módulo ativo (manifesto 'menu');
 *  - área de conteúdo.
 *
 * Os módulos renderizam seu HTML e chamam Layout::render([...]).
 */
final class Layout
{
    /**
     * Modo "embutido": as próximas renderizações usam o chrome da
     * administração central (sidebar/aba ativa/cabeçalho de abas) em vez do
     * menu do módulo. Usado pelos painéis de configuração dos módulos
     * (index.php?m=admin&a=module&slug=...), sem alterar as views do módulo.
     *
     * @var array{sidebar?: array, active?: string, prepend?: string, title?: string}|null
     */
    private static ?array $embed = null;

    public static function embed(?array $opts): void
    {
        self::$embed = $opts;
    }

    public static function embedded(): bool
    {
        return self::$embed !== null;
    }

    /**
     * @param array{
     *   title?: string, content: string, module?: ?string, active?: string,
     *   head?: string, scripts?: string, fluid?: bool, sidebar?: ?array,
     *   body_class?: string
     * } $opts
     */
    public static function render(array $opts): void
    {
        $user       = Auth::user();
        $moduleSlug = $opts['module'] ?? (defined('MODULE_SLUG') ? MODULE_SLUG : null);
        $manifest   = $moduleSlug ? Modules::manifest($moduleSlug) : null;

        // Sidebar: override explícito > manifesto do módulo > nenhuma
        $sidebar = $opts['sidebar'] ?? null;
        if ($sidebar === null && $manifest && isset($manifest['menu']) && is_callable($manifest['menu']) && $user) {
            // O closure 'menu' recebe um verificador de micropermissões
            $userId  = (int) $user['id'];
            $can     = fn (string $permKey): bool => Perms::can($userId, $moduleSlug, $permKey);
            $sidebar = ($manifest['menu'])($can);

            // Painel de configuração do módulo na administração central:
            // link padronizado no fim do menu lateral de todos os módulos.
            if (self::$embed === null && !empty($manifest['admin']) && AdminPanel::tabsFor($userId, $moduleSlug) !== []) {
                $sidebar   = is_array($sidebar) ? $sidebar : [];
                $sidebar[] = [
                    'heading' => 'Configuração',
                    'items'   => [[
                        'label' => 'Configurações do módulo',
                        'url'   => AdminPanel::url($moduleSlug),
                        'icon'  => 'bi-gear',
                        'key'   => 'module-settings',
                    ]],
                ];
            }
        }

        $title        = $opts['title'] ?? ($manifest['name'] ?? core_config('app.name', 'Portal'));
        $content      = $opts['content'] ?? '';
        $active       = $opts['active'] ?? '';
        $topbarActive = $moduleSlug;

        // Painel de módulo dentro da administração central
        if (self::$embed !== null) {
            $sidebar      = self::$embed['sidebar'] ?? $sidebar;
            $active       = self::$embed['active'] ?? $active;
            $content      = (self::$embed['prepend'] ?? '') . $content;
            $title        = (self::$embed['title'] ?? 'Administração') . ' — ' . $title;
            $topbarActive = null;
        }

        $isGlobalAdmin = $user && Auth::isGlobalAdmin();
        $ctx = [
            'title'       => $title,
            'content'     => $content,
            'head'        => $opts['head'] ?? '',
            'scripts'     => $opts['scripts'] ?? '',
            'fluid'       => (bool) ($opts['fluid'] ?? false),
            'body_class'  => $opts['body_class'] ?? '',
            'active'      => $active,
            'user'        => $user,
            'module_slug' => $moduleSlug,
            'topbar_active' => $topbarActive,
            'manifest'    => $manifest,
            'sidebar'     => $sidebar,
            'modules_nav' => $user ? Modules::forUser((int) $user['id']) : [],
            'unread'      => $user ? Notifications::unreadCount((int) $user['id']) : 0,
            'flash'       => Flash::pull(),
            'admin_link'  => $user && ($isGlobalAdmin || AdminPanel::canAccess((int) $user['id'])),
            'migrations_pending' => $isGlobalAdmin && Migrations::hasPending(),
        ];

        extract($ctx, EXTR_SKIP);
        require CORE_PATH . '/views/layout.php';
    }

    /** Página standalone (login, erro) — sem topbar/sidebar. */
    public static function renderBare(string $title, string $content): void
    {
        require CORE_PATH . '/views/bare.php';
    }

    public static function renderError(int $code, string $message): void
    {
        http_response_code($code);
        $titles = [403 => 'Acesso negado', 404 => 'Página não encontrada', 500 => 'Erro interno'];
        $title  = $titles[$code] ?? "Erro {$code}";

        if (Auth::check()) {
            ob_start(); ?>
            <div class="text-center py-5">
                <div class="display-1 text-secondary"><i class="bi bi-shield-lock"></i></div>
                <h1 class="h3 mt-3"><?= core_e($title) ?></h1>
                <p class="text-muted"><?= core_e($message) ?></p>
                <a class="btn btn-primary" href="<?= core_url('index.php') ?>"><i class="bi bi-house me-1"></i> Início</a>
            </div>
            <?php
            self::render(['title' => $title, 'content' => (string) ob_get_clean(), 'module' => null, 'sidebar' => null]);
        } else {
            self::renderBare($title, '<div class="text-center"><h1 class="h4">' . core_e($title) . '</h1><p class="text-muted">'
                . core_e($message) . '</p><a class="btn btn-primary" href="' . core_url('index.php') . '">Ir para o login</a></div>');
        }
    }
}
