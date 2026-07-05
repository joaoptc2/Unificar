<?php
/**
 * Model: Usuários
 */

function user_find_by_email($email, $hospital_id) {
    return db_query_one(
        "SELECT u.*, h.name AS hospital_name
         FROM users u
         JOIN hospitals h ON h.id = u.hospital_id
         WHERE u.email = ?
           AND u.hospital_id = ?
           AND u.deleted_at IS NULL
           AND u.is_active = 1
           AND h.deleted_at IS NULL
           AND h.is_active = 1",
        [$email, (int) $hospital_id]
    );
}

function user_find_by_email_any($email) {
    return db_query_one(
        "SELECT u.*, h.name AS hospital_name
         FROM users u
         JOIN hospitals h ON h.id = u.hospital_id
         WHERE u.email = ? AND u.deleted_at IS NULL AND u.is_active = 1
         LIMIT 1",
        [$email]
    );
}

function user_find($id) {
    return db_query_one(
        "SELECT u.*, h.name AS hospital_name
         FROM users u
         JOIN hospitals h ON h.id = u.hospital_id
         WHERE u.id = ? AND u.deleted_at IS NULL",
        [(int) $id]
    );
}

function user_list($hospital_id = null, $search = '', $limit = 20, $offset = 0) {
    $sql = "SELECT u.id, u.hospital_id, u.name, u.email, u.role_id, u.is_active,
                   u.last_login, u.created_at, h.name AS hospital_name
            FROM users u
            JOIN hospitals h ON h.id = u.hospital_id
            WHERE u.deleted_at IS NULL";
    $params = [];
    if ($hospital_id !== null) {
        $sql .= " AND u.hospital_id = ?";
        $params[] = (int) $hospital_id;
    }
    if ($search !== '') {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    $sql .= " ORDER BY h.name, u.name LIMIT ? OFFSET ?";
    $params[] = (int) $limit; $params[] = (int) $offset;
    return db_query($sql, $params);
}

function user_count($hospital_id = null, $search = '') {
    $sql = "SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL";
    $params = [];
    if ($hospital_id !== null) { $sql .= " AND hospital_id = ?"; $params[] = (int) $hospital_id; }
    if ($search !== '') {
        $sql .= " AND (name LIKE ? OR email LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    $row = db_query_one($sql, $params);
    return (int) ($row['total'] ?? 0);
}

function user_email_exists($email, $hospital_id, $exclude_id = null) {
    $sql = "SELECT id FROM users WHERE email = ? AND hospital_id = ? AND deleted_at IS NULL";
    $params = [$email, $hospital_id];
    if ($exclude_id) { $sql .= " AND id <> ?"; $params[] = $exclude_id; }
    $row = db_query_one($sql, $params);
    return !empty($row);
}

function user_create($hospital_id, $name, $email, $password_hash, $role_id, $force_password_change = true) {
    db_execute(
        "INSERT INTO users
            (hospital_id, name, email, password, role_id, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())",
        [$hospital_id, $name, $email, $password_hash, $role_id, $force_password_change ? 1 : 0]
    );
    return (int) db_last_id();
}

function user_update($id, array $data) {
    $sets = [];
    $params = [];
    foreach (['name', 'email', 'role_id', 'is_active'] as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "$f = ?";
            $params[] = $data[$f];
        }
    }
    if (empty($sets)) return 0;
    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    return db_execute("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?", $params);
}

function user_set_password($id, $password_hash, $force_change = false) {
    return db_execute(
        "UPDATE users SET password = ?, force_password_change = ?, updated_at = NOW()
         WHERE id = ?",
        [$password_hash, $force_change ? 1 : 0, $id]
    );
}

function user_touch_last_login($id) {
    return db_execute("UPDATE users SET last_login = NOW() WHERE id = ?", [$id]);
}

function user_soft_delete($id, $restrict_to_hospital_id = null) {
    if ($restrict_to_hospital_id) {
        return db_execute(
            "UPDATE users SET deleted_at = NOW() WHERE id = ? AND hospital_id = ?",
            [$id, $restrict_to_hospital_id]
        );
    }
    return db_execute("UPDATE users SET deleted_at = NOW() WHERE id = ?", [$id]);
}

/**
 * Gestores + admins ativos de um hospital (para envio de notificações).
 */
function user_managers_of($hospital_id) {
    return db_query(
        "SELECT id, name, email FROM users
         WHERE hospital_id = ? AND role_id <= 2 AND is_active = 1 AND deleted_at IS NULL",
        [$hospital_id]
    );
}
