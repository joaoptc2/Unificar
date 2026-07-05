<?php
/**
 * Model: Configurações funcionais do módulo (doc_system_settings, key-value).
 *
 * As configurações VISUAIS do legado (cores, fontes, CSS custom,
 * settings_dynamic_css) foram removidas — o tema agora é do núcleo.
 * Esta tabela fica disponível para settings funcionais do módulo.
 */

/**
 * Carrega todas as settings do banco (cache em arquivo + por requisição).
 */
function settings_all() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache_key = 'doc_system_settings';
    $cached = cache_get($cache_key);
    if (is_array($cached)) {
        return $cache = $cached;
    }

    if (!db_has_table('doc_system_settings')) {
        return $cache = [];
    }

    try {
        $rows = db_query("SELECT setting_key, setting_value FROM doc_system_settings");
        $vals = [];
        foreach ($rows as $r) {
            $vals[$r['setting_key']] = $r['setting_value'];
        }
        cache_set($cache_key, $vals, 600); // 10 min
        return $cache = $vals;
    } catch (Exception $ex) {
        return $cache = [];
    }
}

/**
 * Obtém uma setting individual.
 */
function setting($key, $default = null) {
    $all = settings_all();
    $val = $all[$key] ?? null;
    return ($val === null || $val === '') ? $default : $val;
}

/**
 * Grava um conjunto de settings. Invalida cache.
 */
function settings_save(array $values, $user_id = null) {
    if (!db_has_table('doc_system_settings')) return false;

    foreach ($values as $key => $value) {
        db_execute(
            "INSERT INTO doc_system_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by = VALUES(updated_by),
                                     updated_at = NOW()",
            [$key, $value, $user_id]
        );
    }

    cache_forget('doc_system_settings');
    return true;
}
