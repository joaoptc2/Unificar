<?php
/**
 * MÓDULO DE ORDENS DE SERVIÇO
 * CRUD com foto, observação, filtro, link anônimo, status
 */
requireModule("service-orders");

$hid    = hospitalId();
$action = $_GET['action'] ?? 'list';

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    requireWrite();
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $title       = trim($_POST['title'] ?? '');
        $type        = $_POST['type'] ?? 'corrective';
        $priority    = $_POST['priority'] ?? 'medium';
        $equipmentId = ((int)($_POST['equipment_id'] ?? 0)) ?: null;
        $assignedTo  = ((int)($_POST['assigned_to'] ?? 0)) ?: null;
        $scheduledDate = trim($_POST['scheduled_date'] ?? '') ?: null;
        $description = trim($_POST['description'] ?? '') ?: null;
        $observation = trim($_POST['observation'] ?? '') ?: null;

        if ($title === '') {
            flash('error', 'Título é obrigatório.');
        } else {
            try {
                $osNumber = generateOsNumber();
                $photoPath = null;
                if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $photoPath = uploadFile($_FILES['photo'], 'os_photos');
                }

                $stmt = db()->prepare("
                    INSERT INTO service_orders (hospital_id, equipment_id, os_number, type, priority, status, title, description, observation, photo_path, assigned_to, created_by, scheduled_date)
                    VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$hid, $equipmentId, $osNumber, $type, $priority, $title, $description, $observation, $photoPath, $assignedTo, $_SESSION['user_id'], $scheduledDate]);
                $newId = (int)db()->lastInsertId();
                auditLog('create', 'service_orders', $newId);
                addOsHistory($newId, 'OS criada', "Tipo: {$type}, Prioridade: {$priority}");
                flash('success', "OS {$osNumber} criada com sucesso!");
            } catch (Exception $ex) {
                flash('error', 'Erro ao criar OS: ' . $ex->getMessage());
            }
        }
        redirect(url('service-orders'));
    }

    if ($act === 'edit') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $type        = $_POST['type'] ?? 'corrective';
        $priority    = $_POST['priority'] ?? 'medium';
        $equipmentId = ((int)($_POST['equipment_id'] ?? 0)) ?: null;
        $assignedTo  = ((int)($_POST['assigned_to'] ?? 0)) ?: null;
        $scheduledDate = trim($_POST['scheduled_date'] ?? '') ?: null;
        $description = trim($_POST['description'] ?? '') ?: null;
        $observation = trim($_POST['observation'] ?? '') ?: null;
        $solution    = trim($_POST['solution'] ?? '') ?: null;
        $laborHours      = trim($_POST['labor_hours'] ?? '') !== '' ? (float)$_POST['labor_hours'] : null;
        $laborCostPerHour = trim($_POST['labor_cost_per_hour'] ?? '') !== '' ? (float)$_POST['labor_cost_per_hour'] : null;

        if ($title === '') {
            flash('error', 'Título é obrigatório.');
        } else {
            try {
                $photoPath = null;
                if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $photoPath = uploadFile($_FILES['photo'], 'os_photos');
                }

                $sql = "UPDATE service_orders SET title=?, type=?, priority=?, equipment_id=?, assigned_to=?, scheduled_date=?, description=?, observation=?, solution=?";
                $params = [$title, $type, $priority, $equipmentId, $assignedTo, $scheduledDate, $description, $observation, $solution];

                try {
                    $sql .= ", labor_hours=?, labor_cost_per_hour=?";
                    $params[] = $laborHours;
                    $params[] = $laborCostPerHour;
                } catch (\Throwable $ignored) {}

                if ($photoPath) {
                    $sql .= ", photo_path=?";
                    $params[] = $photoPath;
                }
                $sql .= " WHERE id=? AND hospital_id=?";
                $params[] = $id;
                $params[] = $hid;

                db()->prepare($sql)->execute($params);
                auditLog('update', 'service_orders', $id);
                addOsHistory($id, 'OS editada', "Título: {$title}");
                flash('success', 'OS atualizada!');
            } catch (Exception $ex) {
                flash('error', 'Erro: ' . $ex->getMessage());
            }
        }
        redirect(url('service-orders'));
    }

    if ($act === 'change_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $valid  = ['open', 'in_progress', 'waiting_part', 'completed', 'cancelled'];
        if (in_array($status, $valid, true)) {
            try {
                $extra = '';
                $params = [$status];
                if ($status === 'in_progress') { $extra = ', started_at = NOW()'; }
                if ($status === 'completed')   { $extra = ', completed_at = NOW()'; }

                // Downtime tracking
                try {
                    if ($status === 'in_progress') {
                        $chkOs = db()->prepare("SELECT type, downtime_start FROM service_orders WHERE id=? AND hospital_id=?");
                        $chkOs->execute([$id, $hid]);
                        $chkRow = $chkOs->fetch();
                        if ($chkRow && $chkRow['type'] === 'corrective' && empty($chkRow['downtime_start'])) {
                            $extra .= ', downtime_start = NOW()';
                        }
                    }
                    if ($status === 'completed') {
                        $extra .= ', downtime_end = NOW()';
                    }
                } catch (\Throwable $ignored) {}

                // Signature data
                try {
                    $sigData = $_POST['signature_data'] ?? '';
                    if (is_string($sigData) && strncmp($sigData, 'data:image/png;base64,', 22) === 0 && strlen($sigData) <= 150000) {
                        $extra .= ', signature_data = ?';
                        $params[] = $sigData;
                    }
                } catch (\Throwable $ignored) {}

                $params[] = $id;
                $params[] = $hid;
                db()->prepare("UPDATE service_orders SET status = ?{$extra} WHERE id = ? AND hospital_id = ?")->execute($params);
                auditLog('status_change', 'service_orders', $id, "Status: {$status}");
                $statusLabelsHist = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Ag. Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];
                addOsHistory($id, 'Status alterado', 'Novo status: ' . ($statusLabelsHist[$status] ?? $status));
                flash('success', 'Status atualizado!');
            } catch (Exception $ex) {
                flash('error', 'Erro: ' . $ex->getMessage());
            }
        }
        redirect(url('service-orders'));
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM os_history WHERE os_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM service_orders WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
            auditLog('delete', 'service_orders', $id);
            flash('success', 'OS excluída.');
        } catch (Exception $ex) {
            flash('error', 'Erro ao excluir: ' . $ex->getMessage());
        }
        redirect(url('service-orders'));
    }

    if ($act === 'generate_anonymous_link') {
        $id = (int)($_POST['id'] ?? 0);
        $token = generateToken(16);
        db()->prepare("UPDATE service_orders SET anonymous_token = ? WHERE id = ? AND hospital_id = ?")->execute([$token, $id, $hid]);
        flash('success', 'Link anônimo gerado! Token: ' . $token);
        redirect(url('service-orders', ['action' => 'edit', 'id' => $id]));
    }

    if ($act === 'add_part') {
        $osId     = (int)($_POST['os_id'] ?? 0);
        $partId   = (int)($_POST['part_id'] ?? 0);
        $qty      = (int)($_POST['quantity'] ?? 1);
        if ($qty < 1) $qty = 1;
        try {
            // Get part info
            $partStmt = db()->prepare("SELECT id, name, unit_cost, quantity FROM parts WHERE id=? AND hospital_id=?");
            $partStmt->execute([$partId, $hid]);
            $part = $partStmt->fetch();
            if (!$part) { flash('error', 'Peça não encontrada.'); redirect(url('service-orders', ['action'=>'edit','id'=>$osId])); }
            if ($part['quantity'] < $qty) { flash('error', 'Estoque insuficiente para esta peça.'); redirect(url('service-orders', ['action'=>'edit','id'=>$osId])); }

            $unitCost = (float)$part['unit_cost'];

            // Check if part already linked
            $existing = db()->prepare("SELECT id, quantity FROM os_parts WHERE os_id=? AND part_id=?");
            $existing->execute([$osId, $partId]);
            $exRow = $existing->fetch();
            if ($exRow) {
                db()->prepare("UPDATE os_parts SET quantity = quantity + ?, unit_cost=? WHERE id=?")->execute([$qty, $unitCost, $exRow['id']]);
            } else {
                db()->prepare("INSERT INTO os_parts (os_id, part_id, quantity, unit_cost) VALUES (?,?,?,?)")->execute([$osId, $partId, $qty, $unitCost]);
            }

            // Deduct from stock
            db()->prepare("UPDATE parts SET quantity = quantity - ? WHERE id=?")->execute([$qty, $partId]);

            // Record stock movement
            try {
                db()->prepare("INSERT INTO stock_movements (hospital_id, part_id, type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,'out',?,'os',?,?,?)")
                    ->execute([$hid, $partId, $qty, $osId, 'Peça utilizada na OS', $_SESSION['user_id'] ?? null]);
            } catch (\Throwable $ignored) {}

            flash('success', 'Peça adicionada.');
        } catch (\Throwable $ex) { flash('error', 'Erro ao adicionar peça: ' . $ex->getMessage()); }
        redirect(url('service-orders', ['action'=>'edit','id'=>$osId]));
    }

    if ($act === 'remove_part') {
        $osId     = (int)($_POST['os_id'] ?? 0);
        $osPartId = (int)($_POST['os_part_id'] ?? 0);
        try {
            // Get os_part info
            $opStmt = db()->prepare("SELECT op.part_id, op.quantity FROM os_parts op JOIN service_orders so ON so.id=op.os_id WHERE op.id=? AND so.hospital_id=?");
            $opStmt->execute([$osPartId, $hid]);
            $opRow = $opStmt->fetch();
            if ($opRow) {
                // Restore stock
                db()->prepare("UPDATE parts SET quantity = quantity + ? WHERE id=?")->execute([$opRow['quantity'], $opRow['part_id']]);
                db()->prepare("DELETE FROM os_parts WHERE id=?")->execute([$osPartId]);

                // Record stock movement
                try {
                    db()->prepare("INSERT INTO stock_movements (hospital_id, part_id, type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,'in',?,'os',?,?,?)")
                        ->execute([$hid, $opRow['part_id'], $opRow['quantity'], $osId, 'Peça devolvida da OS', $_SESSION['user_id'] ?? null]);
                } catch (\Throwable $ignored) {}

                flash('success', 'Peça removida.');
            }
        } catch (\Throwable $ex) { flash('error', 'Erro ao remover peça: ' . $ex->getMessage()); }
        redirect(url('service-orders', ['action'=>'edit','id'=>$osId]));
    }

    if ($act === 'save_signature') {
        $osId = (int)($_POST['os_id'] ?? 0);
        $sigData = $_POST['signature_data'] ?? '';
        try {
            if (is_string($sigData) && strncmp($sigData, 'data:image/png;base64,', 22) === 0 && strlen($sigData) <= 150000) {
                db()->prepare("UPDATE service_orders SET signature_data=? WHERE id=? AND hospital_id=?")->execute([$sigData, $osId, $hid]);
                flash('success', 'Assinatura salva.');
            } else {
                flash('error', 'Assinatura inválida.');
            }
        } catch (\Throwable $ex) { flash('error', 'Erro ao salvar assinatura: ' . $ex->getMessage()); }
        redirect(url('service-orders', ['action'=>'edit','id'=>$osId]));
    }
}

