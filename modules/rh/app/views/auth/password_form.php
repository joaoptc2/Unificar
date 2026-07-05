<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova senha — RH Hospital</title>
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
                            <i class="bi bi-shield-lock fs-1 text-primary"></i>
                            <h5 class="fw-bold mt-2">Definir nova senha</h5>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger py-2 small"><?= Sanitize::e($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="index.php?page=password_reset&action=update">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="token" value="<?= Sanitize::e($token) ?>">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Nova senha (mín. 8 caracteres)</label>
                                <input type="password" class="form-control" name="password" minlength="8" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Confirmar senha</label>
                                <input type="password" class="form-control" name="password_confirm" minlength="8" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-lg me-1"></i> Redefinir senha
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
