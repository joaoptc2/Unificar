<?php
/**
 * Configurações do módulo DOCUMENTOS (plataforma unificada).
 *
 * Banco, sessão, e-mail e autenticação são responsabilidade do núcleo
 * (core/bootstrap.php + config/config.php). Aqui ficam apenas caminhos
 * internos do módulo e constantes de negócio.
 */

// ── Modo de depuração (herdado do núcleo) ───────────────────────────────────
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', (bool) core_config('app.debug', false));
}

// ── Informações do módulo ───────────────────────────────────────────────────
define('APP_NAME',    'Gestão Documental');
define('APP_VERSION', '2.0.0');

// ── Caminhos internos ───────────────────────────────────────────────────────
define('INCLUDES_PATH',    __DIR__);
define('CONTROLLERS_PATH', dirname(__DIR__) . '/controllers');
define('MODELS_PATH',      dirname(__DIR__) . '/models');
define('VIEWS_PATH',       dirname(__DIR__) . '/views');
define('LOGS_PATH',        dirname(__DIR__) . '/logs');
define('CACHE_PATH',       dirname(__DIR__) . '/cache');
define('ASSETS_VERSION',   APP_VERSION);

// Uploads do módulo ficam na raiz da plataforma: /uploads/documentos
// (UPLOADS_PATH — sem prefixo — é a raiz de uploads definida pelo núcleo)
define('DOC_UPLOADS_PATH', defined('UPLOADS_PATH')
    ? UPLOADS_PATH . '/documentos'
    : dirname(__DIR__, 3) . '/uploads/documentos');

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

// ── Notificações ────────────────────────────────────────────────────────────
define('NOTIFY_DAYS_BEFORE', 30);

// ── Paginação ───────────────────────────────────────────────────────────────
define('PAGINATION_PER_PAGE', 20);
