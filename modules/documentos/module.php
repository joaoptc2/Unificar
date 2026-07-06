<?php
/**
 * Manifesto do módulo DOCUMENTOS (gestão documental / qualidade).
 *
 * Carregado pelo núcleo em TODAS as requisições (topbar, admin, cron),
 * inclusive fora do contexto do módulo — por isso o menu usa
 * core_module_url() e este arquivo não tem efeitos colaterais.
 *
 * Controle de acesso: MICROPERMISSÕES por função (catálogo 'permissions',
 * chave efetiva "<recurso>.<ação>"). Os antigos níveis admin/gestor/operador
 * viraram 'presets' (modelos na UI de permissões + base do conversor
 * scripts/migrate_role_grants.php).
 */

return [
    'slug'        => 'documentos',
    'name'        => 'Documentos',
    'icon'        => 'bi-file-earmark-text',
    'description' => 'Gestão documental, indicadores de qualidade e planos de ação (PDCA).',

    'entry'       => 'index.php',

    // ── Catálogo de micropermissões (recurso => ações) ──────────────────────
    'permissions' => [
        'dashboard'    => [
            'label'   => 'Dashboard',
            'actions' => ['view' => 'Visualizar'],
        ],
        'documents'    => [
            'label'   => 'Documentos',
            'actions' => [
                'view'        => 'Visualizar',
                'create'      => 'Criar',
                'edit'        => 'Editar',
                'delete'      => 'Excluir',
                'approve'     => 'Aprovar/Reprovar',
                'acknowledge' => 'Dar ciência',
                'export'      => 'Exportar',
            ],
        ],
        'indicators'   => [
            'label'   => 'Indicadores',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
                'record' => 'Lançar dados',
                'import' => 'Importar',
                'export' => 'Exportar',
            ],
        ],
        'actions'      => [
            'label'   => 'Planos de ação',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'reports'      => [
            'label'   => 'Conformidade',
            'actions' => ['view' => 'Visualizar'],
        ],
        'sectors'      => [
            'label'   => 'Setores',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'user_sectors' => [
            'label'   => 'Usuários e Setores',
            'actions' => [
                'view' => 'Visualizar',
                'edit' => 'Associar/Desassociar setores',
            ],
        ],
        'categories'   => [
            'label'   => 'Categorias de documentos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'hospitals'    => [
            'label'   => 'Unidades',
            'actions' => [
                'view' => 'Visualizar',
                'edit' => 'Gerenciar',
            ],
        ],
    ],

    // ── Modelos (presets) — chave = nível legado correspondente ─────────────
    'presets'     => [
        'admin'    => [
            'label' => 'Administrador',
            'keys'  => ['*'],
        ],
        'gestor'   => [
            'label' => 'Gestor',
            'keys'  => [
                'dashboard.*', 'documents.*', 'indicators.*', 'actions.*',
                'reports.*', 'sectors.*', 'user_sectors.*', 'categories.*',
            ],
        ],
        'operador' => [
            'label' => 'Operador',
            'keys'  => [
                'dashboard.view',
                'documents.view', 'documents.create', 'documents.acknowledge',
                'indicators.view', 'indicators.record',
                'actions.view',
                'reports.view',
            ],
        ],
    ],

    // ── Menu lateral — filtrado por micropermissões ──────────────────────────
    'menu'        => function (callable $can): array {
        $u = fn (string $path) => core_module_url('documentos', ['url' => $path]);

        $main = [];
        if ($can('dashboard.view')) {
            $main[] = ['label' => 'Dashboard',             'url' => $u('dashboard'),            'icon' => 'bi-speedometer2',   'key' => 'dashboard'];
        }
        if ($can('documents.view')) {
            $main[] = ['label' => 'Documentos',            'url' => $u('documents'),            'icon' => 'bi-folder2-open',   'key' => 'documents'];
        }
        if ($can('indicators.view')) {
            $main[] = ['label' => 'Indicadores',           'url' => $u('indicators'),           'icon' => 'bi-graph-up',       'key' => 'indicators'];
            $main[] = ['label' => 'Painel de Indicadores', 'url' => $u('indicators/dashboard'), 'icon' => 'bi-speedometer',    'key' => 'indicators-dashboard'];
        }
        if ($can('actions.view')) {
            $main[] = ['label' => 'Planos de Ação',        'url' => $u('indicators/actions'),   'icon' => 'bi-list-check',     'key' => 'indicators-actions'];
        }
        if ($can('reports.view')) {
            $main[] = ['label' => 'Conformidade',          'url' => $u('reports'),              'icon' => 'bi-clipboard-data', 'key' => 'reports'];
        }

        $sections = [];
        if ($main) {
            $sections[] = ['heading' => 'Principal', 'items' => $main];
        }

        $adminItems = [];
        if ($can('sectors.view')) {
            $adminItems[] = ['label' => 'Setores',            'url' => $u('admin/sectors'),    'icon' => 'bi-diagram-3', 'key' => 'admin-sectors'];
        }
        if ($can('user_sectors.view')) {
            $adminItems[] = ['label' => 'Usuários & Setores', 'url' => $u('admin/users'),      'icon' => 'bi-people',    'key' => 'admin-users'];
        }
        if ($can('categories.view')) {
            $adminItems[] = ['label' => 'Categorias',         'url' => $u('admin/categories'), 'icon' => 'bi-tags',      'key' => 'admin-categories'];
        }
        if ($can('hospitals.view')) {
            $adminItems[] = ['label' => 'Unidades',           'url' => $u('admin/hospitals'),  'icon' => 'bi-hospital',  'key' => 'admin-hospitals'];
        }
        // Administração central de usuários: só para admin GLOBAL da
        // plataforma (não é função do módulo — flag do núcleo).
        if (!empty(core_user()['is_admin'])) {
            $adminItems[] = [
                'label' => 'Usuários (central)',
                'url'   => core_url('index.php?m=admin&a=users'),
                'icon'  => 'bi-people-fill',
                'key'   => 'admin-central-users',
            ];
        }
        if ($adminItems) {
            $sections[] = ['heading' => 'Administração', 'items' => $adminItems];
        }

        return $sections;
    },

    // Rotina periódica: documentos vencendo/vencidos → notificações + e-mails
    'cron'        => function (): void {
        require __DIR__ . '/cron/check_expiring.php';
    },
];
