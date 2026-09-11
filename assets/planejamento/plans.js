/* PLANEJAMENTO — (1) árvore de itens do plano (objetivo → meta → ação → tarefa):
 * renderização e edição inline em JS vanilla, escritas via pages/api.php;
 * (2) editor estruturado de modelos (plano / quadro / diagrama) da página de modelos. */
(function () {
    'use strict';
    var D = window.PLAN_DATA;
    if (!D) { return; }

    var tree     = document.getElementById('planTree');
    var items    = D.items || [];
    var collapsed = {};
    var NEXT = { objective: 'goal', goal: 'action', action: 'task', task: 'task' };

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function dateBr(d) { return d ? d.substr(8, 2) + '/' + d.substr(5, 2) + '/' + d.substr(0, 4) : ''; }
    function money(v) { return v === null || v === undefined || v === '' ? '' : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }
    function byParent(pid) {
        return items.filter(function (i) { return (i.parent_id || 0) === (pid || 0); })
            .sort(function (a, b) { return a.sort_order - b.sort_order || a.id - b.id; });
    }
    function find(id) { return items.filter(function (i) { return i.id === id; })[0]; }

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
        el._t = setTimeout(function () { el.className = 'plan-toast'; }, 3500);
    }

    function api(action, data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (k) {
            if (Array.isArray(data[k])) { data[k].forEach(function (v) { fd.append(k + '[]', v); }); }
            else if (data[k] !== null && data[k] !== undefined) { fd.append(k, data[k]); }
        });
        return fetch(D.apiUrl + '&action=' + encodeURIComponent(action), {
            method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': D.csrf, 'Accept': 'application/json' }, body: fd
        }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Resposta inválida (' + r.status + ')' }; }); })
          .then(function (j) {
              if (!j.ok) { throw new Error(j.error || 'Erro'); }
              if (j.items) { items = j.items; }
              if (typeof j.progress === 'number') { updateProgress(j.progress); }
              return j;
          });
    }

    function updateProgress(p) {
        D.plan.progress = p;
        var bar = document.getElementById('planProgressBar'), lbl = document.getElementById('planProgressLabel'), dl = document.getElementById('planDoneLabel');
        if (bar) { bar.style.width = p + '%'; }
        if (lbl) { lbl.textContent = p + '%'; }
        if (dl) {
            var acts = items.filter(function (i) { return i.kind === 'action'; });
            dl.textContent = acts.filter(function (i) { return i.status === 'done'; }).length + '/' + acts.length;
        }
    }

    // ---------------------------------------------------------------- render
    function render() {
        var roots = byParent(0);
        if (!roots.length) {
            tree.innerHTML = '<div class="text-muted py-3 text-center">Nenhum item. ' + (D.canEdit ? 'Use "Novo objetivo" para começar.' : '') + '</div>';
            return;
        }
        var html = '';
        roots.forEach(function (it) { html += renderItem(it); });
        tree.innerHTML = html;
    }

    function renderItem(it) {
        var kids = byParent(it.id);
        var L = D.labels;
        var isCollapsed = !!collapsed[it.id];
        var h = '<div class="plan-item plan-item-' + it.kind + (it.status === 'done' ? ' is-done' : '') + (it.overdue ? ' is-overdue' : '') + '" data-id="' + it.id + '">';
        h += '<div class="plan-item-row">';
        h += kids.length
            ? '<button type="button" class="plan-toggle" data-act="toggle" title="Expandir/recolher"><i class="bi ' + (isCollapsed ? 'bi-chevron-right' : 'bi-chevron-down') + '"></i></button>'
            : '<span class="plan-toggle"></span>';
        if (D.canEdit) {
            h += '<input type="checkbox" class="form-check-input plan-done" data-act="done" title="Marcar concluído"' + (it.status === 'done' ? ' checked' : '') + (it.status === 'cancelled' ? ' disabled' : '') + '>';
        }
        h += '<span class="badge plan-kind plan-kind-' + it.kind + '">' + esc(L.kinds[it.kind]) + '</span>';
        h += '<span class="plan-title">' + esc(it.title) + '</span>';
        h += '<span class="plan-meta">';
        if (it.responsible_label) { h += '<span class="plan-chip" title="Quem"><i class="bi bi-person"></i> ' + esc(it.responsible_label) + '</span>'; }
        if (it.due_date) { h += '<span class="plan-chip' + (it.overdue ? ' text-danger' : '') + '" title="Prazo"><i class="bi bi-calendar-event"></i> ' + dateBr(it.due_date) + '</span>'; }
        if (it.priority === 'high') { h += '<span class="badge text-bg-warning">Alta</span>'; }
        if (it.status === 'cancelled') { h += '<span class="badge text-bg-dark">Cancelado</span>'; }
        else if (it.status === 'in_progress') { h += '<span class="badge text-bg-primary">Em andamento</span>'; }
        h += '<span class="plan-progress" title="' + it.progress + '%"><span class="plan-progress-bar' + (it.status === 'done' ? ' bg-success' : '') + '" style="width:' + it.progress + '%"></span></span><span class="plan-pct">' + it.progress + '%</span>';
        h += '</span>';
        if (D.canEdit) {
            h += '<span class="plan-actions">';
            if (it.kind !== 'task') { h += '<button type="button" class="btn btn-sm btn-link" data-act="add" title="Adicionar ' + esc(L.kinds[NEXT[it.kind]].toLowerCase()) + '"><i class="bi bi-plus-circle"></i></button>'; }
            h += '<button type="button" class="btn btn-sm btn-link" data-act="edit" title="Editar"><i class="bi bi-pencil"></i></button>';
            h += '<button type="button" class="btn btn-sm btn-link" data-act="up" title="Mover para cima"><i class="bi bi-arrow-up"></i></button>';
            h += '<button type="button" class="btn btn-sm btn-link" data-act="down" title="Mover para baixo"><i class="bi bi-arrow-down"></i></button>';
            h += '<button type="button" class="btn btn-sm btn-link text-danger" data-act="del" title="Excluir"><i class="bi bi-trash"></i></button>';
            h += '</span>';
        } else {
            h += '<span class="plan-actions"><button type="button" class="btn btn-sm btn-link" data-act="show" title="Detalhes"><i class="bi bi-eye"></i></button></span>';
        }
        h += '</div>';
        h += '<div class="plan-item-panel"></div>';
        h += '<div class="plan-children"' + (isCollapsed ? ' style="display:none"' : '') + '>';
        kids.forEach(function (k) { h += renderItem(k); });
        h += '</div></div>';
        return h;
    }

    // ------------------------------------------------------------- formulários
    function usersOptions(sel) {
        var h = '<option value="">— (nome livre abaixo)</option>';
        (D.users || []).forEach(function (u) { h += '<option value="' + u.id + '"' + (sel === u.id ? ' selected' : '') + '>' + esc(u.name) + '</option>'; });
        return h;
    }
    function options(map, sel) {
        var h = '';
        Object.keys(map).forEach(function (k) { h += '<option value="' + k + '"' + (sel === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; });
        return h;
    }

    function editForm(it) {
        var L = D.labels;
        return '<form class="plan-edit-form row g-2" data-id="' + it.id + '">'
            + '<div class="col-md-8"><label class="form-label small mb-0">O quê (título) *</label><input class="form-control form-control-sm" name="title" required maxlength="300" value="' + esc(it.title) + '"></div>'
            + '<div class="col-md-2"><label class="form-label small mb-0">Tipo</label><select class="form-select form-select-sm" name="kind">' + options(L.kinds, it.kind) + '</select></div>'
            + '<div class="col-md-2"><label class="form-label small mb-0">Prioridade</label><select class="form-select form-select-sm" name="priority">' + options(L.priorities, it.priority) + '</select></div>'
            + '<div class="col-md-6"><label class="form-label small mb-0">Por quê (justificativa)</label><textarea class="form-control form-control-sm" name="description" rows="2">' + esc(it.description) + '</textarea></div>'
            + '<div class="col-md-6"><label class="form-label small mb-0">Como (passos)</label><textarea class="form-control form-control-sm" name="how_text" rows="2">' + esc(it.how_text) + '</textarea></div>'
            + '<div class="col-md-4"><label class="form-label small mb-0">Onde</label><input class="form-control form-control-sm" name="where_text" maxlength="200" value="' + esc(it.where_text) + '"></div>'
            + '<div class="col-md-4"><label class="form-label small mb-0">Quem (usuário)</label><select class="form-select form-select-sm" name="responsible_id">' + usersOptions(it.responsible_id) + '</select></div>'
            + '<div class="col-md-4"><label class="form-label small mb-0">Quem (nome livre)</label><input class="form-control form-control-sm" name="responsible_name" maxlength="150" value="' + esc(it.responsible_name) + '" placeholder="se não for usuário do sistema"></div>'
            + '<div class="col-md-3"><label class="form-label small mb-0">Início</label><input type="date" class="form-control form-control-sm" name="start_date" value="' + esc(it.start_date || '') + '"></div>'
            + '<div class="col-md-3"><label class="form-label small mb-0">Prazo</label><input type="date" class="form-control form-control-sm" name="due_date" value="' + esc(it.due_date || '') + '"></div>'
            + '<div class="col-md-3"><label class="form-label small mb-0">Quanto custa (R$)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="cost" value="' + (it.cost === null ? '' : it.cost) + '"></div>'
            + '<div class="col-md-3"><label class="form-label small mb-0">Status</label><select class="form-select form-select-sm" name="status">' + options(L.statuses, it.status) + '</select></div>'
            + '<div class="col-md-6"><label class="form-label small mb-0">Progresso: <output>' + it.progress + '</output>%</label><input type="range" class="form-range" name="progress" min="0" max="100" step="5" value="' + it.progress + '" oninput="this.previousElementSibling.querySelector(\'output\').value=this.value"></div>'
            + '<div class="col-md-6"><label class="form-label small mb-0">Indicador / evidência</label><input class="form-control form-control-sm" name="indicator" maxlength="255" value="' + esc(it.indicator) + '"></div>'
            + '<div class="col-12 d-flex gap-2 justify-content-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-act="cancel">Cancelar</button><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button></div>'
            + '</form>';
    }

    function showPanel(it) {
        var L = D.labels;
        var rows = [
            ['Por quê', it.description], ['Onde', it.where_text], ['Como', it.how_text],
            ['Quem', it.responsible_label], ['Quando', [dateBr(it.start_date), dateBr(it.due_date)].filter(Boolean).join(' – ')],
            ['Quanto custa', money(it.cost)], ['Indicador', it.indicator], ['Status', L.statuses[it.status] + ' (' + it.progress + '%)'], ['Prioridade', L.priorities[it.priority]]
        ];
        var h = '<dl class="row small mb-0 plan-show">';
        rows.forEach(function (r) { if (r[1]) { h += '<dt class="col-sm-3">' + esc(r[0]) + '</dt><dd class="col-sm-9 mb-1" style="white-space:pre-wrap">' + esc(r[1]) + '</dd>'; } });
        h += '</dl><div class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-act="cancel">Fechar</button></div>';
        return h;
    }

    function addForm(parent) {
        var kind = parent ? NEXT[parent.kind] : 'objective';
        var L = D.labels;
        var kinds = parent ? {} : L.kinds;
        if (parent) { Object.keys(L.kinds).forEach(function (k) { if (k !== 'objective') { kinds[k] = L.kinds[k]; } }); }
        return '<form class="plan-add-form d-flex flex-wrap gap-2 align-items-center" data-parent="' + (parent ? parent.id : 0) + '">'
            + '<select class="form-select form-select-sm w-auto" name="kind">' + options(kinds, kind) + '</select>'
            + '<input class="form-control form-control-sm flex-grow-1" name="title" required maxlength="300" placeholder="Título do novo item (o quê)" autofocus>'
            + '<button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Adicionar</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="cancel">Cancelar</button></form>';
    }

    function panelOf(id) {
        var el = tree.querySelector('.plan-item[data-id="' + id + '"] > .plan-item-panel');
        return el;
    }
    function closePanels() { tree.querySelectorAll('.plan-item-panel').forEach(function (p) { p.innerHTML = ''; }); var r = document.getElementById('planRootAdd'); if (r) { r.innerHTML = ''; } }

    // -------------------------------------------------------------- eventos
    tree.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) { return; }
        var act = btn.getAttribute('data-act');
        var itemEl = btn.closest('.plan-item');
        var id = itemEl ? parseInt(itemEl.getAttribute('data-id'), 10) : 0;
        var it = find(id);
        if (act === 'toggle') {
            collapsed[id] = !collapsed[id];
            var ch = itemEl.querySelector(':scope > .plan-children');
            ch.style.display = collapsed[id] ? 'none' : '';
            btn.innerHTML = '<i class="bi ' + (collapsed[id] ? 'bi-chevron-right' : 'bi-chevron-down') + '"></i>';
            return;
        }
        if (act === 'cancel') { closePanels(); return; }
        if (act === 'show') { closePanels(); panelOf(id).innerHTML = showPanel(it); return; }
        if (!D.canEdit) { return; }
        if (act === 'edit') { closePanels(); panelOf(id).innerHTML = editForm(it); panelOf(id).querySelector('input[name=title]').focus(); return; }
        if (act === 'add') { closePanels(); collapsed[id] = false; panelOf(id).innerHTML = addForm(it); panelOf(id).querySelector('input[name=title]').focus(); return; }
        if (act === 'done') {
            api('item_done', { id: id, done: btn.checked ? 1 : 0 }).then(function () { render(); notify(btn.checked ? 'Item concluído.' : 'Item reaberto.'); })
                .catch(function (err) { notify(err.message, 'error'); render(); });
            return;
        }
        if (act === 'up' || act === 'down') {
            api('item_move', { id: id, dir: act }).then(render).catch(function (err) { notify(err.message, 'error'); });
            return;
        }
        if (act === 'del') {
            var n = countDesc(id);
            if (!confirm('Excluir "' + it.title + '"' + (n ? ' e seus ' + n + ' subitem(ns)' : '') + '?')) { return; }
            api('item_delete', { id: id }).then(function () { render(); notify('Item excluído.'); }).catch(function (err) { notify(err.message, 'error'); });
        }
    });

    function countDesc(id) { var n = 0; byParent(id).forEach(function (k) { n += 1 + countDesc(k.id); }); return n; }

    tree.addEventListener('submit', function (e) {
        var f = e.target;
        e.preventDefault();
        if (f.classList.contains('plan-edit-form')) {
            var data = { id: f.getAttribute('data-id') };
            new FormData(f).forEach(function (v, k) { data[k] = v; });
            api('item_update', data).then(function () { render(); notify('Item salvo.'); }).catch(function (err) { notify(err.message, 'error'); });
        } else if (f.classList.contains('plan-add-form')) {
            var pid = parseInt(f.getAttribute('data-parent'), 10);
            api('item_add', { plan_id: D.plan.id, parent_id: pid || '', kind: f.kind.value, title: f.title.value })
                .then(function () { render(); notify('Item adicionado.'); }).catch(function (err) { notify(err.message, 'error'); });
        }
    });

    var btnRoot = document.getElementById('btnAddRoot');
    if (btnRoot) {
        btnRoot.addEventListener('click', function () {
            closePanels();
            var host = document.getElementById('planRootAdd');
            if (!host) { host = document.createElement('div'); host.id = 'planRootAdd'; host.className = 'mb-2'; tree.parentNode.insertBefore(host, tree); }
            host.innerHTML = addForm(null);
            host.querySelector('input[name=title]').focus();
            host.onsubmit = function (e) {
                e.preventDefault();
                var f = e.target;
                api('item_add', { plan_id: D.plan.id, parent_id: '', kind: f.kind.value, title: f.title.value })
                    .then(function () { host.innerHTML = ''; render(); notify('Item adicionado.'); }).catch(function (err) { notify(err.message, 'error'); });
            };
            host.onclick = function (e) { if (e.target.closest('[data-act=cancel]')) { host.innerHTML = ''; } };
        });
    }
    var ex = document.getElementById('btnExpandAll'), co = document.getElementById('btnCollapseAll');
    if (ex) { ex.addEventListener('click', function () { collapsed = {}; render(); }); }
    if (co) { co.addEventListener('click', function () { items.forEach(function (i) { if (byParent(i.id).length) { collapsed[i.id] = true; } }); render(); }); }

    // abas
    document.querySelectorAll('#planTabs a[data-tab]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('#planTabs a').forEach(function (x) { x.classList.remove('active'); });
            a.classList.add('active');
            document.querySelectorAll('.plan-tab').forEach(function (t) { t.classList.toggle('d-none', t.id !== a.getAttribute('data-tab')); });
        });
    });

    render();
})();

