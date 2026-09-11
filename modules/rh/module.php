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
 *
 * Configuração do módulo (departamentos, cargos, acessos dos funcionários e
 * layout A4 dos aniversariantes) fica na ADMINISTRAÇÃO CENTRAL (chave
 * 'admin' → Core\AdminPanel). A antiga aba "Vínculos de usuários" foi
 * descontinuada: o vínculo usuário ↔ funcionário é feito no cadastro do
 * funcionário (login padrão: CPF / senha inicial: data de nascimento).
 */

return [
    'slug'        => 'rh',
    'name'        => 'RH',
    'icon'        => 'bi-people',
    'description' => 'Funcionários, portal do funcionário, férias, escalas, recrutamento, comunicados, pesquisas e brindes.',
    'entry'       => 'index.php',

    // ------------------------------------------------------------------
    // CATÁLOGO DE MICROPERMISSÕES — todas as funções do módulo.
    // ------------------------------------------------------------------
    'permissions' => [
        'dashboard' => [
            'label'   => 'Dashboard',
            'actions' => ['view' => 'Visualizar'],
        ],
        'my' => [
            'label'   => 'Minha Área (portal do funcionário)',
            'actions' => ['view' => 'Acessar (férias, comunicados, pesquisas, brindes, solicitações)'],
        ],
        'employees' => [
            'label'   => 'Funcionários',
            'actions' => [
                'view'   => 'Visualizar (inclui ficha e impressão)',
                'create' => 'Criar (gera o acesso padrão ao portal)',
                'edit'   => 'Editar',
                'delete' => 'Excluir / anonimizar (LGPD)',
                'export' => 'Exportar CSV',
            ],
        ],
        'employee_access' => [
            'label'   => 'Acessos dos funcionários (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'manage' => 'Criar / redefinir acessos padrão (CPF + nascimento)',
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
            'actions' => [
                'view'      => 'Visualizar',
                'export'    => 'Exportar A4 (impressão / PDF)',
                'configure' => 'Personalizar layout do A4',
            ],
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
                'create' => 'Criar / publicar (portal e e-mail)',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'surveys' => [
            'label'   => 'Pesquisas',
            'actions' => [
                'view'   => 'Visualizar / responder',
                'create' => 'Criar / publicar (portal e e-mail)',
                'edit'   => 'Editar / encerrar',
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
        'rewards' => [
            'label'   => 'Brindes (troca de pontos)',
            'actions' => [
                'view'    => 'Visualizar catálogo e resgates',
                'create'  => 'Cadastrar brindes',
                'edit'    => 'Editar brindes',
                'delete'  => 'Excluir brindes',
                'respond' => 'Aprovar / entregar / recusar resgates',
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
            'label'   => 'Departamentos (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'positions' => [
            'label'   => 'Cargos (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
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
    // PRESETS — chave = nível legado.
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
                'employee_access.*',
                'employee_documents.*',
                'certificates.*',
                'expirations.*',
                'schedules.*',
                'vacations.*',
                'shifts.*',
                'trainings.*',
                'birthdays.*',
                'recruitment.*',
                'talent_pool.*',
                'announcements.*',
                'surveys.*',
                'requests.*',
                'rewards.*',
                'warnings.*',
                'salary_history.*',
                'dependents.*',
                'scores.*',
                'compliments.*',
                'signatures.*',
                'onboarding.*',
                'departments.*',
                'positions.*',
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
                'birthdays.view', 'birthdays.export',
                'recruitment.view',
                'talent_pool.view',
                'onboarding.view', 'onboarding.edit',
                'announcements.view',
                'surveys.view',
                'requests.view',
                'rewards.view',
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
        // Preset aplicado automaticamente ao login padrão criado no cadastro
        // do funcionário (EmployeeController) e em "Acessos dos funcionários".
        'funcionario' => [
            'label' => 'Funcionário',
            'keys'  => ['my.view', 'requests.view', 'requests.create', 'announcements.view', 'surveys.view', 'rewards.view'],
        ],
    ],

    // ------------------------------------------------------------------
    // Menu lateral — itens filtrados pela micropermissão .view.
    // (o link "Configurações do módulo" é acrescentado pelo núcleo)
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
        if ($can('rewards.view'))       { $main[] = ['label' => 'Brindes',             'url' => $url('rewards'),       'icon' => 'bi-bag-heart',      'key' => 'rewards']; }
        if ($can('onboarding.view'))    { $main[] = ['label' => 'Onboarding',          'url' => $url('onboarding'),    'icon' => 'bi-list-check',     'key' => 'onboarding']; }
        if ($main) {
            $sections[] = ['heading' => 'RH', 'items' => $main];
        }

        return $sections;
    },

    // ------------------------------------------------------------------
    // Painel de configuração na Administração central.
    // ------------------------------------------------------------------
    'admin' => [
        'label' => 'RH',
        'icon'  => 'bi-people',
        'entry' => 'admin_panel.php',
        'tabs'  => [
            'departments' => ['label' => 'Departamentos',           'icon' => 'bi-building',     'perm' => 'departments.view'],
            'positions'   => ['label' => 'Cargos',                  'icon' => 'bi-diagram-3',    'perm' => 'positions.view'],
            'access'      => ['label' => 'Acessos dos funcionários','icon' => 'bi-person-lock',  'perm' => 'employee_access.view'],
            'birthdays'   => ['label' => 'Aniversariantes (A4)',    'icon' => 'bi-gift',         'perm' => 'birthdays.configure'],
        ],
    ],

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
