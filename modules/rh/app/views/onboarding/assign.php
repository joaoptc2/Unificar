<div class="page-header">
    <h1><i class="bi bi-person-plus me-2"></i>Atribuir Checklist</h1>
    <a href="index.php?page=onboarding" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-md-6">
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="POST" action="index.php?page=onboarding&action=assign_store">
    <?= Csrf::field() ?>
    <div class="mb-3"><label class="form-label required">Funcionario</label><select name="employee_id" class="form-select" required><option value="">Selecione</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= Sanitize::e($e['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label required">Template</label><select name="template_id" class="form-select" required><option value="">Selecione</option><?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>"><?= Sanitize::e($t['name']) ?> (<?= ucfirst($t['type']) ?>)</option><?php endforeach; ?></select></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i> Atribuir</button>
</form>
</div></div></div></div>
