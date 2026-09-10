<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  Módulo DOCUMENTOS — Ponto de Entrada                                ║
 * ║  Executado pelo front controller da plataforma:                      ║
 * ║  /index.php?m=documentos&url=pagina/acao                             ║
 * ║  O núcleo já resolveu: sessão, login, acesso ao módulo e             ║
 * ║  $GLOBALS['MODULE_PERMS'] (micropermissões, via core_can/            ║
 * ║  core_require); headers de segurança também são dele.                ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

// ── 1. Carrega infraestrutura ──────────────────────────────────────────────
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/formula.php';
require_once __DIR__ . '/includes/analysis.php';
require_once __DIR__ . '/includes/indicator_templates.php';

// ── 2. Carrega models (funções globais de acesso a dados) ──────────────────
foreach (glob(MODELS_PATH . '/*.php') as $model_file) {
    require_once $model_file;
}

// ── 3. Contexto de setor (primeiro acesso ao módulo nesta sessão) ──────────
if (is_logged_in()) {
    doc_sectors_ensure_loaded();
}

// ── 4. Rota ─────────────────────────────────────────────────────────────────
$url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
$segments = $url !== '' ? explode('/', $url) : [];

$page   = $segments[0] ?? 'dashboard';
$action = $segments[1] ?? 'index';
$param  = $segments[2] ?? null;

// Normaliza hífens para underscore: "switch-sector" -> "switch_sector"
$action = str_replace('-', '_', $action);
$page   = str_replace('-', '_', $page);

// Logout → núcleo (fluxos de login/senha saíram do módulo)
if ($page === 'logout') {
    core_redirect('index.php?m=auth&a=logout');
}

// ── 5. Mapa de rotas ────────────────────────────────────────────────────────
$routes = [
    ''              => ['dashboard',     'index'],
    'dashboard'     => ['dashboard',     $action],
    'documents'     => ['documents',     $action],
    'indicators'    => ['indicators',    $action],
    'notifications' => ['notifications', $action],
    'admin'         => ['admin',         $action],
    'profile'       => ['profile',       $action],
    'api'           => ['api',           $action],
    'reports'       => ['reports',       $action],
];

if (!isset($routes[$page])) {
    Core\Layout::renderError(404, 'Página não encontrada neste módulo.');
    exit;
}

$controller_file   = $routes[$page][0];
$controller_action = $routes[$page][1];

// ── 6. Controller ───────────────────────────────────────────────────────────
$controller_path = CONTROLLERS_PATH . '/' . $controller_file . '.php';

if (!file_exists($controller_path)) {
    http_response_code(500);
    if (APP_DEBUG) die('Controller não encontrado: ' . $controller_path);
    die('Erro interno do servidor.');
}

require_once $controller_path;

// ── 7. Ação ─────────────────────────────────────────────────────────────────
$function_name = $controller_file . '_' . $controller_action;

if (!function_exists($function_name)) {
    if (APP_DEBUG) {
        http_response_code(404);
        die('Ação não encontrada: ' . $function_name . '()');
    }
    Core\Layout::renderError(404, 'Página não encontrada neste módulo.');
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
