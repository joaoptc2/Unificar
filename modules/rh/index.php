<?php
/**
 * RH — entry do módulo (front controller interno).
 *
 * Executado pelo front controller da plataforma (/index.php?m=rh&...),
 * que já: iniciou a sessão única, autenticou (exceto rotas públicas),
 * validou o acesso ao módulo, definiu MODULE_SLUG/MODULE_PATH/MODULE_URL,
 * carregou o conjunto de micropermissões do usuário
 * ($GLOBALS['MODULE_PERMS'] → core_can()/core_require()) e fez
 * chdir(MODULE_PATH).
 */

// Autoloader do módulo (controllers/helpers/models sem namespace).
require __DIR__ . '/app/helpers/Autoloader.php';
Autoloader::register();

// Assets do módulo agora vivem em /assets/rh/.
if (!defined('ASSET_URL')) {
    define('ASSET_URL', core_asset('rh') . '/');
}

// Uploads do módulo agora vivem em /uploads/rh/ (fora de modules/).
if (!defined('RH_UPLOADS_PATH')) {
    define('RH_UPLOADS_PATH', UPLOADS_PATH . '/rh');
}

// ---- Roteamento (query string legada: page/action/id) --------------------
$page   = Sanitize::get('page', 'dashboard');
$action = Sanitize::get('action', 'index');
$id     = Sanitize::int($_GET['id'] ?? 0);

// Fluxos de autenticação agora são do núcleo.
if ($page === 'logout') {
    core_redirect('index.php?m=auth&a=logout');
}
if (in_array($page, ['login', 'password_reset', 'two_factor'], true)) {
    core_redirect('index.php?m=auth&a=login');
}
if ($page === 'profile') {
    core_redirect('index.php?m=auth&a=profile');
}

// Raiz do módulo (?m=rh sem page): quem não enxerga o dashboard mas tem o
// portal do funcionário (my.view) vai direto para a Minha Área.
if (!isset($_GET['page']) && !core_can('dashboard.view') && core_can('my.view')) {
    header('Location: index.php?m=rh&page=my');
    exit;
}

// A aba "Vínculos de usuários" foi descontinuada: o vínculo é feito no
// cadastro do funcionário (seção "Acesso ao sistema").
if ($page === 'users') {
    header('Location: index.php?m=rh&page=employees');
    exit;
}

// Departamentos e cargos são configurados na Administração central
// (Core\AdminPanel). As telas (GET) redirecionam para o painel; os POSTs
// continuam sendo processados pelos controllers do módulo.
if (in_array($page, ['departments', 'positions'], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    core_redirect(core_admin_url('rh', $page));
}

// ---- MAPA CENTRAL rota → [controller, permissão mínima] ------------------
// A permissão mínima é exigida ANTES do despacho (403 via core_require).
// null = sem gate de rota: rota pública (is_public no manifesto) ou rota em
// que cada ação valida a própria micropermissão dentro do controller
// (ex.: files valida a .view do recurso dono do arquivo; requests separa
// view/create/respond; search filtra os resultados por core_can()).
$routes = [
    'dashboard'          => ['DashboardController',         'dashboard.view'],
    'employees'          => ['EmployeeController',          'employees.view'],
    'documents'          => ['DocumentController',          'employee_documents.view'],
    'certificates'       => ['CertificateController',       'certificates.create'],
    'expirations'        => ['ExpirationController',        'expirations.view'],
    'schedules'          => ['ScheduleController',          'schedules.view'],
    'birthdays'          => ['BirthdayController',          'birthdays.view'],
    'recruitment'        => ['RecruitmentController',       'recruitment.view'],
    'talent_pool'        => ['TalentPoolController',        'talent_pool.view'],
    'notifications'      => ['NotificationController',      null], // notificações do próprio usuário (núcleo)
    'departments'        => ['DepartmentController',        'departments.view'],
    'positions'          => ['PositionController',          'positions.view'],
    'public_recruitment' => ['PublicRecruitmentController', null], // pública (is_public)
    'privacy'            => ['PrivacyController',           null], // pública (is_public)
    'search'             => ['SearchController',            null], // resultados filtrados por core_can()
    'scores'             => ['ScoreController',             null], // gates por ação (scores.create/.delete)
    'compliments'        => ['ComplimentController',        null], // gates por ação (compliments.create/.delete)
    'my'                 => ['MyController',                'my.view'],
    'vacations'          => ['VacationController',          'vacations.view'],
    'shifts'             => ['ShiftController',             'shifts.view'],
    'onboarding'         => ['OnboardingController',        'onboarding.view'],
    'announcements'      => ['AnnouncementController',      'announcements.view'],
    'surveys'            => ['SurveyController',            'surveys.view'],
    'trainings'          => ['TrainingController',          'trainings.view'],
    'salary_history'     => ['SalaryHistoryController',     null], // gates por ação (salary_history.create/.delete)
    'dependents'         => ['DependentController',         null], // gates por ação (dependents.create/.delete)
    'requests'           => ['RequestController',           null], // gates por ação (requests.view/.create/.respond)
    'rewards'            => ['RewardController',            'rewards.view'], // gates por ação (rewards.create/.edit/.delete/.respond)
    'warnings'           => ['WarningController',           null], // gates por ação (warnings.create/.delete)
    'signatures'         => ['SignatureController',         null], // gates por ação (signatures.create/.view)
    'files'              => ['DownloadController',          null], // valida a .view do recurso dono do arquivo
];

if (!isset($routes[$page])) {
    // Rota desconhecida → raiz do módulo (que decide entre dashboard e my).
    header('Location: index.php?m=rh');
    exit;
}

[$controllerName, $minPerm] = $routes[$page];

// Gate de rota: usuário sem a permissão mínima recebe 403.
if ($minPerm !== null) {
    core_require($minPerm);
}

if (class_exists($controllerName)) {
    $controller = new $controllerName();
    if (method_exists($controller, $action)) {
        $controller->$action();
    } else {
        $controller->index();
    }
} else {
    Session::flash('error', 'Página não encontrada.');
    header('Location: index.php?m=rh');
    exit;
}
