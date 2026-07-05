<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação em duas etapas — RH Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-5 col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 text-center">
                        <i class="bi bi-shield-lock fs-1 text-primary"></i>
                        <h5 class="fw-bold mt-2">Verificação em duas etapas</h5>
                        <p class="text-muted small">
                            Abra o aplicativo autenticador (Google Authenticator, Authy, Microsoft Authenticator, 1Password)
                            e digite o código de 6 dígitos.
                        </p>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger py-2 small text-start"><?= Sanitize::e($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="index.php?page=two_factor&action=verify" autocomplete="off">
                            <?= Csrf::field() ?>
                            <div class="mb-3">
                                <input type="text" inputmode="numeric" pattern="[0-9A-Za-z\-]*"
                                       name="code" class="form-control form-control-lg text-center"
                                       maxlength="14" autofocus required
                                       placeholder="000000" style="letter-spacing:6px; font-size:1.5rem;">
                                <small class="text-muted d-block mt-2">
                                    Você também pode usar um código de recuperação (formato XXXX-XXXX-XXXX).
                                </small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-lg me-1"></i> Verificar
                            </button>
                        </form>
                    </div>
                    <div class="card-footer bg-transparent text-center py-3">
                        <a href="index.php?page=login" class="text-decoration-none small">
                            <i class="bi bi-arrow-left me-1"></i> Cancelar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
