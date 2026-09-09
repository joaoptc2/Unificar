<?php
/** Brindes — catálogo e resgates (RH). Variáveis: $rewards, $redemptions, $status. */
$canRespond = core_can('rewards.respond');
$pending = count(array_filter($redemptions, fn ($d) => $d['status'] === 'pendente'));
?>
<div class="page-header">
    <h1><i class="bi bi-bag-heart me-2"></i>Brindes</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('my.view')): ?><a href="index.php?m=rh&page=my#brindes" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-badge me-1"></i> Ver como funcionário</a><?php endif; ?>
        <?php if (core_can('rewards.create')): ?>
            <a href="index.php?m=rh&page=rewards&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo brinde</a>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-inbox me-1"></i> Resgates <?= $pending ? '<span class="badge bg-warning text-dark">' . $pending . ' pendente(s)</span>' : '' ?></span>
        <form class="d-flex gap-1" method="GET">
            <input type="hidden" name="m" value="rh"><input type="hidden" name="page" value="rewards">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Todos os status</option>
                <?php foreach (RewardRedemption::STATUS as $k => [$label]): ?><option value="<?= $k ?>" <?= ($status ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>Funcionário</th><th>Brinde</th><th>Pontos</th><th>Status</th><th>Solicitado em</th><th>Observação / resposta</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if (empty($redemptions)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhum resgate<?= $status ? ' com este status' : '' ?>.</td></tr>
                <?php else: foreach ($redemptions as $d): [$lbl, $cls] = RewardRedemption::STATUS[$d['status']] ?? [ucfirst($d['status']), 'bg-secondary']; ?>
                    <tr>
                        <td class="fw-semibold"><?= Sanitize::e($d['employee_name']) ?></td>
                        <td><?= Sanitize::e($d['reward_name']) ?></td>
                        <td><span class="badge bg-primary"><?= (int)$d['points_spent'] ?></span></td>
                        <td>
                            <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                            <?php if ($d['responded_by_name']): ?><small class="text-muted d-block"><?= Sanitize::e($d['responded_by_name']) ?> · <?= Sanitize::formatDateTime($d['responded_at']) ?></small><?php endif; ?>
                        </td>
                        <td class="small text-muted text-nowrap"><?= Sanitize::formatDateTime($d['created_at']) ?></td>
                        <td class="small">
                            <?= $d['notes'] ? '<div><i class="bi bi-chat-left-text text-muted me-1"></i>' . Sanitize::e($d['notes']) . '</div>' : '' ?>
                            <?= $d['response'] ? '<div class="text-primary"><i class="bi bi-reply me-1"></i>' . Sanitize::e($d['response']) . '</div>' : '' ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($canRespond && in_array($d['status'], ['pendente', 'aprovada'], true)): ?>
                                <form method="POST" action="index.php?m=rh&page=rewards&action=respond" class="d-inline-flex gap-1 align-items-center">
                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <input type="text" name="response" class="form-control form-control-sm" placeholder="Resposta (opcional)" maxlength="1000" style="width:170px">
                                    <?php if ($d['status'] === 'pendente'): ?>
                                        <button name="decision" value="aprovar" class="btn btn-outline-success btn-action" title="Aprovar (lança os pontos)"><i class="bi bi-check-lg"></i></button>
                                    <?php else: ?>
                                        <button name="decision" value="entregar" class="btn btn-outline-primary btn-action" title="Marcar como entregue"><i class="bi bi-box-seam"></i></button>
                                    <?php endif; ?>
                                    <button name="decision" value="rejeitar" class="btn btn-outline-danger btn-action" title="Rejeitar<?= $d['status'] === 'aprovada' ? ' (devolve os pontos)' : '' ?>" data-confirm="Rejeitar este resgate?"><i class="bi bi-x-lg"></i></button>
                                    <button name="decision" value="cancelar" class="btn btn-outline-secondary btn-action" title="Cancelar<?= $d['status'] === 'aprovada' ? ' (devolve os pontos)' : '' ?>" data-confirm="Cancelar este resgate?"><i class="bi bi-slash-circle"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-gift me-1"></i> Catálogo de brindes</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th style="width:60px"></th><th>Brinde</th><th>Custo</th><th>Estoque</th><th>Resgatados</th><th>Pendentes</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if (empty($rewards)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhum brinde cadastrado.</td></tr>
                <?php else: foreach ($rewards as $r): ?>
                    <tr class="<?= (int)$r['active'] ? '' : 'opacity-50' ?>">
                        <td>
                            <?php if (!empty($r['image_path'])): ?><img src="<?= Sanitize::e(Upload::publicUrl($r['image_path'])) ?>" class="rounded" style="width:48px;height:48px;object-fit:cover" alt="">
                            <?php else: ?><div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:48px;height:48px"><i class="bi bi-gift text-muted"></i></div><?php endif; ?>
                        </td>
                        <td><div class="fw-semibold"><?= Sanitize::e($r['name']) ?></div><?= $r['description'] ? '<small class="text-muted">' . Sanitize::e(mb_strimwidth((string)$r['description'], 0, 90, '…')) . '</small>' : '' ?></td>
                        <td><span class="badge bg-primary"><?= (int)$r['points_cost'] ?> pts</span></td>
                        <td><?= $r['stock'] === null ? '<span class="text-muted">ilimitado</span>' : ((int)$r['stock'] > 0 ? (int)$r['stock'] : '<span class="badge bg-danger">esgotado</span>') ?></td>
                        <td><?= (int)$r['redeemed'] ?></td>
                        <td><?= (int)$r['pending'] ? '<span class="badge bg-warning text-dark">' . (int)$r['pending'] . '</span>' : '0' ?></td>
                        <td><?= (int)$r['active'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (core_can('rewards.edit')): ?><a href="index.php?m=rh&page=rewards&action=edit&id=<?= (int)$r['id'] ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a><?php endif; ?>
                            <?php if (core_can('rewards.delete')): ?>
                                <form method="POST" action="index.php?m=rh&page=rewards&action=delete" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button type="submit" class="btn btn-outline-danger btn-action" title="Excluir" data-confirm="Excluir este brinde? (se houver resgates, ele será apenas desativado)"><i class="bi bi-trash"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
