<?php
/**
 * Controller de Documentos
 * Inclui: workflow aprovação, revisão periódica, ciência digital, metadados.
 */

function documents_index($param = null) {
    require_login();
    $hospital_id = get_hospital_id();

    $filter   = (string) query('filter', 'all');
    $search   = clean(query('search', ''));
    $category = clean(query('category', ''));
    $status   = clean(query('status', ''));

    $total      = 0;
    $documents  = [];
    $categories = [];
    $predefined_categories = [];

    try {
        $total      = document_count($hospital_id, $filter, $search, $category, $status);
        $pagination = paginate($total);
        $documents  = document_list($hospital_id, $filter, $search, $category, $status,
                                    $pagination['per_page'], $pagination['offset']);
        $categories = document_distinct_categories($hospital_id);
        $predefined_categories = document_categories_list();
    } catch (Exception $ex) {
        log_error('documents_index', $ex);
        $pagination = paginate(0);
    }

    view('documents/index', [
        'page_title'  => 'Documentos',
        'documents'   => $documents,
        'categories'  => $categories,
        'predefined_categories' => $predefined_categories,
        'filter'      => $filter,
        'search'      => $search,
        'category'    => $category,
        'status'      => $status,
        'pagination'  => $pagination,
    ]);
}

function documents_create($param = null) {
    require_login();
    $predefined = document_categories_list();
    view('documents/form', [
        'page_title' => 'Novo Documento',
        'document'   => null,
        'editing'    => false,
        'predefined_categories' => $predefined,
    ]);
}

function documents_store($param = null) {
    require_login();
    if (!is_post()) redirect('documents');
    csrf_validate();

    $hospital_id = get_hospital_id();

    $data = _documents_collect_form();

    $errors = [];
    if (empty($data['title']))           $errors[] = 'Título é obrigatório.';
    if (empty($data['category']))        $errors[] = 'Categoria é obrigatória.';
    if (empty($data['expiration_date'])) $errors[] = 'Data de validade é obrigatória.';
    elseif (!strtotime($data['expiration_date'])) $errors[] = 'Data de validade inválida.';

    $file_data = null;
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $upload_errors = validate_upload($_FILES['document_file']);
        if (!empty($upload_errors)) {
            $errors = array_merge($errors, $upload_errors);
        } else {
            $file_data = save_upload($_FILES['document_file'], $hospital_id);
            if (!$file_data) $errors[] = 'Erro ao salvar o arquivo.';
        }
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('documents/create');
    }

    try {
        $id = document_create($hospital_id, $data, $file_data, get_user_id());
        audit_log('document_created', "id=$id, title={$data['title']}");
        set_flash('success', 'Documento cadastrado com sucesso!');
        redirect('documents/view?id=' . $id);
    } catch (Exception $ex) {
        log_error('documents_store', $ex);
        set_flash('error', 'Erro ao salvar documento.');
        redirect('documents/create');
    }
}

function documents_view($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();

    $document = null;
    $versions = [];
    $ack_status = ['acknowledged' => []];
    $user_acked = false;

    try {
        $document = document_find($id, $hospital_id);
        if ($document) {
            $versions   = document_versions($id);
            $ack_status = document_acknowledgment_status($id);
            $user_acked = document_user_acknowledged($id, get_user_id());
        }
    } catch (Exception $ex) {
        log_error('documents_view', $ex);
    }

    if (!$document) {
        set_flash('error', 'Documento não encontrado.');
        redirect('documents');
    }

    view('documents/view', [
        'page_title' => $document['title'],
        'document'   => $document,
        'versions'   => $versions,
        'ack_status' => $ack_status,
        'user_acked' => $user_acked,
    ]);
}

function documents_edit($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();

    $document = null;
    try { $document = document_find($id, $hospital_id); } catch (Exception $ex) { log_error('documents_edit', $ex); }

    if (!$document) {
        set_flash('error', 'Documento não encontrado.');
        redirect('documents');
    }

    view('documents/form', [
        'page_title' => 'Editar Documento',
        'document'   => $document,
        'editing'    => true,
        'predefined_categories' => document_categories_list(),
    ]);
}

