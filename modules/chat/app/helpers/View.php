<?php
/**
 * Adaptador de views — o layout legado (header/footer/chat_layout) foi
 * substituído pelo layout unificado da plataforma (Core\Layout).
 * As views do módulo produzem apenas o HTML do conteúdo.
 */
class View
{
    /** Página padrão dentro do layout do núcleo. */
    public static function render(string $template, array $data = []): void
    {
        \Core\Layout::render([
            'title'   => $data['pageTitle'] ?? 'Comunicação',
            'content' => self::capture($template, $data),
            'active'  => self::activeKey(),
            'head'    => self::head(),
            'scripts' => self::scripts(false),
        ]);
    }

    /** Tela full-screen do chat (fluid, com chat.js). */
    public static function renderChat(string $template, array $data = []): void
    {
        \Core\Layout::render([
            'title'      => $data['pageTitle'] ?? 'Chat',
            'content'    => self::capture($template, $data),
            'active'     => self::activeKey(),
            'head'       => self::head(),
            'scripts'    => self::scripts(true),
            'fluid'      => true,
            'body_class' => 'chat-app',
        ]);
    }

    /** Resposta parcial/crua (sem layout) — usada em AJAX. */
    public static function renderRaw(string $template, array $data = []): void
    {
        extract($data);
        require CHAT_PATH . '/app/views/' . $template . '.php';
    }

    public static function capture(string $template, array $data = []): string
    {
        extract($data);
        ob_start();
        require CHAT_PATH . '/app/views/' . $template . '.php';
        return (string) ob_get_clean();
    }

    public static function partial(string $template, array $data = []): void
    {
        extract($data);
        require CHAT_PATH . '/app/views/' . $template . '.php';
    }

    /** CSS do módulo (Bootstrap/Icons já vêm do layout do núcleo). */
    private static function head(): string
    {
        return '<link rel="stylesheet" href="' . core_asset('chat/style.css') . '">';
    }

    /**
     * JS do módulo. O layout do núcleo já injeta as metatags base-url,
     * csrf-token, user-id e module.
     */
    private static function scripts(bool $chat): string
    {
        $js = '<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>'
            . '<script src="' . core_asset('chat/app.js') . '"></script>';
        if ($chat) {
            $js .= '<script src="' . core_asset('chat/chat.js') . '"></script>';
        }
        return $js;
    }

    /** Chave do item ativo do menu lateral (manifesto). */
    private static function activeKey(): string
    {
        $page   = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['page'] ?? 'chat'));
        $action = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['action'] ?? 'index'));

        return match (true) {
            $page === 'tasks' && $action === 'my'          => 'tasks-my',
            $page === 'meetings' && $action === 'calendar' => 'meetings-calendar',
            $page === 'admin' && $action === 'categories'  => 'admin-categories',
            $page === 'admin' && $action === 'emojis'      => 'admin-emojis',
            $page === 'channels', $page === 'polls'        => 'chat',
            default                                        => $page,
        };
    }
}
