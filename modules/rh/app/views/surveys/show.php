<?php
/**
 * Resultados da pesquisa. Variáveis: $survey (com questions), $stats,
 * $participants, $audience, $departmentName, $answered, $employeeDept.
 */
$open = Survey::isOpen($survey);
$rate = $audience > 0 ? min(100, round($participants / $audience * 100)) : null;
$canAnswer = $open && !$answered && (Survey::targets($survey, $employeeDept) || core_can('surveys.create'));
?>
<style>
    .star-rating { display: inline-flex; flex-direction: row-reverse; gap: 2px; }
    .star-rating input { display: none; }
    .star-rating label { font-size: 1.6rem; color: #ced4da; cursor: pointer; line-height: 1; }
    .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #ffc107; }
    .dist-row { display: flex; align-items: center; gap: 8px; font-size: .85rem; margin-bottom: 4px; }
    .dist-row .lbl { min-width: 110px; text-align: right; color: #555; }
    .dist-row .bar { flex: 1; background: #eef1f5; border-radius: 4px; height: 18px; overflow: hidden; }
    .dist-row .bar > div { height: 100%; background: #0d6efd; }
    .dist-row .val { min-width: 70px; color: #555; }
</style>
<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i><?= Sanitize::e($survey['title']) ?></h1>
    <div class="d-flex gap-2">
        <?php if (core_can('surveys.edit')): ?>
            <a href="index.php?m=rh&page=surveys&action=edit&id=<?= (int)$survey['id'] ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i> Editar</a>
            <?php if ($survey['status'] === 'ativa'): ?>
                <form method="POST" action="index.php?m=rh&page=surveys&action=close" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$survey['id'] ?>"><button type="submit" class="btn btn-outline-dark btn-sm" data-confirm="Encerrar a pesquisa? Não será mais possível responder."><i class="bi bi-lock me-1"></i> Encerrar</button></form>
            <?php endif; ?>
        <?php endif; ?>
        <a href="index.php?m=rh&page=surveys" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>
</div>

<div class="mb-3">
    <span class="badge <?= match($survey['status']) { 'ativa' => 'bg-success', 'encerrada' => 'bg-secondary', default => 'bg-warning text-dark' } ?>"><?= ucfirst($survey['status']) ?></span>
    <span class="badge bg-info text-dark"><?= Sanitize::e(Survey::TYPES[$survey['type']] ?? ucfirst($survey['type'])) ?></span>
    <?= (int)$survey['anonymous'] ? '<span class="badge bg-dark"><i class="bi bi-incognito"></i> Anônima</span>' : '<span class="badge bg-light text-dark border">Identificada</span>' ?>
    <span class="badge bg-light text-dark border"><i class="bi bi-people"></i> <?= $departmentName ? Sanitize::e($departmentName) : 'Todos os departamentos' ?></span>
    <?php if ($survey['starts_at'] || $survey['ends_at']): ?><span class="badge bg-light text-dark border"><?= Sanitize::formatDate($survey['starts_at']) ?> – <?= Sanitize::formatDate($survey['ends_at']) ?></span><?php endif; ?>
    <?php if ($survey['description']): ?><p class="text-muted mt-2 mb-0"><?= nl2br(Sanitize::e($survey['description'])) ?></p><?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value text-primary"><?= (int)$participants ?></div><div class="stat-label">Participantes</div></div></div></div>
    <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value text-secondary"><?= (int)$audience ?></div><div class="stat-label">Público-alvo (com acesso)</div></div></div></div>
    <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value <?= $rate === null ? 'text-muted' : ($rate >= 60 ? 'text-success' : ($rate >= 30 ? 'text-warning' : 'text-danger')) ?>"><?= $rate === null ? '—' : $rate . '%' ?></div><div class="stat-label">Taxa de participação</div></div></div></div>
    <div class="col-md-3"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value text-info"><?= count($survey['questions']) ?></div><div class="stat-label">Perguntas</div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-1"></i> Resultados por pergunta</div>
    <div class="card-body">
        <?php if (empty($stats)): ?><p class="text-muted text-center py-3 mb-0">Nenhuma pergunta cadastrada.</p><?php endif; ?>
        <?php $n = 0; foreach ($stats as $st): $n++; $q = $st['question']; $total = (int)$st['total']; ?>
            <div class="mb-4 pb-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <h6 class="fw-semibold mb-1"><span class="text-muted me-1"><?= $n ?>.</span><?= Sanitize::e($q['question']) ?> <?= (int)($q['required'] ?? 0) ? '<span class="text-danger">*</span>' : '' ?></h6>
                    <small class="text-muted"><span class="badge bg-light text-dark border"><?= Sanitize::e(Survey::QUESTION_TYPES[$q['type']] ?? $q['type']) ?></span> <?= $total ?> resposta(s)</small>
                </div>
                <?php if (!empty($q['help_text'])): ?><small class="text-muted d-block mb-2"><?= Sanitize::e($q['help_text']) ?></small><?php endif; ?>

                <?php if ($total === 0): ?>
                    <p class="text-muted small mb-0">Sem respostas.</p>

                <?php elseif (in_array($q['type'], ['rating', 'scale'], true)):
                    $min = $st['scale_min']; $max = $st['scale_max']; $span = max(1, $max - $min);
                    $pct = $st['avg'] !== null ? round(($st['avg'] - $min) / $span * 100) : 0; ?>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="fs-3 fw-bold <?= $pct >= 70 ? 'text-success' : ($pct >= 40 ? 'text-warning' : 'text-danger') ?>">
                            <?= $st['avg'] ?><?= $q['type'] === 'rating' ? ' <i class="bi bi-star-fill text-warning fs-6"></i>' : '' ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="progress" style="height:12px"><div class="progress-bar <?= $pct >= 70 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $pct ?>%"></div></div>
                            <small class="text-muted">média (escala <?= $min ?>–<?= $max ?>)<?= $q['type'] === 'scale' && (!empty($q['opts']['min_label']) || !empty($q['opts']['max_label'])) ? ': ' . Sanitize::e($q['opts']['min_label'] ?? '') . ' … ' . Sanitize::e($q['opts']['max_label'] ?? '') : '' ?></small>
                        </div>
                    </div>
                    <?php foreach ($st['dist'] as $val => $cnt): ?>
                        <div class="dist-row">
                            <span class="lbl"><?= $val ?><?= $q['type'] === 'rating' ? ' ★' : '' ?></span>
                            <div class="bar"><div style="width:<?= $total ? round($cnt / $total * 100) : 0 ?>%"></div></div>
                            <span class="val"><?= $cnt ?> (<?= $total ? round($cnt / $total * 100) : 0 ?>%)</span>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($q['type'] === 'number'): ?>
                    <div class="d-flex gap-4">
                        <div><small class="text-muted d-block">Média</small><strong class="fs-5"><?= $st['avg'] ?></strong></div>
                        <div><small class="text-muted d-block">Mínimo</small><strong class="fs-5"><?= $st['min'] ?></strong></div>
                        <div><small class="text-muted d-block">Máximo</small><strong class="fs-5"><?= $st['max'] ?></strong></div>
                    </div>

                <?php elseif (in_array($q['type'], ['yes_no', 'choice', 'multiple'], true)): ?>
                    <?php $sum = array_sum($st['counts']) ?: 1; foreach ($st['counts'] as $opt => $cnt): ?>
                        <div class="dist-row">
                            <span class="lbl" title="<?= Sanitize::e((string)$opt) ?>"><?= Sanitize::e(mb_strimwidth((string)$opt, 0, 22, '…')) ?></span>
                            <div class="bar"><div style="width:<?= round($cnt / ($q['type'] === 'multiple' ? $total : $sum) * 100) ?>%;<?= $opt === 'Não' ? 'background:#dc3545' : ($opt === 'Sim' ? 'background:#198754' : '') ?>"></div></div>
                            <span class="val"><?= $cnt ?> (<?= round($cnt / ($q['type'] === 'multiple' ? $total : $sum) * 100) ?>%)</span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($q['type'] === 'multiple'): ?><small class="text-muted">Percentual sobre o total de respondentes (cada um pode marcar várias opções).</small><?php endif; ?>

                <?php else: ?>
                    <ul class="list-group list-group-flush small" style="max-height:260px;overflow:auto">
                        <?php foreach ($st['texts'] as $t): ?><li class="list-group-item px-0 py-1"><i class="bi bi-chat-left-text text-muted me-1"></i><?= nl2br(Sanitize::e($t)) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($survey['status'] === 'ativa' && !empty($survey['questions'])): ?>
<div class="card border-0 shadow-sm" id="respond">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-pencil-square me-1"></i> Responder</div>
    <div class="card-body">
        <?php if ($answered): ?>
            <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> Você já respondeu esta pesquisa.</div>
        <?php elseif (!$canAnswer): ?>
            <div class="alert alert-secondary mb-0"><i class="bi bi-lock me-1"></i> <?= $open ? 'Esta pesquisa não é destinada ao seu departamento.' : 'A pesquisa não está aberta para respostas.' ?></div>
        <?php else: ?>
            <?php require __DIR__ . '/_answer_form.php'; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
