<?php
$d = $document ?? [];
$f = function($k, $def = '') use ($d) { return $d[$k] ?? $def; };
$cats = $predefined_categories ?? [];
?>
<div class="page-header">
    <h1>
        <i class="bi bi-<?php echo $editing ? 'pencil' : 'plus-circle'; ?> me-2"></i>
        <?php echo $editing ? 'Editar Documento' : 'Novo Documento'; ?>
    </h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('documents'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<form method="POST"
      action="<?php echo url($editing ? 'documents/update' : 'documents/store'); ?>"
      enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-info-circle me-1"></i>Informações do Documento
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
                            <label class="form-label">Responsável</label>
                            <input type="text" name="responsible" class="form-control"
                                   value="<?php echo e($f('responsible')); ?>" placeholder="Nome">
                        </div>
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
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-paperclip me-1"></i>Arquivo e Observações
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Arquivo</label>
                            <input type="file" name="document_file" class="form-control"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt">
                            <small class="text-muted">Máx: <?php echo format_bytes(UPLOAD_MAX_SIZE); ?></small>
                            <?php if ($editing && !empty($d['file_name'])): ?>
                                <div class="mt-1 small text-success">
                                    <i class="bi bi-paperclip me-1"></i>Atual: <?php echo e($d['file_name']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Observações</label>
                            <textarea name="observations" class="form-control" rows="2"><?php echo e($f('observations')); ?></textarea>
                        </div>
                        <?php if ($editing): ?>
                        <div class="col-12">
                            <label class="form-label">Notas da alteração</label>
                            <input type="text" name="version_notes" class="form-control"
                                   placeholder="Ex: Renovação do alvará, atualização do protocolo">
                            <small class="text-muted">O sistema salva a versão anterior automaticamente.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if (!$editing): ?>
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

            <div class="d-flex flex-column gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?php echo $editing ? 'Atualizar' : 'Cadastrar'; ?>
                </button>
                <a href="<?php echo url('documents'); ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </div>
</form>
