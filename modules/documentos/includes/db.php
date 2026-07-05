<?php
/**
 * Conexão com Banco de Dados via PDO (Singleton)
 */

function db_connect() {
    static $pdo = null;

    if ($pdo !== null) return $pdo;

    // Detecção: configuração ausente → mensagem útil (não "Erro interno" genérico)
    if (DB_NAME === '' || DB_USER === '') {
        http_response_code(503);
        _db_show_setup_needed();
        exit;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        if (defined('LOGS_PATH') && is_dir(LOGS_PATH)) {
            @file_put_contents(
                LOGS_PATH . '/db_errors.log',
                '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        error_log('DB Connection Error: ' . $e->getMessage());
        if (APP_DEBUG) die('Erro de conexão: ' . $e->getMessage());
        http_response_code(503);
        _db_show_connection_error();
        exit;
    }
}

/**
 * Página amigável quando o .env não está configurado.
 */
function _db_show_setup_needed() {
    $setup_url = (defined('APP_URL') && APP_URL ? APP_URL : '') . '/setup.php';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<title>Configuração necessária</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body style="background:#f1f5f9;font-family:system-ui,sans-serif">
<div class="container" style="max-width:680px;margin-top:50px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h4 class="fw-bold text-warning mb-3">⚠ Sistema não configurado</h4>
      <p>O arquivo <code>.env</code> com as credenciais do banco de dados não foi encontrado.</p>
      <p>Você pode configurar de duas formas:</p>
      <h6 class="mt-4">Opção A — Assistente de configuração (recomendado)</h6>
      <p><a href="' . htmlspecialchars($setup_url) . '" class="btn btn-primary">
        Abrir assistente de configuração</a></p>
      <h6 class="mt-4">Opção B — Manual (via SSH ou File Manager)</h6>
      <ol class="small">
        <li>Copie <code>.env.example</code> para <code>.env</code> (idealmente UMA pasta acima do <code>public_html/</code>)</li>
        <li>Edite com suas credenciais reais (DB_NAME, DB_USER, DB_PASS, APP_URL, etc)</li>
        <li>Recarregue esta página</li>
      </ol>
      <hr>
      <p class="small text-muted mb-0">
        Após configurar, <strong>remova</strong> o arquivo <code>setup.php</code> por segurança.
      </p>
    </div>
  </div>
</div></body></html>';
}

/**
 * Página amigável quando a conexão falha (credenciais erradas, banco offline).
 */
function _db_show_connection_error() {
    $setup_url = (defined('APP_URL') && APP_URL ? APP_URL : '') . '/setup.php';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<title>Erro de conexão</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body style="background:#f1f5f9;font-family:system-ui,sans-serif">
<div class="container" style="max-width:680px;margin-top:50px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h4 class="fw-bold text-danger mb-3">✗ Não foi possível conectar ao banco</h4>
      <p>As credenciais podem estar incorretas ou o servidor MySQL está inacessível.</p>
      <p>Possíveis causas:</p>
      <ul class="small">
        <li>Senha do banco mudou</li>
        <li>Nome do banco/usuário digitados errado no <code>.env</code></li>
        <li>Servidor MySQL da Hostinger temporariamente indisponível</li>
      </ul>
      <p><a href="' . htmlspecialchars($setup_url) . '?force=1" class="btn btn-primary">
        Reconfigurar credenciais</a></p>
      <p class="small text-muted">
        Detalhes do erro estão em <code>logs/db_errors.log</code>.
      </p>
    </div>
  </div>
</div></body></html>';
}

function db_query($sql, $params = []) {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_query_one($sql, $params = []) {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_execute($sql, $params = []) {
    $stmt = db_connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_last_id() {
    return db_connect()->lastInsertId();
}

/**
 * Verifica se uma coluna existe em uma tabela (para detectar migrations pendentes).
 * Resultado é cacheado estaticamente dentro da requisição.
 */
function db_has_column($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $row = db_query_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return $cache[$key] = !empty($row);
    } catch (Exception $ex) {
        return $cache[$key] = false;
    }
}

/**
 * Verifica se uma tabela existe.
 */
function db_has_table($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $row = db_query_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
        return $cache[$table] = !empty($row);
    } catch (Exception $ex) {
        return $cache[$table] = false;
    }
}

/**
 * Verifica se todas as migrations da versão 1.1/1.2 foram aplicadas.
 * Retorna array com ['ok' => bool, 'missing' => ['coluna/tabela faltando', ...]].
 */
function db_check_migrations() {
    $missing = [];

    // Migration 002
    if (!db_has_column('users', 'force_password_change')) $missing[] = 'users.force_password_change (migration 002)';
    if (!db_has_table('login_attempts'))                  $missing[] = 'tabela login_attempts (migration 002)';
    if (!db_has_table('password_resets'))                 $missing[] = 'tabela password_resets (migration 002)';
    if (!db_has_column('documents', 'mime_type'))         $missing[] = 'documents.mime_type (migration 002)';

    // Migration 003
    if (!db_has_column('indicators', 'formula'))          $missing[] = 'indicators.formula (migration 003)';
    if (!db_has_column('indicators', 'goal_numeric'))     $missing[] = 'indicators.goal_numeric (migration 003)';
    if (!db_has_column('indicators', 'chart_type'))       $missing[] = 'indicators.chart_type (migration 003)';
    if (!db_has_table('indicator_variables'))             $missing[] = 'tabela indicator_variables (migration 003)';
    if (!db_has_table('indicator_data_values'))           $missing[] = 'tabela indicator_data_values (migration 003)';

    // Migration 005
    if (!db_has_column('indicators', 'benchmark_value'))  $missing[] = 'indicators.benchmark_value (migration 005)';
    if (!db_has_column('indicators', 'responsible_user_id')) $missing[] = 'indicators.responsible_user_id (migration 005)';
    if (!db_has_table('indicator_actions'))               $missing[] = 'tabela indicator_actions (migration 005)';
    if (!db_has_table('document_versions'))               $missing[] = 'tabela document_versions (migration 005)';

    return ['ok' => empty($missing), 'missing' => $missing];
}

/**
 * Executa bloco em transação. Faz rollback em caso de exceção.
 */
function db_transaction(callable $fn) {
    $pdo = db_connect();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}
