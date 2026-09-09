<?php
/**
 * ============================================================
 * PLATAFORMA UNIFICADA — Instalador
 * 1. Coleta credenciais do banco e dados do administrador;
 * 2. Gera config/config.php;
 * 3. Executa sql/schema.sql (núcleo) e sql/modules/*.sql;
 * 4. Cria o administrador inicial.
 * Remova este arquivo após a instalação.
 * ============================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/core/src/Migrations.php'; // apenas o parser de SQL

$configFile = __DIR__ . '/config/config.php';
if (is_file($configFile) && empty($_GET['force'])) {
    exit('A plataforma já está instalada. Remova o arquivo install.php. Para reinstalar, apague config/config.php.');
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $orgName = trim($_POST['org_name'] ?? 'Portal Corporativo');
    $admName = trim($_POST['admin_name'] ?? '');
    $admUser = trim($_POST['admin_username'] ?? '');
    $admMail = trim($_POST['admin_email'] ?? '');
    $admPass = (string) ($_POST['admin_password'] ?? '');

    if ($dbName === '' || $dbUser === '') $errors[] = 'Informe os dados do banco.';
    if ($admName === '' || $admUser === '' || $admMail === '') $errors[] = 'Informe os dados do administrador.';
    if (strlen($admPass) < 8) $errors[] = 'A senha do administrador deve ter pelo menos 8 caracteres.';

    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Executa os schemas (núcleo primeiro, depois módulos)
            $files = array_merge(
                [__DIR__ . '/sql/schema.sql'],
                glob(__DIR__ . '/sql/modules/*.sql') ?: []
            );
            foreach ($files as $file) {
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException("Não foi possível ler {$file}");
                }
                // Executa statement a statement (parser do núcleo: respeita strings e comentários)
                foreach (Core\Migrations::split($sql) as $stmt) {
                    $pdo->exec($stmt);
                }
            }

            // Instalação nova: o schema já está atualizado — registra todas as
            // migrações como aplicadas (Administração → Atualizações de banco).
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(150) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $mark = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, notes) VALUES (?, 'instalação')");
            foreach (glob(__DIR__ . '/sql/migrations/*.sql') ?: [] as $mf) {
                $mark->execute([basename($mf)]);
            }

            // Administrador inicial (substitui o seed padrão)
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$admUser, $admMail]);
            $hash = password_hash($admPass, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password_hash = ?, is_admin = 1, active = 1 WHERE id = ?')
                    ->execute([$admName, $admUser, $admMail, $hash, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO users (name, username, email, password_hash, is_admin, active) VALUES (?, ?, ?, ?, 1, 1)')
                    ->execute([$admName, $admUser, $admMail, $hash]);
            }
            // Remove o admin seed se não for o mesmo
            $pdo->prepare("DELETE FROM users WHERE username = 'admin' AND email = 'admin@example.com' AND username <> ?")
                ->execute([$admUser]);

            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('org_name', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$orgName]);

            // Gera config/config.php a partir do exemplo
            $template = require __DIR__ . '/config/config.example.php';
            $template['app']['name'] = $orgName;
            $template['app']['key']  = bin2hex(random_bytes(24));
            $template['db'] = [
                'host' => $dbHost, 'port' => $dbPort, 'name' => $dbName,
                'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4',
            ];
            $template['cron_secret'] = bin2hex(random_bytes(16));

            $export = "<?php\n\n// Gerado pelo instalador em " . date('Y-m-d H:i:s') . "\n\nreturn " . var_export($template, true) . ";\n";
            if (!is_dir(__DIR__ . '/config')) {
                mkdir(__DIR__ . '/config', 0755, true);
            }
            file_put_contents($configFile, $export);

            $success = true;
        } catch (Throwable $e) {
            $errors[] = 'Erro: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação — Plataforma Unificada</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>body{background:linear-gradient(135deg,#0a4a74,#0d5c8f 60%,#1178b5);min-height:100vh}</style>
</head>
<body class="d-flex align-items-center justify-content-center py-4">
<div class="card shadow" style="width:640px;max-width:95vw">
    <div class="card-body p-4">
        <h1 class="h4 mb-1"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Plataforma Unificada</h1>
        <p class="text-muted">Instalação — Documentos, Comunicação, RH, Manutenção, Intranet e Planejamento com login único.</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Instalação concluída!</strong>
                <ol class="mb-0 mt-2">
                    <li>Apague o arquivo <code>install.php</code> do servidor.</li>
                    <li><a href="index.php">Acesse a plataforma</a> com o usuário administrador criado.</li>
                    <li>Configure a integração Moodle e o e-mail em <code>config/config.php</code>, se desejar.</li>
                </ol>
            </div>
        <?php else: ?>
            <form method="post">
                <h2 class="h6 text-uppercase text-muted mt-3">Banco de dados (MySQL/MariaDB)</h2>
                <div class="row g-2">
                    <div class="col-8"><label class="form-label">Host</label><input class="form-control" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"></div>
                    <div class="col-4"><label class="form-label">Porta</label><input class="form-control" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>"></div>
                    <div class="col-12"><label class="form-label">Banco *</label><input class="form-control" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Usuário *</label><input class="form-control" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Senha</label><input type="password" class="form-control" name="db_pass"></div>
                </div>

                <h2 class="h6 text-uppercase text-muted mt-4">Organização</h2>
                <input class="form-control" name="org_name" placeholder="Nome exibido no topo" value="<?= htmlspecialchars($_POST['org_name'] ?? '') ?>">

                <h2 class="h6 text-uppercase text-muted mt-4">Administrador global</h2>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label">Nome *</label><input class="form-control" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Usuário *</label><input class="form-control" name="admin_username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">E-mail *</label><input type="email" class="form-control" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required></div>
                    <div class="col-12"><label class="form-label">Senha * (mín. 8)</label><input type="password" class="form-control" name="admin_password" minlength="8" required></div>
                </div>

                <button class="btn btn-primary w-100 mt-4"><i class="bi bi-rocket-takeoff me-1"></i>Instalar</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