function documents_update($param = null) {
    require_login();
    if (!is_post()) redirect('documents');
    csrf_validate();

    $id = sanitize_int(input('id'));
    $hospital_id = get_hospital_id();

    try { $document = document_find($id, $hospital_id); }
    catch (Exception $ex) { log_error('documents_update:find', $ex); $document = null; }

    if (!$document) {
        set_flash('error', 'Documento não encontrado.');
        redirect('documents');
    }

    $data = _documents_collect_form();

    $errors = [];
    if (empty($data['title']))           $errors[] = 'Título é obrigatório.';
    if (empty($data['category']))        $errors[] = 'Categoria é obrigatória.';
    if (empty($data['expiration_date'])) $errors[] = 'Data de validade é obrigatória.';

    $file_data = null;
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $upload_errors = validate_upload($_FILES['document_file']);
        if (!empty($upload_errors)) {
            $errors = array_merge($errors, $upload_errors);
        } else {
            $file_data = save_upload($_FILES['document_file'], $hospital_id);
            if (!$file_data) $errors[] = 'Erro ao salvar o arquivo.';
        }
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('documents/edit?id=' . $id);
    }

    try {
        $version_notes = clean(input('version_notes'));
        $has_changes = $file_data
            || $data['expiration_date'] !== ($document['expiration_date'] ?? '')
            || $data['title'] !== ($document['title'] ?? '');
        if ($has_changes) {
            document_save_version($document, $version_notes, get_user_id());
            document_bump_version($id, $hospital_id);
        }

        document_update($id, $hospital_id, $data, $file_data);
        audit_log('document_updated', "id=$id, title={$data['title']}");
        set_flash('success', 'Documento atualizado com sucesso!');
    } catch (Exception $ex) {
        log_error('documents_update', $ex);
        set_flash('error', 'Erro ao atualizar documento.');
    }

    redirect('documents/view?id=' . $id);
}

function documents_delete($param = null) {
    require_login();
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        document_soft_delete($id, get_hospital_id());
        audit_log('document_deleted', "id=$id");
        set_flash('success', 'Documento removido com sucesso.');
    } catch (Exception $ex) {
        log_error('documents_delete', $ex);
        set_flash('error', 'Erro ao remover documento.');
    }
    redirect('documents');
}

function documents_download($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();
    try { $document = document_find($id, $hospital_id); }
    catch (Exception $ex) { log_error('documents_download:find', $ex); $document = null; }
    if (!$document || empty($document['file_path'])) {
        set_flash('error', 'Arquivo não encontrado.');
        redirect('documents');
    }
    $file_full_path = UPLOADS_PATH . '/hospital_' . $hospital_id . '/' . basename($document['file_path']);
    $mime = !empty($document['mime_type']) ? $document['mime_type'] : 'application/octet-stream';
    audit_log('document_downloaded', "id=$id");
    stream_download($file_full_path, $document['file_name'] ?: $document['file_path'], $mime);
}

function documents_download_version($param = null) {
    require_login();
    $version_id = sanitize_int(query('version_id'));
    $doc_id     = sanitize_int(query('id'));
    $hospital_id = get_hospital_id();
    $document = document_find($doc_id, $hospital_id);
    if (!$document) { set_flash('error', 'Documento não encontrado.'); redirect('documents'); }
    $version = document_version_find($version_id, $doc_id);
    if (!$version || empty($version['file_path'])) {
        set_flash('error', 'Versão não encontrada.');
        redirect('documents/view?id=' . $doc_id);
    }
    $file_full_path = UPLOADS_PATH . '/hospital_' . $hospital_id . '/' . basename($version['file_path']);
    audit_log('document_version_downloaded', "doc=$doc_id, version={$version['version']}");
    stream_download($file_full_path, $version['file_name'] ?: $version['file_path'], $version['mime_type'] ?? 'application/octet-stream');
}

// ─── Workflow: aprovar, rejeitar, submeter ──────────────────────────────────

function documents_submit($param = null) {
    require_login();
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        document_set_status($id, get_hospital_id(), 'pending_review');
        audit_log('document_submitted', "id=$id");
        set_flash('success', 'Documento enviado para revisão.');
    } catch (Exception $ex) {
        log_error('documents_submit', $ex);
        set_flash('error', 'Erro ao enviar para revisão.');
    }
    redirect('documents/view?id=' . $id);
}

