<?php
/**
 * PLANEJAMENTO — diagramas / fluxogramas / mapas de processo.
 *
 *   page=diagrams                                  lista (filtros: kind, q, scope, plan_id)
 *   page=diagrams&action=create[&template=ID][&plan_id=ID]   novo (GET formulário; POST cria + versão 1 → edit)
 *   page=diagrams&action=edit&id=X                 editor visual (diagrams.edit)
 *   page=diagrams&action=save&id=X                 POST JSON {_csrf_token,title,data,note} → {ok,version}
 *   page=diagrams&action=view&id=X                 somente leitura (SVG estático)
 *   page=diagrams&action=versions&id=X[&v=N]       histórico de versões / ver versão
 *   page=diagrams&action=restore                   POST: restaura versão → nova versão (diagrams.edit)
 *   page=diagrams&action=print&id=X[&size=a4|a3][&v=N]   impressão paisagem (diagrams.export)
 *   page=diagrams&action=export&id=X&format=svg|json[&v=N]  download (diagrams.export)
 *   page=diagrams&action=delete                    POST: exclusão lógica (diagrams.delete)
 *   page=diagrams&action=meta                      POST: título/tipo/descrição/visibilidade/plano (diagrams.edit)
 *   page=diagrams&action=save_template             POST: grava/atualiza modelo kind=diagram (templates.create/edit)
 */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Flash;

require_once __DIR__ . '/../lib/diagram.php';

core_require('diagrams.view');

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$uid    = (int) core_user_id();

// ---------------------------------------------------------------------------
// Helpers locais (prefixo pdg_) — não dependem de helpers do núcleo do módulo
// ---------------------------------------------------------------------------

function pdg_url(array $params = []): string
{
    return function_exists('plan_url')
        ? plan_url('diagrams', $params)
        : core_module_url('planejamento', array_merge(['page' => 'diagrams'], $params));
}

function pdg_page(array $opts): void
{
    $opts['head']   = '<link rel="stylesheet" href="' . core_asset('planejamento/diagram-editor.css') . '?v=1">' . ($opts['head'] ?? '');
    $opts['active'] = $opts['active'] ?? 'diagrams';
    if (function_exists('plan_page')) {
        plan_page($opts);
        return;
    }
    Core\Layout::render($opts);
}

function pdg_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pdg_datetime(?string $dt): string
{
    if (function_exists('plan_datetime_br')) {
        return plan_datetime_br($dt);
    }
    $ts = $dt ? strtotime($dt) : false;
    return $ts ? date('d/m/Y H:i', $ts) : '';
}

function pdg_find(int $id, bool $withDeleted = false): ?array
{
    if ($id <= 0) {
        return null;
    }
    return DB::queryOne(
        'SELECT d.*, c.name AS creator_name, u.name AS updater_name, p.title AS plan_title
         FROM plan_diagrams d
         LEFT JOIN users c ON c.id = d.created_by
         LEFT JOIN users u ON u.id = d.updated_by
         LEFT JOIN plan_plans p ON p.id = d.plan_id
         WHERE d.id = ?' . ($withDeleted ? '' : ' AND d.deleted_at IS NULL'),
        [$id]
    );
}

/** Diagrama privado só para o autor ou quem tem diagrams.delete. */
function pdg_can_view(array $d): bool
{
    if (!empty($d['is_public'])) {
        return true;
    }
    if (function_exists('plan_can_see_private')) {
        return plan_can_see_private((int) ($d['created_by'] ?? 0), 'diagrams');
    }
    $uid = (int) core_user_id();
    return $uid > 0 && ($uid === (int) ($d['created_by'] ?? 0) || core_can('diagrams.delete'));
}

/** Carrega o diagrama ou responde 404/403. */
function pdg_load_or_fail(int $id): array
{
    $d = pdg_find($id);
    if (!$d) {
        Core\Layout::renderError(404, 'Diagrama não encontrado.');
        exit;
    }
    if (!pdg_can_view($d)) {
        Core\Layout::renderError(403, 'Este diagrama é privado.');
        exit;
    }
    return $d;
}

function pdg_templates(): array
{
    return DB::query("SELECT id, name, description, icon, data FROM plan_templates WHERE kind = 'diagram' AND active = 1 ORDER BY sort_order, name");
}

function pdg_plans_for_select(): array
{
    try {
        return DB::query("SELECT id, title FROM plan_plans WHERE deleted_at IS NULL AND status IN ('draft','active') ORDER BY title LIMIT 300");
    } catch (\Throwable) {
        return [];
    }
}

function pdg_plan_exists(int $planId): bool
{
    if ($planId <= 0) {
        return false;
    }
    try {
        return (bool) DB::queryOne('SELECT id FROM plan_plans WHERE id = ? AND deleted_at IS NULL', [$planId]);
    } catch (\Throwable) {
        return false;
    }
}

function pdg_version(int $diagramId, int $version): ?array
{
    return DB::queryOne(
        'SELECT v.*, u.name AS user_name FROM plan_diagram_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.diagram_id = ? AND v.version = ?',
        [$diagramId, $version]
    );
}

function pdg_add_version(int $diagramId, int $version, string $title, string $json, ?string $note, int $uid): void
{
    DB::execute(
        'INSERT INTO plan_diagram_versions (diagram_id, version, title, data, note, created_by) VALUES (?, ?, ?, ?, ?, ?)',
        [$diagramId, $version, mb_substr($title, 0, 200), $json, $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, $uid ?: null]
    );
}

function pdg_thumb(array $data, int $id): ?string
{
    try {
        return plan_diagram_svg($data, ['thumb' => true, 'links' => false, 'id' => 'pdt' . $id]);
    } catch (\Throwable) {
        return null;
    }
}

function pdg_file_name(string $title, string $ext): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $s), '-'));
    return ($s !== '' ? $s : 'diagrama') . '.' . $ext;
}

function pdg_kind_badge(string $kind): string
{
    $kinds = plan_diagram_kinds();
    return '<span class="badge text-bg-light border">' . core_e($kinds[$kind] ?? $kind) . '</span>';
}

