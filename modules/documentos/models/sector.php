<?php
/**
 * Model: Setores (doc_sectors / doc_user_sectors).
 * user_id em doc_user_sectors referencia a tabela global users(id).
 */

function sector_list($hospital_id) {
    if (!db_has_table('doc_sectors')) return [];
    try {
        return db_query(
            "SELECT s.*, (SELECT COUNT(*) FROM doc_user_sectors us WHERE us.sector_id = s.id) AS user_count
             FROM doc_sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL AND s.is_active = 1
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_list_all($hospital_id) {
    if (!db_has_table('doc_sectors')) return [];
    try {
        return db_query(
            "SELECT s.*, (SELECT COUNT(*) FROM doc_user_sectors us WHERE us.sector_id = s.id) AS user_count
             FROM doc_sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_find($id) {
    if (!db_has_table('doc_sectors')) return null;
    return db_query_one("SELECT * FROM doc_sectors WHERE id = ? AND deleted_at IS NULL", [(int) $id]);
}

function sector_create($hospital_id, $name, $code = null, $description = null) {
    db_execute(
        "INSERT INTO doc_sectors (hospital_id, name, code, description, is_active) VALUES (?, ?, ?, ?, 1)",
        [$hospital_id, $name, $code, $description]
    );
    return (int) db_last_id();
}

function sector_update($id, $name, $code = null, $description = null, $is_active = 1) {
    return db_execute(
        "UPDATE doc_sectors SET name = ?, code = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
        [$name, $code, $description, (int) $is_active, $id]
    );
}

function sector_soft_delete($id) {
    return db_execute("UPDATE doc_sectors SET deleted_at = NOW() WHERE id = ?", [$id]);
}

/**
 * Retorna setores de um usuário (usuário global do núcleo).
 */
function user_sector_list($user_id) {
    if (!db_has_table('doc_user_sectors')) return [];
    try {
        return db_query(
            "SELECT s.* FROM doc_sectors s
             JOIN doc_user_sectors us ON us.sector_id = s.id
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
    if (!db_has_table('doc_user_sectors')) return;
    db_execute("DELETE FROM doc_user_sectors WHERE user_id = ?", [$user_id]);
    foreach ($sector_ids as $sid) {
        if ((int) $sid <= 0) continue;
        try {
            db_execute("INSERT INTO doc_user_sectors (user_id, sector_id) VALUES (?, ?)", [$user_id, (int) $sid]);
        } catch (Exception $ex) {}
    }
}

/**
 * Retorna o primeiro hospital (unidade) ativo.
 */
function get_default_hospital() {
    return db_query_one(
        "SELECT id, name FROM doc_hospitals WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
    );
}
