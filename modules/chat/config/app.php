<?php
/**
 * Configuração geral do aplicativo
 */
return [
    'name'     => 'TeamChat',
    'timezone' => 'America/Sao_Paulo',
    'debug'    => true,
    'url'      => '',

    'upload' => [
        'max_size'       => 10 * 1024 * 1024,
        'allowed_images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'allowed_files'  => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'text/plain', 'text/csv',
        ],
    ],

    'poll_interval' => 3000,

    'pagination' => [
        'per_page' => 50,
        'messages' => 50,
    ],
];