// ===========================================================================
// POST: salvar via AJAX (JSON)
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!core_can('diagrams.edit')) {
        pdg_json(['ok' => false, 'error' => 'Sem permissão para editar diagramas.'], 403);
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > PLAN_DIAGRAM_MAX_BYTES) {
        pdg_json(['ok' => false, 'error' => 'Diagrama muito grande (limite de 2 MB).'], 413);
    }
    $payload = json_decode($raw, true, 32);
    if (!is_array($payload)) {
        pdg_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
    }
    if (!Csrf::validate(is_string($payload['_csrf_token'] ?? null) ? $payload['_csrf_token'] : null)) {
        pdg_json(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página e tente novamente.'], 419);
    }
    $id = (int) ($_GET['id'] ?? $payload['id'] ?? 0);
    $d  = pdg_find($id);
    if (!$d) {
        pdg_json(['ok' => false, 'error' => 'Diagrama não encontrado.'], 404);
    }
    if (!pdg_can_view($d)) {
        pdg_json(['ok' => false, 'error' => 'Este diagrama é privado.'], 403);
    }
    $title = mb_substr(trim(plan_diagram_text($payload['title'] ?? $d['title'], 200)), 0, 200);
    if ($title === '') {
        $title = (string) $d['title'];
    }
    $note = trim(plan_diagram_text($payload['note'] ?? '', 255));
    $data = plan_diagram_validate(is_array($payload['data'] ?? null) ? $payload['data'] : []);
    $newJson = plan_diagram_encode($data);
    $curJson = plan_diagram_encode(plan_diagram_decode((string) $d['data']));
    $version = (int) $d['current_version'];
    $changed = $newJson !== $curJson;

    try {
        $version = DB::transaction(function () use ($d, $title, $note, $data, $newJson, $changed, $uid, $version): int {
            $id = (int) $d['id'];
            if ($changed) {
                $version++;
                pdg_add_version($id, $version, $title, $newJson, $note, $uid);
                DB::execute(
                    'UPDATE plan_diagrams SET title = ?, data = ?, thumbnail_svg = ?, current_version = ?, updated_by = ? WHERE id = ?',
                    [$title, $newJson, pdg_thumb($data, $id), $version, $uid ?: null, $id]
                );
            } elseif ($title !== (string) $d['title']) {
                DB::execute('UPDATE plan_diagrams SET title = ?, updated_by = ? WHERE id = ?', [$title, $uid ?: null, $id]);
            }
            return $version;
        });
    } catch (\Throwable $e) {
        pdg_json(['ok' => false, 'error' => 'Erro ao gravar: ' . $e->getMessage()], 500);
    }
    if ($changed) {
        Audit::log('planejamento.diagram_save', 'plan_diagrams', (string) $d['id'], ['title' => $title, 'version' => $version]);
    }
    pdg_json(['ok' => true, 'version' => $version, 'changed' => $changed, 'title' => $title, 'saved_at' => date('d/m/Y H:i')]);
}

