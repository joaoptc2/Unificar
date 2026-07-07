<?php
/**
 * MÓDULO INTRANET — helpers compartilhados.
 */

declare(strict_types=1);

use Core\DB;

/** Tamanhos de página suportados (largura × altura em mm, retrato). */
function intra_page_sizes(): array
{
    return [
        'A4'     => ['label' => 'A4 (210 × 297 mm)',      'w' => 210, 'h' => 297],
        'A3'     => ['label' => 'A3 (297 × 420 mm)',      'w' => 297, 'h' => 420],
        'A5'     => ['label' => 'A5 (148 × 210 mm)',      'w' => 148, 'h' => 210],
        'Letter' => ['label' => 'Carta (216 × 279 mm)',   'w' => 216, 'h' => 279],
        'Oficio' => ['label' => 'Ofício (216 × 330 mm)',  'w' => 216, 'h' => 330],
    ];
}

/** Dimensões efetivas [largura, altura] em mm, já considerando a orientação. */
function intra_page_dims(string $size, string $orientation): array
{
    $s = intra_page_sizes()[$size] ?? intra_page_sizes()['A4'];
    return $orientation === 'landscape' ? [$s['h'], $s['w']] : [$s['w'], $s['h']];
}

function intra_find_document(int $id): ?array
{
    return DB::queryOne('SELECT * FROM intra_documents WHERE id = ?', [$id]);
}

function intra_find_layout(?int $id): ?array
{
    if ($id) {
        $l = DB::queryOne('SELECT * FROM intra_layouts WHERE id = ?', [$id]);
        if ($l) {
            return $l;
        }
    }
    return DB::queryOne('SELECT * FROM intra_layouts WHERE is_default = 1 AND active = 1')
        ?? DB::queryOne('SELECT * FROM intra_layouts WHERE active = 1 ORDER BY id LIMIT 1');
}

/** @return array<int, array<string, mixed>> */
function intra_active_layouts(): array
{
    return DB::query('SELECT * FROM intra_layouts WHERE active = 1 ORDER BY is_default DESC, name');
}

/**
 * Sanitização básica do HTML do editor: remove scripts, iframes e
 * handlers on* (conteúdo pode ir para a página pública).
 */
