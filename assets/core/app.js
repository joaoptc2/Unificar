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

    // ── Sino de notificações ────────────────────────────────────────────
    //
    // Antes: uma consulta de 60 em 60 segundos que só trazia o número — uma
    // notificação criada logo após a consulta demorava um minuto para
    // aparecer. Agora a cadência é a do chat (poucos segundos com a aba à
    // frente), a lista do sino se atualiza junto e a chegada é anunciada na
    // hora. Com a aba escondida o intervalo se alarga sozinho: ninguém
    // precisa de notificação instantânea numa aba que não está sendo vista.
    //
    // É UM poller só para o portal inteiro: os módulos penduram seus
    // contadores aqui (window.PortalNotificacoes.aoAtualizar) em vez de
    // abrirem cada um o seu.
    window.PortalNotificacoes = (function () {
        var badge   = document.getElementById('portalBellBadge');
        var lista   = document.getElementById('portalNotifList');
        var ativo   = 5000;      // aba à frente (o servidor manda o valor)
        var oculto  = 20000;     // aba em segundo plano
        var timer   = null;
        var ultimoId = 0;
        var falhas  = 0;
        var buscando = false;
        var ouvintes = [];
        var primeira = true;

        function intervalo() {
            var base = document.hidden ? oculto : ativo;
            // Erro seguido afasta as tentativas (até 2 min): servidor fora do
            // ar não deve virar uma enxurrada de pedidos.
            return falhas > 0 ? Math.min(base * Math.pow(2, falhas), 120000) : base;
        }

        function agendar() {
            clearTimeout(timer);
            timer = setTimeout(buscar, intervalo());
        }

        function pintarBadge(n) {
            if (!badge) return;
            badge.textContent = n > 99 ? '99+' : n;
            badge.classList.toggle('d-none', n === 0);
        }

        function esc(t) {
            var d = document.createElement('div');
            d.textContent = t == null ? '' : String(t);
            return d.innerHTML;
        }

        function quando(iso) {
            var t = Date.parse(String(iso).replace(' ', 'T'));
            if (isNaN(t)) return '';
            var seg = Math.max(0, Math.floor((Date.now() - t) / 1000));
            if (seg < 60)    return 'agora';
            if (seg < 3600)  return 'há ' + Math.floor(seg / 60) + ' min';
            if (seg < 86400) return 'há ' + Math.floor(seg / 3600) + ' h';
            var d = new Date(t);
            return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
        }

        function pintarLista(itens) {
            if (!lista) return;
            if (!itens.length) {
                lista.innerHTML = '<div class="px-3 py-3 text-muted small">Nenhuma notificação.</div>';
                return;
            }
            lista.innerHTML = itens.map(function (n) {
                var url = n.link ? baseUrl + '/index.php?m=auth&a=notification_open&id=' + n.id : '#';
                return '<a class="dropdown-item text-wrap py-2 ' + (n.read ? 'text-muted' : 'fw-semibold') + '" href="' + esc(url) + '">'
                     + '<span class="badge text-bg-light border me-1">' + esc(n.module || 'portal') + '</span>'
                     + esc(n.title)
                     + '<div class="small text-muted fw-normal">' + esc(quando(n.created_at)) + '</div>'
                     + '</a>';
            }).join('');
        }

        // Quais itens são novos desde a última resposta. O cálculo é aqui, e
        // não no servidor, porque o servidor só sabe comparar com o 'since'
        // que recebeu — e na primeira carga de uma caixa vazia esse since é
        // 0, o que fazia a primeira notificação da sessão nunca ser anunciada.
        function novidades(itens) {
            if (primeira) return [];
            return itens.filter(function (n) { return n.id > ultimoId; });
        }

        function anunciar(novas) {
            // A primeira resposta não anuncia: o que já estava lá quando a
            // página abriu não é novidade para quem acabou de chegar.
            if (primeira || !novas.length) return;
            novas.slice(0, 3).forEach(function (n) {
                if (window.PortalAvisos && window.PortalAvisos.mostrar) {
                    window.PortalAvisos.mostrar(n);
                }
            });
        }

        function buscar() {
            if (buscando || !baseUrl) { agendar(); return; }
            buscando = true;
            fetch(baseUrl + '/index.php?m=auth&a=notifications_feed&since=' + ultimoId,
                  { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(function (d) {
                    falhas = 0;
                    if (d.poll) {
                        ativo  = Math.max(2000, (d.poll.ativo  || 5) * 1000);
                        oculto = Math.max(5000, (d.poll.oculto || 20) * 1000);
                    }
                    var itens = d.items || [];
                    var novas = novidades(itens);
                    pintarBadge(d.count || 0);
                    pintarLista(itens);
                    anunciar(novas);
                    ultimoId = Math.max(ultimoId, d.last_id || 0);
                    d.novas = novas;   // os módulos recebem a mesma lista
                    ouvintes.forEach(function (fn) { try { fn(d); } catch (e) { /* um módulo não derruba os outros */ } });
                    primeira = false;
                })
                .catch(function () { falhas = Math.min(falhas + 1, 5); })
                .then(function () { buscando = false; agendar(); });
        }

        function acordar() {
            // Voltar para a aba busca na hora: é o momento em que a pessoa
            // olha para o sino.
            if (!document.hidden) { clearTimeout(timer); buscar(); }
            else { agendar(); }
        }

        if (badge || lista) {
            document.addEventListener('visibilitychange', acordar);
            window.addEventListener('focus', acordar);
            // Abrir o sino também atualiza antes de mostrar.
            var sino = document.getElementById('portalBell');
            if (sino) sino.addEventListener('click', function () { clearTimeout(timer); buscar(); });
            buscar();
        }

        return {
            /** Força uma atualização agora (após uma ação que gera notificação). */
            atualizar: function () { clearTimeout(timer); buscar(); },
            /** Módulos penduram aqui seu contador em vez de abrir outro poller. */
            aoAtualizar: function (fn) { if (typeof fn === 'function') ouvintes.push(fn); },
        };
    })();

    // Avisos de canto: mostram a notificação que acabou de chegar.
    window.PortalAvisos = (function () {
        var caixa = null;

        function container() {
            if (caixa) return caixa;
            caixa = document.createElement('div');
            caixa.className = 'portal-avisos';
            caixa.setAttribute('aria-live', 'polite');
            document.body.appendChild(caixa);
            return caixa;
        }

        return {
            mostrar: function (n) {
                var el = document.createElement('div');
                el.className = 'portal-aviso';
                var titulo = document.createElement('div');
                titulo.className = 'portal-aviso-titulo';
                titulo.textContent = n.title || 'Notificação';
                var corpo = document.createElement('div');
                corpo.className = 'portal-aviso-corpo';
                corpo.textContent = n.message || '';
                el.appendChild(titulo);
                if (n.message) el.appendChild(corpo);
                if (n.link) {
                    el.classList.add('portal-aviso-link');
                    el.setAttribute('role', 'link');
                    el.setAttribute('tabindex', '0');
                    var ir = function () { window.location.href = baseUrl + '/index.php?m=auth&a=notification_open&id=' + n.id; };
                    el.addEventListener('click', ir);
                    el.addEventListener('keydown', function (e) { if (e.key === 'Enter') ir(); });
                }
                container().appendChild(el);
                requestAnimationFrame(function () { el.classList.add('visivel'); });
                setTimeout(function () {
                    el.classList.remove('visivel');
                    setTimeout(function () { el.remove(); }, 300);
                }, 8000);
            }
        };
    })();

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
