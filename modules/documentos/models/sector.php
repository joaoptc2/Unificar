<?php
/**
 * Model: Setores (departamentos/unidades do hospital)
 */

function sector_list($hospital_id) {
    if (!db_has_table('sectors')) return [];
    try {
        return db_query(
            "SELECT s.*, (SELECT COUNT(*) FROM user_sectors us WHERE us.sector_id = s.id) AS user_count
             FROM sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL AND s.is_active = 1
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_list_all($hospital_id) {
    if (!db_has_table('sectors')) return [];
    try {
        return db_query(
            "SELECT s.*, (SELECT COUNT(*) FROM user_sectors us WHERE us.sector_id = s.id) AS user_count
             FROM sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_find($id) {
    if (!db_has_table('sectors')) return null;
    return db_query_one("SELECT * FROM sectors WHERE id = ? AND deleted_at IS NULL", [(int) $id]);
}

function sector_create($hospital_id, $name, $code = null, $description = null) {
    db_execute(
        "INSERT INTO sectors (hospital_id, name, code, description, is_active) VALUES (?, ?, ?, ?, 1)",
        [$hospital_id, $name, $code, $description]
    );
    return (int) db_last_id();
}

function sector_update($id, $name, $code = null, $description = null, $is_active = 1) {
    return db_execute(
        "UPDATE sectors SET name = ?, code = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
        [$name, $code, $description, (int) $is_active, $id]
    );
}

function sector_soft_delete($id) {
    return db_execute("UPDATE sectors SET deleted_at = NOW() WHERE id = ?", [$id]);
}

/**
 * Retorna setores de um usuário.
 */
function user_sector_list($user_id) {
    if (!db_has_table('user_sectors')) return [];
    try {
        return db_query(
            "SELECT s.* FROM sectors s
             JOIN user_sectors us ON us.sector_id = s.id
             WHERE us.user_id = ? AND s.deleted_at IS NULL AND s.is_active = 1
             ORDER BY s.name",
            [(int) $user_id]
        );
    } catch (Exception $ex) { return []; }
}

/**
 * Sincroniza os setores de um usuário (substitui todos).
 */
function user_sector_sync($user_id, array $sector_ids) {
    if (!db_has_table('user_sectors')) return;
    db_execute("DELETE FROM user_sectors WHERE user_id = ?", [$user_id]);
    foreach ($sector_ids as $sid) {
        if ((int) $sid <= 0) continue;
        try {
            db_execute("INSERT INTO user_sectors (user_id, sector_id) VALUES (?, ?)", [$user_id, (int) $sid]);
        } catch (Exception $ex) {}
    }
}

/**
 * Retorna o primeiro hospital ativo (para login sem seleção de hospital).
 */
function get_default_hospital() {
    return db_query_one(
        "SELECT id, name FROM hospitals WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
    );
}
