<?php
/**
 * Manifesto do módulo MANUTENÇÃO (ManuHosp portado para a plataforma).
 *
 * Atenção: este arquivo é carregado também FORA do módulo (topbar, admin,
 * cron), quando MODULE_URL não existe — por isso o menu usa
 * core_module_url() em vez de MODULE_URL.
 */

return [
    'slug'        => 'manutencao',
    'name'        => 'Manutenção',
    'icon'        => 'bi-tools',
    'description' => 'Equipamentos, ordens de serviço, preventivas, calibração e limpeza.',
    'entry'       => 'index.php',

    /**
     * CATÁLOGO DE MICROPERMISSÕES — cada função do módulo, agrupada por
     * recurso. A chave efetiva é "<recurso>.<ação>" (ex.: service_orders.create).
     */
    'permissions' => [
        'dashboard' => [
            'label'   => 'Dashboard',
            'actions' => ['view' => 'Visualizar'],
        ],
        'equipment' => [
            'label'   => 'Equipamentos',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'service_orders' => [
            'label'   => 'Ordens de Serviço',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'stock' => [
            'label'   => 'Estoque',
            // Movimentações de estoque (entrada/saída/ajuste) = create
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar/Movimentar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'maintenance' => [
            'label'   => 'Manutenção Preventiva',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'calibration' => [
            'label'   => 'Calibração',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'cleaning' => [
            'label'   => 'Limpeza',
            // Execução de limpeza (registro de checklist) = create
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar/Executar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'inspections' => [
            'label'   => 'Inspeções',
            // Execução de rota de inspeção = create
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar/Executar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'technicians' => [
            'label'   => 'Técnicos',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'calendar' => [
            'label'   => 'Calendário',
            'actions' => ['view' => 'Visualizar'],
        ],
        'indicators' => [
            'label'   => 'Indicadores',
            'actions' => ['view' => 'Visualizar'],
        ],
        'heatmap' => [
            'label'   => 'Mapa de Calor',
            'actions' => ['view' => 'Visualizar'],
        ],
        'anvisa' => [
            'label'   => 'Relatório ANVISA',
            'actions' => ['view' => 'Visualizar'],
        ],
        'qr_locations' => [
            'label'   => 'QR Codes (locais)',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'notifications' => [
            'label'   => 'Notificações',
            'actions' => ['view' => 'Visualizar'],
        ],
        'search' => [
            'label'   => 'Busca Global',
            'actions' => ['view' => 'Visualizar'],
        ],
        'export' => [
            'label'   => 'Exportação (CSV/Impressão)',
            'actions' => ['view' => 'Visualizar'],
        ],
        'sectors' => [
            'label'   => 'Setores (Administração)',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'org_settings' => [
            'label'   => 'Dados da Unidade',
            'actions' => ['edit' => 'Editar'],
        ],
    ],

    /**
     * MODELOS (presets): atalhos na UI de permissões + base do conversor de
     * níveis legados (a chave É o nível legado). Reproduzem o acesso do
     * canAccessModule()/canWrite() antigos, com curingas '*' e 'recurso.*'.
     */
    'presets' => [
        'admin' => [
            'label' => 'Administrador',
            'keys'  => ['*'],
        ],
        // Gestor: tudo, exceto administração (setores e dados da unidade).
        'manager' => [
            'label' => 'Gestor',
            'keys'  => [
                'dashboard.view', 'equipment.*', 'service_orders.*', 'stock.*',
                'maintenance.*', 'calibration.*', 'cleaning.*', 'inspections.*',
                'technicians.*', 'calendar.view', 'indicators.view', 'heatmap.view',
                'anvisa.view', 'qr_locations.*', 'notifications.view',
                'search.view', 'export.view',
            ],
        ],
        // Manutenção: opera OS/equipamentos/estoque/preventivas/calibração/
        // técnicos/inspeções (ver+criar+editar) e consulta o restante.
        'maintenance' => [
            'label' => 'Manutenção',
            'keys'  => [
                'service_orders.view', 'service_orders.create', 'service_orders.edit',
                'equipment.view', 'equipment.create', 'equipment.edit',
                'stock.view', 'stock.create', 'stock.edit',
                'maintenance.view', 'maintenance.create', 'maintenance.edit',
                'calibration.view', 'calibration.create', 'calibration.edit',
                'technicians.view', 'technicians.create', 'technicians.edit',
                'inspections.view', 'inspections.create', 'inspections.edit',
                'dashboard.view', 'notifications.view', 'indicators.view',
                'calendar.view', 'search.view', 'export.view',
                // legado: o nível "maintenance" acessava o relatório ANVISA
                'anvisa.view',
            ],
        ],
        // Limpeza: opera o módulo de limpeza e consulta dashboards.
        'cleaning' => [
            'label' => 'Limpeza',
            'keys'  => [
                'cleaning.*', 'dashboard.view', 'notifications.view', 'indicators.view',
                // legado: o nível "cleaning" também acessava calendário/busca/export
                'calendar.view', 'search.view', 'export.view',
            ],
        ],
        // Visualizador: somente leitura (sem administração).
        'viewer' => [
            'label' => 'Visualizador',
            'keys'  => [
                'dashboard.view', 'equipment.view', 'service_orders.view',
                'stock.view', 'maintenance.view', 'calibration.view',
                'cleaning.view', 'inspections.view', 'technicians.view',
                'calendar.view', 'indicators.view', 'heatmap.view', 'anvisa.view',
                'qr_locations.view', 'notifications.view', 'search.view', 'export.view',
            ],
        ],
    ],

    /**
     * Menu lateral — recebe o verificador de micropermissões do usuário
     * logado e filtra cada item pela permissão .view correspondente.
     * O item "Administração" aparece para quem vê setores OU edita os
     * dados da unidade.
     */
    'menu' => function (callable $can): array {
        $sections = [
            ['heading' => 'Principal', 'items' => [
                ['page' => 'dashboard',      'perm' => 'dashboard.view',      'icon' => 'bi-speedometer2',    'label' => 'Dashboard'],
                ['page' => 'equipment',      'perm' => 'equipment.view',      'icon' => 'bi-hdd-rack',        'label' => 'Equipamentos'],
                ['page' => 'service-orders', 'perm' => 'service_orders.view', 'icon' => 'bi-clipboard-check', 'label' => 'Ordens de Serviço'],
                ['page' => 'calendar',       'perm' => 'calendar.view',       'icon' => 'bi-calendar3',       'label' => 'Calendário'],
            ]],
            ['heading' => 'Gestão', 'items' => [
                ['page' => 'stock',       'perm' => 'stock.view',       'icon' => 'bi-box-seam',         'label' => 'Estoque'],
                ['page' => 'maintenance', 'perm' => 'maintenance.view', 'icon' => 'bi-tools',            'label' => 'Manutenção'],
                ['page' => 'calibration', 'perm' => 'calibration.view', 'icon' => 'bi-rulers',           'label' => 'Calibração'],
                ['page' => 'cleaning',    'perm' => 'cleaning.view',    'icon' => 'bi-droplet-half',     'label' => 'Limpeza'],
                ['page' => 'inspections', 'perm' => 'inspections.view', 'icon' => 'bi-clipboard2-pulse', 'label' => 'Inspeções'],
                ['page' => 'technicians', 'perm' => 'technicians.view', 'icon' => 'bi-person-badge',     'label' => 'Técnicos'],
            ]],
            ['heading' => 'Relatórios', 'items' => [
                ['page' => 'indicators',    'perm' => 'indicators.view', 'icon' => 'bi-graph-up',     'label' => 'Indicadores'],
                ['page' => 'heatmap',       'perm' => 'heatmap.view',    'icon' => 'bi-fire',         'label' => 'Mapa de Calor'],
                ['page' => 'anvisa-report', 'perm' => 'anvisa.view',     'icon' => 'bi-shield-check', 'label' => 'ANVISA'],
            ]],
            ['heading' => 'Sistema', 'items' => [
                ['page' => 'qr-locations',  'perm' => 'qr_locations.view',  'icon' => 'bi-qr-code', 'label' => 'QR Codes'],
                ['page' => 'notifications', 'perm' => 'notifications.view', 'icon' => 'bi-bell',    'label' => 'Notificações'],
                // Administração do módulo: setores e dados da unidade (o
                // CRUD de usuários migrou para a administração central).
                ['page' => 'admin', 'perms_any' => ['sectors.view', 'org_settings.edit'],
                 'icon' => 'bi-gear', 'label' => 'Administração', 'params' => ['tab' => 'sectors']],
            ]],
        ];

        $result = [];
        foreach ($sections as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if (isset($item['perm'])) {
                    $allowed = $can($item['perm']);
                } else {
                    $allowed = false;
                    foreach ($item['perms_any'] ?? [] as $perm) {
                        if ($can($perm)) {
                            $allowed = true;
                            break;
                        }
                    }
                }
                if (!$allowed) {
                    continue;
                }
                $params = array_merge(['page' => $item['page']], $item['params'] ?? []);
                $items[] = [
                    'label' => $item['label'],
                    'icon'  => $item['icon'],
                    'key'   => $item['page'],
                    'url'   => core_module_url('manutencao', $params),
                ];
            }
            if ($items !== []) {
                $result[] = ['heading' => $section['heading'], 'items' => $items];
            }
        }
        return $result;
    },

    // Rotas públicas (sem login): OS anônima, QR scan e rastreamento
    'is_public' => fn (array $get): bool => in_array(
        $get['page'] ?? '',
        ['anonymous-os', 'qr-scan', 'track-os'],
        true
    ),

    // Rotina periódica (preventivas, alertas de calibração/estoque, limpeza)
    'cron' => function (): void {
        require __DIR__ . '/cron/run.php';
    },
];
