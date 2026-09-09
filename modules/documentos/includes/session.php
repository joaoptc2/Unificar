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
 * core_can('<recurso>.<ação>') / core_require('<recurso>.<ação>').
 *
 * Contexto de SETOR: o módulo tem um seletor global (topo de todas as
 * páginas) com a opção "Todos os setores" (id 0) e todos os setores ativos
 * da unidade. O setor escolhido fica em $_SESSION['doc_sector_id'] e filtra
 * listagens, dashboard, indicadores e planos de ação (0 = sem filtro).
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

/** Setor em foco (0 = "Todos os setores", sem filtro). */
function get_sector_id()    { return (int) ($_SESSION['doc_sector_id'] ?? 0); }
function get_sector_name()  { return (string) ($_SESSION['doc_sector_name'] ?? ''); }

function switch_sector_context($sector_id, $sector_name) {
    $_SESSION['doc_sector_id']   = (int) $sector_id;
    $_SESSION['doc_sector_name'] = (string) $sector_name;
    return true;
}

/**
 * Garante que o contexto de setor da sessão é válido: se o setor escolhido
 * foi removido/desativado, volta para "Todos os setores". Também limpa
 * chaves legadas da sessão (doc_user_sectors).
 * Chamada no entry do módulo, após os models estarem carregados.
 */
function doc_sectors_ensure_loaded() {
    if (!is_logged_in()) return;
    unset($_SESSION['doc_user_sectors']);
    $sid = get_sector_id();
    if ($sid <= 0) {
        $_SESSION['doc_sector_id']   = 0;
        $_SESSION['doc_sector_name'] = '';
        return;
    }
    try {
        $s = sector_find($sid);
    } catch (Exception $ex) {
        $s = null;
    }
    if (!$s || empty($s['is_active']) || (int) $s['hospital_id'] !== get_hospital_id()) {
        switch_sector_context(0, '');
    } else {
        $_SESSION['doc_sector_name'] = $s['name'];
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
