/* ============================================================
   PLATAFORMA UNIFICADA — JS do núcleo
   - polling do sino de notificações unificado (60s)
   - auto-dismiss de alerts
   - confirmação via data-confirm
   ============================================================ */
/**
 * Cores do tema ao alcance do JavaScript.
 *
 * Os gráficos (Chart.js) e o editor de diagramas precisam de valores
 * concretos, não de variáveis CSS. Ler as variáveis aqui, uma vez, evita que
 * cada tela repita a própria paleta — era assim que os rótulos cinza-escuro
 * ficavam ilegíveis quando o hospital escolhia um tema escuro.
 */
window.PortalTheme = (function () {
    function v(nome, padrao) {
        var s = getComputedStyle(document.documentElement).getPropertyValue(nome).trim();
        return s || padrao;
    }
    function api() {
        var t = {
            primary:  v('--portal-primary', '#0d5c8f'),
            accent:   v('--portal-accent', '#0f9d8f'),
            success:  v('--portal-success', '#198754'),
            warning:  v('--portal-warning', '#ffc107'),
            danger:   v('--portal-danger', '#dc3545'),
            info:     v('--portal-info', '#0dcaf0'),
            text:     v('--portal-text', '#212529'),
            muted:    v('--portal-muted', '#6c757d'),
            surface:  v('--portal-surface', '#ffffff'),
            border:   v('--portal-border', 'rgba(0,0,0,.09)'),
            grid:     v('--portal-border', 'rgba(0,0,0,.09)')
        };
        // Paleta categórica para séries: começa pela marca e segue pelas
        // cores de estado, que o administrador também escolhe.
        t.palette = [t.primary, t.accent, t.success, t.warning, t.danger, t.info,
                     v('--portal-primary-light', '#6295b6'), t.muted];
        return t;
    }
    var cache = null;
    window.addEventListener('portal:tema', function () { cache = null; });
    return {
        get: function () { return (cache = cache || api()); },
        color: function (nome) { return this.get()[nome]; },
        palette: function () { return this.get().palette; },
        /** Aplica ao Chart.js os padrões do tema (rótulos, grade, fonte). */
        applyChartDefaults: function () {
            if (!window.Chart) { return; }
            var t = this.get();
            Chart.defaults.color = t.muted;
            Chart.defaults.borderColor = t.grid;
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        }
    };
})();

(function () {
    'use strict';

    var baseUrl = (document.querySelector('meta[name="base-url"]') || {}).content || '';

    // Sino de notificações: atualiza o contador periodicamente
    var badge = document.getElementById('portalBellBadge');
    if (badge && baseUrl) {
        setInterval(function () {
            fetch(baseUrl + '/index.php?m=auth&a=notifications_count', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) return;
                    var n = data.count || 0;
                    badge.textContent = n > 99 ? '99+' : n;
                    badge.classList.toggle('d-none', n === 0);
                })
                .catch(function () { /* silencioso */ });
        }, 60000);
    }

    // Auto-dismiss de alerts (8s)
    document.querySelectorAll('.portal-main > .alert').forEach(function (el) {
        setTimeout(function () {
            if (window.bootstrap && el.isConnected) {
                bootstrap.Alert.getOrCreateInstance(el).close();
            }
        }, 8000);
    });

    // ---- Tema claro/escuro por usuário ----------------------------------
    // A escolha vale só neste navegador (localStorage): não é preferência de
    // conta, é conforto de quem está na frente da tela. O <html> já nasce com
    // o atributo certo — um script no <head> o aplica antes da primeira
    // pintura, para a tela não piscar clara antes de ficar escura.
    var temaBtn = document.getElementById('portalTemaBtn');
    if (temaBtn) {
        var rotulo = function () {
            var atual = document.documentElement.getAttribute('data-portal-theme');
            var span = temaBtn.querySelector('span');
            if (span) {
                span.textContent = atual === 'escuro' ? 'Mudar para tema claro'
                                 : (atual === 'claro' ? 'Mudar para tema escuro' : 'Tema claro/escuro');
            }
        };
        rotulo();
        temaBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var d = document.documentElement;
            var atual = d.getAttribute('data-portal-theme');
            var escuroAgora = atual
                ? atual === 'escuro'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;
            var novo = escuroAgora ? 'claro' : 'escuro';
            d.setAttribute('data-portal-theme', novo);
            try { localStorage.setItem('portalTema', novo); } catch (err) {}
            rotulo();
            // Os gráficos leem as cores uma vez, ao desenhar.
            window.dispatchEvent(new Event('portal:tema'));
        });
    }

    // ---- Menu lateral recolhível ----------------------------------------
    var sideBtn = document.getElementById('portalSidebarToggle');
    if (sideBtn) {
        var pinta = function () {
            var recolhido = document.documentElement.getAttribute('data-portal-sidebar') === 'recolhido';
            var icone = sideBtn.querySelector('i');
            if (icone) {
                icone.className = 'bi bi-chevron-double-' + (recolhido ? 'right' : 'left');
            }
            sideBtn.setAttribute('aria-label', recolhido ? 'Expandir menu' : 'Recolher menu');
        };
        pinta();
        sideBtn.addEventListener('click', function () {
            var d = document.documentElement;
            var recolhido = d.getAttribute('data-portal-sidebar') === 'recolhido';
            if (recolhido) {
                d.removeAttribute('data-portal-sidebar');
            } else {
                d.setAttribute('data-portal-sidebar', 'recolhido');
            }
            try {
                localStorage.setItem('portalMenu', recolhido ? 'aberto' : 'recolhido');
            } catch (err) {}
            pinta();
            // Canvas (gráficos e o editor de diagramas) não se redimensiona
            // sozinho quando a largura disponível muda.
            setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 200);
        });
    }

    // Confirmação genérica
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm') || 'Confirmar?')) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
})();
