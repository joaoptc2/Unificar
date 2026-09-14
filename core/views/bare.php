<?php
/** Página standalone (login, erros sem sessão). Variáveis: $title, $content */
$appName   = Core\Branding::name();
$brandLogo = Core\Branding::logoUrl();
$loginMsg  = Core\Branding::loginMessage();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= core_e($title) ?> — <?= core_e($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= core_asset('core/app.css') ?>">
    <?= Core\Branding::faviconTag() ?>
    <?= Core\Branding::fontTag() ?>
    <?= Core\Branding::cssVariables() ?>
</head>
<body class="portal-bare d-flex align-items-center justify-content-center min-vh-100">
    <div class="portal-bare-card card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <?php if ($brandLogo !== ''): ?>
                    <div class="portal-bare-logo has-image"><img src="<?= core_e($brandLogo) ?>" alt="<?= core_e($appName) ?>"></div>
                <?php else: ?>
                    <div class="portal-bare-logo"><i class="bi bi-grid-3x3-gap-fill"></i></div>
                <?php endif; ?>
                <div class="fw-semibold fs-5 mt-2"><?= core_e($appName) ?></div>
                <?php if ($loginMsg !== ''): ?>
                    <div class="text-muted small mt-1"><?= core_e($loginMsg) ?></div>
                <?php endif; ?>
            </div>
            <?= $content ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
