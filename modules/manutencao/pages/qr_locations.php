<?php
/**
 * MÓDULO DE QR CODES — Gestão de locais escaneáveis
 */
requireModule('qr-locations');

$hid = hospitalId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    requireWrite();
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $sectorId = ((int)($_POST['sector_id'] ?? 0)) ?: null;
        $desc     = trim($_POST['description'] ?? '') ?: null;
        if ($name === '') {
            flash('error', 'Nome do local é obrigatório.');
        } else {
            $token = generateToken(16);
            db()->prepare("INSERT INTO qr_locations (hospital_id, sector_id, name, description, token) VALUES (?,?,?,?,?)")
                ->execute([$hid, $sectorId, $name, $desc, $token]);
            auditLog('create', 'qr_locations', (int)db()->lastInsertId());
            flash('success', 'Local criado! Token: ' . $token);
        }
        redirect(url('qr-locations'));
    }
    if ($act === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $sectorId = ((int)($_POST['sector_id'] ?? 0)) ?: null;
        $desc     = trim($_POST['description'] ?? '') ?: null;
        $status   = $_POST['status'] ?? 'active';
        if ($name !== '') {
            db()->prepare("UPDATE qr_locations SET name=?, sector_id=?, description=?, status=? WHERE id=? AND hospital_id=?")
                ->execute([$name, $sectorId, $desc, $status, $id, $hid]);
            auditLog('update', 'qr_locations', $id);
            flash('success', 'Local atualizado!');
        }
        redirect(url('qr-locations'));
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM qr_locations WHERE id=? AND hospital_id=?")->execute([$id, $hid]);
        auditLog('delete', 'qr_locations', $id);
        flash('success', 'Local removido.');
        redirect(url('qr-locations'));
    }
}

$locs = db()->prepare("
    SELECT ql.*, s.name AS sector_name
    FROM qr_locations ql
    LEFT JOIN sectors s ON s.id = ql.sector_id
    WHERE ql.hospital_id = ?
    ORDER BY ql.name
");
$locs->execute([$hid]);
$locs = $locs->fetchAll();

$sectors = db()->prepare("SELECT id, name FROM sectors WHERE hospital_id = ? AND status='active' ORDER BY name");
$sectors->execute([$hid]);
$sectors = $sectors->fetchAll();

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$baseUrl = rtrim($baseUrl, '/') . '/';

$pageTitle = 'QR Codes';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-qr-code me-2"></i>Locais com QR Code</h1>
    <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo local</button>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    Cada local gera um link único. Imprima o QR Code e cole na parede do ambiente. Qualquer pessoa pode escanear para solicitar limpeza ou manutenção sem precisar de login.
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Local</th><th>Setor</th><th>Status</th><th>Link</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if (empty($locs)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum local cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($locs as $l):
                        $scanUrl = $baseUrl . 'index.php?page=qr-scan&token=' . urlencode($l['token']);
                    ?>
                    <tr>
                        <td><strong><?php echo e($l['name']); ?></strong>
                            <?php if ($l['description']): ?><br><small class="text-muted"><?php echo e($l['description']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo e($l['sector_name'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo e($l['status']); ?>"><?php echo $l['status']==='active'?'Ativo':'Inativo'; ?></span></td>
                        <td>
                            <div class="input-group input-group-sm" style="max-width:320px">
                                <input type="text" class="form-control form-control-sm" value="<?php echo e($scanUrl); ?>" readonly id="url<?php echo $l['id']; ?>">
                                <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('url<?php echo $l['id']; ?>').value);this.innerHTML='<i class=\'bi bi-check\'></i>';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',1500)" title="Copiar"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button onclick="openModal('editLoc<?php echo $l['id']; ?>')" class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></button>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
                                    <button class="btn btn-outline-danger btn-action" data-confirm="Excluir '<?php echo e($l['name']); ?>'?"><i class="bi bi-trash"></i></button>
                                </form>
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

<!-- MODAL NOVO -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header py-2"><h5 class="modal-title fw-semibold"><i class="bi bi-plus-lg me-1"></i> Novo local</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label required">Nome do local</label><input type="text" class="form-control" name="name" required placeholder="Ex: Quarto 201, Banheiro Ala B"></div>
                        <div class="col-md-6"><label class="form-label">Setor</label><select class="form-select" name="sector_id"><option value="0">Nenhum</option><?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label">Descrição</label><input type="text" class="form-control" name="description" placeholder="Opcional"></div>
                    </div>
                </div>
                <div class="modal-footer py-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Criar</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAIS EDIÇÃO -->
<?php foreach ($locs as $l): ?>
<div class="modal fade" id="editLoc<?php echo $l['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
                <div class="modal-header py-2"><h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i> Editar: <?php echo e($l['name']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label required">Nome</label><input type="text" class="form-control" name="name" value="<?php echo e($l['name']); ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Setor</label><select class="form-select" name="sector_id"><option value="0">Nenhum</option><?php foreach ($sectors as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo (int)$s['id']===(int)$l['sector_id']?'selected':''; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?php echo $l['status']==='active'?'selected':''; ?>>Ativo</option><option value="inactive" <?php echo $l['status']==='inactive'?'selected':''; ?>>Inativo</option></select></div>
                        <div class="col-12"><label class="form-label">Descrição</label><input type="text" class="form-control" name="description" value="<?php echo e($l['description'] ?? ''); ?>"></div>
                    </div>
                </div>
                <div class="modal-footer py-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Salvar</button></div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
