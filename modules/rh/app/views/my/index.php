<?php
/**
 * Minha Área — portal do funcionário.
 * Variáveis: $employee, $scores, $scoresTotal, $available, $reserved, $compliments,
 * $upcoming, $vacations, $requests, $announcements, $surveys, $rewards, $redemptions,
 * $requestTypes, $tab, $hospitalName, $flashSuccess, $flashError, $unread.
 */
$unreadAnn   = count(array_filter($announcements, fn ($a) => !(int)$a['is_read']));
$openSurveys = count(array_filter($surveys, fn ($s) => !(int)$s['answered']));
$pendingReq  = count(array_filter($requests, fn ($r) => in_array($r['status'], ['pendente', 'em_analise'], true)));
$today       = date('Y-m-d');
$vacBadge = fn (string $s): string => match ($s) {
    'aprovada', 'em_gozo', 'concluida' => 'bg-success',
    'rejeitada' => 'bg-danger',
    'solicitada' => 'bg-warning text-dark',
    default => 'bg-secondary',
};
$reqBadge = fn (string $s): string => match ($s) {
    'aprovada' => 'bg-success', 'rejeitada' => 'bg-danger', 'em_analise' => 'bg-info text-dark', default => 'bg-warning text-dark',
};
$annBadge = fn (string $t): string => match ($t) { 'urgente' => 'bg-danger', 'celebracao' => 'bg-success', default => 'bg-info text-dark' };
$initialTab = in_array($tab, ['inicio', 'ferias', 'solicitacoes', 'comunicados', 'pesquisas', 'brindes'], true) ? $tab : '';
require __DIR__ . '/_top.php';
?>

    <nav class="my-tabs" id="myTabs">
        <a href="#inicio" data-tab="inicio"><i class="bi bi-house"></i> Início</a>
        <a href="#ferias" data-tab="ferias"><i class="bi bi-sun"></i> Férias</a>
        <a href="#solicitacoes" data-tab="solicitacoes"><i class="bi bi-envelope-paper"></i> Solicitações <?= $pendingReq ? '<span class="badge bg-warning text-dark">' . $pendingReq . '</span>' : '' ?></a>
        <?php if (core_can('announcements.view')): ?>
            <a href="#comunicados" data-tab="comunicados"><i class="bi bi-megaphone"></i> Comunicados <?= $unreadAnn ? '<span class="badge bg-danger">' . $unreadAnn . '</span>' : '' ?></a>
        <?php endif; ?>
        <?php if (core_can('surveys.view')): ?>
            <a href="#pesquisas" data-tab="pesquisas"><i class="bi bi-clipboard-data"></i> Pesquisas <?= $openSurveys ? '<span class="badge bg-danger">' . $openSurveys . '</span>' : '' ?></a>
        <?php endif; ?>
        <?php if (core_can('rewards.view')): ?>
            <a href="#brindes" data-tab="brindes"><i class="bi bi-bag-heart"></i> Brindes</a>
        <?php endif; ?>
    </nav>

    <!-- ===================== INÍCIO ===================== -->
    <section class="my-pane" data-pane="inicio">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="score-box text-center h-100">
                    <div class="text-muted small">Meus pontos</div>
                    <div class="display-5 fw-bold <?= $scoresTotal >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($scoresTotal >= 0 ? '+' : '') . (int)$scoresTotal ?></div>
                    <div class="text-muted small">
                        acumulados<?= $reserved ? ' · ' . (int)$reserved . ' reservados em resgates' : '' ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="score-box text-center h-100">
                    <div class="text-muted small">Elogios recebidos</div>
                    <div class="display-5 fw-bold text-warning"><i class="bi bi-heart-fill" style="font-size:1.8rem"></i> <?= count($compliments) ?></div>
                    <div class="text-muted small">no total</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="score-box text-center h-100">
                    <div class="text-muted small">Pendências</div>
                    <div class="display-5 fw-bold text-primary"><?= $unreadAnn + $openSurveys ?></div>
                    <div class="text-muted small"><?= $unreadAnn ?> comunicado(s) não lido(s) · <?= $openSurveys ?> pesquisa(s) aberta(s)</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy me-1"></i> Histórico de pontos</div>
                    <div class="card-body">
                        <?php if (empty($scores)): ?>
                            <p class="text-muted text-center py-3 mb-0">Você ainda não tem pontos lançados.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="text-muted small"><tr><th>Data</th><th>Pontos</th><th>Categoria</th><th>Motivo</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($scores as $s): ?>
                                        <tr>
                                            <td class="text-muted small text-nowrap"><?= Sanitize::formatDateTime($s['created_at']) ?></td>
                                            <td><strong class="<?= $s['points'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($s['points'] >= 0 ? '+' : '') . (int)$s['points'] ?></strong></td>
                                            <td><span class="badge bg-light text-dark"><?= Sanitize::e($s['category'] ?: '-') ?></span></td>
                                            <td><?= Sanitize::e($s['reason']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-heart me-1 text-warning"></i> Elogios recebidos</div>
                    <div class="card-body">
                        <?php if (empty($compliments)): ?>
                            <p class="text-muted text-center py-3 mb-0">Nenhum elogio registrado ainda — continue o bom trabalho!</p>
                        <?php else: foreach ($compliments as $c): ?>
                            <div class="compliment-card">
                                <div class="d-flex">
                                    <span class="quote me-2">“</span>
                                    <div class="flex-grow-1">
                                        <p class="mb-1"><?= nl2br(Sanitize::e($c['message'])) ?></p>
                                        <small class="text-muted">
                                            <?= $c['compliment_from'] ? 'de <strong>' . Sanitize::e($c['compliment_from']) . '</strong> &middot; ' : '' ?>
                                            <?= Sanitize::formatDate(substr((string)$c['created_at'], 0, 10)) ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <?php if (!empty($upcoming)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i> Minhas próximas pendências</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($upcoming as $u):
                            $days = (int)(new DateTime())->diff(new DateTime($u['expiry_date']))->format('%r%a'); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= Sanitize::e($u['title']) ?></strong>
                                    <small class="text-muted d-block"><?= Sanitize::e(str_replace('_', ' ', $u['type'])) ?></small>
                                </div>
                                <span class="badge <?= $days < 0 ? 'bg-danger' : ($days <= 7 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                    <?= Sanitize::formatDate($u['expiry_date']) ?> &middot; <?= $days < 0 ? 'Vencido' : 'Em ' . $days . 'd' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ===================== FÉRIAS ===================== -->
    <section class="my-pane" data-pane="ferias">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-sun me-1"></i> Minhas férias</div>
                    <div class="card-body p-0">
                        <?php if (empty($vacations)): ?>
                            <p class="text-muted text-center py-4 mb-0">Nenhum período de férias registrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="text-muted small"><tr><th>Período de gozo</th><th>Dias</th><th>Vendidos</th><th>Aquisitivo</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($vacations as $v): ?>
                                        <tr>
                                            <td class="text-nowrap"><?= Sanitize::formatDate($v['start_date']) ?> a <?= Sanitize::formatDate($v['end_date']) ?></td>
                                            <td><?= (int)$v['days'] ?></td>
                                            <td><?= (int)$v['sold_days'] ?></td>
                                            <td class="small text-muted text-nowrap"><?= Sanitize::formatDate($v['period_start']) ?> – <?= Sanitize::formatDate($v['period_end']) ?></td>
                                            <td>
                                                <span class="badge <?= $vacBadge($v['status']) ?>"><?= ucfirst(str_replace('_', ' ', $v['status'])) ?></span>
                                                <?php if ($v['approved_by_name'] && in_array($v['status'], ['aprovada', 'rejeitada'], true)): ?>
                                                    <small class="text-muted d-block">por <?= Sanitize::e($v['approved_by_name']) ?> em <?= Sanitize::formatDateTime($v['approved_at']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-plus me-1"></i> Solicitar férias</div>
                    <div class="card-body">
                        <form method="POST" action="index.php?m=rh&page=vacations&action=request" id="vacForm">
                            <?= Csrf::field() ?>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label small">Período aquisitivo — início</label><input type="date" name="period_start" class="form-control form-control-sm"></div>
                                <div class="col-6"><label class="form-label small">Período aquisitivo — fim</label><input type="date" name="period_end" class="form-control form-control-sm"></div>
                                <div class="col-6"><label class="form-label small required">Início das férias</label><input type="date" name="start_date" id="vacStart" class="form-control form-control-sm" required min="<?= $today ?>"></div>
                                <div class="col-6"><label class="form-label small required">Fim das férias</label><input type="date" name="end_date" id="vacEnd" class="form-control form-control-sm" required min="<?= $today ?>"></div>
                                <div class="col-6"><label class="form-label small">Dias de gozo</label><input type="text" id="vacDays" class="form-control form-control-sm" value="—" readonly></div>
                                <div class="col-6"><label class="form-label small">Vender dias (abono)</label><input type="number" name="sold_days" class="form-control form-control-sm" min="0" max="10" value="0"></div>
                                <div class="col-12"><label class="form-label small">Observação</label><textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="Ex.: viagem em família, preferência por parcelamento…"></textarea></div>
                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i> Enviar solicitação ao RH</button>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">A solicitação aparece em <em>Solicitações</em> e o RH aprova ou rejeita. Você será notificado.</small>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===================== SOLICITAÇÕES ===================== -->
    <section class="my-pane" data-pane="solicitacoes">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-envelope-paper me-1"></i> Minhas solicitações</div>
                    <div class="card-body p-0">
                        <?php if (empty($requests)): ?>
                            <p class="text-muted text-center py-4 mb-0">Nenhuma solicitação enviada.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($requests as $r): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div>
                                                <span class="badge bg-light text-dark border"><?= Sanitize::e($requestTypes[$r['type']] ?? ucfirst($r['type'])) ?></span>
                                                <strong class="ms-1"><?= Sanitize::e($r['subject']) ?></strong>
                                                <small class="text-muted d-block"><?= Sanitize::formatDateTime($r['created_at']) ?></small>
                                            </div>
                                            <span class="badge <?= $reqBadge($r['status']) ?>"><?= ucfirst(str_replace('_', ' ', $r['status'])) ?></span>
                                        </div>
                                        <?php if ($r['type'] === 'ferias' && $r['vac_start']): ?>
                                            <small class="text-muted d-block mt-1"><i class="bi bi-sun me-1"></i><?= Sanitize::formatDate($r['vac_start']) ?> a <?= Sanitize::formatDate($r['vac_end']) ?> (<?= (int)$r['vac_days'] ?> dias) · férias: <?= ucfirst((string)$r['vac_status']) ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($r['response'])): ?>
                                            <div class="small mt-2 p-2 bg-light rounded"><i class="bi bi-reply me-1"></i><strong>Resposta do RH<?= $r['responded_by_name'] ? ' (' . Sanitize::e($r['responded_by_name']) . ')' : '' ?>:</strong> <?= nl2br(Sanitize::e($r['response'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <?php if (core_can('requests.create')): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle me-1"></i> Nova solicitação</div>
                    <div class="card-body">
                        <form method="POST" action="index.php?m=rh&page=requests&action=store">
                            <?= Csrf::field() ?>
                            <div class="mb-2">
                                <label class="form-label small">Tipo</label>
                                <select name="type" class="form-select form-select-sm">
                                    <?php foreach ($requestTypes as $k => $label): if ($k === 'ferias') continue; ?>
                                        <option value="<?= $k ?>"><?= Sanitize::e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Férias são solicitadas na aba <a href="#ferias" data-tab-link="ferias">Férias</a>.</div>
                            </div>
                            <div class="mb-2"><label class="form-label small required">Assunto</label><input type="text" name="subject" class="form-control form-control-sm" required maxlength="200"></div>
                            <div class="mb-3"><label class="form-label small">Detalhes</label><textarea name="body" class="form-control form-control-sm" rows="4" maxlength="4000"></textarea></div>
                            <div class="d-grid"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i> Enviar</button></div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ===================== COMUNICADOS ===================== -->
    <?php if (core_can('announcements.view')): ?>
    <section class="my-pane" data-pane="comunicados">
        <?php if (empty($announcements)): ?>
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhum comunicado no momento.</div></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($announcements as $a): $isRead = (int)$a['is_read']; ?>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100 ann-card <?= $isRead ? '' : 'unread' ?>">
                            <?php if (!empty($a['image_path'])): ?>
                                <img src="<?= Sanitize::e(Upload::publicUrl($a['image_path'])) ?>" class="card-img-top" style="max-height:160px;object-fit:cover" alt="">
                            <?php endif; ?>
                            <div class="card-body">
                                <div class="mb-1">
                                    <?php if ((int)$a['pinned']): ?><span class="badge bg-dark"><i class="bi bi-pin"></i> Fixo</span><?php endif; ?>
                                    <span class="badge <?= $annBadge($a['type']) ?>"><?= Sanitize::e(Announcement::TYPES[$a['type']] ?? ucfirst($a['type'])) ?></span>
                                    <?php if (!$isRead): ?><span class="badge bg-primary">Novo</span><?php endif; ?>
                                    <?php if (!empty($a['attachment_name'])): ?><span class="badge bg-light text-dark border"><i class="bi bi-paperclip"></i> Anexo</span><?php endif; ?>
                                </div>
                                <h6 class="fw-bold mb-1"><?= Sanitize::e($a['title']) ?></h6>
                                <small class="text-muted d-block mb-2"><?= Sanitize::formatDateTime($a['published_at']) ?><?= $a['expires_at'] ? ' · válido até ' . Sanitize::formatDate($a['expires_at']) : '' ?></small>
                                <p class="small mb-2"><?= Sanitize::e($a['summary'] ?: mb_substr((string)$a['body'], 0, 160) . (mb_strlen((string)$a['body']) > 160 ? '…' : '')) ?></p>
                                <a href="index.php?m=rh&page=my&action=announcement&id=<?= (int)$a['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-book me-1"></i> Ler</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===================== PESQUISAS ===================== -->
    <?php if (core_can('surveys.view')): ?>
    <section class="my-pane" data-pane="pesquisas">
        <?php if (empty($surveys)): ?>
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhuma pesquisa disponível no momento.</div></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($surveys as $s): $done = (int)$s['answered']; ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <span class="badge bg-info text-dark"><?= Sanitize::e(Survey::TYPES[$s['type']] ?? ucfirst($s['type'])) ?></span>
                                <?= (int)$s['anonymous'] ? '<span class="badge bg-dark"><i class="bi bi-incognito"></i> Anônima</span>' : '<span class="badge bg-light text-dark border">Identificada</span>' ?>
                                <?= $done ? '<span class="badge bg-success">Respondida</span>' : '<span class="badge bg-warning text-dark">Aguardando resposta</span>' ?>
                                <h6 class="fw-bold mt-2 mb-1"><?= Sanitize::e($s['title']) ?></h6>
                                <?php if ($s['description']): ?><p class="small text-muted mb-2"><?= Sanitize::e(mb_substr((string)$s['description'], 0, 140)) ?></p><?php endif; ?>
                                <small class="text-muted d-block"><?= (int)$s['question_count'] ?> pergunta(s)<?= $s['ends_at'] ? ' · até ' . Sanitize::formatDate($s['ends_at']) : '' ?></small>
                            </div>
                            <div class="card-footer bg-transparent">
                                <?php if ($done): ?>
                                    <span class="small text-success"><i class="bi bi-check-circle me-1"></i>Respondida em <?= Sanitize::formatDateTime($s['completed_at']) ?></span>
                                <?php else: ?>
                                    <a href="index.php?m=rh&page=my&action=survey&id=<?= (int)$s['id'] ?>" class="btn btn-primary btn-sm w-100"><i class="bi bi-pencil-square me-1"></i> Responder</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===================== BRINDES ===================== -->
    <?php if (core_can('rewards.view')): ?>
    <section class="my-pane" data-pane="brindes">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="score-box text-center h-100">
                    <div class="text-muted small">Saldo disponível para troca</div>
                    <div class="display-5 fw-bold text-primary"><?= (int)$available ?></div>
                    <div class="text-muted small"><?= (int)$scoresTotal ?> acumulados − <?= (int)$reserved ?> reservados</div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i> Meus resgates</div>
                    <div class="card-body p-0">
                        <?php if (empty($redemptions)): ?>
                            <p class="text-muted text-center py-3 mb-0">Você ainda não resgatou nenhum brinde.</p>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height:220px;overflow:auto">
                                <table class="table table-sm mb-0">
                                    <thead class="text-muted small"><tr><th>Brinde</th><th>Pontos</th><th>Status</th><th>Data</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($redemptions as $d): [$lbl, $cls] = RewardRedemption::STATUS[$d['status']] ?? [ucfirst($d['status']), 'bg-secondary']; ?>
                                        <tr>
                                            <td><?= Sanitize::e($d['reward_name']) ?><?= $d['response'] ? '<small class="text-muted d-block">' . Sanitize::e($d['response']) . '</small>' : '' ?></td>
                                            <td><?= (int)$d['points_spent'] ?></td>
                                            <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
                                            <td class="small text-muted text-nowrap"><?= Sanitize::formatDateTime($d['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <h6 class="fw-semibold mb-2"><i class="bi bi-gift me-1"></i> Catálogo de brindes</h6>
        <?php if (empty($rewards)): ?>
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhum brinde disponível no momento.</div></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($rewards as $rw): $cost = (int)$rw['points_cost']; $can = $available >= $cost; ?>
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <?php if (!empty($rw['image_path'])): ?>
                                <img src="<?= Sanitize::e(Upload::publicUrl($rw['image_path'])) ?>" class="reward-img" alt="">
                            <?php else: ?>
                                <div class="reward-placeholder"><i class="bi bi-gift"></i></div>
                            <?php endif; ?>
                            <div class="card-body">
                                <h6 class="fw-bold mb-1"><?= Sanitize::e($rw['name']) ?></h6>
                                <?php if ($rw['description']): ?><p class="small text-muted mb-2"><?= Sanitize::e(mb_substr((string)$rw['description'], 0, 120)) ?></p><?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-primary fs-6"><?= $cost ?> pts</span>
                                    <?php if ($rw['stock'] !== null): ?><small class="text-muted"><?= (int)$rw['stock'] ?> em estoque</small><?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent">
                                <form method="POST" action="index.php?m=rh&page=rewards&action=redeem">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="reward_id" value="<?= (int)$rw['id'] ?>">
                                    <button type="submit" class="btn btn-sm w-100 <?= $can ? 'btn-success' : 'btn-outline-secondary' ?>" <?= $can ? 'data-confirm="Trocar ' . $cost . ' pontos por &quot;' . Sanitize::e($rw['name']) . '&quot;?"' : 'disabled title="Saldo insuficiente"' ?>>
                                        <i class="bi bi-arrow-left-right me-1"></i> <?= $can ? 'Trocar' : 'Faltam ' . ($cost - $available) . ' pts' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

<?php
$extraScripts = <<<'JS'
<script>
(function () {
    var links = document.querySelectorAll('#myTabs a[data-tab]');
    var panes = document.querySelectorAll('.my-pane');
    function show(name) {
        var found = false;
        panes.forEach(function (p) { var on = p.getAttribute('data-pane') === name; p.classList.toggle('active', on); if (on) found = true; });
        if (!found) { name = 'inicio'; panes.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-pane') === name); }); }
        links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('data-tab') === name); });
    }
    links.forEach(function (l) {
        l.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + l.getAttribute('data-tab')); show(l.getAttribute('data-tab')); window.scrollTo({ top: 0 }); });
    });
    document.querySelectorAll('[data-tab-link]').forEach(function (l) {
        l.addEventListener('click', function (e) { e.preventDefault(); show(l.getAttribute('data-tab-link')); });
    });
    window.addEventListener('hashchange', function () { show(location.hash.replace('#', '')); });
    var initial = document.body.getAttribute('data-initial-tab') || location.hash.replace('#', '') || 'inicio';
    show(initial);

    // Contador de dias das férias.
    var s = document.getElementById('vacStart'), e = document.getElementById('vacEnd'), d = document.getElementById('vacDays');
    function calc() {
        if (!s.value || !e.value) { d.value = '—'; return; }
        var diff = Math.round((new Date(e.value) - new Date(s.value)) / 86400000) + 1;
        d.value = diff > 0 ? diff + ' dia(s)' : 'inválido';
        d.classList.toggle('is-invalid', diff <= 0 || diff > 30);
    }
    if (s && e) { s.addEventListener('change', calc); e.addEventListener('change', calc); }
})();
</script>
JS;
if ($initialTab !== '') {
    $extraScripts = '<script>document.body.setAttribute("data-initial-tab", ' . json_encode($initialTab) . ');</script>' . $extraScripts;
}
require __DIR__ . '/_bottom.php';
