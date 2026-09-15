<?php
/**
 * Model: Documentos
 *
 * Documentos CONTROLADOS (is_controlled = 1): workflow de aprovação, validade,
 * revisão periódica, ciência digital, versionamento e avisos de vencimento.
 * Documentos NÃO CONTROLADOS (is_controlled = 0): apenas armazenados para
 * acesso fácil (sem validade obrigatória, sem workflow — status 'approved').
 *
 * Origem (source): 'upload' (arquivo enviado) ou 'editor' (escrito no sistema
 * com os layouts do hospital — content_html/cover_html/layout_id/
 * cover_layout_id/font_family/font_size, copiados para cada versão).
 *
 * Filtro por setor: $sector_id > 0 restringe ao setor; 0 = sem filtro.
 */

function document_hydrate($row) {
    if (!is_array($row)) return $row;
    $defaults = [
        'status' => 'approved', 'approved_by' => null, 'approved_at' => null,
        'review_interval_months' => 12, 'last_reviewed_at' => null, 'next_review_date' => null,
        'document_code' => null, 'issuing_body' => null, 'legal_basis' => null,
        'confidentiality' => 'internal', 'current_version' => 1, 'sector_id' => null,
        'is_controlled' => 1, 'source' => 'upload', 'content_html' => null, 'cover_html' => null,
        'layout_id' => null, 'cover_layout_id' => null, 'font_family' => null, 'font_size' => null,
        'expiration_date' => null,
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $row)) $row[$k] = $v;
    }
    return $row;
}

/** Documento escrito no sistema (editor)? */
function document_is_editor($document) {
    return is_array($document) && ($document['source'] ?? 'upload') === 'editor';
}

/**
 * WHERE das listagens.
 * @param int|null $is_controlled 1 = controlados, 0 = não controlados, null = ambos
 */
