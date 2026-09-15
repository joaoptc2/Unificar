/**
 * PortalPreview — pré-visualização ao vivo de um formulário de aparência.
 *
 * Por que existe
 * --------------
 * As três telas de personalização (Aparência, E-mail > Layout e RH >
 * Aniversariantes) tinham cada uma a sua cópia do mesmo mecanismo: junta os
 * campos, espera o usuário parar de digitar, manda para um iframe. Eram três
 * implementações com três conjuntos de defeitos possíveis — uma esquecia os
 * rádios, outra tratava caixas desmarcadas de outro jeito.
 *
 * Agora é um só. Quem adiciona uma tela nova ganha a pré-visualização de
 * graça, e qualquer correção aqui vale para todas.
 *
 * Uso:
 *
 *   PortalPreview.ligar({
 *       form:   'meuForm',            // id ou elemento
 *       quadro: 'meuIframe',          // id ou elemento (o <iframe>)
 *       acao:   '/index.php?...',     // rota que devolve o HTML da amostra
 *       metodo: 'post',               // 'post' (padrão) ou 'get'
 *       ignorar: ['custom_css'],      // campos que não vão na amostra
 *       extra:  function () { return { esquema: 'escuro' }; },
 *       espera: 400                   // ms de silêncio antes de atualizar
 *   });
 *
 * Devolve { atualizar, emNovaAba, destruir }.
 */
(function () {
    'use strict';

    function elemento(ref) {
        if (!ref) { return null; }
        return typeof ref === 'string' ? document.getElementById(ref) : ref;
    }

    /**
     * Valores do formulário, já com as regras que confundiam as três cópias:
     *  - caixa desmarcada NÃO entra (ausência = desmarcado, que é como o
     *    servidor lê o POST);
     *  - de um grupo de rádios entra só o marcado;
     *  - botões e o token não entram.
     */
    function coletar(form, ignorar) {
        var fora = {};
        (ignorar || []).forEach(function (n) { fora[n] = true; });
        fora._csrf_token = true;
        fora.csrf_token = true;

        var out = [];
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled || fora[el.name]) { return; }
            var t = (el.type || '').toLowerCase();
            if (t === 'submit' || t === 'button' || t === 'reset' || t === 'file') { return; }
            if ((t === 'checkbox' || t === 'radio') && !el.checked) { return; }
            out.push([el.name, el.value]);
        });
        return out;
    }

    var PortalPreview = {
        ligar: function (opts) {
            var form   = elemento(opts.form);
            var quadro = elemento(opts.quadro);
            if (!form || !quadro) { return null; }

            var metodo  = (opts.metodo || 'post').toLowerCase();
            var espera  = typeof opts.espera === 'number' ? opts.espera : 400;
            var nome    = quadro.name || ('portalPreview' + Math.random().toString(36).slice(2, 8));
            quadro.name = nome;

            // Formulário oculto próprio: mandar o formulário principal levaria
            // o usuário embora da página em vez de pintar o iframe.
            var envio = null;
            if (metodo === 'post') {
                envio = document.createElement('form');
                envio.method = 'post';
                envio.action = opts.acao;
                envio.target = nome;
                envio.style.display = 'none';
                document.body.appendChild(envio);
            }

            var timer = null;
            var vivo  = true;

            function paresExtra() {
                var e = typeof opts.extra === 'function' ? opts.extra() : (opts.extra || {});
                return Object.keys(e).map(function (k) { return [k, String(e[k])]; });
            }

            function atualizar(alvo) {
                if (!vivo) { return; }
                var pares = coletar(form, opts.ignorar).concat(paresExtra());

                if (metodo === 'get') {
                    var p = new URLSearchParams();
                    pares.forEach(function (par) { p.append(par[0], par[1]); });
                    var sep = opts.acao.indexOf('?') === -1 ? '?' : '&';
                    quadro.src = opts.acao + sep + p.toString();
                    return;
                }

                envio.innerHTML = '';
                // O token do formulário principal acompanha: a rota da amostra
                // valida CSRF como qualquer outro POST.
                var tok = form.querySelector('[name="_csrf_token"], [name="csrf_token"]');
                if (tok) {
                    pares.push([tok.name, tok.value]);
                }
                pares.forEach(function (par) {
                    var i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = par[0];
                    i.value = par[1];
                    envio.appendChild(i);
                });
                envio.target = alvo || nome;
                envio.submit();
                envio.target = nome;
                if (typeof opts.aoAtualizar === 'function') { opts.aoAtualizar(); }
            }

            function agendar() {
                clearTimeout(timer);
                timer = setTimeout(function () { atualizar(); }, espera);
            }

            // 'input' pega digitação e arrastar de controles; 'change' pega
            // seleção e caixas, e responde mais rápido porque já é definitivo.
            form.addEventListener('input', agendar);
            form.addEventListener('change', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { atualizar(); }, Math.min(espera, 150));
            });

            atualizar();   // primeira carga

            return {
                atualizar: function () { clearTimeout(timer); atualizar(); },
                emNovaAba: function () {
                    if (metodo === 'get') { window.open(quadro.src, '_blank'); }
                    else { atualizar('_blank'); }
                },
                destruir: function () {
                    vivo = false;
                    clearTimeout(timer);
                    if (envio && envio.parentNode) { envio.parentNode.removeChild(envio); }
                }
            };
        }
    };

    window.PortalPreview = PortalPreview;
})();
