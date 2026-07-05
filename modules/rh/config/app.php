<?php
/**
 * Módulo RH — configurações funcionais remanescentes.
 *
 * Banco de dados, sessão, e-mail (SMTP), tema e rate-limit de login agora
 * são responsabilidade do NÚCLEO da plataforma (config/config.php +
 * Core\Settings) — as credenciais correspondentes foram removidas daqui.
 */

return [
    // Upload (limites do módulo)
    'upload_max_size'    => 5 * 1024 * 1024, // 5MB
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],

    // Vencimentos
    'expiry_alert_days' => 30,

    // Paginação
    'items_per_page' => 20,

    // Rate-limit da página pública de vagas
    'public_apply_max_per_hour' => 3,
];
