<?php
/**
 * MÓDULO DE NOTIFICAÇÕES — Design System "RH Hospital"
 *
 * Portado para a tabela GLOBAL notifications do núcleo:
 *   - registros por usuário (user_id) com module = 'manutencao';
 *   - is_read (legado) → read_at (NULL = não lida);
 *   - links das notificações carregam m=manutencao.
 */
requireModule("notifications");

$hid = hospitalId();
$uid = (int) ($_SESSION['user_id'] ?? 0);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $st = db()->prepare("
            SELECT title, message, created_at
            FROM notifications
            WHERE user_id = ? AND module = 'manutencao' AND read_at IS NULL
            ORDER BY created_at DESC LIMIT 5
        ");
        $st->execute([$uid]);
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
        db()->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND module = 'manutencao'")
            ->execute([$id, $uid]);
        redirect(url('notifications'));
    }
    if ($act === 'mark_all_read') {
        db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND module = 'manutencao' AND read_at IS NULL")
            ->execute([$uid]);
        flash('success', 'Todas as notificações marcadas como lidas.');
        redirect(url('notifications'));
    }
    if ($act === 'delete_read') {
        db()->prepare("DELETE FROM notifications WHERE user_id = ? AND module = 'manutencao' AND read_at IS NOT NULL")
            ->execute([$uid]);
        flash('success', 'Notificações lidas removidas.');
        redirect(url('notifications'));
    }
}

// ============================================================
// GERAR NOTIFICAÇÕES AUTOMÁTICAS (para o usuário atual)
// ============================================================
try {
    $stmt = db()->prepare("
        SELECT mp.id, mp.title, e.name AS equip_name
        FROM man_maintenance_plans mp
        LEFT JOIN man_equipment e ON e.id = mp.equipment_id
        WHERE mp.hospital_id = ? AND mp.status = 'active' AND mp.next_date < CURDATE()
    ");
    $stmt->execute([$hid]);
    foreach ($stmt->fetchAll() as $m) {
        $title = 'Manutenção Atrasada';
        $msg   = "Manutenção \"{$m['title']}\" do equipamento \"{$m['equip_name']}\" está atrasada.";
        $link  = url('maintenance');
        $exists = db()->prepare("
            SELECT id FROM notifications
            WHERE user_id = ? AND module = 'manutencao' AND type = 'warning'
              AND title = ? AND message = ? AND DATE(created_at) = CURDATE()
        ");
        $exists->execute([$uid, $title, $msg]);
        if (!$exists->fetch()) {
            db()->prepare("INSERT INTO notifications (user_id, module, type, title, message, link) VALUES (?, 'manutencao', 'warning', ?, ?, ?)")
                ->execute([$uid, $title, $msg, $link]);
        }
    }
    $stmt = db()->prepare("SELECT id, name, quantity, min_quantity FROM man_parts WHERE hospital_id = ? AND status = 'active' AND quantity <= min_quantity");
    $stmt->execute([$hid]);
    foreach ($stmt->fetchAll() as $p) {
        $title = 'Estoque Baixo';
        $msg   = "Peça \"{$p['name']}\" com estoque baixo: {$p['quantity']}/{$p['min_quantity']}.";
        $link  = url('stock', ['action' => 'edit', 'id' => (int)$p['id']]);
        $exists = db()->prepare("
            SELECT id FROM notifications
            WHERE user_id = ? AND module = 'manutencao' AND type = 'warning'
              AND title = ? AND message = ? AND DATE(created_at) = CURDATE()
        ");
        $exists->execute([$uid, $title, $msg]);
        if (!$exists->fetch()) {
            db()->prepare("INSERT INTO notifications (user_id, module, type, title, message, link) VALUES (?, 'manutencao', 'warning', ?, ?, ?)")
                ->execute([$uid, $title, $msg, $link]);
        }
    }
} catch (Exception $ex) {}

// ============================================================
// DADOS
// ============================================================
$notifications = db()->prepare("
    SELECT * FROM notifications
    WHERE user_id = ? AND module = 'manutencao'
    ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT 100
");
$notifications->execute([$uid]);
$notifications = $notifications->fetchAll();

$unread = 0;
foreach ($notifications as $n) { if (empty($n['read_at'])) $unread++; }

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
                    $icon   = $typeIcons[$n['type']] ?? 'bell text-secondary';
                    $isRead = !empty($n['read_at']);
                ?>
                <div class="list-group-item <?php echo !$isRead ? 'list-group-item-primary' : ''; ?> d-flex align-items-start gap-3">
                    <i class="bi bi-<?php echo e($icon); ?> fs-5 mt-1"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">
                            <?php if (!empty($n['link'])): ?>
                                <a href="<?php echo e($n['link']); ?>" class="text-decoration-none"><?php echo e($n['title']); ?></a>
                            <?php else: ?>
                                <?php echo e($n['title']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small"><?php echo e($n['message'] ?? ''); ?></div>
                        <small class="text-slate-500"><?php echo formatDate($n['created_at'], 'd/m/Y H:i'); ?></small>
                    </div>
                    <?php if (!$isRead): ?>
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
