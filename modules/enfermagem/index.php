<?php
/**
 * MÓDULO ENFERMAGEM — roteador.
 *
 * O núcleo já iniciou a sessão, autenticou, verificou o acesso ao módulo e
 * definiu MODULE_SLUG/MODULE_PATH/MODULE_URL e $GLOBALS['MODULE_PERMS'].
 * Cada página valida a própria micropermissão (core_require) logo no topo.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['page'] ?? 'painel')));

$routes = [
    'painel'    => 'pages/painel.php',
    'pacientes' => 'pages/pacientes.php',
    'paciente'  => 'pages/paciente.php',
    'editar'    => 'pages/editar.php',
    'roteiro'   => 'pages/roteiro.php',
    'relatorio' => 'pages/relatorio.php',
    'importar'  => 'pages/importar.php',
    'config'    => 'pages/config.php',
];

$file = $routes[$page] ?? null;
if ($file === null || !is_file(__DIR__ . '/' . $file)) {
    Core\Layout::renderError(404, 'Página não encontrada na Enfermagem.');
    exit;
}

require __DIR__ . '/' . $file;
