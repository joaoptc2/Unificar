<?php
/**
 * Assistente de Instalação — TeamChat
 * Execute uma vez e depois remova este arquivo.
 */
session_start();

$step    = (int) ($_GET['step'] ?? 1);
$errors  = [];
$success = false;

if (file_exists(__DIR__ . '/config/.installed')) {
    die('<h2>O sistema já está instalado.</h2><p><a href="index.php">Ir para o TeamChat</a></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        $host = trim($_POST['db_host'] ?? '127.0.0.1');
        $port = (int) ($_POST['db_port'] ?? 3306);
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        if (!$name || !$user) {
            $errors[] = 'Nome do banco e usuário são obrigatórios.';
        }

        if (empty($errors)) {
            try {
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$name}`");

                $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
                $schema = preg_replace('/--.*$/m', '', $schema);
                $statements = array_filter(array_map('trim', explode(';', $schema)));
                foreach ($statements as $stmt) {
                    if ($stmt !== '') $pdo->exec($stmt);
                }

                $dbConfig = "<?php\nreturn [\n    'host'     => " . var_export($host, true) . ",\n    'port'     => {$port},\n    'database' => " . var_export($name, true) . ",\n    'username' => " . var_export($user, true) . ",\n    'password' => " . var_export($pass, true) . ",\n    'charset'  => 'utf8mb4',\n];\n";
                file_put_contents(__DIR__ . '/config/database.php', $dbConfig);

                $_SESSION['install_db'] = true;
                header('Location: install.php?step=2');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Erro de conexão: ' . $e->getMessage();
            }
        }
    }

    if ($step === 2) {
        $adminName  = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim(strtolower($_POST['admin_email'] ?? ''));
        $adminPass  = $_POST['admin_password'] ?? '';
        $adminPass2 = $_POST['admin_password_confirm'] ?? '';

        if (!$adminName || !$adminEmail || !$adminPass) {
            $errors[] = 'Todos os campos são obrigatórios.';
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido.';
        }
        if (strlen($adminPass) < 6) {
            $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = 'As senhas não conferem.';
        }

        if (empty($errors)) {
            $cfg = require __DIR__ . '/config/database.php';
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password, role, status, created_at)
                 VALUES (?, ?, ?, "admin", "online", NOW())'
            );
            $stmt->execute([$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
            $adminId = $pdo->lastInsertId();

            $pdo->exec(
                "INSERT INTO channels (name, slug, description, type, is_general, created_by, created_at)
                 VALUES ('Geral', 'geral', 'Canal geral para toda a equipe', 'public', 1, {$adminId}, NOW())"
            );
            $channelId = $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO channel_members (channel_id, user_id, role, joined_at)
                 VALUES (?, ?, "owner", NOW())'
            )->execute([$channelId, $adminId]);

            file_put_contents(__DIR__ . '/config/.installed', date('Y-m-d H:i:s'));
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação — TeamChat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #0f0a25 0%, #312e81 50%, #6366f1 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .install-card { background: #fff; border-radius: 16px; padding: 2rem; max-width: 500px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
        .install-icon { width: 64px; height: 64px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.5rem; margin: 0 auto 1rem; }
        .step-indicator { display: flex; gap: 8px; justify-content: center; margin-bottom: 1.5rem; }
        .step-dot { width: 32px; height: 4px; border-radius: 2px; background: #e2e8f0; }
        .step-dot.active { background: #6366f1; }
        .step-dot.done { background: #10b981; }
    </style>
</head>
<body>
    <div class="install-card">
        <div class="install-icon"><i class="bi bi-chat-dots-fill"></i></div>
        <h2 class="text-center mb-1">TeamChat</h2>
        <p class="text-center text-muted mb-3">Assistente de Instalação</p>

        <div class="step-indicator">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? ($success ? 'done' : 'active') : '' ?>"></div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <div><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success text-center">
                <i class="bi bi-check-circle-fill me-2"></i>Instalação concluída!
            </div>
            <p class="text-center text-muted">Remova o arquivo <code>install.php</code> por segurança.</p>
            <a href="index.php" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-box-arrow-in-right me-2"></i>Acessar o TeamChat
            </a>

        <?php elseif ($step === 1): ?>
            <h5 class="text-center mb-3">Passo 1 — Banco de Dados</h5>
            <form method="POST" action="install.php?step=1">
                <div class="row g-3">
                    <div class="col-8">
                        <label class="form-label">Host</label>
                        <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Porta</label>
                        <input type="number" name="db_port" class="form-control" value="<?= (int)($_POST['db_port'] ?? 3306) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nome do banco</label>
                        <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? 'teamchat') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Usuário</label>
                        <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Senha</label>
                        <input type="password" name="db_pass" class="form-control">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            Próximo <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </form>

        <?php elseif ($step === 2): ?>
            <h5 class="text-center mb-3">Passo 2 — Administrador</h5>
            <form method="POST" action="install.php?step=2">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Nome completo</label>
                        <input type="text" name="admin_name" class="form-control" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Senha</label>
                        <input type="password" name="admin_password" class="form-control" minlength="6" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Confirmar senha</label>
                        <input type="password" name="admin_password_confirm" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="bi bi-check-lg me-2"></i>Instalar
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
