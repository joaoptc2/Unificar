<?php
/**
 * LAYOUT DO MÓDULO — adaptador para o Core\Layout da plataforma.
 *
 * As pages continuam usando o padrão legado:
 *   $pageTitle = 'Equipamentos';
 *   ob_start();
 *   // conteúdo da página ...
 *   $content = ob_get_clean();
 *   require __DIR__ . '/layout.php';
 *
 * Aqui o conteúdo capturado é entregue ao layout unificado
 * (topbar de módulos + sidebar do manifesto + notificações do núcleo).
 * O flash local do módulo é renderizado dentro do conteúdo.
 */

$currentPage = $page ?? ($_GET['page'] ?? 'dashboard');

// Flash do módulo (mantido local, renderizado no topo do conteúdo)
$flash = getFlash();
$flashHtml = '';
if ($flash) {
    $map = ['success' => 'success', 'error' => 'danger', 'danger' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $typeClass = $map[$flash['type']] ?? 'info';
    $flashHtml = '<div class="alert alert-' . e($typeClass) . ' alert-dismissible fade show shadow-sm" role="alert">'
        . e($flash['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>'
        . '</div>';
}

Core\Layout::render([
    'title'   => $pageTitle ?? APP_NAME,
    'content' => $flashHtml . ($content ?? ''),
    'active'  => $currentPage,
    'head'    => '<link rel="stylesheet" href="' . core_asset('manutencao/style.css') . '">',
    'scripts' => '<script src="' . core_asset('manutencao/app.js') . '"></script>',
]);
