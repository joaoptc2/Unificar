<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RH Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-5 col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <div class="login-icon mb-3">
                                <i class="bi bi-hospital fs-1 text-primary"></i>
                            </div>
                            <h4 class="fw-bold text-dark">RH Hospital</h4>
                            <p class="text-muted small">Sistema de Gestão de Recursos Humanos</p>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                                <small><?= Sanitize::e($error) ?></small>
                                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                                <small><?= Sanitize::e($success) ?></small>
                                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="index.php?page=login&action=login" autocomplete="off">
                            <?= Csrf::field() ?>

                            <div class="mb-3">
                                <label for="email" class="form-label small fw-semibold">E-mail</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email"
                                           placeholder="seu@email.com" required autofocus>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label small fw-semibold">Senha</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="Sua senha" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
                            </button>
                            <div class="text-center mt-3">
                                <a href="index.php?page=password_reset" class="text-decoration-none small">
                                    Esqueci minha senha
                                </a>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer bg-transparent text-center py-3">
                        <small class="text-muted">Sistema de Gestão de RH Hospitalar</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
