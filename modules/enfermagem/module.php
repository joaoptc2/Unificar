<?php
/**
 * Manifesto do módulo ENFERMAGEM.
 *
 * A enfermagem hospitalar entra no portal com uma função só, por ora: a
 * BUSCA FONADA DA CCIH — a vigilância epidemiológica pós-alta de Infecção de
 * Sítio Cirúrgico (ISC). Depois da alta, a enfermagem liga para o paciente
 * operado e registra o que ele relata; a CCIH classifica. É a substituição
 * da planilha BUSCA_FONADA_CCIH, com o cálculo de prazos, status, alerta e
 * indicadores feito pelo sistema (ver lib.php).
 *
 * Controle de acesso por MICROPERMISSÕES ("<recurso>.<ação>"), resolvidas
 * pelo núcleo (core_can/core_require). Como são DADOS DE SAÚDE (LGPD), nada
 * aqui é 'todos' => true: só quem a CCIH autorizar enxerga a vigilância.
 *
 * Carregado também fora do módulo (topbar, admin) — o menu usa
 * core_module_url().
 */

return [
    'slug'        => 'enfermagem',
    'name'        => 'Enfermagem',
    'icon'        => 'bi-heart-pulse',
    'description' => 'Busca Fonada da CCIH — vigilância pós-alta de infecção de sítio cirúrgico (ISC).',
    'entry'       => 'index.php',

    // ------------------------------------------------------------------
    // CATÁLOGO DE MICROPERMISSÕES
    // ------------------------------------------------------------------
    'permissions' => [
        'ccih' => [
            'label'   => 'Busca Fonada (vigilância pós-alta)',
            'actions' => [
                'view'     => 'Ver a vigilância, o painel e o relatório',
                'manage'   => 'Cadastrar cirurgias e registrar ligações/questionário',
                'classify' => 'Avaliação da CCIH (classificação ANVISA e notificação)',
                'import'   => 'Importar cirurgias de planilha',
                'export'   => 'Exportar / imprimir o relatório de indicadores',
                'config'   => 'Gerenciar as listas (procedimentos e médicos)',
            ],
        ],
    ],

    // ------------------------------------------------------------------
    // PRESETS — atalhos na UI de permissões.
    // ------------------------------------------------------------------
    'presets' => [
        'admin' => [
            'label' => 'Administrador',
            'keys'  => ['*'],
        ],
        // Enfermeira/médico da CCIH: faz tudo, inclusive classificar.
        'ccih' => [
            'label' => 'CCIH (enfermagem/médico)',
            'keys'  => ['ccih.*'],
        ],
        // Quem só faz as ligações e registra o questionário; não classifica.
        'operador' => [
            'label' => 'Operador da busca fonada',
            'keys'  => ['ccih.view', 'ccih.manage', 'ccih.export'],
        ],
        // Gestão que acompanha os indicadores, sem mexer nos dados.
        'visualizador' => [
            'label' => 'Visualizador (indicadores)',
            'keys'  => ['ccih.view', 'ccih.export'],
        ],
    ],

    // ------------------------------------------------------------------
    // Menu lateral — itens filtrados pela micropermissão.
    // ------------------------------------------------------------------
    'menu' => function (callable $can): array {
        $u = fn (string $page): string => core_module_url('enfermagem', ['page' => $page]);

        $items = [];
        if ($can('ccih.view')) {
            $items[] = ['label' => 'Painel',        'url' => $u('painel'),    'icon' => 'bi-speedometer2',   'key' => 'painel'];
            $items[] = ['label' => 'Pacientes',     'url' => $u('pacientes'), 'icon' => 'bi-telephone',      'key' => 'pacientes'];
            $items[] = ['label' => 'Relatório',     'url' => $u('relatorio'), 'icon' => 'bi-clipboard-data', 'key' => 'relatorio'];
            $items[] = ['label' => 'Roteiro',       'url' => $u('roteiro'),   'icon' => 'bi-card-checklist', 'key' => 'roteiro'];
        }
        if ($can('ccih.import')) {
            $items[] = ['label' => 'Importar', 'url' => $u('importar'), 'icon' => 'bi-upload', 'key' => 'importar'];
        }
        if ($can('ccih.config')) {
            $items[] = ['label' => 'Listas', 'url' => $u('config'), 'icon' => 'bi-list-ul', 'key' => 'config'];
        }

        return [['heading' => 'Busca Fonada da CCIH', 'items' => $items]];
    },
];
