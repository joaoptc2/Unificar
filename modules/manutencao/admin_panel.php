<?php
/**
 * PAINEL DE CONFIGURAÇÃO DO MÓDULO — Administração central
 *
 * Incluído pelo núcleo (Core\AdminPanel) em
 *   index.php?m=admin&a=module&slug=manutencao&tab=<sectors|categories>[&action=...]
 * com MODULE_SLUG/MODULE_PATH/MODULE_URL, CORE_ADMIN_TAB e
 * $GLOBALS['MODULE_PERMS'] já definidos. O cabeçalho e as abas do painel
 * são desenhados pelo núcleo — aqui só o conteúdo da aba ativa.
 *
 * Abas:
 *   sectors    — CRUD de setores (sectors.*)
 *   categories — CRUD de categorias de equipamentos (categories.*)
 *
 * POSTs vão para o próprio painel (&action=save) e redirecionam de volta.
 */

require_once __DIR__ . '/config.php';

$tab = core_admin_tab() ?? 'sectors';
if (!in_array($tab, ['sectors', 'categories'], true)) {
    $tab = 'sectors';
}
requireModule($tab === 'categories' ? 'categories' : 'admin'); // categories.view | sectors.view

$hid       = hospitalId();
$panelUrl  = core_admin_url(MAN_MODULE_SLUG, $tab);
$postUrl   = core_admin_url(MAN_MODULE_SLUG, $tab, ['action' => 'save']);

// ============================================================
// POST (formulários do painel)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCsrf()) {
        $target = manAdminHandlePost($_POST['action'] ?? '') ?? $tab;
    } else {
        flash('error', 'Sessão expirada ou token inválido. Tente novamente.');
        $target = $tab;
    }
    core_redirect(core_admin_url(MAN_MODULE_SLUG, $target));
}

// ============================================================
// DADOS
// ============================================================
$sectorsList    = $tab === 'sectors' ? manSectorsWithCounts($hid) : [];
$categoriesList = $tab === 'categories' ? manCategoriesWithCounts($hid) : [];

$pageTitle = $tab === 'sectors' ? 'Setores' : 'Categorias de equipamentos';
ob_start();
?>

