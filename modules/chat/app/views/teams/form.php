<?php $isEdit = !empty($team['id']); ?>
<div class="page-header">
    <h1><i class="bi bi-people me-2"></i><?= $isEdit ? 'Editar Equipe' : 'Nova Equipe' ?></h1>
    <a href="index.php?page=teams" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=teams&action=<?= $isEdit ? 'update' : 'store' ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $team['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Nome da equipe</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= Sanitize::e($team['name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cor</label>
                            <input type="color" name="color" class="form-control form-control-color w-100"
                                   value="<?= Sanitize::e($team['color'] ?? '#6366f1') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="3"><?= Sanitize::e($team['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Membros</label>
                            <select name="members[]" class="form-select" multiple size="6">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"
                                    <?= in_array($u['id'], $memberIds ?? []) ? 'selected' : '' ?>>
                                    <?= Sanitize::e($u['name']) ?> (<?= Sanitize::e($u['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Segure Ctrl para selecionar múltiplos. O criador é automaticamente líder.</div>
                        </div>

                        <div class="col-12 text-end">
                            <a href="index.php?page=teams" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Criar Equipe' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
