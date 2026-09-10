<?php /** Formulário de brinde. Variáveis: $item (array|null). */
$isEdit = !empty($item['id']);
$formAction = $isEdit ? 'index.php?m=rh&page=rewards&action=update' : 'index.php?m=rh&page=rewards&action=store';
?>
<div class="page-header">
    <h1><i class="bi bi-gift me-2"></i><?= $isEdit ? 'Editar Brinde' : 'Novo Brinde' ?></h1>
    <a href="index.php?m=rh&page=rewards" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label required">Nome</label><input type="text" name="name" class="form-control" required maxlength="150" value="<?= Sanitize::e($item['name'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label required">Custo em pontos</label><input type="number" name="points_cost" class="form-control" required min="1" value="<?= (int)($item['points_cost'] ?? 0) ?: '' ?>"></div>
        <div class="col-12"><label class="form-label">Descrição</label><textarea name="description" class="form-control" rows="3"><?= Sanitize::e($item['description'] ?? '') ?></textarea></div>
        <div class="col-md-4">
            <label class="form-label">Estoque</label>
            <input type="number" name="stock" class="form-control" min="0" value="<?= isset($item['stock']) && $item['stock'] !== null ? (int)$item['stock'] : '' ?>" placeholder="ilimitado">
            <div class="form-text">Deixe vazio para estoque ilimitado.</div>
        </div>
        <div class="col-md-8">
            <label class="form-label">Imagem (JPG/PNG, até 5 MB)</label>
            <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png">
            <?php if (!empty($item['image_path'])): ?>
                <div class="mt-2 d-flex align-items-center gap-3">
                    <img src="<?= Sanitize::e(Upload::publicUrl($item['image_path'])) ?>" style="max-height:80px;border-radius:6px" alt="">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="rmImg"><label class="form-check-label" for="rmImg">Remover imagem atual</label></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="active" value="1" id="active" <?= !isset($item['active']) || (int)$item['active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="active">Ativo (visível no catálogo do portal)</label>
            </div>
        </div>
        <div class="col-12 text-end">
            <a href="index.php?m=rh&page=rewards" class="btn btn-outline-secondary me-2">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Salvar' : 'Cadastrar' ?></button>
        </div>
    </div>
</form>
</div></div></div></div>
