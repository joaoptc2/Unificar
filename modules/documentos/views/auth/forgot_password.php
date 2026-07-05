<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar senha — <?php echo e(APP_NAME); ?></title>
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
                            <i class="bi bi-key"></i>
                        </div>
                        <h4 class="fw-semibold mb-1">Recuperar senha</h4>
                        <p class="text-muted small mb-0">Enviaremos um link para seu e-mail</p>
                    </div>

                    <?php if (has_flash('error')): ?>
                        <div class="alert alert-danger alert-dismissible fade show py-2">
                            <small><?php echo get_flash('error'); ?></small>
                            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo url('forgot-password'); ?>">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label required">E-mail cadastrado</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control" required autofocus placeholder="seu@email.com">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-send me-2"></i>Enviar link
                        </button>
                    </form>
                </div>
                <div class="card-footer bg-transparent text-center py-3">
                    <a href="<?php echo url('login'); ?>" class="small text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Voltar ao login
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
