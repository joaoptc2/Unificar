<?php /** Portal — aba "Denúncias" (canal anônimo). */ ?>
<section class="my-pane" data-pane="denuncias">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-exclamation me-1"></i> Abrir denúncia anônima</div>
                <div class="card-body">
                    <div class="alert alert-light border small">
                        <i class="bi bi-incognito me-1"></i> <strong>Como funciona:</strong> o sistema <u>não grava</u> quem abriu a denúncia (nem seu usuário, nem o computador). Ao enviar, você recebe um <strong>protocolo</strong> e uma <strong>chave</strong> — guarde-os: são a única forma de acompanhar a apuração e de conversar com a comissão. Evite colocar no texto ou no anexo algo que identifique você, a menos que queira se identificar.
                    </div>
                    <form method="POST" action="index.php?m=rh&page=complaints&action=store" enctype="multipart/form-data" autocomplete="off">
                        <?= Csrf::field() ?>
                        <div class="row g-2">
                            <div class="col-md-6 mb-2"><label class="form-label small required">Categoria</label><select name="category" class="form-select form-select-sm" required><?php foreach (Complaint::CATEGORIES as $k => $l): ?><option value="<?= $k ?>"><?= Sanitize::e($l) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-2"><label class="form-label small">Quando ocorreu</label><input type="date" name="occurred_at" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>"></div>
                        </div>
                        <div class="mb-2"><label class="form-label small required">Assunto</label><input name="subject" class="form-control form-control-sm" required maxlength="200"></div>
                        <div class="mb-2"><label class="form-label small required">Relato</label><textarea name="body" class="form-control form-control-sm" rows="6" required minlength="20" maxlength="20000" placeholder="Descreva o que aconteceu, com o máximo de detalhes que puder (o quê, quando, onde, quem presenciou)."></textarea></div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-2"><label class="form-label small">Local / setor</label><input name="location" class="form-control form-control-sm" maxlength="200"></div>
                            <div class="col-md-6 mb-2"><label class="form-label small">Pessoas envolvidas</label><input name="involved" class="form-control form-control-sm" maxlength="300"></div>
                        </div>
                        <div class="mb-2"><label class="form-label small">Anexo (opcional)</label><input type="file" name="attachment" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"><div class="form-text">O nome do arquivo é descartado; só o conteúdo é guardado.</div></div>
                        <div class="mb-2"><label class="form-label small">Quer se identificar? (opcional)</label><input name="contact" class="form-control form-control-sm" maxlength="200" placeholder="Deixe em branco para permanecer anônimo"></div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="consent" value="1" id="cmpConsent" required><label class="form-check-label small" for="cmpConsent">Li como o canal funciona e vou guardar o protocolo e a chave.</label></div>
                        <div class="d-grid"><button class="btn btn-danger btn-sm"><i class="bi bi-send me-1"></i> Enviar denúncia</button></div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-search me-1"></i> Acompanhar uma denúncia</div>
                <div class="card-body">
                    <p class="small text-muted">Informe o protocolo e a chave. A consulta também pode ser feita <a href="index.php?m=rh&page=complaint_track" target="_blank">sem login</a>.</p>
                    <form method="POST" action="index.php?m=rh&page=complaint_track" target="_blank" autocomplete="off">
                        <?= Csrf::field() ?>
                        <div class="mb-2"><input name="protocol" class="form-control form-control-sm" placeholder="Protocolo (DEN-2026-XXXXXX)" required></div>
                        <div class="mb-2"><input type="password" name="key" class="form-control form-control-sm" placeholder="Chave" required></div>
                        <div class="d-grid"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-search me-1"></i> Consultar</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
