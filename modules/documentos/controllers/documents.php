<?php
/**
 * Controller de Documentos
 *
 *  - Controlados (is_controlled=1): workflow de aprovação, validade, revisão
 *    periódica, ciência digital, versionamento.
 *  - Não controlados (is_controlled=0): apenas armazenados (sem validade
 *    obrigatória, sem workflow, status sempre 'approved').
 *  - Origem: arquivo enviado (upload) ou escrito no sistema (editor com os
 *    layouts do hospital — Core\DocLayout), com impressão/PDF fiel ao
 *    timbrado em documents/print/{id}[?version=N][&pdf=1].
 */

// ═══════════════════════════════════════════════════════════════════════════
//  Listagens
// ═══════════════════════════════════════════════════════════════════════════

function documents_index($param = null) {
    core_require('documents.view');
    $hospital_id = get_hospital_id();
    $sector_id   = get_sector_id();

    $filter   = (string) query('filter', 'all');
    $search   = clean(query('search', ''));
    $category = clean(query('category', ''));
    $status   = clean(query('status', ''));

    $total      = 0;
    $documents  = [];
    $categories = [];

    try {
        $total      = document_count($hospital_id, $filter, $search, $category, $status, $sector_id, 1);
        $pagination = paginate($total);
        $documents  = document_list($hospital_id, $filter, $search, $category, $status,
                                    $pagination['per_page'], $pagination['offset'], $sector_id, 1);
        $categories = document_distinct_categories($hospital_id, 1);
    } catch (Exception $ex) {
        log_error('documents_index', $ex);
        $pagination = paginate(0);
    }

    view('documents/index', [
        'page_title'  => 'Documentos controlados',
        'documents'   => $documents,
        'categories'  => $categories,
        'filter'      => $filter,
        'search'      => $search,
        'category'    => $category,
        'status'      => $status,
        'pagination'  => $pagination,
        'menu_key'    => 'documents',
    ]);
}

