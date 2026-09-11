<?php
/**
 * MÓDULO INTRANET — Documentos institucionais padronizados.
 * Editor de texto com layouts predefinidos (papel timbrado), múltiplos
 * tamanhos de página, versionamento das edições, cópia pública opcional
 * e exportação em PDF (impressão do navegador com CSS @page).
 *
 * Os layouts (papel timbrado, capa, fundo, fontes) são do NÚCLEO
 * (Core\DocLayout) e são gerenciados em Administração > Padronização >
 * Layouts de documentos — compartilhados com o módulo Documentos.
 */

return [
    'slug'        => 'intranet',
    'name'        => 'Intranet',
    'icon'        => 'bi-newspaper',
    'description' => 'Documentos institucionais com layout padronizado, versionamento e exportação em PDF.',
    'entry'       => 'index.php',

    'permissions' => [
        'documents' => [
            'label'   => 'Documentos',
            'actions' => [
                'view'    => 'Visualizar',
                'create'  => 'Criar',
                'edit'    => 'Editar',
                'delete'  => 'Excluir',
                'publish' => 'Publicar / tornar público',
                'export'  => 'Exportar PDF',
            ],
        ],
        'history' => [
            'label'   => 'Histórico de edições',
            'actions' => [
                'view'    => 'Visualizar',
                'restore' => 'Restaurar versão',
            ],
        ],
        'layouts' => [
            'label'   => 'Layouts de documentos (padronização)',
            'actions' => [
                'view'   => 'Visualizar',
                'create' => 'Criar',
                'edit'   => 'Editar',
                'delete' => 'Excluir',
            ],
        ],
    ],

    'presets' => [
        'admin'  => ['label' => 'Administrador', 'keys' => ['*']],
        'editor' => ['label' => 'Editor', 'keys' => [
            'documents.view', 'documents.create', 'documents.edit', 'documents.export',
            'history.view', 'history.restore', 'layouts.view',
        ]],
        'leitor' => ['label' => 'Leitor', 'keys' => ['documents.view', 'documents.export']],
    ],

    'menu' => function (callable $can): array {
        $items = [];
        if ($can('documents.view')) {
            $items[] = ['label' => 'Documentos', 'url' => core_module_url('intranet', ['page' => 'documents']), 'icon' => 'bi-files', 'key' => 'documents'];
        }
        if ($can('documents.create')) {
            $items[] = ['label' => 'Novo documento', 'url' => core_module_url('intranet', ['page' => 'editor']), 'icon' => 'bi-file-earmark-plus', 'key' => 'editor'];
        }
        $sections = [['heading' => 'Intranet', 'items' => $items]];

        if ($can('layouts.view')) {
            $sections[] = ['heading' => 'Padronização', 'items' => [
                ['label' => 'Layouts de documentos', 'url' => core_module_url('admin', ['a' => 'layouts']), 'icon' => 'bi-layout-text-window-reverse', 'key' => 'layouts'],
            ]];
        }
        return $sections;
    },

    // Cópia pública: acessível sem login via token
    'is_public' => fn (array $get): bool => ($get['page'] ?? '') === 'public',
];
