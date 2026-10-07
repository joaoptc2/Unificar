<?php /** Portal — recibo da denúncia (protocolo + chave, mostrados UMA vez). Variáveis: $protocol, $key + as do _top. */
require __DIR__ . '/_top.php';
?>
<div class="row justify-content-center"><div class="col-lg-7">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold text-success"><i class="bi bi-check-circle me-1"></i> Denúncia registrada</div>
        <div class="card-body">
            <p>Sua denúncia foi registrada <strong>sem identificação</strong>. Guarde os dados abaixo: eles são a <strong>única</strong> forma de acompanhar a apuração e de responder à comissão — o sistema não consegue recuperá-los depois.</p>
            <div class="row g-2 mb-3">
                <div class="col-md-5"><label class="form-label small text-muted mb-0">Protocolo</label><input class="form-control form-control-lg fw-bold" readonly value="<?= Sanitize::e($protocol) ?>" onclick="this.select()"></div>
                <div class="col-md-7"><label class="form-label small text-muted mb-0">Chave de acompanhamento</label><input class="form-control form-control-lg font-monospace" readonly value="<?= Sanitize::e($key) ?>" onclick="this.select()"></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary btn-sm" onclick="navigator.clipboard && navigator.clipboard.writeText('Protocolo: <?= Sanitize::e($protocol) ?>\nChave: <?= Sanitize::e($key) ?>').then(function(){ alert('Copiado.'); })"><i class="bi bi-clipboard me-1"></i> Copiar</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir</button>
                <a class="btn btn-outline-secondary btn-sm" href="index.php?m=rh&page=complaint_track" target="_blank"><i class="bi bi-search me-1"></i> Página de acompanhamento</a>
            </div>
            <p class="small text-muted mt-3 mb-0">Para acompanhar, use a aba <a href="index.php?m=rh&page=my&tab=denuncias">Denúncias</a> da Minha Área ou a página pública (sem login), informando protocolo e chave.</p>
        </div>
    </div>
    <div class="text-center mt-3"><a href="index.php?m=rh&page=my" class="btn btn-link btn-sm">Voltar à Minha Área</a></div>
</div></div>
<?php require __DIR__ . '/_bottom.php'; ?>
