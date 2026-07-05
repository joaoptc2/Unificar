<?php
/**
 * Script de Instalação do Sistema RH Hospital
 *
 * Acesse via navegador: https://seudominio.com/install.php
 * IMPORTANTE: Exclua este arquivo após a instalação!
 */

session_start();

$step = $_GET['step'] ?? '1';
$errors = [];
$success = false;

// Verificar se já está instalado
if (file_exists(__DIR__ . '/config/.installed') && $step !== 'done') {
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>RH Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="bg-light"><div style="font-family:Arial;padding:60px;text-align:center;">
        <h2>Sistema já instalado</h2>
        <p>Exclua o arquivo <code>install.php</code> por segurança.</p>
        <a href="index.php" class="btn btn-primary mt-3">Ir para o sistema</a>
    </div></body></html>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    $host = trim($_POST['db_host'] ?? '');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $port = (int)(trim($_POST['db_port'] ?? '3306') ?: 3306);
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $hospitalName = trim($_POST['hospital_name'] ?? 'Hospital');

    // Validar
    if (empty($host) || empty($name) || empty($user)) {
        $errors[] = 'Preencha os dados do banco de dados.';
    }
    if (empty($adminName) || empty($adminEmail) || empty($adminPass)) {
        $errors[] = 'Preencha os dados do administrador.';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = 'A senha do administrador deve ter no mínimo 8 caracteres.';
    }

    if (empty($errors)) {
        try {
            // Testar conexão
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);

            // Criar banco se não existir
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");

            // Executar schema SQL
            $schemaFile = __DIR__ . '/sql/schema.sql';
            if (!file_exists($schemaFile)) {
                throw new Exception('Arquivo sql/schema.sql não encontrado.');
            }
            $schema = file_get_contents($schemaFile);

            // Remove comentários SQL `-- ...` linha-a-linha (alguns drivers/proxies
            // não toleram comentário antes do statement em exec()).
            $clean = preg_replace('/^\s*--.*$/m', '', $schema);

            // Split por `;` no fim de linha. Como nenhuma string literal do schema
            // contém `;`, isso é seguro aqui (validado).
            $statements = array_filter(array_map('trim', explode(';', $clean)));
            foreach ($statements as $sql) {
                if ($sql !== '') {
                    $pdo->exec($sql);
                }
            }

            // Criar admin
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, 'admin', 1)");
            $stmt->execute([$adminName, $adminEmail, $hash]);

            // Criar departamentos padrão
            $depts = ['Administração', 'Enfermagem', 'Médico', 'Farmácia', 'Laboratório', 'Radiologia', 'Nutrição', 'Recepção', 'Limpeza', 'Manutenção'];
            foreach ($depts as $dept) {
                $pdo->prepare("INSERT INTO departments (name, active) VALUES (?, 1)")->execute([$dept]);
            }

            // Salvar configuração do banco (formato compatível com Database.php)
            $configDb = "<?php\nreturn [\n"
                . "    'host'     => " . var_export($host, true) . ",\n"
                . "    'dbname'   => " . var_export($name, true) . ",\n"
                . "    'username' => " . var_export($user, true) . ",\n"
                . "    'password' => " . var_export($pass, true) . ",\n"
                . "    'charset'  => 'utf8mb4',\n"
                . "    'port'     => " . $port . ",\n"
                . "];\n";
            file_put_contents(__DIR__ . '/config/database.php', $configDb);

            // Atualizar app.php com nome do hospital
            $configApp = file_get_contents(__DIR__ . '/config/app.php');
            $configApp = preg_replace("/'app_name'\s*=>\s*'[^']*'/", "'app_name' => " . var_export($hospitalName, true), $configApp);
            file_put_contents(__DIR__ . '/config/app.php', $configApp);

            // Marcar como instalado
            file_put_contents(__DIR__ . '/config/.installed', date('Y-m-d H:i:s'));

            // Criar diretórios de upload e infraestrutura.
            // `public/uploads/` guarda só recursos públicos (fotos).
            // `storage/uploads/` guarda documentos sensíveis, fora do webroot,
            // servidos apenas por DownloadController com autenticação.
            // `storage/cache/` é usado pelo FileCache (dashboard, etc.).
            $uploadDirs = [
                'public/uploads/employees',
                'public/uploads/photos',
                'storage/uploads/documents',
                'storage/uploads/resumes',
                'storage/uploads/certificates',
                'storage/cache',
                'logs',
            ];
            $dirErrors = [];
            foreach ($uploadDirs as $dir) {
                $path = __DIR__ . '/' . $dir;
                if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
                    $dirErrors[] = $dir;
                }
            }
            if (!empty($dirErrors)) {
                throw new Exception(
                    'Falha ao criar diretórios (verifique permissões): ' . implode(', ', $dirErrors)
                );
            }

            // Bloquear execução de scripts em uploads públicos.
            $uploadProtection = "Options -Indexes\nOptions -ExecCGI\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\n";
            foreach (['public/uploads/employees', 'public/uploads/photos'] as $dir) {
                @file_put_contents(__DIR__ . '/' . $dir . '/.htaccess', $uploadProtection);
            }

            // Bloquear completamente qualquer acesso direto a storage/ e logs/ (defesa em profundidade).
            $denyAll = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
            @file_put_contents(__DIR__ . '/storage/.htaccess', $denyAll);
            @file_put_contents(__DIR__ . '/logs/.htaccess', $denyAll);

            $success = true;
            header('Location: install.php?step=done');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Erro de banco de dados: ' . $e->getMessage();
        } catch (Exception $e) {
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
    <title>Instalação — RH Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .install-card { max-width: 700px; margin: 40px auto; }
        .step-indicator { display: flex; justify-content: center; gap: 10px; margin-bottom: 20px; }
        .step-dot { width: 12px; height: 12px; border-radius: 50%; background: rgba(255,255,255,0.4); }
        .step-dot.active { background: #fff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="install-card">
            <div class="text-center text-white mb-4 pt-4">
                <h2><i class="bi bi-hospital me-2"></i>RH Hospital</h2>
                <p>Assistente de Instalação</p>
                <div class="step-indicator">
                    <div class="step-dot <?= in_array($step, ['1','2','done']) ? 'active' : '' ?>"></div>
                    <div class="step-dot <?= in_array($step, ['2','done']) ? 'active' : '' ?>"></div>
                    <div class="step-dot <?= $step === 'done' ? 'active' : '' ?>"></div>
                </div>
            </div>

            <?php if ($step === 'done'): ?>
                <div class="card border-0 shadow-lg">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle text-success" style="font-size:4rem;"></i>
                        <h3 class="mt-3 fw-bold">Instalação Concluída!</h3>
                        <p class="text-muted">O sistema foi instalado com sucesso.</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>IMPORTANTE:</strong> Exclua o arquivo <code>install.php</code> do servidor por segurança!
                        </div>
                        <a href="index.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Acessar o Sistema
                        </a>
                    </div>
                </div>

            <?php elseif ($step === '1'): ?>
                <div class="card border-0 shadow-lg">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-gear me-1"></i> Passo 1 — Verificação do Ambiente
                    </div>
                    <div class="card-body">
                        <?php
                        // Garante que existe `sql/schema.sql` e tenta detectar se a raiz é gravável
                        // (necessário para criar o diretório storage/ fora do webroot).
                        $rootWritable = is_writable(__DIR__) || is_dir(__DIR__ . '/storage');
                        $checks = [
                            ['PHP >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), 'Versão atual: ' . PHP_VERSION],
                            ['PDO MySQL', extension_loaded('pdo_mysql'), 'Extensão necessária para o banco de dados'],
                            ['mbstring', extension_loaded('mbstring'), 'Extensão para manipulação de strings UTF-8'],
                            ['json', extension_loaded('json'), 'Extensão para manipulação de JSON'],
                            ['fileinfo', extension_loaded('fileinfo'), 'Extensão para validação de uploads'],
                            ['config/ gravável', is_writable(__DIR__ . '/config/'), 'Necessário para salvar configurações'],
                            ['public/ gravável', is_writable(__DIR__ . '/public/'), 'Necessário para criar pastas de upload'],
                            ['raiz gravável (storage/)', $rootWritable, 'Necessário para criar a pasta storage/ (uploads sensíveis e cache)'],
                            ['sql/schema.sql presente', file_exists(__DIR__ . '/sql/schema.sql'), 'Arquivo de schema do banco de dados'],
                        ];
                        $allOk = true;
                        foreach ($checks as [$label, $ok, $hint]):
                            if (!$ok) $allOk = false;
                        ?>
                            <div class="d-flex justify-content-between align-items-center mb-2 py-1">
                                <div>
                                    <span><?= $label ?></span>
                                    <br><small class="text-muted"><?= $hint ?></small>
                                </div>
                                <?php if ($ok): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> OK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i> Falha</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <hr>
                        <?php if ($allOk): ?>
                            <a href="install.php?step=2" class="btn btn-primary w-100">
                                Próximo <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <div class="alert alert-danger mb-2">Corrija os problemas acima antes de continuar.</div>
                            <a href="install.php?step=1" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-arrow-clockwise me-1"></i> Verificar Novamente
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <div class="card border-0 shadow-lg">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-database me-1"></i> Passo 2 — Configuração
                    </div>
                    <div class="card-body">
                        <?php foreach ($errors as $err): ?>
                            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>

                        <form method="POST" action="install.php?step=2">
                            <h6 class="fw-semibold text-primary mb-3"><i class="bi bi-database me-1"></i> Banco de Dados MySQL</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-8">
                                    <label class="form-label">Host</label>
                                    <input type="text" name="db_host" class="form-control"
                                           value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                                    <small class="text-muted">Na Hostinger, geralmente é <code>localhost</code> ou o endereço fornecido no painel.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Porta</label>
                                    <input type="number" name="db_port" class="form-control"
                                           value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Nome do Banco</label>
                                    <input type="text" name="db_name" class="form-control"
                                           value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Usuário do Banco</label>
                                    <input type="text" name="db_user" class="form-control"
                                           value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Senha do Banco</label>
                                    <input type="password" name="db_pass" class="form-control">
                                </div>
                            </div>

                            <h6 class="fw-semibold text-primary mb-3"><i class="bi bi-person-lock me-1"></i> Conta do Administrador</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Nome completo</label>
                                    <input type="text" name="admin_name" class="form-control"
                                           value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">E-mail de acesso</label>
                                    <input type="email" name="admin_email" class="form-control"
                                           value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Senha (mínimo 8 caracteres)</label>
                                    <input type="password" name="admin_pass" class="form-control" minlength="8" required>
                                </div>
                            </div>

                            <h6 class="fw-semibold text-primary mb-3"><i class="bi bi-hospital me-1"></i> Identificação</h6>
                            <div class="mb-4">
                                <label class="form-label">Nome do Hospital / Clínica</label>
                                <input type="text" name="hospital_name" class="form-control"
                                       value="<?= htmlspecialchars($_POST['hospital_name'] ?? 'Hospital') ?>" required>
                                <small class="text-muted">Será exibido no cabeçalho do sistema.</small>
                            </div>

                            <div class="d-flex gap-2">
                                <a href="install.php?step=1" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i> Voltar
                                </a>
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="bi bi-check-lg me-1"></i> Instalar Sistema
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
