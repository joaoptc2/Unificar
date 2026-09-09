<?php
/**
 * Administração central — Layouts de documentos (papel timbrado).
 *
 * Cadastro compartilhado por Documentos, Intranet e RH (impressos):
 * tamanho/orientação, margens, cabeçalho/rodapé, imagem de fundo,
 * modelo de capa, fontes e tamanhos permitidos. Tabela: intra_layouts.
 *
 * Acesso: administrador global ou 'layouts.<ação>' em qualquer módulo que
 * declare o recurso (Documentos, Intranet).
 */

declare(strict_types=1);

use Core\AdminPanel;
use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\DocLayout;
use Core\Flash;
use Core\Layout;

function core_admin_layouts_require(string $action = 'view'): void
{
    if (!AdminPanel::canManageLayouts((int) Auth::id(), $action)) {
        http_response_code(403);
        Layout::renderError(403, 'Você não tem permissão para gerenciar layouts de documentos.');
        exit;
    }
}

function core_admin_layouts_index(): void
{
    core_admin_layouts_require('view');
    $rows = DB::query(
        'SELECT l.*,
                (SELECT COUNT(*) FROM intra_documents d WHERE d.layout_id = l.id) AS docs_n
         FROM ' . DocLayout::TABLE . ' l ORDER BY l.active DESC, l.is_default DESC, l.name'
    );
    $kinds = ['both' => 'Capa e páginas', 'page' => 'Páginas', 'cover' => 'Capa'];
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-layout-text-window-reverse me-2"></i>Layouts de documentos</h1>
        <?php if (AdminPanel::canManageLayouts((int) Auth::id(), 'create')): ?>
            <a class="btn btn-primary" href="<?= core_module_url('admin', ['a' => 'layout_form']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo layout</a>
        <?php endif; ?>
    </div>
    <p class="text-muted small">Papel timbrado do hospital: tamanho da página, margens, cabeçalho e rodapé, imagem de fundo,
        modelo de capa e fontes permitidas. Os layouts são usados pelos documentos editados no sistema (Documentos e Intranet)
        e por impressos de outros módulos.</p>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Layout</th><th>Página</th><th>Uso</th><th class="text-center">Documentos</th><th class="text-center">Status</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum layout cadastrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $l): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= core_e($l['name']) ?>
                                <?php if ($l['is_default']): ?><span class="badge text-bg-primary">padrão</span><?php endif; ?>
                                <?php if (!empty($l['background_path'])): ?><span class="badge text-bg-light border" title="Possui imagem de fundo"><i class="bi bi-image"></i></span><?php endif; ?>
                                <?php if (!empty($l['cover_html'])): ?><span class="badge text-bg-light border" title="Possui modelo de capa"><i class="bi bi-file-earmark-break"></i></span><?php endif; ?>
                            </div>
                            <div class="small text-muted"><?= core_e($l['description'] ?? '') ?></div>
                        </td>
                        <td class="small">
                            <span class="badge text-bg-light border"><?= core_e($l['page_size']) ?></span>
                            <?= $l['orientation'] === 'landscape' ? 'paisagem' : 'retrato' ?>
                            <div class="text-muted">margens <?= (int) $l['margin_top'] ?>/<?= (int) $l['margin_right'] ?>/<?= (int) $l['margin_bottom'] ?>/<?= (int) $l['margin_left'] ?> mm</div>
                        </td>
                        <td class="small"><?= core_e($kinds[$l['kind'] ?? 'both'] ?? 'Capa e páginas') ?></td>
                        <td class="text-center"><span class="badge text-bg-secondary"><?= (int) $l['docs_n'] ?></span></td>
                        <td class="text-center"><?= $l['active'] ? '<span class="badge text-bg-success">ativo</span>' : '<span class="badge text-bg-danger">inativo</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" title="Pré-visualizar" target="_blank"
                               href="<?= core_module_url('admin', ['a' => 'layout_preview', 'id' => $l['id']]) ?>"><i class="bi bi-eye"></i></a>
                            <?php if (AdminPanel::canManageLayouts((int) Auth::id(), 'edit')): ?>
                                <a class="btn btn-sm btn-outline-primary" title="Editar"
                                   href="<?= core_module_url('admin', ['a' => 'layout_form', 'id' => $l['id']]) ?>"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (AdminPanel::canManageLayouts((int) Auth::id(), 'delete')): ?>
                                <form method="post" class="d-inline" action="<?= core_module_url('admin', ['a' => 'layout_delete']) ?>" onsubmit="return confirm('Excluir este layout?')">
                                    <?= Csrf::field() ?>
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
    admin_render('Layouts de documentos', (string) ob_get_clean(), 'layouts');
}

