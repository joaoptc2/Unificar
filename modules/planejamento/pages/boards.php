<?php
/**
 * PLANEJAMENTO — quadros (Kanban / Scrum / personalizado).
 *   page=boards[&scope=all|mine|public|archived]   lista
 *   page=boards&action=create[&template=ID][&plan_id=ID]   novo quadro
 *   page=boards&action=edit&id=X     configurar (nome, tipo, privacidade, membros, etiquetas, pontos, sprint)
 *   page=boards&action=view&id=X[&card=ID]   quadro
 *   POST action=save|archive|unarchive|delete
 */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Flash;

core_require('boards.view');

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$uid    = (int) core_user_id();

// ===========================================================================
// POSTs
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $id    = (int) ($_POST['id'] ?? 0);
    $board = $id ? plan_find_board($id) : null;
    if ($id && (!$board || !plan_board_can_view($board))) {
        Flash::set('error', 'Quadro não encontrado.');
        core_redirect(plan_url('boards'));
    }

    if ($action === 'save') {
        core_require($board ? 'boards.edit' : 'boards.create');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Informe o nome do quadro.');
            core_redirect(plan_url('boards', $board ? ['action' => 'edit', 'id' => $board['id']] : ['action' => 'create']));
        }
        $kind      = in_array($_POST['kind'] ?? '', array_keys(plan_board_kinds()), true) ? $_POST['kind'] : 'kanban';
        $private   = !empty($_POST['is_private']) ? 1 : 0;
        $points    = !empty($_POST['use_points']) ? 1 : 0;
        $sprintDays = (int) ($_POST['sprint_days'] ?? 0) ?: null;
        $sprintDays = $sprintDays ? max(1, min(365, $sprintDays)) : null;
        $sprintStart = plan_date_or_null($_POST['sprint_start'] ?? '');
        $labels    = plan_labels_list($_POST['labels'] ?? '');
        $planId    = (int) ($_POST['plan_id'] ?? 0) ?: null;
        if ($planId && !plan_find_plan($planId)) {
            $planId = null;
        }
        $members = array_values(array_unique(array_map('intval', (array) ($_POST['members'] ?? []))));
        $members = array_filter($members, fn ($m) => $m > 0);
        $fields  = [mb_substr($name, 0, 150), trim((string) ($_POST['description'] ?? '')) ?: null, $kind, $private, $points, $sprintDays, $sprintStart, $labels ? implode(',', $labels) : null, $planId];

        $saveMembers = function (int $boardId, array $members, int $ownerId): void {
            DB::execute('DELETE FROM plan_board_members WHERE board_id = ?', [$boardId]);
            $members[] = $ownerId;
            foreach (array_unique($members) as $m) {
                if (DB::queryOne('SELECT id FROM users WHERE id = ? AND active = 1', [$m])) {
                    DB::execute('INSERT IGNORE INTO plan_board_members (board_id, user_id, role) VALUES (?, ?, ?)', [$boardId, $m, $m === $ownerId ? 'owner' : 'member']);
                }
            }
        };

        if ($board) {
            DB::execute(
                'UPDATE plan_boards SET name = ?, description = ?, kind = ?, is_private = ?, use_points = ?, sprint_days = ?, sprint_start = ?, labels = ?, plan_id = ? WHERE id = ?',
                array_merge($fields, [$board['id']])
            );
            $saveMembers((int) $board['id'], $members, (int) ($board['created_by'] ?: $uid));
            Audit::log('planejamento.board_update', 'plan_boards', (string) $board['id'], ['name' => $name]);
            Flash::set('success', 'Quadro atualizado.');
            core_redirect(plan_url('boards', ['action' => 'view', 'id' => $board['id']]));
        }
        $tpl = plan_find_template((int) ($_POST['template_id'] ?? 0));
        if ($tpl && ($tpl['kind'] !== 'board' || !$tpl['active'])) {
            $tpl = null;
        }
        $tplData = $tpl ? plan_normalize_board_template(plan_json_decode((string) $tpl['data'])) : null;
        $newId = DB::transaction(function () use ($fields, $tpl, $tplData, $uid, $members, $saveMembers): int {
            DB::execute(
                'INSERT INTO plan_boards (name, description, kind, is_private, use_points, sprint_days, sprint_start, labels, plan_id, template_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($fields, [$tpl ? (int) $tpl['id'] : null, $uid])
            );
            $newId = DB::lastId();
            plan_board_create_columns($newId, $tplData['columns'] ?? []);
            $saveMembers($newId, $members, $uid);
            return $newId;
        });
        Audit::log('planejamento.board_create', 'plan_boards', (string) $newId, ['name' => $name, 'template_id' => $tpl['id'] ?? null]);
        Flash::set('success', $tpl ? 'Quadro criado a partir do modelo "' . $tpl['name'] . '".' : 'Quadro criado.');
        core_redirect(plan_url('boards', ['action' => 'view', 'id' => $newId]));
    }

    if (!$board) {
        core_redirect(plan_url('boards'));
    }
    if ($action === 'archive' || $action === 'unarchive') {
        core_require('boards.delete');
        DB::execute('UPDATE plan_boards SET archived_at = ' . ($action === 'archive' ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$board['id']]);
        Audit::log('planejamento.board_' . $action, 'plan_boards', (string) $board['id'], ['name' => $board['name']]);
        Flash::set('success', $action === 'archive' ? 'Quadro arquivado.' : 'Quadro desarquivado.');
        core_redirect(plan_url('boards', $action === 'archive' ? ['scope' => 'archived'] : ['action' => 'view', 'id' => $board['id']]));
    }
    if ($action === 'delete') {
        core_require('boards.delete');
        DB::execute('DELETE FROM plan_boards WHERE id = ?', [$board['id']]); // colunas/cartões/membros via CASCADE
        Audit::log('planejamento.board_delete', 'plan_boards', (string) $board['id'], ['name' => $board['name']]);
        Flash::set('success', 'Quadro excluído.');
        core_redirect(plan_url('boards'));
    }
    core_redirect(plan_url('boards'));
}

