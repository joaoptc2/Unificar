<?php
/**
 * Formulário de documento (controlado × não controlado; arquivo × editor).
 * Variáveis: $document, $editing, $is_controlled, $predefined_categories,
 * $sectors, $default_sector_id, $page_layouts, $cover_layouts, $layouts_json
 */
$d = $document ?? [];
$f = function($k, $def = '') use ($d) { return $d[$k] ?? $def; };
$cats = $predefined_categories ?? [];
$is_controlled = !empty($is_controlled);
$source = $f('source', 'upload') === 'editor' ? 'editor' : 'upload';
$list_url = $is_controlled ? url('documents') : url('documents/uncontrolled');
$cur_layout = (int) $f('layout_id', 0);
if (!$cur_layout) {
    foreach (($page_layouts ?? []) as $pl) { if (!empty($pl['is_default'])) { $cur_layout = (int) $pl['id']; break; } }
    if (!$cur_layout && !empty($page_layouts)) $cur_layout = (int) $page_layouts[0]['id'];
}
$cur_cover = (int) $f('cover_layout_id', 0);
$cur_font  = (string) $f('font_family', '');
$cur_size  = (string) $f('font_size', '');
$has_page_layouts = !empty($page_layouts);
?>
<div class="page-header">
    <h1>
        <i class="bi bi-<?php echo $editing ? 'pencil' : 'plus-circle'; ?> me-2"></i>
        <?php echo $editing ? 'Editar documento' : 'Novo documento'; ?>
        <span class="badge <?php echo $is_controlled ? 'bg-primary' : 'bg-secondary'; ?> ms-2" style="font-size:.7rem;vertical-align:middle">
            <?php echo $is_controlled ? 'Controlado' : 'Não controlado'; ?>
        </span>
    </h1>
    <div class="d-flex gap-2">
        <?php if (!$editing): ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="Tipo de documento">
                <a href="<?php echo url('documents/create'); ?>" class="btn <?php echo $is_controlled ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="bi bi-shield-check me-1"></i>Controlado
                </a>
                <a href="<?php echo url('documents/create?type=uncontrolled'); ?>" class="btn <?php echo !$is_controlled ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="bi bi-archive me-1"></i>Não controlado
                </a>
            </div>
        <?php endif; ?>
        <a href="<?php echo $list_url; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<?php if (!$is_controlled): ?>
<div class="alert alert-secondary py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Documento não controlado:</strong> apenas armazenado para acesso fácil — sem validade obrigatória,
    sem fluxo de aprovação/revisão e sem avisos de vencimento.
</div>
<?php endif; ?>

