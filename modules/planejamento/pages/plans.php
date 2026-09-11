<?php
/**
 * PLANEJAMENTO — planos de trabalho e planejamento organizacional.
 *   page=plans                       lista
 *   page=plans&action=create[&template=ID]   novo plano (em branco ou de modelo)
 *   page=plans&action=edit&id=X      dados do plano
 *   page=plans&action=view&id=X      árvore de itens / tabela 5W2H / Gantt
 *   page=plans&action=print&id=X[&cover=1]   impressão no timbrado (plans.export)
 *   POST action=save|archive|unarchive|delete|status
 */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Flash;

core_require('plans.view');

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$uid    = (int) core_user_id();

// ===========================================================================
// POSTs
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $id   = (int) ($_POST['id'] ?? 0);
    $plan = $id ? plan_find_plan($id) : null;

    if ($action === 'save') {
        core_require($plan ? 'plans.edit' : 'plans.create');
        if ($id && !$plan) {
            Flash::set('error', 'Plano não encontrado.');
            core_redirect(plan_url('plans'));
        }
        if ($plan && $plan['status'] === 'archived') {
            Flash::set('error', 'Plano arquivado: desarquive para editar.');
            core_redirect(plan_url('plans', ['action' => 'view', 'id' => $plan['id']]));
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $kind  = in_array($_POST['kind'] ?? '', array_keys(plan_plan_kinds()), true) ? $_POST['kind'] : 'work_plan';
        $status = in_array($_POST['status'] ?? '', ['draft', 'active', 'completed'], true) ? $_POST['status'] : ($plan['status'] ?? 'draft');
        $owner = (int) ($_POST['owner_id'] ?? 0) ?: null;
        if ($owner && !DB::queryOne('SELECT id FROM users WHERE id = ? AND active = 1', [$owner])) {
            $owner = null;
        }
        $start = plan_date_or_null($_POST['start_date'] ?? '');
        $end   = plan_date_or_null($_POST['end_date'] ?? '');
        if ($title === '') {
            Flash::set('error', 'Informe o título do plano.');
            core_redirect(plan_url('plans', $plan ? ['action' => 'edit', 'id' => $plan['id']] : ['action' => 'create']));
        }
        if ($start && $end && $end < $start) {
            Flash::set('error', 'A data de término deve ser posterior ao início.');
            core_redirect(plan_url('plans', $plan ? ['action' => 'edit', 'id' => $plan['id']] : ['action' => 'create']));
        }
        $fields = [mb_substr($title, 0, 200), trim((string) ($_POST['description'] ?? '')) ?: null, $kind,
                   mb_substr(trim((string) ($_POST['sector'] ?? '')), 0, 150) ?: null, $owner, $start, $end];
        if ($plan) {
            DB::execute(
                'UPDATE plan_plans SET title = ?, description = ?, kind = ?, sector = ?, owner_id = ?, start_date = ?, end_date = ?, status = ?, updated_by = ? WHERE id = ?',
                array_merge($fields, [$status, $uid, $plan['id']])
            );
            Audit::log('planejamento.plan_update', 'plan_plans', (string) $plan['id'], ['title' => $title]);
            Flash::set('success', 'Plano atualizado.');
            core_redirect(plan_url('plans', ['action' => 'view', 'id' => $plan['id']]));
        }
        $tpl = plan_find_template((int) ($_POST['template_id'] ?? 0));
        if ($tpl && ($tpl['kind'] !== 'plan' || !$tpl['active'])) {
            $tpl = null;
        }
        $tplData = $tpl ? plan_json_decode((string) $tpl['data']) : [];
        // Quando o formulário enviado não refletia este modelo (tpl_applied),
        // o tipo de plano definido no modelo prevalece sobre o padrão.
        if ($tpl && (int) ($_POST['tpl_applied'] ?? 0) !== (int) $tpl['id']
            && in_array($tplData['kind'] ?? '', array_keys(plan_plan_kinds()), true)) {
            $fields[2] = (string) $tplData['kind'];
        }
        $newId = DB::transaction(function () use ($fields, $status, $uid, $tpl, $tplData): int {
            DB::execute(
                'INSERT INTO plan_plans (title, description, kind, sector, owner_id, start_date, end_date, status, template_id, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($fields, [$status, $tpl ? (int) $tpl['id'] : null, $uid, $uid])
            );
            $newId = DB::lastId();
            if ($tpl) {
                plan_instantiate_items($newId, (array) ($tplData['items'] ?? []));
            }
            return $newId;
        });
        plan_recalc_progress($newId);
        Audit::log('planejamento.plan_create', 'plan_plans', (string) $newId, ['title' => $title, 'template_id' => $tpl['id'] ?? null]);
        Flash::set('success', $tpl ? 'Plano criado a partir do modelo "' . $tpl['name'] . '".' : 'Plano criado. Adicione os objetivos, metas e ações.');
        core_redirect(plan_url('plans', ['action' => 'view', 'id' => $newId]));
    }

    if (!$plan) {
        Flash::set('error', 'Plano não encontrado.');
        core_redirect(plan_url('plans'));
    }

    if ($action === 'archive' || $action === 'unarchive') {
        core_require('plans.delete');
        $new = $action === 'archive' ? 'archived' : 'active';
        DB::execute('UPDATE plan_plans SET status = ?, updated_by = ? WHERE id = ?', [$new, $uid, $plan['id']]);
        Audit::log('planejamento.plan_' . $action, 'plan_plans', (string) $plan['id'], ['title' => $plan['title']]);
        Flash::set('success', $action === 'archive' ? 'Plano arquivado.' : 'Plano desarquivado (status: Ativo).');
        core_redirect(plan_url('plans', ['action' => 'view', 'id' => $plan['id']]));
    }

    if ($action === 'delete') {
        core_require('plans.delete');
        DB::execute('UPDATE plan_plans SET deleted_at = NOW(), updated_by = ? WHERE id = ?', [$uid, $plan['id']]);
        Audit::log('planejamento.plan_delete', 'plan_plans', (string) $plan['id'], ['title' => $plan['title']]);
        Flash::set('success', 'Plano excluído.');
        core_redirect(plan_url('plans'));
    }

    if ($action === 'status') {
        core_require('plans.edit');
        $new = (string) ($_POST['status'] ?? '');
        if ($plan['status'] !== 'archived' && in_array($new, ['draft', 'active', 'completed'], true)) {
            DB::execute('UPDATE plan_plans SET status = ?, updated_by = ? WHERE id = ?', [$new, $uid, $plan['id']]);
            Flash::set('success', 'Status alterado para "' . plan_plan_statuses()[$new] . '".');
        }
        core_redirect(plan_url('plans', ['action' => 'view', 'id' => $plan['id']]));
    }

    core_redirect(plan_url('plans'));
}