// ===========================================================================
// POSTs de formulário
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

    // ---- criar ------------------------------------------------------------
    if ($action === 'create') {
        core_require('diagrams.create');
        $kinds = plan_diagram_kinds();
        $title = mb_substr(trim(plan_diagram_text($_POST['title'] ?? '', 200)), 0, 200);
        $kind  = isset($kinds[$_POST['kind'] ?? '']) ? (string) $_POST['kind'] : 'flowchart';
        $desc  = trim(plan_diagram_text($_POST['description'] ?? '', 5000));
        $tplId = (int) ($_POST['template_id'] ?? 0);
        $plan  = (int) ($_POST['plan_id'] ?? 0);
        $pub   = !empty($_POST['is_public']) ? 1 : 0;
        $tpl   = $tplId > 0 ? DB::queryOne("SELECT * FROM plan_templates WHERE id = ? AND kind = 'diagram' AND active = 1", [$tplId]) : null;
        if ($tplId > 0 && !$tpl) {
            Flash::set('error', 'Modelo não encontrado ou inativo.');
            core_redirect(pdg_url(['action' => 'create']));
        }
        if ($title === '') {
            $title = $tpl ? (string) $tpl['name'] : 'Novo diagrama';
        }
        if ($plan > 0 && !pdg_plan_exists($plan)) {
            $plan = 0;
        }
        $data = $tpl ? plan_diagram_decode((string) $tpl['data']) : plan_diagram_default();
        $json = plan_diagram_encode($data);
        $newId = DB::transaction(function () use ($title, $desc, $kind, $json, $data, $plan, $tpl, $pub, $uid): int {
            DB::execute(
                'INSERT INTO plan_diagrams (title, description, kind, data, thumbnail_svg, plan_id, template_id, is_public, current_version, created_by, updated_by)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 1, ?, ?)',
                [$title, $desc !== '' ? $desc : null, $kind, $json, $plan ?: null, $tpl ? (int) $tpl['id'] : null, $pub, $uid ?: null, $uid ?: null]
            );
            $newId = DB::lastId();
            DB::execute('UPDATE plan_diagrams SET thumbnail_svg = ? WHERE id = ?', [pdg_thumb($data, $newId), $newId]);
            pdg_add_version($newId, 1, $title, $json, $tpl ? 'Criado a partir do modelo "' . $tpl['name'] . '"' : 'Criado em branco', $uid);
            return $newId;
        });
        Audit::log('planejamento.diagram_create', 'plan_diagrams', (string) $newId, ['title' => $title, 'kind' => $kind, 'template_id' => $tpl['id'] ?? null]);
        Flash::set('success', 'Diagrama criado. Edite-o e clique em Salvar para gravar as alterações.');
        core_redirect(pdg_url(core_can('diagrams.edit') ? ['action' => 'edit', 'id' => $newId] : ['action' => 'view', 'id' => $newId]));
    }

    $d = pdg_find($id);
    if (!$d) {
        Flash::set('error', 'Diagrama não encontrado.');
        core_redirect(pdg_url());
    }
    if (!pdg_can_view($d)) {
        Core\Layout::renderError(403, 'Este diagrama é privado.');
        exit;
    }

    // ---- excluir (lógica) -------------------------------------------------
    if ($action === 'delete') {
        core_require('diagrams.delete');
        DB::execute('UPDATE plan_diagrams SET deleted_at = NOW(), updated_by = ? WHERE id = ?', [$uid ?: null, $d['id']]);
        Audit::log('planejamento.diagram_delete', 'plan_diagrams', (string) $d['id'], ['title' => $d['title']]);
        Flash::set('success', 'Diagrama excluído.');
        core_redirect(pdg_url());
    }

    // ---- restaurar versão -------------------------------------------------
    if ($action === 'restore') {
        core_require('diagrams.edit');
        $v   = (int) ($_POST['version'] ?? 0);
        $row = pdg_version((int) $d['id'], $v);
        if (!$row) {
            Flash::set('error', 'Versão não encontrada.');
            core_redirect(pdg_url(['action' => 'versions', 'id' => $d['id']]));
        }
        $data    = plan_diagram_decode((string) $row['data']);
        $json    = plan_diagram_encode($data);
        $version = (int) $d['current_version'] + 1;
        $title   = (string) $d['title']; // restaura o desenho; o título atual é mantido
        DB::transaction(function () use ($d, $version, $title, $json, $data, $v, $uid): void {
            pdg_add_version((int) $d['id'], $version, $title, $json, 'Restaurado da versão ' . $v, $uid);
            DB::execute(
                'UPDATE plan_diagrams SET data = ?, thumbnail_svg = ?, current_version = ?, updated_by = ? WHERE id = ?',
                [$json, pdg_thumb($data, (int) $d['id']), $version, $uid ?: null, $d['id']]
            );
        });
        Audit::log('planejamento.diagram_restore', 'plan_diagrams', (string) $d['id'], ['from_version' => $v, 'version' => $version]);
        Flash::set('success', 'Versão ' . $v . ' restaurada como versão ' . $version . '.');
        core_redirect(pdg_url(['action' => 'versions', 'id' => $d['id']]));
    }

    // ---- propriedades (título/tipo/descrição/visibilidade/plano) ----------
    if ($action === 'meta') {
        core_require('diagrams.edit');
        $kinds = plan_diagram_kinds();
        $title = mb_substr(trim(plan_diagram_text($_POST['title'] ?? $d['title'], 200)), 0, 200) ?: (string) $d['title'];
        $kind  = isset($kinds[$_POST['kind'] ?? '']) ? (string) $_POST['kind'] : (string) $d['kind'];
        $desc  = trim(plan_diagram_text($_POST['description'] ?? '', 5000));
        $plan  = (int) ($_POST['plan_id'] ?? 0);
        $pub   = !empty($_POST['is_public']) ? 1 : 0;
        if ($plan > 0 && !pdg_plan_exists($plan)) {
            $plan = 0;
        }
        DB::execute(
            'UPDATE plan_diagrams SET title = ?, kind = ?, description = ?, plan_id = ?, is_public = ?, updated_by = ? WHERE id = ?',
            [$title, $kind, $desc !== '' ? $desc : null, $plan ?: null, $pub, $uid ?: null, $d['id']]
        );
        Audit::log('planejamento.diagram_update', 'plan_diagrams', (string) $d['id'], ['title' => $title, 'kind' => $kind, 'is_public' => $pub]);
        Flash::set('success', 'Propriedades atualizadas.');
        $back = ($_POST['back'] ?? '') === 'view' ? 'view' : 'edit';
        core_redirect(pdg_url(['action' => $back, 'id' => $d['id']]));
    }

    // ---- salvar como modelo -----------------------------------------------
    if ($action === 'save_template') {
        core_require('templates.create');
        $name  = mb_substr(trim(plan_diagram_text($_POST['name'] ?? '', 150)), 0, 150);
        $desc  = mb_substr(trim(plan_diagram_text($_POST['description'] ?? '', 500)), 0, 500);
        $tplId = (int) ($_POST['template_id'] ?? 0);
        $rawData = (string) ($_POST['data'] ?? '');
        if ($name === '') {
            $name = (string) $d['title'];
        }
        // JSON atual do editor (se enviado e válido) ou o gravado no banco
        $data = null;
        if ($rawData !== '' && strlen($rawData) <= PLAN_DIAGRAM_MAX_BYTES) {
            $dec = json_decode($rawData, true, 32);
            if (is_array($dec)) {
                $data = plan_diagram_validate($dec);
            }
        }
        $data ??= plan_diagram_decode((string) $d['data']);
        $json = plan_diagram_encode($data);
        $iconMap = ['flowchart' => 'bi-diagram-3', 'process_map' => 'bi-layout-three-columns', 'org_chart' => 'bi-diagram-2', 'mind_map' => 'bi-share', 'swot' => 'bi-grid-1x2'];
        $icon    = $iconMap[$d['kind']] ?? 'bi-diagram-3';
        if ($tplId > 0) {
            core_require('templates.edit');
            $tpl = DB::queryOne("SELECT id, description FROM plan_templates WHERE id = ? AND kind = 'diagram'", [$tplId]);
            if (!$tpl) {
                Flash::set('error', 'Modelo não encontrado.');
                core_redirect(pdg_url(['action' => 'edit', 'id' => $d['id']]));
            }
            if ($desc === '') {
                $desc = (string) ($tpl['description'] ?? '');
            }
            DB::execute('UPDATE plan_templates SET name = ?, description = ?, data = ? WHERE id = ?', [$name, $desc !== '' ? $desc : null, $json, $tplId]);
            Audit::log('planejamento.template_update', 'plan_templates', (string) $tplId, ['name' => $name, 'from_diagram' => $d['id']]);
            Flash::set('success', 'Modelo "' . $name . '" atualizado com o diagrama atual.');
        } else {
            $order = (int) (DB::queryOne("SELECT COALESCE(MAX(sort_order), 0) + 1 AS o FROM plan_templates WHERE kind = 'diagram'")['o'] ?? 1);
            DB::execute(
                "INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, active, sort_order, created_by) VALUES ('diagram', ?, ?, ?, ?, 0, 1, ?, ?)",
                [$name, $desc !== '' ? $desc : null, $icon, $json, $order, $uid ?: null]
            );
            $tplId = DB::lastId();
            Audit::log('planejamento.template_create', 'plan_templates', (string) $tplId, ['name' => $name, 'from_diagram' => $d['id']]);
            Flash::set('success', 'Modelo "' . $name . '" criado a partir deste diagrama.');
        }
        core_redirect(pdg_url(['action' => core_can('diagrams.edit') ? 'edit' : 'view', 'id' => $d['id']]));
    }

    Flash::set('error', 'Ação inválida.');
    core_redirect(pdg_url());
}

