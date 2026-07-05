<?php
/**
 * Manifesto do módulo DOCUMENTOS (gestão documental / qualidade).
 *
 * Carregado pelo núcleo em TODAS as requisições (topbar, admin, cron),
 * inclusive fora do contexto do módulo — por isso o menu usa
 * core_module_url() e este arquivo não tem efeitos colaterais.
 */

return [
    'slug'        => 'documentos',
    'name'        => 'Documentos',
    'icon'        => 'bi-file-earmark-text',
    'description' => 'Gestão documental, indicadores de qualidade e planos de ação (PDCA).',

    // Níveis de acesso, do maior para o menor privilégio
    'roles'       => [
        'admin'    => 'Administrador',
        'gestor'   => 'Gestor',
        'operador' => 'Operador',
    ],
    'admin_role'  => 'admin',

    'entry'       => 'index.php',

    // Menu lateral (portado da sidebar do layout legado)
    'menu'        => function (string $role): array {
        $u = fn (string $path) => core_module_url('documentos', ['url' => $path]);

        $sections = [
            [
                'heading' => 'Principal',
                'items'   => [
                    ['label' => 'Dashboard',             'url' => $u('dashboard'),            'icon' => 'bi-speedometer2',   'key' => 'dashboard'],
                    ['label' => 'Documentos',            'url' => $u('documents'),            'icon' => 'bi-folder2-open',   'key' => 'documents'],
                    ['label' => 'Indicadores',           'url' => $u('indicators'),           'icon' => 'bi-graph-up',       'key' => 'indicators'],
                    ['label' => 'Painel de Indicadores', 'url' => $u('indicators/dashboard'), 'icon' => 'bi-speedometer',    'key' => 'indicators-dashboard'],
                    ['label' => 'Planos de Ação',        'url' => $u('indicators/actions'),   'icon' => 'bi-list-check',     'key' => 'indicators-actions'],
                    ['label' => 'Conformidade',          'url' => $u('reports'),              'icon' => 'bi-clipboard-data', 'key' => 'reports'],
                ],
            ],
        ];

        if (in_array($role, ['admin', 'gestor'], true)) {
            $adminItems = [
                ['label' => 'Setores',            'url' => $u('admin/sectors'),    'icon' => 'bi-diagram-3', 'key' => 'admin-sectors'],
                ['label' => 'Usuários & Setores', 'url' => $u('admin/users'),      'icon' => 'bi-people',    'key' => 'admin-users'],
                ['label' => 'Categorias',         'url' => $u('admin/categories'), 'icon' => 'bi-tags',      'key' => 'admin-categories'],
            ];
            if ($role === 'admin') {
                $adminItems[] = [
                    'label' => 'Usuários (central)',
                    'url'   => core_url('index.php?m=admin&a=users'),
                    'icon'  => 'bi-people-fill',
                    'key'   => 'admin-central-users',
                ];
            }
            $sections[] = ['heading' => 'Administração', 'items' => $adminItems];
        }

        return $sections;
    },

    // Rotina periódica: documentos vencendo/vencidos → notificações + e-mails
    'cron'        => function (): void {
        require __DIR__ . '/cron/check_expiring.php';
    },
];
