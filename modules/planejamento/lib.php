<?php
/**
 * MÓDULO PLANEJAMENTO — funções auxiliares (prefixo plan_).
 *
 * Helpers genéricos (usados também pela página de diagramas):
 *   plan_url(), plan_json_decode(), plan_user_name(), plan_users_active(),
 *   plan_page(), plan_flash(), plan_can_see_private(), plan_json_response(),
 *   plan_date_br(), plan_datetime_br(), plan_find_template().
 * Helpers de planos/quadros: plan_find_plan(), plan_plan_items(),
 *   plan_item_tree(), plan_recalc_progress(), plan_instantiate_items(),
 *   plan_find_board(), plan_board_can_view(), plan_board_columns(), ...
 */

declare(strict_types=1);

use Core\DB;

// ---------------------------------------------------------------------------
// Genéricos
// ---------------------------------------------------------------------------

/** URL de uma página do módulo. */
function plan_url(string $page, array $params = []): string
{
    return core_module_url('planejamento', array_merge(['page' => $page], $params));
}

/** Decodifica JSON para array (array vazio quando inválido/nulo). */
function plan_json_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/** Nome do usuário (cache por requisição). */
function plan_user_name(?int $id): string
{
    static $cache = [];
    if (!$id) {
        return '';
    }
    if (!array_key_exists($id, $cache)) {
        $row = DB::queryOne('SELECT name FROM users WHERE id = ?', [$id]);
        $cache[$id] = (string) ($row['name'] ?? '');
    }
    return $cache[$id];
}

/** Usuários ativos (id, name) para selects. @return array<int, array{id:int,name:string}> */
function plan_users_active(): array
{
    static $users = null;
    if ($users === null) {
        $users = array_map(
            fn (array $u) => ['id' => (int) $u['id'], 'name' => (string) $u['name']],
            DB::query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')
        );
    }
    return $users;
}

/** Wrapper de Core\Layout::render que injeta o CSS do módulo. */
function plan_page(array $opts): void
{
    $opts['head']    = '<link rel="stylesheet" href="' . core_asset('planejamento/style.css') . '">' . ($opts['head'] ?? '');
    $opts['content'] = (string) ($opts['content'] ?? '');
    Core\Layout::render($opts);
}

/** Atalho para mensagens flash (success|error|warning|info). */
function plan_flash(string $type, string $message): void
{
    Core\Flash::set($type, $message);
}

/**
 * O usuário logado pode ver um registro privado? Autor, membros informados
 * ou quem tem a permissão "<recurso>.delete" (gestor) do módulo.
 */
function plan_can_see_private(int $createdBy, string $resource = 'diagrams', array $memberIds = []): bool
{
    $uid = (int) core_user_id();
    if ($uid <= 0) {
        return false;
    }
    if ($uid === $createdBy || in_array($uid, array_map('intval', $memberIds), true)) {
        return true;
    }
    return core_can($resource . '.delete');
}

/** Resposta JSON e fim da requisição. */
function plan_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Erro JSON padronizado. */
function plan_api_error(string $message, int $status = 400): never
{
    plan_json_response(['ok' => false, 'error' => $message], $status);
}

/** Data (Y-m-d) → dd/mm/aaaa. */
function plan_date_br(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '';
}

/** Data e hora → dd/mm/aaaa HH:ii. */
function plan_datetime_br(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '';
}

