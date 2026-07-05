/* ═══════════════════════════════════════════════════════════════════════════
   RH Hospital — Comportamentos JS globais
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    // ── 1. Auto-dismiss de alerts em 5s ──────────────────────────────────
    function initAlerts() {
        document.querySelectorAll('.alert.alert-dismissible').forEach(function (el) {
            if (el.dataset.noAutoDismiss === '1') return;
            setTimeout(function () {
                try {
                    var instance = bootstrap.Alert.getOrCreateInstance(el);
                    instance.close();
                } catch (e) { el.remove(); }
            }, 5000);
        });
    }

    // ── 2. Máscaras: cpf / phone / cep ───────────────────────────────────
    function mask(el, type) {
        el.addEventListener('input', function () {
            var v = el.value.replace(/\D/g, '');
            if (type === 'cpf') {
                v = v.slice(0, 11);
                v = v.replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else if (type === 'phone') {
                v = v.slice(0, 11);
                if (v.length > 10) {
                    v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
                } else if (v.length > 6) {
                    v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
                } else if (v.length > 2) {
                    v = v.replace(/^(\d{2})(\d{0,5}).*/, '($1) $2');
                }
            } else if (type === 'cep') {
                v = v.slice(0, 8);
                v = v.replace(/^(\d{5})(\d{0,3}).*/, '$1-$2').replace(/-$/, '');
            }
            el.value = v;
        });
    }
    function initMasks() {
        document.querySelectorAll('[data-mask]').forEach(function (el) {
            mask(el, el.dataset.mask);
        });
    }

    // ── 3. Preview de imagem em upload ───────────────────────────────────
    function initPreviews() {
        document.querySelectorAll('[data-preview]').forEach(function (input) {
            var target = document.querySelector(input.dataset.preview);
            if (!target) return;
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) { target.style.display = 'none'; return; }
                var reader = new FileReader();
                reader.onload = function (e) {
                    if (target.tagName === 'IMG') target.src = e.target.result;
                    target.style.display = '';
                };
                reader.readAsDataURL(file);
            });
        });
    }

    // ── 4. Confirmação de ações destrutivas ──────────────────────────────
    function initConfirm() {
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-confirm]');
            if (!el) return;
            var msg = el.getAttribute('data-confirm');
            if (!window.confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
        // Também intercepta submit de forms com data-confirm
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form.dataset && form.dataset.confirm) {
                if (!window.confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            }
        }, true);
    }

    // ── 5. Toggle da sidebar em mobile (offcanvas via Bootstrap) ─────────
    // Bootstrap JS já lida com [data-bs-toggle="offcanvas"] + data-bs-target.

    // ── 6. Tooltips ──────────────────────────────────────────────────────
    function initTooltips() {
        if (!window.bootstrap || !bootstrap.Tooltip) return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }

    // ── 7. Atalho Ctrl+K para busca global ───────────────────────────────
    function initSearchShortcut() {
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                var modal = document.getElementById('globalSearchModal');
                if (modal && window.bootstrap) {
                    e.preventDefault();
                    var m = bootstrap.Modal.getOrCreateInstance(modal);
                    m.show();
                }
            }
        });
    }

    // ── 8. Badge de notificações (atualiza via API, se disponível) ───────
    function initNotificationBadge() {
        var badge = document.getElementById('notif-badge');
        if (!badge) return;
        var url = document.body.getAttribute('data-notif-url');
        if (!url) return;
        function update() {
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.count > 0) {
                        badge.textContent = d.count > 99 ? '99+' : d.count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                })
                .catch(function () {});
        }
        update();
        setInterval(update, 60000);
    }

    // ── Init ─────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initAlerts();
        initMasks();
        initPreviews();
        initConfirm();
        initTooltips();
        initSearchShortcut();
        initNotificationBadge();
    });
})();
