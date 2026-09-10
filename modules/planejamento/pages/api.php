<?php
/**
 * PLANEJAMENTO — API JSON (AJAX) para itens de plano, cartões, colunas e
 * comentários. Rotas: index.php?m=planejamento&page=api&action=<ação>.
 * Escritas somente via POST com CSRF (header X-CSRF-TOKEN ou _csrf_token).
 */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Notifications;

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$uid    = (int) core_user_id();

$readOnly = ['plan_tree', 'board_state', 'card_get'];
$writable = [
    'item_add', 'item_update', 'item_done', 'item_delete', 'item_move', 'item_reorder',
    'card_save', 'card_move', 'card_delete', 'checklist_toggle', 'comment_add',
    'column_add', 'column_update', 'column_delete', 'column_reorder',
];
if (!in_array($action, $readOnly, true) && !in_array($action, $writable, true)) {
    plan_api_error('Ação inválida.', 404);
}
if (!in_array($action, $readOnly, true)) {
    if (!$isPost) {
        plan_api_error('Método não permitido.', 405);
    }
    if (!Csrf::validate()) {
        plan_api_error('Sessão expirada ou token inválido. Recarregue a página.', 419);
    }
}

/** Exige micropermissão (403 em JSON). */
function plan_api_require(string $perm): void
{
    if (!core_can($perm)) {
        plan_api_error('Você não tem permissão para esta ação.', 403);
    }
}

/** Plano editável (existe, não excluído, não arquivado) + plans.edit. */
function plan_api_plan_editable(int $planId): array
{
    plan_api_require('plans.edit');
    $plan = plan_find_plan($planId);
    if (!$plan) {
        plan_api_error('Plano não encontrado.', 404);
    }
    if ($plan['status'] === 'archived') {
        plan_api_error('Plano arquivado: desarquive para editar.', 409);
    }
    return $plan;
}

function plan_api_item(int $id): array
{
    $item = $id > 0 ? DB::queryOne('SELECT * FROM plan_plan_items WHERE id = ?', [$id]) : null;
    if (!$item) {
        plan_api_error('Item não encontrado.', 404);
    }
    return $item;
}

/** Resposta padrão de plano: árvore + progresso. */
function plan_api_plan_response(int $planId, array $extra = []): never
{
    $progress = plan_recalc_progress($planId);
    $items    = array_map('plan_item_to_array', plan_plan_items($planId));
    plan_json_response(['ok' => true, 'progress' => $progress, 'items' => $items] + $extra);
}

/** Quadro acessível ao usuário com a permissão pedida. */
function plan_api_board(int $boardId, string $perm = 'boards.view'): array
{
    plan_api_require($perm);
    $board = plan_find_board($boardId);
    if (!$board || !plan_board_can_view($board)) {
        plan_api_error('Quadro não encontrado.', 404);
    }
    if ($perm !== 'boards.view' && !empty($board['archived_at'])) {
        plan_api_error('Quadro arquivado: desarquive para alterar.', 409);
    }
    return $board;
}

function plan_api_card(int $id): array
{
    $card = $id > 0 ? DB::queryOne('SELECT * FROM plan_board_cards WHERE id = ?', [$id]) : null;
    if (!$card) {
        plan_api_error('Cartão não encontrado.', 404);
    }
    return $card;
}

function plan_api_board_response(int $boardId, array $extra = []): never
{
    $board = plan_find_board($boardId);
    plan_json_response(['ok' => true] + plan_board_state($board) + $extra);
}

/**
 * Sincroniza a ação do plano vinculada ao cartão ao entrar/sair da coluna
 * concluída. Só escreve no plano quem tem a permissão de editar planos —
 * caso contrário o vínculo é apenas informativo.
 */
