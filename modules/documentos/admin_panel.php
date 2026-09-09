<?php
/**
 * PAINEL DE CONFIGURAÇÃO DO MÓDULO DOCUMENTOS — Administração central
 *
 * Incluído pelo núcleo (Core\AdminPanel) em
 *   index.php?m=admin&a=module&slug=documentos&tab=<sectors|categories>
 * com MODULE_SLUG/MODULE_PATH/MODULE_URL, CORE_ADMIN_TAB e
 * $GLOBALS['MODULE_PERMS'] já definidos e chdir(MODULE_PATH). O cabeçalho
 * e as abas do painel são desenhados pelo núcleo (Layout::embed) — aqui só
 * o conteúdo da aba ativa, renderizado pelo view() do módulo.
 *
 * Abas:
 *   sectors    — CRUD de setores (sectors.*)
 *   categories — CRUD de categorias de documentos (categories.*)
 *
 * Os POSTs continuam nas rotas do módulo (?m=documentos&url=admin/sector-store
 * etc. — controllers/admin.php) e, ao terminar, redirecionam para cá.
 */

// ── Bootstrap do módulo (o mesmo de index.php) ─────────────────────────────
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/formula.php';
require_once __DIR__ . '/includes/analysis.php';
require_once __DIR__ . '/includes/indicator_templates.php';

foreach (glob(MODELS_PATH . '/*.php') as $model_file) {
    require_once $model_file;
}

require_once CONTROLLERS_PATH . '/admin.php';

// ── Aba ativa ───────────────────────────────────────────────────────────────
$tab = (string) (core_admin_tab() ?? 'sectors');
if (!in_array($tab, ['sectors', 'categories'], true)) {
    Core\Layout::renderError(404, 'Aba de configuração desconhecida.');
    exit;
}

try {
    if ($tab === 'categories') {
        admin_render_categories();
    } else {
        admin_render_sectors();
    }
} catch (Throwable $ex) {
    log_error('admin_panel:' . $tab, $ex);
    Core\Layout::renderError(500, APP_DEBUG ? (string) $ex : 'Erro ao carregar a configuração do módulo.');
}
