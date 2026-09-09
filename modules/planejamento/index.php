<?php
/**
 * MÓDULO PLANEJAMENTO — roteador.
 * O núcleo já autenticou, verificou o acesso ao módulo e definiu
 * MODULE_SLUG/MODULE_PATH/MODULE_URL e $GLOBALS['MODULE_PERMS'].
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['page'] ?? 'dashboard')));

$routes = [
    'dashboard' => 'pages/dashboard.php',
    'plans'     => 'pages/plans.php',
    'boards'    => 'pages/boards.php',
    'diagrams'  => 'pages/diagrams.php',
    'templates' => 'pages/templates.php',
    'api'       => 'pages/api.php',
];

$file = $routes[$page] ?? null;
if ($file === null || !is_file(__DIR__ . '/' . $file)) {
    Core\Layout::renderError(404, 'Página não encontrada no módulo Planejamento.');
    exit;
}

require __DIR__ . '/' . $file;