// ===========================================================================
// GET: novo diagrama (escolher modelo / em branco)
// ===========================================================================
if ($action === 'create') {
    core_require('diagrams.create');
    $kinds     = plan_diagram_kinds();
    $templates = pdg_templates();
    $plans     = pdg_plans_for_select();
    $selTpl    = (int) ($_GET['template'] ?? 0);
    $selPlan   = (int) ($_GET['plan_id'] ?? 0);
    $selKind   = isset($kinds[$_GET['kind'] ?? '']) ? (string) $_GET['kind'] : 'flowchart';
    $tplKind   = ['Fluxograma' => 'flowchart', 'Mapa de processo' => 'process_map', 'SIPOC' => 'process_map', 'SWOT' => 'swot', 'Organograma' => 'org_chart'];
    ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i>Novo diagrama</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= pdg_url() ?>"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
</div>
<form method="post" action="<?= pdg_url(['action' => 'create']) ?>" id="pdgCreate">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="pdgTitleInput">Título</label>
                    <input class="form-control" id="pdgTitleInput" name="title" maxlength="200" placeholder="Ex.: Fluxo de admissão do paciente" autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="pdgKind">Tipo</label>
                    <select class="form-select" id="pdgKind" name="kind">
                        <?php foreach ($kinds as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $selKind ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="pdgDesc">Descrição <span class="text-muted">(opcional)</span></label>
                    <textarea class="form-control" id="pdgDesc" name="description" rows="3"></textarea>
                </div>
                <?php if ($plans): ?>
                <div class="mb-3">
                    <label class="form-label" for="pdgPlan">Plano de trabalho vinculado</label>
                    <select class="form-select" id="pdgPlan" name="plan_id">
                        <option value="">— nenhum —</option>
                        <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $selPlan ? 'selected' : '' ?>><?= core_e($p['title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="pdgPublic" name="is_public" value="1" checked>
                    <label class="form-check-label" for="pdgPublic">Público (visível a todos com acesso aos diagramas)</label>
                    <div class="form-text">Desmarcado: só você e os gestores do módulo veem este diagrama.</div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Criar e abrir no editor</button>
            </div></div>
        </div>
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <h2 class="h6 mb-3">Começar a partir de</h2>
                <div class="row g-2">
                    <div class="col-6 col-md-4">
                        <label class="card h-100 pdg-tpl-pick <?= $selTpl === 0 ? 'active' : '' ?>">
                            <input type="radio" name="template_id" value="0" class="d-none" <?= $selTpl === 0 ? 'checked' : '' ?>>
                            <div class="pdg-thumb d-flex align-items-center justify-content-center text-muted"><i class="bi bi-file-earmark fs-1"></i></div>
                            <div class="card-body p-2"><div class="fw-semibold small">Em branco</div><div class="small text-muted">Comece do zero.</div></div>
                        </label>
                    </div>
                    <?php foreach ($templates as $t):
                        $tdata = plan_diagram_decode((string) $t['data']); ?>
                    <div class="col-6 col-md-4">
                        <label class="card h-100 pdg-tpl-pick pdg-card <?= (int) $t['id'] === $selTpl ? 'active' : '' ?>" data-kind="<?= core_e($tplKind[$t['name']] ?? '') ?>">
                            <input type="radio" name="template_id" value="<?= (int) $t['id'] ?>" class="d-none" <?= (int) $t['id'] === $selTpl ? 'checked' : '' ?>>
                            <div class="pdg-thumb"><?= plan_diagram_svg($tdata, ['thumb' => true, 'links' => false, 'id' => 'tpl' . (int) $t['id']]) ?></div>
                            <div class="card-body p-2">
                                <div class="fw-semibold small"><i class="bi <?= core_e($t['icon'] ?: 'bi-diagram-3') ?> me-1"></i><?= core_e($t['name']) ?></div>
                                <?php if ($t['description']): ?><div class="small text-muted"><?= core_e(mb_strimwidth((string) $t['description'], 0, 110, '…')) ?></div><?php endif; ?>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div></div>
        </div>
    </div>
</form>
<script>
(function () {
    var picks = document.querySelectorAll('.pdg-tpl-pick'), kind = document.getElementById('pdgKind');
    picks.forEach(function (p) {
        p.addEventListener('click', function () {
            picks.forEach(function (o) { o.classList.remove('active'); });
            p.classList.add('active');
            var r = p.querySelector('input[type=radio]'); if (r) { r.checked = true; }
            if (p.dataset.kind && kind) { kind.value = p.dataset.kind; }
        });
    });
})();
</script>
    <?php
    pdg_page(['title' => 'Novo diagrama', 'content' => (string) ob_get_clean()]);
    exit;
}

// ===========================================================================
// GET: editor
// ===========================================================================
if ($action === 'edit') {
    $d = pdg_load_or_fail((int) ($_GET['id'] ?? 0));
    if (!core_can('diagrams.edit')) {
        core_redirect(pdg_url(['action' => 'view', 'id' => $d['id']]));
    }
    $data   = plan_diagram_decode((string) $d['data']);
    $kinds  = plan_diagram_kinds();
    $plans  = pdg_plans_for_select();
    $tpls   = core_can('templates.edit') ? DB::query("SELECT id, name FROM plan_templates WHERE kind = 'diagram' ORDER BY sort_order, name") : [];
    $cfg    = [
        'id'       => (int) $d['id'],
        'title'    => (string) $d['title'],
        'version'  => (int) $d['current_version'],
        'data'     => $data,
        'saveUrl'  => pdg_url(['action' => 'save', 'id' => $d['id']]),
        'csrf'     => Csrf::token(),
        'fileName' => pdg_file_name((string) $d['title'], 'x'),
        'canExport' => core_can('diagrams.export'),
    ];
    $cfg['fileName'] = substr($cfg['fileName'], 0, -2);
    ob_start(); ?>
<div class="pdg-edit">
    <div class="pdg-head">
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url() ?>" title="Voltar à lista"><i class="bi bi-arrow-left"></i><span class="d-none d-xl-inline ms-1">Voltar</span></a>
        <input class="form-control form-control-sm pdg-title" id="pdgTitle" value="<?= core_e($d['title']) ?>" maxlength="200" placeholder="Título do diagrama" aria-label="Título do diagrama">
        <span class="badge text-bg-light border pdg-kind"><?= core_e($kinds[$d['kind']] ?? $d['kind']) ?></span>
        <span class="badge text-bg-secondary" id="pdgVersion" title="Versão atual">v<?= (int) $d['current_version'] ?></span>
        <?php if (!$d['is_public']): ?><span class="badge text-bg-warning" title="Privado"><i class="bi bi-lock"></i></span><?php endif; ?>
        <span class="flex-grow-1"></span>
        <button type="button" class="btn btn-sm btn-primary" id="pdgSave"><i class="bi bi-save me-1"></i>Salvar</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url(['action' => 'versions', 'id' => $d['id']]) ?>" id="pdgVersions"><i class="bi bi-clock-history me-1"></i>Versões</a>
        <?php if (core_can('diagrams.export')): ?>
        <div class="btn-group btn-group-sm" role="group" aria-label="Exportar">
            <button type="button" class="btn btn-outline-secondary" id="pdgExpSvg" title="Exportar SVG"><i class="bi bi-filetype-svg me-1"></i>SVG</button>
            <button type="button" class="btn btn-outline-secondary" id="pdgExpPng" title="Exportar PNG"><i class="bi bi-filetype-png me-1"></i>PNG</button>
            <a class="btn btn-outline-secondary" href="<?= pdg_url(['action' => 'export', 'id' => $d['id'], 'format' => 'json']) ?>" title="Baixar JSON (versão salva)"><i class="bi bi-filetype-json"></i></a>
            <button type="button" class="btn btn-outline-secondary" id="pdgPrint" title="Imprimir"><i class="bi bi-printer me-1"></i>Imprimir</button>
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="pdgMetaBtn" title="Propriedades do diagrama"><i class="bi bi-sliders"></i></button>
        <?php if (core_can('templates.create')): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="pdgTplBtn" title="Salvar como modelo"><i class="bi bi-bookmark-plus"></i></button>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url(['action' => 'view', 'id' => $d['id']]) ?>" title="Visualizar (somente leitura)"><i class="bi bi-eye"></i></a>
    </div>
    <div id="pdgEditor"></div>
</div>

<dialog class="pdg-dialog" id="pdgMeta">
    <form method="post" action="<?= pdg_url(['action' => 'meta', 'id' => $d['id']]) ?>" id="pdgMetaForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="meta"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="title" id="pdgMetaTitle" value="<?= core_e($d['title']) ?>">
        <h2 class="h6 mb-3"><i class="bi bi-sliders me-1"></i>Propriedades do diagrama</h2>
        <div class="mb-2"><label class="form-label small mb-1">Tipo</label>
            <select class="form-select form-select-sm" name="kind"><?php foreach ($kinds as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $d['kind'] ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label small mb-1">Descrição</label><textarea class="form-control form-control-sm" name="description" rows="3"><?= core_e($d['description']) ?></textarea></div>
        <?php if ($plans || $d['plan_id']): ?>
        <div class="mb-2"><label class="form-label small mb-1">Plano de trabalho</label>
            <select class="form-select form-select-sm" name="plan_id"><option value="">— nenhum —</option>
                <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $d['plan_id'] ? 'selected' : '' ?>><?= core_e($p['title']) ?></option><?php endforeach; ?>
                <?php if ($d['plan_id'] && !in_array((int) $d['plan_id'], array_map('intval', array_column($plans, 'id')), true)): ?><option value="<?= (int) $d['plan_id'] ?>" selected><?= core_e($d['plan_title'] ?: ('Plano #' . $d['plan_id'])) ?></option><?php endif; ?>
            </select></div>
        <?php endif; ?>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_public" value="1" id="pdgMetaPublic" <?= $d['is_public'] ? 'checked' : '' ?>><label class="form-check-label" for="pdgMetaPublic">Público</label></div>
        <div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-close>Cancelar</button><button class="btn btn-sm btn-primary" type="submit">Gravar</button></div>
    </form>
</dialog>

<?php if (core_can('templates.create')): ?>
<dialog class="pdg-dialog" id="pdgTpl">
    <form method="post" action="<?= pdg_url(['action' => 'save_template', 'id' => $d['id']]) ?>" id="pdgTplForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_template"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="data" id="pdgTplData">
        <h2 class="h6 mb-3"><i class="bi bi-bookmark-plus me-1"></i>Salvar como modelo</h2>
        <p class="small text-muted">O desenho atual do editor será gravado como modelo reutilizável em “Novo diagrama”.</p>
        <?php if ($tpls): ?>
        <div class="mb-2"><label class="form-label small mb-1">Modelo</label>
            <select class="form-select form-select-sm" name="template_id" id="pdgTplSel"><option value="0">— criar novo modelo —</option>
                <?php foreach ($tpls as $t): ?><option value="<?= (int) $t['id'] ?>" data-name="<?= core_e($t['name']) ?>">Atualizar: <?= core_e($t['name']) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <div class="mb-2"><label class="form-label small mb-1">Nome</label><input class="form-control form-control-sm" name="name" id="pdgTplName" maxlength="150" value="<?= core_e($d['title']) ?>" required></div>
        <div class="mb-3"><label class="form-label small mb-1">Descrição</label><input class="form-control form-control-sm" name="description" maxlength="500" value="<?= core_e($d['description']) ?>"></div>
        <div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-close>Cancelar</button><button class="btn btn-sm btn-primary" type="submit">Gravar modelo</button></div>
    </form>
</dialog>
<?php endif; ?>
<script>window.PDG_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;</script>
<script>
(function () {
    var cfg = window.PDG_CONFIG, titleEl = document.getElementById('pdgTitle'), verEl = document.getElementById('pdgVersion');
    var baseTitle = document.title;
    function toast(msg, cls) {
        var t = document.createElement('div'); t.className = 'pdg-toast ' + (cls || ''); t.textContent = msg; document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('show'); });
        setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 400); }, 2600);
    }
    var ed = PlanDiagramEditor.init(document.getElementById('pdgEditor'), {
        data: cfg.data, saveUrl: cfg.saveUrl, csrf: cfg.csrf, version: cfg.version, fileName: cfg.fileName,
        getTitle: function () { return titleEl.value; },
        onSaved: function (resp) {
            verEl.textContent = 'v' + resp.version;
            document.getElementById('pdgMetaTitle').value = titleEl.value;
            toast(resp.changed ? 'Diagrama salvo (versão ' + resp.version + ').' : 'Nenhuma alteração no desenho — título atualizado.');
        },
        onDirty: function (dirty) { document.title = (dirty ? '● ' : '') + baseTitle; }
    });
    window.PDG_EDITOR = ed;
    titleEl.addEventListener('input', function () { ed.titleChanged(); });
    titleEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); titleEl.blur(); } });
    document.getElementById('pdgSave').addEventListener('click', function () { ed.save(); });
    var b;
    if ((b = document.getElementById('pdgExpSvg'))) { b.addEventListener('click', function () { ed.exportSvg(); }); }
    if ((b = document.getElementById('pdgExpPng'))) { b.addEventListener('click', function () { ed.exportPng(function (ok) { if (!ok) { toast('Não foi possível gerar o PNG neste navegador.', 'error'); } }); }); }
    if ((b = document.getElementById('pdgPrint'))) { b.addEventListener('click', function () { ed.print(); }); }
    // diálogos nativos (<dialog>) — sem dependências
    function openDialog(id, before) {
        var dlg = document.getElementById(id);
        if (!dlg) { return; }
        if (before) { before(dlg); }
        if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    }
    document.querySelectorAll('.pdg-dialog [data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { var dlg = btn.closest('dialog'); if (dlg.close) { dlg.close(); } else { dlg.removeAttribute('open'); } });
    });
    document.getElementById('pdgMetaBtn').addEventListener('click', function () {
        openDialog('pdgMeta', function () { document.getElementById('pdgMetaTitle').value = titleEl.value; });
    });
    // grava o desenho antes de enviar formulários que recarregam a página
    function submitAfterSave(form) {
        if (!ed.dirty) { return true; }
        ed.save().then(function (resp) { if (resp && resp.ok) { form.submit(); } });
        return false;
    }
    document.getElementById('pdgMetaForm').addEventListener('submit', function (ev) {
        document.getElementById('pdgMetaTitle').value = titleEl.value;
        if (!submitAfterSave(this)) { ev.preventDefault(); }
    });
    var tplBtn = document.getElementById('pdgTplBtn');
    if (tplBtn) {
        tplBtn.addEventListener('click', function () { openDialog('pdgTpl', function () { document.getElementById('pdgTplName').value = document.getElementById('pdgTplName').value || titleEl.value; }); });
        var sel = document.getElementById('pdgTplSel');
        if (sel) { sel.addEventListener('change', function () { var o = sel.options[sel.selectedIndex]; document.getElementById('pdgTplName').value = o.dataset.name || titleEl.value; }); }
        document.getElementById('pdgTplForm').addEventListener('submit', function () {
            // o modelo usa o JSON atual do editor (o aviso de "alterações não salvas" continua valendo)
            document.getElementById('pdgTplData').value = JSON.stringify(ed.toJSON());
        });
    }
})();
</script>
    <?php
    pdg_page([
        'title'   => $d['title'],
        'content' => (string) ob_get_clean(),
        'fluid'   => true,
        'head'    => '<script src="' . core_asset('planejamento/diagram-editor.js') . '?v=1"></script>',
    ]);
    exit;
}

