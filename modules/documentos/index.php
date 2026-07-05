<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  Sistema de Gestão Documental — Ponto de Entrada                    ║
 * ║  Todas as requisições passam por aqui via .htaccess                 ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

// ── 1. Carrega infraestrutura ──────────────────────────────────────────────
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/formula.php';
require_once __DIR__ . '/includes/analysis.php';
require_once __DIR__ . '/includes/indicator_templates.php';

// ── 2. Carrega models (funções globais de acesso a dados) ──────────────────
foreach (glob(MODELS_PATH . '/*.php') as $model_file) {
    require_once $model_file;
}

// ── 3. Sessão ──────────────────────────────────────────────────────────────
session_init();

// ── 4. Headers de segurança ────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// ── 5. Rota ────────────────────────────────────────────────────────────────
$url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
$segments = $url !== '' ? explode('/', $url) : [];

$page   = $segments[0] ?? 'dashboard';
$action = $segments[1] ?? 'index';
$param  = $segments[2] ?? null;

// Normaliza hífens para underscore: "change-password" -> "change_password"
$action = str_replace('-', '_', $action);
$page   = str_replace('-', '_', $page);

// ── 6. Mapa de rotas ───────────────────────────────────────────────────────
$routes = [
    ''                => ['dashboard',      'index'],
    'dashboard'       => ['dashboard',      'index'],
    'login'           => ['auth',           'login'],
    'process_login'   => ['auth',           'process_login'],
    'logout'          => ['auth',           'logout'],
    'forgot_password' => ['password_reset', 'forgot'],
    'reset_password'  => ['password_reset', 'reset'],
    'documents'       => ['documents',      $action],
    'indicators'      => ['indicators',     $action],
    'notifications'   => ['notifications',  $action],
    'admin'           => ['admin',          $action],
    'profile'         => ['profile',        $action],
    'api'             => ['api',            $action],
    'reports'         => ['reports',        $action],
];

if (!isset($routes[$page])) {
    http_response_code(404);
    require VIEWS_PATH . '/layouts/404.php';
    exit;
}

$controller_file   = $routes[$page][0];
$controller_action = $routes[$page][1];

// ── 7. Controller ──────────────────────────────────────────────────────────
$controller_path = CONTROLLERS_PATH . '/' . $controller_file . '.php';

if (!file_exists($controller_path)) {
    http_response_code(500);
    if (APP_DEBUG) die('Controller não encontrado: ' . $controller_path);
    die('Erro interno do servidor.');
}

require_once $controller_path;

// ── 8. Ação ────────────────────────────────────────────────────────────────
$function_name = $controller_file . '_' . $controller_action;

if (!function_exists($function_name)) {
    http_response_code(404);
    if (APP_DEBUG) die('Ação não encontrada: ' . $function_name . '()');
    require VIEWS_PATH . '/layouts/404.php';
    exit;
}

try {
    $function_name($param);
} catch (Throwable $ex) {
    log_error('route:' . $page . '/' . $action, $ex);
    http_response_code(500);
    if (APP_DEBUG) {
        echo '<pre>' . e((string) $ex) . '</pre>';
    } else {
        echo 'Ocorreu um erro interno. Tente novamente ou contate o administrador.';
    }
}
