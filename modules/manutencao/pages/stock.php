<?php
/**
 * MÓDULO DE ESTOQUE
 * CRUD com edição, lançamentos (entrada/saída), exclusão e filtro
 */
requireModule("stock");

$hid    = hospitalId();
$action = $_GET['action'] ?? 'list';

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    requireWrite();
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $code     = trim($_POST['code'] ?? '') ?: null;
        $unit     = trim($_POST['unit'] ?? 'un');
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $minQty   = max(0, (int)($_POST['min_quantity'] ?? 5));
        $unitCost = max(0, (float)($_POST['unit_cost'] ?? 0));
        $supplier = trim($_POST['supplier'] ?? '') ?: null;
        $location = trim($_POST['location'] ?? '') ?: null;
        $desc     = trim($_POST['description'] ?? '') ?: null;

        if ($name === '') {
            flash('error', 'Nome da peça é obrigatório.');
        } else {
            try {
                $stmt = db()->prepare("
                    INSERT INTO man_parts (hospital_id, code, name, description, unit, quantity, min_quantity, unit_cost, supplier, location)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$hid, $code, $name, $desc, $unit, $quantity, $minQty, $unitCost, $supplier, $location]);
                $partId = (int)db()->lastInsertId();

                // Registrar entrada inicial
                if ($quantity > 0) {
                    db()->prepare("INSERT INTO man_stock_movements (hospital_id, part_id, type, quantity, reason, created_by) VALUES (?, ?, 'entry', ?, 'Estoque inicial', ?)")
                        ->execute([$hid, $partId, $quantity, $_SESSION['user_id']]);
                }

                auditLog('create', 'parts', $partId);
                flash('success', 'Peça adicionada ao estoque!');
            } catch (Exception $ex) {
                flash('error', 'Erro: ' . $ex->getMessage());
            }
        }
        redirect(url('stock'));
    }

    if ($act === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $code     = trim($_POST['code'] ?? '') ?: null;
        $unit     = trim($_POST['unit'] ?? 'un');
        $minQty   = max(0, (int)($_POST['min_quantity'] ?? 5));
        $unitCost = max(0, (float)($_POST['unit_cost'] ?? 0));
        $supplier = trim($_POST['supplier'] ?? '') ?: null;
        $location = trim($_POST['location'] ?? '') ?: null;
        $desc     = trim($_POST['description'] ?? '') ?: null;
        $status   = $_POST['status'] ?? 'active';

        if ($name === '') {
            flash('error', 'Nome é obrigatório.');
        } else {
            try {
                db()->prepare("
                    UPDATE man_parts SET code=?, name=?, description=?, unit=?, min_quantity=?, unit_cost=?, supplier=?, location=?, status=?
                    WHERE id=? AND hospital_id=?
                ")->execute([$code, $name, $desc, $unit, $minQty, $unitCost, $supplier, $location, $status, $id, $hid]);
                auditLog('update', 'parts', $id);
                flash('success', 'Peça atualizada!');
            } catch (Exception $ex) {
                flash('error', 'Erro: ' . $ex->getMessage());
            }
        }
        redirect(url('stock'));
    }

    if ($act === 'movement') {
        $partId   = (int)($_POST['part_id'] ?? 0);
        $type     = $_POST['movement_type'] ?? 'entry';
        $qty      = max(1, (int)($_POST['movement_qty'] ?? 1));
        $reason   = trim($_POST['reason'] ?? '') ?: null;

        if (!in_array($type, ['entry', 'exit', 'adjustment'], true)) {
            flash('error', 'Tipo de movimentação inválido.');
        } else {
            try {
                db()->beginTransaction();

                // Registrar movimentação
                db()->prepare("INSERT INTO man_stock_movements (hospital_id, part_id, type, quantity, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$hid, $partId, $type, $qty, $reason, $_SESSION['user_id']]);

                // Atualizar quantidade
                if ($type === 'entry') {
                    db()->prepare("UPDATE man_parts SET quantity = quantity + ? WHERE id = ? AND hospital_id = ?")->execute([$qty, $partId, $hid]);
                } elseif ($type === 'exit') {
                    db()->prepare("UPDATE man_parts SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND hospital_id = ?")->execute([$qty, $partId, $hid]);
                } else { // adjustment
                    db()->prepare("UPDATE man_parts SET quantity = ? WHERE id = ? AND hospital_id = ?")->execute([$qty, $partId, $hid]);
                }

                db()->commit();
                auditLog('stock_movement', 'parts', $partId, "{$type}: {$qty}");
                flash('success', 'Movimentação registrada!');
            } catch (Exception $ex) {
                if (db()->inTransaction()) db()->rollBack();
                flash('error', 'Erro: ' . $ex->getMessage());
            }
        }
        redirect(url('stock'));
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM man_stock_movements WHERE part_id = ? AND hospital_id = ?")->execute([$id, $hid]);
            db()->prepare("DELETE FROM man_parts WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
            auditLog('delete', 'parts', $id);
            flash('success', 'Peça removida do estoque.');
        } catch (Exception $ex) {
            flash('error', 'Erro: ' . $ex->getMessage());
        }
        redirect(url('stock'));
    }
}

// ============================================================
// OBTER DADOS
// ============================================================
$pageTitle = 'Estoque';
ob_start();

// ============================================================
// VIEW: EDITAR
// ============================================================
if ($action === 'edit'):
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM man_parts WHERE id = ? AND hospital_id = ?");
    $stmt->execute([$id, $hid]);
    $part = $stmt->fetch();
    if (!$part) { flash('error', 'Peça não encontrada.'); redirect(url('stock')); }

    // Histórico de movimentações
    $stmt = db()->prepare("
        SELECT sm.*, u.name AS user_name
        FROM man_stock_movements sm
        LEFT JOIN users u ON u.id = sm.created_by
        WHERE sm.part_id = ? AND sm.hospital_id = ?
        ORDER BY sm.created_at DESC LIMIT 20
    ");
    $stmt->execute([$id, $hid]);
    $movements = $stmt->fetchAll();
?>
<div class="page-header">
    <h1><i class="bi bi-pencil me-2"></i>Editar Peça: <?php echo e($part['name']); ?></h1>
    <a href="<?php echo url('stock'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?php echo url('stock'); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?php echo $part['id']; ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required">Nome</label>
                    <input type="text" class="form-control" name="name" value="<?php echo e($part['name']); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Código</label>
                    <input type="text" class="form-control" name="code" value="<?php echo e($part['code'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Unidade</label>
                    <input type="text" class="form-control" name="unit" value="<?php echo e($part['unit']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Qtd. Mínima</label>
                    <input type="number" class="form-control" name="min_quantity" value="<?php echo $part['min_quantity']; ?>" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Custo Unitário (R$)</label>
                    <input type="number" class="form-control" name="unit_cost" value="<?php echo $part['unit_cost']; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?php echo $part['status']==='active'?'selected':''; ?>>Ativo</option>
                        <option value="inactive" <?php echo $part['status']==='inactive'?'selected':''; ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fornecedor</label>
                    <input type="text" class="form-control" name="supplier" value="<?php echo e($part['supplier'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Localização</label>
                    <input type="text" class="form-control" name="location" value="<?php echo e($part['location'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Descrição</label>
                    <textarea class="form-control" name="description" rows="3"><?php echo e($part['description'] ?? ''); ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1"></i> Salvar Alterações</button>
        </form>
    </div>
</div>

<!-- LANÇAMENTO -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right me-1"></i> Lançar Movimentação (Qtd. atual: <strong><?php echo $part['quantity']; ?></strong>)</div>
    <div class="card-body">
        <form method="POST" action="<?php echo url('stock'); ?>" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="movement">
            <input type="hidden" name="part_id" value="<?php echo $part['id']; ?>">
            <div class="col-md-3">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="movement_type">
                    <option value="entry">Entrada</option>
                    <option value="exit">Saída</option>
                    <option value="adjustment">Ajuste (definir qtd.)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Quantidade</label>
                <input type="number" class="form-control" name="movement_qty" value="1" min="1" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Motivo (opcional)</label>
                <input type="text" class="form-control" name="reason" placeholder="Motivo...">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-lg me-1"></i> Lançar</button>
            </div>
        </form>
    </div>
</div>

<!-- HISTÓRICO -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i> Histórico de Movimentações</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr><th>Data</th><th>Tipo</th><th>Quantidade</th><th>Motivo</th><th>Usuário</th></tr>
                </thead>
                <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma movimentação.</td></tr>
                <?php else: ?>
                    <?php
                    $moveLabels = ['entry'=>'Entrada','exit'=>'Saída','adjustment'=>'Ajuste'];
                    foreach ($movements as $m): ?>
                    <tr>
                        <td><?php echo formatDate($m['created_at'], 'd/m/Y H:i'); ?></td>
                        <td><span class="badge badge-<?php echo $m['type']==='entry'?'active':($m['type']==='exit'?'cancelled':'medium'); ?>"><?php echo $moveLabels[$m['type']] ?? $m['type']; ?></span></td>
                        <td><?php echo $m['quantity']; ?></td>
                        <td><?php echo e($m['reason'] ?? '-'); ?></td>
                        <td><?php echo e($m['user_name'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// ============================================================
// VIEW: LISTA
// ============================================================
else:
    $filter = trim($_GET['filter'] ?? '');
    $filterStock = $_GET['filter_stock'] ?? '';

    $where  = "WHERE p.hospital_id = ?";
    $params = [$hid];

    if ($filter !== '') {
        $where .= " AND (p.name LIKE ? OR p.code LIKE ?)";
        $params[] = "%{$filter}%";
        $params[] = "%{$filter}%";
    }
    if ($filterStock === 'low') {
        $where .= " AND p.quantity <= p.min_quantity";
    } elseif ($filterStock === 'ok') {
        $where .= " AND p.quantity > p.min_quantity";
    }

    $baseQuery = "SELECT p.* FROM man_parts p {$where} ORDER BY p.name ASC";
    $pg = paginate($baseQuery, $params, 20);
?>

<div class="page-header">
    <h1><i class="bi bi-box-seam me-2"></i>Estoque de Peças</h1>
    <div class="d-flex gap-2">
        <?php if (canWrite()): ?>
            <button onclick="openModal('modalAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova Peça</button>
        <?php endif; ?>
    </div>
</div>

<!-- FILTRO -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="manutencao"><input type="hidden" name="page" value="stock">
        <div class="col-md-5">
            <label class="form-label">Buscar</label>
            <input type="text" class="form-control" name="filter" value="<?php echo e($filter); ?>" placeholder="Nome ou código...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Nível</label>
            <select class="form-select" name="filter_stock">
                <option value="">Todos</option>
                <option value="low" <?php echo $filterStock==='low'?'selected':''; ?>>Estoque Baixo</option>
                <option value="ok" <?php echo $filterStock==='ok'?'selected':''; ?>>Estoque OK</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
            <a href="<?php echo url('stock'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
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
                        <th>Código</th>
                        <th>Nome</th>
                        <th>Qtd.</th>
                        <th>Mín.</th>
                        <th>Unidade</th>
                        <th>Custo Unit.</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pg['rows'])): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma peça cadastrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($pg['rows'] as $p): ?>
                    <tr>
                        <td class="text-muted"><?php echo e($p['code'] ?? '—'); ?></td>
                        <td><strong><?php echo e($p['name']); ?></strong></td>
                        <td>
                            <?php if ($p['quantity'] <= $p['min_quantity']): ?>
                                <span class="text-danger fw-bold"><?php echo $p['quantity']; ?></span>
                            <?php else: ?>
                                <?php echo $p['quantity']; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $p['min_quantity']; ?></td>
                        <td><?php echo e($p['unit']); ?></td>
                        <td>R$ <?php echo number_format($p['unit_cost'], 2, ',', '.'); ?></td>
                        <td><span class="badge badge-<?php echo $p['status']; ?>"><?php echo $p['status']==='active'?'Ativo':'Inativo'; ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="<?php echo url('stock', ['action'=>'edit','id'=>$p['id']]); ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php if (canWrite()): ?>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Tem certeza que deseja excluir esta peça?"><i class="bi bi-trash"></i></button>
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
        <?php echo paginationLinks($pg['page'], $pg['lastPage'], url('stock', array_filter(['filter'=>$filter,'filter_stock'=>$filterStock]))); ?>
    </div>
</div>

<!-- MODAL NOVA PEÇA -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-labelledby="modalAddLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="modalAddLabel"><i class="bi bi-box-seam me-1"></i> Nova Peça</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?php echo url('stock'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Nome</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Código</label>
                            <input type="text" class="form-control" name="code">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantidade Inicial</label>
                            <input type="number" class="form-control" name="quantity" value="0" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Qtd. Mínima</label>
                            <input type="number" class="form-control" name="min_quantity" value="5" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unidade</label>
                            <input type="text" class="form-control" name="unit" value="un">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Custo Unitário (R$)</label>
                            <input type="number" class="form-control" name="unit_cost" value="0" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fornecedor</label>
                            <input type="text" class="form-control" name="supplier">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Localização</label>
                            <input type="text" class="form-control" name="location">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Adicionar Peça</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
