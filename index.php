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

    Access::requireModule($module);
}

define('MODULE_SLUG', $module);
define('MODULE_PATH', $manifest['path']);
define('MODULE_URL', BASE_URL . '/index.php?m=' . $module);

/**
 * Papel do usuário neste módulo, disponível para o código legado.
 * Definido por request (cada request de módulo redefine o valor),
 * portanto seguro mesmo com módulos abertos em abas diferentes.
 */
$GLOBALS['MODULE_ROLE'] = Auth::check()
    ? Access::roleFor((int) Auth::id(), $module)
    : 'none';

chdir(MODULE_PATH);
require MODULE_PATH . '/' . ($manifest['entry'] ?? 'index.php');
