<?php
/**
 * PLANEJAMENTO — modelos predefinidos (planos, quadros e diagramas).
 *   page=templates[&kind=plan|board|diagram][&inactive=1]   lista (cards)
 *   page=templates&action=create[&kind=X]                   novo modelo
 *   page=templates&action=edit&id=X                         editar
 *   POST action=save|duplicate|toggle|delete
 * Modelos is_builtin=1 podem ser editados mas não excluídos (só desativados).
 */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Flash;

core_require('templates.view');

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$uid    = (int) core_user_id();
$kinds  = plan_template_kinds();

/** Valida e normaliza o JSON do modelo conforme o tipo. Retorna [json, erro]. */
function plan_template_validate(string $kind, string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['', 'O conteúdo do modelo não é um JSON válido.'];
    }
    if ($kind === 'plan') {
        $items = plan_normalize_template_items((array) ($data['items'] ?? []));
        if (!$items) {
            return ['', 'Informe ao menos um item (objetivo/meta/ação) no modelo de plano.'];
        }
        $pk  = in_array($data['kind'] ?? '', array_keys(plan_plan_kinds()), true) ? $data['kind'] : 'work_plan';
        $out = ['kind' => $pk, 'items' => $items];
    } elseif ($kind === 'board') {
        $out = plan_normalize_board_template($data);
        if (!$out['columns']) {
            return ['', 'Informe ao menos uma coluna no modelo de quadro.'];
        }
    } else {
        if (!isset($data['nodes']) || !is_array($data['nodes'])) {
            return ['', 'O JSON do diagrama deve conter a lista "nodes" (e opcionalmente "edges" e "canvas").'];
        }
        if (plan_diagram_lib() && function_exists('plan_diagram_validate')) {
            try {
                $out = plan_diagram_validate($data);
            } catch (\Throwable $e) {
                return ['', 'JSON do diagrama inválido: ' . $e->getMessage()];
            }
        } else {
            $out = ['v' => 1, 'canvas' => is_array($data['canvas'] ?? null) ? $data['canvas'] : ['w' => 1600, 'h' => 1000, 'grid' => 20],
                    'nodes' => array_values($data['nodes']), 'edges' => array_values((array) ($data['edges'] ?? []))];
        }
    }
    return [(string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ''];
}

/** Resumo curto do conteúdo do modelo (para os cards). */
function plan_template_summary(array $t): string
{
    $data = plan_json_decode((string) $t['data']);
    if ($t['kind'] === 'plan') {
        $n = 0;
        $walk = function (array $items) use (&$walk, &$n): void {
            foreach ($items as $it) {
                if (is_array($it)) {
                    $n++;
                    $walk((array) ($it['children'] ?? []));
                }
            }
        };
        $walk((array) ($data['items'] ?? []));
        $first = array_map(fn ($i) => (string) ($i['title'] ?? ''), array_slice(array_filter((array) ($data['items'] ?? []), 'is_array'), 0, 3));
        return '<div class="small text-muted">' . $n . ' item(ns) · ' . core_e(plan_plan_kinds()[$data['kind'] ?? ''] ?? 'Plano de trabalho') . '</div>'
            . '<ul class="small mb-0 ps-3">' . implode('', array_map(fn ($s) => '<li>' . core_e(mb_strimwidth($s, 0, 60, '…')) . '</li>', $first)) . '</ul>';
    }
    if ($t['kind'] === 'board') {
        $b = plan_normalize_board_template($data);
        $h = '<div class="d-flex flex-wrap gap-1 mb-1">';
        foreach ($b['columns'] as $c) {
            $h .= '<span class="badge text-bg-light border" style="border-left:4px solid ' . core_e($c['color'] ?? '#adb5bd') . ' !important">' . core_e($c['name']) . ($c['wip_limit'] ?? 0 ? ' <small>WIP ' . (int) $c['wip_limit'] . '</small>' : '') . '</span>';
        }
        $h .= '</div><div class="small text-muted">' . core_e(plan_board_kinds()[$b['kind']] ?? '') . ($b['points'] ? ' · pontos' : '') . (!empty($b['sprint_days']) ? ' · sprint ' . (int) $b['sprint_days'] . 'd' : '')
            . ($b['labels'] ? ' · ' . core_e(implode(', ', $b['labels'])) : '') . '</div>';
        return $h;
    }
    $svg = plan_diagram_thumb($data, ['thumb' => true, 'id' => 'tpl' . (int) $t['id']]);
    if ($svg) {
        return '<div class="plan-tpl-thumb mb-1">' . $svg . '</div>';
    }
    return '<div class="small text-muted"><i class="bi bi-diagram-3"></i> ' . count((array) ($data['nodes'] ?? [])) . ' elemento(s), ' . count((array) ($data['edges'] ?? [])) . ' conexão(ões)</div>';
}

