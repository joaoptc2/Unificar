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
     * Sanitização do HTML do editor (conteúdo, capa, cabeçalho/rodapé de
     * layouts): lista de permissão por DOM — ver Core\HtmlSanitizer.
     * O conteúdo pode ir para páginas públicas e para a impressão.
     */
    public static function sanitizeHtml(string $html): string
    {
        return HtmlSanitizer::clean($html);
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
            '{{org}}'     => core_e(Branding::name()),
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

    /**
     * CSS do CONTEÚDO na impressão/visualização — paridade com o editor.
     *
     * O editor (Quill "snow") zera as margens de parágrafos/títulos/listas,
     * desenha os marcadores de lista com `li[data-list]` + `.ql-ui::before`
     * e contadores, recua `.ql-indent-N` até o nível 9, preserva espaços
     * (white-space: pre-wrap) e estiliza citação/tabela do seu jeito. O papel
     * tinha regras próprias (p com margem, listas do navegador, td com outro
     * padding): o mesmo texto ficava 10% mais alto, listas de marcadores
     * saíam numeradas e a paginação mudava. Aqui o papel copia o editor,
     * regra a regra, para o que se vê ser o que se imprime.
     *
     * Listas antigas sem data-list (conteúdo importado) mantêm o marcador
     * nativo do navegador: as regras do Quill só valem para li[data-list].
     */
    public static function contentCss(): string
    {
        $css = <<<'CSS'
    .doc-content { word-wrap: break-word; tab-size: 4; counter-reset: list-0 list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9; }
    /* Espaços/quebras preservados só DENTRO dos blocos (como o Quill grava):
       no contêiner, pre-wrap transformaria as quebras de linha do código-fonte
       de capas e HTML legado em linhas em branco. */
    .doc-content p, .doc-content li, .doc-content td, .doc-content th, .doc-content blockquote,
    .doc-content h1, .doc-content h2, .doc-content h3, .doc-content h4, .doc-content h5, .doc-content h6 { white-space: pre-wrap; }
    .doc-content p, .doc-content ol, .doc-content ul, .doc-content pre, .doc-content blockquote,
    .doc-content h1, .doc-content h2, .doc-content h3, .doc-content h4, .doc-content h5, .doc-content h6 { margin: 0; padding: 0; }
    .doc-content p, .doc-content h1, .doc-content h2, .doc-content h3, .doc-content h4, .doc-content h5, .doc-content h6 { counter-set: list-0 list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9; }
    .doc-content h1 { font-size: 2em; } .doc-content h2 { font-size: 1.5em; } .doc-content h3 { font-size: 1.17em; }
    .doc-content h4 { font-size: 1em; } .doc-content h5 { font-size: .83em; } .doc-content h6 { font-size: .67em; }
    .doc-content a { color: #0b57d0; }
    .doc-content img { max-width: 100%; }
    .doc-content blockquote { border-left: 4px solid #ccc; margin: 5px 0; padding-left: 16px; }
    .doc-content pre { white-space: pre-wrap; margin: 5px 0; padding: 5px 10px; border-radius: 3px; background: #f0f0f0; }
    .doc-content table { border-collapse: collapse; table-layout: fixed; width: 100%; }
    .doc-content td, .doc-content th { border: 1px solid #000; padding: 2px 5px; }
    .doc-content ol, .doc-content ul { padding-left: 1.5em; }
    .doc-content li[data-list] { list-style-type: none; padding-left: 1.5em; position: relative; }
    .doc-content .ql-ui { position: absolute; }
    .doc-content li > .ql-ui:before { display: inline-block; margin-left: -1.5em; margin-right: .3em; text-align: right; white-space: nowrap; width: 1.2em; }
    .doc-content li[data-list=bullet] > .ql-ui:before { content: '\2022'; }
    .doc-content li[data-list=checked] > .ql-ui:before { content: '\2611'; }
    .doc-content li[data-list=unchecked] > .ql-ui:before { content: '\2610'; }
    .doc-content li[data-list] { counter-set: list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9; }
    .doc-content li[data-list=ordered] { counter-increment: list-0; }
    .doc-content li[data-list=ordered] > .ql-ui:before { content: counter(list-0, decimal) '. '; }
    .ql-align-center { text-align: center; } .ql-align-right { text-align: right; } .ql-align-justify { text-align: justify; }

CSS;
        // Níveis de recuo 1..9: parágrafo recua 3em por nível; item de lista
        // 3em + 1.5em; listas numeradas alternam decimal / alfa / romano.
        $estilos = ['decimal', 'lower-alpha', 'lower-roman'];
        for ($n = 1; $n <= 9; $n++) {
            $resto = [];
            for ($k = $n + 1; $k <= 9; $k++) { $resto[] = 'list-' . $k; }
            $css .= "    .doc-content .ql-indent-{$n}:not(.ql-direction-rtl) { padding-left: " . (3 * $n) . "em; }\n";
            $css .= "    .doc-content li.ql-indent-{$n}:not(.ql-direction-rtl) { padding-left: " . (3 * $n + 1.5) . "em; }\n";
            $css .= "    .doc-content li[data-list=ordered].ql-indent-{$n} { counter-increment: list-{$n}; }\n";
            $css .= "    .doc-content li[data-list=ordered].ql-indent-{$n} > .ql-ui:before { content: counter(list-{$n}, " . $estilos[$n % 3] . ") '. '; }\n";
            if ($resto) {
                $css .= "    .doc-content li[data-list].ql-indent-{$n} { counter-set: " . implode(' ', $resto) . "; }\n";
            }
        }
        return $css;
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
        // Sem tamanho no documento nem no layout, o editor mostra 12pt
        // (.ql-container) — o papel usa o MESMO valor, senão o texto reflui.
        $fontSize   = $fontSize !== '' ? preg_replace('/[^0-9a-z.]/', '', strtolower($fontSize)) : '12pt';
        $fontCss    = self::fontCss(self::fontsOf($layout), self::sizesOf($layout));

        // UM único respiro entre cabeçalho/rodapé e o corpo, usado pelo papel
        // (thead/tfoot) e pela tela (paginador JS). Eram 3 mm aqui e 4 mm no
        // JS — 1 mm de diferença em toda página.
        $gap         = 4;
        $spaceTop    = $mt + ($repeatHead ? $hh + $gap : 0);
        $spaceBottom = $mb + ($repeatFoot ? $fh + $gap : 0);

        ob_start(); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= core_e((string) $opts['title']) ?></title>
<?php
// Paleta de impressão: a mesma de onde saem o cartaz do RH e a etiqueta de
// equipamento. Antes o texto, o fundo e a barra de ações eram cores fixas,
// então o papel timbrado não acompanhava a identidade do hospital.
$pal = Tokens::printPalette();
?>
<style>
    @page { size: <?= $w ?>mm <?= $h ?>mm; margin: 0; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { margin: 0; padding: 0; }
    body { font-family: <?= $pal['font'] ?>; font-size: 11pt; color: <?= $pal['text'] ?>; background: #e9edf1; }
    <?= $fontCss ?>
    .doc-content { font-family: <?= $fontFamily ?>; font-size: <?= $fontSize ?>; line-height: 1.5; }
<?= self::contentCss() ?>
    .doc-table { width: 100%; border-collapse: collapse; }
    /* Só as células da MOLDURA (filhas diretas da .doc-table): uma regra
       `.doc-table td` genérica vazava para as tabelas escritas no documento
       e zerava o padding delas. */
    .doc-table > thead > tr > td, .doc-table > tbody > tr > td, .doc-table > tfoot > tr > td { padding: 0; vertical-align: top; }
    .doc-table > tbody > tr > td.doc-cell { padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; }
    .doc-space-top { height: <?= $spaceTop ?>mm; }
    .doc-space-bottom { height: <?= $spaceBottom ?>mm; }
    .doc-hf { padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; }
    /* Em fluxo (header_height/footer_height = 0): o espaçador .doc-space-top
       JÁ reserva a margem superior; dar padding-top: margem aqui também
       aplicava a margem DUAS vezes em toda página (medido: +25 mm no topo e
       +20 mm no pé). Fica só o respiro até o corpo. */
    .doc-header-flow { margin-bottom: <?= $gap ?>mm; }
    .doc-footer-flow { margin-top: <?= $gap ?>mm; }

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

    /* --------- pré-visualização PAGINADA na tela ---------
       A impressão usa a paginação nativa (thead/tfoot repetem por página).
       Na tela, sem paginação, um documento de várias páginas virava UMA folha
       contínua: o cabeçalho/rodapé apareciam uma vez só e o texto corria pelas
       fronteiras de página, ignorando as margens de topo/rodapé "após a
       primeira página". O JS abaixo monta folhas A4 separadas (repetindo
       cabeçalho, rodapé, fundo e margens). É só para a TELA. */
    .doc-screen-pages { display: none; }
    body.js-paginado .sheet-body { display: none; }
    body.js-paginado .doc-screen-pages { display: block; }
    .doc-screen-pages .doc-page {
        width: <?= $w ?>mm; height: <?= $h ?>mm; position: relative; margin: 16px auto;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18); overflow: hidden;
        <?= $bgUrl !== '' ? 'background-image: url(' . core_e($bgUrl) . '); background-size: ' . $w . 'mm ' . $h . 'mm; background-repeat: no-repeat;' : '' ?>
    }
    .doc-screen-pages .doc-page-head { position: absolute; top: <?= $mt ?>mm; left: 0; right: 0; padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; overflow: hidden; }
    .doc-screen-pages .doc-page-foot { position: absolute; bottom: <?= $mb ?>mm; left: 0; right: 0; padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; overflow: hidden; }
    .doc-screen-pages .doc-page-body { position: absolute; left: 0; right: 0; padding: 0 <?= $mr ?>mm 0 <?= $ml ?>mm; overflow: hidden; }

    /* --------- barra de ações (some na impressão) --------- */
    .intra-toolbar {
        position: sticky; top: 0; z-index: 100; background: <?= $pal['primary'] ?>; color: <?= $pal['on_primary'] ?>;
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
        /* A impressão SEMPRE usa a folha nativa (paginação por thead/tfoot);
           a versão paginada por JS, que é só da tela, nunca vai para o papel. */
        .doc-screen-pages { display: none !important; }
        body.js-paginado .sheet-body { display: block !important; }
        .sheet { width: <?= $w ?>mm; min-height: 0; margin: 0; box-shadow: none; overflow: visible; }
        .sheet-body { background: transparent; }
        .sheet-cover { height: <?= $h ?>mm; break-after: page; page-break-after: always; position: relative; }
        <?php if ($bgUrl !== ''): ?>
        .doc-bg-fixed { display: block; position: fixed; top: 0; left: 0; width: <?= $w ?>mm; height: <?= $h ?>mm; z-index: -1; }
        .doc-bg-fixed img { width: 100%; height: 100%; }
        <?php endif; ?>
        /* Sem condição: só existe no DOM quando há cabeçalho/rodapé fixo —
           inclusive o "em fluxo" que o JS promove a fixo depois de medir. */
        .doc-header-fixed { position: fixed; top: <?= $mt ?>mm; left: 0; right: 0; width: <?= $w ?>mm; }
        .doc-footer-fixed { position: fixed; bottom: <?= $mb ?>mm; left: 0; right: 0; width: <?= $w ?>mm; }
        /* PARIDADE DE PAGINAÇÃO com a tela: o paginador JS move blocos
           inteiros e, em listas/tabelas, itens/linhas inteiros. Sem isto o
           Chromium partia parágrafos entre páginas e o papel ficava com
           outra contagem de páginas e outro conteúdo por folha a partir da
           2ª. Um bloco maior que a página continua sendo partido. */
        .doc-content > *:not(ol):not(ul):not(table) { break-inside: avoid; page-break-inside: avoid; }
        .doc-content li, .doc-content tr { break-inside: avoid; page-break-inside: avoid; }
        .doc-content h1, .doc-content h2, .doc-content h3, .doc-content h4 { break-after: avoid; page-break-after: avoid; }
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
<div class="doc-screen-pages" aria-hidden="true"></div>
<script>
/* Pré-visualização paginada NA TELA + preparação da impressão.
   1. Cabeçalho/rodapé EM FLUXO (altura não informada no layout) são MEDIDOS
      e promovidos a fixos: assim repetem em toda página, o rodapé vai ao pé
      também na última, e o espaçador do papel recebe a altura real.
   2. A tela monta folhas separadas a partir do conteúdo contínuo, com a
      MESMA geometria do papel (margens, cabeçalho, rodapé e respiro), movendo
      blocos inteiros — e, em listas/tabelas, itens/linhas inteiros, como a
      impressão faz com break-inside:avoid. Numeração de lista continua na
      folha seguinte.
   Falha em silêncio, mantendo a folha contínua, se algo der errado. */
(function () {
    var C = {
        w: <?= $w ?>, h: <?= $h ?>, mt: <?= $mt ?>, mb: <?= $mb ?>, gap: <?= $gap ?>,
        hh: <?= $hh ?>, fh: <?= $fh ?>, rh: <?= $repeatHead ? 1 : 0 ?>, rf: <?= $repeatFoot ? 1 : 0 ?>,
        hhpx: 0, fhpx: 0
    };
    var MM = 96 / 25.4; // px por mm na tela
    function quandoCarregar(fn) {
        if (document.readyState === 'complete') { fn(); }
        else { window.addEventListener('load', fn); } // imagens do timbrado já medidas
    }
    quandoCarregar(function () { try { promover(); } catch (e) { if (window.console) { console.warn('cabeçalho em fluxo não promovido:', e); } } paginar(); });

    /* Mede o cabeçalho/rodapé em fluxo e os transforma em fixos (fora da
       tabela), ajustando os espaçadores do papel para a altura real. */
    function promover() {
        var body = document.querySelector('.sheet-body');
        if (!body) { return; }
        var hf = body.querySelector('.doc-header-flow');
        if (hf && !C.rh) {
            var hpx = hf.offsetHeight;
            var fixo = document.createElement('div');
            fixo.className = 'doc-header-fixed doc-hf';
            fixo.innerHTML = hf.innerHTML;
            fixo.style.height = hpx + 'px';
            body.insertBefore(fixo, body.querySelector('.doc-table'));
            hf.parentNode.removeChild(hf);
            var sp = body.querySelector('.doc-space-top');
            if (sp) { sp.style.height = (C.mt * MM + hpx + C.gap * MM) + 'px'; }
            C.hhpx = hpx; C.rh = 1;
        }
        var ff = body.querySelector('.doc-footer-flow');
        if (ff && !C.rf) {
            var fpx = ff.offsetHeight;
            var fixoF = document.createElement('div');
            fixoF.className = 'doc-footer-fixed doc-hf';
            fixoF.innerHTML = ff.innerHTML;
            fixoF.style.height = fpx + 'px';
            body.insertBefore(fixoF, body.querySelector('.doc-table'));
            ff.parentNode.removeChild(ff);
            var sb = body.querySelector('.doc-space-bottom');
            if (sb) { sb.style.height = (C.mb * MM + fpx + C.gap * MM) + 'px'; }
            C.fhpx = fpx; C.rf = 1;
        }
    }

    function paginar() {
        try {
            montar();
        } catch (e) {
            // Qualquer falha: volta à folha contínua, nunca deixa a tela em branco.
            document.body.classList.remove('js-paginado');
            if (window.console) { console.warn('Pré-visualização paginada indisponível:', e); }
        }
    }
    function montar() {
        // Não roda quando a página já está sendo impressa.
        if (window.matchMedia && window.matchMedia('print').matches) { return; }
        var body  = document.querySelector('.sheet-body');
        var wrap  = document.querySelector('.doc-screen-pages');
        if (!body || !wrap) { return; }
        var content = body.querySelector('.doc-content');
        if (!content) { return; }
        var headSrc = body.querySelector('.doc-header-fixed, .doc-header-flow');
        var footSrc = body.querySelector('.doc-footer-fixed, .doc-footer-flow');
        var headHTML = headSrc ? headSrc.innerHTML : '';
        var footHTML = footSrc ? footSrc.innerHTML : '';
        var blocks = Array.prototype.slice.call(content.children);
        if (!blocks.length) { return; }

        wrap.textContent = '';
        // Ativa a versão paginada ANTES de medir: com o contêiner display:none
        // as alturas viriam todas zero e tudo cairia numa página só. A troca é
        // síncrona (a montagem termina antes de qualquer repintura), sem piscar.
        document.body.classList.add('js-paginado');

        var alvo, limite;
        function novaPagina() {
            var pg = document.createElement('div'); pg.className = 'doc-page';
            var headH = 0, footH = 0;
            wrap.appendChild(pg);
            if (headHTML) {
                var h = document.createElement('div'); h.className = 'doc-page-head'; h.innerHTML = headHTML;
                pg.appendChild(h);
                // Mesma caixa do papel: altura fixa, conteúdo alinhado ao topo.
                headH = C.hhpx || (C.hh > 0 ? C.hh * MM : h.offsetHeight);
                h.style.height = headH + 'px';
            }
            if (footHTML) {
                var f = document.createElement('div'); f.className = 'doc-page-foot'; f.innerHTML = footHTML;
                pg.appendChild(f);
                footH = C.fhpx || (C.fh > 0 ? C.fh * MM : f.offsetHeight);
                f.style.height = footH + 'px';
            }
            var b = document.createElement('div'); b.className = 'doc-page-body doc-content';
            b.style.top = (C.mt * MM + (headHTML ? headH + C.gap * MM : 0)) + 'px';
            b.style.bottom = (C.mb * MM + (footHTML ? footH + C.gap * MM : 0)) + 'px';
            pg.appendChild(b);
            alvo = b; limite = b.clientHeight;
            return b;
        }
        function cabe() { return alvo.scrollHeight <= limite + 0.5; }
        function divisivel(el) {
            var t = el.tagName;
            return (t === 'OL' || t === 'UL' || t === 'TABLE') && el.children.length > 1;
        }
        // Clona o elemento SEM os filhos (a "casca" de uma lista/tabela).
        function casca(el) {
            var c = el.cloneNode(false);
            if (el.tagName === 'TABLE') {
                Array.prototype.forEach.call(el.children, function (ch) {
                    if (ch.tagName !== 'TBODY') { c.appendChild(ch.cloneNode(true)); }
                });
                c.appendChild(document.createElement('tbody'));
            }
            return c;
        }
        function destino(cont) { return cont.tagName === 'TABLE' ? cont.querySelector('tbody') : cont; }
        function itensDe(el) {
            if (el.tagName === 'TABLE') {
                var tb = el.querySelector('tbody');
                return Array.prototype.slice.call((tb || el).children);
            }
            return Array.prototype.slice.call(el.children);
        }
        // Lista/tabela que não cabe: distribui os itens/linhas pelas folhas,
        // repetindo a casca (e o thead) e continuando a numeração.
        function dividir(el) {
            var itens = itensDe(el), cont = casca(el), ordinais = 0;
            alvo.appendChild(cont);
            for (var j = 0; j < itens.length; j++) {
                var it = itens[j].cloneNode(true);
                destino(cont).appendChild(it);
                if (!cabe() && (destino(cont).children.length > 1 || alvo.children.length > 1)) {
                    destino(cont).removeChild(it);
                    if (!destino(cont).children.length) { alvo.removeChild(cont); }
                    novaPagina();
                    cont = casca(el);
                    if (ordinais > 0) { cont.style.counterReset = 'list-0 ' + ordinais; }
                    alvo.appendChild(cont);
                    destino(cont).appendChild(it);
                }
                if (it.getAttribute && it.getAttribute('data-list') === 'ordered' && !/ql-indent-/.test(it.className || '')) { ordinais++; }
            }
        }

        novaPagina();
        for (var i = 0; i < blocks.length; i++) {
            var clone = blocks[i].cloneNode(true);
            alvo.appendChild(clone);
            if (cabe()) { continue; }
            if (divisivel(blocks[i])) {
                alvo.removeChild(clone);
                dividir(blocks[i]);
                continue;
            }
            if (alvo.children.length === 1) {
                // Um único bloco maior que a página fica sozinho e transborda
                // (raro: imagem gigante) — o papel faz o mesmo.
                continue;
            }
            alvo.removeChild(clone);
            novaPagina();
            alvo.appendChild(clone);
        }
    }
})();
</script>
<?php if (!empty($opts['autoprint'])): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }
}