// ===========================================================================
// Impressão / exportação (timbrado)
// ===========================================================================
if ($action === 'print') {
    core_require('plans.export');
    $plan = plan_find_plan((int) ($_GET['id'] ?? 0));
    if (!$plan) {
        Core\Layout::renderError(404, 'Plano não encontrado.');
        exit;
    }
    $layout = Core\DocLayout::findOrDefault((int) ($_GET['layout'] ?? 0));
    if (!$layout) {
        Core\Layout::renderError(500, 'Nenhum layout de documento cadastrado — crie um em Administração > Layouts de documentos.');
        exit;
    }
    $items    = plan_items_flat_ordered(plan_plan_items((int) $plan['id']));
    $kinds    = plan_item_kinds();
    $statuses = plan_item_statuses();
    $prios    = plan_priorities();
    $withCover = !empty($_GET['cover']);

    ob_start(); ?>
    <h1 style="margin:0 0 4px;font-size:1.6em"><?= core_e($plan['title']) ?></h1>
    <p style="margin:0 0 10px;color:#555"><?= core_e(plan_plan_kinds()[$plan['kind']] ?? $plan['kind']) ?> · <?= core_e(plan_plan_statuses()[$plan['status']] ?? $plan['status']) ?></p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:12px;font-size:.95em">
        <tr><th style="width:22%;text-align:left;background:#f1f3f5">Responsável geral</th><td><?= core_e($plan['owner_name'] ?: '—') ?></td>
            <th style="width:22%;text-align:left;background:#f1f3f5">Setor / área</th><td><?= core_e($plan['sector'] ?: '—') ?></td></tr>
        <tr><th style="text-align:left;background:#f1f3f5">Período</th><td><?= core_e(trim(plan_date_br($plan['start_date']) . ' a ' . plan_date_br($plan['end_date']), ' a') ?: '—') ?></td>
            <th style="text-align:left;background:#f1f3f5">Progresso</th><td><?= (int) $plan['progress'] ?>%</td></tr>
        <?php if ($plan['description']): ?>
        <tr><th style="text-align:left;background:#f1f3f5">Descrição</th><td colspan="3"><?= nl2br(core_e($plan['description'])) ?></td></tr>
        <?php endif; ?>
    </table>
    <h2 style="font-size:1.15em;margin:14px 0 6px">Plano de ação (5W2H)</h2>
    <table style="width:100%;border-collapse:collapse;font-size:.82em">
        <thead><tr style="background:#e9ecef">
            <th>O quê (What)</th><th>Por quê (Why)</th><th>Onde (Where)</th><th>Quando (When)</th><th>Quem (Who)</th><th>Como (How)</th><th>Quanto (How much)</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php if (!$items): ?><tr><td colspan="8" style="text-align:center;color:#777">Nenhum item cadastrado.</td></tr><?php endif; ?>
        <?php foreach ($items as $it):
            $isContainer = in_array($it['kind'], ['objective', 'goal'], true);
            $resp = $it['responsible_id'] ? ($it['responsible_user_name'] ?? '') : ($it['responsible_name'] ?? '');
            $when = trim(plan_date_br($it['start_date']) . ' – ' . plan_date_br($it['due_date']), ' –');
        ?>
            <tr style="<?= $isContainer ? 'background:#f8f9fa;font-weight:600' : '' ?>">
                <td style="padding-left:<?= 6 + (int) $it['depth'] * 14 ?>px">
                    <span style="font-size:.85em;color:#666"><?= core_e($kinds[$it['kind']]) ?>:</span> <?= core_e($it['title']) ?>
                    <?php if ($it['indicator']): ?><div style="font-weight:400;font-size:.9em;color:#555">Indicador: <?= core_e($it['indicator']) ?></div><?php endif; ?>
                </td>
                <td><?= nl2br(core_e($it['description'] ?? '')) ?></td>
                <td><?= core_e($it['where_text'] ?? '') ?></td>
                <td style="white-space:nowrap"><?= core_e($when) ?></td>
                <td><?= core_e($resp) ?></td>
                <td><?= nl2br(core_e($it['how_text'] ?? '')) ?></td>
                <td style="white-space:nowrap;text-align:right"><?= $it['cost'] !== null ? 'R$ ' . number_format((float) $it['cost'], 2, ',', '.') : '' ?></td>
                <td style="white-space:nowrap"><?= core_e($statuses[$it['status']] ?? $it['status']) ?> (<?= (int) $it['progress'] ?>%)<br><small><?= core_e($prios[$it['priority']] ?? '') ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $content = (string) ob_get_clean();
    $meta = [
        'title'    => $plan['title'],
        'author'   => $plan['owner_name'] ?: ($plan['creator_name'] ?? ''),
        'date'     => date('d/m/Y'),
        'version'  => 1,
        'sector'   => (string) ($plan['sector'] ?? ''),
        'subtitle' => plan_plan_kinds()[$plan['kind']] ?? '',
    ];
    $cover = null;
    if ($withCover) {
        $coverHtml = trim((string) ($layout['cover_html'] ?? ''));
        if ($coverHtml === '') {
            $coverHtml = '<div style="text-align:center;margin-top:35%">'
                . '<div style="font-size:.9em;color:#666;letter-spacing:.2em;text-transform:uppercase">' . core_e($meta['subtitle']) . '</div>'
                . '<h1 style="font-size:2.2em;margin:.4em 0">' . core_e($plan['title']) . '</h1>'
                . ($plan['sector'] ? '<p style="font-size:1.1em">' . core_e($plan['sector']) . '</p>' : '')
                . ($plan['owner_name'] ? '<p>Responsável: ' . core_e($plan['owner_name']) . '</p>' : '')
                . '<p style="color:#666">' . core_e(trim(plan_date_br($plan['start_date']) . ' a ' . plan_date_br($plan['end_date']), ' a') ?: date('d/m/Y')) . '</p></div>';
        }
        $cover = ['layout' => null, 'html' => $coverHtml];
    }
    $base = plan_url('plans', ['action' => 'print', 'id' => $plan['id']]);
    $toolbar = '<strong>' . core_e($plan['title']) . '</strong><span class="spacer"></span>'
        . '<button onclick="window.print()">Salvar em PDF / Imprimir</button>'
        . '<a href="' . core_e($base . ($withCover ? '' : '&cover=1')) . '">' . ($withCover ? 'Sem capa' : 'Com capa') . '</a>'
        . '<a href="' . core_e(plan_url('plans', ['action' => 'view', 'id' => $plan['id']])) . '">Voltar</a>';
    echo Core\DocLayout::renderHtml([
        'title'        => $plan['title'],
        'layout'       => $layout,
        'content_html' => $content,
        'meta'         => $meta,
        'toolbar'      => $toolbar,
        'autoprint'    => !empty($_GET['pdf']),
        'cover'        => $cover,
        'extra_css'    => '.doc-content th, .doc-content td { vertical-align: top; }',
    ]);
    exit;
}

