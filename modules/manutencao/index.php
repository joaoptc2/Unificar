<?php
/**
 * ============================================================
 * MÓDULO MANUTENÇÃO — ponto de entrada
 *
 * Chamado pelo front controller da plataforma:
 *   /index.php?m=manutencao&page=<nome>
 *
 * O núcleo já autenticou (exceto páginas públicas declaradas no
 * manifesto), verificou o acesso ao módulo e definiu MODULE_SLUG,
 * MODULE_PATH, MODULE_URL e $GLOBALS['MODULE_ROLE'].
 * ============================================================
 */

require __DIR__ . '/config.php';

// Páginas públicas (sem login) — precisam bater com is_public do manifesto
$publicPages = ['anonymous-os', 'qr-scan', 'track-os'];

// Obter página solicitada
$page = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['page'] ?? 'dashboard'));

// Login/registro/logout locais foram removidos — são do núcleo
if (in_array($page, ['login', 'register'], true)) {
    core_redirect('index.php?m=auth&a=login');
}
if ($page === 'logout') {
    core_redirect('index.php?m=auth&a=logout');
}

// Verificação de autenticação (redundante ao núcleo, mantida por segurança)
if (!in_array($page, $publicPages, true) && !isLoggedIn()) {
    core_redirect('index.php?m=auth&a=login');
}

// Exportações CSV/impressão (antigo export.php da raiz do sistema)
if ($page === 'export') {
    requireLogin();
    require __DIR__ . '/export.php';
    exit;
}

// Mapa de páginas válidas
$validPages = [
    'dashboard'      => 'pages/dashboard.php',
    'equipment'      => 'pages/equipment.php',
    'service-orders' => 'pages/service_orders.php',
    'stock'          => 'pages/stock.php',
    'admin'          => 'pages/admin.php',
    'maintenance'    => 'pages/maintenance.php',
    'calibration'    => 'pages/calibration.php',
    'cleaning'       => 'pages/cleaning.php',
    'technicians'    => 'pages/technicians.php',
    'notifications'  => 'pages/notifications.php',
    'indicators'     => 'pages/indicators.php',
    'anonymous-os'   => 'pages/anonymous_os.php',
    'qr-scan'        => 'pages/qr_scan.php',
    'qr-locations'   => 'pages/qr_locations.php',
    'track-os'       => 'pages/track_os.php',
    'anvisa-report'  => 'pages/anvisa_report.php',
    'heatmap'        => 'pages/heatmap.php',
    'calendar'       => 'pages/calendar.php',
    'inspections'    => 'pages/inspections.php',
    'search'         => 'pages/search.php',
];

$file = $validPages[$page] ?? null;

if ($file && file_exists(__DIR__ . '/' . $file)) {
    require __DIR__ . '/' . $file;
} else {
    // Fallback para dashboard
    if (isLoggedIn()) {
        require __DIR__ . '/pages/dashboard.php';
    } else {
        core_redirect('index.php?m=auth&a=login');
    }
}
