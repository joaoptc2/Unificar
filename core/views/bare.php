<?php
/**
 * Página standalone (login, 2FA, recuperação de senha, erros sem sessão).
 * Variáveis: $title, $content
 *
 * O layout segue a escolha de Administração > Aparência:
 *  - "centralizado": o cartão no meio, sobre o fundo da marca;
 *  - "lado_a_lado": a imagem do hospital ocupa a maior parte e o formulário
 *    fica numa coluna própria — abaixo de 768px vira uma coluna só.
 */
$appName   = Core\Branding::name();
$brandLogo = Core\Branding::logoUrl();
$loginMsg  = Core\Branding::loginMessage();
$rodape    = Core\Branding::get('login_footer');
$split     = Core\Branding::get('login_layout') === 'lado_a_lado'
             && Core\Branding::loginBgUrl() !== '';
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
    <?= Core\Branding::metaTags() ?>
    <?= Core\Branding::cssVariables() ?>
    <?= Core\Branding::customCssTag() ?>
    <?= Core\Branding::bootScript() ?>
</head>
<?php if ($split): ?>
<body class="portal-bare is-split">
    <div class="portal-bare-split">
        <div class="portal-bare-visual">
            <div>
                <div class="fs-3 fw-semibold"><?= core_e($appName) ?></div>
                <?php if ($loginMsg !== ''): ?>
                    <div class="mt-1"><?= core_e($loginMsg) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="portal-bare-panel">
            <div class="portal-bare-card card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <?php if ($brandLogo !== ''): ?>
                            <div class="portal-bare-logo has-image">
                                <img src="<?= core_e($brandLogo) ?>" alt="<?= core_e($appName) ?>">
                            </div>
                        <?php else: ?>
                            <div class="portal-bare-logo"><i class="bi bi-grid-3x3-gap-fill"></i></div>
                        <?php endif; ?>
                        <div class="fw-semibold fs-5 mt-2"><?= core_e($appName) ?></div>
                    </div>
                    <?= $content ?>
                    <?php if ($rodape !== ''): ?>
                        <div class="portal-bare-footer"><?= core_e($rodape) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
<body class="portal-bare d-flex flex-column align-items-center justify-content-center min-vh-100">
    <div class="portal-bare-card card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <?php if ($brandLogo !== ''): ?>
                    <div class="portal-bare-logo has-image">
                        <img src="<?= core_e($brandLogo) ?>" alt="<?= core_e($appName) ?>">
                    </div>
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
    <?php if ($rodape !== ''): ?>
        <div class="portal-bare-footer" style="max-width:min(var(--portal-login-card-w), 94vw)">
            <?= core_e($rodape) ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
