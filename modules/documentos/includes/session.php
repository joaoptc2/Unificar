<?php
/**
 * Gerenciamento de Sessão Segura
 */

/**
 * Detecta HTTPS considerando proxy reverso (comum em Hostinger/Cloudflare).
 */
function is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['HTTP_CF_VISITOR'])
        && stripos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false) return true;
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) return true;
    return false;
}

/**
 * Inicia sessão com configurações seguras.
 */
function session_init() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

    if (is_https()) {
        ini_set('session.cookie_secure', 1);
    }

    session_name(SESSION_NAME);
    session_start();

    // Regenera ID a cada 15 minutos para evitar session fixation
    if (!isset($_SESSION['_last_regen'])) {
        $_SESSION['_last_regen'] = time();
    } elseif (time() - $_SESSION['_last_regen'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }

    // Expiração por inatividade
    if (isset($_SESSION['_last_activity'])
        && time() - $_SESSION['_last_activity'] > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}

// ── Autenticação ────────────────────────────────────────────────────────────

function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

/**
 * Exige autenticação. Se a flag force_password_change estiver ativa,
 * força o usuário a trocar a senha antes de acessar qualquer outra página.
 */
function require_login() {
    if (!is_logged_in()) {
        set_flash('error', 'Você precisa fazer login para acessar esta página.');
        redirect('login');
    }

    // Força troca de senha no primeiro acesso ou reset obrigatório
    if (!empty($_SESSION['force_password_change'])) {
        $current = ($_GET['url'] ?? '');
        if (strpos($current, 'profile/change-password') !== 0
            && strpos($current, 'logout') !== 0) {
            set_flash('info', 'Você precisa definir uma nova senha antes de continuar.');
            redirect('profile/change-password');
        }
    }
}

function require_admin() {
    require_login();
    if (get_user_role() > 1) {
        set_flash('error', 'Acesso negado. Permissão insuficiente.');
        redirect('dashboard');
    }
}

function require_manager() {
    require_login();
    if (get_user_role() > 2) {
        set_flash('error', 'Acesso negado. Permissão insuficiente.');
        redirect('dashboard');
    }
}

/**
 * Armazena dados do usuário na sessão após login.
 */
function session_login($user) {
    session_regenerate_id(true);
    $_SESSION['user_id']          = (int) $user['id'];
    $_SESSION['user_name']        = $user['name'];
    $_SESSION['user_email']       = $user['email'];
    $_SESSION['user_role']        = (int) $user['role_id'];
    $_SESSION['hospital_id']      = (int) $user['hospital_id'];
    $_SESSION['hospital_name']    = $user['hospital_name'] ?? '';
    $_SESSION['force_password_change'] = !empty($user['force_password_change']);
    $_SESSION['_last_activity']   = time();
    $_SESSION['_last_regen']      = time();
    unset($_SESSION['csrf_token']); // novo token na nova sessão
}

function session_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Dados do Usuário ────────────────────────────────────────────────────────

function get_user_id()       { return $_SESSION['user_id']       ?? 0; }
function get_user_name()     { return $_SESSION['user_name']     ?? ''; }
function get_user_email()    { return $_SESSION['user_email']    ?? ''; }
function get_user_role()     { return $_SESSION['user_role']     ?? 99; }
function get_hospital_id()   { return $_SESSION['hospital_id']   ?? 0; }
function get_hospital_name() { return $_SESSION['hospital_name'] ?? ''; }

function is_admin()   { return get_user_role() === 1; }
function is_manager() { return get_user_role() <= 2; }

function get_sector_id()   { return $_SESSION['sector_id']    ?? 0; }
function get_sector_name() { return $_SESSION['sector_name']  ?? ''; }
function get_user_sectors(){ return $_SESSION['user_sectors']  ?? []; }

function switch_sector_context($sector_id, $sector_name) {
    $_SESSION['sector_id']   = (int) $sector_id;
    $_SESSION['sector_name'] = $sector_name;
    return true;
}

function switch_hospital_context($hospital_id, $hospital_name) {
    if (!is_admin()) return false;
    $_SESSION['hospital_id']   = (int) $hospital_id;
    $_SESSION['hospital_name'] = $hospital_name;
    return true;
}

// ── Flash Messages ──────────────────────────────────────────────────────────

function set_flash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

function get_flash($type) {
    $msg = $_SESSION['flash'][$type] ?? null;
    unset($_SESSION['flash'][$type]);
    return $msg;
}

function has_flash($type) {
    return !empty($_SESSION['flash'][$type]);
}
