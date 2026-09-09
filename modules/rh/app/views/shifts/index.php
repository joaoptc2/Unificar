<div class="page-header">
    <h1><i class="bi bi-calendar2-week me-2"></i>Escalas de Plantao</h1>
</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
    <form class="row g-2 align-items-end">
        <input type="hidden" name="m" value="rh"><input type="hidden" name="page" value="shifts">
        <div class="col-md-4">
            <label class="form-label small">Departamento</label>
            <select name="department" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Selecione o departamento</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $deptId == $d['id'] ? 'selected' : '' ?>><?= Sanitize::e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <div class="d-flex gap-2 align-items-center">
                <a href="index.php?m=rh&page=shifts&department=<?= $deptId ?>&week=<?= $prevWeek ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
                <span class="fw-semibold small"><?= date('d/m', strtotime($weekStart)) ?> - <?= date('d/m/Y', strtotime($weekEnd)) ?></span>
                <a href="index.php?m=rh&page=shifts&department=<?= $deptId ?>&week=<?= $nextWeek ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </form>
</div></div>

<?php if ($deptId): ?>
<?php if (core_can('shifts.create')): ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle me-1"></i> Adicionar escala</div><div class="card-body">
<form method="POST" action="index.php?m=rh&page=shifts&action=store" class="row g-2 align-items-end">
    <?= Csrf::field() ?>
    <input type="hidden" name="department_id" value="<?= $deptId ?>">
    <div class="col-md-3">
        <label class="form-label small">Funcionario</label>
        <select name="employee_id" class="form-select form-select-sm" required>
            <option value="">Selecione</option>
            <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= Sanitize::e($e['full_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><label class="form-label small">Data</label><input type="date" name="shift_date" class="form-control form-control-sm" required value="<?= $weekStart ?>"></div>
    <div class="col-md-1"><label class="form-label small">Inicio</label><input type="time" name="start_time" class="form-control form-control-sm" required value="07:00"></div>
    <div class="col-md-1"><label class="form-label small">Fim</label><input type="time" name="end_time" class="form-control form-control-sm" required value="19:00"></div>
    <div class="col-md-2">
        <label class="form-label small">Template</label>
        <select name="template_id" class="form-select form-select-sm">
            <option value="">Nenhum</option>
            <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>"><?= Sanitize::e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small">Tipo</label>
        <select name="type" class="form-select form-select-sm">
            <option value="regular">Regular</option><option value="extra">Extra</option><option value="sobreaviso">Sobreaviso</option><option value="cobertura">Cobertura</option>
        </select>
    </div>
    <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i></button></div>
</form>
</div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
<div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Funcionario</th><th>Data</th><th>Horario</th><th>Template</th><th>Tipo</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($shifts)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma escala na semana selecionada.</td></tr>
        <?php else: foreach ($shifts as $s): ?>
            <tr>
                <td class="fw-semibold"><?= Sanitize::e($s['employee_name']) ?></td>
                <td><?= Sanitize::formatDate($s['shift_date']) ?></td>
                <td><?= substr($s['start_time'],0,5) ?> - <?= substr($s['end_time'],0,5) ?></td>
                <td><span class="badge" style="background:<?= Sanitize::e($s['color'] ?? '#6c757d') ?>"><?= Sanitize::e($s['template_name'] ?? '-') ?></span></td>
                <td><?= ucfirst($s['type']) ?></td>
                <td><span class="badge <?= match($s['status']) { 'realizado' => 'bg-success', 'falta' => 'bg-danger', 'troca_pendente' => 'bg-warning text-dark', default => 'bg-secondary' } ?>"><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span></td>
                <td class="text-end">
                    <?php if (core_can('shifts.delete')): ?>
                        <form method="POST" action="index.php?m=rh&page=shifts&action=delete" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Remover?"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</div></div>
<?php else: ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="bi bi-arrow-up fs-3 d-block mb-2"></i>Selecione um departamento acima para ver as escalas.</div></div>
<?php endif; ?>
