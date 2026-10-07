<?php /** Aba Vacinas da ficha. Variáveis: $employee, $vaccineEval (por vaccine_id), $vaccineRequests. */
$canEdit = core_can('vaccines.edit'); $canDel = core_can('vaccines.delete');
$eid = (int) $employee['id'];
$aguardando = 0; foreach ($vaccineEval as $x) { $aguardando += $x['unverified']; }
$abertas = array_filter($vaccineRequests, fn ($r) => in_array($r['status'], ['aberta', 'enviada'], true));
?>
<div class="card border-0 shadow-sm border-top-0 rounded-top-0"><div class="card-body">
    <?php if ($aguardando): ?><div class="alert alert-info py-2 small"><i class="bi bi-hourglass-split me-1"></i> <?= $aguardando ?> comprovante(s) enviado(s) pelo funcionário aguardando validação.</div><?php endif; ?>
    <?php if ($canEdit): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#vacForm"><i class="bi bi-plus-lg me-1"></i> Registrar dose / dispensa / sorologia</button>
        <form method="POST" action="index.php?m=rh&page=vaccines&action=request_all" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="employee_id" value="<?= $eid ?>"><input type="hidden" name="include_pending" value="1">
            <button class="btn btn-outline-warning btn-sm" data-confirm="Solicitar ao funcionário os comprovantes de todas as vacinas vencidas, vencendo ou pendentes?"><i class="bi bi-send me-1"></i> Solicitar regularização de tudo</button></form>
    </div>
    <div class="collapse mb-3" id="vacForm"><div class="card card-body bg-light">
        <form method="POST" action="index.php?m=rh&page=vaccines&action=store_record" enctype="multipart/form-data" class="row g-2">
            <?= Csrf::field() ?><input type="hidden" name="employee_id" value="<?= $eid ?>">
            <div class="col-md-5"><label class="form-label small required">Vacina</label><select name="vaccine_id" class="form-select form-select-sm" required>
                <?php foreach ($vaccineEval as $x): ?><option value="<?= (int) $x['vaccine']['id'] ?>"><?= Sanitize::e($x['vaccine']['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Tipo</label><select name="kind" class="form-select form-select-sm" id="vacKind"><option value="dose">Dose aplicada</option><option value="sorologia">Sorologia (exame)</option><option value="dispensa">Dispensa / não se aplica</option></select></div>
            <div class="col-md-2"><label class="form-label small">Data</label><input type="date" name="applied_at" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-2"><label class="form-label small">Dose nº</label><input type="number" name="dose_number" class="form-control form-control-sm" min="1" max="20" placeholder="auto"></div>
            <div class="col-md-3"><label class="form-label small">Lote</label><input name="batch" class="form-control form-control-sm" maxlength="60"></div>
            <div class="col-md-3"><label class="form-label small">Fabricante</label><input name="manufacturer" class="form-control form-control-sm" maxlength="80"></div>
            <div class="col-md-3"><label class="form-label small">Validade (se diferente)</label><input type="date" name="valid_until" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small">Rótulo (ex.: Reforço)</label><input name="dose_label" class="form-control form-control-sm" maxlength="40"></div>
            <div class="col-md-3 vac-sorologia d-none"><label class="form-label small">Resultado</label><select name="result" class="form-select form-select-sm"><option value="reagente">Reagente (imune)</option><option value="nao_reagente">Não reagente</option></select></div>
            <div class="col-md-6 vac-dispensa d-none"><label class="form-label small">Motivo da dispensa</label><input name="reason" class="form-control form-control-sm" maxlength="200" placeholder="ex.: já teve a doença / contraindicação médica"></div>
            <div class="col-md-4"><label class="form-label small">Comprovante (PDF/imagem)</label><input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="col-md-8"><label class="form-label small">Observações</label><input name="notes" class="form-control form-control-sm" maxlength="2000"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Salvar</button></div>
        </form>
    </div></div>
    <script>
    (function(){ var k=document.getElementById('vacKind'); if(!k) return; function sync(){ document.querySelectorAll('.vac-sorologia').forEach(function(e){e.classList.toggle('d-none',k.value!=='sorologia');}); document.querySelectorAll('.vac-dispensa').forEach(function(e){e.classList.toggle('d-none',k.value!=='dispensa');}); } k.addEventListener('change',sync); sync(); })();
    </script>
    <?php endif; ?>

    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
        <thead><tr><th>Vacina</th><th>Doses</th><th>Última</th><th>Validade / próxima</th><th>Status</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($vaccineEval as $vid => $x): $v = $x['vaccine'];
            if ((int) $v['applies_to_all'] === 0 && !$x['records']) { continue; }
            $req = null; foreach ($abertas as $r) { if ((int) $r['vaccine_id'] === (int) $vid) { $req = $r; break; } } ?>
            <tr>
                <td><span class="fw-semibold"><?= Sanitize::e($v['name']) ?></span> <span class="badge <?= Vaccine::categoryBadge($v['category']) ?> ms-1" style="font-size:.65rem"><?= $v['category'] === 'obrigatoria' ? 'Obrigatória' : ($v['category'] === 'pni_rotina' ? 'PNI' : 'Recomendada') ?></span><?= !empty($x['inactive']) ? ' <span class="badge bg-light text-muted border" style="font-size:.65rem">inativa no catálogo</span>' : '' ?>
                    <small class="text-muted d-block"><?= Sanitize::e($v['schedule'] ?? '') ?></small>
                    <?php if ($x['serology']): ?><small class="d-block <?= strtolower((string) $x['serology']['result']) === 'reagente' ? 'text-success' : 'text-danger' ?>">Sorologia <?= Sanitize::formatDate($x['serology']['applied_at']) ?>: <?= strtolower((string) $x['serology']['result']) === 'reagente' ? 'reagente (imune)' : 'não reagente — dose extra e nova sorologia' ?></small><?php endif; ?></td>
                <td><?= $x['status'] === 'nao_aplica' ? '—' : ($x['doses'] > $x['doses_total'] ? $x['doses'] . ' <small class="text-muted">(esquema ' . $x['doses_total'] . ' + reforço)</small>' : $x['doses'] . ' / ' . $x['doses_total']) ?></td>
                <td class="small"><?= $x['last'] ? Sanitize::formatDate($x['last']['applied_at']) . ($x['last']['batch'] ? '<br><span class="text-muted">lote ' . Sanitize::e($x['last']['batch']) . '</span>' : '') : '—' ?></td>
                <td class="small"><?= $x['valid_until'] ? 'válida até ' . Sanitize::formatDate($x['valid_until']) : ($x['next_due'] ? 'próxima dose: ' . Sanitize::formatDate($x['next_due']) : ($x['status'] === 'em_dia' ? 'sem reforço' : '—')) ?></td>
                <td><span class="badge <?= Vaccine::badge($x['status']) ?>"><?= Vaccine::STATUS_LABELS[$x['status']] ?></span>
                    <?php if ($x['unverified']): ?><span class="badge bg-info text-dark" title="comprovante enviado pelo funcionário">validar</span><?php endif; ?>
                    <?php if ($req): ?><small class="d-block text-muted">solicitação: <?= VaccineRequest::STATUS_LABELS[$req['status']] ?></small><?php endif; ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-outline-secondary btn-action" type="button" data-bs-toggle="collapse" data-bs-target="#vacHist<?= (int) $vid ?>" title="Histórico"><i class="bi bi-clock-history"></i></button>
                    <?php if ($canEdit && !$req && in_array($x['status'], ['vencida', 'vencendo', 'pendente', 'incompleta'], true)): ?>
                    <form method="POST" action="index.php?m=rh&page=vaccines&action=request" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="employee_id" value="<?= $eid ?>"><input type="hidden" name="vaccine_id" value="<?= (int) $vid ?>"><input type="hidden" name="kind" value="<?= in_array($x['status'], ['vencida', 'vencendo'], true) ? 'renovacao' : 'pendente' ?>">
                        <button class="btn btn-outline-warning btn-action" title="Solicitar ao funcionário"><i class="bi bi-send"></i></button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="collapse" id="vacHist<?= (int) $vid ?>"><td colspan="6" class="bg-light small">
                <?php if (!$x['records']): ?><span class="text-muted">Sem registros.</span><?php else: ?>
                <table class="table table-sm mb-0"><tbody>
                <?php foreach ($x['records'] as $r): ?>
                    <tr>
                        <td style="width:9rem"><?= $r['kind'] === 'dose' ? ($r['dose_label'] ?: ($r['dose_number'] . 'ª dose')) : ($r['kind'] === 'sorologia' ? 'Sorologia' : 'Dispensa') ?></td>
                        <td><?= $r['applied_at'] ? Sanitize::formatDate($r['applied_at']) : '—' ?></td>
                        <td><?= $r['kind'] === 'sorologia' ? Sanitize::e((string) $r['result']) : ($r['kind'] === 'dispensa' ? Sanitize::e((string) $r['reason']) : ($r['valid_until'] ? 'até ' . Sanitize::formatDate($r['valid_until']) : '')) ?><?= $r['batch'] ? ' · lote ' . Sanitize::e($r['batch']) : '' ?><?= $r['notes'] ? ' · ' . Sanitize::e($r['notes']) : '' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['file_path']): ?><a class="btn btn-outline-secondary btn-action" href="index.php?m=rh&page=files&action=get&type=vaccine&id=<?= (int) $r['id'] ?>" title="Comprovante"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                            <?php if ((int) $r['verified'] === 0 && $canEdit): ?>
                                <form method="POST" action="index.php?m=rh&page=vaccines&action=verify_record" class="d-inline-flex gap-1 align-items-center"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="date" name="applied_at" class="form-control form-control-sm" value="<?= Sanitize::e($r['applied_at']) ?>" style="width:9rem" title="Data de aplicação">
                                    <input type="number" name="dose_number" class="form-control form-control-sm" value="<?= (int) $r['dose_number'] ?>" min="1" max="20" style="width:4.5rem" title="Dose nº">
                                    <button class="btn btn-success btn-action" title="Validar"><i class="bi bi-check-lg"></i></button>
                                    <button class="btn btn-outline-danger btn-action" name="reject" value="1" title="Recusar comprovante" data-confirm="Recusar este comprovante? O registro será apagado e a solicitação volta a aguardar."><i class="bi bi-x-lg"></i></button>
                                </form>
                            <?php elseif ($canDel): ?>
                                <form method="POST" action="index.php?m=rh&page=vaccines&action=delete_record" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-outline-danger btn-action" title="Excluir" data-confirm="Excluir este registro?"><i class="bi bi-trash"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php $outras = array_filter($vaccineEval, fn ($x) => (int) $x['vaccine']['applies_to_all'] === 0 && !$x['records']); if ($outras): ?>
        <div class="small text-muted">Outras vacinas disponíveis para registro: <?= implode(', ', array_map(fn ($x) => Sanitize::e($x['vaccine']['name']), $outras)) ?>.<?= core_can('vaccines.config') ? ' <a href="' . core_admin_url('rh', 'vaccines') . '">Gerenciar catálogo</a>.' : '' ?></div>
    <?php endif; ?>

    <?php if ($vaccineRequests): ?>
    <h6 class="fw-semibold mt-3 mb-2">Solicitações</h6>
    <ul class="list-group list-group-flush small">
        <?php foreach ($vaccineRequests as $r): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
            <span><?= Sanitize::e($r['vaccine_name']) ?> · <?= $r['kind'] === 'pendente' ? 'regularização' : 'renovação' ?> · <?= Sanitize::formatDateTime($r['created_at']) ?><?= $r['requested_by_name'] ? ' por ' . Sanitize::e($r['requested_by_name']) : ' (automática)' ?></span>
            <span><span class="badge <?= VaccineRequest::badge($r['status']) ?>"><?= VaccineRequest::STATUS_LABELS[$r['status']] ?></span>
            <?php if ($canEdit && in_array($r['status'], ['aberta', 'enviada'], true)): ?><form method="POST" action="index.php?m=rh&page=vaccines&action=cancel_request" class="d-inline ms-1"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="employee_id" value="<?= $eid ?>"><button class="btn btn-outline-secondary btn-action" title="Cancelar"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div></div>
