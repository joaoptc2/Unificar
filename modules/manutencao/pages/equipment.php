<?php
/**
 * MÓDULO DE EQUIPAMENTOS — Design System "RH Hospital"
 */
requireModule("equipment");

$hid    = hospitalId();
$action = $_GET['action'] ?? 'list';

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'add' || $act === 'edit') {
        core_require($act === 'add' ? 'equipment.create' : 'equipment.edit');
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $code        = trim($_POST['code'] ?? '') ?: null;
        $sectorId    = ((int)($_POST['sector_id'] ?? 0)) ?: null;
        $categoryId  = ((int)($_POST['category_id'] ?? 0)) ?: null;
        $manufacturer= trim($_POST['manufacturer'] ?? '') ?: null;
        $model       = trim($_POST['model'] ?? '') ?: null;
        $serialNumber= trim($_POST['serial_number'] ?? '') ?: null;
        $acqDate     = trim($_POST['acquisition_date'] ?? '') ?: null;
        $criticality = $_POST['criticality'] ?? 'medium';
        $status      = $_POST['status'] ?? 'active';
        $description = trim($_POST['description'] ?? '') ?: null;
        $installationDate = trim($_POST['installation_date'] ?? '') ?: null;
        $usefulLifeYears  = ((int)($_POST['useful_life_years'] ?? 0)) ?: null;

        if ($name === '') {
            flash('error', 'Nome do equipamento é obrigatório.');
        } else {
            try {
                if ($act === 'add') {
                    $qrToken = generateToken(16);
                    db()->prepare("INSERT INTO man_equipment (hospital_id, sector_id, category_id, code, name, manufacturer, model, serial_number, acquisition_date, criticality, status, description, qr_token, installation_date, useful_life_years) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$hid, $sectorId, $categoryId, $code, $name, $manufacturer, $model, $serialNumber, $acqDate, $criticality, $status, $description, $qrToken, $installationDate, $usefulLifeYears]);
                    auditLog('create', 'equipment', (int)db()->lastInsertId());
                    flash('success', 'Equipamento adicionado!');
                } else {
                    db()->prepare("UPDATE man_equipment SET sector_id=?, category_id=?, code=?, name=?, manufacturer=?, model=?, serial_number=?, acquisition_date=?, criticality=?, status=?, description=?, installation_date=?, useful_life_years=? WHERE id=? AND hospital_id=?")
                        ->execute([$sectorId, $categoryId, $code, $name, $manufacturer, $model, $serialNumber, $acqDate, $criticality, $status, $description, $installationDate, $usefulLifeYears, $id, $hid]);
                    auditLog('update', 'equipment', $id);
                    flash('success', 'Equipamento atualizado!');
                }
            } catch (Exception $ex) { flash('error', 'Erro: ' . $ex->getMessage()); }
        }
        redirect(url('equipment'));
    }
    if ($act === 'delete') {
        core_require('equipment.delete');
        $id = (int)($_POST['id'] ?? 0);
        $deactivationReason = trim($_POST['deactivation_reason'] ?? '');
        try {
            db()->prepare("UPDATE man_equipment SET status='inactive', deactivation_date=NOW(), deactivation_reason=? WHERE id=? AND hospital_id=?")
                ->execute([$deactivationReason ?: null, $id, $hid]);
            auditLog('deactivate', 'equipment', $id);
            flash('success', 'Equipamento desativado.');
        } catch (\Throwable $ex) { flash('error', 'Erro ao desativar: ' . $ex->getMessage()); }
        redirect(url('equipment'));
    }
    if ($act === 'add_category') {
        core_require('equipment.create');
        $catName = trim($_POST['cat_name'] ?? '');
        if ($catName !== '') { db()->prepare("INSERT INTO man_equipment_categories (hospital_id, name) VALUES (?, ?)")->execute([$hid, $catName]); flash('success', 'Categoria adicionada.'); }
        redirect(url('equipment', ['action' => 'categories']));
    }
    if ($act === 'delete_category') {
        core_require('equipment.delete');
        $catId = (int)($_POST['cat_id'] ?? 0);
        db()->prepare("DELETE FROM man_equipment_categories WHERE id = ? AND hospital_id = ?")->execute([$catId, $hid]);
        flash('success', 'Categoria removida.');
        redirect(url('equipment', ['action' => 'categories']));
    }
    if ($act === 'edit_category') {
        core_require('equipment.edit');
        $catId = (int)($_POST['cat_id'] ?? 0);
        $catName = trim($_POST['cat_name'] ?? '');
        if ($catName !== '') { db()->prepare("UPDATE man_equipment_categories SET name = ? WHERE id = ? AND hospital_id = ?")->execute([$catName, $catId, $hid]); flash('success', 'Categoria atualizada.'); }
        redirect(url('equipment', ['action' => 'categories']));
    }
}

