<?php $isEdit = !empty($item['id']); ?>
<div class="page-header">
    <h1><i class="bi bi-sun me-2"></i><?= $isEdit ? 'Editar' : 'Novas' ?> Férias</h1>
    <a href="index.php?m=rh&page=vacations" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-md-8">
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="POST" action="index.php?m=rh&page=vacations&action=<?= $isEdit ? 'update' : 'store' ?>">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $item['id'] ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label required">Funcionario</label>
            <select name="employee_id" class="form-select" required>
                <option value="">Selecione</option>
                <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= ($item['employee_id'] ?? '') == $e['id'] ? 'selected' : '' ?>><?= Sanitize::e($e['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Parcela</label>
            <select name="installment" class="form-select">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                    <option value="<?= $i ?>" <?= ($item['installment'] ?? 1) == $i ? 'selected' : '' ?>><?= $i ?>a parcela</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Abono pecuniario (dias)</label>
            <input type="number" name="sold_days" class="form-control" min="0" max="10" value="<?= (int)($item['sold_days'] ?? 0) ?>">
        </div>
        <div class="col-md-3"><label class="form-label required">Inicio gozo</label><input type="date" name="start_date" class="form-control" required value="<?= Sanitize::e($item['start_date'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label required">Fim gozo</label><input type="date" name="end_date" class="form-control" required value="<?= Sanitize::e($item['end_date'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label">Per. aquisitivo inicio</label><input type="date" name="period_start" class="form-control" value="<?= Sanitize::e($item['period_start'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label">Per. aquisitivo fim</label><input type="date" name="period_end" class="form-control" value="<?= Sanitize::e($item['period_end'] ?? '') ?>"></div>
        <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <?php foreach (['planejada','solicitada','aprovada','em_gozo','concluida'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($item['status'] ?? 'planejada') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12"><label class="form-label">Observacoes</label><textarea name="notes" class="form-control" rows="2"><?= Sanitize::e($item['notes'] ?? '') ?></textarea></div>
        <div class="col-12 text-end">
            <a href="index.php?m=rh&page=vacations" class="btn btn-outline-secondary me-2">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Cadastrar' ?></button>
        </div>
    </div>
</form>
</div></div></div></div>
