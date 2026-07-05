<?php
/**
 * RH Hospital - Configurações Gerais da Aplicação
 */

return [
    // Nome do sistema
    'app_name' => 'Hospital Premier',

    // URL base (sem barra final) — ajustar conforme ambiente
    'base_url' => '',

    // Fuso horário
    'timezone' => 'America/Sao_Paulo',

    // Sessão
    'session_name'     => 'RH_HOSPITAL_SESS',
    'session_lifetime' => 3600, // 1 hora

    // Upload
    'upload_max_size'    => 5 * 1024 * 1024, // 5MB
    'upload_base_path'   => __DIR__ . '/../public/uploads/',
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

    // E-mail
    // `mail_enabled`: false desativa totalmente o envio de e-mails.
    // `mail_use_smtp`: true usa SmtpClient (recomendado); false cai para mail().
    'mail_enabled'    => false,
    'mail_use_smtp'   => true,
    'mail_host'       => 'smtp.hostinger.com',
    'mail_port'       => 587,
    'mail_encryption' => 'tls', // 'tls' (STARTTLS) | 'ssl' (TLS impl. na 465) | ''
    'mail_username'   => '',
    'mail_password'   => '',
    'mail_timeout'    => 20,
    'mail_from'       => 'rh@hospital.com.br',
    'mail_from_name'  => 'RH Hospital',

    // Log
    'log_path' => __DIR__ . '/../logs/',

    // Token opcional para executar CRONs via URL quando o cron nativo não
    // estiver disponível (alguns painéis só permitem chamadas HTTP). Se vazio,
    // os scripts de cron só aceitam execução por linha de comando.
    // Recomenda-se gerar com: bin2hex(random_bytes(32))
    'cron_token' => '',

    // Rate-limit de login (tentativas por IP em janela de tempo)
    'login_max_attempts'        => 5,
    'login_lockout_minutes'     => 15,

    // Rate-limit da página pública de vagas
    'public_apply_max_per_hour' => 3,

    // Debug (desativar em produção)
    'debug' => false,
];
