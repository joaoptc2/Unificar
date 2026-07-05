<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página não encontrada</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
    <div class="text-center">
        <i class="bi bi-exclamation-circle display-1 text-muted"></i>
        <h1 class="display-4 fw-bold text-muted">404</h1>
        <p class="lead text-secondary">Página não encontrada.</p>
        <a href="<?php echo url('dashboard'); ?>" class="btn btn-primary">
            <i class="bi bi-house me-1"></i>Voltar ao Dashboard
        </a>
    </div>
</body>
</html>