// ===========================================================================
// Formulário (criar / editar)
// ===========================================================================
if ($action === 'create' || $action === 'edit') {
    $plan = $action === 'edit' ? plan_find_plan((int) ($_GET['id'] ?? 0)) : null;
    if ($action === 'edit' && !$plan) {
        Flash::set('error', 'Plano não encontrado.');
        core_redirect(plan_url('plans'));
    }
    core_require($plan ? 'plans.edit' : 'plans.create');
    if ($plan && $plan['status'] === 'archived') {
        Flash::set('warning', 'Plano arquivado: desarquive para editar.');
        core_redirect(plan_url('plans', ['action' => 'view', 'id' => $plan['id']]));
    }
    $templates  = $plan ? [] : plan_templates_active('plan');
    $selectedTp = (int) ($_GET['template'] ?? 0);
    $users      = plan_users_active();
    // tipo de plano de cada modelo, para o JS aplicar ao trocar o rádio
    $tplJs = [];
    foreach ($templates as $t) {
        $d = plan_json_decode((string) $t['data']);
        $tplJs[(int) $t['id']] = [
            'name' => (string) $t['name'],
            'kind' => in_array($d['kind'] ?? '', array_keys(plan_plan_kinds()), true) ? (string) $d['kind'] : 'work_plan',
        ];
    }
    $tplSelValid = $selectedTp > 0 && isset($tplJs[$selectedTp]);
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard2-check me-2"></i><?= $plan ? 'Editar plano' : 'Novo plano' ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $plan ? plan_url('plans', ['action' => 'view', 'id' => $plan['id']]) : plan_url('plans') ?>">Voltar</a>
    </div>
    <form method="post" action="<?= plan_url('plans') ?>" class="row g-3" id="planForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($plan['id'] ?? 0) ?>">
        <?php if (!$plan): ?><input type="hidden" name="tpl_applied" id="tplApplied" value="<?= $tplSelValid ? $selectedTp : 0 ?>"><?php endif; ?>
        <div class="col-12 col-xl-8">
            <div class="card">
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Título *</label>
                        <input class="form-control" name="title" required maxlength="200" value="<?= core_e($plan['title'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="kind">
                            <?php foreach (plan_plan_kinds() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($plan['kind'] ?? ($tplSelValid ? $tplJs[$selectedTp]['kind'] : 'work_plan')) === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <?php if (($plan['status'] ?? '') === 'archived'): ?>
                            <input class="form-control" value="Arquivado" disabled>
                        <?php else: ?>
                            <select class="form-select" name="status">
                                <?php foreach (['draft', 'active', 'completed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= ($plan['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= plan_plan_statuses()[$s] ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Setor / área</label>
                        <input class="form-control" name="sector" maxlength="150" value="<?= core_e($plan['sector'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Responsável geral</label>
                        <select class="form-select" name="owner_id">
                            <option value="">—</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= (int) ($plan['owner_id'] ?? ($plan ? 0 : $uid)) === $u['id'] ? 'selected' : '' ?>><?= core_e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Início</label>
                        <input type="date" class="form-control" name="start_date" value="<?= core_e($plan['start_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Término previsto</label>
                        <input type="date" class="form-control" name="end_date" value="<?= core_e($plan['end_date'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descrição / contexto</label>
                        <textarea class="form-control" name="description" rows="4"><?= core_e($plan['description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <?php if (!$plan): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-grid-1x2 me-1"></i>Começar a partir de um modelo</div>
                <div class="card-body">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="template_id" value="0" id="tpl0" <?= $selectedTp === 0 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tpl0">Em branco</label>
                    </div>
                    <?php foreach ($templates as $t): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="template_id" value="<?= (int) $t['id'] ?>" id="tpl<?= (int) $t['id'] ?>" <?= $selectedTp === (int) $t['id'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="tpl<?= (int) $t['id'] ?>">
                                <i class="bi <?= core_e($t['icon'] ?: 'bi-file-earmark') ?> me-1"></i><?= core_e($t['name']) ?>
                                <?php if ($t['description']): ?><div class="form-text mt-0"><?= core_e($t['description']) ?></div><?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i><?= $plan ? 'Salvar alterações' : 'Criar plano' ?></button>
        </div>
    </form>
    <?php if (!$plan): ?>
    <script>
    (function () {
        var TPL = <?= json_encode($tplJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var form = document.getElementById('planForm');
        var applied = document.getElementById('tplApplied');
        if (!form || !applied) { return; }
        form.querySelectorAll('input[name=template_id]').forEach(function (r) {
            r.addEventListener('change', function () {
                var t = TPL[r.value];
                applied.value = t ? r.value : 0;
                if (!t) { return; }
                if (!form.title.value.trim()) { form.title.value = t.name; }
                form.kind.value = t.kind;
            });
        });
    })();
    </script>
    <?php endif; ?>
    <?php
    plan_page(['title' => $plan ? 'Editar plano' : 'Novo plano', 'content' => (string) ob_get_clean(), 'active' => 'plans']);
    exit;
}

// ===========================================================================
// Página do plano (árvore / 5W2H / Gantt)
// ===========================================================================
if ($action === 'view') {
    $plan = plan_find_plan((int) ($_GET['id'] ?? 0));
    if (!$plan) {
        Core\Layout::renderError(404, 'Plano não encontrado.');
        exit;
    }
    $itemsRaw = plan_plan_items((int) $plan['id']);
    $items    = array_map('plan_item_to_array', $itemsRaw);
    $flat     = plan_items_flat_ordered($itemsRaw);
    $canEdit  = core_can('plans.edit') && $plan['status'] !== 'archived';
    $kinds    = plan_item_kinds();
    $statuses = plan_item_statuses();
    $prios    = plan_priorities();
    $today    = date('Y-m-d');

    // resumo
    $totalActions = count(array_filter($items, fn ($i) => $i['kind'] === 'action'));
    $doneActions  = count(array_filter($items, fn ($i) => $i['kind'] === 'action' && $i['status'] === 'done'));
    $overdue      = count(array_filter($items, fn ($i) => $i['overdue']));

    // Gantt: itens com datas
    $gantt  = array_values(array_filter($flat, fn ($i) => !empty($i['due_date']) || !empty($i['start_date'])));
    $gMin   = $plan['start_date'] ?: null;
    $gMax   = $plan['end_date'] ?: null;
    foreach ($gantt as $g) {
        foreach ([$g['start_date'], $g['due_date']] as $d) {
            if ($d) {
                $gMin = $gMin === null || $d < $gMin ? $d : $gMin;
                $gMax = $gMax === null || $d > $gMax ? $d : $gMax;
            }
        }
    }
    $months = [];
    if ($gMin && $gMax) {
        $gMin = date('Y-m-01', strtotime($gMin));
        $gMax = date('Y-m-t', strtotime($gMax));
        $cur  = $gMin;
        while ($cur <= $gMax && count($months) < 36) {
            $months[] = $cur;
            $cur      = date('Y-m-01', strtotime($cur . ' +1 month'));
        }
        $gMax = date('Y-m-t', strtotime(end($months)));
    }
    $span = $gMin && $gMax ? max(1, (strtotime($gMax) - strtotime($gMin)) / 86400 + 1) : 1;
    $pct  = fn (string $d): float => max(0.0, min(100.0, ((strtotime($d) - strtotime($gMin)) / 86400) / $span * 100));
    $mesesPt = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    $data = [
        'plan'     => ['id' => (int) $plan['id'], 'title' => $plan['title'], 'status' => $plan['status'], 'progress' => (int) $plan['progress']],
        'items'    => $items,
        'users'    => plan_users_active(),
        'labels'   => ['kinds' => $kinds, 'statuses' => $statuses, 'priorities' => $prios],
        'canEdit'  => $canEdit,
        'apiUrl'   => plan_url('api'),
        'csrf'     => Csrf::token(),
    ];
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-clipboard2-check me-2"></i><?= core_e($plan['title']) ?></h1>
            <div class="small text-muted">
                <span class="badge text-bg-light border"><?= core_e(plan_plan_kinds()[$plan['kind']] ?? $plan['kind']) ?></span>
                <?= plan_status_badge($plan['status']) ?>
                <?php if ($plan['sector']): ?> · <i class="bi bi-building"></i> <?= core_e($plan['sector']) ?><?php endif; ?>
                <?php if ($plan['owner_name']): ?> · <i class="bi bi-person"></i> <?= core_e($plan['owner_name']) ?><?php endif; ?>
                <?php if ($plan['start_date'] || $plan['end_date']): ?> · <i class="bi bi-calendar3"></i> <?= core_e(trim(plan_date_br($plan['start_date']) . ' a ' . plan_date_br($plan['end_date']), ' a')) ?><?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (core_can('plans.export')): ?>
                <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= plan_url('plans', ['action' => 'print', 'id' => $plan['id']]) ?>"><i class="bi bi-printer me-1"></i>Imprimir / PDF</a>
            <?php endif; ?>
            <?php if (core_can('boards.create')): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('boards', ['action' => 'create', 'plan_id' => $plan['id']]) ?>"><i class="bi bi-kanban me-1"></i>Quadro deste plano</a>
            <?php endif; ?>
            <?php if (core_can('diagrams.create')): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('diagrams', ['action' => 'create', 'plan_id' => $plan['id']]) ?>"><i class="bi bi-diagram-3 me-1"></i>Diagrama deste plano</a>
            <?php endif; ?>
            <?php if (core_can('plans.edit')): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= plan_url('plans', ['action' => 'edit', 'id' => $plan['id']]) ?>"><i class="bi bi-pencil me-1"></i>Editar dados</a>
            <?php endif; ?>
            <?php if (core_can('plans.delete')): ?>
                <form method="post" action="<?= plan_url('plans') ?>" class="d-inline">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>">
                    <input type="hidden" name="action" value="<?= $plan['status'] === 'archived' ? 'unarchive' : 'archive' ?>">
                    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-archive me-1"></i><?= $plan['status'] === 'archived' ? 'Desarquivar' : 'Arquivar' ?></button>
                </form>
                <form method="post" action="<?= plan_url('plans') ?>" class="d-inline" onsubmit="return confirm('Excluir o plano e todos os seus itens?')">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= plan_url('plans') ?>">Voltar</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-5">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between small mb-1"><span>Progresso geral</span><strong id="planProgressLabel"><?= (int) $plan['progress'] ?>%</strong></div>
                <div class="progress" style="height:12px"><div class="progress-bar bg-success" id="planProgressBar" style="width:<?= (int) $plan['progress'] ?>%"></div></div>
                <div class="small text-muted mt-2">Média das ações ponderada pela prioridade. Ações concluídas: <span id="planDoneLabel"><?= $doneActions ?>/<?= $totalActions ?></span><?php if ($overdue): ?> · <span class="text-danger"><i class="bi bi-exclamation-circle"></i> <?= $overdue ?> atrasado(s)</span><?php endif; ?></div>
                <?php if ($canEdit && $plan['status'] !== 'completed'): ?>
                <form method="post" action="<?= plan_url('plans') ?>" class="mt-2 d-flex gap-2 align-items-center">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="action" value="status">
                    <?php if ($plan['status'] === 'draft'): ?>
                        <button class="btn btn-sm btn-outline-primary" name="status" value="active"><i class="bi bi-play me-1"></i>Ativar plano</button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline-success" name="status" value="completed"><i class="bi bi-check2-all me-1"></i>Marcar plano como concluído</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-12 col-lg-7">
            <div class="card h-100"><div class="card-body">
                <?php if ($plan['description']): ?>
                    <div class="small text-muted mb-1">Descrição</div>
                    <div class="plan-desc"><?= nl2br(core_e($plan['description'])) ?></div>
                <?php else: ?>
                    <div class="text-muted small">Sem descrição. <?php if (core_can('plans.edit')): ?><a href="<?= plan_url('plans', ['action' => 'edit', 'id' => $plan['id']]) ?>">Adicionar</a><?php endif; ?></div>
                <?php endif; ?>
                <?php if ($plan['status'] === 'archived'): ?><div class="alert alert-secondary py-1 px-2 small mt-2 mb-0"><i class="bi bi-archive me-1"></i>Plano arquivado: somente leitura.</div><?php endif; ?>
            </div></div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="planTabs">
        <li class="nav-item"><a class="nav-link active" href="#tab-tree" data-tab="tab-tree"><i class="bi bi-diagram-2 me-1"></i>Árvore de itens</a></li>
        <li class="nav-item"><a class="nav-link" href="#tab-table" data-tab="tab-table"><i class="bi bi-table me-1"></i>Tabela 5W2H</a></li>
        <li class="nav-item"><a class="nav-link" href="#tab-gantt" data-tab="tab-gantt"><i class="bi bi-bar-chart-steps me-1"></i>Gantt</a></li>
    </ul>

    <div class="plan-tab" id="tab-tree">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-diagram-2 me-1"></i>Objetivos → metas → ações → tarefas</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExpandAll"><i class="bi bi-arrows-expand"></i> Expandir</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCollapseAll"><i class="bi bi-arrows-collapse"></i> Recolher</button>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-primary" id="btnAddRoot"><i class="bi bi-plus-lg me-1"></i>Novo objetivo</button><?php endif; ?>
                </div>
            </div>
            <div class="card-body" id="planTree"><div class="text-muted small">Carregando…</div></div>
        </div>
    </div>

    <div class="plan-tab d-none" id="tab-table">
        <div class="card"><div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0 plan-5w2h">
                <thead><tr>
                    <th>O quê</th><th>Por quê</th><th>Onde</th><th>Quando</th><th>Quem</th><th>Como</th><th class="text-end">Quanto</th><th>Prior.</th><th>Status</th><th style="min-width:90px">Progresso</th>
                </tr></thead>
                <tbody>
                <?php if (!$flat): ?><tr><td colspan="10" class="text-center text-muted py-4">Nenhum item cadastrado.</td></tr><?php endif; ?>
                <?php foreach ($flat as $it):
                    $resp = $it['responsible_id'] ? ($it['responsible_user_name'] ?? '') : ($it['responsible_name'] ?? '');
                    $cont = in_array($it['kind'], ['objective', 'goal'], true);
                    $late = !in_array($it['status'], ['done', 'cancelled'], true) && $it['due_date'] && $it['due_date'] < $today;
                ?>
                    <tr class="<?= $cont ? 'table-light fw-semibold' : '' ?>">
                        <td style="padding-left:<?= 8 + (int) $it['depth'] * 18 ?>px">
                            <span class="badge plan-kind plan-kind-<?= $it['kind'] ?>"><?= core_e($kinds[$it['kind']]) ?></span> <?= core_e($it['title']) ?>
                            <?php if ($it['indicator']): ?><div class="small text-muted fw-normal">Indicador: <?= core_e($it['indicator']) ?></div><?php endif; ?>
                        </td>
                        <td class="small fw-normal"><?= nl2br(core_e($it['description'] ?? '')) ?></td>
                        <td class="small fw-normal"><?= core_e($it['where_text'] ?? '') ?></td>
                        <td class="small text-nowrap fw-normal <?= $late ? 'text-danger' : '' ?>"><?= core_e(trim(plan_date_br($it['start_date']) . ' – ' . plan_date_br($it['due_date']), ' –')) ?></td>
                        <td class="small fw-normal"><?= core_e($resp) ?></td>
                        <td class="small fw-normal"><?= nl2br(core_e($it['how_text'] ?? '')) ?></td>
                        <td class="small text-end text-nowrap fw-normal"><?= $it['cost'] !== null ? 'R$ ' . number_format((float) $it['cost'], 2, ',', '.') : '' ?></td>
                        <td><?= plan_priority_badge($it['priority']) ?></td>
                        <td><?= plan_status_badge($it['status']) ?></td>
                        <td><div class="progress" style="height:8px"><div class="progress-bar <?= $it['status'] === 'done' ? 'bg-success' : '' ?>" style="width:<?= (int) $it['progress'] ?>%"></div></div><div class="small text-muted"><?= (int) $it['progress'] ?>%</div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>

    <div class="plan-tab d-none" id="tab-gantt">
        <div class="card">
            <div class="card-header"><i class="bi bi-bar-chart-steps me-1"></i>Cronograma (barras por mês)</div>
            <div class="card-body">
            <?php if (!$months || !$gantt): ?>
                <p class="text-muted mb-0">Informe datas de início/prazo nos itens (ou no plano) para montar o cronograma.</p>
            <?php else: ?>
                <div class="plan-gantt">
                    <div class="plan-gantt-row plan-gantt-head">
                        <div class="plan-gantt-label"></div>
                        <div class="plan-gantt-track">
                            <?php foreach ($months as $m): $w = (int) date('t', strtotime($m)) / $span * 100; ?>
                                <div class="plan-gantt-month" style="width:<?= $w ?>%"><?= $mesesPt[(int) date('n', strtotime($m))] ?>/<?= date('y', strtotime($m)) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php foreach ($gantt as $g):
                        $s = $g['start_date'] ?: $g['due_date'];
                        $e = $g['due_date'] ?: $g['start_date'];
                        if ($e < $s) { $e = $s; }
                        $left  = $pct($s);
                        $width = max(0.6, $pct($e) - $left + (1 / $span * 100));
                        $late  = !in_array($g['status'], ['done', 'cancelled'], true) && $g['due_date'] && $g['due_date'] < $today;
                        $cls   = $g['status'] === 'done' ? 'bg-success' : ($late ? 'bg-danger' : ($g['status'] === 'cancelled' ? 'bg-secondary' : 'bg-primary'));
                    ?>
                        <div class="plan-gantt-row">
                            <div class="plan-gantt-label" style="padding-left:<?= 6 + (int) $g['depth'] * 12 ?>px" title="<?= core_e($g['title']) ?>">
                                <span class="badge plan-kind plan-kind-<?= $g['kind'] ?>"><?= core_e(mb_substr($kinds[$g['kind']], 0, 3)) ?></span> <?= core_e($g['title']) ?>
                            </div>
                            <div class="plan-gantt-track">
                                <?php foreach ($months as $m): ?><div class="plan-gantt-month" style="width:<?= (int) date('t', strtotime($m)) / $span * 100 ?>%"></div><?php endforeach; ?>
                                <div class="plan-gantt-bar <?= $cls ?>" style="left:<?= $left ?>%;width:<?= $width ?>%" title="<?= core_e(plan_date_br($s) . ' – ' . plan_date_br($e)) ?> · <?= (int) $g['progress'] ?>%">
                                    <span style="width:<?= (int) $g['progress'] ?>%"></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php $tp = $today >= $gMin && $today <= $gMax ? $pct($today) : null; ?>
                    <?php if ($tp !== null): ?><div class="plan-gantt-today" style="left:calc(var(--plan-gantt-label) + (100% - var(--plan-gantt-label)) * <?= $tp / 100 ?>)" title="Hoje"></div><?php endif; ?>
                </div>
                <div class="small text-muted mt-2"><span class="badge bg-primary">&nbsp;</span> em andamento · <span class="badge bg-success">&nbsp;</span> concluído · <span class="badge bg-danger">&nbsp;</span> atrasado · <span class="badge bg-secondary">&nbsp;</span> cancelado</div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <script>window.PLAN_DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <?php
    plan_page([
        'title'   => $plan['title'],
        'content' => (string) ob_get_clean(),
        'active'  => 'plans',
        'fluid'   => true,
        'scripts' => '<script src="' . core_asset('planejamento/plans.js') . '"></script>',
    ]);
    exit;
}

// ===========================================================================
// Lista
// ===========================================================================
$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'open');
$kindF  = (string) ($_GET['kind'] ?? '');
$owner  = (int) ($_GET['owner'] ?? 0);
$conds  = ['p.deleted_at IS NULL'];
$params = [];
if ($status === 'open') {
    $conds[] = "p.status IN ('draft','active')";
} elseif (in_array($status, array_keys(plan_plan_statuses()), true)) {
    $conds[]  = 'p.status = ?';
    $params[] = $status;
}
if (in_array($kindF, array_keys(plan_plan_kinds()), true)) {
    $conds[]  = 'p.kind = ?';
    $params[] = $kindF;
}
if ($owner > 0) {
    $conds[]  = 'p.owner_id = ?';
    $params[] = $owner;
}
if ($q !== '') {
    $conds[]  = '(p.title LIKE ? OR p.sector LIKE ? OR p.description LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$plans = DB::query(
    'SELECT p.*, o.name AS owner_name,
            (SELECT COUNT(*) FROM plan_plan_items i WHERE i.plan_id = p.id AND i.kind = \'action\') AS actions_total,
            (SELECT COUNT(*) FROM plan_plan_items i WHERE i.plan_id = p.id AND i.kind = \'action\' AND i.status = \'done\') AS actions_done,
            (SELECT COUNT(*) FROM plan_plan_items i WHERE i.plan_id = p.id AND i.due_date < CURDATE() AND i.status IN (\'pending\',\'in_progress\')) AS overdue
     FROM plan_plans p LEFT JOIN users o ON o.id = p.owner_id
     WHERE ' . implode(' AND ', $conds) . ' ORDER BY p.updated_at DESC LIMIT 300',
    $params
);
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-clipboard2-check me-2"></i>Planos de trabalho</h1>
    <?php if (core_can('plans.create')): ?>
        <a class="btn btn-primary" href="<?= plan_url('plans', ['action' => 'create']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo plano</a>
    <?php endif; ?>
</div>
<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="m" value="planejamento"><input type="hidden" name="page" value="plans">
    <div class="col-12 col-md-3"><input class="form-control" name="q" value="<?= core_e($q) ?>" placeholder="Buscar por título, setor ou descrição"></div>
    <div class="col-6 col-md-2">
        <select class="form-select" name="status" onchange="this.form.submit()">
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Em aberto (rascunho/ativo)</option>
            <option value="" <?= $status === '' ? 'selected' : '' ?>>Todos os status</option>
            <?php foreach (plan_plan_statuses() as $k => $lbl): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select" name="kind" onchange="this.form.submit()">
            <option value="">Todos os tipos</option>
            <?php foreach (plan_plan_kinds() as $k => $lbl): ?><option value="<?= $k ?>" <?= $kindF === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select" name="owner" onchange="this.form.submit()">
            <option value="">Todos os responsáveis</option>
            <?php foreach (plan_users_active() as $u): ?><option value="<?= $u['id'] ?>" <?= $owner === $u['id'] ? 'selected' : '' ?>><?= core_e($u['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Plano</th><th>Tipo</th><th>Responsável</th><th>Período</th><th style="min-width:140px">Progresso</th><th class="text-center">Ações</th><th class="text-center">Status</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php if (!$plans): ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhum plano encontrado.</td></tr><?php endif; ?>
        <?php foreach ($plans as $p): ?>
            <tr>
                <td>
                    <a class="fw-semibold text-decoration-none" href="<?= plan_url('plans', ['action' => 'view', 'id' => $p['id']]) ?>"><?= core_e($p['title']) ?></a>
                    <?php if ($p['sector']): ?><div class="small text-muted"><?= core_e($p['sector']) ?></div><?php endif; ?>
                </td>
                <td class="small"><?= core_e(plan_plan_kinds()[$p['kind']] ?? $p['kind']) ?></td>
                <td class="small"><?= core_e($p['owner_name'] ?: '—') ?></td>
                <td class="small text-nowrap"><?= core_e(trim(plan_date_br($p['start_date']) . ' a ' . plan_date_br($p['end_date']), ' a') ?: '—') ?></td>
                <td><div class="progress" style="height:8px"><div class="progress-bar bg-success" style="width:<?= (int) $p['progress'] ?>%"></div></div><div class="small text-muted"><?= (int) $p['progress'] ?>%</div></td>
                <td class="text-center small"><?= (int) $p['actions_done'] ?>/<?= (int) $p['actions_total'] ?><?php if ($p['overdue']): ?> <span class="badge text-bg-danger" title="atrasadas"><?= (int) $p['overdue'] ?></span><?php endif; ?></td>
                <td class="text-center"><?= plan_status_badge($p['status']) ?></td>
                <td class="text-end text-nowrap">
                    <?php if (core_can('plans.export')): ?><a class="btn btn-sm btn-outline-secondary" title="Imprimir / PDF" target="_blank" href="<?= plan_url('plans', ['action' => 'print', 'id' => $p['id']]) ?>"><i class="bi bi-printer"></i></a><?php endif; ?>
                    <?php if (core_can('plans.edit')): ?><a class="btn btn-sm btn-outline-primary" title="Editar" href="<?= plan_url('plans', ['action' => 'edit', 'id' => $p['id']]) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
                    <?php if (core_can('plans.delete')): ?>
                        <form method="post" action="<?= plan_url('plans') ?>" class="d-inline" onsubmit="return confirm('Excluir o plano e todos os seus itens?')">
                            <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php
plan_page(['title' => 'Planos de trabalho', 'content' => (string) ob_get_clean(), 'active' => 'plans']);
