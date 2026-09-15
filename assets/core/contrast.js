/**
 * PortalContraste — selo de legibilidade ao lado dos seletores de cor.
 *
 * Por que a conta está repetida aqui
 * ---------------------------------
 * A razão de contraste já existe no servidor (Core\Tokens::contrastReport).
 * Repeti-la em JavaScript é, em princípio, exatamente o problema que a
 * camada de tokens veio resolver — mas o aviso precisa aparecer enquanto a
 * pessoa arrasta o seletor, e uma ida ao servidor por movimento do mouse
 * não serve.
 *
 * O acordo é: UMA cópia, neste arquivo, e um teste de paridade
 * (scripts/test_contraste.php) que compara os dois lados em dezenas de
 * pares e falha se divergirem. A fórmula é a da WCAG 2.1 — uma norma
 * publicada, não uma regra de negócio que muda.
 *
 * Marcação esperada:
 *
 *   <span data-contraste="sidebar_text|sidebar_bg"></span>
 *
 * Os dois lados são nomes de campos do formulário; um valor começando com
 * '#' é usado como cor fixa (ex.: "primary|#ffffff").
 */
(function () {
    'use strict';

    function canal(c) {
        c /= 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    }

    function rgb(hex) {
        var h = String(hex || '').trim().replace(/^#/, '');
        if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
        if (!/^[0-9a-fA-F]{6}$/.test(h)) { h = '0d5c8f'; }
        return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
    }

    function luminancia(hex) {
        var c = rgb(hex);
        return 0.2126 * canal(c[0]) + 0.7152 * canal(c[1]) + 0.0722 * canal(c[2]);
    }

    function razao(a, b) {
        var la = luminancia(a), lb = luminancia(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }

    /** Mesma saída de Core\Tokens::contrastReport(). */
    function avaliar(frente, fundo) {
        var r = razao(frente, fundo);
        var aa = r >= 4.5, aaGrande = r >= 3.0, aaa = r >= 7.0;
        var nivel = aa ? 'ok' : (aaGrande ? 'aviso' : 'erro');
        return {
            ratio: Math.round(r * 100) / 100,
            texto: r.toFixed(1).replace('.', ',') + ':1',
            aa: aa, aa_large: aaGrande, aaa: aaa,
            nivel: nivel,
            resumo: nivel === 'ok' ? (aaa ? 'AAA' : 'AA')
                  : (nivel === 'aviso' ? 'AA só em texto grande' : 'não legível')
        };
    }

    var CLASSES = { ok: 'text-bg-success', aviso: 'text-bg-warning', erro: 'text-bg-danger' };
    var ICONES  = { ok: 'bi-check-lg', aviso: 'bi-exclamation-triangle', erro: 'bi-x-lg' };

    function valor(form, ref) {
        if (!ref) { return ''; }
        if (ref.charAt(0) === '#') { return ref; }
        var el = form.querySelector('[name="' + ref + '"]');
        return el ? el.value : '';
    }

    function pintar(selo, r) {
        selo.className = 'badge ' + (CLASSES[r.nivel] || 'text-bg-secondary') + ' portal-contraste';
        selo.innerHTML = '<i class="bi ' + (ICONES[r.nivel] || 'bi-dot') + ' me-1"></i>' + r.texto + ' · ' + r.resumo;
        selo.title = r.nivel === 'ok'
            ? 'Contraste suficiente para texto normal (WCAG AA exige 4,5:1).'
            : (r.nivel === 'aviso'
                ? 'Passa só em texto grande (≥ 24px, ou 19px em negrito). Para texto normal, a WCAG AA exige 4,5:1.'
                : 'Combinação ilegível: a WCAG AA exige 4,5:1 para texto normal e 3:1 para texto grande.');
    }

    var PortalContraste = {
        avaliar: avaliar,
        razao: razao,

        /** Liga todos os selos de um formulário e os mantém atualizados. */
        ligar: function (form) {
            form = typeof form === 'string' ? document.getElementById(form) : form;
            if (!form) { return null; }

            var selos = Array.prototype.slice.call(form.querySelectorAll('[data-contraste]'));
            // Selos podem estar fora do <form> (numa coluna ao lado): procura
            // no documento os que apontam para este formulário.
            if (form.id) {
                Array.prototype.forEach.call(
                    document.querySelectorAll('[data-contraste][data-contraste-form="' + form.id + '"]'),
                    function (s) { if (selos.indexOf(s) === -1) { selos.push(s); } });
            }
            if (!selos.length) { return null; }

            function atualizar() {
                selos.forEach(function (selo) {
                    var par = (selo.getAttribute('data-contraste') || '').split('|');
                    var f = valor(form, par[0]), g = valor(form, par[1]);
                    if (!f || !g) { selo.textContent = ''; selo.className = ''; return; }
                    pintar(selo, avaliar(f, g));
                });
            }

            form.addEventListener('input', atualizar);
            form.addEventListener('change', atualizar);
            atualizar();
            return { atualizar: atualizar };
        }
    };

    window.PortalContraste = PortalContraste;
})();
