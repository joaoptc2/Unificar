<?php
$isEdit = !empty($user);
$formAction = $isEdit ? 'index.php?page=users&action=update' : 'index.php?page=users&action=store';
?>

<div class="page-header">
    <h1><i class="bi bi-person-plus me-2"></i><?= $isEdit ? 'Editar Usuário' : 'Novo Usuário' ?></h1>
    <a href="index.php?page=users" class="btn btn-outline-secondary btn-sm">
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
                        <input type="hidden" name="id" value="<?= $user['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" name="name" class="form-control"
                               value="<?= Sanitize::e($user['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">E-mail</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= Sanitize::e($user['email'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label <?= $isEdit ? '' : 'required' ?>">Senha <?= $isEdit ? '(deixe em branco para manter)' : '' ?></label>
                        <input type="password" name="password" class="form-control" minlength="8"
                            <?= $isEdit ? '' : 'required' ?>>
                        <small class="text-muted">Mínimo 8 caracteres.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Perfil</label>
                        <select name="role" class="form-select" required>
                            <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="rh" <?= ($user['role'] ?? 'rh') === 'rh' ? 'selected' : '' ?>>RH</option>
                            <option value="gestor" <?= ($user['role'] ?? '') === 'gestor' ? 'selected' : '' ?>>Gestor</option>
                            <option value="visualizador" <?= ($user['role'] ?? '') === 'visualizador' ? 'selected' : '' ?>>Visualizador</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="active" class="form-check-input" id="active"
                            <?= ($user['active'] ?? 1) ? 'checked' : '' ?>>
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