// ===========================================================================
// POSTs
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $id  = (int) ($_POST['id'] ?? 0);
    $tpl = $id ? plan_find_template($id) : null;
    if ($id && !$tpl) {
        Flash::set('error', 'Modelo não encontrado.');
        core_redirect(plan_url('templates'));
    }

    if ($action === 'save') {
        core_require($tpl ? 'templates.edit' : 'templates.create');
        $kind = $tpl ? (string) $tpl['kind'] : (string) ($_POST['kind'] ?? '');
        if (!isset($kinds[$kind])) {
            Flash::set('error', 'Tipo de modelo inválido.');
            core_redirect(plan_url('templates', ['action' => 'create']));
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $back = plan_url('templates', $tpl ? ['action' => 'edit', 'id' => $tpl['id']] : ['action' => 'create', 'kind' => $kind]);
        if ($name === '') {
            Flash::set('error', 'Informe o nome do modelo.');
            core_redirect($back);
        }
        [$json, $err] = plan_template_validate($kind, (string) ($_POST['data'] ?? ''));
        if ($err !== '') {
            Flash::set('error', $err);
            core_redirect($back);
        }
        $icon = trim((string) ($_POST['icon'] ?? ''));
        $icon = preg_match('/^bi-[a-z0-9\-]{1,50}$/', $icon) ? $icon : null;
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500) ?: null;
        $sort = (int) ($_POST['sort_order'] ?? 0);
        if ($tpl) {
            DB::execute(
                'UPDATE plan_templates SET name = ?, description = ?, icon = ?, data = ?, sort_order = ? WHERE id = ?',
                [mb_substr($name, 0, 150), $desc, $icon, $json, $sort, $tpl['id']]
            );
            Audit::log('planejamento.template_update', 'plan_templates', (string) $tpl['id'], ['name' => $name, 'kind' => $kind]);
            Flash::set('success', 'Modelo atualizado.');
            core_redirect(plan_url('templates', ['kind' => $kind]));
        }
        DB::execute(
            'INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, active, sort_order, created_by) VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?)',
            [$kind, mb_substr($name, 0, 150), $desc, $icon, $json, $sort, $uid]
        );
        $newId = DB::lastId();
        Audit::log('planejamento.template_create', 'plan_templates', (string) $newId, ['name' => $name, 'kind' => $kind]);
        Flash::set('success', 'Modelo criado.');
        core_redirect(plan_url('templates', ['kind' => $kind]));
    }

    if (!$tpl) {
        core_redirect(plan_url('templates'));
    }

    if ($action === 'duplicate') {
        core_require('templates.create');
        DB::execute(
            'INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, active, sort_order, created_by)
             SELECT kind, ?, description, icon, data, 0, 1, sort_order + 1, ? FROM plan_templates WHERE id = ?',
            [mb_substr($tpl['name'] . ' (cópia)', 0, 150), $uid, $tpl['id']]
        );
        $newId = DB::lastId();
        Audit::log('planejamento.template_duplicate', 'plan_templates', (string) $newId, ['from' => $tpl['id'], 'name' => $tpl['name']]);
        Flash::set('success', 'Modelo duplicado. Ajuste o nome e o conteúdo da cópia.');
        core_redirect(plan_url('templates', ['action' => 'edit', 'id' => $newId]));
    }

    if ($action === 'toggle') {
        core_require('templates.edit');
        $new = (int) $tpl['active'] ? 0 : 1;
        DB::execute('UPDATE plan_templates SET active = ? WHERE id = ?', [$new, $tpl['id']]);
        Audit::log('planejamento.template_' . ($new ? 'activate' : 'deactivate'), 'plan_templates', (string) $tpl['id'], ['name' => $tpl['name']]);
        Flash::set('success', $new ? 'Modelo ativado.' : 'Modelo desativado (não aparece mais para uso).');
        core_redirect(plan_url('templates', ['kind' => $tpl['kind'], 'inactive' => $new ? null : 1]));
    }

    if ($action === 'delete') {
        core_require('templates.delete');
        if ((int) $tpl['is_builtin'] === 1) {
            Flash::set('error', 'Modelos fornecidos pelo sistema não podem ser excluídos — apenas desativados.');
            core_redirect(plan_url('templates', ['kind' => $tpl['kind']]));
        }
        DB::execute('DELETE FROM plan_templates WHERE id = ?', [$tpl['id']]); // FKs em planos/quadros/diagramas: SET NULL
        Audit::log('planejamento.template_delete', 'plan_templates', (string) $tpl['id'], ['name' => $tpl['name'], 'kind' => $tpl['kind']]);
        Flash::set('success', 'Modelo excluído.');
        core_redirect(plan_url('templates', ['kind' => $tpl['kind']]));
    }

    core_redirect(plan_url('templates'));
}

