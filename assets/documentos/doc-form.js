/* ═══════════════════════════════════════════════════════════════════════════
   Documentos — formulário de documento: origem (arquivo × editor), layouts do
   hospital (fonte/tamanho permitidos por layout), capa opcional.
   Depende de window.DOC_FORM_CFG (definido na view) e, quando disponível,
   de Quill + DocEditor (assets/core/doc-editor.js). Sem Quill (ex.: CDN
   indisponível) cai para um <textarea> com o HTML.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    var cfg = window.DOC_FORM_CFG || {};
    var form = document.getElementById('docForm');
    if (!form) return;

    var srcUpload = document.getElementById('srcUpload');
    var srcEditor = document.getElementById('srcEditor');
    var uploadBox = document.getElementById('srcUploadBox');
    var editorBox = document.getElementById('srcEditorBox');
    var contentHidden = document.getElementById('contentHtml');
    var coverHidden   = document.getElementById('coverHtml');

    function toggleSource() {
        var isEditor = srcEditor && srcEditor.checked;
        if (uploadBox) uploadBox.hidden = !!isEditor;
        if (editorBox) editorBox.hidden = !isEditor;
        if (isEditor && !editorsReady) initEditors();
    }
    if (srcUpload) srcUpload.addEventListener('change', toggleSource);
    if (srcEditor) srcEditor.addEventListener('change', toggleSource);

    // ── Editores ──────────────────────────────────────────────────────────
    var editorsReady = false;
    var quill = null, coverQuill = null;
    var layoutSelect = document.getElementById('layoutSelect');
    var fontSelect   = document.getElementById('fontSelect');
    var sizeSelect   = document.getElementById('sizeSelect');
    var coverSelect  = document.getElementById('coverLayoutSelect');
    var coverBox     = document.getElementById('coverBox');

    function layoutCfg(id) { return (cfg.layouts && cfg.layouts[String(id)]) || null; }

    function fillSelect(sel, list, current, fallback) {
        if (!sel) return;
        var prev = current !== undefined ? current : sel.value;
        sel.innerHTML = '';
        var found = false;
        list.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v; o.textContent = v;
            if (v === prev) { o.selected = true; found = true; }
            sel.appendChild(o);
        });
        if (!found && fallback && list.indexOf(fallback) !== -1) sel.value = fallback;
    }

    /** Recarrega fontes/tamanhos permitidos pelo layout e ajusta o editor. */
    function applyLayout(keepSelection) {
        var lc = layoutCfg(layoutSelect ? layoutSelect.value : cfg.currentLayout);
        if (!lc) return;
        fillSelect(fontSelect, lc.fonts || [], keepSelection ? undefined : (cfg.currentFont || lc.defaultFont), lc.defaultFont);
        fillSelect(sizeSelect, lc.sizes || [], keepSelection ? undefined : (cfg.currentSize || lc.defaultSize), lc.defaultSize);
        applyFont();
        if (window.DocEditor && quill) {
            DocEditor.applyLayout(quill, {
                sheetWidth: lc.sheetWidth, marginLeft: lc.marginLeft, marginRight: lc.marginRight,
                defaultFont: fontSelect ? fontSelect.value : lc.defaultFont,
                defaultSize: sizeSelect ? sizeSelect.value : lc.defaultSize
            });
        }
    }

    /** Fonte/tamanho do documento refletidos no editor (WYSIWYG). */
    function applyFont() {
        var root = quill ? quill.root : document.getElementById('editor');
        if (!root) return;
        var f = fontSelect ? fontSelect.value : '';
        var s = sizeSelect ? sizeSelect.value : '';
        root.style.fontFamily = f ? "'" + f.replace(/'/g, '') + "'" : '';
        root.style.fontSize = s || '';
    }

    function toggleCover() {
        var has = coverSelect && coverSelect.value !== '';
        if (coverBox) coverBox.hidden = !has;
        if (has && coverQuill && coverQuill.update) coverQuill.update();
    }

    /** Sem Quill: textarea com o HTML (o servidor sanitiza). */
    function fallbackTextarea(el, hidden) {
        var ta = document.createElement('textarea');
        ta.className = 'form-control font-monospace';
        ta.rows = 14;
        ta.value = hidden.value || el.innerHTML;
        el.parentNode.replaceChild(ta, el);
        form.addEventListener('submit', function () { hidden.value = ta.value; });
        return ta;
    }

    function initEditors() {
        editorsReady = true;
        if (!cfg.hasEditor) return;
        var el = document.getElementById('editor');
        var coverEl = document.getElementById('coverEditor');
        var lc = layoutCfg(layoutSelect ? layoutSelect.value : cfg.currentLayout) || {};

        if (window.Quill && window.DocEditor) {
            quill = DocEditor.init(el, {
                fonts: lc.fonts, sizes: lc.sizes,
                defaultFont: cfg.currentFont || lc.defaultFont, defaultSize: cfg.currentSize || lc.defaultSize,
                sheetWidth: lc.sheetWidth, marginLeft: lc.marginLeft, marginRight: lc.marginRight,
                hiddenInput: '#contentHtml', noWarn: true
            });
            if (coverEl) {
                coverQuill = DocEditor.init(coverEl, {
                    fonts: lc.fonts, sizes: lc.sizes,
                    defaultFont: cfg.currentFont || lc.defaultFont, defaultSize: cfg.currentSize || lc.defaultSize,
                    sheetWidth: lc.sheetWidth, marginLeft: lc.marginLeft, marginRight: lc.marginRight,
                    hiddenInput: '#coverHtml', noWarn: true,
                    placeholder: 'Conteúdo da capa (título, código, setor...). Deixe vazio para usar o modelo de capa do layout.'
                });
            }
        } else {
            if (el) fallbackTextarea(el, contentHidden);
            if (coverEl) fallbackTextarea(coverEl, coverHidden);
        }
        applyLayout(false);
        toggleCover();
    }

    if (layoutSelect) layoutSelect.addEventListener('change', function () { applyLayout(true); });
    if (fontSelect)   fontSelect.addEventListener('change', applyFont);
    if (sizeSelect)   sizeSelect.addEventListener('change', applyFont);
    if (coverSelect)  coverSelect.addEventListener('change', toggleCover);

    // Estado inicial
    if (srcEditor && srcEditor.checked) initEditors();
    else applyLayout(false); // preenche os selects mesmo com o editor oculto
    toggleSource();
    toggleCover();

    // Garante os campos ocultos no submit (quando o editor está ativo)
    form.addEventListener('submit', function () {
        if (srcEditor && srcEditor.checked) {
            if (quill && contentHidden) contentHidden.value = quill.root.innerHTML;
            if (coverQuill && coverHidden) coverHidden.value = coverSelect && coverSelect.value !== '' ? coverQuill.root.innerHTML : '';
        }
    });
})();
