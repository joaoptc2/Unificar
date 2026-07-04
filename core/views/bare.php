<?php
/** Página standalone (login, erros sem sessão). Variáveis: $title, $content */
$appName = Core\Settings::get('org_name', core_config('app.name', 'Portal Corporativo'));
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
</head>
<body class="portal-bare d-flex align-items-center justify-content-center min-vh-100">
    <div class="portal-bare-card card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="portal-bare-logo"><i class="bi bi-grid-3x3-gap-fill"></i></div>
                <div class="fw-semibold fs-5 mt-2"><?= core_e($appName) ?></div>
            </div>
            <?= $content ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
