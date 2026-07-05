<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i>Novo Template</h1>
    <a href="index.php?page=onboarding" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-md-10">
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="POST" action="index.php?page=onboarding&action=store_template">
    <?= Csrf::field() ?>
    <div class="row g-3 mb-4">
        <div class="col-md-5"><label class="form-label required">Nome do template</label><input type="text" name="name" class="form-control" required placeholder="Ex: Admissao Enfermagem"></div>
        <div class="col-md-3"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="onboarding">Onboarding</option><option value="offboarding">Offboarding</option></select></div>
        <div class="col-md-4"><label class="form-label">Departamento (opcional)</label><select name="department_id" class="form-select"><option value="">Todos</option><?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= Sanitize::e($d['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <h6 class="fw-semibold mb-3">Itens do checklist</h6>
    <div id="itemsList">
        <div class="row g-2 mb-2 item-row">
            <div class="col-md-5"><input type="text" name="items[0][title]" class="form-control form-control-sm" placeholder="Titulo do item" required></div>
            <div class="col-md-4"><input type="text" name="items[0][description]" class="form-control form-control-sm" placeholder="Descricao (opcional)"></div>
            <div class="col-md-2"><select name="items[0][role]" class="form-select form-select-sm"><option value="">Qualquer</option><option value="rh">RH</option><option value="gestor">Gestor</option><option value="admin">Admin</option></select></div>
            <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest('.item-row').remove()"><i class="bi bi-trash"></i></button></div>
        </div>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addItem()"><i class="bi bi-plus-lg me-1"></i> Adicionar item</button>
    <div class="text-end"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Criar Template</button></div>
</form>
</div></div></div></div>
<script>
var itemIdx = 1;
function addItem() {
    var html = '<div class="row g-2 mb-2 item-row"><div class="col-md-5"><input type="text" name="items['+itemIdx+'][title]" class="form-control form-control-sm" placeholder="Titulo do item" required></div><div class="col-md-4"><input type="text" name="items['+itemIdx+'][description]" class="form-control form-control-sm" placeholder="Descricao (opcional)"></div><div class="col-md-2"><select name="items['+itemIdx+'][role]" class="form-select form-select-sm"><option value="">Qualquer</option><option value="rh">RH</option><option value="gestor">Gestor</option><option value="admin">Admin</option></select></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest(\'.item-row\').remove()"><i class="bi bi-trash"></i></button></div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
    itemIdx++;
}
</script>
