<?php
/**
 * Sanitizador de HTML do editor de documentos (conteúdo e capa).
 * Ponto único: doc_sanitize_html($html) — delega ao Core\HtmlSanitizer
 * (lista de permissão de tags/atributos via DOM), compartilhado com a
 * Intranet, os layouts e os demais módulos que usam o editor.
 */

function doc_sanitize_html($html, $force_legacy_dom = false) {
    return Core\HtmlSanitizer::clean((string) $html, (bool) $force_legacy_dom);
}
