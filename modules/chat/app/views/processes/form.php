<?php $isEdit = !empty($process['id']); ?>
<div class="page-header">
    <h1><i class="bi bi-diagram-3 me-2"></i><?= $isEdit ? 'Editar Processo' : 'Novo Processo' ?></h1>
    <a href="index.php?m=chat&page=processes" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=processes&action=<?= $isEdit ? 'update' : 'store' ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $process['id'] ?>">
                    <?php endif; ?>
                    <?php if (!empty($channelId)): ?>
                        <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Título do processo</label>
                            <input type="text" name="title" class="form-control" required
                                   value="<?= Sanitize::e($process['title'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="3"><?= Sanitize::e($process['description'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Etapas do processo</label>
                            <div id="stepsContainer">
                                <?php
                                $steps = $process['steps'] ?? [['title' => '', 'description' => '', 'assigned_to' => '', 'due_date' => '']];
                                foreach ($steps as $i => $step):
                                ?>
                                <div class="step-row card border mb-2">
                                    <div class="card-body py-2">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-1 text-center">
                                                <span class="badge bg-secondary step-number"><?= $i + 1 ?></span>
                                                <input type="hidden" name="step_order[]" value="<?= $i + 1 ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" name="step_title[]" class="form-control form-control-sm"
                                                       placeholder="Título da etapa" value="<?= Sanitize::e($step['title'] ?? '') ?>" required>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" name="step_description[]" class="form-control form-control-sm"
                                                       placeholder="Descrição (opcional)" value="<?= Sanitize::e($step['description'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <select name="step_assigned_to[]" class="form-select form-select-sm">
                                                    <option value="">Responsável</option>
                                                    <?php foreach ($users as $u): ?>
                                                    <option value="<?= $u['id'] ?>" <?= ($step['assigned_to'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                                                        <?= Sanitize::e($u['name']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="date" name="step_due_date[]" class="form-control form-control-sm"
                                                       value="<?= Sanitize::e($step['due_date'] ?? '') ?>">
                                            </div>
                                            <div class="col-1">
                                                <button type="button" class="btn btn-outline-danger btn-sm remove-step">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addStepBtn">
                                <i class="bi bi-plus-lg me-1"></i> Adicionar etapa
                            </button>
                        </div>

                        <div class="col-12 text-end mt-4">
                            <a href="index.php?m=chat&page=processes" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Criar Processo' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('addStepBtn').addEventListener('click', function() {
    const container = document.getElementById('stepsContainer');
    const num = container.querySelectorAll('.step-row').length + 1;
    const usersOptions = document.querySelector('[name="step_assigned_to[]"]').innerHTML;
    const html = `
    <div class="step-row card border mb-2">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-1 text-center">
                    <span class="badge bg-secondary step-number">${num}</span>
                    <input type="hidden" name="step_order[]" value="${num}">
                </div>
                <div class="col-md-3">
                    <input type="text" name="step_title[]" class="form-control form-control-sm" placeholder="Título da etapa" required>
                </div>
                <div class="col-md-3">
                    <input type="text" name="step_description[]" class="form-control form-control-sm" placeholder="Descrição (opcional)">
                </div>
                <div class="col-md-2">
                    <select name="step_assigned_to[]" class="form-select form-select-sm">${usersOptions}</select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="step_due_date[]" class="form-control form-control-sm">
                </div>
                <div class="col-1">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-step"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
});

document.getElementById('stepsContainer').addEventListener('click', function(e) {
    if (e.target.closest('.remove-step')) {
        const rows = this.querySelectorAll('.step-row');
        if (rows.length > 1) {
            e.target.closest('.step-row').remove();
            this.querySelectorAll('.step-number').forEach((el, i) => el.textContent = i + 1);
        }
    }
});
</script>
