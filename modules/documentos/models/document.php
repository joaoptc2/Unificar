<?php
/**
 * Model: Documentos
 *
 * Inclui: workflow de aprovação, revisão periódica, categorias pré-definidas,
 * ciência digital, metadados extras, versionamento.
 * Todas as funções são tolerantes ao schema antigo (migration 007 opcional).
 */

$_doc_has_status = null;
function _doc_has_workflow() {
    global $_doc_has_status;
    if ($_doc_has_status === null) $_doc_has_status = db_has_column('documents', 'status');
    return $_doc_has_status;
}

function document_hydrate($row) {
    if (!is_array($row)) return $row;
    $defaults = [
        'status' => 'approved', 'approved_by' => null, 'approved_at' => null,
        'review_interval_months' => 12, 'last_reviewed_at' => null, 'next_review_date' => null,
        'document_code' => null, 'issuing_body' => null, 'legal_basis' => null,
        'confidentiality' => 'internal', 'current_version' => 1, 'sector_id' => null,
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $row)) $row[$k] = $v;
    }
    return $row;
}

function document_filter_where($hospital_id, $filter, $search, $category, $status = '') {
    $sql    = " WHERE hospital_id = ? AND deleted_at IS NULL";
    $params = [$hospital_id];

    if ($filter === 'expired') {
        $sql .= " AND expiration_date < CURDATE()";
    } elseif ($filter === 'expiring') {
        $sql .= " AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $params[] = NOTIFY_DAYS_BEFORE;
    } elseif ($filter === 'valid') {
        $sql .= " AND expiration_date > DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $params[] = NOTIFY_DAYS_BEFORE;
    } elseif ($filter === 'review_pending') {
        if (db_has_column('documents', 'next_review_date')) {
            $sql .= " AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()";
        }
    }

    if ($status !== '' && _doc_has_workflow()) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    if ($search !== '') {
        $sql .= " AND (title LIKE ? OR responsible LIKE ? OR category LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($category !== '') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    return [$sql, $params];
}

function document_count($hospital_id, $filter = 'all', $search = '', $category = '', $status = '') {
    [$where, $params] = document_filter_where($hospital_id, $filter, $search, $category, $status);
    $row = db_query_one("SELECT COUNT(*) AS total FROM documents" . $where, $params);
    return (int) ($row['total'] ?? 0);
}

function document_list($hospital_id, $filter = 'all', $search = '', $category = '', $status = '', $limit = 20, $offset = 0) {
    [$where, $params] = document_filter_where($hospital_id, $filter, $search, $category, $status);
    $params[] = (int) $limit;
    $params[] = (int) $offset;
    $rows = db_query(
        "SELECT * FROM documents" . $where . " ORDER BY expiration_date ASC LIMIT ? OFFSET ?",
        $params
    );
    return array_map('document_hydrate', $rows);
}

function document_find($id, $hospital_id) {
    $row = db_query_one(
        "SELECT d.*, u.name AS created_by_name, ua.name AS approved_by_name
         FROM documents d
         LEFT JOIN users u  ON u.id  = d.created_by
         LEFT JOIN users ua ON ua.id = d." . (_doc_has_workflow() ? 'approved_by' : 'created_by') . "
         WHERE d.id = ? AND d.hospital_id = ? AND d.deleted_at IS NULL",
        [(int) $id, (int) $hospital_id]
    );
    return document_hydrate($row);
}

function document_distinct_categories($hospital_id) {
    return db_query(
        "SELECT DISTINCT category FROM documents
         WHERE hospital_id = ? AND deleted_at IS NULL AND category <> ''
         ORDER BY category",
        [(int) $hospital_id]
    );
}

function document_create($hospital_id, array $data, array $file_data = null, $created_by = null) {
    $cols = ['hospital_id','title','category','responsible','expiration_date','notify_days_before',
             'observations','file_name','file_path','file_size','file_type','mime_type','created_by'];
    $vals = [
        $hospital_id, $data['title'], $data['category'], $data['responsible'],
        $data['expiration_date'], $data['notify_days'],  $data['observations'],
        $file_data['original_name'] ?? null, $file_data['saved_name'] ?? null,
        $file_data['file_size'] ?? null, $file_data['file_type'] ?? null,
        $file_data['mime_type'] ?? null, $created_by,
    ];

    $opt_cols = ['status','document_code','issuing_body','legal_basis','confidentiality',
                 'review_interval_months','next_review_date','sector_id'];
    foreach ($opt_cols as $c) {
        if (db_has_column('documents', $c) && array_key_exists($c, $data)) {
            $cols[] = $c;
            $vals[] = $data[$c];
        }
    }

    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $cl = '`' . implode('`, `', $cols) . '`';
    db_execute("INSERT INTO documents ($cl, created_at, updated_at) VALUES ($ph, NOW(), NOW())", $vals);
    return db_last_id();
}

function document_update($id, $hospital_id, array $data, array $file_data = null) {
    $sets = ['title = ?','category = ?','responsible = ?','expiration_date = ?',
             'notify_days_before = ?','observations = ?'];
    $params = [$data['title'],$data['category'],$data['responsible'],
               $data['expiration_date'],$data['notify_days'],$data['observations']];

    if ($file_data) {
        $sets = array_merge($sets, ['file_name = ?','file_path = ?','file_size = ?','file_type = ?','mime_type = ?']);
        $params = array_merge($params, [
            $file_data['original_name'], $file_data['saved_name'],
            $file_data['file_size'], $file_data['file_type'], $file_data['mime_type'],
        ]);
    }

    $opt_cols = ['document_code','issuing_body','legal_basis','confidentiality',
                 'review_interval_months','next_review_date','sector_id'];
    foreach ($opt_cols as $c) {
        if (db_has_column('documents', $c) && array_key_exists($c, $data)) {
            $sets[] = "$c = ?";
            $params[] = $data[$c];
        }
    }

    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    $params[] = $hospital_id;
    return db_execute("UPDATE documents SET " . implode(', ', $sets) . " WHERE id = ? AND hospital_id = ?", $params);
}

function document_soft_delete($id, $hospital_id) {
    return db_execute(
        "UPDATE documents SET deleted_at = NOW() WHERE id = ? AND hospital_id = ? AND deleted_at IS NULL",
        [$id, $hospital_id]
    );
}

// ─── Workflow de aprovação ──────────────────────────────────────────────────

function document_set_status($id, $hospital_id, $status, $user_id = null) {
    if (!_doc_has_workflow()) return 0;
    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];
    if ($status === 'approved') {
        $sets[] = 'approved_by = ?';
        $sets[] = 'approved_at = NOW()';
        $params[] = $user_id;
    }
    $params[] = $id;
    $params[] = $hospital_id;
    return db_execute("UPDATE documents SET " . implode(', ', $sets) . " WHERE id = ? AND hospital_id = ?", $params);
}

