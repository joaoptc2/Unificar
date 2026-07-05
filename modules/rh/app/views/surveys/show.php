<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i><?= Sanitize::e($survey['title']) ?></h1>
    <a href="index.php?m=rh&page=surveys" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row g-3">
    <div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value text-primary"><?= $totalResp ?></div><div class="stat-label">Respostas</div></div></div></div>
    <?php $avgAll = 0; $ratingCount = 0; foreach ($results as $r) { if ($r['type'] === 'rating' && $r['avg_rating'] !== null) { $avgAll += (float)$r['avg_rating']; $ratingCount++; } } ?>
    <?php if ($ratingCount > 0): $avgAll = round($avgAll / $ratingCount, 1); ?>
    <div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value <?= $avgAll >= 7 ? 'text-success' : ($avgAll >= 5 ? 'text-warning' : 'text-danger') ?>"><?= $avgAll ?></div><div class="stat-label">Media Geral</div></div></div></div>
    <?php endif; ?>
</div>
<div class="card border-0 shadow-sm mt-3"><div class="card-header bg-white fw-semibold">Resultados por pergunta</div><div class="card-body">
<?php foreach ($results as $r): ?>
    <div class="mb-4 pb-3 border-bottom">
        <h6 class="fw-semibold"><?= Sanitize::e($r['question']) ?></h6>
        <small class="text-muted"><?= (int)$r['total_responses'] ?> respostas</small>
        <?php if ($r['type'] === 'rating' && $r['avg_rating'] !== null): ?>
            <div class="mt-2"><div class="progress" style="height:20px"><div class="progress-bar <?= (float)$r['avg_rating'] >= 7 ? 'bg-success' : ((float)$r['avg_rating'] >= 5 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= round((float)$r['avg_rating'] * 10) ?>%"><?= round((float)$r['avg_rating'], 1) ?>/10</div></div></div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div></div>

<?php if ($survey['status'] === 'ativa'): ?>
<div class="card border-0 shadow-sm mt-3" id="respond"><div class="card-header bg-white fw-semibold"><i class="bi bi-pencil-square me-1"></i> Responder</div><div class="card-body">
<form method="POST" action="index.php?m=rh&page=surveys&action=respond">
    <?= Csrf::field() ?>
    <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
    <?php foreach ($survey['questions'] as $q): ?>
    <div class="mb-3">
        <label class="form-label fw-semibold"><?= Sanitize::e($q['question']) ?></label>
        <?php if ($q['type'] === 'rating'): ?>
            <input type="range" name="answers[<?= $q['id'] ?>]" class="form-range" min="0" max="10" value="5" oninput="this.nextElementSibling.textContent=this.value"><span class="badge bg-primary">5</span>
        <?php else: ?>
            <textarea name="answers[<?= $q['id'] ?>]" class="form-control" rows="2"></textarea>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Enviar resposta</button>
</form>
</div></div>
<?php endif; ?>
