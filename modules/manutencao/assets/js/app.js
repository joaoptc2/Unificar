/* ==========================================================================
   ManuHosp — Comportamentos JS globais
   ========================================================================== */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * 1. Auto-dismiss de alerts dispensáveis (5s)
     * ------------------------------------------------------------------ */
    setTimeout(function () {
        document.querySelectorAll('.alert.alert-dismissible.fade.show').forEach(function (el) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                bootstrap.Alert.getOrCreateInstance(el).close();
            }
        });
    }, 5000);

    /* ------------------------------------------------------------------
     * 2. Máscaras simples (CPF, phone, CEP)
     * ------------------------------------------------------------------ */
    function applyMask(value, mask) {
        var digits = value.replace(/\D/g, '');
        var out = '';
        var di = 0;
        for (var i = 0; i < mask.length && di < digits.length; i++) {
            var m = mask[i];
            if (m === '0') {
                out += digits[di++];
            } else {
                out += m;
            }
        }
        return out;
    }
    var masks = {
        cpf:   '000.000.000-00',
        phone: '(00) 00000-0000',
        cep:   '00000-000'
    };
    document.querySelectorAll('[data-mask]').forEach(function (input) {
        var type = input.getAttribute('data-mask');
        if (!masks[type]) return;
        input.addEventListener('input', function () {
            input.value = applyMask(input.value, masks[type]);
        });
    });

    /* ------------------------------------------------------------------
     * 3. Preview de imagem em upload
     * ------------------------------------------------------------------ */
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        var target = document.querySelector(input.getAttribute('data-preview'));
        if (!target) return;
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                target.src = e.target.result;
                target.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    });

    /* ------------------------------------------------------------------
     * 4. Confirmação via data-confirm="Mensagem?"
     * ------------------------------------------------------------------ */
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-confirm]');
        if (!el) return;
        var msg = el.getAttribute('data-confirm');
        if (!confirm(msg)) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    });
    // Em forms com data-confirm no submit
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!confirm(form.getAttribute('data-confirm'))) {
                ev.preventDefault();
            }
        });
    });

    /* ------------------------------------------------------------------
     * 5. Tooltips Bootstrap
     * ------------------------------------------------------------------ */
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }

    /* ------------------------------------------------------------------
     * 6. Helpers de modal expostos globalmente (compatibilidade)
     * ------------------------------------------------------------------ */
    window.openModal = function (id) {
        var el = document.getElementById(id);
        if (!el || typeof bootstrap === 'undefined') return;
        bootstrap.Modal.getOrCreateInstance(el).show();
    };
    window.closeModal = function (id) {
        var el = document.getElementById(id);
        if (!el || typeof bootstrap === 'undefined') return;
        var inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
    };
    window.confirmDelete = function () {
        return confirm('Tem certeza que deseja excluir? Esta ação não pode ser desfeita.');
    };

    /* ------------------------------------------------------------------
     * 7. Marca link ativo da sidebar baseado em ?page=...
     * ------------------------------------------------------------------ */
    (function highlightSidebar() {
        var params = new URLSearchParams(window.location.search);
        var current = params.get('page') || 'dashboard';
        document.querySelectorAll('.sidebar-nav .nav-link[data-page]').forEach(function (a) {
            if (a.getAttribute('data-page') === current) {
                a.classList.add('active');
            }
        });
    })();

    /* ------------------------------------------------------------------
     * 8. Notificações push via Notification API (polling a cada 5min)
     * ------------------------------------------------------------------ */
    (function setupPushNotifs() {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'denied') return;

        var btn = document.getElementById('enableNotifBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                Notification.requestPermission().then(function (perm) {
                    if (perm === 'granted') {
                        btn.textContent = 'Notificações ativadas';
                        btn.disabled = true;
                        pollNotifications();
                    }
                });
            });
        }

        if (Notification.permission !== 'granted') return;

        var lastCheck = parseInt(localStorage.getItem('mh_notif_last') || '0', 10);

        function pollNotifications() {
            fetch('index.php?page=notifications&format=json')
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.items) return;
                    var now = Date.now();
                    data.items.forEach(function (n) {
                        var ts = new Date(n.created_at).getTime();
                        if (ts > lastCheck) {
                            new Notification(n.title || 'ManuHosp', {
                                body: n.message || '',
                                icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%230d6efd" rx="20" width="100" height="100"/><text x="50" y="62" font-size="50" text-anchor="middle" fill="white">🏥</text></svg>'
                            });
                        }
                    });
                    localStorage.setItem('mh_notif_last', String(now));
                })
                .catch(function () {});
        }

        pollNotifications();
        setInterval(pollNotifications, 300000);
    })();
})();