// ===========================================================================
// Formulário (criar / editar) com editor estruturado
// ===========================================================================
if ($action === 'create' || $action === 'edit') {
    $tpl = $action === 'edit' ? plan_find_template((int) ($_GET['id'] ?? 0)) : null;
    if ($action === 'edit' && !$tpl) {
        Flash::set('error', 'Modelo não encontrado.');
        core_redirect(plan_url('templates'));
    }
    core_require($tpl ? 'templates.edit' : 'templates.create');
    $kind = $tpl ? (string) $tpl['kind'] : (isset($kinds[$_GET['kind'] ?? '']) ? (string) $_GET['kind'] : 'plan');
    $data = $tpl ? plan_json_decode((string) $tpl['data']) : [];

    $planData  = $kind === 'plan' ? ['kind' => $data['kind'] ?? 'work_plan', 'items' => plan_normalize_template_items((array) ($data['items'] ?? []))] : ['kind' => 'work_plan', 'items' => []];
    $boardData = $kind === 'board' ? plan_normalize_board_template($data) : plan_normalize_board_template([]);
    if (!$boardData['columns']) {
        $boardData['columns'] = [['name' => 'A fazer', 'color' => '#0d6efd'], ['name' => 'Em andamento', 'color' => '#fd7e14', 'wip_limit' => 3], ['name' => 'Concluído', 'color' => '#198754', 'is_done' => 1]];
    }
    $diagramJson = $kind === 'diagram' && $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        : json_encode(['v' => 1, 'canvas' => ['w' => 1400, 'h' => 900, 'grid' => 20], 'nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 560, 'y' => 60, 'w' => 180, 'h' => 56, 'text' => 'Início'],
            ['id' => 'n2', 'type' => 'process', 'x' => 540, 'y' => 180, 'w' => 220, 'h' => 70, 'text' => 'Etapa'],
            ['id' => 'n3', 'type' => 'end', 'x' => 560, 'y' => 320, 'w' => 180, 'h' => 56, 'text' => 'Fim'],
        ], 'edges' => [['id' => 'e1', 'from' => 'n1', 'to' => 'n2'], ['id' => 'e2', 'from' => 'n2', 'to' => 'n3']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $icons = ['bi-clipboard2-check', 'bi-bullseye', 'bi-arrow-repeat', 'bi-kanban', 'bi-exclamation-triangle', 'bi-diagram-3', 'bi-diagram-2', 'bi-layout-three-columns', 'bi-table', 'bi-grid-3x3-gap', 'bi-list-check', 'bi-flag', 'bi-lightbulb', 'bi-gear', 'bi-people', 'bi-hospital', 'bi-heart-pulse', 'bi-shield-check', 'bi-graph-up', 'bi-calendar-check'];
    $tplJs = [
        'kind'    => $kind,
        'fixed'   => (bool) $tpl,
        'plan'    => $planData,
        'board'   => $boardData,
        'labels'  => ['kinds' => plan_item_kinds(), 'priorities' => plan_priorities(), 'planKinds' => plan_plan_kinds(), 'boardKinds' => plan_board_kinds()],
    ];
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-grid-1x2 me-2"></i><?= $tpl ? 'Editar modelo' : 'Novo modelo' ?>
            <?php if ($tpl && $tpl['is_builtin']): ?><span class="badge text-bg-info fw-normal">modelo do sistema</span><?php endif; ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('templates', ['kind' => $kind]) ?>">Voltar</a>
    </div>
    <form method="post" action="<?= plan_url('templates') ?>" class="row g-3" id="tplForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($tpl['id'] ?? 0) ?>">
        <textarea name="data" id="tplData" class="d-none" aria-hidden="true"></textarea>
        <div class="col-12 col-xl-4">
            <div class="card"><div class="card-body row g-3">
                <div class="col-12"><label class="form-label">Tipo</label>
                    <?php if ($tpl): ?>
                        <input class="form-control" value="<?= core_e($kinds[$kind]) ?>" disabled><input type="hidden" name="kind" value="<?= $kind ?>">
                    <?php else: ?>
                        <select class="form-select" name="kind" id="tplKind">
                            <?php foreach ($kinds as $k => $lbl): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-12"><label class="form-label">Nome *</label><input class="form-control" name="name" required maxlength="150" value="<?= core_e($tpl['name'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="3" maxlength="500"><?= core_e($tpl['description'] ?? '') ?></textarea></div>
                <div class="col-8"><label class="form-label">Ícone (Bootstrap Icons)</label>
                    <div class="input-group"><span class="input-group-text"><i class="bi <?= core_e($tpl['icon'] ?? 'bi-clipboard2-check') ?>" id="tplIconPreview"></i></span>
                        <input class="form-control" name="icon" id="tplIcon" list="tplIcons" maxlength="60" pattern="bi-[a-z0-9\-]+" value="<?= core_e($tpl['icon'] ?? 'bi-clipboard2-check') ?>" placeholder="bi-..."></div>
                    <datalist id="tplIcons"><?php foreach ($icons as $ic): ?><option value="<?= $ic ?>"><?php endforeach; ?></datalist></div>
                <div class="col-4"><label class="form-label">Ordem</label><input type="number" class="form-control" name="sort_order" value="<?= (int) ($tpl['sort_order'] ?? 0) ?>"></div>
                <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i><?= $tpl ? 'Salvar modelo' : 'Criar modelo' ?></button></div>
            </div></div>
        </div>
        <div class="col-12 col-xl-8">
            <!-- editor de plano -->
            <div class="card plan-tpl-editor" data-kind="plan" <?= $kind !== 'plan' ? 'hidden' : '' ?>>
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><span><i class="bi bi-diagram-2 me-1"></i>Árvore de itens do plano</span>
                    <div class="d-flex gap-2 align-items-center"><label class="small mb-0">Tipo de plano</label><select class="form-select form-select-sm w-auto" id="tplPlanKind"></select>
                        <button type="button" class="btn btn-sm btn-primary" id="tplPlanAddRoot"><i class="bi bi-plus-lg"></i> Objetivo</button></div></div>
                <div class="card-body">
                    <div class="form-text mb-2">Objetivo → meta → ação → tarefa. Clique em "detalhes" para preencher os campos 5W2H sugeridos (por quê, como, onde, indicador).</div>
                    <ul class="plan-tpl-tree" id="tplPlanTree"></ul>
                </div>
            </div>
            <!-- editor de quadro -->
            <div class="card plan-tpl-editor" data-kind="board" <?= $kind !== 'board' ? 'hidden' : '' ?>>
                <div class="card-header"><i class="bi bi-layout-three-columns me-1"></i>Colunas e configurações do quadro</div>
                <div class="card-body">
                    <div class="table-responsive"><table class="table table-sm align-middle mb-2" id="tplBoardCols">
                        <thead><tr><th>Coluna</th><th style="width:70px">Cor</th><th style="width:90px">WIP</th><th class="text-center" style="width:90px">Concluída</th><th style="width:110px"></th></tr></thead>
                        <tbody></tbody></table></div>
                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="tplBoardAddCol"><i class="bi bi-plus-lg"></i> Coluna</button>
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label small mb-0">Tipo de quadro</label><select class="form-select form-select-sm" id="tplBoardKind"></select></div>
                        <div class="col-md-8"><label class="form-label small mb-0">Etiquetas (separadas por vírgula)</label><input class="form-control form-control-sm" id="tplBoardLabels"></div>
                        <div class="col-md-4"><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" id="tplBoardPoints"><label class="form-check-label" for="tplBoardPoints">Usar pontos (story points)</label></div></div>
                        <div class="col-md-4"><label class="form-label small mb-0">Duração da sprint (dias, 0 = sem sprint)</label><input type="number" min="0" max="365" class="form-control form-control-sm" id="tplBoardSprint"></div>
                    </div>
                </div>
            </div>
            <!-- editor de diagrama -->
            <div class="card plan-tpl-editor" data-kind="diagram" <?= $kind !== 'diagram' ? 'hidden' : '' ?>>
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><span><i class="bi bi-diagram-3 me-1"></i>JSON do diagrama</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="tplDiagValidate"><i class="bi bi-check2-circle"></i> Validar / formatar</button>
                        <?php if ($tpl && core_can('diagrams.create')): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= plan_url('diagrams', ['action' => 'create', 'template' => $tpl['id']]) ?>"><i class="bi bi-pencil-square me-1"></i>Abrir no editor de diagramas</a>
                        <?php endif; ?>
                    </div></div>
                <div class="card-body">
                    <div class="form-text mb-2">Formato: <code>{"v":1,"canvas":{"w","h","grid"},"nodes":[{"id","type","x","y","w","h","text"}],"edges":[{"id","from","to","label"}]}</code>. Tipos de nó: start, end, process, decision, lane, note, ...
                        <?php if ($tpl): ?>Para desenhar visualmente, abra no editor de diagramas e use "Salvar como modelo".<?php else: ?>Depois de criar, você poderá abrir o modelo no editor visual de diagramas.<?php endif; ?></div>
                    <textarea class="form-control font-monospace" id="tplDiagJson" rows="18" spellcheck="false"><?= core_e((string) $diagramJson) ?></textarea>
                    <div class="small mt-1" id="tplDiagInfo"></div>
                    <?php if ($tpl): $thumb = plan_diagram_thumb($data, ['thumb' => true, 'id' => 'tplprev']); if ($thumb): ?>
                        <div class="mt-2"><div class="small text-muted mb-1">Pré-visualização (última versão salva)</div><div class="plan-tpl-thumb" style="height:220px"><?= $thumb ?></div></div>
                    <?php endif; endif; ?>
                </div>
            </div>
        </div>
    </form>
    <script>window.PLAN_TPL = <?= json_encode($tplJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <?php
    plan_page([
        'title'   => $tpl ? 'Editar modelo' : 'Novo modelo',
        'content' => (string) ob_get_clean(),
        'active'  => 'templates',
        'scripts' => '<script src="' . core_asset('planejamento/plans.js') . '?v=2"></script>',
    ]);
    exit;
}

// ===========================================================================
// Lista (cards por tipo)
// ===========================================================================
$kindF    = isset($kinds[$_GET['kind'] ?? '']) ? (string) $_GET['kind'] : '';
$showInactive = !empty($_GET['inactive']) && core_can('templates.edit');
$conds  = [];
$params = [];
if ($kindF !== '') {
    $conds[]  = 'kind = ?';
    $params[] = $kindF;
}
$conds[] = $showInactive ? 'active = 0' : 'active = 1';
$templates = DB::query(
    'SELECT t.*, u.name AS creator_name FROM plan_templates t LEFT JOIN users u ON u.id = t.created_by WHERE ' . implode(' AND ', $conds) . ' ORDER BY t.kind, t.sort_order, t.name',
    $params
);
$byKind = [];
foreach ($templates as $t) {
    $byKind[$t['kind']][] = $t;
}
$useUrl = fn (array $t): ?string => match ($t['kind']) {
    'plan'    => core_can('plans.create') ? plan_url('plans', ['action' => 'create', 'template' => $t['id']]) : null,
    'board'   => core_can('boards.create') ? plan_url('boards', ['action' => 'create', 'template' => $t['id']]) : null,
    'diagram' => core_can('diagrams.create') ? plan_url('diagrams', ['action' => 'create', 'template' => $t['id']]) : null,
    default   => null,
};
$kindIcons = ['plan' => 'bi-clipboard2-check', 'board' => 'bi-kanban', 'diagram' => 'bi-diagram-3'];
$kindTitles = ['plan' => 'Modelos de plano de trabalho', 'board' => 'Modelos de quadro', 'diagram' => 'Modelos de diagrama / fluxograma'];
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-grid-1x2 me-2"></i>Modelos</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('templates.edit')): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('templates', ['kind' => $kindF ?: null, 'inactive' => $showInactive ? null : 1]) ?>"><i class="bi <?= $showInactive ? 'bi-eye' : 'bi-eye-slash' ?> me-1"></i><?= $showInactive ? 'Ver ativos' : 'Ver desativados' ?></a>
        <?php endif; ?>
        <?php if (core_can('templates.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= plan_url('templates', ['action' => 'create', 'kind' => $kindF ?: null]) ?>"><i class="bi bi-plus-lg me-1"></i>Novo modelo</a>
        <?php endif; ?>
    </div>
</div>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $kindF === '' ? 'active' : '' ?>" href="<?= plan_url('templates', ['inactive' => $showInactive ? 1 : null]) ?>">Todos</a></li>
    <?php foreach ($kinds as $k => $lbl): ?>
        <li class="nav-item"><a class="nav-link <?= $kindF === $k ? 'active' : '' ?>" href="<?= plan_url('templates', ['kind' => $k, 'inactive' => $showInactive ? 1 : null]) ?>"><i class="bi <?= $kindIcons[$k] ?> me-1"></i><?= core_e($lbl) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php if ($showInactive): ?><div class="alert alert-secondary py-2 small"><i class="bi bi-eye-slash me-1"></i>Exibindo modelos <strong>desativados</strong> (não aparecem para uso).</div><?php endif; ?>
<?php if (!$templates): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">Nenhum modelo<?= $showInactive ? ' desativado' : '' ?><?= $kindF ? ' deste tipo' : '' ?>.</div></div>
<?php endif; ?>
<?php foreach ($byKind as $k => $list): ?>
    <h2 class="h6 text-muted mt-2 mb-2"><i class="bi <?= $kindIcons[$k] ?> me-1"></i><?= core_e($kindTitles[$k]) ?></h2>
    <div class="row g-3 mb-3">
    <?php foreach ($list as $t): $use = $useUrl($t); ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100 plan-tpl-card">
                <div class="card-body">
                    <div class="d-flex gap-3 align-items-start mb-2">
                        <div class="plan-tpl-icon text-primary"><i class="bi <?= core_e($t['icon'] ?: $kindIcons[$k]) ?>"></i></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= core_e($t['name']) ?></div>
                            <div class="small text-muted">
                                <?php if ($t['is_builtin']): ?><span class="badge text-bg-info fw-normal">sistema</span><?php else: ?><span class="badge text-bg-light border fw-normal"><?= core_e($t['creator_name'] ?: 'personalizado') ?></span><?php endif; ?>
                                <?php if (!$t['active']): ?><span class="badge text-bg-dark fw-normal">desativado</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($t['description']): ?><p class="small mb-2"><?= core_e($t['description']) ?></p><?php endif; ?>
                    <?= plan_template_summary($t) ?>
                </div>
                <div class="card-footer bg-white d-flex flex-wrap gap-1 align-items-center">
                    <?php if ($use && $t['active']): ?><a class="btn btn-sm btn-primary" href="<?= $use ?>"><i class="bi bi-magic me-1"></i>Usar modelo</a><?php endif; ?>
                    <span class="ms-auto"></span>
                    <?php if (core_can('templates.edit')): ?><a class="btn btn-sm btn-outline-primary" title="Editar" href="<?= plan_url('templates', ['action' => 'edit', 'id' => $t['id']]) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
                    <?php if (core_can('templates.create')): ?>
                        <form method="post" action="<?= plan_url('templates') ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="Duplicar"><i class="bi bi-files"></i></button></form>
                    <?php endif; ?>
                    <?php if (core_can('templates.edit')): ?>
                        <form method="post" action="<?= plan_url('templates') ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="<?= $t['active'] ? 'Desativar' : 'Ativar' ?>"><i class="bi <?= $t['active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button></form>
                    <?php endif; ?>
                    <?php if (core_can('templates.delete') && !$t['is_builtin']): ?>
                        <form method="post" action="<?= plan_url('templates') ?>" class="d-inline" onsubmit="return confirm('Excluir o modelo definitivamente?')"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php
plan_page(['title' => 'Modelos', 'content' => (string) ob_get_clean(), 'active' => 'templates']);