function document_filter_where($hospital_id, $filter, $search, $category, $status = '', $sector_id = 0, $is_controlled = 1) {
    $sql    = " WHERE hospital_id = ? AND deleted_at IS NULL";
    $params = [(int) $hospital_id];

    if ($is_controlled !== null) {
        $sql .= " AND is_controlled = ?";
        $params[] = (int) $is_controlled;
    }
    // Escopo de setor do usuário + setor em foco (models/sector.php).
    [$ssql, $sparams] = doc_sector_where('', $sector_id);
    $sql .= $ssql;
    $params = array_merge($params, $sparams);

    if ($filter === 'expired') {
        $sql .= " AND expiration_date IS NOT NULL AND expiration_date < CURDATE()";
    } elseif ($filter === 'expiring') {
        $sql .= " AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $params[] = NOTIFY_DAYS_BEFORE;
    } elseif ($filter === 'valid') {
        $sql .= " AND expiration_date > DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $params[] = NOTIFY_DAYS_BEFORE;
    } elseif ($filter === 'review_pending') {
        $sql .= " AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()";
    }

    if ($status !== '') {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    if ($search !== '') {
        $sql .= " AND (title LIKE ? OR responsible LIKE ? OR category LIKE ? OR document_code LIKE ?)";
        $like = "%$search%";
        array_push($params, $like, $like, $like, $like);
    }

    if ($category !== '') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    return [$sql, $params];
}

function document_count($hospital_id, $filter = 'all', $search = '', $category = '', $status = '', $sector_id = 0, $is_controlled = 1) {
    [$where, $params] = document_filter_where($hospital_id, $filter, $search, $category, $status, $sector_id, $is_controlled);
    $row = db_query_one("SELECT COUNT(*) AS total FROM doc_documents" . $where, $params);
    return (int) ($row['total'] ?? 0);
}

function document_list($hospital_id, $filter = 'all', $search = '', $category = '', $status = '', $limit = 20, $offset = 0, $sector_id = 0, $is_controlled = 1) {
    [$where, $params] = document_filter_where($hospital_id, $filter, $search, $category, $status, $sector_id, $is_controlled);
    $params[] = (int) $limit;
    $params[] = (int) $offset;
    $order = (int) $is_controlled === 0 && $is_controlled !== null
        ? " ORDER BY title ASC"
        : " ORDER BY (expiration_date IS NULL), expiration_date ASC, title ASC";
    $rows = db_query(
        "SELECT doc_documents.*,
                (SELECT s.name FROM doc_sectors s WHERE s.id = doc_documents.sector_id) AS sector_name
         FROM doc_documents" . $where . $order . " LIMIT ? OFFSET ?",
        $params
    );
    return array_map('document_hydrate', $rows);
}

function document_find($id, $hospital_id) {
    $row = db_query_one(
        "SELECT d.*, u.name AS created_by_name, ua.name AS approved_by_name, s.name AS sector_name
         FROM doc_documents d
         LEFT JOIN users u  ON u.id  = d.created_by
         LEFT JOIN users ua ON ua.id = d.approved_by
         LEFT JOIN doc_sectors s ON s.id = d.sector_id
         WHERE d.id = ? AND d.hospital_id = ? AND d.deleted_at IS NULL",
        [(int) $id, (int) $hospital_id]
    );
    // Setor fora do escopo do usuário: some como se não existisse. Sem isto,
    // o id na URL contornaria a independência entre setores.
    if ($row && !doc_sector_allowed($row['sector_id'] ?? 0)) {
        return null;
    }
    return document_hydrate($row);
}

function document_distinct_categories($hospital_id, $is_controlled = null) {
    $sql = "SELECT DISTINCT category FROM doc_documents
            WHERE hospital_id = ? AND deleted_at IS NULL AND category <> ''";
    $params = [(int) $hospital_id];
    if ($is_controlled !== null) {
        $sql .= " AND is_controlled = ?";
        $params[] = (int) $is_controlled;
    }
    return db_query($sql . " ORDER BY category", $params);
}

/** Colunas opcionais/de negócio aceitas em create/update (além das básicas). */
function _document_extra_columns() {
    return ['status', 'document_code', 'issuing_body', 'legal_basis', 'confidentiality',
            'review_interval_months', 'next_review_date', 'sector_id', 'is_controlled',
            'source', 'content_html', 'cover_html', 'layout_id', 'cover_layout_id',
            'font_family', 'font_size'];
}

function document_create($hospital_id, array $data, ?array $file_data = null, $created_by = null) {
    $cols = ['hospital_id','title','category','responsible','expiration_date','notify_days_before',
             'observations','file_name','file_path','file_size','file_type','mime_type','created_by'];
    $vals = [
        $hospital_id, $data['title'], $data['category'], $data['responsible'],
        $data['expiration_date'] ?: null, $data['notify_days'], $data['observations'],
        $file_data['original_name'] ?? null, $file_data['saved_name'] ?? null,
        $file_data['file_size'] ?? null, $file_data['file_type'] ?? null,
        $file_data['mime_type'] ?? null, $created_by,
    ];

    foreach (_document_extra_columns() as $c) {
        if (array_key_exists($c, $data)) {
            $cols[] = $c;
            $vals[] = $data[$c];
        }
    }

    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $cl = '`' . implode('`, `', $cols) . '`';
    db_execute("INSERT INTO doc_documents ($cl, created_at, updated_at) VALUES ($ph, NOW(), NOW())", $vals);
    $id = (int) db_last_id();

    document_history_log($id, 'created', [
        'to_status' => (string) ($data['status'] ?? 'approved'),
        'version'   => 1,
        'cycle'     => 1,
        'summary'   => 'Documento cadastrado',
        'user_id'   => $created_by,
    ]);
    return $id;
}

/**
 * Campos cuja alteração o histórico descreve por nome (o resto entra só
 * como "documento editado"). São os que importam numa auditoria.
 */
function _document_tracked_fields() {
    return [
        'title'                  => 'Título',
        'category'               => 'Categoria',
        'responsible'            => 'Responsável',
        'expiration_date'        => 'Validade',
        'notify_days'            => 'Aviso (dias)',
        'observations'           => 'Observações',
        'sector_id'              => 'Setor',
        'document_code'          => 'Código',
        'confidentiality'        => 'Confidencialidade',
        'review_interval_months' => 'Intervalo de revisão',
        'issuing_body'           => 'Órgão emissor',
        'legal_basis'            => 'Base legal',
        'is_controlled'          => 'Controlado',
        'content_html'           => 'Conteúdo do documento',
        'cover_html'             => 'Capa',
        'layout_id'              => 'Layout',
        'cover_layout_id'        => 'Layout da capa',
        'font_family'            => 'Fonte',
        'font_size'              => 'Tamanho da fonte',
    ];
}

/** Compara o documento antes/depois e descreve o que mudou. */
function _document_diff(array $antes, array $data, $trocou_arquivo = false) {
    $mudou = [];
    foreach (_document_tracked_fields() as $campo => $rotulo) {
        $col = $campo === 'notify_days' ? 'notify_days_before' : $campo;
        if (!array_key_exists($campo, $data) || !array_key_exists($col, $antes)) continue;
        $de   = (string) ($antes[$col] ?? '');
        $para = (string) ($data[$campo] ?? '');
        if ($de === $para) continue;
        // Conteúdo longo: registra que mudou, não o texto inteiro.
        $longo = in_array($campo, ['content_html', 'cover_html', 'observations'], true);
        $mudou[$rotulo] = $longo
            ? ['de' => mb_strlen($de) . ' caracteres', 'para' => mb_strlen($para) . ' caracteres']
            : ['de' => mb_substr($de, 0, 120), 'para' => mb_substr($para, 0, 120)];
    }
    if ($trocou_arquivo) {
        $mudou['Arquivo'] = ['de' => (string) ($antes['file_name'] ?? '—'), 'para' => 'novo arquivo enviado'];
    }
    return $mudou;
}

function document_update($id, $hospital_id, array $data, ?array $file_data = null) {
    $sets = ['title = ?','category = ?','responsible = ?','expiration_date = ?',
             'notify_days_before = ?','observations = ?'];
    $params = [$data['title'],$data['category'],$data['responsible'],
               $data['expiration_date'] ?: null,$data['notify_days'],$data['observations']];

    if ($file_data) {
        $sets = array_merge($sets, ['file_name = ?','file_path = ?','file_size = ?','file_type = ?','mime_type = ?']);
        $params = array_merge($params, [
            $file_data['original_name'], $file_data['saved_name'],
            $file_data['file_size'], $file_data['file_type'], $file_data['mime_type'],
        ]);
    } elseif (($data['source'] ?? null) === 'editor') {
        // Passou a ser escrito no sistema: o arquivo antigo deixa de valer
        $sets = array_merge($sets, ['file_name = NULL','file_path = NULL','file_size = NULL','file_type = NULL','mime_type = NULL']);
    }

    foreach (_document_extra_columns() as $c) {
        if (array_key_exists($c, $data)) {
            $sets[] = "$c = ?";
            $params[] = $data[$c];
        }
    }

    $sets[] = 'updated_at = NOW()';
    $params[] = (int) $id;
    $params[] = (int) $hospital_id;

    // O "antes" é lido sem passar pelo escopo de setor: aqui a permissão já
    // foi verificada pelo controller e o objetivo é só descrever a mudança.
    $antes = db_query_one("SELECT * FROM doc_documents WHERE id = ? AND hospital_id = ?",
                          [(int) $id, (int) $hospital_id]) ?: [];

    $r = db_execute("UPDATE doc_documents SET " . implode(', ', $sets) . " WHERE id = ? AND hospital_id = ?", $params);

    $mudou = $antes ? _document_diff($antes, $data, $file_data !== null) : [];
    if ($mudou !== []) {
        document_history_log($id, 'updated', [
            'from_status' => $antes['status'] ?? null,
            'to_status'   => $antes['status'] ?? null,
            'version'     => (int) ($antes['current_version'] ?? 1),
            'summary'     => 'Documento editado: ' . implode(', ', array_keys($mudou)),
            'details'     => $mudou,
        ]);
    }
    return $r;
}

function document_soft_delete($id, $hospital_id) {
    document_history_log($id, 'deleted', ['summary' => 'Documento removido']);
    return db_execute(
        "UPDATE doc_documents SET deleted_at = NOW() WHERE id = ? AND hospital_id = ? AND deleted_at IS NULL",
        [(int) $id, (int) $hospital_id]
    );
}

// ─── Workflow de aprovação ──────────────────────────────────────────────────

/**
 * Muda o status do documento e, por consequência, REDEFINE as confirmações
 * de leitura: um documento que foi para revisão ou voltou a ser aprovado não
 * é mais o mesmo texto que as pessoas leram. O histórico das ciências
 * anteriores permanece (ver document_reset_acknowledgments).
 *
 * Passe $reset_acks = false apenas quando o status já veio de um fluxo que
 * cuidou disso (para não incrementar o ciclo duas vezes pela mesma mudança).
 */
function document_set_status($id, $hospital_id, $status, $user_id = null, $reset_acks = true) {
    $antes = db_query_one(
        "SELECT status, current_version FROM doc_documents WHERE id = ? AND hospital_id = ?",
        [(int) $id, (int) $hospital_id]
    );
    $de = (string) ($antes['status'] ?? '');

    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];
    if ($status === 'approved') {
        $sets[] = 'approved_by = ?';
        $sets[] = 'approved_at = NOW()';
        $params[] = $user_id;
    }
    $params[] = (int) $id;
    $params[] = (int) $hospital_id;
    $r = db_execute("UPDATE doc_documents SET " . implode(', ', $sets) . " WHERE id = ? AND hospital_id = ?", $params);

    // Status igual ao anterior não é mudança: não zera ciência nem polui a
    // linha do tempo (acontece ao salvar o formulário sem mexer no status).
    if ($de === (string) $status) {
        return $r;
    }

    document_history_log($id, 'status', [
        'from_status' => $de,
        'to_status'   => (string) $status,
        'version'     => (int) ($antes['current_version'] ?? 1),
        'summary'     => 'Status: ' . document_status_label($de) . ' → ' . document_status_label($status),
        'user_id'     => $user_id,
    ]);

    if ($reset_acks) {
        document_reset_acknowledgments(
            $id, $hospital_id,
            'status alterado para ' . document_status_label($status),
            $user_id
        );
    }
    return $r;
}

