<?php
/**
 * Model: Notificações
 */

function notification_list($hospital_id, $user_id, $filter = 'all', $limit = 20, $offset = 0) {
    $sql = "SELECT id, title, message, type, is_read, read_at, created_at
            FROM notifications
            WHERE hospital_id = ? AND user_id = ? AND deleted_at IS NULL";
    $params = [$hospital_id, $user_id];

    if ($filter === 'unread') $sql .= " AND is_read = 0";
    elseif ($filter === 'read') $sql .= " AND is_read = 1";

    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = (int) $limit; $params[] = (int) $offset;
    return db_query($sql, $params);
}

function notification_count($hospital_id, $user_id, $filter = 'all') {
    $sql = "SELECT COUNT(*) AS total FROM notifications
            WHERE hospital_id = ? AND user_id = ? AND deleted_at IS NULL";
    $params = [$hospital_id, $user_id];
    if ($filter === 'unread') $sql .= " AND is_read = 0";
    elseif ($filter === 'read') $sql .= " AND is_read = 1";
    $row = db_query_one($sql, $params);
    return (int) ($row['total'] ?? 0);
}

function notification_unread_count($hospital_id, $user_id) {
    return notification_count($hospital_id, $user_id, 'unread');
}

function notification_recent($hospital_id, $user_id, $limit = 5) {
    return db_query(
        "SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE hospital_id = ? AND user_id = ? AND deleted_at IS NULL
         ORDER BY created_at DESC LIMIT ?",
        [$hospital_id, $user_id, (int) $limit]
    );
}

function notification_create($hospital_id, $user_id, $title, $message, $type = 'info') {
    db_execute(
        "INSERT INTO notifications (hospital_id, user_id, title, message, type, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$hospital_id, $user_id, $title, $message, $type]
    );
    return (int) db_last_id();
}

function notification_mark_read($id, $user_id, $hospital_id) {
    return db_execute(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE id = ? AND user_id = ? AND hospital_id = ? AND is_read = 0",
        [$id, $user_id, $hospital_id]
    );
}

function notification_mark_all_read($user_id, $hospital_id) {
    return db_execute(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE user_id = ? AND hospital_id = ? AND is_read = 0",
        [$user_id, $hospital_id]
    );
}

function notification_soft_delete($id, $user_id, $hospital_id) {
    return db_execute(
        "UPDATE notifications SET deleted_at = NOW()
         WHERE id = ? AND user_id = ? AND hospital_id = ?",
        [$id, $user_id, $hospital_id]
    );
}