// ============================================================
// OBTER DADOS
// ============================================================
$equipments = db()->prepare("SELECT id, name, code FROM equipment WHERE hospital_id = ? AND status = 'active' ORDER BY name");
$equipments->execute([$hid]);
$equipments = $equipments->fetchAll();

$users = db()->prepare("SELECT id, name FROM users WHERE hospital_id = ? AND status = 'active' ORDER BY name");
$users->execute([$hid]);
$users = $users->fetchAll();

$statusLabels = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Aguardando Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];
$typeLabels   = ['preventive'=>'Preventiva','corrective'=>'Corretiva','predictive'=>'Preditiva','calibration'=>'Calibração','inspection'=>'Inspeção'];
$prioLabels   = ['low'=>'Baixa','medium'=>'Média','high'=>'Alta','critical'=>'Crítica'];

$pageTitle = 'Ordens de Serviço';
ob_start();

// ============================================================
// VIEW: EDITAR
// ============================================================
if ($action === 'edit'):
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM service_orders WHERE id = ? AND hospital_id = ?");
    $stmt->execute([$id, $hid]);
    $os = $stmt->fetch();
    if (!$os) { flash('error', 'OS não encontrada.'); redirect(url('service-orders')); }
?>
<div class="page-header">
    <h1><i class="bi bi-pencil me-2"></i>Editar OS: <?php echo e($os['os_number']); ?></h1>
    <a href="<?php echo url('service-orders'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?php echo url('service-orders'); ?>" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?php echo $os['id']; ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required">Título</label>
                    <input type="text" class="form-control" name="title" value="<?php echo e($os['title']); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="type">
                        <?php foreach ($typeLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $os['type']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Prioridade</label>
                    <select class="form-select" name="priority">
                        <?php foreach ($prioLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $os['priority']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Equipamento (opcional)</label>
                    <select class="form-select" name="equipment_id">
                        <option value="">Nenhum</option>
                        <?php foreach ($equipments as $eq): ?><option value="<?php echo $eq['id']; ?>" <?php echo $os['equipment_id']==$eq['id']?'selected':''; ?>><?php echo e($eq['code'] ? $eq['code'].' - ' : ''); echo e($eq['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Responsável</label>
                    <select class="form-select" name="assigned_to">
                        <option value="">Nenhum</option>
                        <?php foreach ($users as $u): ?><option value="<?php echo $u['id']; ?>" <?php echo $os['assigned_to']==$u['id']?'selected':''; ?>><?php echo e($u['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Data Prevista</label>
                    <input type="date" class="form-control" name="scheduled_date" value="<?php echo e($os['scheduled_date'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Descrição</label>
                    <textarea class="form-control" name="description" rows="3"><?php echo e($os['description'] ?? ''); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Observação</label>
                    <textarea class="form-control" name="observation" rows="3"><?php echo e($os['observation'] ?? ''); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Solução</label>
                    <textarea class="form-control" name="solution" rows="3"><?php echo e($os['solution'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Foto (substituir)</label>
                    <input type="file" class="form-control" name="photo" accept="image/*">
                </div>
                <?php try { ?>
                <div class="col-md-3">
                    <label class="form-label">Horas de Trabalho</label>
                    <input type="number" class="form-control" name="labor_hours" step="0.5" min="0" value="<?php echo e($os['labor_hours'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Custo/Hora (R$)</label>
                    <input type="number" class="form-control" name="labor_cost_per_hour" step="0.01" min="0" value="<?php echo e($os['labor_cost_per_hour'] ?? ''); ?>">
                </div>
                <?php } catch (\Throwable $ignored) {} ?>
                <?php try { ?>
                <?php if (!empty($os['downtime_start'])): ?>
                <div class="col-md-3">
                    <label class="form-label">Início Parada</label>
                    <input type="datetime-local" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($os['downtime_start'])); ?>" readonly>
                </div>
                <?php endif; ?>
                <?php if (!empty($os['downtime_end'])): ?>
                <div class="col-md-3">
                    <label class="form-label">Fim Parada</label>
                    <input type="datetime-local" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($os['downtime_end'])); ?>" readonly>
                </div>
                <?php endif; ?>
                <?php } catch (\Throwable $ignored) {} ?>
            </div>
            <?php if ($os['photo_path']): ?>
                <p class="small text-muted mt-2 mb-0">Foto atual: <?php echo e($os['photo_path']); ?></p>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1"></i> Salvar Alterações</button>
        </form>
    </div>
</div>

<?php
// ── Parts Used ──
$osParts = [];
$partsList = [];
$partsCostTotal = 0;
try {
    $opStmt = db()->prepare("SELECT op.id, op.quantity, op.unit_cost, p.name AS part_name, p.code AS part_code FROM os_parts op JOIN parts p ON p.id=op.part_id WHERE op.os_id=?");
    $opStmt->execute([$os['id']]);
    $osParts = $opStmt->fetchAll();
    foreach ($osParts as $op) { $partsCostTotal += (float)$op['unit_cost'] * (int)$op['quantity']; }

    $plStmt = db()->prepare("SELECT id, name, code, quantity FROM parts WHERE hospital_id=? AND quantity > 0 ORDER BY name");
    $plStmt->execute([$hid]);
    $partsList = $plStmt->fetchAll();
} catch (\Throwable $ignored) {}

if (!empty($osParts) || !empty($partsList)):
?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-box-seam me-1"></i> Peças Utilizadas</div>
    <div class="card-body">
        <?php if (!empty($osParts)): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Peça</th><th>Código</th><th>Qtd</th><th>Custo Unit.</th><th>Subtotal</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($osParts as $op): ?>
                <tr>
                    <td><?php echo e($op['part_name']); ?></td>
                    <td class="text-muted"><?php echo e($op['part_code'] ?? '—'); ?></td>
                    <td><?php echo (int)$op['quantity']; ?></td>
                    <td>R$ <?php echo number_format((float)$op['unit_cost'], 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format((float)$op['unit_cost'] * (int)$op['quantity'], 2, ',', '.'); ?></td>
                    <td class="text-end">
                        <?php if (canWrite()): ?>
                        <form method="POST" action="<?php echo url('service-orders'); ?>" class="d-inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="remove_part">
                            <input type="hidden" name="os_id" value="<?php echo $os['id']; ?>">
                            <input type="hidden" name="os_part_id" value="<?php echo $op['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Remover esta peça?"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (canWrite() && !empty($partsList)): ?>
        <form method="POST" action="<?php echo url('service-orders'); ?>" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_part">
            <input type="hidden" name="os_id" value="<?php echo $os['id']; ?>">
            <div class="col-md-5">
                <label class="form-label">Peça</label>
                <select class="form-select" name="part_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($partsList as $pl): ?><option value="<?php echo $pl['id']; ?>"><?php echo e(($pl['code'] ? $pl['code'].' - ' : '') . $pl['name'] . ' (est: '.$pl['quantity'].')'); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Quantidade</label>
                <input type="number" class="form-control" name="quantity" min="1" value="1" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Adicionar Peça</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
// ── Cost Summary ──
try {
    $laborHoursVal = (float)($os['labor_hours'] ?? 0);
    $laborCostHrVal = (float)($os['labor_cost_per_hour'] ?? 0);
    $laborCostTotal = $laborHoursVal * $laborCostHrVal;
    $totalCost = $partsCostTotal + $laborCostTotal;
    if ($partsCostTotal > 0 || $laborCostTotal > 0):
?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-currency-dollar me-1"></i> Resumo de Custos</div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-sm-4">
                <small class="text-muted d-block">Custo de Peças</small>
                <strong>R$ <?php echo number_format($partsCostTotal, 2, ',', '.'); ?></strong>
            </div>
            <div class="col-sm-4">
                <small class="text-muted d-block">Custo de Mão de Obra</small>
                <strong>R$ <?php echo number_format($laborCostTotal, 2, ',', '.'); ?></strong>
                <?php if ($laborHoursVal > 0): ?><br><small class="text-muted"><?php echo $laborHoursVal; ?>h x R$ <?php echo number_format($laborCostHrVal, 2, ',', '.'); ?></small><?php endif; ?>
            </div>
            <div class="col-sm-4">
                <small class="text-muted d-block">Custo Total</small>
                <strong class="text-primary fs-5">R$ <?php echo number_format($totalCost, 2, ',', '.'); ?></strong>
            </div>
        </div>
    </div>
</div>
<?php
    endif;
} catch (\Throwable $ignored) {}
?>

<?php
// ── Digital Signature ──
try {
    $hasSignature = !empty($os['signature_data']);
    $showSignaturePad = ($os['status'] === 'completed' || $os['status'] === 'in_progress') && !$hasSignature;
?>
<?php if ($hasSignature): ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-pen me-1"></i> Assinatura Digital</div>
    <div class="card-body text-center">
        <img src="<?php echo e($os['signature_data']); ?>" alt="Assinatura" class="img-fluid rounded border" style="max-width:500px">
        <small class="text-muted d-block mt-2">Assinatura registrada</small>
    </div>
</div>
<?php elseif ($showSignaturePad && canWrite()): ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-pen me-1"></i> Assinatura Digital</div>
    <div class="card-body">
        <form method="POST" action="<?php echo url('service-orders'); ?>" id="sigForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_signature">
            <input type="hidden" name="os_id" value="<?php echo $os['id']; ?>">
            <div class="mb-3">
                <label class="form-label">Assinatura do responsável</label>
                <canvas id="sigPad" width="500" height="140" style="border:1px solid #e2e8f0;border-radius:6px;background:#fff;touch-action:none;max-width:100%"></canvas>
                <input type="hidden" name="signature_data" id="sigData">
                <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="sigClear()"><i class="bi bi-eraser me-1"></i> Limpar</button>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Salvar Assinatura</button>
        </form>
        <script>
        (function(){
            var canvas = document.getElementById('sigPad');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a';
            var drawing = false, last = null;

            function pos(e){
                var r = canvas.getBoundingClientRect();
                var t = e.touches ? e.touches[0] : e;
                return { x: t.clientX - r.left, y: t.clientY - r.top };
            }
            function start(e){ e.preventDefault(); drawing = true; last = pos(e); }
            function move(e){
                if (!drawing) return;
                e.preventDefault();
                var p = pos(e);
                ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
                last = p;
            }
            function end(){ drawing = false; }

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', end);
            canvas.addEventListener('mouseleave', end);
            canvas.addEventListener('touchstart', start);
            canvas.addEventListener('touchmove', move);
            canvas.addEventListener('touchend', end);

            window.sigClear = function(){
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                document.getElementById('sigData').value = '';
            };

            document.getElementById('sigForm').addEventListener('submit', function(){
                var blank = document.createElement('canvas');
                blank.width = canvas.width; blank.height = canvas.height;
                if (canvas.toDataURL() !== blank.toDataURL()) {
                    document.getElementById('sigData').value = canvas.toDataURL('image/png');
                }
            });
        })();
        </script>
    </div>
</div>
<?php endif; ?>
<?php } catch (\Throwable $ignored) {} ?>

<!-- Link anônimo -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-link-45deg me-1"></i> Link Anônimo</div>
    <div class="card-body">
        <?php if ($os['anonymous_token']): ?>
            <p class="mb-0 small">Link: <code><?php echo e('index.php?page=anonymous-os&token=' . $os['anonymous_token']); ?></code></p>
        <?php else: ?>
            <?php if (canWrite()): ?>
            <form method="POST" action="<?php echo url('service-orders'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="generate_anonymous_link">
                <input type="hidden" name="id" value="<?php echo $os['id']; ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-link-45deg me-1"></i> Gerar Link Anônimo</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
    $history = [];
    try {
        $hst = db()->prepare("SELECT * FROM os_history WHERE os_id = ? ORDER BY created_at DESC");
        $hst->execute([$os['id']]);
        $history = $hst->fetchAll();
    } catch (Throwable $ignored) {}
?>
<?php if (!empty($history)): ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i> Histórico desta OS</div>
    <div class="card-body">
        <?php foreach ($history as $h): ?>
        <div class="d-flex mb-3">
            <div class="me-3 text-center" style="min-width:80px">
                <small class="text-muted"><?php echo formatDate($h['created_at'], 'd/m/Y'); ?></small><br>
                <small class="text-muted"><?php echo formatDate($h['created_at'], 'H:i'); ?></small>
            </div>
            <div class="border-start border-2 border-primary ps-3">
                <span class="badge bg-primary mb-1"><?php echo e($h['action']); ?></span>
                <?php if ($h['details']): ?><p class="mb-0 small"><?php echo e($h['details']); ?></p><?php endif; ?>
                <small class="text-muted">por <?php echo e($h['user_name'] ?? 'Sistema'); ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
// ============================================================
// VIEW: LISTA (PADRÃO)
// ============================================================
else:
    $filter       = trim($_GET['filter'] ?? '');
    $filterStatus = $_GET['filter_status'] ?? '';
    $filterType   = $_GET['filter_type'] ?? '';

    $where  = "WHERE so.hospital_id = ?";
    $params = [$hid];

    if ($filter !== '') {
        $where .= " AND (so.os_number LIKE ? OR so.title LIKE ?)";
        $params[] = "%{$filter}%";
        $params[] = "%{$filter}%";
    }
    if ($filterStatus !== '') {
        $where .= " AND so.status = ?";
        $params[] = $filterStatus;
    }
    if ($filterType !== '') {
        $where .= " AND so.type = ?";
        $params[] = $filterType;
    }

    $baseQuery = "SELECT so.*, e.name AS equip_name, u1.name AS assigned_name, u2.name AS created_name
                  FROM service_orders so
                  LEFT JOIN equipment e ON e.id = so.equipment_id
                  LEFT JOIN users u1 ON u1.id = so.assigned_to
                  LEFT JOIN users u2 ON u2.id = so.created_by
                  {$where}
                  ORDER BY so.created_at DESC";

    $pg = paginate($baseQuery, $params, 20);
?>

<div class="page-header">
    <h1><i class="bi bi-clipboard-check me-2"></i>Ordens de Serviço</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="export.php?type=service_orders&format=csv"><i class="bi bi-download me-1"></i> CSV</a>
        <a class="btn btn-outline-primary btn-sm" href="export.php?type=service_orders&format=print" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i> Imprimir</a>
        <?php if (canWrite()): ?>
            <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova OS</button>
        <?php endif; ?>
    </div>
</div>

<!-- FILTRO -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="service-orders">
        <div class="col-md-4">
            <label class="form-label">Buscar</label>
            <input type="text" class="form-control" name="filter" value="<?php echo e($filter); ?>" placeholder="Número ou título...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="filter_status">
                <option value="">Todos</option>
                <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterStatus===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Tipo</label>
            <select class="form-select" name="filter_type">
                <option value="">Todos</option>
                <?php foreach ($typeLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterType===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
            <a href="<?php echo url('service-orders'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<!-- LISTA -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>OS</th>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Prioridade</th>
                        <th>Status</th>
                        <th>Responsável</th>
                        <th>Data</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pg['rows'])): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma OS encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($pg['rows'] as $os): ?>
                    <tr>
                        <td><strong><?php echo e($os['os_number']); ?></strong></td>
                        <td><?php echo e($os['title']); ?></td>
                        <td><?php echo $typeLabels[$os['type']] ?? $os['type']; ?></td>
                        <td><span class="badge badge-<?php echo $os['priority']; ?>"><?php echo $prioLabels[$os['priority']] ?? $os['priority']; ?></span></td>
                        <td>
                            <?php if (canWrite()): ?>
                            <form method="POST" class="d-inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="id" value="<?php echo $os['id']; ?>">
                                <select name="status" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto;display:inline-block;font-size:0.78rem">
                                    <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $os['status']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                                </select>
                            </form>
                            <?php else: ?>
                                <span class="badge badge-<?php echo $os['status']; ?>"><?php echo $statusLabels[$os['status']] ?? $os['status']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($os['assigned_name'] ?? '-'); ?></td>
                        <td><?php echo formatDate($os['created_at']); ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="<?php echo url('service-orders', ['action'=>'edit','id'=>$os['id']]); ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php if (canWrite()): ?>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $os['id']; ?>">
                                    <button class="btn btn-outline-danger btn-action" data-confirm="Excluir OS <?php echo e($os['os_number']); ?>?" title="Excluir"><i class="bi bi-trash"></i></button>
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
    <div class="card-footer bg-transparent">
        <?php echo paginationLinks($pg['page'], $pg['lastPage'], url('service-orders', array_filter(['filter'=>$filter,'filter_status'=>$filterStatus,'filter_type'=>$filterType]))); ?>
    </div>
</div>

<!-- MODAL NOVA OS -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-labelledby="modalAddLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="modalAddLabel"><i class="bi bi-clipboard-plus me-1"></i> Nova Ordem de Serviço</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?php echo url('service-orders'); ?>" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Título</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tipo</label>
                            <select class="form-select" name="type">
                                <?php foreach ($typeLabels as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prioridade</label>
                            <select class="form-select" name="priority">
                                <?php foreach ($prioLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $k==='medium'?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Equipamento (opcional)</label>
                            <select class="form-select" name="equipment_id">
                                <option value="">Nenhum</option>
                                <?php foreach ($equipments as $eq): ?><option value="<?php echo $eq['id']; ?>"><?php echo e($eq['code'] ? $eq['code'].' - ' : ''); echo e($eq['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Responsável</label>
                            <select class="form-select" name="assigned_to">
                                <option value="">Nenhum</option>
                                <?php foreach ($users as $u): ?><option value="<?php echo $u['id']; ?>"><?php echo e($u['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data Prevista</label>
                            <input type="date" class="form-control" name="scheduled_date">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observação</label>
                            <textarea class="form-control" name="observation" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Foto</label>
                            <input type="file" class="form-control" name="photo" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Criar OS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
