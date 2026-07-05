<?php
/**
 * RH Hospital - Ponto de entrada principal (Front Controller)
 * Todas as requisições passam por aqui
 */

// Configurações iniciais
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Definir BASE_PATH (raiz do projeto)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/..'));
}

// Carregar configuração
$appConfig = require BASE_PATH . '/config/app.php';

// Detectar BASE_URL automaticamente
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Detectar o diretório base a partir do SCRIPT_NAME
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $baseDir = rtrim(dirname($scriptName), '/\\');
    // Se acessado via public/index.php, subir um nível
    if (str_ends_with($baseDir, '/public')) {
        $baseDir = dirname($baseDir);
    }
    $baseDir = ($baseDir === '/' || $baseDir === '\\') ? '' : $baseDir;
    define('BASE_URL', $protocol . '://' . $host . $baseDir . '/');
}

// ASSET_URL aponta para a pasta public/ (onde ficam CSS, JS, uploads)
if (!defined('ASSET_URL')) {
    define('ASSET_URL', BASE_URL);
}

date_default_timezone_set($appConfig['timezone']);

if ($appConfig['debug']) {
    ini_set('display_errors', '1');
}

// Autoloader (PSR-4 simples, sem Composer).
require BASE_PATH . '/app/helpers/Autoloader.php';
Autoloader::register();

// Iniciar sessão segura
Session::start();

// Roteamento simples baseado em query string
$page   = Sanitize::get('page', 'dashboard');
$action = Sanitize::get('action', 'index');
$id     = Sanitize::int($_GET['id'] ?? 0);

// Páginas públicas (não requerem login).
// `two_factor` é semi-público: a action `challenge`/`verify` precisa rodar
// antes do login completo. As outras actions chamam Auth::requireLogin()
// individualmente nos seus métodos.
$publicPages = ['login', 'public_recruitment', 'password_reset', 'privacy', 'two_factor'];

// Verificar autenticação
if (!in_array($page, $publicPages)) {
    Auth::requireLogin();
}

// Funcionário comum só pode acessar rotas essenciais (Minha Área, perfil,
// logout, notificações e arquivos que lhe pertençam).
$employeeAllowed = ['my', 'profile', 'logout', 'files', 'two_factor', 'notifications', 'requests', 'announcements'];
if (Session::userRole() === 'funcionario' && !in_array($page, $employeeAllowed, true)) {
    header('Location: index.php?page=my');
    exit;
}

// Mapa de rotas para controllers
$routes = [
    'login'              => 'AuthController',
    'logout'             => 'AuthController',
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
    'password_reset'     => 'PasswordResetController',
    'profile'            => 'ProfileController',
    'privacy'            => 'PrivacyController',
    'two_factor'         => 'TwoFactorController',
    'search'             => 'SearchController',
    'scores'             => 'ScoreController',
    'compliments'        => 'ComplimentController',
    'my'                 => 'MyController',
    'settings'           => 'SettingsController',
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

// Carregar controller via autoloader
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
        header('Location: index.php?page=dashboard');
        exit;
    }
} else {
    // Página não encontrada
    if (Session::isLoggedIn()) {
        header('Location: index.php?page=dashboard');
    } else {
        header('Location: index.php?page=login');
    }
    exit;
}
