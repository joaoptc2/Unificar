<?php
/**
 * MÓDULO DE EQUIPAMENTOS — Design System "RH Hospital"
 *
 * Ações (GET action=...):
 *   list (padrão)  lista com filtros, seleção múltipla e etiquetas em lote
 *   lookup         busca por código de identificação (leitor USB/QR) → history
 *   history        histórico consolidado (aliases legados: detail, view)
 *   label          etiquetas para impressão (id=X ou ids=1,2,3) — página standalone
 *   edit           formulário de edição
 *   categories     (legado) → painel de configuração na Administração central
 *
 * Todo equipamento tem um CÓDIGO DE IDENTIFICAÇÃO de 12 dígitos
 * (asset_code — lib/asset_code.php), gerado automaticamente na criação ou
 * informado manualmente (validação Luhn + unicidade).
 */
requireModule("equipment");

$hid    = hospitalId();
$action = preg_replace('/[^a-z_]/', '', strtolower($_GET['action'] ?? 'list'));

// Rotas legadas
if ($action === 'categories') {
    core_redirect(core_admin_url(MAN_MODULE_SLUG, 'categories'));
}
if ($action === 'detail' || $action === 'view') {
    $action = 'history';
}

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
        $assetInput  = man_asset_code_normalize((string)($_POST['asset_code'] ?? ''));
        $sectorId    = ((int)($_POST['sector_id'] ?? 0)) ?: null;
        $categoryId  = ((int)($_POST['category_id'] ?? 0)) ?: null;
        $manufacturer= trim($_POST['manufacturer'] ?? '') ?: null;
        $model       = trim($_POST['model'] ?? '') ?: null;
        $serialNumber= trim($_POST['serial_number'] ?? '') ?: null;
        $acqDate     = trim($_POST['acquisition_date'] ?? '') ?: null;
        $criticality = in_array($_POST['criticality'] ?? '', ['low','medium','high','critical'], true) ? $_POST['criticality'] : 'medium';
        $status      = in_array($_POST['status'] ?? '', ['active','maintenance','inactive','broken'], true) ? $_POST['status'] : 'active';
        $description = trim($_POST['description'] ?? '') ?: null;
        $installationDate = trim($_POST['installation_date'] ?? '') ?: null;
        $usefulLifeYears  = ((int)($_POST['useful_life_years'] ?? 0)) ?: null;

        $error = null;
        if ($name === '') {
            $error = 'Nome do equipamento é obrigatório.';
        } elseif ($assetInput !== '' && !man_asset_code_valid($assetInput)) {
            $error = 'Código de identificação inválido: informe 12 dígitos com dígito verificador correto (ou deixe em branco para gerar automaticamente).';
        } elseif ($assetInput !== '' && man_asset_code_exists($assetInput, $id)) {
            $error = 'O código de identificação ' . man_asset_code_format($assetInput) . ' já está em uso por outro equipamento.';
        }

        if ($error !== null) {
            flash('error', $error);
            redirect($act === 'edit' && $id > 0 ? url('equipment', ['action' => 'edit', 'id' => $id]) : url('equipment'));
        }

        try {
            if ($act === 'add') {
                $assetCode = $assetInput !== '' ? $assetInput : man_asset_code_generate();
                $qrToken   = generateToken(16);
                db()->prepare("INSERT INTO man_equipment (hospital_id, sector_id, category_id, code, asset_code, name, manufacturer, model, serial_number, acquisition_date, criticality, status, description, qr_token, installation_date, useful_life_years) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$hid, $sectorId, $categoryId, $code, $assetCode, $name, $manufacturer, $model, $serialNumber, $acqDate, $criticality, $status, $description, $qrToken, $installationDate, $usefulLifeYears]);
                $newId = (int)db()->lastInsertId();
                auditLog('create', 'equipment', $newId, 'asset_code=' . $assetCode);
                flash('success', 'Equipamento adicionado! Código de identificação: ' . man_asset_code_format($assetCode));
                redirect(url('equipment', ['action' => 'history', 'id' => $newId]));
            }

            // edit: código informado substitui; em branco mantém (ou gera se faltava)
            $cur = db()->prepare("SELECT asset_code FROM man_equipment WHERE id = ? AND hospital_id = ?");
            $cur->execute([$id, $hid]);
            $current = $cur->fetchColumn();
            if ($current === false) {
                flash('error', 'Equipamento não encontrado.');
                redirect(url('equipment'));
            }
            $assetCode = $assetInput !== '' ? $assetInput : ((is_string($current) && $current !== '') ? $current : man_asset_code_generate());

            db()->prepare("UPDATE man_equipment SET sector_id=?, category_id=?, code=?, asset_code=?, name=?, manufacturer=?, model=?, serial_number=?, acquisition_date=?, criticality=?, status=?, description=?, installation_date=?, useful_life_years=? WHERE id=? AND hospital_id=?")
                ->execute([$sectorId, $categoryId, $code, $assetCode, $name, $manufacturer, $model, $serialNumber, $acqDate, $criticality, $status, $description, $installationDate, $usefulLifeYears, $id, $hid]);
            auditLog('update', 'equipment', $id, $assetCode !== $current ? 'asset_code=' . $assetCode : '');
            flash('success', 'Equipamento atualizado!');
            redirect(url('equipment', ['action' => 'history', 'id' => $id]));
        } catch (Exception $ex) {
            flash('error', 'Erro: ' . $ex->getMessage());
            redirect(url('equipment'));
        }
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
}