// ===========================================================================
// Formulário (criar / configurar)
// ===========================================================================
if ($action === 'create' || $action === 'edit') {
    $board = $action === 'edit' ? plan_find_board((int) ($_GET['id'] ?? 0)) : null;
    if ($action === 'edit' && (!$board || !plan_board_can_view($board))) {
        Flash::set('error', 'Quadro não encontrado.');
        core_redirect(plan_url('boards'));
    }
    core_require($board ? 'boards.edit' : 'boards.create');
    $templates  = $board ? [] : plan_templates_active('board');
    $selectedTp = (int) ($_GET['template'] ?? 0);
    $tplSel     = $selectedTp ? plan_find_template($selectedTp) : null;
    $tplData    = $tplSel && $tplSel['kind'] === 'board' ? plan_normalize_board_template(plan_json_decode((string) $tplSel['data'])) : null;
    $planIdPre  = (int) ($_GET['plan_id'] ?? ($board['plan_id'] ?? 0));
    $memberIds  = $board ? plan_board_member_ids((int) $board['id']) : [$uid];
    $users      = plan_users_active();
    $plans      = DB::query("SELECT id, title FROM plan_plans WHERE deleted_at IS NULL AND status <> 'archived' ORDER BY title LIMIT 300");
    $v = fn (string $k, $default = '') => $board[$k] ?? ($tplData[$k] ?? $default);
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-kanban me-2"></i><?= $board ? 'Configurar quadro' : 'Novo quadro' ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $board ? plan_url('boards', ['action' => 'view', 'id' => $board['id']]) : plan_url('boards') ?>">Voltar</a>
    </div>
    <form method="post" action="<?= plan_url('boards') ?>" class="row g-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($board['id'] ?? 0) ?>">
        <div class="col-12 col-xl-8">
            <div class="card mb-3"><div class="card-body row g-3">
                <div class="col-md-8"><label class="form-label">Nome *</label><input class="form-control" name="name" required maxlength="150" value="<?= core_e($board['name'] ?? ($tplSel ? $tplSel['name'] : '')) ?>"></div>
                <div class="col-md-4"><label class="form-label">Tipo</label>
                    <select class="form-select" name="kind" id="boardKind">
                        <?php foreach (plan_board_kinds() as $k => $lbl): ?><option value="<?= $k ?>" <?= $v('kind', 'kanban') === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="2"><?= core_e($board['description'] ?? '') ?></textarea></div>
                <div class="col-md-6"><label class="form-label">Etiquetas disponíveis (separadas por vírgula)</label>
                    <input class="form-control" name="labels" maxlength="500" value="<?= core_e($board ? (string) $board['labels'] : implode(', ', $tplData['labels'] ?? [])) ?>" placeholder="urgente, melhoria, rotina"></div>
                <div class="col-md-6"><label class="form-label">Plano de trabalho vinculado</label>
                    <select class="form-select" name="plan_id"><option value="">—</option>
                        <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $planIdPre === (int) $p['id'] ? 'selected' : '' ?>><?= core_e($p['title']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-4">
                    <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="use_points" value="1" id="usePoints" <?= !empty($board['use_points']) || !empty($tplData['points']) ? 'checked' : '' ?>><label class="form-check-label" for="usePoints">Usar pontos (story points)</label></div>
                </div>
                <div class="col-md-4"><label class="form-label">Duração da sprint (dias)</label><input type="number" min="1" max="365" class="form-control" name="sprint_days" value="<?= core_e((string) ($board['sprint_days'] ?? ($tplData['sprint_days'] ?? ''))) ?>"></div>
                <div class="col-md-4"><label class="form-label">Início da sprint atual</label><input type="date" class="form-control" name="sprint_start" value="<?= core_e($board['sprint_start'] ?? '') ?>"></div>
            </div></div>
            <div class="card"><div class="card-body row g-3">
                <div class="col-12">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_private" value="1" id="isPrivate" <?= !empty($board['is_private']) ? 'checked' : '' ?>><label class="form-check-label" for="isPrivate">Quadro privado (somente membros, autor e gestores)</label></div>
                </div>
                <div class="col-12"><label class="form-label">Membros</label>
                    <select class="form-select" name="members[]" multiple size="8">
                        <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= in_array($u['id'], $memberIds, true) ? 'selected' : '' ?>><?= core_e($u['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Segure Ctrl (ou Cmd) para selecionar vários. O autor do quadro é sempre membro.</div></div>
            </div></div>
        </div>
        <div class="col-12 col-xl-4">
            <?php if (!$board): ?>
            <div class="card mb-3"><div class="card-header"><i class="bi bi-grid-1x2 me-1"></i>Modelo de quadro</div><div class="card-body">
                <div class="form-check mb-2"><input class="form-check-input" type="radio" name="template_id" value="0" id="tpl0" <?= !$tplData ? 'checked' : '' ?>><label class="form-check-label" for="tpl0">Em branco (A fazer / Em andamento / Concluído)</label></div>
                <?php foreach ($templates as $t): ?>
                    <div class="form-check mb-2"><input class="form-check-input" type="radio" name="template_id" value="<?= (int) $t['id'] ?>" id="tpl<?= (int) $t['id'] ?>" <?= $selectedTp === (int) $t['id'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tpl<?= (int) $t['id'] ?>"><i class="bi <?= core_e($t['icon'] ?: 'bi-kanban') ?> me-1"></i><?= core_e($t['name']) ?>
                            <?php if ($t['description']): ?><div class="form-text mt-0"><?= core_e($t['description']) ?></div><?php endif; ?></label></div>
                <?php endforeach; ?>
                <div class="form-text">O modelo define as colunas iniciais, etiquetas, pontos e sprint. Você pode ajustar as colunas depois no próprio quadro.</div>
            </div></div>
            <?php else: ?>
            <div class="card mb-3"><div class="card-header"><i class="bi bi-layout-three-columns me-1"></i>Colunas</div><div class="card-body small">
                <?php foreach (plan_board_columns((int) $board['id']) as $c): ?>
                    <div class="d-flex align-items-center gap-2 mb-1"><span class="d-inline-block rounded" style="width:12px;height:12px;background:<?= core_e($c['color'] ?: '#adb5bd') ?>"></span><?= core_e($c['name']) ?>
                        <?php if ($c['wip_limit']): ?><span class="badge text-bg-light border">WIP <?= (int) $c['wip_limit'] ?></span><?php endif; ?>
                        <?php if ($c['is_done']): ?><span class="badge text-bg-success">concluída</span><?php endif; ?></div>
                <?php endforeach; ?>
                <div class="text-muted mt-2">Adicione, renomeie, reordene ou exclua colunas diretamente no quadro (menu de cada coluna).</div>
            </div></div>
            <?php endif; ?>
            <button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i><?= $board ? 'Salvar configurações' : 'Criar quadro' ?></button>
        </div>
    </form>
    <?php
    plan_page(['title' => $board ? 'Configurar quadro' : 'Novo quadro', 'content' => (string) ob_get_clean(), 'active' => 'boards']);
    exit;
}

// ===========================================================================
// Quadro (Kanban / Scrum)
// ===========================================================================
if ($action === 'view') {
    $board = plan_find_board((int) ($_GET['id'] ?? 0));
    if (!$board || !plan_board_can_view($board)) {
        Core\Layout::renderError(404, 'Quadro não encontrado ou sem acesso.');
        exit;
    }
    $state    = plan_board_state($board);
    $archived = !empty($board['archived_at']);
    $canCards = core_can('boards.cards') && !$archived;
    $canEdit  = core_can('boards.edit') && !$archived;
    $members  = plan_board_members((int) $board['id']);
    $labels   = plan_labels_list($board['labels'] ?? '');
    $isScrum  = $board['kind'] === 'scrum';

    // Scrum: pontos e burndown
    $totalPoints = 0;
    $donePoints  = 0;
    foreach ($state['cards'] as $c) {
        $totalPoints += (int) ($c['points'] ?? 0);
        if ($c['completed_at']) {
            $donePoints += (int) ($c['points'] ?? 0);
        }
    }
    $burndown = '';
    if ($isScrum && $board['sprint_start'] && $board['sprint_days']) {
        $days   = (int) $board['sprint_days'];
        $start  = strtotime((string) $board['sprint_start']);
        $today  = strtotime(date('Y-m-d'));
        $W = 640; $H = 200; $pl = 36; $pr = 12; $pt = 12; $pb = 26;
        $iw = $W - $pl - $pr; $ih = $H - $pt - $pb;
        $maxY = max(1, $totalPoints);
        $x = fn (int $d): float => $pl + ($days > 0 ? $d / $days * $iw : 0);
        $y = fn (float $v): float => $pt + $ih - ($v / $maxY) * $ih;
        $actual = [];
        for ($d = 0; $d <= $days; $d++) {
            $dayTs = $start + $d * 86400;
            if ($dayTs > $today) {
                break;
            }
            $dayEnd = date('Y-m-d', $dayTs) . ' 23:59:59';
            $done = 0;
            foreach ($state['cards'] as $c) {
                if ($c['completed_at'] && $c['completed_at'] <= $dayEnd) {
                    $done += (int) ($c['points'] ?? 0);
                }
            }
            $actual[] = sprintf('%.1f,%.1f', $x($d), $y((float) max(0, $totalPoints - $done)));
        }
        $svg  = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Burndown da sprint">';
        $svg .= '<line x1="' . $pl . '" y1="' . ($pt + $ih) . '" x2="' . ($W - $pr) . '" y2="' . ($pt + $ih) . '" stroke="#adb5bd"/>';
        $svg .= '<line x1="' . $pl . '" y1="' . $pt . '" x2="' . $pl . '" y2="' . ($pt + $ih) . '" stroke="#adb5bd"/>';
        for ($g = 0; $g <= 4; $g++) {
            $v = $maxY * $g / 4;
            $svg .= '<text x="' . ($pl - 4) . '" y="' . ($y($v) + 4) . '" font-size="10" text-anchor="end" fill="#6c757d">' . round($v) . '</text>';
            $svg .= '<line x1="' . $pl . '" y1="' . $y($v) . '" x2="' . ($W - $pr) . '" y2="' . $y($v) . '" stroke="#f1f3f5"/>';
        }
        $step = max(1, (int) ceil($days / 14));
        for ($d = 0; $d <= $days; $d += $step) {
            $svg .= '<text x="' . $x($d) . '" y="' . ($H - 8) . '" font-size="10" text-anchor="middle" fill="#6c757d">' . date('d/m', $start + $d * 86400) . '</text>';
        }
        $svg .= '<line x1="' . $x(0) . '" y1="' . $y((float) $totalPoints) . '" x2="' . $x($days) . '" y2="' . $y(0) . '" stroke="#6c757d" stroke-dasharray="5,4" stroke-width="1.5"/>';
        if (count($actual) > 1) {
            $svg .= '<polyline points="' . implode(' ', $actual) . '" fill="none" stroke="#0d6efd" stroke-width="2.5"/>';
        }
        foreach ($actual as $p) {
            [$px, $py] = explode(',', $p);
            $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="#0d6efd"/>';
        }
        $svg .= '</svg>';
        $burndown = $svg;
    }

    $data = [
        'board'     => ['id' => (int) $board['id'], 'name' => $board['name'], 'kind' => $board['kind'], 'use_points' => (int) $board['use_points'], 'archived' => $archived],
        'columns'   => $state['columns'],
        'cards'     => $state['cards'],
        'users'     => plan_users_active(),
        'labels'    => $labels,
        'priorities' => plan_card_priorities(),
        'planItems' => array_map(fn ($i) => ['id' => (int) $i['id'], 'title' => $i['title'], 'plan' => $i['plan_title'], 'kind' => $i['kind']], plan_linkable_items($board['plan_id'] ? (int) $board['plan_id'] : null)),
        'canCards'  => $canCards,
        'canEdit'   => $canEdit,
        'apiUrl'    => plan_url('api'),
        'csrf'      => Csrf::token(),
        'openCard'  => (int) ($_GET['card'] ?? 0),
        'userId'    => $uid,
    ];
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-kanban me-2"></i><?= core_e($board['name']) ?>
                <span class="badge text-bg-light border fw-normal"><?= core_e(plan_board_kinds()[$board['kind']] ?? $board['kind']) ?></span>
                <?php if ($board['is_private']): ?><span class="badge text-bg-secondary fw-normal" title="Privado"><i class="bi bi-lock"></i> privado</span><?php endif; ?>
                <?php if ($archived): ?><span class="badge text-bg-dark fw-normal"><i class="bi bi-archive"></i> arquivado</span><?php endif; ?>
            </h1>
            <div class="small text-muted">
                <?php if ($board['description']): ?><?= core_e($board['description']) ?> · <?php endif; ?>
                <i class="bi bi-people"></i> <?= count($members) ?> membro(s)
                <?php if ($board['plan_title']): ?> · <i class="bi bi-clipboard2-check"></i> <a href="<?= plan_url('plans', ['action' => 'view', 'id' => $board['plan_id']]) ?>"><?= core_e($board['plan_title']) ?></a><?php endif; ?>
                <?php if ($isScrum && $board['sprint_start']): ?>
                    · <i class="bi bi-arrow-repeat"></i> Sprint: <?= plan_date_br($board['sprint_start']) ?><?php if ($board['sprint_days']): ?> a <?= date('d/m/Y', strtotime($board['sprint_start'] . ' +' . ((int) $board['sprint_days'] - 1) . ' days')) ?> (<?= (int) $board['sprint_days'] ?> dias)<?php endif; ?>
                <?php endif; ?>
                <?php if ($board['use_points']): ?> · <i class="bi bi-123"></i> Pontos: <strong id="boardPointsDone"><?= $donePoints ?></strong>/<strong id="boardPointsTotal"><?= $totalPoints ?></strong><?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canCards): ?><button type="button" class="btn btn-primary btn-sm" id="btnNewCard"><i class="bi bi-plus-lg me-1"></i>Novo cartão</button><?php endif; ?>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnNewColumn"><i class="bi bi-layout-three-columns me-1"></i>Nova coluna</button>
                <a class="btn btn-outline-primary btn-sm" href="<?= plan_url('boards', ['action' => 'edit', 'id' => $board['id']]) ?>"><i class="bi bi-gear me-1"></i>Configurar</a>
            <?php endif; ?>
            <?php if (core_can('boards.delete')): ?>
                <form method="post" action="<?= plan_url('boards') ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $board['id'] ?>"><input type="hidden" name="action" value="<?= $archived ? 'unarchive' : 'archive' ?>">
                    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-archive me-1"></i><?= $archived ? 'Desarquivar' : 'Arquivar' ?></button></form>
                <form method="post" action="<?= plan_url('boards') ?>" class="d-inline" onsubmit="return confirm('Excluir o quadro e todos os cartões?')"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $board['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('boards') ?>">Voltar</a>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <input class="form-control form-control-sm w-auto" id="fltSearch" placeholder="Buscar cartão…" style="min-width:200px">
        <select class="form-select form-select-sm w-auto" id="fltAssignee"><option value="">Todos os responsáveis</option><option value="me">Meus cartões</option><option value="none">Sem responsável</option>
            <?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= core_e($m['name']) ?></option><?php endforeach; ?></select>
        <?php if ($labels): ?>
        <select class="form-select form-select-sm w-auto" id="fltLabel"><option value="">Todas as etiquetas</option>
            <?php foreach ($labels as $l): ?><option value="<?= core_e($l) ?>"><?= core_e($l) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <span class="small text-muted" id="fltInfo"></span>
        <?php if ($isScrum && $burndown): ?><button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="btnBurndown"><i class="bi bi-graph-down me-1"></i>Burndown</button><?php endif; ?>
    </div>

    <?php if ($isScrum && $burndown): ?>
    <div class="card mb-3 d-none" id="burndownCard"><div class="card-body plan-burndown">
        <div class="d-flex justify-content-between small text-muted mb-1"><span><strong>Burndown</strong> — pontos restantes por dia da sprint</span><span><span style="border-top:2px dashed #6c757d;display:inline-block;width:24px;vertical-align:middle"></span> ideal &nbsp; <span style="border-top:3px solid #0d6efd;display:inline-block;width:24px;vertical-align:middle"></span> real</span></div>
        <?= $burndown ?>
    </div></div>
    <?php elseif ($isScrum): ?>
    <div class="alert alert-light border small py-2">Defina o <strong>início</strong> e a <strong>duração da sprint</strong> em Configurar para ver o burndown.</div>
    <?php endif; ?>

    <div class="plan-board" id="board"><div class="text-muted small p-3">Carregando quadro…</div></div>

    <!-- Modal de cartão -->
    <div class="plan-modal-backdrop" id="planBackdrop"></div>
    <div class="plan-modal" id="cardModal"><div class="modal-dialog modal-lg"><div class="modal-content">
        <form id="cardForm">
            <div class="modal-header py-2"><h5 class="modal-title" id="cardModalTitle">Cartão</h5><button type="button" class="btn-close" data-close></button></div>
            <div class="modal-body">
                <input type="hidden" name="id" value="0">
                <div class="row g-2">
                    <div class="col-md-9"><label class="form-label small mb-0">Título *</label><input class="form-control" name="title" required maxlength="300"></div>
                    <div class="col-md-3"><label class="form-label small mb-0">Coluna</label><select class="form-select" name="column_id" id="cardColumn"></select></div>
                    <div class="col-12"><label class="form-label small mb-0">Descrição</label><textarea class="form-control" name="description" rows="3"></textarea></div>
                    <div class="col-md-4"><label class="form-label small mb-0">Responsável</label><select class="form-select" name="assignee_id"><option value="">—</option>
                        <?php foreach (plan_users_active() as $u): ?><option value="<?= $u['id'] ?>"><?= core_e($u['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label small mb-0">Prioridade</label><select class="form-select" name="priority">
                        <?php foreach (plan_card_priorities() as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'medium' ? 'selected' : '' ?>><?= core_e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label small mb-0">Prazo</label><input type="date" class="form-control" name="due_date"></div>
                    <div class="col-md-2"><label class="form-label small mb-0">Cor</label><input type="color" class="form-control form-control-color w-100" name="color" value="#ffffff"><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="cardNoColor" checked><label class="form-check-label small" for="cardNoColor">sem cor</label></div></div>
                    <?php if ($board['use_points']): ?><div class="col-md-2"><label class="form-label small mb-0">Pontos</label><input type="number" min="0" max="999" class="form-control" name="points"></div><?php endif; ?>
                    <div class="col-md-<?= $board['use_points'] ? '5' : '6' ?>"><label class="form-label small mb-0">Etiquetas</label>
                        <?php if ($labels): ?>
                            <div class="d-flex flex-wrap gap-2 pt-1" id="cardLabels">
                                <?php foreach ($labels as $i => $l): ?><div class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="labels_chk" value="<?= core_e($l) ?>" id="lbl<?= $i ?>"><label class="form-check-label small" for="lbl<?= $i ?>"><?= core_e($l) ?></label></div><?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <input class="form-control" name="labels_text" placeholder="separadas por vírgula" maxlength="255">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-<?= $board['use_points'] ? '5' : '6' ?>"><label class="form-label small mb-0">Ação do plano vinculada</label><select class="form-select" name="plan_item_id" id="cardPlanItem"><option value="">—</option></select></div>
                    <div class="col-12"><label class="form-label small mb-0">Checklist</label>
                        <div class="plan-checklist" id="cardChecklist"></div>
                        <div class="input-group input-group-sm mt-1"><input class="form-control" id="cardChecklistNew" placeholder="Novo item da checklist"><button type="button" class="btn btn-outline-secondary" id="cardChecklistAdd"><i class="bi bi-plus"></i></button></div>
                    </div>
                </div>
                <div id="cardCommentsWrap" class="mt-3 d-none">
                    <label class="form-label small mb-1"><i class="bi bi-chat-dots me-1"></i>Comentários</label>
                    <div class="plan-comments border rounded p-2 mb-2" id="cardComments"></div>
                    <?php if ($canCards): ?><div class="input-group input-group-sm"><input class="form-control" id="cardCommentNew" placeholder="Escreva um comentário…"><button type="button" class="btn btn-outline-primary" id="cardCommentAdd">Comentar</button></div><?php endif; ?>
                </div>
            </div>
            <div class="modal-footer py-2">
                <span class="small text-muted me-auto" id="cardInfo"></span>
                <?php if ($canCards): ?><button type="button" class="btn btn-outline-danger btn-sm d-none" id="cardDelete"><i class="bi bi-trash"></i></button><?php endif; ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close>Fechar</button>
                <?php if ($canCards): ?><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Salvar</button><?php endif; ?>
            </div>
        </form>
    </div></div></div>

    <!-- Modal de coluna -->
    <div class="plan-modal" id="columnModal"><div class="modal-dialog"><div class="modal-content">
        <form id="columnForm">
            <div class="modal-header py-2"><h5 class="modal-title" id="columnModalTitle">Coluna</h5><button type="button" class="btn-close" data-close></button></div>
            <div class="modal-body row g-2">
                <input type="hidden" name="id" value="0">
                <div class="col-8"><label class="form-label small mb-0">Nome *</label><input class="form-control" name="name" required maxlength="100"></div>
                <div class="col-4"><label class="form-label small mb-0">Cor</label><input type="color" class="form-control form-control-color w-100" name="color" value="#0d6efd"></div>
                <div class="col-6"><label class="form-label small mb-0">Limite WIP (0 = sem limite)</label><input type="number" min="0" max="999" class="form-control" name="wip_limit" value="0"></div>
                <div class="col-6"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="is_done" value="1" id="colIsDone"><label class="form-check-label" for="colIsDone">Coluna de concluídos</label></div></div>
                <div class="col-12 d-none" id="colDeleteWrap"><hr><label class="form-label small mb-0">Excluir coluna — mover os cartões para:</label>
                    <div class="input-group input-group-sm"><select class="form-select" id="colMoveTo"></select><button type="button" class="btn btn-outline-danger" id="colDelete"><i class="bi bi-trash me-1"></i>Excluir coluna</button></div></div>
            </div>
            <div class="modal-footer py-2">
                <div class="me-auto d-none" id="colMoveWrap"><button type="button" class="btn btn-outline-secondary btn-sm" id="colLeft" title="Mover para a esquerda"><i class="bi bi-arrow-left"></i></button> <button type="button" class="btn btn-outline-secondary btn-sm" id="colRight" title="Mover para a direita"><i class="bi bi-arrow-right"></i></button></div>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close>Fechar</button>
                <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Salvar</button>
            </div>
        </form>
    </div></div></div>

    <script>window.BOARD_DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <?php
    plan_page([
        'title'   => $board['name'],
        'content' => (string) ob_get_clean(),
        'active'  => 'boards',
        'fluid'   => true,
        'scripts' => '<script src="' . core_asset('planejamento/boards.js') . '?v=1"></script>',
    ]);
    exit;
}

// ===========================================================================
// Lista
// ===========================================================================
$scope  = in_array($_GET['scope'] ?? '', ['all', 'mine', 'public', 'archived'], true) ? $_GET['scope'] : 'all';
$boards = plan_boards_visible($scope === 'archived', $scope === 'archived' ? 'all' : $scope);
$tabs   = ['all' => 'Todos', 'mine' => 'Meus quadros', 'public' => 'Públicos', 'archived' => 'Arquivados'];
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-kanban me-2"></i>Quadros</h1>
    <?php if (core_can('boards.create')): ?><a class="btn btn-primary" href="<?= plan_url('boards', ['action' => 'create']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo quadro</a><?php endif; ?>
</div>
<ul class="nav nav-pills mb-3">
    <?php foreach ($tabs as $k => $lbl): ?><li class="nav-item"><a class="nav-link <?= $scope === $k ? 'active' : '' ?>" href="<?= plan_url('boards', ['scope' => $k]) ?>"><?= $lbl ?></a></li><?php endforeach; ?>
</ul>
<div class="card"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Quadro</th><th>Tipo</th><th class="text-center">Cartões</th><th class="text-center">Membros</th><th>Criado por</th><th>Atualização</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php if (!$boards): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum quadro<?= $scope === 'archived' ? ' arquivado' : '' ?>.</td></tr><?php endif; ?>
        <?php foreach ($boards as $b): ?>
            <tr>
                <td><a class="fw-semibold text-decoration-none" href="<?= plan_url('boards', ['action' => 'view', 'id' => $b['id']]) ?>"><?= core_e($b['name']) ?></a>
                    <?php if ($b['is_private']): ?><span class="badge text-bg-secondary" title="Privado"><i class="bi bi-lock"></i></span><?php endif; ?>
                    <?php if ($b['description']): ?><div class="small text-muted"><?= core_e(mb_strimwidth((string) $b['description'], 0, 90, '…')) ?></div><?php endif; ?>
                    <?php if ($b['plan_title']): ?><div class="small text-muted"><i class="bi bi-clipboard2-check"></i> <?= core_e($b['plan_title']) ?></div><?php endif; ?></td>
                <td class="small"><?= core_e(plan_board_kinds()[$b['kind']] ?? $b['kind']) ?></td>
                <td class="text-center small"><?= (int) $b['cards_done'] ?>/<?= (int) $b['cards_total'] ?></td>
                <td class="text-center small"><?= (int) $b['members_total'] ?></td>
                <td class="small"><?= core_e($b['creator_name'] ?: '—') ?></td>
                <td class="small text-muted"><?= plan_datetime_br($b['updated_at']) ?></td>
                <td class="text-end text-nowrap">
                    <?php if (core_can('boards.edit') && !$b['archived_at']): ?><a class="btn btn-sm btn-outline-primary" title="Configurar" href="<?= plan_url('boards', ['action' => 'edit', 'id' => $b['id']]) ?>"><i class="bi bi-gear"></i></a><?php endif; ?>
                    <?php if (core_can('boards.delete')): ?>
                        <form method="post" action="<?= plan_url('boards') ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="<?= $b['archived_at'] ? 'unarchive' : 'archive' ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="<?= $b['archived_at'] ? 'Desarquivar' : 'Arquivar' ?>"><i class="bi bi-archive"></i></button></form>
                        <form method="post" action="<?= plan_url('boards') ?>" class="d-inline" onsubmit="return confirm('Excluir o quadro e todos os cartões?')"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="delete">
                            <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php
plan_page(['title' => 'Quadros', 'content' => (string) ob_get_clean(), 'active' => 'boards']);