/* ===================================================================== */
/* Editor estruturado de modelos (page=templates&action=create|edit)     */
/* ===================================================================== */
(function () {
    'use strict';
    var T = window.PLAN_TPL;
    if (!T) { return; }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function options(map, sel) {
        var h = '';
        Object.keys(map).forEach(function (k) { h += '<option value="' + k + '"' + (sel === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; });
        return h;
    }
    var NEXT = { objective: 'goal', goal: 'action', action: 'task', task: 'task' };
    var form = document.getElementById('tplForm');
    var dataField = document.getElementById('tplData');
    var kindSel = document.getElementById('tplKind');
    var kind = T.kind;

    // ---- ícone -------------------------------------------------------------
    var iconIn = document.getElementById('tplIcon'), iconPrev = document.getElementById('tplIconPreview');
    if (iconIn && iconPrev) {
        iconIn.addEventListener('input', function () { iconPrev.className = 'bi ' + iconIn.value.replace(/[^a-z0-9\-]/g, ''); });
    }

    // ---- troca de tipo (só na criação) --------------------------------------
    function showEditor() {
        document.querySelectorAll('.plan-tpl-editor').forEach(function (c) { c.hidden = c.getAttribute('data-kind') !== kind; });
    }
    if (kindSel) { kindSel.addEventListener('change', function () { kind = kindSel.value; showEditor(); }); }
    showEditor();

    // ---- PLANO: árvore ------------------------------------------------------
    var planItems = (T.plan && T.plan.items) || [];
    var planTree = document.getElementById('tplPlanTree');
    var planKind = document.getElementById('tplPlanKind');
    planKind.innerHTML = options(T.labels.planKinds, (T.plan && T.plan.kind) || 'work_plan');

    function nodeAt(path) {
        var list = planItems, node = null;
        for (var i = 0; i < path.length; i++) {
            node = list[path[i]];
            if (!node) { return null; }
            node.children = node.children || [];
            list = node.children;
        }
        return node;
    }
    function listAt(path) { // lista que contém o último índice de path
        if (path.length === 1) { return planItems; }
        return nodeAt(path.slice(0, -1)).children;
    }
    function renderPlan() {
        planTree.innerHTML = renderNodes(planItems, []);
        if (!planItems.length) { planTree.innerHTML = '<li class="text-muted small">Nenhum item. Clique em "Objetivo" para começar.</li>'; }
    }
    function renderNodes(list, base) {
        var h = '';
        list.forEach(function (n, i) {
            var path = base.concat([i]);
            var p = path.join('.');
            var L = T.labels;
            var hasDetails = !!(n.description || n.how_text || n.where_text || n.indicator || n.responsible_name || n.cost || (n.priority && n.priority !== 'medium'));
            h += '<li data-path="' + p + '"><div class="plan-tpl-node">'
                + '<select class="form-select form-select-sm w-auto" data-f="kind">' + options(L.kinds, n.kind) + '</select>'
                + '<input class="form-control form-control-sm" data-f="title" maxlength="300" value="' + esc(n.title) + '" placeholder="Título (o quê)" required>'
                + '<span class="btn-group btn-group-sm">'
                + (n.kind !== 'task' ? '<button type="button" class="btn btn-outline-primary" data-act="add" title="Adicionar subitem"><i class="bi bi-plus-lg"></i></button>' : '')
                + '<button type="button" class="btn btn-outline-secondary" data-act="up" title="Mover para cima"><i class="bi bi-arrow-up"></i></button>'
                + '<button type="button" class="btn btn-outline-secondary" data-act="down" title="Mover para baixo"><i class="bi bi-arrow-down"></i></button>'
                + '<button type="button" class="btn btn-outline-danger" data-act="del" title="Excluir"><i class="bi bi-trash"></i></button>'
                + '</span>'
                + '<details' + (hasDetails ? ' open' : '') + '><summary class="small text-muted" style="cursor:pointer">detalhes 5W2H</summary><div>'
                + '<input class="form-control form-control-sm" data-f="description" value="' + esc(n.description || '') + '" placeholder="Por quê (justificativa)">'
                + '<input class="form-control form-control-sm" data-f="how_text" value="' + esc(n.how_text || '') + '" placeholder="Como (passos)">'
                + '<input class="form-control form-control-sm" data-f="where_text" maxlength="200" value="' + esc(n.where_text || '') + '" placeholder="Onde">'
                + '<input class="form-control form-control-sm" data-f="responsible_name" maxlength="150" value="' + esc(n.responsible_name || '') + '" placeholder="Quem (sugestão de responsável)">'
                + '<input class="form-control form-control-sm" data-f="indicator" maxlength="255" value="' + esc(n.indicator || '') + '" placeholder="Indicador / evidência">'
                + '<input type="number" step="0.01" min="0" class="form-control form-control-sm" data-f="cost" value="' + (n.cost === undefined || n.cost === null ? '' : n.cost) + '" placeholder="Quanto custa (R$)">'
                + '<select class="form-select form-select-sm" data-f="priority">' + options(L.priorities, n.priority || 'medium') + '</select>'
                + '</div></details></div>';
            if (n.children && n.children.length) { h += '<ul>' + renderNodes(n.children, path) + '</ul>'; }
            h += '</li>';
        });
        return h;
    }
    function pathOf(el) {
        var li = el.closest('li[data-path]');
        return li ? li.getAttribute('data-path').split('.').map(function (x) { return parseInt(x, 10); }) : null;
    }
    planTree.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f');
        var path = pathOf(e.target);
        if (!f || !path) { return; }
        var n = nodeAt(path);
        if (!n) { return; }
        n[f] = f === 'cost' ? (e.target.value === '' ? null : parseFloat(e.target.value)) : e.target.value;
        if (f === 'kind') { renderPlan(); }
    });
    planTree.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) { return; }
        var path = pathOf(btn);
        if (!path) { return; }
        var act = btn.getAttribute('data-act');
        var list = listAt(path), i = path[path.length - 1], n = list[i];
        if (act === 'add') {
            n.children = n.children || [];
            n.children.push({ kind: NEXT[n.kind] || 'action', title: '', children: [] });
            renderPlan();
            var last = planTree.querySelector('li[data-path="' + path.concat([n.children.length - 1]).join('.') + '"] input[data-f=title]');
            if (last) { last.focus(); }
        } else if (act === 'up' && i > 0) {
            list[i] = list[i - 1]; list[i - 1] = n; renderPlan();
        } else if (act === 'down' && i < list.length - 1) {
            list[i] = list[i + 1]; list[i + 1] = n; renderPlan();
        } else if (act === 'del') {
            if (!n.title || confirm('Excluir "' + n.title + '" e seus subitens?')) { list.splice(i, 1); renderPlan(); }
        }
    });
    document.getElementById('tplPlanAddRoot').addEventListener('click', function () {
        planItems.push({ kind: 'objective', title: '', children: [] });
        renderPlan();
        var last = planTree.querySelector('li[data-path="' + (planItems.length - 1) + '"] input[data-f=title]');
        if (last) { last.focus(); }
    });
    function cleanItems(list) {
        var out = [];
        (list || []).forEach(function (n) {
            if (!n.title || !String(n.title).trim()) { return; }
            var o = { kind: n.kind, title: String(n.title).trim() };
            ['description', 'how_text', 'where_text', 'responsible_name', 'indicator'].forEach(function (f) { if (n[f] && String(n[f]).trim()) { o[f] = String(n[f]).trim(); } });
            if (n.priority && n.priority !== 'medium') { o.priority = n.priority; }
            if (n.cost !== undefined && n.cost !== null && n.cost !== '' && !isNaN(n.cost)) { o.cost = Number(n.cost); }
            var ch = cleanItems(n.children);
            if (ch.length) { o.children = ch; }
            out.push(o);
        });
        return out;
    }
    renderPlan();

    // ---- QUADRO: colunas ----------------------------------------------------
    var board = T.board || { columns: [], labels: [], points: false };
    var colsBody = document.querySelector('#tplBoardCols tbody');
    var boardKind = document.getElementById('tplBoardKind');
    boardKind.innerHTML = options(T.labels.boardKinds, board.kind || 'kanban');
    document.getElementById('tplBoardLabels').value = (board.labels || []).join(', ');
    document.getElementById('tplBoardPoints').checked = !!board.points;
    document.getElementById('tplBoardSprint').value = board.sprint_days || 0;
    function renderCols() {
        var h = '';
        (board.columns || []).forEach(function (c, i) {
            h += '<tr data-i="' + i + '">'
                + '<td><input class="form-control form-control-sm" data-f="name" maxlength="100" value="' + esc(c.name) + '" placeholder="Nome da coluna" required></td>'
                + '<td><input type="color" class="form-control form-control-color form-control-sm" data-f="color" value="' + esc(c.color || '#adb5bd') + '"></td>'
                + '<td><input type="number" min="0" max="999" class="form-control form-control-sm" data-f="wip_limit" value="' + (c.wip_limit || 0) + '" title="Limite WIP (0 = sem limite)"></td>'
                + '<td class="text-center"><input type="checkbox" class="form-check-input" data-f="is_done"' + (c.is_done ? ' checked' : '') + '></td>'
                + '<td class="text-end text-nowrap"><span class="btn-group btn-group-sm">'
                + '<button type="button" class="btn btn-outline-secondary" data-act="up"><i class="bi bi-arrow-up"></i></button>'
                + '<button type="button" class="btn btn-outline-secondary" data-act="down"><i class="bi bi-arrow-down"></i></button>'
                + '<button type="button" class="btn btn-outline-danger" data-act="del"><i class="bi bi-trash"></i></button></span></td></tr>';
        });
        colsBody.innerHTML = h || '<tr><td colspan="5" class="text-muted small">Nenhuma coluna.</td></tr>';
    }
    colsBody.addEventListener('input', function (e) {
        var tr = e.target.closest('tr[data-i]'), f = e.target.getAttribute('data-f');
        if (!tr || !f) { return; }
        var c = board.columns[parseInt(tr.getAttribute('data-i'), 10)];
        if (f === 'is_done') { c.is_done = e.target.checked ? 1 : 0; }
        else if (f === 'wip_limit') { c.wip_limit = parseInt(e.target.value, 10) || 0; }
        else { c[f] = e.target.value; }
    });
    colsBody.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) { return; }
        var i = parseInt(btn.closest('tr').getAttribute('data-i'), 10), act = btn.getAttribute('data-act'), c = board.columns[i];
        if (act === 'up' && i > 0) { board.columns[i] = board.columns[i - 1]; board.columns[i - 1] = c; }
        else if (act === 'down' && i < board.columns.length - 1) { board.columns[i] = board.columns[i + 1]; board.columns[i + 1] = c; }
        else if (act === 'del') { board.columns.splice(i, 1); }
        renderCols();
    });
    document.getElementById('tplBoardAddCol').addEventListener('click', function () {
        board.columns.push({ name: '', color: '#0d6efd', wip_limit: 0, is_done: 0 });
        renderCols();
        var last = colsBody.querySelector('tr:last-child input[data-f=name]');
        if (last) { last.focus(); }
    });
    renderCols();

    // ---- DIAGRAMA: JSON -----------------------------------------------------
    var diagTa = document.getElementById('tplDiagJson'), diagInfo = document.getElementById('tplDiagInfo');
    function validateDiagram(format) {
        var txt = diagTa.value.trim();
        try {
            var d = JSON.parse(txt);
            if (!d || typeof d !== 'object' || !Array.isArray(d.nodes)) { throw new Error('O JSON precisa de um objeto com a lista "nodes".'); }
            if (d.edges && !Array.isArray(d.edges)) { throw new Error('"edges" deve ser uma lista.'); }
            var ids = {};
            d.nodes.forEach(function (n, i) { if (!n || typeof n !== 'object') { throw new Error('Nó #' + (i + 1) + ' inválido.'); } if (n.id) { ids[n.id] = true; } });
            (d.edges || []).forEach(function (e, i) {
                if (!e || typeof e !== 'object') { throw new Error('Conexão #' + (i + 1) + ' inválida.'); }
                if (e.from && !ids[e.from]) { throw new Error('Conexão #' + (i + 1) + ': nó de origem "' + e.from + '" não existe.'); }
                if (e.to && !ids[e.to]) { throw new Error('Conexão #' + (i + 1) + ': nó de destino "' + e.to + '" não existe.'); }
            });
            if (format) { diagTa.value = JSON.stringify(d, null, 2); }
            diagInfo.className = 'small mt-1 text-success';
            diagInfo.textContent = 'JSON válido: ' + d.nodes.length + ' elemento(s), ' + (d.edges || []).length + ' conexão(ões).';
            diagTa.classList.remove('is-invalid');
            return true;
        } catch (err) {
            diagInfo.className = 'small mt-1 text-danger';
            diagInfo.textContent = 'Erro: ' + err.message;
            diagTa.classList.add('is-invalid');
            return false;
        }
    }
    document.getElementById('tplDiagValidate').addEventListener('click', function () { validateDiagram(true); });

    // ---- serialização no envio ------------------------------------------
    form.addEventListener('submit', function (e) {
        if (kind === 'plan') {
            var items = cleanItems(planItems);
            if (!items.length) { e.preventDefault(); alert('Informe ao menos um item com título no modelo de plano.'); return; }
            dataField.value = JSON.stringify({ kind: planKind.value, items: items });
        } else if (kind === 'board') {
            var cols = (board.columns || []).filter(function (c) { return c.name && c.name.trim(); })
                .map(function (c) { return { name: c.name.trim(), color: c.color, wip_limit: c.wip_limit || 0, is_done: c.is_done ? 1 : 0 }; });
            if (!cols.length) { e.preventDefault(); alert('Informe ao menos uma coluna no modelo de quadro.'); return; }
            dataField.value = JSON.stringify({
                kind: boardKind.value, columns: cols,
                labels: document.getElementById('tplBoardLabels').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean),
                points: document.getElementById('tplBoardPoints').checked,
                sprint_days: parseInt(document.getElementById('tplBoardSprint').value, 10) || 0
            });
        } else {
            if (!validateDiagram(false)) { e.preventDefault(); return; }
            dataField.value = diagTa.value;
        }
    });
})();
