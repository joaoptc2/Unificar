<?php
/**
 * RH — painel de configuração na ADMINISTRAÇÃO CENTRAL (Core\AdminPanel).
 *
 * Aberto pelo núcleo em index.php?m=admin&a=module&slug=rh&tab=<aba>[&action=..],
 * já com MODULE_SLUG/MODULE_PATH/MODULE_URL, CORE_ADMIN_TAB e MODULE_PERMS
 * definidos e chdir(MODULE_PATH). O que for renderizado via Core\Layout
 * aparece dentro do "chrome" da administração (abas já desenhadas pelo núcleo).
 *
 * Abas (module.php → 'admin' → 'tabs'):
 *   departments  CRUD de departamentos   (DepartmentController)
 *   positions    CRUD de cargos          (PositionController)
 *   access       acessos dos funcionários (EmployeeAccessController)
 *   birthdays    layout do cartaz A4     (BirthdayController::configure/save_config/preview)
 *
 * Os POSTs de departamentos/cargos continuam nas rotas do módulo
 * (?m=rh&page=departments|positions&action=store|update|delete) e voltam para cá.
 */

require __DIR__ . '/app/helpers/Autoloader.php';
Autoloader::register();

if (!defined('ASSET_URL')) {
    define('ASSET_URL', core_asset('rh') . '/');
}
if (!defined('RH_UPLOADS_PATH')) {
    define('RH_UPLOADS_PATH', UPLOADS_PATH . '/rh');
}

$tab    = (string)(core_admin_tab() ?? 'departments');
$action = preg_replace('/[^a-z0-9_]/', '', strtolower(Sanitize::get('action', 'index'))) ?: 'index';

// Mapa aba → [controller, ações permitidas neste painel]
$panels = [
    'departments' => ['DepartmentController',     ['index', 'create', 'edit']],
    'positions'   => ['PositionController',       ['index', 'create', 'edit']],
    'access'      => ['EmployeeAccessController', ['index', 'ensure', 'reset', 'create_all']],
    'birthdays'   => ['BirthdayController',       ['configure', 'save_config', 'preview']],
];

if (!isset($panels[$tab])) {
    Core\Layout::renderError(404, 'Aba de configuração desconhecida.');
    exit;
}

[$controllerName, $allowed] = $panels[$tab];
if ($tab === 'birthdays' && $action === 'index') {
    $action = 'configure';
}
if (!in_array($action, $allowed, true)) {
    $action = $allowed[0];
}

$controller = new $controllerName();
$controller->$action();