function core_admin_layouts_form(): void
{
    $id     = (int) ($_GET['id'] ?? 0);
    $layout = $id ? DocLayout::find($id) : null;
    core_admin_layouts_require($layout ? 'edit' : 'create');

    $fontsSel = $layout ? DocLayout::fontsOf($layout) : DocLayout::defaultFonts();
    $sizesSel = $layout ? DocLayout::sizesOf($layout) : DocLayout::defaultSizes();
    $allFonts = array_values(array_unique(array_merge(DocLayout::defaultFonts(), $fontsSel)));
    $allSizes = array_values(array_unique(array_merge(DocLayout::defaultSizes(), $sizesSel)));
    usort($allSizes, fn ($a, $b) => (float) $a <=> (float) $b);
    $extraFonts = array_values(array_diff($fontsSel, DocLayout::defaultFonts()));
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-layout-text-window-reverse me-2"></i><?= $layout ? 'Editar layout' : 'Novo layout' ?></h1>
        <div class="d-flex gap-2">
            <?php if ($layout): ?>
                <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= core_module_url('admin', ['a' => 'layout_preview', 'id' => $layout['id']]) ?>">
                    <i class="bi bi-eye me-1"></i>Pré-visualizar
                </a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('admin', ['a' => 'layouts']) ?>">Voltar</a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" action="<?= core_module_url('admin', ['a' => 'layout_save']) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) ($layout['id'] ?? 0) ?>">
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <div class="card mb-3">
                    <div class="card-header">Identificação</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Nome *</label>
                            <input class="form-control" name="name" value="<?= core_e($layout['name'] ?? '') ?>" required placeholder="ex.: Timbrado oficial A4">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <input class="form-control" name="description" value="<?= core_e($layout['description'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Uso do layout</label>
                            <select class="form-select" name="kind">
                                <?php foreach (['both' => 'Capa e páginas', 'page' => 'Somente páginas (corpo)', 'cover' => 'Somente capa'] as $k => $lbl): ?>
                                    <option value="<?= $k ?>" <?= ($layout['kind'] ?? 'both') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Um documento pode usar um layout para a capa e outro para as páginas.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Logo (PNG/JPG/WEBP, máx. 2 MB)</label>
                            <input type="file" class="form-control" name="logo" accept="image/png,image/jpeg,image/webp">
                            <?php if (!empty($layout['logo_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-2">
                                    <img src="<?= core_e(core_url($layout['logo_path'])) ?>" alt="Logo atual" style="max-height:48px" class="border rounded p-1">
                                    <label class="small"><input type="checkbox" name="remove_logo" value="1"> remover</label>
                                </div>
                            <?php endif; ?>
                            <div class="form-text">Use <code>{{logo}}</code> no cabeçalho/rodapé/capa para posicioná-lo.</div>
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
                                    <?php foreach (DocLayout::pageSizes() as $key => $s): ?>
                                        <option value="<?= core_e($key) ?>" <?= ($layout['page_size'] ?? 'A4') === $key ? 'selected' : '' ?>><?= core_e($s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label">Orientação</label>
                                <select class="form-select" name="orientation">
                                    <option value="portrait" <?= ($layout['orientation'] ?? 'portrait') === 'portrait' ? 'selected' : '' ?>>Retrato</option>
                                    <option value="landscape" <?= ($layout['orientation'] ?? '') === 'landscape' ? 'selected' : '' ?>>Paisagem</option>
                                </select>
                            </div>
                        </div>
                        <label class="form-label">Margens (mm)</label>
                        <div class="row g-2 mb-3">
                            <?php foreach ([['margin_top', 'Sup.', 20], ['margin_right', 'Dir.', 15], ['margin_bottom', 'Inf.', 20], ['margin_left', 'Esq.', 15]] as [$f, $l, $def]): ?>
                                <div class="col-3">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><?= $l ?></span>
                                        <input type="number" class="form-control" name="<?= $f ?>" min="0" max="60" value="<?= (int) ($layout[$f] ?? $def) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Imagem de fundo das páginas (PNG/JPG, máx. 5 MB)</label>
                            <input type="file" class="form-control" name="background" accept="image/png,image/jpeg">
                            <?php if (!empty($layout['background_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-2">
                                    <img src="<?= core_e(core_url($layout['background_path'])) ?>" alt="Fundo atual" style="max-height:80px" class="border rounded p-1">
                                    <label class="small"><input type="checkbox" name="remove_background" value="1"> remover</label>
                                </div>
                            <?php endif; ?>
                            <div class="form-text">A imagem é esticada para o tamanho exato da página e repetida em todas as páginas (por trás do texto). Prefira a mesma proporção da página, ex.: 2480 × 3508 px para A4.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Imagem de fundo da capa (PNG/JPG, máx. 5 MB)</label>
                            <input type="file" class="form-control" name="cover_background" accept="image/png,image/jpeg">
                            <?php if (!empty($layout['cover_background_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-2">
                                    <img src="<?= core_e(core_url($layout['cover_background_path'])) ?>" alt="Fundo da capa" style="max-height:80px" class="border rounded p-1">
                                    <label class="small"><input type="checkbox" name="remove_cover_background" value="1"> remover</label>
                                </div>
                            <?php endif; ?>
                            <div class="form-text">Se vazio, a capa usa a imagem de fundo das páginas.</div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Fontes permitidas no editor</div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($allFonts as $f): ?>
                                <div class="col-6">
                                    <label class="form-check small">
                                        <input class="form-check-input" type="checkbox" name="fonts[]" value="<?= core_e($f) ?>" <?= in_array($f, $fontsSel, true) ? 'checked' : '' ?>>
                                        <span class="form-check-label" style="font-family:'<?= core_e($f) ?>'"><?= core_e($f) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small">Outras fontes (separadas por vírgula; precisam estar instaladas nos computadores que imprimem)</label>
                            <input class="form-control form-control-sm" name="fonts_extra" value="<?= core_e(implode(', ', $extraFonts)) ?>" placeholder="ex.: Montserrat, Open Sans">
                        </div>
                        <label class="form-label mt-3">Tamanhos permitidos</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($allSizes as $z): ?>
                                <label class="form-check small me-1">
                                    <input class="form-check-input" type="checkbox" name="font_sizes[]" value="<?= core_e($z) ?>" <?= in_array($z, $sizesSel, true) ? 'checked' : '' ?>>
                                    <span class="form-check-label"><?= core_e($z) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-7">
                                <label class="form-label small">Fonte padrão do texto</label>
                                <select class="form-select form-select-sm" name="default_font">
                                    <option value="">(do navegador)</option>
                                    <?php foreach ($allFonts as $f): ?>
                                        <option value="<?= core_e($f) ?>" <?= ($layout['default_font'] ?? '') === $f ? 'selected' : '' ?>><?= core_e($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">Tamanho padrão</label>
                                <select class="form-select form-select-sm" name="default_font_size">
                                    <option value="">(11pt)</option>
                                    <?php foreach ($allSizes as $z): ?>
                                        <option value="<?= core_e($z) ?>" <?= ($layout['default_font_size'] ?? '') === $z ? 'selected' : '' ?>><?= core_e($z) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-text">Deixe todas desmarcadas para permitir todas as fontes/tamanhos padrão.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="card mb-3">
                    <div class="card-header">Cabeçalho e rodapé (HTML)</div>
                    <div class="card-body">
                        <p class="form-text mt-0">
                            Variáveis: <code>{{logo}}</code> <code>{{org}}</code> <code>{{titulo}}</code> <code>{{subtitulo}}</code>
                            <code>{{codigo}}</code> <code>{{setor}}</code> <code>{{autor}}</code> <code>{{data}}</code> <code>{{versao}}</code>
                        </p>
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <label class="form-label mb-0">Cabeçalho</label>
                                <div class="input-group input-group-sm ms-auto" style="max-width: 280px">
                                    <span class="input-group-text">Repetir em toda página: altura</span>
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
                                <div class="input-group input-group-sm ms-auto" style="max-width: 280px">
                                    <span class="input-group-text">Repetir em toda página: altura</span>
                                    <input type="number" class="form-control" name="footer_height" min="0" max="80" value="<?= (int) ($layout['footer_height'] ?? 0) ?>">
                                    <span class="input-group-text">mm</span>
                                </div>
                            </div>
                            <textarea class="form-control font-monospace" name="footer_html" rows="4"><?= core_e($layout['footer_html'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header">Modelo de capa (HTML, opcional)</div>
                    <div class="card-body">
                        <textarea class="form-control font-monospace" name="cover_html" rows="8" placeholder="<div style=&quot;margin-top:80mm;text-align:center&quot;><h1>{{titulo}}</h1><p>{{codigo}} — v{{versao}}</p><p>{{org}}</p></div>"><?= core_e($layout['cover_html'] ?? '') ?></textarea>
                        <div class="form-text">Usado como capa padrão quando o documento escolhe este layout para a capa. O documento pode
                            sobrescrever o conteúdo da capa no editor. Aceita as mesmas variáveis do cabeçalho.</div>
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
    admin_render($layout ? 'Editar layout' : 'Novo layout', (string) ob_get_clean(), 'layouts');
}

/** Upload de imagem para uploads/layouts. Retorna o caminho relativo ou null (define erro em $error). */
function core_admin_layouts_upload(string $field, int $maxBytes, array $mimes, ?string &$error): ?string
{
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return null;
    }
    $mime = mime_content_type($_FILES[$field]['tmp_name']) ?: '';
    $ext  = $mimes[$mime] ?? null;
    if ($ext === null) {
        $error = 'Imagem inválida em "' . $field . '": envie ' . implode('/', array_map('strtoupper', array_unique(array_values($mimes)))) . '.';
        return null;
    }
    if (($_FILES[$field]['size'] ?? 0) > $maxBytes) {
        $error = 'Imagem "' . $field . '" excede o tamanho máximo de ' . round($maxBytes / 1048576) . ' MB.';
        return null;
    }
    $dir = UPLOADS_PATH . '/layouts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $field . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $file)) {
        $error = 'Falha ao salvar a imagem.';
        return null;
    }
    return 'uploads/layouts/' . $file;
}

function core_admin_layouts_save(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    core_admin_layouts_require($id ? 'edit' : 'create');
    Csrf::check();

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Flash::set('error', 'Informe o nome do layout.');
        core_redirect('index.php?m=admin&a=layouts');
    }
    $existing = $id ? DocLayout::find($id) : null;
    $sizes    = array_keys(DocLayout::pageSizes());

    $fonts = array_values(array_unique(array_filter(array_map('trim', array_map('strval', (array) ($_POST['fonts'] ?? []))))));
    foreach (explode(',', (string) ($_POST['fonts_extra'] ?? '')) as $f) {
        $f = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $f) ?? '');
        if ($f !== '' && !in_array($f, $fonts, true)) {
            $fonts[] = $f;
        }
    }
    $fontSizes = array_values(array_unique(array_filter(array_map(
        fn ($s) => preg_replace('/[^0-9a-z.]/', '', strtolower(trim((string) $s))) ?? '',
        (array) ($_POST['font_sizes'] ?? [])
    ))));
    usort($fontSizes, fn ($a, $b) => (float) $a <=> (float) $b);

    $data = [
        'name'              => $name,
        'description'       => trim((string) ($_POST['description'] ?? '')) ?: null,
        'kind'              => in_array($_POST['kind'] ?? '', ['both', 'page', 'cover'], true) ? $_POST['kind'] : 'both',
        'page_size'         => in_array($_POST['page_size'] ?? '', $sizes, true) ? $_POST['page_size'] : 'A4',
        'orientation'       => ($_POST['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
        'margin_top'        => max(0, min(60, (int) ($_POST['margin_top'] ?? 20))),
        'margin_right'      => max(0, min(60, (int) ($_POST['margin_right'] ?? 15))),
        'margin_bottom'     => max(0, min(60, (int) ($_POST['margin_bottom'] ?? 20))),
        'margin_left'       => max(0, min(60, (int) ($_POST['margin_left'] ?? 15))),
        'header_html'       => DocLayout::sanitizeHtml((string) ($_POST['header_html'] ?? '')) ?: null,
        'footer_html'       => DocLayout::sanitizeHtml((string) ($_POST['footer_html'] ?? '')) ?: null,
        'header_height'     => max(0, min(80, (int) ($_POST['header_height'] ?? 0))),
        'footer_height'     => max(0, min(80, (int) ($_POST['footer_height'] ?? 0))),
        'cover_html'        => DocLayout::sanitizeHtml((string) ($_POST['cover_html'] ?? '')) ?: null,
        'custom_css'        => trim((string) ($_POST['custom_css'] ?? '')) ?: null,
        'fonts'             => $fonts ? json_encode($fonts, JSON_UNESCAPED_UNICODE) : null,
        'font_sizes'        => $fontSizes ? json_encode($fontSizes) : null,
        'default_font'      => trim((string) ($_POST['default_font'] ?? '')) ?: null,
        'default_font_size' => preg_replace('/[^0-9a-z.]/', '', strtolower(trim((string) ($_POST['default_font_size'] ?? '')))) ?: null,
        'is_default'        => !empty($_POST['is_default']) ? 1 : 0,
        'active'            => !empty($_POST['active']) ? 1 : 0,
    ];

    $error = null;
    $img   = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    $logo  = core_admin_layouts_upload('logo', 2 * 1024 * 1024, $img + ['image/webp' => 'webp'], $error);
    $bg    = $error ? null : core_admin_layouts_upload('background', 5 * 1024 * 1024, $img, $error);
    $cbg   = $error ? null : core_admin_layouts_upload('cover_background', 5 * 1024 * 1024, $img, $error);
    if ($error) {
        Flash::set('error', $error);
        core_redirect('index.php?m=admin&a=' . ($id ? 'layout_form&id=' . $id : 'layout_form'));
    }
    if ($logo !== null || !empty($_POST['remove_logo'])) {
        $data['logo_path'] = $logo;
    }
    if ($bg !== null || !empty($_POST['remove_background'])) {
        $data['background_path'] = $bg;
    }
    if ($cbg !== null || !empty($_POST['remove_cover_background'])) {
        $data['cover_background_path'] = $cbg;
    }

    if ($data['is_default']) {
        DB::execute('UPDATE ' . DocLayout::TABLE . ' SET is_default = 0');
    }

    if ($existing) {
        $set    = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($data)));
        $params = array_values($data);
        $params[] = $id;
        DB::execute('UPDATE ' . DocLayout::TABLE . " SET {$set} WHERE id = ?", $params);
        Audit::log('layout.update', DocLayout::TABLE, (string) $id, ['name' => $name], null, 'admin');
    } else {
        $data['created_by'] = Auth::id();
        $cols = implode(', ', array_keys($data));
        $qs   = implode(', ', array_fill(0, count($data), '?'));
        DB::execute('INSERT INTO ' . DocLayout::TABLE . " ({$cols}) VALUES ({$qs})", array_values($data));
        $id = DB::lastId();
        Audit::log('layout.create', DocLayout::TABLE, (string) $id, ['name' => $name], null, 'admin');
    }
    Flash::set('success', 'Layout salvo.');
    core_redirect('index.php?m=admin&a=layout_form&id=' . $id);
}

function core_admin_layouts_delete(): void
{
    core_admin_layouts_require('delete');
    Csrf::check();
    $id = (int) ($_POST['id'] ?? 0);
    $used = 0;
    foreach (['intra_documents' => 'layout_id', 'doc_documents' => 'layout_id'] as $table => $col) {
        try {
            $r = DB::queryOne("SELECT COUNT(*) n FROM {$table} WHERE {$col} = ?", [$id]);
            $used += (int) ($r['n'] ?? 0);
        } catch (\Throwable) {
        }
    }
    if ($used > 0) {
        DB::execute('UPDATE ' . DocLayout::TABLE . ' SET active = 0, is_default = 0 WHERE id = ?', [$id]);
        Flash::set('warning', 'Layout em uso por documentos — foi desativado em vez de excluído.');
    } else {
        DB::execute('DELETE FROM ' . DocLayout::TABLE . ' WHERE id = ?', [$id]);
        Flash::set('success', 'Layout excluído.');
    }
    Audit::log('layout.delete', DocLayout::TABLE, (string) $id, null, null, 'admin');
    core_redirect('index.php?m=admin&a=layouts');
}

function core_admin_layout_preview(): void
{
    core_admin_layouts_require('view');
    $layout = DocLayout::find((int) ($_GET['id'] ?? 0));
    if (!$layout) {
        Layout::renderError(404, 'Layout não encontrado.');
        exit;
    }
    $sample = '<h1>Documento de exemplo</h1><p>Este é um texto de demonstração para conferir o papel timbrado, '
        . 'as margens, a imagem de fundo e o tamanho da página deste layout.</p><p>' . str_repeat('Conteúdo de exemplo. ', 90) . '</p>'
        . '<h2>Seção 2</h2><p>' . str_repeat('Mais conteúdo de exemplo para forçar a quebra de página e conferir a repetição do cabeçalho, do rodapé e do fundo. ', 40) . '</p>'
        . '<h2>Seção 3</h2><p>' . str_repeat('Texto adicional. ', 120) . '</p>';
    $meta = ['title' => 'Documento de exemplo', 'subtitle' => 'Pré-visualização do layout', 'code' => 'DOC-000',
             'author' => core_user()['name'] ?? '', 'version' => 1, 'sector' => 'Qualidade'];
    DocLayout::render([
        'title'        => 'Pré-visualização — ' . $layout['name'],
        'layout'       => $layout,
        'content_html' => $sample,
        'meta'         => $meta,
        'cover'        => !empty($layout['cover_html']) ? ['layout' => $layout, 'html' => ''] : null,
        'toolbar'      => '<strong>Pré-visualização do layout: ' . core_e($layout['name']) . '</strong>'
            . '<span class="spacer"></span>'
            . '<button onclick="window.print()">Testar impressão/PDF</button>'
            . '<a href="' . core_module_url('admin', ['a' => 'layouts']) . '">Voltar</a>',
    ]);
}
