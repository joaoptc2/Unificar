<?php
/**
 * ============================================================
 * PLATAFORMA UNIFICADA — Bootstrap do núcleo
 * Carrega configuração, autoloader das classes Core\*,
 * sessão única e helpers globais (prefixados com core_).
 * ============================================================
 */

declare(strict_types=1);

if (defined('CORE_BOOTSTRAPPED')) {
    return;
}
define('CORE_BOOTSTRAPPED', true);

define('BASE_PATH', dirname(__DIR__));
define('CORE_PATH', __DIR__);
define('MODULES_PATH', BASE_PATH . '/modules');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// ---- Autoloader das classes Core\* ------------------------------------
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Core\\')) {
        $file = CORE_PATH . '/src/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// ---- Configuração ------------------------------------------------------
$configFile = BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    // Sem configuração: manda para o instalador (quando em contexto web)
    if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    $configFile = BASE_PATH . '/config/config.example.php';
}
Core\Config::load(require $configFile);

date_default_timezone_set(Core\Config::get('app.timezone', 'America/Sao_Paulo'));

// ---- Erros / debug -----------------------------------------------------
if (Core\Config::get('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');

// ---- URL base ----------------------------------------------------------
if (!defined('BASE_URL')) {
    $configured = Core\Config::get('app.base_url');
    if ($configured) {
        define('BASE_URL', rtrim((string) $configured, '/'));
    } elseif (PHP_SAPI === 'cli') {
        define('BASE_URL', '');
    } else {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        define('BASE_URL', $scheme . '://' . $host . $dir);
    }
}

// ---- Headers de segurança + sessão (somente web) ------------------------
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    Core\Session::start();
}

require_once CORE_PATH . '/helpers.php';
