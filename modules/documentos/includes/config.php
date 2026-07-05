<?php
/**
 * Configurações Globais do Sistema
 *
 * Valores sensíveis (DB, SMTP) devem vir do arquivo .env,
 * idealmente localizado UMA PASTA ACIMA do public_html.
 */

require_once __DIR__ . '/env.php';
env_load();

// ── Modo de Depuração ──────────────────────────────────────────────────────
define('APP_DEBUG', (bool) env('APP_DEBUG', false));

// ── Informações da Aplicação ────────────────────────────────────────────────
define('APP_NAME',    env('APP_NAME',    'Sistema de Gestão Documental'));
define('APP_VERSION', '1.1.0');
define('APP_URL',     rtrim((string) env('APP_URL', ''), '/'));

// ── Caminhos ────────────────────────────────────────────────────────────────
define('BASE_PATH',        __DIR__ . '/..');
define('INCLUDES_PATH',    __DIR__);
define('CONTROLLERS_PATH', BASE_PATH . '/controllers');
define('MODELS_PATH',      BASE_PATH . '/models');
define('VIEWS_PATH',       BASE_PATH . '/views');
define('UPLOADS_PATH',     BASE_PATH . '/uploads');
define('LOGS_PATH',        BASE_PATH . '/logs');
define('CACHE_PATH',       BASE_PATH . '/cache');
define('ASSETS_VERSION',   APP_VERSION);

// ── Banco de Dados ──────────────────────────────────────────────────────────
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', ''));
define('DB_USER',    env('DB_USER', ''));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ── Upload ──────────────────────────────────────────────────────────────────
define('UPLOAD_MAX_SIZE', 50 * 1024 * 1024); // 50 MB
define('UPLOAD_ALLOWED_TYPES', [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'txt'  => 'text/plain',
]);

// ── E-mail (SMTP) ──────────────────────────────────────────────────────────
define('MAIL_ENABLED',    (bool) env('MAIL_ENABLED', false));
define('MAIL_HOST',       env('MAIL_HOST', ''));
define('MAIL_PORT',       (int) env('MAIL_PORT', 465));
define('MAIL_ENCRYPTION', env('MAIL_ENCRYPTION', 'ssl')); // ssl | tls | ''
define('MAIL_USER',       env('MAIL_USER', ''));
define('MAIL_PASS',       env('MAIL_PASS', ''));
define('MAIL_FROM_NAME',  env('MAIL_FROM_NAME', APP_NAME));

// ── Sessão ──────────────────────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600);  // 1 hora
define('SESSION_NAME',     'HOSP_SESSID');

// ── Segurança ──────────────────────────────────────────────────────────────
define('LOGIN_MAX_ATTEMPTS',     (int) env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES',  (int) env('LOGIN_LOCKOUT_MINUTES', 15));
define('PASSWORD_MIN_LENGTH',    8);
define('PASSWORD_RESET_TTL_MIN', (int) env('PASSWORD_RESET_TTL_MINUTES', 30));

// ── Notificações ────────────────────────────────────────────────────────────
define('NOTIFY_DAYS_BEFORE', (int) env('NOTIFY_DAYS_BEFORE', 30));

// ── Paginação ──────────────────────────────────────────────────────────────
define('PAGINATION_PER_PAGE', 20);

// ── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');

// ── Exibição de erros ───────────────────────────────────────────────────────
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    if (is_dir(LOGS_PATH) && is_writable(LOGS_PATH)) {
        ini_set('error_log', LOGS_PATH . '/php_errors.log');
    }
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
}
