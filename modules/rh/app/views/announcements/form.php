<div class="page-header">
    <h1><i class="bi bi-megaphone me-2"></i>Novo Comunicado</h1>
    <a href="index.php?m=rh&page=announcements" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-md-8">
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="POST" action="index.php?m=rh&page=announcements&action=store">
    <?= Csrf::field() ?>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label required">Titulo</label><input type="text" name="title" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="informativo">Informativo</option><option value="urgente">Urgente</option><option value="celebracao">Celebracao</option></select></div>
        <div class="col-12"><label class="form-label required">Mensagem</label><textarea name="body" class="form-control" rows="6" required></textarea></div>
        <div class="col-md-4"><label class="form-label">Departamento</label><select name="department_id" class="form-select"><option value="">Todos</option><?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= Sanitize::e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Expira em</label><input type="date" name="expires_at" class="form-control"></div>
        <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="pinned" value="1" id="pinned"><label class="form-check-label" for="pinned">Fixar</label></div></div>
        <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="publish_now" value="1" id="publishNow" checked><label class="form-check-label" for="publishNow">Publicar agora</label></div></div>
        <div class="col-12 text-end"><button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Publicar</button></div>
    </div>
</form>
</div></div></div></div>
