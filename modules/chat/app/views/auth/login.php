<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — TeamChat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/public/css/style.css" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-logo">
                <div class="auth-icon">
                    <i class="bi bi-chat-dots-fill"></i>
                </div>
                <h1>TeamChat</h1>
                <p class="text-muted">Entre para se conectar com sua equipe</p>
            </div>

            <?php if ($error = Session::flash('error')): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= Sanitize::e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php?page=auth&action=doLogin">
                <?= Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label">E-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="seu@email.com"
                               value="<?= Sanitize::e($email ?? '') ?>" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Sua senha" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
                </button>
            </form>

            <div class="auth-footer">
                <p>Não tem uma conta? <a href="index.php?page=auth&action=register">Criar conta</a></p>
            </div>
        </div>
    </div>
</body>
</html>