<form method="POST" id="docForm"
      action="<?php echo url($editing ? 'documents/update' : 'documents/store'); ?>"
      enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="is_controlled" value="<?php echo $is_controlled ? 1 : 0; ?>">
    <input type="hidden" name="content_html" id="contentHtml" value="<?php echo e($f('content_html', '')); ?>">
    <input type="hidden" name="cover_html" id="coverHtml" value="<?php echo e($f('cover_html', '')); ?>">

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-info-circle me-1"></i>Informações do documento
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Código</label>
                            <input type="text" name="document_code" class="form-control"
                                   value="<?php echo e($f('document_code')); ?>"
                                   placeholder="POP-UTI-001">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control" required
                                   value="<?php echo e($f('title')); ?>"
                                   placeholder="Ex: Alvará de Funcionamento">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Categoria</label>
                            <?php if (!empty($cats)): ?>
                            <select name="category" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($cats as $cat): ?>
                                    <option value="<?php echo e($cat['name']); ?>"
                                            <?php echo $f('category') === $cat['name'] ? 'selected' : ''; ?>>
                                        <?php echo e($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($f('category') !== '' && !in_array($f('category'), array_column($cats, 'name'), true)): ?>
                                    <option value="<?php echo e($f('category')); ?>" selected><?php echo e($f('category')); ?></option>
                                <?php endif; ?>
                            </select>
                            <?php else: ?>
                            <input type="text" name="category" class="form-control" required
                                   value="<?php echo e($f('category')); ?>"
                                   placeholder="Ex: Protocolos" list="cat-list">
                            <datalist id="cat-list">
                                <option value="Políticas"><option value="Protocolos Clínicos">
                                <option value="POPs"><option value="Manuais">
                                <option value="Contratos"><option value="Alvarás e Licenças">
                                <option value="Certificações"><option value="Outros">
                            </datalist>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Setor</label>
                            <select name="sector_id" class="form-select">
                                <option value="">— Sem setor —</option>
                                <?php foreach (($sectors ?? []) as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>"
                                            <?php echo (int) ($default_sector_id ?? 0) === (int) $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($s['name']); ?><?php echo !empty($s['code']) ? ' (' . e($s['code']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Responsável</label>
                            <input type="text" name="responsible" class="form-control"
                                   value="<?php echo e($f('responsible')); ?>" placeholder="Nome">
                        </div>

                        <?php if ($is_controlled): ?>
                        <div class="col-md-4">
                            <label class="form-label">Órgão emissor</label>
                            <input type="text" name="issuing_body" class="form-control"
                                   value="<?php echo e($f('issuing_body')); ?>" placeholder="ANVISA, MS, etc">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Validade</label>
                            <input type="date" name="expiration_date" class="form-control" required
                                   value="<?php echo e($f('expiration_date')); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Aviso prévio (dias)</label>
                            <input type="number" name="notify_days" class="form-control" min="1" max="365"
                                   value="<?php echo (int) $f('notify_days_before', 30); ?>" placeholder="30">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Revisão a cada</label>
                            <div class="input-group">
                                <input type="number" name="review_interval_months" class="form-control"
                                       min="1" max="60"
                                       value="<?php echo (int) $f('review_interval_months', 12); ?>">
                                <span class="input-group-text">meses</span>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Base legal / normativa</label>
                            <input type="text" name="legal_basis" class="form-control"
                                   value="<?php echo e($f('legal_basis')); ?>"
                                   placeholder="RDC nº 36/2013, Portaria MS nº...">
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4">
                            <label class="form-label">Confidencialidade</label>
                            <select name="confidentiality" class="form-select">
                                <?php foreach (['internal'=>'Interno','public'=>'Público','restricted'=>'Restrito','confidential'=>'Confidencial'] as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $f('confidentiality','internal') === $v ? 'selected' : ''; ?>>
                                        <?php echo $l; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Observações</label>
                            <textarea name="observations" class="form-control" rows="2"><?php echo e($f('observations')); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Origem: arquivo ou editor ─────────────────────────────── -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap align-items-center gap-3">
                    <span><i class="bi bi-file-earmark-richtext me-1"></i>Conteúdo do documento</span>
                    <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Origem do documento">
                        <input type="radio" class="btn-check" name="source" id="srcUpload" value="upload" <?php echo $source === 'upload' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="srcUpload"><i class="bi bi-upload me-1"></i>Enviar arquivo</label>
                        <input type="radio" class="btn-check" name="source" id="srcEditor" value="editor" <?php echo $source === 'editor' ? 'checked' : ''; ?> <?php echo $has_page_layouts ? '' : 'disabled'; ?>>
                        <label class="btn btn-outline-primary" for="srcEditor"><i class="bi bi-pencil-square me-1"></i>Escrever no sistema</label>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Upload -->
                    <div id="srcUploadBox" <?php echo $source === 'upload' ? '' : 'hidden'; ?>>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Arquivo</label>
                                <input type="file" name="document_file" class="form-control"
                                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt">
                                <small class="text-muted">Máx: <?php echo format_bytes(UPLOAD_MAX_SIZE); ?> — PDF, Word, Excel, imagens ou texto.</small>
                                <?php if ($editing && !empty($d['file_name'])): ?>
                                    <div class="mt-1 small text-success">
                                        <i class="bi bi-paperclip me-1"></i>Atual: <?php echo e($d['file_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Editor -->
                    <div id="srcEditorBox" <?php echo $source === 'editor' ? '' : 'hidden'; ?>>
                        <?php if (!$has_page_layouts): ?>
                            <div class="alert alert-warning py-2 small mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Nenhum layout de página ativo. Cadastre um em
                                <a href="<?php echo core_url('index.php?m=admin&a=layouts'); ?>">Administração &rsaquo; Padronização &rsaquo; Layouts de documentos</a>.
                            </div>
                        <?php else: ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Layout de página (papel timbrado)</label>
                                <select name="layout_id" id="layoutSelect" class="form-select form-select-sm">
                                    <?php foreach ($page_layouts as $l): ?>
                                        <option value="<?php echo (int) $l['id']; ?>" <?php echo (int) $l['id'] === $cur_layout ? 'selected' : ''; ?>>
                                            <?php echo e($l['name']); ?> — <?php echo e($l['page_size']); ?> <?php echo $l['orientation'] === 'landscape' ? 'paisagem' : 'retrato'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fonte do texto</label>
                                <select name="font_family" id="fontSelect" class="form-select form-select-sm" data-current="<?php echo e($cur_font); ?>"></select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Tamanho</label>
                                <select name="font_size" id="sizeSelect" class="form-select form-select-sm" data-current="<?php echo e($cur_size); ?>"></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Capa (opcional)</label>
                                <select name="cover_layout_id" id="coverLayoutSelect" class="form-select form-select-sm">
                                    <option value="">Sem capa</option>
                                    <?php foreach (($cover_layouts ?? []) as $l): ?>
                                        <option value="<?php echo (int) $l['id']; ?>" <?php echo (int) $l['id'] === $cur_cover ? 'selected' : ''; ?>>
                                            <?php echo e($l['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div id="coverBox" class="mb-3" <?php echo $cur_cover ? '' : 'hidden'; ?>>
                            <label class="form-label">Conteúdo da capa
                                <small class="text-muted fw-normal">(variáveis: {{titulo}} {{codigo}} {{setor}} {{autor}} {{data}} {{versao}} {{subtitulo}} {{org}} {{logo}})</small>
                            </label>
                            <div class="doc-editor-wrap doc-editor-cover">
                                <div id="coverEditor"><?php echo $f('cover_html', ''); ?></div>
                            </div>
                        </div>

                        <label class="form-label">Texto do documento</label>
                        <div class="doc-editor-wrap" id="editorWrap">
                            <div id="editor"><?php echo $f('content_html', ''); ?></div>
                        </div>
                        <small class="text-muted">O documento é impresso/exportado em PDF com o cabeçalho, rodapé e fundo do layout escolhido.</small>
                        <?php endif; ?>
                    </div>

                    <?php if ($editing): ?>
                    <div class="mt-3">
                        <label class="form-label">Notas da alteração</label>
                        <input type="text" name="version_notes" class="form-control"
                               placeholder="Ex: Renovação do alvará, atualização do protocolo">
                        <small class="text-muted">Ao alterar arquivo, conteúdo, layout, fonte, título ou validade, a versão anterior é guardada automaticamente (v<?php echo (int) $f('current_version', 1); ?> → v<?php echo (int) $f('current_version', 1) + 1; ?>).</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if (!$editing && $is_controlled): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-send me-1"></i>Status inicial
                </div>
                <div class="card-body">
                    <select name="status" class="form-select">
                        <option value="draft">Rascunho</option>
                        <option value="pending_review">Enviar para revisão</option>
                        <option value="approved" selected>Aprovado (publicar direto)</option>
                    </select>
                    <small class="text-muted">Gestores podem aprovar depois.</small>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-question-circle me-1"></i>Como funciona</div>
                <div class="card-body small text-muted">
                    <p class="mb-2"><strong>Enviar arquivo:</strong> anexe o PDF/Word/imagem já pronto.</p>
                    <p class="mb-0"><strong>Escrever no sistema:</strong> use o editor com o layout (papel timbrado) do hospital,
                        escolha a fonte permitida pelo layout e, se quiser, uma capa. A impressão/PDF sai fiel ao timbrado e
                        cada alteração gera uma nova versão.</p>
                </div>
            </div>

            <div class="d-flex flex-column gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?php echo $editing ? 'Atualizar' : 'Cadastrar'; ?>
                </button>
                <a href="<?php echo $list_url; ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </div>
</form>

<script>
window.DOC_FORM_CFG = {
    layouts: <?php echo $layouts_json ?? '{}'; ?>,
    currentLayout: <?php echo (int) $cur_layout; ?>,
    currentCover: <?php echo (int) $cur_cover; ?>,
    currentFont: <?php echo json_encode($cur_font); ?>,
    currentSize: <?php echo json_encode($cur_size); ?>,
    hasEditor: <?php echo $has_page_layouts ? 'true' : 'false'; ?>
};
</script>
