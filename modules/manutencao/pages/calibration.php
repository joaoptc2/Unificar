<?php
/**
 * MÓDULO DE CALIBRAÇÃO DE EQUIPAMENTOS
 *
 * Histórico de calibrações alinhado à RDC ANVISA 02/2010:
 *  - Data da calibração e próxima data
 *  - Órgão / responsável
 *  - Resultado (conforme / não conforme / com ressalvas)
 *  - Upload do certificado (PDF/JPG/PNG)
 *  - Observações
 *
 * A listagem destaca equipamentos vencidos e os próximos de vencer
 * (≤ 30 dias). O cron.php gera notificações automáticas.
 */
requireModule("calibration");

$hid = hospitalId();

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'add' || $act === 'edit') {
        core_require($act === 'add' ? 'calibration.create' : 'calibration.edit');
        $id               = (int)($_POST['id'] ?? 0);
        $equipmentId      = (int)($_POST['equipment_id'] ?? 0);
        $calibrationDate  = trim($_POST['calibration_date'] ?? '');
        $nextDate         = trim($_POST['next_date'] ?? '');
        $responsibleBody  = trim($_POST['responsible_body'] ?? '') ?: null;
        $responsiblePerson= trim($_POST['responsible_person'] ?? '') ?: null;
        $result           = $_POST['result'] ?? 'conforme';
        $observations     = trim($_POST['observations'] ?? '') ?: null;
        $cost             = (float)str_replace(',', '.', (string)($_POST['cost'] ?? '0'));

        if (!in_array($result, ['conforme','nao_conforme','conforme_com_ressalvas'], true)) {
            $result = 'conforme';
        }

        if ($equipmentId <= 0 || $calibrationDate === '' || $nextDate === '') {
            flash('error', 'Equipamento, data da calibração e próxima data são obrigatórios.');
            redirect(url('calibration'));
        }

        // Valida que o equipamento pertence ao hospital
        $eq = db()->prepare("SELECT id FROM man_equipment WHERE id = ? AND hospital_id = ?");
        $eq->execute([$equipmentId, $hid]);
        if (!$eq->fetch()) {
            flash('error', 'Equipamento inválido.');
            redirect(url('calibration'));
        }

        // Upload do certificado (opcional)
        $certPath = null;
        if (!empty($_FILES['certificate']['name'])) {
            $certPath = uploadFile($_FILES['certificate'], 'calibrations');
            if ($certPath === null) {
                flash('error', 'Falha no upload do certificado (aceitos: PDF, JPG, PNG; máx 5MB).');
                redirect(url('calibration'));
            }
        }

        try {
            if ($act === 'add') {
                $sql = "INSERT INTO man_equipment_calibrations
                          (hospital_id, equipment_id, calibration_date, next_date,
                           responsible_body, responsible_person, result, certificate_path,
                           observations, cost, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                db()->prepare($sql)->execute([
                    $hid, $equipmentId, $calibrationDate, $nextDate,
                    $responsibleBody, $responsiblePerson, $result, $certPath,
                    $observations, $cost, $_SESSION['user_id'] ?? null,
                ]);
                auditLog('create', 'equipment_calibrations', (int)db()->lastInsertId());
                flash('success', 'Calibração registrada!');
            } else {
                // Edit: mantém certificado anterior se nenhum novo foi enviado
                if ($certPath === null) {
                    $sql = "UPDATE man_equipment_calibrations SET
                              equipment_id=?, calibration_date=?, next_date=?,
                              responsible_body=?, responsible_person=?, result=?,
                              observations=?, cost=?
                            WHERE id=? AND hospital_id=?";
                    db()->prepare($sql)->execute([
                        $equipmentId, $calibrationDate, $nextDate,
                        $responsibleBody, $responsiblePerson, $result,
                        $observations, $cost, $id, $hid,
                    ]);
                } else {
                    $sql = "UPDATE man_equipment_calibrations SET
                              equipment_id=?, calibration_date=?, next_date=?,
                              responsible_body=?, responsible_person=?, result=?,
                              certificate_path=?, observations=?, cost=?
                            WHERE id=? AND hospital_id=?";
                    db()->prepare($sql)->execute([
                        $equipmentId, $calibrationDate, $nextDate,
                        $responsibleBody, $responsiblePerson, $result,
                        $certPath, $observations, $cost, $id, $hid,
                    ]);
                }
                auditLog('update', 'equipment_calibrations', $id);
                flash('success', 'Calibração atualizada!');
            }
        } catch (Exception $ex) {
            error_log('Calibration save error: ' . $ex->getMessage());
            flash('error', 'Erro ao salvar calibração.');
        }
        redirect(url('calibration'));
    }

    if ($act === 'delete') {
        core_require('calibration.delete');
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM man_equipment_calibrations WHERE id = ? AND hospital_id = ?")
            ->execute([$id, $hid]);
        auditLog('delete', 'equipment_calibrations', $id);
        flash('success', 'Registro de calibração removido.');
        redirect(url('calibration'));
    }
}