function document_mark_reviewed($id, $hospital_id, $interval_months = 12) {
    if (!db_has_column('documents', 'last_reviewed_at')) return 0;
    return db_execute(
        "UPDATE documents SET last_reviewed_at = NOW(),
                next_review_date = DATE_ADD(CURDATE(), INTERVAL ? MONTH),
                updated_at = NOW()
         WHERE id = ? AND hospital_id = ?",
        [$interval_months, $id, $hospital_id]
    );
}

// ─── Estatísticas ───────────────────────────────────────────────────────────

function document_stats($hospital_id) {
    $stats = ['total' => 0, 'expired' => 0, 'expiring' => 0, 'valid' => 0,
              'draft' => 0, 'pending_review' => 0, 'review_overdue' => 0];
    $rows = db_query(
        "SELECT COUNT(*) AS total,
            SUM(CASE WHEN expiration_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS expiring,
            SUM(CASE WHEN expiration_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS valid
         FROM documents WHERE hospital_id = ? AND deleted_at IS NULL",
        [NOTIFY_DAYS_BEFORE, NOTIFY_DAYS_BEFORE, $hospital_id]
    );
    if (!empty($rows[0])) {
        $r = $rows[0];
        $stats['total']    = (int) $r['total'];
        $stats['expired']  = (int) $r['expired'];
        $stats['expiring'] = (int) $r['expiring'];
        $stats['valid']    = (int) $r['valid'];
    }
    if (_doc_has_workflow()) {
        try {
            $r = db_query_one("SELECT
                SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN status='pending_review' THEN 1 ELSE 0 END) AS pending_review
                FROM documents WHERE hospital_id = ? AND deleted_at IS NULL", [$hospital_id]);
            if ($r) {
                $stats['draft'] = (int) ($r['draft'] ?? 0);
                $stats['pending_review'] = (int) ($r['pending_review'] ?? 0);
            }
        } catch (Exception $ex) {}
    }
    if (db_has_column('documents', 'next_review_date')) {
        try {
            $r = db_query_one("SELECT COUNT(*) AS c FROM documents
                WHERE hospital_id = ? AND deleted_at IS NULL
                AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()", [$hospital_id]);
            $stats['review_overdue'] = (int) ($r['c'] ?? 0);
        } catch (Exception $ex) {}
    }
    return $stats;
}

function document_expiring_list($hospital_id, $limit = 10) {
    return db_query(
        "SELECT id, title, category, expiration_date FROM documents
         WHERE hospital_id = ? AND deleted_at IS NULL
           AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY expiration_date ASC LIMIT ?",
        [$hospital_id, NOTIFY_DAYS_BEFORE, $limit]
    );
}

function document_expired_list($hospital_id, $limit = 10) {
    return db_query(
        "SELECT id, title, category, expiration_date FROM documents
         WHERE hospital_id = ? AND deleted_at IS NULL AND expiration_date < CURDATE()
         ORDER BY expiration_date DESC LIMIT ?",
        [$hospital_id, $limit]
    );
}

function document_review_pending_list($hospital_id, $limit = 10) {
    if (!db_has_column('documents', 'next_review_date')) return [];
    return db_query(
        "SELECT id, title, category, next_review_date FROM documents
         WHERE hospital_id = ? AND deleted_at IS NULL
           AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()
         ORDER BY next_review_date ASC LIMIT ?",
        [$hospital_id, $limit]
    );
}

// ─── Versionamento ──────────────────────────────────────────────────────────

function document_save_version($document, $notes = '', $user_id = null) {
    if (!db_has_table('document_versions')) return false;
    $version = (int) ($document['current_version'] ?? 1);
    db_execute(
        "INSERT INTO document_versions
            (document_id, version, file_name, file_path, file_size, file_type, mime_type,
             expiration_date, notes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$document['id'], $version, $document['file_name'], $document['file_path'],
         $document['file_size'], $document['file_type'], $document['mime_type'] ?? null,
         $document['expiration_date'], $notes, $user_id]
    );
    return (int) db_last_id();
}

function document_bump_version($id, $hospital_id) {
    if (!db_has_column('documents', 'current_version')) return;
    db_execute("UPDATE documents SET current_version = current_version + 1 WHERE id = ? AND hospital_id = ?",
        [$id, $hospital_id]);
}

function document_versions($document_id) {
    if (!db_has_table('document_versions')) return [];
    try {
        return db_query(
            "SELECT dv.*, u.name AS created_by_name FROM document_versions dv
             LEFT JOIN users u ON u.id = dv.created_by
             WHERE dv.document_id = ? ORDER BY dv.version DESC",
            [(int) $document_id]);
    } catch (Exception $ex) { return []; }
}

function document_version_find($version_id, $document_id) {
    if (!db_has_table('document_versions')) return null;
    return db_query_one(
        "SELECT * FROM document_versions WHERE id = ? AND document_id = ?",
        [(int) $version_id, (int) $document_id]);
}

// ─── Ciência digital ────────────────────────────────────────────────────────

function document_acknowledge($document_id, $user_id, $ip = null) {
    if (!db_has_table('document_acknowledgments')) return false;
    try {
        db_execute(
            "INSERT IGNORE INTO document_acknowledgments (document_id, user_id, ip_address)
             VALUES (?, ?, ?)",
            [$document_id, $user_id, $ip]
        );
        return true;
    } catch (Exception $ex) { return false; }
}

function document_acknowledgment_status($document_id) {
    if (!db_has_table('document_acknowledgments')) return ['acknowledged' => [], 'total_users' => 0];
    try {
        $acks = db_query(
            "SELECT da.user_id, da.acknowledged_at, u.name AS user_name, u.email
             FROM document_acknowledgments da
             JOIN users u ON u.id = da.user_id
             WHERE da.document_id = ?
             ORDER BY da.acknowledged_at DESC",
            [$document_id]
        );
        return ['acknowledged' => $acks];
    } catch (Exception $ex) { return ['acknowledged' => []]; }
}

function document_user_acknowledged($document_id, $user_id) {
    if (!db_has_table('document_acknowledgments')) return false;
    $row = db_query_one(
        "SELECT id FROM document_acknowledgments WHERE document_id = ? AND user_id = ?",
        [$document_id, $user_id]
    );
    return !empty($row);
}

// ─── Categorias pré-definidas ───────────────────────────────────────────────

function document_categories_list() {
    if (!db_has_table('document_categories')) return [];
    try {
        return db_query("SELECT * FROM document_categories WHERE is_active = 1 ORDER BY sort_order, name");
    } catch (Exception $ex) { return []; }
}