// ============================================================
// LOOKUP: busca por código (leitor de código de barras / QR)
// ============================================================
if ($action === 'lookup') {
    $rawCode = trim((string)($_GET['code'] ?? ''));
    $digits  = man_asset_code_normalize($rawCode);
    $lookupError = '';
    if ($rawCode !== '') {
        $found = null;
        if (strlen($digits) === 12) {
            $st = db()->prepare("SELECT id FROM man_equipment WHERE asset_code = ? AND hospital_id = ?");
            $st->execute([$digits, $hid]);
            $found = $st->fetchColumn();
            if (!$found && !man_asset_code_valid($digits)) {
                $lookupError = 'Código ' . man_asset_code_format($digits) . ' inválido (dígito verificador não confere). Verifique a leitura.';
            }
        }
        if (!$found) {
            // Também aceita o código patrimonial legado ou número de série exatos
            $st = db()->prepare("SELECT id FROM man_equipment WHERE hospital_id = ? AND (code = ? OR serial_number = ?) ORDER BY id LIMIT 1");
            $st->execute([$hid, $rawCode, $rawCode]);
            $found = $st->fetchColumn();
        }
        if ($found) {
            redirect(url('equipment', ['action' => 'history', 'id' => (int)$found]));
        }
        if ($lookupError === '') {
            $lookupError = 'Nenhum equipamento encontrado para "' . $rawCode . '".';
        }
    }
}

