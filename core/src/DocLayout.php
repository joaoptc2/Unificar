<?php

declare(strict_types=1);

namespace Core;

/**
 * Motor compartilhado de LAYOUTS DE DOCUMENTOS (papel timbrado do hospital).
 *
 * Usado pelos módulos Documentos (edição de documentos no sistema),
 * Intranet (documentos institucionais) e RH (impressos, como o cartaz de
 * aniversariantes). A tabela continua sendo `intra_layouts` (compatível
 * com as instalações existentes) e o cadastro fica em
 * Administração → Layouts de documentos.
 *
 * Um layout define: tamanho/orientação da página, margens, cabeçalho e
 * rodapé (HTML com variáveis), altura do cabeçalho/rodapé repetidos,
 * imagem de FUNDO da página (PNG/JPG), modelo de CAPA (HTML + fundo
 * próprio), fontes e tamanhos de fonte permitidos no editor e a fonte/
 * tamanho padrão do texto.
 *
 * Renderização (renderHtml): página standalone com CSS @page no tamanho
 * exato; o "Exportar PDF" é a impressão do navegador (Salvar como PDF).
 * Técnica de impressão: @page sem margens + elementos fixos (fundo,
 * cabeçalho, rodapé) que se repetem em todas as páginas + tabela com
 * thead/tfoot "espaçadores" que reservam as margens e as áreas de
 * cabeçalho/rodapé em cada página. A capa é uma folha própria, opaca,
 * acima dos elementos fixos.
 */
final class DocLayout
{
    public const TABLE = 'intra_layouts';

    // ------------------------------------------------------------------
    // Catálogos
    // ------------------------------------------------------------------

    /** Tamanhos de página suportados (largura × altura em mm, retrato). */
    public static function pageSizes(): array
    {
        return [
            'A4'     => ['label' => 'A4 (210 × 297 mm)',     'w' => 210, 'h' => 297],
            'A3'     => ['label' => 'A3 (297 × 420 mm)',     'w' => 297, 'h' => 420],
            'A5'     => ['label' => 'A5 (148 × 210 mm)',     'w' => 148, 'h' => 210],
            'Letter' => ['label' => 'Carta (216 × 279 mm)',  'w' => 216, 'h' => 279],
            'Oficio' => ['label' => 'Ofício (216 × 330 mm)', 'w' => 216, 'h' => 330],
        ];
    }

    /** Dimensões efetivas [largura, altura] em mm, já considerando a orientação. */
    public static function dims(string $size, string $orientation): array
    {
        $s = self::pageSizes()[$size] ?? self::pageSizes()['A4'];
        return $orientation === 'landscape' ? [$s['h'], $s['w']] : [$s['w'], $s['h']];
    }

    /** Fontes oferecidas por padrão (todas seguras para impressão). */
    public static function defaultFonts(): array
    {
        return ['Arial', 'Helvetica', 'Calibri', 'Cambria', 'Times New Roman', 'Georgia',
                'Verdana', 'Tahoma', 'Trebuchet MS', 'Segoe UI', 'Garamond', 'Courier New'];
    }

    public static function defaultSizes(): array
    {
        return ['8pt', '9pt', '10pt', '11pt', '12pt', '14pt', '16pt', '18pt', '20pt', '24pt', '28pt', '36pt'];
    }

    // ------------------------------------------------------------------
    // Acesso a dados
    // ------------------------------------------------------------------

    public static function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        return DB::queryOne('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
    }

    /** Layout pedido, senão o padrão ativo, senão o primeiro ativo. */
    public static function findOrDefault(?int $id): ?array
    {
        return self::find($id)
            ?? DB::queryOne('SELECT * FROM ' . self::TABLE . ' WHERE is_default = 1 AND active = 1')
            ?? DB::queryOne('SELECT * FROM ' . self::TABLE . ' WHERE active = 1 ORDER BY id LIMIT 1');
    }