/** Rótulo do status em português (para o histórico e as telas). */
function document_status_label($status) {
    $map = [
        'draft'          => 'Rascunho',
        'pending_review' => 'Em revisão',
        'approved'       => 'Aprovado',
        'expired'        => 'Vencido',
        'archived'       => 'Arquivado',
    ];
    return $map[(string) $status] ?? (string) $status;
}

function document_mark_reviewed($id, $hospital_id, $interval_months = 12) {
    document_history_log($id, 'reviewed', [
        'summary' => 'Revisão registrada — próxima em ' . (int) $interval_months . ' mês(es)',
    ]);
    return db_execute(
        "UPDATE doc_documents SET last_reviewed_at = NOW(),
                next_review_date = DATE_ADD(CURDATE(), INTERVAL ? MONTH),
                updated_at = NOW()
         WHERE id = ? AND hospital_id = ?",
        [(int) $interval_months, (int) $id, (int) $hospital_id]
    );
}

// ─── Estatísticas (somente documentos CONTROLADOS) ──────────────────────────

/** Fragmento SQL + params do filtro de setor (0 = sem filtro). */
function _document_sector_sql($sector_id, $alias = '') {
    return doc_sector_where($alias, $sector_id);
}

function document_stats($hospital_id, $sector_id = 0) {
    $stats = ['total' => 0, 'expired' => 0, 'expiring' => 0, 'valid' => 0,
              'draft' => 0, 'pending_review' => 0, 'review_overdue' => 0, 'uncontrolled' => 0];
    [$ssql, $sparams] = _document_sector_sql($sector_id);

    $r = db_query_one(
        "SELECT COUNT(*) AS total,
            SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS expiring,
            SUM(CASE WHEN expiration_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS valid,
            SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS draft,
            SUM(CASE WHEN status='pending_review' THEN 1 ELSE 0 END) AS pending_review,
            SUM(CASE WHEN next_review_date IS NOT NULL AND next_review_date <= CURDATE() THEN 1 ELSE 0 END) AS review_overdue
         FROM doc_documents WHERE hospital_id = ? AND deleted_at IS NULL AND is_controlled = 1" . $ssql,
        array_merge([NOTIFY_DAYS_BEFORE, NOTIFY_DAYS_BEFORE, (int) $hospital_id], $sparams)
    );
    if ($r) {
        foreach (['total','expired','expiring','valid','draft','pending_review','review_overdue'] as $k) {
            $stats[$k] = (int) ($r[$k] ?? 0);
        }
    }
    $u = db_query_one(
        "SELECT COUNT(*) AS c FROM doc_documents WHERE hospital_id = ? AND deleted_at IS NULL AND is_controlled = 0" . $ssql,
        array_merge([(int) $hospital_id], $sparams)
    );
    $stats['uncontrolled'] = (int) ($u['c'] ?? 0);
    return $stats;
}

function document_expiring_list($hospital_id, $limit = 10, $sector_id = 0) {
    [$ssql, $sparams] = _document_sector_sql($sector_id);
    return db_query(
        "SELECT id, title, category, expiration_date FROM doc_documents
         WHERE hospital_id = ? AND deleted_at IS NULL AND is_controlled = 1
           AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)" . $ssql . "
         ORDER BY expiration_date ASC LIMIT ?",
        array_merge([(int) $hospital_id, NOTIFY_DAYS_BEFORE], $sparams, [(int) $limit])
    );
}

