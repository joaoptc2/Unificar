<div class="row">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-person-circle me-1"></i> Meus dados
            </div>
            <div class="card-body">
                <form method="POST" action="index.php?page=profile&action=update">
                    <?= Csrf::field() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome completo</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= Sanitize::e($user['name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= Sanitize::e($user['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Perfil</label>
                            <input type="text" class="form-control" disabled
                                   value="<?= Sanitize::e(ucfirst($user['role'])) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Último login</label>
                            <input type="text" class="form-control" disabled
                                   value="<?= $user['last_login'] ? Sanitize::formatDateTime($user['last_login']) : '-' ?>">
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="fw-semibold text-primary"><i class="bi bi-key me-1"></i> Alterar senha</h6>
                    <p class="text-muted small mb-3">Preencha os três campos abaixo apenas se quiser mudar sua senha.</p>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Senha atual</label>
                            <input type="password" name="current_password" class="form-control" autocomplete="current-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nova senha (mín. 8 caracteres)</label>
                            <input type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirmar nova senha</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Salvar alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-semibold mb-1">
                        <i class="bi bi-shield-lock me-1"></i> Autenticação em duas etapas (2FA)
                    </h6>
                    <small class="text-muted">Camada extra de segurança no login com app autenticador.</small>
                </div>
                <a href="index.php?page=two_factor" class="btn btn-outline-primary btn-sm">Configurar</a>
            </div>
        </div>
    </div>
</div>
