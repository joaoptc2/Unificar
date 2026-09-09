<?php
/**
 * Manifesto do módulo Comunicação (chat interno).
 * Carregado também fora do módulo (topbar/admin) — usar core_module_url().
 *
 * Modelo de acesso: MICROPERMISSÕES por função ("<recurso>.<ação>"),
 * resolvidas pelo núcleo (Core\Perms / core_can()). Os antigos níveis
 * (admin/manager/member) viraram apenas 'presets'.
 *
 * O módulo é SOMENTE chat: canais, mensagens diretas, threads, reações,
 * anexos, menções e busca. Tarefas, reuniões, calendário, equipes,
 * processos, enquetes e o "Painel" administrativo foram descontinuados.
 * Categorias de canais e emojis personalizados são configurados na
 * ADMINISTRAÇÃO CENTRAL (chave 'admin' → Core\AdminPanel).
 */

return [
    'slug'        => 'chat',
    'name'        => 'Comunicação',
    'icon'        => 'bi-chat-dots',
    'description' => 'Chat interno por canais e mensagens diretas.',

    'entry'       => 'index.php',

    // ------------------------------------------------------------------
    // Catálogo de micropermissões — TODAS as funções do módulo.
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
        'search' => [
            'label'   => 'Busca de mensagens',
            'actions' => [
                'view' => 'Visualizar',
            ],
        ],
        'categories' => [
            'label'   => 'Categorias de canais (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
        'emojis' => [
            'label'   => 'Emojis personalizados (configuração)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'delete' => 'Excluir',
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // Presets — chave = nível legado.
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
                'search.view',
                'categories.*',
                'emojis.*',
            ],
        ],
        'member' => [
            'label' => 'Membro',
            'keys'  => [
                'chat.view', 'chat.create', 'chat.edit', 'chat.delete',
                'channels.view', 'channels.create',
                'search.view',
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // Menu lateral — mesmo padrão dos demais módulos.
    // (o link "Configurações do módulo" é acrescentado pelo núcleo)
    // ------------------------------------------------------------------
    'menu'        => function (callable $can): array {
        $u = fn (array $params): string => core_module_url('chat', $params);

        $main = [];
        if ($can('chat.view')) {
            $main[] = ['label' => 'Chat',     'url' => $u(['page' => 'chat']),                         'icon' => 'bi-chat-dots', 'key' => 'chat'];
        }
        if ($can('channels.view')) {
            $main[] = ['label' => 'Canais',   'url' => $u(['page' => 'channels', 'action' => 'browse']), 'icon' => 'bi-hash',      'key' => 'channels'];
        }
        if ($can('search.view')) {
            $main[] = ['label' => 'Buscar mensagens', 'url' => $u(['page' => 'search']),              'icon' => 'bi-search',    'key' => 'search'];
        }

        $sections = [];
        if ($main) {
            $sections[] = ['heading' => 'Comunicação', 'items' => $main];
        }
        return $sections;
    },

    // ------------------------------------------------------------------
    // Painel de configuração na Administração central.
    // ------------------------------------------------------------------
    'admin' => [
        'label' => 'Comunicação',
        'icon'  => 'bi-chat-dots',
        'entry' => 'admin_panel.php',
        'tabs'  => [
            'categories' => ['label' => 'Categorias de canais', 'icon' => 'bi-collection',  'perm' => 'categories.view'],
            'emojis'     => ['label' => 'Emojis personalizados', 'icon' => 'bi-emoji-smile', 'perm' => 'emojis.view'],
        ],
    ],
];
