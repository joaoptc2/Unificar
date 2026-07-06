<?php
/**
 * Manifesto do módulo Comunicação (TeamChat portado).
 * Carregado também fora do módulo (topbar/admin) — usar core_module_url().
 *
 * Modelo de acesso: MICROPERMISSÕES por função ("<recurso>.<ação>"),
 * resolvidas pelo núcleo (Core\Perms / core_can()). Os antigos níveis
 * (admin/manager/member) viraram apenas 'presets' — atalhos de concessão
 * derivados da matriz legada de Auth::$permissions.
 */

return [
    'slug'        => 'chat',
    'name'        => 'Comunicação',
    'icon'        => 'bi-chat-dots',
    'description' => 'Chat, tarefas, reuniões, equipes, processos e enquetes.',

    'entry'       => 'index.php',

    // ------------------------------------------------------------------
    // Catálogo de micropermissões — TODAS as funções do módulo.
    // Chave efetiva: "<recurso>.<ação>" (ex.: chat.moderate).
    // ------------------------------------------------------------------
    'permissions' => [
        'chat' => [
            'label'   => 'Chat (mensagens)',
            'actions' => [
                'view'     => 'Visualizar',
                'create'   => 'Enviar mensagens',
                'edit'     => 'Editar próprias mensagens',
                'delete'   => 'Excluir próprias mensagens',
                'moderate' => 'Moderar: fixar/excluir de terceiros',
            ],
        ],
        'channels' => [
            'label'   => 'Canais',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar (configurações/membros)',
                'delete' => 'Arquivar',
            ],
        ],
        'tasks' => [
            'label'   => 'Tarefas',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'meetings' => [
            'label'   => 'Reuniões',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir (cancelar)',
            ],
        ],
        'calendar' => [
            'label'   => 'Calendário',
            'actions' => [
                'view' => 'Visualizar',
            ],
        ],
        'teams' => [
            'label'   => 'Equipes',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'processes' => [
            'label'   => 'Processos',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'polls' => [
            'label'   => 'Enquetes',
            'actions' => [
                'view'   => 'Visualizar e votar',
                'create' => 'Criar',
                'edit'   => 'Editar (encerrar)',
                'delete' => 'Excluir',
            ],
        ],
        'search' => [
            'label'   => 'Busca',
            'actions' => [
                'view' => 'Visualizar',
            ],
        ],
        'categories' => [
            'label'   => 'Categorias de canais',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'emojis' => [
            'label'   => 'Emojis personalizados',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'delete' => 'Excluir',
            ],
        ],
        'admin' => [
            'label'   => 'Painel administrativo',
            'actions' => [
                'view'     => 'Visualizar (dashboard/estatísticas)',
                'settings' => 'Configurações',
                'export'   => 'Exportar dados',
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // Presets — chave = nível legado, derivados fielmente da antiga
    // matriz Auth::$permissions:
    //  - manager tinha CRUD completo de chat/canais/tarefas/equipes/
    //    reuniões/processos + acesso ao painel administrativo inteiro
    //    (o gate do AdminController era isAdmin||isManager), MAS não
    //    moderava o chat (fixar/excluir de terceiros era admin-only) nem
    //    encerrava enquetes de terceiros (admin-only).
    //  - member tinha o básico: chat completo sobre as PRÓPRIAS mensagens
    //    (a exclusão na janela de 1 min segue imposta no código),
    //    canais view/create, tarefas view/create/edit, reuniões
    //    view/create/edit, equipes/processos apenas view, busca e
    //    enquetes (criar/votar eram liberados a qualquer logado).
    //  - polls.delete não tem endpoint (reservada para uso futuro).
    // ------------------------------------------------------------------
    'presets' => [
        'admin' => [
            'label' => 'Administrador',
            'keys'  => ['*'],
        ],
        'manager' => [
            'label' => 'Gestor',
            'keys'  => [
                'chat.view', 'chat.create', 'chat.edit', 'chat.delete',
                'channels.*',
                'tasks.*',
                'meetings.*',
                'calendar.view',
                'teams.*',
                'processes.*',
                'polls.view', 'polls.create',
                'search.view',
                'categories.*',
                'emojis.*',
                'admin.*',
            ],
        ],
        'member' => [
            'label' => 'Membro',
            'keys'  => [
                'chat.view', 'chat.create', 'chat.edit', 'chat.delete',
                'channels.view', 'channels.create',
                'tasks.view', 'tasks.create', 'tasks.edit',
                'meetings.view', 'meetings.create', 'meetings.edit',
                'calendar.view',
                'teams.view',
                'processes.view',
                'polls.view', 'polls.create',
                'search.view',
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // Menu lateral — recebe o verificador de micropermissões do usuário.
    // Cada item é filtrado pela permissão .view correspondente.
    // ------------------------------------------------------------------
    'menu'        => function (callable $can): array {
        $u = fn (array $params): string => core_module_url('chat', $params);

        $main = [];
        if ($can('chat.view')) {
            $main[] = ['label' => 'Chat',           'url' => $u(['page' => 'chat']),                             'icon' => 'bi-chat',           'key' => 'chat'];
        }
        if ($can('tasks.view')) {
            $main[] = ['label' => 'Tarefas',        'url' => $u(['page' => 'tasks']),                            'icon' => 'bi-kanban',         'key' => 'tasks'];
            $main[] = ['label' => 'Minhas tarefas', 'url' => $u(['page' => 'tasks', 'action' => 'my']),          'icon' => 'bi-person-check',   'key' => 'tasks-my'];
        }
        if ($can('meetings.view')) {
            $main[] = ['label' => 'Reuniões',       'url' => $u(['page' => 'meetings']),                         'icon' => 'bi-calendar-event', 'key' => 'meetings'];
        }
        if ($can('calendar.view')) {
            $main[] = ['label' => 'Calendário',     'url' => $u(['page' => 'meetings', 'action' => 'calendar']), 'icon' => 'bi-calendar3',      'key' => 'meetings-calendar'];
        }
        if ($can('teams.view')) {
            $main[] = ['label' => 'Equipes',        'url' => $u(['page' => 'teams']),                            'icon' => 'bi-people',         'key' => 'teams'];
        }
        if ($can('processes.view')) {
            $main[] = ['label' => 'Processos',      'url' => $u(['page' => 'processes']),                        'icon' => 'bi-diagram-3',      'key' => 'processes'];
        }
        if ($can('search.view')) {
            $main[] = ['label' => 'Busca',          'url' => $u(['page' => 'search']),                           'icon' => 'bi-search',         'key' => 'search'];
        }

        $sections = [];
        if ($main) {
            $sections[] = ['heading' => 'Comunicação', 'items' => $main];
        }

        $adm = [];
        if ($can('admin.view')) {
            $adm[] = ['label' => 'Painel',     'url' => $u(['page' => 'admin']),                           'icon' => 'bi-speedometer2', 'key' => 'admin'];
        }
        if ($can('categories.view')) {
            $adm[] = ['label' => 'Categorias', 'url' => $u(['page' => 'admin', 'action' => 'categories']), 'icon' => 'bi-collection',   'key' => 'admin-categories'];
        }
        if ($can('emojis.view')) {
            $adm[] = ['label' => 'Emojis',     'url' => $u(['page' => 'admin', 'action' => 'emojis']),     'icon' => 'bi-emoji-smile',  'key' => 'admin-emojis'];
        }
        if ($can('admin.view')) {
            $adm[] = ['label' => 'Auditoria',  'url' => core_url('index.php?m=admin&a=audit&module=chat'), 'icon' => 'bi-journal-text', 'key' => 'admin-audit'];
        }
        if ($adm) {
            $sections[] = ['heading' => 'Administração', 'items' => $adm];
        }

        return $sections;
    },
];
