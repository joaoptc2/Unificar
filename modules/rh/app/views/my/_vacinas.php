<?php /** Portal — aba "Minhas vacinas". Variáveis: $myVaccines (avaliação), $myVaccineRequests. */
$abertas = array_filter($myVaccineRequests, fn ($r) => $r['status'] === 'aberta');
$enviadas = array_filter($myVaccineRequests, fn ($r) => $r['status'] === 'enviada');
?>
<section class="my-pane" data-pane="vacinas">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-plus me-1"></i> Minhas vacinas</div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                    <?php foreach ($myVaccines as $x): $v = $x['vaccine']; if ((int) $v['applies_to_all'] === 0 && !$x['records']) { continue; } ?>
                        <div class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <strong><?= Sanitize::e($v['name']) ?></strong>
                                <small class="text-muted d-block"><?= Sanitize::e($v['schedule'] ?? '') ?></small>
                                <small class="d-block"><?= $x['status'] === 'nao_aplica' ? 'Não se aplica' : ($x['doses'] > $x['doses_total'] ? $x['doses'] . ' dose(s), esquema completo' : $x['doses'] . ' de ' . $x['doses_total'] . ' dose(s)') ?><?= $x['last'] ? ' · última em ' . Sanitize::formatDate($x['last']['applied_at']) : '' ?><?= $x['valid_until'] ? ' · válida até ' . Sanitize::formatDate($x['valid_until']) : '' ?><?= $x['next_due'] ? ' · próxima dose: ' . Sanitize::formatDate($x['next_due']) : '' ?></small>
                                <?php if ($x['unverified']): ?><small class="text-info d-block"><i class="bi bi-hourglass-split me-1"></i>comprovante enviado, aguardando validação do RH</small><?php endif; ?>
                            </div>
                            <span class="badge <?= Vaccine::badge($x['status']) ?>"><?= Vaccine::STATUS_LABELS[$x['status']] ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <?php if ($abertas || $enviadas): ?>
            <div class="card border-0 shadow-sm mb-4 border-warning">
                <div class="card-header bg-white fw-semibold text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i> O RH pediu comprovantes</div>
                <div class="card-body small">
                    <?php foreach ($abertas as $r): ?>
                        <div class="mb-2"><strong><?= Sanitize::e($r['vaccine_name']) ?></strong> — <?= $r['kind'] === 'pendente' ? 'regularizar o esquema' : 'renovar (vencida/vencendo)' ?><?= $r['due_date'] ? ' até ' . Sanitize::formatDate($r['due_date']) : '' ?><?= $r['message'] ? '<br><span class="text-muted">' . Sanitize::e($r['message']) . '</span>' : '' ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($enviadas as $r): ?>
                        <div class="mb-2 text-muted"><i class="bi bi-check2 me-1"></i><?= Sanitize::e($r['vaccine_name']) ?> — comprovante enviado, aguardando validação.</div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Enviar comprovante de vacina</div>
                <div class="card-body">
                    <form method="POST" action="index.php?m=rh&page=vaccines&action=my_submit" enctype="multipart/form-data">
                        <?= Csrf::field() ?>
                        <div class="mb-2"><label class="form-label small required">Vacina</label><select name="vaccine_id" class="form-select form-select-sm" required>
                            <?php foreach ($myVaccines as $x): ?><option value="<?= (int) $x['vaccine']['id'] ?>" <?= ($abertas && (int) reset($abertas)['vaccine_id'] === (int) $x['vaccine']['id']) ? 'selected' : '' ?>><?= Sanitize::e($x['vaccine']['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label class="form-label small required">Data da aplicação</label><input type="date" name="applied_at" class="form-control form-control-sm" required max="<?= date('Y-m-d') ?>"></div>
                        <div class="mb-2"><label class="form-label small required">Comprovante (foto do cartão ou PDF)</label><input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png"></div>
                        <div class="mb-3"><label class="form-label small">Observação</label><input name="notes" class="form-control form-control-sm" maxlength="1000"></div>
                        <div class="d-grid"><button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i> Enviar para o RH</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
