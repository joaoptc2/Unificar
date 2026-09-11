<?php

declare(strict_types=1);

namespace Core;

/**
 * Sanitizador de HTML por lista de permissão (DOM) para conteúdo produzido
 * pelo editor de documentos (Documentos, Intranet, layouts, comunicados…).
 *
 * Mantém o que o Quill gera (parágrafos, títulos, listas com data-list,
 * span com classes ql-*, cores em style, links, imagens inclusive
 * data:image, tabelas) e descarta scripts, elementos ativos (svg, iframe,
 * object, meta, link, base, form…), manipuladores on*, URLs com esquema
 * perigoso (javascript:, vbscript:, data:text…) e CSS com url()/expression().
 * Usa Dom\HTMLDocument (PHP >= 8.4) e cai para DOMDocument nas versões
 * anteriores; sem DOM disponível, reduz a um strip_tags conservador.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p','br','hr','strong','b','em','i','u','s','strike','del','ins','sub','sup','small','mark',
        'span','div','h1','h2','h3','h4','h5','h6','ul','ol','li','blockquote','pre','code','a','img',
        'table','thead','tbody','tfoot','tr','td','th','caption','colgroup','col','dl','dt','dd',
        'figure','figcaption','section','article','header','footer','address','cite','q','abbr','kbd','samp','var','time',
    ];

    /** Elementos removidos COM o conteúdo (ativos / não textuais). */
    private const DROP_WITH_CONTENT = [
        'script','style','iframe','frame','frameset','object','embed','applet','svg','math',
        'template','noscript','meta','link','base','form','input','button','select','textarea',
        'option','title','head','xml','video','audio','source','track','canvas','map','area','slot','portal',
    ];

    private const ALLOWED_ATTRS = [
        '*'        => ['class','style','title','dir','lang','align','valign','width','height'],
        'a'        => ['href','target','rel','name'],
        'img'      => ['src','alt'],
        'td'       => ['colspan','rowspan'],
        'th'       => ['colspan','rowspan','scope'],
        'ol'       => ['start','type'],
        'li'       => ['value','data-list'],
        'col'      => ['span'],
        'colgroup' => ['span'],
        'time'     => ['datetime'],
    ];

    /** Sanitiza um fragmento HTML. $legacyDom força DOMDocument (testes). */
    public static function clean(string $html, bool $legacyDom = false): string
    {
        if (trim($html) === '') {
            return '';
        }
        // 1ª passada: filtro rápido por regex (script/iframe/object/embed/form/style, on*, javascript:)
        $html = self::quickFilter($html);

        $dom = null;
        $root = null;
        $isHtml5 = false;
        $prev = libxml_use_internal_errors(true);
        try {
            if (!$legacyDom && class_exists('Dom\HTMLDocument')) {
                $dom  = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
                $root = $dom->body;
                $isHtml5 = true;
            } else {
                $dom = new \DOMDocument('1.0', 'UTF-8');
                $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $root = $dom->getElementsByTagName('body')->item(0);
            }
        } catch (\Throwable) {
            $dom = null;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$dom || !$root) {
            return strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><table><thead><tbody><tr><td><th><span><div><sub><sup><hr>');
        }

        self::walk($root);

        if ($isHtml5) {
            return (string) $root->innerHTML;
        }
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $dom->saveHTML($c);
        }
        return $out;
    }

    /** Filtro rápido por regex (compatível com o comportamento anterior do núcleo). */
    public static function quickFilter(string $html): string
    {
        $html = preg_replace('#<\s*(script|iframe|object|embed|form|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(script|iframe|object|embed|form)\b[^>]*/?\s*>#i', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', ' $1="#"', $html) ?? $html;
        return $html;
    }

    /** Percorre em pós-ordem (filhos antes) para poder remover/desembrulhar nós. */
    private static function walk(object $node): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            $type = $child->nodeType;
            if ($type === XML_ELEMENT_NODE) {
                $tag = strtolower((string) $child->localName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true) || str_contains($tag, ':')) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                self::walk($child);
                self::attrs($child, $tag);
            } elseif ($type === XML_COMMENT_NODE || $type === XML_PI_NODE || $type === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function attrs(object $el, string $tag): void
    {
        $ok = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);
        $attrs = [];
        foreach ($el->attributes as $a) {
            $attrs[] = [(string) $a->nodeName, (string) $a->nodeValue];
        }
        foreach ($attrs as [$name, $value]) {
            $lname = strtolower($name);
            if (!in_array($lname, $ok, true) || str_starts_with($lname, 'on')) {
                $el->removeAttribute($name);
                continue;
            }
            if ($lname === 'href' || $lname === 'src') {
                $new = self::url($value, $lname === 'src');
                if ($new === null) {
                    $el->removeAttribute($name);
                    continue;
                }
            } elseif ($lname === 'style') {
                $new = self::style($value);
                if ($new === '') {
                    $el->removeAttribute($name);
                    continue;
                }
            } elseif ($lname === 'class') {
                $new = trim(preg_replace('/[^A-Za-z0-9_\- ]+/', '', $value) ?? '');
                if ($new === '') {
                    $el->removeAttribute($name);
                    continue;
                }
            } elseif ($lname === 'target') {
                $new = in_array($value, ['_blank', '_self'], true) ? $value : '_blank';
            } elseif (in_array($lname, ['colspan','rowspan','width','height','start','value','span'], true)) {
                $new = preg_replace('/[^0-9%.]/', '', $value) ?? '';
                if ($new === '') {
                    $el->removeAttribute($name);
                    continue;
                }
            } else {
                $new = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
            }
            if ($new !== $value) {
                $el->setAttribute($name, $new);
            }
        }
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * URL de href/src: permite relativas, http(s), mailto, tel e (para src)
     * data:image/*. Qualquer outro esquema é rejeitado (null).
     */
    public static function url(string $value, bool $isSrc = false): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        $probe = strtolower(preg_replace('/[\x00-\x20\x7F]+|\x{FFFD}/u', '', $v) ?? $v);
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m)) {
            $scheme = $m[1];
            if ($scheme === 'data') {
                return $isSrc && preg_match('#^data:image/(png|jpe?g|gif|webp|bmp|svg\+xml);base64,[a-z0-9+/=\s]+$#i', $v) ? $v : null;
            }
            if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
                return null;
            }
        }
        return preg_replace('/[\x00-\x1F\x7F]/', '', $v);
    }

    /** Atributo style: bloqueia url(), expression(), import, binding e esquemas. */
    public static function style(string $value): string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
        if ($v === '') {
            return '';
        }
        $probe = strtolower(preg_replace('/\s+/', '', $v) ?? $v);
        if (preg_match('/(url\(|expression|javascript:|vbscript:|@import|behavior|binding|<|>|\\\\)/', $probe)) {
            return '';
        }
        return $v;
    }
}
