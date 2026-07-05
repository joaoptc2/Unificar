<?php
$isEdit = !empty($expiration);
$formAction = $isEdit ? 'index.php?m=rh&page=expirations&action=update' : 'index.php?m=rh&page=expirations&action=store';
?>

<div class="page-header">
    <h1><i class="bi bi-clock me-2"></i><?= $isEdit ? 'Editar Vencimento' : 'Novo Vencimento' ?></h1>
    <a href="index.php?m=rh&page=expirations" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row g-3">
    <div class="col-md-<?= $isEdit ? '8' : '12' ?>">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $expiration['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Funcionário</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Selecione</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"
                                        <?= ($expiration['employee_id'] ?? $employeeId ?? 0) == $emp['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($emp['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Tipo</label>
                            <select name="type" class="form-select" required>
                                <option value="">Selecione</option>
                                <?php
                                $types = [
                                    'exame_periodico' => 'Exame Periódico',
                                    'aso_admissional' => 'ASO Admissional',
                                    'aso_demissional' => 'ASO Demissional',
                                    'aso_periodico' => 'ASO Periódico',
                                    'certificacao' => 'Certificação',
                                    'treinamento' => 'Treinamento',
                                    'conselho_regional' => 'Conselho Regional',
                                    'outro' => 'Outro',
                                ];
                                foreach ($types as $val => $label):
                                ?>
                                    <option value="<?= $val ?>" <?= ($expiration['type'] ?? '') === $val ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= Sanitize::e($expiration['title'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="2"><?= Sanitize::e($expiration['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data de Emissão</label>
                            <input type="date" name="issue_date" class="form-control"
                                   value="<?= Sanitize::e($expiration['issue_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Data de Vencimento</label>
                            <input type="date" name="expiry_date" class="form-control"
                                   value="<?= Sanitize::e($expiration['expiry_date'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Dias para Alerta</label>
                            <input type="number" name="alert_days" class="form-control" min="1"
                                   value="<?= Sanitize::e($expiration['alert_days'] ?? '30') ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Arquivo</label>
                            <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            <?php if ($isEdit && $expiration['file_path']): ?>
                                <small class="text-muted">Arquivo atual: <a href="<?= Sanitize::e(Upload::url($expiration['file_path'], 'expiration', (int)$expiration['id'])) ?>" target="_blank">Baixar</a></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Cadastrar' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isEdit && !empty($history)): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-arrow-repeat me-1"></i> Histórico de Renovações
            </div>
            <div class="card-body">
                <?php foreach ($history as $h): ?>
                    <div class="border-bottom pb-2 mb-2">
                        <small class="text-muted"><?= Sanitize::formatDateTime($h['created_at']) ?></small>
                        <div class="small">
                            <?= Sanitize::formatDate($h['old_expiry_date']) ?> &rarr;
                            <strong><?= Sanitize::formatDate($h['new_expiry_date']) ?></strong>
                        </div>
                        <?php if ($h['user_name']): ?>
                            <small class="text-muted">por <?= Sanitize::e($h['user_name']) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
