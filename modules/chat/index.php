<?php
/**
 * Módulo Comunicação (chat) — entry/roteador.
 *
 * Executado pelo front controller da plataforma (/index.php?m=chat&...),
 * que já iniciou a sessão, autenticou o usuário (Core\Auth), verificou o
 * acesso ao módulo e definiu:
 *   MODULE_SLUG, MODULE_PATH, MODULE_URL e as micropermissões do usuário
 *   (consultadas via core_can()/core_require()).
 *
 * O módulo é SOMENTE chat: canais, mensagens diretas, threads, reações,
 * anexos, menções, fixados, favoritos e busca. As antigas funções
 * (tarefas, reuniões, calendário, equipes, processos, enquetes, painel,
 * exportações e configurações visuais) foram descontinuadas — as rotas
 * correspondentes redirecionam para o chat com um aviso.
 */

// Caminho raiz do módulo para o código legado (views, config, autoloader).
if (!defined('CHAT_PATH')) {
    define('CHAT_PATH', __DIR__);
}

// Configuração própria do módulo (upload, poll_interval, paginação...)
$config = require CHAT_PATH . '/config/app.php';
date_default_timezone_set($config['timezone'] ?? 'America/Sao_Paulo');

// Autoloader das classes globais do módulo (helpers, models, controllers)
require CHAT_PATH . '/app/helpers/Autoloader.php';

$page   = trim($_GET['page'] ?? 'chat');
$action = trim($_GET['action'] ?? 'index');

$page   = preg_replace('/[^a-zA-Z0-9_-]/', '', $page);
$action = preg_replace('/[^a-zA-Z0-9_-]/', '', $action);

// Login/registro/logout saem do módulo — núcleo cuida (?m=auth).
// Perfil do usuário também é do núcleo.
if ($page === 'profile') {
    core_redirect('index.php?m=auth&a=profile');
}
if ($page === 'login' || $page === 'auth') {
    core_redirect('index.php?m=chat&page=chat');
}

// Funções descontinuadas → volta ao chat com aviso.
$discontinued = ['tasks', 'teams', 'meetings', 'calendar', 'processes', 'polls', 'export'];
if (in_array($page, $discontinued, true)) {
    \Core\Flash::set('warning', 'Função descontinuada: o módulo Comunicação agora é somente chat.');
    core_redirect('index.php?m=chat&page=chat');
}

$routes = [
    'chat'      => 'ChatController',
    'channels'  => 'ChannelController',
    'search'    => 'SearchController',
    'admin'     => 'AdminController',
    'api'       => 'ApiController',
];

if (!isset($routes[$page])) {
    \Core\Layout::renderError(404, 'Página não encontrada no módulo Comunicação.');
    exit;
}

// Configuração (categorias/emojis) fica na Administração central: qualquer
// GET em page=admin abre o painel central; os POSTs continuam aqui.
if ($page === 'admin' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $tab = in_array($action, ['categories', 'emojis'], true) ? $action : '';
    if (in_array($action, ['index', 'settings', 'audit', 'export', 'updateSettings', 'doExport', 'generateExport'], true)) {
        \Core\Flash::set('warning', 'Função descontinuada: o módulo Comunicação agora é somente chat.');
    }
    core_redirect(core_admin_url('chat', $tab));
}

$controllerName = $routes[$page];
$controller     = new $controllerName();

if (!method_exists($controller, $action) || !is_callable([$controller, $action])
    || str_starts_with($action, '__')) {
    $action = 'index';
}

if (!method_exists($controller, $action)) {
    \Core\Layout::renderError(404, 'Ação não encontrada no módulo Comunicação.');
    exit;
}

$controller->$action();