function documents_approve($param = null) {
    require_login();
    require_manager();
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        document_set_status($id, get_hospital_id(), 'approved', get_user_id());
        document_mark_reviewed($id, get_hospital_id());
        audit_log('document_approved', "id=$id");
        set_flash('success', 'Documento aprovado.');
    } catch (Exception $ex) {
        log_error('documents_approve', $ex);
        set_flash('error', 'Erro ao aprovar.');
    }
    redirect('documents/view?id=' . $id);
}

function documents_reject($param = null) {
    require_login();
    require_manager();
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        document_set_status($id, get_hospital_id(), 'draft');
        audit_log('document_rejected', "id=$id");
        set_flash('info', 'Documento devolvido para rascunho.');
    } catch (Exception $ex) {
        log_error('documents_reject', $ex);
        set_flash('error', 'Erro ao rejeitar.');
    }
    redirect('documents/view?id=' . $id);
}

function documents_acknowledge($param = null) {
    require_login();
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        document_acknowledge($id, get_user_id(), client_ip());
        audit_log('document_acknowledged', "id=$id");
        set_flash('success', 'Ciência registrada.');
    } catch (Exception $ex) {
        log_error('documents_acknowledge', $ex);
        set_flash('error', 'Erro ao registrar ciência.');
    }
    redirect('documents/view?id=' . $id);
}

function documents_mark_reviewed($param = null) {
    require_login();
    require_manager();
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        $doc = document_find($id, get_hospital_id());
        $interval = (int) ($doc['review_interval_months'] ?? 12);
        document_mark_reviewed($id, get_hospital_id(), $interval);
        audit_log('document_reviewed', "id=$id");
        set_flash('success', 'Revisão registrada. Próxima em ' . $interval . ' meses.');
    } catch (Exception $ex) {
        log_error('documents_mark_reviewed', $ex);
        set_flash('error', 'Erro ao registrar revisão.');
    }
    redirect('documents/view?id=' . $id);
}

function documents_export($param = null) {
    require_login();
    $hospital_id = get_hospital_id();
    $filter   = (string) query('filter', 'all');
    $search   = clean(query('search', ''));
    $category = clean(query('category', ''));
    try {
        $rows = document_list($hospital_id, $filter, $search, $category, '', 10000, 0);
    } catch (Exception $ex) {
        log_error('documents_export', $ex);
        set_flash('error', 'Erro ao exportar.');
        redirect('documents');
    }
    $data = [];
    foreach ($rows as $d) {
        $days = days_until($d['expiration_date']);
        $data[] = [
            'Código'       => $d['document_code'] ?? '',
            'Título'       => $d['title'],
            'Categoria'    => $d['category'],
            'Responsável'  => $d['responsible'],
            'Validade'     => format_date($d['expiration_date']),
            'Status'       => $d['status'] ?? 'approved',
            'Situação'     => expiry_label($days),
            'Arquivo'      => $d['file_name'] ?: '',
            'Cadastrado em'=> format_datetime($d['created_at']),
        ];
    }
    audit_log('documents_exported', 'count=' . count($data));
    csv_response(
        'documentos_' . date('Ymd_His') . '.csv',
        array_keys($data[0] ?? []),
        $data
    );
}

// ─── Helper interno ─────────────────────────────────────────────────────────

function _documents_collect_form() {
    $data = [
        'title'           => clean(input('title')),
        'category'        => clean(input('category')),
        'responsible'     => clean(input('responsible')),
        'expiration_date' => (string) input('expiration_date'),
        'notify_days'     => sanitize_int(input('notify_days', 30)),
        'observations'    => clean(input('observations')),
    ];

    $opt = [
        'status'                 => (string) input('status', 'approved'),
        'document_code'          => clean(input('document_code')),
        'issuing_body'           => clean(input('issuing_body')),
        'legal_basis'            => clean(input('legal_basis')),
        'confidentiality'        => (string) input('confidentiality', 'internal'),
        'review_interval_months' => max(1, sanitize_int(input('review_interval_months', 12))),
        'sector_id'              => sanitize_int(input('sector_id')) ?: null,
    ];

    $exp = $data['expiration_date'];
    $interval = $opt['review_interval_months'];
    if ($exp && strtotime($exp)) {
        $opt['next_review_date'] = date('Y-m-d', strtotime("+{$interval} months", strtotime($exp)));
    }

    return array_merge($data, $opt);
}
