<?php
/**
 * Formulário de pesquisa (nova/editar) com construtor de perguntas.
 * Variáveis: $survey (array|null, com 'questions' na edição), $departments.
 * Reordenação por botões ↑/↓ (JS vanilla, sem SortableJS).
 */
$isEdit = !empty($survey['id']);
$formAction = $isEdit ? 'index.php?m=rh&page=surveys&action=update' : 'index.php?m=rh&page=surveys&action=store';
$questions = $survey['questions'] ?? [];
$frozen = $isEdit && Survey::participantCount((int)$survey['id']) > 0;
$qJson = array_map(fn ($q) => [
    'id' => (int)$q['id'], 'question' => $q['question'], 'type' => $q['type'],
    'required' => (int)($q['required'] ?? 0), 'help_text' => $q['help_text'] ?? '',
    'options' => implode("\n", (array)($q['opts']['options'] ?? [])),
    'min' => $q['opts']['min'] ?? 0, 'max' => $q['opts']['max'] ?? 10,
    'min_label' => $q['opts']['min_label'] ?? '', 'max_label' => $q['opts']['max_label'] ?? '',
], $questions);
?>
<style>
    .q-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; }
    .q-card .q-num { font-weight: 700; color: #6c757d; }
    .q-card .q-handle { color: #adb5bd; }
    .q-extra[hidden] { display: none; }
</style>
<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i><?= $isEdit ? 'Editar Pesquisa' : 'Nova Pesquisa' ?></h1>
    <a href="index.php?m=rh&page=surveys" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<form method="POST" action="<?= $formAction ?>" id="surveyForm">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$survey['id'] ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i> Dados da pesquisa</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label required">Título</label><input type="text" name="title" class="form-control" required maxlength="200" value="<?= Sanitize::e($survey['title'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Descrição / instruções</label><textarea name="description" class="form-control" rows="3"><?= Sanitize::e($survey['description'] ?? '') ?></textarea></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Tipo</label>
                            <select name="type" class="form-select">
                                <?php foreach (Survey::TYPES as $k => $label): ?><option value="<?= $k ?>" <?= ($survey['type'] ?? 'clima') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="rascunho" <?= ($survey['status'] ?? 'rascunho') === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                                <option value="ativa" <?= ($survey['status'] ?? '') === 'ativa' ? 'selected' : '' ?>>Ativa (publicar)</option>
                                <option value="encerrada" <?= ($survey['status'] ?? '') === 'encerrada' ? 'selected' : '' ?>>Encerrada</option>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label">Início</label><input type="date" name="starts_at" class="form-control" value="<?= Sanitize::e($survey['starts_at'] ?? '') ?>"></div>
                        <div class="col-6"><label class="form-label">Fim</label><input type="date" name="ends_at" class="form-control" value="<?= Sanitize::e($survey['ends_at'] ?? '') ?>"></div>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i> Público e divulgação</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Departamento-alvo</label>
                        <select name="department_id" class="form-select">
                            <option value="">Todos os departamentos</option>
                            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($survey['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= Sanitize::e($d['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="anonymous" value="1" id="anon" <?= !isset($survey['anonymous']) || (int)$survey['anonymous'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="anon"><i class="bi bi-incognito me-1"></i>Anônima (respostas sem identificação)</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="show_in_portal" value="1" id="showPortal" <?= !isset($survey['show_in_portal']) || (int)$survey['show_in_portal'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showPortal"><i class="bi bi-person-badge me-1"></i>Exibir na Minha Área (portal)</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmail" <?= !empty($survey['send_email']) ? 'checked' : '' ?> <?= !empty($survey['emailed_at']) ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="sendEmail"><i class="bi bi-envelope me-1"></i>Enviar por e-mail ao ativar</label>
                        <?php if (!empty($survey['emailed_at'])): ?><div class="form-text text-success">E-mails enfileirados em <?= Sanitize::formatDateTime($survey['emailed_at']) ?>.</div>
                        <?php else: ?><div class="form-text">Link direto para responder, aos funcionários com e-mail real.</div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2 mb-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Salvar alterações' : 'Criar pesquisa' ?></button>
                <a href="index.php?m=rh&page=surveys" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-check me-1"></i> Perguntas <span class="badge bg-secondary" id="qCount">0</span></span>
                    <?php if (!$frozen): ?>
                        <div class="d-flex gap-1 align-items-center">
                            <select id="newQType" class="form-select form-select-sm" style="width:auto">
                                <?php foreach (Survey::QUESTION_TYPES as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addQ"><i class="bi bi-plus-lg me-1"></i> Adicionar</button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($frozen): ?>
                        <div class="alert alert-warning small py-2"><i class="bi bi-lock me-1"></i> Esta pesquisa já possui respostas: as perguntas estão congeladas para preservar os resultados.</div>
                    <?php endif; ?>
                    <div id="qList"></div>
                    <p class="text-muted small text-center py-3 mb-0" id="qEmpty">Nenhuma pergunta ainda. Escolha um tipo e clique em <strong>Adicionar</strong>.</p>
                </div>
            </div>
        </div>
    </div>
</form>

<template id="qTpl">
    <div class="q-card" data-q>
        <div class="d-flex align-items-start gap-2">
            <div class="text-center" style="min-width:34px">
                <div class="q-num" data-num>1</div>
                <div class="btn-group-vertical btn-group-sm mt-1">
                    <button type="button" class="btn btn-light btn-sm" data-up title="Mover para cima"><i class="bi bi-chevron-up"></i></button>
                    <button type="button" class="btn btn-light btn-sm" data-down title="Mover para baixo"><i class="bi bi-chevron-down"></i></button>
                </div>
            </div>
            <div class="flex-grow-1">
                <input type="hidden" data-f="id">
                <div class="row g-2">
                    <div class="col-md-8"><input type="text" class="form-control form-control-sm" data-f="question" placeholder="Texto da pergunta" required maxlength="1000"></div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" data-f="type">
                            <?php foreach (Survey::QUESTION_TYPES as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8"><input type="text" class="form-control form-control-sm" data-f="help_text" placeholder="Texto de apoio (opcional)" maxlength="255"></div>
                    <div class="col-md-4 d-flex align-items-center justify-content-between">
                        <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" data-f="required" value="1" id=""><label class="form-check-label small">Obrigatória</label></div>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-remove title="Remover"><i class="bi bi-trash"></i></button>
                    </div>
                    <div class="col-12 q-extra" data-extra="options" hidden>
                        <textarea class="form-control form-control-sm" data-f="options" rows="3" placeholder="Uma opção por linha (mínimo 2)"></textarea>
                    </div>
                    <div class="col-12 q-extra" data-extra="scale" hidden>
                        <div class="row g-2">
                            <div class="col-3"><input type="number" class="form-control form-control-sm" data-f="min" placeholder="Mín." value="0"></div>
                            <div class="col-3"><input type="number" class="form-control form-control-sm" data-f="max" placeholder="Máx." value="10"></div>
                            <div class="col-3"><input type="text" class="form-control form-control-sm" data-f="min_label" placeholder="Rótulo mín. (ex.: Discordo)" maxlength="60"></div>
                            <div class="col-3"><input type="text" class="form-control form-control-sm" data-f="max_label" placeholder="Rótulo máx. (ex.: Concordo)" maxlength="60"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    var list = document.getElementById('qList'), tpl = document.getElementById('qTpl'), empty = document.getElementById('qEmpty');
    var frozen = <?= $frozen ? 'true' : 'false' ?>;
    var initial = <?= json_encode($qJson, JSON_UNESCAPED_UNICODE) ?>;

    function renumber() {
        var cards = list.querySelectorAll('[data-q]');
        cards.forEach(function (c, i) {
            c.querySelector('[data-num]').textContent = i + 1;
            c.querySelectorAll('[data-f]').forEach(function (f) {
                var name = f.getAttribute('data-f');
                f.name = 'questions[' + i + '][' + name + ']';
                if (f.type === 'checkbox') { f.id = 'q_req_' + i; f.nextElementSibling.setAttribute('for', f.id); }
            });
            c.querySelector('[data-up]').disabled = i === 0;
            c.querySelector('[data-down]').disabled = i === cards.length - 1;
        });
        document.getElementById('qCount').textContent = cards.length;
        empty.hidden = cards.length > 0;
    }
    function applyType(card) {
        var t = card.querySelector('[data-f="type"]').value;
        card.querySelector('[data-extra="options"]').hidden = !(t === 'choice' || t === 'multiple');
        card.querySelector('[data-extra="scale"]').hidden = t !== 'scale';
    }
    function add(data) {
        data = data || {};
        var node = tpl.content.firstElementChild.cloneNode(true);
        node.querySelectorAll('[data-f]').forEach(function (f) {
            var k = f.getAttribute('data-f');
            if (f.type === 'checkbox') { f.checked = !!Number(data[k] || 0); }
            else if (data[k] !== undefined && data[k] !== null) { f.value = data[k]; }
        });
        if (frozen) {
            node.querySelectorAll('input, textarea, select').forEach(function (f) { if (f.type !== 'hidden') f.readOnly = true; if (f.tagName === 'SELECT' || f.type === 'checkbox') f.addEventListener('change', function (e) { e.preventDefault(); }); });
            node.querySelector('[data-remove]').remove();
        }
        node.querySelector('[data-f="type"]').addEventListener('change', function () { applyType(node); });
        node.querySelector('[data-up]').addEventListener('click', function () { var p = node.previousElementSibling; if (p) list.insertBefore(node, p); renumber(); });
        node.querySelector('[data-down]').addEventListener('click', function () { var n = node.nextElementSibling; if (n) list.insertBefore(n, node); renumber(); });
        var rm = node.querySelector('[data-remove]');
        if (rm) rm.addEventListener('click', function () { if (confirm('Remover esta pergunta?')) { node.remove(); renumber(); } });
        list.appendChild(node);
        applyType(node);
        renumber();
        if (!data.id) { node.querySelector('[data-f="question"]').focus(); }
    }
    initial.forEach(add);
    var addBtn = document.getElementById('addQ');
    if (addBtn) addBtn.addEventListener('click', function () { add({ type: document.getElementById('newQType').value }); });
    if (!initial.length && !frozen) add({ type: 'rating' });

    document.getElementById('surveyForm').addEventListener('submit', function (e) {
        var bad = null;
        list.querySelectorAll('[data-q]').forEach(function (c) {
            var t = c.querySelector('[data-f="type"]').value;
            if (t === 'choice' || t === 'multiple') {
                var lines = c.querySelector('[data-f="options"]').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                if (lines.length < 2 && !bad) bad = c;
            }
        });
        if (bad) { e.preventDefault(); alert('Perguntas de escolha precisam de ao menos 2 opções (uma por linha).'); bad.querySelector('[data-f="options"]').focus(); }
    });
})();
</script>
