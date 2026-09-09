<?php
/**
 * Model: Setores (doc_sectors).
 *
 * O acesso NÃO é mais restrito por setor do usuário (a antiga tabela
 * doc_user_sectors deixou de ser usada): o filtro é o seletor global de
 * setor da sessão, disponível a todos os usuários do módulo.
 */

function sector_list($hospital_id) {
    try {
        return db_query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM doc_documents d WHERE d.sector_id = s.id AND d.deleted_at IS NULL) AS document_count
             FROM doc_sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL AND s.is_active = 1
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_list_all($hospital_id) {
    try {
        return db_query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM doc_documents d WHERE d.sector_id = s.id AND d.deleted_at IS NULL) AS document_count,
                    (SELECT COUNT(*) FROM doc_indicators i WHERE i.sector_id = s.id AND i.deleted_at IS NULL) AS indicator_count
             FROM doc_sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_find($id) {
    return db_query_one("SELECT * FROM doc_sectors WHERE id = ? AND deleted_at IS NULL", [(int) $id]);
}

/** Setor ativo da unidade (para validar o contexto/os formulários). */
function sector_find_active($id, $hospital_id) {
    return db_query_one(
        "SELECT * FROM doc_sectors WHERE id = ? AND hospital_id = ? AND deleted_at IS NULL AND is_active = 1",
        [(int) $id, (int) $hospital_id]
    );
}

function sector_create($hospital_id, $name, $code = null, $description = null) {
    db_execute(
        "INSERT INTO doc_sectors (hospital_id, name, code, description, is_active) VALUES (?, ?, ?, ?, 1)",
        [(int) $hospital_id, $name, $code, $description]
    );
    return (int) db_last_id();
}

function sector_update($id, $name, $code = null, $description = null, $is_active = 1) {
    return db_execute(
        "UPDATE doc_sectors SET name = ?, code = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
        [$name, $code, $description, (int) $is_active, (int) $id]
    );
}

function sector_soft_delete($id) {
    return db_execute("UPDATE doc_sectors SET deleted_at = NOW() WHERE id = ?", [(int) $id]);
}

/**
 * Retorna o primeiro hospital (unidade) ativo.
 */
function get_default_hospital() {
    return db_query_one(
        "SELECT id, name FROM doc_hospitals WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
    );
}
