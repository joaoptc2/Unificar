<?php
/**
 * MÓDULO MEU ESPAÇO — a organização pessoal de cada pessoa dentro do portal.
 *
 *  • Agenda própria (compromissos, plantões, lembretes);
 *  • Bloco de notas, com fixação e cor;
 *  • Tarefas com prazo, prioridade e situação.
 *
 * O que distingue este módulo dos outros: aqui NADA é institucional. Toda
 * tabela tem user_id e toda consulta filtra por ele — nem o administrador
 * global vê a agenda de outra pessoa por esta tela.
 *
 * O nome exibido é o da própria pessoa ("João"), não um rótulo fixo: ver
 * 'display_name' abaixo.
 *
 * Carregado também fora do módulo (topbar/admin) — usar core_module_url().
 */

return [
    'slug'        => 'meu',
    // Nome canônico, usado em Administração > Módulos e nas permissões. O
    // nome que a PESSOA vê é o dela, montado por display_name().
    'name'        => 'Meu espaço',
    'icon'        => 'bi-person-workspace',
    'description' => 'Sua agenda, suas notas e suas tarefas — o que é só seu, num lugar só.',
    'entry'       => 'index.php',

    /**
     * Nome exibido no portal e no menu: o primeiro nome de quem está logado.
     *
     * Fica FORA de 'name' de propósito. Modules::all() reescreve a coluna
     * name da tabela modules com o valor do manifesto sempre que a tela de
     * Administração > Módulos é aberta; se o nome dependesse do usuário, o
     * primeiro administrador a abrir aquela tela gravaria o próprio nome
     * como nome do módulo para todo mundo.
     */
    'display_name' => function (array $user): string {
        $primeiro = trim(explode(' ', trim((string) ($user['name'] ?? '')))[0]);
        return $primeiro !== '' ? $primeiro : 'Meu espaço';
    },

    /**
     * Acesso universal: toda pessoa logada tem o próprio espaço, sem o
     * administrador precisar conceder nada. Ver Core\Perms::effective() —
     * é seguro porque nenhuma consulta deste módulo sai do próprio user_id.
     */
    'todos' => true,

    'permissions' => [
        'agenda' => [
            'label'   => 'Agenda pessoal',
            'actions' => [
                'view'   => 'Ver a própria agenda',
                'manage' => 'Criar, editar e excluir compromissos',
            ],
        ],
        'notas' => [
            'label'   => 'Bloco de notas',
            'actions' => [
                'view'   => 'Ver as próprias notas',
                'manage' => 'Criar, editar e excluir notas',
            ],
        ],
        'tarefas' => [
            'label'   => 'Tarefas',
            'actions' => [
                'view'   => 'Ver as próprias tarefas',
                'manage' => 'Criar, editar, concluir e excluir tarefas',
            ],
        ],
    ],

    /**
     * Só há um perfil que faz sentido: o módulo é de uso pessoal, e um
     * "leitor" que vê as próprias notas sem poder escrevê-las não existe no
     * mundo real. O perfil fica para o administrador conceder em bloco.
     */
    'presets' => [
        'padrao' => ['label' => 'Uso pessoal (todos)', 'keys' => ['*']],
    ],

    'menu' => function (callable $can): array {
        $u = fn (string $page): string => core_module_url('meu', ['page' => $page]);

        $items = [];
        $items[] = ['label' => 'Hoje', 'url' => $u('hoje'), 'icon' => 'bi-sun', 'key' => 'hoje'];
        if ($can('agenda.view'))  { $items[] = ['label' => 'Agenda',  'url' => $u('agenda'),  'icon' => 'bi-calendar3',   'key' => 'agenda']; }
        if ($can('tarefas.view')) { $items[] = ['label' => 'Tarefas', 'url' => $u('tarefas'), 'icon' => 'bi-check2-square', 'key' => 'tarefas']; }
        if ($can('notas.view'))   { $items[] = ['label' => 'Notas',   'url' => $u('notas'),   'icon' => 'bi-journal-text', 'key' => 'notas']; }

        return [['heading' => 'Meu espaço', 'items' => $items]];
    },
];
