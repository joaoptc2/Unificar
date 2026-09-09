<div class="page-header">
    <h1><i class="bi bi-gift me-2"></i>Aniversariantes</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('birthdays.export')): ?>
            <a href="index.php?m=rh&page=birthdays&action=print&month=<?= (int)$month ?>&year=<?= (int)$year ?><?= $department ? '&department=' . (int)$department : '' ?>"
               target="_blank" class="btn btn-primary btn-sm">
                <i class="bi bi-printer me-1"></i> Exportar A4 (imprimir/PDF)
            </a>
        <?php endif; ?>
        <?php if (core_can('birthdays.configure')): ?>
            <a href="<?= core_admin_url('rh', 'birthdays') ?>" class="btn btn-outline-secondary btn-sm" title="Personalizar o layout do cartaz A4">
                <i class="bi bi-sliders me-1"></i> Layout do A4
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="rh">
        <input type="hidden" name="page" value="birthdays">
        <div class="col-md-3">
            <label class="form-label">Mês</label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Ano (idade / cartaz)</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= (int)$year ?>" min="2000" max="2100">
        </div>
        <div class="col-md-3">
            <label class="form-label">Departamento</label>
            <select name="department" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $department == $d['id'] ? 'selected' : '' ?>><?= Sanitize::e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-cake2 me-1"></i> Aniversariantes de <?= $monthNames[$month] ?>
        <span class="badge bg-primary ms-2"><?= count($birthdays) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($birthdays)): ?>
            <p class="text-muted text-center py-4">Nenhum aniversariante neste mês.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php
                $today = (int)date('j');
                $currentMonth = (int)date('n');
                foreach ($birthdays as $b):
                    $isToday = ($b['birth_day'] == $today && $month == $currentMonth);
                    $age = (int)$year - (int)date('Y', strtotime($b['birth_date']));
                ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="card h-100 <?= $isToday ? 'border-warning' : 'border-0' ?> shadow-sm">
                            <div class="card-body text-center py-3">
                                <?php if ($isToday): ?>
                                    <span class="badge bg-warning text-dark mb-2">Hoje!</span>
                                <?php endif; ?>
                                <?php if ($b['photo']): ?>
                                    <img src="<?= Sanitize::e(Upload::publicUrl($b['photo'])) ?>" class="employee-photo-lg mb-2" alt="">
                                <?php else: ?>
                                    <div class="employee-photo-lg bg-light d-flex align-items-center justify-content-center mx-auto mb-2">
                                        <i class="bi bi-person fs-1 text-muted"></i>
                                    </div>
                                <?php endif; ?>
                                <h6 class="fw-bold mb-0"><?= Sanitize::e($b['full_name']) ?></h6>
                                <small class="text-muted"><?= Sanitize::e($b['department_name'] ?? '') ?></small>
                                <div class="mt-2">
                                    <span class="badge bg-light text-dark">
                                        <?= sprintf('%02d/%02d', $b['birth_day'], $month) ?> — <?= $age ?> anos
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
