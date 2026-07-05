<?php
/**
 * RH — entry do módulo (front controller interno).
 *
 * Executado pelo front controller da plataforma (/index.php?m=rh&...),
 * que já: iniciou a sessão única, autenticou (exceto rotas públicas),
 * validou o acesso ao módulo, definiu MODULE_SLUG/MODULE_PATH/MODULE_URL
 * e $GLOBALS['MODULE_ROLE'], e fez chdir(MODULE_PATH).
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

// ---- Compatibilidade de sessão -----------------------------------------
// O núcleo não grava user_role/user_department_id; o código legado lê
// essas chaves diretamente em alguns pontos. Papel vem SEMPRE do RBAC do
// núcleo ($GLOBALS['MODULE_ROLE']) e o vínculo departamental do perfil
// do módulo (rh_user_profile).
if (Core\Auth::check()) {
    $_SESSION['user_role'] = $GLOBALS['MODULE_ROLE'] ?? 'none';
    try {
        $profile = Core\DB::queryOne(
            'SELECT employee_id, department_id FROM rh_user_profile WHERE user_id = ?',
            [Core\Auth::id()]
        );
        $_SESSION['user_department_id'] = $profile['department_id'] ?? null;
    } catch (\Throwable $e) {
        $_SESSION['user_department_id'] = null;
    }
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

// Funcionário comum só pode acessar rotas essenciais (Minha Área,
// solicitações, comunicados, notificações e arquivos que lhe pertençam).
// profile/logout/two_factor saíram da lista — agora são rotas do núcleo.
$employeeAllowed = ['my', 'files', 'notifications', 'requests', 'announcements'];
if (($GLOBALS['MODULE_ROLE'] ?? '') === 'funcionario' && !in_array($page, $employeeAllowed, true)) {
    header('Location: index.php?m=rh&page=my');
    exit;
}

// Mapa de rotas para controllers (login/password_reset/two_factor/profile
// e settings — personalização visual — removidos: núcleo cuida).
$routes = [
    'dashboard'          => 'DashboardController',
    'employees'          => 'EmployeeController',
    'documents'          => 'DocumentController',
    'certificates'       => 'CertificateController',
    'expirations'        => 'ExpirationController',
    'schedules'          => 'ScheduleController',
    'birthdays'          => 'BirthdayController',
    'recruitment'        => 'RecruitmentController',
    'talent_pool'        => 'TalentPoolController',
    'notifications'      => 'NotificationController',
    'users'              => 'UserController',
    'departments'        => 'DepartmentController',
    'positions'          => 'PositionController',
    'public_recruitment' => 'PublicRecruitmentController',
    'privacy'            => 'PrivacyController',
    'search'             => 'SearchController',
    'scores'             => 'ScoreController',
    'compliments'        => 'ComplimentController',
    'my'                 => 'MyController',
    'vacations'          => 'VacationController',
    'shifts'             => 'ShiftController',
    'onboarding'         => 'OnboardingController',
    'announcements'      => 'AnnouncementController',
    'surveys'            => 'SurveyController',
    'trainings'          => 'TrainingController',
    'salary_history'     => 'SalaryHistoryController',
    'dependents'         => 'DependentController',
    'requests'           => 'RequestController',
    'warnings'           => 'WarningController',
    'signatures'         => 'SignatureController',
    'files'              => 'DownloadController',
];

if (isset($routes[$page])) {
    $controllerName = $routes[$page];
    if (class_exists($controllerName)) {
        $controller = new $controllerName();
        if (method_exists($controller, $action)) {
            $controller->$action();
        } else {
            $controller->index();
        }
    } else {
        Session::flash('error', 'Página não encontrada.');
        header('Location: index.php?m=rh&page=dashboard');
        exit;
    }
} else {
    header('Location: index.php?m=rh&page=dashboard');
    exit;
}
