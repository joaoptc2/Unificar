<?php
/**
 * Painel de configuração do módulo Comunicação na ADMINISTRAÇÃO CENTRAL.
 *
 * Incluído por Core\AdminPanel (index.php?m=admin&a=module&slug=chat&tab=...)
 * com MODULE_SLUG/MODULE_PATH/MODULE_URL, CORE_ADMIN_TAB e MODULE_PERMS já
 * definidos. O que for renderizado via Core\Layout aparece dentro do
 * "chrome" da administração (sidebar + abas). Abas: categories | emojis.
 *
 * Os formulários postam para ?m=chat&page=admin&action=... (AdminController),
 * que ao final redireciona de volta para core_admin_url('chat', <aba>).
 */

if (!defined('CHAT_PATH')) {
    define('CHAT_PATH', __DIR__);
}

$config = require CHAT_PATH . '/config/app.php';
date_default_timezone_set($config['timezone'] ?? 'America/Sao_Paulo');

require CHAT_PATH . '/app/helpers/Autoloader.php';

$tab = core_admin_tab() ?? 'categories';

$controller = new AdminController();

switch ($tab) {
    case 'emojis':
        $controller->emojis();
        break;

    case 'categories':
    default:
        $controller->categories();
        break;
}
