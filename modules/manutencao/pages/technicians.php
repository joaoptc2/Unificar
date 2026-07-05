<?php
/**
 * MÓDULO DE EQUIPE TÉCNICA — Design System "RH Hospital"
 */
requireModule("technicians");

$hid = hospitalId();

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    requireWrite();
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name       = trim($_POST['name'] ?? '');
        $specialty  = trim($_POST['specialty'] ?? '') ?: null;
        $phone      = trim($_POST['phone'] ?? '') ?: null;
        $email      = trim($_POST['email'] ?? '') ?: null;
        $crea       = trim($_POST['crea'] ?? '') ?: null;
        $status     = 'available';
        if ($name === '') {
            flash('error', 'Nome é obrigatório.');
        } else {
            db()->prepare("INSERT INTO man_technicians (hospital_id, name, specialty, phone, email, crea, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$hid, $name, $specialty, $phone, $email, $crea, $status]);
            auditLog('create', 'technicians', (int)db()->lastInsertId());
            flash('success', 'Técnico adicionado!');
        }
        redirect(url('technicians'));
    }
    if ($act === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $specialty  = trim($_POST['specialty'] ?? '') ?: null;
        $phone      = trim($_POST['phone'] ?? '') ?: null;
        $email      = trim($_POST['email'] ?? '') ?: null;
        $crea       = trim($_POST['crea'] ?? '') ?: null;
        $status     = $_POST['status'] ?? 'available';
        if ($name === '') {
            flash('error', 'Nome é obrigatório.');
        } else {
            db()->prepare("UPDATE man_technicians SET name=?, specialty=?, phone=?, email=?, crea=?, status=? WHERE id=? AND hospital_id=?")
                ->execute([$name, $specialty, $phone, $email, $crea, $status, $id, $hid]);
            auditLog('update', 'technicians', $id);
            flash('success', 'Técnico atualizado!');
        }
        redirect(url('technicians'));
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM man_technicians WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
        auditLog('delete', 'technicians', $id);
        flash('success', 'Técnico removido.');
        redirect(url('technicians'));
    }
}

// ============================================================
// DADOS
// ============================================================
$techs = db()->prepare("SELECT * FROM man_technicians WHERE hospital_id = ? ORDER BY name");
$techs->execute([$hid]);
$techs = $techs->fetchAll();

$statusLabels = ['available'=>'Disponível','busy'=>'Ocupado','off'=>'Folga','inactive'=>'Inativo'];

$pageTitle = 'Equipe Técnica';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-person-badge me-2"></i>Equipe Técnica</h1>
    <div class="d-flex gap-2">
        <?php if (canWrite()): ?>
            <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Novo Técnico
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-people me-1"></i> <?php echo count($techs); ?> técnico(s) cadastrado(s)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr><th>Nome</th><th>Especialidade</th><th>Telefone</th><th>Email</th><th>CREA</th><th>Status</th><th class="text-end">Ações</th></tr>
                </thead>
                <tbody>
                <?php if (empty($techs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhum técnico cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($techs as $t): ?>
                    <tr>
                        <td><strong><?php echo e($t['name']); ?></strong></td>
                        <td><?php echo e($t['specialty'] ?? '—'); ?></td>
                        <td><?php echo e($t['phone'] ?? '—'); ?></td>
                        <td><?php echo e($t['email'] ?? '—'); ?></td>
                        <td><?php echo e($t['crea'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo e($t['status']); ?>"><?php echo e($statusLabels[$t['status']] ?? $t['status']); ?></span></td>
                        <td class="text-end">
                            <?php if (canWrite()): ?>
                            <div class="d-inline-flex gap-1">
                                <button onclick="openModal('editTech<?php echo $t['id']; ?>')" class="btn btn-outline-warning btn-action" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir o técnico '<?php echo e($t['name']); ?>'?" title="Excluir">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
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

<?php if (canWrite()): ?>
<!-- MODAL NOVO TÉCNICO -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('technicians'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-person-plus me-1"></i> Novo Técnico</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Nome</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Especialidade</label>
                            <input type="text" class="form-control" name="specialty" placeholder="Ex: Eletromecânica">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefone</label>
                            <input type="text" class="form-control" name="phone" data-mask="phone">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">CREA</label>
                            <input type="text" class="form-control" name="crea">
                        </div>
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

<!-- MODAIS DE EDIÇÃO -->
<?php foreach ($techs as $t): ?>
<div class="modal fade" id="editTech<?php echo $t['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('technicians'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i> Editar: <?php echo e($t['name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Nome</label>
                            <input type="text" class="form-control" name="name" value="<?php echo e($t['name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Especialidade</label>
                            <input type="text" class="form-control" name="specialty" value="<?php echo e($t['specialty'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefone</label>
                            <input type="text" class="form-control" name="phone" value="<?php echo e($t['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo e($t['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">CREA</label>
                            <input type="text" class="form-control" name="crea" value="<?php echo e($t['crea'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statusLabels as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $t['status'] === $k ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
$content = ob_get_clean();
require __DIR__ . '/layout.php';
