<?php
/**
 * Wizard de configuração (one-time).
 *
 * Acesse via https://seudominio.com.br/setup.php na PRIMEIRA instalação
 * ou sempre que precisar recriar o arquivo .env.
 *
 * Funciona fora do roteador do index.php.
 * Remova este arquivo após configurar.
 */

// ─── Descoberta dos caminhos candidatos para o .env ────────────────────────
$ROOT        = __DIR__;                         // raiz do projeto (public_html)
$PARENT      = dirname($ROOT);                  // uma pasta acima (ideal)
$env_outside = $PARENT . '/.env';
$env_inside  = $ROOT . '/.env';

$env_existing = null;
if (is_file($env_outside))      $env_existing = $env_outside;
elseif (is_file($env_inside))   $env_existing = $env_inside;

// ─── Segurança do wizard ───────────────────────────────────────────────────
// Se já existe .env e NÃO veio com parâmetro ?force=1 → exige confirmação.
$force = isset($_GET['force']) && $_GET['force'] === '1';

$action = $_POST['action'] ?? null;
$errors = [];
$notice = null;

// ─── Ação: gravar .env ─────────────────────────────────────────────────────
if ($action === 'save') {
    $fields = [
        'APP_URL'  => trim($_POST['app_url']  ?? ''),
        'APP_DEBUG' => isset($_POST['app_debug']) ? 'true' : 'false',
        'DB_HOST'  => trim($_POST['db_host']  ?? 'localhost'),
        'DB_NAME'  => trim($_POST['db_name']  ?? ''),
        'DB_USER'  => trim($_POST['db_user']  ?? ''),
        'DB_PASS'  => $_POST['db_pass']       ?? '',
        'MAIL_ENABLED' => isset($_POST['mail_enabled']) ? 'true' : 'false',
        'MAIL_HOST' => trim($_POST['mail_host'] ?? 'smtp.hostinger.com'),
        'MAIL_PORT' => (int) ($_POST['mail_port'] ?? 465),
        'MAIL_ENCRYPTION' => trim($_POST['mail_encryption'] ?? 'ssl'),
        'MAIL_USER' => trim($_POST['mail_user'] ?? ''),
        'MAIL_PASS' => $_POST['mail_pass']    ?? '',
    ];

    if ($fields['DB_NAME'] === '' || $fields['DB_USER'] === '') {
        $errors[] = 'Nome do banco e usuário são obrigatórios.';
    }

    // Testa conexão
    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                           $fields['DB_HOST'], $fields['DB_NAME']);
            $pdo = new PDO($dsn, $fields['DB_USER'], $fields['DB_PASS'],
                           [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT 1');
        } catch (Throwable $ex) {
            $errors[] = 'Falha ao conectar ao banco: ' . htmlspecialchars($ex->getMessage());
        }
    }

    if (!$errors) {
        // Monta conteúdo .env
        $lines = ["# Gerado por setup.php em " . date('Y-m-d H:i:s')];
        foreach ($fields as $k => $v) {
            // Aspas se houver espaços ou caracteres especiais
            if (preg_match('/[\s#"\'=]/', (string) $v)) {
                $v = '"' . str_replace('"', '\\"', $v) . '"';
            }
            $lines[] = "$k=$v";
        }
        $lines[] = 'MAIL_FROM_NAME="Sistema de Gestão Documental"';
        $lines[] = 'LOGIN_MAX_ATTEMPTS=5';
        $lines[] = 'LOGIN_LOCKOUT_MINUTES=15';
        $lines[] = 'PASSWORD_RESET_TTL_MINUTES=30';
        $lines[] = 'NOTIFY_DAYS_BEFORE=30';
        $content = implode("\n", $lines) . "\n";

        // Tenta fora do public_html primeiro
        $target = $env_outside;
        $ok = @file_put_contents($target, $content);
        if ($ok === false) {
            // Fallback para dentro
            $target = $env_inside;
            $ok = @file_put_contents($target, $content);
        }

        if ($ok === false) {
            $errors[] = 'Não foi possível gravar o .env em nenhum local. '
                      . 'Verifique permissões de escrita em <code>' . htmlspecialchars($PARENT) . '</code> '
                      . 'ou em <code>' . htmlspecialchars($ROOT) . '</code>.';
        } else {
            @chmod($target, 0600);
            $notice = [
                'location' => $target,
                'outside'  => ($target === $env_outside),
            ];
        }
    }
}

// ─── Valores padrão para o formulário ──────────────────────────────────────
function default_val($key, $fallback = '') {
    return htmlspecialchars($_POST[$key] ?? $fallback, ENT_QUOTES, 'UTF-8');
}

