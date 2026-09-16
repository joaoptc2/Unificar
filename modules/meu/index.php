<?php
/**
 * MÓDULO MEU ESPAÇO — roteador.
 * O núcleo já autenticou, verificou o acesso ao módulo e definiu
 * MODULE_SLUG/MODULE_PATH/MODULE_URL e $GLOBALS['MODULE_PERMS'].
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['page'] ?? 'hoje')));

$routes = [
    'hoje'    => 'pages/hoje.php',
    'agenda'  => 'pages/agenda.php',
    'tarefas' => 'pages/tarefas.php',
    'notas'        => 'pages/notas.php',
    'solicitacoes' => 'pages/solicitacoes.php',
    'formularios'  => 'pages/formularios.php',
    'formulario'   => 'pages/formulario.php',
];

$file = $routes[$page] ?? null;
if ($file === null || !is_file(__DIR__ . '/' . $file)) {
    Core\Layout::renderError(404, 'Página não encontrada no Meu espaço.');
    exit;
}

require __DIR__ . '/' . $file;
