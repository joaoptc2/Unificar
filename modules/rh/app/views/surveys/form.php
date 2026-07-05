<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i>Nova Pesquisa</h1>
    <a href="index.php?page=surveys" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-md-10">
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="POST" action="index.php?page=surveys&action=store">
    <?= Csrf::field() ?>
    <div class="row g-3 mb-4">
        <div class="col-md-5"><label class="form-label required">Titulo</label><input type="text" name="title" class="form-control" required></div>
        <div class="col-md-2"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="clima">Clima</option><option value="enps">eNPS</option><option value="pulse">Pulse</option><option value="custom">Custom</option></select></div>
        <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="rascunho">Rascunho</option><option value="ativa">Ativa</option></select></div>
        <div class="col-md-1"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="anonymous" value="1" id="anon" checked><label class="form-check-label" for="anon">Anon.</label></div></div>
        <div class="col-md-3"><label class="form-label">Inicio</label><input type="date" name="starts_at" class="form-control"></div>
        <div class="col-md-3"><label class="form-label">Fim</label><input type="date" name="ends_at" class="form-control"></div>
        <div class="col-12"><label class="form-label">Descricao</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div>
    <h6 class="fw-semibold mb-3">Perguntas</h6>
    <div id="questionsList">
        <div class="row g-2 mb-2 q-row">
            <div class="col-md-8"><input type="text" name="questions[0][question]" class="form-control form-control-sm" placeholder="Pergunta" required></div>
            <div class="col-md-3"><select name="questions[0][type]" class="form-select form-select-sm"><option value="rating">Nota (0-10)</option><option value="text">Texto livre</option><option value="choice">Escolha</option></select></div>
            <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest('.q-row').remove()"><i class="bi bi-trash"></i></button></div>
        </div>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addQ()"><i class="bi bi-plus-lg me-1"></i> Adicionar pergunta</button>
    <div class="text-end"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Criar Pesquisa</button></div>
</form>
</div></div></div></div>
<script>var qIdx=1;function addQ(){var h='<div class="row g-2 mb-2 q-row"><div class="col-md-8"><input type="text" name="questions['+qIdx+'][question]" class="form-control form-control-sm" placeholder="Pergunta" required></div><div class="col-md-3"><select name="questions['+qIdx+'][type]" class="form-select form-select-sm"><option value="rating">Nota (0-10)</option><option value="text">Texto livre</option><option value="choice">Escolha</option></select></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest(\'.q-row\').remove()"><i class="bi bi-trash"></i></button></div></div>';document.getElementById('questionsList').insertAdjacentHTML('beforeend',h);qIdx++;}</script>
