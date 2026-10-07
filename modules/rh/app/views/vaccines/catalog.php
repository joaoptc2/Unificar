<?php /** Catálogo de vacinas (Administração central). Variáveis: $items, $editing. */ $v = $editing; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light"><tr><th>Vacina</th><th>Categoria</th><th>Esquema</th><th>Reforço</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
        <tr class="<?= (int) $i['active'] ? '' : 'text-muted' ?>">
            <td><?= Sanitize::e($i['name']) ?><?= (int) $i['applies_to_all'] ? '' : ' <small class="text-muted">(só quando registrada)</small>' ?><?= (int) $i['active'] ? '' : ' <span class="badge bg-light text-dark border">inativa</span>' ?>
                <?php if ($i['target']): ?><small class="text-muted d-block"><?= Sanitize::e($i['target']) ?></small><?php endif; ?></td>
            <td><span class="badge <?= Vaccine::categoryBadge($i['category']) ?>"><?= Sanitize::e(Vaccine::CATEGORIES[$i['category']] ?? $i['category']) ?></span></td>
            <td class="small"><?= (int) $i['doses_total'] ?> dose(s)<?= $i['schedule'] ? ' — ' . Sanitize::e($i['schedule']) : '' ?></td>
            <td class="small"><?= $i['booster_months'] ? 'a cada ' . (int) $i['booster_months'] . ' meses' : '—' ?></td>
            <td class="text-end text-nowrap">
                <a href="<?= core_admin_url('rh', 'vaccines', ['id' => (int) $i['id']]) ?>" class="btn btn-outline-primary btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                <form method="POST" action="index.php?m=rh&page=vaccines&action=catalog_toggle" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="btn btn-outline-secondary btn-action" title="Ativar/inativar"><i class="bi bi-toggle-<?= (int) $i['active'] ? 'on' : 'off' ?>"></i></button></form>
                <form method="POST" action="index.php?m=rh&page=vaccines&action=catalog_delete" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="btn btn-outline-danger btn-action" title="Excluir" data-confirm="Excluir &quot;<?= Sanitize::e($i['name']) ?>&quot; do catálogo?"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div></div></div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><?= $v ? 'Editar vacina' : 'Adicionar outra vacina' ?></div>
            <div class="card-body">
                <form method="POST" action="index.php?m=rh&page=vaccines&action=catalog_save">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) ($v['id'] ?? 0) ?>">
                    <div class="mb-2"><label class="form-label small required">Nome</label><input name="name" class="form-control form-control-sm" required maxlength="120" value="<?= Sanitize::e($v['name'] ?? '') ?>"></div>
                    <div class="row g-2">
                        <div class="col-7 mb-2"><label class="form-label small">Categoria</label><select name="category" class="form-select form-select-sm"><?php foreach (Vaccine::CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= ($v['category'] ?? 'recomendada') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                        <div class="col-5 mb-2"><label class="form-label small">Doses do esquema</label><input type="number" name="doses_total" class="form-control form-control-sm" min="1" max="10" value="<?= (int) ($v['doses_total'] ?? 1) ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small">Para quem</label><input name="target" class="form-control form-control-sm" maxlength="200" value="<?= Sanitize::e($v['target'] ?? '') ?>"></div>
                    <div class="mb-2"><label class="form-label small">Esquema (texto)</label><input name="schedule" class="form-control form-control-sm" maxlength="200" value="<?= Sanitize::e($v['schedule'] ?? '') ?>" placeholder="ex.: 3 doses: 0, 1 e 6 meses"></div>
                    <div class="row g-2">
                        <div class="col-6 mb-2"><label class="form-label small">Próxima dose (dias)</label><input type="number" name="next_dose_days" class="form-control form-control-sm" min="0" value="<?= (int) ($v['next_dose_days'] ?? 0) ?: '' ?>"></div>
                        <div class="col-6 mb-2"><label class="form-label small">Reforço / validade (meses)</label><input type="number" name="booster_months" class="form-control form-control-sm" min="0" value="<?= (int) ($v['booster_months'] ?? 0) ?: '' ?>" placeholder="vazio = sem reforço"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small">Regra de validade (texto)</label><input name="validity_rule" class="form-control form-control-sm" maxlength="255" value="<?= Sanitize::e($v['validity_rule'] ?? '') ?>"></div>
                    <div class="mb-2"><label class="form-label small">Sorologia associada</label><input name="serology" class="form-control form-control-sm" maxlength="120" value="<?= Sanitize::e($v['serology'] ?? '') ?>" placeholder="ex.: Anti-HBs"></div>
                    <div class="mb-2"><label class="form-label small">Observações</label><textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="2000"><?= Sanitize::e($v['notes'] ?? '') ?></textarea></div>
                    <div class="row g-2 align-items-end">
                        <div class="col-7 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="applies_to_all" value="1" id="v_all" <?= (int) ($v['applies_to_all'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="v_all">Vale para todos (cobra de quem não tem)</label></div></div>
                        <div class="col-5 mb-2"><label class="form-label small">Ordem</label><input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) ($v['sort_order'] ?? 0) ?>"></div>
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> <?= $v ? 'Salvar' : 'Adicionar' ?></button>
                    <?php if ($v): ?><a href="<?= core_admin_url('rh', 'vaccines') ?>" class="btn btn-link btn-sm">Cancelar</a><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="small text-muted mt-2">Base: NR-32 (32.2.4.17), Calendário Nacional de Vacinação (PNI/MS) e Calendário SBIm ocupacional. Vacinas obrigatórias/PNI que valem para todos aparecem como <em>pendentes</em> até serem registradas.</div>
    </div>
</div>
