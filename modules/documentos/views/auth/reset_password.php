<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir senha — <?php echo e(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <style><?php echo settings_dynamic_css(); ?></style>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <div class="login-icon mb-3">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h4 class="fw-semibold mb-1">Nova senha</h4>
                        <p class="text-muted small mb-0"><?php echo e($email); ?></p>
                    </div>

                    <?php if (has_flash('error')): ?>
                        <div class="alert alert-danger alert-dismissible fade show py-2">
                            <small><?php echo get_flash('error'); ?></small>
                            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo url('reset-password'); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="token" value="<?php echo e($token); ?>">

                        <div class="mb-3">
                            <label class="form-label required">Nova senha</label>
                            <input type="password" name="password" class="form-control" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                            <small class="text-muted">Mínimo <?php echo PASSWORD_MIN_LENGTH; ?> caracteres, com letras e números.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Confirmar nova senha</label>
                            <input type="password" name="password_confirm" class="form-control" required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-lg me-2"></i>Redefinir senha
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
