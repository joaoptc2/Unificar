<?php
/** INTRANET — layouts predefinidos (papel timbrado do hospital). */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;

core_require('layouts.view');

// Os layouts de documentos são do NÚCLEO desde a atualização de setembro/2026
// (compartilhados com o módulo Documentos): Administração > Padronização.
$_legacyAction = (string) ($_GET['action'] ?? 'list');
$_target = ['a' => 'layouts'];
if ($_legacyAction === 'form') {
    $_target = ['a' => 'layout_form'];
    if (!empty($_GET['id'])) {
        $_target['id'] = (int) $_GET['id'];
    }
} elseif ($_legacyAction === 'preview' && !empty($_GET['id'])) {
    $_target = ['a' => 'layout_preview', 'id' => (int) $_GET['id']];
}
core_redirect(core_module_url('admin', $_target));

$action = (string) ($_GET['action'] ?? 'list');

// ---- Salvar -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    core_require($id ? 'layouts.edit' : 'layouts.create');
    Csrf::check();

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Flash::set('error', 'Informe o nome do layout.');
        core_redirect('index.php?m=intranet&page=layouts');
    }

    $sizes = array_keys(intra_page_sizes());
    $data = [
        'name'          => $name,
        'description'   => trim((string) ($_POST['description'] ?? '')) ?: null,
        'page_size'     => in_array($_POST['page_size'] ?? '', $sizes, true) ? $_POST['page_size'] : 'A4',
        'orientation'   => ($_POST['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
        'margin_top'    => max(0, min(60, (int) ($_POST['margin_top'] ?? 20))),
        'margin_right'  => max(0, min(60, (int) ($_POST['margin_right'] ?? 15))),
        'margin_bottom' => max(0, min(60, (int) ($_POST['margin_bottom'] ?? 20))),
        'margin_left'   => max(0, min(60, (int) ($_POST['margin_left'] ?? 15))),
        'header_html'   => intra_sanitize_html((string) ($_POST['header_html'] ?? '')) ?: null,
        'footer_html'   => intra_sanitize_html((string) ($_POST['footer_html'] ?? '')) ?: null,
        'header_height' => max(0, min(80, (int) ($_POST['header_height'] ?? 0))),
        'footer_height' => max(0, min(80, (int) ($_POST['footer_height'] ?? 0))),
        'custom_css'    => trim((string) ($_POST['custom_css'] ?? '')) ?: null,
        'is_default'    => !empty($_POST['is_default']) ? 1 : 0,
        'active'        => !empty($_POST['active']) ? 1 : 0,
    ];

    // Upload opcional do logo (png/jpg/webp, máx. 2 MB)
    $logoPath = null;
    if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $mime = mime_content_type($_FILES['logo']['tmp_name']) ?: '';
        $ext  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null) {
            Flash::set('error', 'Logo: envie uma imagem PNG, JPG ou WEBP.');
            core_redirect('index.php?m=intranet&page=layouts');
        }
        if (($_FILES['logo']['size'] ?? 0) > 2 * 1024 * 1024) {
            Flash::set('error', 'Logo: tamanho máximo de 2 MB.');
            core_redirect('index.php?m=intranet&page=layouts');
        }
        $dir = UPLOADS_PATH . '/intranet';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $file);
        $logoPath = 'uploads/intranet/' . $file;
    }

    if ($data['is_default']) {
        DB::execute('UPDATE intra_layouts SET is_default = 0');
    }

    if ($id) {
        $set = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($data)));
        $params = array_values($data);
        if ($logoPath !== null) {
            $set .= ', logo_path = ?';
            $params[] = $logoPath;
        }
        $params[] = $id;
        DB::execute("UPDATE intra_layouts SET {$set} WHERE id = ?", $params);
        Audit::log('intranet.layout_update', 'intra_layouts', (string) $id, ['name' => $name]);
    } else {
        $data['logo_path']  = $logoPath;
        $data['created_by'] = Auth::id();
        $cols = implode(', ', array_keys($data));
        $qs   = implode(', ', array_fill(0, count($data), '?'));
        DB::execute("INSERT INTO intra_layouts ({$cols}) VALUES ({$qs})", array_values($data));
        $id = DB::lastId();
        Audit::log('intranet.layout_create', 'intra_layouts', (string) $id, ['name' => $name]);
    }
    Flash::set('success', 'Layout salvo.');
    core_redirect('index.php?m=intranet&page=layouts&action=form&id=' . $id);
}

