<?php
/**
 * Manifesto do módulo MANUTENÇÃO (ManuHosp portado para a plataforma).
 *
 * Atenção: este arquivo é carregado também FORA do módulo (topbar, admin,
 * cron), quando MODULE_URL não existe — por isso o menu usa
 * core_module_url() em vez de MODULE_URL.
 *
 * Configuração do módulo (setores e categorias de equipamentos) fica na
 * ADMINISTRAÇÃO CENTRAL (chave 'admin' → Core\AdminPanel). A antiga aba
 * "Hospital / Dados da unidade" foi descontinuada (o nome da organização
 * é o do núcleo — Administração > Configurações).
 *
 * Todo equipamento possui um CÓDIGO DE IDENTIFICAÇÃO ÚNICO de 12 dígitos
 * (man_equipment.asset_code) que gera código de barras (Code 128) e QR
 * code para localizar o equipamento e abrir sua página de histórico.
 */

return [
    'slug'        => 'manutencao',
    'name'        => 'Manutenção',
    'icon'        => 'bi-tools',
    'description' => 'Equipamentos (código único, etiquetas com código de barras/QR), ordens de serviço, preventivas, calibração e limpeza.',
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
            'actions' => [
                'view'   => 'Visualizar (inclui histórico, busca por código e etiquetas)',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
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
            'label'   => 'Setores (configuração)',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
        'categories' => [
            'label'   => 'Categorias de equipamentos (configuração)',
            'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'],
        ],
    ],

    /**
     * MODELOS (presets): atalhos na UI de permissões + base do conversor de
     * níveis legados (a chave É o nível legado).
     */
    'presets' => [
        'admin' => [
            'label' => 'Administrador',
            'keys'  => ['*'],
        ],
        // Gestor: tudo, exceto a configuração de setores.
        'manager' => [
            'label' => 'Gestor',
            'keys'  => [
                'dashboard.view', 'equipment.*', 'categories.*', 'service_orders.*', 'stock.*',
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
                'equipment.view', 'equipment.create', 'equipment.edit', 'categories.view',
                'stock.view', 'stock.create', 'stock.edit',
                'maintenance.view', 'maintenance.create', 'maintenance.edit',
                'calibration.view', 'calibration.create', 'calibration.edit',
                'technicians.view', 'technicians.create', 'technicians.edit',
                'inspections.view', 'inspections.create', 'inspections.edit',
                'dashboard.view', 'notifications.view', 'indicators.view',
                'calendar.view', 'search.view', 'export.view',
                'anvisa.view',
            ],
        ],
        // Limpeza: opera o módulo de limpeza e consulta dashboards.
        'cleaning' => [
            'label' => 'Limpeza',
            'keys'  => [
                'cleaning.*', 'dashboard.view', 'notifications.view', 'indicators.view',
                'calendar.view', 'search.view', 'export.view',
            ],
        ],
        // Visualizador: somente leitura (sem configuração).
        'viewer' => [
            'label' => 'Visualizador',
            'keys'  => [
                'dashboard.view', 'equipment.view', 'categories.view', 'service_orders.view',
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
     * (o link "Configurações do módulo" é acrescentado pelo núcleo)
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
                ['page' => 'equipment',     'perm' => 'equipment.view',     'icon' => 'bi-upc-scan', 'label' => 'Buscar por código', 'params' => ['action' => 'lookup'], 'key' => 'equipment-lookup'],
                ['page' => 'qr-locations',  'perm' => 'qr_locations.view',  'icon' => 'bi-qr-code',  'label' => 'QR Codes'],
                ['page' => 'notifications', 'perm' => 'notifications.view', 'icon' => 'bi-bell',     'label' => 'Notificações'],
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
                    'key'   => $item['key'] ?? $item['page'],
                    'url'   => core_module_url('manutencao', $params),
                ];
            }
            if ($items !== []) {
                $result[] = ['heading' => $section['heading'], 'items' => $items];
            }
        }
        return $result;
    },

    /**
     * Painel de configuração na Administração central.
     */
    'admin' => [
        'label' => 'Manutenção',
        'icon'  => 'bi-tools',
        'entry' => 'admin_panel.php',
        'tabs'  => [
            'sectors'    => ['label' => 'Setores',                    'icon' => 'bi-diagram-3', 'perm' => 'sectors.view'],
            'categories' => ['label' => 'Categorias de equipamentos', 'icon' => 'bi-tags',      'perm' => 'categories.view'],
        ],
    ],

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
