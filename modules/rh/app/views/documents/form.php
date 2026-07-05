<div class="page-header">
    <h1><i class="bi bi-upload me-2"></i>Enviar Documento</h1>
    <a href="index.php?page=employees&action=show&id=<?= $employee['id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Documento para: <?= Sanitize::e($employee['full_name']) ?>
            </div>
            <div class="card-body">
                <form method="POST" action="index.php?page=documents&action=store" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">Tipo de Documento</label>
                            <?php $preType = $_GET['type'] ?? ''; ?>
                            <select name="doc_type" class="form-select" required>
                                <option value="">Selecione</option>
                                <?php foreach (['RG','CPF','Contrato','Certificado','Atestado','Treinamento','EPI','Outro'] as $t): ?>
                                    <option value="<?= $t ?>" <?= $preType === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label required">Título / Descrição</label>
                            <input type="text" name="title" class="form-control" required placeholder="Ex: Contrato de trabalho 2024">
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Arquivo</label>
                            <input type="file" name="file" class="form-control" required
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                            <small class="text-muted">Formatos: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX. Máximo: 5MB.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload me-1"></i> Enviar Documento
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
