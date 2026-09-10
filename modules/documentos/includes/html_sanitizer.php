<?php
/**
 * Sanitizador de HTML do editor de documentos (conteúdo e capa).
 *
 * Arquivo SEM dependências (apenas DOM/libxml) para poder ser reutilizado
 * por outros módulos que usam o editor com layouts (ex.: Intranet).
 * Ponto único: doc_sanitize_html($html). O 2º parâmetro força o parser
 * legado (DOMDocument) — usado apenas em testes; em PHP ≥ 8.4 usa Dom\HTMLDocument.
 */
// ── Sanitização de HTML do editor (documentos escritos no sistema) ──────────

/**
 * Sanitiza o HTML produzido pelo editor (conteúdo e capa) com uma lista de
 * permissão de tags/atributos baseada em DOM — complementa o filtro por
 * regex do núcleo (Core\DocLayout::sanitizeHtml), que deixa passar vetores
 * como <svg/onload>, href="javascript&#58;", <meta http-equiv>, <base>,
 * <link>, xlink:href e vbscript:.
 *
 * Mantém o que o Quill gera (p, títulos, listas com data-list, span com
 * classes ql-*, cores em style, links, imagens inclusive data:image, tabelas)
 * e descarta scripts, elementos ativos, manipuladores on*, URLs com esquema
 * perigoso e CSS com url()/expression().
 */
function doc_sanitize_html($html, $force_legacy_dom = false) {
    $html = (string) $html;
    if (trim($html) === '') return '';

    // 1ª passada: filtro do núcleo (remove script/iframe/object/embed/form/style)
    if (class_exists('Core\DocLayout')) {
        $html = Core\DocLayout::sanitizeHtml($html);
    }

    $allowed_tags = [
        'p','br','hr','strong','b','em','i','u','s','strike','del','ins','sub','sup','small','mark',
        'span','div','h1','h2','h3','h4','h5','h6','ul','ol','li','blockquote','pre','code','a','img',
        'table','thead','tbody','tfoot','tr','td','th','caption','colgroup','col','dl','dt','dd',
        'figure','figcaption','section','article','header','footer','address','cite','q','abbr','kbd','samp','var','time',
    ];
    // Elementos removidos COM o conteúdo (ativos / não textuais)
    $drop_with_content = ['script','style','iframe','frame','frameset','object','embed','applet','svg','math',
                          'template','noscript','meta','link','base','form','input','button','select','textarea',
                          'option','title','head','xml','video','audio','source','track','canvas','map','area','slot','portal'];
    $allowed_attrs = [
        '*'   => ['class','style','title','dir','lang','align','valign','width','height'],
        'a'   => ['href','target','rel','name'],
        'img' => ['src','alt'],
        'td'  => ['colspan','rowspan'],
        'th'  => ['colspan','rowspan','scope'],
        'ol'  => ['start','type'],
        'li'  => ['value','data-list'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'time' => ['datetime'],
    ];

    $dom = null; $root = null; $is_html5 = false;
    $prev = libxml_use_internal_errors(true);
    try {
        if (!$force_legacy_dom && class_exists('Dom\HTMLDocument')) {
            $dom  = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
            $root = $dom->body;
            $is_html5 = true;
        } else {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $root = $dom->getElementsByTagName('body')->item(0);
        }
    } catch (Throwable $ex) {
        $dom = null;
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$dom || !$root) {
        // Sem DOM disponível: remove qualquer tag que não seja de texto simples
        return strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><table><thead><tbody><tr><td><th><span><div><sub><sup><hr>');
    }

    // Percorre em pós-ordem (filhos antes) para poder remover/desembrulhar nós
    $walk = function ($node) use (&$walk, $allowed_tags, $drop_with_content, $allowed_attrs) {
        $children = [];
        foreach ($node->childNodes as $c) $children[] = $c;
        foreach ($children as $child) {
            $type = $child->nodeType;
            if ($type === XML_ELEMENT_NODE) {
                $tag = strtolower((string) $child->localName);
                if (in_array($tag, $drop_with_content, true) || strpos($tag, ':') !== false) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($tag, $allowed_tags, true)) {
                    // Desconhecida: mantém apenas os filhos (texto)
                    $walk($child);
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                $walk($child);
                _doc_sanitize_attrs($child, $tag, $allowed_attrs);
            } elseif ($type === XML_COMMENT_NODE || $type === XML_PI_NODE || $type === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);

    $out = '';
    if ($is_html5) {
        $out = (string) $root->innerHTML;
    } else {
        foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
    }
    return $out;
}

/** Filtra os atributos de um elemento (uso interno de doc_sanitize_html). */
function _doc_sanitize_attrs($el, $tag, array $allowed_attrs) {
    $ok = array_merge($allowed_attrs['*'], $allowed_attrs[$tag] ?? []);
    $attrs = [];
    foreach ($el->attributes as $a) $attrs[] = [(string) $a->nodeName, (string) $a->nodeValue];
    foreach ($attrs as [$name, $value]) {
        $lname = strtolower($name);
        if (!in_array($lname, $ok, true) || strpos($lname, 'on') === 0) {
            $el->removeAttribute($name);
            continue;
        }
        $new = null;
        if ($lname === 'href' || $lname === 'src') {
            $new = _doc_sanitize_url($value, $lname === 'src');
            if ($new === null) { $el->removeAttribute($name); continue; }
        } elseif ($lname === 'style') {
            $new = _doc_sanitize_style($value);
            if ($new === '') { $el->removeAttribute($name); continue; }
        } elseif ($lname === 'class') {
            $new = trim(preg_replace('/[^A-Za-z0-9_\- ]+/', '', $value) ?? '');
            if ($new === '') { $el->removeAttribute($name); continue; }
        } elseif ($lname === 'target') {
            $new = in_array($value, ['_blank', '_self'], true) ? $value : '_blank';
        } elseif (in_array($lname, ['colspan','rowspan','width','height','start','value','span'], true)) {
            $new = preg_replace('/[^0-9%.]/', '', $value) ?? '';
            if ($new === '') { $el->removeAttribute($name); continue; }
        } else {
            $new = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        }
        if ($new !== $value) $el->setAttribute($name, $new);
    }
    if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
        $el->setAttribute('rel', 'noopener noreferrer');
    }
}

/**
 * URL de href/src: permite relativas, http(s), mailto, tel e (para src)
 * data:image/*. Qualquer outro esquema (javascript:, vbscript:, data:text…)
 * é rejeitado (retorna null).
 */
function _doc_sanitize_url($value, $is_src = false) {
    $v = trim((string) $value);
    if ($v === '') return null;
    // Remove caracteres de controle/espaços que os navegadores ignoram no esquema
    $probe = strtolower(preg_replace('/[\x00-\x20\x7F]+|\x{FFFD}/u', '', $v) ?? $v);
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m)) {
        $scheme = $m[1];
        if ($scheme === 'data') {
            return $is_src && preg_match('#^data:image/(png|jpe?g|gif|webp|bmp|svg\+xml);base64,[a-z0-9+/=\s]+$#i', $v) ? $v : null;
        }
        if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) return null;
    }
    return preg_replace('/[\x00-\x1F\x7F]/', '', $v);
}

/** Atributo style: bloqueia url(), expression(), import, binding e esquemas. */
function _doc_sanitize_style($value) {
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value) ?? '');
    if ($v === '') return '';
    $probe = strtolower(preg_replace('/\s+/', '', $v) ?? $v);
    if (preg_match('/(url\(|expression|javascript:|vbscript:|@import|behavior|binding|<|>|\\\\)/', $probe)) {
        return '';
    }
    return $v;
}