// ============================================================
// LABEL: página standalone de etiquetas (sem layout)
// ============================================================
if ($action === 'label') {
    require __DIR__ . '/equipment_label.php';
    exit;
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
// VIEW: LOOKUP (busca por código)
// ============================================================
if ($action === 'lookup'):
    $pageTitle = 'Buscar por código';
?>
<div class="page-header">
    <h1><i class="bi bi-upc-scan me-2"></i>Buscar equipamento por código</h1>
    <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i> Lista de equipamentos</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <?php if ($lookupError !== ''): ?>
                    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> <?php echo e($lookupError); ?></div>
                <?php endif; ?>
                <form method="GET" action="<?php echo core_url('index.php'); ?>" class="man-lookup-form">
                    <input type="hidden" name="m" value="manutencao"><input type="hidden" name="page" value="equipment"><input type="hidden" name="action" value="lookup">
                    <label class="form-label fw-semibold" for="lookupCode">Código de identificação (12 dígitos)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-upc"></i></span>
                        <input type="text" id="lookupCode" name="code" class="form-control font-monospace" value="<?php echo e($rawCode); ?>" placeholder="0000 0000 0000" inputmode="numeric" autocomplete="off" autofocus>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i> Buscar</button>
                    </div>
                    <div class="form-text mt-2">
                        Aponte o leitor de código de barras ou QR para a etiqueta (o leitor digita o código e confirma automaticamente),
                        ou digite os 12 dígitos com ou sem espaços. Também aceita o código patrimonial ou o número de série exatos.
                    </div>
                </form>
            </div>
        </div>
        <div class="text-center text-muted small mt-3">
            <i class="bi bi-phone me-1"></i> Pelo celular, basta escanear o QR code da etiqueta — a página de histórico abre diretamente.
        </div>
    </div>
</div>
<script>
(function () {
    var inp = document.getElementById('lookupCode');
    if (!inp) return;
    inp.focus(); inp.select();
    // Leitores USB digitam rápido e enviam Enter; formata visualmente em blocos de 4
    inp.addEventListener('input', function () {
        var d = inp.value.replace(/\D+/g, '');
        if (d.length === 0 || d.length > 12) return;
        inp.value = d.replace(/(\d{4})(?=\d)/g, '$1 ');
    });
})();
</script>

<?php
// ============================================================
// VIEW: HISTÓRICO DO EQUIPAMENTO (dados, código, linha do tempo, totais)
// ============================================================
elseif ($action === 'history'):
    require __DIR__ . '/equipment_history.php';

// ============================================================
// VIEW: EDITAR
// ============================================================
elseif ($action === 'edit'):
    core_require('equipment.edit');
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM man_equipment WHERE id = ? AND hospital_id = ?");
    $stmt->execute([$id, $hid]);
    $eq = $stmt->fetch();
    if (!$eq) { flash('error', 'Equipamento não encontrado.'); redirect(url('equipment')); }
    $pageTitle = 'Editar equipamento';
?>
<div class="page-header">
    <h1><i class="bi bi-pencil me-2"></i>Editar Equipamento</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('equipment', ['action'=>'history','id'=>$eq['id']]); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clock-history me-1"></i> Histórico</a>
        <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?php echo url('equipment'); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?php echo $eq['id']; ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required">Nome</label><input type="text" class="form-control" name="name" value="<?php echo e($eq['name']); ?>" required></div>
                <div class="col-md-4">
                    <label class="form-label">Código de identificação (12 dígitos)</label>
                    <input type="text" class="form-control font-monospace" name="asset_code" value="<?php echo e(man_asset_code_format((string)($eq['asset_code'] ?? ''))); ?>" placeholder="Gerado automaticamente" inputmode="numeric" maxlength="15" pattern="[0-9 ]*">
                    <div class="form-text">Impresso no código de barras/QR da etiqueta. Só altere se for necessário reaproveitar uma etiqueta já impressa.</div>
                </div>
                <div class="col-md-4"><label class="form-label">Código patrimonial</label><input type="text" class="form-control" name="code" value="<?php echo e($eq['code'] ?? ''); ?>" maxlength="50"></div>
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

<?php
// ============================================================
// VIEW: LISTA
// ============================================================
else:
    // Equipamentos antigos sem código de identificação recebem um agora
    if (man_asset_code_missing_count() > 0) {
        man_asset_code_ensure_all();
    }

    $filter       = trim($_GET['filter'] ?? '');
    $filterStatus = $_GET['filter_status'] ?? '';
    $filterCrit   = $_GET['filter_criticality'] ?? '';
    $filterSector = (int)($_GET['filter_sector'] ?? 0);
    $filterCat    = (int)($_GET['filter_category'] ?? 0);

    $where  = "WHERE e.hospital_id = ?";
    $params = [$hid];
    if ($filter !== '') {
        $where .= " AND (e.name LIKE ? OR e.code LIKE ? OR e.serial_number LIKE ? OR e.asset_code LIKE ?)";
        $digits = man_asset_code_normalize($filter);
        array_push($params, "%{$filter}%", "%{$filter}%", "%{$filter}%", '%' . ($digits !== '' ? $digits : $filter) . '%');
    }
    if ($filterStatus !== '') { $where .= " AND e.status = ?"; $params[] = $filterStatus; }
    if ($filterCrit !== '')   { $where .= " AND e.criticality = ?"; $params[] = $filterCrit; }
    if ($filterSector > 0)    { $where .= " AND e.sector_id = ?"; $params[] = $filterSector; }
    if ($filterCat > 0)       { $where .= " AND e.category_id = ?"; $params[] = $filterCat; }

    $baseQuery = "SELECT e.*, s.name AS sector_name, c.name AS category_name
                  FROM man_equipment e LEFT JOIN man_sectors s ON s.id = e.sector_id LEFT JOIN man_equipment_categories c ON c.id = e.category_id
                  {$where} ORDER BY e.created_at DESC";
    $pg = paginate($baseQuery, $params, 20);
    $filterParams = array_filter(['filter'=>$filter,'filter_status'=>$filterStatus,'filter_criticality'=>$filterCrit,'filter_sector'=>$filterSector ?: '','filter_category'=>$filterCat ?: '']);
?>

<div class="page-header">
    <h1><i class="bi bi-hdd-rack me-2"></i>Equipamentos</h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo url('equipment', ['action'=>'lookup']); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-upc-scan me-1"></i> Buscar por código</a>
        <?php if (core_can('categories.view')): ?>
        <a href="<?php echo core_admin_url(MAN_MODULE_SLUG, 'categories'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags me-1"></i> Categorias</a>
        <?php endif; ?>
        <button type="button" id="btnLabelsSelected" class="btn btn-outline-primary btn-sm" disabled title="Selecione equipamentos na lista"><i class="bi bi-printer me-1"></i> Etiquetas dos selecionados <span class="badge text-bg-primary ms-1" id="selCount">0</span></button>
        <?php if (core_can('equipment.create')): ?>
            <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo Equipamento</button>
        <?php endif; ?>
    </div>
</div>

<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="manutencao"><input type="hidden" name="page" value="equipment">
        <div class="col-md-3">
            <label class="form-label">Buscar</label>
            <input type="text" class="form-control" name="filter" value="<?php echo e($filter); ?>" placeholder="Nome, código, identificação ou série...">
        </div>
        <div class="col-md-2">
            <label class="form-label">Setor</label>
            <select class="form-select" name="filter_sector">
                <option value="">Todos</option>
                <?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $filterSector === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Categoria</label>
            <select class="form-select" name="filter_category">
                <option value="">Todas</option>
                <?php foreach ($categories as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $filterCat === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="filter_status">
                <option value="">Todos</option>
                <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Criticidade</label>
            <select class="form-select" name="filter_criticality">
                <option value="">Todas</option>
                <?php foreach ($critLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterCrit === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
            <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" class="form-check-input" id="selAll" title="Selecionar todos"></th>
                        <th>Identificação</th><th>Nome</th><th>Cód. patrimonial</th><th>Setor</th><th>Categoria</th><th>Criticidade</th><th>Status</th><th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pg['rows'])): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Nenhum equipamento encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($pg['rows'] as $eq): ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input sel-eq" value="<?php echo (int)$eq['id']; ?>"></td>
                        <td><a href="<?php echo url('equipment', ['action'=>'history','id'=>$eq['id']]); ?>" class="font-monospace text-decoration-none fw-semibold text-nowrap"><?php echo e(man_asset_code_format((string)($eq['asset_code'] ?? ''))); ?></a></td>
                        <td><strong><?php echo e($eq['name']); ?></strong><?php if ($eq['model']): ?><br><small class="text-muted"><?php echo e($eq['manufacturer'] ? $eq['manufacturer'] . ' · ' : ''); ?><?php echo e($eq['model']); ?></small><?php endif; ?></td>
                        <td class="text-muted"><?php echo e($eq['code'] ?? '—'); ?></td>
                        <td><?php echo e($eq['sector_name'] ?? '—'); ?></td>
                        <td><?php echo e($eq['category_name'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo e($eq['criticality']); ?>"><?php echo e($critLabels[$eq['criticality']] ?? $eq['criticality']); ?></span></td>
                        <td><span class="badge badge-<?php echo e($eq['status']); ?>"><?php echo e($statusLabels[$eq['status']] ?? $eq['status']); ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="<?php echo url('equipment', ['action'=>'history','id'=>$eq['id']]); ?>" class="btn btn-outline-primary btn-action" title="Histórico"><i class="bi bi-clock-history"></i></a>
                                <a href="<?php echo url('equipment', ['action'=>'label','id'=>$eq['id']]); ?>" class="btn btn-outline-secondary btn-action" title="Etiqueta" target="_blank"><i class="bi bi-upc"></i></a>
                                <?php if (core_can('equipment.edit')): ?>
                                <a href="<?php echo url('equipment', ['action'=>'edit','id'=>$eq['id']]); ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (core_can('equipment.delete') && $eq['status'] !== 'inactive'): ?>
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
        <?php echo paginationLinks($pg['page'], $pg['lastPage'], url('equipment', $filterParams)); ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.sel-eq'));
    var all = document.getElementById('selAll');
    var btn = document.getElementById('btnLabelsSelected');
    var cnt = document.getElementById('selCount');
    var labelBase = <?php echo json_encode(url('equipment', ['action' => 'label'])); ?>;
    function refresh() {
        var ids = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
        cnt.textContent = ids.length;
        btn.disabled = ids.length === 0;
        btn.title = ids.length ? 'Abrir etiquetas de ' + ids.length + ' equipamento(s)' : 'Selecione equipamentos na lista';
        if (all) { all.checked = boxes.length > 0 && ids.length === boxes.length; all.indeterminate = ids.length > 0 && ids.length < boxes.length; }
        return ids;
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
    btn.addEventListener('click', function () {
        var ids = refresh();
        if (ids.length) window.open(labelBase + '&ids=' + ids.join(','), '_blank');
    });
    refresh();
})();
</script>

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
                        <div class="col-md-3">
                            <label class="form-label">Código de identificação</label>
                            <input type="text" class="form-control font-monospace" name="asset_code" placeholder="Automático" inputmode="numeric" maxlength="15" pattern="[0-9 ]*" title="12 dígitos (deixe em branco para gerar)">
                            <div class="form-text">12 dígitos; em branco = gerado.</div>
                        </div>
                        <div class="col-md-3"><label class="form-label">Cód. patrimonial</label><input type="text" class="form-control" name="code" maxlength="50"></div>
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
                        <div class="col-md-4">
                            <label class="form-label">Criticidade</label>
                            <select class="form-select" name="criticality">
                                <?php foreach ($critLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $k === 'medium' ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label">Instalação</label><input type="date" class="form-control" name="installation_date"></div>
                        <div class="col-md-2"><label class="form-label">Vida útil (anos)</label><input type="number" class="form-control" name="useful_life_years" min="0"></div>
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
<?php foreach ($pg['rows'] as $eq): if ($eq['status'] === 'inactive') continue; ?>
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