// ============================================================
// DADOS
// ============================================================

// Filtros
$filterStatus = $_GET['status'] ?? 'all'; // all | due_soon | overdue | ok
$filterEquip  = (int)($_GET['equipment_id'] ?? 0);

$where  = ['c.hospital_id = ?'];
$params = [$hid];

if ($filterEquip > 0) {
    $where[] = 'c.equipment_id = ?';
    $params[] = $filterEquip;
}
if ($filterStatus === 'overdue') {
    $where[] = 'c.next_date < CURDATE()';
} elseif ($filterStatus === 'due_soon') {
    $where[] = 'c.next_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
} elseif ($filterStatus === 'ok') {
    $where[] = 'c.next_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
}

$sql = "SELECT c.*, e.name AS equipment_name, e.code AS equipment_code
        FROM man_equipment_calibrations c
        JOIN man_equipment e ON e.id = c.equipment_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.next_date ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Equipamentos para os selects
$equipStmt = db()->prepare("SELECT id, name, code FROM man_equipment WHERE hospital_id = ? ORDER BY name");
$equipStmt->execute([$hid]);
$allEquipment = $equipStmt->fetchAll();

// KPIs
$kpiStmt = db()->prepare("
    SELECT
      SUM(CASE WHEN c.next_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
      SUM(CASE WHEN c.next_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS due_soon,
      COUNT(*) AS total
    FROM (
      SELECT equipment_id, MAX(next_date) AS next_date
      FROM man_equipment_calibrations
      WHERE hospital_id = ?
      GROUP BY equipment_id
    ) c
");
$kpiStmt->execute([$hid]);
$kpi = $kpiStmt->fetch() ?: ['overdue'=>0,'due_soon'=>0,'total'=>0];

$resultLabels = [
    'conforme'                 => 'Conforme',
    'nao_conforme'             => 'Não conforme',
    'conforme_com_ressalvas'   => 'Com ressalvas',
];

$today = new DateTime('today');
function calibStatus(string $nextDate, DateTime $today): array
{
    try {
        $dt = new DateTime($nextDate);
    } catch (Exception $ex) {
        return ['label' => 'Indefinido', 'class' => 'cancelled'];
    }
    $diff = (int)$today->diff($dt)->format('%r%a');
    if ($diff < 0)  return ['label' => 'Vencida',    'class' => 'critical'];
    if ($diff <= 15) return ['label' => 'Urgente',   'class' => 'high'];
    if ($diff <= 30) return ['label' => 'A vencer',  'class' => 'medium'];
    return ['label' => 'Em dia', 'class' => 'completed'];
}

$action = $_GET['action'] ?? 'list';

$pageTitle = 'Calibração';
ob_start();

// ============================================================
// VIEW: EXECUTAR CALIBRAÇÃO (formulário pré-preenchido)
// ============================================================
if ($action === 'execute'):
    $execEqId = (int)($_GET['equipment_id'] ?? 0);
    $execEq = null;
    if ($execEqId) {
        $st = db()->prepare("SELECT id, name, code FROM man_equipment WHERE id=? AND hospital_id=?");
        $st->execute([$execEqId, $hid]);
        $execEq = $st->fetch();
    }
    if (!$execEq) { flash('error', 'Equipamento inválido.'); redirect(url('calibration')); }
?>
<div class="page-header">
    <h1><i class="bi bi-check-circle me-2"></i>Executar calibração: <?php echo e($execEq['name']); ?></h1>
    <a href="<?php echo url('calibration'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?php echo url('calibration'); ?>" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="equipment_id" value="<?php echo $execEq['id']; ?>">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Equipamento</label>
                    <input type="text" class="form-control" value="<?php echo e($execEq['name'] . ($execEq['code'] ? ' (' . $execEq['code'] . ')' : '')); ?>" readonly>
                </div>
                <div class="col-md-6"><label class="form-label required">Data da calibração</label><input type="date" class="form-control" name="calibration_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="col-md-6"><label class="form-label required">Próxima calibração</label><input type="date" class="form-control" name="next_date" required></div>
                <div class="col-md-6"><label class="form-label">Órgão/Empresa</label><input type="text" class="form-control" name="responsible_body"></div>
                <div class="col-md-6"><label class="form-label">Responsável técnico</label><input type="text" class="form-control" name="responsible_person"></div>
                <div class="col-md-6">
                    <label class="form-label">Resultado</label>
                    <select class="form-select" name="result">
                        <?php foreach ($resultLabels as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">Custo (R$)</label><input type="number" class="form-control" step="0.01" name="cost" value="0.00"></div>
                <div class="col-12"><label class="form-label">Certificado (PDF/JPG/PNG)</label><input type="file" class="form-control" name="certificate" accept=".pdf,.jpg,.jpeg,.png"></div>
                <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" name="observations" rows="3"></textarea></div>
            </div>
            <button type="submit" class="btn btn-success mt-3"><i class="bi bi-check-circle me-1"></i> Registrar calibração</button>
        </form>
    </div>
</div>

<?php else: ?>

<!-- PAGE HEADER -->
<div class="page-header">
    <h1><i class="bi bi-rulers me-2"></i>Calibração de Equipamentos</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="index.php?m=manutencao&page=export&type=calibrations&format=csv<?php echo $filterStatus!=='all' ? '&status=' . e($filterStatus) : ''; ?>"><i class="bi bi-download me-1"></i> CSV</a>
        <a class="btn btn-outline-primary btn-sm" href="index.php?m=manutencao&page=export&type=calibrations&format=print<?php echo $filterStatus!=='all' ? '&status=' . e($filterStatus) : ''; ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i> Imprimir/PDF</a>
        <?php if (core_can('calibration.create')): ?>
            <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova calibração</button>
        <?php endif; ?>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-value text-danger"><?php echo (int)$kpi['overdue']; ?></div>
                    <div class="stat-label">Vencidas</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="stat-value text-warning"><?php echo (int)$kpi['due_soon']; ?></div>
                    <div class="stat-label">Vencem em 30 dias</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-value text-success"><?php echo (int)$kpi['total']; ?></div>
                    <div class="stat-label">Equipamentos com histórico</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FILTERS -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="manutencao"><input type="hidden" name="page" value="calibration">
        <div class="col-md-3">
            <label class="form-label">Situação</label>
            <select name="status" class="form-select">
                <option value="all"      <?php echo $filterStatus==='all'?'selected':''; ?>>Todas</option>
                <option value="overdue"  <?php echo $filterStatus==='overdue'?'selected':''; ?>>Vencidas</option>
                <option value="due_soon" <?php echo $filterStatus==='due_soon'?'selected':''; ?>>A vencer (30 dias)</option>
                <option value="ok"       <?php echo $filterStatus==='ok'?'selected':''; ?>>Em dia</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Equipamento</label>
            <select name="equipment_id" class="form-select">
                <option value="0">Todos</option>
                <?php foreach ($allEquipment as $eq): ?>
                    <option value="<?php echo $eq['id']; ?>" <?php echo $filterEquip===(int)$eq['id']?'selected':''; ?>>
                        <?php echo e($eq['name']); ?> <?php echo $eq['code'] ? '(' . e($eq['code']) . ')' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
        <div class="col-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('calibration'); ?>">Limpar</a>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>Calibrado em</th>
                        <th>Próxima</th>
                        <th>Situação</th>
                        <th>Resultado</th>
                        <th>Órgão</th>
                        <th>Certificado</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma calibração cadastrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $s = calibStatus($r['next_date'], $today);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo e($r['equipment_name']); ?></strong>
                            <?php if ($r['equipment_code']): ?>
                                <div class="text-muted small"><?php echo e($r['equipment_code']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDate($r['calibration_date']); ?></td>
                        <td><?php echo formatDate($r['next_date']); ?></td>
                        <td><span class="badge badge-<?php echo e($s['class']); ?>"><?php echo e($s['label']); ?></span></td>
                        <td><?php echo e($resultLabels[$r['result']] ?? $r['result']); ?></td>
                        <td><?php echo e($r['responsible_body'] ?? '-'); ?></td>
                        <td>
                            <?php if (!empty($r['certificate_path'])): ?>
                                <a href="<?php echo e(uploadUrl($r['certificate_path'])); ?>" target="_blank" rel="noopener" class="text-decoration-none"><i class="bi bi-file-earmark-pdf me-1"></i>Ver</a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button onclick="openModal('histCalib<?php echo $r['equipment_id']; ?>')" class="btn btn-outline-info btn-action" title="Histórico"><i class="bi bi-clock-history"></i></button>
                                <?php if (core_can('calibration.create')): ?>
                                    <a href="<?php echo url('calibration', ['action'=>'execute','equipment_id'=>$r['equipment_id']]); ?>" class="btn btn-outline-success btn-action" title="Executar calibração"><i class="bi bi-check-circle"></i></a>
                                <?php endif; ?>
                                <?php if (core_can('calibration.edit')): ?>
                                    <button onclick="openModal('edit<?php echo $r['id']; ?>')" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                                <?php if (core_can('calibration.delete')): ?>
                                    <form method="POST" style="display:inline">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir este registro?"><i class="bi bi-trash"></i></button>
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

<!-- MODAIS DE HISTÓRICO POR EQUIPAMENTO -->
<?php
$shownEquipHistory = [];
foreach ($rows as $r):
    $eqId = (int)$r['equipment_id'];
    if (isset($shownEquipHistory[$eqId])) continue;
    $shownEquipHistory[$eqId] = true;
    $ch = db()->prepare("SELECT calibration_date, next_date, result, responsible_body, responsible_person, cost, observations FROM man_equipment_calibrations WHERE equipment_id=? AND hospital_id=? ORDER BY calibration_date DESC LIMIT 20");
    $ch->execute([$eqId, $hid]);
    $calibHistRows = $ch->fetchAll();
?>
<div class="modal fade" id="histCalib<?php echo $eqId; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fw-semibold"><i class="bi bi-clock-history me-1"></i> Histórico: <?php echo e($r['equipment_name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Data</th><th>Próxima</th><th>Resultado</th><th>Órgão</th><th>Responsável</th><th>Custo</th></tr></thead>
                    <tbody>
                    <?php foreach ($calibHistRows as $ch2): ?>
                    <tr>
                        <td><?php echo formatDate($ch2['calibration_date']); ?></td>
                        <td><?php echo formatDate($ch2['next_date']); ?></td>
                        <td><span class="badge badge-<?php echo $ch2['result']==='conforme'?'ok':($ch2['result']==='nao_conforme'?'vencido':'proximo'); ?>"><?php echo e($resultLabels[$ch2['result']] ?? $ch2['result']); ?></span></td>
                        <td><?php echo e($ch2['responsible_body'] ?? '—'); ?></td>
                        <td><?php echo e($ch2['responsible_person'] ?? '—'); ?></td>
                        <td>R$ <?php echo number_format((float)$ch2['cost'], 2, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (core_can('calibration.create')): ?>
<!-- MODAL NOVA CALIBRAÇÃO -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-labelledby="modalAddLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="modalAddLabel">Nova Calibração</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?php echo url('calibration'); ?>" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Equipamento</label>
                            <select name="equipment_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($allEquipment as $eq): ?>
                                    <option value="<?php echo $eq['id']; ?>">
                                        <?php echo e($eq['name']); ?> <?php echo $eq['code'] ? '(' . e($eq['code']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Data da calibração</label>
                            <input type="date" name="calibration_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Próxima calibração</label>
                            <input type="date" name="next_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Órgão/Empresa</label>
                            <input type="text" name="responsible_body" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Responsável técnico</label>
                            <input type="text" name="responsible_person" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Resultado</label>
                            <select name="result" class="form-select">
                                <?php foreach ($resultLabels as $k=>$v): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Custo (R$)</label>
                            <input type="number" step="0.01" name="cost" class="form-control" value="0.00">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Certificado (PDF/JPG/PNG, máx 5MB)</label>
                            <input type="file" name="certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observations" class="form-control" rows="3"></textarea>
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

<?php endif; ?>

<?php if (core_can('calibration.edit')): ?>
<!-- MODAIS EDIÇÃO -->
<?php foreach ($rows as $r): ?>
<div class="modal fade" id="edit<?php echo $r['id']; ?>" tabindex="-1" aria-labelledby="editLabel<?php echo $r['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="editLabel<?php echo $r['id']; ?>">Editar calibração — <?php echo e($r['equipment_name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?php echo url('calibration'); ?>" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Equipamento</label>
                            <select name="equipment_id" class="form-select" required>
                                <?php foreach ($allEquipment as $eq): ?>
                                    <option value="<?php echo $eq['id']; ?>" <?php echo (int)$eq['id']===(int)$r['equipment_id']?'selected':''; ?>>
                                        <?php echo e($eq['name']); ?> <?php echo $eq['code'] ? '(' . e($eq['code']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Data da calibração</label>
                            <input type="date" name="calibration_date" class="form-control" value="<?php echo e($r['calibration_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Próxima calibração</label>
                            <input type="date" name="next_date" class="form-control" value="<?php echo e($r['next_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Órgão/Empresa</label>
                            <input type="text" name="responsible_body" class="form-control" value="<?php echo e($r['responsible_body'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Responsável técnico</label>
                            <input type="text" name="responsible_person" class="form-control" value="<?php echo e($r['responsible_person'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Resultado</label>
                            <select name="result" class="form-select">
                                <?php foreach ($resultLabels as $k=>$v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $r['result']===$k?'selected':''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Custo (R$)</label>
                            <input type="number" step="0.01" name="cost" class="form-control" value="<?php echo e(number_format((float)$r['cost'], 2, '.', '')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Substituir certificado (opcional)</label>
                            <input type="file" name="certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            <?php if (!empty($r['certificate_path'])): ?>
                                <div class="form-text">
                                    Atual: <a href="<?php echo e(uploadUrl($r['certificate_path'])); ?>" target="_blank" rel="noopener">ver certificado</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observations" class="form-control" rows="3"><?php echo e($r['observations'] ?? ''); ?></textarea>
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

<?php endif; /* fecha if ($action === 'execute') else */ ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