function plan_api_sync_linked_item(array $card, bool $nowDone): void
{
    if (empty($card['plan_item_id']) || !core_can('plans.edit')) {
        return;
    }
    $item = DB::queryOne('SELECT id, plan_id, status FROM plan_plan_items WHERE id = ?', [(int) $card['plan_item_id']]);
    if (!$item) {
        return;
    }
    if ($nowDone && $item['status'] !== 'done') {
        DB::execute("UPDATE plan_plan_items SET status = 'done', progress = 100, completed_at = NOW() WHERE id = ?", [$item['id']]);
    } elseif (!$nowDone && $item['status'] === 'done') {
        DB::execute("UPDATE plan_plan_items SET status = 'in_progress', completed_at = NULL WHERE id = ?", [$item['id']]);
    } else {
        return;
    }
    plan_recalc_progress((int) $item['plan_id']);
}

switch ($action) {
    // =====================================================================
    // PLANOS — itens
    // =====================================================================
    case 'plan_tree':
        plan_api_require('plans.view');
        $plan = plan_find_plan((int) ($_GET['plan_id'] ?? 0));
        if (!$plan) {
            plan_api_error('Plano não encontrado.', 404);
        }
        plan_json_response([
            'ok'       => true,
            'progress' => (int) $plan['progress'],
            'items'    => array_map('plan_item_to_array', plan_plan_items((int) $plan['id'])),
        ]);

    case 'item_add':
        $plan     = plan_api_plan_editable((int) ($_POST['plan_id'] ?? 0));
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        $title    = trim((string) ($_POST['title'] ?? ''));
        $kind     = (string) ($_POST['kind'] ?? '');
        if ($title === '') {
            plan_api_error('Informe o título do item.');
        }
        if ($parentId) {
            $parent = plan_api_item($parentId);
            if ((int) $parent['plan_id'] !== (int) $plan['id']) {
                plan_api_error('Item pai inválido.');
            }
            $next = ['objective' => 'goal', 'goal' => 'action', 'action' => 'task', 'task' => 'task'];
            if (!in_array($kind, array_keys(plan_item_kinds()), true)) {
                $kind = $next[$parent['kind']];
            }
            if ($parent['kind'] === 'task') {
                plan_api_error('Tarefas não podem ter subitens.');
            }
        } elseif (!in_array($kind, array_keys(plan_item_kinds()), true)) {
            $kind = 'objective';
        }
        $max = DB::queryOne('SELECT COALESCE(MAX(sort_order), -1) AS m FROM plan_plan_items WHERE plan_id = ? AND ' . ($parentId ? 'parent_id = ?' : 'parent_id IS NULL'),
            $parentId ? [$plan['id'], $parentId] : [$plan['id']]);
        $prio = in_array($_POST['priority'] ?? '', array_keys(plan_priorities()), true) ? $_POST['priority'] : 'medium';
        DB::execute(
            'INSERT INTO plan_plan_items (plan_id, parent_id, kind, title, priority, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$plan['id'], $parentId, $kind, mb_substr($title, 0, 300), $prio, (int) ($max['m'] ?? -1) + 1]
        );
        $newId = DB::lastId();
        DB::execute('UPDATE plan_plans SET updated_by = ? WHERE id = ?', [$uid, $plan['id']]);
        plan_api_plan_response((int) $plan['id'], ['id' => $newId]);

    case 'item_update':
        $item = plan_api_item((int) ($_POST['id'] ?? 0));
        $plan = plan_api_plan_editable((int) $item['plan_id']);
        $title = trim((string) ($_POST['title'] ?? $item['title']));
        if ($title === '') {
            plan_api_error('Informe o título do item.');
        }
        $status   = in_array($_POST['status'] ?? '', array_keys(plan_item_statuses()), true) ? $_POST['status'] : $item['status'];
        $priority = in_array($_POST['priority'] ?? '', array_keys(plan_priorities()), true) ? $_POST['priority'] : $item['priority'];
        $kind     = in_array($_POST['kind'] ?? '', array_keys(plan_item_kinds()), true) ? $_POST['kind'] : $item['kind'];
        $progress = max(0, min(100, (int) ($_POST['progress'] ?? $item['progress'])));
        if ($status === 'done') {
            $progress = 100;
        }
        $respId = (int) ($_POST['responsible_id'] ?? 0) ?: null;
        if ($respId && !DB::queryOne('SELECT id FROM users WHERE id = ? AND active = 1', [$respId])) {
            $respId = null;
        }
        $cost = trim((string) ($_POST['cost'] ?? ''));
        $cost = $cost !== '' ? round((float) str_replace(',', '.', $cost), 2) : null;
        $completedAt = $status === 'done' ? ($item['completed_at'] ?: date('Y-m-d H:i:s')) : null;
        DB::execute(
            'UPDATE plan_plan_items SET kind = ?, title = ?, description = ?, where_text = ?, how_text = ?, cost = ?,
                    responsible_id = ?, responsible_name = ?, start_date = ?, due_date = ?, status = ?, priority = ?,
                    progress = ?, indicator = ?, completed_at = ? WHERE id = ?',
            [
                $kind, mb_substr($title, 0, 300),
                trim((string) ($_POST['description'] ?? '')) ?: null,
                mb_substr(trim((string) ($_POST['where_text'] ?? '')), 0, 200) ?: null,
                trim((string) ($_POST['how_text'] ?? '')) ?: null,
                $cost, $respId,
                $respId ? null : (mb_substr(trim((string) ($_POST['responsible_name'] ?? '')), 0, 150) ?: null),
                plan_date_or_null($_POST['start_date'] ?? ''), plan_date_or_null($_POST['due_date'] ?? ''),
                $status, $priority, $progress,
                mb_substr(trim((string) ($_POST['indicator'] ?? '')), 0, 255) ?: null,
                $completedAt, $item['id'],
            ]
        );
        if ($respId && $respId !== $uid && $respId !== (int) ($item['responsible_id'] ?? 0)) {
            Notifications::add($respId, 'Você é responsável por um item do plano', mb_substr($title, 0, 200),
                plan_url('plans', ['action' => 'view', 'id' => $plan['id']]), 'info', 'planejamento');
        }
        DB::execute('UPDATE plan_plans SET updated_by = ? WHERE id = ?', [$uid, $plan['id']]);
        plan_api_plan_response((int) $plan['id']);

    case 'item_done':
        $item = plan_api_item((int) ($_POST['id'] ?? 0));
        $plan = plan_api_plan_editable((int) $item['plan_id']);
        $done = (int) ($_POST['done'] ?? 1) === 1;
        if ($done) {
            DB::execute("UPDATE plan_plan_items SET status = 'done', progress = 100, completed_at = NOW() WHERE id = ?", [$item['id']]);
        } else {
            DB::execute("UPDATE plan_plan_items SET status = 'in_progress', completed_at = NULL, progress = LEAST(progress, 99) WHERE id = ?", [$item['id']]);
        }
        DB::execute('UPDATE plan_plans SET updated_by = ? WHERE id = ?', [$uid, $plan['id']]);
        plan_api_plan_response((int) $plan['id']);

    case 'item_delete':
        $item = plan_api_item((int) ($_POST['id'] ?? 0));
        $plan = plan_api_plan_editable((int) $item['plan_id']);
        DB::execute('DELETE FROM plan_plan_items WHERE id = ?', [$item['id']]); // filhos via CASCADE
        Audit::log('planejamento.plan_item_delete', 'plan_plan_items', (string) $item['id'], ['plan_id' => $plan['id'], 'title' => $item['title']]);
        plan_api_plan_response((int) $plan['id']);

    case 'item_move':
        $item = plan_api_item((int) ($_POST['id'] ?? 0));
        $plan = plan_api_plan_editable((int) $item['plan_id']);
        $dir  = ($_POST['dir'] ?? 'down') === 'up' ? 'up' : 'down';
        $siblings = DB::query(
            'SELECT id FROM plan_plan_items WHERE plan_id = ? AND ' . ($item['parent_id'] ? 'parent_id = ?' : 'parent_id IS NULL') . ' ORDER BY sort_order, id',
            $item['parent_id'] ? [$plan['id'], $item['parent_id']] : [$plan['id']]
        );
        $ids = array_map('intval', array_column($siblings, 'id'));
        $pos = array_search((int) $item['id'], $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if (isset($ids[$swap])) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            }
            foreach ($ids as $i => $id) {
                DB::execute('UPDATE plan_plan_items SET sort_order = ? WHERE id = ?', [$i, $id]);
            }
        }
        plan_api_plan_response((int) $plan['id']);

    case 'item_reorder':
        $plan     = plan_api_plan_editable((int) ($_POST['plan_id'] ?? 0));
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        $ids      = plan_id_list($_POST['ids'] ?? []);
        foreach ($ids as $i => $id) {
            DB::execute(
                'UPDATE plan_plan_items SET sort_order = ? WHERE id = ? AND plan_id = ? AND ' . ($parentId ? 'parent_id = ?' : 'parent_id IS NULL'),
                $parentId ? [$i, $id, $plan['id'], $parentId] : [$i, $id, $plan['id']]
            );
        }
        plan_api_plan_response((int) $plan['id']);

    // =====================================================================
    // QUADROS — estado, cartões, colunas, comentários
    // =====================================================================
    case 'board_state':
        $board = plan_api_board((int) ($_GET['board_id'] ?? 0));
        plan_api_board_response((int) $board['id']);

    case 'card_get':
        $card  = plan_api_card((int) ($_GET['id'] ?? 0));
        $board = plan_api_board((int) $card['board_id']);
        $row   = DB::queryOne(
            'SELECT c.*, u.name AS assignee_name, i.title AS plan_item_title FROM plan_board_cards c
             LEFT JOIN users u ON u.id = c.assignee_id LEFT JOIN plan_plan_items i ON i.id = c.plan_item_id WHERE c.id = ?',
            [$card['id']]
        );
        $comments = array_map(fn ($c) => [
            'id'         => (int) $c['id'],
            'user_name'  => (string) ($c['user_name'] ?? '—'),
            'body'       => (string) $c['body'],
            'created_at' => plan_datetime_br($c['created_at']),
        ], DB::query(
            'SELECT c.*, u.name AS user_name FROM plan_card_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.card_id = ? ORDER BY c.created_at, c.id',
            [$card['id']]
        ));
        plan_json_response(['ok' => true, 'card' => plan_card_to_array($row), 'comments' => $comments, 'creator' => plan_user_name((int) ($card['created_by'] ?? 0))]);

    case 'card_save':
        $id      = (int) ($_POST['id'] ?? 0);
        $current = $id ? plan_api_card($id) : null;
        $board   = plan_api_board((int) ($current['board_id'] ?? (int) ($_POST['board_id'] ?? 0)), 'boards.cards');
        $title   = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            plan_api_error('Informe o título do cartão.');
        }
        $columnId = (int) ($_POST['column_id'] ?? ($current['column_id'] ?? 0));
        $column   = DB::queryOne('SELECT * FROM plan_board_columns WHERE id = ? AND board_id = ?', [$columnId, $board['id']]);
        if (!$column && $current) {
            // coluna inexistente/estranha ao quadro: mantém a coluna atual do
            // cartão em vez de movê-lo silenciosamente para a primeira.
            $column = DB::queryOne('SELECT * FROM plan_board_columns WHERE id = ? AND board_id = ?', [(int) $current['column_id'], $board['id']]);
        }
        if (!$column) {
            $column = DB::queryOne('SELECT * FROM plan_board_columns WHERE board_id = ? ORDER BY sort_order, id LIMIT 1', [$board['id']]);
            if (!$column) {
                plan_api_error('O quadro não tem colunas. Configure o quadro primeiro.');
            }
        }
        $assignee = (int) ($_POST['assignee_id'] ?? 0) ?: null;
        if ($assignee && !DB::queryOne('SELECT id FROM users WHERE id = ? AND active = 1', [$assignee])) {
            $assignee = null;
        }
        $priority = in_array($_POST['priority'] ?? '', array_keys(plan_card_priorities()), true) ? $_POST['priority'] : 'medium';
        $points   = trim((string) ($_POST['points'] ?? ''));
        $points   = $points !== '' && !empty($board['use_points']) ? max(0, min(999, (int) $points)) : null;
        $labels   = plan_labels_list($_POST['labels'] ?? '');
        // O vínculo com a ação do plano só pode ser definido por quem enxerga
        // planos; sem essa permissão o vínculo existente é preservado.
        if (core_can('plans.view')) {
            $planItem = (int) ($_POST['plan_item_id'] ?? 0) ?: null;
            if ($planItem && !DB::queryOne('SELECT i.id FROM plan_plan_items i JOIN plan_plans p ON p.id = i.plan_id WHERE i.id = ? AND p.deleted_at IS NULL', [$planItem])) {
                $planItem = null;
            }
        } else {
            $planItem = $current && $current['plan_item_id'] !== null ? (int) $current['plan_item_id'] : null;
        }
        $checklist = [];
        foreach (plan_json_decode((string) ($_POST['checklist'] ?? '')) as $ck) {
            $text = trim((string) (is_array($ck) ? ($ck['text'] ?? '') : $ck));
            if ($text !== '') {
                $checklist[] = ['text' => mb_substr($text, 0, 200), 'done' => !empty($ck['done']) ? 1 : 0];
            }
            if (count($checklist) >= 50) {
                break;
            }
        }
        $params = [
            (int) $column['id'], mb_substr($title, 0, 300), trim((string) ($_POST['description'] ?? '')) ?: null,
            $assignee, $priority, $points, $labels ? implode(',', $labels) : null, plan_color_or_null($_POST['color'] ?? ''),
            plan_date_or_null($_POST['due_date'] ?? ''), $checklist ? json_encode($checklist, JSON_UNESCAPED_UNICODE) : null, $planItem,
        ];
        if ($current) {
            $completed = !empty($column['is_done']) ? ($current['completed_at'] ?: date('Y-m-d H:i:s')) : null;
            DB::execute(
                'UPDATE plan_board_cards SET column_id = ?, title = ?, description = ?, assignee_id = ?, priority = ?, points = ?,
                        labels = ?, color = ?, due_date = ?, checklist = ?, plan_item_id = ?, completed_at = ? WHERE id = ?',
                array_merge(array_slice($params, 0, 11), [$completed, (int) $current['id']])
            );
            $cardId = (int) $current['id'];
            if ((int) $current['column_id'] !== (int) $column['id']) {
                plan_api_sync_linked_item(['plan_item_id' => $planItem], !empty($column['is_done']));
            }
        } else {
            $max = DB::queryOne('SELECT COALESCE(MAX(sort_order), -1) AS m FROM plan_board_cards WHERE column_id = ?', [$column['id']]);
            DB::execute(
                'INSERT INTO plan_board_cards (board_id, column_id, title, description, assignee_id, priority, points, labels, color,
                        due_date, checklist, plan_item_id, sort_order, created_by, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([(int) $board['id']], $params, [(int) ($max['m'] ?? -1) + 1, $uid, !empty($column['is_done']) ? date('Y-m-d H:i:s') : null])
            );
            $cardId = DB::lastId();
            Audit::log('planejamento.card_create', 'plan_board_cards', (string) $cardId, ['board_id' => $board['id'], 'title' => $title]);
        }
        if ($assignee && $assignee !== $uid && $assignee !== (int) ($current['assignee_id'] ?? 0)) {
            Notifications::add($assignee, 'Cartão atribuído a você', mb_substr($title, 0, 200) . ' — ' . $board['name'],
                plan_url('boards', ['action' => 'view', 'id' => $board['id'], 'card' => $cardId]), 'info', 'planejamento');
        }
        DB::execute('UPDATE plan_boards SET updated_at = NOW() WHERE id = ?', [$board['id']]);
        plan_api_board_response((int) $board['id'], ['id' => $cardId]);

    case 'card_move':
        $card   = plan_api_card((int) ($_POST['id'] ?? 0));
        $board  = plan_api_board((int) $card['board_id'], 'boards.cards');
        $column = DB::queryOne('SELECT * FROM plan_board_columns WHERE id = ? AND board_id = ?', [(int) ($_POST['column_id'] ?? 0), $board['id']]);
        if (!$column) {
            plan_api_error('Coluna inválida.');
        }
        $ids = plan_id_list($_POST['ids'] ?? []);
        if (!in_array((int) $card['id'], $ids, true)) {
            $ids[] = (int) $card['id'];
        }
        $wasDone = DB::queryOne('SELECT is_done FROM plan_board_columns WHERE id = ?', [$card['column_id']]);
        $nowDone = !empty($column['is_done']);
        DB::transaction(function () use ($card, $column, $ids, $board, $nowDone) {
            DB::execute(
                'UPDATE plan_board_cards SET column_id = ?, completed_at = ' . ($nowDone ? 'COALESCE(completed_at, NOW())' : 'NULL') . ' WHERE id = ? AND board_id = ?',
                [(int) $column['id'], (int) $card['id'], (int) $board['id']]
            );
            foreach ($ids as $i => $id) {
                DB::execute('UPDATE plan_board_cards SET sort_order = ? WHERE id = ? AND board_id = ? AND column_id = ?', [$i, $id, (int) $board['id'], (int) $column['id']]);
            }
            DB::execute('UPDATE plan_boards SET updated_at = NOW() WHERE id = ?', [(int) $board['id']]);
        });
        if ((int) $card['column_id'] !== (int) $column['id'] && !empty($wasDone['is_done']) !== $nowDone) {
            plan_api_sync_linked_item($card, $nowDone);
        }
        $count   = (int) (DB::queryOne('SELECT COUNT(*) AS n FROM plan_board_cards WHERE column_id = ?', [$column['id']])['n'] ?? 0);
        $warning = (int) $column['wip_limit'] > 0 && $count > (int) $column['wip_limit']
            ? 'Limite WIP da coluna "' . $column['name'] . '" excedido (' . $count . '/' . (int) $column['wip_limit'] . ').'
            : null;
        plan_api_board_response((int) $board['id'], ['warning' => $warning]);

    case 'card_delete':
        $card  = plan_api_card((int) ($_POST['id'] ?? 0));
        $board = plan_api_board((int) $card['board_id'], 'boards.cards');
        DB::execute('DELETE FROM plan_board_cards WHERE id = ?', [$card['id']]);
        Audit::log('planejamento.card_delete', 'plan_board_cards', (string) $card['id'], ['board_id' => $board['id'], 'title' => $card['title']]);
        plan_api_board_response((int) $board['id']);

    case 'checklist_toggle':
        $card  = plan_api_card((int) ($_POST['id'] ?? 0));
        $board = plan_api_board((int) $card['board_id'], 'boards.cards');
        $list  = plan_json_decode($card['checklist'] ?? null);
        $idx   = (int) ($_POST['index'] ?? -1);
        if (isset($list[$idx]) && is_array($list[$idx])) {
            $list[$idx]['done'] = (int) ($_POST['done'] ?? 0) === 1 ? 1 : 0;
            DB::execute('UPDATE plan_board_cards SET checklist = ? WHERE id = ?', [json_encode(array_values($list), JSON_UNESCAPED_UNICODE), $card['id']]);
        }
        plan_api_board_response((int) $board['id']);

    case 'comment_add':
        $card  = plan_api_card((int) ($_POST['card_id'] ?? 0));
        $board = plan_api_board((int) $card['board_id'], 'boards.cards');
        $body  = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            plan_api_error('Escreva o comentário.');
        }
        DB::execute('INSERT INTO plan_card_comments (card_id, user_id, body) VALUES (?, ?, ?)', [$card['id'], $uid, mb_substr($body, 0, 4000)]);
        if (!empty($card['assignee_id']) && (int) $card['assignee_id'] !== $uid) {
            Notifications::add((int) $card['assignee_id'], 'Novo comentário no cartão', mb_substr((string) $card['title'], 0, 200),
                plan_url('boards', ['action' => 'view', 'id' => $board['id'], 'card' => $card['id']]), 'info', 'planejamento');
        }
        $comments = array_map(fn ($c) => [
            'id' => (int) $c['id'], 'user_name' => (string) ($c['user_name'] ?? '—'), 'body' => (string) $c['body'], 'created_at' => plan_datetime_br($c['created_at']),
        ], DB::query('SELECT c.*, u.name AS user_name FROM plan_card_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.card_id = ? ORDER BY c.created_at, c.id', [$card['id']]));
        plan_json_response(['ok' => true, 'comments' => $comments]);

    case 'column_add':
        $board = plan_api_board((int) ($_POST['board_id'] ?? 0), 'boards.edit');
        $name  = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            plan_api_error('Informe o nome da coluna.');
        }
        $max = DB::queryOne('SELECT COALESCE(MAX(sort_order), -1) AS m FROM plan_board_columns WHERE board_id = ?', [$board['id']]);
        DB::execute(
            'INSERT INTO plan_board_columns (board_id, name, color, wip_limit, is_done, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$board['id'], mb_substr($name, 0, 100), plan_color_or_null($_POST['color'] ?? ''), max(0, min(999, (int) ($_POST['wip_limit'] ?? 0))), !empty($_POST['is_done']) ? 1 : 0, (int) ($max['m'] ?? -1) + 1]
        );
        plan_api_board_response((int) $board['id'], ['id' => DB::lastId()]);

    case 'column_update':
        $col = DB::queryOne('SELECT * FROM plan_board_columns WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if (!$col) {
            plan_api_error('Coluna não encontrada.', 404);
        }
        $board = plan_api_board((int) $col['board_id'], 'boards.edit');
        $name  = trim((string) ($_POST['name'] ?? $col['name']));
        if ($name === '') {
            plan_api_error('Informe o nome da coluna.');
        }
        $isDone = array_key_exists('is_done', $_POST) ? (!empty($_POST['is_done']) ? 1 : 0) : (int) $col['is_done'];
        DB::execute(
            'UPDATE plan_board_columns SET name = ?, color = ?, wip_limit = ?, is_done = ? WHERE id = ?',
            [mb_substr($name, 0, 100), array_key_exists('color', $_POST) ? plan_color_or_null($_POST['color']) : $col['color'],
             array_key_exists('wip_limit', $_POST) ? max(0, min(999, (int) $_POST['wip_limit'])) : (int) $col['wip_limit'], $isDone, $col['id']]
        );
        if ($isDone !== (int) $col['is_done']) {
            DB::execute('UPDATE plan_board_cards SET completed_at = ' . ($isDone ? 'COALESCE(completed_at, NOW())' : 'NULL') . ' WHERE column_id = ?', [$col['id']]);
        }
        plan_api_board_response((int) $board['id']);

    case 'column_delete':
        $col = DB::queryOne('SELECT * FROM plan_board_columns WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if (!$col) {
            plan_api_error('Coluna não encontrada.', 404);
        }
        $board = plan_api_board((int) $col['board_id'], 'boards.edit');
        $count = (int) (DB::queryOne('SELECT COUNT(*) AS n FROM plan_board_cards WHERE column_id = ?', [$col['id']])['n'] ?? 0);
        $moveTo = (int) ($_POST['move_to'] ?? 0);
        if ($count > 0) {
            $target = $moveTo ? DB::queryOne('SELECT * FROM plan_board_columns WHERE id = ? AND board_id = ? AND id <> ?', [$moveTo, $board['id'], $col['id']]) : null;
            if (!$target) {
                plan_api_error('A coluna tem ' . $count . ' cartão(ões). Escolha a coluna de destino para movê-los.', 409);
            }
            DB::execute('UPDATE plan_board_cards SET column_id = ?, completed_at = ' . (!empty($target['is_done']) ? 'COALESCE(completed_at, NOW())' : 'NULL') . ' WHERE column_id = ?', [$target['id'], $col['id']]);
        }
        DB::execute('DELETE FROM plan_board_columns WHERE id = ?', [$col['id']]);
        plan_api_board_response((int) $board['id']);

    case 'column_reorder':
        $board = plan_api_board((int) ($_POST['board_id'] ?? 0), 'boards.edit');
        $ids   = plan_id_list($_POST['ids'] ?? []);
        foreach ($ids as $i => $id) {
            DB::execute('UPDATE plan_board_columns SET sort_order = ? WHERE id = ? AND board_id = ?', [$i, $id, $board['id']]);
        }
        plan_api_board_response((int) $board['id']);

    default:
        plan_api_error('Ação inválida.', 404);
}
