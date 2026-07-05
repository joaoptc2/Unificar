<?php
/**
 * MÓDULO DE LIMPEZA HOSPITALAR
 *
 * Dois universos:
 *   1. Agendamentos/templates (cleaning_schedules) — definem o checklist
 *      por setor, tipo (concorrente/terminal/preparatória) e frequência.
 *   2. Execuções (cleaning_executions) — registro real com itens
 *      marcados, foto opcional e assinatura digital (canvas) do
 *      responsável. O % de conformidade é calculado automaticamente.
 *
 * Views:
 *   ?page=cleaning               → lista execuções + KPIs
 *   ?page=cleaning&action=plans  → gerencia templates
 *   ?page=cleaning&action=record → registra nova execução (formulário)
 */
requireModule("cleaning");

$hid    = hospitalId();
$action = $_GET['action'] ?? 'list';

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    requireWrite();
    $act = $_POST['action'] ?? '';

    /* ----- Templates/agendamentos ---------------------------- */
    if ($act === 'plan_add' || $act === 'plan_edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $sector   = ((int)($_POST['sector_id'] ?? 0)) ?: null;
        $type     = $_POST['type'] ?? 'concurrent';
        $freq     = $_POST['frequency'] ?? 'daily';
        $instr    = trim($_POST['instructions'] ?? '') ?: null;

        $raw = trim($_POST['checklist_items'] ?? '');
        $items = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }
        $json = $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;

        if ($title === '' || empty($items)) {
            flash('error', 'Título e ao menos um item de checklist são obrigatórios.');
            redirect(url('cleaning', ['action' => 'plans']));
        }

        if ($act === 'plan_add') {
            db()->prepare("
                INSERT INTO cleaning_schedules
                    (hospital_id, sector_id, title, type, frequency, checklist_items, instructions, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ")->execute([$hid, $sector, $title, $type, $freq, $json, $instr]);
            auditLog('create', 'cleaning_schedules', (int)db()->lastInsertId());
            flash('success', 'Checklist criado!');
        } else {
            db()->prepare("
                UPDATE cleaning_schedules
                SET sector_id=?, title=?, type=?, frequency=?, checklist_items=?, instructions=?
                WHERE id=? AND hospital_id=?
            ")->execute([$sector, $title, $type, $freq, $json, $instr, $id, $hid]);
            auditLog('update', 'cleaning_schedules', $id);
            flash('success', 'Checklist atualizado.');
        }
        redirect(url('cleaning', ['action' => 'plans']));
    }

    if ($act === 'plan_delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM cleaning_schedules WHERE id = ? AND hospital_id = ?")
            ->execute([$id, $hid]);
        auditLog('delete', 'cleaning_schedules', $id);
        flash('success', 'Checklist removido.');
        redirect(url('cleaning', ['action' => 'plans']));
    }

    /* ----- Registro de execução ------------------------------ */
    if ($act === 'record') {
        $scheduleId = ((int)($_POST['schedule_id'] ?? 0)) ?: null;
        $sectorId   = ((int)($_POST['sector_id'] ?? 0)) ?: null;
        $type       = $_POST['type'] ?? 'concurrent';
        $executedBy = trim($_POST['executed_by_name'] ?? '');
        $observ     = trim($_POST['observation'] ?? '') ?: null;
        $signature  = $_POST['signature_data'] ?? '';

        // Normaliza assinatura (apenas dataURL PNG, até ~100KB)
        if (is_string($signature) && strncmp($signature, 'data:image/png;base64,', 22) === 0) {
            if (strlen($signature) > 150000) {
                $signature = null;
            }
        } else {
            $signature = null;
        }

        $checked = $_POST['checked'] ?? [];
        if (!is_array($checked)) $checked = [];

        $totalItems = 0;
        $template = null;
        if ($scheduleId) {
            $st = db()->prepare("SELECT * FROM cleaning_schedules WHERE id = ? AND hospital_id = ?");
            $st->execute([$scheduleId, $hid]);
            $template = $st->fetch() ?: null;
            if ($template) {
                $tItems = json_decode($template['checklist_items'] ?? '[]', true) ?: [];
                $totalItems = count($tItems);
                $sectorId = $sectorId ?: ($template['sector_id'] ?: null);
                $type     = $template['type'];
            }
        }

        if ($executedBy === '') {
            flash('error', 'Informe o nome do responsável pela execução.');
            redirect(url('cleaning', ['action' => 'record', 'schedule_id' => (int)$scheduleId]));
        }

        $compliance = null;
        if ($totalItems > 0) {
            $done = count(array_filter(array_map('intval', $checked), fn($v) => $v === 1));
            $compliance = (int)round(($done / $totalItems) * 100);
        }

        // Upload de foto opcional
        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = uploadFile($_FILES['photo'], 'cleaning');
        }

        $checkedJson = json_encode(array_values(array_map('intval', $checked)), JSON_UNESCAPED_UNICODE);

        db()->prepare("
            INSERT INTO cleaning_executions
                (hospital_id, schedule_id, sector_id, type, executed_by_name,
                 executed_by_user, checked_items, compliance_pct, photo_path,
                 signature_data, observation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $hid, $scheduleId, $sectorId, $type, $executedBy,
            $_SESSION['user_id'] ?? null, $checkedJson, $compliance,
            $photoPath, $signature, $observ,
        ]);
        auditLog('create', 'cleaning_executions', (int)db()->lastInsertId(), 'compliance=' . ($compliance ?? 'n/a'));
        flash('success', 'Execução de limpeza registrada.');
        redirect(url('cleaning'));
    }
}