$sectors    = db()->prepare("SELECT * FROM man_sectors WHERE hospital_id = ? ORDER BY name"); $sectors->execute([$hid]); $sectors = $sectors->fetchAll();
$categories = db()->prepare("SELECT * FROM man_equipment_categories WHERE hospital_id = ? ORDER BY name"); $categories->execute([$hid]); $categories = $categories->fetchAll();

$critLabels     = ['low'=>'Baixa','medium'=>'Média','high'=>'Alta','critical'=>'Crítica'];
$statusLabels   = ['active'=>'Ativo','maintenance'=>'Em Manutenção','inactive'=>'Inativo','broken'=>'Quebrado'];
$typeLabels     = ['preventive'=>'Preventiva','corrective'=>'Corretiva','predictive'=>'Preditiva','calibration'=>'Calibração','inspection'=>'Inspeção'];
$prioLabels     = ['low'=>'Baixa','medium'=>'Média','high'=>'Alta','critical'=>'Crítica'];
$osStatusLabels = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Ag. Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];

$pageTitle = 'Equipamentos';
ob_start();

// ============================================================
// VIEW: CATEGORIAS
// ============================================================
if ($action === 'categories'):
?>
<div class="page-header">
    <h1><i class="bi bi-tag me-2"></i>Categorias de Equipamento</h1>
    <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>

<div class="card border-0 shadow-sm">
    <?php if (core_can('equipment.create')): ?>
    <div class="card-header bg-white">
        <form method="POST" action="<?php echo url('equipment', ['action'=>'categories']); ?>" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_category">
            <div class="col">
                <input type="text" class="form-control" name="cat_name" placeholder="Nome da nova categoria" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Adicionar</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Nome</th><th class="text-end" style="width:200px">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td>
                        <?php if (core_can('equipment.edit')): ?>
                        <form method="POST" action="<?php echo url('equipment', ['action'=>'categories']); ?>" class="d-flex gap-2 align-items-center">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="edit_category">
                            <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                            <input type="text" class="form-control form-control-sm" name="cat_name" value="<?php echo e($cat['name']); ?>" required>
                            <button type="submit" class="btn btn-outline-primary btn-action"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <?php else: ?>
                            <?php echo e($cat['name']); ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (core_can('equipment.delete')): ?>
                        <form method="POST" action="<?php echo url('equipment', ['action'=>'categories']); ?>" class="d-inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir esta categoria?"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-4">Nenhuma categoria cadastrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// ============================================================
