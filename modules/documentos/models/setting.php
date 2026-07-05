<?php
/**
 * Model: Configurações do sistema (key-value).
 *
 * Todas as settings são cacheadas por requisição e persistidas em arquivo
 * de cache para que o layout não precise consultar o banco a cada pageload.
 */

/**
 * Valores padrão caso a tabela não exista ou a chave não esteja gravada.
 */
function setting_defaults() {
    return [
        'app_name'            => defined('APP_NAME') ? APP_NAME : 'Sistema de Gestão Documental',
        'primary_color'       => '#0d6efd',
        'sidebar_bg'          => '#1e293b',
        'sidebar_text'        => '#94a3b8',
        'sidebar_hover_bg'    => '#334155',
        'sidebar_active_text' => '#ffffff',
        'navbar_bg'           => '#0d6efd',
        'body_bg'             => '#f1f5f9',
        'body_font'           => "'Segoe UI', system-ui, -apple-system, sans-serif",
        'body_font_size'      => '0.9',
        'card_shadow'         => '0 1px 3px rgba(0,0,0,.08)',
        'card_border_radius'  => '0.5',
        'login_gradient_start'=> '#0d6efd',
        'login_gradient_end'  => '#6610f2',
        'logo_icon'           => 'bi-hospital',
        'footer_text'         => '',
        'custom_css'          => '',
    ];
}

/**
 * Metadata dos campos: label, tipo de input, grupo para organizar o form.
 */
function setting_fields() {
    return [
        // ── Identidade ──
        'app_name' => [
            'label' => 'Nome do sistema',
            'type'  => 'text',
            'group' => 'Identidade',
            'help'  => 'Exibido na navbar, login e rodapé.',
        ],
        'logo_icon' => [
            'label' => 'Ícone do logo',
            'type'  => 'icon',
            'group' => 'Identidade',
            'help'  => 'Classe Bootstrap Icons (ex: bi-hospital, bi-building, bi-heart-pulse).',
        ],
        'footer_text' => [
            'label' => 'Texto do rodapé',
            'type'  => 'text',
            'group' => 'Identidade',
            'help'  => 'Deixe vazio para usar o nome do sistema + ano.',
        ],

        // ── Cores principais ──
        'primary_color' => [
            'label' => 'Cor primária',
            'type'  => 'color',
            'group' => 'Cores',
            'help'  => 'Botões principais, links, destaques.',
        ],
        'navbar_bg' => [
            'label' => 'Cor da barra superior',
            'type'  => 'color',
            'group' => 'Cores',
        ],
        'body_bg' => [
            'label' => 'Cor de fundo da página',
            'type'  => 'color',
            'group' => 'Cores',
        ],

        // ── Sidebar ──
        'sidebar_bg' => [
            'label' => 'Fundo da sidebar',
            'type'  => 'color',
            'group' => 'Sidebar',
        ],
        'sidebar_text' => [
            'label' => 'Texto da sidebar',
            'type'  => 'color',
            'group' => 'Sidebar',
        ],
        'sidebar_hover_bg' => [
            'label' => 'Hover / item ativo',
            'type'  => 'color',
            'group' => 'Sidebar',
        ],
        'sidebar_active_text' => [
            'label' => 'Texto do item ativo',
            'type'  => 'color',
            'group' => 'Sidebar',
        ],

        // ── Tipografia ──
        'body_font' => [
            'label' => 'Fonte',
            'type'  => 'select',
            'group' => 'Tipografia',
            'options' => [
                "'Segoe UI', system-ui, -apple-system, sans-serif" => 'Segoe UI (padrão)',
                "'Inter', sans-serif"         => 'Inter',
                "'Roboto', sans-serif"        => 'Roboto',
                "'Nunito', sans-serif"        => 'Nunito',
                "'Poppins', sans-serif"       => 'Poppins',
                "system-ui, sans-serif"       => 'System UI',
                "'Arial', sans-serif"         => 'Arial',
            ],
        ],
        'body_font_size' => [
            'label' => 'Tamanho base (rem)',
            'type'  => 'range',
            'group' => 'Tipografia',
            'min'   => 0.75,
            'max'   => 1.1,
            'step'  => 0.05,
        ],

        // ── Cards ──
        'card_border_radius' => [
            'label' => 'Arredondamento dos cards (rem)',
            'type'  => 'range',
            'group' => 'Cards',
            'min'   => 0,
            'max'   => 1.5,
            'step'  => 0.125,
        ],
        'card_shadow' => [
            'label' => 'Sombra dos cards',
            'type'  => 'select',
            'group' => 'Cards',
            'options' => [
                'none'                            => 'Nenhuma',
                '0 1px 3px rgba(0,0,0,.08)'       => 'Sutil (padrão)',
                '0 2px 8px rgba(0,0,0,.12)'       => 'Média',
                '0 4px 15px rgba(0,0,0,.15)'      => 'Forte',
            ],
        ],

        // ── Login ──
        'login_gradient_start' => [
            'label' => 'Gradiente login (início)',
            'type'  => 'color',
            'group' => 'Tela de Login',
        ],
        'login_gradient_end' => [
            'label' => 'Gradiente login (fim)',
            'type'  => 'color',
            'group' => 'Tela de Login',
        ],

        // ── CSS Customizado ──
        'custom_css' => [
            'label' => 'CSS personalizado',
            'type'  => 'textarea',
            'group' => 'Avançado',
            'help'  => 'CSS adicional injetado depois do style.css. Use com cuidado.',
        ],
    ];
}