// ============================================================
// SETORES (reusados em várias views)
// ============================================================
$sectors = db()->prepare("SELECT id, name FROM sectors WHERE hospital_id = ? AND status = 'active' ORDER BY name");
$sectors->execute([$hid]);
$sectors = $sectors->fetchAll();

$typeLabels = ['concurrent' => 'Concorrente', 'terminal' => 'Terminal', 'preparatory' => 'Preparatória'];
$freqLabels = ['daily' => 'Diária', 'weekly' => 'Semanal', 'biweekly' => 'Quinzenal', 'monthly' => 'Mensal', 'on_demand' => 'Sob demanda'];

$pageTitle = 'Limpeza Hospitalar';
ob_start();

// ============================================================
// VIEW: GERENCIAR TEMPLATES (action=plans)
// ============================================================
if ($action === 'plans') {
    $st = db()->prepare("
        SELECT cs.*, s.name AS sector_name
        FROM cleaning_schedules cs
        LEFT JOIN sectors s ON s.id = cs.sector_id
        WHERE cs.hospital_id = ?
        ORDER BY cs.title
    ");
    $st->execute([$hid]);
    $plans = $st->fetchAll();
?>
    <div class="page-header">
        <h1><i class="bi bi-card-checklist me-2"></i>Checklists de Limpeza</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('cleaning'); ?>"><i class="bi bi-arrow-left me-1"></i> Execuções</a>
            <?php if (canWrite()): ?>
                <button onclick="openModal('modalPlanAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo checklist</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr><th>Título</th><th>Setor</th><th>Tipo</th><th>Frequência</th><th>Itens</th><th>Status</th><th class="text-end">Ações</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($plans)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nenhum checklist cadastrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($plans as $p):
                            $items = json_decode($p['checklist_items'] ?? '[]', true) ?: [];
                        ?>
                        <tr>
                            <td><strong><?php echo e($p['title']); ?></strong></td>
                            <td><?php echo e($p['sector_name'] ?? 'Qualquer'); ?></td>
                            <td><?php echo e($typeLabels[$p['type']] ?? $p['type']); ?></td>
                            <td><?php echo e($freqLabels[$p['frequency']] ?? $p['frequency']); ?></td>
                            <td><?php echo count($items); ?></td>
                            <td><span class="badge badge-<?php echo $p['status']; ?>"><?php echo $p['status']; ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-outline-success btn-action" href="<?php echo url('cleaning', ['action' => 'record', 'schedule_id' => $p['id']]); ?>" title="Executar"><i class="bi bi-play-fill"></i></a>
                                    <?php if (canWrite()): ?>
                                        <button class="btn btn-outline-warning btn-action" onclick="openModal('planEdit<?php echo $p['id']; ?>')" title="Editar"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="plan_delete">
                                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                            <button class="btn btn-outline-danger btn-action" data-confirm="Excluir este checklist?"><i class="bi bi-trash"></i></button>
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

    <?php if (canWrite()): ?>
    <div class="modal fade" id="modalPlanAdd" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('cleaning', ['action' => 'plans']); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="plan_add">
                    <div class="modal-header py-2">
                        <h5 class="modal-title fw-semibold"><i class="bi bi-plus-lg me-1"></i> Novo checklist</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label required">Título</label><input type="text" class="form-control" name="title" required placeholder="Ex: Limpeza concorrente — UTI"></div>
                            <div class="col-md-4">
                                <label class="form-label">Setor</label>
                                <select class="form-select" name="sector_id"><option value="0">Qualquer</option>
                                <?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="type">
                                <?php foreach ($typeLabels as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Frequência</label>
                                <select class="form-select" name="frequency">
                                <?php foreach ($freqLabels as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12"><label class="form-label required">Itens do checklist</label><textarea class="form-control" name="checklist_items" rows="6" required placeholder="Um item por linha"></textarea></div>
                            <div class="col-12"><label class="form-label">Instruções</label><textarea class="form-control" name="instructions" rows="2"></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Criar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($plans as $p):
        $items = json_decode($p['checklist_items'] ?? '[]', true) ?: [];
    ?>
    <div class="modal fade" id="planEdit<?php echo $p['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('cleaning', ['action' => 'plans']); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="plan_edit">
                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                    <div class="modal-header py-2">
                        <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i> Editar: <?php echo e($p['title']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label required">Título</label><input type="text" class="form-control" name="title" value="<?php echo e($p['title']); ?>" required></div>
                            <div class="col-md-4"><label class="form-label">Setor</label><select class="form-select" name="sector_id"><option value="0">Qualquer</option><?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo (int)$s['id']===(int)$p['sector_id']?'selected':''; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="type"><?php foreach ($typeLabels as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $p['type']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label">Frequência</label><select class="form-select" name="frequency"><?php foreach ($freqLabels as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $p['frequency']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label required">Itens do checklist</label><textarea class="form-control" name="checklist_items" rows="6" required><?php echo e(implode("\n", $items)); ?></textarea></div>
                            <div class="col-12"><label class="form-label">Instruções</label><textarea class="form-control" name="instructions" rows="2"><?php echo e($p['instructions'] ?? ''); ?></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

<?php
// ============================================================
// VIEW: FORMULÁRIO DE EXECUÇÃO (action=record)
// ============================================================
} elseif ($action === 'record') {
    $scheduleId = (int)($_GET['schedule_id'] ?? 0);
    $plan = null;
    $items = [];
    if ($scheduleId) {
        $st = db()->prepare("SELECT * FROM cleaning_schedules WHERE id = ? AND hospital_id = ?");
        $st->execute([$scheduleId, $hid]);
        $plan  = $st->fetch() ?: null;
        if ($plan) $items = json_decode($plan['checklist_items'] ?? '[]', true) ?: [];
    }

    // Se não veio com schedule_id, permite escolher
    $allPlans = [];
    if (!$plan) {
        $st = db()->prepare("SELECT id, title FROM cleaning_schedules WHERE hospital_id = ? AND status='active' ORDER BY title");
        $st->execute([$hid]);
        $allPlans = $st->fetchAll();
    }
?>
    <div class="page-header">
        <h1><i class="bi bi-clipboard-plus me-2"></i>Registrar execução de limpeza</h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('cleaning'); ?>"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>

    <?php if (!$plan): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="mb-3">Selecione um checklist:</p>
                <?php if (empty($allPlans)): ?>
                    <p class="text-muted">Nenhum checklist cadastrado.
                        <a href="<?php echo url('cleaning', ['action' => 'plans']); ?>">Criar um agora</a>.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($allPlans as $ap): ?>
                            <a class="list-group-item list-group-item-action" href="<?php echo url('cleaning', ['action' => 'record', 'schedule_id' => $ap['id']]); ?>">
                                <i class="bi bi-card-checklist me-2"></i><?php echo e($ap['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?php echo e($plan['title']); ?></span>
                <span class="badge badge-<?php echo $plan['status']; ?>"><?php echo e($typeLabels[$plan['type']] ?? $plan['type']); ?></span>
            </div>
            <div class="card-body">
                <?php if ($plan['instructions']): ?>
                    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i> <?php echo nl2br(e($plan['instructions'])); ?></div>
                <?php endif; ?>
                <form method="POST" action="<?php echo url('cleaning', ['action' => 'record']); ?>" enctype="multipart/form-data" id="cleaningForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="record">
                    <input type="hidden" name="schedule_id" value="<?php echo (int)$plan['id']; ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Setor</label>
                            <select class="form-select" name="sector_id">
                                <option value="0">— (usar setor do checklist)</option>
                                <?php foreach ($sectors as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo (int)$s['id']===(int)$plan['sector_id']?'selected':''; ?>><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Responsável pela execução</label>
                            <input type="text" class="form-control" name="executed_by_name" required value="<?php echo e($_SESSION['user_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <h6 class="fw-semibold mb-2"><i class="bi bi-list-check me-1"></i> Itens do checklist</h6>
                    <div class="card bg-light border mb-3">
                        <div class="card-body py-2">
                            <?php foreach ($items as $i => $item): ?>
                                <div class="form-check py-1">
                                    <input type="hidden" name="checked[<?php echo $i; ?>]" value="0">
                                    <input class="form-check-input" type="checkbox" name="checked[<?php echo $i; ?>]" value="1" id="chk<?php echo $i; ?>">
                                    <label class="form-check-label" for="chk<?php echo $i; ?>"><?php echo e($item); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Foto (opcional)</label>
                        <input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png,.webp">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Assinatura do responsável</label>
                        <canvas id="sigPad" width="500" height="140" style="border:1px solid #e2e8f0;border-radius:6px;background:#fff;touch-action:none;max-width:100%"></canvas>
                        <input type="hidden" name="signature_data" id="sigData">
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="sigClear()"><i class="bi bi-eraser me-1"></i> Limpar</button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Observação</label>
                        <textarea class="form-control" name="observation" rows="3"></textarea>
                    </div>

                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Registrar execução</button>
                </form>
            </div>
        </div>

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

            document.getElementById('cleaningForm').addEventListener('submit', function(){
                // Só envia assinatura se o usuário realmente desenhou algo
                var blank = document.createElement('canvas');
                blank.width = canvas.width; blank.height = canvas.height;
                if (canvas.toDataURL() !== blank.toDataURL()) {
                    document.getElementById('sigData').value = canvas.toDataURL('image/png');
                }
            });
        })();
        </script>
    <?php endif; ?>

<?php
// ============================================================
// VIEW: LISTA DE EXECUÇÕES (default)
// ============================================================
} else {
    $filterSector = (int)($_GET['sector_id'] ?? 0);
    $filterType   = $_GET['type'] ?? '';
    $filterFrom   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $filterTo     = $_GET['to']   ?? date('Y-m-d');

    $where  = ['ce.hospital_id = ?'];
    $params = [$hid];
    if ($filterSector) { $where[] = 'ce.sector_id = ?'; $params[] = $filterSector; }
    if (isset($typeLabels[$filterType])) { $where[] = 'ce.type = ?'; $params[] = $filterType; }
    if ($filterFrom) { $where[] = 'ce.executed_at >= ?'; $params[] = $filterFrom . ' 00:00:00'; }
    if ($filterTo)   { $where[] = 'ce.executed_at <= ?'; $params[] = $filterTo . ' 23:59:59'; }

    $st = db()->prepare("
        SELECT ce.*, s.name AS sector_name, cs.title AS schedule_title
        FROM cleaning_executions ce
        LEFT JOIN sectors s            ON s.id = ce.sector_id
        LEFT JOIN cleaning_schedules cs ON cs.id = ce.schedule_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ce.executed_at DESC
        LIMIT 200
    ");
    $st->execute($params);
    $rows = $st->fetchAll();

    // KPIs
    $kpi = db()->prepare("
        SELECT
            COUNT(*) AS total,
            AVG(compliance_pct) AS avg_compliance,
            SUM(CASE WHEN compliance_pct = 100 THEN 1 ELSE 0 END) AS full_compliance
        FROM cleaning_executions
        WHERE hospital_id = ? AND executed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $kpi->execute([$hid]);
    $k = $kpi->fetch() ?: ['total'=>0,'avg_compliance'=>0,'full_compliance'=>0];
?>
    <div class="page-header">
        <h1><i class="bi bi-droplet-half me-2"></i>Execuções de limpeza</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary btn-sm" href="export.php?type=cleaning&format=csv"><i class="bi bi-download me-1"></i> CSV</a>
            <a class="btn btn-outline-primary btn-sm" href="export.php?type=cleaning&format=print" target="_blank"><i class="bi bi-printer me-1"></i> Imprimir</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('cleaning', ['action' => 'plans']); ?>"><i class="bi bi-card-checklist me-1"></i> Checklists</a>
            <?php if (canWrite()): ?>
                <a class="btn btn-primary btn-sm" href="<?php echo url('cleaning', ['action' => 'record']); ?>"><i class="bi bi-plus-lg me-1"></i> Registrar</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-clipboard-data"></i></div>
                <div><div class="stat-value"><?php echo (int)$k['total']; ?></div><div class="stat-label">Execuções (30d)</div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="bi bi-percent"></i></div>
                <div><div class="stat-value text-success"><?php echo $k['avg_compliance'] !== null ? round((float)$k['avg_compliance']) . '%' : '—'; ?></div><div class="stat-label">Conformidade média</div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info bg-opacity-10 text-info me-3"><i class="bi bi-check-all"></i></div>
                <div><div class="stat-value"><?php echo (int)$k['full_compliance']; ?></div><div class="stat-label">100% conformes</div></div>
            </div></div>
        </div>
    </div>

    <div class="filter-panel">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="cleaning">
            <div class="col-md-3"><label class="form-label">Setor</label><select class="form-select" name="sector_id"><option value="0">Todos</option><?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $filterSector===(int)$s['id']?'selected':''; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select" name="type"><option value="">Todos</option><?php foreach ($typeLabels as $k2 => $v): ?><option value="<?php echo $k2; ?>" <?php echo $filterType===$k2?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">De</label><input type="date" class="form-control" name="from" value="<?php echo e($filterFrom); ?>"></div>
            <div class="col-md-2"><label class="form-label">Até</label><input type="date" class="form-control" name="to" value="<?php echo e($filterTo); ?>"></div>
            <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button><a class="btn btn-outline-secondary btn-sm" href="<?php echo url('cleaning'); ?>"><i class="bi bi-x-lg"></i></a></div>
        </form>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Data/Hora</th><th>Setor</th><th>Tipo</th><th>Checklist</th><th>Responsável</th><th>Conformidade</th><th>Anexos</th></tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma execução no período.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $pct = $r['compliance_pct'];
                            $pctClass = $pct === null ? 'bg-secondary' : ($pct >= 90 ? 'badge-completed' : ($pct >= 70 ? 'badge-medium' : 'badge-critical'));
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo formatDate($r['executed_at'], 'd/m/Y H:i'); ?></td>
                            <td><?php echo e($r['sector_name'] ?? '—'); ?></td>
                            <td><?php echo e($typeLabels[$r['type']] ?? $r['type']); ?></td>
                            <td><?php echo e($r['schedule_title'] ?? '—'); ?></td>
                            <td><strong><?php echo e($r['executed_by_name']); ?></strong></td>
                            <td><span class="badge <?php echo $pctClass; ?>"><?php echo $pct !== null ? $pct . '%' : '—'; ?></span></td>
                            <td>
                                <?php if (!empty($r['photo_path'])): ?>
                                    <a href="uploads/<?php echo e($r['photo_path']); ?>" target="_blank" class="btn btn-outline-secondary btn-action" title="Foto"><i class="bi bi-image"></i></a>
                                <?php endif; ?>
                                <?php if (!empty($r['signature_data'])): ?>
                                    <button class="btn btn-outline-secondary btn-action" onclick="openModal('sig<?php echo $r['id']; ?>')" title="Assinatura"><i class="bi bi-pen"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php foreach ($rows as $r): if (empty($r['signature_data'])) continue; ?>
    <div class="modal fade" id="sig<?php echo $r['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold">Assinatura — <?php echo e($r['executed_by_name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="<?php echo e($r['signature_data']); ?>" alt="assinatura" class="img-fluid rounded border">
                    <small class="text-muted d-block mt-2">Registrada em <?php echo formatDate($r['executed_at'], 'd/m/Y H:i'); ?></small>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

<?php }
$content = ob_get_clean();
require __DIR__ . '/layout.php';
