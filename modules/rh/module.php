<?php
/**
 * Manifesto do módulo RH (Recursos Humanos hospitalar).
 *
 * Controle de acesso por MICROPERMISSÕES ("<recurso>.<ação>"), resolvidas
 * pelo núcleo (Core\Perms + core_can/core_require). Os antigos níveis
 * (admin/rh/gestor/visualizador/funcionario) viram apenas PRESETS —
 * atalhos na UI de permissões e base do conversor de níveis legados.
 *
 * O menu recebe um verificador `callable $can` e filtra os itens pela
 * permissão `.view` de cada recurso. Como o manifesto também é carregado
 * fora do módulo (topbar, admin central), o closure usa core_module_url().
 */

return [
    'slug'        => 'rh',
    'name'        => 'RH',
    'icon'        => 'bi-people',
    'description' => 'Funcionários, vencimentos, férias, escalas, recrutamento e comunicados.',
    'entry'       => 'index.php',

    // ------------------------------------------------------------------
    // CATÁLOGO DE MICROPERMISSÕES — todas as funções do módulo.
    // Chave efetiva: "<recurso>.<ação>" (ex.: employees.edit).
    // ------------------------------------------------------------------
    'permissions' => [
        'dashboard' => [
            'label'   => 'Dashboard',
            'actions' => ['view' => 'Visualizar'],
        ],
        'my' => [
            'label'   => 'Minha Área (portal do funcionário)',
            'actions' => ['view' => 'Acessar'],
        ],
        'employees' => [
            'label'   => 'Funcionários',
            'actions' => [
                'view'   => 'Visualizar (inclui ficha e impressão)',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir / anonimizar (LGPD)',
                'export' => 'Exportar CSV',
            ],
        ],
        'employee_documents' => [
            'label'   => 'Documentos de funcionários',
            'actions' => [
                'view'   => 'Visualizar / baixar',
                'create' => 'Enviar',
                'delete' => 'Excluir',
            ],
        ],
        'certificates' => [
            'label'   => 'Atestados médicos',
            'actions' => [
                'view'   => 'Visualizar / baixar',
                'create' => 'Registrar',
            ],
        ],
        'expirations' => [
            'label'   => 'Vencimentos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
                'export' => 'Exportar CSV',
            ],
        ],
        'schedules' => [
            'label'   => 'Agenda',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'vacations' => [
            'label'   => 'Férias',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar / aprovar',
                'delete' => 'Excluir',
            ],
        ],
        'shifts' => [
            'label'   => 'Escalas',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'trainings' => [
            'label'   => 'Treinamentos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Registrar (catálogo e conclusões)',
                'delete' => 'Excluir',
            ],
        ],
        'birthdays' => [
            'label'   => 'Aniversariantes',
            'actions' => ['view' => 'Visualizar'],
        ],
        'recruitment' => [
            'label'   => 'Processos seletivos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar / mover candidatos',
                'delete' => 'Excluir',
            ],
        ],
        'talent_pool' => [
            'label'   => 'Banco de talentos',
            'actions' => [
                'view'   => 'Visualizar',
                'delete' => 'Excluir',
                'export' => 'Exportar CSV',
            ],
        ],
        'announcements' => [
            'label'   => 'Comunicados',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar / publicar',
                'delete' => 'Excluir',
            ],
        ],
        'surveys' => [
            'label'   => 'Pesquisas',
            'actions' => [
                'view'   => 'Visualizar / responder',
                'create' => 'Criar',
                'delete' => 'Excluir',
            ],
        ],
        'requests' => [
            'label'   => 'Solicitações',
            'actions' => [
                'view'    => 'Visualizar',
                'create'  => 'Criar (portal)',
                'respond' => 'Responder',
            ],
        ],
        'warnings' => [
            'label'   => 'Advertências',
            'actions' => [
                'create' => 'Registrar',
                'delete' => 'Excluir',
            ],
        ],
        'onboarding' => [
            'label'   => 'Onboarding / Offboarding',
            'actions' => [
                'view'             => 'Visualizar',
                'edit'             => 'Atualizar checklists',
                'manage_templates' => 'Gerenciar templates e atribuições',
            ],
        ],
        'departments' => [
            'label'   => 'Departamentos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'positions' => [
            'label'   => 'Cargos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'user_links' => [
            'label'   => 'Vínculos usuário ↔ funcionário',
            'actions' => [
                'view' => 'Visualizar',
                'edit' => 'Editar',
            ],
        ],
        'salary_history' => [
            'label'   => 'Histórico salarial',
            'actions' => [
                'create' => 'Lançar',
                'delete' => 'Excluir',
            ],
        ],
        'dependents' => [
            'label'   => 'Dependentes',
            'actions' => [
                'create' => 'Cadastrar',
                'delete' => 'Excluir',
            ],
        ],
        'scores' => [
            'label'   => 'Pontuação de funcionários',
            'actions' => [
                'create' => 'Lançar',
                'delete' => 'Excluir',
            ],
        ],
        'compliments' => [
            'label'   => 'Elogios',
            'actions' => [
                'create' => 'Registrar',
                'delete' => 'Excluir',
            ],
        ],
        'signatures' => [
            'label'   => 'Assinaturas digitais',
            'actions' => [
                'view'   => 'Verificar',
                'create' => 'Assinar',
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // PRESETS — chave = nível legado, fiéis à matriz Auth::$permissions
    // antiga (usados na UI de permissões e pelo conversor de níveis).
    // ------------------------------------------------------------------
    'presets' => [
        'admin' => [
            'label' => 'Administrador',
            'keys'  => ['*'],
        ],
        'rh' => [
            'label' => 'RH',
            'keys'  => [
                'dashboard.view',
                'employees.*',
                'employee_documents.*',
                'certificates.*',
                'expirations.*',
                'schedules.*',
                'vacations.*',
                'shifts.*',
                'trainings.*',
                'birthdays.view',
                'recruitment.*',
                'talent_pool.*',
                'announcements.*',
                'surveys.*',
                'requests.*',
                'warnings.*',
                'salary_history.*',
                'dependents.*',
                'scores.*',
                'compliments.*',
                'signatures.*',
                'onboarding.*',
                'departments.view',
                'positions.view',
            ],
        ],
        'gestor' => [
            'label' => 'Gestor',
            'keys'  => [
                'dashboard.view',
                'employees.view',
                'employee_documents.view',
                'certificates.view',
                'expirations.view',
                'schedules.view', 'schedules.create', 'schedules.edit',
                'vacations.view', 'vacations.edit',
                'shifts.view', 'shifts.create', 'shifts.edit',
                'trainings.view',
                'birthdays.view',
                'recruitment.view',
                'talent_pool.view',
                'onboarding.view', 'onboarding.edit',
                'announcements.view',
                'surveys.view',
                'requests.view',
            ],
        ],
        'visualizador' => [
            'label' => 'Visualizador',
            'keys'  => [
                'dashboard.view',
                'employees.view',
                'birthdays.view',
                'announcements.view',
            ],
        ],
        'funcionario' => [
            'label' => 'Funcionário',
            'keys'  => ['my.view', 'requests.view', 'requests.create', 'announcements.view'],
        ],
    ],

    // ------------------------------------------------------------------
    // Menu lateral — itens filtrados pela micropermissão .view.
    // ------------------------------------------------------------------
    'menu' => function (callable $can): array {
        $url = fn (string $page, array $extra = []): string =>
            core_module_url('rh', array_merge(['page' => $page], $extra));

        $sections = [];

        // Portal do funcionário.
        if ($can('my.view')) {
            $sections[] = [
                'heading' => 'Minha Área',
                'items'   => [
                    ['label' => 'Minha Área', 'url' => $url('my'), 'icon' => 'bi-person-badge', 'key' => 'my'],
                ],
            ];
        }

        $main = [];
        if ($can('dashboard.view'))     { $main[] = ['label' => 'Dashboard',           'url' => $url('dashboard'),     'icon' => 'bi-speedometer2',   'key' => 'dashboard']; }
        if ($can('employees.view'))     { $main[] = ['label' => 'Funcionários',        'url' => $url('employees'),     'icon' => 'bi-people',         'key' => 'employees']; }
        if ($can('expirations.view'))   { $main[] = ['label' => 'Vencimentos',         'url' => $url('expirations'),   'icon' => 'bi-clock-history',  'key' => 'expirations']; }
        if ($can('vacations.view'))     { $main[] = ['label' => 'Férias',              'url' => $url('vacations'),     'icon' => 'bi-sun',            'key' => 'vacations']; }
        if ($can('shifts.view'))        { $main[] = ['label' => 'Escalas',             'url' => $url('shifts'),        'icon' => 'bi-calendar2-week', 'key' => 'shifts']; }
        if ($can('schedules.view'))     { $main[] = ['label' => 'Agenda',              'url' => $url('schedules'),     'icon' => 'bi-calendar3',      'key' => 'schedules']; }
        if ($can('trainings.view'))     { $main[] = ['label' => 'Treinamentos',        'url' => $url('trainings'),     'icon' => 'bi-mortarboard',    'key' => 'trainings']; }
        if ($can('birthdays.view'))     { $main[] = ['label' => 'Aniversariantes',     'url' => $url('birthdays'),     'icon' => 'bi-gift',           'key' => 'birthdays']; }
        if ($can('recruitment.view'))   { $main[] = ['label' => 'Processos Seletivos', 'url' => $url('recruitment'),   'icon' => 'bi-briefcase',      'key' => 'recruitment']; }
        if ($can('talent_pool.view'))   { $main[] = ['label' => 'Banco de Talentos',   'url' => $url('talent_pool'),   'icon' => 'bi-star',           'key' => 'talent_pool']; }
        if ($can('announcements.view')) { $main[] = ['label' => 'Comunicados',         'url' => $url('announcements'), 'icon' => 'bi-megaphone',      'key' => 'announcements']; }
        if ($can('surveys.view'))       { $main[] = ['label' => 'Pesquisas',           'url' => $url('surveys'),       'icon' => 'bi-clipboard-data', 'key' => 'surveys']; }
        if ($can('requests.view'))      { $main[] = ['label' => 'Solicitações',        'url' => $url('requests'),      'icon' => 'bi-envelope-paper', 'key' => 'requests']; }
        if ($main) {
            $sections[] = ['heading' => 'RH', 'items' => $main];
        }

        $admin = [];
        if ($can('departments.view')) { $admin[] = ['label' => 'Departamentos', 'url' => $url('departments'), 'icon' => 'bi-building',   'key' => 'departments']; }
        if ($can('positions.view'))   { $admin[] = ['label' => 'Cargos',        'url' => $url('positions'),   'icon' => 'bi-diagram-3',  'key' => 'positions']; }
        if ($can('onboarding.view'))  { $admin[] = ['label' => 'Onboarding',    'url' => $url('onboarding'),  'icon' => 'bi-list-check', 'key' => 'onboarding']; }
        if ($can('user_links.view')) {
            $admin[] = ['label' => 'Vínculos de usuários', 'url' => $url('users'),                                   'icon' => 'bi-person-badge', 'key' => 'users'];
            $admin[] = ['label' => 'Usuários (central)',   'url' => core_url('index.php?m=admin&a=users'),           'icon' => 'bi-shield-lock',  'key' => 'users_central'];
            $admin[] = ['label' => 'Auditoria',            'url' => core_url('index.php?m=admin&a=audit&module=rh'), 'icon' => 'bi-journal-text', 'key' => 'audit'];
        }
        if ($admin) {
            $sections[] = ['heading' => 'Administração', 'items' => $admin];
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