function documents_uncontrolled($param = null) {
    core_require('documents.view');
    $hospital_id = get_hospital_id();
    $sector_id   = get_sector_id();

    $search   = clean(query('search', ''));
    $category = clean(query('category', ''));

    $total      = 0;
    $documents  = [];
    $categories = [];

    try {
        $total      = document_count($hospital_id, 'all', $search, $category, '', $sector_id, 0);
        $pagination = paginate($total);
        $documents  = document_list($hospital_id, 'all', $search, $category, '',
                                    $pagination['per_page'], $pagination['offset'], $sector_id, 0);
        $categories = document_distinct_categories($hospital_id, 0);
    } catch (Exception $ex) {
        log_error('documents_uncontrolled', $ex);
        $pagination = paginate(0);
    }

    view('documents/uncontrolled', [
        'page_title'  => 'Documentos não controlados',
        'documents'   => $documents,
        'categories'  => $categories,
        'search'      => $search,
        'category'    => $category,
        'pagination'  => $pagination,
        'menu_key'    => 'documents-uncontrolled',
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Cadastro / edição
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Dados dos layouts do hospital para o formulário (editor): layouts de
 * página, de capa e o mapa JSON id → fontes/tamanhos/largura para o JS.
 */
function _documents_layouts_data() {
    $page_layouts  = [];
    $cover_layouts = [];
    try {
        $page_layouts  = Core\DocLayout::active('page');
        $cover_layouts = Core\DocLayout::active('cover');
    } catch (Throwable $ex) {
        log_error('documents_layouts', $ex);
    }
    $map = [];
    foreach (array_merge($page_layouts, $cover_layouts) as $l) {
        $map[(int) $l['id']] = Core\DocLayout::editorConfig($l, ['name' => $l['name']]);
    }
    return [
        'page_layouts'  => $page_layouts,
        'cover_layouts' => $cover_layouts,
        'layouts_json'  => json_encode((object) $map, JSON_UNESCAPED_UNICODE),
    ];
}

function _documents_form_data($document, $editing, $is_controlled) {
    $sectors = [];
    try { $sectors = sector_list(get_hospital_id()); } catch (Exception $ex) {}
    return array_merge([
        'page_title' => $editing ? 'Editar documento' : ($is_controlled ? 'Novo documento controlado' : 'Novo documento não controlado'),
        'document'   => $document,
        'editing'    => $editing,
        'is_controlled' => $is_controlled,
        'predefined_categories' => document_categories_list(),
        'sectors'    => $sectors,
        'default_sector_id' => $document['sector_id'] ?? get_sector_id(),
        'menu_key'   => $is_controlled ? 'documents' : 'documents-uncontrolled',
        'head'       => Core\DocLayout::editorHead(),
        'scripts'    => Core\DocLayout::editorScripts() . "\n" . '<script src="' . asset('doc-form.js') . '"></script>',
    ], _documents_layouts_data());
}

function documents_create($param = null) {
    core_require('documents.create');
    $type = (string) query('type', 'controlled');
    $is_controlled = $type !== 'uncontrolled';
    view('documents/form', _documents_form_data(null, false, $is_controlled));
}

function documents_edit($param = null) {
    core_require('documents.edit');
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();

    $document = null;
    try { $document = document_find($id, $hospital_id); } catch (Exception $ex) { log_error('documents_edit', $ex); }

    if (!$document) {
        set_flash('error', 'Documento não encontrado.');
        redirect('documents');
    }

    view('documents/form', _documents_form_data($document, true, (int) $document['is_controlled'] === 1));
}

function documents_store($param = null) {
    core_require('documents.create');
    if (!is_post()) redirect('documents');
    csrf_validate();

    $hospital_id = get_hospital_id();
    $data   = _documents_collect_form();
    $errors = _documents_validate($data);
    $back   = 'documents/create' . ($data['is_controlled'] ? '' : '?type=uncontrolled');

    $file_data = null;
    if ($data['source'] === 'upload') {
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $upload_errors = validate_upload($_FILES['document_file']);
            if (!empty($upload_errors)) {
                $errors = array_merge($errors, $upload_errors);
            } else {
                $file_data = save_upload($_FILES['document_file'], $hospital_id);
                if (!$file_data) $errors[] = 'Erro ao salvar o arquivo.';
            }
        } elseif (!$data['is_controlled']) {
            $errors[] = 'Envie um arquivo ou escreva o documento no sistema.';
        }
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect($back);
    }

    try {
        $id = document_create($hospital_id, $data, $file_data, get_user_id());
        audit_log('document_created', "id=$id, title={$data['title']}, controlled=" . (int) $data['is_controlled'] . ", source={$data['source']}");
        set_flash('success', 'Documento cadastrado com sucesso!');
        redirect('documents/view?id=' . $id);
    } catch (Exception $ex) {
        log_error('documents_store', $ex);
        set_flash('error', 'Erro ao salvar documento.');
        redirect($back);
    }
}

function documents_update($param = null) {
    core_require('documents.edit');
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

    $data = _documents_collect_form($document);
    // O tipo (controlado × não controlado) não muda na edição
    $data['is_controlled'] = (int) $document['is_controlled'];
    if (!$data['is_controlled']) {
        $data['status'] = 'approved';
        $data['expiration_date'] = '';
        $data['next_review_date'] = null;
    } else {
        unset($data['status']); // status é alterado pelo workflow, não pelo formulário
    }
    $errors = _documents_validate($data);

    $file_data = null;
    if ($data['source'] === 'upload' && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $upload_errors = validate_upload($_FILES['document_file']);
        if (!empty($upload_errors)) {
            $errors = array_merge($errors, $upload_errors);
        } else {
            $file_data = save_upload($_FILES['document_file'], $hospital_id);
            if (!$file_data) $errors[] = 'Erro ao salvar o arquivo.';
        }
    }
    if ($data['source'] === 'upload' && !$file_data && empty($document['file_path']) && $data['source'] !== $document['source']) {
        $errors[] = 'Envie o arquivo do documento.';
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('documents/edit?id=' . $id);
    }

    try {
        $version_notes = clean(input('version_notes'));
        $has_changes = (bool) $file_data
            || (string) ($data['expiration_date'] ?: '') !== (string) ($document['expiration_date'] ?: '')
            || $data['title'] !== ($document['title'] ?? '')
            || $data['source'] !== ($document['source'] ?? 'upload');
        if ($data['source'] === 'editor') {
            foreach (['content_html', 'cover_html', 'layout_id', 'cover_layout_id', 'font_family', 'font_size'] as $k) {
                if ((string) ($data[$k] ?? '') !== (string) ($document[$k] ?? '')) { $has_changes = true; break; }
            }
        }
        if ($has_changes) {
            document_save_version($document, $version_notes, get_user_id());
            document_bump_version($id, $hospital_id);
        }

        document_update($id, $hospital_id, $data, $file_data);
        audit_log('document_updated', "id=$id, title={$data['title']}" . ($has_changes ? ', new_version' : ''));
        set_flash('success', 'Documento atualizado com sucesso!' . ($has_changes ? ' Nova versão registrada.' : ''));
    } catch (Exception $ex) {
        log_error('documents_update', $ex);
        set_flash('error', 'Erro ao atualizar documento.');
    }

    redirect('documents/view?id=' . $id);
}

function documents_delete($param = null) {
    core_require('documents.delete');
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    $back = 'documents';
    try {
        $doc = document_find($id, get_hospital_id());
        if ($doc && (int) $doc['is_controlled'] === 0) $back = 'documents/uncontrolled';
        document_soft_delete($id, get_hospital_id());
        audit_log('document_deleted', "id=$id");
        set_flash('success', 'Documento removido com sucesso.');
    } catch (Exception $ex) {
        log_error('documents_delete', $ex);
        set_flash('error', 'Erro ao remover documento.');
    }
    redirect($back);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Visualização, download e impressão
// ═══════════════════════════════════════════════════════════════════════════

function documents_view($param = null) {
    core_require('documents.view');
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
        'menu_key'   => (int) $document['is_controlled'] === 1 ? 'documents' : 'documents-uncontrolled',
    ]);
}

function documents_download($param = null) {
    core_require('documents.view');
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();
    try { $document = document_find($id, $hospital_id); }
    catch (Exception $ex) { log_error('documents_download:find', $ex); $document = null; }
    if ($document && document_is_editor($document)) {
        // Documento escrito no sistema: o "download" é a página de impressão/PDF
        redirect('documents/print/' . $id);
    }
    if (!$document || empty($document['file_path'])) {
        set_flash('error', 'Arquivo não encontrado.');
        redirect('documents');
    }
    $file_full_path = DOC_UPLOADS_PATH . '/hospital_' . $hospital_id . '/' . basename($document['file_path']);
    $mime = !empty($document['mime_type']) ? $document['mime_type'] : 'application/octet-stream';
    audit_log('document_downloaded', "id=$id");
    stream_download($file_full_path, $document['file_name'] ?: $document['file_path'], $mime);
}

function documents_download_version($param = null) {
    core_require('documents.view');
    $version_id = sanitize_int(query('version_id'));
    $doc_id     = sanitize_int(query('id'));
    $hospital_id = get_hospital_id();
    $document = document_find($doc_id, $hospital_id);
    if (!$document) { set_flash('error', 'Documento não encontrado.'); redirect('documents'); }
    $version = document_version_find($version_id, $doc_id);
    if ($version && empty($version['file_path']) && !empty($version['content_html'])) {
        redirect('documents/print/' . $doc_id . '?version=' . (int) $version['version']);
    }
    if (!$version || empty($version['file_path'])) {
        set_flash('error', 'Versão não encontrada.');
        redirect('documents/view?id=' . $doc_id);
    }
    $file_full_path = DOC_UPLOADS_PATH . '/hospital_' . $hospital_id . '/' . basename($version['file_path']);
    audit_log('document_version_downloaded', "doc=$doc_id, version={$version['version']}");
    stream_download($file_full_path, $version['file_name'] ?: $version['file_path'], $version['mime_type'] ?? 'application/octet-stream');
}

/**
 * documents/print/{id}[?version=N][&pdf=1]
 * Página de impressão/PDF do documento escrito no sistema, fiel ao layout
 * (fundo, cabeçalho/rodapé repetidos, folha de capa opaca).
 */
function documents_print($param = null) {
    core_require('documents.view');
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();
    $document = document_find($id, $hospital_id);
    if (!$document) {
        set_flash('error', 'Documento não encontrado.');
        redirect('documents');
    }

    $version_n  = sanitize_int(query('version'));
    $current_v  = (int) $document['current_version'];
    $is_history = false;

    $content  = (string) ($document['content_html'] ?? '');
    $cover    = (string) ($document['cover_html'] ?? '');
    $layoutId = $document['layout_id'] !== null ? (int) $document['layout_id'] : null;
    $coverId  = $document['cover_layout_id'] !== null ? (int) $document['cover_layout_id'] : null;
    $font     = (string) ($document['font_family'] ?? '');
    $size     = (string) ($document['font_size'] ?? '');
    $date     = $document['updated_at'];
    $version  = $current_v;

    if ($version_n > 0 && $version_n !== $current_v) {
        $v = document_version_by_number($id, $version_n);
        if (!$v || (empty($v['content_html']) && empty($v['cover_html']))) {
            set_flash('error', 'Versão não encontrada ou sem conteúdo do editor.');
            redirect('documents/view?id=' . $id);
        }
        $is_history = true;
        $content  = (string) ($v['content_html'] ?? '');
        $cover    = (string) ($v['cover_html'] ?? '');
        $layoutId = $v['layout_id'] !== null ? (int) $v['layout_id'] : $layoutId;
        $coverId  = $v['cover_layout_id'] !== null ? (int) $v['cover_layout_id'] : null;
        $font     = (string) ($v['font_family'] ?? '');
        $size     = (string) ($v['font_size'] ?? '');
        $date     = $v['created_at'];
        $version  = (int) $v['version'];
    } elseif (!document_is_editor($document) && $content === '') {
        set_flash('info', 'Este documento é um arquivo enviado — use o download.');
        redirect('documents/view?id=' . $id);
    }

    $layout = Core\DocLayout::findOrDefault($layoutId);
    if (!$layout) {
        Core\Layout::renderError(500, 'Nenhum layout de documento cadastrado — crie um em Administração > Padronização > Layouts de documentos.');
        exit;
    }
    $coverLayout = $coverId ? Core\DocLayout::find($coverId) : null;
    $hasCover    = $coverLayout !== null || trim($cover) !== '';

    $can_print = core_can('documents.export');
    $autoprint = $can_print && !empty($_GET['pdf']);

    $toolbar = '<strong>' . core_e($document['title']) . '</strong> <span style="opacity:.8">v' . $version . '</span>'
        . ($is_history ? ' <span style="background:#ffc107;color:#000;border-radius:4px;padding:2px 8px;font-size:12px">versão do histórico</span>' : '')
        . ((int) $document['is_controlled'] === 0 ? ' <span style="background:#6c757d;color:#fff;border-radius:4px;padding:2px 8px;font-size:12px">não controlado</span>' : '')
        . '<span class="spacer"></span>';
    if ($can_print) {
        $toolbar .= '<button onclick="window.print()">Imprimir / Salvar em PDF</button>';
    }
    if ($is_history) {
        $toolbar .= '<a href="' . core_e(url('documents/print/' . $id)) . '">Versão atual</a>';
    }
    $toolbar .= '<a href="' . core_e(url('documents/view?id=' . $id)) . '">Voltar</a>';

    if ($autoprint) audit_log('document_printed', "id=$id, version=$version");

    // Defesa em profundidade: sanitiza também na saída (conteúdo antigo/importado)
    $content = doc_sanitize_html($content);
    $cover   = doc_sanitize_html($cover);

    echo Core\DocLayout::renderHtml([
        'title'        => $document['title'],
        'layout'       => $layout,
        'content_html' => $content,
        'meta'         => [
            'title'    => $document['title'],
            'author'   => $document['created_by_name'] ?? '',
            'date'     => date('d/m/Y', strtotime((string) $date)),
            'version'  => $version,
            'code'     => $document['document_code'] ?? '',
            'sector'   => $document['sector_name'] ?? '',
            'subtitle' => $document['category'] ?? '',
        ],
        'toolbar'      => $toolbar,
        'autoprint'    => $autoprint,
        'cover'        => $hasCover ? ['layout' => $coverLayout, 'html' => $cover] : null,
        'font_family'  => $font,
        'font_size'    => $size,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Workflow (somente documentos controlados)
// ═══════════════════════════════════════════════════════════════════════════

function _documents_controlled_or_back($id) {
    $doc = document_find($id, get_hospital_id());
    if (!$doc) {
        set_flash('error', 'Documento não encontrado.');
        redirect('documents');
    }
    if ((int) $doc['is_controlled'] !== 1) {
        set_flash('warning', 'Documentos não controlados não possuem fluxo de aprovação/revisão.');
        redirect('documents/view?id=' . $id);
    }
    return $doc;
}

function documents_submit($param = null) {
    core_require('documents.create');
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    _documents_controlled_or_back($id);
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
    core_require('documents.approve');
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    $doc = _documents_controlled_or_back($id);
    try {
        document_set_status($id, get_hospital_id(), 'approved', get_user_id());
        document_mark_reviewed($id, get_hospital_id(), (int) ($doc['review_interval_months'] ?? 12));
        audit_log('document_approved', "id=$id");
        set_flash('success', 'Documento aprovado.');
    } catch (Exception $ex) {
        log_error('documents_approve', $ex);
        set_flash('error', 'Erro ao aprovar.');
    }
    redirect('documents/view?id=' . $id);
}

function documents_reject($param = null) {
    core_require('documents.approve');
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    _documents_controlled_or_back($id);
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
    core_require('documents.acknowledge');
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    _documents_controlled_or_back($id);
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
    core_require('documents.approve');
    if (!is_post()) redirect('documents');
    csrf_validate();
    $id = sanitize_int(input('id'));
    $doc = _documents_controlled_or_back($id);
    try {
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

// ═══════════════════════════════════════════════════════════════════════════
//  Exportação CSV
// ═══════════════════════════════════════════════════════════════════════════

function documents_export($param = null) {
    core_require('documents.export');
    $hospital_id = get_hospital_id();
    $filter   = (string) query('filter', 'all');
    $search   = clean(query('search', ''));
    $category = clean(query('category', ''));
    $type     = (string) query('type', 'controlled');
    $is_controlled = $type === 'uncontrolled' ? 0 : 1;
    try {
        $rows = document_list($hospital_id, $filter, $search, $category, '', 10000, 0, get_sector_id(), $is_controlled);
    } catch (Exception $ex) {
        log_error('documents_export', $ex);
        set_flash('error', 'Erro ao exportar.');
        redirect($is_controlled ? 'documents' : 'documents/uncontrolled');
    }
    $data = [];
    foreach ($rows as $d) {
        $line = [
            'Código'       => $d['document_code'] ?? '',
            'Título'       => $d['title'],
            'Categoria'    => $d['category'],
            'Setor'        => $d['sector_name'] ?? '',
            'Responsável'  => $d['responsible'],
        ];
        if ($is_controlled) {
            $days = $d['expiration_date'] ? days_until($d['expiration_date']) : null;
            $line['Validade'] = format_date($d['expiration_date']);
            $line['Status']   = $d['status'] ?? 'approved';
            $line['Situação'] = $days === null ? 'Sem validade' : expiry_label($days);
        }
        $line['Origem']        = document_is_editor($d) ? 'Editor' : 'Arquivo';
        $line['Arquivo']       = $d['file_name'] ?: '';
        $line['Versão']        = 'v' . (int) $d['current_version'];
        $line['Cadastrado em'] = format_datetime($d['created_at']);
        $data[] = $line;
    }
    audit_log('documents_exported', 'count=' . count($data) . ', type=' . $type);
    csv_response(
        'documentos_' . ($is_controlled ? 'controlados' : 'nao_controlados') . '_' . date('Ymd_His') . '.csv',
        array_keys($data[0] ?? []),
        $data
    );
}

// ═══════════════════════════════════════════════════════════════════════════
//  Helpers internos
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Coleta o formulário (cadastro e edição).
 * @param array|null $existing documento atual (na edição)
 */
function _documents_collect_form($existing = null) {
    $is_controlled = sanitize_int(input('is_controlled', 1)) === 1 ? 1 : 0;
    $source = (string) input('source', 'upload') === 'editor' ? 'editor' : 'upload';

    $sector_id = sanitize_int(input('sector_id')) ?: null;
    if ($sector_id && !sector_find_active($sector_id, get_hospital_id())) {
        $sector_id = null;
    }

    $data = [
        'title'           => clean(input('title')),
        'category'        => clean(input('category')),
        'responsible'     => clean(input('responsible')),
        'expiration_date' => $is_controlled ? (string) input('expiration_date') : '',
        'notify_days'     => max(1, min(365, sanitize_int(input('notify_days', 30)) ?: 30)),
        'observations'    => clean(input('observations')),
        'is_controlled'   => $is_controlled,
        'source'          => $source,
        'status'          => $is_controlled ? (string) input('status', 'approved') : 'approved',
        'document_code'   => clean(input('document_code')) ?: null,
        'issuing_body'    => clean(input('issuing_body')) ?: null,
        'legal_basis'     => clean(input('legal_basis')) ?: null,
        'confidentiality' => (string) input('confidentiality', 'internal'),
        'review_interval_months' => max(1, sanitize_int(input('review_interval_months', 12)) ?: 12),
        'sector_id'       => $sector_id,
        'next_review_date' => null,
    ];

    if (!in_array($data['status'], ['draft', 'pending_review', 'approved'], true)) $data['status'] = 'approved';
    // Publicar direto como 'approved' exige a permissão de aprovação (documents.approve)
    if ($is_controlled && $data['status'] === 'approved' && !core_can('documents.approve')) {
        $data['status'] = 'pending_review';
    }
    if (!in_array($data['confidentiality'], ['public', 'internal', 'restricted', 'confidential'], true)) {
        $data['confidentiality'] = 'internal';
    }

    if ($is_controlled && $data['expiration_date'] && strtotime($data['expiration_date'])) {
        $data['next_review_date'] = date('Y-m-d', strtotime("+{$data['review_interval_months']} months", strtotime($data['expiration_date'])));
    }

    // ── Escrito no sistema (editor com layouts do hospital) ─────────────────
    if ($source === 'editor') {
        $layout_id = sanitize_int(input('layout_id')) ?: null;
        $layout    = $layout_id ? Core\DocLayout::find($layout_id) : null;
        if (!$layout || empty($layout['active'])) {
            $layout    = Core\DocLayout::findOrDefault(null);
            $layout_id = $layout ? (int) $layout['id'] : null;
        }
        $cover_layout_id = sanitize_int(input('cover_layout_id')) ?: null;
        $cover_layout    = $cover_layout_id ? Core\DocLayout::find($cover_layout_id) : null;
        if (!$cover_layout || empty($cover_layout['active']) || !in_array($cover_layout['kind'] ?? 'both', ['cover', 'both'], true)) {
            $cover_layout_id = null;
        }

        // Fonte e tamanho restritos à lista permitida pelo layout de página
        $fonts = Core\DocLayout::fontsOf($layout);
        $sizes = Core\DocLayout::sizesOf($layout);
        $font  = clean(input('font_family'));
        $size  = clean(input('font_size'));
        if ($font !== '' && !in_array($font, $fonts, true)) $font = '';
        if ($size !== '' && !in_array($size, $sizes, true)) $size = '';

        $data['layout_id']       = $layout_id;
        $data['cover_layout_id'] = $cover_layout_id;
        $data['content_html']    = doc_sanitize_html((string) input('content_html', ''));
        $data['cover_html']      = $cover_layout_id ? doc_sanitize_html((string) input('cover_html', '')) : null;
        $data['font_family']     = $font !== '' ? $font : ($layout['default_font'] ?? null);
        $data['font_size']       = $size !== '' ? $size : ($layout['default_font_size'] ?? null);
    } else {
        $data['layout_id'] = null; $data['cover_layout_id'] = null;
        $data['content_html'] = null; $data['cover_html'] = null;
        $data['font_family'] = null; $data['font_size'] = null;
    }

    return $data;
}

function _documents_validate(array $data) {
    $errors = [];
    if (empty($data['title']))    $errors[] = 'Título é obrigatório.';
    if (empty($data['category'])) $errors[] = 'Categoria é obrigatória.';
    if ($data['is_controlled']) {
        if (empty($data['expiration_date']))          $errors[] = 'Data de validade é obrigatória para documentos controlados.';
        elseif (!strtotime($data['expiration_date'])) $errors[] = 'Data de validade inválida.';
    }
    if ($data['source'] === 'editor') {
        if (trim(strip_tags((string) $data['content_html'])) === '' && strpos((string) $data['content_html'], '<img') === false) {
            $errors[] = 'Escreva o conteúdo do documento.';
        }
        if (empty($data['layout_id'])) {
            $errors[] = 'Nenhum layout de documento ativo — cadastre um em Administração > Padronização > Layouts de documentos.';
        }
    }
    return $errors;
}
