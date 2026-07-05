<div class="page-header">
    <h1><i class="bi bi-file-medical me-2"></i>Novo Atestado</h1>
    <a href="index.php?m=rh&page=employees&action=show&id=<?= $employee['id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Atestado para: <?= Sanitize::e($employee['full_name']) ?>
            </div>
            <div class="card-body">
                <form method="POST" action="index.php?m=rh&page=certificates&action=store" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">Data do Atestado</label>
                            <input type="date" name="issue_date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Dias de Afastamento</label>
                            <input type="number" name="days" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">CID</label>
                            <input type="text" name="cid" class="form-control" placeholder="Ex: J11">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nome do Médico</label>
                            <input type="text" name="doctor_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CRM</label>
                            <input type="text" name="doctor_crm" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Arquivo do Atestado</label>
                            <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Cadastrar Atestado
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