$host_guess = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seudominio.com.br');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup — Sistema de Gestão Documental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family:'Segoe UI', system-ui, sans-serif; }
        .container { max-width: 800px; margin-top: 40px; margin-bottom: 60px; }
        .card { border:0; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .brand { text-align:center; margin-bottom: 24px; }
        .brand i { font-size: 2.2rem; color: #4f46e5; }
        .badge-outside { background: #d1fae5; color: #065f46; }
        .badge-inside { background: #fef3c7; color: #78350f; }
    </style>
</head>
<body>
<div class="container">
    <div class="brand">
        <i class="bi bi-hospital"></i>
        <h3 class="fw-bold mt-2">Configuração do Sistema</h3>
        <p class="text-muted small">Assistente de primeira configuração — crie o arquivo <code>.env</code></p>
    </div>

    <?php if ($notice): ?>
        <!-- ── SUCESSO ──────────────────────────────────────────────── -->
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="text-success mb-3"><i class="bi bi-check-circle me-2"></i>Configuração salva!</h5>
                <p>Arquivo gravado em:</p>
                <p><code><?php echo htmlspecialchars($notice['location']); ?></code>
                    <?php if ($notice['outside']): ?>
                        <span class="badge badge-outside ms-2">Fora do public_html (ideal)</span>
                    <?php else: ?>
                        <span class="badge badge-inside ms-2">Dentro do public_html</span>
                    <?php endif; ?>
                </p>

                <hr>
                <h6 class="fw-bold">Próximos passos</h6>
                <ol class="small">
                    <li>Aplique as <strong>migrations</strong> no phpMyAdmin (se ainda não aplicou):
                        <ul>
                            <li><code>database/schema.sql</code> (instalação nova) <strong>OU</strong></li>
                            <li><code>database/migrations/002_security_and_improvements.sql</code> + <code>003_indicators_v2.sql</code> (atualização)</li>
                        </ul>
                    </li>
                    <li>
                        <strong>Apague este arquivo <code>setup.php</code></strong>
                        após terminar — ele é um risco de segurança se ficar online.
                    </li>
                    <li>Acesse <a href="<?php echo htmlspecialchars($host_guess); ?>">o sistema</a> e faça login.</li>
                </ol>

                <a href="<?php echo htmlspecialchars($host_guess); ?>" class="btn btn-primary">
                    <i class="bi bi-arrow-right me-1"></i>Ir ao sistema
                </a>
            </div>
        </div>

    <?php elseif ($env_existing && !$force): ?>
        <!-- ── JÁ CONFIGURADO ───────────────────────────────────────── -->
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="text-warning mb-3"><i class="bi bi-exclamation-triangle me-2"></i>Sistema já configurado</h5>
                <p>Já existe um arquivo <code>.env</code> em:</p>
                <p><code><?php echo htmlspecialchars($env_existing); ?></code></p>
                <p class="text-muted small">
                    Se o sistema não está funcionando, pode ser outro problema (migrations não aplicadas,
                    banco inacessível, etc).
                </p>
                <div class="d-flex gap-2">
                    <a href="?force=1" class="btn btn-outline-danger">
                        <i class="bi bi-arrow-clockwise me-1"></i>Reconfigurar mesmo assim
                    </a>
                    <a href="<?php echo htmlspecialchars($host_guess); ?>" class="btn btn-primary">
                        Ir ao sistema
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ── FORMULÁRIO ───────────────────────────────────────────── -->
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <div><?php echo $err; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="save">

            <!-- Aplicação -->
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-globe me-2"></i>Aplicação</h6></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">URL do sistema <span class="text-danger">*</span></label>
                        <input type="url" name="app_url" class="form-control" required
                               value="<?php echo default_val('app_url', $host_guess); ?>"
                               placeholder="https://documentos.seudominio.com.br">
                        <small class="text-muted">Sem barra no final.</small>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="app_debug" id="app_debug"
                               <?php echo isset($_POST['app_debug']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="app_debug">
                            Modo debug (mostra erros detalhados — <strong>desative em produção</strong>)
                        </label>
                    </div>
                </div>
            </div>

            <!-- Banco -->
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-database me-2"></i>Banco de Dados</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Host</label>
                            <input type="text" name="db_host" class="form-control"
                                   value="<?php echo default_val('db_host', 'localhost'); ?>">
                            <small class="text-muted">Na Hostinger geralmente é <code>localhost</code>.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nome do banco <span class="text-danger">*</span></label>
                            <input type="text" name="db_name" class="form-control" required
                                   value="<?php echo default_val('db_name'); ?>"
                                   placeholder="u000000000_documentos">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Usuário <span class="text-danger">*</span></label>
                            <input type="text" name="db_user" class="form-control" required
                                   value="<?php echo default_val('db_user'); ?>"
                                   placeholder="u000000000_documentos">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Senha <span class="text-danger">*</span></label>
                            <input type="password" name="db_pass" class="form-control" required
                                   placeholder="••••••••">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SMTP -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi bi-envelope me-2"></i>SMTP (opcional)
                        <small class="text-muted">— para "esqueci senha" e alertas de vencimento</small>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="mail_enabled" id="mail_enabled"
                               <?php echo isset($_POST['mail_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="mail_enabled">Habilitar envio de e-mails</label>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small">Servidor SMTP</label>
                            <input type="text" name="mail_host" class="form-control form-control-sm"
                                   value="<?php echo default_val('mail_host', 'smtp.hostinger.com'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Porta</label>
                            <input type="number" name="mail_port" class="form-control form-control-sm"
                                   value="<?php echo default_val('mail_port', '465'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Criptografia</label>
                            <select name="mail_encryption" class="form-select form-select-sm">
                                <option value="ssl" <?php echo (($_POST['mail_encryption'] ?? 'ssl') === 'ssl') ? 'selected' : ''; ?>>SSL (465)</option>
                                <option value="tls" <?php echo (($_POST['mail_encryption'] ?? '') === 'tls') ? 'selected' : ''; ?>>TLS (587)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Usuário (e-mail)</label>
                            <input type="email" name="mail_user" class="form-control form-control-sm"
                                   value="<?php echo default_val('mail_user'); ?>"
                                   placeholder="noreply@seudominio.com.br">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Senha do e-mail</label>
                            <input type="password" name="mail_pass" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100">
                <i class="bi bi-check-lg me-2"></i>Testar e salvar configuração
            </button>

            <p class="text-center text-muted small mt-3">
                <i class="bi bi-info-circle me-1"></i>
                O assistente tenta gravar em <code><?php echo htmlspecialchars($env_outside); ?></code>
                (fora do public_html). Se não conseguir, cai para <code><?php echo htmlspecialchars($env_inside); ?></code>.
            </p>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
