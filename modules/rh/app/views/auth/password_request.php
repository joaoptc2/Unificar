<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir senha — RH Hospital</title>
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
                            <i class="bi bi-key fs-1 text-primary"></i>
                            <h5 class="fw-bold mt-2">Redefinir senha</h5>
                            <p class="text-muted small">Informe seu e-mail e enviaremos um link para redefinir sua senha.</p>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger py-2 small"><?= Sanitize::e($error) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success py-2 small"><?= Sanitize::e($success) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="index.php?page=password_reset&action=request">
                            <?= Csrf::field() ?>
                            <div class="mb-3">
                                <label for="email" class="form-label small fw-semibold">E-mail</label>
                                <input type="email" class="form-control" name="email" id="email" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i> Enviar link
                            </button>
                        </form>
                    </div>
                    <div class="card-footer bg-transparent text-center py-3">
                        <a href="index.php?page=login" class="text-decoration-none small">
                            <i class="bi bi-arrow-left me-1"></i> Voltar ao login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
