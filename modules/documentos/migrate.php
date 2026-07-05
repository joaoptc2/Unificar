<?php
/**
 * Aplicador de migrations via web.
 *
 * Acesse: https://seudominio.com.br/migrate.php
 *
 * Protegido: exige autenticação como admin global do sistema.
 * Mostra status das migrations + botão para aplicar as pendentes.
 *
 * REMOVA este arquivo após aplicar, por segurança.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';

session_init();

// ─── Autenticação ─────────────────────────────────────────────────────────
// Só admins globais podem rodar migrations (role_id = 1).
$unauthorized = !is_logged_in() || (get_user_role() !== 1);
if ($unauthorized) {
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Não autorizado</title>'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
       . '<body style="background:#f1f5f9;font-family:system-ui"><div class="container" style="max-width:560px;margin-top:80px">'
       . '<div class="card shadow-sm"><div class="card-body">'
       . '<h4 class="text-danger">🔒 Acesso restrito</h4>'
       . '<p>Esta ferramenta só pode ser usada por Administradores Globais logados.</p>'
       . '<p><a href="' . e(url('login')) . '" class="btn btn-primary">Fazer login</a></p>'
       . '</div></div></div></body></html>';
    exit;
}

// ─── Aplicar migrations ───────────────────────────────────────────────────
$results = null;
if (($_POST['action'] ?? '') === 'apply') {
    csrf_validate();
    $results = _migrate_apply_pending();
}

// ─── Status atual ─────────────────────────────────────────────────────────
$dir = __DIR__ . '/database/migrations';
$files = is_dir($dir) ? glob($dir . '/*.sql') : [];
sort($files);

// Garante tabela schema_migrations
_migrate_ensure_table();

$applied_names = _migrate_applied_names();

$migrations = [];
foreach ($files as $file) {
    $name = basename($file);
    $migrations[] = [
        'name'    => $name,
        'path'    => $file,
        'size'    => filesize($file),
        'applied' => in_array($name, $applied_names, true),
    ];
}

// Detalha o que ainda falta no schema
$check = db_check_migrations();

// ─── Funções auxiliares ───────────────────────────────────────────────────
function _migrate_ensure_table() {
    try {
        db_connect()->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration`  VARCHAR(200) NOT NULL,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $ex) {}
}

function _migrate_applied_names() {
    try {
        $rows = db_query("SELECT migration FROM schema_migrations");
        return array_column($rows, 'migration');
    } catch (Exception $ex) {
        return [];
    }
}

function _migrate_apply_pending() {
    _migrate_ensure_table();
    $applied = _migrate_applied_names();
    $dir = __DIR__ . '/database/migrations';
    $files = glob($dir . '/*.sql');
    sort($files);

    $output = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) {
            $output[] = ['name' => $name, 'status' => 'skip', 'message' => 'já aplicada'];
            continue;
        }

        $sql = file_get_contents($file);
        $stmts = _migrate_split_sql($sql);
        $errors = [];
        $ok = 0;

        foreach ($stmts as $stmt) {
            try {
                db_connect()->exec($stmt);
                $ok++;
            } catch (PDOException $ex) {
                $msg = $ex->getMessage();
                // Ignora erros idempotentes esperados
                if (stripos($msg, 'duplicate column name') !== false
                 || stripos($msg, 'duplicate key name') !== false
                 || stripos($msg, "already exists") !== false
                 || stripos($msg, 'check that column/key exists') !== false
                 || stripos($msg, "can't drop") !== false) {
                    $ok++;
                    continue;
                }
                $errors[] = substr($msg, 0, 200);
            }
        }

        if (empty($errors)) {
            try {
                db_execute("INSERT INTO schema_migrations (migration) VALUES (?)", [$name]);
            } catch (Exception $ex) {}
            $output[] = ['name' => $name, 'status' => 'ok', 'message' => "$ok statements aplicados"];
        } else {
            $output[] = ['name' => $name, 'status' => 'error', 'message' => implode('; ', $errors)];
        }
    }
    return $output;
}

function _migrate_split_sql($sql) {
    // Remove comentários de linha
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    // Quebra por ; (simples, sem suportar DELIMITER)
    $parts = explode(';', $sql);
    $stmts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $stmts[] = $p;
    }
    return $stmts;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Migrations — <?php echo e(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family:system-ui, sans-serif; }
        .container { max-width:860px; margin-top:40px; margin-bottom:80px; }
        .card { border:0; box-shadow:0 2px 12px rgba(0,0,0,.08); }
    </style>
</head>
<body>
<div class="container">
    <div class="text-center mb-4">
        <i class="bi bi-database" style="font-size:2.5rem;color:#4f46e5"></i>
        <h3 class="fw-bold mt-2">Migrations do Banco</h3>
        <p class="text-muted small">Aplique as atualizações de schema pendentes</p>
    </div>

    <?php if ($results): ?>
        <div class="card mb-3">
            <div class="card-header">Resultado</div>
            <div class="card-body">
                <?php foreach ($results as $r):
                    $cls = ['ok' => 'success', 'skip' => 'secondary', 'error' => 'danger'][$r['status']];
                    $icn = ['ok' => 'check-circle', 'skip' => 'minus-circle', 'error' => 'exclamation-circle'][$r['status']];
                ?>
                    <div class="alert alert-<?php echo $cls; ?> py-2 mb-2">
                        <i class="bi bi-<?php echo $icn; ?> me-2"></i>
                        <strong><?php echo e($r['name']); ?>:</strong>
                        <?php echo e($r['message']); ?>
                    </div>
                <?php endforeach; ?>
                <div class="text-end">
                    <a href="<?php echo e(url('indicators')); ?>" class="btn btn-primary">
                        <i class="bi bi-graph-up me-1"></i>Ir aos Indicadores
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($check['missing'])): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header bg-warning-subtle">
                <strong>⚠ Schema desatualizado</strong>
            </div>
            <div class="card-body">
                <p>Os seguintes itens estão faltando no banco de dados:</p>
                <ul class="small">
                    <?php foreach ($check['missing'] as $m): ?>
                        <li><code><?php echo e($m); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>Schema está atualizado.
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Migrations disponíveis</div>
        <div class="card-body p-0">
            <?php if (empty($migrations)): ?>
                <p class="p-3 text-muted mb-0">Nenhum arquivo de migration encontrado em <code>database/migrations/</code>.</p>
            <?php else: ?>
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr><th>Arquivo</th><th>Tamanho</th><th class="text-end">Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($migrations as $m): ?>
                        <tr>
                            <td><code><?php echo e($m['name']); ?></code></td>
                            <td><?php echo format_bytes($m['size']); ?></td>
                            <td class="text-end">
                                <?php if ($m['applied']): ?>
                                    <span class="badge bg-success">Aplicada</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pendente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if (array_filter($migrations, function($m) { return !$m['applied']; })): ?>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="btn btn-primary btn-lg w-100"
                onclick="return confirm('Aplicar todas as migrations pendentes? Faça backup antes.')">
            <i class="bi bi-play-fill me-2"></i>Aplicar migrations pendentes
        </button>
    </form>
    <?php endif; ?>

    <p class="text-center mt-4">
        <strong class="text-danger">⚠ Remova este arquivo (<code>migrate.php</code>)</strong> após terminar —
        ele é uma ferramenta administrativa sensível.
    </p>
</div>
</body>
</html>
