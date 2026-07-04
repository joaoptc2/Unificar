<?php
/**
 * ============================================================
 * PLATAFORMA UNIFICADA — Configuração
 * Copie este arquivo para config/config.php e ajuste os valores.
 * ============================================================
 */

return [

    'app' => [
        'name'     => 'Portal Corporativo',
        // URL base SEM barra final. Deixe null para detectar automaticamente.
        'base_url' => null,
        'timezone' => 'America/Sao_Paulo',
        'debug'    => false,
        // Chave usada para tokens internos (troque por um valor aleatório longo)
        'key'      => 'troque-esta-chave-por-um-valor-aleatorio',
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'portal_unificado',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    /**
     * Integração com o Moodle (login único).
     *
     * Como funciona: no login, se a senha local não conferir (ou o usuário
     * não existir), a plataforma tenta autenticar no Moodle via
     * {url}/login/token.php. Se o Moodle aceitar, o usuário é criado/
     * atualizado automaticamente aqui (auto-provisionamento).
     *
     * Requisitos no Moodle:
     *  - Administração do site > Plugins > Serviços web: habilitar REST;
     *  - Um serviço externo habilitado (por padrão o app móvel:
     *    'moodle_mobile_app' já serve para validar credenciais).
     *  - (Opcional) um token de administrador com a função
     *    core_user_get_users_by_field para importar e-mail/nome completos.
     */
    'moodle' => [
        'enabled'      => false,
        'url'          => 'https://moodle.exemplo.com.br', // sem barra final
        'service'      => 'moodle_mobile_app',
        'admin_token'  => '', // opcional: enriquece o cadastro (e-mail, nome)
        // Módulos liberados automaticamente para usuários vindos do Moodle
        // (slug => nível). Deixe [] para exigir liberação manual pelo admin.
        'default_access' => [
            // 'chat' => 'user',
        ],
        // Link "Moodle" no menu superior (null oculta)
        'link_label'   => 'Moodle',
    ],

    'mail' => [
        'enabled'    => false,
        'host'       => 'smtp.exemplo.com.br',
        'port'       => 587,
        'user'       => '',
        'pass'       => '',
        'encryption' => 'tls', // tls | ssl | ''
        'from'       => 'nao-responda@exemplo.com.br',
        'from_name'  => 'Portal Corporativo',
    ],

    'security' => [
        'session_name'     => 'portal_sess',
        'session_lifetime' => 60 * 60 * 8, // 8h
        // Bloqueio de força bruta: N falhas em M minutos
        'login_max_attempts' => 5,
        'login_window_min'   => 10,
        'login_lockout_min'  => 15,
    ],

    // Segredo do cron unificado (cron.php?token=...)
    'cron_secret' => 'troque-este-token',
];
