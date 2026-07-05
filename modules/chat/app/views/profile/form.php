<div class="page-header">
    <h1><i class="bi bi-person me-2"></i>Editar Perfil</h1>
    <a href="index.php?page=profile" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Dados pessoais</div>
            <div class="card-body">
                <form method="POST" action="index.php?page=profile&action=update" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Nome</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= Sanitize::e($user['name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">E-mail</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= Sanitize::e($user['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cargo / Título</label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= Sanitize::e($user['title'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Departamento</label>
                            <input type="text" name="department" class="form-control"
                                   value="<?= Sanitize::e($user['department'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= Sanitize::e($user['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fuso horário</label>
                            <select name="timezone" class="form-select">
                                <option value="America/Sao_Paulo" <?= ($user['timezone'] ?? '') === 'America/Sao_Paulo' ? 'selected' : '' ?>>São Paulo (BRT)</option>
                                <option value="America/Manaus" <?= ($user['timezone'] ?? '') === 'America/Manaus' ? 'selected' : '' ?>>Manaus (AMT)</option>
                                <option value="America/Belem" <?= ($user['timezone'] ?? '') === 'America/Belem' ? 'selected' : '' ?>>Belém (BRT)</option>
                                <option value="America/Recife" <?= ($user['timezone'] ?? '') === 'America/Recife' ? 'selected' : '' ?>>Recife (BRT)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Foto de perfil</label>
                            <input type="file" name="avatar" class="form-control" accept="image/*">
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Salvar Alterações
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Alterar Senha</div>
            <div class="card-body">
                <form method="POST" action="index.php?page=profile&action=password">
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Senha atual</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Nova senha</label>
                            <input type="password" name="new_password" class="form-control" required minlength="6">
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Confirmar nova senha</label>
                            <input type="password" name="new_password_confirm" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-lock me-1"></i> Alterar Senha
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
