<?php
$isEdit = !empty($position);
$formAction = $isEdit ? 'index.php?m=rh&page=positions&action=update' : 'index.php?m=rh&page=positions&action=store';
?>

<div class="page-header">
    <h1><i class="bi bi-person-workspace me-2"></i><?= $isEdit ? 'Editar Cargo' : 'Novo Cargo' ?></h1>
    <a href="index.php?m=rh&page=positions" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= $formAction ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $position['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label required">Título do Cargo</label>
                        <input type="text" name="title" class="form-control"
                               value="<?= Sanitize::e($position['title'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Departamento</label>
                        <select name="department_id" class="form-select">
                            <option value="">Nenhum</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= ($position['department_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>>
                                    <?= Sanitize::e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="description" class="form-control" rows="3"><?= Sanitize::e($position['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="active" class="form-check-input" id="active"
                            <?= ($position['active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Ativo</label>
                    </div>
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Cadastrar' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
