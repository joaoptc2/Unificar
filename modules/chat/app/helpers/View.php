<?php
/**
 * Adaptador de views — o layout legado (header/footer/chat_layout) foi
 * substituído pelo layout unificado da plataforma (Core\Layout): topbar +
 * sidebar do núcleo em todas as telas, inclusive no chat. As views do
 * módulo produzem apenas o HTML do conteúdo.
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

    /**
     * Tela do chat: mesmo layout do núcleo (topbar + sidebar), com o
     * conteúdo "fluid" ocupando toda a área abaixo da topbar. A lista de
     * canais/DMs é um painel esquerdo DENTRO do conteúdo.
     */
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

    /** Nome exibido para o chat: nome da organização (ou do app). */
    public static function appName(): string
    {
        return (string) \Core\Settings::get('org_name', core_config('app.name', 'Comunicação'));
    }

    /** CSS do módulo (Bootstrap/Icons já vêm do layout do núcleo). */
    private static function head(): string
    {
        return '<link rel="stylesheet" href="' . core_asset('chat/style.css') . '">';
    }

    /**
     * JS do módulo (somente na tela do chat). O layout do núcleo já injeta
     * as metatags base-url, csrf-token, user-id e module.
     */
    private static function scripts(bool $chat): string
    {
        if (!$chat) {
            return '';
        }
        $cfg  = require CHAT_PATH . '/config/app.php';
        $meta = '<meta name="chat-poll-interval" content="' . (int) ($cfg['poll_interval'] ?? 2000) . '">'
              . '<meta name="chat-poll-idle" content="' . (int) ($cfg['poll_interval_idle'] ?? 8000) . '">';
        return $meta . '<script src="' . core_asset('chat/chat.js') . '"></script>';
    }

    /** Chave do item ativo do menu lateral (manifesto). */
    private static function activeKey(): string
    {
        $page = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['page'] ?? 'chat'));

        return match ($page) {
            'channels' => 'channels',
            'search'   => 'search',
            'admin'    => 'module-settings',
            default    => 'chat',
        };
    }
}
