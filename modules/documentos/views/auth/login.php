<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar — <?php echo e(APP_NAME); ?></title>
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
                            <i class="bi <?php echo e(setting('logo_icon')); ?>"></i>
                        </div>
                        <h4 class="fw-semibold mb-1"><?php echo e(setting('app_name')); ?></h4>
                        <p class="text-muted small mb-0">Faça login para acessar o sistema</p>
                    </div>

                    <?php foreach (['success' => 'success', 'error' => 'danger', 'info' => 'info'] as $t => $css):
                        if (!has_flash($t)) continue;
                    ?>
                        <div class="alert alert-<?php echo $css; ?> alert-dismissible fade show py-2" role="alert">
                            <small><?php echo get_flash($t); ?></small>
                            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endforeach; ?>

                    <form method="POST" action="<?php echo url('process-login'); ?>">
                        <?php echo csrf_field(); ?>

                        <div class="mb-3">
                            <label for="email" class="form-label required">E-mail</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email"
                                       placeholder="seu@email.com" required autocomplete="email">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label required">Senha</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password"
                                       placeholder="Sua senha" required autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="#password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
                        </button>
                    </form>
                </div>
                <div class="card-footer bg-transparent text-center py-3">
                    <a href="<?php echo url('forgot-password'); ?>" class="small text-decoration-none">
                        Esqueci minha senha
                    </a>
                </div>
            </div>
            <p class="text-center text-muted small mt-3">
                <?php echo e(setting('app_name')); ?> &copy; <?php echo date('Y'); ?>
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = document.querySelector(btn.getAttribute('data-toggle-password'));
            var icon = btn.querySelector('i');
            if (target.type === 'password') {
                target.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                target.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });
    });
</script>
</body>
</html>
