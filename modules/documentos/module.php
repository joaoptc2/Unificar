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
 *
 * Configuração do módulo (setores e categorias) fica na ADMINISTRAÇÃO
 * CENTRAL (chave 'admin' → Core\AdminPanel): index.php?m=admin&a=module
 * &slug=documentos&tab=<aba>. As antigas abas "Usuários & Setores" e
 * "Unidades" foram descontinuadas.
 */

return [
    'slug'        => 'documentos',
    'name'        => 'Documentos',
    'icon'        => 'bi-file-earmark-text',
    'description' => 'Gestão documental (controlados e não controlados), editor com layouts do hospital, indicadores de qualidade e planos de ação (PDCA).',

    'entry'       => 'index.php',

    // ── Catálogo de micropermissões (recurso => ações) ──────────────────────
    'permissions' => [
        'dashboard'    => [
            'label'   => 'Dashboard (inclui conformidade)',
            'actions' => ['view' => 'Visualizar'],
        ],
        'documents'    => [
            'label'   => 'Documentos (controlados e não controlados)',
            'actions' => [
                'view'        => 'Visualizar',
                'create'      => 'Criar (upload ou editor)',
                'edit'        => 'Editar',
                'delete'      => 'Excluir',
                'approve'     => 'Aprovar/Reprovar',
                'acknowledge' => 'Dar ciência',
                'export'      => 'Exportar / imprimir PDF',
            ],
        ],
        'indicators'   => [
            'label'   => 'Indicadores (painel unificado)',
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
        'sectors'      => [
            'label'   => 'Setores (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'categories'   => [
            'label'   => 'Categorias de documentos (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        // Layouts de documentos (papel timbrado, capa, fundo, fontes) —
        // gerenciados em Administração > Padronização > Layouts de documentos.
        'layouts'      => [
            'label'   => 'Layouts de documentos (padronização)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
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
                'sectors.*', 'categories.*', 'layouts.view',
            ],
        ],
        'operador' => [
            'label' => 'Operador',
            'keys'  => [
                'dashboard.view',
                'documents.view', 'documents.create', 'documents.acknowledge', 'documents.export',
                'indicators.view', 'indicators.record',
                'actions.view',
            ],
        ],
    ],

    // ── Menu lateral — filtrado por micropermissões ──────────────────────────
    // (o link "Configurações do módulo" para a administração central é
    //  acrescentado automaticamente pelo núcleo — Core\Layout)
    'menu'        => function (callable $can): array {
        $u = fn (string $path) => core_module_url('documentos', ['url' => $path]);

        $main = [];
        if ($can('dashboard.view')) {
            $main[] = ['label' => 'Dashboard',                 'url' => $u('dashboard'),              'icon' => 'bi-speedometer2',      'key' => 'dashboard'];
        }
        if ($can('documents.view')) {
            $main[] = ['label' => 'Documentos controlados',    'url' => $u('documents'),              'icon' => 'bi-folder2-open',      'key' => 'documents'];
            $main[] = ['label' => 'Documentos não controlados','url' => $u('documents/uncontrolled'), 'icon' => 'bi-archive',           'key' => 'documents-uncontrolled'];
        }
        if ($can('indicators.view')) {
            $main[] = ['label' => 'Indicadores',               'url' => $u('indicators'),             'icon' => 'bi-graph-up',          'key' => 'indicators'];
        }
        if ($can('actions.view')) {
            $main[] = ['label' => 'Planos de Ação',            'url' => $u('indicators/actions'),     'icon' => 'bi-list-check',        'key' => 'indicators-actions'];
        }

        $sections = [];
        if ($main) {
            $sections[] = ['heading' => 'Principal', 'items' => $main];
        }
        if ($can('layouts.view')) {
            $sections[] = ['heading' => 'Padronização', 'items' => [
                ['label' => 'Layouts de documentos', 'url' => core_module_url('admin', ['a' => 'layouts']), 'icon' => 'bi-layout-text-window-reverse', 'key' => 'layouts'],
            ]];
        }
        return $sections;
    },

    // ── Painel de configuração na Administração central ─────────────────────
    'admin'       => [
        'label' => 'Documentos',
        'icon'  => 'bi-file-earmark-text',
        'entry' => 'admin_panel.php',
        'tabs'  => [
            'sectors'    => ['label' => 'Setores',    'icon' => 'bi-diagram-3', 'perm' => 'sectors.view'],
            'categories' => ['label' => 'Categorias', 'icon' => 'bi-tags',      'perm' => 'categories.view'],
        ],
    ],

    // Rotina periódica: documentos vencendo/vencidos → notificações + e-mails
    'cron'        => function (): void {
        require __DIR__ . '/cron/check_expiring.php';
    },
];
