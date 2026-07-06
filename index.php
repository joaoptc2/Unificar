<?php
/**
 * ============================================================
 * PLATAFORMA UNIFICADA — Front controller
 *
 * Roteamento: index.php?m=<módulo>&...
 *   m=auth   → login/logout/perfil/notificações (núcleo)
 *   m=admin  → administração central (usuários/permissões)
 *   m=<slug> → módulos (documentos, chat, rh, manutencao)
 *   sem m    → portal inicial (cartões de módulos)
 *
 * Cada módulo mantém seu roteamento interno legado (page=, url=, ...);
 * o núcleo resolve autenticação, permissão e o papel do usuário no
 * módulo antes de delegar.
 * ============================================================
 */

require __DIR__ . '/core/bootstrap.php';

use Core\Access;
use Core\Auth;
use Core\Layout;
use Core\Modules;

$module = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_GET['m'] ?? '')));

// ---- Rotas do núcleo ----------------------------------------------------
if ($module === '' || $module === 'auth') {
    require CORE_PATH . '/controllers/auth.php';
    exit;
}

if ($module === 'admin') {
    require CORE_PATH . '/controllers/admin.php';
    exit;
}

// ---- Módulos ------------------------------------------------------------
$manifest = Modules::manifest($module);
if ($manifest === null || !($manifest['active'] ?? true)) {
    Layout::renderError(404, 'Módulo não encontrado ou desativado.');
    exit;
}

// Rotas públicas do módulo (ex.: OS anônima, vagas públicas)
$isPublic = isset($manifest['is_public'])
    && is_callable($manifest['is_public'])
    && ($manifest['is_public'])($_GET);

if (!$isPublic) {
    Auth::requireLogin();

    // Troca de senha obrigatória antes de qualquer módulo
    $me = Auth::user();
    if (!empty($me['force_password_change'])) {
        core_redirect('index.php?m=auth&a=security&force=1');
    }

    // Acesso ao módulo = ter ao menos UMA micropermissão nele
    if (!Core\Perms::hasAny((int) Auth::id(), $module)) {
        http_response_code(403);
        Layout::renderError(403, 'Você não tem acesso a este módulo. Solicite ao administrador.');
        exit;
    }
}

define('MODULE_SLUG', $module);
define('MODULE_PATH', $manifest['path']);
define('MODULE_URL', BASE_URL . '/index.php?m=' . $module);

/**
 * Permissões efetivas do usuário neste módulo, disponíveis para o código
 * do módulo via core_can()/core_require(). Definidas por request.
 */
$GLOBALS['MODULE_PERMS'] = Auth::check()
    ? Core\Perms::effective((int) Auth::id(), $module)
    : [];

chdir(MODULE_PATH);
require MODULE_PATH . '/' . ($manifest['entry'] ?? 'index.php');
