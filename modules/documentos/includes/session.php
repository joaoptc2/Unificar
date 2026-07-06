<?php
/**
 * Sessão / autenticação do módulo DOCUMENTOS — adaptador do núcleo.
 *
 * A sessão única é iniciada pelo core/bootstrap.php e o login acontece nas
 * telas do núcleo (?m=auth&a=login). Estas funções mantêm a API procedural
 * legada do módulo, delegando para Core\Auth e para as variáveis de sessão
 * garantidas pelo núcleo (user_id, user_name, user_email, hospital_id, ...).
 *
 * Autorização: o núcleo define $GLOBALS['MODULE_PERMS'] por request e expõe
 * core_can('<recurso>.<ação>') / core_require('<recurso>.<ação>'). Os antigos
 * helpers de papel (níveis admin/gestor/operador) foram REMOVIDOS — cada
 * checagem usa a micropermissão específica da ação diretamente nos
 * controllers e views.
 */

/**
 * No-op — a sessão já foi iniciada pelo núcleo (Core\Session::start()).
 * Mantida apenas para compatibilidade de assinatura.
 */
function session_init() {
    // intencionalmente vazio
}

// ── Autenticação ────────────────────────────────────────────────────────────

function is_logged_in() {
    return Core\Auth::check();
}

/**
 * Exige autenticação — redireciona para o login central do núcleo.
 * (Troca de senha obrigatória já é tratada pelo front controller do núcleo.)
 */
function require_login() {
    if (!is_logged_in()) {
        core_redirect('index.php?m=auth&a=login');
    }
}

// ── Dados do usuário (sessão do núcleo) ─────────────────────────────────────

function get_user_id()    { return (int) ($_SESSION['user_id'] ?? 0); }
function get_user_name()  { return $_SESSION['user_name']  ?? ''; }
function get_user_email() { return $_SESSION['user_email'] ?? ''; }

function get_hospital_id()   { return (int) ($_SESSION['hospital_id'] ?? 0); }
function get_hospital_name() { return $_SESSION['hospital_name'] ?? ''; }

// ── Contexto de setor (namespace doc_ na sessão compartilhada) ──────────────

function get_sector_id()    { return (int) ($_SESSION['doc_sector_id'] ?? 0); }
function get_sector_name()  { return $_SESSION['doc_sector_name']  ?? ''; }
function get_user_sectors() { return $_SESSION['doc_user_sectors'] ?? []; }

function switch_sector_context($sector_id, $sector_name) {
    $_SESSION['doc_sector_id']   = (int) $sector_id;
    $_SESSION['doc_sector_name'] = $sector_name;
    return true;
}

/**
 * Carrega os setores do usuário na sessão no primeiro acesso ao módulo
 * (definindo o primeiro setor como contexto ativo).
 * Chamada no entry do módulo, após os models estarem carregados.
 */
function doc_sectors_ensure_loaded() {
    if (!is_logged_in() || isset($_SESSION['doc_user_sectors'])) return;
    try {
        $sectors = user_sector_list(get_user_id());
    } catch (Exception $ex) {
        $sectors = [];
    }
    $_SESSION['doc_user_sectors'] = $sectors;
    if (!empty($sectors) && empty($_SESSION['doc_sector_id'])) {
        $_SESSION['doc_sector_id']   = (int) $sectors[0]['id'];
        $_SESSION['doc_sector_name'] = $sectors[0]['name'];
    }
}

// ── Flash messages (namespace do módulo) ────────────────────────────────────

function set_flash($type, $message) {
    $_SESSION['doc_flash'][$type] = $message;
}

function get_flash($type) {
    $msg = $_SESSION['doc_flash'][$type] ?? null;
    unset($_SESSION['doc_flash'][$type]);
    return $msg;
}

function has_flash($type) {
    return !empty($_SESSION['doc_flash'][$type]);
}
