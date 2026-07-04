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
            $role    = Access::roleFor((int) $user['id'], $moduleSlug);
            $sidebar = ($manifest['menu'])($role);
        }

        $ctx = [
            'title'       => $opts['title'] ?? ($manifest['name'] ?? core_config('app.name', 'Portal')),
            'content'     => $opts['content'] ?? '',
            'head'        => $opts['head'] ?? '',
            'scripts'     => $opts['scripts'] ?? '',
            'fluid'       => (bool) ($opts['fluid'] ?? false),
            'body_class'  => $opts['body_class'] ?? '',
            'active'      => $opts['active'] ?? '',
            'user'        => $user,
            'module_slug' => $moduleSlug,
            'manifest'    => $manifest,
            'sidebar'     => $sidebar,
            'modules_nav' => $user ? Modules::forUser((int) $user['id']) : [],
            'unread'      => $user ? Notifications::unreadCount((int) $user['id']) : 0,
            'flash'       => Flash::pull(),
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