// VIEW: DETALHE DO EQUIPAMENTO (histórico + QR Code)
// ============================================================
elseif ($action === 'detail'):
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT e.*, s.name AS sector_name, c.name AS category_name FROM man_equipment e LEFT JOIN man_sectors s ON s.id=e.sector_id LEFT JOIN man_equipment_categories c ON c.id=e.category_id WHERE e.id=? AND e.hospital_id=?");
    $stmt->execute([$id, $hid]);
    $eq = $stmt->fetch();
    if (!$eq) { flash('error', 'Equipamento não encontrado.'); redirect(url('equipment')); }

    $osHistory = db()->prepare("SELECT os_number, title, type, status, priority, created_at, completed_at FROM man_service_orders WHERE equipment_id=? AND hospital_id=? ORDER BY created_at DESC LIMIT 50");
    $osHistory->execute([$id, $hid]);
    $osHistory = $osHistory->fetchAll();

    $calibHistory = [];
    try {
        $st = db()->prepare("SELECT calibration_date, next_date, result, responsible_body, cost FROM man_equipment_calibrations WHERE equipment_id=? AND hospital_id=? ORDER BY calibration_date DESC LIMIT 20");
        $st->execute([$id, $hid]);
        $calibHistory = $st->fetchAll();
    } catch (Throwable $ignored) {}

    $maintPlans = db()->prepare("SELECT title, frequency, next_date, last_executed, status FROM man_maintenance_plans WHERE equipment_id=? AND hospital_id=? ORDER BY next_date");
    $maintPlans->execute([$id, $hid]);
    $maintPlans = $maintPlans->fetchAll();

    $eqPublicUrl = core_url('index.php') . '?m=manutencao&page=anonymous-os&token=' . urlencode($eq['qr_token'] ?? '');
    $qrImageUrl  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($eqPublicUrl);

    $freqLabels = ['daily'=>'Diária','weekly'=>'Semanal','biweekly'=>'Quinzenal','monthly'=>'Mensal','quarterly'=>'Trimestral','semiannual'=>'Semestral','annual'=>'Anual'];
    $resultLabels = ['conforme'=>'Conforme','nao_conforme'=>'Não conforme','conforme_com_ressalvas'=>'Com ressalvas'];
?>

