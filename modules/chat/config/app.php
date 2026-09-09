<?php
/**
 * Configuração geral do módulo Comunicação (chat).
 * O nome exibido é o da organização (Core\Settings 'org_name').
 */
return [
    'name'     => 'Comunicação',
    'timezone' => 'America/Sao_Paulo',
    'debug'    => true,
    'url'      => '',

    'upload' => [
        'max_size'       => 10 * 1024 * 1024,
        // MIME → extensões aceitas (validação dupla: extensão + conteúdo)
        'allowed_images' => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'image/gif'  => ['gif'],
            'image/webp' => ['webp'],
        ],
        'allowed_files'  => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'image/gif'  => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/vnd.ms-powerpoint' => ['ppt'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
            'application/zip' => ['zip'],
            'application/x-zip-compressed' => ['zip'],
            'text/plain' => ['txt', 'csv', 'log', 'md'],
            'text/csv'   => ['csv'],
        ],
    ],

    // Intervalo (ms) do polling de mensagens com a aba ativa / em segundo plano
    'poll_interval'      => 2000,
    'poll_interval_idle' => 8000,

    'pagination' => [
        'per_page' => 50,
        'messages' => 50,
    ],
];
