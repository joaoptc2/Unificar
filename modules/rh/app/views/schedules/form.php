<?php
$isEdit = $isEdit ?? !empty($schedule['id']);
$formAction = $isEdit ? 'index.php?page=schedules&action=update' : 'index.php?page=schedules&action=store';
?>

<div class="page-header">
    <h1><i class="bi bi-calendar-plus me-2"></i><?= $isEdit ? 'Editar Compromisso' : 'Novo Compromisso' ?></h1>
    <a href="index.php?page=schedules" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= $formAction ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= Sanitize::e($schedule['title'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo</label>
                            <select name="event_type" class="form-select">
                                <?php
                                $types = ['compromisso'=>'Compromisso','vencimento'=>'Vencimento','reuniao'=>'Reunião','treinamento'=>'Treinamento','outro'=>'Outro'];
                                foreach ($types as $val => $label):
                                ?>
                                    <option value="<?= $val ?>" <?= ($schedule['event_type'] ?? 'compromisso') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Funcionário</label>
                            <select name="employee_id" class="form-select">
                                <option value="">Geral (sem funcionário)</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($schedule['employee_id'] ?? 0) == $emp['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($emp['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cor</label>
                            <input type="color" name="color" class="form-control form-control-color w-100"
                                   value="<?= Sanitize::e($schedule['color'] ?? '#0d6efd') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Data</label>
                            <input type="date" name="event_date" class="form-control"
                                   value="<?= Sanitize::e($schedule['event_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hora Início</label>
                            <input type="time" name="event_time" class="form-control"
                                   value="<?= Sanitize::e($schedule['event_time'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hora Fim</label>
                            <input type="time" name="end_time" class="form-control"
                                   value="<?= Sanitize::e($schedule['end_time'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="3"><?= Sanitize::e($schedule['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Cadastrar' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
