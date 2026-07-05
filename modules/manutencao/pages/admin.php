<?php
/**
 * MÓDULO DE ADMINISTRAÇÃO
 * Hospital, Setores, Usuários com permissões
 */
requireModule('admin');

$hid = hospitalId();
$tab = $_GET['tab'] ?? 'hospital';

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';

    // --- HOSPITAL ---
    if ($act === 'edit_hospital') {
        $name    = trim($_POST['name'] ?? '');
        $cnpj    = trim($_POST['cnpj'] ?? '') ?: null;
        $address = trim($_POST['address'] ?? '') ?: null;
        $city    = trim($_POST['city'] ?? '') ?: null;
        $state   = trim($_POST['state'] ?? '') ?: null;
        $phone   = trim($_POST['phone'] ?? '') ?: null;
        $email   = trim($_POST['email'] ?? '') ?: null;
        $contact = trim($_POST['contact_person'] ?? '') ?: null;

        if ($name === '') {
            flash('error', 'Nome do hospital é obrigatório.');
        } else {
            db()->prepare("
                UPDATE man_hospitals SET name=?, cnpj=?, address=?, city=?, state=?, phone=?, email=?, contact_person=?
                WHERE id=?
            ")->execute([$name, $cnpj, $address, $city, $state, $phone, $email, $contact, $hid]);
            auditLog('update', 'hospitals', $hid);
            flash('success', 'Dados do hospital atualizados!');
        }
        redirect(url('admin', ['tab' => 'hospital']));
    }

    // --- SETORES ---
    if ($act === 'add_sector') {
        $name = trim($_POST['sector_name'] ?? '');
        $desc = trim($_POST['sector_desc'] ?? '') ?: null;
        if ($name !== '') {
            db()->prepare("INSERT INTO man_sectors (hospital_id, name, description) VALUES (?, ?, ?)")->execute([$hid, $name, $desc]);
            auditLog('create', 'sectors', (int)db()->lastInsertId());
            flash('success', 'Setor adicionado!');
        }
        redirect(url('admin', ['tab' => 'sectors']));
    }
    if ($act === 'edit_sector') {
        $id   = (int)($_POST['sector_id'] ?? 0);
        $name = trim($_POST['sector_name'] ?? '');
        $desc = trim($_POST['sector_desc'] ?? '') ?: null;
        $status = $_POST['sector_status'] ?? 'active';
        if ($name !== '') {
            db()->prepare("UPDATE man_sectors SET name=?, description=?, status=? WHERE id=? AND hospital_id=?")->execute([$name, $desc, $status, $id, $hid]);
            auditLog('update', 'sectors', $id);
            flash('success', 'Setor atualizado!');
        }
        redirect(url('admin', ['tab' => 'sectors']));
    }
    if ($act === 'delete_sector') {
        $id = (int)($_POST['sector_id'] ?? 0);
        db()->prepare("UPDATE man_equipment SET sector_id = NULL WHERE sector_id = ? AND hospital_id = ?")->execute([$id, $hid]);
        db()->prepare("DELETE FROM man_sectors WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
        auditLog('delete', 'sectors', $id);
        flash('success', 'Setor removido.');
        redirect(url('admin', ['tab' => 'sectors']));
    }

    // --- USUÁRIOS ---
    if ($act === 'add_user') {
        $name     = trim($_POST['user_name'] ?? '');
        $email    = trim($_POST['user_email'] ?? '');
        $password = $_POST['user_password'] ?? '';
        $role     = $_POST['user_role'] ?? 'viewer';
        $phone    = trim($_POST['user_phone'] ?? '') ?: null;

        if ($name === '' || $email === '' || $password === '') {
            flash('error', 'Nome, email e senha são obrigatórios.');
        } elseif (strlen($password) < 6) {
            flash('error', 'Senha deve ter no mínimo 6 caracteres.');
        } else {
            $stmt = db()->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                flash('error', 'Email já cadastrado.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare("
                    INSERT INTO users (hospital_id, name, email, password, role, phone, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ")->execute([$hid, $name, $email, $hash, $role, $phone]);
                auditLog('create', 'users', (int)db()->lastInsertId());
                flash('success', 'Usuário adicionado!');
            }
        }
        redirect(url('admin', ['tab' => 'users']));
    }
    if ($act === 'edit_user') {
        $id     = (int)($_POST['user_id'] ?? 0);
        $name   = trim($_POST['user_name'] ?? '');
        $email  = trim($_POST['user_email'] ?? '');
        $role   = $_POST['user_role'] ?? 'viewer';
        $phone  = trim($_POST['user_phone'] ?? '') ?: null;
        $status = $_POST['user_status'] ?? 'active';
        $newPass = $_POST['user_password'] ?? '';

        if ($name === '' || $email === '') {
            flash('error', 'Nome e email são obrigatórios.');
        } else {
            $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                flash('error', 'Email já usado por outro usuário.');
            } else {
                $sql = "UPDATE users SET name=?, email=?, role=?, phone=?, status=?";
                $params = [$name, $email, $role, $phone, $status];

                if ($newPass !== '' && strlen($newPass) >= 6) {
                    $sql .= ", password=?";
                    $params[] = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                }

                $sql .= " WHERE id=? AND hospital_id=?";
                $params[] = $id;
                $params[] = $hid;

                db()->prepare($sql)->execute($params);
                auditLog('update', 'users', $id);
                flash('success', 'Usuário atualizado!');
            }
        }
        redirect(url('admin', ['tab' => 'users']));
    }
    if ($act === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) {
            flash('error', 'Você não pode excluir sua própria conta.');
        } else {
            db()->prepare("DELETE FROM users WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
            auditLog('delete', 'users', $id);
            flash('success', 'Usuário removido.');
        }
        redirect(url('admin', ['tab' => 'users']));
    }
}

// ============================================================
// OBTER DADOS
// ============================================================
$hospital = db()->prepare("SELECT * FROM man_hospitals WHERE id = ?");
$hospital->execute([$hid]);
$hospital = $hospital->fetch();

$sectorsList = db()->prepare("SELECT * FROM man_sectors WHERE hospital_id = ? ORDER BY name");
$sectorsList->execute([$hid]);
$sectorsList = $sectorsList->fetchAll();

$usersList = db()->prepare("SELECT * FROM users WHERE hospital_id = ? ORDER BY name");
$usersList->execute([$hid]);
$usersList = $usersList->fetchAll();

$roleLabels = ['admin'=>'Administrador','manager'=>'Gerente','maintenance'=>'Manutenção','cleaning'=>'Limpeza','viewer'=>'Visualizador'];

$pageTitle = 'Administração';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-gear me-2"></i>Administração</h1>
</div>

<ul class="nav nav-tabs mb-4">
    <?php foreach (['hospital'=>'Hospital','sectors'=>'Setores','users'=>'Usuários'] as $k=>$v): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab===$k?'active':''; ?>" href="<?php echo url('admin', ['tab'=>$k]); ?>">
                <i class="bi bi-<?php echo $k==='hospital'?'building':($k==='sectors'?'diagram-3':'people'); ?> me-1"></i><?php echo $v; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php
// ============================================================
// TAB: HOSPITAL
// ============================================================
if ($tab === 'hospital'):
?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-1"></i> Dados do Hospital</div>
    <div class="card-body">
        <form method="POST" action="<?php echo url('admin', ['tab'=>'hospital']); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_hospital">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label required">Nome</label><input type="text" class="form-control" name="name" value="<?php echo e($hospital['name']); ?>" required></div>
                <div class="col-md-6"><label class="form-label">CNPJ</label><input type="text" class="form-control" name="cnpj" value="<?php echo e($hospital['cnpj'] ?? ''); ?>" data-mask="cpf"></div>
                <div class="col-md-4"><label class="form-label">Endereço</label><input type="text" class="form-control" name="address" value="<?php echo e($hospital['address'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Cidade</label><input type="text" class="form-control" name="city" value="<?php echo e($hospital['city'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Estado</label><input type="text" class="form-control" name="state" value="<?php echo e($hospital['state'] ?? ''); ?>" maxlength="2"></div>
                <div class="col-md-4"><label class="form-label">Telefone</label><input type="text" class="form-control" name="phone" value="<?php echo e($hospital['phone'] ?? ''); ?>" data-mask="phone"></div>
                <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?php echo e($hospital['email'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Pessoa de Contato</label><input type="text" class="form-control" name="contact_person" value="<?php echo e($hospital['contact_person'] ?? ''); ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1"></i> Salvar</button>
        </form>
    </div>
</div>

<?php
// ============================================================
// TAB: SETORES
// ============================================================
elseif ($tab === 'sectors'):
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1"></i> Adicionar Setor</div>
    <div class="card-body">
        <form method="POST" action="<?php echo url('admin', ['tab'=>'sectors']); ?>" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_sector">
            <div class="col-md-5"><label class="form-label required">Nome do Setor</label><input type="text" class="form-control" name="sector_name" placeholder="Ex: UTI, Centro Cirúrgico..." required></div>
            <div class="col-md-5"><label class="form-label">Descrição</label><input type="text" class="form-control" name="sector_desc" placeholder="Descrição (opcional)"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i> Adicionar</button></div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 me-1"></i> Setores (<?php echo count($sectorsList); ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($sectorsList)): ?>
            <p class="text-center text-muted py-4 mb-0">Nenhum setor cadastrado.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Nome</th><th>Descrição</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($sectorsList as $s): ?>
                    <tr id="sector-view-<?php echo $s['id']; ?>">
                        <td><strong><?php echo e($s['name']); ?></strong></td>
                        <td class="text-muted"><?php echo e($s['description'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo $s['status']; ?>"><?php echo $s['status'] === 'active' ? 'Ativo' : 'Inativo'; ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" onclick="toggleSectorEdit(<?php echo $s['id']; ?>)" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></button>
                                <form method="POST" action="<?php echo url('admin', ['tab'=>'sectors']); ?>" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_sector">
                                    <input type="hidden" name="sector_id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir setor '<?php echo e($s['name']); ?>'?"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <tr id="sector-edit-<?php echo $s['id']; ?>" class="table-info" style="display:none">
                        <td>
                            <form id="sector-form-<?php echo $s['id']; ?>" method="POST" action="<?php echo url('admin', ['tab'=>'sectors']); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="edit_sector">
                                <input type="hidden" name="sector_id" value="<?php echo $s['id']; ?>">
                                <input type="text" class="form-control form-control-sm" name="sector_name" value="<?php echo e($s['name']); ?>" required>
                            </form>
                        </td>
                        <td><input type="text" class="form-control form-control-sm" form="sector-form-<?php echo $s['id']; ?>" name="sector_desc" value="<?php echo e($s['description'] ?? ''); ?>"></td>
                        <td><select class="form-select form-select-sm" form="sector-form-<?php echo $s['id']; ?>" name="sector_status"><option value="active" <?php echo $s['status']==='active'?'selected':''; ?>>Ativo</option><option value="inactive" <?php echo $s['status']==='inactive'?'selected':''; ?>>Inativo</option></select></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="submit" form="sector-form-<?php echo $s['id']; ?>" class="btn btn-outline-primary btn-action"><i class="bi bi-check-lg"></i></button>
                                <button type="button" onclick="toggleSectorEdit(<?php echo $s['id']; ?>)" class="btn btn-outline-secondary btn-action"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSectorEdit(id) {
    var v = document.getElementById('sector-view-' + id);
    var e = document.getElementById('sector-edit-' + id);
    if (e.style.display === 'none') { v.style.display = 'none'; e.style.display = 'table-row'; }
    else { v.style.display = 'table-row'; e.style.display = 'none'; }
}
</script>

<?php
// ============================================================
// TAB: USUÁRIOS
// ============================================================
elseif ($tab === 'users'):
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i> Adicionar Usuário</div>
    <div class="card-body">
        <form method="POST" action="<?php echo url('admin', ['tab'=>'users']); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_user">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required">Nome</label><input type="text" class="form-control" name="user_name" required></div>
                <div class="col-md-4"><label class="form-label required">Email</label><input type="email" class="form-control" name="user_email" required></div>
                <div class="col-md-4"><label class="form-label required">Senha</label><input type="password" class="form-control" name="user_password" minlength="6" required></div>
                <div class="col-md-4"><label class="form-label">Telefone</label><input type="text" class="form-control" name="user_phone" data-mask="phone"></div>
                <div class="col-md-4"><label class="form-label">Permissão</label><select class="form-select" name="user_role"><?php foreach ($roleLabels as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
            </div>
            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1"></i> Adicionar</button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i> Usuários (<?php echo count($usersList); ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Nome</th><th>Email</th><th>Permissão</th><th>Status</th><th>Último Login</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if (empty($usersList)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum usuário cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($usersList as $u): ?>
                    <tr>
                        <td><strong><?php echo e($u['name']); ?></strong></td>
                        <td class="text-muted"><?php echo e($u['email']); ?></td>
                        <td><span class="badge bg-<?php echo $u['role']==='admin'?'danger':($u['role']==='manager'?'warning':($u['role']==='technician'?'info':'secondary')); ?>"><?php echo $roleLabels[$u['role']] ?? $u['role']; ?></span></td>
                        <td><span class="badge badge-<?php echo $u['status']; ?>"><?php echo $u['status']==='active'?'Ativo':'Inativo'; ?></span></td>
                        <td class="text-muted"><?php echo formatDate($u['last_login'] ?? '', 'd/m/Y H:i'); ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button onclick="openModal('editUser<?php echo $u['id']; ?>')" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></button>
                                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir '<?php echo e($u['name']); ?>'?"><i class="bi bi-trash"></i></button>
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

<!-- MODAIS DE EDIÇÃO DE USUÁRIO -->
<?php foreach ($usersList as $u): ?>
<div class="modal fade" id="editUser<?php echo $u['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('admin', ['tab'=>'users']); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i> Editar: <?php echo e($u['name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label required">Nome</label><input type="text" class="form-control" name="user_name" value="<?php echo e($u['name']); ?>" required></div>
                        <div class="col-md-6"><label class="form-label required">Email</label><input type="email" class="form-control" name="user_email" value="<?php echo e($u['email']); ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Nova Senha</label><input type="password" class="form-control" name="user_password" minlength="6" placeholder="Vazio = manter"></div>
                        <div class="col-md-6"><label class="form-label">Telefone</label><input type="text" class="form-control" name="user_phone" value="<?php echo e($u['phone'] ?? ''); ?>" data-mask="phone"></div>
                        <div class="col-md-6"><label class="form-label">Permissão</label><select class="form-select" name="user_role"><?php foreach ($roleLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $u['role']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="user_status"><option value="active" <?php echo $u['status']==='active'?'selected':''; ?>>Ativo</option><option value="inactive" <?php echo $u['status']==='inactive'?'selected':''; ?>>Inativo</option></select></div>
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
