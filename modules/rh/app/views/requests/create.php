<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Solicitação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4"><div class="row justify-content-center"><div class="col-md-6">
<div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold"><i class="bi bi-envelope-paper me-1"></i> Nova Solicitação</div><div class="card-body">
<form method="POST" action="index.php?m=rh&page=requests&action=store">
    <?= Csrf::field() ?>
    <div class="mb-3"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="declaracao">Declaração</option><option value="alteracao_cadastral">Alteração cadastral</option><option value="treinamento">Treinamento</option><option value="outro">Outro</option></select></div>
    <div class="form-text mb-2">Férias são solicitadas na aba <a href="index.php?m=rh&page=my#ferias">Férias</a> da Minha Área.</div>
    <div class="mb-3"><label class="form-label required">Assunto</label><input type="text" name="subject" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Detalhes</label><textarea name="body" class="form-control" rows="4"></textarea></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-1"></i> Enviar</button>
</form>
</div></div>
<div class="text-center mt-3"><a href="index.php?m=rh&page=my" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Voltar ao portal</a></div>
</div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
