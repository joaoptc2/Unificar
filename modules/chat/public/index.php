<?php
/**
 * FRONT CONTROLLER — Roteamento principal
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    define('BASE_URL', $protocol . '://' . $host . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir));
}

$config = require BASE_PATH . '/config/app.php';
date_default_timezone_set($config['timezone'] ?? 'America/Sao_Paulo');

if ($config['debug'] ?? false) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/logs/php_errors.log');

if (!file_exists(BASE_PATH . '/config/.installed')) {
    if (file_exists(BASE_PATH . '/install.php')) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/install.php');
        exit;
    }
    die('Sistema não instalado. Acesse install.php para configurar.');
}

require BASE_PATH . '/app/helpers/Autoloader.php';

Session::start();

$page   = trim($_GET['page'] ?? 'chat');
$action = trim($_GET['action'] ?? 'index');

$page   = preg_replace('/[^a-zA-Z0-9_-]/', '', $page);
$action = preg_replace('/[^a-zA-Z0-9_-]/', '', $action);

$publicPages = ['login', 'auth'];

$routes = [
    'auth'      => 'AuthController',
    'login'     => 'AuthController',
    'chat'      => 'ChatController',
    'channels'  => 'ChannelController',
    'tasks'     => 'TaskController',
    'teams'     => 'TeamController',
    'meetings'  => 'MeetingController',
    'processes' => 'ProcessController',
    'profile'   => 'ProfileController',
    'search'    => 'SearchController',
    'admin'     => 'AdminController',
    'polls'     => 'PollController',
    'api'       => 'ApiController',
];

if ($page === 'login') {
    $page   = 'auth';
    $action = 'login';
}

if (!in_array($page, $publicPages, true)) {
    Auth::requireLogin();
}

if (!isset($routes[$page])) {
    http_response_code(404);
    echo '<h1>Página não encontrada</h1>';
    echo '<p><a href="index.php?page=chat">Voltar ao chat</a></p>';
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
