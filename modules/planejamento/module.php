<?php
/**
 * MÓDULO PLANEJAMENTO — planejamento e gestão de processos.
 *
 *  • Planos de trabalho e planejamento organizacional (objetivos → metas →
 *    ações, no formato 5W2H, com responsáveis, prazos e progresso);
 *  • Quadros de gestão visual (Kanban, Scrum, personalizados) com colunas,
 *    cartões, responsáveis, prioridades e pontos;
 *  • Diagramas, fluxogramas e mapas de processo (editor visual próprio,
 *    sem dependências externas, com versionamento e exportação SVG/PNG);
 *  • Modelos predefinidos (Scrum, Kanban, PDCA, 5W2H, SWOT, fluxograma
 *    básico, mapa de processo SIPOC...) editáveis pelo hospital.
 *
 * Carregado também fora do módulo (topbar/admin) — usar core_module_url().
 */

return [
    'slug'        => 'planejamento',
    'name'        => 'Planejamento',
    'icon'        => 'bi-kanban',
    'description' => 'Planos de trabalho, quadros (Kanban/Scrum), fluxogramas, mapas de processo, diagramas e modelos.',
    'entry'       => 'index.php',

    'permissions' => [
        'dashboard' => [
            'label'   => 'Painel',
            'actions' => ['view' => 'Visualizar'],
        ],
        'plans' => [
            'label'   => 'Planos de trabalho e planejamento organizacional',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar (inclui itens, progresso e status)',
                'delete' => 'Excluir / arquivar',
                'export' => 'Exportar / imprimir',
            ],
        ],
        'boards' => [
            'label'   => 'Quadros (Kanban / Scrum)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar quadros',
                'edit'   => 'Configurar quadros (colunas, membros)',
                'delete' => 'Excluir / arquivar quadros',
                'cards'  => 'Criar, editar e mover cartões',
            ],
        ],
        'diagrams' => [
            'label'   => 'Diagramas, fluxogramas e mapas de processo',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar (gera versões)',
                'delete' => 'Excluir',
                'export' => 'Exportar SVG / PNG / imprimir',
            ],
        ],
        'templates' => [
            'label'   => 'Modelos predefinidos',
            'actions' => [
                'view'   => 'Visualizar e usar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
    ],

    'presets' => [
        'admin'       => ['label' => 'Administrador', 'keys' => ['*']],
        'gestor'      => ['label' => 'Gestor', 'keys' => [
            'dashboard.view', 'plans.*', 'boards.*', 'diagrams.*', 'templates.*',
        ]],
        'colaborador' => ['label' => 'Colaborador', 'keys' => [
            'dashboard.view',
            'plans.view', 'plans.edit', 'plans.export',
            'boards.view', 'boards.create', 'boards.cards',
            'diagrams.view', 'diagrams.create', 'diagrams.edit', 'diagrams.export',
            'templates.view',
        ]],
        'leitor'      => ['label' => 'Leitor', 'keys' => [
            'dashboard.view', 'plans.view', 'plans.export', 'boards.view', 'diagrams.view', 'diagrams.export', 'templates.view',
        ]],
    ],

    'menu' => function (callable $can): array {
        $u = fn (string $page, array $extra = []): string =>
            core_module_url('planejamento', array_merge(['page' => $page], $extra));

        $items = [];
        if ($can('dashboard.view')) { $items[] = ['label' => 'Painel',                  'url' => $u('dashboard'), 'icon' => 'bi-speedometer2',      'key' => 'dashboard']; }
        if ($can('plans.view'))     { $items[] = ['label' => 'Planos de trabalho',      'url' => $u('plans'),     'icon' => 'bi-clipboard2-check',  'key' => 'plans']; }
        if ($can('boards.view'))    { $items[] = ['label' => 'Quadros',                 'url' => $u('boards'),    'icon' => 'bi-kanban',            'key' => 'boards']; }
        if ($can('diagrams.view'))  { $items[] = ['label' => 'Fluxogramas e diagramas', 'url' => $u('diagrams'),  'icon' => 'bi-diagram-3',         'key' => 'diagrams']; }
        if ($can('templates.view')) { $items[] = ['label' => 'Modelos',                 'url' => $u('templates'), 'icon' => 'bi-grid-1x2',          'key' => 'templates']; }

        return $items ? [['heading' => 'Planejamento', 'items' => $items]] : [];
    },
];
