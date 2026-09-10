<?php
/**
 * MÓDULO DE MANUTENÇÃO PREVENTIVA
 * Planos de manutenção com frequência e agendamento
 */
requireModule("maintenance");

$hid    = hospitalId();
$action = $_GET['action'] ?? 'list';

// ============================================================
// PROCESSAR POST
// ============================================================
if (manPostIsValid()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        core_require('maintenance.create');
        $equipmentId = (int)($_POST['equipment_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $frequency   = $_POST['frequency'] ?? 'monthly';
        $nextDate    = trim($_POST['next_date'] ?? '');

        if ($title === '' || $equipmentId < 1 || $nextDate === '') {
            flash('error', 'Título, equipamento e próxima data são obrigatórios.');
        } else {
            try {
                db()->prepare("
                    INSERT INTO man_maintenance_plans (hospital_id, equipment_id, title, description, frequency, next_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ")->execute([$hid, $equipmentId, $title, $description, $frequency, $nextDate]);
                auditLog('create', 'maintenance_plans', (int)db()->lastInsertId());
                flash('success', 'Plano de manutenção criado!');
            } catch (Exception $ex) {
                error_log('manutencao: ' . $ex->getMessage()); flash('error', 'Não foi possível concluir a operação. Tente novamente.');
            }
        }
        redirect(url('maintenance'));
    }

    if ($act === 'edit') {
        core_require('maintenance.edit');
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $frequency   = $_POST['frequency'] ?? 'monthly';
        $nextDate    = trim($_POST['next_date'] ?? '');
        $status      = $_POST['status'] ?? 'active';

        if ($title === '' || $nextDate === '') {
            flash('error', 'Título e próxima data são obrigatórios.');
        } else {
            db()->prepare("
                UPDATE man_maintenance_plans SET title=?, description=?, frequency=?, next_date=?, status=?
                WHERE id=? AND hospital_id=?
            ")->execute([$title, $description, $frequency, $nextDate, $status, $id, $hid]);
            auditLog('update', 'maintenance_plans', $id);
            flash('success', 'Plano atualizado!');
        }
        redirect(url('maintenance'));
    }

    if ($act === 'execute') {
        // Executar plano = atualizar o plano (edit) + gerar OS derivada
        core_require('maintenance.edit');
        $id = (int)($_POST['id'] ?? 0);
        try {
            // Buscar plano
            $stmt = db()->prepare("SELECT * FROM man_maintenance_plans WHERE id = ? AND hospital_id = ?");
            $stmt->execute([$id, $hid]);
            $plan = $stmt->fetch();

            if ($plan) {
                // Criar OS automaticamente
                $osNumber = generateOsNumber();
                db()->prepare("
                    INSERT INTO man_service_orders (hospital_id, equipment_id, os_number, type, priority, status, title, description, created_by)
                    VALUES (?, ?, ?, 'preventive', 'medium', 'open', ?, ?, ?)
                ")->execute([$hid, $plan['equipment_id'], $osNumber, 'Manutenção: '.$plan['title'], $plan['description'], $_SESSION['user_id']]);

                // Atualizar próxima data
                $nextDate = calcNextDate($plan['next_date'], $plan['frequency']);
                db()->prepare("UPDATE man_maintenance_plans SET last_executed = NOW(), next_date = ? WHERE id = ?")->execute([$nextDate, $id]);

                auditLog('execute', 'maintenance_plans', $id);
                flash('success', "OS {$osNumber} criada! Próxima manutenção: {$nextDate}");
            }
        } catch (Exception $ex) {
            error_log('manutencao: ' . $ex->getMessage()); flash('error', 'Não foi possível concluir a operação. Tente novamente.');
        }
        redirect(url('maintenance'));
    }

    if ($act === 'delete') {
        core_require('maintenance.delete');
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM man_maintenance_plans WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
        auditLog('delete', 'maintenance_plans', $id);
        flash('success', 'Plano removido.');
        redirect(url('maintenance'));
    }
}

// ============================================================
// DADOS
// ============================================================
$equipments = db()->prepare("SELECT id, name, code FROM man_equipment WHERE hospital_id = ? AND status = 'active' ORDER BY name");
$equipments->execute([$hid]);
$equipments = $equipments->fetchAll();

$freqLabels = ['daily'=>'Diária','weekly'=>'Semanal','biweekly'=>'Quinzenal','monthly'=>'Mensal','quarterly'=>'Trimestral','semiannual'=>'Semestral','annual'=>'Anual'];

$plans = db()->prepare("
    SELECT mp.*, e.name AS equip_name, e.code AS equip_code
    FROM man_maintenance_plans mp
    LEFT JOIN man_equipment e ON e.id = mp.equipment_id
    WHERE mp.hospital_id = ?
    ORDER BY mp.next_date ASC
");
$plans->execute([$hid]);
$plans = $plans->fetchAll();

$osStatusLabels = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Ag. Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];

$pageTitle = 'Manutenção Preventiva';
ob_start();
?>

<!-- PAGE HEADER -->
<div class="page-header">
    <h1><i class="bi bi-tools me-2"></i>Manutenção Preventiva</h1>
    <?php if (core_can('maintenance.create')): ?>
    <div class="d-flex gap-2">
        <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo Plano</button>
    </div>
    <?php endif; ?>
</div>

<!-- LISTA -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>Título</th>
                        <th>Frequência</th>
                        <th>Próxima Data</th>
                        <th>Última Execução</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($plans)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhum plano cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($plans as $p):
                        $overdue = $p['next_date'] && strtotime($p['next_date']) < strtotime('today');
                    ?>
                    <tr class="<?php echo $overdue ? 'table-danger' : ''; ?>">
                        <td><?php echo e(($p['equip_code'] ? $p['equip_code'].' - ' : '') . ($p['equip_name'] ?? 'N/A')); ?></td>
                        <td><strong><?php echo e($p['title']); ?></strong></td>
                        <td><?php echo $freqLabels[$p['frequency']] ?? $p['frequency']; ?></td>
                        <td>
                            <?php echo formatDate($p['next_date']); ?>
                            <?php if ($overdue): ?> <span class="text-danger fw-bold">(Atrasada)</span><?php endif; ?>
                        </td>
                        <td><?php echo formatDate($p['last_executed'] ?? '', 'd/m/Y H:i'); ?></td>
                        <td><span class="badge badge-<?php echo $p['status']; ?>"><?php echo $p['status']==='active'?'Ativo':'Inativo'; ?></span></td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button onclick="openModal('histPlan<?php echo $p['id']; ?>')" class="btn btn-outline-info btn-action" title="Histórico"><i class="bi bi-clock-history"></i></button>
                                <?php if (core_can('maintenance.edit')): ?>
                                <form method="POST" style="display:inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="execute">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-outline-success btn-action" title="Gerar OS" data-confirm="Gerar OS preventiva para '<?php echo e($p['title']); ?>'?"><i class="bi bi-play-fill"></i></button>
                                </form>
                                <button onclick="openModal('editPlan<?php echo $p['id']; ?>')" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                                <?php if (core_can('maintenance.delete')): ?>
                                <form method="POST" style="display:inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir este plano?"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAIS DE HISTÓRICO -->
<?php foreach ($plans as $p):
    $planOs = db()->prepare("SELECT os_number, title, status, created_at, completed_at FROM man_service_orders WHERE hospital_id = ? AND equipment_id = ? AND type = 'preventive' ORDER BY created_at DESC LIMIT 20");
    $planOs->execute([$hid, $p['equipment_id']]);
    $planOsRows = $planOs->fetchAll();
?>
<div class="modal fade" id="histPlan<?php echo $p['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fw-semibold"><i class="bi bi-clock-history me-1"></i> Histórico: <?php echo e($p['title']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <?php if (empty($planOsRows)): ?>
                    <p class="text-center text-muted py-4 mb-0">Nenhuma OS preventiva registrada para este equipamento.</p>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                        <thead><tr><th>OS</th><th>Título</th><th>Status</th><th>Criada</th><th>Concluída</th></tr></thead>
                        <tbody>
                        <?php foreach ($planOsRows as $po): ?>
                        <tr>
                            <td><strong><?php echo e($po['os_number']); ?></strong></td>
                            <td><?php echo e($po['title']); ?></td>
                            <td><span class="badge badge-<?php echo e($po['status']); ?>"><?php echo e($osStatusLabels[$po['status']] ?? $po['status']); ?></span></td>
                            <td class="text-muted"><?php echo formatDate($po['created_at']); ?></td>
                            <td class="text-muted"><?php echo formatDate($po['completed_at'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (core_can('maintenance.create')): ?>
<!-- MODAL NOVO PLANO -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-labelledby="modalAddLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="modalAddLabel">Novo Plano de Manutenção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?php echo url('maintenance'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Equipamento</label>
                            <select name="equipment_id" class="form-select" required>
                                <option value="">Selecione</option>
                                <?php foreach ($equipments as $eq): ?>
                                    <option value="<?php echo $eq['id']; ?>"><?php echo e($eq['code'] ? $eq['code'].' - ' : ''); echo e($eq['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Frequência</label>
                            <select name="frequency" class="form-select">
                                <?php foreach ($freqLabels as $k=>$v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $k==='monthly'?'selected':''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Próxima Data</label>
                            <input type="date" name="next_date" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm">Criar Plano</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if (core_can('maintenance.edit')): ?>
<!-- MODAIS EDIÇÃO -->
<?php foreach ($plans as $p): ?>
<div class="modal fade" id="editPlan<?php echo $p['id']; ?>" tabindex="-1" aria-labelledby="editPlanLabel<?php echo $p['id']; ?>" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="editPlanLabel<?php echo $p['id']; ?>">Editar: <?php echo e($p['title']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?php echo url('maintenance'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control" value="<?php echo e($p['title']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Frequência</label>
                            <select name="frequency" class="form-select">
                                <?php foreach ($freqLabels as $k=>$v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $p['frequency']===$k?'selected':''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Próxima Data</label>
                            <input type="date" name="next_date" class="form-control" value="<?php echo e($p['next_date']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo $p['status']==='active'?'selected':''; ?>>Ativo</option>
                                <option value="inactive" <?php echo $p['status']==='inactive'?'selected':''; ?>>Inativo</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="3"><?php echo e($p['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