function intra_sanitize_html(string $html): string
{
    $html = preg_replace('#<\s*(script|iframe|object|embed|form)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
    $html = preg_replace('#<\s*(script|iframe|object|embed|form)\b[^>]*/?\s*>#i', '', $html) ?? $html;
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/\shref\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\1/i', ' href="#"', $html) ?? $html;
    return $html;
}

/** Substitui os placeholders do cabeçalho/rodapé do layout. */
function intra_placeholders(string $html, array $layout, array $meta): string
{
    $logo = '';
    if (!empty($layout['logo_path'])) {
        $logo = '<img src="' . core_e(core_url($layout['logo_path'])) . '" alt="Logo" style="max-height:14mm">';
    }
    return strtr($html, [
        '{{logo}}'   => $logo,
        '{{org}}'    => core_e(Core\Settings::get('org_name', core_config('app.name', ''))),
        '{{titulo}}' => core_e($meta['title'] ?? ''),
        '{{autor}}'  => core_e($meta['author'] ?? ''),
        '{{data}}'   => core_e($meta['date'] ?? date('d/m/Y')),
        '{{versao}}' => core_e((string) ($meta['version'] ?? '1')),
    ]);
}

/**
 * Renderiza a página de documento (visualização/impressão/pública) como
 * HTML standalone com CSS @page do layout — o "Exportar PDF" imprime
 * esta página (Ctrl+P → Salvar como PDF), fiel ao papel timbrado.
 *
 * $opts: title, content_html, layout(array), meta(array p/ placeholders),
 *        toolbar(html interno, oculto na impressão), autoprint(bool)
 */
function intra_render_page(array $opts): void
{
    $layout = $opts['layout'];
    [$w, $h] = intra_page_dims((string) $layout['page_size'], (string) $layout['orientation']);
    $mt = (int) $layout['margin_top'];
    $mr = (int) $layout['margin_right'];
    $mb = (int) $layout['margin_bottom'];
    $ml = (int) $layout['margin_left'];
    $hh = (int) $layout['header_height'];
    $fh = (int) $layout['footer_height'];

    $meta       = $opts['meta'] ?? [];
    $headerHtml = intra_placeholders((string) ($layout['header_html'] ?? ''), $layout, $meta);
    $footerHtml = intra_placeholders((string) ($layout['footer_html'] ?? ''), $layout, $meta);
    $repeatHead = $hh > 0 && $headerHtml !== '';
    $repeatFoot = $fh > 0 && $footerHtml !== '';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= core_e($opts['title']) ?></title>
<style>
    @page {
        size: <?= $w ?>mm <?= $h ?>mm;
        margin: <?= $mt ?>mm <?= $mr ?>mm <?= $mb ?>mm <?= $ml ?>mm;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1a1a1a; background: #e9edf1; }

    /* --------- folha na tela (emula o papel) --------- */
    .sheet {
        width: <?= $w ?>mm;
        min-height: <?= $h ?>mm;
        margin: 16px auto;
        padding: <?= $mt ?>mm <?= $mr ?>mm <?= $mb ?>mm <?= $ml ?>mm;
        background: #fff;
        box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    .doc-content { line-height: 1.5; }
    .doc-content img { max-width: 100%; }
    .doc-content table { border-collapse: collapse; width: 100%; }
    .doc-content td, .doc-content th { border: 1px solid #999; padding: 4px 6px; }
    .doc-header-flow { margin-bottom: 6mm; }
    .doc-footer-flow { margin-top: 8mm; }

    /* --------- barra de ações (some na impressão) --------- */
    .intra-toolbar {
        position: sticky; top: 0; z-index: 10;
        background: #0d5c8f; color: #fff;
        padding: 8px 14px; display: flex; gap: 8px; align-items: center;
        font-size: 14px;
    }
    .intra-toolbar a, .intra-toolbar button {
        color: #fff; background: rgba(255,255,255,.15); border: 0;
        padding: 5px 12px; border-radius: 6px; text-decoration: none;
        font-size: 13px; cursor: pointer; font-family: inherit;
    }
    .intra-toolbar a:hover, .intra-toolbar button:hover { background: rgba(255,255,255,.28); }
    .intra-toolbar .spacer { flex: 1; }

    @media print {
        body { background: #fff; }
        .intra-toolbar { display: none !important; }
        .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        <?php if ($repeatHead): ?>
        .doc-header-fixed { position: fixed; top: 0; left: 0; right: 0; height: <?= $hh ?>mm; overflow: hidden; }
        .doc-body-pad-top { padding-top: <?= $hh + 3 ?>mm; }
        <?php endif; ?>
        <?php if ($repeatFoot): ?>
        .doc-footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: <?= $fh ?>mm; overflow: hidden; }
        .doc-body-pad-bottom { padding-bottom: <?= $fh + 3 ?>mm; }
        <?php endif; ?>
    }
    /* na tela, cabeçalho/rodapé repetidos aparecem no fluxo normal */
    @media screen {
        .doc-header-fixed { min-height: <?= max($hh, 1) ?>mm; margin-bottom: 6mm; }
        .doc-footer-fixed { min-height: <?= max($fh, 1) ?>mm; margin-top: 8mm; }
    }
    <?= (string) ($layout['custom_css'] ?? '') ?>
</style>
</head>
<body>
<?php if (!empty($opts['toolbar'])): ?>
    <div class="intra-toolbar"><?= $opts['toolbar'] ?></div>
<?php endif; ?>
<div class="sheet">
    <?php if ($headerHtml !== ''): ?>
        <div class="<?= $repeatHead ? 'doc-header-fixed' : 'doc-header-flow' ?>"><?= $headerHtml ?></div>
    <?php endif; ?>
    <div class="doc-content <?= $repeatHead ? 'doc-body-pad-top' : '' ?> <?= $repeatFoot ? 'doc-body-pad-bottom' : '' ?>">
        <?= $opts['content_html'] ?>
    </div>
    <?php if ($footerHtml !== ''): ?>
        <div class="<?= $repeatFoot ? 'doc-footer-fixed' : 'doc-footer-flow' ?>"><?= $footerHtml ?></div>
    <?php endif; ?>
</div>
<?php if (!empty($opts['autoprint'])): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
    <?php
}

/** Wrapper de renderização das telas internas no layout unificado. */
function intra_view(string $title, string $content, string $active, string $head = '', string $scripts = ''): void
{
    Core\Layout::render([
        'title'   => $title,
        'content' => $content,
        'active'  => $active,
        'head'    => '<link rel="stylesheet" href="' . core_asset('intranet/style.css') . '">' . $head,
        'scripts' => $scripts,
    ]);
}
