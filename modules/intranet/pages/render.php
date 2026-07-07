<?php
/**
 * INTRANET — renderização de documentos no papel timbrado.
 *   page=view&id=N[&v=X]   visualização interna (requer documents.view)
 *   page=print&id=N[&v=X]  exportar PDF (imprime automaticamente)
 *   page=preview&id=L      pré-visualização de um layout (requer layouts.view)
 *   page=public&token=T    cópia pública (sem login; doc publicado e público)
 */

declare(strict_types=1);

use Core\DB;

$mode = (string) ($_GET['page'] ?? 'view');

// ---------------- Pré-visualização de layout ----------------
if ($mode === 'preview') {
    core_require('layouts.view');
    $layout = DB::queryOne('SELECT * FROM intra_layouts WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
    if (!$layout) {
        Core\Layout::renderError(404, 'Layout não encontrado.');
        exit;
    }
    $sample = '<h1>Documento de exemplo</h1><p>Este é um texto de demonstração para conferir o papel timbrado, '
        . 'as margens e o tamanho da página deste layout.</p><p>' . str_repeat('Conteúdo de exemplo. ', 80) . '</p>'
        . '<h2>Seção 2</h2><p>' . str_repeat('Mais conteúdo de exemplo. ', 120) . '</p>';
    intra_render_page([
        'title'        => 'Pré-visualização — ' . $layout['name'],
        'layout'       => $layout,
        'content_html' => $sample,
        'meta'         => ['title' => 'Documento de exemplo', 'author' => core_user()['name'] ?? '', 'version' => 1],
        'toolbar'      => '<strong>Pré-visualização do layout: ' . core_e($layout['name']) . '</strong>'
            . '<span class="spacer"></span>'
            . '<button onclick="window.print()">Testar impressão/PDF</button>'
            . '<a href="' . MODULE_URL . '&page=layouts">Voltar</a>',
    ]);
    exit;
}

// ---------------- Cópia pública ----------------
if ($mode === 'public') {
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['token'] ?? ''));
    $doc   = $token ? DB::queryOne(
        "SELECT * FROM intra_documents WHERE public_token = ? AND is_public = 1 AND status = 'published'",
        [$token]
    ) : null;
    if (!$doc) {
        http_response_code(404);
        Core\Layout::renderBare('Documento não disponível',
            '<div class="text-center"><h1 class="h5">Documento não disponível</h1>'
            . '<p class="text-muted">O link é inválido ou o documento não está mais público.</p></div>');
        exit;
    }
    $layout = intra_find_layout((int) $doc['layout_id']);
    $author = DB::queryOne('SELECT name FROM users WHERE id = ?', [$doc['updated_by'] ?? $doc['created_by']]);
    intra_render_page([
        'title'        => (string) $doc['title'],
        'layout'       => $layout,
        'content_html' => (string) $doc['content_html'],
        'meta'         => [
            'title'   => $doc['title'],
            'author'  => $author['name'] ?? '',
            'date'    => date('d/m/Y', strtotime((string) $doc['updated_at'])),
            'version' => $doc['current_version'],
        ],
        'toolbar'      => '<strong>' . core_e($doc['title']) . '</strong><span class="spacer"></span>'
            . '<button onclick="window.print()">Salvar em PDF / Imprimir</button>',
        'autoprint'    => !empty($_GET['pdf']),
    ]);
    exit;
}

// ---------------- Visualização interna / exportar PDF ----------------
core_require($mode === 'print' ? 'documents.export' : 'documents.view');

$doc = intra_find_document((int) ($_GET['id'] ?? 0));
if (!$doc) {
    Core\Layout::renderError(404, 'Documento não encontrado.');
    exit;
}

// Versão específica do histórico (acompanhamento das edições)
$title   = (string) $doc['title'];
$content = (string) $doc['content_html'];
$version = (int) $doc['current_version'];
$layoutId = $doc['layout_id'] !== null ? (int) $doc['layout_id'] : null;

$reqVersion = (int) ($_GET['v'] ?? 0);
if ($reqVersion > 0 && $reqVersion !== $version) {
    core_require('history.view');
    $vRow = DB::queryOne('SELECT * FROM intra_document_versions WHERE document_id = ? AND version = ?', [$doc['id'], $reqVersion]);
    if ($vRow) {
        $title    = (string) $vRow['title'];
        $content  = (string) $vRow['content_html'];
        $version  = (int) $vRow['version'];
        $layoutId = $vRow['layout_id'] !== null ? (int) $vRow['layout_id'] : $layoutId;
    }
}

$layout = intra_find_layout($layoutId);
if (!$layout) {
    Core\Layout::renderError(500, 'Nenhum layout cadastrado — crie um em Intranet > Layouts.');
    exit;
}
$author = DB::queryOne('SELECT name FROM users WHERE id = ?', [$doc['updated_by'] ?? $doc['created_by']]);

$toolbar = '<strong>' . core_e($title) . '</strong> <span style="opacity:.8">v' . $version . '</span>'
    . ($version !== (int) $doc['current_version'] ? ' <span style="background:#ffc107;color:#000;border-radius:4px;padding:2px 8px;font-size:12px">versão do histórico</span>' : '')
    . '<span class="spacer"></span>';
if (core_can('documents.export')) {
    $toolbar .= '<button onclick="window.print()">Salvar em PDF / Imprimir</button>';
}
if (core_can('documents.edit') && $version === (int) $doc['current_version']) {
    $toolbar .= '<a href="' . MODULE_URL . '&page=editor&id=' . (int) $doc['id'] . '">Editar</a>';
}
$toolbar .= '<a href="' . MODULE_URL . '&page=documents">Voltar</a>';

intra_render_page([
    'title'        => $title,
    'layout'       => $layout,
    'content_html' => $content,
    'meta'         => [
        'title'   => $title,
        'author'  => $author['name'] ?? '',
        'date'    => date('d/m/Y', strtotime((string) $doc['updated_at'])),
        'version' => $version,
    ],
    'toolbar'   => $toolbar,
    'autoprint' => $mode === 'print',
]);
