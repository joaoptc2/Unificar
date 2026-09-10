<?php
/**
 * MÓDULO INTRANET — helpers compartilhados.
 */

declare(strict_types=1);

use Core\DB;

/**
 * Compatibilidade: as funções intra_* delegam ao motor compartilhado
 * Core\DocLayout (layouts agora são cadastrados em Administração →
 * Layouts de documentos e usados também pelo módulo Documentos).
 */
function intra_page_sizes(): array
{
    return Core\DocLayout::pageSizes();
}

function intra_page_dims(string $size, string $orientation): array
{
    return Core\DocLayout::dims($size, $orientation);
}

function intra_find_document(int $id): ?array
{
    return DB::queryOne('SELECT * FROM intra_documents WHERE id = ?', [$id]);
}

function intra_find_layout(?int $id): ?array
{
    return Core\DocLayout::findOrDefault($id);
}

/** @return array<int, array<string, mixed>> */
function intra_active_layouts(?string $kind = null): array
{
    return Core\DocLayout::active($kind);
}

function intra_sanitize_html(string $html): string
{
    // Sanitizador por lista de permissão (DOM) do núcleo — Core\HtmlSanitizer.
    return Core\DocLayout::sanitizeHtml($html);
}

function intra_placeholders(string $html, array $layout, array $meta): string
{
    return Core\DocLayout::placeholders($html, $layout, $meta);
}

/**
 * Renderiza a página de documento (visualização/impressão/pública).
 * $opts: title, content_html, layout(array), meta(array), toolbar(html),
 *        autoprint(bool), cover(array|null), font_family, font_size.
 */
function intra_render_page(array $opts): void
{
    Core\DocLayout::render($opts);
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

/**
 * Valida os campos de layout do formulário do editor (layout de página,
 * layout de capa opcional, fonte e tamanho restritos ao layout de página).
 * @return array{layout_id:?int, cover_layout_id:?int, font_family:?string, font_size:?string, cover_html:?string}
 */
function intra_collect_layout_fields(array $post): array
{
    $layoutId = (int) ($post['layout_id'] ?? 0) ?: null;
    $layout   = $layoutId ? Core\DocLayout::find($layoutId) : null;
    if (!$layout || empty($layout['active'])) {
        $layout   = Core\DocLayout::findOrDefault(null);
        $layoutId = $layout ? (int) $layout['id'] : null;
    }

    $coverId = (int) ($post['cover_layout_id'] ?? 0) ?: null;
    $cover   = $coverId ? Core\DocLayout::find($coverId) : null;
    if (!$cover || empty($cover['active']) || !in_array($cover['kind'] ?? 'both', ['cover', 'both'], true)) {
        $coverId = null;
    }

    $font = trim((string) ($post['font_family'] ?? ''));
    $size = trim((string) ($post['font_size'] ?? ''));
    if ($font !== '' && !in_array($font, Core\DocLayout::fontsOf($layout), true)) {
        $font = '';
    }
    if ($size !== '' && !in_array($size, Core\DocLayout::sizesOf($layout), true)) {
        $size = '';
    }

    $coverHtml = $coverId ? intra_sanitize_html((string) ($post['cover_html'] ?? '')) : '';

    return [
        'layout_id'       => $layoutId,
        'cover_layout_id' => $coverId,
        'font_family'     => $font !== '' ? $font : null,
        'font_size'       => $size !== '' ? $size : null,
        'cover_html'      => trim(strip_tags($coverHtml)) !== '' || str_contains($coverHtml, '<img') ? $coverHtml : null,
    ];
}

/**
 * Mapa JSON id → configuração do editor por layout (fontes/tamanhos
 * permitidos, largura da folha, margens) para o JS do formulário.
 */
function intra_layouts_editor_json(array $layouts): string
{
    $map = [];
    foreach ($layouts as $l) {
        $map[(int) $l['id']] = Core\DocLayout::editorConfig($l, ['name' => $l['name'], 'kind' => $l['kind'] ?? 'both']);
    }
    return (string) json_encode((object) $map, JSON_UNESCAPED_UNICODE);
}

/**
 * Bloco 'cover' para Core\DocLayout::renderHtml a partir de um registro
 * (documento ou versão): null quando não há capa.
 */
function intra_cover_for(array $row): ?array
{
    $coverId  = !empty($row['cover_layout_id']) ? (int) $row['cover_layout_id'] : null;
    $coverLay = $coverId ? Core\DocLayout::find($coverId) : null;
    $html     = trim((string) ($row['cover_html'] ?? ''));
    if ($coverLay === null && $html === '') {
        return null;
    }
    return ['layout' => $coverLay, 'html' => $html];
}
