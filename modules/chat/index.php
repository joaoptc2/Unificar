<?php
/**
 * Módulo Comunicação (chat) — entry/roteador.
 *
 * Executado pelo front controller da plataforma (/index.php?m=chat&...),
 * que já iniciou a sessão, autenticou o usuário (Core\Auth), verificou o
 * acesso ao módulo e definiu:
 *   MODULE_SLUG, MODULE_PATH, MODULE_URL e as micropermissões do usuário
 *   (consultadas via core_can()/core_require()).
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

$routes = [
    'chat'      => 'ChatController',
    'channels'  => 'ChannelController',
    'tasks'     => 'TaskController',
    'teams'     => 'TeamController',
    'meetings'  => 'MeetingController',
    'processes' => 'ProcessController',
    'search'    => 'SearchController',
    'admin'     => 'AdminController',
    'polls'     => 'PollController',
    'api'       => 'ApiController',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    echo '<h1>Página não encontrada</h1>';
    echo '<p><a href="index.php?m=chat&page=chat">Voltar ao chat</a></p>';
    exit;
}

$controllerName = $routes[$page];

if (!class_exists($controllerName)) {
    http_response_code(500);
    echo '<h1>Erro interno</h1>';
    exit;
}

$controller = new $controllerName();

if (!method_exists($controller, $action)) {
    $action = 'index';
}

if (!method_exists($controller, $action)) {
    http_response_code(404);
    echo '<h1>Ação não encontrada</h1>';
    exit;
}

$controller->$action();
