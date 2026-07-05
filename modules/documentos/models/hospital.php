<?php
/**
 * Model: Hospitais
 */

function hospital_list_active() {
    return cache_remember('hospitals:active', 300, function() {
        return db_query(
            "SELECT id, name FROM hospitals
             WHERE is_active = 1 AND deleted_at IS NULL
             ORDER BY name"
        );
    });
}

function hospital_list_all() {
    return db_query(
        "SELECT h.*, (SELECT COUNT(*) FROM users u WHERE u.hospital_id = h.id AND u.deleted_at IS NULL) AS user_count
         FROM hospitals h
         WHERE h.deleted_at IS NULL
         ORDER BY h.name"
    );
}

function hospital_find($id) {
    return db_query_one(
        "SELECT * FROM hospitals WHERE id = ? AND deleted_at IS NULL",
        [(int) $id]
    );
}

function hospital_create(array $data) {
    db_execute(
        "INSERT INTO hospitals (name, cnpj, address, phone, email, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())",
        [$data['name'], $data['cnpj'], $data['address'], $data['phone'], $data['email']]
    );
    cache_forget('hospitals:active');
    return (int) db_last_id();
}

function hospital_update($id, array $data) {
    $r = db_execute(
        "UPDATE hospitals SET name=?, cnpj=?, address=?, phone=?, email=?, is_active=?, updated_at=NOW()
         WHERE id = ? AND deleted_at IS NULL",
        [$data['name'], $data['cnpj'], $data['address'], $data['phone'],
         $data['email'], (int) $data['is_active'], $id]
    );
    cache_forget('hospitals:active');
    return $r;
}

function hospital_soft_delete($id) {
    $r = db_execute("UPDATE hospitals SET deleted_at = NOW() WHERE id = ?", [$id]);
    cache_forget('hospitals:active');
    return $r;
}
