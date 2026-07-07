<?php
/**
 * MÓDULO INTRANET — roteador.
 * O núcleo já autenticou, verificou o acesso ao módulo (exceto page=public)
 * e definiu MODULE_SLUG/MODULE_PATH/MODULE_URL e $GLOBALS['MODULE_PERMS'].
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['page'] ?? 'documents')));

$routes = [
    'documents' => 'pages/documents.php',
    'editor'    => 'pages/editor.php',
    'history'   => 'pages/history.php',
    'layouts'   => 'pages/layouts.php',
    'view'      => 'pages/render.php',
    'print'     => 'pages/render.php',
    'preview'   => 'pages/render.php',
    'public'    => 'pages/render.php',
];

$file = $routes[$page] ?? null;
if ($file === null) {
    Core\Layout::renderError(404, 'Página não encontrada na Intranet.');
    exit;
}

require __DIR__ . '/' . $file;