/**
 * Carrega todas as settings do banco (com cache em arquivo).
 * Retorna array associativo key => value, com defaults aplicados.
 */
function settings_all() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = setting_defaults();

    // Tenta cache em arquivo primeiro (evita query a cada pageview)
    $cache_key = 'system_settings';
    $cached = cache_get($cache_key);
    if (is_array($cached)) {
        $cache = array_merge($defaults, $cached);
        return $cache;
    }

    // Busca no banco
    if (!db_has_table('system_settings')) {
        $cache = $defaults;
        return $cache;
    }

    try {
        $rows = db_query("SELECT setting_key, setting_value FROM system_settings");
        $db_vals = [];
        foreach ($rows as $r) {
            $db_vals[$r['setting_key']] = $r['setting_value'];
        }
        $cache = array_merge($defaults, $db_vals);
        cache_set($cache_key, $db_vals, 600); // 10 min
    } catch (Exception $ex) {
        $cache = $defaults;
    }

    return $cache;
}

/**
 * Obtém uma setting individual.
 */
function setting($key, $default = null) {
    $all = settings_all();
    $val = $all[$key] ?? $default;
    if ($val === null || $val === '') {
        $defs = setting_defaults();
        return $defs[$key] ?? $default;
    }
    return $val;
}

/**
 * Grava um conjunto de settings. Invalida cache.
 */
function settings_save(array $values, $user_id = null) {
    if (!db_has_table('system_settings')) return false;

    foreach ($values as $key => $value) {
        db_execute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by = VALUES(updated_by),
                                     updated_at = NOW()",
            [$key, $value, $user_id]
        );
    }

    cache_forget('system_settings');
    return true;
}

/**
 * Reseta todas as settings para os valores padrão.
 */
function settings_reset($user_id = null) {
    return settings_save(setting_defaults(), $user_id);
}

/**
 * Gera o bloco <style> com CSS variáveis a partir das settings atuais.
 * Chamado pelo layout para injetar as customizações.
 */
function settings_dynamic_css() {
    $s = settings_all();

    $css = ":root {\n";
    $css .= "  --primary-color: {$s['primary_color']};\n";
    $css .= "  --sidebar-bg: {$s['sidebar_bg']};\n";
    $css .= "  --sidebar-text: {$s['sidebar_text']};\n";
    $css .= "  --sidebar-hover-bg: {$s['sidebar_hover_bg']};\n";
    $css .= "  --sidebar-active: {$s['sidebar_active_text']};\n";
    $css .= "}\n";
    $css .= "body { background-color: {$s['body_bg']}; font-family: {$s['body_font']}; font-size: {$s['body_font_size']}rem; }\n";
    $css .= ".navbar.bg-primary { background-color: {$s['navbar_bg']} !important; }\n";
    $css .= ".card { border-radius: {$s['card_border_radius']}rem; }\n";
    $css .= ".card.shadow-sm { box-shadow: {$s['card_shadow']}; }\n";
    $css .= ".stat-card { border-radius: {$s['card_border_radius']}rem; }\n";
    $css .= ".btn-primary { background-color: {$s['primary_color']}; border-color: {$s['primary_color']}; }\n";
    $css .= ".btn-primary:hover { background-color: {$s['primary_color']}; border-color: {$s['primary_color']}; filter: brightness(0.9); }\n";
    $css .= ".btn-outline-primary { color: {$s['primary_color']}; border-color: {$s['primary_color']}; }\n";
    $css .= ".btn-outline-primary:hover { background-color: {$s['primary_color']}; border-color: {$s['primary_color']}; }\n";
    $css .= ".text-primary { color: {$s['primary_color']} !important; }\n";
    $css .= ".bg-primary { background-color: {$s['primary_color']} !important; }\n";
    $css .= ".login-icon { background: linear-gradient(135deg, {$s['login_gradient_start']}, {$s['login_gradient_end']}); }\n";
    $css .= "a { color: {$s['primary_color']}; }\n";
    $css .= ".page-link.active, .active > .page-link { background-color: {$s['primary_color']}; border-color: {$s['primary_color']}; }\n";
    $css .= ".sidebar-nav .nav-link.active { border-left-color: {$s['primary_color']}; }\n";

    if (!empty($s['custom_css'])) {
        $css .= "/* Custom CSS */\n" . $s['custom_css'] . "\n";
    }

    return $css;
}