<div class="page-header">
    <h1><i class="bi bi-hdd-rack me-2"></i><?php echo e($eq['name']); ?></h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('equipment', ['action'=>'edit','id'=>$eq['id']]); ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i> Editar</a>
        <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- DADOS DO EQUIPAMENTO -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i> Dados do equipamento</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-sm-4"><small class="text-muted d-block">Código</small><strong><?php echo e($eq['code'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Fabricante</small><strong><?php echo e($eq['manufacturer'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Modelo</small><strong><?php echo e($eq['model'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Nº Série</small><strong><?php echo e($eq['serial_number'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Setor</small><strong><?php echo e($eq['sector_name'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Categoria</small><strong><?php echo e($eq['category_name'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Criticidade</small><span class="badge badge-<?php echo e($eq['criticality']); ?>"><?php echo e($critLabels[$eq['criticality']] ?? $eq['criticality']); ?></span></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Status</small><span class="badge badge-<?php echo e($eq['status']); ?>"><?php echo e($statusLabels[$eq['status']] ?? $eq['status']); ?></span></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Aquisição</small><strong><?php echo formatDate($eq['acquisition_date'] ?? ''); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Data de Instalação</small><strong><?php echo formatDate($eq['installation_date'] ?? ''); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Vida Útil (anos)</small><strong><?php echo e($eq['useful_life_years'] ?? '—'); ?></strong></div>
                    <?php if (!empty($eq['deactivation_date'])): ?>
                    <div class="col-sm-4"><small class="text-muted d-block">Data de Desativação</small><strong><?php echo formatDate($eq['deactivation_date']); ?></strong></div>
                    <div class="col-sm-12"><small class="text-muted d-block">Motivo da Desativação</small><strong><?php echo e($eq['deactivation_reason'] ?? '—'); ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if ($eq['description']): ?>
                    <hr><small class="text-muted d-block mb-1">Descrição</small><p class="mb-0 small"><?php echo nl2br(e($eq['description'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- QR CODE -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-qr-code me-1"></i> QR Code</div>
            <div class="card-body">
                <?php if ($eq['qr_token']): ?>
                    <img src="<?php echo e($qrImageUrl); ?>" alt="QR Code" class="img-fluid mb-2" style="max-width:200px">
                    <p class="small text-muted mb-1">Escaneie para consultar este equipamento</p>
                    <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i> Imprimir QR</button>
                <?php else: ?>
                    <p class="text-muted">QR Code não gerado.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- HISTÓRICO DE OS -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1"></i> Ordens de Serviço (<?php echo count($osHistory); ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($osHistory)): ?>
            <p class="text-center text-muted py-4 mb-0">Nenhuma OS registrada para este equipamento.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>OS</th><th>Título</th><th>Tipo</th><th>Prioridade</th><th>Status</th><th>Criada</th><th>Concluída</th></tr></thead>
                <tbody>
                <?php foreach ($osHistory as $o): ?>
                <tr>
                    <td><strong><?php echo e($o['os_number']); ?></strong></td>
                    <td><?php echo e($o['title']); ?></td>
                    <td><?php echo e($typeLabels[$o['type']] ?? $o['type']); ?></td>
                    <td><span class="badge badge-<?php echo e($o['priority']); ?>"><?php echo e($prioLabels[$o['priority']] ?? $o['priority']); ?></span></td>
                    <td><span class="badge badge-<?php echo e($o['status']); ?>"><?php echo e($osStatusLabels[$o['status']] ?? $o['status']); ?></span></td>
                    <td class="text-muted"><?php echo formatDate($o['created_at']); ?></td>
                    <td class="text-muted"><?php echo formatDate($o['completed_at'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<!-- HISTÓRICO DE CALIBRAÇÕES -->
<?php if (!empty($calibHistory)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers me-1"></i> Calibrações (<?php echo count($calibHistory); ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead><tr><th>Data</th><th>Próxima</th><th>Resultado</th><th>Órgão</th><th>Custo</th></tr></thead>
            <tbody>
            <?php foreach ($calibHistory as $c): ?>
            <tr>
                <td><?php echo formatDate($c['calibration_date']); ?></td>
                <td><?php echo formatDate($c['next_date']); ?></td>
                <td><span class="badge badge-<?php echo $c['result']==='conforme'?'ok':($c['result']==='nao_conforme'?'vencido':'proximo'); ?>"><?php echo e($resultLabels[$c['result']] ?? $c['result']); ?></span></td>
                <td><?php echo e($c['responsible_body'] ?? '—'); ?></td>
                <td>R$ <?php echo number_format((float)$c['cost'], 2, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<!-- PLANOS DE MANUTENÇÃO -->
<?php if (!empty($maintPlans)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-tools me-1"></i> Planos de Manutenção</div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead><tr><th>Plano</th><th>Frequência</th><th>Próxima</th><th>Última Execução</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($maintPlans as $mp): ?>
            <tr>
                <td><strong><?php echo e($mp['title']); ?></strong></td>
                <td><?php echo e($freqLabels[$mp['frequency']] ?? $mp['frequency']); ?></td>
                <td><?php echo formatDate($mp['next_date']); ?></td>
                <td><?php echo formatDate($mp['last_executed'] ?? '', 'd/m/Y H:i'); ?></td>
                <td><span class="badge badge-<?php echo e($mp['status']); ?>"><?php echo $mp['status']==='active'?'Ativo':'Inativo'; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<?php
// ============================================================
// VIEW: EDITAR
// ============================================================
elseif ($action === 'edit'):
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM man_equipment WHERE id = ? AND hospital_id = ?");
    $stmt->execute([$id, $hid]);
    $eq = $stmt->fetch();
    if (!$eq) { flash('error', 'Equipamento não encontrado.'); redirect(url('equipment')); }
?>
<div class="page-header">
    <h1><i class="bi bi-pencil me-2"></i>Editar Equipamento</h1>
    <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?php echo url('equipment'); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?php echo $eq['id']; ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required">Nome</label><input type="text" class="form-control" name="name" value="<?php echo e($eq['name']); ?>" required></div>
                <div class="col-md-4"><label class="form-label">Código</label><input type="text" class="form-control" name="code" value="<?php echo e($eq['code'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Fabricante</label><input type="text" class="form-control" name="manufacturer" value="<?php echo e($eq['manufacturer'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Modelo</label><input type="text" class="form-control" name="model" value="<?php echo e($eq['model'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Nº Série</label><input type="text" class="form-control" name="serial_number" value="<?php echo e($eq['serial_number'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Data Aquisição</label><input type="date" class="form-control" name="acquisition_date" value="<?php echo e($eq['acquisition_date'] ?? ''); ?>"></div>
                <div class="col-md-4">
                    <label class="form-label">Setor</label>
                    <select class="form-select" name="sector_id"><option value="">Selecione...</option>
                    <?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo ($eq['sector_id'] == $s['id']) ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Categoria</label>
                    <select class="form-select" name="category_id"><option value="">Selecione...</option>
                    <?php foreach ($categories as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo ($eq['category_id'] == $c['id']) ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Criticidade</label>
                    <select class="form-select" name="criticality">
                        <?php foreach ($critLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $eq['criticality'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $eq['status'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="3"><?php echo e($eq['description'] ?? ''); ?></textarea></div>
                <div class="col-md-4"><label class="form-label">Data de Instalação</label><input type="date" class="form-control" name="installation_date" value="<?php echo e($eq['installation_date'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Vida Útil (anos)</label><input type="number" class="form-control" name="useful_life_years" min="0" value="<?php echo e($eq['useful_life_years'] ?? ''); ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1"></i> Salvar</button>
        </form>
    </div>
</div>

<?php if ($eq['qr_token']): ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-qr-code me-1"></i> QR Code / Token</div>
    <div class="card-body">
        <p class="mb-1 small">Token único: <code><?php echo e($eq['qr_token']); ?></code></p>
        <small class="text-muted">Use este token para gerar um QR Code externo.</small>
    </div>
</div>
<?php endif; ?>

<?php
// ============================================================
// VIEW: LISTA
// ============================================================
else:
    $filter       = trim($_GET['filter'] ?? '');
    $filterStatus = $_GET['filter_status'] ?? '';
    $filterCrit   = $_GET['filter_criticality'] ?? '';

    $where  = "WHERE e.hospital_id = ?";
    $params = [$hid];
    if ($filter !== '') { $where .= " AND (e.name LIKE ? OR e.code LIKE ? OR e.serial_number LIKE ?)"; $params[] = "%{$filter}%"; $params[] = "%{$filter}%"; $params[] = "%{$filter}%"; }
    if ($filterStatus !== '') { $where .= " AND e.status = ?"; $params[] = $filterStatus; }
    if ($filterCrit !== '')   { $where .= " AND e.criticality = ?"; $params[] = $filterCrit; }

    $baseQuery = "SELECT e.*, s.name AS sector_name, c.name AS category_name
                  FROM man_equipment e LEFT JOIN man_sectors s ON s.id = e.sector_id LEFT JOIN man_equipment_categories c ON c.id = e.category_id
                  {$where} ORDER BY e.created_at DESC";
    $pg = paginate($baseQuery, $params, 20);
?>

<div class="page-header">
    <h1><i class="bi bi-hdd-rack me-2"></i>Equipamentos</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('equipment', ['action'=>'categories']); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tag me-1"></i> Categorias</a>
        <?php if (core_can('equipment.create')): ?>
            <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo Equipamento</button>
        <?php endif; ?>
    </div>
</div>

<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="manutencao"><input type="hidden" name="page" value="equipment">
        <div class="col-md-4">
            <label class="form-label">Buscar</label>
            <input type="text" class="form-control" name="filter" value="<?php echo e($filter); ?>" placeholder="Nome, código ou série...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="filter_status">
                <option value="">Todos</option>
                <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Criticidade</label>
            <select class="form-select" name="filter_criticality">
                <option value="">Todas</option>
                <?php foreach ($critLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterCrit === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
            <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr><th>Código</th><th>Nome</th><th>Setor</th><th>Categoria</th><th>Criticidade</th><th>Status</th><th class="text-end">Ações</th></tr>
                </thead>
                <tbody>
                <?php if (empty($pg['rows'])): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhum equipamento encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($pg['rows'] as $eq): ?>
                    <tr>
                        <td class="text-muted"><?php echo e($eq['code'] ?? '—'); ?></td>
                        <td><strong><?php echo e($eq['name']); ?></strong></td>
                        <td><?php echo e($eq['sector_name'] ?? '—'); ?></td>
                        <td><?php echo e($eq['category_name'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo e($eq['criticality']); ?>"><?php echo e($critLabels[$eq['criticality']] ?? $eq['criticality']); ?></span></td>
                        <td><span class="badge badge-<?php echo e($eq['status']); ?>"><?php echo e($statusLabels[$eq['status']] ?? $eq['status']); ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="<?php echo url('equipment', ['action'=>'detail','id'=>$eq['id']]); ?>" class="btn btn-outline-primary btn-action" title="Ver detalhes"><i class="bi bi-eye"></i></a>
                                <a href="<?php echo url('equipment', ['action'=>'edit','id'=>$eq['id']]); ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php if (core_can('equipment.delete')): ?>
                                <button type="button" class="btn btn-outline-danger btn-action" title="Desativar" onclick="openModal('modalDeactivate<?php echo $eq['id']; ?>')"><i class="bi bi-x-circle"></i></button>
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
    <?php if ($pg['lastPage'] > 1): ?>
    <div class="card-footer bg-transparent text-center">
        <?php echo paginationLinks($pg['page'], $pg['lastPage'], url('equipment', array_filter(['filter'=>$filter,'filter_status'=>$filterStatus,'filter_criticality'=>$filterCrit]))); ?>
    </div>
    <?php endif; ?>
</div>

<?php if (core_can('equipment.create')): ?>
<!-- MODAL NOVO EQUIPAMENTO -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('equipment'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-plus-lg me-1"></i> Novo Equipamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label required">Nome</label><input type="text" class="form-control" name="name" required></div>
                        <div class="col-md-6"><label class="form-label">Código</label><input type="text" class="form-control" name="code"></div>
                        <div class="col-md-4"><label class="form-label">Fabricante</label><input type="text" class="form-control" name="manufacturer"></div>
                        <div class="col-md-4"><label class="form-label">Modelo</label><input type="text" class="form-control" name="model"></div>
                        <div class="col-md-4"><label class="form-label">Nº Série</label><input type="text" class="form-control" name="serial_number"></div>
                        <div class="col-md-4"><label class="form-label">Data Aquisição</label><input type="date" class="form-control" name="acquisition_date"></div>
                        <div class="col-md-4">
                            <label class="form-label">Setor</label>
                            <select class="form-select" name="sector_id"><option value="">Selecione...</option>
                            <?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Categoria</label>
                            <select class="form-select" name="category_id"><option value="">Selecione...</option>
                            <?php foreach ($categories as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Criticidade</label>
                            <select class="form-select" name="criticality">
                                <?php foreach ($critLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $k === 'medium' ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="3"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if (core_can('equipment.delete')): ?>
<?php foreach ($pg['rows'] as $eq): ?>
<!-- Modal Desativar Equipamento #<?php echo $eq['id']; ?> -->
<div class="modal fade" id="modalDeactivate<?php echo $eq['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('equipment'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $eq['id']; ?>">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-x-circle me-1"></i> Desativar Equipamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Deseja desativar o equipamento <strong><?php echo e($eq['name']); ?></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label">Motivo da desativação</label>
                        <textarea class="form-control" name="deactivation_reason" rows="3" placeholder="Informe o motivo da desativação..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-x-circle me-1"></i> Desativar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