// ===========================================================================
// GET: visualização somente leitura
// ===========================================================================
if ($action === 'view') {
    $d     = pdg_load_or_fail((int) ($_GET['id'] ?? 0));
    $data  = plan_diagram_decode((string) $d['data']);
    $kinds = plan_diagram_kinds();
    $svg   = plan_diagram_svg($data, ['fit' => true, 'width' => '100%', 'height' => '100%', 'links' => true, 'id' => 'pdv']);
    $fname = pdg_file_name((string) $d['title'], 'png');
    ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><i class="bi bi-diagram-3 me-2"></i><?= core_e($d['title']) ?>
            <?= pdg_kind_badge((string) $d['kind']) ?>
            <span class="badge text-bg-secondary">v<?= (int) $d['current_version'] ?></span>
            <?php if (!$d['is_public']): ?><span class="badge text-bg-warning"><i class="bi bi-lock me-1"></i>Privado</span><?php endif; ?>
        </h1>
        <div class="small text-muted">
            Criado por <?= core_e($d['creator_name'] ?: '—') ?> em <?= pdg_datetime($d['created_at']) ?> · atualizado <?= pdg_datetime($d['updated_at']) ?><?= $d['updater_name'] ? ' por ' . core_e($d['updater_name']) : '' ?>
            <?php if ($d['plan_id']): ?> · <i class="bi bi-clipboard2-check"></i> <a href="<?= core_module_url('planejamento', ['page' => 'plans', 'action' => 'view', 'id' => $d['plan_id']]) ?>"><?= core_e($d['plan_title'] ?: ('Plano #' . $d['plan_id'])) ?></a><?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-1">
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url() ?>"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
        <?php if (core_can('diagrams.edit')): ?><a class="btn btn-sm btn-primary" href="<?= pdg_url(['action' => 'edit', 'id' => $d['id']]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url(['action' => 'versions', 'id' => $d['id']]) ?>"><i class="bi bi-clock-history me-1"></i>Versões</a>
        <?php if (core_can('diagrams.export')): ?>
        <div class="btn-group btn-group-sm" role="group">
            <a class="btn btn-outline-secondary" href="<?= pdg_url(['action' => 'export', 'id' => $d['id'], 'format' => 'svg']) ?>"><i class="bi bi-filetype-svg me-1"></i>SVG</a>
            <button type="button" class="btn btn-outline-secondary" id="pdgExpPng"><i class="bi bi-filetype-png me-1"></i>PNG</button>
            <a class="btn btn-outline-secondary" href="<?= pdg_url(['action' => 'export', 'id' => $d['id'], 'format' => 'json']) ?>"><i class="bi bi-filetype-json me-1"></i>JSON</a>
            <a class="btn btn-outline-secondary" href="<?= pdg_url(['action' => 'print', 'id' => $d['id']]) ?>" target="_blank"><i class="bi bi-printer me-1"></i>Imprimir</a>
        </div>
        <?php endif; ?>
        <?php if (core_can('diagrams.delete')): ?>
        <form method="post" action="<?= pdg_url(['action' => 'delete']) ?>" class="d-inline" onsubmit="return confirm('Excluir este diagrama?')"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Excluir</button></form>
        <?php endif; ?>
    </div>
</div>
<?php if ($d['description']): ?><p class="text-muted"><?= nl2br(core_e($d['description'])) ?></p><?php endif; ?>
<div class="pdg-view" id="pdgView"><?= $svg ?></div>
<script>
(function () {
    var b = document.getElementById('pdgExpPng');
    if (b && window.PlanDiagramEditor) {
        b.addEventListener('click', function () {
            var svg = document.querySelector('#pdgView svg');
            PlanDiagramEditor.downloadPng(svg, <?= json_encode($fname) ?>, function (ok) { if (!ok) { alert('Não foi possível gerar o PNG neste navegador.'); } });
        });
    }
})();
</script>
    <?php
    pdg_page([
        'title'   => $d['title'],
        'content' => (string) ob_get_clean(),
        'head'    => core_can('diagrams.export') ? '<script src="' . core_asset('planejamento/diagram-editor.js') . '?v=1"></script>' : '',
    ]);
    exit;
}