/** Valida data de formulário (Y-m-d) → string|null. */
function plan_date_or_null(mixed $value): ?string
{
    $v = trim((string) $value);
    if ($v === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** Cor hexadecimal (#rrggbb) ou null. */
function plan_color_or_null(mixed $value): ?string
{
    $v = strtolower(trim((string) $value));
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

/** Normaliza lista de etiquetas (texto separado por vírgula ou array) → array de strings únicas. */
function plan_labels_list(mixed $value): array
{
    $parts = is_array($value) ? $value : explode(',', (string) $value);
    $out   = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '' && mb_strlen($p) <= 40 && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return array_slice($out, 0, 30);
}

// ---------------------------------------------------------------------------
// Catálogos de rótulos
// ---------------------------------------------------------------------------

function plan_item_kinds(): array
{
    return ['objective' => 'Objetivo', 'goal' => 'Meta', 'action' => 'Ação', 'task' => 'Tarefa'];
}

function plan_item_statuses(): array
{
    return ['pending' => 'Pendente', 'in_progress' => 'Em andamento', 'done' => 'Concluído', 'cancelled' => 'Cancelado'];
}

function plan_priorities(): array
{
    return ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta'];
}

function plan_card_priorities(): array
{
    return ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'urgent' => 'Urgente'];
}

function plan_plan_kinds(): array
{
    return [
        'work_plan'   => 'Plano de trabalho',
        'strategic'   => 'Planejamento estratégico',
        'operational' => 'Plano operacional',
        'project'     => 'Projeto',
        'pdca'        => 'Ciclo PDCA',
        'other'       => 'Outro',
    ];
}

function plan_plan_statuses(): array
{
    return ['draft' => 'Rascunho', 'active' => 'Ativo', 'completed' => 'Concluído', 'archived' => 'Arquivado'];
}

function plan_board_kinds(): array
{
    return ['kanban' => 'Kanban', 'scrum' => 'Scrum', 'custom' => 'Personalizado'];
}

function plan_template_kinds(): array
{
    return ['plan' => 'Plano', 'board' => 'Quadro', 'diagram' => 'Diagrama'];
}

/** Badge Bootstrap para status de item/plano. */
function plan_status_badge(string $status): string
{
    $map = [
        'pending' => 'secondary', 'in_progress' => 'primary', 'done' => 'success', 'cancelled' => 'dark',
        'draft' => 'secondary', 'active' => 'primary', 'completed' => 'success', 'archived' => 'dark',
    ];
    $labels = plan_item_statuses() + plan_plan_statuses();
    $cls    = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . core_e($labels[$status] ?? $status) . '</span>';
}

function plan_priority_badge(string $priority): string
{
    $map    = ['low' => 'light border', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
    $labels = plan_card_priorities();
    return '<span class="badge text-bg-' . ($map[$priority] ?? 'light border') . '">' . core_e($labels[$priority] ?? $priority) . '</span>';
}

// ---------------------------------------------------------------------------
// Modelos
// ---------------------------------------------------------------------------

function plan_find_template(int $id): ?array
{
    return $id > 0 ? DB::queryOne('SELECT * FROM plan_templates WHERE id = ?', [$id]) : null;
}

/** Modelos ativos de um tipo. */
function plan_templates_active(string $kind): array
{
    return DB::query(
        'SELECT * FROM plan_templates WHERE kind = ? AND active = 1 ORDER BY sort_order, name',
        [$kind]
    );
}

/** Normaliza a árvore de itens de um modelo de plano (profundidade máx. 4). */
function plan_normalize_template_items(array $items, int $depth = 0): array
{
    $kinds = array_keys(plan_item_kinds());
    $out   = [];
    if ($depth > 3) {
        return $out;
    }
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $title = trim((string) ($it['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $kind = (string) ($it['kind'] ?? '');
        if (!in_array($kind, $kinds, true)) {
            $kind = $kinds[min($depth, 3)];
        }
        $node = ['kind' => $kind, 'title' => mb_substr($title, 0, 300)];
        foreach (['description', 'how_text', 'where_text', 'indicator', 'responsible_name'] as $f) {
            $v = trim((string) ($it[$f] ?? ''));
            if ($v !== '') {
                $node[$f] = $f === 'where_text' ? mb_substr($v, 0, 200) : mb_substr($v, 0, 2000);
            }
        }
        if (isset($it['priority']) && in_array($it['priority'], array_keys(plan_priorities()), true)) {
            $node['priority'] = $it['priority'];
        }
        if (isset($it['cost']) && is_numeric($it['cost'])) {
            $node['cost'] = round((float) $it['cost'], 2);
        }
        if (!empty($it['children']) && is_array($it['children'])) {
            $children = plan_normalize_template_items($it['children'], $depth + 1);
            if ($children) {
                $node['children'] = $children;
            }
        }
        $out[] = $node;
        if (count($out) >= 200) {
            break;
        }
    }
    return $out;
}

/** Normaliza o JSON de um modelo de quadro. */
function plan_normalize_board_template(array $data): array
{
    $columns = [];
    foreach ((array) ($data['columns'] ?? []) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $name = trim((string) ($c['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $col = ['name' => mb_substr($name, 0, 100)];
        if ($color = plan_color_or_null($c['color'] ?? '')) {
            $col['color'] = $color;
        }
        $wip = (int) ($c['wip_limit'] ?? 0);
        if ($wip > 0) {
            $col['wip_limit'] = min($wip, 999);
        }
        if (!empty($c['is_done'])) {
            $col['is_done'] = 1;
        }
        $columns[] = $col;
        if (count($columns) >= 20) {
            break;
        }
    }
    $out = [
        'kind'    => in_array($data['kind'] ?? '', array_keys(plan_board_kinds()), true) ? $data['kind'] : 'kanban',
        'columns' => $columns,
        'labels'  => plan_labels_list($data['labels'] ?? []),
        'points'  => !empty($data['points']),
    ];
    $sprint = (int) ($data['sprint_days'] ?? 0);
    if ($sprint > 0) {
        $out['sprint_days'] = min($sprint, 365);
    }
    return $out;
}

/** Carrega lib/diagram.php (do agente de diagramas) se existir; true quando plan_diagram_svg() está disponível. */
function plan_diagram_lib(): bool
{
    static $loaded = null;
    if ($loaded === null) {
        $file = __DIR__ . '/lib/diagram.php';
        if (!function_exists('plan_diagram_svg') && is_file($file)) {
            try {
                require_once $file;
            } catch (\Throwable $e) {
                // biblioteca indisponível: segue sem miniaturas
            }
        }
        $loaded = function_exists('plan_diagram_svg');
    }
    return $loaded;
}

/** SVG estático de um diagrama (via lib/diagram.php do agente de diagramas, se existir) ou null. */
function plan_diagram_thumb(array $data, array $opts = []): ?string
{
    if (!plan_diagram_lib()) {
        return null;
    }
    try {
        $svg = plan_diagram_svg($data, $opts);
        return is_string($svg) && $svg !== '' ? $svg : null;
    } catch (\Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Planos de trabalho
// ---------------------------------------------------------------------------

function plan_find_plan(int $id, bool $withDeleted = false): ?array
{
    if ($id <= 0) {
        return null;
    }
    $sql = 'SELECT p.*, o.name AS owner_name, c.name AS creator_name
            FROM plan_plans p
            LEFT JOIN users o ON o.id = p.owner_id
            LEFT JOIN users c ON c.id = p.created_by
            WHERE p.id = ?' . ($withDeleted ? '' : ' AND p.deleted_at IS NULL');
    return DB::queryOne($sql, [$id]);
}

/** Itens do plano (lista plana, ordenada por sort_order). */
function plan_plan_items(int $planId): array
{
    return DB::query(
        'SELECT i.*, u.name AS responsible_user_name
         FROM plan_plan_items i LEFT JOIN users u ON u.id = i.responsible_id
         WHERE i.plan_id = ? ORDER BY i.sort_order, i.id',
        [$planId]
    );
}

/** Converte a linha do item para o formato da API/JS. */
function plan_item_to_array(array $r): array
{
    $today   = date('Y-m-d');
    $open    = !in_array($r['status'], ['done', 'cancelled'], true);
    $respLbl = $r['responsible_id'] ? (string) ($r['responsible_user_name'] ?? plan_user_name((int) $r['responsible_id'])) : (string) ($r['responsible_name'] ?? '');
    return [
        'id'               => (int) $r['id'],
        'plan_id'          => (int) $r['plan_id'],
        'parent_id'        => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
        'kind'             => (string) $r['kind'],
        'title'            => (string) $r['title'],
        'description'      => (string) ($r['description'] ?? ''),
        'where_text'       => (string) ($r['where_text'] ?? ''),
        'how_text'         => (string) ($r['how_text'] ?? ''),
        'cost'             => $r['cost'] !== null ? (float) $r['cost'] : null,
        'responsible_id'   => $r['responsible_id'] !== null ? (int) $r['responsible_id'] : null,
        'responsible_name' => (string) ($r['responsible_name'] ?? ''),
        'responsible_label' => $respLbl,
        'start_date'       => $r['start_date'] ?: null,
        'due_date'         => $r['due_date'] ?: null,
        'status'           => (string) $r['status'],
        'priority'         => (string) $r['priority'],
        'progress'         => (int) $r['progress'],
        'indicator'        => (string) ($r['indicator'] ?? ''),
        'sort_order'       => (int) $r['sort_order'],
        'completed_at'     => $r['completed_at'] ?: null,
        'overdue'          => $open && !empty($r['due_date']) && $r['due_date'] < $today,
    ];
}

/** Lista plana → árvore aninhada (chave 'children'). */
function plan_item_tree(array $flat): array
{
    $byParent = [];
    foreach ($flat as $r) {
        $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
    }
    $build = function (int $parent) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $r) {
            $r['children'] = $build((int) $r['id']);
            $out[]         = $r;
        }
        return $out;
    };
    return $build(0);
}

/** Lista plana em ordem de árvore (pré-ordem) com 'depth'. */
function plan_items_flat_ordered(array $flat): array
{
    $tree = plan_item_tree($flat);
    $out  = [];
    $walk = function (array $nodes, int $depth) use (&$walk, &$out): void {
        foreach ($nodes as $n) {
            $children  = $n['children'] ?? [];
            unset($n['children']);
            $n['depth'] = $depth;
            $out[]      = $n;
            $walk($children, $depth + 1);
        }
    };
    $walk($tree, 0);
    return $out;
}

/**
 * Recalcula o progresso do plano: média das ações ponderada pela prioridade
 * (baixa=1, média=2, alta=3). Ação com tarefas → média das tarefas; item
 * concluído = 100; cancelado não conta. Também grava o progresso calculado
 * nos itens com filhos (objetivos/metas/ações com tarefas).
 */
function plan_recalc_progress(int $planId): int
{
    $rows     = DB::query('SELECT id, parent_id, kind, status, priority, progress FROM plan_plan_items WHERE plan_id = ? ORDER BY sort_order, id', [$planId]);
    $byId     = [];
    $children = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']]                        = $r;
        $children[(int) ($r['parent_id'] ?? 0)][]    = (int) $r['id'];
    }
    $weights = ['low' => 1, 'medium' => 2, 'high' => 3];
    $eff     = [];
    $calc    = function (int $id) use (&$calc, &$eff, $byId, $children, $weights): ?float {
        $r = $byId[$id];
        if ($r['status'] === 'cancelled') {
            return null;
        }
        if ($r['status'] === 'done') {
            return 100.0;
        }
        $kids = $children[$id] ?? [];
        if (!$kids) {
            return (float) $r['progress'];
        }
        $sum = 0.0;
        $w   = 0;
        foreach ($kids as $k) {
            $v = $calc($k);
            if ($v === null) {
                continue;
            }
            $wt   = $weights[$byId[$k]['priority']] ?? 2;
            $sum += $v * $wt;
            $w   += $wt;
        }
        $val      = $w > 0 ? $sum / $w : (float) $r['progress'];
        $eff[$id] = $val;
        return $val;
    };

    $sum = 0.0;
    $w   = 0;
    $actions = array_filter($byId, fn ($r) => $r['kind'] === 'action');
    $pool    = $actions ?: array_filter($byId, fn ($r) => (int) ($r['parent_id'] ?? 0) === 0);
    foreach ($pool as $id => $r) {
        $v = $calc((int) $id);
        if ($v === null) {
            continue;
        }
        $wt   = $weights[$r['priority']] ?? 2;
        $sum += $v * $wt;
        $w   += $wt;
    }
    // garante que todos os contêineres tenham progresso calculado
    foreach (array_keys($byId) as $id) {
        if (!empty($children[$id]) && !array_key_exists($id, $eff)) {
            $calc($id);
        }
    }
    $progress = $w > 0 ? (int) round($sum / $w) : 0;
    $progress = max(0, min(100, $progress));

    foreach ($eff as $id => $val) {
        $r = $byId[$id];
        if (!empty($children[$id]) && !in_array($r['status'], ['done', 'cancelled'], true)) {
            $p = (int) round($val);
            if ($p !== (int) $r['progress']) {
                DB::execute('UPDATE plan_plan_items SET progress = ? WHERE id = ?', [$p, $id]);
            }
        }
    }
    DB::execute('UPDATE plan_plans SET progress = ? WHERE id = ?', [$progress, $planId]);
    return $progress;
}

/** Instancia a árvore de itens de um modelo no plano. Retorna nº de itens criados. */
function plan_instantiate_items(int $planId, array $items, ?int $parentId = null): int
{
    $n     = 0;
    $order = 0;
    $kinds = array_keys(plan_item_kinds());
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $title = trim((string) ($it['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $kind = in_array($it['kind'] ?? '', $kinds, true) ? $it['kind'] : ($parentId ? 'action' : 'objective');
        $prio = in_array($it['priority'] ?? '', array_keys(plan_priorities()), true) ? $it['priority'] : 'medium';
        DB::execute(
            'INSERT INTO plan_plan_items (plan_id, parent_id, kind, title, description, where_text, how_text, cost,
                    responsible_name, indicator, priority, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $planId, $parentId, $kind, mb_substr($title, 0, 300),
                ($it['description'] ?? '') !== '' ? (string) $it['description'] : null,
                ($it['where_text'] ?? '') !== '' ? mb_substr((string) $it['where_text'], 0, 200) : null,
                ($it['how_text'] ?? '') !== '' ? (string) $it['how_text'] : null,
                isset($it['cost']) && is_numeric($it['cost']) ? round((float) $it['cost'], 2) : null,
                ($it['responsible_name'] ?? '') !== '' ? mb_substr((string) $it['responsible_name'], 0, 150) : null,
                ($it['indicator'] ?? '') !== '' ? mb_substr((string) $it['indicator'], 0, 255) : null,
                $prio, $order++,
            ]
        );
        $id = DB::lastId();
        $n++;
        if (!empty($it['children']) && is_array($it['children'])) {
            $n += plan_instantiate_items($planId, $it['children'], $id);
        }
    }
    return $n;
}

/** Ações (e tarefas) de planos ativos para vincular a cartões. */
function plan_linkable_items(?int $planId = null): array
{
    $sql = "SELECT i.id, i.title, i.kind, i.status, p.id AS plan_id, p.title AS plan_title
            FROM plan_plan_items i JOIN plan_plans p ON p.id = i.plan_id
            WHERE p.deleted_at IS NULL AND i.kind IN ('action','task')"
        . ($planId ? ' AND p.id = ?' : " AND p.status IN ('draft','active')")
        . ' ORDER BY p.title, i.sort_order, i.id LIMIT 400';
    return DB::query($sql, $planId ? [$planId] : []);
}

// ---------------------------------------------------------------------------
// Quadros
// ---------------------------------------------------------------------------

function plan_find_board(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    return DB::queryOne(
        'SELECT b.*, u.name AS creator_name, p.title AS plan_title
         FROM plan_boards b LEFT JOIN users u ON u.id = b.created_by LEFT JOIN plan_plans p ON p.id = b.plan_id
         WHERE b.id = ?',
        [$id]
    );
}

/** IDs dos membros do quadro. @return int[] */
function plan_board_member_ids(int $boardId): array
{
    return array_map('intval', array_column(DB::query('SELECT user_id FROM plan_board_members WHERE board_id = ?', [$boardId]), 'user_id'));
}

/** Membros do quadro com nome. */
function plan_board_members(int $boardId): array
{
    return DB::query(
        'SELECT m.user_id, m.role, u.name FROM plan_board_members m JOIN users u ON u.id = m.user_id WHERE m.board_id = ? ORDER BY u.name',
        [$boardId]
    );
}

/** O usuário logado pode ver o quadro (público, ou privado sendo autor/membro/gestor). */
function plan_board_can_view(array $board): bool
{
    if (!core_can('boards.view')) {
        return false;
    }
    if (empty($board['is_private'])) {
        return true;
    }
    return plan_can_see_private((int) ($board['created_by'] ?? 0), 'boards', plan_board_member_ids((int) $board['id']));
}

/** O usuário pode configurar o quadro (boards.edit e acesso ao quadro). */
function plan_board_can_manage(array $board): bool
{
    return core_can('boards.edit') && plan_board_can_view($board);
}

function plan_board_columns(int $boardId): array
{
    return DB::query('SELECT * FROM plan_board_columns WHERE board_id = ? ORDER BY sort_order, id', [$boardId]);
}

function plan_board_cards(int $boardId): array
{
    return DB::query(
        'SELECT c.*, u.name AS assignee_name, i.title AS plan_item_title,
                (SELECT COUNT(*) FROM plan_card_comments cc WHERE cc.card_id = c.id) AS comments_count
         FROM plan_board_cards c
         LEFT JOIN users u ON u.id = c.assignee_id
         LEFT JOIN plan_plan_items i ON i.id = c.plan_item_id
         WHERE c.board_id = ? ORDER BY c.sort_order, c.id',
        [$boardId]
    );
}

function plan_card_to_array(array $c): array
{
    $checklist = [];
    foreach (plan_json_decode($c['checklist'] ?? null) as $ck) {
        if (is_array($ck) && isset($ck['text'])) {
            $checklist[] = ['text' => (string) $ck['text'], 'done' => !empty($ck['done']) ? 1 : 0];
        }
    }
    $today = date('Y-m-d');
    return [
        'id'              => (int) $c['id'],
        'board_id'        => (int) $c['board_id'],
        'column_id'       => (int) $c['column_id'],
        'title'           => (string) $c['title'],
        'description'     => (string) ($c['description'] ?? ''),
        'assignee_id'     => $c['assignee_id'] !== null ? (int) $c['assignee_id'] : null,
        'assignee_name'   => (string) ($c['assignee_name'] ?? ''),
        'priority'        => (string) $c['priority'],
        'points'          => $c['points'] !== null ? (int) $c['points'] : null,
        'labels'          => plan_labels_list($c['labels'] ?? ''),
        'color'           => $c['color'] ?: null,
        'due_date'        => $c['due_date'] ?: null,
        'checklist'       => $checklist,
        'plan_item_id'    => $c['plan_item_id'] !== null ? (int) $c['plan_item_id'] : null,
        'plan_item_title' => (string) ($c['plan_item_title'] ?? ''),
        'sort_order'      => (int) $c['sort_order'],
        'completed_at'    => $c['completed_at'] ?: null,
        'comments_count'  => (int) ($c['comments_count'] ?? 0),
        'overdue'         => empty($c['completed_at']) && !empty($c['due_date']) && $c['due_date'] < $today,
        'created_at'      => (string) $c['created_at'],
    ];
}

/** Estado completo do quadro para o JS (colunas + cartões). */
function plan_board_state(array $board): array
{
    $columns = array_map(fn ($c) => [
        'id'        => (int) $c['id'],
        'name'      => (string) $c['name'],
        'color'     => $c['color'] ?: null,
        'wip_limit' => (int) $c['wip_limit'],
        'is_done'   => (int) $c['is_done'],
        'sort_order' => (int) $c['sort_order'],
    ], plan_board_columns((int) $board['id']));
    $cards = array_map('plan_card_to_array', plan_board_cards((int) $board['id']));
    return ['columns' => $columns, 'cards' => $cards];
}

/** Cria as colunas de um quadro a partir de um modelo (ou padrão em branco). */
function plan_board_create_columns(int $boardId, array $columns): void
{
    if (!$columns) {
        $columns = [
            ['name' => 'A fazer', 'color' => '#0d6efd'],
            ['name' => 'Em andamento', 'color' => '#fd7e14'],
            ['name' => 'Concluído', 'color' => '#198754', 'is_done' => 1],
        ];
    }
    $order = 0;
    foreach ($columns as $c) {
        DB::execute(
            'INSERT INTO plan_board_columns (board_id, name, color, wip_limit, is_done, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$boardId, mb_substr((string) $c['name'], 0, 100), plan_color_or_null($c['color'] ?? ''), (int) ($c['wip_limit'] ?? 0), !empty($c['is_done']) ? 1 : 0, $order++]
        );
    }
}

/** Quadros visíveis ao usuário (aplica regra de privacidade em SQL). */
function plan_boards_visible(bool $archived = false, string $scope = 'all'): array
{
    $uid    = (int) core_user_id();
    $params = [];
    $conds  = [$archived ? 'b.archived_at IS NOT NULL' : 'b.archived_at IS NULL'];
    if (!core_can('boards.delete')) {
        $conds[]  = '(b.is_private = 0 OR b.created_by = ? OR EXISTS (SELECT 1 FROM plan_board_members m WHERE m.board_id = b.id AND m.user_id = ?))';
        $params[] = $uid;
        $params[] = $uid;
    }
    if ($scope === 'mine') {
        $conds[]  = '(b.created_by = ? OR EXISTS (SELECT 1 FROM plan_board_members m2 WHERE m2.board_id = b.id AND m2.user_id = ?))';
        $params[] = $uid;
        $params[] = $uid;
    } elseif ($scope === 'public') {
        $conds[] = 'b.is_private = 0';
    }
    return DB::query(
        'SELECT b.*, u.name AS creator_name, p.title AS plan_title,
                (SELECT COUNT(*) FROM plan_board_cards c WHERE c.board_id = b.id) AS cards_total,
                (SELECT COUNT(*) FROM plan_board_cards c JOIN plan_board_columns col ON col.id = c.column_id WHERE c.board_id = b.id AND col.is_done = 1) AS cards_done,
                (SELECT COUNT(*) FROM plan_board_members m3 WHERE m3.board_id = b.id) AS members_total
         FROM plan_boards b LEFT JOIN users u ON u.id = b.created_by LEFT JOIN plan_plans p ON p.id = b.plan_id
         WHERE ' . implode(' AND ', $conds) . ' ORDER BY b.updated_at DESC LIMIT 300',
        $params
    );
}
