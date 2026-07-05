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

    // Níveis de acesso, do maior para o menor privilégio
    'roles'       => [
        'admin'       => 'Administrador',
        'manager'     => 'Gestor',
        'maintenance' => 'Manutenção',
        'cleaning'    => 'Limpeza',
        'viewer'      => 'Visualizador',
    ],
    'admin_role'  => 'admin',
    'entry'       => 'index.php',

    /**
     * Menu lateral — mesma lógica de visibilidade do canAccessModule()
     * legado (config.php v4), filtrando cada item pelo papel recebido.
     */
    'menu' => function (string $role): array {
        // page => papéis autorizados (idêntico ao mapa legado)
        $access = [
            'dashboard'      => ['admin', 'manager', 'maintenance', 'cleaning', 'viewer'],
            'equipment'      => ['admin', 'manager', 'maintenance'],
            'service-orders' => ['admin', 'manager', 'maintenance'],
            'calendar'       => ['admin', 'manager', 'maintenance', 'cleaning'],
            'stock'          => ['admin', 'manager', 'maintenance'],
            'maintenance'    => ['admin', 'manager', 'maintenance'],
            'calibration'    => ['admin', 'manager', 'maintenance'],
            'cleaning'       => ['admin', 'manager', 'cleaning'],
            'inspections'    => ['admin', 'manager', 'maintenance'],
            'technicians'    => ['admin', 'manager', 'maintenance'],
            'indicators'     => ['admin', 'manager', 'maintenance', 'cleaning'],
            'heatmap'        => ['admin', 'manager'],
            'anvisa-report'  => ['admin', 'manager', 'maintenance'],
            'qr-locations'   => ['admin', 'manager'],
            'notifications'  => ['admin', 'manager', 'maintenance', 'cleaning', 'viewer'],
            'admin'          => ['admin'],
        ];

        $sections = [
            ['heading' => 'Principal', 'items' => [
                ['page' => 'dashboard',      'icon' => 'bi-speedometer2',    'label' => 'Dashboard'],
                ['page' => 'equipment',      'icon' => 'bi-hdd-rack',        'label' => 'Equipamentos'],
                ['page' => 'service-orders', 'icon' => 'bi-clipboard-check', 'label' => 'Ordens de Serviço'],
                ['page' => 'calendar',       'icon' => 'bi-calendar3',       'label' => 'Calendário'],
            ]],
            ['heading' => 'Gestão', 'items' => [
                ['page' => 'stock',       'icon' => 'bi-box-seam',         'label' => 'Estoque'],
                ['page' => 'maintenance', 'icon' => 'bi-tools',            'label' => 'Manutenção'],
                ['page' => 'calibration', 'icon' => 'bi-rulers',           'label' => 'Calibração'],
                ['page' => 'cleaning',    'icon' => 'bi-droplet-half',     'label' => 'Limpeza'],
                ['page' => 'inspections', 'icon' => 'bi-clipboard2-pulse', 'label' => 'Inspeções'],
                ['page' => 'technicians', 'icon' => 'bi-person-badge',     'label' => 'Técnicos'],
            ]],
            ['heading' => 'Relatórios', 'items' => [
                ['page' => 'indicators',    'icon' => 'bi-graph-up',     'label' => 'Indicadores'],
                ['page' => 'heatmap',       'icon' => 'bi-fire',         'label' => 'Mapa de Calor'],
                ['page' => 'anvisa-report', 'icon' => 'bi-shield-check', 'label' => 'ANVISA'],
            ]],
            ['heading' => 'Sistema', 'items' => [
                ['page' => 'qr-locations',  'icon' => 'bi-qr-code', 'label' => 'QR Codes'],
                ['page' => 'notifications', 'icon' => 'bi-bell',    'label' => 'Notificações'],
                // Somente admin: gestão de setores do módulo (o CRUD de
                // usuários migrou para a administração central).
                ['page' => 'admin', 'icon' => 'bi-gear', 'label' => 'Administração',
                 'params' => ['tab' => 'sectors']],
            ]],
        ];

        $result = [];
        foreach ($sections as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $allowed = $access[$item['page']] ?? ['admin'];
                if (!in_array($role, $allowed, true)) {
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
