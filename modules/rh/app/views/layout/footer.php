<?php
/**
 * Footer de COMPATIBILIDADE — fecha o buffer aberto pelo header.php e
 * delega a renderização ao layout unificado (Core\Layout::render()).
 * Bootstrap 5.3 + Bootstrap Icons já são carregados pelo núcleo.
 */

// Modal da busca global (Ctrl+K) — parte do conteúdo do módulo.
$rhSearchModal = <<<'HTML'
<div class="modal fade" id="globalSearchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div class="input-group">
                    <span class="input-group-text border-end-0">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" id="globalSearchInput" class="form-control border-start-0"
                           placeholder="Buscar funcionário, vencimento, candidato, compromisso..." autocomplete="off">
                </div>
            </div>
            <div class="modal-body p-0" style="min-height:120px; max-height:60vh; overflow-y:auto;">
                <div id="globalSearchResults" class="p-3 text-muted small text-center">
                    Digite ao menos 2 caracteres para buscar.
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted">
                    Atalhos:
                    <kbd>Ctrl</kbd>+<kbd>K</kbd> para abrir,
                    <kbd>Esc</kbd> para fechar,
                    <kbd>&uarr;</kbd>/<kbd>&darr;</kbd> + <kbd>Enter</kbd> para navegar.
                </small>
            </div>
        </div>
    </div>
</div>
HTML;

$rhContent = (string)ob_get_clean() . $rhSearchModal;

$rhHead = '<link href="' . core_asset('rh/style.css') . '" rel="stylesheet">';
if (!empty($extraCss)) {
    foreach ((array)$extraCss as $css) {
        $rhHead .= "\n" . '<link href="' . $css . '" rel="stylesheet">';
    }
}

$rhScripts = '<script src="' . core_asset('rh/app.js') . '"></script>' . "\n"
           . '<script src="' . core_asset('rh/global-search.js') . '"></script>';
if (!empty($extraJs)) {
    foreach ((array)$extraJs as $js) {
        $rhScripts .= "\n" . '<script src="' . $js . '"></script>';
    }
}

Core\Layout::render([
    'title'   => $pageTitle ?? 'RH',
    'content' => $rhContent,
    'active'  => $page ?? '',
    'head'    => $rhHead,
    'scripts' => $rhScripts,
]);
