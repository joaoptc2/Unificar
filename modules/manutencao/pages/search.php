<?php
/**
 * BUSCA GLOBAL — Design System "RH Hospital"
 */
requireModule('search');

$hid = hospitalId();
$q   = trim($_GET['q'] ?? '');

$results = ['equipment' => [], 'service_orders' => [], 'parts' => [], 'technicians' => []];

if (strlen($q) >= 2) {
    $like = "%{$q}%";

    try {
        $st = db()->prepare("SELECT id, name, code, status, criticality FROM equipment WHERE hospital_id = ? AND (name LIKE ? OR code LIKE ? OR serial_number LIKE ?) LIMIT 10");
        $st->execute([$hid, $like, $like, $like]);
        $results['equipment'] = $st->fetchAll();
    } catch (Throwable $ex) {}

    try {
        $st = db()->prepare("SELECT id, os_number, title, status, type FROM service_orders WHERE hospital_id = ? AND (os_number LIKE ? OR title LIKE ?) LIMIT 10");
        $st->execute([$hid, $like, $like]);
        $results['service_orders'] = $st->fetchAll();
    } catch (Throwable $ex) {}

    try {
        $st = db()->prepare("SELECT id, name, code, quantity, min_quantity FROM parts WHERE hospital_id = ? AND (name LIKE ? OR code LIKE ?) LIMIT 10");
        $st->execute([$hid, $like, $like]);
        $results['parts'] = $st->fetchAll();
    } catch (Throwable $ex) {}

    try {
        $st = db()->prepare("SELECT id, name, specialty, status FROM technicians WHERE hospital_id = ? AND (name LIKE ? OR specialty LIKE ?) LIMIT 10");
        $st->execute([$hid, $like, $like]);
        $results['technicians'] = $st->fetchAll();
    } catch (Throwable $ex) {}
}

$totalResults = array_sum(array_map('count', $results));
$statusLabels = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Ag. Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];
$critLabels   = ['low'=>'Baixa','medium'=>'Média','high'=>'Alta','critical'=>'Crítica'];

$pageTitle = 'Busca';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-search me-2"></i>Busca</h1>
</div>

<div class="filter-panel mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="search">
        <div class="col">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Buscar equipamentos, OS, peças, técnicos..." autofocus>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i> Buscar</button>
        </div>
    </form>
</div>

<?php if ($q !== '' && strlen($q) < 2): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i> Digite ao menos 2 caracteres.</div>
<?php elseif ($q !== '' && $totalResults === 0): ?>
    <div class="text-center py-5">
        <i class="bi bi-search text-muted" style="font-size:3rem"></i>
        <h5 class="mt-3 text-muted">Nenhum resultado para "<?php echo e($q); ?>"</h5>
        <p class="text-muted">Tente termos diferentes ou mais curtos.</p>
    </div>
<?php elseif ($q !== ''): ?>
    <p class="text-muted small mb-3"><?php echo $totalResults; ?> resultado(s) para "<?php echo e($q); ?>"</p>

    <?php if (!empty($results['equipment'])): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-hdd-rack me-1"></i> Equipamentos (<?php echo count($results['equipment']); ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>Nome</th><th>Código</th><th>Criticidade</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($results['equipment'] as $r): ?>
                <tr>
                    <td><strong><?php echo e($r['name']); ?></strong></td>
                    <td class="text-muted"><?php echo e($r['code'] ?? '—'); ?></td>
                    <td><span class="badge badge-<?php echo e($r['criticality']); ?>"><?php echo e($critLabels[$r['criticality']] ?? $r['criticality']); ?></span></td>
                    <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e($r['status']); ?></span></td>
                    <td class="text-end"><a href="<?php echo url('equipment', ['action'=>'detail','id'=>$r['id']]); ?>" class="btn btn-outline-primary btn-action"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['service_orders'])): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1"></i> Ordens de Serviço (<?php echo count($results['service_orders']); ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>OS</th><th>Título</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($results['service_orders'] as $r): ?>
                <tr>
                    <td><strong><?php echo e($r['os_number']); ?></strong></td>
                    <td><?php echo e($r['title']); ?></td>
                    <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e($statusLabels[$r['status']] ?? $r['status']); ?></span></td>
                    <td class="text-end"><a href="<?php echo url('service-orders', ['action'=>'edit','id'=>$r['id']]); ?>" class="btn btn-outline-primary btn-action"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['parts'])): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-box-seam me-1"></i> Peças (<?php echo count($results['parts']); ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>Nome</th><th>Código</th><th>Estoque</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($results['parts'] as $r): ?>
                <tr>
                    <td><strong><?php echo e($r['name']); ?></strong></td>
                    <td class="text-muted"><?php echo e($r['code'] ?? '—'); ?></td>
                    <td><span class="badge <?php echo (int)$r['quantity'] <= (int)$r['min_quantity'] ? 'bg-danger' : 'bg-success'; ?>"><?php echo $r['quantity']; ?></span></td>
                    <td class="text-end"><a href="<?php echo url('stock', ['action'=>'edit','id'=>$r['id']]); ?>" class="btn btn-outline-primary btn-action"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['technicians'])): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i> Técnicos (<?php echo count($results['technicians']); ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>Nome</th><th>Especialidade</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($results['technicians'] as $r): ?>
                <tr>
                    <td><strong><?php echo e($r['name']); ?></strong></td>
                    <td><?php echo e($r['specialty'] ?? '—'); ?></td>
                    <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e($r['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
