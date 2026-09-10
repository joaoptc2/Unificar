<?php
/**
 * PLANEJAMENTO — painel: contagens, minhas ações/cartões pendentes,
 * próximos vencimentos e atalhos.
 */

declare(strict_types=1);

use Core\DB;

core_require('dashboard.view');

$uid   = (int) core_user_id();
$today = date('Y-m-d');
$canPlans    = core_can('plans.view');
$canBoards   = core_can('boards.view');
$canDiagrams = core_can('diagrams.view');

// ---- contagens ---------------------------------------------------------------
$activePlans = $canPlans ? (int) (DB::queryOne("SELECT COUNT(*) AS n FROM plan_plans WHERE deleted_at IS NULL AND status = 'active'")['n'] ?? 0) : 0;
$openPlans   = $canPlans ? (int) (DB::queryOne("SELECT COUNT(*) AS n FROM plan_plans WHERE deleted_at IS NULL AND status IN ('draft','active')")['n'] ?? 0) : 0;
$overdue     = $canPlans ? (int) (DB::queryOne(
    "SELECT COUNT(*) AS n FROM plan_plan_items i JOIN plan_plans p ON p.id = i.plan_id
     WHERE p.deleted_at IS NULL AND p.status IN ('draft','active') AND i.kind IN ('action','task')
       AND i.status IN ('pending','in_progress') AND i.due_date IS NOT NULL AND i.due_date < ?",
    [$today]
)['n'] ?? 0) : 0;
// uma única consulta de quadros visíveis (usada na contagem e nos recentes)
$visibleBoards = $canBoards ? plan_boards_visible(false, 'all') : [];
$boardsCount   = count($visibleBoards);
$diagramsCount = 0;
if ($canDiagrams) {
    $params = [];
    $where  = 'deleted_at IS NULL';
    if (!core_can('diagrams.delete')) {
        $where   .= ' AND (is_public = 1 OR created_by = ?)';
        $params[] = $uid;
    }
    $diagramsCount = (int) (DB::queryOne('SELECT COUNT(*) AS n FROM plan_diagrams WHERE ' . $where, $params)['n'] ?? 0);
}

// ---- minhas ações pendentes (responsável = usuário) ---------------------------
$myItems = $canPlans ? DB::query(
    "SELECT i.id, i.title, i.kind, i.status, i.priority, i.progress, i.due_date, p.id AS plan_id, p.title AS plan_title
     FROM plan_plan_items i JOIN plan_plans p ON p.id = i.plan_id
     WHERE p.deleted_at IS NULL AND p.status <> 'archived' AND i.responsible_id = ? AND i.status IN ('pending','in_progress')
     ORDER BY (i.due_date IS NULL), i.due_date, FIELD(i.priority,'high','medium','low'), i.id LIMIT 15",
    [$uid]
) : [];