// ---- Excluir ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    core_require('layouts.delete');
    Csrf::check();
    $id   = (int) ($_POST['id'] ?? 0);
    $used = DB::queryOne('SELECT COUNT(*) n FROM intra_documents WHERE layout_id = ?', [$id]);
    if ((int) ($used['n'] ?? 0) > 0) {
        DB::execute('UPDATE intra_layouts SET active = 0, is_default = 0 WHERE id = ?', [$id]);
        Flash::set('warning', 'Layout em uso por documentos — foi desativado em vez de excluído.');
    } else {
        DB::execute('DELETE FROM intra_layouts WHERE id = ?', [$id]);
        Flash::set('success', 'Layout excluído.');
    }
    Audit::log('intranet.layout_delete', 'intra_layouts', (string) $id);
    core_redirect('index.php?m=intranet&page=layouts');
}

// ---- Formulário -----------------------------------------------------------------
if ($action === 'form') {
    $id     = (int) ($_GET['id'] ?? 0);
    $layout = $id ? DB::queryOne('SELECT * FROM intra_layouts WHERE id = ?', [$id]) : null;
    core_require($layout ? 'layouts.edit' : 'layouts.create');
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-layout-text-window-reverse me-2"></i><?= $layout ? 'Editar layout' : 'Novo layout' ?></h1>
        <div class="d-flex gap-2">
            <?php if ($layout): ?>
                <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= MODULE_URL ?>&page=preview&id=<?= (int) $layout['id'] ?>">
                    <i class="bi bi-eye me-1"></i>Pré-visualizar
                </a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= MODULE_URL ?>&page=layouts">Voltar</a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($layout['id'] ?? 0) ?>">
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <div class="card mb-3">
                    <div class="card-header">Identificação</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Nome *</label>
                            <input class="form-control" name="name" value="<?= core_e($layout['name'] ?? '') ?>" required
                                   placeholder="ex.: Timbrado oficial A4">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <input class="form-control" name="description" value="<?= core_e($layout['description'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Logo (PNG/JPG/WEBP, máx. 2 MB)</label>
                            <input type="file" class="form-control" name="logo" accept="image/png,image/jpeg,image/webp">
                            <?php if (!empty($layout['logo_path'])): ?>
                                <div class="mt-2"><img src="<?= core_e(core_url($layout['logo_path'])) ?>" alt="Logo atual" style="max-height:48px" class="border rounded p-1"></div>
                            <?php endif; ?>
                            <div class="form-text">Use <code>{{logo}}</code> no cabeçalho/rodapé para posicioná-lo.</div>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="is_default" id="def" value="1" <?= !empty($layout['is_default']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="def">Layout padrão (pré-selecionado em novos documentos)</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="active" id="atv" value="1" <?= ($layout['active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="atv">Ativo</label>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Página</div>
                    <div class="card-body">
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label">Tamanho</label>
                                <select class="form-select" name="page_size">
                                    <?php foreach (intra_page_sizes() as $key => $s): ?>
                                        <option value="<?= core_e($key) ?>" <?= ($layout['page_size'] ?? 'A4') === $key ? 'selected' : '' ?>><?= core_e($s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label">Orientação</label>
                                <select class="form-select" name="orientation">
                                    <option value="portrait" <?= ($layout['orientation'] ?? 'portrait') === 'portrait' ? 'selected' : '' ?>>Retrato (vertical)</option>
                                    <option value="landscape" <?= ($layout['orientation'] ?? '') === 'landscape' ? 'selected' : '' ?>>Paisagem (horizontal)</option>
                                </select>
                            </div>
                        </div>
                        <label class="form-label">Margens (mm)</label>
                        <div class="row g-2">
                            <?php foreach ([['margin_top', 'Sup.', 20], ['margin_right', 'Dir.', 15], ['margin_bottom', 'Inf.', 20], ['margin_left', 'Esq.', 15]] as [$f, $l, $def]): ?>
                                <div class="col-3">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><?= $l ?></span>
                                        <input type="number" class="form-control" name="<?= $f ?>" min="0" max="60" value="<?= (int) ($layout[$f] ?? $def) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="card mb-3">
                    <div class="card-header">Cabeçalho e rodapé (HTML)</div>
                    <div class="card-body">
                        <p class="form-text mt-0">
                            Variáveis: <code>{{logo}}</code> <code>{{org}}</code> <code>{{titulo}}</code>
                            <code>{{autor}}</code> <code>{{data}}</code> <code>{{versao}}</code>
                        </p>
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <label class="form-label mb-0">Cabeçalho</label>
                                <div class="input-group input-group-sm ms-auto" style="max-width: 260px">
                                    <span class="input-group-text">Repetir a cada página: altura</span>
                                    <input type="number" class="form-control" name="header_height" min="0" max="80" value="<?= (int) ($layout['header_height'] ?? 0) ?>">
                                    <span class="input-group-text">mm</span>
                                </div>
                            </div>
                            <textarea class="form-control font-monospace" name="header_html" rows="5"><?= core_e($layout['header_html'] ?? '') ?></textarea>
                            <div class="form-text">Altura 0 = aparece apenas na primeira página (fluxo normal).</div>
                        </div>
                        <div class="mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <label class="form-label mb-0">Rodapé</label>
                                <div class="input-group input-group-sm ms-auto" style="max-width: 260px">
                                    <span class="input-group-text">Repetir a cada página: altura</span>
                                    <input type="number" class="form-control" name="footer_height" min="0" max="80" value="<?= (int) ($layout['footer_height'] ?? 0) ?>">
                                    <span class="input-group-text">mm</span>
                                </div>
                            </div>
                            <textarea class="form-control font-monospace" name="footer_html" rows="4"><?= core_e($layout['footer_html'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header">CSS adicional (opcional)</div>
                    <div class="card-body">
                        <textarea class="form-control font-monospace" name="custom_css" rows="4" placeholder=".doc-content h1 { color: #0d5c8f; }"><?= core_e($layout['custom_css'] ?? '') ?></textarea>
                    </div>
                </div>
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar layout</button>
            </div>
        </div>
    </form>
    <?php
    intra_view($layout ? 'Editar layout' : 'Novo layout', (string) ob_get_clean(), 'layouts');
    exit;
}

// ---- Listagem -------------------------------------------------------------------
$layouts = DB::query(
    'SELECT l.*, (SELECT COUNT(*) FROM intra_documents d WHERE d.layout_id = l.id) AS docs_n
     FROM intra_layouts l ORDER BY l.active DESC, l.is_default DESC, l.name'
);
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-layout-text-window-reverse me-2"></i>Layouts (papel timbrado)</h1>
    <?php if (core_can('layouts.create')): ?>
        <a class="btn btn-primary" href="<?= MODULE_URL ?>&page=layouts&action=form"><i class="bi bi-plus-lg me-1"></i>Novo layout</a>
    <?php endif; ?>
</div>
<p class="text-muted small">Cadastre os modelos padronizados do hospital: tamanho da página (A4, A3...), orientação,
margens, cabeçalho e rodapé. Os documentos da Intranet são editados e exportados em PDF já dentro do layout escolhido.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Layout</th><th>Página</th><th class="text-center">Documentos</th><th class="text-center">Status</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($layouts as $l): ?>
                <tr>
                    <td>
                        <div class="fw-semibold">
                            <?= core_e($l['name']) ?>
                            <?php if ($l['is_default']): ?><span class="badge text-bg-primary">padrão</span><?php endif; ?>
                        </div>
                        <div class="small text-muted"><?= core_e($l['description'] ?? '') ?></div>
                    </td>
                    <td class="small">
                        <span class="badge text-bg-light border"><?= core_e($l['page_size']) ?></span>
                        <?= $l['orientation'] === 'landscape' ? 'paisagem' : 'retrato' ?>
                        <div class="text-muted">margens <?= (int) $l['margin_top'] ?>/<?= (int) $l['margin_right'] ?>/<?= (int) $l['margin_bottom'] ?>/<?= (int) $l['margin_left'] ?> mm</div>
                    </td>
                    <td class="text-center"><span class="badge text-bg-secondary"><?= (int) $l['docs_n'] ?></span></td>
                    <td class="text-center">
                        <?= $l['active'] ? '<span class="badge text-bg-success">ativo</span>' : '<span class="badge text-bg-danger">inativo</span>' ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" title="Pré-visualizar" target="_blank"
                           href="<?= MODULE_URL ?>&page=preview&id=<?= (int) $l['id'] ?>"><i class="bi bi-eye"></i></a>
                        <?php if (core_can('layouts.edit')): ?>
                            <a class="btn btn-sm btn-outline-primary" title="Editar"
                               href="<?= MODULE_URL ?>&page=layouts&action=form&id=<?= (int) $l['id'] ?>"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if (core_can('layouts.delete')): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Excluir este layout?')">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
intra_view('Layouts', (string) ob_get_clean(), 'layouts');
