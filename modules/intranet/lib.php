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
