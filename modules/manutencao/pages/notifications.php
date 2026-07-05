<?php
/**
 * MÓDULO DE NOTIFICAÇÕES — Design System "RH Hospital"
 */
requireModule("notifications");

$hid = hospitalId();

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $st = db()->prepare("SELECT title, message, created_at FROM notifications WHERE hospital_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
        $st->execute([$hid]);
        echo json_encode(['items' => $st->fetchAll()]);
    } catch (Throwable $ex) {
        echo json_encode(['items' => []]);
    }
    exit;
}

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND hospital_id = ?")->execute([$id, $hid]);
        redirect(url('notifications'));
    }
    if ($act === 'mark_all_read') {
        db()->prepare("UPDATE notifications SET is_read = 1 WHERE hospital_id = ? AND is_read = 0")->execute([$hid]);
        flash('success', 'Todas as notificações marcadas como lidas.');
        redirect(url('notifications'));
    }
    if ($act === 'delete_read') {
        db()->prepare("DELETE FROM notifications WHERE hospital_id = ? AND is_read = 1")->execute([$hid]);
        flash('success', 'Notificações lidas removidas.');
        redirect(url('notifications'));
    }
}

// ============================================================
// GERAR NOTIFICAÇÕES AUTOMÁTICAS
// ============================================================
try {
    $stmt = db()->prepare("
        SELECT mp.id, mp.title, e.name AS equip_name
        FROM maintenance_plans mp
        LEFT JOIN equipment e ON e.id = mp.equipment_id
        WHERE mp.hospital_id = ? AND mp.status = 'active' AND mp.next_date < CURDATE()
    ");
    $stmt->execute([$hid]);
    foreach ($stmt->fetchAll() as $m) {
        $exists = db()->prepare("SELECT id FROM notifications WHERE hospital_id = ? AND type = 'warning' AND reference_id = ? AND DATE(created_at) = CURDATE()");
        $exists->execute([$hid, $m['id']]);
        if (!$exists->fetch()) {
            db()->prepare("INSERT INTO notifications (hospital_id, type, title, message, reference_id) VALUES (?, 'warning', ?, ?, ?)")
                ->execute([$hid, 'Manutenção Atrasada', "Manutenção \"{$m['title']}\" do equipamento \"{$m['equip_name']}\" está atrasada.", $m['id']]);
        }
    }
    $stmt = db()->prepare("SELECT id, name, quantity, min_quantity FROM parts WHERE hospital_id = ? AND status = 'active' AND quantity <= min_quantity");
    $stmt->execute([$hid]);
    foreach ($stmt->fetchAll() as $p) {
        $exists = db()->prepare("SELECT id FROM notifications WHERE hospital_id = ? AND type = 'warning' AND reference_id = ? AND title = 'Estoque Baixo' AND DATE(created_at) = CURDATE()");
        $exists->execute([$hid, $p['id']]);
        if (!$exists->fetch()) {
            db()->prepare("INSERT INTO notifications (hospital_id, type, title, message, reference_id) VALUES (?, 'warning', ?, ?, ?)")
                ->execute([$hid, 'Estoque Baixo', "Peça \"{$p['name']}\" com estoque baixo: {$p['quantity']}/{$p['min_quantity']}.", $p['id']]);
        }
    }
} catch (Exception $ex) {}

// ============================================================
// DADOS
// ============================================================
$notifications = db()->prepare("
    SELECT * FROM notifications WHERE hospital_id = ?
    ORDER BY is_read ASC, created_at DESC LIMIT 100
");
$notifications->execute([$hid]);
$notifications = $notifications->fetchAll();

$unread = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $unread++; }

$typeIcons = [
    'warning' => 'exclamation-triangle-fill text-warning',
    'error'   => 'x-octagon-fill text-danger',
    'success' => 'check-circle-fill text-success',
    'info'    => 'info-circle-fill text-info',
];

$pageTitle = 'Notificações';
ob_start();
?>

<div class="page-header">
    <h1>
        <i class="bi bi-bell me-2"></i>Notificações
        <?php if ($unread > 0): ?>
            <span class="badge bg-danger ms-2"><?php echo $unread; ?> não lida(s)</span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if ($unread > 0): ?>
        <form method="POST" class="d-inline">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-check-all me-1"></i> Marcar todas como lidas
            </button>
        </form>
        <?php endif; ?>
        <form method="POST" class="d-inline" data-confirm="Remover todas as notificações já lidas?">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_read">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash me-1"></i> Limpar lidas
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
            <p class="text-center text-muted py-5 mb-0">
                <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
                Nenhuma notificação.
            </p>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($notifications as $n):
                    $icon = $typeIcons[$n['type']] ?? 'bell text-secondary';
                ?>
                <div class="list-group-item <?php echo !$n['is_read'] ? 'list-group-item-primary' : ''; ?> d-flex align-items-start gap-3">
                    <i class="bi bi-<?php echo e($icon); ?> fs-5 mt-1"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?php echo e($n['title']); ?></div>
                        <div class="text-muted small"><?php echo e($n['message'] ?? ''); ?></div>
                        <small class="text-slate-500"><?php echo formatDate($n['created_at'], 'd/m/Y H:i'); ?></small>
                    </div>
                    <?php if (!$n['is_read']): ?>
                    <form method="POST" class="ms-2">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                        <button type="submit" class="btn btn-outline-primary btn-action" title="Marcar como lida">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
