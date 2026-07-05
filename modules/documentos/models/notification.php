<?php
/**
 * Model: Notificações — tabela GLOBAL `notifications` do núcleo.
 *
 * Todas as gravações usam module = 'documentos'. O estado de leitura é a
 * coluna read_at (as consultas expõem o alias legado is_read para as views).
 * A tabela global não tem soft delete: remover = DELETE.
 */

const DOC_NOTIF_MODULE = 'documentos';

function notification_list($user_id, $filter = 'all', $limit = 20, $offset = 0) {
    $sql = "SELECT id, title, message, type, link, created_at, read_at,
                   (read_at IS NOT NULL) AS is_read
            FROM notifications
            WHERE module = ? AND user_id = ?";
    $params = [DOC_NOTIF_MODULE, (int) $user_id];

    if ($filter === 'unread')    $sql .= " AND read_at IS NULL";
    elseif ($filter === 'read')  $sql .= " AND read_at IS NOT NULL";

    $sql .= " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?";
    $params[] = (int) $limit;
    $params[] = (int) $offset;
    return db_query($sql, $params);
}

function notification_count($user_id, $filter = 'all') {
    $sql = "SELECT COUNT(*) AS total FROM notifications
            WHERE module = ? AND user_id = ?";
    $params = [DOC_NOTIF_MODULE, (int) $user_id];
    if ($filter === 'unread')    $sql .= " AND read_at IS NULL";
    elseif ($filter === 'read')  $sql .= " AND read_at IS NOT NULL";
    $row = db_query_one($sql, $params);
    return (int) ($row['total'] ?? 0);
}

function notification_unread_count($user_id) {
    return notification_count($user_id, 'unread');
}

function notification_recent($user_id, $limit = 5) {
    return db_query(
        "SELECT id, title, message, type, link, created_at, read_at,
                (read_at IS NOT NULL) AS is_read
         FROM notifications
         WHERE module = ? AND user_id = ?
         ORDER BY created_at DESC, id DESC LIMIT ?",
        [DOC_NOTIF_MODULE, (int) $user_id, (int) $limit]
    );
}

function notification_create($user_id, $title, $message, $type = 'info', $link = null) {
    db_execute(
        "INSERT INTO notifications (user_id, module, type, title, message, link)
         VALUES (?, ?, ?, ?, ?, ?)",
        [(int) $user_id, DOC_NOTIF_MODULE, $type, $title, $message, $link]
    );
    return (int) db_last_id();
}

function notification_mark_read($id, $user_id) {
    return db_execute(
        "UPDATE notifications SET read_at = NOW()
         WHERE id = ? AND user_id = ? AND module = ? AND read_at IS NULL",
        [(int) $id, (int) $user_id, DOC_NOTIF_MODULE]
    );
}

function notification_mark_all_read($user_id) {
    return db_execute(
        "UPDATE notifications SET read_at = NOW()
         WHERE user_id = ? AND module = ? AND read_at IS NULL",
        [(int) $user_id, DOC_NOTIF_MODULE]
    );
}

function notification_delete($id, $user_id) {
    return db_execute(
        "DELETE FROM notifications
         WHERE id = ? AND user_id = ? AND module = ?",
        [(int) $id, (int) $user_id, DOC_NOTIF_MODULE]
    );
}
