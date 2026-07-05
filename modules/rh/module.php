<?php
/**
 * Manifesto do módulo RH (Recursos Humanos hospitalar).
 *
 * Papéis (do maior para o menor privilégio):
 *   admin, rh, gestor, visualizador, funcionario
 *
 * O menu reproduz a lógica de visibilidade da sidebar legada
 * (Auth::can por papel). Como o manifesto também é carregado fora do
 * módulo (topbar, admin central), o closure usa core_module_url().
 */

return [
    'slug'        => 'rh',
    'name'        => 'RH',
    'icon'        => 'bi-people',
    'description' => 'Funcionários, vencimentos, férias, escalas, recrutamento e comunicados.',
    'roles'       => [
        'admin'        => 'Administrador',
        'rh'           => 'RH',
        'gestor'       => 'Gestor',
        'visualizador' => 'Visualizador',
        'funcionario'  => 'Funcionário',
    ],
    'admin_role'  => 'admin',
    'entry'       => 'index.php',

    'menu' => function (string $role): array {
        $url = fn (string $page, array $extra = []): string =>
            core_module_url('rh', array_merge(['page' => $page], $extra));

        // Funcionário comum: só o portal "Minha Área" e rotas essenciais.
        if ($role === 'funcionario') {
            return [[
                'heading' => 'Minha Área',
                'items'   => [
                    ['label' => 'Minha Área',   'url' => $url('my'),                             'icon' => 'bi-person-badge',    'key' => 'my'],
                    ['label' => 'Solicitações', 'url' => $url('requests', ['action' => 'create']), 'icon' => 'bi-envelope-paper',  'key' => 'requests'],
                    ['label' => 'Comunicados',  'url' => $url('announcements'),                  'icon' => 'bi-megaphone',       'key' => 'announcements'],
                    ['label' => 'Notificações', 'url' => core_url('index.php?m=auth&a=notifications'), 'icon' => 'bi-bell',      'key' => 'notifications'],
                ],
            ]];
        }

        // Matriz de visibilidade legada (Auth::can('<módulo>', 'view')).
        $can = function (string $module) use ($role): bool {
            if ($role === 'admin') {
                return true;
            }
            $viewable = [
                'rh' => ['dashboard', 'employees', 'documents', 'expirations', 'schedules',
                         'birthdays', 'recruitment', 'talent_pool', 'notifications',
                         'certificates', 'departments', 'positions', 'vacations', 'shifts',
                         'onboarding', 'announcements', 'surveys', 'trainings', 'requests'],
                'gestor' => ['dashboard', 'employees', 'documents', 'expirations', 'schedules',
                             'birthdays', 'recruitment', 'talent_pool', 'notifications',
                             'certificates', 'vacations', 'shifts', 'onboarding',
                             'announcements', 'surveys', 'trainings', 'requests'],
                'visualizador' => ['dashboard', 'employees', 'birthdays', 'notifications', 'announcements'],
            ];
            return in_array($module, $viewable[$role] ?? [], true);
        };

        $main = [
            ['label' => 'Dashboard', 'url' => $url('dashboard'), 'icon' => 'bi-speedometer2', 'key' => 'dashboard'],
        ];
        if ($can('employees'))     { $main[] = ['label' => 'Funcionários',        'url' => $url('employees'),     'icon' => 'bi-people',          'key' => 'employees']; }
        if ($can('expirations'))   { $main[] = ['label' => 'Vencimentos',         'url' => $url('expirations'),   'icon' => 'bi-clock-history',   'key' => 'expirations']; }
        if ($can('vacations'))     { $main[] = ['label' => 'Férias',              'url' => $url('vacations'),     'icon' => 'bi-sun',             'key' => 'vacations']; }
        if ($can('shifts'))        { $main[] = ['label' => 'Escalas',             'url' => $url('shifts'),        'icon' => 'bi-calendar2-week',  'key' => 'shifts']; }
        if ($can('schedules'))     { $main[] = ['label' => 'Agenda',              'url' => $url('schedules'),     'icon' => 'bi-calendar3',       'key' => 'schedules']; }
        if ($can('trainings'))     { $main[] = ['label' => 'Treinamentos',        'url' => $url('trainings'),     'icon' => 'bi-mortarboard',     'key' => 'trainings']; }
        if ($can('birthdays'))     { $main[] = ['label' => 'Aniversariantes',     'url' => $url('birthdays'),     'icon' => 'bi-gift',            'key' => 'birthdays']; }
        if ($can('recruitment'))   { $main[] = ['label' => 'Processos Seletivos', 'url' => $url('recruitment'),   'icon' => 'bi-briefcase',       'key' => 'recruitment']; }
        if ($can('talent_pool'))   { $main[] = ['label' => 'Banco de Talentos',   'url' => $url('talent_pool'),   'icon' => 'bi-star',            'key' => 'talent_pool']; }
        if ($can('announcements')) { $main[] = ['label' => 'Comunicados',         'url' => $url('announcements'), 'icon' => 'bi-megaphone',       'key' => 'announcements']; }
        if ($can('surveys'))       { $main[] = ['label' => 'Pesquisas',           'url' => $url('surveys'),       'icon' => 'bi-clipboard-data',  'key' => 'surveys']; }
        if ($can('requests'))      { $main[] = ['label' => 'Solicitações',        'url' => $url('requests'),      'icon' => 'bi-envelope-paper',  'key' => 'requests']; }

        $sections = [['heading' => 'RH', 'items' => $main]];

        if ($role === 'admin') {
            $sections[] = [
                'heading' => 'Administração',
                'items'   => [
                    ['label' => 'Departamentos',        'url' => $url('departments'), 'icon' => 'bi-building',     'key' => 'departments'],
                    ['label' => 'Cargos',               'url' => $url('positions'),   'icon' => 'bi-diagram-3',    'key' => 'positions'],
                    ['label' => 'Onboarding',           'url' => $url('onboarding'),  'icon' => 'bi-list-check',   'key' => 'onboarding'],
                    ['label' => 'Vínculos de usuários', 'url' => $url('users'),       'icon' => 'bi-person-badge', 'key' => 'users'],
                    ['label' => 'Usuários (central)',   'url' => core_url('index.php?m=admin&a=users'),            'icon' => 'bi-shield-lock',  'key' => 'users_central'],
                    ['label' => 'Auditoria',            'url' => core_url('index.php?m=admin&a=audit&module=rh'),  'icon' => 'bi-journal-text', 'key' => 'audit'],
                ],
            ];
        }

        return $sections;
    },

    // Rotas públicas (sem login): vagas públicas e política de privacidade.
    'is_public' => fn (array $get): bool =>
        in_array($get['page'] ?? '', ['public_recruitment', 'privacy'], true),

    // Rotina de cron unificada (o núcleo autentica/agenda — sem token próprio).
    'cron' => function (): void {
        require_once __DIR__ . '/app/helpers/Autoloader.php';
        Autoloader::register();
        if (!defined('RH_UPLOADS_PATH')) {
            define('RH_UPLOADS_PATH', UPLOADS_PATH . '/rh');
        }
        require __DIR__ . '/cron/check_birthdays.php';
        require __DIR__ . '/cron/check_expirations.php';
        require __DIR__ . '/cron/cleanup.php';
    },
];
