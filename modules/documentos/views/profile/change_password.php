<div class="page-header">
    <h1><i class="bi bi-key me-2"></i>Alterar Senha</h1>
    <div class="d-flex gap-2">
        <?php if (!$is_forced): ?>
            <a href="<?php echo url('profile'); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Voltar
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_forced): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Atenção:</strong> você precisa definir uma nova senha antes de continuar usando o sistema.
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?php echo url('profile/change-password'); ?>">
                    <?php echo csrf_field(); ?>

                    <?php if (!$is_forced): ?>
                    <div class="mb-3">
                        <label class="form-label required">Senha atual</label>
                        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label required">Nova senha</label>
                        <input type="password" name="new_password" class="form-control" required
                               minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                        <small class="text-muted">
                            Mínimo <?php echo PASSWORD_MIN_LENGTH; ?> caracteres, com pelo menos uma letra e um número.
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Confirmar nova senha</label>
                        <input type="password" name="new_password_confirm" class="form-control" required autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Salvar nova senha
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
