/* PLANEJAMENTO — quadro Kanban / Scrum (JS vanilla, sem dependências).
 * Colunas, cartões arrastáveis (drag & drop HTML5 nativo), modal de cartão
 * (checklist, comentários, vínculo com ação de plano), modal de coluna,
 * filtros e busca. Escritas via pages/api.php (POST + CSRF). */
(function () {
    'use strict';
    var D = window.BOARD_DATA;
    if (!D) { return; }

    var boardEl = document.getElementById('board');
    var columns = D.columns || [];
    var cards   = D.cards || [];
    var filters = { q: '', assignee: '', label: '' };
    var dragId  = null;

    // ------------------------------------------------------------ utilitários
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function dateBr(d) { return d ? d.substr(8, 2) + '/' + d.substr(5, 2) + '/' + d.substr(0, 4) : ''; }
    function initials(name) {
        var p = String(name || '').trim().split(/\s+/);
        return ((p[0] || '').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
    }
    function findCard(id) { return cards.filter(function (c) { return c.id === id; })[0]; }
    function findColumn(id) { return columns.filter(function (c) { return c.id === id; })[0]; }
    function cardsOf(colId) {
        return cards.filter(function (c) { return c.column_id === colId; })
            .sort(function (a, b) { return a.sort_order - b.sort_order || a.id - b.id; });
    }

    function notify(msg, type) {
        var el = document.getElementById('planToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'planToast';
            el.className = 'plan-toast';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.className = 'plan-toast show ' + (type || 'success');
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.className = 'plan-toast'; }, 4000);
    }

    function api(action, data, method) {
        method = method || 'POST';
        var url = D.apiUrl + '&action=' + encodeURIComponent(action);
        var opts = { method: method, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': D.csrf, 'Accept': 'application/json' } };
        if (method === 'GET') {
            Object.keys(data || {}).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
        } else {
            var fd = new FormData();
            Object.keys(data || {}).forEach(function (k) {
                if (Array.isArray(data[k])) { data[k].forEach(function (v) { fd.append(k + '[]', v); }); }
                else if (data[k] !== null && data[k] !== undefined) { fd.append(k, data[k]); }
            });
            opts.body = fd;
        }
        return fetch(url, opts)
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Resposta inválida (' + r.status + ')' }; }); })
            .then(function (j) {
                if (!j.ok) { throw new Error(j.error || 'Erro'); }
                if (j.columns) { columns = j.columns; }
                if (j.cards) { cards = j.cards; }
                return j;
            });
    }

    // ------------------------------------------------------------- filtros
    function visible(c) {
        if (filters.q) {
            var q = filters.q.toLowerCase();
            if ((c.title + ' ' + (c.description || '') + ' ' + (c.labels || []).join(' ')).toLowerCase().indexOf(q) < 0) { return false; }
        }
        if (filters.assignee === 'me' && c.assignee_id !== D.userId) { return false; }
        if (filters.assignee === 'none' && c.assignee_id) { return false; }
        if (filters.assignee && filters.assignee !== 'me' && filters.assignee !== 'none' && c.assignee_id !== parseInt(filters.assignee, 10)) { return false; }
        if (filters.label && (c.labels || []).indexOf(filters.label) < 0) { return false; }
        return true;
    }
    function filtersActive() { return !!(filters.q || filters.assignee || filters.label); }

    // ------------------------------------------------------------- render
    function render() {
        if (!columns.length) {
            boardEl.innerHTML = '<div class="alert alert-light border m-0">Este quadro não tem colunas.' + (D.canEdit ? ' Use "Nova coluna" para criar a primeira.' : '') + '</div>';
            updateSummary();
            return;
        }
        var html = '';
        columns.forEach(function (col) { html += renderColumn(col); });
        boardEl.innerHTML = html;
        updateSummary();
        if (D.canCards) { bindDrag(); }
    }

    function renderColumn(col) {
        var list = cardsOf(col.id);
        var count = list.length;
        var over = col.wip_limit > 0 && count > col.wip_limit;
        var pts = list.reduce(function (s, c) { return s + (c.points || 0); }, 0);
        var h = '<div class="plan-col' + (over ? ' over-wip' : '') + '" data-col="' + col.id + '" style="border-top-color:' + esc(col.color || '#adb5bd') + '">';
        h += '<div class="plan-col-head">';
        h += '<span class="plan-col-name" title="' + esc(col.name) + '">' + esc(col.name) + (col.is_done ? ' <i class="bi bi-check2-circle text-success" title="Coluna de concluídos"></i>' : '') + '</span>';
        h += '<span class="plan-col-count" title="' + (col.wip_limit ? 'Limite WIP: ' + col.wip_limit : 'Cartões') + '">' + count + (col.wip_limit ? '/' + col.wip_limit : '') + '</span>';
        if (D.board.use_points) { h += '<span class="plan-col-points" title="Pontos"><i class="bi bi-123"></i> ' + pts + '</span>'; }
        if (D.canEdit) { h += '<button type="button" class="btn btn-sm btn-link" data-act="col-edit" title="Editar coluna"><i class="bi bi-three-dots-vertical"></i></button>'; }
        h += '</div>';
        if (over) { h += '<div class="small text-danger px-2 pb-1"><i class="bi bi-exclamation-triangle"></i> Limite WIP excedido</div>'; }
        h += '<div class="plan-col-cards" data-col="' + col.id + '">';
        list.forEach(function (c) { h += renderCard(c, col); });
        h += '</div>';
        if (D.canCards) { h += '<div class="plan-col-foot"><button type="button" class="btn btn-sm btn-light w-100 text-start text-muted" data-act="card-new"><i class="bi bi-plus"></i> Cartão</button></div>'; }
        h += '</div>';
        return h;
    }

    function renderCard(c, col) {
        var hidden = !visible(c);
        var h = '<div class="plan-card' + (col.is_done || c.completed_at ? ' is-done' : '') + (hidden ? ' filtered-out' : '') + '" data-id="' + c.id + '"' + (D.canCards ? ' draggable="true"' : '') + ' style="border-left-color:' + esc(c.color || 'transparent') + '">';
        if (c.labels && c.labels.length) {
            h += '<div class="plan-card-labels">';
            c.labels.forEach(function (l) { h += '<span class="plan-card-label">' + esc(l) + '</span>'; });
            h += '</div>';
        }
        h += '<div class="plan-card-title">' + esc(c.title) + '</div>';
        h += '<div class="plan-card-meta">';
        if (c.priority === 'urgent') { h += '<span class="badge text-bg-danger">Urgente</span>'; }
        else if (c.priority === 'high') { h += '<span class="badge text-bg-warning">Alta</span>'; }
        if (c.due_date) { h += '<span class="' + (c.overdue ? 'text-danger' : '') + '" title="Prazo"><i class="bi bi-calendar-event"></i> ' + dateBr(c.due_date) + '</span>'; }
        if (D.board.use_points && c.points !== null && c.points !== undefined) { h += '<span class="plan-card-points" title="Pontos">' + c.points + '</span>'; }
        if (c.checklist && c.checklist.length) {
            var done = c.checklist.filter(function (k) { return k.done; }).length;
            h += '<span title="Checklist" class="' + (done === c.checklist.length ? 'text-success' : '') + '"><i class="bi bi-check2-square"></i> ' + done + '/' + c.checklist.length + '</span>';
        }
        if (c.comments_count) { h += '<span title="Comentários"><i class="bi bi-chat"></i> ' + c.comments_count + '</span>'; }
        if (c.plan_item_id) { h += '<span title="Ação do plano: ' + esc(c.plan_item_title) + '"><i class="bi bi-clipboard2-check"></i></span>'; }
        if (c.assignee_id) { h += '<span class="plan-card-avatar" title="' + esc(c.assignee_name) + '">' + esc(initials(c.assignee_name)) + '</span>'; }
        h += '</div></div>';
        return h;
    }

    function updateSummary() {
        var total = cards.length, shown = cards.filter(visible).length;
        var info = document.getElementById('fltInfo');
        if (info) { info.textContent = filtersActive() ? shown + ' de ' + total + ' cartão(ões)' : total + ' cartão(ões)'; }
        var pd = document.getElementById('boardPointsDone'), pt = document.getElementById('boardPointsTotal');
        if (pd && pt) {
            var tp = 0, dp = 0;
            cards.forEach(function (c) { tp += c.points || 0; if (c.completed_at) { dp += c.points || 0; } });
            pd.textContent = dp; pt.textContent = tp;
        }
    }

    // ------------------------------------------------------ drag & drop nativo
    function bindDrag() {
        boardEl.querySelectorAll('.plan-card[draggable=true]').forEach(function (el) {
            el.addEventListener('dragstart', function (e) {
                dragId = parseInt(el.getAttribute('data-id'), 10);
                el.classList.add('dragging');
                try { e.dataTransfer.setData('text/plain', String(dragId)); } catch (err) { /* IE */ }
                e.dataTransfer.effectAllowed = 'move';
            });
            el.addEventListener('dragend', function () {
                el.classList.remove('dragging');
                clearDrop();
                dragId = null;
            });
        });
        boardEl.querySelectorAll('.plan-col-cards').forEach(function (zone) {
            zone.addEventListener('dragover', function (e) {
                if (dragId === null) { return; }
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                zone.classList.add('drag-over');
                placeDropMarker(zone, e.clientY);
            });
            zone.addEventListener('dragleave', function (e) {
                if (!zone.contains(e.relatedTarget)) { zone.classList.remove('drag-over'); }
            });
            zone.addEventListener('drop', function (e) {
                e.preventDefault();
                var id = dragId || parseInt(e.dataTransfer.getData('text/plain'), 10);
                zone.classList.remove('drag-over');
                if (!id) { return; }
                var marker = zone.querySelector('.plan-card-drop');
                var colId = parseInt(zone.getAttribute('data-col'), 10);
                var ordered = [];
                Array.prototype.forEach.call(zone.children, function (ch) {
                    if (ch.classList.contains('plan-card-drop')) { ordered.push(id); }
                    else if (ch.classList.contains('plan-card')) {
                        var cid = parseInt(ch.getAttribute('data-id'), 10);
                        if (cid !== id) { ordered.push(cid); }
                    }
                });
                if (!marker) { ordered.push(id); }
                clearDrop();
                moveCard(id, colId, ordered);
            });
        });
    }
    function placeDropMarker(zone, y) {
        var marker = zone.querySelector('.plan-card-drop');
        if (!marker) { marker = document.createElement('div'); marker.className = 'plan-card-drop'; }
        var after = null;
        var list = zone.querySelectorAll('.plan-card:not(.dragging):not(.filtered-out)');
        for (var i = 0; i < list.length; i++) {
            var r = list[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) { after = list[i]; break; }
        }
        if (after) { zone.insertBefore(marker, after); } else { zone.appendChild(marker); }
    }
    function clearDrop() {
        boardEl.querySelectorAll('.plan-card-drop').forEach(function (m) { m.parentNode.removeChild(m); });
        boardEl.querySelectorAll('.drag-over').forEach(function (z) { z.classList.remove('drag-over'); });
    }
    function moveCard(id, colId, ordered) {
        var c = findCard(id);
        if (!c) { return; }
        // atualização otimista
        var prevCol = c.column_id, prevOrder = c.sort_order;
        c.column_id = colId;
        ordered.forEach(function (cid, i) { var x = findCard(cid); if (x) { x.sort_order = i; } });
        render();
        api('card_move', { id: id, column_id: colId, ids: ordered }).then(function (j) {
            render();
            if (j.warning) { notify(j.warning, 'warning'); }
        }).catch(function (err) {
            c.column_id = prevCol; c.sort_order = prevOrder;
            render();
            notify(err.message, 'error');
        });
    }

    // ------------------------------------------------------------- modais
    var backdrop = document.getElementById('planBackdrop');
    function openModal(id) {
        document.getElementById(id).classList.add('show');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeModals() {
        document.querySelectorAll('.plan-modal.show').forEach(function (m) { m.classList.remove('show'); });
        backdrop.classList.remove('show');
        document.body.style.overflow = '';
    }
    backdrop.addEventListener('click', closeModals);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModals(); } });
    document.querySelectorAll('.plan-modal [data-close]').forEach(function (b) { b.addEventListener('click', closeModals); });

    // ---- cartão -----------------------------------------------------------
    var cardModal = document.getElementById('cardModal');
    var cardForm  = document.getElementById('cardForm');
    var checklist = [];
    var currentCard = null;

    function fillColumnSelect(sel, selected) {
        sel.innerHTML = '';
        columns.forEach(function (c) {
            var o = document.createElement('option');
            o.value = c.id; o.textContent = c.name; o.selected = c.id === selected;
            sel.appendChild(o);
        });
    }
    function fillPlanItems(selected) {
        var sel = document.getElementById('cardPlanItem');
        sel.innerHTML = '<option value="">—</option>';
        (D.planItems || []).forEach(function (i) {
            var o = document.createElement('option');
            o.value = i.id; o.textContent = (i.plan ? i.plan + ' › ' : '') + i.title; o.selected = i.id === selected;
            sel.appendChild(o);
        });
        if (selected && !sel.value && currentCard && currentCard.plan_item_title) {
            var o2 = document.createElement('option');
            o2.value = selected; o2.textContent = currentCard.plan_item_title; o2.selected = true;
            sel.appendChild(o2);
        }
    }
    function renderChecklist() {
        var host = document.getElementById('cardChecklist');
        host.innerHTML = '';
        checklist.forEach(function (k, i) {
            var row = document.createElement('div');
            row.className = 'form-check d-flex align-items-center gap-2';
            row.innerHTML = '<input class="form-check-input" type="checkbox" id="ck' + i + '"' + (k.done ? ' checked' : '') + (D.canCards ? '' : ' disabled') + '>'
                + '<label class="form-check-label flex-grow-1' + (k.done ? ' text-decoration-line-through text-muted' : '') + '" for="ck' + i + '">' + esc(k.text) + '</label>'
                + (D.canCards ? '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-ck-del="' + i + '" title="Remover"><i class="bi bi-x"></i></button>' : '');
            host.appendChild(row);
            row.querySelector('input').addEventListener('change', function (e) {
                checklist[i].done = e.target.checked ? 1 : 0;
                if (currentCard && currentCard.id) {
                    api('checklist_toggle', { id: currentCard.id, index: i, done: checklist[i].done }).then(render).catch(function (err) { notify(err.message, 'error'); });
                }
                renderChecklist();
            });
        });
        if (!checklist.length) { host.innerHTML = '<div class="small text-muted">Sem itens.</div>'; }
    }
    document.getElementById('cardChecklist').addEventListener('click', function (e) {
        var b = e.target.closest('[data-ck-del]');
        if (!b) { return; }
        checklist.splice(parseInt(b.getAttribute('data-ck-del'), 10), 1);
        renderChecklist();
    });
    var ckAdd = document.getElementById('cardChecklistAdd'), ckNew = document.getElementById('cardChecklistNew');
    function addChecklistItem() {
        var t = ckNew.value.trim();
        if (!t) { return; }
        checklist.push({ text: t, done: 0 });
        ckNew.value = '';
        renderChecklist();
    }
    if (ckAdd) { ckAdd.addEventListener('click', addChecklistItem); }
    if (ckNew) { ckNew.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addChecklistItem(); } }); }

    function renderComments(list) {
        var host = document.getElementById('cardComments');
        host.innerHTML = '';
        if (!list || !list.length) { host.innerHTML = '<div class="small text-muted">Nenhum comentário.</div>'; return; }
        list.forEach(function (c) {
            var d = document.createElement('div');
            d.className = 'plan-comment';
            d.innerHTML = '<div class="plan-comment-meta"><strong>' + esc(c.user_name) + '</strong> · ' + esc(c.created_at) + '</div><div style="white-space:pre-wrap">' + esc(c.body) + '</div>';
            host.appendChild(d);
        });
        host.scrollTop = host.scrollHeight;
    }
    var cmAdd = document.getElementById('cardCommentAdd'), cmNew = document.getElementById('cardCommentNew');
    function addComment() {
        var body = cmNew.value.trim();
        if (!body || !currentCard || !currentCard.id) { return; }
        api('comment_add', { card_id: currentCard.id, body: body }).then(function (j) {
            cmNew.value = '';
            renderComments(j.comments);
            var c = findCard(currentCard.id);
            if (c) { c.comments_count = (j.comments || []).length; render(); }
        }).catch(function (err) { notify(err.message, 'error'); });
    }
    if (cmAdd) { cmAdd.addEventListener('click', addComment); }
    if (cmNew) { cmNew.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addComment(); } }); }

    function setLabels(list) {
        var chk = cardForm.querySelectorAll('input[name=labels_chk]');
        if (chk.length) { chk.forEach(function (c) { c.checked = list.indexOf(c.value) >= 0; }); }
        else if (cardForm.labels_text) { cardForm.labels_text.value = list.join(', '); }
    }
    function getLabels() {
        var chk = cardForm.querySelectorAll('input[name=labels_chk]:checked');
        if (cardForm.querySelectorAll('input[name=labels_chk]').length) { return Array.prototype.map.call(chk, function (c) { return c.value; }).join(','); }
        return cardForm.labels_text ? cardForm.labels_text.value : '';
    }

    function openCard(card, colId) {
        currentCard = card || null;
        cardForm.reset();
        cardForm.id.value = card ? card.id : 0;
        cardForm.title.value = card ? card.title : '';
        cardForm.description.value = card ? card.description : '';
        cardForm.assignee_id.value = card && card.assignee_id ? card.assignee_id : '';
        cardForm.priority.value = card ? card.priority : 'medium';
        cardForm.due_date.value = card && card.due_date ? card.due_date : '';
        cardForm.color.value = card && card.color ? card.color : '#ffffff';
        document.getElementById('cardNoColor').checked = !(card && card.color);
        if (cardForm.points) { cardForm.points.value = card && card.points !== null && card.points !== undefined ? card.points : ''; }
        fillColumnSelect(document.getElementById('cardColumn'), card ? card.column_id : (colId || (columns[0] && columns[0].id)));
        fillPlanItems(card ? card.plan_item_id : null);
        setLabels(card ? card.labels : []);
        checklist = card ? (card.checklist || []).map(function (k) { return { text: k.text, done: k.done ? 1 : 0 }; }) : [];
        renderChecklist();
        document.getElementById('cardModalTitle').textContent = card ? 'Cartão #' + card.id : 'Novo cartão';
        var del = document.getElementById('cardDelete');
        if (del) { del.classList.toggle('d-none', !card); }
        var cw = document.getElementById('cardCommentsWrap');
        cw.classList.toggle('d-none', !card);
        document.getElementById('cardInfo').textContent = '';
        var ro = !D.canCards;
        Array.prototype.forEach.call(cardForm.querySelectorAll('input,select,textarea'), function (el) {
            if (el.id === 'cardCommentNew' || el.id === 'cardChecklistNew') { return; }
            el.disabled = ro;
        });
        openModal('cardModal');
        if (!ro) { cardForm.title.focus(); }
        if (card) {
            renderComments([]);
            api('card_get', { id: card.id }, 'GET').then(function (j) {
                renderComments(j.comments);
                document.getElementById('cardInfo').textContent = 'Criado por ' + (j.creator || '—') + (j.card.created_at ? ' em ' + dateBr(j.card.created_at.substr(0, 10)) : '');
                if (j.card && j.card.checklist) { checklist = j.card.checklist; renderChecklist(); }
            }).catch(function () { /* silencioso */ });
        }
    }

    cardForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!D.canCards) { return; }
        var data = {
            id: cardForm.id.value, board_id: D.board.id, title: cardForm.title.value, column_id: cardForm.column_id.value,
            description: cardForm.description.value, assignee_id: cardForm.assignee_id.value, priority: cardForm.priority.value,
            due_date: cardForm.due_date.value, color: document.getElementById('cardNoColor').checked ? '' : cardForm.color.value,
            labels: getLabels(), plan_item_id: cardForm.plan_item_id.value, checklist: JSON.stringify(checklist)
        };
        if (cardForm.points) { data.points = cardForm.points.value; }
        api('card_save', data).then(function () { closeModals(); render(); notify('Cartão salvo.'); }).catch(function (err) { notify(err.message, 'error'); });
    });
    var cardDel = document.getElementById('cardDelete');
    if (cardDel) {
        cardDel.addEventListener('click', function () {
            if (!currentCard || !confirm('Excluir o cartão "' + currentCard.title + '"?')) { return; }
            api('card_delete', { id: currentCard.id }).then(function () { closeModals(); render(); notify('Cartão excluído.'); }).catch(function (err) { notify(err.message, 'error'); });
        });
    }

    // ---- coluna -----------------------------------------------------------
    var colForm = document.getElementById('columnForm');
    var currentCol = null;
    function openColumn(col) {
        currentCol = col || null;
        colForm.reset();
        colForm.id.value = col ? col.id : 0;
        colForm.name.value = col ? col.name : '';
        colForm.color.value = col && col.color ? col.color : '#0d6efd';
        colForm.wip_limit.value = col ? col.wip_limit : 0;
        colForm.is_done.checked = !!(col && col.is_done);
        document.getElementById('columnModalTitle').textContent = col ? 'Coluna: ' + col.name : 'Nova coluna';
        document.getElementById('colDeleteWrap').classList.toggle('d-none', !col);
        document.getElementById('colMoveWrap').classList.toggle('d-none', !col);
        if (col) {
            var sel = document.getElementById('colMoveTo');
            sel.innerHTML = '';
            var n = cardsOf(col.id).length;
            if (!n) { var o0 = document.createElement('option'); o0.value = ''; o0.textContent = '(coluna vazia)'; sel.appendChild(o0); }
            columns.filter(function (c) { return c.id !== col.id; }).forEach(function (c) {
                var o = document.createElement('option'); o.value = c.id; o.textContent = c.name; sel.appendChild(o);
            });
            sel.disabled = !n;
        }
        openModal('columnModal');
        colForm.name.focus();
    }
    colForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = { name: colForm.name.value, color: colForm.color.value, wip_limit: colForm.wip_limit.value, is_done: colForm.is_done.checked ? 1 : 0 };
        var p;
        if (currentCol) { data.id = currentCol.id; p = api('column_update', data); }
        else { data.board_id = D.board.id; p = api('column_add', data); }
        p.then(function () { closeModals(); render(); notify('Coluna salva.'); }).catch(function (err) { notify(err.message, 'error'); });
    });
    document.getElementById('colDelete').addEventListener('click', function () {
        if (!currentCol) { return; }
        var n = cardsOf(currentCol.id).length;
        var to = document.getElementById('colMoveTo').value;
        if (n && !to) { notify('Escolha a coluna de destino para os cartões.', 'error'); return; }
        if (!confirm('Excluir a coluna "' + currentCol.name + '"' + (n ? ' e mover ' + n + ' cartão(ões)' : '') + '?')) { return; }
        api('column_delete', { id: currentCol.id, move_to: to || '' }).then(function () { closeModals(); render(); notify('Coluna excluída.'); }).catch(function (err) { notify(err.message, 'error'); });
    });
    function moveColumn(dir) {
        if (!currentCol) { return; }
        var ids = columns.map(function (c) { return c.id; });
        var i = ids.indexOf(currentCol.id), j = i + dir;
        if (j < 0 || j >= ids.length) { return; }
        ids[i] = ids[j]; ids[j] = currentCol.id;
        api('column_reorder', { board_id: D.board.id, ids: ids }).then(render).catch(function (err) { notify(err.message, 'error'); });
    }
    document.getElementById('colLeft').addEventListener('click', function () { moveColumn(-1); });
    document.getElementById('colRight').addEventListener('click', function () { moveColumn(1); });

    // ------------------------------------------------------------- eventos
    boardEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (btn) {
            var colEl = btn.closest('.plan-col');
            var colId = colEl ? parseInt(colEl.getAttribute('data-col'), 10) : 0;
            if (btn.getAttribute('data-act') === 'col-edit') { openColumn(findColumn(colId)); }
            if (btn.getAttribute('data-act') === 'card-new') { openCard(null, colId); }
            return;
        }
        var cardEl = e.target.closest('.plan-card');
        if (cardEl) { openCard(findCard(parseInt(cardEl.getAttribute('data-id'), 10))); }
    });
    var bNew = document.getElementById('btnNewCard');
    if (bNew) { bNew.addEventListener('click', function () { openCard(null, columns[0] && columns[0].id); }); }
    var bCol = document.getElementById('btnNewColumn');
    if (bCol) { bCol.addEventListener('click', function () { openColumn(null); }); }
    var bBurn = document.getElementById('btnBurndown');
    if (bBurn) { bBurn.addEventListener('click', function () { document.getElementById('burndownCard').classList.toggle('d-none'); }); }

    var fS = document.getElementById('fltSearch'), fA = document.getElementById('fltAssignee'), fL = document.getElementById('fltLabel');
    function applyFilters() {
        filters.q = fS ? fS.value.trim() : '';
        filters.assignee = fA ? fA.value : '';
        filters.label = fL ? fL.value : '';
        boardEl.querySelectorAll('.plan-card').forEach(function (el) {
            var c = findCard(parseInt(el.getAttribute('data-id'), 10));
            el.classList.toggle('filtered-out', !(c && visible(c)));
        });
        updateSummary();
    }
    if (fS) { fS.addEventListener('input', applyFilters); }
    if (fA) { fA.addEventListener('change', applyFilters); }
    if (fL) { fL.addEventListener('change', applyFilters); }

    render();
    if (D.openCard) {
        var oc = findCard(D.openCard);
        if (oc) { openCard(oc); }
    }
})();