<?php if ($tab === 'sectors'): ?>
<!-- ============================================================ -->
<!-- ABA: SETORES                                                  -->
<!-- ============================================================ -->
<?php if (core_can('sectors.create')): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1"></i> Adicionar setor</div>
    <div class="card-body">
        <form method="POST" action="<?php echo e($postUrl); ?>" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_sector">
            <div class="col-md-5"><label class="form-label required">Nome do setor</label><input type="text" class="form-control" name="sector_name" placeholder="Ex: UTI, Centro Cirúrgico..." required maxlength="150"></div>
            <div class="col-md-5"><label class="form-label">Descrição</label><input type="text" class="form-control" name="sector_desc" placeholder="Descrição (opcional)"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i> Adicionar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 me-1"></i> Setores (<?php echo count($sectorsList); ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($sectorsList)): ?>
            <p class="text-center text-muted py-4 mb-0">Nenhum setor cadastrado.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Nome</th><th>Descrição</th><th>Status</th><th class="text-center">Equipamentos</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($sectorsList as $s): $count = (int) $s['equipment_count']; ?>
                    <tr id="sector-view-<?php echo (int) $s['id']; ?>">
                        <td><strong><?php echo e($s['name']); ?></strong></td>
                        <td class="text-muted"><?php echo e($s['description'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo e($s['status']); ?>"><?php echo $s['status'] === 'active' ? 'Ativo' : 'Inativo'; ?></span></td>
                        <td class="text-center"><span class="badge text-bg-light border"><?php echo $count; ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <?php if (core_can('sectors.edit')): ?>
                                <button type="button" class="btn btn-outline-warning btn-sm" title="Editar" data-toggle-edit="sector" data-id="<?php echo (int) $s['id']; ?>"><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                                <?php if (core_can('sectors.delete')): ?>
                                <form method="POST" action="<?php echo e($postUrl); ?>" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_sector">
                                    <input type="hidden" name="sector_id" value="<?php echo (int) $s['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" <?php echo $count > 0 ? 'disabled title="Em uso por ' . $count . ' equipamento(s)"' : 'title="Excluir" data-confirm="Excluir o setor \'' . e($s['name']) . '\'?"'; ?>><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php if (core_can('sectors.edit')): ?>
                    <tr id="sector-edit-<?php echo (int) $s['id']; ?>" class="table-info" hidden>
                        <td>
                            <form id="sector-form-<?php echo (int) $s['id']; ?>" method="POST" action="<?php echo e($postUrl); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="edit_sector">
                                <input type="hidden" name="sector_id" value="<?php echo (int) $s['id']; ?>">
                                <input type="text" class="form-control form-control-sm" name="sector_name" value="<?php echo e($s['name']); ?>" required maxlength="150">
                            </form>
                        </td>
                        <td><input type="text" class="form-control form-control-sm" form="sector-form-<?php echo (int) $s['id']; ?>" name="sector_desc" value="<?php echo e($s['description'] ?? ''); ?>"></td>
                        <td><select class="form-select form-select-sm" form="sector-form-<?php echo (int) $s['id']; ?>" name="sector_status"><option value="active" <?php echo $s['status'] === 'active' ? 'selected' : ''; ?>>Ativo</option><option value="inactive" <?php echo $s['status'] === 'inactive' ? 'selected' : ''; ?>>Inativo</option></select></td>
                        <td class="text-center text-muted"><?php echo $count; ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="submit" form="sector-form-<?php echo (int) $s['id']; ?>" class="btn btn-outline-primary btn-sm" title="Salvar"><i class="bi bi-check-lg"></i></button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" title="Cancelar" data-toggle-edit="sector" data-id="<?php echo (int) $s['id']; ?>"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ============================================================ -->
<!-- ABA: CATEGORIAS DE EQUIPAMENTOS                               -->
<!-- ============================================================ -->
<?php if (core_can('categories.create')): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1"></i> Adicionar categoria</div>
    <div class="card-body">
        <form method="POST" action="<?php echo e($postUrl); ?>" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_category">
            <div class="col-md-5"><label class="form-label required">Nome da categoria</label><input type="text" class="form-control" name="cat_name" placeholder="Ex: Monitores, Ventiladores, Bombas de infusão..." required maxlength="150"></div>
            <div class="col-md-5"><label class="form-label">Descrição</label><input type="text" class="form-control" name="cat_desc" placeholder="Descrição (opcional)"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i> Adicionar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-tags me-1"></i> Categorias (<?php echo count($categoriesList); ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($categoriesList)): ?>
            <p class="text-center text-muted py-4 mb-0">Nenhuma categoria cadastrada.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Nome</th><th>Descrição</th><th class="text-center">Equipamentos</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($categoriesList as $c): $count = (int) $c['equipment_count']; ?>
                    <tr id="category-view-<?php echo (int) $c['id']; ?>">
                        <td><strong><?php echo e($c['name']); ?></strong></td>
                        <td class="text-muted"><?php echo e($c['description'] ?? '—'); ?></td>
                        <td class="text-center">
                            <?php if ($count > 0): ?>
                                <a class="badge text-bg-light border text-decoration-none" href="<?php echo e(url('equipment', ['filter_category' => (int) $c['id']])); ?>" title="Ver equipamentos"><?php echo $count; ?></a>
                            <?php else: ?>
                                <span class="badge text-bg-light border">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <?php if (core_can('categories.edit')): ?>
                                <button type="button" class="btn btn-outline-warning btn-sm" title="Editar" data-toggle-edit="category" data-id="<?php echo (int) $c['id']; ?>"><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                                <?php if (core_can('categories.delete')): ?>
                                <form method="POST" action="<?php echo e($postUrl); ?>" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="cat_id" value="<?php echo (int) $c['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" <?php echo $count > 0 ? 'disabled title="Em uso por ' . $count . ' equipamento(s)"' : 'title="Excluir" data-confirm="Excluir a categoria \'' . e($c['name']) . '\'?"'; ?>><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php if (core_can('categories.edit')): ?>
                    <tr id="category-edit-<?php echo (int) $c['id']; ?>" class="table-info" hidden>
                        <td>
                            <form id="category-form-<?php echo (int) $c['id']; ?>" method="POST" action="<?php echo e($postUrl); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="edit_category">
                                <input type="hidden" name="cat_id" value="<?php echo (int) $c['id']; ?>">
                                <input type="text" class="form-control form-control-sm" name="cat_name" value="<?php echo e($c['name']); ?>" required maxlength="150">
                            </form>
                        </td>
                        <td><input type="text" class="form-control form-control-sm" form="category-form-<?php echo (int) $c['id']; ?>" name="cat_desc" value="<?php echo e($c['description'] ?? ''); ?>"></td>
                        <td class="text-center text-muted"><?php echo $count; ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="submit" form="category-form-<?php echo (int) $c['id']; ?>" class="btn btn-outline-primary btn-sm" title="Salvar"><i class="bi bi-check-lg"></i></button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" title="Cancelar" data-toggle-edit="category" data-id="<?php echo (int) $c['id']; ?>"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-toggle-edit]');
    if (!btn) return;
    var kind = btn.getAttribute('data-toggle-edit'), id = btn.getAttribute('data-id');
    var view = document.getElementById(kind + '-view-' + id);
    var edit = document.getElementById(kind + '-edit-' + id);
    if (!view || !edit) return;
    var editing = !edit.hidden;
    edit.hidden = editing;
    view.hidden = !editing;
    if (!editing) { var inp = edit.querySelector('input[type="text"]'); if (inp) inp.focus(); }
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/pages/layout.php';
