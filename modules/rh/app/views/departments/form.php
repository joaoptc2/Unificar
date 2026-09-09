<?php
$isEdit = !empty($department);
$formAction = $isEdit ? 'index.php?m=rh&page=departments&action=update' : 'index.php?m=rh&page=departments&action=store';
?>

<div class="page-header">
    <h1 class="h5"><i class="bi bi-building me-2"></i><?= $isEdit ? 'Editar Departamento' : 'Novo Departamento' ?></h1>
    <a href="<?= core_admin_url('rh', 'departments') ?>" class="btn btn-outline-secondary btn-sm">
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
                        <input type="hidden" name="id" value="<?= $department['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" name="name" class="form-control"
                               value="<?= Sanitize::e($department['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="description" class="form-control" rows="3"><?= Sanitize::e($department['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="active" class="form-check-input" id="active"
                            <?= ($department['active'] ?? 1) ? 'checked' : '' ?>>
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
