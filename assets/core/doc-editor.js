/* ============================================================
   PLATAFORMA UNIFICADA — Editor de documentos compartilhado (Quill 2)
   Usado por Documentos, Intranet e demais módulos que editam documentos
   no papel timbrado. Restringe fontes/tamanhos aos permitidos pelo layout
   e emula a largura útil da folha.

   Uso:
     var quill = DocEditor.init('#editor', {
         fonts: ['Arial', 'Times New Roman'], sizes: ['10pt','12pt'],
         defaultFont: 'Arial', defaultSize: '12pt',
         sheetWidth: 210, marginLeft: 15, marginRight: 15,
         hiddenInput: '#contentField',   // campo sincronizado no submit
         placeholder: '...'
     });
   ============================================================ */
(function () {
    'use strict';

    function fontSlug(s) {
        return String(s).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }
    function sizeSlug(s) {
        return String(s).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-');
    }
    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    var registered = false;
    function registerFormats(fonts, sizes) {
        if (registered || !window.Quill) return;
        var Font = Quill.import('attributors/class/font');
        Font.whitelist = fonts.map(fontSlug);
        Quill.register(Font, true);
        var Size = Quill.import('attributors/class/size');
        Size.whitelist = sizes.map(sizeSlug);
        Quill.register(Size, true);
        registered = true;
    }

    function injectCss(fonts, sizes) {
        var css = '';
        fonts.forEach(function (f) {
            var s = fontSlug(f), name = String(f).replace(/'/g, '');
            css += ".ql-font-" + s + "{font-family:'" + name + "'}\n"
                 + ".ql-snow .ql-picker.ql-font .ql-picker-label[data-value=\"" + s + "\"]::before,"
                 + ".ql-snow .ql-picker.ql-font .ql-picker-item[data-value=\"" + s + "\"]::before{content:'" + name + "';font-family:'" + name + "'}\n";
        });
        sizes.forEach(function (z) {
            var s = sizeSlug(z), val = String(z).replace(/[^0-9a-z.]/gi, '');
            css += ".ql-size-" + s + "{font-size:" + val + "}\n"
                 + ".ql-snow .ql-picker.ql-size .ql-picker-label[data-value=\"" + s + "\"]::before,"
                 + ".ql-snow .ql-picker.ql-size .ql-picker-item[data-value=\"" + s + "\"]::before{content:'" + val + "'}\n";
        });
        var st = document.createElement('style');
        st.setAttribute('data-doc-editor', '1');
        st.textContent = css;
        document.head.appendChild(st);
    }

    function buildToolbar(container, fonts, sizes, full) {
        var fontOpts = fonts.map(function (f) { return '<option value="' + escAttr(fontSlug(f)) + '">' + escAttr(f) + '</option>'; }).join('');
        var sizeOpts = sizes.map(function (z) { return '<option value="' + escAttr(sizeSlug(z)) + '">' + escAttr(z) + '</option>'; }).join('');
        var html = ''
            + '<span class="ql-formats"><select class="ql-font" title="Fonte">' + fontOpts + '</select>'
            + '<select class="ql-size" title="Tamanho">' + sizeOpts + '</select></span>'
            + '<span class="ql-formats"><select class="ql-header" title="Título"><option value="1"></option><option value="2"></option><option value="3"></option><option value="4"></option><option selected></option></select></span>'
            + '<span class="ql-formats"><button class="ql-bold" title="Negrito"></button><button class="ql-italic" title="Itálico"></button>'
            + '<button class="ql-underline" title="Sublinhado"></button><button class="ql-strike" title="Tachado"></button></span>'
            + '<span class="ql-formats"><select class="ql-color" title="Cor do texto"></select><select class="ql-background" title="Cor de fundo"></select></span>'
            + '<span class="ql-formats"><button class="ql-script" value="sub" title="Subscrito"></button><button class="ql-script" value="super" title="Sobrescrito"></button></span>'
            + '<span class="ql-formats"><select class="ql-align" title="Alinhamento"></select>'
            + '<button class="ql-list" value="ordered" title="Lista numerada"></button><button class="ql-list" value="bullet" title="Lista"></button>'
            + '<button class="ql-indent" value="-1" title="Diminuir recuo"></button><button class="ql-indent" value="+1" title="Aumentar recuo"></button></span>'
            + '<span class="ql-formats"><button class="ql-blockquote" title="Citação"></button><button class="ql-code-block" title="Bloco de código"></button>'
            + '<button class="ql-link" title="Link"></button><button class="ql-image" title="Imagem"></button>'
            + (full ? '<button class="ql-video" title="Vídeo"></button>' : '') + '</span>'
            + '<span class="ql-formats"><button class="ql-clean" title="Limpar formatação"></button></span>';
        container.innerHTML = html;
    }

    window.DocEditor = {
        fontSlug: fontSlug,
        sizeSlug: sizeSlug,

        init: function (selector, opts) {
            opts = opts || {};
            var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (!el || !window.Quill) return null;

            var fonts = (opts.fonts && opts.fonts.length) ? opts.fonts : ['Arial'];
            var sizes = (opts.sizes && opts.sizes.length) ? opts.sizes : ['12pt'];
            registerFormats(fonts, sizes);
            if (!document.querySelector('style[data-doc-editor]')) injectCss(fonts, sizes);

            var toolbar = opts.toolbar ? document.querySelector(opts.toolbar) : null;
            if (!toolbar) {
                toolbar = document.createElement('div');
                toolbar.className = 'doc-editor-toolbar';
                el.parentNode.insertBefore(toolbar, el);
            }
            buildToolbar(toolbar, fonts, sizes, !!opts.full);

            var quill = new Quill(el, {
                theme: 'snow',
                placeholder: opts.placeholder || 'Escreva o conteúdo do documento...',
                readOnly: !!opts.readOnly,
                modules: { toolbar: toolbar }
            });

            this.applyLayout(quill, opts);

            var hidden = opts.hiddenInput ? document.querySelector(opts.hiddenInput) : null;
            var form = hidden ? hidden.form : null;
            var dirty = false;
            quill.on('text-change', function (d, o, source) { if (source === 'user') dirty = true; });
            if (form) {
                form.addEventListener('submit', function () {
                    if (hidden) hidden.value = quill.root.innerHTML;
                    dirty = false;
                });
            }
            if (!opts.noWarn) {
                window.addEventListener('beforeunload', function (e) {
                    if (dirty) { e.preventDefault(); e.returnValue = ''; }
                });
            }
            quill.docEditorMarkClean = function () { dirty = false; };
            quill.docEditorIsDirty = function () { return dirty; };
            return quill;
        },

        /** Aplica largura da folha/margens e fonte padrão (após trocar de layout). */
        applyLayout: function (quill, cfg) {
            if (!quill) return;
            var root = quill.root;
            var wrap = root.closest('.doc-editor-wrap');
            if (wrap && cfg.sheetWidth) {
                wrap.style.setProperty('--sheet-w', cfg.sheetWidth + 'mm');
                wrap.style.setProperty('--sheet-ml', (cfg.marginLeft || 15) + 'mm');
                wrap.style.setProperty('--sheet-mr', (cfg.marginRight || 15) + 'mm');
            }
            root.style.fontFamily = cfg.defaultFont ? "'" + String(cfg.defaultFont).replace(/'/g, '') + "'" : '';
            root.style.fontSize = cfg.defaultSize || '';
        },

        /** HTML atual do editor. */
        html: function (quill) { return quill ? quill.root.innerHTML : ''; }
    };
})();
