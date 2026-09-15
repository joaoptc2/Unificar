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

        /**
         * Fuso da sessão do banco. 'app' (padrão) alinha o banco ao fuso do
         * PHP (app.timezone), para que NOW() e date() marquem a mesma hora.
         * Use 'server' para manter o fuso do servidor de banco — indicado
         * quando a base já tem histórico gravado em outro fuso e você prefere
         * não misturar. Também aceita um deslocamento fixo, ex.: '-03:00'.
         */
        'timezone' => 'app',
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

    /**
     * E-mail. Estes valores são o PADRÃO: a tela Administração > E-mail pode
     * sobrescrever cada campo sem editar arquivo (a senha é guardada cifrada
     * no banco). Para travar tudo aqui — quando a hospedagem gerencia o
     * envio — acrescente 'lock' => true.
     */
    'mail' => [
        'enabled'    => false,
        'host'       => 'smtp.exemplo.com.br',
        'port'       => 587,
        'user'       => '',
        'pass'       => '',
        'encryption' => 'tls', // tls | ssl | '' (sem criptografia)
        'from'       => 'nao-responda@exemplo.com.br',
        'from_name'  => 'Portal Corporativo',
        'reply_to'   => '',
        // Nome usado na apresentação ao servidor (vazio = nome da máquina).
        'ehlo'       => '',
        'timeout'    => 15,
        /**
         * Se o SMTP falhar, tentar a função mail() do PHP?
         * 'auto' (padrão) só recorre a ela quando NÃO há servidor configurado.
         * Deixar sempre ligado mascara problemas: a mensagem sai pelo sendmail
         * local e o sistema marca como enviada mesmo com o SMTP quebrado.
         */
        'fallback_mail' => 'auto', // auto | true | false
        // Seletor DKIM do provedor, se souber — habilita a conferência na
        // aba Diagnóstico (ex.: 'default', 'google', 'selector1').
        'dkim_selector' => '',
        // 'lock'    => true,
    ],

    'security' => [
        'session_name'     => 'portal_sess',
        'session_lifetime' => 60 * 60 * 8, // 8h
        // Bloqueio de força bruta: N falhas em M minutos
        'login_max_attempts' => 5,
        'login_window_min'   => 10,
        'login_lockout_min'  => 15,

        // Proxy reverso à frente (Nginx, Cloudflare, balanceador)?
        //
        // Ligue SOMENTE se houver de fato um proxy e ele definir o cabeçalho
        // X-Forwarded-Proto. Com isto ligado, o sistema passa a acreditar
        // nesse cabeçalho para saber se a visita veio por https — e é assim
        // que o cookie de sessão ganha a marca Secure no arranjo mais comum
        // de hospedagem.
        //
        // Ligar sem proxy é um furo: qualquer cliente poderia afirmar "vim
        // por https" e escapar do redirecionamento.
        'trust_proxy' => false,
    ],

    // Segredo do cron unificado (cron.php?token=...)
    'cron_secret' => 'troque-este-token',
];
