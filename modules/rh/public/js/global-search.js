/**
 * Busca global (Ctrl+K) — UI client-side para o endpoint
 * ?page=search&action=q&q=...
 */
(function () {
    const modalEl   = document.getElementById('globalSearchModal');
    const inputEl   = document.getElementById('globalSearchInput');
    const resultsEl = document.getElementById('globalSearchResults');
    if (!modalEl || !inputEl || !resultsEl) return;

    const modal = new bootstrap.Modal(modalEl);
    let debounceTimer = null;
    let lastQuery = '';
    let activeIndex = -1;

    // Atalho Ctrl+K (Cmd+K em Mac)
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            modal.show();
        }
    });

    modalEl.addEventListener('shown.bs.modal', function () {
        inputEl.value = '';
        resultsEl.innerHTML = '<div class="p-3 text-muted small text-center">Digite ao menos 2 caracteres para buscar.</div>';
        activeIndex = -1;
        inputEl.focus();
    });

    inputEl.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(performSearch, 200);
    });

    inputEl.addEventListener('keydown', function (e) {
        const links = resultsEl.querySelectorAll('a.search-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(links.length - 1, activeIndex + 1);
            updateActive(links);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(0, activeIndex - 1);
            updateActive(links);
        } else if (e.key === 'Enter' && activeIndex >= 0 && links[activeIndex]) {
            e.preventDefault();
            window.location.href = links[activeIndex].href;
        }
    });

    function updateActive(links) {
        links.forEach(function (l, i) {
            l.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) l.scrollIntoView({ block: 'nearest' });
        });
    }

    function performSearch() {
        const q = inputEl.value.trim();
        if (q.length < 2) {
            resultsEl.innerHTML = '<div class="p-3 text-muted small text-center">Digite ao menos 2 caracteres para buscar.</div>';
            return;
        }
        if (q === lastQuery) return;
        lastQuery = q;
        resultsEl.innerHTML = '<div class="p-3 text-muted small text-center"><span class="spinner-border spinner-border-sm me-2"></span> Buscando...</div>';

        fetch('index.php?page=search&action=q&q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { renderResults(data); })
            .catch(function () {
                resultsEl.innerHTML = '<div class="p-3 text-danger small text-center">Erro ao buscar.</div>';
            });
    }

    function renderResults(data) {
        if (!data.groups || data.groups.length === 0) {
            resultsEl.innerHTML = '<div class="p-3 text-muted small text-center">Nenhum resultado encontrado.</div>';
            return;
        }
        let html = '<div class="list-group list-group-flush">';
        data.groups.forEach(function (g) {
            html += '<div class="px-3 py-1 small fw-bold text-muted bg-light">' +
                    '<i class="bi ' + g.icon + ' me-1"></i> ' + escapeHtml(g.label) + '</div>';
            g.items.forEach(function (it) {
                html += '<a href="' + escapeAttr(it.url) + '" class="list-group-item list-group-item-action search-item py-2">' +
                          '<div class="fw-semibold">' + highlight(escapeHtml(it.title), data.query) + '</div>' +
                          '<small class="text-muted">' + highlight(escapeHtml(it.subtitle), data.query) + '</small>' +
                        '</a>';
            });
        });
        html += '</div>';
        resultsEl.innerHTML = html;
        activeIndex = 0;
        const links = resultsEl.querySelectorAll('a.search-item');
        updateActive(links);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function escapeAttr(s) { return escapeHtml(s); }
    function highlight(text, q) {
        if (!q) return text;
        const safe = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        try {
            return text.replace(new RegExp('(' + safe + ')', 'gi'), '<mark>$1</mark>');
        } catch (e) {
            return text;
        }
    }
})();