function document_expired_list($hospital_id, $limit = 10, $sector_id = 0) {
    [$ssql, $sparams] = _document_sector_sql($sector_id);
    return db_query(
        "SELECT id, title, category, expiration_date FROM doc_documents
         WHERE hospital_id = ? AND deleted_at IS NULL AND is_controlled = 1
           AND expiration_date IS NOT NULL AND expiration_date < CURDATE()" . $ssql . "
         ORDER BY expiration_date DESC LIMIT ?",
        array_merge([(int) $hospital_id], $sparams, [(int) $limit])
    );
}

function document_review_pending_list($hospital_id, $limit = 10, $sector_id = 0) {
    [$ssql, $sparams] = _document_sector_sql($sector_id);
    return db_query(
        "SELECT id, title, category, next_review_date FROM doc_documents
         WHERE hospital_id = ? AND deleted_at IS NULL AND is_controlled = 1
           AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()" . $ssql . "
         ORDER BY next_review_date ASC LIMIT ?",
        array_merge([(int) $hospital_id], $sparams, [(int) $limit])
    );
}

// ─── Versionamento ──────────────────────────────────────────────────────────

/**
 * Guarda o estado ATUAL do documento como versão histórica (arquivo e/ou
 * conteúdo do editor + layouts + fonte).
 */
