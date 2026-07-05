<?php
/**
 * Manifesto do módulo Comunicação (TeamChat portado).
 * Carregado também fora do módulo (topbar/admin) — usar core_module_url().
 */

return [
    'slug'        => 'chat',
    'name'        => 'Comunicação',
    'icon'        => 'bi-chat-dots',
    'description' => 'Chat, tarefas, reuniões, equipes, processos e enquetes.',

    // Níveis de acesso do módulo, do maior para o menor privilégio
    'roles'       => [
        'admin'   => 'Administrador',
        'manager' => 'Gestor',
        'member'  => 'Membro',
    ],
    'admin_role'  => 'admin',

    'entry'       => 'index.php',

    // Menu lateral — recebe o papel do usuário neste módulo
    'menu'        => function (string $role): array {
        $u = fn (array $params): string => core_module_url('chat', $params);

        $sections = [
            [
                'heading' => 'Comunicação',
                'items'   => [
                    ['label' => 'Chat',           'url' => $u(['page' => 'chat']),                              'icon' => 'bi-chat',           'key' => 'chat'],
                    ['label' => 'Tarefas',        'url' => $u(['page' => 'tasks']),                             'icon' => 'bi-kanban',         'key' => 'tasks'],
                    ['label' => 'Minhas tarefas', 'url' => $u(['page' => 'tasks', 'action' => 'my']),           'icon' => 'bi-person-check',   'key' => 'tasks-my'],
                    ['label' => 'Reuniões',       'url' => $u(['page' => 'meetings']),                          'icon' => 'bi-calendar-event', 'key' => 'meetings'],
                    ['label' => 'Calendário',     'url' => $u(['page' => 'meetings', 'action' => 'calendar']),  'icon' => 'bi-calendar3',      'key' => 'meetings-calendar'],
                    ['label' => 'Equipes',        'url' => $u(['page' => 'teams']),                             'icon' => 'bi-people',         'key' => 'teams'],
                    ['label' => 'Processos',      'url' => $u(['page' => 'processes']),                         'icon' => 'bi-diagram-3',      'key' => 'processes'],
                    ['label' => 'Busca',          'url' => $u(['page' => 'search']),                            'icon' => 'bi-search',         'key' => 'search'],
                ],
            ],
        ];

        if (in_array($role, ['admin', 'manager'], true)) {
            $sections[] = [
                'heading' => 'Administração',
                'items'   => [
                    ['label' => 'Painel',     'url' => $u(['page' => 'admin']),                              'icon' => 'bi-speedometer2',  'key' => 'admin'],
                    ['label' => 'Categorias', 'url' => $u(['page' => 'admin', 'action' => 'categories']),    'icon' => 'bi-collection',    'key' => 'admin-categories'],
                    ['label' => 'Emojis',     'url' => $u(['page' => 'admin', 'action' => 'emojis']),        'icon' => 'bi-emoji-smile',   'key' => 'admin-emojis'],
                    ['label' => 'Auditoria',  'url' => core_url('index.php?m=admin&a=audit&module=chat'),    'icon' => 'bi-journal-text',  'key' => 'admin-audit'],
                ],
            ];
        }

        return $sections;
    },
];
