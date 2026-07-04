/* ============================================================
   PLATAFORMA UNIFICADA — JS do núcleo
   - polling do sino de notificações unificado (60s)
   - auto-dismiss de alerts
   - confirmação via data-confirm
   ============================================================ */
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

    // Confirmação genérica
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm') || 'Confirmar?')) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
})();