function document_save_version($document, $notes = '', $user_id = null) {
    $version = (int) ($document['current_version'] ?? 1);
    db_execute(
        "INSERT INTO doc_document_versions
            (document_id, version, file_name, file_path, file_size, file_type, mime_type,
             content_html, cover_html, layout_id, cover_layout_id, font_family, font_size,
             expiration_date, notes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            file_name = VALUES(file_name), file_path = VALUES(file_path), file_size = VALUES(file_size),
            file_type = VALUES(file_type), mime_type = VALUES(mime_type),
            content_html = VALUES(content_html), cover_html = VALUES(cover_html),
            layout_id = VALUES(layout_id), cover_layout_id = VALUES(cover_layout_id),
            font_family = VALUES(font_family), font_size = VALUES(font_size),
            expiration_date = VALUES(expiration_date), notes = VALUES(notes),
            created_by = VALUES(created_by), created_at = NOW()",
        [$document['id'], $version, $document['file_name'], $document['file_path'],
         $document['file_size'], $document['file_type'], $document['mime_type'] ?? null,
         $document['content_html'] ?? null, $document['cover_html'] ?? null,
         $document['layout_id'] ?? null, $document['cover_layout_id'] ?? null,
         $document['font_family'] ?? null, $document['font_size'] ?? null,
         $document['expiration_date'] ?: null, $notes, $user_id]
    );
    return (int) db_last_id();
}

/**
 * Nova versão do documento: incrementa o número e redefine as confirmações
 * de leitura — quem leu a versão anterior não leu esta.
 */
function document_bump_version($id, $hospital_id) {
    db_execute("UPDATE doc_documents SET current_version = current_version + 1 WHERE id = ? AND hospital_id = ?",
        [(int) $id, (int) $hospital_id]);
    $row = db_query_one("SELECT current_version FROM doc_documents WHERE id = ?", [(int) $id]);
    $v   = (int) ($row['current_version'] ?? 1);

    document_history_log($id, 'version', [
        'version' => $v,
        'summary' => 'Nova versão do documento (v' . $v . ')',
    ]);
    document_reset_acknowledgments($id, $hospital_id, 'nova versão do documento (v' . $v . ')');
}

function document_versions($document_id) {
    try {
        return db_query(
            "SELECT dv.*, u.name AS created_by_name FROM doc_document_versions dv
             LEFT JOIN users u ON u.id = dv.created_by
             WHERE dv.document_id = ? ORDER BY dv.version DESC",
            [(int) $document_id]);
    } catch (Exception $ex) { return []; }
}

function document_version_find($version_id, $document_id) {
    return db_query_one(
        "SELECT * FROM doc_document_versions WHERE id = ? AND document_id = ?",
        [(int) $version_id, (int) $document_id]);
}

/** Versão pelo NÚMERO (v1, v2...) — usada em documents/print/{id}?version=N. */
function document_version_by_number($document_id, $version) {
    return db_query_one(
        "SELECT * FROM doc_document_versions WHERE document_id = ? AND version = ?",
        [(int) $document_id, (int) $version]);
}

// ─── Ciência digital ────────────────────────────────────────────────────────
//
// A confirmação de leitura vale para UM ciclo. Toda mudança de status do
// documento (e toda versão nova) incrementa doc_documents.ack_cycle: as
// confirmações do ciclo anterior deixam de valer — todo mundo precisa ler e
// confirmar de novo — mas continuam gravadas, com o ciclo, a versão e o
// status em que foram dadas. Apagá-las seria perder exatamente a prova que a
// acreditação pede: quem leu o quê, quando, e em qual versão.

/** Ciclo de ciência corrente do documento (1 quando a coluna não existe). */
function document_ack_cycle($document) {
    if (is_array($document)) {
        return max(1, (int) ($document['ack_cycle'] ?? 1));
    }
    if (!db_has_column('doc_documents', 'ack_cycle')) return 1;
    $row = db_query_one("SELECT ack_cycle FROM doc_documents WHERE id = ?", [(int) $document]);
    return max(1, (int) ($row['ack_cycle'] ?? 1));
}

function document_acknowledge($document_id, $user_id, $ip = null) {
    try {
        $doc = db_query_one(
            "SELECT id, status, current_version" . (db_has_column('doc_documents', 'ack_cycle') ? ', ack_cycle' : '')
            . " FROM doc_documents WHERE id = ?",
            [(int) $document_id]
        );
        if (!$doc) return false;

        $cols = ['document_id', 'user_id', 'ip_address'];
        $vals = [(int) $document_id, (int) $user_id, $ip];
        if (db_has_column('doc_document_acknowledgments', 'cycle')) {
            $cols[] = 'cycle';             $vals[] = document_ack_cycle($doc);
            $cols[] = 'document_version';  $vals[] = (int) ($doc['current_version'] ?? 1);
            $cols[] = 'document_status';   $vals[] = (string) ($doc['status'] ?? '');
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        db_execute("INSERT IGNORE INTO doc_document_acknowledgments (" . implode(',', $cols) . ") VALUES ($ph)", $vals);

        document_history_log($document_id, 'acknowledged', [
            'cycle'   => document_ack_cycle($doc),
            'version' => (int) ($doc['current_version'] ?? 1),
            'summary' => 'Ciência registrada',
            'user_id' => (int) $user_id,
        ]);
        return true;
    } catch (Exception $ex) { return false; }
}

/**
 * Confirmações do ciclo CORRENTE (as que valem hoje).
 * $document pode ser o id ou a linha já carregada.
 */
function document_acknowledgment_status($document) {
    try {
        $id    = is_array($document) ? (int) $document['id'] : (int) $document;
        $cycle = document_ack_cycle($document);
        $sql   = "SELECT da.user_id, da.acknowledged_at, da.document_version, u.name AS user_name, u.email
                  FROM doc_document_acknowledgments da
                  JOIN users u ON u.id = da.user_id
                  WHERE da.document_id = ?";
        $params = [$id];
        if (db_has_column('doc_document_acknowledgments', 'cycle')) {
            $sql .= " AND da.cycle = ?";
            $params[] = $cycle;
        }
        $acks = db_query($sql . " ORDER BY da.acknowledged_at DESC", $params);
        return ['acknowledged' => $acks, 'cycle' => $cycle];
    } catch (Exception $ex) { return ['acknowledged' => [], 'cycle' => 1]; }
}

/**
 * Histórico COMPLETO das ciências, agrupado por ciclo (mais recente
 * primeiro). É o que a auditoria consulta: "quem confirmou a versão 2?".
 * @return array<int, array{cycle:int, corrente:bool, acks:array}>
 */
function document_acknowledgment_history($document) {
    try {
        $id    = is_array($document) ? (int) $document['id'] : (int) $document;
        $atual = document_ack_cycle($document);
        if (!db_has_column('doc_document_acknowledgments', 'cycle')) {
            $r = document_acknowledgment_status($document);
            return [['cycle' => 1, 'corrente' => true, 'acks' => $r['acknowledged']]];
        }
        $rows = db_query(
            "SELECT da.cycle, da.user_id, da.acknowledged_at, da.document_version, da.document_status,
                    u.name AS user_name, u.email
             FROM doc_document_acknowledgments da
             JOIN users u ON u.id = da.user_id
             WHERE da.document_id = ?
             ORDER BY da.cycle DESC, da.acknowledged_at DESC",
            [$id]
        );
        $por = [];
        foreach ($rows as $r) {
            $por[(int) $r['cycle']][] = $r;
        }
        $out = [];
        foreach ($por as $cycle => $acks) {
            $out[] = ['cycle' => $cycle, 'corrente' => $cycle === $atual, 'acks' => $acks];
        }
        return $out;
    } catch (Exception $ex) { return []; }
}

function document_user_acknowledged($document, $user_id) {
    $id    = is_array($document) ? (int) $document['id'] : (int) $document;
    $sql   = "SELECT id FROM doc_document_acknowledgments WHERE document_id = ? AND user_id = ?";
    $params = [$id, (int) $user_id];
    if (db_has_column('doc_document_acknowledgments', 'cycle')) {
        $sql .= " AND cycle = ?";
        $params[] = document_ack_cycle($document);
    }
    return !empty(db_query_one($sql, $params));
}

/**
 * Redefine as confirmações de leitura: incrementa o ciclo. Nada é apagado —
 * as confirmações do ciclo anterior viram histórico.
 * Devolve o novo ciclo (ou o atual, quando não há o que redefinir).
 */
function document_reset_acknowledgments($id, $hospital_id, $motivo = '', $user_id = null) {
    if (!db_has_column('doc_documents', 'ack_cycle')) return 1;

    $anterior = document_ack_cycle($id);
    db_execute(
        "UPDATE doc_documents SET ack_cycle = ack_cycle + 1, ack_reset_at = NOW(), updated_at = NOW()
         WHERE id = ? AND hospital_id = ?",
        [(int) $id, (int) $hospital_id]
    );
    $novo = document_ack_cycle($id);

    document_history_log($id, 'ack_reset', [
        'cycle'   => $novo,
        'summary' => $motivo !== ''
            ? 'Confirmações de leitura redefinidas — ' . $motivo
            : 'Confirmações de leitura redefinidas',
        'details' => ['ciclo_anterior' => $anterior, 'ciclo_novo' => $novo],
        'user_id' => $user_id,
    ]);
    return $novo;
}

// ─── Histórico de modificações ──────────────────────────────────────────────

/**
 * Registra um evento na linha do tempo do documento. Nunca lança: um
 * histórico que derruba a tela é pior do que um histórico com um buraco.
 *
 * $opts: from_status, to_status, version, cycle, summary, details (array),
 *        user_id (padrão: usuário da sessão).
 */
function document_history_log($document_id, $event, array $opts = []) {
    try {
        if (!db_has_table('doc_document_history')) return 0;
        $uid = $opts['user_id'] ?? (function_exists('get_user_id') ? get_user_id() : null);
        db_execute(
            "INSERT INTO doc_document_history
                (document_id, event, from_status, to_status, version, cycle, summary, details, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $document_id,
                substr((string) $event, 0, 40),
                $opts['from_status'] ?? null,
                $opts['to_status'] ?? null,
                isset($opts['version']) ? (int) $opts['version'] : null,
                isset($opts['cycle']) ? (int) $opts['cycle'] : null,
                isset($opts['summary']) ? mb_substr((string) $opts['summary'], 0, 255) : null,
                isset($opts['details']) && $opts['details'] !== []
                    ? json_encode($opts['details'], JSON_UNESCAPED_UNICODE) : null,
                $uid ? (int) $uid : null,
            ]
        );
        return (int) db_last_id();
    } catch (Exception $ex) { return 0; }
}

/** Linha do tempo do documento, do mais recente para o mais antigo. */
function document_history($document_id, $limit = 200) {
    try {
        if (!db_has_table('doc_document_history')) return [];
        return db_query(
            "SELECT h.*, u.name AS user_name
             FROM doc_document_history h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.document_id = ?
             ORDER BY h.id DESC
             LIMIT " . max(1, (int) $limit),
            [(int) $document_id]
        );
    } catch (Exception $ex) { return []; }
}

// ─── Categorias pré-definidas ───────────────────────────────────────────────

function document_categories_list() {
    try {
        return db_query("SELECT * FROM doc_document_categories WHERE is_active = 1 ORDER BY sort_order, name");
    } catch (Exception $ex) { return []; }
}

/** Todas as categorias (inclusive inativas) — tela de administração. */
function document_categories_all() {
    try {
        return db_query("SELECT * FROM doc_document_categories ORDER BY sort_order, name");
    } catch (Exception $ex) { return []; }
}

function document_category_create($name, $description = null, $icon = null, $sort_order = 0) {
    db_execute(
        "INSERT INTO doc_document_categories (name, description, icon, sort_order, is_active)
         VALUES (?, ?, ?, ?, 1)",
        [$name, $description, $icon, (int) $sort_order]
    );
    return (int) db_last_id();
}

function document_category_update($id, array $data) {
    return db_execute(
        "UPDATE doc_document_categories
         SET name = ?, description = ?, icon = ?, sort_order = ?, is_active = ?
         WHERE id = ?",
        [$data['name'], $data['description'], $data['icon'],
         (int) $data['sort_order'], (int) $data['is_active'], (int) $id]
    );
}

/**
 * Remove a categoria (hard delete — documentos guardam a categoria como
 * texto, então não há FK a violar).
 */
function document_category_delete($id) {
    return db_execute("DELETE FROM doc_document_categories WHERE id = ?", [(int) $id]);
}
