<?php
$isEdit = !empty($job);
$formAction = $isEdit ? 'index.php?page=recruitment&action=update' : 'index.php?page=recruitment&action=store';
?>

<div class="page-header">
    <h1><i class="bi bi-briefcase me-2"></i><?= $isEdit ? 'Editar Vaga' : 'Nova Vaga' ?></h1>
    <a href="index.php?page=recruitment" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= $formAction ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $job['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Título da Vaga</label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= Sanitize::e($job['title'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Departamento</label>
                            <select name="department_id" class="form-select">
                                <option value="">Selecione</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($job['department_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="4"><?= Sanitize::e($job['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Requisitos</label>
                            <textarea name="requirements" class="form-control" rows="3"><?= Sanitize::e($job['requirements'] ?? '') ?></textarea>
                        </div>
                        <?php if ($isEdit): ?>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="aberta" <?= ($job['status'] ?? '') === 'aberta' ? 'selected' : '' ?>>Aberta</option>
                                <option value="fechada" <?= ($job['status'] ?? '') === 'fechada' ? 'selected' : '' ?>>Fechada</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Criar Vaga' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