// ---- meus cartões pendentes (responsável = usuário) ---------------------------
$myCards = $canBoards ? DB::query(
    'SELECT c.id, c.title, c.priority, c.due_date, c.points, b.id AS board_id, b.name AS board_name, col.name AS column_name
     FROM plan_board_cards c JOIN plan_boards b ON b.id = c.board_id JOIN plan_board_columns col ON col.id = c.column_id
     WHERE b.archived_at IS NULL AND c.assignee_id = ? AND c.completed_at IS NULL AND col.is_done = 0
     ORDER BY (c.due_date IS NULL), c.due_date, FIELD(c.priority,\'urgent\',\'high\',\'medium\',\'low\'), c.id LIMIT 15',
    [$uid]
) : [];

// ---- próximos vencimentos (30 dias) + atrasados ---------------------------------
$limitDate = date('Y-m-d', strtotime('+30 days'));
$upcoming  = [];
if ($canPlans) {
    foreach (DB::query(
        "SELECT i.id, i.title, i.due_date, i.status, i.responsible_id, i.responsible_name, u.name AS responsible_user, p.id AS plan_id, p.title AS plan_title
         FROM plan_plan_items i JOIN plan_plans p ON p.id = i.plan_id LEFT JOIN users u ON u.id = i.responsible_id
         WHERE p.deleted_at IS NULL AND p.status IN ('draft','active') AND i.status IN ('pending','in_progress')
           AND i.due_date IS NOT NULL AND i.due_date <= ? ORDER BY i.due_date, i.id LIMIT 20",
        [$limitDate]
    ) as $r) {
        $upcoming[] = [
            'type' => 'item', 'title' => $r['title'], 'due' => $r['due_date'], 'ctx' => $r['plan_title'],
            'who'  => $r['responsible_id'] ? (string) $r['responsible_user'] : (string) ($r['responsible_name'] ?? ''),
            'url'  => plan_url('plans', ['action' => 'view', 'id' => $r['plan_id']]),
        ];
    }
}
if ($canBoards) {
    $bp = [$limitDate];
    $bw = '';
    if (!core_can('boards.delete')) {
        $bw   = ' AND (b.is_private = 0 OR b.created_by = ? OR EXISTS (SELECT 1 FROM plan_board_members m WHERE m.board_id = b.id AND m.user_id = ?))';
        $bp[] = $uid;
        $bp[] = $uid;
    }
    foreach (DB::query(
        'SELECT c.id, c.title, c.due_date, u.name AS assignee_name, b.id AS board_id, b.name AS board_name
         FROM plan_board_cards c JOIN plan_boards b ON b.id = c.board_id LEFT JOIN users u ON u.id = c.assignee_id
         WHERE b.archived_at IS NULL AND c.completed_at IS NULL AND c.due_date IS NOT NULL AND c.due_date <= ?' . $bw . ' ORDER BY c.due_date, c.id LIMIT 20',
        $bp
    ) as $r) {
        $upcoming[] = [
            'type' => 'card', 'title' => $r['title'], 'due' => $r['due_date'], 'ctx' => $r['board_name'], 'who' => (string) ($r['assignee_name'] ?? ''),
            'url'  => plan_url('boards', ['action' => 'view', 'id' => $r['board_id'], 'card' => $r['id']]),
        ];
    }
}
usort($upcoming, fn ($a, $b) => strcmp($a['due'], $b['due']));
$upcoming = array_slice($upcoming, 0, 15);

// ---- planos recentes -----------------------------------------------------------
$recentPlans = $canPlans ? DB::query(
    "SELECT p.id, p.title, p.status, p.progress, p.end_date, o.name AS owner_name,
            (SELECT COUNT(*) FROM plan_plan_items i WHERE i.plan_id = p.id AND i.kind = 'action') AS actions_total,
            (SELECT COUNT(*) FROM plan_plan_items i WHERE i.plan_id = p.id AND i.kind = 'action' AND i.status = 'done') AS actions_done
     FROM plan_plans p LEFT JOIN users o ON o.id = p.owner_id
     WHERE p.deleted_at IS NULL AND p.status IN ('draft','active') ORDER BY p.updated_at DESC LIMIT 6"
) : [];
$recentBoards = array_slice($visibleBoards, 0, 6);

$kinds = plan_item_kinds();
$stat = function (string $icon, string $bg, int|string $value, string $label, ?string $url): string {
    $inner = '<div class="plan-stat"><span class="plan-stat-icon ' . $bg . '"><i class="bi ' . $icon . '"></i></span><div><div class="plan-stat-value">' . $value . '</div><div class="plan-stat-label">' . core_e($label) . '</div></div></div>';
    return '<div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body py-3">' . ($url ? '<a class="text-decoration-none text-reset" href="' . $url . '">' . $inner . '</a>' : $inner) . '</div></div></div>';
};
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-speedometer2 me-2"></i>Painel de planejamento</h1>
    <div class="d-flex flex-wrap gap-2">
        <?php if (core_can('plans.create')): ?><a class="btn btn-primary btn-sm" href="<?= plan_url('plans', ['action' => 'create']) ?>"><i class="bi bi-clipboard2-plus me-1"></i>Novo plano</a><?php endif; ?>
        <?php if (core_can('boards.create')): ?><a class="btn btn-outline-primary btn-sm" href="<?= plan_url('boards', ['action' => 'create']) ?>"><i class="bi bi-kanban me-1"></i>Novo quadro</a><?php endif; ?>
        <?php if (core_can('diagrams.create')): ?><a class="btn btn-outline-primary btn-sm" href="<?= plan_url('diagrams', ['action' => 'create']) ?>"><i class="bi bi-diagram-3 me-1"></i>Novo diagrama</a><?php endif; ?>
        <?php if (core_can('templates.view')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('templates') ?>"><i class="bi bi-grid-1x2 me-1"></i>Modelos</a><?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <?= $stat('bi-clipboard2-check', 'bg-primary-subtle text-primary', $activePlans, 'Planos ativos' . ($openPlans > $activePlans ? ' (+' . ($openPlans - $activePlans) . ' rascunho)' : ''), $canPlans ? plan_url('plans', ['status' => 'active']) : null) ?>
    <?= $stat('bi-exclamation-circle', $overdue ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success', $overdue, 'Ações atrasadas', $canPlans ? plan_url('plans') : null) ?>
    <?= $stat('bi-kanban', 'bg-warning-subtle text-warning', $boardsCount, 'Quadros ativos', $canBoards ? plan_url('boards') : null) ?>
    <?= $stat('bi-diagram-3', 'bg-info-subtle text-info', $diagramsCount, 'Diagramas', $canDiagrams ? plan_url('diagrams') : null) ?>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person-check me-1"></i>Minhas ações e cartões pendentes</div>
            <?php if (!$myItems && !$myCards): ?>
                <div class="card-body text-muted small">Nenhuma ação de plano ou cartão atribuído a você está pendente.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0">
                <thead><tr><th>Item</th><th>Origem</th><th>Prazo</th><th class="text-center">Status</th></tr></thead>
                <tbody>
                <?php foreach ($myItems as $it): $late = $it['due_date'] && $it['due_date'] < $today; ?>
                    <tr>
                        <td><span class="badge plan-kind plan-kind-<?= $it['kind'] ?>"><?= core_e($kinds[$it['kind']] ?? $it['kind']) ?></span>
                            <a class="text-decoration-none" href="<?= plan_url('plans', ['action' => 'view', 'id' => $it['plan_id']]) ?>"><?= core_e($it['title']) ?></a>
                            <?php if ($it['priority'] === 'high'): ?><span class="badge text-bg-warning">Alta</span><?php endif; ?></td>
                        <td class="small text-muted"><i class="bi bi-clipboard2-check"></i> <?= core_e(mb_strimwidth((string) $it['plan_title'], 0, 40, '…')) ?></td>
                        <td class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : '' ?>"><?= plan_date_br($it['due_date']) ?: '—' ?></td>
                        <td class="text-center"><?= plan_status_badge($it['status']) ?><div class="small text-muted"><?= (int) $it['progress'] ?>%</div></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($myCards as $c): $late = $c['due_date'] && $c['due_date'] < $today; ?>
                    <tr>
                        <td><span class="badge text-bg-secondary">Cartão</span>
                            <a class="text-decoration-none" href="<?= plan_url('boards', ['action' => 'view', 'id' => $c['board_id'], 'card' => $c['id']]) ?>"><?= core_e($c['title']) ?></a>
                            <?php if (in_array($c['priority'], ['high', 'urgent'], true)): ?><?= plan_priority_badge($c['priority']) ?><?php endif; ?></td>
                        <td class="small text-muted"><i class="bi bi-kanban"></i> <?= core_e(mb_strimwidth((string) $c['board_name'], 0, 40, '…')) ?></td>
                        <td class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : '' ?>"><?= plan_date_br($c['due_date']) ?: '—' ?></td>
                        <td class="text-center"><span class="badge text-bg-light border"><?= core_e($c['column_name']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-calendar-event me-1"></i>Próximos vencimentos (30 dias) e atrasos</div>
            <?php if (!$upcoming): ?>
                <div class="card-body text-muted small">Nenhuma ação ou cartão com prazo nos próximos 30 dias.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0">
                <thead><tr><th>Prazo</th><th>Item</th><th>Onde</th><th>Quem</th></tr></thead>
                <tbody>
                <?php foreach ($upcoming as $u): $late = $u['due'] < $today; ?>
                    <tr>
                        <td class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : ($u['due'] === $today ? 'text-warning fw-semibold' : '') ?>"><?= plan_date_br($u['due']) ?><?= $late ? ' <i class="bi bi-exclamation-triangle"></i>' : '' ?></td>
                        <td><i class="bi <?= $u['type'] === 'card' ? 'bi-card-text' : 'bi-check2-square' ?> text-muted"></i> <a class="text-decoration-none" href="<?= $u['url'] ?>"><?= core_e($u['title']) ?></a></td>
                        <td class="small text-muted"><?= core_e(mb_strimwidth((string) $u['ctx'], 0, 36, '…')) ?></td>
                        <td class="small text-muted"><?= core_e($u['who'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canPlans): ?>
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-clipboard2-check me-1"></i>Planos em andamento</span><a class="small" href="<?= plan_url('plans') ?>">ver todos</a></div>
            <?php if (!$recentPlans): ?>
                <div class="card-body text-muted small">Nenhum plano em aberto.<?php if (core_can('plans.create')): ?> <a href="<?= plan_url('plans', ['action' => 'create']) ?>">Criar o primeiro</a>.<?php endif; ?></div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentPlans as $p): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <a class="text-decoration-none fw-semibold text-truncate" href="<?= plan_url('plans', ['action' => 'view', 'id' => $p['id']]) ?>"><?= core_e($p['title']) ?></a>
                            <?= plan_status_badge($p['status']) ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-muted">
                            <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-success" style="width:<?= (int) $p['progress'] ?>%"></div></div>
                            <span><?= (int) $p['progress'] ?>%</span>
                            <span>· <?= (int) $p['actions_done'] ?>/<?= (int) $p['actions_total'] ?> ações</span>
                            <?php if ($p['owner_name']): ?><span>· <?= core_e($p['owner_name']) ?></span><?php endif; ?>
                            <?php if ($p['end_date']): ?><span>· até <?= plan_date_br($p['end_date']) ?></span><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canBoards): ?>
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-kanban me-1"></i>Quadros recentes</span><a class="small" href="<?= plan_url('boards') ?>">ver todos</a></div>
            <?php if (!$recentBoards): ?>
                <div class="card-body text-muted small">Nenhum quadro.<?php if (core_can('boards.create')): ?> <a href="<?= plan_url('boards', ['action' => 'create']) ?>">Criar o primeiro</a>.<?php endif; ?></div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentBoards as $b): $pct = $b['cards_total'] ? (int) round($b['cards_done'] / $b['cards_total'] * 100) : 0; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <a class="text-decoration-none fw-semibold text-truncate" href="<?= plan_url('boards', ['action' => 'view', 'id' => $b['id']]) ?>"><?= core_e($b['name']) ?></a>
                            <span class="badge text-bg-light border"><?= core_e(plan_board_kinds()[$b['kind']] ?? $b['kind']) ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-muted">
                            <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
                            <span><?= (int) $b['cards_done'] ?>/<?= (int) $b['cards_total'] ?> cartões</span>
                            <span>· <?= (int) $b['members_total'] ?> membro(s)</span>
                            <?php if ($b['is_private']): ?><span><i class="bi bi-lock"></i></span><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
plan_page(['title' => 'Painel', 'content' => (string) ob_get_clean(), 'active' => 'dashboard']);