    /**
     * Layouts ativos. $kind: 'page' (para o corpo), 'cover' (para capa) ou
     * null (todos). Layouts com kind='both' servem para os dois usos.
     * @return array<int, array<string, mixed>>
     */
    public static function active(?string $kind = null): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE active = 1';
        $par = [];
        if ($kind === 'page' || $kind === 'cover') {
            $sql .= ' AND (kind = ? OR kind = "both")';
            $par[] = $kind;
        }
        $sql .= ' ORDER BY is_default DESC, name';
        try {
            return DB::query($sql, $par);
        } catch (\Throwable) {
            // schema antigo (sem a coluna kind)
            return DB::query('SELECT * FROM ' . self::TABLE . ' WHERE active = 1 ORDER BY is_default DESC, name');
        }
    }

    /** @return string[] fontes permitidas no layout (padrão: todas) */
    public static function fontsOf(?array $layout): array
    {
        $list = self::jsonList($layout['fonts'] ?? null);
        return $list !== [] ? $list : self::defaultFonts();
    }

    /** @return string[] tamanhos permitidos no layout (padrão: todos) */
    public static function sizesOf(?array $layout): array
    {
        $list = self::jsonList($layout['font_sizes'] ?? null);
        return $list !== [] ? $list : self::defaultSizes();
    }

    private static function jsonList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw), fn ($v) => trim($v) !== ''));
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = array_map('trim', explode(',', $raw));
        }
        return array_values(array_filter(array_map('strval', $decoded), fn ($v) => trim($v) !== ''));
    }

    // ------------------------------------------------------------------
    // HTML
    // ------------------------------------------------------------------

    /**
     * Sanitização do HTML do editor: remove scripts, iframes, objetos,
     * formulários e handlers on* (o conteúdo pode ir para páginas públicas).
     */
    public static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<\s*(script|iframe|object|embed|form|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(script|iframe|object|embed|form)\b[^>]*/?\s*>#i', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', ' $1="#"', $html) ?? $html;
        return $html;
    }

    /** Substitui os placeholders do cabeçalho/rodapé/capa. */
    public static function placeholders(string $html, array $layout, array $meta): string
    {
        $logo = '';
        if (!empty($layout['logo_path'])) {
            $logo = '<img src="' . core_e(core_url((string) $layout['logo_path'])) . '" alt="Logo" style="max-height:14mm">';
        }
        $map = [
            '{{logo}}'    => $logo,
            '{{org}}'     => core_e((string) Settings::get('org_name', core_config('app.name', ''))),
            '{{titulo}}'  => core_e((string) ($meta['title'] ?? '')),
            '{{autor}}'   => core_e((string) ($meta['author'] ?? '')),
            '{{data}}'    => core_e((string) ($meta['date'] ?? date('d/m/Y'))),
            '{{versao}}'  => core_e((string) ($meta['version'] ?? '1')),
            '{{codigo}}'  => core_e((string) ($meta['code'] ?? '')),
            '{{setor}}'   => core_e((string) ($meta['sector'] ?? '')),
            '{{subtitulo}}' => core_e((string) ($meta['subtitle'] ?? '')),
        ];
        foreach ((array) ($meta['extra'] ?? []) as $k => $v) {
            $map['{{' . $k . '}}'] = core_e((string) $v);
        }
        return strtr($html, $map);
    }

    /** Slug de classe CSS para uma fonte ("Times New Roman" → "times-new-roman"). */
    public static function fontSlug(string $font): string
    {
        $s = strtolower(trim($font));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    /** Slug de classe para um tamanho ("12pt" → "12pt", "10.5pt" → "10-5pt"). */
    public static function sizeSlug(string $size): string
    {
        return preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($size))) ?? $size;
    }

    /**
     * CSS das classes do editor (Quill) para fontes e tamanhos:
     *   .ql-font-arial { font-family: 'Arial' }  .ql-size-12pt { font-size: 12pt }
     * Deve ser incluído tanto no editor quanto na renderização/impressão.
     */
    public static function fontCss(array $fonts, array $sizes): string
    {
        $css = '';
        foreach ($fonts as $f) {
            $css .= '.ql-font-' . self::fontSlug($f) . "{font-family:'" . addslashes($f) . "'}\n";
        }
        foreach ($sizes as $s) {
            $css .= '.ql-size-' . self::sizeSlug($s) . '{font-size:' . preg_replace('/[^0-9a-z.]/', '', strtolower($s)) . "}\n";
        }
        return $css;
    }

    /**
     * Configuração JSON do editor (assets/core/doc-editor.js).
     * @param array $layout layout do corpo (define fontes/tamanhos permitidos)
     */
    public static function editorConfig(?array $layout, array $extra = []): array
    {
        return array_merge([
            'fonts'       => self::fontsOf($layout),
            'sizes'       => self::sizesOf($layout),
            'defaultFont' => (string) ($layout['default_font'] ?? ''),
            'defaultSize' => (string) ($layout['default_font_size'] ?? ''),
            'sheetWidth'  => $layout ? self::dims((string) $layout['page_size'], (string) $layout['orientation'])[0] : 210,
            'marginLeft'  => (int) ($layout['margin_left'] ?? 15),
            'marginRight' => (int) ($layout['margin_right'] ?? 15),
        ], $extra);
    }

    /** Tags <link> do editor (Quill via CDN + CSS do núcleo). */
    public static function editorHead(): string
    {
        return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">' . "\n"
             . '<link rel="stylesheet" href="' . core_asset('core/doc-editor.css') . '">';
    }

    /** Tags <script> do editor. */
    public static function editorScripts(): string
    {
        return '<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>' . "\n"
             . '<script src="' . core_asset('core/doc-editor.js') . '"></script>';
    }

    // ------------------------------------------------------------------
    // Renderização
    // ------------------------------------------------------------------

    /**
     * @param array{
     *   title: string, layout: array, content_html: string, meta?: array,
     *   toolbar?: string, autoprint?: bool,
     *   cover?: ?array{layout?: ?array, html?: string},
     *   font_family?: ?string, font_size?: ?string, extra_css?: string
     * } $opts
     */
    public static function render(array $opts): void
    {
        echo self::renderHtml($opts);
    }

    public static function renderHtml(array $opts): string
    {
        $layout = $opts['layout'];
        [$w, $h] = self::dims((string) $layout['page_size'], (string) $layout['orientation']);
        $mt = (int) $layout['margin_top'];
        $mr = (int) $layout['margin_right'];
        $mb = (int) $layout['margin_bottom'];
        $ml = (int) $layout['margin_left'];
        $hh = (int) ($layout['header_height'] ?? 0);
        $fh = (int) ($layout['footer_height'] ?? 0);

        $meta       = (array) ($opts['meta'] ?? []);
        $headerHtml = self::placeholders((string) ($layout['header_html'] ?? ''), $layout, $meta);
        $footerHtml = self::placeholders((string) ($layout['footer_html'] ?? ''), $layout, $meta);
        $repeatHead = $hh > 0 && $headerHtml !== '';
        $repeatFoot = $fh > 0 && $footerHtml !== '';
        $bgUrl      = !empty($layout['background_path']) ? core_url((string) $layout['background_path']) : '';

        // Capa (opcional): layout próprio (ou o mesmo) + conteúdo
        $cover      = $opts['cover'] ?? null;
        $coverHtml  = trim((string) ($cover['html'] ?? ''));
        $coverLay   = $cover['layout'] ?? null;
        $hasCover   = is_array($cover) && ($coverHtml !== '' || (!empty($coverLay['cover_html'] ?? '')));
        if ($hasCover) {
            $coverLay = $coverLay ?: $layout;
            if ($coverHtml === '') {
                $coverHtml = self::placeholders((string) ($coverLay['cover_html'] ?? ''), $coverLay, $meta);
            } else {
                $coverHtml = self::placeholders($coverHtml, $coverLay, $meta);
            }
            $coverBg   = !empty($coverLay['cover_background_path']) ? core_url((string) $coverLay['cover_background_path'])
                       : (!empty($coverLay['background_path']) ? core_url((string) $coverLay['background_path']) : '');
            $coverHead = self::placeholders((string) ($coverLay['header_html'] ?? ''), $coverLay, $meta);
            $coverFoot = self::placeholders((string) ($coverLay['footer_html'] ?? ''), $coverLay, $meta);
            $cmt = (int) $coverLay['margin_top']; $cmr = (int) $coverLay['margin_right'];
            $cmb = (int) $coverLay['margin_bottom']; $cml = (int) $coverLay['margin_left'];
        }

        $fontFamily = trim((string) ($opts['font_family'] ?? '')) ?: (string) ($layout['default_font'] ?? '');
        $fontSize   = trim((string) ($opts['font_size'] ?? '')) ?: (string) ($layout['default_font_size'] ?? '');
        $fontFamily = $fontFamily !== '' ? "'" . addslashes($fontFamily) . "', Arial, Helvetica, sans-serif" : 'Arial, Helvetica, sans-serif';
        $fontSize   = $fontSize !== '' ? preg_replace('/[^0-9a-z.]/', '', strtolower($fontSize)) : '11pt';
        $fontCss    = self::fontCss(self::fontsOf($layout), self::sizesOf($layout));

        $spaceTop    = $mt + ($repeatHead ? $hh + 3 : 0);
        $spaceBottom = $mb + ($repeatFoot ? $fh + 3 : 0);

        ob_start(); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= core_e((string) $opts['title']) ?></title>
<style>
    @page { size: <?= $w ?>mm <?= $h ?>mm; margin: 0; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1a1a1a; background: #e9edf1; }
    <?= $fontCss ?>
    .doc-content { font-family: <?= $fontFamily ?>; font-size: <?= $fontSize ?>; line-height: 1.5; }
    .doc-content img { max-width: 100%; }
    .doc-content table { border-collapse: collapse; width: 100%; }
    .doc-content td, .doc-content th { border: 1px solid #999; padding: 4px 6px; }
    .doc-content p { margin: 0 0 .6em; }
    .ql-align-center { text-align: center; } .ql-align-right { text-align: right; } .ql-align-justify { text-align: justify; }
    .ql-indent-1 { padding-left: 3em; } .ql-indent-2 { padding-left: 6em; } .ql-indent-3 { padding-left: 9em; }
    .doc-table { width: 100%; border-collapse: collapse; }
    .doc-table td { padding: 0; vertical-align: top; }
    .doc-cell { padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; }
    .doc-space-top { height: <?= $spaceTop ?>mm; }
    .doc-space-bottom { height: <?= $spaceBottom ?>mm; }
    .doc-hf { padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; }
    .doc-header-flow { padding-top: <?= $mt ?>mm; margin-bottom: 4mm; }
    .doc-footer-flow { margin-top: 8mm; padding-bottom: <?= $mb ?>mm; }

    /* --------- folhas na tela (emulam o papel) --------- */
    .sheet {
        width: <?= $w ?>mm; min-height: <?= $h ?>mm; margin: 16px auto; position: relative;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18); overflow: hidden;
    }
    .sheet-body { <?= $bgUrl !== '' ? 'background: #fff url(' . core_e($bgUrl) . ') top left / ' . $w . 'mm ' . $h . 'mm repeat-y;' : '' ?> }
    .doc-header-fixed { position: absolute; top: <?= $mt ?>mm; left: 0; right: 0; height: <?= $hh ?>mm; overflow: hidden; z-index: 2; }
    .doc-footer-fixed { position: absolute; bottom: <?= $mb ?>mm; left: 0; right: 0; height: <?= $fh ?>mm; overflow: hidden; z-index: 2; }
    .doc-bg-fixed { display: none; }
    .sheet-cover {
        height: <?= $h ?>mm; z-index: 10;
        <?= $hasCover && $coverBg !== '' ? 'background: #fff url(' . core_e($coverBg) . ') center / 100% 100% no-repeat;' : 'background: #fff;' ?>
    }
    <?php if ($hasCover): ?>
    .cover-header { position: absolute; top: <?= $cmt ?>mm; left: <?= $cml ?>mm; right: <?= $cmr ?>mm; }
    .cover-footer { position: absolute; bottom: <?= $cmb ?>mm; left: <?= $cml ?>mm; right: <?= $cmr ?>mm; }
    .cover-content { position: absolute; top: <?= $cmt ?>mm; bottom: <?= $cmb ?>mm; left: <?= $cml ?>mm; right: <?= $cmr ?>mm; overflow: hidden; }
    <?php endif; ?>

    /* --------- barra de ações (some na impressão) --------- */
    .intra-toolbar {
        position: sticky; top: 0; z-index: 100; background: #0d5c8f; color: #fff;
        padding: 8px 14px; display: flex; gap: 8px; align-items: center; font-size: 14px; flex-wrap: wrap;
    }
    .intra-toolbar a, .intra-toolbar button {
        color: #fff; background: rgba(255,255,255,.15); border: 0; padding: 5px 12px; border-radius: 6px;
        text-decoration: none; font-size: 13px; cursor: pointer; font-family: inherit;
    }
    .intra-toolbar a:hover, .intra-toolbar button:hover { background: rgba(255,255,255,.28); }
    .intra-toolbar .spacer { flex: 1; }

    @media print {
        body { background: #fff; }
        .intra-toolbar { display: none !important; }
        .sheet { width: <?= $w ?>mm; min-height: 0; margin: 0; box-shadow: none; overflow: visible; }
        .sheet-body { background: transparent; }
        .sheet-cover { height: <?= $h ?>mm; break-after: page; page-break-after: always; position: relative; }
        <?php if ($bgUrl !== ''): ?>
        .doc-bg-fixed { display: block; position: fixed; top: 0; left: 0; width: <?= $w ?>mm; height: <?= $h ?>mm; z-index: -1; }
        .doc-bg-fixed img { width: 100%; height: 100%; }
        <?php endif; ?>
        <?php if ($repeatHead): ?>
        .doc-header-fixed { position: fixed; top: <?= $mt ?>mm; left: 0; right: 0; width: <?= $w ?>mm; }
        <?php endif; ?>
        <?php if ($repeatFoot): ?>
        .doc-footer-fixed { position: fixed; bottom: <?= $mb ?>mm; left: 0; right: 0; width: <?= $w ?>mm; }
        <?php endif; ?>
    }
    <?= (string) ($layout['custom_css'] ?? '') ?>
    <?= (string) ($opts['extra_css'] ?? '') ?>
</style>
</head>
<body>
<?php if (!empty($opts['toolbar'])): ?>
    <div class="intra-toolbar"><?= $opts['toolbar'] ?></div>
<?php endif; ?>
<?php if ($hasCover): ?>
<div class="sheet sheet-cover">
    <?php if ($coverHead !== ''): ?><div class="cover-header"><?= $coverHead ?></div><?php endif; ?>
    <div class="cover-content doc-content"><?= $coverHtml ?></div>
    <?php if ($coverFoot !== ''): ?><div class="cover-footer"><?= $coverFoot ?></div><?php endif; ?>
</div>
<?php endif; ?>
<div class="sheet sheet-body">
    <?php if ($bgUrl !== ''): ?><div class="doc-bg-fixed"><img src="<?= core_e($bgUrl) ?>" alt=""></div><?php endif; ?>
    <?php if ($repeatHead): ?><div class="doc-header-fixed doc-hf"><?= $headerHtml ?></div><?php endif; ?>
    <?php if ($repeatFoot): ?><div class="doc-footer-fixed doc-hf"><?= $footerHtml ?></div><?php endif; ?>
    <table class="doc-table">
        <thead><tr><td><div class="doc-space-top"></div>
            <?php if (!$repeatHead && $headerHtml !== ''): ?><div class="doc-header-flow doc-hf"><?= $headerHtml ?></div><?php endif; ?>
        </td></tr></thead>
        <tfoot><tr><td>
            <?php if (!$repeatFoot && $footerHtml !== ''): ?><div class="doc-footer-flow doc-hf"><?= $footerHtml ?></div><?php endif; ?>
            <div class="doc-space-bottom"></div></td></tr></tfoot>
        <tbody><tr><td class="doc-cell"><div class="doc-content"><?= $opts['content_html'] ?></div></td></tr></tbody>
    </table>
</div>
<?php if (!empty($opts['autoprint'])): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }
}