// ===========================================================================
// GET: versões
// ===========================================================================
if ($action === 'versions') {
    $d        = pdg_load_or_fail((int) ($_GET['id'] ?? 0));
    $versions = DB::query(
        'SELECT v.id, v.version, v.title, v.note, v.created_at, u.name AS user_name, LENGTH(v.data) AS bytes
         FROM plan_diagram_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.diagram_id = ? ORDER BY v.version DESC',
        [$d['id']]
    );
    $selV = (int) ($_GET['v'] ?? 0);
    $sel  = $selV > 0 ? pdg_version((int) $d['id'], $selV) : null;
    if ($selV > 0 && !$sel) {
        Flash::set('error', 'Versão não encontrada.');
        core_redirect(pdg_url(['action' => 'versions', 'id' => $d['id']]));
    }
    $showData = $sel ? plan_diagram_decode((string) $sel['data']) : plan_diagram_decode((string) $d['data']);
    $svg      = plan_diagram_svg($showData, ['fit' => true, 'width' => '100%', 'height' => '100%', 'links' => false, 'id' => 'pdv']);
    ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Versões — <?= core_e($d['title']) ?> <span class="badge text-bg-secondary">atual v<?= (int) $d['current_version'] ?></span></h1>
    <div class="d-flex gap-1">
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url(['action' => 'view', 'id' => $d['id']]) ?>"><i class="bi bi-eye me-1"></i>Visualizar</a>
        <?php if (core_can('diagrams.edit')): ?><a class="btn btn-sm btn-primary" href="<?= pdg_url(['action' => 'edit', 'id' => $d['id']]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= pdg_url() ?>"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Versão</th><th>Quando / quem</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php if (!$versions): ?><tr><td colspan="3" class="text-center text-muted py-3">Nenhuma versão gravada.</td></tr><?php endif; ?>
                <?php foreach ($versions as $v): $isCur = (int) $v['version'] === (int) $d['current_version']; ?>
                    <tr class="<?= $selV === (int) $v['version'] ? 'table-primary' : '' ?>">
                        <td><a class="fw-semibold text-decoration-none" href="<?= pdg_url(['action' => 'versions', 'id' => $d['id'], 'v' => $v['version']]) ?>">v<?= (int) $v['version'] ?></a>
                            <?php if ($isCur): ?><span class="badge text-bg-success ms-1">atual</span><?php endif; ?>
                            <?php if ($v['note']): ?><div class="small text-muted"><?= core_e($v['note']) ?></div><?php endif; ?>
                            <?php if ($v['title'] !== $d['title']): ?><div class="small text-muted fst-italic"><?= core_e($v['title']) ?></div><?php endif; ?></td>
                        <td class="small text-muted"><?= pdg_datetime($v['created_at']) ?><br><?= core_e($v['user_name'] ?: '—') ?> · <?= number_format((int) $v['bytes'] / 1024, 1, ',', '.') ?> KB</td>
                        <td class="text-end text-nowrap">
                            <?php if (core_can('diagrams.export')): ?><a class="btn btn-sm btn-outline-secondary" title="Baixar SVG desta versão" href="<?= pdg_url(['action' => 'export', 'id' => $d['id'], 'format' => 'svg', 'v' => $v['version']]) ?>"><i class="bi bi-download"></i></a><?php endif; ?>
                            <?php if (core_can('diagrams.edit') && !$isCur): ?>
                            <form method="post" action="<?= pdg_url(['action' => 'restore']) ?>" class="d-inline" onsubmit="return confirm('Restaurar a versão <?= (int) $v['version'] ?>? Uma nova versão será criada.')">
                                <?= Csrf::field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="version" value="<?= (int) $v['version'] ?>">
                                <button class="btn btn-sm btn-outline-primary" title="Restaurar esta versão"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="small text-muted mb-1"><?= $sel ? 'Versão ' . (int) $sel['version'] . ' — ' . pdg_datetime($sel['created_at']) : 'Versão atual (v' . (int) $d['current_version'] . ')' ?></div>
        <div class="pdg-view"><?= $svg ?></div>
    </div>
</div>
    <?php
    pdg_page(['title' => 'Versões — ' . $d['title'], 'content' => (string) ob_get_clean()]);
    exit;
}

// ===========================================================================
// GET: impressão (A4/A3 paisagem)
// ===========================================================================
if ($action === 'print') {
    core_require('diagrams.export');
    $d    = pdg_load_or_fail((int) ($_GET['id'] ?? 0));
    $v    = (int) ($_GET['v'] ?? 0);
    $row  = $v > 0 ? pdg_version((int) $d['id'], $v) : null;
    $data = plan_diagram_decode((string) ($row ? $row['data'] : $d['data']));
    $size = ($_GET['size'] ?? 'a4') === 'a3' ? 'A3' : 'A4';
    $auto = ($_GET['auto'] ?? '1') !== '0';
    $svg  = plan_diagram_svg($data, ['fit' => true, 'width' => '100%', 'height' => '100%', 'links' => false, 'id' => 'pdp']);
    $title = (string) $d['title'] . ($row ? ' (v' . $v . ')' : '');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title><?= core_e($title) ?></title>
<style>
@page { size: <?= $size ?> landscape; margin: 10mm; }
html, body { margin: 0; padding: 0; background: #fff; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
.bar { position: fixed; top: 0; left: 0; right: 0; background: #212529; color: #fff; padding: 6px 12px; font-size: 13px; display: flex; gap: 8px; align-items: center; z-index: 2; }
.bar a, .bar button { font: inherit; padding: 4px 10px; border-radius: 4px; border: 1px solid #6c757d; background: #343a40; color: #fff; text-decoration: none; cursor: pointer; }
.bar a.active { background: #0d6efd; border-color: #0d6efd; }
.pg { padding: 48px 12px 12px; box-sizing: border-box; width: 100%; height: 100vh; display: flex; flex-direction: column; }
.pg h1 { font-size: 15px; margin: 0 0 6px; font-weight: 600; }
.pg .meta { font-size: 11px; color: #6c757d; margin-bottom: 8px; }
.pg .fig { flex: 1 1 auto; min-height: 0; display: flex; align-items: center; justify-content: center; }
.pg svg { max-width: 100%; max-height: 100%; width: auto; height: auto; }
@media print { .bar { display: none; } .pg { padding: 0; height: auto; } .pg .fig { height: calc(100vh - 40px); } }
</style>
</head>
<body>
<div class="bar">
    <span><?= core_e($title) ?></span>
    <span style="flex:1"></span>
    <a href="<?= pdg_url(['action' => 'print', 'id' => $d['id'], 'v' => $v ?: null, 'size' => 'a4', 'auto' => '0']) ?>" class="<?= $size === 'A4' ? 'active' : '' ?>">A4</a>
    <a href="<?= pdg_url(['action' => 'print', 'id' => $d['id'], 'v' => $v ?: null, 'size' => 'a3', 'auto' => '0']) ?>" class="<?= $size === 'A3' ? 'active' : '' ?>">A3</a>
    <button type="button" onclick="window.print()">Imprimir / PDF</button>
    <button type="button" onclick="window.close()">Fechar</button>
</div>
<div class="pg">
    <h1><?= core_e($title) ?></h1>
    <div class="meta"><?= core_e(plan_diagram_kinds()[$d['kind']] ?? $d['kind']) ?> · <?= core_e($d['creator_name'] ?: '') ?> · atualizado em <?= pdg_datetime($row ? $row['created_at'] : $d['updated_at']) ?> · <?= $size ?> paisagem</div>
    <div class="fig"><?= $svg ?></div>
</div>
<?php if ($auto): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script><?php endif; ?>
</body>
</html>
    <?php
    exit;
}

// ===========================================================================
// GET: exportar (svg | json)
// ===========================================================================
if ($action === 'export') {
    core_require('diagrams.export');
    $d      = pdg_load_or_fail((int) ($_GET['id'] ?? 0));
    $v      = (int) ($_GET['v'] ?? 0);
    $row    = $v > 0 ? pdg_version((int) $d['id'], $v) : null;
    $data   = plan_diagram_decode((string) ($row ? $row['data'] : $d['data']));
    $format = ($_GET['format'] ?? 'svg') === 'json' ? 'json' : 'svg';
    $suffix = $row ? '-v' . $v : '';
    Audit::log('planejamento.diagram_export', 'plan_diagrams', (string) $d['id'], ['format' => $format, 'version' => $v ?: (int) $d['current_version']]);
    if ($format === 'json') {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . pdg_file_name((string) $d['title'] . $suffix, 'json') . '"');
    } else {
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . plan_diagram_svg($data, ['fit' => true, 'links' => true, 'id' => 'pd']);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . pdg_file_name((string) $d['title'] . $suffix, 'svg') . '"');
    }
    header('Content-Length: ' . strlen((string) $body));
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

// ===========================================================================
// GET: lista
// ===========================================================================
$kinds  = plan_diagram_kinds();
$kind   = isset($kinds[$_GET['kind'] ?? '']) ? (string) $_GET['kind'] : '';
$q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$scope  = ($_GET['scope'] ?? 'all') === 'mine' ? 'mine' : 'all';
$planId = (int) ($_GET['plan_id'] ?? 0);
$conds  = ['d.deleted_at IS NULL'];
$params = [];
if (!core_can('diagrams.delete')) {
    $conds[]  = '(d.is_public = 1 OR d.created_by = ?)';
    $params[] = $uid;
}
if ($scope === 'mine') {
    $conds[]  = 'd.created_by = ?';
    $params[] = $uid;
}
if ($kind !== '') {
    $conds[]  = 'd.kind = ?';
    $params[] = $kind;
}
if ($q !== '') {
    $conds[]  = '(d.title LIKE ? OR d.description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($planId > 0) {
    $conds[]  = 'd.plan_id = ?';
    $params[] = $planId;
}
$rows = DB::query(
    'SELECT d.id, d.title, d.description, d.kind, d.is_public, d.current_version, d.plan_id, d.created_by, d.updated_at, d.thumbnail_svg,
            c.name AS creator_name, p.title AS plan_title
     FROM plan_diagrams d LEFT JOIN users c ON c.id = d.created_by LEFT JOIN plan_plans p ON p.id = d.plan_id
     WHERE ' . implode(' AND ', $conds) . ' ORDER BY d.updated_at DESC LIMIT 120',
    $params
);
$canEdit = core_can('diagrams.edit');
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i>Fluxogramas e diagramas</h1>
    <?php if (core_can('diagrams.create')): ?><a class="btn btn-primary" href="<?= pdg_url(['action' => 'create'] + ($planId ? ['plan_id' => $planId] : [])) ?>"><i class="bi bi-plus-lg me-1"></i>Novo diagrama</a><?php endif; ?>
</div>
<form method="get" action="<?= core_url('index.php') ?>" class="card mb-3"><div class="card-body py-2">
    <input type="hidden" name="m" value="planejamento"><input type="hidden" name="page" value="diagrams">
    <?php if ($planId): ?><input type="hidden" name="plan_id" value="<?= $planId ?>"><?php endif; ?>
    <div class="row g-2 align-items-center">
        <div class="col-auto">
            <ul class="nav nav-pills nav-sm mb-0">
                <li class="nav-item"><a class="nav-link py-1 <?= $scope === 'all' ? 'active' : '' ?>" href="<?= pdg_url(['kind' => $kind ?: null, 'q' => $q ?: null, 'plan_id' => $planId ?: null]) ?>">Todos</a></li>
                <li class="nav-item"><a class="nav-link py-1 <?= $scope === 'mine' ? 'active' : '' ?>" href="<?= pdg_url(['scope' => 'mine', 'kind' => $kind ?: null, 'q' => $q ?: null, 'plan_id' => $planId ?: null]) ?>">Meus diagramas</a></li>
            </ul>
            <?php if ($scope === 'mine'): ?><input type="hidden" name="scope" value="mine"><?php endif; ?>
        </div>
        <div class="col-sm-3 col-lg-2">
            <select class="form-select form-select-sm" name="kind" onchange="this.form.submit()">
                <option value="">Todos os tipos</option>
                <?php foreach ($kinds as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $kind ? 'selected' : '' ?>><?= core_e($lbl) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-4 col-lg-3">
            <div class="input-group input-group-sm">
                <input class="form-control" name="q" value="<?= core_e($q) ?>" placeholder="Buscar por título ou descrição">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </div>
        <?php if ($planId): ?><div class="col-auto small text-muted"><i class="bi bi-clipboard2-check"></i> Diagramas do plano #<?= $planId ?> · <a href="<?= pdg_url() ?>">ver todos</a></div><?php endif; ?>
        <div class="col-auto ms-auto small text-muted"><?= count($rows) ?> diagrama<?= count($rows) === 1 ? '' : 's' ?></div>
    </div>
</div></form>
<?php if (!$rows): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>Nenhum diagrama encontrado.
        <?php if (core_can('diagrams.create')): ?><div class="mt-3"><a class="btn btn-outline-primary btn-sm" href="<?= pdg_url(['action' => 'create']) ?>"><i class="bi bi-plus-lg me-1"></i>Criar o primeiro diagrama</a></div><?php endif; ?>
    </div></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($rows as $r):
        $open = pdg_url(['action' => $canEdit ? 'edit' : 'view', 'id' => $r['id']]);
        $thumb = (string) ($r['thumbnail_svg'] ?? '');
        if ($thumb === '') {
            $thumb = pdg_thumb(plan_diagram_decode(DB::queryOne('SELECT data FROM plan_diagrams WHERE id = ?', [$r['id']])['data'] ?? ''), (int) $r['id']) ?? '';
        } ?>
    <div class="col-sm-6 col-lg-4 col-xxl-3">
        <div class="card h-100 pdg-card">
            <a class="pdg-thumb" href="<?= $open ?>" title="Abrir"><?= $thumb !== '' ? $thumb : '<span class="pdg-empty"><i class="bi bi-diagram-3"></i></span>' ?></a>
            <div class="card-body p-2 pb-1">
                <div class="card-title fw-semibold mb-1"><a class="text-decoration-none" href="<?= $open ?>"><?= core_e($r['title']) ?></a>
                    <?php if (!$r['is_public']): ?><span class="badge text-bg-warning ms-1" title="Privado"><i class="bi bi-lock"></i></span><?php endif; ?></div>
                <div class="small text-muted"><?= pdg_kind_badge((string) $r['kind']) ?> <span class="badge text-bg-secondary">v<?= (int) $r['current_version'] ?></span>
                    <?php if ($r['plan_title']): ?><span class="d-block text-truncate" title="Plano vinculado"><i class="bi bi-clipboard2-check"></i> <?= core_e($r['plan_title']) ?></span><?php endif; ?></div>
                <?php if ($r['description']): ?><div class="small text-muted mt-1"><?= core_e(mb_strimwidth((string) $r['description'], 0, 90, '…')) ?></div><?php endif; ?>
            </div>
            <div class="card-footer bg-white p-2 d-flex align-items-center gap-1">
                <span class="small text-muted flex-grow-1 text-truncate"><?= core_e($r['creator_name'] ?: '—') ?> · <?= pdg_datetime($r['updated_at']) ?></span>
                <a class="btn btn-sm btn-outline-secondary" title="Visualizar" href="<?= pdg_url(['action' => 'view', 'id' => $r['id']]) ?>"><i class="bi bi-eye"></i></a>
                <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary" title="Editar" href="<?= pdg_url(['action' => 'edit', 'id' => $r['id']]) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" title="Versões" href="<?= pdg_url(['action' => 'versions', 'id' => $r['id']]) ?>"><i class="bi bi-clock-history"></i></a>
                <?php if (core_can('diagrams.delete')): ?>
                <form method="post" action="<?= pdg_url(['action' => 'delete']) ?>" class="d-inline" onsubmit="return confirm('Excluir o diagrama \'<?= core_e(addslashes((string) $r['title'])) ?>\'?')"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
pdg_page(['title' => 'Fluxogramas e diagramas', 'content' => (string) ob_get_clean()]);
